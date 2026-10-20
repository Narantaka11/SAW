<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

$path = tempnam(sys_get_temp_dir(), 'saw-test-');
if ($path === false) {
    throw new RuntimeException('Database uji tidak dapat dibuat.');
}

try {
    $database = new Database($path);
    $database->saveMetadata('dataset_hash', hash('sha256', 'fixture'));
    $database->saveMetadata('dataset_name', 'fixture.xlsx');
    $database->saveMetadata('sheet_name', 'Data');
    $database->saveMetadata('processed_at', gmdate('c'));

    $insert = $database->pdo()->prepare(
        'INSERT INTO teachers(id, ach, akc, pbr, pgp, itb, missing_mask)
         VALUES(:id, :ach, :akc, :pbr, :pgp, :itb, :missing_mask)'
    );
    foreach ([
        ['Guru-B', 100, 20, 50, 10, 100, 0],
        ['Guru-A', 100, 20, 50, 10, 100, 0],
        ['Guru-C', 50, 100, 50, 10, 50, 0],
        ['Guru-D', 0, 0, 20, 50, 100, 0],
        ['Guru-M', null, 30, 20, 50, 100, 1],
    ] as [$id, $ach, $akc, $pbr, $pgp, $itb, $missingMask]) {
        $insert->execute([
            ':id' => $id,
            ':ach' => $ach,
            ':akc' => $akc,
            ':pbr' => $pbr,
            ':pgp' => $pgp,
            ':itb' => $itb,
            ':missing_mask' => $missingMask,
        ]);
    }

    $service = new AnalysisService($database);
    $overview = $service->overview();
    expect($overview['totalTeachers'] === 5, 'Total guru harus berasal dari dataset fixture.');
    expect($overview['completeTeachers'] === 4, 'Complete case harus mendeteksi empat baris lengkap.');
    expect($overview['rankedTeachers'] === 4, 'Complete Case hanya boleh meranking data lengkap.');
    expect($overview['winner']['id'] === 'Guru-A', 'ID harus menjadi tie-break deterministik untuk skor yang sama.');
    expect($overview['topTen'][1]['id'] === 'Guru-B', 'Alternatif dengan skor sama harus tetap terurut konsisten.');
    expect($overview['missingByCriterion']['ACH']['missing'] === 1, 'Statistik missing harus tetap dihitung per kriteria.');
    expect(array_sum(array_column($overview['distribution'], 'count')) === 4, 'Distribusi skor harus mencakup seluruh guru yang diranking.');
    expect(count($overview['sensitivity']) === 6, 'Baseline dan lima skenario kriteria harus dihitung.');
    expect($overview['sensitivity'][0]['spearman'] === 1.0, 'Baseline harus memiliki Spearman 1.');
    expect($overview['sensitivity'][0]['kendallTau'] === 1.0, 'Baseline harus memiliki Kendall Tau 1.');
    expect($overview['sensitivity'][0]['topK']['10']['overlap'] === 4, 'Overlap Top-K harus menggunakan jumlah alternatif aktual.');

    $teacherPage = $service->teacherList(['page' => 1, 'limit' => 10, 'sort' => 'rank']);
    expect($teacherPage['total'] === 4, 'Jumlah total daftar guru harus memakai populasi yang dapat diranking.');
    expect($teacherPage['rows'][0]['id'] === 'Guru-A', 'Daftar guru harus tetap memakai ranking deterministik.');
    expect($teacherPage['rows'][0]['score_change'] === 0.0, 'Perubahan skor baseline harus nol.');

    $analysis = $service->prepareAnalysis();
    $baselineScenarioId = $database->rankingScenarioId($analysis['runKey'], 'Baseline');
    $removeCachedRank = $database->pdo()->prepare(
        'DELETE FROM ranking_values
         WHERE scenario_id = :scenario_id
           AND teacher_rowid = (SELECT rowid FROM teachers WHERE id = :teacher_id)'
    );
    $removeCachedRank->execute([':scenario_id' => $baselineScenarioId, ':teacher_id' => 'Guru-A']);
    $recoveredOverview = $service->overview();
    expect($recoveredOverview['winner']['id'] === 'Guru-A', 'Cache ranking parsial harus dibangun ulang secara deterministik.');
    $cachedBaselineCount = $database->pdo()->prepare(
        'SELECT COUNT(*) FROM ranking_values WHERE scenario_id = :scenario_id'
    );
    $cachedBaselineCount->execute([':scenario_id' => $baselineScenarioId]);
    expect((int) $cachedBaselineCount->fetchColumn() === 4, 'Pemulihan cache harus mengisi seluruh populasi.');

    $medianConfiguration = $service->saveConfiguration([
        'weights' => array_fill_keys(['ACH', 'AKC', 'PBR', 'PGP', 'ITB'], 0.2),
        'types' => array_fill_keys(['ACH', 'AKC', 'PBR', 'PGP', 'ITB'], 'Benefit'),
        'strategy' => 'Median Imputation',
        'increasedWeight' => 0.4,
    ]);
    expect($medianConfiguration['strategy'] === 'Median Imputation', 'Median imputation harus dapat dipilih.');
    $medianOverview = $service->overview();
    expect($medianOverview['rankedTeachers'] === 5, 'Median imputation harus mempertahankan semua guru.');
    $detail = $service->teacherDetail('Guru-M');
    expect($detail['criteria'][0]['imputedValue'] === 75.0, 'Nilai missing harus diimputasi menggunakan median aktual.');
    expect($detail['score'] !== null, 'Guru imputasi harus mendapatkan skor SAW.');
    $strategyComparison = $service->strategyComparison();
    expect($strategyComparison['summary']['Complete Case']['rankedTeachers'] === 4, 'Perbandingan harus menjalankan Complete Case.');
    expect($strategyComparison['summary']['Median Imputation']['rankedTeachers'] === 5, 'Perbandingan harus menjalankan Median Imputation.');
    expect($strategyComparison['metrics']['comparedCompleteTeachers'] === 4, 'Korelasi strategi harus membandingkan cohort lengkap yang sama.');
    expect(count($strategyComparison['metrics']['enteredTopTen']) === 1, 'Guru yang hanya tersedia setelah imputasi harus tampak pada perubahan Top 10.');
    expect(
        $service->strategyComparison()['metrics'] === $strategyComparison['metrics'],
        'Cache metrik perbandingan strategi harus mempertahankan hasil yang sama.'
    );

    $invalidWeights = array_fill_keys(['ACH', 'AKC', 'PBR', 'PGP', 'ITB'], 0.1);
    expectException(
        static fn () => SawCalculator::validateWeights($invalidWeights),
        'Bobot yang totalnya tidak 1 harus ditolak.'
    );
    expectException(
        static fn () => $service->saveConfiguration([
            'weights' => array_fill_keys(['ACH', 'AKC', 'PBR', 'PGP', 'ITB'], 0.2),
            'types' => ['ACH' => 'Benefit', 'AKC' => 'Cost', 'PBR' => 'Benefit', 'PGP' => 'Benefit', 'ITB' => 'Benefit'],
            'strategy' => 'Median Imputation',
            'increasedWeight' => 0.4,
        ]),
        'Normalisasi Cost harus ditolak jika nilai nol ada.'
    );
    expect($service->configuration()['types']['AKC'] === 'Benefit', 'Konfigurasi Cost invalid tidak boleh menimpa pengaturan aktif.');

    $database->pdo()->exec('UPDATE teachers SET ach = 0');
    expectException(
        static fn () => $service->overview(),
        'Normalisasi Benefit harus ditolak jika seluruh nilai kriteria bernilai nol.'
    );

    echo "Semua pengujian SAW lulus." . PHP_EOL;
} finally {
    unset($service, $database, $insert, $analysis, $removeCachedRank, $cachedBaselineCount);
    foreach ([$path, $path . '-shm', $path . '-wal'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
