<?php
/**
 * MABUMBATECH — Installer
 * Run this once in your browser: http://yourdomain/Backend/install.php
 * It will:
 *   1. Connect to MySQL using the details you provide (creating the
 *      database itself if it doesn't exist yet)
 *   2. Import Database/mabumbatech_schema.sql (creates all tables,
 *      seeds roles/payment methods/expense categories/settings)
 *   3. Create Backend/config.php with your DB details + a fresh app key
 *   4. Create the first Administrator account
 *
 * After install completes, this file blocks itself from running again.
 * Delete config.php manually if you ever need to reinstall.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$configPath = __DIR__ . '/config.php';
$alreadyInstalled = false;
if (file_exists($configPath)) {
    try {
        $pdoCheck = new PDO(
            'mysql:host=' . ((require $configPath)['db']['host'] ?? 'localhost')
            . ';dbname=' . ((require $configPath)['db']['name'] ?? ''),
            (require $configPath)['db']['user'] ?? 'root',
            (require $configPath)['db']['password'] ?? ''
        );
        $installedFlag = $pdoCheck->query("SELECT setting_value FROM settings WHERE setting_key = 'installed'")->fetchColumn();
        $alreadyInstalled = $installedFlag === '1';
    } catch (Throwable $e) {
        $alreadyInstalled = false; // config.php exists but DB isn't reachable/seeded yet — allow retry
    }
}

if ($alreadyInstalled) {
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><title>Already Installed</title>
    <style>
      body{font-family:'Segoe UI',Arial,sans-serif;background:#060a14;color:#fff;display:flex;height:100vh;align-items:center;justify-content:center;text-align:center;margin:0;}
      .box{background:#0b1830;padding:40px;border-radius:14px;max-width:420px;}
      h2{color:#4c86ff;} a{color:#4c86ff;}
    </style></head><body>
    <div class="box">
    <h2>MABUMBATECH</h2>
    <p>The system is already installed.</p>
    <p><a href="../Frontend/index.html">Go to the website &rarr;</a></p>
    <p style="font-size:13px;color:#8fa4d6">To reinstall, delete <code>Backend/config.php</code> and empty the database first.</p>
    </div></body></html>
    <?php
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? 'office1_mabumbatech');
    $db_user = trim($_POST['db_user'] ?? 'root');
    $db_pass = $_POST['db_pass'] ?? '';
    $company_name = trim($_POST['company_name'] ?? 'MABUMBATECH');
    $company_phone = trim($_POST['company_phone'] ?? '');
    $company_email = trim($_POST['company_email'] ?? '');
    $company_location = trim($_POST['company_location'] ?? '');

    $admin_name = trim($_POST['admin_name'] ?? '');
    $admin_username = trim($_POST['admin_username'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';
    $admin_password2 = $_POST['admin_password2'] ?? '';
    $security_question = trim($_POST['security_question'] ?? '');
    $security_answer = trim($_POST['security_answer'] ?? '');

    if ($db_host === '' || $db_name === '' || $db_user === '') $errors[] = 'Database host, name and user are required.';
    if ($admin_name === '' || $admin_username === '') $errors[] = 'Administrator full name and username are required.';
    if (strlen($admin_password) < 6) $errors[] = 'Administrator password must be at least 6 characters.';
    if ($admin_password !== $admin_password2) $errors[] = 'Administrator passwords do not match.';
    if ($security_question === '' || $security_answer === '') $errors[] = 'Security question and answer are required.';

    if (empty($errors)) {
        try {
            $pdoRoot = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

            $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $sqlFile = dirname(__DIR__) . '/Database/mabumbatech_schema.sql';
            if (!file_exists($sqlFile)) {
                throw new Exception('Could not find Database/mabumbatech_schema.sql. Make sure the Frontend, Backend and Database folders are kept together.');
            }
            $sql = str_replace("\r\n", "\n", file_get_contents($sqlFile));

            $lines = array_filter(explode("\n", $sql), function ($line) {
                return strpos(ltrim($line), '--') !== 0;
            });
            $sqlNoComments = implode("\n", $lines);

            // Split on ';' only when it is NOT inside a quoted string (descriptions may contain ';').
            $statements = [];
            $buf = '';
            $inQuote = false;
            $len = strlen($sqlNoComments);
            for ($i = 0; $i < $len; $i++) {
                $ch = $sqlNoComments[$i];
                if ($ch === '\\' && $inQuote && $i + 1 < $len) { $buf .= $ch . $sqlNoComments[++$i]; continue; }
                if ($ch === "'") $inQuote = !$inQuote;
                if ($ch === ';' && !$inQuote) { $statements[] = trim($buf); $buf = ''; continue; }
                $buf .= $ch;
            }
            if (trim($buf) !== '') $statements[] = trim($buf);
            $statements = array_filter($statements);
            foreach ($statements as $stmt) {
                if ($stmt === '') continue;
                $pdo->exec($stmt);
            }

            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $checkStmt->execute([$admin_username]);
            if ($checkStmt->fetchColumn() > 0) {
                throw new Exception('That administrator username already exists in the database.');
            }

            $adminRoleId = (int)$pdo->query("SELECT id FROM roles WHERE slug = 'administrator'")->fetchColumn();

            $insert = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role_id, security_question, security_answer_hash, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())');
            $insert->execute([
                $admin_name, $admin_username, password_hash($admin_password, PASSWORD_DEFAULT), $adminRoleId,
                $security_question, password_hash(strtolower(trim($security_answer)), PASSWORD_DEFAULT),
            ]);

            $set = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $set->execute(['company_name', $company_name !== '' ? $company_name : 'MABUMBATECH']);
            if ($company_phone !== '') $set->execute(['company_phone', $company_phone]);
            if ($company_email !== '') $set->execute(['company_email', $company_email]);
            if ($company_location !== '') $set->execute(['company_location', $company_location]);
            $set->execute(['installed', '1']);

            $appKey = bin2hex(random_bytes(32));
            $configContent = "<?php\n// Auto-generated by install.php on " . date('Y-m-d H:i:s') . "\nreturn [\n" .
                "    'db' => [\n" .
                "        'host'     => " . var_export($db_host, true) . ",\n" .
                "        'name'     => " . var_export($db_name, true) . ",\n" .
                "        'user'     => " . var_export($db_user, true) . ",\n" .
                "        'password' => " . var_export($db_pass, true) . ",\n" .
                "        'charset'  => 'utf8mb4',\n" .
                "    ],\n" .
                "    'app_key' => " . var_export($appKey, true) . ",\n" .
                "];\n";
            file_put_contents($configPath, $configContent);

            $success = true;
        } catch (Exception $e) {
            $errors[] = 'Install failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Install — MABUMBATECH</title>
<style>
  :root{--navy:#060a14;--navy2:#0b1830;--blue:#1459ff;--blue-bright:#4c86ff;--text:#EAEEF6;--muted:#8fa4d6;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:var(--navy);color:var(--text);padding:30px 16px;}
  .wrap{max-width:560px;margin:0 auto;}
  .brand{text-align:center;margin-bottom:24px;}
  .brand h1{color:var(--blue-bright);font-size:22px;letter-spacing:1px;margin:6px 0 2px;}
  .brand p{color:var(--muted);font-size:12px;margin:0;}
  .card{background:var(--navy2);border-radius:14px;padding:26px;box-shadow:0 10px 30px rgba(0,0,0,.3);}
  h2{font-size:15px;color:var(--blue-bright);margin:18px 0 10px;border-bottom:1px solid #1c2b4d;padding-bottom:6px;}
  h2:first-child{margin-top:0;}
  label{display:block;font-size:12.5px;color:var(--muted);margin:10px 0 4px;}
  input{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #223357;background:#0e1626;color:var(--text);font-size:14px;}
  input:focus{outline:1px solid var(--blue-bright);}
  button{width:100%;margin-top:22px;padding:13px;border:none;border-radius:9px;background:var(--blue);color:#fff;font-weight:700;font-size:14.5px;cursor:pointer;}
  button:hover{background:#0f49da;}
  .row{display:flex;gap:12px;}
  .row > div{flex:1;}
  .msg{padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;}
  .err{background:#3a1c22;color:#ff9b9b;border:1px solid #5c2530;}
  .ok{background:#0f2a1e;color:#8be0ac;border:1px solid #1d5c3d;}
  .foot{text-align:center;color:var(--muted);font-size:11.5px;margin-top:18px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <h1>MABUMBATECH</h1>
    <p>Technology. Innovation. Solutions. — System Installer</p>
  </div>

  <div class="card">
    <?php if ($success): ?>
      <div class="msg ok">Installation complete! Your administrator account has been created.</div>
      <p style="font-size:14px;">You can now log in as an administrator.</p>
      <a href="../Frontend/index.html"><button type="button">Go to the Website</button></a>
    <?php else: ?>

      <?php if (!empty($errors)): ?>
        <div class="msg err"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <h2>Database Connection</h2>
        <label>Database Host</label>
        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>

        <label>Database Name</label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'office1_mabumbatech') ?>" required>

        <div class="row">
          <div>
            <label>Database Username</label>
            <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
          </div>
          <div>
            <label>Database Password</label>
            <input type="password" name="db_pass" value="">
          </div>
        </div>

        <h2>Business Details</h2>
        <label>Company Name</label>
        <input type="text" name="company_name" value="<?= htmlspecialchars($_POST['company_name'] ?? 'MABUMBATECH') ?>" required>
        <label>Phone</label>
        <input type="text" name="company_phone" value="<?= htmlspecialchars($_POST['company_phone'] ?? '+255 620 839 640 / +255 760 620 418') ?>">
        <label>Email</label>
        <input type="text" name="company_email" value="<?= htmlspecialchars($_POST['company_email'] ?? 'mabumbatech@gmail.com') ?>">
        <label>Location</label>
        <input type="text" name="company_location" value="<?= htmlspecialchars($_POST['company_location'] ?? 'Mbeya Iyunga, Ikuti') ?>">

        <h2>First Administrator Account</h2>
        <label>Full Name</label>
        <input type="text" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>

        <label>Username</label>
        <input type="text" name="admin_username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" required>

        <div class="row">
          <div>
            <label>Password (min. 6 characters)</label>
            <input type="password" name="admin_password" required minlength="6">
          </div>
          <div>
            <label>Confirm Password</label>
            <input type="password" name="admin_password2" required minlength="6">
          </div>
        </div>

        <label>Security Question (used to reset password later)</label>
        <input type="text" name="security_question" placeholder="e.g. What is your mother's maiden name?" value="<?= htmlspecialchars($_POST['security_question'] ?? '') ?>" required>

        <label>Answer to Security Question</label>
        <input type="text" name="security_answer" required>

        <button type="submit">Install System</button>
      </form>
    <?php endif; ?>
  </div>
  <p class="foot">Keep the Frontend, Backend and Database folders together in the same location.</p>
</div>
</body>
</html>
