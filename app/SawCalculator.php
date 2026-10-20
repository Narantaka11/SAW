<?php
declare(strict_types=1);

final class SawCalculator
{
    public const CRITERIA = [
        ['code' => 'C1', 'key' => 'ACH', 'column' => 'ach'],
        ['code' => 'C2', 'key' => 'AKC', 'column' => 'akc'],
        ['code' => 'C3', 'key' => 'PBR', 'column' => 'pbr'],
        ['code' => 'C4', 'key' => 'PGP', 'column' => 'pgp'],
        ['code' => 'C5', 'key' => 'ITB', 'column' => 'itb'],
    ];

    public static function validateWeights(array $weights): array
    {
        if (count($weights) !== count(self::CRITERIA)) {
            throw new InvalidArgumentException('Masukkan bobot untuk kelima kriteria.');
        }

        $validated = [];
        foreach (self::CRITERIA as $index => $criterion) {
            $weight = $weights[$criterion['key']] ?? $weights[$index] ?? null;
            if (!is_numeric($weight) || !is_finite((float) $weight) || (float) $weight < 0) {
                throw new InvalidArgumentException('Bobot ' . $criterion['key'] . ' harus bernilai numerik dan tidak negatif.');
            }
            $validated[$criterion['key']] = (float) $weight;
        }

        if (abs(array_sum($validated) - 1.0) > 0.000001) {
            throw new InvalidArgumentException('Total bobot harus sama dengan 1 (100%).');
        }

        return $validated;
    }

    public static function validateTypes(array $types): array
    {
        $validated = [];
        foreach (self::CRITERIA as $criterion) {
            $type = $types[$criterion['key']] ?? 'Benefit';
            if (!in_array($type, ['Benefit', 'Cost'], true)) {
                throw new InvalidArgumentException('Tipe ' . $criterion['key'] . ' harus Benefit atau Cost.');
            }
            $validated[$criterion['key']] = $type;
        }

        return $validated;
    }

    public static function scenarioWeights(float $increasedWeight): array
    {
        if ($increasedWeight <= 0.2 || $increasedWeight >= 1.0) {
            throw new InvalidArgumentException('Bobot kriteria yang ditingkatkan harus lebih besar dari 20% dan kurang dari 100%.');
        }

        $remainingWeight = (1.0 - $increasedWeight) / 4.0;
        $scenarios = ['Baseline' => array_fill_keys(array_column(self::CRITERIA, 'key'), 0.2)];

        foreach (self::CRITERIA as $criterion) {
            $weights = array_fill_keys(array_column(self::CRITERIA, 'key'), $remainingWeight);
            $weights[$criterion['key']] = $increasedWeight;
            $scenarios['Bobot ' . $criterion['key'] . ' ditingkatkan'] = $weights;
        }

        return $scenarios;
    }

    public function calculate(
        Database $database,
        string $strategy,
        array $weights,
        array $types,
        string $scenario,
        string $runKey,
        ?array $sharedData = null
    ): array
    {
        $weights = self::validateWeights($weights);
        $types = self::validateTypes($types);
        $pdo = $database->pdo();
        $scenarioId = $database->rankingScenarioId($runKey, $scenario);
        $limits = $sharedData['limits'] ?? [];
        $populationFilter = $strategy === 'Complete Case' ? ' AND missing_mask = 0' : '';

        foreach (self::CRITERIA as $criterion) {
            $column = $criterion['column'];
            if ($sharedData === null) {
                $aggregate = $pdo->query(
                    'SELECT MIN(' . $column . ') AS minimum, MAX(' . $column . ') AS maximum
                     FROM teachers WHERE ' . $column . ' IS NOT NULL' . $populationFilter
                )->fetch();
                if ($aggregate === false || $aggregate['minimum'] === null || $aggregate['maximum'] === null) {
                    throw new RuntimeException('Tidak ada nilai tersedia untuk kriteria ' . $criterion['key'] . '.');
                }

                $limits[$criterion['key']] = [
                    'min' => (float) $aggregate['minimum'],
                    'max' => (float) $aggregate['maximum'],
                ];
            }
            $minimum = $limits[$criterion['key']]['min'];
            $maximum = $limits[$criterion['key']]['max'];

            if ($types[$criterion['key']] === 'Cost') {
                $hasZero = $sharedData === null
                    ? $pdo->query(
                        'SELECT 1 FROM teachers WHERE ' . $column . ' = 0' . $populationFilter . ' LIMIT 1'
                    )->fetchColumn() !== false
                    : $sharedData['zeroCounts'][$criterion['key']] > 0;
                if ($hasZero) {
                    throw new InvalidArgumentException(
                        'Normalisasi Cost min(x)/x untuk ' . $criterion['key']
                        . ' tidak dapat digunakan karena terdapat nilai nol. '
                        . 'Gunakan Benefit atau tetapkan normalisasi Cost alternatif dengan dasar metodologis.'
                    );
                }
            } else {
                if ($maximum === 0.0) {
                    throw new InvalidArgumentException(
                        'Normalisasi Benefit x/max(x) untuk ' . $criterion['key']
                        . ' tidak dapat digunakan karena seluruh nilai pada populasi analisis bernilai nol.'
                    );
                }
            }
        }

        $imputation = $sharedData['imputation']
            ?? ($strategy === 'Median Imputation' ? $this->medians($pdo) : []);
        $expectedPopulation = $sharedData['population']
            ?? (int) $pdo->query(
                'SELECT COUNT(*) FROM teachers' . ($strategy === 'Complete Case' ? ' WHERE missing_mask = 0' : '')
            )->fetchColumn();
        $cachedCountStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM ranking_values WHERE scenario_id = :scenario_id'
        );
        $cachedCountStatement->execute([':scenario_id' => $scenarioId]);
        $cachedCount = (int) $cachedCountStatement->fetchColumn();

