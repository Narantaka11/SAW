<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$publicRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'public');
$relativePath = ltrim(str_replace('/', DIRECTORY_SEPARATOR, rawurldecode((string) $path)), DIRECTORY_SEPARATOR);
$file = $publicRoot === false ? false : realpath($publicRoot . DIRECTORY_SEPARATOR . $relativePath);

if ($path !== '/' && $file !== false && $publicRoot !== false
    && str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($file)) {
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
        if (basename($file) === 'index.php') {
            enableCompression();
        }
        require $file;
        return;
    }

    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'ico' => 'image/x-icon',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
    ];
    $contentType = $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');
    if (str_starts_with($contentType, 'text/') || $contentType === 'image/svg+xml') {
        enableCompression();
    }
    readfile($file);
    return;
}

enableCompression();
require __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';

function enableCompression(): void
{
    if (function_exists('ob_gzhandler')) {
        ob_start('ob_gzhandler');
    }
}
