<?php
/**
 * MABUMBATECH — sample configuration.
 * Copy this file to config.php and fill in your real database
 * credentials, OR simply run Backend/install.php and it will be
 * generated for you automatically.
 *
 * config.php is intentionally excluded from version control
 * (see .gitignore) since it holds real credentials.
 */

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'office1_mabumbatech',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    // Random per-install string used to salt session cookies.
    // install.php generates a fresh one automatically.
    'app_key' => 'change-this-before-going-live',
];
