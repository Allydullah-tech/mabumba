<?php
/**
 * MABUMBATECH — Products API (PARENT catalog entries only)
 *
 * A "product" here is just the catalog grouping (e.g. "Pen"). All price,
 * stock, SKU/barcode and supplier data lives on its variants/types — see
 * Backend/api/product_variants.php (e.g. "Pen — Marshal", "Pen — Obama").
 * Every product needs at least one variant to actually be sellable.
 *
 * GET    -> list/search products, with aggregated variant stats (variant
 *           count, total stock, price range). Any logged-in role can view.
 *           ?id= returns one product with its full variant list attached.
 * POST   -> create a product (administrator, manager, stock_manager).
 *           Optionally include `first_variant` to create the product and
 *           its first type in one step (most common case: a product that
 *           only has one type, e.g. "Printer Paper A4").
 * PUT    -> update (administrator, manager, stock_manager)
 * DELETE -> archive the product AND all its variants (same roles)   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function resolve_category_id(PDO $pdo, array $d): ?int
{
    if (!empty($d['category_name'])) {
        $cat = clean($d['category_name']);
        $pdo->prepare("INSERT INTO categories (name, type) VALUES (?, 'product') ON DUPLICATE KEY UPDATE name = name")
            ->execute([$cat]);
        $g = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND type = 'product'");
        $g->execute([$cat]);
        return (int)$g->fetchColumn();
    }
    if (isset($d['category_id']) && $d['category_id'] !== '') {
        return (int)$d['category_id'];
    }
    return null;
}

/** Aggregate variant stats used on the product list/detail views. */
function variant_aggregate_select(): string
{
    return "
        COUNT(v.id) AS variant_count,
        COALESCE(SUM(v.stock_quantity), 0) AS total_stock,
        COALESCE(SUM(v.stock_quantity * v.buying_price), 0) AS total_buying_value,
        COALESCE(SUM(v.stock_quantity * v.selling_price), 0) AS total_selling_value,
        MIN(v.selling_price) AS min_selling_price,
        MAX(v.selling_price) AS max_selling_price,
        SUM(CASE WHEN v.stock_quantity <= v.reorder_level AND v.status = 'active' THEN 1 ELSE 0 END) AS low_stock_variant_count
    ";
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(false, null, 'Product not found.', 404);

        $variantsStmt = $pdo->prepare('SELECT v.*, s.name AS supplier_name FROM product_variants v
                                        LEFT JOIN suppliers s ON s.id = v.supplier_id
                                        WHERE v.product_id = ? ORDER BY v.variant_name ASC');
        $variantsStmt->execute([$row['id']]);
        $row['variants'] = $variantsStmt->fetchAll();

        // Workers never see what a product cost to buy, or who supplies it.
        if (!can_see_costs($user)) {
            $row['variants'] = strip_keys_list($row['variants'], ['buying_price', 'supplier_id', 'supplier_name']);
        }

        respond(true, $row);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['status'])) { $where .= ' AND p.status = ?'; $params[] = clean($_GET['status']); }
    if (!empty($_GET['category_id'])) { $where .= ' AND p.category_id = ?'; $params[] = (int)$_GET['category_id']; }
    if (!empty($_GET['q'])) {
        $where .= ' AND (p.name LIKE ? OR EXISTS (SELECT 1 FROM product_variants v2 WHERE v2.product_id = p.id AND (v2.variant_name LIKE ? OR v2.sku LIKE ? OR v2.barcode LIKE ?)))';
        $q = '%' . clean($_GET['q']) . '%';
        array_push($params, $q, $q, $q, $q);
    }
    if (!empty($_GET['low_stock'])) {
        $where .= ' AND EXISTS (SELECT 1 FROM product_variants v3 WHERE v3.product_id = p.id AND v3.status = "active" AND v3.stock_quantity <= v3.reorder_level)';
    }

    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, " . variant_aggregate_select() . "
                            FROM products p
                            LEFT JOIN categories c ON c.id = p.category_id
                            LEFT JOIN product_variants v ON v.product_id = p.id
                            $where GROUP BY p.id ORDER BY p.name ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!can_see_costs($user)) {
        $rows = strip_keys_list($rows, ['total_buying_value']);
    }
    respond(true, $rows);
}

