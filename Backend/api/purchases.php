<?php
/**
 * MABUMBATECH — Purchases API
 * Handles stock being received — one product type at a time, or a bulk
 * list imported from a supplier's Excel/CSV file.
 *
 * GET  -> list recent purchases (any logged-in role can view)
 *         filters: ?source=manual|import
 *                  &period=today|yesterday|week|month|year|custom &date=YYYY-MM-DD &end=YYYY-MM-DD
 * POST -> administrator, manager, stock_manager. body:
 *         { items: [ { product_name, variant_name, quantity, buying_price,
 *                       selling_price, minimum_price, reorder_level,
 *                       category, unit, supplier_id }, ... ],
 *           purchase_date, source, note }
 *         For each item: `product_name` is the parent catalog entry (e.g.
 *         "Pen") and `variant_name` is the specific type (e.g. "Marshal") —
 *         leave variant_name blank for a single-type product and it
 *         becomes "Standard". If that exact product+type already exists
 *         (case-insensitive), its stock is increased and its prices
 *         updated only if new prices were given. Otherwise a new
 *         product and/or variant is created (buying + selling price
 *         required for a brand-new variant; minimum_price and
 *         reorder_level are optional and default to none/5). The whole
 *         batch is all-or-nothing.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/report_helper.php';
require_once __DIR__ . '/../helpers/stock_helper.php';

$MANAGE_ROLES = ['administrator', 'manager', 'stock_manager'];
$user = require_role(['administrator']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['source']) && in_array($_GET['source'], ['manual', 'import'], true)) {
        $where .= ' AND pu.source = ?';
        $params[] = $_GET['source'];
    }
    if (!empty($_GET['period']) && in_array($_GET['period'], ['today', 'yesterday', 'week', 'month', 'year', 'custom'], true)) {
        $anchor = !empty($_GET['date']) ? clean($_GET['date']) : null;
        $endCustom = !empty($_GET['end']) ? clean($_GET['end']) : null;
        [$periodStart, $periodEnd] = resolve_period($_GET['period'], $anchor, $endCustom);
        $where .= ' AND pu.purchase_date BETWEEN ? AND ?';
        $params[] = $periodStart;
        $params[] = $periodEnd;
    }

    $stmt = $pdo->prepare("SELECT pu.*, p.name AS product_name, v.variant_name, v.unit, s.name AS supplier_name, u.full_name AS recorded_by_name
                            FROM purchases pu
                            JOIN product_variants v ON v.id = pu.variant_id
                            JOIN products p ON p.id = v.product_id
                            LEFT JOIN suppliers s ON s.id = pu.supplier_id
                            JOIN users u ON u.id = pu.recorded_by
                            $where ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT $limit");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['display_name'] = $r['product_name'] . ' — ' . $r['variant_name']; }
    unset($r);
    respond(true, $rows);
}

if ($method === 'POST') {
    $user = require_role($MANAGE_ROLES);
    $d = body();
    $items = $d['items'] ?? null;

    if (!is_array($items) || count($items) === 0) {
        respond(false, null, 'No items were submitted.', 422);
    }

    // ---- Pass 1: validate everything, resolve product + variant matches ----
    $resolved = [];
    $errors = [];

    foreach ($items as $i => $item) {
        $rowNum = $i + 1;
        $productName = clean($item['product_name'] ?? $item['name'] ?? '');
        $variantName = clean($item['variant_name'] ?? '') ?: 'Standard';
        $qty = isset($item['quantity']) && $item['quantity'] !== '' ? (int)$item['quantity'] : null;
        $label = $productName . ' — ' . $variantName;

        if ($productName === '') { $errors[] = "Row $rowNum: product name is missing."; continue; }
        if ($qty === null || $qty <= 0) { $errors[] = "Row $rowNum ($label): enter a valid quantity."; continue; }

        $prodStmt = $pdo->prepare('SELECT * FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $prodStmt->execute([$productName]);
        $existingProduct = $prodStmt->fetch();

        $existingVariant = null;
        if ($existingProduct) {
            $varStmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? AND LOWER(variant_name) = LOWER(?) LIMIT 1');
            $varStmt->execute([$existingProduct['id'], $variantName]);
            $existingVariant = $varStmt->fetch();
        }

        $buyingPrice = isset($item['buying_price']) && $item['buying_price'] !== '' ? (float)$item['buying_price'] : null;
        $sellingPrice = isset($item['selling_price']) && $item['selling_price'] !== '' ? (float)$item['selling_price'] : null;
        $minimumPrice = isset($item['minimum_price']) && $item['minimum_price'] !== '' ? (float)$item['minimum_price'] : null;
        $reorderLevel = isset($item['reorder_level']) && $item['reorder_level'] !== '' ? (int)$item['reorder_level'] : 5;

        if (!$existingVariant && ($buyingPrice === null || $sellingPrice === null)) {
            $errors[] = "Row $rowNum ($label): this is a new product type, so both Buying Price and Selling Price are required.";
            continue;
        }

        $resolved[] = [
            'product_name' => $productName, 'variant_name' => $variantName, 'label' => $label,
            'quantity' => $qty, 'buying_price' => $buyingPrice, 'selling_price' => $sellingPrice,
            'minimum_price' => $minimumPrice, 'reorder_level' => $reorderLevel,
            'category' => clean($item['category'] ?? ''), 'unit' => clean($item['unit'] ?? 'pcs') ?: 'pcs',
            'supplier_id' => !empty($item['supplier_id']) ? (int)$item['supplier_id'] : (!empty($d['supplier_id']) ? (int)$d['supplier_id'] : null),
            'existing_product' => $existingProduct, 'existing_variant' => $existingVariant,
        ];
    }

    if (!empty($errors)) {
        respond(false, ['errors' => $errors], 'Please fix the following before importing: ' . implode(' ', $errors), 422);
    }

    // ---- Pass 2: apply all writes in one transaction ----
    try {
        $pdo->beginTransaction();
        $purchaseDate = !empty($d['purchase_date']) ? clean($d['purchase_date']) : today();
        $source = !empty($d['source']) && $d['source'] === 'import' ? 'import' : 'manual';
        $created = 0;
        $updated = 0;

        foreach ($resolved as $row) {
            $productId = $row['existing_product']['id'] ?? null;
            if (!$productId) {
                $categoryId = null;
                if ($row['category'] !== '') {
                    $pdo->prepare("INSERT INTO categories (name, type) VALUES (?, 'product') ON DUPLICATE KEY UPDATE name = name")->execute([$row['category']]);
                    $g = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND type = 'product'");
                    $g->execute([$row['category']]);
                    $categoryId = $g->fetchColumn();
                }
                $pStmt = $pdo->prepare('INSERT INTO products (name, category_id, status, created_by, created_at, updated_at) VALUES (?,?,"active",?,NOW(),NOW())');
                $pStmt->execute([$row['product_name'], $categoryId, $user['id']]);
                $productId = $pdo->lastInsertId();
            }

            if ($row['existing_variant']) {
                $variantId = $row['existing_variant']['id'];
                $fields = ['stock_quantity = stock_quantity + ?'];
                $params = [$row['quantity']];

                if ($row['buying_price'] !== null) { $fields[] = 'buying_price = ?'; $params[] = $row['buying_price']; }
                if ($row['selling_price'] !== null) { $fields[] = 'selling_price = ?'; $params[] = $row['selling_price']; }
                if ($row['minimum_price'] !== null) { $fields[] = 'minimum_price = ?'; $params[] = $row['minimum_price']; }
                if ($row['supplier_id'] !== null) { $fields[] = 'supplier_id = ?'; $params[] = $row['supplier_id']; }
                $fields[] = 'updated_at = NOW()';
                $params[] = $variantId;

                $pdo->prepare('UPDATE product_variants SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
                $buyingPriceUsed = $row['buying_price'] !== null ? $row['buying_price'] : (float)$row['existing_variant']['buying_price'];
                $updated++;
            } else {
                $ins = $pdo->prepare('INSERT INTO product_variants
                    (product_id, variant_name, supplier_id, unit, buying_price, selling_price, minimum_price, stock_quantity, reorder_level, status, created_by, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,"active",?,NOW(),NOW())');
                $ins->execute([
                    $productId, $row['variant_name'], $row['supplier_id'], $row['unit'],
                    $row['buying_price'], $row['selling_price'], $row['minimum_price'], $row['quantity'], $row['reorder_level'], $user['id'],
                ]);
                $variantId = $pdo->lastInsertId();
                $buyingPriceUsed = $row['buying_price'];
                $created++;
            }

            $totalCost = round($row['quantity'] * $buyingPriceUsed, 2);
            if (table_has_column($pdo, 'purchases', 'product_id')) {
                $pdo->prepare('INSERT INTO purchases (variant_id, product_id, supplier_id, quantity, buying_price, total_cost, source, note, recorded_by, purchase_date, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())')
                    ->execute([$variantId, (int)$productId, $row['supplier_id'], $row['quantity'], $buyingPriceUsed, $totalCost, $source, clean($d['note'] ?? ''), $user['id'], $purchaseDate]);
            } else {
                $pdo->prepare('INSERT INTO purchases (variant_id, supplier_id, quantity, buying_price, total_cost, source, note, recorded_by, purchase_date, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                    ->execute([$variantId, $row['supplier_id'], $row['quantity'], $buyingPriceUsed, $totalCost, $source, clean($d['note'] ?? ''), $user['id'], $purchaseDate]);
            }

            log_stock_movement($pdo, (int)$variantId, 'purchase', (int)$row['quantity'], 'purchases', (int)$pdo->lastInsertId(), 'Stock received', $user['id']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('MABUMBATECH purchases.php save failed: ' . $e->getMessage());
        // Show the real reason (this endpoint is staff-only) so a failure can be fixed
        // instead of just retried.
        respond(false, null, 'Could not save the purchase: ' . $e->getMessage(), 500);
    }

    $itemSummary = count($resolved) === 1
        ? '"' . $resolved[0]['label'] . '" (' . $resolved[0]['quantity'] . ' ' . $resolved[0]['unit'] . ')'
        : count($resolved) . ' item(s)';
    log_activity($pdo, $user, 'create', 'purchase', null,
        'Recorded a ' . $source . ' purchase of ' . $itemSummary . ': ' . $created . ' new type(s) added, ' . $updated . ' type(s) restocked');

    respond(true, ['created' => $created, 'updated' => $updated], "Stock updated: $created new type(s) added, $updated existing type(s) restocked.");
}

respond(false, null, 'Unsupported method.', 405);
