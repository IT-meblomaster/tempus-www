<?php
declare(strict_types=1);

ini_set('memory_limit', '256M');

// --- Filtr daty - domyślnie wczoraj ---
$dateFilter = $_GET['data'] ?? date('Y-m-d', strtotime('yesterday'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = date('Y-m-d', strtotime('yesterday'));
}

// Zakres: tylko wybrany dzień
$dateFrom     = $dateFilter . ' 00:00:00';
$dateTo       = $dateFilter . ' 23:59:59';
$dateTomorrow = date('Y-m-d', strtotime($dateFilter . ' +1 day')) . ' 23:59:59';

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

// Pobierz zdarzenia tylko z wybranego dnia
$sql = "SELECT
            uID,
            [Nazwisko Imie],
            Grupa,
            [Nr karty],
            idimpuls,
            Przejscie,
            [Zdarzenie - czas]
        FROM vMonitorZdarzen
        WHERE Strefa = 'Kołowroty'
          AND [Zdarzenie - typ] = 'Otwarcie kartą'
          AND Przejscie IN ('Kołowr/Wej', 'Kołowr/Wyj')
          AND [Zdarzenie - czas] >= ?
          AND [Zdarzenie - czas] <= ?
        ORDER BY uID, [Zdarzenie - czas] ASC";

$params = [
    [$dateFrom, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_DATETIME],
    [$dateTo,   SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_DATETIME],
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

// Grupuj zdarzenia per osoba
$persons = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $uid = (int) $row['uID'];
    if (!isset($persons[$uid])) {
        $persons[$uid] = [
            'uid'      => $uid,
            'nazwisko' => $row['Nazwisko Imie'],
            'grupa'    => $row['Grupa'],
            'karta'    => $row['Nr karty'],
            'idimpuls' => $row['idimpuls'],
            'zdarzenia'=> [],
        ];
    }
    $persons[$uid]['zdarzenia'][] = [
        'kierunek' => $row['Przejscie'],
        'czas'     => $row['Zdarzenie - czas'] instanceof DateTime
                        ? $row['Zdarzenie - czas']
                        : new DateTime($row['Zdarzenie - czas']),
    ];
}

sqlsrv_free_stmt($stmt);

// Oblicz czas pracy per osoba
$wyniki = [];
foreach ($persons as $uid => $person) {
    $zdarzenia = $person['zdarzenia'];

    // Pierwsze zdarzenie musi być wejściem
    if ($zdarzenia[0]['kierunek'] !== 'Kołowr/Wej') continue;
    $wejscie = $zdarzenia[0]['czas'];

    // Szukaj ostatniego wyjścia z wybranego dnia
    $wyjscie = null;
    for ($i = count($zdarzenia) - 1; $i >= 0; $i--) {
        if ($zdarzenia[$i]['kierunek'] === 'Kołowr/Wyj') {
            $wyjscie = $zdarzenia[$i]['czas'];
            break;
        }
    }

    // Brak wyjścia tego dnia — szukaj następnego dnia
    if ($wyjscie === null) {
        $sql2 = "SELECT TOP 1 [Zdarzenie - czas]
                 FROM vMonitorZdarzen
                 WHERE Strefa = 'Kołowroty'
                   AND [Zdarzenie - typ] = 'Otwarcie kartą'
                   AND Przejscie = 'Kołowr/Wyj'
                   AND uID = ?
                   AND [Zdarzenie - czas] > ?
                   AND [Zdarzenie - czas] <= ?
                 ORDER BY [Zdarzenie - czas] ASC";

        $p2 = [
            [(int) $uid,   SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_INT],
            [$dateTo,      SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_DATETIME],
            [$dateTomorrow,SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_DATETIME],
        ];

        $stmt2 = sqlsrv_query($mssqlConn, $sql2, $p2);
        if ($stmt2 !== false) {
            $row2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC);
            if ($row2) {
                $wyjscie = $row2['Zdarzenie - czas'] instanceof DateTime
                    ? $row2['Zdarzenie - czas']
                    : new DateTime($row2['Zdarzenie - czas']);
            }
            sqlsrv_free_stmt($stmt2);
        }
    }

    // Oblicz minuty
    $minuty = null;
    if ($wyjscie !== null && $wyjscie > $wejscie) {
        $diff   = $wejscie->diff($wyjscie);
        $minuty = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;
    }

    $wyniki[] = [
        'nazwisko' => $person['nazwisko'],
        'grupa'    => $person['grupa'],
        'karta'    => $person['karta'],
        'idimpuls' => $person['idimpuls'],
        'wejscie'  => $wejscie,
        'wyjscie'  => $wyjscie,
        'minuty'   => $minuty,
    ];
}

sqlsrv_close($mssqlConn);

// Sortuj: najpierw wyszli (malejąco wg czasu pracy), potem w pracy
usort($wyniki, fn($a, $b) => strcmp($a['nazwisko'], $b['nazwisko']));

