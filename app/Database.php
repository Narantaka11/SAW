<?php
declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $path = DATABASE_PATH)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->initialize();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function initialize(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS teachers (
                id TEXT PRIMARY KEY,
                ach REAL NULL,
                akc REAL NULL,
                pbr REAL NULL,
                pgp REAL NULL,
                itb REAL NULL,
                missing_mask INTEGER NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_teachers_missing_mask ON teachers(missing_mask)');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS metadata (
                name TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );

        $rankingType = $this->pdo->query(
            "SELECT type FROM sqlite_master WHERE name = 'rankings'"
        )->fetchColumn();
        if ($rankingType === 'table') {
            $this->pdo->exec('DROP TABLE rankings');
            $this->pdo->exec('VACUUM');
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ranking_runs (
                id INTEGER PRIMARY KEY,
                run_key TEXT NOT NULL UNIQUE
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ranking_scenarios (
                id INTEGER PRIMARY KEY,
                run_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                UNIQUE(run_id, name),
                FOREIGN KEY (run_id) REFERENCES ranking_runs(id)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ranking_values (
                scenario_id INTEGER NOT NULL,
                teacher_rowid INTEGER NOT NULL,
                score REAL NOT NULL,
                rank INTEGER NOT NULL,
                PRIMARY KEY (scenario_id, teacher_rowid),
                FOREIGN KEY (scenario_id) REFERENCES ranking_scenarios(id)
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_ranking_values_rank ON ranking_values(scenario_id, rank)');
        $this->pdo->exec(
            'CREATE VIEW IF NOT EXISTS rankings AS
             SELECT runs.run_key, scenarios.name AS scenario, teachers.id AS teacher_id, values_table.score, values_table.rank
             FROM ranking_values values_table
             JOIN ranking_scenarios scenarios ON scenarios.id = values_table.scenario_id
             JOIN ranking_runs runs ON runs.id = scenarios.run_id
             JOIN teachers ON teachers.rowid = values_table.teacher_rowid'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS comparisons (
                run_key TEXT NOT NULL,
                scenario TEXT NOT NULL,
                metrics_json TEXT NOT NULL,
                PRIMARY KEY (run_key, scenario)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                name TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );
    }

    public function rankingScenarioId(string $runKey, string $scenario): int
    {
        $run = $this->pdo->prepare(
            'INSERT INTO ranking_runs(run_key) VALUES(:run_key)
             ON CONFLICT(run_key) DO NOTHING'
        );
        $run->execute([':run_key' => $runKey]);
        $runStatement = $this->pdo->prepare('SELECT id FROM ranking_runs WHERE run_key = :run_key');
        $runStatement->execute([':run_key' => $runKey]);
        $runId = $runStatement->fetchColumn();
        if ($runId === false) {
            throw new RuntimeException('Kunci analisis tidak dapat disimpan.');
        }

        $scenarioStatement = $this->pdo->prepare(
            'INSERT INTO ranking_scenarios(run_id, name) VALUES(:run_id, :name)
             ON CONFLICT(run_id, name) DO NOTHING'
        );
        $scenarioStatement->execute([':run_id' => $runId, ':name' => $scenario]);
        $idStatement = $this->pdo->prepare(
            'SELECT id FROM ranking_scenarios WHERE run_id = :run_id AND name = :name'
        );
        $idStatement->execute([':run_id' => $runId, ':name' => $scenario]);
        $scenarioId = $idStatement->fetchColumn();
        if ($scenarioId === false) {
            throw new RuntimeException('Skenario analisis tidak dapat disimpan.');
        }

        return (int) $scenarioId;
    }

    public function setting(string $name): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE name = :name');
        $statement->execute([':name' => $name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function saveSetting(string $name, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings(name, value) VALUES(:name, :value)
             ON CONFLICT(name) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([':name' => $name, ':value' => $value]);
    }

    public function metadata(string $name): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM metadata WHERE name = :name');
        $statement->execute([':name' => $name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function saveMetadata(string $name, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO metadata(name, value) VALUES(:name, :value)
             ON CONFLICT(name) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([':name' => $name, ':value' => $value]);
    }
}
