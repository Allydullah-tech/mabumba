<?php
/**
 * MABUMBATECH — Cash Audit PDF
 * GET ?id=123 -> the saved "Daily Cash Closing & Sales Reconciliation"
 *      sheet as a downloadable PDF, in the same style as the other reports
 *      (standard rows, repeating table headers, natural page flow).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/pdf_helper.php';

$user = require_role(['administrator', 'worker']);
$pdo = get_db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) respond(false, null, 'Cash audit id is required.', 422);

$stmt = $pdo->prepare('SELECT * FROM cash_audits WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) respond(false, null, 'Cash audit sheet not found.', 404);

$denoms = json_decode($row['denominations'], true) ?: [];
$electronic = $row['electronic_sales'] ? (json_decode($row['electronic_sales'], true) ?: []) : [];

$depListStmt = $pdo->prepare('SELECT * FROM bank_deposits WHERE deposit_date = ? ORDER BY created_at ASC, id ASC');
$depListStmt->execute([$row['audit_date']]);
$depositRows = $depListStmt->fetchAll();
$bankDeposit = round((float)array_sum(array_column($depositRows, 'deposit_amount')), 2);
$lastDeposit = $depositRows ? end($depositRows) : null;
$remainingCash = ($lastDeposit && $lastDeposit['remaining_cash'] !== null)
    ? round((float)$lastDeposit['remaining_cash'], 2)
    : round((float)$row['total_cash_counted'] - $bankDeposit, 2);
$remarkParts = array_values(array_filter(array_column($depositRows, 'remark')));
$depositRemark = $remarkParts ? implode('; ', $remarkParts) : null;
if ($depositRemark !== null && strlen($depositRemark) > 105) $depositRemark = substr($depositRemark, 0, 102) . '...';

$carryStmt = $pdo->prepare('SELECT deposit_date FROM bank_deposits WHERE deposit_date < ? ORDER BY deposit_date DESC, id DESC LIMIT 1');
$carryStmt->execute([$row['audit_date']]);
$carryDate = $carryStmt->fetchColumn();

$changeNoteStmt = $pdo->prepare(
    "SELECT customer_name, change_left_customer_name, (change_left_amount - change_collected_amount) AS outstanding
     FROM sale_transactions WHERE sale_date = ? AND (change_left_amount - change_collected_amount) > 0.001"
);
$changeNoteStmt->execute([$row['audit_date']]);
$changeNotes = $changeNoteStmt->fetchAll();

$company = [
    'company_name' => get_setting($pdo, 'company_name', 'MABUMBATECH'),
    'company_tagline' => get_setting($pdo, 'company_tagline', 'Technology. Innovation. Solutions.'),
    'company_location' => get_setting($pdo, 'company_location', ''),
    'company_phone' => get_setting($pdo, 'company_phone', ''),
    'company_email' => get_setting($pdo, 'company_email', ''),
    'generated_by' => $user['full_name'],
];

$pdf = new SimplePdf();   // standard row sizing, same as the other reports
$money = fn($n) => number_format((float)$n, 0) . ' TZS';

$c = draw_branded_header($pdf, $company, 'Daily Cash Closing & Sales Reconciliation Sheet');
$navy = $c['navy']; $blue = $c['blue']; $paleBand = $c['paleBand']; $zebra = $c['zebra'];

// Plain section heading (no green side accent), like the clean list exports.
$sectionTitle = function (string $title) use ($pdf, $navy) {
    $pdf->ensureSpace(26);
    $pdf->setFont('F2', 12);
    $pdf->setTextColor($navy);
    $pdf->text($pdf->margin, $title);
    $pdf->resetTextColor();
    $pdf->moveDown(18);
};

draw_meta_strip($pdf, [
    ['Date', $row['audit_date'] . ($row['shift'] ? ' — ' . $row['shift'] . ' shift' : '')],
    ['Cashier', $row['cashier_name']],
    ['Prepared By', $row['prepared_by_name']],
], $c);
$pdf->moveDown(6);

$sectionTitle('A. Cash Count');
$denomRows = [];
foreach ($denoms as $note => $info) {
    $denomRows[] = [number_format((int)$note), (string)($info['qty'] ?? 0), $money($info['amount'] ?? 0), ''];
}
draw_table($pdf, ['Denomination (TZS)', 'Quantity', 'Amount (TZS)', 'Remarks'], [0.28, 0.2, 0.28, 0.24], $paleBand, $zebra, $denomRows);
$pdf->tableRow(['TOTAL CASH IN HAND', '', $money($row['total_cash_counted']), ''], [0.28, 0.2, 0.28, 0.24], true, $paleBand);
$pdf->moveDown(16);

$sectionTitle('B. Sales Reconciliation');
$partB = [];
$partB[] = ['Opening Cash (Physical Float)', $money($row['opening_float']), false];
$partB[] = ['Cash In Hand Sales', $money($row['cash_physical_sales']), false];
if ($electronic) {
    foreach ($electronic as $e) $partB[] = ['Electronic Sales — ' . $e['method'], $money($e['amount']), true];
} else {
    $partB[] = ['Electronic Sales (Mixx by Yas / M-Pesa / Bank)', $money(0), false];
}
$partB[] = ['Credit Sales', $money($row['credit_sales']), false];
$partB[] = ['Total Sales (Cash In Hand Sales + Electronic Sales + Credit Sales)', $money($row['total_sales']), 'total'];
$partB[] = ['Less: Credit Sales (owed by customers)', '-' . $money($row['credit_sales']), false];
$partB[] = ['Add: Cash Collections from Previous Credit Sales (Received on This Date)', $money($row['credit_collected_today']), false];
$partB[] = ['Cash Available (Opening Cash + Total Sales - Credit Sales + Cash Collected from Previous Credit Sales)', $money($row['cash_available']), 'total'];
$partB[] = ['Outstanding Customer Change (Owed to Customers)', $money($row['outstanding_customer_change']), false];
$partB[] = ['Expenses', $money($row['expenses_total']), false];
$partB[] = ['Expected Closing Cash ((Cash Available + Outstanding Customer Change) - Expenses)', $money($row['expected_cash']), 'total'];
$partB[] = ['Outstanding Customer Receivables (Unpaid Balances; Excluded from Expected Closing Cash)', $money($row['outstanding_receivables']), false];

$descHead = fn() => $pdf->tableRow(['Description', 'Amount'], [0.72, 0.28], true, null, null, ['l', 'c'], true);
$pdf->ensureSpace(90);
$descHead();
foreach ($partB as $i => [$label, $amt, $kind]) {
    $bold = $kind === 'total';
    $h = $pdf->rowHeight([$label, $amt], [0.72, 0.28], $bold);
    if ($pdf->y() - $h - 4 < $pdf->margin + 20) {   // new page: repeat the header row
        $pdf->addPage();
        $descHead();
    }
    $pdf->tableRow([$label, $amt], [0.72, 0.28], $bold, $bold ? $paleBand : ($i % 2 === 1 ? $zebra : null));
}
$pdf->moveDown(16);

if ($changeNotes || (float)$row['outstanding_receivables'] > 0) {
    $sectionTitle('Notes on Expected Closing Cash');
    $pdf->setFont('F1', 9);
    if ($changeNotes) {
        $bits = array_map(fn($n) => ($n['change_left_customer_name'] ?: ($n['customer_name'] ?: 'A customer')) . ' left TZS ' . number_format((float)$n['outstanding']), $changeNotes);
        $pdf->text($pdf->margin, 'Customer change left: ' . implode(', ', $bits) . ' as change; this cash is added to Expected Closing Cash.');
        $pdf->moveDown(14);
    }
    if ((float)$row['outstanding_receivables'] > 0) {
        $pdf->text($pdf->margin, 'Outstanding receivables: ' . $money($row['outstanding_receivables']) . ' is still owed by customers as of this date. This amount is excluded from Expected Closing Cash until collected in cash.');
        $pdf->moveDown(14);
    }
    $pdf->moveDown(10);
}

if (!empty($row['remarks'])) {
    $sectionTitle('Remarks');
    $pdf->setFont('F1', 9.5);
    $pdf->text($pdf->margin, $row['remarks']);
    $pdf->moveDown(20);
}

$pdf->ensureSpace(150);   // keep the Bank Deposit summary together; starts a new page only if it would not fit

$sectionTitle('Bank Deposit & Closing Cash');
$pdf->tableRow(['Description', 'Amount'], [0.72, 0.28], true, null, null, ['l', 'c'], true);
$pdf->tableRow(['Total Cash (cash counted in Part A)', $money($row['total_cash_counted'])], [0.72, 0.28], false, $zebra);
$pdf->tableRow(['Bank Deposit', $money($bankDeposit)], [0.72, 0.28], false, null);
$pdf->tableRow(['Remaining Cash (Total Cash - Bank Deposit)', $money($remainingCash)], [0.72, 0.28], true, $paleBand);
$pdf->moveDown(14);
if ($depositRows) {
    $pdf->ensureSpace(60);
    $pdf->setFont('F2', 9.5);
    $pdf->text($pdf->margin, 'Deposit Breakdown');
    $pdf->moveDown(14);
    $breakdown = [];
    foreach ($depositRows as $d) {
        $remark = (string)($d['remark'] ?? '');
        if (strlen($remark) > 30) $remark = substr($remark, 0, 27) . '...';
        $breakdown[] = [
            date('H:i', strtotime($d['created_at'])),
            (string)$d['created_by_name'],
            $money($d['deposit_amount']),
            $money($d['remaining_cash']),
            $remark,
        ];
    }
    draw_table($pdf, ['Time', 'Saved By', 'Deposited', 'Remaining After', 'Remark'], [0.1, 0.22, 0.2, 0.22, 0.26], $paleBand, $zebra, $breakdown);
    $pdf->moveDown(14);
}
if ($depositRemark) {
    $pdf->setFont('F1', 9.5);
    $pdf->text($pdf->margin, 'Bank Deposit Remark: ' . $depositRemark);
    $pdf->moveDown(18);
}
$pdf->setFont('F1', 9.5);
$carryNote = $carryDate ? '' : ' (no earlier bank deposit on record yet — this will need to be set by hand)';
$pdf->text($pdf->margin, "Tomorrow's Opening Cash: " . $money($remainingCash) . ' (the closing cash balance is carried forward automatically' . $carryNote . ', and can still be adjusted from Opening Cash.)');
$pdf->moveDown(36);

draw_signature_block_simple($pdf, $navy, $row['prepared_by_name'], $row['approved_by_name']);

$filename = str_replace(' ', '_', $company['company_name']) . '_CashAudit_' . $row['audit_date'] . '.pdf';
$pdf->output($filename);
