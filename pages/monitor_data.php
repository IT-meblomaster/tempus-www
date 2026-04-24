<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $pg = db_pgsql($config);

    $stmt = $pg->query("
        SELECT
            zdjecie,
            data_zdarzenia,
            nazwa_kontrolera,
            nazwa_przejscia,
            pracownik,
            nazwa_dzialu
        FROM monitoring.monitoring_log
        ORDER BY data_zdarzenia DESC
        LIMIT 12
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}