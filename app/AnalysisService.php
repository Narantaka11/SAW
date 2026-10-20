<?php
declare(strict_types=1);

final class AnalysisService
{
    private SawCalculator $calculator;

    public function __construct(private readonly Database $database)
    {
        $this->calculator = new SawCalculator();
    }

    public function configuration(): array
    {
        $weights = $this->decodeSetting('weights', array_fill_keys(array_column(SawCalculator::CRITERIA, 'key'), 0.2));
        $types = $this->decodeSetting('types', array_fill_keys(array_column(SawCalculator::CRITERIA, 'key'), 'Benefit'));
        $strategy = $this->database->setting('strategy') ?? 'Complete Case';
        $increasedWeight = (float) ($this->database->setting('increased_weight') ?? '0.4');

        return [
            'weights' => SawCalculator::validateWeights($weights),
            'types' => SawCalculator::validateTypes($types),
            'strategy' => $this->validateStrategy($strategy),
            'increasedWeight' => $increasedWeight,
        ];
    }

    public function saveConfiguration(array $input): array
    {
        $current = $this->configuration();
        $weights = SawCalculator::validateWeights($input['weights'] ?? []);
        $types = SawCalculator::validateTypes($input['types'] ?? $current['types']);
        $strategy = $this->validateStrategy((string) ($input['strategy'] ?? $current['strategy']));
        $increasedWeight = (float) ($input['increasedWeight'] ?? $current['increasedWeight']);
        if ($increasedWeight <= 0.2 || $increasedWeight >= 1.0) {
            throw new InvalidArgumentException('Bobot skenario harus lebih besar dari 20% dan kurang dari 100%.');
        }
        if ($this->database->metadata('dataset_hash') === null
            && in_array('Cost', $types, true)) {
            throw new InvalidArgumentException('Impor dataset terlebih dahulu sebelum menerapkan tipe Cost.');
        }
        $this->validateCostTypes($types, $strategy);

        $this->database->saveSetting('weights', json_encode($weights, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->database->saveSetting('types', json_encode($types, JSON_THROW_ON_ERROR));
        $this->database->saveSetting('strategy', $strategy);
        $this->database->saveSetting('increased_weight', (string) $increasedWeight);

        return $this->configuration();
    }

    public function scenarios(): array
    {
        $configuration = $this->configuration();
        $scenarios = SawCalculator::scenarioWeights($configuration['increasedWeight']);
        if ($configuration['weights'] !== $scenarios['Baseline']) {
            $scenarios['Bobot aktif'] = $configuration['weights'];
        }

        return $scenarios;
    }

    public function prepareAnalysis(): array
    {
        if ($this->database->metadata('dataset_hash') === null) {
            throw new InvalidArgumentException('Dataset belum diimpor. Pilih workbook XLSX terlebih dahulu.');
        }

        $configuration = $this->configuration();
        $scenarios = $this->scenarios();
        $runKey = hash('sha256', json_encode([
            'dataset' => $this->database->metadata('dataset_hash'),
            'strategy' => $configuration['strategy'],
            'types' => $configuration['types'],
            'scenarios' => $scenarios,
            'normalization' => 'ratio-v1',
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $calculationData = $this->calculationData($configuration['strategy']);

        foreach ($scenarios as $name => $weights) {
            $this->calculator->calculate(
                $this->database,
                $configuration['strategy'],
                $weights,
                $configuration['types'],
                $name,
                $runKey,
                $calculationData
            );
        }

        return [
            'configuration' => $configuration,
            'scenarios' => $scenarios,
            'runKey' => $runKey,
            'calculationData' => $calculationData,
        ];
    }

    private function calculationData(string $strategy): array
    {
        $expressions = ['COUNT(*) AS total_teachers'];
        foreach (SawCalculator::CRITERIA as $criterion) {
            $column = $criterion['column'];
            $key = $criterion['key'];
            $eligible = $strategy === 'Complete Case' ? 'missing_mask = 0 AND ' : '';
            $value = $strategy === 'Complete Case'
                ? 'CASE WHEN missing_mask = 0 THEN ' . $column . ' END'
                : $column;
            $expressions[] = 'MIN(' . $value . ') AS ' . $key . '_min';
            $expressions[] = 'MAX(' . $value . ') AS ' . $key . '_max';
            $expressions[] = 'SUM(CASE WHEN ' . $eligible . $column . ' = 0 THEN 1 ELSE 0 END) AS '
                . $key . '_zeros';
        }
        $row = $this->database->pdo()->query(
            'SELECT ' . implode(', ', $expressions) . ',
                    SUM(CASE WHEN missing_mask = 0 THEN 1 ELSE 0 END) AS complete_teachers
             FROM teachers'
        )->fetch();
        if ($row === false) {
            throw new RuntimeException('Statistik populasi analisis tidak dapat dihitung.');
        }

        $limits = [];
        $zeroCounts = [];
        foreach (SawCalculator::CRITERIA as $criterion) {
            $key = $criterion['key'];
            if ($row[$key . '_min'] === null || $row[$key . '_max'] === null) {
                throw new RuntimeException('Tidak ada nilai tersedia untuk kriteria ' . $key . '.');
            }
            $limits[$key] = [
                'min' => (float) $row[$key . '_min'],
                'max' => (float) $row[$key . '_max'],
            ];
            $zeroCounts[$key] = (int) $row[$key . '_zeros'];
        }

        return [
            'limits' => $limits,
            'zeroCounts' => $zeroCounts,
            'population' => $strategy === 'Complete Case'
                ? (int) $row['complete_teachers']
                : (int) $row['total_teachers'],
            'imputation' => $strategy === 'Median Imputation'
                ? $this->medianValues()
                : [],
        ];
    }

    private function medianValues(): array
    {
        $datasetHash = $this->database->metadata('dataset_hash');
        if ($datasetHash === null) {
            throw new InvalidArgumentException('Dataset belum diimpor. Pilih workbook XLSX terlebih dahulu.');
        }

        $settingName = 'medians:' . $datasetHash;
        $cached = $this->database->setting($settingName);
        if ($cached !== null) {
            $medians = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($medians)) {
                throw new RuntimeException('Cache median dataset tidak valid.');
            }
            foreach (SawCalculator::CRITERIA as $criterion) {
                $column = $criterion['column'];
                if (!isset($medians[$column]) || !is_numeric($medians[$column])
                    || !is_finite((float) $medians[$column])) {
                    throw new RuntimeException('Cache median dataset tidak valid untuk ' . $criterion['key'] . '.');
                }
                $medians[$column] = (float) $medians[$column];
            }

            return $medians;
        }

        $medians = $this->calculator->medians($this->database->pdo());
        $this->database->saveSetting(
            $settingName,
            json_encode($medians, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        );

        return $medians;
    }

    public function overview(string $scenarioName = 'Baseline'): array
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario($scenarioName, $analysis['scenarios']);
        $pdo = $this->database->pdo();
        $runKey = $analysis['runKey'];
        $scenario = $analysis['scenarios'][$scenarioName];
        $count = (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
        $complete = (int) $pdo->query('SELECT COUNT(*) FROM teachers WHERE missing_mask = 0')->fetchColumn();
        $missingTeachers = $count - $complete;
        $rankedCountStatement = $pdo->prepare('SELECT COUNT(*) FROM rankings WHERE run_key = :run_key AND scenario = :scenario');
        $rankedCountStatement->execute([':run_key' => $runKey, ':scenario' => $scenarioName]);
        $rankedCount = (int) $rankedCountStatement->fetchColumn();

        $topStatement = $pdo->prepare(
            'SELECT r.teacher_id AS id, r.score, r.rank, t.ach, t.akc, t.pbr, t.pgp, t.itb
             FROM rankings r JOIN teachers t ON t.id = r.teacher_id
             WHERE r.run_key = :run_key AND r.scenario = :scenario
             ORDER BY r.rank LIMIT 10'
        );
        $topStatement->execute([':run_key' => $runKey, ':scenario' => $scenarioName]);

        return [
            'totalTeachers' => $count,
            'completeTeachers' => $complete,
            'teachersWithMissing' => $missingTeachers,
            'criteriaCount' => count(SawCalculator::CRITERIA),
            'rankedTeachers' => $rankedCount,
            'strategy' => $analysis['configuration']['strategy'],
            'weights' => $analysis['configuration']['weights'],
            'types' => $analysis['configuration']['types'],
            'activeScenario' => $scenarioName,
            'winner' => ($winner = $topStatement->fetch()) === false ? null : $winner,
            'topTen' => [$winner, ...$topStatement->fetchAll()],
            'distribution' => $this->scoreDistribution($runKey, $scenarioName),
            'dataset' => $this->datasetInformation(),
            'missingByCriterion' => $this->missingStatistics($count),
            'sensitivity' => $this->sensitivityForRun($analysis),
        ];
    }

    public function teacherList(array $parameters): array
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario(
            (string) ($parameters['scenario'] ?? 'Baseline'),
            $analysis['scenarios']
        );
        $page = max(1, (int) ($parameters['page'] ?? 1));
        $limit = min(100, max(10, (int) ($parameters['limit'] ?? 25)));
        $search = trim((string) ($parameters['search'] ?? ''));
        $sortColumns = [
            'id' => 't.id COLLATE BINARY',
            'ACH' => 't.ach',
            'AKC' => 't.akc',
            'PBR' => 't.pbr',
            'PGP' => 't.pgp',
            'ITB' => 't.itb',
            'score' => 'r.score',
            'rank' => 'r.rank',
        ];
        $sort = $sortColumns[(string) ($parameters['sort'] ?? 'rank')] ?? $sortColumns['rank'];
        $direction = strtoupper((string) ($parameters['direction'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';
        $filters = [];
        $values = [
            ':scenario_id' => $this->database->rankingScenarioId($analysis['runKey'], $scenarioName),
            ':baseline_scenario_id' => $this->database->rankingScenarioId($analysis['runKey'], 'Baseline'),
        ];

        if ($search !== '') {
            $filters[] = 't.id LIKE :search';
            $values[':search'] = '%' . $search . '%';
        }
        if (($parameters['complete'] ?? '') === 'yes') {
            $filters[] = 't.missing_mask = 0';
        } elseif (($parameters['complete'] ?? '') === 'no') {
            $filters[] = 't.missing_mask <> 0';
        }
        if (($parameters['minimumScore'] ?? '') !== '' && is_numeric($parameters['minimumScore'])) {
            $filters[] = 'r.score >= :minimum_score';
            $values[':minimum_score'] = (float) $parameters['minimumScore'];
        }
        if (($parameters['maximumScore'] ?? '') !== '' && is_numeric($parameters['maximumScore'])) {
            $filters[] = 'r.score <= :maximum_score';
            $values[':maximum_score'] = (float) $parameters['maximumScore'];
        }

        $where = $filters === [] ? '' : ' AND ' . implode(' AND ', $filters);
        $countStatement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM ranking_values r
             JOIN teachers t ON t.rowid = r.teacher_rowid
             WHERE r.scenario_id = :scenario_id' . $where
        );
        $countValues = $values;
        unset($countValues[':baseline_scenario_id']);
        $countStatement->execute($countValues);
        $total = (int) $countStatement->fetchColumn();

        $statement = $this->database->pdo()->prepare(
            'SELECT t.id, t.ach AS ACH, t.akc AS AKC, t.pbr AS PBR,
                    t.pgp AS PGP, t.itb AS ITB, r.score, r.rank,
                    r.score - baseline.score AS score_change,
                    r.rank - baseline.rank AS rank_change,
                    t.missing_mask
             FROM ranking_values r JOIN teachers t ON t.rowid = r.teacher_rowid
             LEFT JOIN ranking_values baseline
                    ON baseline.scenario_id = :baseline_scenario_id
                   AND baseline.teacher_rowid = r.teacher_rowid
             WHERE r.scenario_id = :scenario_id' . $where
            . ' ORDER BY ' . $sort . ' ' . $direction . ', t.id COLLATE BINARY ASC
              LIMIT :limit OFFSET :offset'
        );
        foreach ($values as $key => $value) {
            $statement->bindValue($key, $value, is_float($value) ? PDO::PARAM_STR : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();

        return [
            'rows' => $statement->fetchAll(),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => (int) ceil($total / max(1, $limit)),
            'scenario' => $scenarioName,
        ];
    }

    public function teacherDetail(string $teacherId, string $scenarioName = 'Baseline'): array
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario($scenarioName, $analysis['scenarios']);
        $statement = $this->database->pdo()->prepare('SELECT * FROM teachers WHERE id = :id');
        $statement->execute([':id' => $teacherId]);
        $teacher = $statement->fetch();
        if ($teacher === false) {
            throw new OutOfBoundsException('Guru dengan ID tersebut tidak ditemukan.');
        }

        $runKey = $analysis['runKey'];
        $rankingStatement = $this->database->pdo()->prepare(
            'SELECT score, rank FROM rankings
             WHERE run_key = :run_key AND scenario = :scenario AND teacher_id = :id'
        );
        $rankingStatement->execute([
            ':run_key' => $runKey,
            ':scenario' => $scenarioName,
            ':id' => $teacherId,
        ]);
        $ranking = $rankingStatement->fetch();
        $scenarioWeights = $analysis['scenarios'][$scenarioName];
        $imputation = $analysis['calculationData']['imputation'];
        $limits = $analysis['calculationData']['limits'];
        $criteria = [];
        $score = 0.0;

        foreach (SawCalculator::CRITERIA as $index => $criterion) {
            $column = $criterion['column'];
            $raw = $teacher[$column] === null ? null : (float) $teacher[$column];
            $value = $raw ?? ($imputation[$column] ?? null);
            $weight = $scenarioWeights[$criterion['key']];
            $type = $analysis['configuration']['types'][$criterion['key']];
            $minimum = $limits[$criterion['key']]['min'];
            $maximum = $limits[$criterion['key']]['max'];
            if ($value === null) {
                $normalized = null;
            } elseif ($type === 'Cost') {
                if ($value === 0.0) {
                    throw new InvalidArgumentException('Normalisasi Cost min(x)/x tidak terdefinisi untuk nilai nol.');
                }
                $normalized = $minimum / $value;
            } else {
                $normalized = $maximum === 0.0 ? null : $value / $maximum;
            }
            $contribution = $normalized === null ? null : $weight * $normalized;
            if ($contribution !== null) {
                $score += $contribution;
            }
            $criteria[] = [
                'code' => $criterion['code'],
                'key' => $criterion['key'],
                'value' => $raw,
                'imputedValue' => $raw === null ? $value : null,
                'normalized' => $normalized,
                'weight' => $weight,
                'contribution' => $contribution,
                'mean' => $this->criterionMean(
                    $column,
                    $imputation[$column] ?? null,
                    $analysis['configuration']['strategy']
                ),
            ];
        }

        return [
            'id' => $teacherId,
            'criteria' => $criteria,
            'score' => $ranking === false ? null : (float) $ranking['score'],
            'rank' => $ranking === false ? null : (int) $ranking['rank'],
            'calculatedScore' => $analysis['configuration']['strategy'] === 'Complete Case'
                && (int) $teacher['missing_mask'] !== 0 ? null : $score,
            'eligible' => $analysis['configuration']['strategy'] !== 'Complete Case'
                || (int) $teacher['missing_mask'] === 0,
            'scenario' => $scenarioName,
            'strategy' => $analysis['configuration']['strategy'],
            'rankedTeachers' => $this->rankedCount($runKey, $scenarioName),
            'comparison' => $this->comparisonWithBaseline($teacherId, $runKey, $scenarioName),
        ];
    }

    public function sensitivity(): array
    {
        $analysis = $this->prepareAnalysis();

        return $this->sensitivityForRun($analysis);
    }

    public function strategyComparison(): array
    {
        if ($this->database->metadata('dataset_hash') === null) {
            throw new InvalidArgumentException('Dataset belum diimpor. Pilih workbook XLSX terlebih dahulu.');
        }

        $configuration = $this->configuration();
        $weights = $configuration['weights'];
        $types = $configuration['types'];
        $runKeys = [];
        foreach (['Complete Case', 'Median Imputation'] as $strategy) {
            $runKey = hash('sha256', json_encode([
                'dataset' => $this->database->metadata('dataset_hash'),
                'strategy' => $strategy,
                'weights' => $weights,
                'types' => $types,
                'normalization' => 'ratio-v1',
                'comparison' => true,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $this->calculator->calculate(
                $this->database,
                $strategy,
                $weights,
                $types,
                'Strategy comparison',
                $runKey,
                $this->calculationData($strategy)
            );
            $runKeys[$strategy] = $runKey;
        }

        $counts = [];
        $summary = [];
        foreach ($runKeys as $strategy => $runKey) {
            $scenarioId = $this->database->rankingScenarioId($runKey, 'Strategy comparison');
            $countStatement = $this->database->pdo()->prepare(
                'SELECT COUNT(*) FROM ranking_values WHERE scenario_id = :scenario_id'
            );
            $countStatement->execute([':scenario_id' => $scenarioId]);
            $count = (int) $countStatement->fetchColumn();
            $counts[$strategy] = $count;
            $statement = $this->database->pdo()->prepare(
                'SELECT teachers.id, ranking_values.score
                 FROM ranking_values JOIN teachers ON teachers.rowid = ranking_values.teacher_rowid
                 WHERE ranking_values.scenario_id = :scenario_id
                 ORDER BY ranking_values.rank LIMIT 1'
            );
            $statement->execute([':scenario_id' => $scenarioId]);
            $winner = $statement->fetch();
            $mean = $this->database->pdo()->prepare(
                'SELECT AVG(score) FROM ranking_values WHERE scenario_id = :scenario_id'
            );
            $mean->execute([':scenario_id' => $scenarioId]);
            $summary[$strategy] = [
                'rankedTeachers' => $count,
                'winner' => $winner === false ? null : $winner,
                'meanScore' => ($value = $mean->fetchColumn()) === false ? null : (float) $value,
            ];
        }

        $metricsRunKey = $runKeys['Complete Case'];
        $cachedComparison = $this->database->pdo()->prepare(
            'SELECT metrics_json FROM comparisons
             WHERE run_key = :run_key AND scenario = :scenario'
        );
        $cachedComparison->execute([
            ':run_key' => $metricsRunKey,
            ':scenario' => 'Strategy comparison',
        ]);
        $cachedMetrics = $cachedComparison->fetchColumn();
        if ($cachedMetrics === false) {
            $comparison = $this->compareStrategyRuns(
                $runKeys['Complete Case'],
                $runKeys['Median Imputation'],
                $counts['Complete Case'],
                $counts['Median Imputation']
            );
            $saveComparison = $this->database->pdo()->prepare(
                'INSERT INTO comparisons(run_key, scenario, metrics_json)
                 VALUES(:run_key, :scenario, :metrics)
                 ON CONFLICT(run_key, scenario) DO UPDATE SET metrics_json = excluded.metrics_json'
            );
            $saveComparison->execute([
                ':run_key' => $metricsRunKey,
                ':scenario' => 'Strategy comparison',
                ':metrics' => json_encode($comparison, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ]);
        } else {
            $comparison = json_decode((string) $cachedMetrics, true, 512, JSON_THROW_ON_ERROR);
        }
        $total = (int) $this->database->pdo()->query('SELECT COUNT(*) FROM teachers')->fetchColumn();

        return [
            'weights' => $weights,
            'types' => $types,
            'summary' => $summary,
            'missingTeachersImputed' => $total - $counts['Complete Case'],
            'metrics' => $comparison,
        ];
    }

    public function calculationExample(string $scenarioName = 'Baseline'): array
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario($scenarioName, $analysis['scenarios']);
        $statement = $this->database->pdo()->prepare(
            'SELECT teacher_id FROM rankings
             WHERE run_key = :run_key AND scenario = :scenario
             ORDER BY rank LIMIT 1'
        );
        $statement->execute([':run_key' => $analysis['runKey'], ':scenario' => $scenarioName]);
        $teacherId = $statement->fetchColumn();
        if ($teacherId === false) {
            throw new RuntimeException('Tidak ada alternatif yang dapat diranking pada strategi ini.');
        }

        return $this->teacherDetail((string) $teacherId, $scenarioName);
    }

    public function criteria(): array
    {
        $configuration = $this->configuration();
        $count = (int) $this->database->pdo()->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
        $statistics = $this->missingStatistics($count);
        $limits = $this->criterionLimits();
        $criteria = [];

        foreach (SawCalculator::CRITERIA as $criterion) {
            $key = $criterion['key'];
            $criteria[] = [
                'code' => $criterion['code'],
                'key' => $key,
                'definition' => 'Data tidak tersedia — codebook indikator tidak disertakan.',
                'type' => $configuration['types'][$key],
                'weight' => $configuration['weights'][$key],
                'missing' => $statistics[$key]['missing'],
                'missingPercentage' => $statistics[$key]['percentage'],
                'minimum' => $limits[$key]['min'],
                'maximum' => $limits[$key]['max'],
            ];
        }

        return ['criteria' => $criteria, 'totalTeachers' => $count];
    }

    public function reportData(string $scenarioName = 'Baseline'): array
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario($scenarioName, $analysis['scenarios']);
        $pdo = $this->database->pdo();
        $metadata = [
            ['Parameter', 'Nilai'],
            ['Dataset', $this->database->metadata('dataset_name') ?? 'Data tidak tersedia'],
            ['SHA-256', $this->database->metadata('dataset_hash') ?? 'Data tidak tersedia'],
            ['Tanggal pemrosesan (UTC)', $this->database->metadata('processed_at') ?? 'Data tidak tersedia'],
            ['Sheet sumber', $this->database->metadata('sheet_name') ?? 'Data tidak tersedia'],
            ['Strategi missing values', $analysis['configuration']['strategy']],
            ['Normalisasi', 'Benefit: x/max(x); Cost: min(x)/x'],
            ['Tipe kriteria', json_encode($analysis['configuration']['types'], JSON_UNESCAPED_UNICODE)],
            ['Strategi cost saat nilai nol', 'Perhitungan Cost min(x)/x ditolak jika dataset mengandung nol.'],
            ['Metode pemeringkatan', 'Skor SAW menurun; ikatan skor diurutkan berdasarkan kd_guru_an (binary).'],
        ];
        foreach ($analysis['scenarios'] as $name => $weights) {
            $metadata[] = ['Bobot · ' . $name, json_encode($weights, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)];
        }

        return [
            'analysis' => $analysis,
            'scenario' => $scenarioName,
            'metadata' => $metadata,
            'criteria' => $this->criteria(),
            'missing' => $this->missingStatistics((int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn()),
            'sensitivity' => $this->sensitivityForRun($analysis),
            'limits' => $analysis['calculationData']['limits'],
            'imputation' => $analysis['calculationData']['imputation'],
        ];
    }

    public function datasetInformation(): array
    {
        return [
            'name' => $this->database->metadata('dataset_name'),
            'hash' => $this->database->metadata('dataset_hash'),
            'sheet' => $this->database->metadata('sheet_name'),
            'processedAt' => $this->database->metadata('processed_at'),
            'rows' => $this->database->metadata('row_count'),
        ];
    }

    public function missingStatistics(?int $knownCount = null): array
    {
        $expressions = ['COUNT(*) AS total'];
        foreach (SawCalculator::CRITERIA as $index => $criterion) {
            $mask = 1 << $index;
            $expressions[] = 'SUM(CASE WHEN (missing_mask & ' . $mask . ') <> 0 THEN 1 ELSE 0 END) AS '
                . $criterion['key'];
        }
        $row = $this->database->pdo()->query(
            'SELECT ' . implode(', ', $expressions) . ' FROM teachers'
        )->fetch();
        $count = $knownCount ?? (int) $row['total'];
        $statistics = [];

        foreach (SawCalculator::CRITERIA as $criterion) {
            $missing = (int) $row[$criterion['key']];
            $statistics[$criterion['key']] = [
                'missing' => $missing,
                'percentage' => $count === 0 ? null : 100.0 * $missing / $count,
            ];
        }

        return $statistics;
    }

    public function teacherExportRows(string $scenarioName): Generator
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario($scenarioName, $analysis['scenarios']);
        $statement = $this->database->pdo()->prepare(
            'SELECT t.id, t.ach, t.akc, t.pbr, t.pgp, t.itb, r.score, r.rank
             FROM rankings r JOIN teachers t ON t.id = r.teacher_id
             WHERE r.run_key = :run_key AND r.scenario = :scenario
             ORDER BY r.rank'
        );
        $statement->execute([':run_key' => $analysis['runKey'], ':scenario' => $scenarioName]);
        yield ['kd_guru_an', 'ACH', 'AKC', 'PBR', 'PGP', 'ITB', 'Skor SAW', 'Ranking'];
        while ($row = $statement->fetch()) {
            yield [
                $row['id'],
                $row['ach'],
                $row['akc'],
                $row['pbr'],
                $row['pgp'],
                $row['itb'],
                $row['score'],
                $row['rank'],
            ];
        }
    }

    public function reportRows(string $kind, string $scenarioName): Generator
    {
        $analysis = $this->prepareAnalysis();
        $scenarioName = $this->validateScenario($scenarioName, $analysis['scenarios']);
        $pdo = $this->database->pdo();

        if ($kind === 'cleaned') {
            yield ['kd_guru_an', 'ACH', 'AKC', 'PBR', 'PGP', 'ITB'];
            $statement = $pdo->query(
                'SELECT id, ach, akc, pbr, pgp, itb FROM teachers ORDER BY id COLLATE BINARY'
            );
            while ($row = $statement->fetch()) {
                yield [$row['id'], $row['ach'], $row['akc'], $row['pbr'], $row['pgp'], $row['itb']];
            }
            return;
        }

        if ($kind === 'decision' || $kind === 'normalized') {
            $criteria = SawCalculator::CRITERIA;
            yield ['kd_guru_an', ...array_column($criteria, 'key')];
            $limits = $analysis['calculationData']['limits'];
            $medians = $analysis['calculationData']['imputation'];
            $where = $analysis['configuration']['strategy'] === 'Complete Case'
                ? ' WHERE missing_mask = 0'
                : '';
            $statement = $pdo->query(
                'SELECT id, ach, akc, pbr, pgp, itb FROM teachers' . $where . ' ORDER BY id COLLATE BINARY'
            );
            while ($row = $statement->fetch()) {
                $values = [$row['id']];
                foreach ($criteria as $criterion) {
                    $column = $criterion['column'];
                    $value = $row[$column] === null ? ($medians[$column] ?? null) : (float) $row[$column];
                    if ($kind === 'normalized') {
                        $weightType = $analysis['configuration']['types'][$criterion['key']];
                        $value = $value === null
                            ? null
                            : ($weightType === 'Cost'
                                ? $limits[$criterion['key']]['min'] / $value
                                : $value / $limits[$criterion['key']]['max']);
                    }
                    $values[] = $value;
                }
                yield $values;
            }
            return;
        }

        if ($kind === 'weights') {
            yield ['Skenario', 'C1 · ACH', 'C2 · AKC', 'C3 · PBR', 'C4 · PGP', 'C5 · ITB'];
            foreach ($analysis['scenarios'] as $name => $weights) {
                yield [
                    $name,
                    $weights['ACH'],
                    $weights['AKC'],
                    $weights['PBR'],
                    $weights['PGP'],
                    $weights['ITB'],
                ];
            }
            return;
        }

        if ($kind === 'ranking') {
            yield from $this->teacherExportRows($scenarioName);
            return;
        }

        if ($kind === 'sensitivity') {
            yield [
                'Skenario',
                'Jumlah perubahan ranking',
                'Mean rank displacement',
                'Maximum rank displacement',
                'Spearman',
                'Kendall Tau',
                'Top 5 overlap',
                'Top 10 overlap',
                'Top 20 overlap',
                'Rata-rata perubahan absolut skor',
            ];
            foreach ($this->sensitivityForRun($analysis) as $row) {
                yield [
                    $row['name'],
                    $row['rankChanges'],
                    $row['meanRankDisplacement'],
                    $row['maximumRankDisplacement'],
                    $row['spearman'],
                    $row['kendallTau'],
                    $row['topK']['5']['overlap'] ?? null,
                    $row['topK']['10']['overlap'] ?? null,
                    $row['topK']['20']['overlap'] ?? null,
                    $row['meanAbsoluteScoreChange'],
                ];
            }
            return;
        }

        if ($kind === 'missing') {
            $total = (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
            $complete = (int) $pdo->query('SELECT COUNT(*) FROM teachers WHERE missing_mask = 0')->fetchColumn();
            yield ['Kriteria', 'Jumlah missing', 'Persentase missing'];
            foreach ($this->missingStatistics($total) as $key => $statistics) {
                yield [$key, $statistics['missing'], $statistics['percentage']];
            }
            yield ['Guru lengkap', $complete, $total === 0 ? null : 100.0 * $complete / $total];
            yield ['Guru dengan missing', $total - $complete, $total === 0 ? null : 100.0 * ($total - $complete) / $total];
            return;
        }

        throw new InvalidArgumentException('Jenis lembar laporan tidak dikenali.');
    }

    private function sensitivityForRun(array $analysis): array
    {
        $rows = [];
        foreach ($analysis['scenarios'] as $name => $weights) {
            $statement = $this->database->pdo()->prepare(
                'SELECT metrics_json FROM comparisons WHERE run_key = :run_key AND scenario = :scenario'
            );
            $statement->execute([':run_key' => $analysis['runKey'], ':scenario' => $name]);
            $cached = $statement->fetchColumn();
            if ($cached !== false) {
                $metrics = json_decode((string) $cached, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $metrics = $this->calculateComparison($analysis['runKey'], $name);
                $save = $this->database->pdo()->prepare(
                    'INSERT INTO comparisons(run_key, scenario, metrics_json)
                     VALUES(:run_key, :scenario, :metrics)
                     ON CONFLICT(run_key, scenario) DO UPDATE SET metrics_json = excluded.metrics_json'
                );
                $save->execute([
                    ':run_key' => $analysis['runKey'],
                    ':scenario' => $name,
                    ':metrics' => json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                ]);
            }
            $rows[] = [
                'name' => $name,
                'weights' => $weights,
                ...$metrics,
                'winner' => $this->scenarioWinner($analysis['runKey'], $name),
                ...$this->topTenChanges($analysis['runKey'], $name),
            ];
        }

        return $rows;
    }

    private function calculateComparison(string $runKey, string $scenario): array
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT COUNT(*), SUM((changed.rank - baseline.rank) * (changed.rank - baseline.rank)),
                    SUM(ABS(changed.rank - baseline.rank)),
                    SUM(CASE WHEN changed.rank <> baseline.rank THEN 1 ELSE 0 END),
                    MAX(ABS(changed.rank - baseline.rank))
             FROM rankings baseline
             JOIN rankings changed ON changed.run_key = baseline.run_key
                                  AND changed.teacher_id = baseline.teacher_id
             WHERE baseline.run_key = :run_key
               AND baseline.scenario = :baseline
               AND changed.scenario = :scenario'
        );
        $statement->execute([':run_key' => $runKey, ':baseline' => 'Baseline', ':scenario' => $scenario]);
        $aggregate = $statement->fetch();
        return $this->rankingComparisonMetrics($runKey, $scenario, $aggregate);
    }

    private function compareStrategyRuns(
            string $completeRunKey,
            string $imputedRunKey,
            int $completeCount,
            int $imputedCount
        ): array {
            $pdo = $this->database->pdo();
            $completeScenarioId = $this->database->rankingScenarioId($completeRunKey, 'Strategy comparison');
            $imputedScenarioId = $this->database->rankingScenarioId($imputedRunKey, 'Strategy comparison');
            $sql = 'WITH paired AS (
                        SELECT complete.rank AS complete_rank,
                               ROW_NUMBER() OVER (ORDER BY imputed.rank) AS imputed_common_rank,
                               complete.score AS complete_score,
                               imputed.score AS imputed_score
                        FROM ranking_values complete
                        JOIN ranking_values imputed ON imputed.teacher_rowid = complete.teacher_rowid
                                                   AND imputed.scenario_id = :imputed_scenario_id
                        WHERE complete.scenario_id = :complete_scenario_id
                    )
                    SELECT COUNT(*) AS pair_count,
                           SUM((complete_rank - imputed_common_rank) * (complete_rank - imputed_common_rank)) AS squared_displacement,
                           SUM(ABS(complete_rank - imputed_common_rank)) AS absolute_displacement,
                           SUM(CASE WHEN complete_rank <> imputed_common_rank THEN 1 ELSE 0 END) AS changed_count,
                           MAX(ABS(complete_rank - imputed_common_rank)) AS maximum_displacement,
                           AVG(ABS(complete_score - imputed_score)) AS mean_score_change
                    FROM paired';
            $statement = $pdo->prepare($sql);
            $statement->execute([
                ':imputed_scenario_id' => $imputedScenarioId,
                ':complete_scenario_id' => $completeScenarioId,
            ]);
            $aggregate = $statement->fetch();
            $pairCount = (int) ($aggregate['pair_count'] ?? 0);
            $kendall = $pairCount > 1 ? $this->compareStrategyKendall($completeRunKey, $imputedRunKey) : null;
            $topK = [];

            foreach ([5, 10, 20] as $k) {
                $effectiveK = min($k, $completeCount, $imputedCount);
                $overlapStatement = $pdo->prepare(
                    'SELECT COUNT(*) FROM ranking_values complete
                     JOIN ranking_values imputed ON imputed.teacher_rowid = complete.teacher_rowid
                                                AND imputed.scenario_id = :imputed_scenario_id
                     WHERE complete.scenario_id = :complete_scenario_id
                       AND complete.rank <= :k AND imputed.rank <= :k'
                );
                $overlapStatement->execute([
                    ':imputed_scenario_id' => $imputedScenarioId,
                    ':complete_scenario_id' => $completeScenarioId,
                    ':k' => $effectiveK,
                ]);
                $overlap = (int) $overlapStatement->fetchColumn();
                $topK[(string) $k] = [
                    'k' => $effectiveK,
                    'overlap' => $overlap,
                    'percentage' => $effectiveK === 0 ? null : 100.0 * $overlap / $effectiveK,
                ];
            }

            return [
                'comparedCompleteTeachers' => $pairCount,
                'rankChanges' => (int) ($aggregate['changed_count'] ?? 0),
                'meanRankDisplacement' => $pairCount === 0 ? null : (float) $aggregate['absolute_displacement'] / $pairCount,
                'maximumRankDisplacement' => (int) ($aggregate['maximum_displacement'] ?? 0),
                'spearman' => $pairCount <= 1
                    ? null
                    : 1.0 - (6.0 * (float) $aggregate['squared_displacement'])
                        / ($pairCount * ($pairCount * $pairCount - 1.0)),
                'kendallTau' => $kendall,
                'meanAbsoluteScoreChange' => $aggregate['mean_score_change'] === null
                    ? null
                    : (float) $aggregate['mean_score_change'],
                'topK' => $topK,
                ...$this->compareStrategyTopTen($completeRunKey, $imputedRunKey),
            ];
        }

    private function compareStrategyKendall(string $completeRunKey, string $imputedRunKey): float
        {
            $completeScenarioId = $this->database->rankingScenarioId($completeRunKey, 'Strategy comparison');
            $imputedScenarioId = $this->database->rankingScenarioId($imputedRunKey, 'Strategy comparison');
            $statement = $this->database->pdo()->prepare(
                'SELECT imputed_common_rank
                 FROM (
                     SELECT complete.rank AS complete_rank,
                            ROW_NUMBER() OVER (ORDER BY imputed.rank) AS imputed_common_rank
                     FROM ranking_values complete
                     JOIN ranking_values imputed ON imputed.teacher_rowid = complete.teacher_rowid
                                                AND imputed.scenario_id = :imputed_scenario_id
                     WHERE complete.scenario_id = :complete_scenario_id
                 )
                 ORDER BY complete_rank'
            );
            $statement->execute([
                ':imputed_scenario_id' => $imputedScenarioId,
                ':complete_scenario_id' => $completeScenarioId,
            ]);
            $sequence = [];
            while (($rank = $statement->fetchColumn()) !== false) {
                $sequence[] = (int) $rank;
            }
            $tree = array_fill(0, count($sequence) + 1, 0);
            $inversions = 0;
            $seen = 0;
            foreach ($sequence as $rank) {
                $notGreater = 0;
                for ($index = $rank; $index > 0; $index -= $index & (-$index)) {
                    $notGreater += $tree[$index];
                }
                $inversions += $seen - $notGreater;
                for ($index = $rank; $index <= count($sequence); $index += $index & (-$index)) {
                    $tree[$index]++;
                }
                $seen++;
            }

            return 1.0 - (4.0 * $inversions) / (count($sequence) * (count($sequence) - 1.0));
        }

    private function compareStrategyTopTen(string $completeRunKey, string $imputedRunKey): array
        {
            $pdo = $this->database->pdo();
            $completeScenarioId = $this->database->rankingScenarioId($completeRunKey, 'Strategy comparison');
            $imputedScenarioId = $this->database->rankingScenarioId($imputedRunKey, 'Strategy comparison');
            $enteredStatement = $pdo->prepare(
                'SELECT teachers.id, imputed.rank
                 FROM ranking_values imputed
                 JOIN teachers ON teachers.rowid = imputed.teacher_rowid
                 LEFT JOIN ranking_values complete
                        ON complete.scenario_id = :complete_scenario_id
                       AND complete.teacher_rowid = imputed.teacher_rowid
                 WHERE imputed.scenario_id = :imputed_scenario_id
                   AND imputed.rank <= 10
                   AND (complete.rank IS NULL OR complete.rank > 10)
                 ORDER BY imputed.rank'
            );
            $enteredStatement->execute([
                ':complete_scenario_id' => $completeScenarioId,
                ':imputed_scenario_id' => $imputedScenarioId,
            ]);
            $exitedStatement = $pdo->prepare(
                'SELECT teachers.id, imputed.rank
                 FROM ranking_values complete
                 JOIN teachers ON teachers.rowid = complete.teacher_rowid
                 LEFT JOIN ranking_values imputed
                        ON imputed.scenario_id = :imputed_scenario_id
                       AND imputed.teacher_rowid = complete.teacher_rowid
                 WHERE complete.scenario_id = :complete_scenario_id
                   AND complete.rank <= 10
                   AND (imputed.rank IS NULL OR imputed.rank > 10)
                 ORDER BY complete.rank'
            );
            $exitedStatement->execute([
                ':imputed_scenario_id' => $imputedScenarioId,
                ':complete_scenario_id' => $completeScenarioId,
            ]);

            return [
                'enteredTopTen' => $enteredStatement->fetchAll(),
                'exitedTopTen' => $exitedStatement->fetchAll(),
            ];
        }

    private function rankingComparisonMetrics(string $runKey, string $scenario, array $aggregate): array
    {
        $pdo = $this->database->pdo();
        $count = (int) ($aggregate['COUNT(*)'] ?? 0);
        if ($count === 0) {
            return [
                'rankChanges' => null,
                'meanRankDisplacement' => null,
                'maximumRankDisplacement' => null,
                'spearman' => null,
                'kendallTau' => null,
                'topK' => [],
                'meanAbsoluteScoreChange' => null,
            ];
        }

        $sumSquared = (float) ($aggregate['SUM((changed.rank - baseline.rank) * (changed.rank - baseline.rank))'] ?? 0);
        $rankChanges = (int) ($aggregate['SUM(CASE WHEN changed.rank <> baseline.rank THEN 1 ELSE 0 END)'] ?? 0);
        $meanDisplacement = (float) ($aggregate['SUM(ABS(changed.rank - baseline.rank))'] ?? 0) / $count;
        $spearman = $count <= 1
            ? null
            : 1.0 - (6.0 * $sumSquared) / ($count * ($count * $count - 1.0));
        $baselineCount = $this->rankedCount($runKey, 'Baseline');
        $scenarioCount = $this->rankedCount($runKey, $scenario);
        $pairCount = min($baselineCount, $scenarioCount);
        $kendall = $pairCount > 1 ? $this->kendallTau($runKey, $scenario, $pairCount) : null;

        $topK = [];
        foreach ([5, 10, 20] as $k) {
            $effectiveK = min($k, $pairCount);
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM rankings baseline
                 JOIN rankings changed ON changed.run_key = baseline.run_key
                                      AND changed.teacher_id = baseline.teacher_id
                 WHERE baseline.run_key = :run_key AND baseline.scenario = :baseline
                   AND changed.scenario = :scenario
                   AND baseline.rank <= :k AND changed.rank <= :k'
            );
            $statement->execute([
                ':run_key' => $runKey,
                ':baseline' => 'Baseline',
                ':scenario' => $scenario,
                ':k' => $effectiveK,
            ]);
            $overlap = (int) $statement->fetchColumn();
            $topK[(string) $k] = [
                'k' => $effectiveK,
                'overlap' => $overlap,
                'percentage' => $effectiveK === 0 ? null : 100.0 * $overlap / $effectiveK,
            ];
        }

        $scoreStatement = $pdo->prepare(
            'SELECT AVG(ABS(changed.score - baseline.score))
             FROM rankings baseline
             JOIN rankings changed ON changed.run_key = baseline.run_key
                                  AND changed.teacher_id = baseline.teacher_id
             WHERE baseline.run_key = :run_key
               AND baseline.scenario = :baseline
               AND changed.scenario = :scenario'
        );
        $scoreStatement->execute([':run_key' => $runKey, ':baseline' => 'Baseline', ':scenario' => $scenario]);

        return [
            'rankChanges' => $rankChanges,
            'meanRankDisplacement' => $meanDisplacement,
            'maximumRankDisplacement' => (int) ($aggregate['MAX(ABS(changed.rank - baseline.rank))'] ?? 0),
            'spearman' => $spearman,
            'kendallTau' => $kendall,
            'topK' => $topK,
            'meanAbsoluteScoreChange' => ($score = $scoreStatement->fetchColumn()) === false ? null : (float) $score,
        ];
    }

    private function topTenChanges(string $runKey, string $scenario): array
    {
        if ($scenario === 'Baseline') {
            return ['enteredTopTen' => [], 'exitedTopTen' => []];
        }

        $pdo = $this->database->pdo();
        $scenarioIds = $pdo->prepare(
            "SELECT baseline.id AS baseline_id, changed.id AS scenario_id
             FROM ranking_runs runs
             JOIN ranking_scenarios baseline
               ON baseline.run_id = runs.id AND baseline.name = 'Baseline'
             JOIN ranking_scenarios changed
               ON changed.run_id = runs.id AND changed.name = :scenario
             WHERE runs.run_key = :run_key"
        );
        $scenarioIds->execute([':scenario' => $scenario, ':run_key' => $runKey]);
        $ids = $scenarioIds->fetch();
        if ($ids === false) {
            throw new RuntimeException('Skenario ranking tidak ditemukan untuk membandingkan Top 10.');
        }

        $statement = $pdo->prepare(
            'SELECT teachers.id, changed.rank
             FROM ranking_values changed
             JOIN teachers ON teachers.rowid = changed.teacher_rowid
             LEFT JOIN ranking_values baseline
               ON baseline.scenario_id = :baseline_scenario
              AND baseline.teacher_rowid = changed.teacher_rowid
             WHERE changed.scenario_id = :scenario_id
               AND changed.rank <= 10 AND (baseline.rank IS NULL OR baseline.rank > 10)
             ORDER BY changed.rank'
        );
        $statement->execute([
            ':baseline_scenario' => (int) $ids['baseline_id'],
            ':scenario_id' => (int) $ids['scenario_id'],
        ]);
        $entered = $statement->fetchAll();

        $statement = $pdo->prepare(
            'SELECT teachers.id, changed.rank
             FROM ranking_values baseline
             JOIN teachers ON teachers.rowid = baseline.teacher_rowid
             LEFT JOIN ranking_values changed
               ON changed.scenario_id = :scenario_id
              AND changed.teacher_rowid = baseline.teacher_rowid
             WHERE baseline.scenario_id = :baseline_scenario
               AND baseline.rank <= 10 AND (changed.rank IS NULL OR changed.rank > 10)
             ORDER BY baseline.rank'
        );
        $statement->execute([
            ':scenario_id' => (int) $ids['scenario_id'],
            ':baseline_scenario' => (int) $ids['baseline_id'],
        ]);

        return ['enteredTopTen' => $entered, 'exitedTopTen' => $statement->fetchAll()];
    }

    private function scenarioWinner(string $runKey, string $scenario): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT teacher_id AS id, score FROM rankings
             WHERE run_key = :run_key AND scenario = :scenario ORDER BY rank LIMIT 1'
        );
        $statement->execute([':run_key' => $runKey, ':scenario' => $scenario]);
        $winner = $statement->fetch();

        return $winner === false ? null : $winner;
    }

    private function kendallTau(string $runKey, string $scenario, int $count): float
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT changed.rank
             FROM rankings baseline
             JOIN rankings changed ON changed.run_key = baseline.run_key
                                  AND changed.teacher_id = baseline.teacher_id
             WHERE baseline.run_key = :run_key
               AND baseline.scenario = :baseline
               AND changed.scenario = :scenario
             ORDER BY baseline.rank'
        );
        $statement->execute([':run_key' => $runKey, ':baseline' => 'Baseline', ':scenario' => $scenario]);
        $sequence = [];
        while (($rank = $statement->fetchColumn()) !== false) {
            $sequence[] = (int) $rank;
        }

        $tree = array_fill(0, count($sequence) + 1, 0);
        $inversions = 0;
        $seen = 0;
        foreach ($sequence as $rank) {
            $notGreater = 0;
            for ($index = $rank; $index > 0; $index -= $index & (-$index)) {
                $notGreater += $tree[$index];
            }
            $inversions += $seen - $notGreater;
            for ($index = $rank; $index <= count($sequence); $index += $index & (-$index)) {
                $tree[$index]++;
            }
            $seen++;
        }

        return 1.0 - (4.0 * $inversions) / ($count * ($count - 1.0));
    }

    private function scoreDistribution(string $runKey, string $scenario): array
    {
        $scenarioId = $this->database->rankingScenarioId($runKey, $scenario);
        $statement = $this->database->pdo()->prepare(
            'SELECT MIN(score) AS minimum, MAX(score) AS maximum
             FROM ranking_values WHERE scenario_id = :scenario_id'
        );
        $statement->execute([':scenario_id' => $scenarioId]);
        $limits = $statement->fetch();
        if ($limits === false || $limits['minimum'] === null) {
            return [];
        }
        $minimum = (float) $limits['minimum'];
        $maximum = (float) $limits['maximum'];
        $statement = $this->database->pdo()->prepare(
            'SELECT CASE WHEN :range = 0 THEN 0
                         ELSE MIN(9, CAST(((score - :minimum) / :range) * 10 AS INTEGER))
                    END AS bucket,
                    COUNT(*) AS total
             FROM ranking_values WHERE scenario_id = :scenario_id
             GROUP BY bucket ORDER BY bucket'
        );
        $statement->execute([
            ':range' => $maximum - $minimum,
            ':minimum' => $minimum,
            ':scenario_id' => $scenarioId,
        ]);
        $counts = array_fill(0, 10, 0);
        foreach ($statement->fetchAll() as $row) {
            $counts[(int) $row['bucket']] = (int) $row['total'];
        }

        return array_map(static fn (int $bucket, int $total): array => [
            'label' => number_format($minimum + ($maximum - $minimum) * $bucket / 10.0, 4, '.', ''),
            'count' => $total,
        ], array_keys($counts), $counts);
    }

    private function criterionLimits(string $strategy = 'Median Imputation'): array
    {
        $expressions = [];
        foreach (SawCalculator::CRITERIA as $criterion) {
            $column = $criterion['column'];
            $key = $criterion['key'];
            $value = $strategy === 'Complete Case'
                ? 'CASE WHEN missing_mask = 0 THEN ' . $column . ' END'
                : $column;
            $expressions[] = 'MIN(' . $value . ') AS ' . $key . '_min';
            $expressions[] = 'MAX(' . $value . ') AS ' . $key . '_max';
        }

        $row = $this->database->pdo()->query(
            'SELECT ' . implode(', ', $expressions) . ' FROM teachers'
        )->fetch();
        $limits = [];
        foreach (SawCalculator::CRITERIA as $criterion) {
            $key = $criterion['key'];
            $limits[$key] = [
                'min' => $row[$key . '_min'] === null ? null : (float) $row[$key . '_min'],
                'max' => $row[$key . '_max'] === null ? null : (float) $row[$key . '_max'],
            ];
        }

        return $limits;
    }

    private function criterionMean(string $column, ?float $median, string $strategy): ?float
    {
        $expression = $strategy === 'Median Imputation' && $median !== null
            ? 'COALESCE(' . $column . ', ' . $median . ')'
            : $column;
        $value = $this->database->pdo()->query(
            'SELECT AVG(' . $expression . ') FROM teachers'
        )->fetchColumn();

        return $value === false || $value === null ? null : (float) $value;
    }

    private function comparisonWithBaseline(string $teacherId, string $runKey, string $scenario): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT baseline.rank AS baseline_rank, baseline.score AS baseline_score
             FROM rankings baseline WHERE baseline.run_key = :run_key
               AND baseline.scenario = :scenario AND baseline.teacher_id = :id'
        );
        $statement->execute([':run_key' => $runKey, ':scenario' => 'Baseline', ':id' => $teacherId]);
        $baseline = $statement->fetch();
        if ($baseline === false) {
            return ['rankChange' => null, 'scoreChange' => null];
        }
        $current = $this->database->pdo()->prepare(
            'SELECT rank, score FROM rankings WHERE run_key = :run_key AND scenario = :scenario AND teacher_id = :id'
        );
        $current->execute([':run_key' => $runKey, ':scenario' => $scenario, ':id' => $teacherId]);
        $ranked = $current->fetch();

        return [
            'rankChange' => $ranked === false ? null : (int) $ranked['rank'] - (int) $baseline['baseline_rank'],
            'scoreChange' => $ranked === false ? null : (float) $ranked['score'] - (float) $baseline['baseline_score'],
        ];
    }

    private function rankedCount(string $runKey, string $scenario): int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM rankings WHERE run_key = :run_key AND scenario = :scenario'
        );
        $statement->execute([':run_key' => $runKey, ':scenario' => $scenario]);