if ($method === 'POST') {
    require_role($MANAGE_ROLES);
    $d = body();
    $missing = missing_fields($d, ['name']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $name = clean($d['name']);
    $categoryId = resolve_category_id($pdo, $d);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT INTO products (name, category_id, image_path, description, status, created_by, created_at, updated_at)
                                VALUES (?,?,?,?,"active",?,NOW(),NOW())');
        $stmt->execute([$name, $categoryId, clean($d['image_path'] ?? ''), clean($d['description'] ?? ''), $user['id']]);
        $newId = $pdo->lastInsertId();

        // Most products only have one type at creation time — let the
        // caller create it in the same step instead of a second request.
        if (!empty($d['first_variant']) && is_array($d['first_variant'])) {
            $fv = $d['first_variant'];
            $variantIns = $pdo->prepare('INSERT INTO product_variants
                (product_id, variant_name, sku, barcode, brand, unit, buying_price, selling_price, minimum_price,
                 stock_quantity, reorder_level, supplier_id, status, created_by, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"active",?,NOW(),NOW())');
            $variantIns->execute([
                $newId, clean($fv['variant_name'] ?? 'Standard'),
                !empty($fv['sku']) ? clean($fv['sku']) : null, !empty($fv['barcode']) ? clean($fv['barcode']) : null,
                clean($fv['brand'] ?? ''), clean($fv['unit'] ?? 'pcs'),
                (float)($fv['buying_price'] ?? 0), (float)($fv['selling_price'] ?? 0),
                isset($fv['minimum_price']) && $fv['minimum_price'] !== '' ? (float)$fv['minimum_price'] : null,
                (int)($fv['stock_quantity'] ?? 0), (int)($fv['reorder_level'] ?? 5),
                !empty($fv['supplier_id']) ? (int)$fv['supplier_id'] : null, $user['id'],
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (strpos($e->getMessage(), 'uniq_variant_sku') !== false) respond(false, null, 'That SKU is already used by another product type.', 409);
        if (strpos($e->getMessage(), 'uniq_variant_barcode') !== false) respond(false, null, 'That barcode is already used by another product type.', 409);
        respond(false, null, 'Could not save this product. Please try again.', 500);
    }

    log_activity($pdo, $user, 'create', 'product', $newId, 'Added product: "' . $name . '"');
    respond(true, ['id' => $newId], 'Product added successfully.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Product id is required.', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Product not found.', 404);

    $categoryId = resolve_category_id($pdo, $d) ?? $existing['category_id'];
    $name = clean($d['name'] ?? $existing['name']);
    $newStatus = clean($d['status'] ?? $existing['status']);

    $pdo->prepare('UPDATE products SET name=?, category_id=?, image_path=?, description=?, status=?, updated_at=NOW() WHERE id=?')
        ->execute([
            $name, $categoryId, clean($d['image_path'] ?? $existing['image_path']),
            clean($d['description'] ?? $existing['description']), $newStatus, (int)$d['id'],
        ]);

    $changes = [];
    if ($existing['name'] !== $name) $changes[] = 'name "' . $existing['name'] . '" to "' . $name . '"';
    if ($existing['status'] !== $newStatus) $changes[] = 'status ' . $existing['status'] . ' to ' . $newStatus;

    log_activity($pdo, $user, 'update', 'product', $existing['id'],
        'Edited "' . $existing['name'] . '"' . ($changes ? ': ' . implode(', ', $changes) : ' (no significant changes)'));

    respond(true, null, 'Updated successfully.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Product id is required.', 422);
    $lookup = $pdo->prepare('SELECT name FROM products WHERE id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE products SET status = "disabled" WHERE id = ?')->execute([$id]);
    $pdo->prepare('UPDATE product_variants SET status = "disabled" WHERE product_id = ?')->execute([$id]);
    $pdo->commit();

    log_activity($pdo, $user, 'delete', 'product', $id, 'Archived product and all its types: "' . ($row['name'] ?? 'unknown') . '"');
    respond(true, null, 'Product archived.');
}

respond(false, null, 'Unsupported method.', 405);
