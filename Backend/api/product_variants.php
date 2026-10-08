<?php
/**
 * MABUMBATECH — Product Variants API (the actual sellable/stock-tracked types)
 *
 * A variant is a specific type of a product — e.g. product "Pen" might
 * have variants "Marshal", "Obama", "Maker", each with its own price,
 * stock, SKU and minimum selling price. A product with only one type
 * still has exactly one variant (conventionally named "Standard").
 *
 * GET    -> list/search variants. Any logged-in role can view — the
 *           Sales screen needs this to build a cart.
 *           ?product_id= restricts to one parent's variants.
 *           ?id= returns a single variant (with parent name attached).
 *           ?low_stock=1 restricts to variants at/under their reorder level.
 *           ?names_only=1 returns a lightweight list with a ready-to-display
 *           "Parent — Variant" label, for pickers (Sales, Damages, Stock).
 * POST   -> create a new variant under an existing product
 *           (administrator, manager, stock_manager).
 * PUT    -> update a variant (same roles).
 * DELETE -> archive a variant (same roles)   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/stock_helper.php';

$MANAGE_ROLES = ['administrator'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function variant_display_name(string $productName, string $variantName): string
{
    // Always "Product — Type", including "Standard", so every type is named exactly as stored.
    return $productName . ' — ' . $variantName;
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT v.*, p.name AS product_name, p.category_id, s.name AS supplier_name
                                FROM product_variants v
                                JOIN products p ON p.id = v.product_id
                                LEFT JOIN suppliers s ON s.id = v.supplier_id
                                WHERE v.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(false, null, 'Product type not found.', 404);
        $row['display_name'] = variant_display_name($row['product_name'], $row['variant_name']);
        if (!can_see_costs($user)) $row = strip_keys($row, ['buying_price', 'supplier_id', 'supplier_name']);
        respond(true, $row);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['product_id'])) { $where .= ' AND v.product_id = ?'; $params[] = (int)$_GET['product_id']; }
    if (!empty($_GET['status'])) { $where .= ' AND v.status = ?'; $params[] = clean($_GET['status']); }
    if (!empty($_GET['low_stock'])) { $where .= ' AND v.stock_quantity <= v.reorder_level'; }
    if (!empty($_GET['q'])) {
        $where .= ' AND (p.name LIKE ? OR v.variant_name LIKE ? OR v.sku LIKE ? OR v.barcode LIKE ?)';
        $q = '%' . clean($_GET['q']) . '%';
        array_push($params, $q, $q, $q, $q);
    }

    if (!empty($_GET['names_only'])) {
        $stmt = $pdo->prepare("SELECT v.id, p.id AS product_id, p.name AS product_name, v.variant_name, v.unit, v.selling_price, v.minimum_price, v.stock_quantity, v.reorder_level
                                FROM product_variants v JOIN products p ON p.id = v.product_id
                                $where ORDER BY p.name ASC, v.variant_name ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['display_name'] = variant_display_name($r['product_name'], $r['variant_name']); }
        unset($r);
        respond(true, $rows);
    }

    $stmt = $pdo->prepare("SELECT v.*, p.name AS product_name, s.name AS supplier_name
                            FROM product_variants v
                            JOIN products p ON p.id = v.product_id
                            LEFT JOIN suppliers s ON s.id = v.supplier_id
                            $where ORDER BY p.name ASC, v.variant_name ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['display_name'] = variant_display_name($r['product_name'], $r['variant_name']); }
    unset($r);
    if (!can_see_costs($user)) $rows = strip_keys_list($rows, ['buying_price', 'supplier_id', 'supplier_name']);
    respond(true, $rows);
}

if ($method === 'POST') {
    require_role($MANAGE_ROLES);
    $d = body();
    $missing = missing_fields($d, ['product_id']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $productStmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
    $productStmt->execute([(int)$d['product_id']]);
    $product = $productStmt->fetch();
    if (!$product) respond(false, null, 'Parent product not found.', 404);

    $variantName = clean($d['variant_name'] ?? 'Standard') ?: 'Standard';
    $minimumPrice = isset($d['minimum_price']) && $d['minimum_price'] !== '' ? (float)$d['minimum_price'] : null;

    $stmt = $pdo->prepare('INSERT INTO product_variants
        (product_id, variant_name, sku, barcode, brand, unit, buying_price, selling_price, minimum_price,
         stock_quantity, reorder_level, supplier_id, status, created_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"active",?,NOW(),NOW())');
    try {
        $stmt->execute([
            (int)$d['product_id'], $variantName,
            !empty($d['sku']) ? clean($d['sku']) : null, !empty($d['barcode']) ? clean($d['barcode']) : null,
            clean($d['brand'] ?? ''), clean($d['unit'] ?? 'pcs'),
            (float)($d['buying_price'] ?? 0), (float)($d['selling_price'] ?? 0), $minimumPrice,
            (int)($d['stock_quantity'] ?? 0), (int)($d['reorder_level'] ?? 5),
            !empty($d['supplier_id']) ? (int)$d['supplier_id'] : null, $user['id'],
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uniq_variant_name_per_product') !== false) respond(false, null, 'This product already has a type named "' . $variantName . '".', 409);
        if (strpos($e->getMessage(), 'uniq_variant_sku') !== false) respond(false, null, 'That SKU is already used by another product type.', 409);
        if (strpos($e->getMessage(), 'uniq_variant_barcode') !== false) respond(false, null, 'That barcode is already used by another product type.', 409);
        respond(false, null, 'Could not save this product type. Please try again.', 500);
    }

    $newId = $pdo->lastInsertId();
    if ((int)($d['stock_quantity'] ?? 0) > 0) {
        log_stock_movement($pdo, (int)$newId, 'adjustment', (int)$d['stock_quantity'], 'product_variants', (int)$newId, 'Opening stock for new type', $user['id']);
    }

    log_activity($pdo, $user, 'create', 'product_variant', $newId, 'Added type "' . $variantName . '" to "' . $product['name'] . '"');
    respond(true, ['id' => $newId], 'Product type added successfully.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Product type id is required.', 422);

    $existingStmt = $pdo->prepare('SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Product type not found.', 404);

    $variantName = clean($d['variant_name'] ?? $existing['variant_name']) ?: $existing['variant_name'];
    $minimumPrice = isset($d['minimum_price']) && $d['minimum_price'] !== '' ? (float)$d['minimum_price'] : null;
    $newBuying = (float)($d['buying_price'] ?? $existing['buying_price']);
    $newSelling = (float)($d['selling_price'] ?? $existing['selling_price']);
    $newStatus = clean($d['status'] ?? $existing['status']);
    $newStock = isset($d['stock_quantity']) ? (int)$d['stock_quantity'] : (int)$existing['stock_quantity'];
    $stockDelta = $newStock - (int)$existing['stock_quantity'];

    $stmt = $pdo->prepare('UPDATE product_variants SET
        variant_name=?, sku=?, barcode=?, brand=?, unit=?, buying_price=?, selling_price=?, minimum_price=?,
        stock_quantity=?, reorder_level=?, supplier_id=?, status=?, updated_at=NOW()
        WHERE id=?');
    try {
        $stmt->execute([
            $variantName,
            !empty($d['sku']) ? clean($d['sku']) : $existing['sku'],
            !empty($d['barcode']) ? clean($d['barcode']) : $existing['barcode'],
            clean($d['brand'] ?? $existing['brand']), clean($d['unit'] ?? $existing['unit']),
            $newBuying, $newSelling, $minimumPrice, $newStock,
            (int)($d['reorder_level'] ?? $existing['reorder_level']),
            isset($d['supplier_id']) && $d['supplier_id'] !== '' ? (int)$d['supplier_id'] : $existing['supplier_id'],
            $newStatus, (int)$d['id'],
        ]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uniq_variant_name_per_product') !== false) respond(false, null, 'This product already has a type named "' . $variantName . '".', 409);
        if (strpos($e->getMessage(), 'uniq_variant_sku') !== false) respond(false, null, 'That SKU is already used by another product type.', 409);
        if (strpos($e->getMessage(), 'uniq_variant_barcode') !== false) respond(false, null, 'That barcode is already used by another product type.', 409);
        respond(false, null, 'Could not save changes. Please try again.', 500);
    }

    if ($stockDelta !== 0) {
        log_stock_movement($pdo, (int)$d['id'], 'adjustment', $stockDelta, 'product_variants', (int)$d['id'], 'Manual stock adjustment via type edit', $user['id']);
    }

    $changes = [];
    if ($existing['variant_name'] !== $variantName) $changes[] = 'name "' . $existing['variant_name'] . '" to "' . $variantName . '"';
    if ((float)$existing['buying_price'] != $newBuying) $changes[] = 'buying price ' . audit_money_diff($existing['buying_price'], $newBuying);
    if ((float)$existing['selling_price'] != $newSelling) $changes[] = 'selling price ' . audit_money_diff($existing['selling_price'], $newSelling);
    if ($existing['status'] !== $newStatus) $changes[] = 'status ' . $existing['status'] . ' to ' . $newStatus;

    log_activity($pdo, $user, 'update', 'product_variant', $existing['id'],
        'Edited "' . $existing['product_name'] . ' — ' . $existing['variant_name'] . '"' . ($changes ? ': ' . implode(', ', $changes) : ' (no significant changes)'));

    respond(true, null, 'Updated successfully.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Product type id is required.', 422);
    $lookup = $pdo->prepare('SELECT v.variant_name, p.name AS product_name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    $pdo->prepare('UPDATE product_variants SET status = "disabled" WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'product_variant', $id,
        'Archived type: "' . ($row ? $row['product_name'] . ' — ' . $row['variant_name'] : 'unknown') . '"');
    respond(true, null, 'Product type archived.');
}

respond(false, null, 'Unsupported method.', 405);