function fmtCzas(DateTime $dt): string {
    return $dt->format('d.m H:i');
}

function fmtMinuty(?int $minuty): string {
    if ($minuty === null) return '<span class="badge text-bg-success">w pracy</span>';
    $h = intdiv($minuty, 60);
    $m = $minuty % 60;
    return sprintf('%d:%02d', $h, $m);
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Czas pracy — Kołowroty</h1>
    <span class="badge text-bg-secondary"><?= count($wyniki) ?> osób</span>
</div>

<!-- Filtr daty -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="czas_pracy">
            <div class="col-auto">
                <label class="form-label mb-1">Dzień</label>
                <input type="date" name="data" class="form-control form-control-sm"
                       value="<?= e($dateFilter) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Pokaż</button>
                <a href="index.php?page=czas_pracy" class="btn btn-outline-secondary btn-sm ms-1">Wczoraj</a>
            </div>
        </form>
        <div class="mt-2 text-muted small">
            Wejścia: <?= e($dateFilter) ?> 00:00–23:59 &nbsp;|&nbsp; Wyjścia: <?= e($dateFilter) ?> 00:00 — <?= e(date('Y-m-d', strtotime($dateFilter . ' +1 day'))) ?> 23:59
        </div>
    </div>
</div>

<!-- Tabela -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if ($wyniki === []): ?>
            <p class="p-3 mb-0 text-muted">Brak danych dla wybranego dnia.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table id="czasPracyTable" class="table table-striped table-hover table-sm align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-dark">
                        <tr>
                            <th class="sortable" data-col="0" style="cursor:pointer;user-select:none">Nazwisko Imię <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-col="1" style="cursor:pointer;user-select:none">Grupa <span class="sort-icon">↕</span></th>
                            <th class="sortable text-center" data-col="2" style="cursor:pointer;user-select:none">Wejście <span class="sort-icon">↕</span></th>
                            <th class="sortable text-center" data-col="3" style="cursor:pointer;user-select:none">Wyjście <span class="sort-icon">↕</span></th>
                            <th class="sortable text-center" data-col="4" style="cursor:pointer;user-select:none">Czas pracy <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-col="5" style="cursor:pointer;user-select:none">Nr karty <span class="sort-icon">↕</span></th>
                            <th class="sortable" data-col="6" style="cursor:pointer;user-select:none">idimpuls <span class="sort-icon">↕</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($wyniki as $w): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($w['nazwisko']) ?></td>
                            <td><?= e($w['grupa']) ?></td>
                            <td class="text-center text-nowrap" data-val="<?= e($w['wejscie']->format('YmdHi')) ?>"><?= fmtCzas($w['wejscie']) ?></td>
                            <td class="text-center text-nowrap" data-val="<?= $w['wyjscie'] ? e($w['wyjscie']->format('YmdHi')) : '999999999' ?>">
                                <?= $w['wyjscie'] ? fmtCzas($w['wyjscie']) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-center text-nowrap" data-val="<?= $w['minuty'] ?? 99999 ?>">
                                <?= fmtMinuty($w['minuty']) ?>
                            </td>
                            <td><?= e($w['karta']) ?></td>
                            <td><?= e($w['idimpuls']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const table = document.getElementById('czasPracyTable');
    if (!table) return;

    let sortCol = 0;
    let sortAsc = true;

    function getCellValue(row, col) {
        const cell = row.cells[col];
        // Użyj data-val jeśli istnieje, inaczej textContent
        return cell.dataset.val !== undefined ? cell.dataset.val : cell.textContent.trim();
    }

    function isNumeric(val) {
        return !isNaN(parseFloat(val)) && isFinite(val);
    }

    function sortTable(col) {
        if (sortCol === col) {
            sortAsc = !sortAsc;
        } else {
            sortCol = col;
            sortAsc = true;
        }

        const tbody = table.tBodies[0];
        const rows  = Array.from(tbody.rows);

        rows.sort(function (a, b) {
            const av = getCellValue(a, col);
            const bv = getCellValue(b, col);
            let cmp;
            if (isNumeric(av) && isNumeric(bv)) {
                cmp = parseFloat(av) - parseFloat(bv);
            } else {
                cmp = av.localeCompare(bv, 'pl', {sensitivity: 'base'});
            }
            return sortAsc ? cmp : -cmp;
        });

        rows.forEach(r => tbody.appendChild(r));
        updateIcons();
    }

    function updateIcons() {
        table.querySelectorAll('th.sortable').forEach(function (th) {
            const icon = th.querySelector('.sort-icon');
            const col  = parseInt(th.dataset.col);
            if (col === sortCol) {
                icon.textContent = sortAsc ? '↑' : '↓';
                icon.style.opacity = '1';
            } else {
                icon.textContent = '↕';
                icon.style.opacity = '0.4';
            }
        });
    }

    table.querySelectorAll('th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            sortTable(parseInt(th.dataset.col));
        });
    });

    // Domyślnie posortuj po nazwisku
    updateIcons();
})();
</script>