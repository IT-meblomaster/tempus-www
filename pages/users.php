<?php
declare(strict_types=1);

if (!has_permission($pdo, 'users.view') && !has_permission($pdo, 'users.manage')) {
    http_response_code(403);
    require __DIR__ . '/forbidden.php';
    return;
}

$editingUserId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$errors = [];
$openModal = false;
$modalTitle = 'Dodaj użytkownika';

$user = [
    'id' => 0,
    'username' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
    'is_active' => 1,
];

$userRoleIds = [];
$roles = get_all_roles($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission($pdo, 'users.manage')) {
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        return;
    }

    verify_csrf();

    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'toggle_active' && $userId > 0) {
        if ($userId === current_user_id()) {
            set_flash('danger', 'Nie możesz zmienić statusu własnego konta.');
            redirect('index.php?page=users');
        }

        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            set_flash('danger', 'Nie znaleziono użytkownika.');
            redirect('index.php?page=users');
        }

        $currentlyActive = (int) $targetUser['is_active'] === 1;
        $isAdmin = user_has_role($pdo, $userId, 'Administrator');

        if ($currentlyActive && $isAdmin && count_active_administrators($pdo) <= 1) {
            set_flash('danger', 'Nie można dezaktywować ostatniego aktywnego administratora.');
            redirect('index.php?page=users');
        }

        $stmt = $pdo->prepare('
            UPDATE users
            SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
            WHERE id = :id
        ');
        $stmt->execute(['id' => $userId]);

        set_flash('success', 'Status użytkownika został zmieniony.');
        redirect('index.php?page=users');
    }

    if ($action === 'save_user') {
        $isEdit = $userId > 0;

        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $selectedRoles = array_values(array_unique(array_map('intval', $_POST['roles'] ?? [])));

        $user = [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => $isActive,
        ];
        $userRoleIds = $selectedRoles;
        $modalTitle = $isEdit ? 'Edytuj użytkownika' : 'Dodaj użytkownika';
        $openModal = true;

        if ($username === '') {
            $errors[] = 'Login jest wymagany.';
        }

        if (!$isEdit && $password === '') {
            $errors[] = 'Hasło jest wymagane przy tworzeniu użytkownika.';
        }

        if ($password !== '' && mb_strlen($password) < 6) {
            $errors[] = 'Hasło musi mieć co najmniej 6 znaków.';
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
        $stmt->execute([
            'username' => $username,
            'id' => $isEdit ? $userId : 0,
        ]);
        if ($stmt->fetch()) {
            $errors[] = 'Taki login już istnieje.';
        }

        if ($email !== '') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $stmt->execute([
                'email' => $email,
                'id' => $isEdit ? $userId : 0,
            ]);
            if ($stmt->fetch()) {
                $errors[] = 'Taki adres email już istnieje.';
            }
        }

        $validRoleIds = array_map('intval', array_column($roles, 'id'));
        foreach ($selectedRoles as $roleId) {
            if (!in_array($roleId, $validRoleIds, true)) {
                $errors[] = 'Wybrano nieprawidłową rolę.';
                break;
            }
        }

        $selectedRoleNames = [];
        foreach ($roles as $role) {
            if (in_array((int) $role['id'], $selectedRoles, true)) {
                $selectedRoleNames[] = (string) $role['name'];
            }
        }

        if ($isEdit) {
            $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                $wasActive = (int) $existingUser['is_active'] === 1;
                $willBeActive = $isActive === 1;
                $willBeAdmin = in_array('Administrator', $selectedRoleNames, true);
                $wasAdmin = user_has_role($pdo, $userId, 'Administrator');

                if ($userId === current_user_id() && !$willBeActive) {
                    $errors[] = 'Nie możesz wyłączyć własnego konta.';
                }

                if ($wasActive && $wasAdmin && (!$willBeActive || !$willBeAdmin) && count_active_administrators($pdo) <= 1) {
                    $errors[] = 'Nie można odebrać aktywności lub roli ostatniemu aktywnemu administratorowi.';
                }
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();

            try {
                if ($isEdit) {
                    $stmt = $pdo->prepare('
                        UPDATE users
                        SET username = :username,
                            email = :email,
                            first_name = :first_name,
                            last_name = :last_name,
                            is_active = :is_active
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        'username' => $username,
                        'email' => $email !== '' ? $email : null,
                        'first_name' => $firstName !== '' ? $firstName : null,
                        'last_name' => $lastName !== '' ? $lastName : null,
                        'is_active' => $isActive,
                        'id' => $userId,
                    ]);

                    if ($password !== '') {
                        $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                        $stmt->execute([
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'id' => $userId,
                        ]);
                    }
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO users (username, email, password_hash, first_name, last_name, is_active)
                        VALUES (:username, :email, :password_hash, :first_name, :last_name, :is_active)
                    ');
                    $stmt->execute([
                        'username' => $username,
                        'email' => $email !== '' ? $email : null,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'first_name' => $firstName !== '' ? $firstName : null,
                        'last_name' => $lastName !== '' ? $lastName : null,
                        'is_active' => $isActive,
                    ]);

                    $userId = (int) $pdo->lastInsertId();
                }

                save_user_roles($pdo, $userId, $selectedRoles);

                $pdo->commit();
                set_flash('success', $isEdit ? 'Użytkownik został zaktualizowany.' : 'Użytkownik został dodany.');
                redirect('index.php?page=users');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Nie udało się zapisać użytkownika.';
            }
        }
    }
}

