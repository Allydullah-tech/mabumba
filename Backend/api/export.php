<?php
/**
 * MABUMBATECH — Generic List Export API
 *
 * Every list page (Sales, Products, Customers, Expenses, Suppliers,
 * Damages, Debts, Stock Movements, Purchases, Activity Log) shares this
 * one endpoint for its "Export PDF" / "Export Excel" buttons instead of
 * each having its own export code — one place to keep the branded
 * header/meta-strip/signature-block treatment consistent everywhere.
 *
 * GET ?type=sales|products|customers|expenses|suppliers|damages|debts|
 *            stock_movements|purchases|audit_log
 *     &format=pdf|xlsx
 *     &start=&end=  (most types; date range filter, same as that page's own filter)
 *     &status=      (products/suppliers/damages status filter, debts status)
 *     &movement_type= (stock_movements)
 *     &q=           (free-text filter, applied the same way the page itself filters)
 *
 * Permissions mirror each type's own page/API — see the require_role/
 * require_login call at the top of each case below.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/pdf_helper.php';
require_once __DIR__ . '/../helpers/xlsx_helper.php';

$pdo = get_db();
$type = clean($_GET['type'] ?? '');
$format = clean($_GET['format'] ?? 'pdf');
if (!in_array($format, ['pdf', 'xlsx'], true)) respond(false, null, 'Unsupported export format.', 422);

function company_info(PDO $pdo, array $user): array
{
    return [
        'company_name' => get_setting($pdo, 'company_name', 'MABUMBATECH'),
        'company_tagline' => get_setting($pdo, 'company_tagline', 'Technology. Innovation. Solutions.'),
        'company_location' => get_setting($pdo, 'company_location', ''),
        'company_phone' => get_setting($pdo, 'company_phone', ''),
        'company_email' => get_setting($pdo, 'company_email', ''),
        'generated_by' => $user['full_name'],
    ];
}

function do_export(string $format, string $title, array $headers, array $widths, array $rows, array $company, ?array $totalsRow, array $metaExtra): void
{
    if ($format === 'pdf') {
        stream_table_pdf($title, $headers, $widths, $rows, $company, $totalsRow, $metaExtra);
    } else {
        stream_table_xlsx($title, $headers, $rows, $company, $totalsRow, $metaExtra);
    }
}

$start = !empty($_GET['start']) ? clean($_GET['start']) : null;
$end = !empty($_GET['end']) ? clean($_GET['end']) : null;
$metaExtra = [];
if ($start && $end) $metaExtra[] = ['Date range', "$start to $end"];

switch ($type) {

    case 'sales': {
        $user = require_role(['administrator', 'worker']);
        $where = 'WHERE 1=1'; $params = [];
        if ($start) { $where .= ' AND st.sale_date >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND st.sale_date <= ?'; $params[] = $end; }
        $stmt = $pdo->prepare("SELECT st.*, u.full_name AS sold_by_name, pm.name AS payment_method_name,
                                       (SELECT GROUP_CONCAT(si.description SEPARATOR ', ') FROM sale_items si WHERE si.transaction_id = st.id) AS item_names
                                FROM sale_transactions st JOIN users u ON u.id = st.sold_by
                                LEFT JOIN payment_methods pm ON pm.id = st.payment_method_id
                                $where ORDER BY st.sale_date DESC, st.id DESC LIMIT 2000");
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $rows = array_map(fn($r) => [
            $r['invoice_no'], $r['sale_date'], $r['customer_name'] ?: 'Walk-in', $r['item_names'] ?: '',
            number_format($r['total_amount']), $r['payment_type'] === 'credit' ? 'Credit' : ($r['payment_method_name'] ?: 'Cash'), $r['sold_by_name'],
        ], $data);
        $total = array_sum(array_column($data, 'total_amount'));
        do_export($format, 'Sales List',
            ['Invoice', 'Date', 'Customer', 'Items', 'Amount', 'Payment', 'Sold By'],
            [0.13, 0.1, 0.16, 0.28, 0.12, 0.11, 0.1], $rows, company_info($pdo, $user),
            ['', '', '', 'TOTAL', number_format($total), '', ''], $metaExtra);
        break;
    }

    case 'products': {
        $user = require_login();
        $stmt = $pdo->query("SELECT p.*, c.name AS category_name,
                COUNT(v.id) AS variant_count, COALESCE(SUM(v.stock_quantity), 0) AS total_stock,
                MIN(v.selling_price) AS min_price, MAX(v.selling_price) AS max_price
            FROM products p LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants v ON v.product_id = p.id
            WHERE p.status = 'active' GROUP BY p.id ORDER BY p.name ASC");
        $data = $stmt->fetchAll();
        $rows = array_map(fn($p) => [
            $p['name'], $p['category_name'] ?: '—', $p['variant_count'], $p['total_stock'],
            $p['variant_count'] ? ($p['min_price'] == $p['max_price'] ? number_format($p['min_price']) : number_format($p['min_price']) . '-' . number_format($p['max_price'])) : '—',
            $p['status'],
        ], $data);
        do_export($format, 'Products List',
            ['Product', 'Category', 'Types', 'Total Stock', 'Price Range', 'Status'],
            [0.28, 0.18, 0.12, 0.14, 0.18, 0.1], $rows, company_info($pdo, $user), null, $metaExtra);
        break;
    }

    case 'customers': {
        $user = require_role(['administrator']);
        $stmt = $pdo->query("SELECT c.name, c.phone, c.status,
                COUNT(st.id) AS total_purchases, COALESCE(SUM(st.total_amount), 0) AS total_spent,
                COALESCE((SELECT SUM(st2.total_amount) - COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st2.id), 0)
                          FROM sale_transactions st2 WHERE st2.customer_id = c.id AND st2.payment_type = 'credit'), 0) AS outstanding
            FROM customers c LEFT JOIN sale_transactions st ON st.customer_id = c.id
            GROUP BY c.id ORDER BY total_spent DESC");
        $data = $stmt->fetchAll();
        $rows = array_map(fn($c) => [
            $c['name'] ?: 'Unnamed', $c['phone'] ?: '—', $c['total_purchases'],
            number_format($c['total_spent']), number_format($c['outstanding']), $c['status'],
        ], $data);
        do_export($format, 'Customers List',
            ['Name', 'Phone', 'Purchases', 'Total Spent', 'Outstanding', 'Status'],
            [0.26, 0.16, 0.14, 0.16, 0.16, 0.12], $rows, company_info($pdo, $user), null, $metaExtra);
        break;
    }

    case 'expenses': {
        $user = require_role(['administrator', 'worker']);
        $where = 'WHERE 1=1'; $params = [];
        if ($start) { $where .= ' AND e.expense_date >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND e.expense_date <= ?'; $params[] = $end; }
        $stmt = $pdo->prepare("SELECT e.*, ec.name AS category_name, pm.name AS payment_method_name
                                FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id
                                LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
                                $where ORDER BY e.expense_date DESC LIMIT 2000");
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $rows = array_map(fn($e) => [
            $e['title'], $e['category_name'] ?: '—', number_format($e['amount']), $e['expense_date'], $e['vendor'] ?: '—', $e['payment_method_name'] ?: '—',
        ], $data);
        $total = array_sum(array_column($data, 'amount'));
        do_export($format, 'Expenses List',
            ['Title', 'Category', 'Amount', 'Date', 'Vendor', 'Method'],
            [0.24, 0.18, 0.14, 0.14, 0.16, 0.14], $rows, company_info($pdo, $user),
            ['TOTAL', '', number_format($total), '', '', ''], $metaExtra);
        break;
    }

    case 'suppliers': {
        $user = require_role(['administrator']);
        $stmt = $pdo->query("SELECT s.name, s.phone, s.status,
                (SELECT COUNT(*) FROM purchases pu WHERE pu.supplier_id = s.id) AS purchase_count,
                (SELECT COALESCE(SUM(pu.total_cost),0) FROM purchases pu WHERE pu.supplier_id = s.id) AS total_purchased
            FROM suppliers s WHERE s.status = 'active' ORDER BY s.name ASC");
        $data = $stmt->fetchAll();
        $rows = array_map(fn($s) => [$s['name'], $s['phone'] ?: '—', $s['purchase_count'], number_format($s['total_purchased'])], $data);
        do_export($format, 'Suppliers List', ['Name', 'Phone', 'Purchases', 'Total Purchased'], [0.35, 0.25, 0.2, 0.2], $rows, company_info($pdo, $user), null, $metaExtra);
        break;
    }

    case 'damages': {
        $user = require_login();
        $where = 'WHERE 1=1'; $params = [];
        if ($start) { $where .= ' AND d.damage_date >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND d.damage_date <= ?'; $params[] = $end; }
        $stmt = $pdo->prepare("SELECT d.*, p.name AS product_name, v.variant_name, u.full_name AS recorded_by_name
                                FROM damages d LEFT JOIN product_variants v ON v.id = d.variant_id
                                LEFT JOIN products p ON p.id = v.product_id
                                JOIN users u ON u.id = d.recorded_by
                                $where ORDER BY d.damage_date DESC LIMIT 2000");
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $showCost = can_see_costs($user);
        $rows = array_map(function ($d) use ($showCost) {
            $name = $d['item_type'] === 'other' ? $d['item_name'] : ($d['product_name'] . ' — ' . $d['variant_name']);
            $cost = $d['item_type'] === 'other' ? $d['manual_cost'] : $d['loss_value'];
            $line = [$name, $d['item_type'] === 'other' ? 'Non-stock' : 'Product', $d['quantity'] ?? '—', $d['reason']];
            if ($showCost) $line[] = number_format($cost);
            $line[] = $d['damage_date'];
            $line[] = $d['recorded_by_name'];
            return $line;
        }, $data);
        if ($showCost) {
            $total = array_sum(array_map(fn($d) => $d['item_type'] === 'other' ? $d['manual_cost'] : $d['loss_value'], $data));
            do_export($format, 'Damages & Losses List',
                ['Item', 'Type', 'Qty', 'Reason', 'Loss Value', 'Date', 'By'],
                [0.22, 0.12, 0.08, 0.2, 0.14, 0.12, 0.12], $rows, company_info($pdo, $user),
                ['', '', '', 'TOTAL', number_format($total), '', ''], $metaExtra);
        } else {
            // Workers: no loss values (they would reveal buying prices).
            do_export($format, 'Damages & Losses List',
                ['Item', 'Type', 'Qty', 'Reason', 'Date', 'By'],
                [0.26, 0.14, 0.1, 0.24, 0.13, 0.13], $rows, company_info($pdo, $user),
                null, $metaExtra);
        }
        break;
    }

    case 'debts': {
        $user = require_role(['administrator']);
        $stmt = $pdo->query("SELECT s.id, s.invoice_no, s.customer_name, s.credit_deadline, s.total_amount,
                (SELECT GROUP_CONCAT(si.description SEPARATOR ', ') FROM sale_items si WHERE si.transaction_id = s.id) AS item_names,
                COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = s.id), 0) AS paid_amount
            FROM sale_transactions s WHERE s.payment_type = 'credit' ORDER BY s.credit_deadline ASC");
        $data = $stmt->fetchAll();
        $today = today();
        $rows = [];
        $totalRemaining = 0;
        foreach ($data as $d) {
            $remaining = max(round($d['total_amount'] - $d['paid_amount'], 2), 0);
            $status = $remaining <= 0.01 ? 'Paid' : (($d['credit_deadline'] ?? '') < $today ? 'Overdue' : 'Pending');
            $totalRemaining += $remaining;
            $rows[] = [$d['invoice_no'], $d['customer_name'] ?: '—', $d['item_names'] ?: '', number_format($d['total_amount']), number_format($d['paid_amount']), number_format($remaining), $d['credit_deadline'], $status];
        }
        do_export($format, 'Payments & Debts List',
            ['Invoice', 'Customer', 'Items', 'Total', 'Paid', 'Remaining', 'Deadline', 'Status'],
            [0.12, 0.15, 0.2, 0.11, 0.11, 0.11, 0.1, 0.1], $rows, company_info($pdo, $user),
            ['', '', '', '', '', 'TOTAL: ' . number_format($totalRemaining), '', ''], $metaExtra);
        break;
    }

    case 'stock_movements': {
        $user = require_login();
        $where = 'WHERE 1=1'; $params = [];
        if ($start) { $where .= ' AND DATE(sm.created_at) >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND DATE(sm.created_at) <= ?'; $params[] = $end; }
        if (!empty($_GET['movement_type'])) { $where .= ' AND sm.movement_type = ?'; $params[] = clean($_GET['movement_type']); }
        $stmt = $pdo->prepare("SELECT sm.*, p.name AS product_name, v.variant_name, v.unit, u.full_name AS recorded_by_name
                                FROM stock_movements sm JOIN product_variants v ON v.id = sm.variant_id
                                JOIN products p ON p.id = v.product_id JOIN users u ON u.id = sm.recorded_by
                                $where ORDER BY sm.created_at DESC LIMIT 2000");
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $rows = array_map(fn($m) => [
            date('d M Y H:i', strtotime($m['created_at'])),
            $m['product_name'] . ' — ' . $m['variant_name'],
            ucfirst($m['movement_type']), ($m['quantity_change'] >= 0 ? '+' : '') . $m['quantity_change'] . ' ' . $m['unit'],
            $m['note'] ?: '', $m['recorded_by_name'],
        ], $data);
        do_export($format, 'Stock Movements List',
            ['Date', 'Product / Type', 'Movement', 'Change', 'Note', 'By'],
            [0.16, 0.26, 0.14, 0.14, 0.18, 0.12], $rows, company_info($pdo, $user), null, $metaExtra);
        break;
    }

    case 'purchases': {
        $user = require_role(['administrator']);
        $where = 'WHERE 1=1'; $params = [];
        if ($start) { $where .= ' AND pu.purchase_date >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND pu.purchase_date <= ?'; $params[] = $end; }
        $stmt = $pdo->prepare("SELECT pu.*, p.name AS product_name, v.variant_name, s.name AS supplier_name, u.full_name AS recorded_by_name
                                FROM purchases pu JOIN product_variants v ON v.id = pu.variant_id
                                JOIN products p ON p.id = v.product_id
                                LEFT JOIN suppliers s ON s.id = pu.supplier_id
                                JOIN users u ON u.id = pu.recorded_by
                                $where ORDER BY pu.purchase_date DESC LIMIT 2000");
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $rows = array_map(fn($p) => [
            $p['purchase_date'], $p['product_name'] . ' — ' . $p['variant_name'],
            $p['supplier_name'] ?: '—', $p['quantity'], number_format($p['buying_price']), number_format($p['total_cost']), ucfirst($p['source']), $p['recorded_by_name'],
        ], $data);
        $total = array_sum(array_column($data, 'total_cost'));
        do_export($format, 'Purchases List',
            ['Date', 'Product / Type', 'Supplier', 'Qty', 'Unit Cost', 'Total Cost', 'Source', 'By'],
            [0.11, 0.22, 0.15, 0.08, 0.13, 0.13, 0.09, 0.09], $rows, company_info($pdo, $user),
            ['', '', '', '', 'TOTAL', number_format($total), '', ''], $metaExtra);
        break;
    }

    case 'audit_log': {
        $user = require_role(['administrator']);
        $where = 'WHERE 1=1'; $params = [];
        if ($start) { $where .= ' AND DATE(created_at) >= ?'; $params[] = $start; }
        if ($end)   { $where .= ' AND DATE(created_at) <= ?'; $params[] = $end; }
        $stmt = $pdo->prepare("SELECT * FROM audit_log $where ORDER BY created_at DESC LIMIT 2000");
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $rows = array_map(fn($a) => [
            date('d M Y H:i', strtotime($a['created_at'])), $a['user_name'], $a['user_role'], $a['action'], $a['entity_type'], $a['description'],
        ], $data);
        do_export($format, 'Activity Log',
            ['When', 'User', 'Role', 'Action', 'Module', 'Details'],
            [0.13, 0.14, 0.1, 0.1, 0.12, 0.41], $rows, company_info($pdo, $user), null, $metaExtra);
        break;
    }

    default:
        respond(false, null, 'Unknown export type.', 422);
}
