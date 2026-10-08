<?php
/**
 * MABUMBATECH — Shared Helper Functions
 * Loaded first by every API entry point.
 */

// Never let raw PHP warnings/notices leak into what should be pure JSON
// output — they'd break JSON.parse() on the frontend. Errors are still
// logged server-side (check the host's PHP error log), never echoed.
ini_set('display_errors', 0);
// Keep every date/time in East Africa Time regardless of the host's default (shared hosts usually run on UTC).
date_default_timezone_set('Africa/Dar_es_Salaam');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
    exit;
});

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 7, // 7 days
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Send a JSON response and stop execution.
 */
function respond(bool $success, $data = null, string $message = '', int $code = 200): void
{
    http_response_code($code);
    $out = ['success' => $success];
    if ($message !== '') $out['message'] = $message;
    if ($data !== null) $out['data'] = $data;
    echo json_encode($out);
    exit;
}

/**
 * Read the JSON body of the current request as an assoc array.
 */
function body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Require an active logged-in session. Returns the user row with an
 * extra `role` key holding the role's slug (e.g. "administrator"),
 * resolved via a join, so every caller can keep checking a simple
 * string instead of juggling role_id everywhere.
 */
function require_login(): array
{
    if (empty($_SESSION['user_id'])) {
        respond(false, null, 'Please log in to continue.', 401);
    }
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT u.*, r.slug AS role, r.name AS role_name
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.status = "active"'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        respond(false, null, 'Session expired or account disabled. Please log in again.', 401);
    }
    return $user;
}

/**
 * Require the logged-in user to hold one of the given role slugs,
 * e.g. require_role(['administrator', 'manager']).
 */
function require_role(array $roleSlugs): array
{
    $user = require_login();
    if (!in_array($user['role'], $roleSlugs, true)) {
        respond(false, null, 'You do not have permission to perform this action.', 403);
    }
    return $user;
}

/**
 * Basic string cleanup — strips tags, trims whitespace. Applied to any
 * free-text field before it reaches a query or gets stored.
 */
function clean(?string $val): string
{
    return trim(strip_tags((string)$val));
}

/**
 * Returns the list of required field names missing/empty from $data.
 */
function missing_fields(array $data, array $required): array
{
    $missing = [];
    foreach ($required as $f) {
        if (!isset($data[$f]) || $data[$f] === '' || $data[$f] === null) {
            $missing[] = $f;
        }
    }
    return $missing;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

/**
 * Read one setting value, with a fallback if it isn't set.
 */
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

/**
 * Generate the next sequential invoice number, e.g. "MBT-000047".
 * Reads the configurable prefix from settings and pads to 6 digits
 * based on how many transactions already exist.
 */
function next_invoice_no(PDO $pdo): string
{
    $prefix = get_setting($pdo, 'invoice_prefix', 'MBT');
    $count = (int)$pdo->query('SELECT COUNT(*) FROM sale_transactions')->fetchColumn();
    return $prefix . '-' . str_pad((string)($count + 1), 6, '0', STR_PAD_LEFT);
}

/**
 * Can this user see what things COST the business (buying prices,
 * suppliers, profit, stock bought-value, loss values)? Only the
 * administrator (the "office"). Workers record sales, expenses and
 * damages and see selling prices only — enforced here on the server so
 * the numbers are never even sent to a worker's browser.
 */
function can_see_costs(array $user): bool
{
    return ($user['role'] ?? '') === 'administrator';
}

/**
 * Remove the given keys from one row (assoc array).
 */
function strip_keys(array $row, array $keys): array
{
    foreach ($keys as $k) { unset($row[$k]); }
    return $row;
}

/**
 * Remove the given keys from every row of a list.
 */
function strip_keys_list(array $rows, array $keys): array
{
    return array_map(fn($r) => is_array($r) ? strip_keys($r, $keys) : $r, $rows);
}
