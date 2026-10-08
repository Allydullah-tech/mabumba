<?php
/**
 * MABUMBATECH — Sales API
 *
 * A "sale" is a transaction (sale_transactions) holding one or more
 * line items (sale_items). Each line item is one of three kinds:
 *
 *   item_type = 'product'  -> variant_id set (a specific product TYPE,
 *                              e.g. "Pen — Marshal"). Priced against that
 *                              variant's minimum_price if any — see the
 *                              minimum-price authorization note below.
 *                              Stock is deducted from that variant only.
 *                              profit = (price - buying_price) * qty.
 *   item_type = 'service'  -> service_id set. Priced against the
 *                              catalog price (editable down to its
 *                              minimum_price if set). By default a service
 *                              has no stock impact and its full price is
 *                              profit. The seller can say which products
 *                              were used (`materials: [{variant_id, quantity}]`,
 *                              quantity = total used on this line): those are
 *                              deducted from stock and their cost price is
 *                              counted as the line's cost, so
 *                              profit = line total - cost of products used.
 *                              The sale amount itself (unit_price, subtotal,
 *                              total_amount, cash) is NEVER reduced by it.
 *                              A custom service can list products used too.
 *   item_type = 'custom'   -> no catalog row at all. The seller types a
 *                              free-text description and whatever price
 *                              was agreed with the customer on the spot
 *                              (e.g. a bespoke design/repair job). No
 *                              stock impact, no minimum-price check —
 *                              there is nothing in the catalog to check
 *                              it against.
 *
 * MINIMUM SELLING PRICE AUTHORIZATION
 * If a product line's price is below the variant's minimum_price, a
 * cashier is blocked outright. An administrator or manager can still
 * complete the sale below minimum, but only by explicitly sending
 * `override_min_price: true` plus a non-empty `override_reason` for that
 * line — this is recorded on the sale item (below_minimum, override_reason)
 * and written to the activity log, so under-minimum sales are always
 * fully auditable, never silent.
 *
 * GET    -> list transactions (filter by ?start=&end=). ?id= returns
 *           one full transaction with its items, for the receipt/edit
 *           screens.
 * POST   -> record a new sale. Body:
 *           { items: [{item_type, variant_id|service_id|null, description,
 *                       quantity, unit_price, override_min_price, override_reason}, ...],
 *             payment_type: 'cash'|'credit', payment_method_id (required
 *             for cash), credit_deadline (required for credit),
 *             customer_name, customer_phone, discount_amount, sale_date, note }
 * PUT    -> edit a sale. Replaces the whole item list: stock for the old
 *           product items is restored, then stock for the new product
 *           items is deducted.
 * DELETE -> remove a sale, restoring stock for any product items it held.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/customer_helper.php';
require_once __DIR__ . '/../helpers/stock_helper.php';
require_once __DIR__ . '/../helpers/service_material_helper.php';

$SALES_ROLES = ['administrator', 'worker'];
$user = require_role($SALES_ROLES);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function variant_label(string $productName, string $variantName): string
{
    return $productName . ' — ' . $variantName;
}

/**
 * Validate and price one cart line. Returns the enriched item array, or
 * calls respond() (which exits) on failure. $canOverrideMinPrice controls
 * whether a below-minimum price is even allowed through (with a reason) —
 * only administrator/manager may pass true for this.
 */
