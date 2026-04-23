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

$errors = [];
$rows = [];

$today = new DateTimeImmutable('today');
$from = (string)($_GET['from'] ?? '2026-04-01');
$to = (string)($_GET['to'] ?? $today->format('Y-m-d'));

$kontrolerFilter = trim((string)($_GET['kontroler'] ?? ''));
$wejscieFilter = trim((string)($_GET['wejscie'] ?? ''));
$pracownikFilter = trim((string)($_GET['pracownik'] ?? ''));
$dzialFilter = trim((string)($_GET['dzial'] ?? ''));

$columns = ['datetime', 'kontroler', 'wejscie', 'osoba', 'dzial'];
$labels = [
    'datetime'   => 'Data',
    'kontroler'  => 'Kontroler',
    'wejscie'    => 'Wejście',
    'osoba'      => 'Nazwisko Imię',
    'dzial'      => 'Dział',
];

$sort = strtolower((string)($_GET['sort'] ?? 'datetime'));
if (!in_array($sort, punktualnik_allowed_sort_columns(), true)) {
    $sort = 'datetime';
}

$dir = punktualnik_normalize_dir($_GET['dir'] ?? 'desc');

$baseParams = [
    'from' => $from,
    'to' => $to,
    'kontroler' => $kontrolerFilter,
    'wejscie' => $wejscieFilter,
    'pracownik' => $pracownikFilter,
    'dzial' => $dzialFilter,
];

$totalCount = 0;
$kontrolerOptions = [];
$wejscieOptions = [];
$pracownikOptions = [];
$dzialOptions = [];

try {
    $pg = db_pgsql($config);

    $kontrolerOptions = $pg->query("
        SELECT DISTINCT kontroler
        FROM kontrolery.event_log
        WHERE kontroler IS NOT NULL
          AND BTRIM(kontroler) <> ''
        ORDER BY kontroler ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $wejscieOptions = $pg->query("
        SELECT DISTINCT gdzie
        FROM kontrolery.event_log
        WHERE gdzie IS NOT NULL
          AND BTRIM(gdzie) <> ''
        ORDER BY gdzie ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $pracownikOptions = $pg->query("
        SELECT DISTINCT TRIM(COALESCE(nazwisko, '') || ' ' || COALESCE(imie, '')) AS osoba
        FROM kontrolery.event_log
        WHERE TRIM(COALESCE(nazwisko, '') || ' ' || COALESCE(imie, '')) <> ''
        ORDER BY osoba ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $dzialOptions = $pg->query("
        SELECT DISTINCT dzial
        FROM kontrolery.event_log
        WHERE dzial IS NOT NULL
          AND BTRIM(dzial) <> ''
        ORDER BY dzial ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $orderByMap = [
        'datetime'  => 'datetime',
        'kontroler' => 'kontroler',
        'wejscie'   => 'wejscie',
        'osoba'     => 'LOWER(COALESCE(nazwisko, \'\')), LOWER(COALESCE(imie, \'\'))',
        'dzial'     => 'dzial',
    ];

    $orderBy = $orderByMap[$sort] ?? 'datetime';
    $direction = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

    $sql = "
        SELECT
            datetime,
            kontroler,
            gdzie AS wejscie,
            TRIM(COALESCE(nazwisko, '') || ' ' || COALESCE(imie, '')) AS osoba,
            dzial
        FROM kontrolery.event_log
        WHERE datetime::date >= :from
          AND datetime::date <= :to
    ";

    $params = [
        ':from' => $from,
        ':to' => $to,
    ];

    if ($kontrolerFilter !== '') {
        $sql .= " AND kontroler = :kontroler";
        $params[':kontroler'] = $kontrolerFilter;
    }

    if ($wejscieFilter !== '') {
        $sql .= " AND gdzie = :wejscie";
        $params[':wejscie'] = $wejscieFilter;
    }

    if ($pracownikFilter !== '') {
        $sql .= " AND TRIM(COALESCE(nazwisko, '') || ' ' || COALESCE(imie, '')) = :pracownik";
        $params[':pracownik'] = $pracownikFilter;
    }

    if ($dzialFilter !== '') {
        $sql .= " AND dzial = :dzial";
        $params[':dzial'] = $dzialFilter;
    }

    $sql .= " ORDER BY {$orderBy} {$direction}, datetime DESC";

    $stmt = $pg->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalCount = count($rows);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Punktualnik</h1>
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

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="text-muted">
                    <span>
                        Widocznych: <strong id="visibleCount"><?= count($rows) ?></strong> / <?= $totalCount ?>
                    </span>
                </div>

                <div>
                    <a class="btn btn-outline-secondary btn-sm" href="index.php?page=punktualnik">Reset</a>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                <form class="row g-2 align-items-end m-0" method="get" action="index.php" id="punktualnikFiltersForm">
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
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover align-middle" id="punktualnikTable">
                    <thead class="table-light">
                    <tr>
                        <?php foreach ($columns as $c): ?>
                            <?php
                            $newDir = punktualnik_next_dir($c, $sort, $dir);
                            $q = punktualnik_build_query($baseParams, [
                                'page' => 'punktualnik',
                                'sort' => $c,
                                'dir' => $newDir
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
                        <?php endforeach; ?>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
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