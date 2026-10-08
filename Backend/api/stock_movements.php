<?php
/**
 * MABUMBATECH — Stock Movements API (read-only ledger view)
 * GET -> list movements, most recent first.
 *        Filters: ?variant_id=, ?movement_type=purchase|sale|damage|adjustment,
 *                  ?start=&end= (date range on created_at)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';

require_login();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(false, null, 'Unsupported method.', 405);

$where = 'WHERE 1=1';
$params = [];
if (!empty($_GET['variant_id'])) { $where .= ' AND sm.variant_id = ?'; $params[] = (int)$_GET['variant_id']; }
if (!empty($_GET['movement_type']) && in_array($_GET['movement_type'], ['purchase', 'sale', 'damage', 'adjustment'], true)) {
    $where .= ' AND sm.movement_type = ?'; $params[] = $_GET['movement_type'];
}
if (!empty($_GET['start'])) { $where .= ' AND DATE(sm.created_at) >= ?'; $params[] = clean($_GET['start']); }
if (!empty($_GET['end']))   { $where .= ' AND DATE(sm.created_at) <= ?'; $params[] = clean($_GET['end']); }

$limit = !empty($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 200;

$stmt = $pdo->prepare("SELECT sm.*, p.name AS product_name, v.variant_name, v.unit, u.full_name AS recorded_by_name
                        FROM stock_movements sm
                        JOIN product_variants v ON v.id = sm.variant_id
                        JOIN products p ON p.id = v.product_id
                        JOIN users u ON u.id = sm.recorded_by
                        $where ORDER BY sm.created_at DESC, sm.id DESC LIMIT $limit");
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) { $r['display_name'] = $r['product_name'] . ' — ' . $r['variant_name']; }
unset($r);
respond(true, $rows);
