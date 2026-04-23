<?php
declare(strict_types=1);

ini_set('memory_limit', '256M');

// --- Filtry z GET ---
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$limit    = min((int) ($_GET['limit'] ?? 500), 2000);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// --- Połączenie z MSSQL ---
$mssqlConn = sqlsrv_connect($config['mssql']['host'], [
    'Database'               => $config['mssql']['name'],
    'UID'                    => $config['mssql']['user'],
    'PWD'                    => $config['mssql']['pass'],
    'CharacterSet'           => 'UTF-8',
    'TrustServerCertificate' => true,
    'Encrypt'                => false,
]);

if ($mssqlConn === false) {
    $err = sqlsrv_errors(); ?>
    <div class="alert alert-danger">
        <strong>Błąd połączenia z MSSQL:</strong> <?= e($err[0]['message'] ?? 'Nieznany błąd') ?>
    </div>
    <?php return;
}

$sql = "SELECT TOP ($limit)
            Przejscie,
            [Zdarzenie - czas],
            [Nr karty],
            [Nazwisko Imie],
            Grupa,
            idimpuls,
            uID
        FROM vMonitorZdarzen
        WHERE Strefa = 'Kołowroty'
          AND [Zdarzenie - typ] = 'Otwarcie kartą'
          AND [Zdarzenie - czas] >= ?
          AND [Zdarzenie - czas] < DATEADD(day, 1, ?)
        ORDER BY [Zdarzenie - czas] DESC";

$params = [
    [$dateFrom . ' 00:00:00', SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_DATETIME],
    [$dateTo   . ' 00:00:00', SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_DATETIME],
];

$stmt = sqlsrv_query($mssqlConn, $sql, $params);

if ($stmt === false) {
    $err = sqlsrv_errors();
    sqlsrv_close($mssqlConn); ?>
    <div class="alert alert-danger">
        <strong>Błąd zapytania:</strong> <?= e($err[0]['message'] ?? 'Nieznany błąd') ?>
    </div>
    <?php return;
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $row;
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($mssqlConn);

function fmt($value): string {
    if ($value === null)            return '<span class="text-muted">—</span>';
    if ($value instanceof DateTime) return e($value->format('Y-m-d H:i:s'));
    return e((string) $value);
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Monitor zdarzeń — Kołowroty</h1>
    <span class="badge text-bg-secondary"><?= count($rows) ?> rekordów</span>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="mssql_view">
            <div class="col-auto">
                <label class="form-label mb-1">Od</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label mb-1">Do</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label mb-1">Maks. rekordów</label>
                <select name="limit" class="form-select form-select-sm">
                    <?php foreach ([100, 250, 500, 1000, 2000] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                <a href="index.php?page=mssql_view" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabela -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if ($rows === []): ?>
            <p class="p-3 mb-0 text-muted">Brak danych dla wybranego zakresu dat.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-nowrap">Czas</th>
                            <th>Przejście</th>
                            <th>Nazwisko Imię</th>
                            <th>Grupa</th>
                            <th>Nr karty</th>
                            <th>idimpuls</th>
                            <th>uID</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?= fmt($row['Zdarzenie - czas']) ?></td>
                            <td><?= fmt($row['Przejscie']) ?></td>
                            <td><?= fmt($row['Nazwisko Imie']) ?></td>
                            <td><?= fmt($row['Grupa']) ?></td>
                            <td><?= fmt($row['Nr karty']) ?></td>
                            <td><?= fmt($row['idimpuls']) ?></td>
                            <td><?= fmt($row['uID']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>