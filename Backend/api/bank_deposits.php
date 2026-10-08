<?php
/**
 * MABUMBATECH — Bank Deposits API ("Bank Deposit & Closing Cash")
 *
 * GET  ?date=YYYY-MM-DD       -> { already_deposited, deposits:[...] } for that date
 * GET  ?start=&end=[&by=Name] -> deposits in range, most recent first (History tab)
 * POST { deposit_date, total_cash, deposit_amount, remark }
 *      -> logs one deposit. `total_cash` is the Part A total as currently
 *         shown on the Cash Audit form (the client sends it — Part A may
 *         not be saved to a sheet yet). `already_deposited_before` and
 *         `remaining_cash` are computed server-side from what's already
 *         logged for that date, so a day can have more than one deposit.
 * DELETE ?id=123 -> remove one deposit (corrects a mistake).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$user = require_role(['administrator', 'worker']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_GET['date'])) {
        $date = clean($_GET['date']);
        $stmt = $pdo->prepare('SELECT * FROM bank_deposits WHERE deposit_date = ? ORDER BY created_at ASC, id ASC');
        $stmt->execute([$date]);
        $deposits = $stmt->fetchAll();
        $alreadyDeposited = array_sum(array_column($deposits, 'deposit_amount'));
        respond(true, ['date' => $date, 'already_deposited' => round($alreadyDeposited, 2), 'deposits' => $deposits]);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['start'])) { $where .= ' AND deposit_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $where .= ' AND deposit_date <= ?'; $params[] = clean($_GET['end']); }
    if (!empty($_GET['by']))    { $where .= ' AND created_by_name = ?'; $params[] = clean($_GET['by']); }
    $stmt = $pdo->prepare("SELECT * FROM bank_deposits $where ORDER BY deposit_date DESC, created_at DESC LIMIT 500");
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    $d = body();
    $missing = missing_fields($d, ['deposit_date', 'deposit_amount']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $date = clean($d['deposit_date']);
    $totalCash = (float)($d['total_cash'] ?? 0);
    $depositAmount = (float)$d['deposit_amount'];
    if ($depositAmount <= 0) respond(false, null, 'Deposit amount must be greater than zero.', 422);
    $remark = !empty($d['remark']) ? clean($d['remark']) : null;

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(deposit_amount), 0) FROM bank_deposits WHERE deposit_date = ?');
    $stmt->execute([$date]);
    $alreadyBefore = (float)$stmt->fetchColumn();
    $remaining = round($totalCash - $alreadyBefore - $depositAmount, 2);

    $ins = $pdo->prepare(
        'INSERT INTO bank_deposits (deposit_date, total_cash, deposit_amount, already_deposited_before, remaining_cash, remark, created_by, created_by_name, created_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())'
    );
    $ins->execute([$date, round($totalCash, 2), round($depositAmount, 2), round($alreadyBefore, 2), $remaining, $remark, $user['id'], $user['full_name']]);
    $id = (int)$pdo->lastInsertId();

    log_activity($pdo, $user, 'create', 'bank_deposit', $id,
        "Deposited TZS " . number_format($depositAmount) . " for $date — remaining cash TZS " . number_format($remaining));

    respond(true, [
        'id' => $id, 'deposit_date' => $date, 'already_deposited' => round($alreadyBefore + $depositAmount, 2), 'remaining_cash' => $remaining,
    ], 'Bank deposit saved.');
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Deposit id is required.', 422);
    $stmt = $pdo->prepare('SELECT * FROM bank_deposits WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, null, 'Deposit not found.', 404);

    $pdo->prepare('DELETE FROM bank_deposits WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'bank_deposit', $id,
        "Deleted a bank deposit of TZS " . number_format((float)$row['deposit_amount']) . " for {$row['deposit_date']}");
    respond(true, null, 'Deleted.');
}

respond(false, null, 'Unsupported method.', 405);
