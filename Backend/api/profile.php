<?php
/**
 * MABUMBATECH — Own Profile API
 * GET -> current user's profile
 * PUT -> update own name / password / security question (requires current password)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';

$user = require_login();
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    respond(true, [
        'id' => (int)$user['id'],
        'full_name' => $user['full_name'],
        'username' => $user['username'],
        'role' => $user['role'],
        'role_name' => $user['role_name'],
        'security_question' => $user['security_question'],
        'created_at' => $user['created_at'],
    ]);
}

if ($method === 'PUT') {
    $d = body();

    $fields = [];
    $params = [];

    if (!empty($d['full_name'])) {
        $fields[] = 'full_name = ?';
        $params[] = clean($d['full_name']);
    }

    if (!empty($d['new_password'])) {
        if (empty($d['current_password']) || !password_verify($d['current_password'], $user['password_hash'])) {
            respond(false, null, 'Your current password is incorrect.', 401);
        }
        if (strlen($d['new_password']) < 6) respond(false, null, 'New password must be at least 6 characters.', 422);
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($d['new_password'], PASSWORD_DEFAULT);
    }

    if (!empty($d['security_question']) && !empty($d['security_answer'])) {
        if (empty($d['current_password']) || !password_verify($d['current_password'], $user['password_hash'])) {
            respond(false, null, 'Your current password is incorrect.', 401);
        }
        $fields[] = 'security_question = ?';
        $params[] = clean($d['security_question']);
        $fields[] = 'security_answer_hash = ?';
        $params[] = password_hash(strtolower(trim($d['security_answer'])), PASSWORD_DEFAULT);
    }

    if (empty($fields)) respond(false, null, 'Nothing to update.', 422);

    $fields[] = 'updated_at = NOW()';
    $params[] = $user['id'];
    $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    respond(true, null, 'Profile updated successfully.');
}

respond(false, null, 'Unsupported method.', 405);