        if ($cachedCount === $expectedPopulation) {
            return [
                'weights' => $weights,
                'types' => $types,
                'limits' => $limits,
                'imputation' => $imputation,
                'normalization' => 'Benefit: x/max(x); Cost: min(x)/x',
                'scenario' => $scenario,
            ];
        }

        if ($cachedCount > 0) {
            $deletePartialRanking = $pdo->prepare(
                'DELETE FROM ranking_values WHERE scenario_id = :scenario_id'
            );
            $deletePartialRanking->execute([':scenario_id' => $scenarioId]);
        }

        $valueExpressions = [];
        foreach (self::CRITERIA as $criterion) {
            $column = $criterion['column'];
            $valueExpressions[$column] = isset($imputation[$column])
                ? 'COALESCE(t.' . $column . ', ' . $imputation[$column] . ')'
                : 't.' . $column;
        }
        $scoreTerms = [];
        foreach (self::CRITERIA as $criterion) {
            $column = $criterion['column'];
            $baseExpression = $types[$criterion['key']] === 'Cost'
                ? '(' . $limits[$criterion['key']]['min'] . ' / ' . $valueExpressions[$column] . ')'
                : '(' . $valueExpressions[$column] . ' / ' . $limits[$criterion['key']]['max'] . ')';
            $scoreTerms[] = '(' . $weights[$criterion['key']] . ' * ' . $baseExpression . ')';
        }

        $where = $strategy === 'Complete Case' ? ' WHERE t.missing_mask = 0' : '';
        $score = implode(' + ', $scoreTerms);
        $sql = 'INSERT INTO ranking_values(scenario_id, teacher_rowid, score, rank)
                SELECT :scenario_id, scored.teacher_rowid, scored.score,
                       ROW_NUMBER() OVER (ORDER BY scored.score DESC, scored.id COLLATE BINARY ASC)
                FROM (
                    SELECT t.rowid AS teacher_rowid, t.id, (' . $score . ') AS score
                    FROM teachers t' . $where . '
                ) AS scored
                WHERE NOT EXISTS (
                    SELECT 1 FROM ranking_values r
                    WHERE r.scenario_id = :scenario_id_check
                )';
        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':scenario_id' => $scenarioId,
            ':scenario_id_check' => $scenarioId,
        ]);

        return [
            'weights' => $weights,
            'types' => $types,
            'limits' => $limits,
            'imputation' => $imputation,
            'normalization' => 'Benefit: x/max(x); Cost: min(x)/x',
            'scenario' => $scenario,
        ];
    }

    public function medians(PDO $pdo): array
    {
        $medians = [];
        foreach (self::CRITERIA as $criterion) {
            $column = $criterion['column'];
            $count = (int) $pdo->query(
                'SELECT COUNT(*) FROM teachers WHERE ' . $column . ' IS NOT NULL'
            )->fetchColumn();
            if ($count === 0) {
                throw new RuntimeException('Median tidak dapat dihitung untuk ' . $criterion['key'] . '.');
            }

            $middle = ($count - 1) / 2;
            $firstOffset = (int) floor($middle);
            $secondOffset = (int) ceil($middle);
            $statement = $pdo->query(
                'SELECT ' . $column . ' FROM teachers WHERE ' . $column
                . ' IS NOT NULL ORDER BY ' . $column
                . ' LIMIT 2 OFFSET ' . $firstOffset
            );
            $values = $statement->fetchAll(PDO::FETCH_COLUMN);
            if ($values === []) {
                throw new RuntimeException('Median tidak dapat dihitung untuk ' . $criterion['key'] . '.');
            }
            $medians[$column] = (float) $values[0];
            if ($secondOffset !== $firstOffset) {
                if (!isset($values[1])) {
                    throw new RuntimeException('Median tidak dapat dihitung untuk ' . $criterion['key'] . '.');
                }
                $medians[$column] = ((float) $values[0] + (float) $values[1]) / 2.0;
            }
        }

        return $medians;
    }
}
