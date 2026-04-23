<?php

declare(strict_types=1);

function current_user_roles(PDO $pdo): array
{
    if (!is_logged_in()) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT r.id, r.name
        FROM roles r
        INNER JOIN user_roles ur ON ur.role_id = r.id
        WHERE ur.user_id = :user_id
        ORDER BY r.name
    ');
    $stmt->execute(['user_id' => current_user_id()]);

    return $stmt->fetchAll() ?: [];
}

function current_user_permissions(PDO $pdo): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if (!is_logged_in()) {
        $cache = [];
        return $cache;
    }

    $stmt = $pdo->prepare('
        SELECT DISTINCT p.name
        FROM permissions p
        INNER JOIN role_permissions rp ON rp.permission_id = p.id
        INNER JOIN user_roles ur ON ur.role_id = rp.role_id
        WHERE ur.user_id = :user_id
        ORDER BY p.name
    ');
    $stmt->execute(['user_id' => current_user_id()]);

    $cache = array_values(array_map(
        static fn (array $row): string => (string) $row['name'],
        $stmt->fetchAll() ?: []
    ));

    return $cache;
}

function has_permission(PDO $pdo, string $permissionName): bool
{
    return in_array($permissionName, current_user_permissions($pdo), true);
}

function page_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('
        SELECT id, slug, title, file_path, is_public, is_system, is_active
        FROM pages
        WHERE slug = :slug
        LIMIT 1
    ');
    $stmt->execute(['slug' => $slug]);
    $page = $stmt->fetch();

    return $page ?: null;
}

function page_required_permissions(PDO $pdo, int $pageId): array
{
    $stmt = $pdo->prepare('
        SELECT p.name
        FROM page_permissions pp
        INNER JOIN permissions p ON p.id = pp.permission_id
        WHERE pp.page_id = :page_id
        ORDER BY p.name
    ');
    $stmt->execute(['page_id' => $pageId]);

    return array_values(array_map(
        static fn (array $row): string => (string) $row['name'],
        $stmt->fetchAll() ?: []
    ));
}

function can_access_page(PDO $pdo, string $slug): bool
{
    $page = page_by_slug($pdo, $slug);

    if (!$page) {
        return false;
    }

    if ((int) $page['is_active'] !== 1) {
        return false;
    }

    if ((int) $page['is_public'] === 1) {
        return true;
    }

    if (!is_logged_in()) {
        return false;
    }

    $requiredPermissions = page_required_permissions($pdo, (int) $page['id']);

    if ($requiredPermissions === []) {
        return true;
    }

    $userPermissions = current_user_permissions($pdo);

    foreach ($requiredPermissions as $requiredPermission) {
        if (in_array($requiredPermission, $userPermissions, true)) {
            return true;
        }
    }

    return false;
}

function menu_item_required_permissions(PDO $pdo, int $menuItemId): array
{
    $stmt = $pdo->prepare('
        SELECT p.name
        FROM menu_item_permissions mip
        INNER JOIN permissions p ON p.id = mip.permission_id
        WHERE mip.menu_item_id = :menu_item_id
        ORDER BY p.name
    ');
    $stmt->execute(['menu_item_id' => $menuItemId]);

    return array_values(array_map(
        static fn (array $row): string => (string) $row['name'],
        $stmt->fetchAll() ?: []
    ));
}

function can_view_menu_item(PDO $pdo, array $item): bool
{
    if ((int) ($item['is_visible'] ?? 0) !== 1) {
        return false;
    }

    $requiredPermissions = menu_item_required_permissions($pdo, (int) $item['id']);
    if ($requiredPermissions !== []) {
        if (!is_logged_in()) {
            return false;
        }

        $userPermissions = current_user_permissions($pdo);
        $hasAny = false;

        foreach ($requiredPermissions as $requiredPermission) {
            if (in_array($requiredPermission, $userPermissions, true)) {
                $hasAny = true;
                break;
            }
        }

        if (!$hasAny) {
            return false;
        }
    }

    $pageSlug = $item['page_slug'] ?? null;
    if (is_string($pageSlug) && $pageSlug !== '') {
        return can_access_page($pdo, $pageSlug);
    }

    return true;
}

function menu_item_href(array $item): string
{
    $pageSlug = $item['page_slug'] ?? null;
    if (is_string($pageSlug) && $pageSlug !== '') {
        return 'index.php?page=' . urlencode($pageSlug);
    }

    return (string) ($item['url'] ?? '#');
}

function menu_item_target(array $item): string
{
    $target = (string) ($item['target'] ?? '_self');
    return in_array($target, ['_self', '_blank'], true) ? $target : '_self';
}

function get_visible_menu_tree(PDO $pdo, string $menuGroup = 'main'): array
{
    $stmt = $pdo->prepare('
        SELECT
            mi.id,
            mi.parent_id,
            mi.page_id,
            mi.label,
            mi.url,
            mi.menu_group,
            mi.target,
            mi.sort_order,
            mi.is_visible,
            mi.is_system,
            p.slug AS page_slug,
            p.title AS page_title,
            p.is_public AS page_is_public,
            p.is_active AS page_is_active
        FROM menu_items mi
        LEFT JOIN pages p ON p.id = mi.page_id
        WHERE mi.menu_group = :menu_group
        ORDER BY mi.parent_id IS NULL DESC, mi.parent_id ASC, mi.sort_order ASC, mi.label ASC
    ');
    $stmt->execute(['menu_group' => $menuGroup]);
    $rows = $stmt->fetchAll() ?: [];

    $visible = [];
    foreach ($rows as $row) {
        if ((int) ($row['page_id'] ?? 0) > 0 && (int) ($row['page_is_active'] ?? 0) !== 1) {
            continue;
        }

        if (can_view_menu_item($pdo, $row)) {
            $row['children'] = [];
            $visible[(int) $row['id']] = $row;
        }
    }

    $tree = [];
    foreach ($visible as $id => &$item) {
        $parentId = $item['parent_id'] !== null ? (int) $item['parent_id'] : null;

        if ($parentId !== null && isset($visible[$parentId])) {
            $visible[$parentId]['children'][] = &$item;
        } else {
            $tree[] = &$item;
        }
    }
    unset($item);

    return $tree;
}