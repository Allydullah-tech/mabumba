<?php
/**
 * MABUMBATECH — Database connection
 * Returns a shared PDO instance, configured for safe defaults:
 * exceptions on error, real prepared statements (not emulated),
 * and associative-array fetches everywhere.
 */

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'The system has not been installed yet. Please run Backend/install.php first.',
        ]);
        exit;
    }

    $config = require $configFile;
    $db = $config['db'];

    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

    try {
        $pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Make MySQL's NOW()/CURDATE() match East Africa Time (UTC+3) as well.
        $pdo->exec("SET time_zone = '+03:00'");
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Could not connect to the database. Please check Backend/config.php.',
        ]);
        exit;
    }

    return $pdo;
}
