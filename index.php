<?php
declare(strict_types=1);

ob_start();

$config = require __DIR__ . '/config/config.php';

$debugEnabled = !empty($config['debug']);
$logErrors = array_key_exists('log_errors', $config) ? !empty($config['log_errors']) : true;
$errorLogFile = isset($config['error_log']) && is_string($config['error_log']) && $config['error_log'] !== ''
    ? $config['error_log']
    : null;

if ($debugEnabled) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

ini_set('log_errors', $logErrors ? '1' : '0');

if ($errorLogFile !== null) {
    ini_set('error_log', $errorLogFile);
}

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/csrf.php';
require __DIR__ . '/inc/access.php';

start_session($config);
$pdo = db($config);

$page = current_page();
$pageRecord = page_by_slug($pdo, $page);

if (!$pageRecord || (int) $pageRecord['is_active'] !== 1) {
    http_response_code(404);
    $pageRecord = page_by_slug($pdo, 'forbidden');
}

if (!$pageRecord) {
    http_response_code(500);
    exit('Brak strony systemowej forbidden.');
}

if ($page === 'logout') {
    require __DIR__ . '/pages/logout.php';
    exit;
}

if (!can_access_page($pdo, (string) $pageRecord['slug'])) {
    if (!is_logged_in()) {
        redirect('index.php?page=login');
    }

    http_response_code(403);
    $pageRecord = page_by_slug($pdo, 'forbidden');

    if (!$pageRecord) {
        exit('Brak strony systemowej forbidden.');
    }
}

$pageFile = __DIR__ . '/' . ltrim((string) $pageRecord['file_path'], '/');

if (!is_file($pageFile)) {
    http_response_code(500);
    exit('Brak pliku strony: ' . e((string) $pageRecord['file_path']));
}

require __DIR__ . '/pages/header.php';
require $pageFile;
require __DIR__ . '/pages/footer.php';

ob_end_flush();