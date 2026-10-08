<?php
/**
 * MABUMBATECH — Public "Today's Snapshot" widget for the landing page.
 * Intentionally returns ONLY high-level totals (no customer names, no
 * line items) so it is safe to show before login.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/report_helper.php';

$pdo = get_db();
$today = today();
$report = build_report($pdo, $today, $today);

respond(true, [
    'company_name' => get_setting($pdo, 'company_name', 'MABUMBATECH'),
    'date' => date('l, d F Y'),
    'total_sales' => (float)$report['sales']['total_sales'],
    'transactions' => (int)$report['sales']['transactions'],
]);
