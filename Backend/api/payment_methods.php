<?php
/**
 * MABUMBATECH — Payment Methods API (Settings > Payment Methods)
 * GET    -> list all (any logged-in role — sales screen needs this)
 * POST   -> add a new method (administrator, manager)
 * PUT    -> rename / enable / disable a method (administrator, manager)
 * DELETE -> remove a non-system method (administrator, manager)   ?id=
 *           System methods (Cash) cannot be deleted — disable instead.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $where = !empty($_GET['status']) ? $pdo->quote(clean($_GET['status'])) : null;
    $sql = 'SELECT * FROM payment_methods' . ($where ? " WHERE status = $where" : '') . ' ORDER BY sort_order ASC, name ASC';
    respond(true, $pdo->query($sql)->fetchAll());
}

if ($method === 'POST') {
    require_role($MANAGE_ROLES);
    $d = body();
    $missing = missing_fields($d, ['name']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $name = clean($d['name']);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));

    $stmt = $pdo->prepare('INSERT INTO payment_methods (slug, name, is_system, status, sort_order) VALUES (?,?,0,"active", (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM payment_methods) t))');
    try {
        $stmt->execute([$slug, $name]);
    } catch (PDOException $e) {
        respond(false, null, 'A payment method with that name already exists.', 409);
    }
    $newId = $pdo->lastInsertId();
    log_activity($pdo, $user, 'create', 'payment_method', $newId, 'Added payment method: "' . $name . '"');
    respond(true, ['id' => $newId], 'Payment method added.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Payment method id is required.', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM payment_methods WHERE id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Payment method not found.', 404);

    $pdo->prepare('UPDATE payment_methods SET name = ?, status = ? WHERE id = ?')
        ->execute([
            clean($d['name'] ?? $existing['name']),
            in_array($d['status'] ?? '', ['active', 'disabled'], true) ? $d['status'] : $existing['status'],
            (int)$d['id'],
        ]);

    log_activity($pdo, $user, 'update', 'payment_method', $existing['id'], 'Edited payment method "' . $existing['name'] . '"');
    respond(true, null, 'Payment method updated.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Payment method id is required.', 422);

    $lookup = $pdo->prepare('SELECT * FROM payment_methods WHERE id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    if (!$row) respond(false, null, 'Payment method not found.', 404);
    if ($row['is_system']) respond(false, null, 'This is a system payment method and cannot be removed — you can disable it instead.', 422);

    $pdo->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'payment_method', $id, 'Deleted payment method: "' . $row['name'] . '"');
    respond(true, null, 'Payment method removed.');
}

respond(false, null, 'Unsupported method.', 405);
