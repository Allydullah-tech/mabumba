<?php
/**
 * MABUMBATECH — Categories API
 * Manages both product/service categories (`categories`, with a `type`)
 * and expense categories (`expense_categories`) — selected via ?kind=.
 *
 * GET    -> list. ?kind=product|service|expense (default: product)
 * POST   -> create   { name, kind }
 * PUT    -> rename    { id, name, kind }
 * DELETE -> remove    ?id=&kind=   (blocked if still in use)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function kind_table(string $kind): array
{
    if ($kind === 'expense') return ['expense_categories', null];
    return ['categories', in_array($kind, ['product', 'service'], true) ? $kind : 'product'];
}

$kind = clean($_GET['kind'] ?? ($_POST['kind'] ?? 'product'));

if ($method === 'GET') {
    [$table, $type] = kind_table($kind);
    if ($type) {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE type = ? ORDER BY name ASC");
        $stmt->execute([$type]);
    } else {
        $stmt = $pdo->query("SELECT * FROM $table ORDER BY name ASC");
    }
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    require_role($MANAGE_ROLES);
    $d = body();
    $kind = clean($d['kind'] ?? 'product');
    [$table, $type] = kind_table($kind);
    if (empty($d['name'])) respond(false, null, 'Category name is required.', 422);
    $name = clean($d['name']);

    try {
        if ($type) {
            $pdo->prepare("INSERT INTO $table (name, type) VALUES (?, ?)")->execute([$name, $type]);
        } else {
            $pdo->prepare("INSERT INTO $table (name) VALUES (?)")->execute([$name]);
        }
    } catch (PDOException $e) {
        respond(false, null, 'That category already exists.', 409);
    }
    $newId = $pdo->lastInsertId();
    log_activity($pdo, $user, 'create', 'category', $newId, 'Added ' . $kind . ' category: "' . $name . '"');
    respond(true, ['id' => $newId], 'Category added.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    $kind = clean($d['kind'] ?? 'product');
    [$table, $type] = kind_table($kind);
    if (empty($d['id']) || empty($d['name'])) respond(false, null, 'Category id and name are required.', 422);

    $pdo->prepare("UPDATE $table SET name = ? WHERE id = ?")->execute([clean($d['name']), (int)$d['id']]);
    log_activity($pdo, $user, 'update', 'category', (int)$d['id'], 'Renamed ' . $kind . ' category to "' . clean($d['name']) . '"');
    respond(true, null, 'Category updated.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $kind = clean($_GET['kind'] ?? 'product');
    [$table, $type] = kind_table($kind);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Category id is required.', 422);

    // Block deletion if still referenced, rather than silently orphaning rows.
    $inUseTable = $kind === 'expense' ? 'expenses' : ($kind === 'service' ? 'services' : 'products');
    $inUseColumn = $kind === 'expense' ? 'category_id' : 'category_id';
    $check = $pdo->prepare("SELECT COUNT(*) FROM $inUseTable WHERE $inUseColumn = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        respond(false, null, 'This category is still in use and cannot be deleted.', 422);
    }

    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
    log_activity($pdo, $user, 'delete', 'category', $id, 'Deleted ' . $kind . ' category');
    respond(true, null, 'Category deleted.');
}

respond(false, null, 'Unsupported method.', 405);
