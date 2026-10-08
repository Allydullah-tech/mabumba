<?php
/**
 * MABUMBATECH — Reports API
 * GET ?period=today|yesterday|week|month|year|custom &date=YYYY-MM-DD &end=YYYY-MM-DD &format=json|csv
 *
 * today/yesterday: fixed. week: date = the starting date the user
 * clicked, system auto-adds 6 more days. month/year: date = any date
 * within the target period. custom: date = start, end = end.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/report_helper.php';
require_once __DIR__ . '/../helpers/pdf_helper.php';
require_once __DIR__ . '/../helpers/xlsx_helper.php';

$user = require_role(['administrator', 'worker']);
$pdo = get_db();

$period = clean($_GET['period'] ?? 'today');
$date = !empty($_GET['date']) ? clean($_GET['date']) : today();
$endCustom = !empty($_GET['end']) ? clean($_GET['end']) : null;
$format = clean($_GET['format'] ?? 'json');

[$start, $end] = resolve_period($period, $date, $endCustom);
$report = build_report($pdo, $start, $end);
$report['period'] = $period;

// Workers get the report without profit or cost-revealing figures.
if (!can_see_costs($user)) {
    $report = hide_profit_from_report($report);
}

$companyInfo = [
    'company_name' => get_setting($pdo, 'company_name', 'MABUMBATECH'),
    'company_tagline' => get_setting($pdo, 'company_tagline', 'Technology. Innovation. Solutions.'),
    'company_location' => get_setting($pdo, 'company_location', ''),
    'company_phone' => get_setting($pdo, 'company_phone', ''),
    'company_email' => get_setting($pdo, 'company_email', ''),
    'generated_by' => $user['full_name'],
];

if ($format === 'csv') {
    stream_report_csv($report, $companyInfo['company_name']);
}

if ($format === 'pdf') {
    stream_report_pdf($report, $companyInfo);
}

if ($format === 'xlsx') {
    stream_report_xlsx($report, $companyInfo);
}

respond(true, $report);
