<?php
declare(strict_types=1);

if (!has_permission($pdo, 'menu.view') && !has_permission($pdo, 'menu.manage')) {
    http_response_code(403);
    require __DIR__ . '/forbidden.php';
    return;
}

$errors = [];
$editingMenuId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$pages = $pdo->query("
    SELECT id, slug, title
    FROM pages
    WHERE is_active = 1
    ORDER BY title, slug
")->fetchAll();

$menuItemsRaw = $pdo->query("
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
        p.title AS page_title
    FROM menu_items mi
    LEFT JOIN pages p ON p.id = mi.page_id
    ORDER BY mi.sort_order, mi.label
")->fetchAll();

$menuItemsById = [];
foreach ($menuItemsRaw as $item) {
    $menuItemsById[(int) $item['id']] = $item;
}

function menu_item_depth(array $itemsById, ?int $menuItemId): int
{
    $depth = 0;

    while ($menuItemId !== null && isset($itemsById[$menuItemId])) {
        $depth++;
        $menuItemId = $itemsById[$menuItemId]['parent_id'] !== null ? (int) $itemsById[$menuItemId]['parent_id'] : null;
    }

    return $depth;
}

function menu_item_descendants(array $itemsById, int $menuItemId): array
{
    $result = [];

    foreach ($itemsById as $candidateId => $candidate) {
        $parentId = $candidate['parent_id'] !== null ? (int) $candidate['parent_id'] : null;

        if ($parentId === $menuItemId) {
            $result[] = (int) $candidateId;
            $result = array_merge($result, menu_item_descendants($itemsById, (int) $candidateId));
        }
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission($pdo, 'menu.manage')) {
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        return;
    }

    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_menu_item') {
        $menuId = (int) ($_POST['menu_id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $type = (string) ($_POST['item_type'] ?? 'page');
        $pageId = (int) ($_POST['page_id'] ?? 0);
        $url = trim((string) ($_POST['url'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $target = (string) ($_POST['target'] ?? '_self');
        $sortOrder = (int) ($_POST['sort_order'] ?? 100);
        $isVisible = isset($_POST['is_visible']) ? 1 : 0;
        $permissionIds = array_values(array_unique(array_map('intval', $_POST['permissions'] ?? [])));

        $originalItem = null;
        if ($menuId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $menuId]);
            $originalItem = $stmt->fetch() ?: null;
        }

        $isSystemItem = $originalItem ? (int) $originalItem['is_system'] === 1 : false;
        $parentId = $parentId > 0 ? $parentId : null;

        if ($label === '') {
            $errors[] = 'Etykieta pozycji menu jest wymagana.';
        }

        if (!in_array($type, ['page', 'external', 'container', 'separator'], true)) {
            $errors[] = 'Nieprawidłowy typ pozycji menu.';
        }

        if (!in_array($target, ['_self', '_blank'], true)) {
            $errors[] = 'Nieprawidłowy target linku.';
        }

        if ($type === 'page') {
            if ($pageId <= 0) {
                $errors[] = 'Dla pozycji typu strona musisz wskazać stronę.';
            }
            $url = '';
        } elseif ($type === 'external') {
            if ($url === '') {
                $errors[] = 'Dla linku zewnętrznego musisz podać URL.';
            }
            $pageId = 0;
        } elseif ($type === 'separator') {
            $pageId = 0;
            $url = 'internal:separator';
            $label = $label !== '' ? $label : '---';
        } else {
            $pageId = 0;
            $url = 'internal:container:' . preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($label));
        }

        if ($menuId > 0 && $parentId === $menuId) {
            $errors[] = 'Pozycja menu nie może być rodzicem samej siebie.';
        }

        if ($menuId > 0 && $parentId !== null) {
            $descendants = menu_item_descendants($menuItemsById, $menuId);
            if (in_array($parentId, $descendants, true)) {
                $errors[] = 'Nie można przypisać pozycji menu do własnego potomka.';
            }
        }

        if ($parentId !== null && isset($menuItemsById[$parentId])) {
            $futureDepth = menu_item_depth($menuItemsById, $parentId) + 1;
            if ($futureDepth > 3) {
                $errors[] = 'Maksymalna głębokość menu to 3 poziomy.';
            }
        }

        if ($isSystemItem && $parentId !== null) {
            $errors[] = 'Systemowa pozycja menu nie może zmieniać rodzica.';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            try {
                if ($menuId > 0) {
                    $stmt = $pdo->prepare('
                        UPDATE menu_items
                        SET parent_id = :parent_id,
                            page_id = :page_id,
                            label = :label,
                            url = :url,
                            target = :target,
                            sort_order = :sort_order,
                            is_visible = :is_visible
                        WHERE id = :id
                    ');
                    $stmt->execute([
                        'parent_id' => $isSystemItem ? $originalItem['parent_id'] : $parentId,
                        'page_id' => $pageId > 0 ? $pageId : null,
                        'label' => $label,
                        'url' => $pageId > 0 ? null : $url,
                        'target' => $target,
                        'sort_order' => $sortOrder,
                        'is_visible' => $isVisible,
                        'id' => $menuId,
                    ]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO menu_items (parent_id, page_id, label, url, menu_group, target, sort_order, is_visible, is_system)
                        VALUES (:parent_id, :page_id, :label, :url, :menu_group, :target, :sort_order, :is_visible, 0)
                    ');
                    $stmt->execute([
                        'parent_id' => $parentId,
                        'page_id' => $pageId > 0 ? $pageId : null,
                        'label' => $label,
                        'url' => $pageId > 0 ? null : $url,
                        'menu_group' => 'main',
                        'target' => $target,
                        'sort_order' => $sortOrder,
                        'is_visible' => $isVisible,
                    ]);
                    $menuId = (int) $pdo->lastInsertId();
                }

                $pdo->prepare('DELETE FROM menu_item_permissions WHERE menu_item_id = :menu_item_id')
                    ->execute(['menu_item_id' => $menuId]);

                if ($permissionIds !== []) {
                    $stmt = $pdo->prepare('
                        INSERT INTO menu_item_permissions (menu_item_id, permission_id)
                        VALUES (:menu_item_id, :permission_id)
                    ');

                    foreach ($permissionIds as $permissionId) {
                        $stmt->execute([
                            'menu_item_id' => $menuId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }

                $pdo->commit();
                set_flash('success', 'Pozycja menu została zapisana.');
                ?>
                <script>
                window.location.replace('index.php?page=menu_manager');
                </script>
                <noscript>
                    <meta http-equiv="refresh" content="0;url=index.php?page=menu_manager">
                </noscript>
                <?php
                return;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Nie udało się zapisać pozycji menu.';
            }
        }
    }

    if ($action === 'delete_menu_item') {
        $menuId = (int) ($_POST['menu_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id, is_system FROM menu_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $menuId]);
        $item = $stmt->fetch();

        if (!$item) {
            $errors[] = 'Nie znaleziono pozycji menu do usunięcia.';
        } elseif ((int) $item['is_system'] === 1) {
            $errors[] = 'Nie można usunąć systemowej pozycji menu.';
        } else {
            try {
                $stmt = $pdo->prepare('DELETE FROM menu_items WHERE id = :id');
                $stmt->execute(['id' => $menuId]);

                set_flash('success', 'Pozycja menu została usunięta.');
                ?>
                <script>
                window.location.replace('index.php?page=menu_manager');
                </script>
                <noscript>
                    <meta http-equiv="refresh" content="0;url=index.php?page=menu_manager">
                </noscript>
                <?php
                return;
            } catch (Throwable $e) {
                $errors[] = 'Nie udało się usunąć pozycji menu.';
            }
        }
    }
}

$menuToEdit = [
    'id' => 0,
    'parent_id' => null,
    'page_id' => null,
    'label' => '',
    'url' => '',
    'menu_group' => 'main',
    'target' => '_self',
    'sort_order' => 100,
    'is_visible' => 1,
    'is_system' => 0,
];

$menuPermissionIds = [];
$modalTitle = 'Dodaj pozycję menu';
$openModal = false;
$itemType = 'page';

if ($editingMenuId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingMenuId]);
    $found = $stmt->fetch();

    if ($found) {
        $menuToEdit = $found;
        $itemType = $menuToEdit['page_id']
            ? 'page'
            : (str_starts_with((string) $menuToEdit['url'], 'internal:separator') ? 'separator' : (str_starts_with((string) $menuToEdit['url'], 'internal:container:') ? 'container' : 'external'));
        $modalTitle = 'Edytuj pozycję menu: ' . ($menuToEdit['label'] ?: ('#' . $menuToEdit['id']));
        $openModal = true;

        $stmt = $pdo->prepare('SELECT permission_id FROM menu_item_permissions WHERE menu_item_id = :menu_item_id');
        $stmt->execute(['menu_item_id' => $editingMenuId]);
        $menuPermissionIds = array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $menuToEdit = [
        'id' => (int) ($_POST['menu_id'] ?? 0),
        'parent_id' => ((int) ($_POST['parent_id'] ?? 0)) ?: null,
        'page_id' => ((int) ($_POST['page_id'] ?? 0)) ?: null,
        'label' => trim((string) ($_POST['label'] ?? '')),
        'url' => trim((string) ($_POST['url'] ?? '')),
        'menu_group' => 'main',
        'target' => (string) ($_POST['target'] ?? '_self'),
        'sort_order' => (int) ($_POST['sort_order'] ?? 100),
        'is_visible' => isset($_POST['is_visible']) ? 1 : 0,
        'is_system' => 0,
    ];
    $menuPermissionIds = array_map('intval', $_POST['permissions'] ?? []);
    $itemType = in_array((string) ($_POST['item_type'] ?? 'page'), ['page', 'external', 'container'], true)
        ? (string) $_POST['item_type']
        : 'page';
    $modalTitle = $menuToEdit['id'] > 0 ? 'Edytuj pozycję menu' : 'Dodaj pozycję menu';
    $openModal = true;
}

$permissions = $pdo->query("
    SELECT id, name, description
    FROM permissions
    ORDER BY name
")->fetchAll();

$menuItems = $pdo->query("
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
        parent.label AS parent_label,
        p.slug AS page_slug,
        GROUP_CONCAT(DISTINCT perm.name ORDER BY perm.name SEPARATOR ', ') AS permissions_list
    FROM menu_items mi
    LEFT JOIN menu_items parent ON parent.id = mi.parent_id
    LEFT JOIN pages p ON p.id = mi.page_id
    LEFT JOIN menu_item_permissions mip ON mip.menu_item_id = mi.id
    LEFT JOIN permissions perm ON perm.id = mip.permission_id
    GROUP BY
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
        parent.label,
        p.slug
    ORDER BY
        COALESCE(parent.label, ''),
        mi.sort_order,
        mi.label
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Menedżer menu</h1>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$menuItems): ?>
            <p class="mb-0">Brak zdefiniowanych pozycji menu.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle permissions-table menu-manager-table">
                    <thead>
                    <tr>
                        <th>Etykieta</th>
                        <th>Strona / URL</th>
                        <th>Rodzic</th>
                        <th class="menu-manager-col-target">Target</th>
                        <th class="menu-manager-col-visible">Widoczna</th>
                        <th class="menu-manager-col-sort">Sort</th>
                        <th>Uprawnienia</th>
                        <th class="text-end menu-manager-col-actions">Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($menuItems as $row): ?>
                        <?php
                        $rowType = $row['page_id']
                            ? 'page'
                            : (str_starts_with((string) $row['url'], 'internal:separator') ? 'separator' : (str_starts_with((string) $row['url'], 'internal:container:') ? 'container' : 'external'));
                        $rowDestination = $row['page_slug'] ? ('page=' . $row['page_slug']) : (string) $row['url'];
                        $badgeClass = match ($rowType) {
                            'page' => 'menu-item-label-page',
                            'container' => 'menu-item-label-container',
                            'separator' => 'menu-item-label-separator',
                            default => 'menu-item-label-external',
                        };
                        ?>
                        <tr>
                            <td>
                                <span class="menu-item-label <?= e($badgeClass) ?>">
                                    <?= e($row['label']) ?>
                                </span>
                            </td>
                            <td><?= e($rowDestination) ?></td>
                            <td><?= e($row['parent_label'] ?: '-') ?></td>
                            <td class="menu-manager-col-target"><?= e($row['target']) ?></td>
                            <td class="menu-manager-col-visible">
                                <?php if ((int) $row['is_visible'] === 1): ?>
                                    <span class="badge text-bg-success">Tak</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td class="menu-manager-col-sort"><?= (int) $row['sort_order'] ?></td>
                            <td><?= e($row['permissions_list'] ?: '-') ?></td>
                            <td class="text-end menu-manager-col-actions">
                                <?php if (has_permission($pdo, 'menu.manage')): ?>
                                    <div class="d-inline-flex gap-1">
                                        <a href="index.php?page=menu_manager&edit=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            Edytuj
                                        </a>
                                        <?php if ((int) $row['is_system'] !== 1): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć tę pozycję menu?');">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="delete_menu_item">
                                                <input type="hidden" name="menu_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Usuń</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="menu-manager-legend mt-3">
                <span class="menu-item-label menu-item-label-page">Strona</span>
                <span class="menu-item-label menu-item-label-container">Kontener</span>
                <span class="menu-item-label menu-item-label-external">Link zewnętrzny</span>
                <span class="menu-item-label menu-item-label-separator">Separator</span>
            </div>

            <?php if (has_permission($pdo, 'menu.manage')): ?>
                <div class="mt-3">
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#menuItemModal"
                    >
                        Dodaj pozycję menu
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (has_permission($pdo, 'menu.manage')): ?>
    <div class="modal fade" id="menuItemModal" tabindex="-1" aria-labelledby="menuItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable permissions-modal-dialog">
            <div class="modal-content permissions-modal-content">
                <form method="post" class="permissions-modal-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="save_menu_item">
                    <input type="hidden" name="menu_id" value="<?= (int) $menuToEdit['id'] ?>">

                    <div class="modal-header">
                        <h5 class="modal-title" id="menuItemModalLabel"><?= e($modalTitle) ?></h5>
                        <a href="index.php?page=menu_manager" class="btn-close"></a>
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
                                <label class="form-label">Etykieta</label>
                                <input type="text" name="label" class="form-control" value="<?= e($menuToEdit['label']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Typ pozycji</label>
                                <select name="item_type" class="form-select">
                                    <option value="page" <?= $itemType === 'page' ? 'selected' : '' ?>>Strona</option>
                                    <option value="external" <?= $itemType === 'external' ? 'selected' : '' ?>>Link zewnętrzny</option>
                                    <option value="container" <?= $itemType === 'container' ? 'selected' : '' ?>>Kontener</option>
                                    <option value="separator" <?= $itemType === 'separator' ? 'selected' : '' ?>>Separator (kreska)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Strona</label>
                                <select name="page_id" class="form-select">
                                    <option value="0">— brak —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option
                                            value="<?= (int) $page['id'] ?>"
                                            <?= (int) ($menuToEdit['page_id'] ?? 0) === (int) $page['id'] ? 'selected' : '' ?>
                                        >
                                            <?= e($page['title']) ?> (<?= e($page['slug']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">URL</label>
                                <input type="text" name="url" class="form-control" value="<?= e((string) $menuToEdit['url']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Rodzic</label>
                                <select name="parent_id" class="form-select">
                                    <option value="0">— brak —</option>
                                    <?php foreach ($menuItemsRaw as $parentOption): ?>
                                        <?php
                                        $parentOptionId = (int) $parentOption['id'];
                                        if ($menuToEdit['id'] && $parentOptionId === (int) $menuToEdit['id']) {
                                            continue;
                                        }
                                        ?>
                                        <option
                                            value="<?= $parentOptionId ?>"
                                            <?= (int) ($menuToEdit['parent_id'] ?? 0) === $parentOptionId ? 'selected' : '' ?>
                                        >
                                            <?= e($parentOption['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Target</label>
                                <select name="target" class="form-select">
                                    <option value="_self" <?= (string) $menuToEdit['target'] === '_self' ? 'selected' : '' ?>>_self</option>
                                    <option value="_blank" <?= (string) $menuToEdit['target'] === '_blank' ? 'selected' : '' ?>>_blank</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Kolejność</label>
                                <input type="number" name="sort_order" class="form-control" value="<?= (int) $menuToEdit['sort_order'] ?>">
                            </div>

                            <div class="col-md-3">
                                <div class="form-check mt-4">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="is_visible"
                                        id="is_visible"
                                        value="1"
                                        <?= (int) $menuToEdit['is_visible'] === 1 ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="is_visible">
                                        Widoczna w menu
                                    </label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Uprawnienia pozycji menu</label>
                                <div class="border rounded p-3 permissions-list-box">
                                    <?php foreach ($permissions as $permission): ?>
                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="permissions[]"
                                                value="<?= (int) $permission['id'] ?>"
                                                id="menu_perm_<?= (int) $permission['id'] ?>"
                                                <?= in_array((int) $permission['id'], $menuPermissionIds, true) ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="menu_perm_<?= (int) $permission['id'] ?>">
                                                <?= e($permission['name']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer permissions-modal-footer">
                        <a href="index.php?page=menu_manager" class="btn btn-outline-secondary">Anuluj</a>
                        <button type="submit" class="btn btn-primary">Zapisz pozycję</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($openModal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modalEl = document.getElementById('menuItemModal');
                if (modalEl) {
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>