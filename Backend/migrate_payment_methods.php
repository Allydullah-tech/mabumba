<?php
/**
 * MABUMBATECH — One-time migration: updated Payment Methods
 *
 * Your database was installed before "Cash in Hand" / mobile-money
 * providers existed as separate options. Run this ONCE in your browser:
 *
 *   http://localhost/mabumbatech-business/Backend/migrate_payment_methods.php
 *
 * It safely re-labels your existing "Cash" and "Bank"/"Card" rows and adds
 * the new mobile-money options, without touching any sales you've already
 * recorded (old payment_method_id references keep working exactly as
 * before). "Mobile Money" and "Other" are disabled rather than deleted, so
 * historical sales that used them are unaffected — you can re-enable them
 * later from Settings > Payment Methods if you want them back.
 *
 * You can delete this file after running it once.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/functions.php';

$user = require_role(['administrator']);
$pdo = get_db();

$results = [];

// Rename the existing system "Cash" row to "Cash in Hand" (same slug,
// same id — every past cash sale keeps pointing at the right row).
$pdo->prepare("UPDATE payment_methods SET name = 'Cash in Hand' WHERE slug = 'cash'")->execute();
$results[] = 'Renamed "Cash" to "Cash in Hand"';

$pdo->prepare("UPDATE payment_methods SET name = 'Bank (NMB/CRDB)' WHERE slug = 'bank'")->execute();
$results[] = 'Renamed "Bank Transfer" to "Bank (NMB/CRDB)"';

$pdo->prepare("UPDATE payment_methods SET name = 'Card Payment' WHERE slug = 'card'")->execute();
$results[] = 'Renamed "Card" to "Card Payment"';

$pdo->prepare("UPDATE payment_methods SET status = 'disabled' WHERE slug IN ('mobile_money', 'other')")->execute();
$results[] = 'Disabled the old generic "Mobile Money" and "Other" options (re-enable anytime from Settings)';

$newMethods = [
    ['mpesa', 'M-Pesa', 2],
    ['halopesa', 'HaloPesa', 3],
    ['mixx_by_yas', 'Mixx by Yas', 4],
    ['airtel_money', 'Airtel Money', 5],
];
$ins = $pdo->prepare('INSERT INTO payment_methods (slug, name, is_system, status, sort_order) VALUES (?, ?, 0, "active", ?)
                       ON DUPLICATE KEY UPDATE name = VALUES(name), status = "active"');
foreach ($newMethods as [$slug, $name, $sort]) {
    $ins->execute([$slug, $name, $sort]);
    $results[] = "Added/updated \"$name\"";
}

$pdo->prepare("UPDATE payment_methods SET sort_order = 1 WHERE slug = 'cash'")->execute();
$pdo->prepare("UPDATE payment_methods SET sort_order = 6 WHERE slug = 'bank'")->execute();
$pdo->prepare("UPDATE payment_methods SET sort_order = 7 WHERE slug = 'card'")->execute();

?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Migration Complete</title>
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;background:#060a14;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px;}
  .box{background:#0b1830;padding:36px 40px;border-radius:14px;max-width:480px;}
  h2{color:#4c86ff;margin-top:0;}
  li{margin-bottom:8px; font-size:14px; color:#c7d5f5;}
  a{color:#4c86ff;}
</style></head>
<body>
<div class="box">
  <h2>Payment methods updated</h2>
  <ul><?php foreach ($results as $r) echo '<li>' . htmlspecialchars($r) . '</li>'; ?></ul>
  <p style="font-size:13px;color:#8fa4d6;">You can safely delete this file now. Go to <a href="../Frontend/pages/settings.html">Settings</a> to review your payment methods.</p>
</div>
</body></html>
