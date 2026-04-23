<?php
declare(strict_types=1);

if (!has_permission($pdo, 'pages.view') && !has_permission($pdo, 'pages.manage')) {
    http_response_code(403);
    require __DIR__ . '/forbidden.php';
    return;
}

$errors = [];
$editingPageId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$permissions = $pdo->query("
    SELECT id, name, description
    FROM permissions
    ORDER BY name
")->fetchAll();

// --- POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission($pdo, 'pages.manage')) {
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        return;
    }

    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    // SAVE (add / edit)
    if ($action === 'save_page') {
        $pageId    = (int)    ($_POST['page_id']   ?? 0);
        $slug      = trim((string) ($_POST['slug']      ?? ''));
        $title     = trim((string) ($_POST['title']     ?? ''));
        $filePath  = trim((string) ($_POST['file_path'] ?? ''));
        $isPublic  = isset($_POST['is_public'])  ? 1 : 0;
        $isActive  = isset($_POST['is_active'])  ? 1 : 0;
        $permissionIds = array_values(array_unique(array_map('intval', $_POST['permissions'] ?? [])));

        // Check if system page (system pages cannot be edited)
        $isSystem = false;
        if ($pageId > 0) {
            $stmt = $pdo->prepare('SELECT is_system FROM pages WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $pageId]);
            $row = $stmt->fetch();
            $isSystem = $row && (int) $row['is_system'] === 1;
        }

        if ($isSystem) {
            $errors[] = 'Strony systemowe nie mogą być edytowane.';
        }

        if ($slug === '') {
            $errors[] = 'Slug jest wymagany.';
        } elseif (!preg_match('/^[a-z0-9_\-]+$/', $slug)) {
            $errors[] = 'Slug może zawierać tylko małe litery, cyfry, myślnik i podkreślenie.';
        }

        if ($title === '') {
            $errors[] = 'Tytuł jest wymagany.';
        }

        if ($filePath === '') {
            $errors[] = 'Ścieżka do pliku jest wymagana.';
        }

        // Slug uniqueness check
        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = :slug AND id != :id LIMIT 1');
            $stmt->execute(['slug' => $slug, 'id' => $pageId]);
            if ($stmt->fetch()) {
                $errors[] = 'Strona o podanym slugu już istnieje.';
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                if ($pageId > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE pages
                        SET slug      = :slug,
                            title     = :title,
                            file_path = :file_path,
                            is_public = :is_public,
                            is_active = :is_active
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        'slug'      => $slug,
                        'title'     => $title,
                        'file_path' => $filePath,
                        'is_public' => $isPublic,
                        'is_active' => $isActive,
                        'id'        => $pageId,
                    ]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO pages (slug, title, file_path, is_public, is_active, is_system)
                        VALUES (:slug, :title, :file_path, :is_public, :is_active, 0)
                    ');
                    $stmt->execute([
                        'slug'      => $slug,
                        'title'     => $title,
                        'file_path' => $filePath,
                        'is_public' => $isPublic,
                        'is_active' => $isActive,
                    ]);
                    $pageId = (int) $pdo->lastInsertId();
                }

                // Sync page_permissions
                $pdo->prepare('DELETE FROM page_permissions WHERE page_id = :page_id')
                    ->execute(['page_id' => $pageId]);

                if ($permissionIds !== []) {
                    $stmt = $pdo->prepare('
                        INSERT INTO page_permissions (page_id, permission_id)
                        VALUES (:page_id, :permission_id)
                    ');
                    foreach ($permissionIds as $permId) {
                        $stmt->execute(['page_id' => $pageId, 'permission_id' => $permId]);
                    }
                }

                $pdo->commit();
                set_flash('success', 'Strona została zapisana.');
                ?>
                <script>window.location.replace('index.php?page=pages_manager');</script>
                <noscript><meta http-equiv="refresh" content="0;url=index.php?page=pages_manager"></noscript>
                <?php
                return;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Nie udało się zapisać strony.';
            }
        }
    }

    // DELETE
    if ($action === 'delete_page') {
        $pageId = (int) ($_POST['page_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id, is_system FROM pages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $pageId]);
        $item = $stmt->fetch();

        if (!$item) {
            $errors[] = 'Nie znaleziono strony do usunięcia.';
        } elseif ((int) $item['is_system'] === 1) {
            $errors[] = 'Nie można usunąć strony systemowej.';
        } else {
            try {
                $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => $pageId]);
                set_flash('success', 'Strona została usunięta.');
                ?>
                <script>window.location.replace('index.php?page=pages_manager');</script>
                <noscript><meta http-equiv="refresh" content="0;url=index.php?page=pages_manager"></noscript>
                <?php
                return;
            } catch (Throwable $e) {
                $errors[] = 'Nie udało się usunąć strony. Sprawdź, czy nie jest powiązana z pozycją menu.';
            }
        }
    }
}

