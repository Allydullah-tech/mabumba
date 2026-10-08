<?php
/**
 * MABUMBATECH — Users API (Administrator only)
 * GET    -> list all users
 * POST   -> add a new user with one of the 5 roles
 * PUT    -> update a user (name, role, status) — password optional
 * DELETE -> remove a user   ?id=
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

$admin = require_role(['administrator']);
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

$VALID_ROLE_SLUGS = ['administrator', 'worker'];

function role_id_for_slug(PDO $pdo, string $slug): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT u.id, u.full_name, u.username, r.slug AS role, r.name AS role_name, u.status, u.created_at
                          FROM users u JOIN roles r ON r.id = u.role_id
                          ORDER BY u.created_at DESC');
    respond(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    $d = body();
    $missing = missing_fields($d, ['full_name', 'username', 'password', 'role', 'security_question', 'security_answer']);
    if ($missing) respond(false, null, 'Missing fields: ' . implode(', ', $missing), 422);

    if (!in_array($d['role'], $GLOBALS['VALID_ROLE_SLUGS'], true)) respond(false, null, 'Please choose a valid role.', 422);
    if (strlen($d['password']) < 6) respond(false, null, 'Password must be at least 6 characters.', 422);

    $roleId = role_id_for_slug($pdo, $d['role']);
    if (!$roleId) respond(false, null, 'That role does not exist.', 422);

    $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $check->execute([clean($d['username'])]);
    if ($check->fetchColumn() > 0) respond(false, null, 'That username is already taken.', 409);

    $stmt = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role_id, security_question, security_answer_hash, status, created_by, created_at, updated_at)
                            VALUES (?,?,?,?,?,?,"active",?,NOW(),NOW())');
    $stmt->execute([
        clean($d['full_name']),
        clean($d['username']),
        password_hash($d['password'], PASSWORD_DEFAULT),
        $roleId,
        clean($d['security_question']),
        password_hash(strtolower(trim($d['security_answer'])), PASSWORD_DEFAULT),
        $admin['id'],
    ]);
    $newId = $pdo->lastInsertId();
    log_activity($pdo, $admin, 'create', 'user', $newId, 'Created ' . $d['role'] . ' account: "' . clean($d['full_name']) . '" (username: ' . clean($d['username']) . ')');
    respond(true, ['id' => $newId], 'User account created successfully.');
}

if ($method === 'PUT') {
    $d = body();
    if (empty($d['id'])) respond(false, null, 'User id is required.', 422);
    $targetId = (int)$d['id'];

    $existingStmt = $pdo->prepare('SELECT u.*, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $existingStmt->execute([$targetId]);
    $existingUser = $existingStmt->fetch();
    if (!$existingUser) respond(false, null, 'User not found.', 404);

    if ($targetId === (int)$admin['id'] && isset($d['status']) && $d['status'] !== 'active') {
        respond(false, null, 'You cannot disable your own account.', 422);
    }

    $fields = [];
    $params = [];
    if (isset($d['full_name'])) { $fields[] = 'full_name = ?'; $params[] = clean($d['full_name']); }
    if (isset($d['role']) && in_array($d['role'], $GLOBALS['VALID_ROLE_SLUGS'], true)) {
        $newRoleId = role_id_for_slug($pdo, $d['role']);
        if ($newRoleId) { $fields[] = 'role_id = ?'; $params[] = $newRoleId; }
    }
    if (isset($d['status']) && in_array($d['status'], ['active', 'disabled'], true)) { $fields[] = 'status = ?'; $params[] = $d['status']; }
    if (!empty($d['password'])) {
        if (strlen($d['password']) < 6) respond(false, null, 'Password must be at least 6 characters.', 422);
        $fields[] = 'password_hash = ?'; $params[] = password_hash($d['password'], PASSWORD_DEFAULT);
    }
    if (empty($fields)) respond(false, null, 'Nothing to update.', 422);

    $fields[] = 'updated_at = NOW()';
    $params[] = $targetId;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    $changes = [];
    if (isset($d['role']) && $d['role'] !== $existingUser['role']) $changes[] = 'role ' . $existingUser['role'] . ' to ' . $d['role'];
    if (isset($d['status']) && $d['status'] !== $existingUser['status']) $changes[] = 'status ' . $existingUser['status'] . ' to ' . $d['status'];
    if (!empty($d['password'])) $changes[] = 'password reset';
    log_activity($pdo, $admin, 'update', 'user', $targetId,
        'Edited user "' . $existingUser['full_name'] . '"' . ($changes ? ': ' . implode(', ', $changes) : ' (no role/status changes)'));

    respond(true, null, 'User updated successfully.');
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(false, null, 'User id is required.', 422);
    if ($id === (int)$admin['id']) respond(false, null, 'You cannot delete your own account.', 422);
    $lookup = $pdo->prepare('SELECT u.full_name, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $lookup->execute([$id]);
    $row = $lookup->fetch();
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    if ($row) {
        log_activity($pdo, $admin, 'delete', 'user', $id, 'Deleted ' . $row['role'] . ' account: "' . $row['full_name'] . '"');
    }
    respond(true, null, 'User removed.');
}

respond(false, null, 'Unsupported method.', 405);
