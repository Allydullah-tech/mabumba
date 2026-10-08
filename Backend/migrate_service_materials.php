<?php
/**
 * MABUMBATECH — One-time migration: Products used by Services
 *
 * Run this ONCE in your browser (logged in as administrator):
 *   http://localhost/mabumbatech-business/Backend/migrate_service_materials.php
 *
 * WHAT THIS DOES
 * Adds one new table, sale_item_materials, so that when a service is sold
 * the seller can say which product(s) were used (e.g. 1 x Manila for a
 * Manila Binding). It records what each sold service line consumed, with the
 * product's cost price at that moment.
 *
 * It is purely additive. No existing table or row is changed, every existing
 * service keeps selling exactly as before, and sales amounts / cash are never
 * affected — product cost only touches stock and profit.
 *
 * You can safely delete this file after running it once.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/functions.php';

$user = require_role(['administrator']);
$pdo = get_db();

$log = [];
$error = null;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sale_item_materials` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `sale_item_id` INT UNSIGNED NOT NULL,
          `variant_id` INT UNSIGNED NOT NULL,
          `quantity` INT UNSIGNED NOT NULL,
          `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          PRIMARY KEY (`id`),
          KEY `fk_simat_item` (`sale_item_id`),
          KEY `fk_simat_variant` (`variant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'Table sale_item_materials is ready.';

    $fks = [
        ['sale_item_materials', 'fk_simat_item', 'FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`) ON DELETE CASCADE'],
        ['sale_item_materials', 'fk_simat_variant', 'FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE'],
    ];
    foreach ($fks as [$table, $name, $def]) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $def");
            $log[] = "Linked $table ($name).";
        } catch (Throwable $e) {
            $log[] = "Skipped $name (already exists).";
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration — Products used in service sales</title>
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
  <?php if ($error): ?>
    <h2>Migration failed</h2>
    <div class="err"><?= htmlspecialchars($error) ?></div>
    <p style="font-size:13px;color:#8fa4d6;">Fix the issue above and reload this page to try again. It is safe to run more than once.</p>
  <?php else: ?>
    <h2>Migration complete</h2>
    <ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
    <p style="font-size:13px;color:#8fa4d6;">You can safely delete this file now. Now, when you add a service on the <a href="../Frontend/pages/sales.html">Sales</a> screen, you will be asked whether any product was used.</p>
  <?php endif; ?>
</div>
</body></html>
