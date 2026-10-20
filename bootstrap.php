<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('APP_STORAGE', APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app');
define('DATASET_DIRECTORY', APP_STORAGE . DIRECTORY_SEPARATOR . 'datasets');
define('DATABASE_PATH', APP_STORAGE . DIRECTORY_SEPARATOR . 'saw.sqlite');
define('DEFAULT_DATASET_PATH', DATASET_DIRECTORY . DIRECTORY_SEPARATOR . 'kandidat_saw_evaluasi_guru_sdmi.xlsx');

foreach ([APP_STORAGE, DATASET_DIRECTORY] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Tidak dapat membuat direktori penyimpanan aplikasi.');
    }
}

require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Database.php';
require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'WorkbookImporter.php';
require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SawCalculator.php';
require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AnalysisService.php';
require_once APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'XlsxWriter.php';