function prepare_sale_item(PDO $pdo, array $raw, bool $canOverrideMinPrice): array
{
    $itemType = in_array($raw['item_type'] ?? '', ['product', 'service', 'custom'], true)
        ? $raw['item_type'] : 'product';

    $qty = isset($raw['quantity']) ? (int)$raw['quantity'] : 0;
    $unitPrice = isset($raw['unit_price']) ? (float)$raw['unit_price'] : -1;
    if ($qty <= 0 || $unitPrice < 0) {
        respond(false, null, 'Enter a valid quantity and price for every item.', 422);
    }

    if ($itemType === 'product') {
        if (empty($raw['variant_id'])) respond(false, null, 'Each product line needs a specific type selected.', 422);
        $stmt = $pdo->prepare('SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
        $stmt->execute([(int)$raw['variant_id']]);
        $variant = $stmt->fetch();
        if (!$variant) respond(false, null, 'One of the products in this sale no longer exists.', 404);

        $label = variant_label($variant['product_name'], $variant['variant_name']);
        $belowMinimum = false;
        $overrideReason = null;

        if (!empty($variant['minimum_price']) && $unitPrice < (float)$variant['minimum_price']) {
            $wantsOverride = !empty($raw['override_min_price']);
            $reason = clean($raw['override_reason'] ?? '');
            if (!$canOverrideMinPrice) {
                respond(false, null, 'That price is below the minimum allowed selling price of TZS '
                    . number_format((float)$variant['minimum_price']) . ' for "' . $label . '". Ask a manager or administrator to authorize this sale.', 422);
            }
            if (!$wantsOverride || $reason === '') {
                respond(false, null, 'The price for "' . $label . '" is below its minimum of TZS '
                    . number_format((float)$variant['minimum_price']) . '. Confirm the override and give a reason to proceed.', 422);
            }
            $belowMinimum = true;
            $overrideReason = $reason;
        }

        if ((int)$variant['stock_quantity'] < $qty) {
            respond(false, null, 'Not enough stock for "' . $label . '". Only '
                . $variant['stock_quantity'] . ' ' . $variant['unit'] . ' left.', 422);
        }

        $buyingPrice = (float)$variant['buying_price'];
        return [
            'item_type' => 'product', 'variant_id' => (int)$variant['id'], 'service_id' => null,
            'description' => $label, 'quantity' => $qty, 'unit_price' => $unitPrice,
            'buying_price' => $buyingPrice, 'subtotal' => round($qty * $unitPrice, 2),
            'profit' => round(($unitPrice - $buyingPrice) * $qty, 2),
            'below_minimum' => $belowMinimum, 'override_reason' => $overrideReason,
        ];
    }

    if ($itemType === 'service') {
        if (empty($raw['service_id'])) respond(false, null, 'Each service line needs a service selected.', 422);
        $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([(int)$raw['service_id']]);
        $service = $stmt->fetch();
        if (!$service) respond(false, null, 'One of the services in this sale no longer exists.', 404);

        $belowMinimum = false;
        $overrideReason = null;
        if (!empty($service['minimum_price']) && $unitPrice < (float)$service['minimum_price']) {
            $wantsOverride = !empty($raw['override_min_price']);
            $reason = clean($raw['override_reason'] ?? '');
            if (!$canOverrideMinPrice) {
                respond(false, null, 'That price is below the minimum allowed price of TZS '
                    . number_format((float)$service['minimum_price']) . ' for "' . $service['name'] . '". Ask a manager or administrator to authorize this sale.', 422);
            }
            if (!$wantsOverride || $reason === '') {
                respond(false, null, 'The price for "' . $service['name'] . '" is below its minimum of TZS '
                    . number_format((float)$service['minimum_price']) . '. Confirm the override and give a reason to proceed.', 422);
            }
            $belowMinimum = true;
            $overrideReason = $reason;
        }

        // Products the seller said were used (empty if none). Their cost lowers
        // PROFIT only — subtotal stays the full price charged to the customer.
        $materials = prepare_item_materials($pdo, $raw['materials'] ?? []);
        $costTotal = material_plan_cost($materials);
        $subtotal = round($qty * $unitPrice, 2);

        return [
            'item_type' => 'service', 'variant_id' => null, 'service_id' => (int)$service['id'],
            'description' => $service['name'], 'quantity' => $qty, 'unit_price' => $unitPrice,
            'buying_price' => round($costTotal / $qty, 2), 'cost_total' => $costTotal,
            'subtotal' => $subtotal, 'profit' => round($subtotal - $costTotal, 2),
            'materials' => $materials,
            'below_minimum' => $belowMinimum, 'override_reason' => $overrideReason,
        ];
    }

    // item_type === 'custom' — a one-off, negotiated service with no
    // catalog entry. The seller's typed description IS the record.
    $description = clean($raw['description'] ?? '');
    if ($description === '') {
        respond(false, null, 'Please describe the custom service being sold.', 422);
    }
    $materials = prepare_item_materials($pdo, $raw['materials'] ?? []);
    $costTotal = material_plan_cost($materials);
    $subtotal = round($qty * $unitPrice, 2);
    return [
        'item_type' => 'custom', 'variant_id' => null, 'service_id' => null,
        'description' => $description, 'quantity' => $qty, 'unit_price' => $unitPrice,
        'buying_price' => round($costTotal / $qty, 2), 'cost_total' => $costTotal,
        'subtotal' => $subtotal, 'profit' => round($subtotal - $costTotal, 2),
        'materials' => $materials,
        'below_minimum' => false, 'override_reason' => null,
    ];
}

/**
 * A discount reduces the revenue actually collected on a sale, so it must
 * also reduce the profit recorded for it — otherwise reports would show
 * profit as if the customer paid full price. This spreads the discount
 * across items proportionally to their share of the subtotal, then
 * recalculates each item's profit from its (now-reduced) revenue minus its
 * unchanged unit cost. The last item absorbs any rounding remainder so the
 * items' subtotals always sum exactly to (subtotal - discount).
 */
function apply_discount_to_items(array $items, float $subtotal, float $discount): array
{
    if ($discount <= 0 || $subtotal <= 0) return $items;

    $factor = ($subtotal - $discount) / $subtotal;
    $runningSubtotal = 0.0;
    $count = count($items);

    foreach ($items as $i => &$item) {
        if ($i === $count - 1) {
            $item['subtotal'] = round($subtotal - $discount - $runningSubtotal, 2);
        } else {
            $item['subtotal'] = round($item['subtotal'] * $factor, 2);
            $runningSubtotal += $item['subtotal'];
        }
        $costBasis = $item['cost_total'] ?? ($item['buying_price'] * $item['quantity']);
        $item['profit'] = round($item['subtotal'] - $costBasis, 2);
    }
    unset($item);

    return $items;
}

/** Restore or deduct stock for one already-inserted sale item row. */
function apply_item_stock(PDO $pdo, array $item, int $itemId, string $direction, int $userId): void
{
    if (in_array($item['item_type'] ?? null, ['service', 'custom'], true)) {
        // Only moves stock if the seller listed products used on this line.
        apply_service_material_stock($pdo, $item, $itemId, $direction, $userId);
        return;
    }
    $variantId = $item['variant_id'] ?? null;
    if (($item['item_type'] ?? null) !== 'product' || empty($variantId)) return;
    $sign = $direction === 'deduct' ? -1 : 1;
    $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?')
        ->execute([$sign * $item['quantity'], $variantId]);
    log_stock_movement($pdo, (int)$variantId, $direction === 'deduct' ? 'sale' : 'adjustment',
        $sign * $item['quantity'], 'sale_items', $itemId,
        $direction === 'deduct' ? 'Sold via sale item #' . $itemId : 'Stock restored (sale item #' . $itemId . ' removed/edited)',
        $userId);
}

$CAN_OVERRIDE_MIN_PRICE = in_array($user['role'], ['administrator'], true);

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT st.*, u.full_name AS sold_by_name, pm.name AS payment_method_name
                                FROM sale_transactions st
                                JOIN users u ON u.id = st.sold_by
                                LEFT JOIN payment_methods pm ON pm.id = st.payment_method_id
                                WHERE st.id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $txn = $stmt->fetch();
        if (!$txn) respond(false, null, 'Sale not found.', 404);

        $itemsStmt = $pdo->prepare("SELECT si.* FROM sale_items si WHERE si.transaction_id = ? ORDER BY si.id ASC");
        $itemsStmt->execute([$txn['id']]);
        $txn['items'] = attach_item_materials($pdo, $itemsStmt->fetchAll());

        // Workers never see cost price or profit on a sale.
        if (!can_see_costs($user)) {
            $txn = strip_keys($txn, ['total_profit']);
            $txn['items'] = strip_keys_list($txn['items'], ['buying_price', 'profit']);
        }

        $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM debt_payments WHERE sale_id = ?');
        $paidStmt->execute([$txn['id']]);
        $txn['amount_paid'] = (float)$paidStmt->fetchColumn();
        $txn['balance_due'] = round((float)$txn['total_amount'] - $txn['amount_paid'], 2);

        respond(true, $txn);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['start'])) { $where .= ' AND st.sale_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $where .= ' AND st.sale_date <= ?'; $params[] = clean($_GET['end']); }
    $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $stmt = $pdo->prepare("
        SELECT st.*, u.full_name AS sold_by_name, pm.name AS payment_method_name,
               (SELECT GROUP_CONCAT(si.description SEPARATOR ', ') FROM sale_items si WHERE si.transaction_id = st.id) AS item_names,
               (SELECT COUNT(*) FROM sale_items si WHERE si.transaction_id = st.id) AS item_count,
               (SELECT SUM(si.quantity) FROM sale_items si WHERE si.transaction_id = st.id) AS total_quantity
        FROM sale_transactions st
        JOIN users u ON u.id = st.sold_by
        LEFT JOIN payment_methods pm ON pm.id = st.payment_method_id
        $where ORDER BY st.sale_date DESC, st.id DESC LIMIT $limit
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!can_see_costs($user)) $rows = strip_keys_list($rows, ['total_profit']);
    respond(true, $rows);
}

