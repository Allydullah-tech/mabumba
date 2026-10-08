<?php
/**
 * MABUMBATECH — Damages / Losses API
 * GET    -> list (any logged-in role can view)
 * POST   -> record a damage/loss entry (administrator, worker)
 * PUT/DELETE restricted to administrator only.
 *           body: { item_type: 'product'|'other', reason, damage_date, ... }
 *           - item_type = 'product': { variant_id, quantity }. Loss value
 *             is calculated from the variant's buying price and stock is
 *             reduced automatically.
 *           - item_type = 'other': { item_name, manual_cost }. For
 *             equipment or anything else not tracked in Stock.
 * PUT    -> edit an entry. Cannot switch between product/non-stock type —
 *           that's a delete-and-re-add. Quantity/cost changes re-adjust
 *           stock and loss value automatically.
 * DELETE -> remove an entry, restoring stock for a product-type entry.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/stock_helper.php';

$MANAGE_ROLES = ['administrator'];
$CREATE_ROLES = ['administrator', 'worker'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function damage_variant_label(?string $productName, ?string $variantName): ?string
{
    if (!$productName) return null;
    return $productName . ' — ' . $variantName;
}

if ($method === 'GET') {
    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['start'])) { $where .= ' AND d.damage_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $where .= ' AND d.damage_date <= ?'; $params[] = clean($_GET['end']); }
    $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $stmt = $pdo->prepare("SELECT d.*, p.name AS product_name, v.variant_name, v.unit AS unit, u.full_name AS recorded_by_name
                            FROM damages d
                            LEFT JOIN product_variants v ON v.id = d.variant_id
                            LEFT JOIN products p ON p.id = v.product_id
                            JOIN users u ON u.id = d.recorded_by
                            $where ORDER BY d.damage_date DESC, d.id DESC LIMIT $limit");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Normalise "name" and "cost" regardless of item_type, so the frontend
    // can render one table without branching on every row.
    foreach ($rows as &$row) {
        $row['display_name'] = $row['item_type'] === 'other' ? $row['item_name'] : damage_variant_label($row['product_name'], $row['variant_name']);
        $row['display_cost'] = $row['item_type'] === 'other' ? $row['manual_cost'] : $row['loss_value'];
    }
    unset($row);

    // A product's loss value is quantity x buying price, so it would reveal
    // the buying price. Workers see what was lost and why, never the value.
    if (!can_see_costs($user)) {
        $rows = strip_keys_list($rows, ['loss_value', 'manual_cost', 'display_cost']);
    }

    respond(true, $rows);
}

if ($method === 'POST') {
    require_role($CREATE_ROLES);
    $d = body();
    $itemType = (!empty($d['item_type']) && $d['item_type'] === 'other') ? 'other' : 'product';

    if (empty($d['reason'])) respond(false, null, 'Missing fields: reason', 422);
    $damageDate = !empty($d['damage_date']) ? clean($d['damage_date']) : today();

    try {
        if ($itemType === 'product') {
            $missing = missing_fields($d, ['variant_id', 'quantity']);
            if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

            $variantId = (int)$d['variant_id'];
            $qty = (int)$d['quantity'];
            if ($qty <= 0) respond(false, null, 'Enter a valid quantity.', 422);

            $stmt = $pdo->prepare('SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
            $stmt->execute([$variantId]);
            $variant = $stmt->fetch();
            if (!$variant) respond(false, null, 'Product type not found.', 404);
            $label = damage_variant_label($variant['product_name'], $variant['variant_name']);

            $lossValue = round($qty * (float)$variant['buying_price'], 2);

            $pdo->beginTransaction();
            if (table_has_column($pdo, 'damages', 'product_id')) {
                $ins = $pdo->prepare('INSERT INTO damages (variant_id, product_id, item_type, item_name, quantity, reason, loss_value, manual_cost, recorded_by, damage_date, created_at)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
                $ins->execute([$variantId, (int)$variant['product_id'], 'product', null, $qty, clean($d['reason']), $lossValue, null, $user['id'], $damageDate]);
            } else {
                $ins = $pdo->prepare('INSERT INTO damages (variant_id, item_type, item_name, quantity, reason, loss_value, manual_cost, recorded_by, damage_date, created_at)
                                       VALUES (?,?,?,?,?,?,?,?,?,NOW())');
                $ins->execute([$variantId, 'product', null, $qty, clean($d['reason']), $lossValue, null, $user['id'], $damageDate]);
            }
            $damageId = (int)$pdo->lastInsertId();

            $pdo->prepare('UPDATE product_variants SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE id = ?')->execute([$qty, $variantId]);
            log_stock_movement($pdo, $variantId, 'damage', -$qty, 'damages', $damageId, clean($d['reason']), $user['id']);
            $pdo->commit();

            log_activity($pdo, $user, 'create', 'damage', $damageId,
                'Recorded damage: ' . $qty . ' ' . $variant['unit'] . ' x "' . $label . '" — loss TZS ' . number_format($lossValue) . ' (' . clean($d['reason']) . ')');

            $result = ['id' => $damageId, 'loss_value' => $lossValue];
            if (!can_see_costs($user)) unset($result['loss_value']);
            respond(true, $result, 'Damage/loss recorded and stock adjusted.');
        } else {
            $missing = missing_fields($d, ['item_name', 'manual_cost']);
            if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

            $itemName = clean($d['item_name']);
            $cost = (float)$d['manual_cost'];
            if ($cost < 0) respond(false, null, 'Enter a valid cost.', 422);

            $ins = $pdo->prepare('INSERT INTO damages (variant_id, item_type, item_name, quantity, reason, loss_value, manual_cost, recorded_by, damage_date, created_at)
                                   VALUES (NULL,?,?,NULL,?,?,?,?,?,NOW())');
            $ins->execute(['other', $itemName, clean($d['reason']), $cost, $cost, $user['id'], $damageDate]);

            log_activity($pdo, $user, 'create', 'damage', $pdo->lastInsertId(),
                'Recorded loss for non-stock item: "' . $itemName . '" — cost TZS ' . number_format($cost) . ' (' . clean($d['reason']) . ')');

            $result = ['id' => $pdo->lastInsertId(), 'loss_value' => $cost];
            if (!can_see_costs($user)) unset($result['loss_value']);
            respond(true, $result, 'Loss recorded for "' . $itemName . '" (not in Stock).');
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        respond(false, null, 'Could not record loss entry.', 500);
    }
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Record id is required.', 422);
    if (empty($d['reason'])) respond(false, null, 'Missing fields: reason', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM damages WHERE id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Record not found.', 404);

    $newDate = !empty($d['damage_date']) ? clean($d['damage_date']) : $existing['damage_date'];
    $newReason = clean($d['reason']);

    try {
        if ($existing['item_type'] === 'product') {
            $missing = missing_fields($d, ['quantity']);
            if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);
            $newQty = (int)$d['quantity'];
            if ($newQty <= 0) respond(false, null, 'Enter a valid quantity.', 422);

            $vStmt = $pdo->prepare('SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
            $vStmt->execute([$existing['variant_id']]);
            $variant = $vStmt->fetch();
            if (!$variant) respond(false, null, 'The product type this loss was recorded against no longer exists.', 404);
            $label = damage_variant_label($variant['product_name'], $variant['variant_name']);

            $newLossValue = round($newQty * (float)$variant['buying_price'], 2);
            $qtyDelta = $newQty - (int)$existing['quantity']; // positive = MORE lost than before

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE damages SET quantity = ?, reason = ?, loss_value = ?, damage_date = ? WHERE id = ?')
                ->execute([$newQty, $newReason, $newLossValue, $newDate, $existing['id']]);
            if ($qtyDelta != 0) {
                $pdo->prepare('UPDATE product_variants SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE id = ?')
                    ->execute([$qtyDelta, $existing['variant_id']]);
                log_stock_movement($pdo, (int)$existing['variant_id'], 'adjustment', -$qtyDelta, 'damages', (int)$existing['id'], 'Damage record edited', $user['id']);
            }
            $pdo->commit();

            $changes = [];
            if ((int)$existing['quantity'] != $newQty) $changes[] = 'quantity ' . $existing['quantity'] . ' to ' . $newQty . ' (loss ' . audit_money_diff($existing['loss_value'], $newLossValue) . ')';
            if ($existing['reason'] !== $newReason) $changes[] = 'reason "' . $existing['reason'] . '" to "' . $newReason . '"';
            log_activity($pdo, $user, 'update', 'damage', $existing['id'],
                'Edited loss record for "' . $label . '"' . ($changes ? ': ' . implode(', ', $changes) : ' (no changes)'));

            respond(true, null, 'Record updated and stock adjusted.');
        } else {
            $missing = missing_fields($d, ['item_name', 'manual_cost']);
            if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);
            $newItemName = clean($d['item_name']);
            $newCost = (float)$d['manual_cost'];
            if ($newCost < 0) respond(false, null, 'Enter a valid cost.', 422);

            $pdo->prepare('UPDATE damages SET item_name = ?, reason = ?, loss_value = ?, manual_cost = ?, damage_date = ? WHERE id = ?')
                ->execute([$newItemName, $newReason, $newCost, $newCost, $newDate, $existing['id']]);

            log_activity($pdo, $user, 'update', 'damage', $existing['id'], 'Edited loss record for non-stock item "' . $newItemName . '"');

            respond(true, null, 'Record updated.');
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        respond(false, null, 'Could not update loss entry.', 500);
    }
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Record id is required.', 422);
    $lookup = $pdo->prepare('SELECT d.*, p.name AS product_name, v.variant_name FROM damages d
                              LEFT JOIN product_variants v ON v.id = d.variant_id
                              LEFT JOIN products p ON p.id = v.product_id
                              WHERE d.id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    if (!$row) respond(false, null, 'Record not found.', 404);

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM damages WHERE id = ?')->execute([$id]);
    if ($row['item_type'] === 'product' && $row['variant_id']) {
        $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?')->execute([$row['quantity'], $row['variant_id']]);
        log_stock_movement($pdo, (int)$row['variant_id'], 'adjustment', (int)$row['quantity'], 'damages', $id, 'Loss record deleted — stock restored', $user['id']);
    }
    $pdo->commit();

    $label = $row['item_type'] === 'other' ? $row['item_name'] : damage_variant_label($row['product_name'], $row['variant_name']);
    log_activity($pdo, $user, 'delete', 'damage', $id, 'Deleted loss record for "' . $label . '"');

    respond(true, null, 'Loss record removed and stock restored.');
}

respond(false, null, 'Unsupported method.', 405);
