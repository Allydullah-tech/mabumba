<?php
/**
 * MABUMBATECH — Suppliers API
 * GET    -> list/search suppliers, with purchase history stats. Any
 *           logged-in role can view.
 * POST   -> create (administrator, manager, stock_manager)
 * PUT    -> update (administrator, manager, stock_manager)
 * DELETE -> archive (administrator, manager, stock_manager)   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator', 'manager', 'stock_manager'];
$user = require_role(['administrator']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(false, null, 'Supplier not found.', 404);

        $purchasesStmt = $pdo->prepare('SELECT pu.*, p.name AS product_name, v.variant_name
                                         FROM purchases pu
                                         JOIN product_variants v ON v.id = pu.variant_id
                                         JOIN products p ON p.id = v.product_id
                                         WHERE pu.supplier_id = ? ORDER BY pu.purchase_date DESC LIMIT 50');
        $purchasesStmt->execute([$row['id']]);
        $row['purchases'] = $purchasesStmt->fetchAll();
        foreach ($row['purchases'] as &$pr) { $pr['display_name'] = $pr['product_name'] . ' — ' . $pr['variant_name']; }
        unset($pr);

        $productsStmt = $pdo->prepare('SELECT v.id, p.name AS product_name, v.variant_name, v.stock_quantity, v.unit
                                        FROM product_variants v JOIN products p ON p.id = v.product_id
                                        WHERE v.supplier_id = ? ORDER BY p.name ASC, v.variant_name ASC');
        $productsStmt->execute([$row['id']]);
        $row['products_supplied'] = $productsStmt->fetchAll();
        foreach ($row['products_supplied'] as &$ps) { $ps['display_name'] = $ps['product_name'] . ' — ' . $ps['variant_name']; }
        unset($ps);

        respond(true, $row);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['status'])) { $where .= ' AND s.status = ?'; $params[] = clean($_GET['status']); }
    if (!empty($_GET['q'])) { $where .= ' AND (s.name LIKE ? OR s.phone LIKE ?)'; $q = '%' . clean($_GET['q']) . '%'; $params[] = $q; $params[] = $q; }

    $stmt = $pdo->prepare("SELECT s.*,
                                   (SELECT COUNT(*) FROM purchases pu WHERE pu.supplier_id = s.id) AS purchase_count,
                                   (SELECT COALESCE(SUM(pu.total_cost),0) FROM purchases pu WHERE pu.supplier_id = s.id) AS total_purchased
                            FROM suppliers s $where ORDER BY s.name ASC");
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    require_role($MANAGE_ROLES);
    $d = body();
    $missing = missing_fields($d, ['name']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone, email, address, notes, status, created_at) VALUES (?,?,?,?,?,?,NOW())');
    $stmt->execute([
        clean($d['name']), clean($d['phone'] ?? ''), clean($d['email'] ?? ''),
        clean($d['address'] ?? ''), clean($d['notes'] ?? ''), 'active',
    ]);
    $newId = $pdo->lastInsertId();
    log_activity($pdo, $user, 'create', 'supplier', $newId, 'Added supplier: "' . clean($d['name']) . '"');
    respond(true, ['id' => $newId], 'Supplier added successfully.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Supplier id is required.', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Supplier not found.', 404);

    $name = clean($d['name'] ?? $existing['name']);
    $pdo->prepare('UPDATE suppliers SET name=?, phone=?, email=?, address=?, notes=?, status=? WHERE id=?')
        ->execute([
            $name, clean($d['phone'] ?? $existing['phone']), clean($d['email'] ?? $existing['email']),
            clean($d['address'] ?? $existing['address']), clean($d['notes'] ?? $existing['notes']),
            clean($d['status'] ?? $existing['status']), (int)$d['id'],
        ]);

    log_activity($pdo, $user, 'update', 'supplier', $existing['id'], 'Edited supplier "' . $existing['name'] . '"');
    respond(true, null, 'Supplier updated successfully.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Supplier id is required.', 422);
    $lookup = $pdo->prepare('SELECT name FROM suppliers WHERE id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    $pdo->prepare('UPDATE suppliers SET status = "disabled" WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'supplier', $id, 'Archived supplier: "' . ($row['name'] ?? 'unknown') . '"');
    respond(true, null, 'Supplier archived.');
}

respond(false, null, 'Unsupported method.', 405);