if ($editingUserId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingUserId]);
    $found = $stmt->fetch();

    if ($found) {
        $user = $found;
        $userRoleIds = get_user_role_ids($pdo, $editingUserId);
        $modalTitle = 'Edytuj użytkownika: ' . ($user['username'] ?: ('#' . $editingUserId));
        $openModal = true;
    }
}

$stmt = $pdo->query('
    SELECT
        u.id,
        u.username,
        u.email,
        u.first_name,
        u.last_name,
        u.is_active,
        u.last_login_at,
        GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    GROUP BY u.id, u.username, u.email, u.first_name, u.last_name, u.is_active, u.last_login_at
    ORDER BY u.username
');
$users = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Użytkownicy</h1>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$users): ?>
            <p class="mb-0">Brak użytkowników.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <colgroup>
                        <col style="width: 12%">
                        <col style="width: 15%">
                        <col style="width: 22%">
                        <col style="width: 18%">
                        <col style="width: 8%">
                        <col style="width: 10%">
                        <col style="width: 15%">
                    </colgroup>
                    <thead>
                    <tr>
                        <th>Login</th>
                        <th>Imię i nazwisko</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Ostatnie<br>logowanie</th>
                        <th class="text-end">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $row): ?>
                        <?php
                            $lastLogin = $row['last_login_at'] ?? '';
                            $lastLoginDate = '';
                            $lastLoginTime = '';
                            if ($lastLogin) {
                                $dt = new DateTimeImmutable($lastLogin);
                                $lastLoginDate = $dt->format('d.m.Y');
                                $lastLoginTime = $dt->format('H:i');
                            }
                        ?>
                        <tr>
                            <td><?= e($row['username']) ?></td>
                            <td><?= e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?></td>
                            <td><?= e($row['email']) ?></td>
                            <td><?= e($row['roles'] ?: '-') ?></td>
                            <td>
                                <?php if ((int) $row['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Aktywny</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nieaktywny</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($lastLogin): ?>
                                    <?= e($lastLoginDate) ?><br>
                                    <small class="text-muted"><?= e($lastLoginTime) ?></small>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (has_permission($pdo, 'users.manage')): ?>
                                    <a href="index.php?page=users&edit=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        Edytuj
                                    </a>

                                    <form
                                        method="post"
                                        class="d-inline"
                                        onsubmit="return confirm('Czy na pewno chcesz zmienić status tego użytkownika?');"
                                    >
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <?= (int) $row['is_active'] === 1 ? 'Dezaktywuj' : 'Aktywuj' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (has_permission($pdo, 'users.manage')): ?>
                <div class="mt-3">
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#userModal"
                    >
                        Dodaj nowego użytkownika
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (has_permission($pdo, 'users.manage')): ?>
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="userModalLabel"><?= e($modalTitle) ?></h5>
                        <a href="index.php?page=users" class="btn-close"></a>
                    </div>

                    <div class="modal-body">
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
                                <label class="form-label">Login</label>
                                <input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Imię</label>
                                <input type="text" name="first_name" class="form-control" value="<?= e($user['first_name']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Nazwisko</label>
                                <input type="text" name="last_name" class="form-control" value="<?= e($user['last_name']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">
                                    <?= (int) $user['id'] > 0 ? 'Nowe hasło (opcjonalnie)' : 'Hasło' ?>
                                </label>
                                <input type="password" name="password" class="form-control" <?= (int) $user['id'] > 0 ? '' : 'required' ?>>
                            </div>

                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="is_active"
                                        id="is_active"
                                        value="1"
                                        <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="is_active">
                                        Użytkownik aktywny
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Role</label>
                                <div class="row">
                                    <?php foreach ($roles as $role): ?>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="roles[]"
                                                    id="role_<?= (int) $role['id'] ?>"
                                                    value="<?= (int) $role['id'] ?>"
                                                    <?= in_array((int) $role['id'], $userRoleIds, true) ? 'checked' : '' ?>
                                                >
                                                <label class="form-check-label" for="role_<?= (int) $role['id'] ?>">
                                                    <?= e($role['name']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <a href="index.php?page=users" class="btn btn-outline-secondary">Anuluj</a>
                        <button type="submit" class="btn btn-primary">
                            <?= (int) $user['id'] > 0 ? 'Zapisz zmiany' : 'Dodaj użytkownika' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($openModal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modalEl = document.getElementById('userModal');
                if (modalEl) {
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>