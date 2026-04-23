<?php
declare(strict_types=1);

if (!has_permission($pdo, 'roles.view') && !has_permission($pdo, 'roles.manage')) {
    http_response_code(403);
    require __DIR__ . '/forbidden.php';
    return;
}

$errors = [];
$editingRoleId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission($pdo, 'roles.manage')) {
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        return;
    }

    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $permissionIds = array_values(array_unique(array_map('intval', $_POST['permissions'] ?? [])));

        if ($name === '') {
            $errors[] = 'Nazwa roli jest wymagana.';
        }

        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name AND id != :id LIMIT 1');
        $stmt->execute([
            'name' => $name,
            'id' => $roleId,
        ]);

        if ($stmt->fetch()) {
            $errors[] = 'Rola o takiej nazwie już istnieje.';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            try {
                if ($roleId > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE roles
                        SET name = :name,
                            description = :description
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        'name' => $name,
                        'description' => $description,
                        'id' => $roleId,
                    ]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO roles (name, description)
                        VALUES (:name, :description)
                    ');
                    $stmt->execute([
                        'name' => $name,
                        'description' => $description,
                    ]);
                    $roleId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')
                    ->execute(['role_id' => $roleId]);

                if ($permissionIds !== []) {
                    $stmt = $pdo->prepare('
                        INSERT INTO role_permissions (role_id, permission_id)
                        VALUES (:role_id, :permission_id)
                    ');

                    foreach ($permissionIds as $permissionId) {
                        $stmt->execute([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }

                $pdo->commit();
                set_flash('success', 'Rola została zapisana.');
                ?>
                <script>
                window.location.replace('index.php?page=roles');
                </script>
                <noscript>
                    <meta http-equiv="refresh" content="0;url=index.php?page=roles">
                </noscript>
                <?php
                return;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Nie udało się zapisać roli.';
            }
        }
    }
}

$roleToEdit = [
    'id' => 0,
    'name' => '',
    'description' => '',
];

$rolePermissionIds = [];
$modalTitle = 'Dodaj nową rolę';
$openModal = false;

if ($editingRoleId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingRoleId]);
    $found = $stmt->fetch();

    if ($found) {
        $roleToEdit = $found;
        $modalTitle = 'Edytuj rolę: ' . ($roleToEdit['name'] ?: ('#' . $roleToEdit['id']));
        $openModal = true;

        $stmt = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = :role_id');
        $stmt->execute(['role_id' => $editingRoleId]);
        $rolePermissionIds = array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $roleToEdit = [
        'id' => (int) ($_POST['role_id'] ?? 0),
        'name' => trim((string) ($_POST['name'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
    ];
    $rolePermissionIds = array_map('intval', $_POST['permissions'] ?? []);
    $modalTitle = $roleToEdit['id'] > 0 ? 'Edytuj rolę' : 'Dodaj nową rolę';
    $openModal = true;
}

$roles = $pdo->query("
    SELECT
        r.id,
        r.name,
        r.description,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS permissions_list
    FROM roles r
    LEFT JOIN role_permissions rp ON rp.role_id = r.id
    LEFT JOIN permissions p ON p.id = rp.permission_id
    GROUP BY
        r.id,
        r.name,
        r.description
    ORDER BY
        r.name
")->fetchAll();

$permissions = $pdo->query("
    SELECT id, name, description
    FROM permissions
    ORDER BY name
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Role</h1>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$roles): ?>
            <p class="mb-0">Brak zdefiniowanych ról.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle roles-table">
                    <thead>
                    <tr>
                        <th>Nazwa</th>
                        <th>Opis</th>
                        <th class="roles-permissions-col">Uprawnienia</th>
                        <th class="text-end roles-actions-col">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roles as $roleRow): ?>
                        <tr>
                            <td><?= e($roleRow['name']) ?></td>
                            <td><?= e($roleRow['description'] ?: '-') ?></td>
                            <td class="roles-permissions-col"><?= e($roleRow['permissions_list'] ?: '-') ?></td>
                            <td class="text-end roles-actions-col">
                                <?php if (has_permission($pdo, 'roles.manage')): ?>
                                    <a href="index.php?page=roles&edit=<?= (int) $roleRow['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        Edytuj
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (has_permission($pdo, 'roles.manage')): ?>
                <div class="mt-3">
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#roleModal"
                    >
                        Dodaj nową rolę
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (has_permission($pdo, 'roles.manage')): ?>
    <div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable permissions-modal-dialog">
            <div class="modal-content permissions-modal-content">
                <form method="post" class="permissions-modal-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="save_role">
                    <input type="hidden" name="role_id" value="<?= (int) $roleToEdit['id'] ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="roleModalLabel"><?= e($modalTitle) ?></h5>
                        <a href="index.php?page=roles" class="btn-close"></a>
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
                                <label class="form-label">Nazwa</label>
                                <input type="text" name="name" class="form-control" value="<?= e($roleToEdit['name']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Opis</label>
                                <input type="text" name="description" class="form-control" value="<?= e($roleToEdit['description']) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Uprawnienia</label>
                                <div class="border rounded p-3 permissions-list-box">
                                    <?php foreach ($permissions as $permission): ?>
                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="permissions[]"
                                                value="<?= (int) $permission['id'] ?>"
                                                id="role_perm_<?= (int) $permission['id'] ?>"
                                                <?= in_array((int) $permission['id'], $rolePermissionIds, true) ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="role_perm_<?= (int) $permission['id'] ?>">
                                                <?= e($permission['name']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer permissions-modal-footer">
                        <a href="index.php?page=roles" class="btn btn-outline-secondary">Anuluj</a>
                        <button type="submit" class="btn btn-primary">Zapisz rolę</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($openModal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modalEl = document.getElementById('roleModal');
                if (modalEl) {
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>