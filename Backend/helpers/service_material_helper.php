<?php
/**
 * MABUMBATECH — Products used by a service sale
 *
 * When a service is sold (a catalog service or a custom one), the seller is
 * asked "Is any product used?". If yes, they pick the product(s) from
 * Products/Inventory and how many were used for that sale line. This file
 * holds everything that needs, so sales.php stays simple.
 *
 * THE ONE RULE THAT MATTERS
 *   The sale is always recorded at the FULL price charged to the customer.
 *   Products used never reduce sale_items.subtotal / unit_price, never reduce
 *   sale_transactions.total_amount, and therefore never change the cash the
 *   daily cash closing expects. They only do two things:
 *     1. deduct the product from stock, and
 *     2. add the product's cost price to the line's cost, so
 *        profit = revenue - cost of products used.
 *
 * Table (created by Backend/migrate_service_materials.php):
 *   sale_item_materials   what each sold service line actually consumed:
 *                         product type, quantity used, and its cost price at
 *                         sale time. Editing/deleting the sale restores
 *                         exactly this stock.
 *
 * Until the migration has been run, nothing here does anything unless a
 * sale actually sends products used, so the rest of the system keeps working
 * exactly as before.
 */
require_once __DIR__ . '/stock_helper.php';

function service_materials_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$pdo->query("SHOW TABLES LIKE 'sale_item_materials'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function material_display_name(string $productName, string $variantName): string
{
    return $productName . ' — ' . $variantName;
}

/**
 * Validate the "products used" list sent with one service/custom sale line.
 * Blank rows are ignored and duplicates merged. $quantity in each entry is
 * the TOTAL used for the whole line (not per unit). Returns a plan:
 *   [{variant_id, quantity, unit_cost, label, unit}, ...]
 * Calls respond() (exits) on bad input.
 */
function prepare_item_materials(PDO $pdo, $raw): array
{
    if (!is_array($raw) || !$raw) return [];

    $merged = [];
    foreach ($raw as $m) {
        if (!is_array($m)) continue;
        $vid = (int)($m['variant_id'] ?? 0);
        if ($vid <= 0) continue; // an empty row the seller never filled in
        $qty = (int)($m['quantity'] ?? 0);
        if ($qty <= 0) respond(false, null, 'Enter a quantity of 1 or more for every product used.', 422);
        $merged[$vid] = ($merged[$vid] ?? 0) + $qty;
    }
    if (!$merged) return [];

    if (!service_materials_ready($pdo)) {
        respond(false, null, 'Products-used support is not installed yet. Run Backend/migrate_service_materials.php once (as administrator), then try again.', 422);
    }

    $stmt = $pdo->prepare('SELECT v.id, v.variant_name, v.unit, v.buying_price, p.name AS product_name
                           FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
    $plan = [];
    foreach ($merged as $vid => $qty) {
        $stmt->execute([$vid]);
        $v = $stmt->fetch();
        if (!$v) respond(false, null, 'One of the products used no longer exists.', 404);
        $plan[] = [
            'variant_id' => (int)$v['id'],
            'quantity' => $qty,
            'unit_cost' => (float)$v['buying_price'],
            'label' => material_display_name($v['product_name'], $v['variant_name']),
            'unit' => $v['unit'],
        ];
    }
    return $plan;
}

/** Total cost of a plan (quantity x cost price, summed). */
function material_plan_cost(array $plan): float
{
    $cost = 0.0;
    foreach ($plan as $m) $cost += $m['quantity'] * $m['unit_cost'];
    return round($cost, 2);
}

/**
 * Cart-level stock check. Adds up everything the whole cart needs per
 * product type (products used by service lines + any of that same product
 * sold directly) and refuses the sale if stock cannot cover it.
 * $addBack = [variant_id => qty] of stock handed back first (used when
 * editing a sale, whose old lines are restored before the new are deducted).
 */
function check_material_stock(PDO $pdo, array $items, array $addBack = []): void
{
    $materialNeed = [];   // variant_id => units needed by service lines
    $labels = [];         // variant_id => [label, unit]
    $services = [];       // variant_id => service names that need it
    $directNeed = [];     // variant_id => units sold directly in the same cart

    foreach ($items as $item) {
        if (!empty($item['materials'])) {
            foreach ($item['materials'] as $m) {
                $vid = $m['variant_id'];
                $materialNeed[$vid] = ($materialNeed[$vid] ?? 0) + $m['quantity'];
                $labels[$vid] = [$m['label'], $m['unit']];
                $services[$vid][$item['description']] = true;
            }
        } elseif (($item['item_type'] ?? '') === 'product' && !empty($item['variant_id'])) {
            $vid = (int)$item['variant_id'];
            $directNeed[$vid] = ($directNeed[$vid] ?? 0) + (int)$item['quantity'];
        }
    }
    if (!$materialNeed) return;

    $stockStmt = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
    foreach ($materialNeed as $vid => $need) {
        $stockStmt->execute([$vid]);
        $stock = (int)$stockStmt->fetchColumn() + (int)($addBack[$vid] ?? 0);
        $total = $need + ($directNeed[$vid] ?? 0);
        if ($total > $stock) {
            [$label, $unit] = $labels[$vid];
            respond(false, null, 'Not enough stock of "' . $label . '" for '
                . implode(', ', array_map(fn($s) => '"' . $s . '"', array_keys($services[$vid])))
                . '. Needs ' . $total . ' ' . $unit . ' but only ' . $stock . ' ' . $unit . ' left.', 422);
        }
    }
}

/** Stock that editing a sale hands back first: old product lines + old products-used. */
function restorable_stock_for_items(PDO $pdo, array $oldItems): array
{
    $back = [];
    $matStmt = service_materials_ready($pdo)
        ? $pdo->prepare('SELECT variant_id, quantity FROM sale_item_materials WHERE sale_item_id = ?') : null;
    foreach ($oldItems as $old) {
        if (($old['item_type'] ?? '') === 'product' && !empty($old['variant_id'])) {
            $back[(int)$old['variant_id']] = ($back[(int)$old['variant_id']] ?? 0) + (int)$old['quantity'];
        } elseif ($matStmt) {
            $matStmt->execute([(int)$old['id']]);
            foreach ($matStmt->fetchAll() as $m) {
                $back[(int)$m['variant_id']] = ($back[(int)$m['variant_id']] ?? 0) + (int)$m['quantity'];
            }
        }
    }
    return $back;
}

/** Attach each service/custom line's products used (for the edit screen). */
function attach_item_materials(PDO $pdo, array $items): array
{
    foreach ($items as &$it) { $it['materials'] = []; }
    unset($it);
    if (!service_materials_ready($pdo)) return $items;

    $stmt = $pdo->prepare('SELECT sm.variant_id, sm.quantity, v.unit, v.variant_name, p.name AS product_name
                           FROM sale_item_materials sm
                           JOIN product_variants v ON v.id = sm.variant_id
                           JOIN products p ON p.id = v.product_id
                           WHERE sm.sale_item_id = ?');
    foreach ($items as &$it) {
        if (($it['item_type'] ?? '') === 'product') continue;
        $stmt->execute([(int)$it['id']]);
        foreach ($stmt->fetchAll() as $m) {
            $it['materials'][] = [
                'variant_id' => (int)$m['variant_id'],
                'quantity' => (int)$m['quantity'],
                'unit' => $m['unit'],
                'display_name' => material_display_name($m['product_name'], $m['variant_name']),
            ];
        }
    }
    unset($it);
    return $items;
}

/**
 * Deduct or restore the product stock consumed by ONE sold service line.
 * 'deduct' reads the plan attached to the freshly prepared $item and writes
 * a snapshot to sale_item_materials; 'restore' reads that snapshot back.
 * Never touches prices, subtotals or totals.
 */
function apply_service_material_stock(PDO $pdo, array $item, int $itemId, string $direction, int $userId): void
{
    if (!service_materials_ready($pdo)) return;

    if ($direction === 'deduct') {
        $plan = $item['materials'] ?? [];
        if (!$plan) return;
        $snap = $pdo->prepare('INSERT INTO sale_item_materials (sale_item_id, variant_id, quantity, unit_cost) VALUES (?,?,?,?)');
        foreach ($plan as $m) {
            $snap->execute([$itemId, $m['variant_id'], $m['quantity'], $m['unit_cost']]);
            $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ?')
                ->execute([$m['quantity'], $m['variant_id']]);
            log_stock_movement($pdo, $m['variant_id'], 'sale', -$m['quantity'], 'sale_items', $itemId,
                'Used in service "' . $item['description'] . '" (sale item #' . $itemId . ')', $userId);
        }
        return;
    }

    $rows = $pdo->prepare('SELECT variant_id, quantity FROM sale_item_materials WHERE sale_item_id = ?');
    $rows->execute([$itemId]);
    foreach ($rows->fetchAll() as $m) {
        $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?')
            ->execute([(int)$m['quantity'], (int)$m['variant_id']]);
        log_stock_movement($pdo, (int)$m['variant_id'], 'adjustment', (int)$m['quantity'], 'sale_items', $itemId,
            'Stock restored (service sale item #' . $itemId . ' removed/edited)', $userId);
    }
}
