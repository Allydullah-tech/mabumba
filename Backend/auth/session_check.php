<?php
/**
 * MABUMBATECH — Returns the currently logged-in user (or 401 if none).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';

$user = require_login();
respond(true, [
    'id' => (int)$user['id'],
    'full_name' => $user['full_name'],
    'username' => $user['username'],
    'role' => $user['role'],
    'role_name' => $user['role_name'],
]);
