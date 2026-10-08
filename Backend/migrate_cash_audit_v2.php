<?php
/**
 * MABUMBATECH — Migration v2: Cash Audit rebuild
 *
 * Run this ONCE in your browser (after migrate_roles_and_cash_audit.php,
 * if you haven't already — this script creates cash_float_entries and
 * cash_audits itself if they don't exist yet, so it's safe either way):
 *   http://localhost/mabumbatech-business/Backend/migrate_cash_audit_v2.php
 *
 * WHAT THIS DOES (all additive — nothing existing is removed or renamed)
 *  1. sale_transactions: adds change_left_amount, change_left_customer_name,
 *     change_collected_amount — for the "customer left change" workflow.
 *  2. cash_float_entries: adds `replaced` (for the opening-float
 *     replace-not-duplicate behaviour) — creates the table if missing.
 *  3. bank_deposits: new table (see Database/mabumbatech_schema.sql).
 *  4. cash_audits: adds credit_collected_today, cash_available,
 *     outstanding_customer_change, outstanding_receivables — creates the
 *     table if missing.
 *
 * Safe to run more than once. You can delete this file afterward.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/functions.php';

$user = require_role(['administrator']);
$pdo = get_db();
$log = [];

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition, array &$log): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        $log[] = "Added `$table`.`$column`.";
    }
}

try {
    $pdo->beginTransaction();

    // --- 1. sale_transactions: customer-left-change columns -----------
    ensure_column($pdo, 'sale_transactions', 'change_left_amount',
        "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `note`", $log);
    ensure_column($pdo, 'sale_transactions', 'change_left_customer_name',
        "VARCHAR(150) NULL DEFAULT NULL AFTER `change_left_amount`", $log);
    ensure_column($pdo, 'sale_transactions', 'change_collected_amount',
        "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `change_left_customer_name`", $log);

    // --- 2. cash_float_entries ------------------------------------------
    if (!table_exists($pdo, 'cash_float_entries')) {
        $pdo->exec("
            CREATE TABLE `cash_float_entries` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `entry_date` DATE NOT NULL,
              `type` ENUM('opening','add','reduce') NOT NULL,
              `amount` DECIMAL(14,2) NOT NULL,
              `note` VARCHAR(255) NULL,
              `replaced` TINYINT(1) NOT NULL DEFAULT 0,
              `created_by` INT UNSIGNED NULL,
              `created_by_name` VARCHAR(150) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_float_date` (`entry_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log[] = 'Created cash_float_entries table.';
    } else {
        ensure_column($pdo, 'cash_float_entries', 'replaced', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `note`", $log);
    }

    // --- 3. bank_deposits -------------------------------------------------
    if (!table_exists($pdo, 'bank_deposits')) {
        $pdo->exec("
            CREATE TABLE `bank_deposits` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `deposit_date` DATE NOT NULL,
              `total_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `deposit_amount` DECIMAL(14,2) NOT NULL,
              `already_deposited_before` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `remaining_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `remark` VARCHAR(255) NULL,
              `created_by` INT UNSIGNED NULL,
              `created_by_name` VARCHAR(150) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_deposit_date` (`deposit_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log[] = 'Created bank_deposits table.';
    }

    // --- 4. cash_audits -----------------------------------------------
    if (!table_exists($pdo, 'cash_audits')) {
        $pdo->exec("
            CREATE TABLE `cash_audits` (
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
              `credit_collected_today` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `cash_available` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `outstanding_customer_change` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `expenses_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `expected_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
              `outstanding_receivables` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
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
    } else {
        ensure_column($pdo, 'cash_audits', 'credit_collected_today', "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `total_sales`", $log);
        ensure_column($pdo, 'cash_audits', 'cash_available', "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `credit_collected_today`", $log);
        ensure_column($pdo, 'cash_audits', 'outstanding_customer_change', "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `cash_available`", $log);
        ensure_column($pdo, 'cash_audits', 'outstanding_receivables', "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `expected_cash`", $log);
    }

    $pdo->commit();
    if (!$log) $log[] = 'Nothing to do — already up to date.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration — Cash Audit v2</title>
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
    <p style="font-size:13px;color:#a7e8bd;">Nothing was changed — this ran inside a transaction and was rolled back. Fix the issue above and reload this page to try again.</p>
  <?php else: ?>
    <h2>Migration complete</h2>
    <ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
    <p style="font-size:13px;color:#a7e8bd;">You can safely delete this file now. Go to <a href="../Frontend/pages/sales.html">Sales</a> and try the new Cash Audit button.</p>
  <?php endif; ?>
</div>
</body></html>