// --- Load pages list ---
$pages = $pdo->query("
    SELECT
        p.id,
        p.slug,
        p.title,
        p.file_path,
        p.is_public,
        p.is_system,
        p.is_active,
        GROUP_CONCAT(DISTINCT perm.name ORDER BY perm.name SEPARATOR ', ') AS permissions_list
    FROM pages p
    LEFT JOIN page_permissions pp   ON pp.page_id       = p.id
    LEFT JOIN permissions perm      ON perm.id          = pp.permission_id
    GROUP BY
        p.id, p.slug, p.title, p.file_path,
        p.is_public, p.is_system, p.is_active
    ORDER BY p.title, p.slug
")->fetchAll();

// --- Defaults for modal ---
$pageToEdit = [
    'id'        => 0,
    'slug'      => '',
    'title'     => '',
    'file_path' => 'pages/',
    'is_public' => 0,
    'is_active' => 1,
    'is_system' => 0,
];
$pagePermissionIds = [];
$modalTitle = 'Dodaj stronę';
$openModal  = false;

// Load page for editing
if ($editingPageId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingPageId]);
    $found = $stmt->fetch();

    if ($found) {
        $pageToEdit = $found;
        $modalTitle = 'Edytuj stronę: ' . ($pageToEdit['title'] ?: ('#' . $pageToEdit['id']));
        $openModal  = true;

        $stmt = $pdo->prepare('SELECT permission_id FROM page_permissions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $editingPageId]);
        $pagePermissionIds = array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}

// Restore POST data on validation errors
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $pageToEdit = [
        'id'        => (int)   ($_POST['page_id']   ?? 0),
        'slug'      => trim((string) ($_POST['slug']      ?? '')),
        'title'     => trim((string) ($_POST['title']     ?? '')),
        'file_path' => trim((string) ($_POST['file_path'] ?? '')),
        'is_public' => isset($_POST['is_public']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'is_system' => 0,
    ];
    $pagePermissionIds = array_map('intval', $_POST['permissions'] ?? []);
    $modalTitle = $pageToEdit['id'] > 0 ? 'Edytuj stronę' : 'Dodaj stronę';
    $openModal  = true;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Zarządzanie stronami</h1>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$pages): ?>
            <p class="mb-0">Brak zdefiniowanych stron.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle permissions-table">
                    <thead>
                    <tr>
                        <th>Tytuł</th>
                        <th>Slug</th>
                        <th>Plik</th>
                        <th>Publiczna</th>
                        <th>Aktywna</th>
                        <th>Uprawnienia</th>
                        <th class="text-end">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pages as $row): ?>
                        <tr>
                            <td>
                                <?= e($row['title']) ?>
                                <?php if ((int) $row['is_system'] === 1): ?>
                                    <span class="badge text-bg-secondary ms-1">systemowa</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= e($row['slug']) ?></code></td>
                            <td><small class="text-muted"><?= e($row['file_path']) ?></small></td>
                            <td>
                                <?php if ((int) $row['is_public'] === 1): ?>
                                    <span class="badge text-bg-info">Tak</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $row['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Tak</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($row['permissions_list'] ?: '-') ?></td>
                            <td class="text-end">
                                <?php if (has_permission($pdo, 'pages.manage')): ?>
                                    <div class="d-inline-flex gap-1">
                                        <?php if ((int) $row['is_system'] !== 1): ?>
                                            <a href="index.php?page=pages_manager&edit=<?= (int) $row['id'] ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                Edytuj
                                            </a>
                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Usunąć stronę „<?= e(addslashes($row['title'])) ?>"?\nUpewnij się, że żadna pozycja menu jej nie używa.');">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action"  value="delete_page">
                                                <input type="hidden" name="page_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Usuń</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (has_permission($pdo, 'pages.manage')): ?>
                <div class="mt-3">
                    <button type="button" class="btn btn-primary"
                            data-bs-toggle="modal" data-bs-target="#pageModal">
                        Dodaj stronę
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (has_permission($pdo, 'pages.manage')): ?>
    <div class="modal fade" id="pageModal" tabindex="-1" aria-labelledby="pageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable permissions-modal-dialog">
            <div class="modal-content permissions-modal-content">
                <form method="post" class="permissions-modal-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action"  value="save_page">
                    <input type="hidden" name="page_id" value="<?= (int) $pageToEdit['id'] ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="pageModalLabel"><?= e($modalTitle) ?></h5>
                        <a href="index.php?page=pages_manager" class="btn-close"></a>
                    </div>

                    <div class="modal-body permissions-modal-body">
                        <?php if ($errors): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= e($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Tytuł <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control"
                                       value="<?= e($pageToEdit['title']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Slug <span class="text-danger">*</span></label>
                                <input type="text" name="slug" class="form-control"
                                       value="<?= e($pageToEdit['slug']) ?>"
                                       placeholder="np. zdarzenia"
                                       pattern="[a-z0-9_\-]+"
                                       title="Małe litery, cyfry, myślnik, podkreślenie"
                                       required>
                                <div class="form-text">Używany w URL: <code>index.php?page=<strong>slug</strong></code></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Ścieżka do pliku <span class="text-danger">*</span></label>
                                <input type="text" name="file_path" class="form-control"
                                       value="<?= e($pageToEdit['file_path']) ?>"
                                       placeholder="pages/zdarzenia.php"
                                       required>
                                <div class="form-text">Względna ścieżka od katalogu głównego projektu, np. <code>pages/zdarzenia.php</code></div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox"
                                           name="is_public" id="is_public" value="1"
                                           <?= (int) $pageToEdit['is_public'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_public">
                                        Publiczna (bez logowania)
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox"
                                           name="is_active" id="is_active" value="1"
                                           <?= (int) $pageToEdit['is_active'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_active">
                                        Aktywna
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Uprawnienia wymagane do wejścia na stronę</label>
                                <div class="form-text mb-2">Jeśli nie zaznaczysz żadnego — strona dostępna dla każdego zalogowanego (lub publiczna, jeśli zaznaczono wyżej).</div>
                                <div class="border rounded p-3 permissions-list-box">
                                    <?php if ($permissions): ?>
                                        <?php foreach ($permissions as $permission): ?>
                                            <div class="form-check">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="permissions[]"
                                                    value="<?= (int) $permission['id'] ?>"
                                                    id="page_perm_<?= (int) $permission['id'] ?>"
                                                    <?= in_array((int) $permission['id'], $pagePermissionIds, true) ? 'checked' : '' ?>
                                                >
                                                <label class="form-check-label" for="page_perm_<?= (int) $permission['id'] ?>">
                                                    <?= e($permission['name']) ?>
                                                    <?php if ($permission['description'] !== ''): ?>
                                                        <small class="text-muted">— <?= e($permission['description']) ?></small>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="mb-0 text-muted small">Brak zdefiniowanych uprawnień.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer permissions-modal-footer">
                        <a href="index.php?page=pages_manager" class="btn btn-outline-secondary">Anuluj</a>
                        <button type="submit" class="btn btn-primary">Zapisz stronę</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($openModal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modalEl = document.getElementById('pageModal');
                if (modalEl) {
                    new bootstrap.Modal(modalEl).show();
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>