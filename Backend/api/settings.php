<?php
/**
 * MABUMBATECH — Settings API
 * GET -> all settings as a flat key/value object (any logged-in role
 *        can read — e.g. the receipt screen needs company_name/currency).
 * PUT -> administrator only. body: { key: value, key2: value2, ... }
 *        Any subset of known keys; unknown keys are ignored.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

$KNOWN_KEYS = [
    'company_name', 'company_tagline', 'company_location', 'company_phone', 'company_email',
    'currency', 'invoice_prefix', 'tax_enabled', 'tax_rate', 'default_reorder_level', 'logo_path',
];

if ($method === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[$r['setting_key']] = $r['setting_value']; }
    respond(true, $out);
}

if ($method === 'PUT') {
    require_role(['administrator']);
    $d = body();
    if (empty($d) || !is_array($d)) respond(false, null, 'No settings were submitted.', 422);

    $updated = [];
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($d as $key => $value) {
        if (!in_array($key, $KNOWN_KEYS, true)) continue;
        $stmt->execute([$key, clean((string)$value)]);
        $updated[] = $key;
    }

    if (empty($updated)) respond(false, null, 'None of the submitted settings were recognised.', 422);

    log_activity($pdo, $user, 'update', 'settings', null, 'Updated settings: ' . implode(', ', $updated));
    respond(true, ['updated' => $updated], 'Settings saved successfully.');
}

respond(false, null, 'Unsupported method.', 405);
