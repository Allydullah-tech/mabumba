<?php
/**
 * MABUMBATECH — One-time migration: two-role system + Cash Audit
 *
 * Run this ONCE in your browser:
 *   http://localhost/mabumbatech-business/Backend/migrate_roles_and_cash_audit.php
 *
 * WHAT THIS DOES
 *  1. Roles: the system now has exactly two roles — Administrator
 *     (unchanged, full access) and Worker (views stock/products/
 *     services; records sales, expenses and damages; generates
 *     reports — see the `roles` table description column). Any
 *     existing user on the old Manager / Cashier / Stock Manager /
 *     Accountant roles is moved onto Worker, and those old role
 *     rows are then removed. No user is deleted and no user loses
 *     their account — only their role slug changes.
 *  2. Adds `cash_float_entries` (opening cash + add/reduce log) and
 *     `cash_audits` (saved Daily Cash Closing sheets) — both brand
 *     new, purely additive tables. Nothing existing is touched.
 *
 * You can safely delete this file after running it once.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/functions.php';

$user = require_role(['administrator']);
$pdo = get_db();

$log = [];
$alreadyDone = false;

try {
    $rolesDone = false;
    $tablesExist = $pdo->query("SHOW TABLES LIKE 'cash_audits'")->fetch();
    $oldRoleStmt = $pdo->query("SELECT id, slug FROM roles WHERE slug IN ('manager','cashier','stock_manager','accountant')");
    $oldRoles = $oldRoleStmt->fetchAll();
    if ($tablesExist && !$oldRoles) {
        $alreadyDone = true;
    }

    if (!$alreadyDone) {
        $pdo->beginTransaction();

        // --- 1. Roles -----------------------------------------------------
        $workerId = (int)$pdo->query("SELECT id FROM roles WHERE slug = 'worker'")->fetchColumn();
        if (!$workerId) {
            $pdo->exec("INSERT INTO `roles` (`slug`, `name`, `description`) VALUES
                ('worker', 'Worker', 'Records sales, expenses and damages; views stock, products and services; generates reports. Cannot add, edit or remove products/services/stock, and has no access to customers, suppliers, purchases, debts, users or settings.')");
            $workerId = (int)$pdo->lastInsertId();
            $log[] = 'Created the Worker role.';
        }

        if ($oldRoles) {
            $oldIds = array_column($oldRoles, 'id');
            $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
            $moveStmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE role_id IN ($placeholders)");
            $moveStmt->execute(array_merge([$workerId], $oldIds));
            $moved = $moveStmt->rowCount();
            if ($moved > 0) $log[] = "Moved $moved existing user(s) from " . implode('/', array_column($oldRoles, 'slug')) . ' to Worker.';

            $delStmt = $pdo->prepare("DELETE FROM roles WHERE id IN ($placeholders)");
            $delStmt->execute($oldIds);
            $log[] = 'Removed the old Manager/Cashier/Stock Manager/Accountant roles.';
        }
        $rolesDone = true;

        // --- 2. Cash float log ---------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `cash_float_entries` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `entry_date` DATE NOT NULL,
              `type` ENUM('opening','add','reduce') NOT NULL,
              `amount` DECIMAL(14,2) NOT NULL,
              `note` VARCHAR(255) NULL,
              `created_by` INT UNSIGNED NULL,
              `created_by_name` VARCHAR(150) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_float_date` (`entry_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log[] = 'Created cash_float_entries table.';

        // --- 3. Cash audits -------------------------------------------------
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `cash_audits` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `audit_date` DATE NOT NULL,
              `shift` VARCHAR(50) NULL,
              `cashier_name` VARCHAR(150) NOT NULL,
              `denominations` TEXT NOT NULL,
              `total_cash_counted` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `opening_float` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `cash_physical_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `electronic_sales` TEXT NULL,
              `electronic_sales_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `credit_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `total_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `expenses_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `expected_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `variance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `remarks` VARCHAR(500) NULL,
              `prepared_by_name` VARCHAR(150) NOT NULL,
              `approved_by_name` VARCHAR(150) NULL,
              `created_by` INT UNSIGNED NULL,
              `created_by_name` VARCHAR(150) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_audit_date` (`audit_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log[] = 'Created cash_audits table.';

        $pdo->commit();
        if (!$log) $log[] = 'Nothing to do — already up to date.';
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration — Roles &amp; Cash Audit</title>
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;background:#0a1f16;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px;}
  .box{background:#123324;padding:36px 40px;border-radius:14px;max-width:560px;}
  h2{color:#4ade80;margin-top:0;}
  li{margin-bottom:8px; font-size:14px; color:#cdeed9;}
  a{color:#4ade80;}
  .err{background:#3a1c1c;color:#ff9b9b;border:1px solid #5c2525;padding:14px;border-radius:8px;font-size:13.5px;}
</style></head>
<body>
<div class="box">
  <?php if (!empty($error)): ?>
    <h2>Migration failed</h2>
    <div class="err"><?= htmlspecialchars($error) ?></div>
    <p style="font-size:13px;color:#a7e8bd;">Nothing was changed — the migration ran inside a transaction and was rolled back. Fix the issue above and reload this page to try again.</p>
  <?php elseif ($alreadyDone): ?>
    <h2>Already migrated</h2>
    <p style="font-size:14px;color:#cdeed9;">Only Administrator/Worker roles remain and the cash audit tables already exist. You can safely delete this file.</p>
  <?php else: ?>
    <h2>Migration complete</h2>
    <ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
    <p style="font-size:13px;color:#a7e8bd;">You can safely delete this file now. Go to <a href="../Frontend/pages/users.html">Users</a> to check everyone's role, and to <a href="../Frontend/pages/sales.html">Sales</a> to try the new Cash Audit button.</p>
  <?php endif; ?>
</div>
</body></html>
