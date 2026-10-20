<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

$source = $argv[1] ?? DEFAULT_DATASET_PATH;
if (!is_file($source)) {
    fwrite(STDERR, "Dataset tidak ditemukan: {$source}" . PHP_EOL);
    exit(1);
}

$database = new Database();
$result = (new WorkbookImporter($database))->import($source, basename($source));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
