<?php
/**
 * MABUMBATECH — Dashboard Summary API
 * GET -> today's + this-month totals, stock value, low-stock alerts,
 *        debt alerts, top sellers. ?period=today|yesterday|week|month|year|custom
 *        overrides which range the main summary card reflects (defaults
 *        to 'today'); the secondary "this month" block is always the
 *        current calendar month regardless of ?period.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/report_helper.php';

$user = require_login();
$pdo = get_db();
$today = today();

$period = !empty($_GET['period']) && in_array($_GET['period'], ['today', 'yesterday', 'week', 'month', 'year', 'custom'], true)
    ? $_GET['period'] : 'today';
$anchor = !empty($_GET['date']) ? clean($_GET['date']) : null;
$endCustom = !empty($_GET['end']) ? clean($_GET['end']) : null;
[$rangeStart, $rangeEnd] = resolve_period($period, $anchor, $endCustom);
$mainReport = build_report($pdo, $rangeStart, $rangeEnd);

[$mStart, $mEnd] = resolve_period('month', $today);
$monthReport = build_report($pdo, $mStart, $mEnd);

// ---- Stock counts & value (spec: buying value, selling value, expected gross profit) ----
// Counted at the VARIANT level (the actual stock-tracked item), while
// total_products stays a count of distinct parent catalog entries.
$stmt = $pdo->query('SELECT
    COALESCE(SUM(v.stock_quantity * v.buying_price), 0) AS stock_buying_value,
    COALESCE(SUM(v.stock_quantity * v.selling_price), 0) AS stock_selling_value,
    COALESCE(SUM(v.stock_quantity), 0) AS total_quantity,
    COUNT(v.id) AS total_variants
    FROM product_variants v JOIN products p ON p.id = v.product_id
    WHERE v.status = "active" AND p.status = "active"');
$stockValue = $stmt->fetch();
$totalProducts = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();
$totalServices = (int)$pdo->query('SELECT COUNT(*) FROM services WHERE status = "active"')->fetchColumn();

// Low stock is shown by the SPECIFIC type ("Pen — Marshal"), not just the
// parent product, since two types of the same product can be in very
// different stock situations.
$stmt = $pdo->query('SELECT v.id, p.name AS product_name, v.variant_name, v.stock_quantity, v.reorder_level, v.unit
    FROM product_variants v JOIN products p ON p.id = v.product_id
    WHERE v.status = "active" AND p.status = "active" AND v.stock_quantity <= v.reorder_level
    ORDER BY v.stock_quantity ASC LIMIT 15');
$lowStock = $stmt->fetchAll();
foreach ($lowStock as &$lsRow) {
    $lsRow['name'] = $lsRow['product_name'] . ' — ' . $lsRow['variant_name'];
}
unset($lsRow);

// ---- Credit sales in the selected range (marker only, never subtracted from profit) ----
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total_credit, COUNT(*) AS credit_count
                        FROM sale_transactions WHERE payment_type = 'credit' AND sale_date BETWEEN ? AND ?");
$stmt->execute([$rangeStart, $rangeEnd]);
$creditRange = $stmt->fetch();

// ---- Debt due-date alerts: due today, and already overdue with a balance ----
$dueTodayDebts = [];
$overdueDebts = [];
$stmt = $pdo->query("SELECT s.id, s.customer_name, s.credit_deadline, s.total_amount,
        COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = s.id), 0) AS paid_amount
    FROM sale_transactions s WHERE s.payment_type = 'credit'");
foreach ($stmt->fetchAll() as $c) {
    $remaining = round((float)$c['total_amount'] - (float)$c['paid_amount'], 2);
    if ($remaining <= 0.01 || empty($c['credit_deadline'])) continue;
    if ($c['credit_deadline'] === $today) {
        $dueTodayDebts[] = ['customer_name' => $c['customer_name'], 'remaining' => $remaining];
    } elseif ($c['credit_deadline'] < $today) {
        $overdueDebts[] = ['customer_name' => $c['customer_name'], 'remaining' => $remaining, 'deadline' => $c['credit_deadline']];
    }
}

$payload = [
    'server_today' => $today,
    'range' => ['period' => $period, 'start' => $rangeStart, 'end' => $rangeEnd],
    'summary' => [
        'total_sales' => (float)$mainReport['sales']['total_sales'],
        'transactions' => (int)$mainReport['sales']['transactions'],
        'total_expenses' => (float)$mainReport['expenses']['total_expenses'],
        'total_damage_loss' => (float)$mainReport['damages']['total_loss'],
        'gross_profit' => (float)$mainReport['sales']['total_profit'],
        'net_profit' => (float)$mainReport['net_profit'],
        'product_revenue' => (float)$mainReport['revenue_split']['product_revenue'],
        'service_revenue' => (float)$mainReport['revenue_split']['service_revenue'],
        'total_credit' => (float)$creditRange['total_credit'],
        'credit_count' => (int)$creditRange['credit_count'],
    ],
    'month' => [
        'total_sales' => (float)$monthReport['sales']['total_sales'],
        'total_expenses' => (float)$monthReport['expenses']['total_expenses'],
        'net_profit' => (float)$monthReport['net_profit'],
    ],
    'stock' => [
        'total_products' => $totalProducts,
        'total_variants' => (int)($stockValue['total_variants'] ?? 0),
        'total_quantity' => (int)($stockValue['total_quantity'] ?? 0),
        'total_services' => $totalServices,
        'buying_value' => round((float)($stockValue['stock_buying_value'] ?? 0), 2),
        'selling_value' => round((float)($stockValue['stock_selling_value'] ?? 0), 2),
        'expected_gross_profit' => round((float)($stockValue['stock_selling_value'] ?? 0) - (float)($stockValue['stock_buying_value'] ?? 0), 2),
    ],
    'low_stock_alerts' => $lowStock,
    'top_selling' => array_slice($mainReport['top_selling'], 0, 5),
    'debt_alerts' => [
        'due_today' => $dueTodayDebts,
        'overdue' => $overdueDebts,
        'due_today_total' => array_sum(array_column($dueTodayDebts, 'remaining')),
        'overdue_total' => array_sum(array_column($overdueDebts, 'remaining')),
    ],
];

// Workers see sales, expenses, stock counts and low-stock alerts — but
// nothing that reveals how the business earns its profit.
if (!can_see_costs($user)) {
    unset($payload['summary']['gross_profit'], $payload['summary']['net_profit'], $payload['summary']['total_damage_loss']);
    unset($payload['month']['net_profit']);
    unset($payload['stock']['buying_value'], $payload['stock']['expected_gross_profit']);
    $payload['top_selling'] = strip_keys_list($payload['top_selling'], ['profit']);
    $payload['is_worker_view'] = true;
}

respond(true, $payload);
