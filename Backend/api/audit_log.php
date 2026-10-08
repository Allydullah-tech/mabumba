<?php
/**
 * MABUMBATECH — Activity / Audit Log API (read-only, administrator only)
 * GET -> list log entries, most recent first.
 *        Filters: ?start=&end= (date range on created_at),
 *                 ?user_id=, ?entity_type=, ?action=,
 *                 ?q= free-text search (user name, description, module, action)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';

require_role(['administrator']);
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Unsupported method.', 405);
}

$where = 'WHERE 1=1';
$params = [];

if (!empty($_GET['start'])) { $where .= ' AND DATE(created_at) >= ?'; $params[] = clean($_GET['start']); }
if (!empty($_GET['end']))   { $where .= ' AND DATE(created_at) <= ?'; $params[] = clean($_GET['end']); }
if (!empty($_GET['user_id'])) { $where .= ' AND user_id = ?'; $params[] = (int)$_GET['user_id']; }
if (!empty($_GET['entity_type'])) { $where .= ' AND entity_type = ?'; $params[] = clean($_GET['entity_type']); }
if (!empty($_GET['action'])) { $where .= ' AND action = ?'; $params[] = clean($_GET['action']); }
if (!empty($_GET['q'])) {
    $where .= ' AND (user_name LIKE ? OR description LIKE ? OR entity_type LIKE ? OR action LIKE ?)';
    $q = '%' . clean($_GET['q']) . '%';
    array_push($params, $q, $q, $q, $q);
}

$limit = !empty($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 200;

$stmt = $pdo->prepare("SELECT * FROM audit_log $where ORDER BY created_at DESC, id DESC LIMIT $limit");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$usersStmt = $pdo->query('SELECT DISTINCT user_id, user_name FROM audit_log WHERE user_id IS NOT NULL ORDER BY user_name ASC');
$users = $usersStmt->fetchAll();

respond(true, ['entries' => $rows, 'users' => $users]);
