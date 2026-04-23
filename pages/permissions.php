<?php
declare(strict_types=1);

if (!has_permission($pdo, 'permissions.view') && !has_permission($pdo, 'permissions.manage')) {
    http_response_code(403);
    require __DIR__ . '/forbidden.php';
    return;
}

$errors = [];
$editingPageId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$systemSlugs = [
    'home',
    'login',
    'logout',
    'forbidden',
    'dashboard',
    'users',
    'roles',
    'permissions',
    'menu',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission($pdo, 'permissions.manage')) {
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        return;
    }

    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_page') {
        $pageId = (int) ($_POST['page_id'] ?? 0);
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $filePath = trim((string) ($_POST['file_path'] ?? ''));
        $isPublic = isset($_POST['is_public']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $permissionIds = array_values(array_unique(array_map('intval', $_POST['permissions'] ?? [])));

        $originalPage = null;
        if ($pageId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $pageId]);
            $originalPage = $stmt->fetch() ?: null;
        }

        $originalSlug = $originalPage ? (string) $originalPage['slug'] : '';
        $isSystemPage = $originalPage ? (int) $originalPage['is_system'] === 1 : in_array($slug, $systemSlugs, true);

        if ($slug === '') {
            $errors[] = 'Slug strony jest wymagany.';
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            $errors[] = 'Slug może zawierać tylko litery, cyfry, myślnik i podkreślenie.';
        }

        if ($title === '') {
            $errors[] = 'Tytuł strony jest wymagany.';
        }

        if ($filePath === '') {
            $errors[] = 'Ścieżka pliku jest wymagana.';
        }

        if (!preg_match('/^[a-zA-Z0-9_\/.-]+$/', $filePath)) {
            $errors[] = 'Ścieżka pliku zawiera niedozwolone znaki.';
        }

        if (!str_starts_with($filePath, 'pages/') || !str_ends_with($filePath, '.php')) {
            $errors[] = 'Ścieżka pliku musi wskazywać plik PHP w katalogu pages/.';
        }

        $stmt = $pdo->prepare('SELECT id FROM pages WHERE slug = :slug AND id != :id LIMIT 1');
        $stmt->execute([
            'slug' => $slug,
            'id' => $pageId,
        ]);
        if ($stmt->fetch()) {
            $errors[] = 'Strona o takim slug już istnieje.';
        }

        if ($pageId > 0 && $isSystemPage && $slug !== $originalSlug) {
            $errors[] = 'Nie można zmieniać slugów stron systemowych.';
        }

        if ($isSystemPage && $isPublic === 1 && in_array($slug, ['logout'], true)) {
            $errors[] = 'Ta strona systemowa nie może być publiczna.';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            try {
                if ($pageId > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE pages
                        SET slug = :slug,
                            title = :title,
                            file_path = :file_path,
                            is_public = :is_public,
                            is_active = :is_active
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        'slug' => $isSystemPage ? $originalSlug : $slug,
                        'title' => $title,
                        'file_path' => $filePath,
                        'is_public' => $isPublic,
                        'is_active' => $isActive,
                        'id' => $pageId,
                    ]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO pages (slug, title, file_path, is_public, is_system, is_active)
                        VALUES (:slug, :title, :file_path, :is_public, :is_system, :is_active)
                    ');
                    $stmt->execute([
                        'slug' => $slug,
                        'title' => $title,
                        'file_path' => $filePath,
                        'is_public' => $isPublic,
                        'is_system' => in_array($slug, $systemSlugs, true) ? 1 : 0,
                        'is_active' => $isActive,
                    ]);
                    $pageId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM page_permissions WHERE page_id = :page_id')
                    ->execute(['page_id' => $pageId]);

                if (!$isPublic && $permissionIds !== []) {
                    $stmt = $pdo->prepare('
                        INSERT INTO page_permissions (page_id, permission_id)
                        VALUES (:page_id, :permission_id)
                    ');

                    foreach ($permissionIds as $permissionId) {
                        $stmt->execute([
                            'page_id' => $pageId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }

                $pdo->commit();
                set_flash('success', 'Ustawienia strony zostały zapisane.');
                ?>
                <script>
                window.location.replace('index.php?page=permissions');
                </script>
                <noscript>
                    <meta http-equiv="refresh" content="0;url=index.php?page=permissions">
                </noscript>
                <?php
                return;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Nie udało się zapisać ustawień strony.';
            }
        }
    }
}

$pageToEdit = [
    'id' => 0,
    'slug' => '',
    'title' => '',
    'file_path' => 'pages/',
    'is_public' => 0,
    'is_system' => 0,
    'is_active' => 1,
];

$pagePermissionIds = [];
$modalTitle = 'Dodaj stronę';
$openModal = false;

if ($editingPageId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingPageId]);
    $found = $stmt->fetch();

    if ($found) {
        $pageToEdit = $found;
        $modalTitle = 'Edytuj stronę: ' . ($pageToEdit['title'] ?: $pageToEdit['slug']);
        $openModal = true;

        $stmt = $pdo->prepare('SELECT permission_id FROM page_permissions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $editingPageId]);
        $pagePermissionIds = array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $pageToEdit = [
        'id' => (int) ($_POST['page_id'] ?? 0),
        'slug' => trim((string) ($_POST['slug'] ?? '')),
        'title' => trim((string) ($_POST['title'] ?? '')),
        'file_path' => trim((string) ($_POST['file_path'] ?? '')),
        'is_public' => isset($_POST['is_public']) ? 1 : 0,
        'is_system' => 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $pagePermissionIds = array_map('intval', $_POST['permissions'] ?? []);
    $modalTitle = $pageToEdit['id'] > 0 ? 'Edytuj stronę' : 'Dodaj stronę';
    $openModal = true;
}

$pages = $pdo->query("
    SELECT
        pg.id,
        pg.slug,
        pg.title,
        pg.file_path,
        pg.is_public,
        pg.is_system,
        pg.is_active,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS permissions_list
    FROM pages pg
    LEFT JOIN page_permissions pp ON pp.page_id = pg.id
    LEFT JOIN permissions p ON p.id = pp.permission_id
    GROUP BY
        pg.id,
        pg.slug,
        pg.title,
        pg.file_path,
        pg.is_public,
        pg.is_system,
        pg.is_active
    ORDER BY
        pg.title,
        pg.slug
")->fetchAll();

$permissions = $pdo->query("
    SELECT id, name, description
    FROM permissions
    ORDER BY name
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Uprawnienia stron</h1>
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
                        <th>ID</th>
                        <th>Slug</th>
                        <th>Tytuł</th>
                        <th>Plik</th>
                        <th>Publiczna</th>
                        <th>Systemowa</th>
                        <th>Aktywna</th>
                        <th>Wymagane uprawnienia</th>
                        <th class="text-end">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pages as $pageRow): ?>
                        <tr>
                            <td><?= (int) $pageRow['id'] ?></td>
                            <td><code><?= e($pageRow['slug']) ?></code></td>
                            <td><?= e($pageRow['title']) ?></td>
                            <td><code><?= e($pageRow['file_path']) ?></code></td>
                            <td>
                                <?php if ((int) $pageRow['is_public'] === 1): ?>
                                    <span class="badge text-bg-success">Tak</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $pageRow['is_system'] === 1): ?>
                                    <span class="badge text-bg-dark">Tak</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $pageRow['is_active'] === 1): ?>
                                    <span class="badge text-bg-primary">Tak</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($pageRow['permissions_list'] ?: '-') ?></td>
                            <td class="text-end">
                                <?php if (has_permission($pdo, 'permissions.manage')): ?>
                                    <a href="index.php?page=permissions&edit=<?= (int) $pageRow['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        Edytuj
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (has_permission($pdo, 'permissions.manage')): ?>
                <div class="mt-3">
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#pageModal"
                    >
                        Dodaj nową stronę
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (has_permission($pdo, 'permissions.manage')): ?>
    <div class="modal fade" id="pageModal" tabindex="-1" aria-labelledby="pageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable permissions-modal-dialog">
            <div class="modal-content permissions-modal-content">
                <form method="post" class="permissions-modal-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="save_page">
                    <input type="hidden" name="page_id" value="<?= (int) $pageToEdit['id'] ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="pageModalLabel"><?= e($modalTitle) ?></h5>
                        <a href="index.php?page=permissions" class="btn-close"></a>
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
                                <label class="form-label">Slug</label>
                                <input type="text" name="slug" class="form-control" value="<?= e($pageToEdit['slug']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tytuł</label>
                                <input type="text" name="title" class="form-control" value="<?= e($pageToEdit['title']) ?>" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Plik strony</label>
                                <input type="text" name="file_path" class="form-control" value="<?= e($pageToEdit['file_path']) ?>" required>
                                <div class="form-text">Np. pages/dashboard.php</div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="is_public"
                                        id="is_public"
                                        value="1"
                                        <?= (int) $pageToEdit['is_public'] === 1 ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="is_public">
                                        Strona publiczna
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="is_active"
                                        id="is_active"
                                        value="1"
                                        <?= (int) $pageToEdit['is_active'] === 1 ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="is_active">
                                        Strona aktywna
                                    </label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-check mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        id="is_system"
                                        value="1"
                                        <?= (int) $pageToEdit['is_system'] === 1 ? 'checked' : '' ?>
                                        disabled
                                    >
                                    <label class="form-check-label" for="is_system">
                                        Strona systemowa
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Wymagane uprawnienia</label>
                                <div class="border rounded p-3 permissions-list-box">
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
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">
                                    Dla stron publicznych lista uprawnień nie jest używana.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer permissions-modal-footer">
                        <a href="index.php?page=permissions" class="btn btn-outline-secondary">Anuluj</a>
                        <button type="submit" class="btn btn-primary">Zapisz ustawienia</button>
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
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>