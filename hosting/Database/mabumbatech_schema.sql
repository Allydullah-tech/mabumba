-- =========================================================
-- MABUMBATECH — Business Management System
-- Database Schema (v2 — product variants)
-- =========================================================
-- Design notes:
--  - This schema is derived from a working reference system
--    (transaction + line-item sales, credit/debt tracking,
--    stock-linked damages, category-based expenses, audit log)
--    but is restructured for a multi-purpose technology /
--    digital-services / retail business rather than a single
--    stationery shop.
--  - PRODUCTS are split into a PARENT (`products`, e.g. "Pen")
--    and its TYPES/VARIANTS (`product_variants`, e.g. "Pen —
--    Marshal", "Pen — Obama"). All pricing, stock, SKU/barcode
--    and supplier data lives on the variant, not the parent —
--    the parent is just the catalog grouping. Every other table
--    that references a specific stock item (sale_items,
--    purchases, damages, stock_movements) points at
--    `variant_id`. A product with only one type still needs
--    exactly one variant row (conventionally named "Standard").
--  - SERVICES are a separate catalog. A sale item can point at a
--    product variant, a catalog service, OR be a fully
--    custom/negotiated service typed in at the point of sale
--    (item_type = 'custom') — see sale_items below.
--  - Money columns use DECIMAL, never FLOAT, to avoid rounding
--    drift in financial totals. Quantities are whole numbers
--    (INT UNSIGNED) since stock here is counted in whole units.
--  - If you already have a v1 database installed, run
--    Backend/migrate_product_variants.php once instead of
--    re-running this file — it migrates your existing products
--    into this structure without losing any data.
-- =========================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Table: roles
-- Fixed set of system roles. Kept as a table (not a plain
-- ENUM) so permissions can be extended later without a schema
-- migration on the users table itself.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(30) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (`id`, `slug`, `name`, `description`) VALUES
  (1, 'administrator', 'Administrator', 'Full access to every module, including users and settings.'),
  (2, 'worker', 'Worker', 'Records sales, expenses and damages, views stock, products and services, generates reports. Cannot add, edit or remove products/services/stock, and has no access to customers, suppliers, purchases, debts, users or settings.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------
-- Table: users
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `security_question` VARCHAR(255) NOT NULL,
  `security_answer_hash` VARCHAR(255) NOT NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`),
  KEY `fk_user_role` (`role_id`),
  CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: categories
-- Shared by products and services; `type` keeps the two
-- catalogs from mixing in dropdowns while living in one table.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('product','service') NOT NULL DEFAULT 'product',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cat_name_type` (`name`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: suppliers
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) NULL,
  `email` VARCHAR(120) NULL,
  `address` VARCHAR(255) NULL,
  `notes` VARCHAR(255) NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: products
-- The PARENT product only (e.g. "Pen"). All pricing, stock,
-- SKU/barcode and supplier data lives one level down, on
-- product_variants — a parent can have one variant ("Standard")
-- or many ("Marshal", "Obama", "Maker", ...). This lets a
-- business count "Pen" as a single product in the catalog while
-- still tracking each type's stock and price independently.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `image_path` VARCHAR(255) NULL,
  `description` VARCHAR(500) NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_prod_category` (`category_id`),
  KEY `idx_prod_status` (`status`),
  CONSTRAINT `fk_prod_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: product_variants
-- The actual sellable/stock-tracked item (e.g. "Pen — Marshal").
-- Every product has at least one variant. All the fields that
-- used to live directly on `products` (price, stock, SKU, unit,
-- supplier) live here instead, per-type. Every other table that
-- references a specific stock item (sale_items, purchases,
-- damages, stock_movements) points at `variant_id`, not a
-- product id directly.
-- ---------------------------------------------------------
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
  UNIQUE KEY `uniq_variant_sku` (`sku`),
  UNIQUE KEY `uniq_variant_barcode` (`barcode`),
  UNIQUE KEY `uniq_variant_name_per_product` (`product_id`, `variant_name`),
  KEY `fk_variant_product` (`product_id`),
  KEY `fk_variant_supplier` (`supplier_id`),
  KEY `idx_variant_status` (`status`),
  CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_variant_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: services
-- Catalog of standard, pre-priced services (e.g. Photocopy,
-- Printing, Laminating). No stock fields — services are never
-- out of stock. A sale can also use a fully custom, one-off
-- service instead of a catalog row (see sale_items.item_type).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `unit` VARCHAR(30) NOT NULL DEFAULT 'job',
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `minimum_price` DECIMAL(12,2) NULL DEFAULT NULL,
  `description` VARCHAR(500) NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_service_category` (`category_id`),
  KEY `idx_service_status` (`status`),
  CONSTRAINT `fk_service_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: service_variants
-- Optional TYPES for a catalog service — e.g. service "Printing"
-- might have variants "Color" and "Black & White", each with its
-- own price. A service with no rows here is simply sold at its
-- own base price/unit (see `services` above) — types are opt-in,
-- unlike product variants which are mandatory. A sale item still
-- points at the parent `service_id` only; the chosen type's name
-- and price are folded into the line item's snapshot description
-- and unit_price at sale time (same convention used for product
-- variant display names).
-- ---------------------------------------------------------
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
  KEY `idx_svcvariant_status` (`status`),
  CONSTRAINT `fk_svcvariant_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: customers
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NULL,
  `phone` VARCHAR(30) NULL,
  `email` VARCHAR(120) NULL,
  `address` VARCHAR(255) NULL,
  `customer_type` VARCHAR(60) NULL DEFAULT 'Individual',
  `status` ENUM('active','restricted') NOT NULL DEFAULT 'active',
  `restricted_at` DATETIME NULL DEFAULT NULL,
  `restricted_reason` VARCHAR(255) NULL DEFAULT NULL,
  `restriction_overridden_by` INT UNSIGNED NULL DEFAULT NULL,
  `first_purchase_date` DATE NULL DEFAULT NULL,
  `notes` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_phone` (`phone`),
  KEY `idx_customer_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: payment_methods
-- Configurable (Settings > Payment Methods). `is_system = 1`
-- rows (Cash, Credit) cannot be deleted since core logic
-- depends on their slugs existing.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(40) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pm_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `payment_methods` (`slug`, `name`, `is_system`, `sort_order`) VALUES
  ('cash', 'Cash in Hand', 1, 1),
  ('mpesa', 'M-Pesa', 0, 2),
  ('halopesa', 'HaloPesa', 0, 3),
  ('mixx_by_yas', 'Mixx by Yas', 0, 4),
  ('airtel_money', 'Airtel Money', 0, 5),
  ('bank', 'Bank (NMB/CRDB)', 0, 6),
  ('card', 'Card Payment', 0, 7)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------
-- Table: expense_categories
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expcat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `expense_categories` (`name`) VALUES
  ('Rent'), ('Electricity'), ('Internet'), ('Transport'),
  ('Salaries'), ('Supplies'), ('Maintenance'), ('Marketing'),
  ('Equipment'), ('Other')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------
-- Table: sale_transactions
-- One row per checkout/receipt. `invoice_no` is a sequential,
-- human-facing number shown on receipts (separate from the
-- internal auto-increment id).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sale_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(30) NOT NULL,
  `customer_id` INT UNSIGNED NULL DEFAULT NULL,
  `customer_name` VARCHAR(150) NULL DEFAULT NULL,
  `customer_phone` VARCHAR(30) NULL DEFAULT NULL,
  `payment_type` ENUM('cash','credit') NOT NULL DEFAULT 'cash',
  `payment_method_id` INT UNSIGNED NULL DEFAULT NULL,
  `credit_deadline` DATE NULL DEFAULT NULL,
  `subtotal_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('completed','void') NOT NULL DEFAULT 'completed',
  `note` VARCHAR(255) NULL DEFAULT NULL,
  `change_left_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'cash the customer paid but did not take back as change',
  `change_left_customer_name` VARCHAR(150) NULL DEFAULT NULL,
  `change_collected_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'how much of the left change has since been handed back',
  `sold_by` INT UNSIGNED NOT NULL,
  `sale_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_no` (`invoice_no`),
  KEY `fk_txn_customer` (`customer_id`),
  KEY `fk_txn_user` (`sold_by`),
  KEY `fk_txn_pm` (`payment_method_id`),
  KEY `idx_txn_date` (`sale_date`),
  CONSTRAINT `fk_txn_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_user` FOREIGN KEY (`sold_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_txn_pm` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: sale_items
-- item_type = 'product' -> variant_id set (a specific product
--                           TYPE, e.g. "Pen — Marshal"), stock
--                           is deducted from that variant only.
-- item_type = 'service'  -> service_id set, catalog price used
--                           as the default (editable) price.
-- item_type = 'custom'   -> neither id set; `description` is
--                           typed by the seller and `unit_price`
--                           is whatever was agreed with the
--                           customer on the spot (no stock
--                           impact, no minimum-price check).
-- `description` is always stored as a snapshot of the item's
-- full name ("Parent — Variant") at sale time, so historical
-- receipts stay correct even if a product/variant is renamed.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` INT UNSIGNED NOT NULL,
  `item_type` ENUM('product','service','custom') NOT NULL DEFAULT 'product',
  `variant_id` INT UNSIGNED NULL,
  `service_id` INT UNSIGNED NULL,
  `description` VARCHAR(200) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(14,2) NOT NULL,
  `buying_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(14,2) NOT NULL,
  `profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `below_minimum` TINYINT(1) NOT NULL DEFAULT 0,
  `override_reason` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_item_txn` (`transaction_id`),
  KEY `fk_item_variant` (`variant_id`),
  KEY `fk_item_service` (`service_id`),
  CONSTRAINT `fk_item_txn` FOREIGN KEY (`transaction_id`) REFERENCES `sale_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_item_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: sale_item_materials
-- When a service (catalog or custom) is sold, the seller can say
-- which product(s) were used to provide it. This stores, per sold
-- line, the product type, the quantity used and its cost price at
-- sale time. It deducts stock and lowers PROFIT only — the price
-- charged, the sale total and the cash expected never change.
-- Editing or deleting the sale restores exactly this stock.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sale_item_materials` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_item_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_simat_item` (`sale_item_id`),
  KEY `fk_simat_variant` (`variant_id`),
  CONSTRAINT `fk_simat_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_simat_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: debt_payments
-- Repayments against a credit sale. Remaining balance =
-- sale_transactions.total_amount - SUM(debt_payments.amount).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `debt_payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `payment_method_id` INT UNSIGNED NULL,
  `payment_date` DATE NOT NULL,
  `note` VARCHAR(255) NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_debt_sale` (`sale_id`),
  KEY `fk_debt_user` (`recorded_by`),
  KEY `fk_debt_pm` (`payment_method_id`),
  CONSTRAINT `fk_debt_sale` FOREIGN KEY (`sale_id`) REFERENCES `sale_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_debt_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_debt_pm` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: expenses
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `payment_method_id` INT UNSIGNED NULL,
  `vendor` VARCHAR(150) NULL,
  `reference_no` VARCHAR(60) NULL,
  `note` VARCHAR(255) NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `expense_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_exp_user` (`recorded_by`),
  KEY `fk_exp_cat` (`category_id`),
  KEY `idx_exp_date` (`expense_date`),
  CONSTRAINT `fk_exp_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_exp_cat` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: damages
-- item_type='product' uses variant_id + quantity, loss is
-- calculated from that variant's buying price. item_type='other'
-- covers non-stock items (equipment, furniture) with a manual
-- cost, since there's no stock record to derive a loss from.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `damages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` INT UNSIGNED NULL,
  `item_type` ENUM('product','other') NOT NULL DEFAULT 'product',
  `item_name` VARCHAR(150) NULL DEFAULT NULL,
  `quantity` INT UNSIGNED NULL DEFAULT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `loss_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `manual_cost` DECIMAL(14,2) NULL DEFAULT NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `damage_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_dmg_variant` (`variant_id`),
  KEY `fk_dmg_user` (`recorded_by`),
  KEY `idx_dmg_date` (`damage_date`),
  CONSTRAINT `fk_dmg_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dmg_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: purchases
-- Stock received from a supplier, against a specific variant.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` INT UNSIGNED NOT NULL,
  `supplier_id` INT UNSIGNED NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `buying_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `source` ENUM('manual','import') NOT NULL DEFAULT 'manual',
  `note` VARCHAR(255) NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `purchase_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pur_variant` (`variant_id`),
  KEY `fk_pur_supplier` (`supplier_id`),
  KEY `fk_pur_user` (`recorded_by`),
  KEY `idx_pur_date` (`purchase_date`),
  CONSTRAINT `fk_pur_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pur_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: stock_movements
-- Unified, append-only ledger of every stock change, per
-- variant, so the Stock Movements page doesn't have to union
-- purchases + sales + damages + manual adjustments on the fly.
-- Written alongside (not instead of) the source tables above.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `variant_id` INT UNSIGNED NOT NULL,
  `movement_type` ENUM('purchase','sale','damage','adjustment') NOT NULL,
  `quantity_change` INT NOT NULL COMMENT 'Positive = stock in, negative = stock out',
  `reference_type` VARCHAR(30) NULL COMMENT 'e.g. purchases, sale_items, damages',
  `reference_id` INT UNSIGNED NULL,
  `note` VARCHAR(255) NULL,
  `recorded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_mv_variant` (`variant_id`),
  KEY `fk_mv_user` (`recorded_by`),
  KEY `idx_mv_created` (`created_at`),
  CONSTRAINT `fk_mv_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mv_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: settings
-- Key/value store for company profile + system preferences.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(60) NOT NULL,
  `setting_value` VARCHAR(500) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('company_name', 'MABUMBATECH'),
  ('company_tagline', 'Technology. Innovation. Solutions.'),
  ('company_location', 'Mbeya Iyunga, Ikuti'),
  ('company_phone', '+255 620 839 640 / +255 760 620 418'),
  ('company_email', 'mabumbatech@gmail.com'),
  ('currency', 'TZS'),
  ('invoice_prefix', 'MBT'),
  ('tax_enabled', '0'),
  ('tax_rate', '0'),
  ('default_reorder_level', '5'),
  ('installed', '0')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ---------------------------------------------------------
-- Table: audit_log
-- user_id/user_name/user_role are stored as plain values (no
-- FK to users) so the trail survives if that account is later
-- deleted.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `user_name` VARCHAR(150) NOT NULL,
  `user_role` VARCHAR(30) NOT NULL,
  `action` VARCHAR(20) NOT NULL,
  `entity_type` VARCHAR(30) NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `description` VARCHAR(500) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
-- Table: cash_float_entries
-- The office's cash float: opening cash for the day (normally
-- brought forward automatically from the previous day's Remaining
-- Cash — see bank_deposits below — but can be set by hand), plus
-- any top-up/withdrawal made during the day. Append-only — setting
-- a new opening amount for a date that already has one marks the
-- old row `replaced` (kept, struck through in history) rather than
-- deleting it; add/reduce rows are never edited, only deleted.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cash_float_entries` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: bank_deposits
-- How much of a day's counted cash was taken to the bank. Several
-- deposits can be logged for the same date (e.g. a morning and an
-- evening drop); each row snapshots the running totals at the
-- moment it was saved. The latest row for a date is what the next
-- date's opening float is automatically carried forward from.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bank_deposits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `deposit_date` DATE NOT NULL,
  `total_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Part A total counted, as told by the client at save time',
  `deposit_amount` DECIMAL(14,2) NOT NULL,
  `already_deposited_before` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remaining_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remark` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_by_name` VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deposit_date` (`deposit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Table: cash_audits
-- One "Daily Cash Closing & Sales Reconciliation" sheet per save.
-- Part A (the physical denomination count) is entered by hand;
-- Part B is a snapshot of that date's sales/expenses/float at the
-- moment the sheet was saved, so it stays accurate even if later
-- corrections are made to those records.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cash_audits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audit_date` DATE NOT NULL,
  `shift` VARCHAR(50) NULL,
  `cashier_name` VARCHAR(150) NOT NULL,
  `denominations` TEXT NOT NULL COMMENT 'JSON: {"10000":{"qty":2,"amount":20000}, ...}',
  `total_cash_counted` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opening_float` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `cash_physical_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `electronic_sales` TEXT NULL COMMENT 'JSON: {"Mixx by Yas":13000, ...}',
  `electronic_sales_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `credit_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `expenses_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `expected_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `variance` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'total_cash_counted - expected_cash',
  `credit_collected_today` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'cash received today against older credit sales',
  `cash_available` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Opening + Total Sales - Credit Sales + Credit Collected Today',
  `outstanding_customer_change` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'unclaimed change from today''s sales, still in the drawer',
  `outstanding_receivables` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'info only — all unpaid credit sales as of this date, excluded from Expected Cash',
  `remarks` VARCHAR(500) NULL,
  `prepared_by_name` VARCHAR(150) NOT NULL,
  `approved_by_name` VARCHAR(150) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_by_name` VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_date` (`audit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
