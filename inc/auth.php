<?php

declare(strict_types=1);

function start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name($config['security']['session_name'] ?? 'template_www_sess');

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_user(PDO $pdo): ?array
{
    $userId = current_user_id();

    if (!$userId) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT id, username, email, first_name, last_name, is_active, last_login_at
        FROM users
        WHERE id = :id AND is_active = 1
        LIMIT 1
    ');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('
        SELECT id, password_hash, is_active
        FROM users
        WHERE username = :username
        LIMIT 1
    ');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int) $user['id'];

    $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $update->execute([':id' => (int) $user['id']]);

    return true;
}

function login_user(PDO $pdo, string $username, string $password): bool
{
    return login($pdo, $username, $password);
}

function logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
}

function logout_user(): void
{
    logout();
}

function get_all_roles(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, description FROM roles ORDER BY name');
    return $stmt->fetchAll();
}

function get_user_role_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT role_id FROM user_roles WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);

    return array_map('intval', array_column($stmt->fetchAll(), 'role_id'));
}

function save_user_roles(PDO $pdo, int $userId, array $roleIds): void
{
    $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')
        ->execute(['user_id' => $userId]);

    if (!$roleIds) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO user_roles (user_id, role_id)
        VALUES (:user_id, :role_id)
    ');

    foreach ($roleIds as $roleId) {
        if ($roleId <= 0) {
            continue;
        }

        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }
}

function user_has_role(PDO $pdo, int $userId, string $roleName): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = :user_id
          AND r.name = :role_name
        LIMIT 1
    ');
    $stmt->execute(['user_id' => $userId, 'role_name' => $roleName]);

    return (bool) $stmt->fetchColumn();
}

function count_active_administrators(PDO $pdo): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE u.is_active = 1
          AND r.name = :role_name
    ');
    $stmt->execute(['role_name' => 'Administrator']);

    return (int) $stmt->fetchColumn();
}