<?php
/**
 * MABUMBATECH — Cash Audits API (Daily Cash Closing & Sales Reconciliation)
 *
 * GET ?action=summary&date=YYYY-MM-DD  -> live Part B figures for that
 *      date — see cash_audit_summary(). Nothing is written.
 * GET ?id=123                          -> one saved sheet
 * GET ?start=&end=&q=&by=Name          -> history list, most recent
 *      first, each row joined with that date's bank-deposit totals
 * POST { audit_date, shift, cashier_name, denominations,
 *        remarks, prepared_by_name, approved_by_name }
 *      -> saves a new sheet. Part B is recomputed server-side from the
 *         real data at this moment (never taken from the client).
 * DELETE ?id=123 -> remove a saved sheet (corrects a mistake).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/cash_audit_helper.php';

$user = require_role(['administrator', 'worker']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

$DENOMS = [10000, 5000, 2000, 1000, 500, 200, 100, 50];

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'summary') {
        $date = !empty($_GET['date']) ? clean($_GET['date']) : today();
        respond(true, cash_audit_summary($pdo, $date) + ['date' => $date]);
    }

    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM cash_audits WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(false, null, 'Cash audit sheet not found.', 404);
        $row['denominations'] = json_decode($row['denominations'], true);
        $row['electronic_sales'] = $row['electronic_sales'] ? json_decode($row['electronic_sales'], true) : [];
        $depStmt = $pdo->prepare("SELECT COALESCE(SUM(deposit_amount),0) AS deposited,
                                          (SELECT remaining_cash FROM bank_deposits WHERE deposit_date = ? ORDER BY created_at DESC, id DESC LIMIT 1) AS remaining
                                   FROM bank_deposits WHERE deposit_date = ?");
        $depStmt->execute([$row['audit_date'], $row['audit_date']]);
        $dep = $depStmt->fetch();
        $row['bank_deposit_total'] = round((float)($dep['deposited'] ?? 0), 2);
        $row['remaining_cash'] = $dep['remaining'] !== null ? round((float)$dep['remaining'], 2) : null;
        respond(true, $row);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['start'])) { $where .= ' AND ca.audit_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $where .= ' AND ca.audit_date <= ?'; $params[] = clean($_GET['end']); }
    if (!empty($_GET['by']))    { $where .= ' AND ca.created_by_name = ?'; $params[] = clean($_GET['by']); }
    if (!empty($_GET['q'])) {
        $where .= ' AND (ca.cashier_name LIKE ? OR ca.prepared_by_name LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        $params[] = $like; $params[] = $like;
    }
    $stmt = $pdo->prepare(
        "SELECT ca.id, ca.audit_date, ca.shift, ca.cashier_name, ca.total_cash_counted, ca.opening_float, ca.total_sales,
                ca.expenses_total, ca.expected_cash, ca.outstanding_customer_change, ca.outstanding_receivables,
                ca.variance, ca.remarks, ca.prepared_by_name, ca.approved_by_name, ca.created_by_name, ca.created_at,
                (SELECT COALESCE(SUM(bd.deposit_amount),0) FROM bank_deposits bd WHERE bd.deposit_date = ca.audit_date) AS bank_deposit_total,
                (SELECT bd2.remaining_cash FROM bank_deposits bd2 WHERE bd2.deposit_date = ca.audit_date ORDER BY bd2.created_at DESC, bd2.id DESC LIMIT 1) AS remaining_cash
         FROM cash_audits ca $where ORDER BY ca.audit_date DESC, ca.id DESC LIMIT 500"
    );
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    $d = body();
    $missing = missing_fields($d, ['audit_date', 'cashier_name', 'denominations', 'prepared_by_name']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $auditDate = clean($d['audit_date']);
    $shift = !empty($d['shift']) ? clean($d['shift']) : null;
    $cashierName = clean($d['cashier_name']);
    $preparedBy = clean($d['prepared_by_name']);
    $approvedBy = !empty($d['approved_by_name']) ? clean($d['approved_by_name']) : null;
    $remarks = !empty($d['remarks']) ? clean($d['remarks']) : null;

    $rawDenoms = is_array($d['denominations']) ? $d['denominations'] : [];
    $denominations = [];
    $totalCounted = 0.0;
    foreach ($DENOMS as $note) {
        $qty = max(0, (int)($rawDenoms[$note]['qty'] ?? 0));
        $amount = $qty * $note;
        $denominations[(string)$note] = ['qty' => $qty, 'amount' => $amount];
        $totalCounted += $amount;
    }

    $summary = cash_audit_summary($pdo, $auditDate);
    $variance = round($totalCounted - $summary['expected_cash'], 2);

    $stmt = $pdo->prepare(
        'INSERT INTO cash_audits
            (audit_date, shift, cashier_name, denominations, total_cash_counted, opening_float,
             cash_physical_sales, electronic_sales, electronic_sales_total, credit_sales, total_sales,
             credit_collected_today, cash_available, outstanding_customer_change,
             expenses_total, expected_cash, outstanding_receivables, variance, remarks, prepared_by_name, approved_by_name,
             created_by, created_by_name, created_at)
         VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,?,?,?,?,?, ?,?,NOW())'
    );
    $stmt->execute([
        $auditDate, $shift, $cashierName, json_encode($denominations), round($totalCounted, 2), $summary['opening_float'],
        $summary['cash_physical_sales'], json_encode($summary['electronic_sales']), $summary['electronic_sales_total'],
        $summary['credit_sales'], $summary['total_sales'],
        $summary['credit_collected_today'], $summary['cash_available'], $summary['outstanding_customer_change'],
        $summary['expenses_total'], $summary['expected_cash'], $summary['outstanding_receivables'], $variance, $remarks, $preparedBy, $approvedBy,
        $user['id'], $user['full_name'],
    ]);
    $id = (int)$pdo->lastInsertId();

    log_activity($pdo, $user, 'create', 'cash_audit', $id,
        "Saved cash audit for $auditDate — counted TZS " . number_format($totalCounted) .
        ', expected TZS ' . number_format($summary['expected_cash']) .
        ' (variance TZS ' . number_format($variance) . ')');

    respond(true, ['id' => $id, 'variance' => $variance, 'total_cash_counted' => round($totalCounted, 2)] + $summary, 'Cash audit saved.');
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Cash audit id is required.', 422);
    $stmt = $pdo->prepare('SELECT id, audit_date FROM cash_audits WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, null, 'Cash audit sheet not found.', 404);

    $pdo->prepare('DELETE FROM cash_audits WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'cash_audit', $id, "Deleted the cash audit sheet for {$row['audit_date']}");
    respond(true, null, 'Deleted.');
}

respond(false, null, 'Unsupported method.', 405);
