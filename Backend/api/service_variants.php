<?php
/**
 * MABUMBATECH — Service Types/Variants API
 *
 * A "type" is an optional priced variant of a catalog service — e.g.
 * service "Printing" might have types "Color" and "Black & White",
 * each with its own price and minimum selling price. Unlike product
 * variants, types are OPT-IN: a service with no rows here is simply
 * sold at its own base price (see services.php) — nothing here is
 * required to sell a service.
 *
 * GET    -> list/search types. Any logged-in role can view — the Sales
 *           screen needs this to build a cart.
 *           ?service_id= restricts to one parent's types.
 *           ?id= returns a single type (with parent service name attached).
 *           ?names_only=1 returns a lightweight list with a ready-to-display
 *           "Service — Type" label, for pickers (Sales).
 * POST   -> create a new type under an existing service
 *           (administrator, manager, stock_manager).
 * PUT    -> update a type (same roles).
 * DELETE -> archive a type (same roles)   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$MANAGE_ROLES = ['administrator'];
$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

function service_variant_display_name(string $serviceName, string $variantName): string
{
    return $serviceName . ' — ' . $variantName;
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('
            SELECT sv.*, s.name AS service_name, s.unit AS service_unit
            FROM service_variants sv
            JOIN services s ON s.id = sv.service_id
            WHERE sv.id = ?
        ');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) respond(false, null, 'Service type not found.', 404);
        respond(true, $row);
    }

    $where = '1=1';
    $params = [];
    if (!empty($_GET['service_id'])) { $where .= ' AND sv.service_id = ?'; $params[] = (int)$_GET['service_id']; }
    if (!empty($_GET['status'])) { $where .= ' AND sv.status = ?'; $params[] = clean($_GET['status']); }
    else if (empty($_GET['include_disabled'])) { $where .= " AND sv.status = 'active'"; }

    if (!empty($_GET['names_only'])) {
        $stmt = $pdo->prepare("
            SELECT sv.id, sv.service_id, sv.variant_name, sv.price, sv.minimum_price, s.name AS service_name
            FROM service_variants sv
            JOIN services s ON s.id = sv.service_id
            WHERE $where AND s.status = 'active'
            ORDER BY s.name, sv.variant_name
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['display_name'] = service_variant_display_name($r['service_name'], $r['variant_name']);
        }
        respond(true, $rows);
    }

    $stmt = $pdo->prepare("
        SELECT sv.*, s.name AS service_name
        FROM service_variants sv
        JOIN services s ON s.id = sv.service_id
        WHERE $where
        ORDER BY s.name, sv.variant_name
    ");
    $stmt->execute($params);
    respond(true, $stmt->fetchAll());
}

$d = body();

if ($method === 'POST') {
    require_role($MANAGE_ROLES);

    $missing = missing_fields($d, ['service_id', 'variant_name']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    $serviceId = (int)$d['service_id'];
    $svcStmt = $pdo->prepare("SELECT id, name FROM services WHERE id = ? AND status = 'active'");
    $svcStmt->execute([$serviceId]);
    $service = $svcStmt->fetch();
    if (!$service) respond(false, null, 'Parent service not found.', 404);

    $variantName = clean($d['variant_name']);
    $dupStmt = $pdo->prepare('SELECT id FROM service_variants WHERE service_id = ? AND variant_name = ?');
    $dupStmt->execute([$serviceId, $variantName]);
    if ($dupStmt->fetch()) respond(false, null, "\"$variantName\" already exists for this service.", 422);

    $price = (float)($d['price'] ?? 0);
    $minimumPrice = (isset($d['minimum_price']) && $d['minimum_price'] !== '') ? (float)$d['minimum_price'] : null;

    $stmt = $pdo->prepare('
        INSERT INTO service_variants (service_id, variant_name, price, minimum_price, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$serviceId, $variantName, $price, $minimumPrice, 'active', $user['id']]);
    $newId = (int)$pdo->lastInsertId();

    log_activity($pdo, $user, 'create', 'service_variant', $newId,
        'Added type "' . $variantName . '" to service "' . $service['name'] . '"');
    respond(true, ['id' => $newId], 'Type added successfully.');
}

if ($method === 'PUT') {
    require_role($MANAGE_ROLES);
    if (empty($d['id'])) respond(false, null, 'Type id is required.', 422);

    $existingStmt = $pdo->prepare('
        SELECT sv.*, s.name AS service_name
        FROM service_variants sv JOIN services s ON s.id = sv.service_id
        WHERE sv.id = ?
    ');
    $existingStmt->execute([(int)$d['id']]);
    $existing = $existingStmt->fetch();
    if (!$existing) respond(false, null, 'Service type not found.', 404);

    $variantName = clean($d['variant_name'] ?? $existing['variant_name']);
    if ($variantName !== $existing['variant_name']) {
        $dupStmt = $pdo->prepare('SELECT id FROM service_variants WHERE service_id = ? AND variant_name = ? AND id != ?');
        $dupStmt->execute([$existing['service_id'], $variantName, $existing['id']]);
        if ($dupStmt->fetch()) respond(false, null, "\"$variantName\" already exists for this service.", 422);
    }

    $newPrice = isset($d['price']) ? (float)$d['price'] : (float)$existing['price'];
    $minimumPrice = array_key_exists('minimum_price', $d)
        ? (($d['minimum_price'] !== '' && $d['minimum_price'] !== null) ? (float)$d['minimum_price'] : null)
        : $existing['minimum_price'];
    $newStatus = clean($d['status'] ?? $existing['status']);

    $stmt = $pdo->prepare('
        UPDATE service_variants
        SET variant_name = ?, price = ?, minimum_price = ?, status = ?
        WHERE id = ?
    ');
    $stmt->execute([$variantName, $newPrice, $minimumPrice, $newStatus, (int)$d['id']]);

    $changes = [];
    if ($variantName !== $existing['variant_name']) $changes[] = 'renamed "' . $existing['variant_name'] . '" -> "' . $variantName . '"';
    if ((float)$existing['price'] != $newPrice) $changes[] = 'price ' . audit_money_diff($existing['price'], $newPrice);
    if ($newStatus !== $existing['status']) $changes[] = 'status ' . $existing['status'] . ' -> ' . $newStatus;

    log_activity($pdo, $user, 'update', 'service_variant', $existing['id'],
        'Updated type on "' . $existing['service_name'] . '"' . ($changes ? ': ' . implode(', ', $changes) : ''));
    respond(true, null, 'Updated successfully.');
}

if ($method === 'DELETE') {
    require_role($MANAGE_ROLES);
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'Type id is required.', 422);

    $stmt = $pdo->prepare('
        SELECT sv.*, s.name AS service_name
        FROM service_variants sv JOIN services s ON s.id = sv.service_id
        WHERE sv.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    $pdo->prepare("UPDATE service_variants SET status = 'disabled' WHERE id = ?")->execute([$id]);
    log_activity($pdo, $user, 'delete', 'service_variant', $id,
        'Archived type "' . ($row['variant_name'] ?? 'unknown') . '" from "' . ($row['service_name'] ?? 'unknown') . '"');
    respond(true, null, 'Type archived.');
}

respond(false, null, 'Unsupported method.', 405);