        return (int) $statement->fetchColumn();
    }

    private function validateScenario(string $scenario, array $scenarios): string
    {
        if (!array_key_exists($scenario, $scenarios)) {
            throw new InvalidArgumentException('Skenario bobot tidak dikenali.');
        }

        return $scenario;
    }

    private function validateStrategy(string $strategy): string
    {
        if (!in_array($strategy, ['Complete Case', 'Median Imputation'], true)) {
            throw new InvalidArgumentException('Strategi missing values harus Complete Case atau Median Imputation.');
        }

        return $strategy;
    }

    private function validateCostTypes(array $types, string $strategy): void
    {
        $populationFilter = $strategy === 'Complete Case' ? ' AND missing_mask = 0' : '';
        foreach (SawCalculator::CRITERIA as $criterion) {
            if ($types[$criterion['key']] !== 'Cost') {
                continue;
            }

            $zero = $this->database->pdo()->query(
                'SELECT 1 FROM teachers WHERE ' . $criterion['column'] . ' = 0'
                . $populationFilter . ' LIMIT 1'
            )->fetchColumn();
            if ($zero !== false) {
                throw new InvalidArgumentException(
                    'Tipe Cost untuk ' . $criterion['key'] . ' belum dapat diterapkan: '
                    . 'rumus min(x)/x tidak terdefinisi karena terdapat nilai nol pada populasi analisis.'
                );
            }
        }
    }

    private function decodeSetting(string $name, array $fallback): array
    {
        $value = $this->database->setting($name);
        if ($value === null) {
            return $fallback;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Pengaturan ' . $name . ' tidak dapat dibaca.');
        }

        return $decoded;
    }
}
