<?php
declare(strict_types=1);

$flashMessages = get_flash_messages();
$currentPage = current_page();
$menuTree = get_visible_menu_tree($pdo);

function menu_contains_current(array $items, string $currentPage): bool
{
    foreach ($items as $item) {
        $pageSlug = (string) ($item['page_slug'] ?? '');

        if ($pageSlug === $currentPage) {
            return true;
        }

        $children = $item['children'] ?? [];
        if (is_array($children) && $children !== [] && menu_contains_current($children, $currentPage)) {
            return true;
        }
    }

    return false;
}

function render_menu_tree(array $items, string $currentPage, int $level = 0): void
{
    foreach ($items as $item) {
        $label = (string) ($item['label'] ?? '');
        $children = $item['children'] ?? [];
        $hasChildren = is_array($children) && $children !== [];
        $pageSlug = (string) ($item['page_slug'] ?? '');
        $href = menu_item_href($item);
        $target = menu_item_target($item);
        $isActive = ($pageSlug !== '' && $currentPage === $pageSlug) || ($hasChildren && menu_contains_current($children, $currentPage));
        $isSeparator = str_starts_with((string) ($item['url'] ?? ''), 'internal:separator');

        if ($isSeparator && $level > 0) {
            echo '<li><hr class="dropdown-divider"></li>';
            continue;
        }

        if ($level === 0) {
            if ($hasChildren) {
                ?>
                <li class="nav-item dropdown">
                    <button
                        class="nav-link dropdown-toggle btn btn-link <?= $isActive ? 'active' : '' ?>"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        <?= e($label) ?>
                    </button>
                    <ul class="dropdown-menu">
                        <?php render_menu_tree($children, $currentPage, 1); ?>
                    </ul>
                </li>
                <?php
            } else {
                ?>
                <li class="nav-item">
                    <a
                        class="nav-link <?= $isActive ? 'active' : '' ?>"
                        href="<?= e($href) ?>"
                        target="<?= e($target) ?>"
                        <?= $target === '_blank' ? 'rel="noopener noreferrer"' : '' ?>
                    >
                        <?= e($label) ?>
                    </a>
                </li>
                <?php
            }

            continue;
        }

        if ($hasChildren) {
            ?>
            <li class="dropdown-submenu">
                <button
                    class="dropdown-item submenu-toggle d-flex align-items-center justify-content-between <?= $isActive ? 'active' : '' ?>"
                    type="button"
                    data-submenu-toggle="true"
                    aria-expanded="false"
                >
                    <span><?= e($label) ?></span>
                    <span class="submenu-caret">›</span>
                </button>
                <ul class="dropdown-menu">
                    <?php render_menu_tree($children, $currentPage, $level + 1); ?>
                </ul>
            </li>
            <?php
        } else {
            ?>
            <li>
                <a
                    class="dropdown-item <?= $isActive ? 'active' : '' ?>"
                    href="<?= e($href) ?>"
                    target="<?= e($target) ?>"
                    <?= $target === '_blank' ? 'rel="noopener noreferrer"' : '' ?>
                >
                    <?= e($label) ?>
                </a>
            </li>
            <?php
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app']['name'] ?? 'Template') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(($config['app']['base_url'] ?? '') . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="page">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 app-navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="<?= e(($config['app']['base_url'] ?? '') . '/assets/img/logo.png') ?>" alt="Logo" class="app-navbar-logo">
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainMenu2" aria-controls="mainMenu2" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainMenu2">
                <?php if (is_logged_in()): ?>
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <?php render_menu_tree($menuTree, $currentPage); ?>
                    </ul>
                <?php endif; ?>

                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <?php if (is_logged_in()): ?>
                        <?php $currentUser = current_user($pdo); ?>
                        <li class="nav-item dropdown">
                            <button
                                class="nav-link dropdown-toggle btn btn-link"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                            >
                                <?= e($currentUser['username'] ?? 'Użytkownik') ?>
                            </button>

                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="index.php?page=logout">
                                        Wyloguj
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?page=login">Zaloguj</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container py-2 page-content">
        <?php foreach ($flashMessages as $flash): ?>
            <?php
            $type = $flash['type'] ?? 'info';
            $message = $flash['message'] ?? '';
            ?>
            <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
                <?= e($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
