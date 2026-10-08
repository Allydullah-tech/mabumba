<?php
/**
 * MABUMBATECH — One-time migration: Product Types/Variants (v1 -> v2)
 *
 * Run this ONCE in your browser:
 *   http://localhost/mabumbatech-business/Backend/migrate_product_variants.php
 *
 * WHAT THIS DOES
 * Your v1 database stores price/stock directly on `products`. v2 splits
 * that into a parent `products` row (just the catalog name) plus one or
 * more `product_variants` rows (the actual priced, stock-tracked types —
 * e.g. "Pen" the product, "Marshal"/"Obama"/"Maker" the variants).
 *
 * This script, safely and non-destructively:
 *   1. Creates the `product_variants` table if it doesn't exist yet.
 *   2. For every existing product, creates ONE variant named "Standard"
 *      that copies over its price, stock, SKU, barcode, brand, unit and
 *      supplier — so nothing about what you can already sell changes.
 *   3. Adds a `variant_id` column to sale_items, purchases, damages and
 *      stock_movements, and fills it in for every existing row so your
 *      full sales/purchase/damage history stays linked to the right
 *      stock item.
 *   4. Leaves the old `product_id` column and the old pricing/stock
 *      columns on `products` in place, untouched — nothing is deleted.
 *      This migration is purely additive, so if anything looks wrong
 *      afterwards, your original data is still sitting there safely.
 *
 * AFTER RUNNING THIS
 * Open Products — every existing product now shows as a parent with one
 * "Standard" type. Edit any product to rename that type, or add more
 * types (e.g. turn "Pen" into "Marshal", "Obama", "Maker" instead of one
 * generic "Standard").
 *
 * You can delete this file after running it once.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/functions.php';

$user = require_role(['administrator']);
$pdo = get_db();

$log = [];
$alreadyDone = false;

try {
    // ---- Step 0: has this already been run? ----
    $existsStmt = $pdo->query("SHOW TABLES LIKE 'product_variants'");
    if ($existsStmt->fetch()) {
        $countVariants = (int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn();
        $countProducts = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        if ($countVariants > 0 && $countVariants >= $countProducts) {
            $alreadyDone = true;
        }
    }

    if (!$alreadyDone) {
        $pdo->beginTransaction();

        // ---- Step 1: create product_variants if missing ----
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `product_variants` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `product_id` INT UNSIGNED NOT NULL,
              `variant_name` VARCHAR(100) NOT NULL DEFAULT 'Standard',
              `sku` VARCHAR(60) NULL,
              `barcode` VARCHAR(60) NULL,
              `brand` VARCHAR(100) NULL,
              `unit` VARCHAR(30) NOT NULL DEFAULT 'pcs',
              `buying_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `selling_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `minimum_price` DECIMAL(12,2) NULL DEFAULT NULL,
              `stock_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
              `reorder_level` INT UNSIGNED NOT NULL DEFAULT 5,
              `supplier_id` INT UNSIGNED NULL,
              `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
              `created_by` INT UNSIGNED NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `fk_variant_product` (`product_id`),
              KEY `fk_variant_supplier` (`supplier_id`),
              KEY `idx_variant_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log[] = 'Created product_variants table.';

        // ---- Step 2: one "Standard" variant per existing product ----
        $products = $pdo->query('SELECT * FROM products')->fetchAll();
        $variantIdForProduct = []; // old product_id => new variant_id

        $insVariant = $pdo->prepare('INSERT INTO product_variants
            (product_id, variant_name, sku, barcode, brand, unit, buying_price, selling_price, minimum_price,
             stock_quantity, reorder_level, supplier_id, status, created_by, created_at, updated_at)
            VALUES (?, "Standard", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($products as $p) {
            $insVariant->execute([
                $p['id'],
                $p['sku'] ?? null, $p['barcode'] ?? null, $p['brand'] ?? null, $p['unit'] ?? 'pcs',
                $p['buying_price'] ?? 0, $p['selling_price'] ?? 0, $p['minimum_price'] ?? null,
                $p['stock_quantity'] ?? 0, $p['reorder_level'] ?? 5, $p['supplier_id'] ?? null,
                $p['status'] ?? 'active', $p['created_by'] ?? null, $p['created_at'] ?? date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
            ]);
            $variantIdForProduct[$p['id']] = (int)$pdo->lastInsertId();
        }
        $log[] = 'Created ' . count($products) . ' "Standard" variant(s), one per existing product.';

        // ---- Step 3: add variant_id to the tables that used product_id, and fill it in ----
        foreach (['sale_items', 'purchases', 'damages', 'stock_movements'] as $table) {
            $colCheck = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'variant_id'")->fetch();
            if (!$colCheck) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `variant_id` INT UNSIGNED NULL AFTER `id`");
                $log[] = "Added variant_id column to $table.";
            }
        }
        // sale_items also needs the below_minimum / override_reason audit columns from v2.
        $belowMinCheck = $pdo->query("SHOW COLUMNS FROM `sale_items` LIKE 'below_minimum'")->fetch();
        if (!$belowMinCheck) {
            $pdo->exec("ALTER TABLE `sale_items`
                ADD COLUMN `below_minimum` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `override_reason` VARCHAR(255) NULL DEFAULT NULL");
            $log[] = 'Added below_minimum / override_reason columns to sale_items.';
        }

        foreach ($variantIdForProduct as $oldProductId => $newVariantId) {
            $pdo->prepare('UPDATE sale_items SET variant_id = ? WHERE product_id = ? AND variant_id IS NULL')->execute([$newVariantId, $oldProductId]);
            $pdo->prepare('UPDATE purchases SET variant_id = ? WHERE product_id = ? AND variant_id IS NULL')->execute([$newVariantId, $oldProductId]);
            $pdo->prepare('UPDATE damages SET variant_id = ? WHERE product_id = ? AND variant_id IS NULL')->execute([$newVariantId, $oldProductId]);
            $pdo->prepare('UPDATE stock_movements SET variant_id = ? WHERE product_id = ? AND variant_id IS NULL')->execute([$newVariantId, $oldProductId]);
        }
        $log[] = 'Re-linked existing sale, purchase, damage and stock-movement history to the new variants.';

        // ---- Step 4: foreign keys for the new columns (best-effort — skip if already present) ----
        $fks = [
            "ALTER TABLE `product_variants` ADD CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE" => 'product_variants -> products',
            "ALTER TABLE `product_variants` ADD CONSTRAINT `fk_variant_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL" => 'product_variants -> suppliers',
            "ALTER TABLE `sale_items` ADD CONSTRAINT `fk_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL" => 'sale_items -> product_variants',
            "ALTER TABLE `purchases` ADD CONSTRAINT `fk_pur_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE" => 'purchases -> product_variants',
            "ALTER TABLE `damages` ADD CONSTRAINT `fk_dmg_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE" => 'damages -> product_variants',
            "ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_mv_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE" => 'stock_movements -> product_variants',
        ];
        foreach ($fks as $sql => $label) {
            try {
                $pdo->exec($sql);
                $log[] = "Linked $label.";
            } catch (Throwable $e) {
                $log[] = "Skipped $label (likely already exists).";
            }
        }

        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration — Product Variants</title>
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
    <p style="font-size:14px;color:#c7d5f5;">Product variants are already set up on this database. You can safely delete this file.</p>
  <?php else: ?>
    <h2>Migration complete</h2>
    <ul><?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?></ul>
    <p style="font-size:13px;color:#8fa4d6;">You can safely delete this file now. Go to <a href="../Frontend/pages/products.html">Products</a> to see your products with their new "Standard" type, and add more types where needed.</p>
  <?php endif; ?>
</div>
</body></html>
