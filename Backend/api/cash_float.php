<?php
/**
 * MABUMBATECH — Cash Float API ("Opening Cash & Float")
 *
 * GET  ?date=YYYY-MM-DD          -> full state for one date (see
 *      cash_float_state_for_date): opening_auto, opening_manual,
 *      opening_effective, added, reduced, current_float, entries.
 * GET  ?start=&end=[&by=Name]    -> entries in range, most recent first
 *      (History tab). `by` filters on created_by_name.
 * POST { entry_date, type, amount, note } -> type: opening|add|reduce.
 *      A new 'opening' for a date that already has one marks the old
 *      row `replaced` (kept, shown struck through) instead of being
 *      rejected or deleted.
 * DELETE ?id=123 -> remove one entry (corrects a mistake).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/cash_audit_helper.php';

$user = require_role(['administrator', 'worker']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_GET['date'])) {
        respond(true, cash_float_state_for_date($pdo, clean($_GET['date'])));
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['start'])) { $where .= ' AND entry_date >= ?'; $params[] = clean($_GET['start']); }
    if (!empty($_GET['end']))   { $where .= ' AND entry_date <= ?'; $params[] = clean($_GET['end']); }
    if (!empty($_GET['by']))    { $where .= ' AND created_by_name = ?'; $params[] = clean($_GET['by']); }
    $stmt = $pdo->prepare("SELECT * FROM cash_float_entries $where ORDER BY entry_date DESC, created_at DESC LIMIT 500");
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    $d = body();
    $missing = missing_fields($d, ['entry_date', 'type', 'amount']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $entryDate = clean($d['entry_date']);
    $type = clean($d['type']);
    if (!in_array($type, ['opening', 'add', 'reduce'], true)) {
        respond(false, null, 'Type must be opening, add, or reduce.', 422);
    }
    $amount = (float)$d['amount'];
    if ($amount <= 0) respond(false, null, 'Amount must be greater than zero.', 422);
    $note = !empty($d['note']) ? clean($d['note']) : null;

    $pdo->beginTransaction();
    try {
        if ($type === 'opening') {
            $pdo->prepare("UPDATE cash_float_entries SET replaced = 1 WHERE entry_date = ? AND type = 'opening' AND replaced = 0")
                ->execute([$entryDate]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO cash_float_entries (entry_date, type, amount, note, created_by, created_by_name, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([$entryDate, $type, $amount, $note, $user['id'], $user['full_name']]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        respond(false, null, 'Could not save: ' . $e->getMessage(), 500);
    }

    $label = ['opening' => 'Set opening cash', 'add' => 'Added to float', 'reduce' => 'Reduced float'][$type];
    log_activity($pdo, $user, 'create', 'cash_float', $id, "$label of TZS " . number_format($amount) . " for $entryDate" . ($note ? " ($note)" : ''));

    respond(true, cash_float_state_for_date($pdo, $entryDate), 'Saved.');
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Entry id is required.', 422);
    $stmt = $pdo->prepare('SELECT * FROM cash_float_entries WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(false, null, 'Entry not found.', 404);

    $pdo->prepare('DELETE FROM cash_float_entries WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'cash_float', $id,
        "Deleted a {$row['type']} float entry of TZS " . number_format((float)$row['amount']) . " for {$row['entry_date']}");
    respond(true, null, 'Deleted.');
}

respond(false, null, 'Unsupported method.', 405);