if ($method === 'POST') {
    $d = body();
    if (empty($d['items']) || !is_array($d['items'])) {
        respond(false, null, 'Add at least one product or service to the sale.', 422);
    }

    $items = array_map(fn($raw) => prepare_sale_item($pdo, $raw, $CAN_OVERRIDE_MIN_PRICE), $d['items']);
    check_material_stock($pdo, $items);
    $subtotal = round(array_sum(array_column($items, 'subtotal')), 2);

    $discount = isset($d['discount_amount']) ? max(0.0, (float)$d['discount_amount']) : 0.0;
    if ($discount > $subtotal) $discount = $subtotal;
    $items = apply_discount_to_items($items, $subtotal, $discount);

    $taxEnabled = get_setting($pdo, 'tax_enabled', '0') === '1';
    $taxRate = (float)get_setting($pdo, 'tax_rate', '0');
    $tax = $taxEnabled ? round(($subtotal - $discount) * $taxRate / 100, 2) : 0.0;

    $totalAmount = round($subtotal - $discount + $tax, 2);
    $totalProfit = round(array_sum(array_column($items, 'profit')), 2);
    $saleDate = !empty($d['sale_date']) ? clean($d['sale_date']) : today();

    $paymentType = (!empty($d['payment_type']) && $d['payment_type'] === 'credit') ? 'credit' : 'cash';
    $paymentMethodId = null;
    $creditDeadline = null;
    $customerName = trim($d['customer_name'] ?? '');
    $customerPhone = trim($d['customer_phone'] ?? '');

    $changeLeftAmount = 0.0;
    $changeLeftCustomerName = null;
    if ($paymentType === 'cash') {
        if (empty($d['payment_method_id'])) respond(false, null, 'Please choose a payment method.', 422);
        $pmStmt = $pdo->prepare("SELECT id, slug FROM payment_methods WHERE id = ? AND status = 'active'");
        $pmStmt->execute([(int)$d['payment_method_id']]);
        $pm = $pmStmt->fetch();
        if (!$pm) respond(false, null, 'That payment method is not available.', 422);
        $paymentMethodId = (int)$d['payment_method_id'];

        if (!empty($d['customer_left_change'])) {
            if ($pm['slug'] !== 'cash') respond(false, null, 'Left change only applies to cash-in-hand payments.', 422);
            $changeLeftAmount = max(0.0, (float)($d['change_left_amount'] ?? 0));
            if ($changeLeftAmount <= 0) respond(false, null, 'Enter how much change the customer left behind.', 422);
            $changeLeftCustomerName = trim($d['change_left_customer_name'] ?? '');
            if ($changeLeftCustomerName === '') respond(false, null, 'Customer name is required when the customer leaves change behind.', 422);
        }
    } else {
        if (empty($d['credit_deadline'])) respond(false, null, 'Please set a deadline date for this credit sale.', 422);
        if ($customerName === '') respond(false, null, 'Please enter the customer\'s name for a credit sale.', 422);
        if ($customerPhone === '') respond(false, null, 'Please enter the customer\'s phone number for a credit sale.', 422);
        $creditDeadline = clean($d['credit_deadline']);
    }

    $customerId = ($customerName !== '' || $customerPhone !== '') ? resolve_customer($pdo, $customerName, $customerPhone) : null;

    if ($paymentType === 'credit') {
        block_if_credit_restricted($pdo, $customerId);
    }

    try {
        $pdo->beginTransaction();

        $invoiceNo = next_invoice_no($pdo);
        $ins = $pdo->prepare('INSERT INTO sale_transactions
            (invoice_no, customer_id, customer_name, customer_phone, payment_type, payment_method_id, credit_deadline,
             subtotal_amount, discount_amount, tax_amount, total_amount, total_profit, note,
             change_left_amount, change_left_customer_name, sold_by, sale_date, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $ins->execute([
            $invoiceNo, $customerId, $customerName !== '' ? $customerName : null, $customerPhone !== '' ? $customerPhone : null,
            $paymentType, $paymentMethodId, $creditDeadline,
            $subtotal, $discount, $tax, $totalAmount, $totalProfit, clean($d['note'] ?? ''),
            $changeLeftAmount, $changeLeftCustomerName !== '' ? $changeLeftCustomerName : null, $user['id'], $saleDate,
        ]);
        $txnId = (int)$pdo->lastInsertId();

        $itemHasProductId = table_has_column($pdo, 'sale_items', 'product_id');
        $itemIns = $pdo->prepare($itemHasProductId
            ? 'INSERT INTO sale_items
            (transaction_id, item_type, variant_id, product_id, service_id, description, quantity, unit_price, buying_price, subtotal, profit, below_minimum, override_reason)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            : 'INSERT INTO sale_items
            (transaction_id, item_type, variant_id, service_id, description, quantity, unit_price, buying_price, subtotal, profit, below_minimum, override_reason)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $overrideNotices = [];
        foreach ($items as $item) {
            $itemIns->execute($itemHasProductId ? [
                $txnId, $item['item_type'], $item['variant_id'], product_id_for_variant($pdo, !empty($item['variant_id']) ? (int)$item['variant_id'] : null), $item['service_id'], $item['description'],
                $item['quantity'], $item['unit_price'], $item['buying_price'], $item['subtotal'], $item['profit'],
                $item['below_minimum'] ? 1 : 0, $item['override_reason'],
            ] : [
                $txnId, $item['item_type'], $item['variant_id'], $item['service_id'], $item['description'],
                $item['quantity'], $item['unit_price'], $item['buying_price'], $item['subtotal'], $item['profit'],
                $item['below_minimum'] ? 1 : 0, $item['override_reason'],
            ]);
            apply_item_stock($pdo, $item, (int)$pdo->lastInsertId(), 'deduct', $user['id']);
            if ($item['below_minimum']) {
                $overrideNotices[] = $item['description'] . ' (' . $item['override_reason'] . ')';
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, null, 'Could not record sale: ' . $e->getMessage(), 500);
    }

    if ($paymentType === 'credit') refresh_customer_restriction($pdo, $customerId);

    $itemSummary = count($items) === 1
        ? '1 x "' . $items[0]['description'] . '"'
        : count($items) . ' item(s) (' . implode(', ', array_column($items, 'description')) . ')';
    log_activity($pdo, $user, 'create', 'sale', $txnId,
        'Recorded a ' . $paymentType . ' sale ' . $invoiceNo . ': ' . $itemSummary . ' for TZS ' . number_format($totalAmount)
        . ($customerName !== '' ? ' to ' . $customerName : ''));

    if (!empty($overrideNotices)) {
        log_activity($pdo, $user, 'update', 'sale', $txnId,
            'Sold below minimum price on sale ' . $invoiceNo . ': ' . implode('; ', $overrideNotices));
    }

    $result = ['id' => $txnId, 'invoice_no' => $invoiceNo, 'total_amount' => $totalAmount, 'profit' => $totalProfit];
    if (!can_see_costs($user)) unset($result['profit']);
    respond(true, $result, 'Sale recorded successfully.');
}

if ($method === 'PUT') {
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Sale id is required.', 422);

    // Recording that a customer came back to collect some or all of the
    // change they left behind is its own lightweight update — it never
    // touches the sale's items, totals, or anything else about it.
    if (($d['action'] ?? '') === 'collect_change') {
        $txnId = (int)$d['id'];
        $stmt = $pdo->prepare('SELECT * FROM sale_transactions WHERE id = ?');
        $stmt->execute([$txnId]);
        $existing = $stmt->fetch();
        if (!$existing) respond(false, null, 'Sale not found.', 404);

        $outstanding = round((float)$existing['change_left_amount'] - (float)$existing['change_collected_amount'], 2);
        if ($outstanding <= 0.001) respond(false, null, 'There is no outstanding change left on this sale.', 422);

        $collectAmount = round((float)($d['amount'] ?? $outstanding), 2);
        if ($collectAmount <= 0 || $collectAmount > $outstanding + 0.01) {
            respond(false, null, 'Enter an amount between 0 and TZS ' . number_format($outstanding) . '.', 422);
        }
        $newCollected = round((float)$existing['change_collected_amount'] + $collectAmount, 2);

        $pdo->prepare('UPDATE sale_transactions SET change_collected_amount = ? WHERE id = ?')
            ->execute([$newCollected, $txnId]);

        $remaining = round((float)$existing['change_left_amount'] - $newCollected, 2);
        log_activity($pdo, $user, 'update', 'sale', $txnId,
            'Collected TZS ' . number_format($collectAmount) . ' of left change on sale ' . $existing['invoice_no']
            . ($remaining > 0.001 ? ' (TZS ' . number_format($remaining) . ' still outstanding)' : ' (fully collected)'));

        respond(true, ['change_collected_amount' => $newCollected, 'remaining' => max(0, $remaining)], 'Change collection recorded.');
    }

    if (empty($d['items']) || !is_array($d['items'])) respond(false, null, 'A sale needs at least one item.', 422);

    $txnId = (int)$d['id'];
    $stmt = $pdo->prepare('SELECT * FROM sale_transactions WHERE id = ?');
    $stmt->execute([$txnId]);
    $existing = $stmt->fetch();
    if (!$existing) respond(false, null, 'Sale not found.', 404);

    $oldItemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE transaction_id = ?');
    $oldItemsStmt->execute([$txnId]);
    $oldItems = $oldItemsStmt->fetchAll();

    $items = array_map(fn($raw) => prepare_sale_item($pdo, $raw, $CAN_OVERRIDE_MIN_PRICE), $d['items']);
    check_material_stock($pdo, $items, restorable_stock_for_items($pdo, $oldItems));
    $subtotal = round(array_sum(array_column($items, 'subtotal')), 2);
    $discount = isset($d['discount_amount']) ? max(0.0, (float)$d['discount_amount']) : (float)$existing['discount_amount'];
    if ($discount > $subtotal) $discount = $subtotal;
    $items = apply_discount_to_items($items, $subtotal, $discount);

    $taxEnabled = get_setting($pdo, 'tax_enabled', '0') === '1';
    $taxRate = (float)get_setting($pdo, 'tax_rate', '0');
    $tax = $taxEnabled ? round(($subtotal - $discount) * $taxRate / 100, 2) : 0.0;
    $totalAmount = round($subtotal - $discount + $tax, 2);
    $totalProfit = round(array_sum(array_column($items, 'profit')), 2);

    $paymentType = isset($d['payment_type']) && $d['payment_type'] === 'credit' ? 'credit'
        : (isset($d['payment_type']) ? 'cash' : $existing['payment_type']);
    $paymentMethodId = $existing['payment_method_id'];
    $creditDeadline = $existing['credit_deadline'];
    if (isset($d['payment_type'])) {
        if ($paymentType === 'cash') {
            $paymentMethodId = !empty($d['payment_method_id']) ? (int)$d['payment_method_id'] : $paymentMethodId;
            $creditDeadline = null;
        } else {
            $creditDeadline = !empty($d['credit_deadline']) ? clean($d['credit_deadline']) : $existing['credit_deadline'];
            $paymentMethodId = null;
        }
    }
    $customerName = trim($d['customer_name'] ?? $existing['customer_name'] ?? '');
    $customerPhone = trim($d['customer_phone'] ?? $existing['customer_phone'] ?? '');
    $customerId = ($customerName !== '' || $customerPhone !== '') ? resolve_customer($pdo, $customerName, $customerPhone) : $existing['customer_id'];

    try {
        $pdo->beginTransaction();

        foreach ($oldItems as $old) {
            apply_item_stock($pdo, $old, (int)$old['id'], 'restore', $user['id']);
        }
        $pdo->prepare('DELETE FROM sale_items WHERE transaction_id = ?')->execute([$txnId]);

        $itemHasProductId = table_has_column($pdo, 'sale_items', 'product_id');
        $itemIns = $pdo->prepare($itemHasProductId
            ? 'INSERT INTO sale_items
            (transaction_id, item_type, variant_id, product_id, service_id, description, quantity, unit_price, buying_price, subtotal, profit, below_minimum, override_reason)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            : 'INSERT INTO sale_items
            (transaction_id, item_type, variant_id, service_id, description, quantity, unit_price, buying_price, subtotal, profit, below_minimum, override_reason)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($items as $item) {
            $itemIns->execute($itemHasProductId ? [
                $txnId, $item['item_type'], $item['variant_id'], product_id_for_variant($pdo, !empty($item['variant_id']) ? (int)$item['variant_id'] : null), $item['service_id'], $item['description'],
                $item['quantity'], $item['unit_price'], $item['buying_price'], $item['subtotal'], $item['profit'],
                $item['below_minimum'] ? 1 : 0, $item['override_reason'],
            ] : [
                $txnId, $item['item_type'], $item['variant_id'], $item['service_id'], $item['description'],
                $item['quantity'], $item['unit_price'], $item['buying_price'], $item['subtotal'], $item['profit'],
                $item['below_minimum'] ? 1 : 0, $item['override_reason'],
            ]);
            apply_item_stock($pdo, $item, (int)$pdo->lastInsertId(), 'deduct', $user['id']);
        }

        $pdo->prepare('UPDATE sale_transactions SET
                customer_id=?, customer_name=?, customer_phone=?, payment_type=?, payment_method_id=?, credit_deadline=?,
                subtotal_amount=?, discount_amount=?, tax_amount=?, total_amount=?, total_profit=?, sale_date=?
             WHERE id=?')
            ->execute([
                $customerId, $customerName !== '' ? $customerName : null, $customerPhone !== '' ? $customerPhone : null,
                $paymentType, $paymentMethodId, $creditDeadline,
                $subtotal, $discount, $tax, $totalAmount, $totalProfit,
                !empty($d['sale_date']) ? clean($d['sale_date']) : $existing['sale_date'], $txnId,
            ]);

        $pdo->commit();

        if ($paymentType === 'credit') refresh_customer_restriction($pdo, $customerId);

        $changes = [];
        if ((float)$existing['total_amount'] != $totalAmount) $changes[] = 'total ' . audit_money_diff($existing['total_amount'], $totalAmount);
        if ($existing['payment_type'] !== $paymentType) $changes[] = 'payment ' . $existing['payment_type'] . ' to ' . $paymentType;
        if (count($oldItems) !== count($items)) $changes[] = 'items ' . count($oldItems) . ' to ' . count($items);
        log_activity($pdo, $user, 'update', 'sale', $txnId, 'Edited sale ' . $existing['invoice_no'] . ($changes ? ': ' . implode(', ', $changes) : ' (no changes)'));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, null, 'Could not update sale: ' . $e->getMessage(), 500);
    }

    $result = ['total_amount' => $totalAmount, 'profit' => $totalProfit];
    if (!can_see_costs($user)) unset($result['profit']);
    respond(true, $result, 'Sale updated successfully.');
}

if ($method === 'DELETE') {
    require_role(['administrator']);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Sale id is required.', 422);

    $stmt = $pdo->prepare('SELECT * FROM sale_transactions WHERE id = ?');
    $stmt->execute([$id]);
    $txn = $stmt->fetch();
    if (!$txn) respond(false, null, 'Sale not found.', 404);

    $itemsStmt = $pdo->prepare('SELECT * FROM sale_items WHERE transaction_id = ?');
    $itemsStmt->execute([$id]);
    $items = $itemsStmt->fetchAll();

    $pdo->beginTransaction();
    foreach ($items as $item) {
        apply_item_stock($pdo, $item, (int)$item['id'], 'restore', $user['id']);
    }
    $pdo->prepare('DELETE FROM sale_transactions WHERE id = ?')->execute([$id]); // cascades to sale_items and debt_payments
    $pdo->commit();

    $itemSummary = count($items) === 1 ? $items[0]['description'] : count($items) . ' item(s)';
    log_activity($pdo, $user, 'delete', 'sale', $id,
        'Deleted sale ' . $txn['invoice_no'] . ': ' . $itemSummary . ' for TZS ' . number_format($txn['total_amount'])
        . ', sold on ' . $txn['sale_date'] . (!empty($txn['customer_name']) ? ' to ' . $txn['customer_name'] : ''));

    respond(true, null, 'Sale removed and stock restored.');
}

respond(false, null, 'Unsupported method.', 405);
