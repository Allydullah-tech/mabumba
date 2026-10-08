<?php
/**
 * MABUMBATECH — Expenses API
 * GET    -> list expenses (filter ?start=&end=)
 * POST   -> record a new expense (administrator, worker)
 * PUT/DELETE restricted to administrator only.
 * PUT    -> edit an existing expense (logged with before/after values)
 * DELETE -> remove   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator'];
$CREATE_ROLES = ['administrator', 'worker'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function resolve_expense_category_id(PDO $pdo, array $d): ?int
{
    if (!empty($d['category_name'])) {
        $cat = clean($d['category_name']);
        $pdo->prepare('INSERT INTO expense_categories (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name')->execute([$cat]);
        $g = $pdo->prepare('SELECT id FROM expense_categories WHERE name = ?');
        $g->execute([$cat]);
        return (int)$g->fetchColumn();
    }
    if (isset($d['category_id']) && $d['category_id'] !== '') return (int)$d['category_id'];
    return null;
}

if ($method === 'GET') {
    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['start'])) { $where .= ' AND e.expense_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $where .= ' AND e.expense_date <= ?'; $params[] = clean($_GET['end']); }
    if (!empty($_GET['category_id'])) { $where .= ' AND e.category_id = ?'; $params[] = (int)$_GET['category_id']; }
    $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $stmt = $pdo->prepare("SELECT e.*, u.full_name AS recorded_by_name, ec.name AS category_name, pm.name AS payment_method_name
                            FROM expenses e
                            JOIN users u ON u.id = e.recorded_by
                            LEFT JOIN expense_categories ec ON ec.id = e.category_id
                            LEFT JOIN payment_methods pm ON pm.id = e.payment_method_id
                            $where ORDER BY e.expense_date DESC, e.id DESC LIMIT $limit");
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    require_role($CREATE_ROLES);
    $d = body();
    $missing = missing_fields($d, ['title', 'amount']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);
    if ((float)$d['amount'] <= 0) respond(false, null, 'Enter a valid amount.', 422);

    $categoryId = resolve_expense_category_id($pdo, $d);

    $stmt = $pdo->prepare('INSERT INTO expenses
        (title, category_id, amount, payment_method_id, vendor, reference_no, note, recorded_by, expense_date, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())');
    $stmt->execute([
        clean($d['title']), $categoryId, (float)$d['amount'],
        !empty($d['payment_method_id']) ? (int)$d['payment_method_id'] : null,
        clean($d['vendor'] ?? ''), clean($d['reference_no'] ?? ''), clean($d['note'] ?? ''),
        $user['id'], !empty($d['expense_date']) ? clean($d['expense_date']) : today(),
    ]);
    $newId = $pdo->lastInsertId();
    log_activity($pdo, $user, 'create', 'expense', $newId, 'Recorded expense: "' . clean($d['title']) . '" — TZS ' . number_format((float)$d['amount']));
    respond(true, ['id' => $newId], 'Expense recorded successfully.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Expense id is required.', 422);
    $missing = missing_fields($d, ['title', 'amount']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);
    if ((float)$d['amount'] <= 0) respond(false, null, 'Enter a valid amount.', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Expense not found.', 404);

    $categoryId = resolve_expense_category_id($pdo, $d) ?? $existing['category_id'];
    $newTitle = clean($d['title']);
    $newAmount = (float)$d['amount'];
    $newDate = !empty($d['expense_date']) ? clean($d['expense_date']) : $existing['expense_date'];

    $pdo->prepare('UPDATE expenses SET title=?, category_id=?, amount=?, payment_method_id=?, vendor=?, reference_no=?, note=?, expense_date=? WHERE id=?')
        ->execute([
            $newTitle, $categoryId, $newAmount,
            isset($d['payment_method_id']) && $d['payment_method_id'] !== '' ? (int)$d['payment_method_id'] : $existing['payment_method_id'],
            clean($d['vendor'] ?? $existing['vendor']), clean($d['reference_no'] ?? $existing['reference_no']),
            clean($d['note'] ?? $existing['note']), $newDate, $existing['id'],
        ]);

    $changes = [];
    if ($existing['title'] !== $newTitle) $changes[] = 'title "' . $existing['title'] . '" to "' . $newTitle . '"';
    if ((float)$existing['amount'] != $newAmount) $changes[] = 'amount ' . audit_money_diff($existing['amount'], $newAmount);
    if ($existing['expense_date'] !== $newDate) $changes[] = 'date ' . $existing['expense_date'] . ' to ' . $newDate;

    log_activity($pdo, $user, 'update', 'expense', $existing['id'],
        'Edited expense "' . $existing['title'] . '"' . ($changes ? ': ' . implode(', ', $changes) : ' (no changes)'));

    respond(true, null, 'Expense updated successfully.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Expense id is required.', 422);
    $lookup = $pdo->prepare('SELECT title, amount FROM expenses WHERE id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
    if ($row) {
        log_activity($pdo, $user, 'delete', 'expense', $id, 'Deleted expense: "' . $row['title'] . '" — TZS ' . number_format($row['amount']));
    }
    respond(true, null, 'Expense removed.');
}

respond(false, null, 'Unsupported method.', 405);
