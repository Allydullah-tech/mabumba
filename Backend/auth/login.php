<?php
/**
 * MABUMBATECH — Login
 * POST { username, password }
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Invalid request method.', 405);

$data = body();
$missing = missing_fields($data, ['username', 'password']);
if ($missing) respond(false, null, 'Please provide username and password.', 422);

$pdo = get_db();
$stmt = $pdo->prepare(
    'SELECT u.*, r.slug AS role, r.name AS role_name
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.username = ?'
);
$stmt->execute([clean($data['username'])]);
$user = $stmt->fetch();

if (!$user || !password_verify($data['password'], $user['password_hash'])) {
    respond(false, null, 'Incorrect username or password.', 401);
}

if ($user['status'] !== 'active') {
    respond(false, null, 'Your account has been disabled. Contact your administrator.', 403);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];

log_activity($pdo, $user, 'login', 'login', null, $user['full_name'] . ' logged in');

respond(true, [
    'id' => $user['id'],
    'full_name' => $user['full_name'],
    'username' => $user['username'],
    'role' => $user['role'],
    'role_name' => $user['role_name'],
], 'Welcome back, ' . $user['full_name'] . '.');
