<?php
/**
 * MABUMBATECH - Forgot Password (2-step, via security question)
 *
 * step 1: POST { step: "find", username }
 *         -> returns the security_question (not the answer)
 * step 2: POST { step: "reset", username, security_answer, new_password }
 *         -> verifies answer, sets new password
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Invalid request method.', 405);

$data = body();
$pdo = get_db();

$step = $data['step'] ?? 'find';
$username = clean($data['username'] ?? '');

if ($username === '') respond(false, null, 'Please enter your username.', 422);

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) respond(false, null, 'No account found with that username.', 404);

if ($step === 'find') {
    respond(true, ['security_question' => $user['security_question']]);
}

if ($step === 'reset') {
    $answer = strtolower(trim($data['security_answer'] ?? ''));
    $newPassword = $data['new_password'] ?? '';

    if ($answer === '' || strlen($newPassword) < 6) {
        respond(false, null, 'Please provide the answer and a new password (min 6 characters).', 422);
    }

    if (!password_verify($answer, $user['security_answer_hash'])) {
        respond(false, null, 'That answer is not correct.', 401);
    }

    $upd = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
    $upd->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

    respond(true, null, 'Password reset successfully. You can now log in.');
}

respond(false, null, 'Invalid step.', 422);
