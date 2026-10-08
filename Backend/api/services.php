<?php
/**
 * MABUMBATECH — Services API (catalog services: Photocopy, Printing, etc.)
 *
 * These are pre-priced, reusable service offerings. They carry no stock.
 * A service can optionally have priced TYPES (e.g. Printing -> Color /
 * Black & White) managed via service_variants.php — see that file for
 * type CRUD. This file's `variants` field (on ?id= and ?names_only=1)
 * and `variant_count` field (on the plain list) simply surface that data.
 * A sale can also use a fully custom, one-off negotiated service instead
 * of picking one from this catalog — see Backend/api/sales.php.
 *
 * When a service is created from the Services page, its priced descriptions
 * are created with it (`variants: [{variant_name, price, minimum_price}, ...]`,
 * at least one; a single `first_variant` object is still accepted). More can
 * be added later via service_variants.php.
 *
 * GET    -> list/search services. Any logged-in role can view.
 * POST   -> create (administrator, manager, stock_manager)
 * PUT    -> update (administrator, manager, stock_manager)
 * DELETE -> archive (administrator, manager, stock_manager)   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function resolve_service_category_id(PDO $pdo, array $d): ?int
{
    if (!empty($d['category_name'])) {
        $cat = clean($d['category_name']);
        $pdo->prepare("INSERT INTO categories (name, type) VALUES (?, 'service') ON DUPLICATE KEY UPDATE name = name")
            ->execute([$cat]);
        $g = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND type = 'service'");
        $g->execute([$cat]);
        return (int)$g->fetchColumn();
    }
    if (isset($d['category_id']) && $d['category_id'] !== '') {
        return (int)$d['category_id'];
    }
    return null;
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT sv.*, c.name AS category_name FROM services sv LEFT JOIN categories c ON c.id = sv.category_id WHERE sv.id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(false, null, 'Service not found.', 404);

        $varStmt = $pdo->prepare("SELECT id, variant_name, price, minimum_price, status FROM service_variants WHERE service_id = ? AND status != 'disabled' ORDER BY variant_name");
        $varStmt->execute([$row['id']]);
        $row['variants'] = $varStmt->fetchAll();

        respond(true, $row);
    }

    $where = 'WHERE 1=1';
    $params = [];
    if (!empty($_GET['status'])) { $where .= ' AND sv.status = ?'; $params[] = clean($_GET['status']); }
    if (!empty($_GET['category_id'])) { $where .= ' AND sv.category_id = ?'; $params[] = (int)$_GET['category_id']; }
    if (!empty($_GET['q'])) { $where .= ' AND sv.name LIKE ?'; $params[] = '%' . clean($_GET['q']) . '%'; }

    if (!empty($_GET['names_only'])) {
        $stmt = $pdo->prepare("SELECT sv.id, sv.name, sv.unit, sv.price, sv.minimum_price FROM services sv $where ORDER BY sv.name ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Attach any active types (e.g. Printing -> Color / Black & White) so
        // pickers like Sales can offer the right variant instead of just the
        // service's own base price.
        $varStmt = $pdo->prepare("SELECT id, service_id, variant_name, price, minimum_price FROM service_variants WHERE service_id = ? AND status = 'active' ORDER BY variant_name");
        foreach ($rows as &$r) {
            $varStmt->execute([$r['id']]);
            $r['variants'] = $varStmt->fetchAll();
        }
        respond(true, $rows);
    }

    $stmt = $pdo->prepare("SELECT sv.*, c.name AS category_name,
                            (SELECT COUNT(*) FROM service_variants x WHERE x.service_id = sv.id AND x.status = 'active') AS variant_count,
                            (SELECT MIN(x.price) FROM service_variants x WHERE x.service_id = sv.id AND x.status = 'active') AS min_variant_price,
                            (SELECT MAX(x.price) FROM service_variants x WHERE x.service_id = sv.id AND x.status = 'active') AS max_variant_price
                            FROM services sv
                            LEFT JOIN categories c ON c.id = sv.category_id
                            $where ORDER BY sv.name ASC");
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    require_role($MANAGE_ROLES);
    $d = body();
    $missing = missing_fields($d, ['name']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $name = clean($d['name']);
    $categoryId = resolve_service_category_id($pdo, $d);
    $minimumPrice = isset($d['minimum_price']) && $d['minimum_price'] !== '' ? (float)$d['minimum_price'] : null;
    $price = (float)($d['price'] ?? 0);

    // Priced descriptions created together with the service.
    $rawVariants = [];
    if (!empty($d['variants']) && is_array($d['variants'])) $rawVariants = $d['variants'];
    elseif (!empty($d['first_variant']) && is_array($d['first_variant'])) $rawVariants = [$d['first_variant']];

    $variants = [];
    $seenNames = [];
    foreach ($rawVariants as $n => $fv) {
        if (!is_array($fv)) continue;
        $fvName = clean($fv['variant_name'] ?? '') ?: 'Standard';
        $label = $n === 0 ? 'the first description' : 'description "' . $fvName . '"';
        if (!isset($fv['price']) || $fv['price'] === '' || (float)$fv['price'] < 0) {
            respond(false, null, 'Enter the price for ' . $label . '.', 422);
        }
        $key = mb_strtolower($fvName);
        if (isset($seenNames[$key])) {
            respond(false, null, 'Two descriptions are both called "' . $fvName . '". Give each description its own name.', 422);
        }
        $seenNames[$key] = true;
        $variants[] = [
            'name' => $fvName,
            'price' => (float)$fv['price'],
            'minimum_price' => isset($fv['minimum_price']) && $fv['minimum_price'] !== '' ? (float)$fv['minimum_price'] : null,
        ];
    }
    if ($variants) {
        // Keep the service's own price columns in step with its first description.
        $price = $variants[0]['price'];
        $minimumPrice = $variants[0]['minimum_price'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO services
            (name, category_id, unit, price, minimum_price, description, status, created_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([
            $name, $categoryId, clean($d['unit'] ?? 'job') ?: 'job', $price, $minimumPrice,
            clean($d['description'] ?? ''), 'active', $user['id'],
        ]);
        $newId = $pdo->lastInsertId();
        $vIns = $pdo->prepare('INSERT INTO service_variants
            (service_id, variant_name, price, minimum_price, status, created_by, created_at, updated_at)
            VALUES (?,?,?,?,?,?,NOW(),NOW())');
        foreach ($variants as $v) {
            $vIns->execute([$newId, $v['name'], $v['price'], $v['minimum_price'], 'active', $user['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(false, null, 'Could not add the service: ' . $e->getMessage(), 500);
    }
    log_activity($pdo, $user, 'create', 'service', $newId, 'Added service: "' . $name . '"'
        . ($variants ? ' with ' . count($variants) . ' description' . (count($variants) === 1 ? '' : 's')
            . ' (' . implode(', ', array_map(fn($v) => $v['name'] . ' TZS ' . number_format($v['price']), $variants)) . ')' : ''));
    respond(true, ['id' => $newId], 'Service added successfully.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    $d = body();
    if (empty($d['id'])) respond(false, null, 'Service id is required.', 422);

    $existingStmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Service not found.', 404);

    $categoryId = resolve_service_category_id($pdo, $d) ?? $existing['category_id'];
    // Only touch the minimum price if the form actually sent it (services that
    // have descriptions no longer show this field when editing).
    $minimumPrice = array_key_exists('minimum_price', $d)
        ? ($d['minimum_price'] !== '' && $d['minimum_price'] !== null ? (float)$d['minimum_price'] : null)
        : ($existing['minimum_price'] !== null ? (float)$existing['minimum_price'] : null);
    $name = clean($d['name'] ?? $existing['name']);
    $newPrice = (float)($d['price'] ?? $existing['price']);
    $newStatus = clean($d['status'] ?? $existing['status']);

    $pdo->prepare('UPDATE services SET
        name=?, category_id=?, unit=?, price=?, minimum_price=?, description=?, status=?, updated_at=NOW()
        WHERE id=?')
        ->execute([
            $name, $categoryId, clean($d['unit'] ?? $existing['unit']), $newPrice, $minimumPrice,
            clean($d['description'] ?? $existing['description']), $newStatus, (int)$d['id'],
        ]);

    $changes = [];
    if ($existing['name'] !== $name) $changes[] = 'name "' . $existing['name'] . '" to "' . $name . '"';
    if ((float)$existing['price'] != $newPrice) $changes[] = 'price ' . audit_money_diff($existing['price'], $newPrice);
    if ($existing['status'] !== $newStatus) $changes[] = 'status ' . $existing['status'] . ' to ' . $newStatus;

    log_activity($pdo, $user, 'update', 'service', $existing['id'],
        'Edited "' . $existing['name'] . '"' . ($changes ? ': ' . implode(', ', $changes) : ' (no significant changes)'));

    respond(true, null, 'Updated successfully.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Service id is required.', 422);
    $lookup = $pdo->prepare('SELECT name FROM services WHERE id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    $pdo->prepare('UPDATE services SET status = "disabled" WHERE id = ?')->execute([$id]);
    log_activity($pdo, $user, 'delete', 'service', $id, 'Archived service: "' . ($row['name'] ?? 'unknown') . '"');
    respond(true, null, 'Service archived.');
}

respond(false, null, 'Unsupported method.', 405);
