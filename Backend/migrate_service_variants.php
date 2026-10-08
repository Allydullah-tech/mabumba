<?php
/**
 * MABUMBATECH — One-time migration: Service Types/Variants
 *
 * Run this ONCE in your browser:
 *   http://localhost/mabumbatech-business/Backend/migrate_service_variants.php
 *
 * WHAT THIS DOES
 * Adds the new `service_variants` table so a catalog service can
 * optionally have priced types — e.g. service "Printing" can have
 * variants "Color" and "Black & White", each with its own price.
 * This is purely additive:
 *   - No existing table is changed.
 *   - Every existing service keeps working exactly as before, sold
 *     at its own base price — types are opt-in per service, added
 *     from Services > "Manage Types".
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
    $existsStmt = $pdo->query("SHOW TABLES LIKE 'service_variants'");
    if ($existsStmt->fetch()) {
        $alreadyDone = true;
    }

    if (!$alreadyDone) {
        $pdo->beginTransaction();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `service_variants` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `service_id` INT UNSIGNED NOT NULL,
              `variant_name` VARCHAR(100) NOT NULL,
              `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `minimum_price` DECIMAL(12,2) NULL DEFAULT NULL,
              `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
              `created_by` INT UNSIGNED NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_service_variant_name` (`service_id`, `variant_name`),
              KEY `fk_svcvariant_service` (`service_id`),
              KEY `idx_svcvariant_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log[] = 'Created service_variants table.';

        try {
            $pdo->exec("ALTER TABLE `service_variants` ADD CONSTRAINT `fk_svcvariant_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE");
            $log[] = 'Linked service_variants -> services.';
        } catch (Throwable $e) {
            $log[] = 'Skipped foreign key link (likely already exists).';
        }

        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration — Service Types</title>
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;background:#060a14;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px;}
  .box{background:#0b1830;padding:36px 40px;border-radius:14px;max-width:560px;}
  h2{color:#4c86ff;margin-top:0;}
  li{margin-bottom:8px; font-size:14px; color:#c7d5f5;}
  a{color:#4c86ff;}
  .err{background:#3a1c22;color:#ff9b9b;border:1px solid #5c2530;padding:14px;border-radius:8px;font-size:13.5px;}
</style></head>
<body>
<div class="box">
  <?php if (!empty($error)): ?>
    <h2>Migration failed</h2>
    <div class="err"><?= htmlspecialchars($error) ?></div>
    <p style="font-size:13px;color:#8fa4d6;">Nothing was changed — the migration ran inside a transaction and was rolled back. Fix the issue above and reload this page to try again.</p>
  <?php elseif ($alreadyDone): ?>
    <h2>Already migrated</h2>
    <p style="font-size:14px;color:#c7d5f5;">The service_variants table already exists on this database. You can safely delete this file.</p>
  <?php else: ?>
    <h2>Migration complete</h2>
    <ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
    <p style="font-size:13px;color:#8fa4d6;">You can safely delete this file now. Go to <a href="../Frontend/pages/services.html">Services</a> and use "Manage Types" on any service (e.g. turn "Printing" into "Color" / "Black &amp; White" types).</p>
  <?php endif; ?>
</div>
</body></html>
