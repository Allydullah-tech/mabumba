MABUMBATECH — Corrections & Enhancements (patch)
=================================================

HOW TO APPLY
-------------
Copy every file in this ZIP into your existing project, keeping the same
folder paths (they already match your project's structure exactly — e.g.
mabumbatech_business/Backend/api/services.php overwrites your existing
Backend/api/services.php). Two files are brand new:
  - Backend/api/service_variants.php
  - Backend/migrate_service_variants.php

AFTER COPYING THE FILES, RUN THIS ONCE
----------------------------------------
Open in your browser (logged in as an administrator is not required to open
the URL, but the script itself requires an administrator session):
  http://<your-domain>/Backend/migrate_service_variants.php
This adds one new database table (service_variants) used by the new
"Service Types" feature. It only adds — nothing existing is touched. You
can delete this file afterwards.

WHAT CHANGED
-------------
1. Logos
   - Landing page header -> Logo 1 (Frontend/assets/logo-header.png)
   - Landing page footer -> Logo 2 (Frontend/assets/logo-footer.png)
   - Landing page hero, left side -> Logo 4 (Frontend/assets/logo-hero.png)
   - Login page + Dashboard sidebar -> Logo 3, wide and non-circular
     (Frontend/assets/logo-wide.png)

2. Service types/variants
   - Services can now optionally have priced "types", e.g. "Printing" ->
     "Color" / "Black & White", each with its own price. A service with no
     types just keeps selling at its own base price — nothing is required.
   - Manage types from Services > "Manage Types" on any service row.
   - On the Sales screen, a service with types shows each type as its own
     pickable option (e.g. "Printing — Color").
   - New table: service_variants (see migration above).
   - New API: Backend/api/service_variants.php.

3. Products — SKU/Barcode
   - Removed the SKU and Barcode fields from product recording (both when
     adding a new product and when adding/editing a type under "Manage
     Types"). The database columns still exist for any old records, but
     nothing in the UI asks for them anymore.

4. Purchases — Excel import
   - New "Import Excel" button on the Purchases page (visible to
     administrator/manager/stock_manager).
   - Upload an .xlsx/.xls/.csv file with columns: Product Name, Type
     (optional), Category (optional), Unit (optional), Quantity, Buying
     Price, Selling Price (required only for a brand-new product/type),
     Supplier (optional).
   - A "Download template" button in the same modal generates a ready-made
     example file.
   - Shows a preview with per-row validation before you confirm the import;
     uses your existing purchases.php batch-import logic under the hood
     (same all-or-nothing transaction as before).

5. Stock receiving — clearer flow
   - The "Receive Stock" modal on the Stock page now shows each item as its
     own clearly labeled card: "Restocking existing type" or "New product /
     type", instead of a cramped table row.
   - New items now also let you set Category, Unit, Minimum price and
     Reorder level at the point of receiving (previously these had no
     field and silently used defaults).
   - Backend/api/purchases.php was updated to accept and store the new
     optional minimum_price/reorder_level fields for brand-new types
     (defaults unchanged if you don't set them: no minimum price, reorder
     level 5).

FILES INCLUDED IN THIS ZIP
----------------------------
  Backend/api/purchases.php            (modified)
  Backend/api/services.php             (modified)
  Backend/api/service_variants.php     (new)
  Backend/migrate_service_variants.php (new)
  Database/mabumbatech_schema.sql      (modified — service_variants table added;
                                         only needed for fresh installs, existing
                                         installs should use the migration script)
  Frontend/assets/logo-header.png      (new)
  Frontend/assets/logo-footer.png      (new)
  Frontend/assets/logo-hero.png        (new)
  Frontend/assets/logo-wide.png        (new)
  Frontend/css/style.css               (modified)
  Frontend/index.html                  (modified)
  Frontend/login.html                  (modified)
  Frontend/js/shell.js                 (modified)
  Frontend/pages/products.html         (modified)
  Frontend/pages/purchases.html        (modified)
  Frontend/pages/sales.html            (modified)
  Frontend/pages/services.html         (modified)
  Frontend/pages/stock.html            (modified)
