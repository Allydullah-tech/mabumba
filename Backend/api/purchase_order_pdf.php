<?php
/**
 * MABUMBATECH — Purchase Order PDF
 *
 * GET ?items=12:5,14:6   (product-type id : quantity to buy)
 *   -> a printable "Purchase Order / Supplier Purchase" sheet listing only
 *      the items and quantities the person chose, ready to take to the
 *      supplier. Unit Price / Total / Supplier details are left blank to be
 *      filled in by hand at the shop, so no buying prices are ever printed
 *      (safe for workers).
 *
 * Item names, units and current stock are read from the database — the
 * browser only supplies which types and how many.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/pdf_helper.php';

$user = require_role(['administrator', 'worker']);
$pdo = get_db();

// ---- Parse "id:qty,id:qty" ----
$wanted = [];   // variant id => quantity
foreach (explode(',', (string)($_GET['items'] ?? '')) as $pair) {
    $parts = explode(':', trim($pair));
    if (count($parts) !== 2) continue;
    $id = (int)$parts[0];
    $qty = (int)$parts[1];
    if ($id > 0 && $qty > 0) $wanted[$id] = min($qty, 1000000);
}
if (!$wanted) respond(false, null, 'Enter a quantity to buy for at least one item.', 422);
if (count($wanted) > 300) respond(false, null, 'Too many items in one purchase order (max 300).', 422);

$in = implode(',', array_fill(0, count($wanted), '?'));
$stmt = $pdo->prepare(
    "SELECT v.id, p.name AS product_name, v.variant_name, v.unit, v.stock_quantity
     FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE v.id IN ($in)
     ORDER BY p.name ASC, v.variant_name ASC"
);
$stmt->execute(array_keys($wanted));
$items = $stmt->fetchAll();
if (!$items) respond(false, null, 'None of the selected items were found.', 404);

$company = [
    'company_name' => get_setting($pdo, 'company_name', 'MABUMBATECH'),
    'company_tagline' => get_setting($pdo, 'company_tagline', 'Technology. Innovation. Solutions.'),
    'company_location' => get_setting($pdo, 'company_location', ''),
    'company_phone' => get_setting($pdo, 'company_phone', ''),
    'company_email' => get_setting($pdo, 'company_email', ''),
    'generated_by' => $user['full_name'],
];

// ---- Small text helpers (the PDF engine has no text measuring) ----
// Only Latin-1-safe characters survive in the PDF, so swap the dash we use
// in "Pen — Marshal" for a plain hyphen before drawing.
$clean = fn(string $s) => trim(str_replace(["\u{2014}", "\u{2013}", "\u{2192}"], ['-', '-', '>'], $s));
$charW = fn(float $size) => $size * 0.5;   // average Helvetica glyph width, a touch generous
$wrap = function (string $text, float $width, float $size) use ($charW): array {
    $max = max(4, (int)floor($width / $charW($size)));
    $lines = []; $cur = '';
    foreach (preg_split('/\s+/', $text) as $word) {
        while (strlen($word) > $max) {                       // a single very long word
            if ($cur !== '') { $lines[] = $cur; $cur = ''; }
            $lines[] = substr($word, 0, $max);
            $word = substr($word, $max);
        }
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        if (strlen($try) > $max) { $lines[] = $cur; $cur = $word; } else { $cur = $try; }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines ?: [''];
};
$centerX = fn(float $colX, float $colW, string $s, float $size) => $colX + max(2, ($colW - strlen($s) * $size * 0.5) / 2);

// ---- Document ----
$pdf = new SimplePdf();
$c = draw_branded_header($pdf, $company, 'PURCHASE ORDER');

$totalUnits = array_sum($wanted);
$poNo = 'PO-' . date('Ymd-Hi');
draw_meta_strip($pdf, [
    ['PO No.', $poNo],
    ['Date', date('d M Y')],
    ['Prepared By', $user['full_name']],
    ['Items / Units', count($items) . ' / ' . number_format($totalUnits)],
], $c);
$pdf->moveDown(6);

// Supplier details to be filled in by hand
$W = $pdf->contentWidth();
$x0 = $pdf->margin;
$half = $W / 2;
$field = function (float $x, float $y, string $label, float $labelW, float $lineW) use ($pdf, $c): void {
    $pdf->setFont('F2', 9);
    $pdf->setTextColor($c['navy']);
    $pdf->textAt($x, $y, $label);
    $pdf->resetTextColor();
    $pdf->line($x + $labelW, $y - 2, $x + $labelW + $lineW, $y - 2, 0.5);
};
$y = $pdf->y();
$field($x0, $y, 'Supplier Name:', 76, $half - 76 - 14);
$field($x0 + $half + 6, $y, 'Supplier Contact:', 84, $half - 84 - 6);
$field($x0, $y - 22, 'Supplier Location:', 84, $half - 84 - 14);
$field($x0 + $half + 6, $y - 22, 'Purchase Ref No.:', 82, $half - 82 - 6);
$pdf->setY($y - 40);

// ---- Items table ----
$cols = [
    ['SN',          0.06, 'c'],
    ['ITEM DESCRIPTION', 0.30, 'l'],
    ['UNIT',        0.07, 'c'],
    ['IN STOCK',    0.09, 'c'],
    ['QTY TO BUY',  0.10, 'c'],
    ['UNIT PRICE',  0.13, 'c'],
    ['TOTAL',       0.13, 'c'],
    ['REMARKS',     0.12, 'l'],
];
$colX = []; $colW = []; $acc = $x0;
foreach ($cols as $i => $col) { $colX[$i] = $acc; $colW[$i] = $W * $col[1]; $acc += $colW[$i]; }
$grid = [212, 228, 218];
$qtyBand = [234, 251, 240];

$drawHeader = function () use ($pdf, $c, $cols, $colX, $colW, $W, $x0, $centerX): void {
    $h = 24;
    $top = $pdf->y();
    $pdf->rectFill($x0, $top - $h, $W, $h, $c['navy']);
    $pdf->rectFill($x0, $top - $h - 2, $W, 2, $c['blue']);
    $pdf->setFont('F2', 8);
    $pdf->setTextColor([255, 255, 255]);
    foreach ($cols as $i => $col) {
        $tx = $col[2] === 'c' ? $centerX($colX[$i], $colW[$i], $col[0], 8) : $colX[$i] + 8;
        $pdf->textAt($tx, $top - $h / 2 - 3, $col[0]);
    }
    $pdf->resetTextColor();
    $pdf->setY($top - $h - 2);
};

$pdf->ensureSpace(80);
$drawHeader();

$sn = 0;
foreach ($items as $it) {
    $sn++;
    $qty = $wanted[(int)$it['id']];
    $stock = (int)$it['stock_quantity'];
    $name = $clean($it['product_name'] . ' - ' . $it['variant_name']);
    $nameLines = $wrap($name, $colW[1] - 14, 9);
    $remark = $stock <= 0 ? 'Out of stock' : 'Only ' . $stock . ' left';
    $remLines = $wrap($remark, $colW[7] - 12, 8);
    $lines = max(count($nameLines), count($remLines));
    $rowH = max(26, 12 + $lines * 11);

    if ($pdf->y() - $rowH < $pdf->margin + 34) {   // new page: repeat the header row
        $pdf->addPage();
        $drawHeader();
    }

    $top = $pdf->y();
    $bottom = $top - $rowH;
    if ($sn % 2 === 0) $pdf->rectFill($x0, $bottom, $W, $rowH, $c['zebra']);
    $pdf->rectFill($colX[4], $bottom, $colW[4], $rowH, $qtyBand);   // highlight the quantity column

    // grid lines
    $pdf->rectFill($x0, $bottom, $W, 0.5, $grid);
    $pdf->rectFill($x0, $bottom, 0.5, $rowH, $grid);
    foreach ($colX as $i => $cx) { $pdf->rectFill($cx + $colW[$i] - 0.25, $bottom, 0.5, $rowH, $grid); }

    $mid = $bottom + $rowH / 2 - 3;
    $pdf->setFont('F1', 9);
    $pdf->setTextColor($c['slateMuted']);
    $pdf->textAt($centerX($colX[0], $colW[0], (string)$sn, 9), $mid, (string)$sn);

    $pdf->setFont('F2', 9);
    $pdf->setTextColor($c['navy']);
    $ty = $top - ($rowH - count($nameLines) * 11) / 2 - 8;
    foreach ($nameLines as $ln) { $pdf->textAt($colX[1] + 8, $ty, $ln); $ty -= 11; }

    $unit = $clean((string)($it['unit'] ?? 'pcs'));
    $pdf->setFont('F1', 9);
    $pdf->setTextColor([60, 75, 66]);
    $pdf->textAt($centerX($colX[2], $colW[2], $unit, 9), $mid, $unit);
    $pdf->setTextColor($stock <= 0 ? $c['danger'] : [60, 75, 66]);
    $pdf->textAt($centerX($colX[3], $colW[3], (string)$stock, 9), $mid, (string)$stock);

    $pdf->setFont('F2', 11);
    $pdf->setTextColor($c['navy']);
    $pdf->textAt($centerX($colX[4], $colW[4], (string)$qty, 11), $mid - 1, (string)$qty);

    $pdf->setFont('F1', 8);
    $pdf->setTextColor($stock <= 0 ? $c['danger'] : $c['slateMuted']);
    $ry = $top - ($rowH - count($remLines) * 10) / 2 - 7;
    foreach ($remLines as $ln) { $pdf->textAt($colX[7] + 6, $ry, $ln); $ry -= 10; }

    $pdf->resetTextColor();
    $pdf->setY($bottom);
}

// Total units row
$pdf->ensureSpace(40);
$top = $pdf->y();
$pdf->rectFill($x0, $top - 22, $W, 22, $c['paleBand']);
$pdf->rectFill($x0, $top - 22, $W, 0.8, $c['blue']);
$pdf->setFont('F2', 9.5);
$pdf->setTextColor($c['navy']);
$pdf->textAt($colX[1] + 8, $top - 15, 'TOTAL UNITS TO BUY');
$pdf->textAt($centerX($colX[4], $colW[4], (string)$totalUnits, 11), $top - 15, (string)$totalUnits);
$pdf->resetTextColor();
$pdf->setY($top - 22);

// ---- Totals to fill in by hand ----
$pdf->moveDown(26);
$pdf->ensureSpace(150);
$boxW = 250;
$bx = $x0 + $W - $boxW;
$by = $pdf->y();
$labels = ['Subtotal:', 'Transport Cost:', 'Other Expenses:', 'Grand Total:'];
foreach ($labels as $i => $label) {
    $ly = $by - $i * 22;
    $last = $i === count($labels) - 1;
    if ($last) $pdf->rectFill($bx - 8, $ly - 7, $boxW + 8, 20, $c['paleBand']);
    $pdf->setFont('F2', $last ? 10 : 9);
    $pdf->setTextColor($c['navy']);
    $pdf->textAt($bx, $ly, $label);
    $pdf->resetTextColor();
    $pdf->line($bx + 98, $ly - 2, $bx + $boxW, $ly - 2, 0.5);
}
// Note on the left of the totals
$pdf->setFont('F2', 8.5);
$pdf->setTextColor($c['navy']);
$pdf->textAt($x0, $by, 'NOTES');
$pdf->resetTextColor();
foreach ([0, 1, 2] as $k) { $pdf->line($x0, $by - 16 - $k * 20, $x0 + $W - $boxW - 24, $by - 16 - $k * 20, 0.4); }
$pdf->setY($by - 4 * 22);

// ---- Signatures ----
draw_signature_pair($pdf, $c['navy'], 'PREPARED BY', $user['full_name'], 'SUPPLIER SIGNATURE', '');

$pdf->output(str_replace(' ', '_', $company['company_name']) . '_Purchase_Order_' . date('Y-m-d') . '.pdf');
