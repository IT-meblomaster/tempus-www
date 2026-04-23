-- =========================================================
-- template-www seed
-- =========================================================

SET NAMES utf8mb4;

-- Users
INSERT INTO users (id, username, email, password_hash, first_name, last_name, is_active)
VALUES
    (
        1,
        'admin',
        'admin@example.com',
        '$2y$10$ElLH9cXnQ3OYo2r.ele78epDByBGMo2kxExciZiR41Zv0XF3pTH5K',
        'System',
        'Administrator',
        1
    );

-- Roles
INSERT INTO roles (id, name, description)
VALUES
    (1, 'Administrator', 'Pełny dostęp do systemu'),
    (2, 'Użytkownik', 'Podstawowy dostęp do systemu');

-- Permissions
INSERT INTO permissions (id, name, description)
VALUES
    (1, 'dashboard.view', 'Podgląd dashboardu'),
    (2, 'users.view', 'Podgląd użytkowników'),
    (3, 'users.manage', 'Zarządzanie użytkownikami'),
    (4, 'roles.view', 'Podgląd ról'),
    (5, 'roles.manage', 'Zarządzanie rolami'),
    (6, 'permissions.view', 'Podgląd uprawnień stron'),
    (7, 'permissions.manage', 'Zarządzanie uprawnieniami stron'),
    (8, 'menu.view', 'Podgląd menu'),
    (9, 'menu.manage', 'Zarządzanie menu');

-- Role -> permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

INSERT INTO role_permissions (role_id, permission_id)
VALUES
    (2, 1);

-- User -> role
INSERT INTO user_roles (user_id, role_id)
VALUES
    (1, 1);

-- Pages
INSERT INTO pages (id, slug, title, file_path, is_public, is_system, is_active)
VALUES
    (1, 'home', 'Strona główna', 'pages/home.php', 1, 1, 1),
    (2, 'login', 'Logowanie', 'pages/login.php', 1, 1, 1),
    (3, 'logout', 'Wylogowanie', 'pages/logout.php', 0, 1, 1),
    (4, 'forbidden', 'Brak dostępu', 'pages/forbidden.php', 1, 1, 1),
    (5, 'dashboard', 'Dashboard', 'pages/dashboard.php', 0, 1, 1),
    (6, 'users', 'Użytkownicy', 'pages/users.php', 0, 1, 1),
    (7, 'roles', 'Role', 'pages/roles.php', 0, 1, 1),
    (8, 'permissions', 'Uprawnienia', 'pages/permissions.php', 0, 1, 1),
    (9, 'menu_manager', 'Menedżer menu', 'pages/menu_manager.php', 0, 1, 1);

-- Page -> permissions
INSERT INTO page_permissions (page_id, permission_id)
VALUES
    (5, 1),
    (6, 2),
    (6, 3),
    (7, 4),
    (7, 5),
    (8, 6),
    (8, 7),
    (9, 8),
    (9, 9);

-- Menu items
INSERT INTO menu_items (id, parent_id, page_id, label, url, menu_group, target, sort_order, is_visible, is_system)
VALUES
    (1, NULL, 5, 'Dashboard', NULL, 'main', '_self', 10, 1, 1),
    (2, NULL, NULL, 'Ustawienia', 'internal:container:settings', 'main', '_self', 20, 1, 1),
    (3, 2, NULL, 'Zarządzaj dostępem', 'internal:container:access_management', 'main', '_self', 10, 1, 1),
    (4, 3, 6, 'Użytkownicy', NULL, 'main', '_self', 10, 1, 1),
    (5, 3, 7, 'Role', NULL, 'main', '_self', 20, 1, 1),
    (6, 3, 8, 'Uprawnienia', NULL, 'main', '_self', 30, 1, 1),
    (7, 2, 9, 'Menu', NULL, 'main', '_self', 20, 1, 1),
    (8, NULL, NULL, 'OpenAI', 'https://openai.com/', 'main', '_blank', 999, 0, 0);

-- Optional menu item permissions
INSERT INTO menu_item_permissions (menu_item_id, permission_id)
VALUES
    (1, 1),
    (4, 2),
    (4, 3),
    (5, 4),
    (5, 5),
    (6, 6),
    (6, 7),
    (7, 8),
    (7, 9);