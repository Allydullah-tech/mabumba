<?php
/**
 * MABUMBATECH — Customer Profiles API
 *
 * GET (no params) -> list every customer, with aggregate stats.
 *                    ?q= filters by name/phone. ?start=/?end= restrict
 *                    "Purchases"/"Total Spent"/"Last Purchase" to sales
 *                    in that range (customers with none in range are
 *                    dropped). Outstanding debt and overdue count are
 *                    always CURRENT figures, not period-bound.
 * GET ?id=...     -> one customer's full profile: summary, every sale,
 *                    and a merged, date-sorted payment_records list
 *                    (cash sales, debt repayments, overdue debts).
 * PUT             -> administrator/manager only. Manually restrict or
 *                     un-restrict a customer (see customer_helper.php
 *                     for the automatic 3-overdue-debts rule this
 *                     overrides). Every override is written to the
 *                     activity log.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/customer_helper.php';

$user = require_role(['administrator']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function customer_summary_select(): string
{
    return "
        c.id, c.name, c.phone, c.email, c.address, c.customer_type, c.status, c.first_purchase_date,
        COUNT(st.id) AS total_purchases,
        COALESCE(SUM(st.total_amount), 0) AS total_spent,
        MAX(st.sale_date) AS last_purchase_date,
        SUM(st.payment_type = 'credit') AS credit_purchases,
        COALESCE((
            SELECT SUM(st2.total_amount) - COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st2.id), 0)
            FROM sale_transactions st2 WHERE st2.customer_id = c.id AND st2.payment_type = 'credit'
        ), 0) AS total_outstanding,
        COALESCE((
            SELECT COUNT(*) FROM sale_transactions st3
            WHERE st3.customer_id = c.id AND st3.payment_type = 'credit'
              AND st3.credit_deadline < CURDATE()
              AND (st3.total_amount - COALESCE((SELECT SUM(dp2.amount) FROM debt_payments dp2 WHERE dp2.sale_id = st3.id), 0)) > 0.01
        ), 0) AS overdue_count
    ";
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];

        $sumStmt = $pdo->prepare("SELECT " . customer_summary_select() . " FROM customers c
                                   LEFT JOIN sale_transactions st ON st.customer_id = c.id
                                   WHERE c.id = ? GROUP BY c.id");
        $sumStmt->execute([$id]);
        $summary = $sumStmt->fetch();
        if (!$summary) respond(false, null, 'Customer not found.', 404);

        $profile = compute_payment_profile($pdo, $id, $summary['status']);

        $salesStmt = $pdo->prepare("SELECT st.id, st.invoice_no, st.sale_date, st.total_amount, st.payment_type, st.credit_deadline,
                                            (SELECT GROUP_CONCAT(CONCAT(si.description, ' x', si.quantity) SEPARATOR ', ')
                                                FROM sale_items si WHERE si.transaction_id = st.id) AS items_summary,
                                            COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st.id), 0) AS paid_amount
                                     FROM sale_transactions st
                                     WHERE st.customer_id = ?
                                     ORDER BY st.sale_date DESC, st.id DESC");
        $salesStmt->execute([$id]);
        $sales = $salesStmt->fetchAll();

        $records = [];

        $cashStmt = $pdo->prepare("SELECT st.id AS sale_id, st.sale_date AS event_date, st.total_amount AS amount, pm.name AS method
                                    FROM sale_transactions st LEFT JOIN payment_methods pm ON pm.id = st.payment_method_id
                                    WHERE st.customer_id = ? AND st.payment_type = 'cash'
                                    ORDER BY st.sale_date DESC, st.id DESC");
        $cashStmt->execute([$id]);
        foreach ($cashStmt->fetchAll() as $row) {
            $records[] = ['type' => 'cash_sale', 'event_date' => $row['event_date'], 'amount' => (float)$row['amount'], 'method' => $row['method'], 'sale_id' => (int)$row['sale_id']];
        }

        $debtPayStmt = $pdo->prepare("SELECT dp.sale_id, dp.payment_date AS event_date, dp.amount, pm.name AS method
                                       FROM debt_payments dp
                                       JOIN sale_transactions st ON st.id = dp.sale_id
                                       LEFT JOIN payment_methods pm ON pm.id = dp.payment_method_id
                                       WHERE st.customer_id = ?
                                       ORDER BY dp.payment_date DESC, dp.id DESC");
        $debtPayStmt->execute([$id]);
        foreach ($debtPayStmt->fetchAll() as $row) {
            $records[] = ['type' => 'debt_payment', 'event_date' => $row['event_date'], 'amount' => (float)$row['amount'], 'method' => $row['method'], 'sale_id' => (int)$row['sale_id']];
        }

        $overdueStmt = $pdo->prepare("SELECT st.id AS sale_id, st.credit_deadline AS event_date,
                                              st.total_amount - COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st.id), 0) AS amount
                                       FROM sale_transactions st
                                       WHERE st.customer_id = ? AND st.payment_type = 'credit'
                                         AND st.credit_deadline < CURDATE()
                                         AND (st.total_amount - COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st.id), 0)) > 0.01
                                       ORDER BY st.credit_deadline DESC");
        $overdueStmt->execute([$id]);
        foreach ($overdueStmt->fetchAll() as $row) {
            $records[] = ['type' => 'debt_overdue', 'event_date' => $row['event_date'], 'amount' => (float)$row['amount'], 'method' => null, 'sale_id' => (int)$row['sale_id']];
        }

        usort($records, fn($a, $b) => strcmp($b['event_date'], $a['event_date']));

        respond(true, ['summary' => $summary, 'payment_profile' => $profile, 'sales' => $sales, 'payment_records' => $records]);
    }

    $joinDateSql = '';
    $joinDateParams = [];
    $hasDateFilter = false;
    if (!empty($_GET['start'])) { $joinDateSql .= ' AND st.sale_date >= ?'; $joinDateParams[] = clean($_GET['start']); $hasDateFilter = true; }
    if (!empty($_GET['end']))   { $joinDateSql .= ' AND st.sale_date <= ?'; $joinDateParams[] = clean($_GET['end']); $hasDateFilter = true; }

    $havingParts = [];
    $havingParams = [];
    if (!empty($_GET['q'])) {
        $q = '%' . clean($_GET['q']) . '%';
        $havingParts[] = '(c.name LIKE ? OR c.phone LIKE ?)';
        $havingParams[] = $q;
        $havingParams[] = $q;
    }
    if ($hasDateFilter) $havingParts[] = 'total_purchases > 0';
    $havingSql = $havingParts ? ('HAVING ' . implode(' AND ', $havingParts)) : '';

    $stmt = $pdo->prepare("SELECT " . customer_summary_select() . "
                            FROM customers c
                            LEFT JOIN sale_transactions st ON st.customer_id = c.id $joinDateSql
                            GROUP BY c.id
                            $havingSql
                            ORDER BY total_spent DESC");
    $stmt->execute(array_merge($joinDateParams, $havingParams));
    respond(true, $stmt->fetchAll());
}

if ($method === 'PUT') {
    require_role(['administrator', 'manager']);
    $d = body();
    if (empty($d['id']) || empty($d['status']) || !in_array($d['status'], ['active', 'restricted'], true)) {
        respond(false, null, 'Customer id and a valid status (active/restricted) are required.', 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([(int)$d['id']]);
    $existing = $stmt->fetch();
    if (!$existing) respond(false, null, 'Customer not found.', 404);

    if ($d['status'] === 'restricted') {
        $pdo->prepare('UPDATE customers SET status = "restricted", restricted_at = NOW(), restricted_reason = ?, restriction_overridden_by = NULL WHERE id = ?')
            ->execute([clean($d['reason'] ?? 'Manually restricted by ' . $user['full_name']), (int)$d['id']]);
    } else {
        $pdo->prepare('UPDATE customers SET status = "active", restriction_overridden_by = ? WHERE id = ?')
            ->execute([$user['id'], (int)$d['id']]);
    }

    log_activity($pdo, $user, 'update', 'customer', $existing['id'],
        ($d['status'] === 'restricted' ? 'Restricted' : 'Un-restricted') . ' customer "' . ($existing['name'] ?? $existing['phone']) . '"');

    respond(true, null, 'Customer status updated.');
}

respond(false, null, 'Unsupported method.', 405);
