<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/helpers.php';

if (!function_exists('punktualnik_allowed_sort_columns')) {
    function punktualnik_allowed_sort_columns(): array
    {
        return ['datetime', 'kontroler', 'wejscie', 'osoba', 'dzial'];
    }
}

if (!function_exists('punktualnik_normalize_dir')) {
    function punktualnik_normalize_dir(?string $dir): string
    {
        return strtolower((string)$dir) === 'asc' ? 'asc' : 'desc';
    }
}

if (!function_exists('punktualnik_next_dir')) {
    function punktualnik_next_dir(string $column, string $currentSort, string $currentDir): string
    {
        if ($column !== $currentSort) {
            return 'asc';
        }

        return $currentDir === 'asc' ? 'desc' : 'asc';
    }
}

if (!function_exists('punktualnik_sort_indicator')) {
    function punktualnik_sort_indicator(string $column, string $currentSort, string $currentDir): string
    {
        if ($column !== $currentSort) {
            return '';
        }

        return $currentDir === 'asc' ? '▲' : '▼';
    }
}

if (!function_exists('punktualnik_build_query')) {
    function punktualnik_build_query(array $base, array $extra = []): string
    {
        $params = array_merge($base, $extra);

        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            }
        }

        return http_build_query($params);
    }
}

if (!function_exists('punktualnik_apply_filters_sql')) {
    function punktualnik_apply_filters_sql(string $sql, array &$params, array $filters, array $exclude = []): string
    {
        if (!in_array('from', $exclude, true) && $filters['from'] !== '') {
            $sql .= " AND data_zdarzenia::date >= :from";
            $params[':from'] = $filters['from'];
        }

        if (!in_array('to', $exclude, true) && $filters['to'] !== '') {
            $sql .= " AND data_zdarzenia::date <= :to";
            $params[':to'] = $filters['to'];
        }

        if (!in_array('kontroler', $exclude, true) && $filters['kontroler'] !== '') {
            $sql .= " AND nazwa_kontrolera = :kontroler";
            $params[':kontroler'] = $filters['kontroler'];
        }

        if (!in_array('wejscie', $exclude, true) && $filters['wejscie'] !== '') {
            $sql .= " AND nazwa_przejscia = :wejscie";
            $params[':wejscie'] = $filters['wejscie'];
        }

        if (!in_array('pracownik', $exclude, true) && $filters['pracownik'] !== '') {
            $sql .= " AND TRIM(pracownik) = :pracownik";
            $params[':pracownik'] = $filters['pracownik'];
        }

        if (!in_array('dzial', $exclude, true) && $filters['dzial'] !== '') {
            $sql .= " AND nazwa_dzialu = :dzial";
            $params[':dzial'] = $filters['dzial'];
        }

        return $sql;
    }
}

