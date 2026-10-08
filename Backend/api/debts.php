<?php
/**
 * MABUMBATECH — Debts (Credit Sales) API
 *
 * A credit sale is a sale_transactions row (payment_type = 'credit')
 * that can hold several items — see Backend/api/sales.php. The item
 * list is summarised from sale_items.description since a debt can
 * span more than one product/service/custom line.
 *
 * GET  -> list credit sales with computed remaining balance.
 *         ?scope=today restricts to sales made today (dashboard widget).
 *         ?status=pending|overdue|paid filters by computed status.
 *         ?history=<id> returns full payment history for one credit sale.
 * POST -> record a repayment against a credit sale.
 *         body: { sale_id, amount, payment_method_id, payment_date, note }
 *         Recording a payment re-checks the customer's overdue count.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/customer_helper.php';

$user = require_role(['administrator']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function debt_base_query(): string
{
    return "SELECT s.id, s.invoice_no, s.sale_date, s.credit_deadline, s.customer_id, s.customer_name, s.customer_phone, s.total_amount,
                   u.full_name AS sold_by_name,
                   (SELECT GROUP_CONCAT(si.description SEPARATOR ', ') FROM sale_items si WHERE si.transaction_id = s.id) AS item_names,
                   COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = s.id), 0) AS paid_amount
            FROM sale_transactions s
            JOIN users u ON u.id = s.sold_by
            WHERE s.payment_type = 'credit'";
}

if ($method === 'GET' && !empty($_GET['history'])) {
    $saleId = (int)$_GET['history'];
    $stmt = $pdo->prepare("SELECT s.id, s.invoice_no, s.sale_date, s.credit_deadline, s.customer_name, s.customer_phone, s.total_amount,
                                   (SELECT GROUP_CONCAT(si.description SEPARATOR ', ') FROM sale_items si WHERE si.transaction_id = s.id) AS item_names
                            FROM sale_transactions s WHERE s.id = ? AND s.payment_type = 'credit'");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) respond(false, null, 'Credit sale not found.', 404);

    $pStmt = $pdo->prepare('SELECT dp.payment_date, dp.amount, pm.name AS method, dp.note, u.full_name AS recorded_by_name
                             FROM debt_payments dp
                             JOIN users u ON u.id = dp.recorded_by
                             LEFT JOIN payment_methods pm ON pm.id = dp.payment_method_id
                             WHERE dp.sale_id = ? ORDER BY dp.payment_date ASC, dp.id ASC');
    $pStmt->execute([$saleId]);
    $payments = $pStmt->fetchAll();

    $totalPaid = 0;
    foreach ($payments as $p) { $totalPaid += (float)$p['amount']; }
    $sale['payments'] = $payments;
    $sale['total_paid'] = round($totalPaid, 2);
    $sale['remaining'] = max(round((float)$sale['total_amount'] - $totalPaid, 2), 0);

    respond(true, $sale);
}

if ($method === 'GET') {
    $sql = debt_base_query();
    $params = [];

    if (!empty($_GET['scope']) && $_GET['scope'] === 'today') {
        $sql .= ' AND s.sale_date = ?';
        $params[] = today();
    }
    if (!empty($_GET['start'])) { $sql .= ' AND s.sale_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $sql .= ' AND s.sale_date <= ?'; $params[] = clean($_GET['end']); }

    $sql .= ' ORDER BY s.credit_deadline ASC, s.sale_date DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $today = today();
    foreach ($rows as &$row) {
        $remaining = round((float)$row['total_amount'] - (float)$row['paid_amount'], 2);
        $row['remaining'] = max($remaining, 0);
        if ($remaining <= 0.01) {
            $row['status'] = 'paid';
        } elseif (!empty($row['credit_deadline']) && $row['credit_deadline'] < $today) {
            $row['status'] = 'overdue';
        } else {
            $row['status'] = 'pending';
        }
    }
    unset($row);

    if (!empty($_GET['status'])) {
        $wantStatus = clean($_GET['status']);
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $wantStatus));
    }

    respond(true, $rows);
}

if ($method === 'POST') {
    $d = body();
    $missing = missing_fields($d, ['sale_id', 'amount']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $saleId = (int)$d['sale_id'];
    $amount = (float)$d['amount'];
    if ($amount <= 0) respond(false, null, 'Enter a valid payment amount.', 422);

    $stmt = $pdo->prepare("SELECT s.*, COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = s.id), 0) AS paid_amount
                            FROM sale_transactions s WHERE s.id = ? AND s.payment_type = 'credit'");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) respond(false, null, 'Credit sale not found.', 404);

    $remaining = round((float)$sale['total_amount'] - (float)$sale['paid_amount'], 2);
    if ($amount > $remaining + 0.01) {
        respond(false, null, 'That amount is more than the remaining balance of ' . number_format($remaining) . '.', 422);
    }

    $paymentMethodId = !empty($d['payment_method_id']) ? (int)$d['payment_method_id'] : null;

    $ins = $pdo->prepare('INSERT INTO debt_payments (sale_id, amount, payment_method_id, payment_date, note, recorded_by, created_at)
                           VALUES (?,?,?,?,?,?,NOW())');
    $ins->execute([
        $saleId, $amount, $paymentMethodId,
        !empty($d['payment_date']) ? clean($d['payment_date']) : today(),
        clean($d['note'] ?? ''),
        $user['id'],
    ]);

    $newRemaining = round($remaining - $amount, 2);

    if ($sale['customer_id']) refresh_customer_restriction($pdo, (int)$sale['customer_id']);

    log_activity($pdo, $user, 'payment', 'debt_payment', $saleId,
        'Recorded payment of TZS ' . number_format($amount) . ' for ' . ($sale['customer_name'] ?: 'a customer') . '\'s debt (' . $sale['invoice_no'] . '), leaving TZS ' . number_format(max($newRemaining, 0)) . ' remaining');

    respond(true, ['remaining' => max($newRemaining, 0)],
        $newRemaining <= 0 ? 'Fully paid off. Debt cleared.' : 'Payment recorded. ' . number_format($newRemaining) . ' still remaining.');
}

respond(false, null, 'Unsupported method.', 405);
