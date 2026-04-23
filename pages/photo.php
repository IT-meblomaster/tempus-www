<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$oid = (int)($_GET['oid'] ?? 0);
if ($oid <= 0) {
    http_response_code(404);
    exit;
}

$db = $config['pgsql'] ?? null;
if (!$db) {
    http_response_code(500);
    exit;
}

$cacheDir = dirname(__DIR__) . '/cache/photos';
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    http_response_code(500);
    exit;
}

$jpgPath = $cacheDir . '/' . $oid . '.jpg';
$pngPath = $cacheDir . '/' . $oid . '.png';
$gifPath = $cacheDir . '/' . $oid . '.gif';

$serveFile = static function (string $path, string $contentType): void {
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
};

if (is_file($jpgPath)) {
    $serveFile($jpgPath, 'image/jpeg');
}
if (is_file($pngPath)) {
    $serveFile($pngPath, 'image/png');
}
if (is_file($gifPath)) {
    $serveFile($gifPath, 'image/gif');
}

$connString = sprintf(
    "host=%s port=%d dbname=%s user=%s password=%s",
    $db['host'],
    (int)$db['port'],
    $db['name'],
    $db['user'],
    $db['pass']
);

$conn = @pg_connect($connString);
if (!$conn) {
    http_response_code(500);
    exit;
}

$tmpPath = tempnam($cacheDir, 'photo_');
if ($tmpPath === false) {
    http_response_code(500);
    exit;
}

@pg_query($conn, 'BEGIN');

$exportOk = @pg_lo_export($conn, $oid, $tmpPath);

if (!$exportOk) {
    @pg_query($conn, 'ROLLBACK');
    @unlink($tmpPath);
    http_response_code(404);
    exit;
}

@pg_query($conn, 'COMMIT');

if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
    @unlink($tmpPath);
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmpPath);

$targetPath = '';
$contentType = '';

if ($mime === 'image/jpeg') {
    $targetPath = $jpgPath;
    $contentType = 'image/jpeg';
} elseif ($mime === 'image/png') {
    $targetPath = $pngPath;
    $contentType = 'image/png';
} elseif ($mime === 'image/gif') {
    $targetPath = $gifPath;
    $contentType = 'image/gif';
} else {
    @unlink($tmpPath);
    http_response_code(415);
    exit;
}

if (!@rename($tmpPath, $targetPath)) {
    if (!@copy($tmpPath, $targetPath)) {
        @unlink($tmpPath);
        http_response_code(500);
        exit;
    }
    @unlink($tmpPath);
}

$serveFile($targetPath, $contentType);