if (!function_exists('punktualnik_fetch_options')) {
    function punktualnik_fetch_options(PDO $pg, string $selectExpr, string $alias, array $filters, array $exclude = []): array
    {
        $params = [];
        $sql = "
            SELECT DISTINCT {$selectExpr} AS {$alias}
            FROM monitoring.monitoring_log
            WHERE 1=1
        ";

        $sql = punktualnik_apply_filters_sql($sql, $params, $filters, $exclude);
        $sql .= " AND {$selectExpr} IS NOT NULL";
        $sql .= " AND BTRIM({$selectExpr}) <> ''";
        $sql .= " ORDER BY {$alias} ASC";

        $stmt = $pg->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

$errors = [];
$rows = [];

$today = new DateTimeImmutable('today');
$from = (string)($_GET['from'] ?? '2026-04-01');
$to = (string)($_GET['to'] ?? $today->format('Y-m-d'));

$kontrolerFilter = trim((string)($_GET['kontroler'] ?? ''));
$wejscieFilter   = trim((string)($_GET['wejscie'] ?? ''));
$pracownikFilter = trim((string)($_GET['pracownik'] ?? ''));
$dzialFilter     = trim((string)($_GET['dzial'] ?? ''));

$filters = [
    'from'      => $from,
    'to'        => $to,
    'kontroler' => $kontrolerFilter,
    'wejscie'   => $wejscieFilter,
    'pracownik' => $pracownikFilter,
    'dzial'     => $dzialFilter,
];

$columns = ['foto', 'datetime', 'kontroler', 'wejscie', 'osoba', 'dzial'];
$labels = [
    'foto'      => 'Foto',
    'datetime'  => 'Data',
    'kontroler' => 'Kontroler',
    'wejscie'   => 'Wejście',
    'osoba'     => 'Nazwisko Imię',
    'dzial'     => 'Dział',
];

$sort = strtolower((string)($_GET['sort'] ?? 'datetime'));
if (!in_array($sort, punktualnik_allowed_sort_columns(), true)) {
    $sort = 'datetime';
}

$dir = punktualnik_normalize_dir($_GET['dir'] ?? 'desc');

$baseParams = [
    'from'      => $from,
    'to'        => $to,
    'kontroler' => $kontrolerFilter,
    'wejscie'   => $wejscieFilter,
    'pracownik' => $pracownikFilter,
    'dzial'     => $dzialFilter,
];

$kontrolerOptions = [];
$wejscieOptions   = [];
$pracownikOptions = [];
$dzialOptions     = [];

try {
    $pg = db_pgsql($config);

    $kontrolerOptions = punktualnik_fetch_options(
        $pg,
        'nazwa_kontrolera',
        'wartosc',
        $filters,
        ['kontroler']
    );

    $wejscieOptions = punktualnik_fetch_options(
        $pg,
        'nazwa_przejscia',
        'wartosc',
        $filters,
        ['wejscie']
    );

    $pracownikOptions = punktualnik_fetch_options(
        $pg,
        'TRIM(pracownik)',
        'wartosc',
        $filters,
        ['pracownik']
    );

    $dzialOptions = punktualnik_fetch_options(
        $pg,
        'nazwa_dzialu',
        'wartosc',
        $filters,
        ['dzial']
    );

    $orderByMap = [
        'datetime'  => 'data_zdarzenia',
        'kontroler' => 'nazwa_kontrolera',
        'wejscie'   => 'nazwa_przejscia',
        'osoba'     => 'LOWER(pracownik)',
        'dzial'     => 'nazwa_dzialu',
    ];

    $orderBy   = $orderByMap[$sort] ?? 'data_zdarzenia';
    $direction = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

    $params = [];
    $sql = "
        SELECT
            zdjecie,
            data_zdarzenia AS datetime,
            nazwa_kontrolera AS kontroler,
            nazwa_przejscia AS wejscie,
            pracownik AS osoba,
            nazwa_dzialu AS dzial
        FROM monitoring.monitoring_log
        WHERE 1=1
    ";

    $sql = punktualnik_apply_filters_sql($sql, $params, $filters);
    $sql .= " ORDER BY {$orderBy} {$direction}, data_zdarzenia DESC";

    $stmt = $pg->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Punktualnik &nbsp;<small class="text-muted fw-normal"><?= (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('H:i:s') ?></small></h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">

            <form class="row gx-2 gy-2 align-items-end mb-3" method="get" action="index.php" id="punktualnikFiltersForm">
                <input type="hidden" name="page" value="punktualnik">

                <div class="col-auto rp-col">
                    <label class="form-label mb-1">Zakres dat</label>

                    <input
                        class="form-control form-control-sm date-range-input"
                        id="dateRangeInput"
                        type="text"
                        readonly
                        placeholder="Wybierz zakres…"
                    >

                    <input type="hidden" name="from" id="fromHidden" value="<?= e($from) ?>">
                    <input type="hidden" name="to" id="toHidden" value="<?= e($to) ?>">

                    <div id="rangePicker" class="rp card mt-2" style="display:none;">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="rpPrev">&lt;</button>
                                <div class="fw-semibold" id="rpTitle"></div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="rpNext">&gt;</button>
                            </div>
                            <div class="rp-grid" id="rpGrid"></div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="rpClear">Wyczyść</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="rpClose">Zamknij</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1">Kontroler</label>
                    <select name="kontroler" class="form-select form-select-sm punktualnik-autosubmit">
                        <option value="">Wszystkie</option>
                        <?php foreach ($kontrolerOptions as $opt): ?>
                            <option value="<?= e((string)$opt) ?>" <?= $kontrolerFilter === (string)$opt ? 'selected' : '' ?>>
                                <?= e((string)$opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1">Wejście</label>
                    <select name="wejscie" class="form-select form-select-sm punktualnik-autosubmit">
                        <option value="">Wszystkie</option>
                        <?php foreach ($wejscieOptions as $opt): ?>
                            <option value="<?= e((string)$opt) ?>" <?= $wejscieFilter === (string)$opt ? 'selected' : '' ?>>
                                <?= e((string)$opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1">Pracownik</label>
                    <select name="pracownik" class="form-select form-select-sm punktualnik-autosubmit">
                        <option value="">Wszyscy</option>
                        <?php foreach ($pracownikOptions as $opt): ?>
                            <option value="<?= e((string)$opt) ?>" <?= $pracownikFilter === (string)$opt ? 'selected' : '' ?>>
                                <?= e((string)$opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1">Dział</label>
                    <select name="dzial" class="form-select form-select-sm punktualnik-autosubmit">
                        <option value="">Wszystkie</option>
                        <?php foreach ($dzialOptions as $opt): ?>
                            <option value="<?= e((string)$opt) ?>" <?= $dzialFilter === (string)$opt ? 'selected' : '' ?>>
                                <?= e((string)$opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label mb-1 d-block">&nbsp;</label>
                    <a class="btn btn-outline-secondary btn-sm" href="index.php?page=punktualnik">Reset</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover align-middle" id="punktualnikTable">
                    <thead class="table-light">
                    <tr>
                        <?php foreach ($columns as $c): ?>
                            <?php if ($c === 'foto'): ?>
                                <th><?= e($labels[$c]) ?></th>
                            <?php else: ?>
                                <?php
                                $newDir = punktualnik_next_dir($c, $sort, $dir);
                                $q = punktualnik_build_query($baseParams, [
                                    'page' => 'punktualnik',
                                    'sort' => $c,
                                    'dir'  => $newDir
                                ]);
                                $ind = punktualnik_sort_indicator($c, $sort, $dir);
                                ?>
                                <th>
                                    <a class="text-decoration-none text-reset" href="?<?= e($q) ?>">
                                        <?= e($labels[$c] ?? $c) ?>
                                        <?php if ($ind !== ''): ?>
                                            <span class="ms-1"><?= e($ind) ?></span>
                                        <?php endif; ?>
                                    </a>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $oid = (int)($r['zdjecie'] ?? 0); ?>
                        <tr>
                            <td class="text-center">
                                <?php if ($oid > 0): ?>
                                    <img
                                        src="index.php?page=photo&oid=<?= $oid ?>"
                                        alt=""
                                        loading="lazy"
                                        class="punktualnik-photo"
                                        onerror="this.style.display='none';"
                                    >
                                <?php endif; ?>
                            </td>
                            <td><?= e((string)($r['datetime'] ?? '')) ?></td>
                            <td><?= e((string)($r['kontroler'] ?? '')) ?></td>
                            <td><?= e((string)($r['wejscie'] ?? '')) ?></td>
                            <td><?= e((string)($r['osoba'] ?? '')) ?></td>
                            <td><?= e((string)($r['dzial'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script src="assets/js/punktualnik.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('punktualnikFiltersForm');
        if (!form) return;

        const autosubmitFields = form.querySelectorAll('.punktualnik-autosubmit');
        autosubmitFields.forEach(function (field) {
            field.addEventListener('change', function () {
                form.submit();
            });
        });

        const fromHidden = document.getElementById('fromHidden');
        const toHidden = document.getElementById('toHidden');
        const rpGrid = document.getElementById('rpGrid');

        if (rpGrid && fromHidden && toHidden) {
            let lastFrom = fromHidden.value;
            let lastTo = toHidden.value;

            const observer = new MutationObserver(function () {
                if (fromHidden.value !== lastFrom || toHidden.value !== lastTo) {
                    if (fromHidden.value !== '' && toHidden.value !== '') {
                        lastFrom = fromHidden.value;
                        lastTo = toHidden.value;
                        form.submit();
                    }
                }
            });

            observer.observe(rpGrid, { childList: true, subtree: true });
        }
    });
    </script>

<?php endif; ?>