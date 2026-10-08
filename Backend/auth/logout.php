<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';

$_SESSION = [];
session_destroy();
respond(true, null, 'Logged out successfully.');
