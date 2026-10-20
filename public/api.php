<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function scenarioFromRequest(): string
{
    return trim((string) ($_GET['scenario'] ?? 'Baseline'));
}

try {
    set_time_limit(0);
    $database = new Database();
    $service = new AnalysisService($database);
    $action = trim((string) ($_GET['action'] ?? 'status'));

    if ($action === 'status') {
        respond([
            'hasDataset' => $database->metadata('dataset_hash') !== null,
            'dataset' => $service->datasetInformation(),
            'configuration' => $service->configuration(),
            'sourceDatasetAvailable' => is_file(DEFAULT_DATASET_PATH),
            'criteria' => SawCalculator::CRITERIA,
        ]);
    }

    if ($action === 'import') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['error' => 'Gunakan metode POST untuk mengimpor dataset.'], 405);
        }
        if (!isset($_FILES['dataset']) || $_FILES['dataset']['error'] !== UPLOAD_ERR_OK) {
            $uploadError = $_FILES['dataset']['error'] ?? UPLOAD_ERR_NO_FILE;
            $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                ? 'Ukuran workbook melewati batas upload PHP.'
                : 'Workbook XLSX tidak berhasil diunggah.';
            respond(['error' => $message], 400);
        }

        $originalName = basename((string) $_FILES['dataset']['name']);
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            respond(['error' => 'Pilih workbook Excel berekstensi .xlsx.'], 422);
        }
        $uploadPath = DATASET_DIRECTORY . DIRECTORY_SEPARATOR . 'import-' . bin2hex(random_bytes(8)) . '.xlsx';
        if (!move_uploaded_file((string) $_FILES['dataset']['tmp_name'], $uploadPath)) {
            respond(['error' => 'Workbook tidak dapat disimpan ke penyimpanan lokal aplikasi.'], 500);
        }

        try {
            $result = (new WorkbookImporter($database))->import($uploadPath, $originalName);
        } catch (Throwable $exception) {
            unlink($uploadPath);
            if ($exception instanceof RuntimeException || $exception instanceof InvalidArgumentException) {
                respond(['error' => $exception->getMessage()], 422);
            }
            throw $exception;
        }

        respond(['message' => 'Dataset berhasil diimpor dan divalidasi.', 'result' => $result]);
    }

    if ($action === 'save-settings') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(['error' => 'Gunakan metode POST untuk menyimpan pengaturan.'], 405);
        }
        $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            respond(['error' => 'Format pengaturan tidak valid.'], 422);
        }
        respond(['configuration' => $service->saveConfiguration($payload)]);
    }

    if ($action === 'overview') {
        respond($service->overview(scenarioFromRequest()));
    }

    if ($action === 'teachers') {
        respond($service->teacherList($_GET));
    }

    if ($action === 'detail') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            respond(['error' => 'ID guru wajib diisi.'], 422);
        }
        respond($service->teacherDetail($id, scenarioFromRequest()));
    }

    if ($action === 'criteria') {
        respond($service->criteria());
    }

    if ($action === 'sensitivity') {
        respond(['scenarios' => $service->sensitivity()]);
    }

    if ($action === 'compare-strategies') {
        respond($service->strategyComparison());
    }

    if ($action === 'example') {
        respond($service->calculationExample(scenarioFromRequest()));
    }

    if ($action === 'export') {
        $format = (string) ($_GET['format'] ?? 'teachers');
        $scenario = scenarioFromRequest();
        $sheets = [];

        if ($format === 'teachers') {
            $sheets['Data Guru'] = $service->teacherExportRows($scenario);
        } elseif ($format === 'report') {
            $report = $service->reportData($scenario);
            $sheets = [
                'Parameter' => $report['metadata'],
                'Dataset Cleaning' => $service->reportRows('cleaned', $scenario),
                'Matriks Keputusan' => $service->reportRows('decision', $scenario),
                'Matriks Normalisasi' => $service->reportRows('normalized', $scenario),
                'Bobot' => $service->reportRows('weights', $scenario),
                'Skor dan Ranking' => $service->reportRows('ranking', $scenario),
                'Sensitivitas' => $service->reportRows('sensitivity', $scenario),
                'Missing Values' => $service->reportRows('missing', $scenario),
            ];
        } else {
            respond(['error' => 'Jenis ekspor tidak dikenali.'], 422);
        }

        $targetPath = tempnam(sys_get_temp_dir(), 'saw-export-');
        if ($targetPath === false) {
            throw new RuntimeException('File sementara ekspor tidak dapat dibuat.');
        }
        try {
            XlsxWriter::write($sheets, $targetPath);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . ($format === 'report'
                ? 'laporan-penelitian-saw.xlsx'
                : 'data-guru-saw.xlsx') . '"');
            header('Content-Length: ' . filesize($targetPath));
            readfile($targetPath);
        } finally {
            unlink($targetPath);
        }
        exit;
    }

    respond(['error' => 'Endpoint tidak ditemukan.'], 404);
} catch (InvalidArgumentException $exception) {
    respond(['error' => $exception->getMessage()], 422);
} catch (OutOfBoundsException $exception) {
    respond(['error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('[SAW] ' . $exception::class . ': ' . $exception->getMessage());
    respond(['error' => 'Terjadi kesalahan saat memproses permintaan. Periksa log PHP untuk detail.'], 500);
}
