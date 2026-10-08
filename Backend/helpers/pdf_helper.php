<?php
/**
 * MABUMBATECH — Minimal PDF generator (no external library required)
 *
 * Writes a valid PDF by hand: standard PDF objects (Catalog, Pages, Page,
 * Font, content streams) plus a correct xref table with byte offsets
 * computed as the file is built. Uses the built-in Helvetica / Helvetica-Bold
 * base-14 fonts, so no font embedding is needed and the file stays tiny.
 *
 * This intentionally does NOT depend on Composer/FPDF/TCPDF — the goal is
 * a PDF export that works the moment this project is dropped into any
 * plain XAMPP install, with nothing else to install.
 *
 * Scope is deliberately narrow (text, lines, filled rectangles, simple
 * left-aligned table rows with automatic page breaks) — enough for
 * business reports and receipts, not a general layout engine.
 */

class SimplePdf
{
    private array $objects = [];      // index 0 => object #1 body, etc.
    private array $pageObjIds = [];
    private array $imageIds = [];     // XObject ids embedded so far, referenced from every page's Resources
    private int $catalogId;
    private int $pagesId;
    private int $fontRegularId;
    private int $fontBoldId;

    private float $pageW = 595.28;    // A4 portrait, in points
    private float $pageH = 841.89;
    public float $margin = 40;

    private string $stream = '';
    private float $y = 0;
    private string $font = 'F1';
    private float $size = 10;
    private array $textColor = [0, 0, 0];
    private string $footerText = '';

    /** Table look — a compact report (e.g. the cash audit sheet) can shrink these. */
    public float $minRowH = 22;
    public float $rowPad = 10;

    public function __construct()
    {
        $this->catalogId = $this->reserve();
        $this->pagesId = $this->reserve();
        $this->fontRegularId = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->fontBoldId = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $this->startPage();
    }

    private function reserve(): int
    {
        $this->objects[] = null;
        return count($this->objects);
    }

    private function addObject(string $body): int
    {
        $this->objects[] = $body;
        return count($this->objects);
    }

    /** Text printed centered at the bottom of every page (set once, applies to all pages). */
    public function setFooterText(string $text): void
    {
        $this->footerText = $text;
    }

    /** Embed a JPEG file as a reusable image resource. Returns an id to pass to drawImage(). */
    public function addImageJpeg(string $path): ?int
    {
        if (!is_file($path)) return null;
        $data = file_get_contents($path);
        $info = @getimagesize($path);
        if ($data === false || !$info) return null;

        [$width, $height] = $info;
        $channels = $info['channels'] ?? 3;
        $colorSpace = $channels === 1 ? '/DeviceGray' : ($channels === 4 ? '/DeviceCMYK' : '/DeviceRGB');
        $len = strlen($data);

        $id = $this->addObject("<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace {$colorSpace} /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\nstream\n{$data}\nendstream");
        $this->imageIds[] = $id;
        return $id;
    }

    /** Draw a previously-embedded image at (x, y) [bottom-left corner] sized w x h, in points. */
    public function drawImage(?int $imgId, float $x, float $y, float $w, float $h): void
    {
        if (!$imgId) return;
        $this->stream .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im%d Do Q\n", $w, $h, $x, $y, $imgId);
    }

    private function startPage(): void
    {
        $this->stream = '';
        $this->y = $this->pageH - $this->margin;
    }

    private function drawFooter(): void
    {
        $savedFont = $this->font; $savedSize = $this->size; $savedColor = $this->textColor;
        $pageNo = count($this->pageObjIds) + 1;
        $this->setFont('F1', 8);
        $this->setTextColor([130, 150, 138]);
        $this->line($this->margin, 34, $this->pageW - $this->margin, 34, 0.5);
        if ($this->footerText !== '') $this->textAt($this->margin, 22, $this->footerText);
        // "@@" is swapped for the real page count (same 2 characters) when the file is written.
        $this->textAt($this->pageW - $this->margin - 52, 22, "Page {$pageNo} of @@");
        $this->font = $savedFont; $this->size = $savedSize; $this->textColor = $savedColor;
    }

    /** Start a fresh page, closing off whatever was drawn on the current one. */
    public function addPage(): void
    {
        $this->endPage();
        $this->startPage();
    }

    private function endPage(): void
    {
        $this->drawFooter();
        $len = strlen($this->stream);
        $contentId = $this->addObject("<< /Length {$len} >>\nstream\n{$this->stream}\nendstream");

        $xobjEntries = implode(' ', array_map(fn($id) => "/Im{$id} {$id} 0 R", $this->imageIds));
        $resources = "/Font << /F1 {$this->fontRegularId} 0 R /F2 {$this->fontBoldId} 0 R >>" . ($xobjEntries ? " /XObject << {$xobjEntries} >>" : '');

        $pageId = $this->addObject(
            "<< /Type /Page /Parent {$this->pagesId} 0 R /MediaBox [0 0 {$this->pageW} {$this->pageH}] " .
            "/Resources << {$resources} >> /Contents {$contentId} 0 R >>"
        );
        $this->pageObjIds[] = $pageId;
    }

    /** If there isn't enough room left on the page, start a new one. */
    public function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < $this->margin + 20) {
            $this->addPage();
        }
    }

    public function y(): float { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }
    public function pageWidth(): float { return $this->pageW; }
    public function pageHeight(): float { return $this->pageH; }
    public function contentWidth(): float { return $this->pageW - 2 * $this->margin; }

    public function moveDown(float $amount): void { $this->y -= $amount; }

    /** Swap common non-Latin-1 characters for plain equivalents so they print instead of showing as '?'. */
    public function clean(string $s): string
    {
        return strtr($s, [
            "\xE2\x80\x94" => '-', "\xE2\x80\x93" => '-', "\xE2\x88\x92" => '-',
            "\xE2\x86\x92" => '>', "\xE2\x80\x99" => "'", "\xE2\x80\x98" => "'",
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"', "\xE2\x80\xA6" => '...',
            "\xC3\x97" => 'x', "\xC2\xB7" => '-', "\xC2\xA0" => ' ',
        ]);
    }

    private function esc(string $s): string
    {
        $s = $this->clean($s);
        // Keep to Latin-1/WinAnsi-safe characters; anything else becomes '?'
        // rather than corrupting the PDF's byte stream.
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        return preg_replace('/[^\x20-\x7E]/', '?', $s);
    }

    public function setFont(string $font, float $size): void
    {
        $this->font = $font; // 'F1' regular, 'F2' bold
        $this->size = $size;
    }

    /** Text color for subsequent text() / textAt() calls, as [r, g, b] 0-255. Resets to black with resetTextColor(). */
    public function setTextColor(array $rgb255): void
    {
        $this->textColor = $rgb255;
    }
    public function resetTextColor(): void { $this->textColor = [0, 0, 0]; }

    private function colorOp(): string
    {
        [$r, $g, $b] = $this->textColor;
        return sprintf('%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255);
    }

    /** Draw one line of text at (x, current y), using the current font/size/color. */
    public function text(float $x, string $s): void
    {
        $s = $this->esc($s);
        $this->stream .= sprintf("BT %s /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $this->colorOp(), $this->font, $this->size, $x, $this->y, $s);
    }

    /** Draw text at an explicit (x, y) without touching the shared cursor. */
    public function textAt(float $x, float $y, string $s): void
    {
        $s = $this->esc($s);
        $this->stream .= sprintf("BT %s /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $this->colorOp(), $this->font, $this->size, $x, $y, $s);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.6): void
    {
        $this->stream .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $width, $x1, $y1, $x2, $y2);
    }

    public function rectFill(float $x, float $y, float $w, float $h, array $rgb255): void
    {
        [$r, $g, $b] = $rgb255;
        $this->stream .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f 0 0 0 rg\n", $r / 255, $g / 255, $b / 255, $x, $y, $w, $h);
    }

    // ---- text measuring (Helvetica metrics, close enough to wrap table cells) ----
    private const W_LOWER = ['a'=>.556,'b'=>.556,'c'=>.5,'d'=>.556,'e'=>.556,'f'=>.278,'g'=>.556,'h'=>.556,'i'=>.222,'j'=>.222,'k'=>.5,'l'=>.222,'m'=>.833,'n'=>.556,'o'=>.556,'p'=>.556,'q'=>.556,'r'=>.333,'s'=>.5,'t'=>.278,'u'=>.556,'v'=>.5,'w'=>.722,'x'=>.5,'y'=>.5,'z'=>.5];
    private const W_UPPER = ['A'=>.667,'B'=>.667,'C'=>.722,'D'=>.722,'E'=>.667,'F'=>.611,'G'=>.778,'H'=>.722,'I'=>.278,'J'=>.5,'K'=>.667,'L'=>.556,'M'=>.833,'N'=>.722,'O'=>.778,'P'=>.667,'Q'=>.778,'R'=>.722,'S'=>.667,'T'=>.611,'U'=>.722,'V'=>.667,'W'=>.944,'X'=>.667,'Y'=>.667,'Z'=>.611];

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $s = $this->clean($s);
        $w = 0.0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if (isset(self::W_LOWER[$ch])) $w += self::W_LOWER[$ch];
            elseif (isset(self::W_UPPER[$ch])) $w += self::W_UPPER[$ch];
            elseif ($ch === ' ' || $ch === '.' || $ch === ',' || $ch === ':' || $ch === ';' || $ch === '!' || $ch === "'" || $ch === '|' || $ch === '/') $w += .278;
            elseif ($ch === '(' || $ch === ')' || $ch === '-' || $ch === '[' || $ch === ']') $w += .333;
            elseif ($ch === '%') $w += .889;
            elseif ($ch === '&') $w += .667;
            elseif ($ch === '@') $w += 1.015;
            else $w += .556;   // digits and everything else
        }
        return $w * $size * ($bold ? 1.06 : 1.0);
    }

    /** Break text into lines no wider than $maxW (max 4 lines; the rest is cut with "..."). */
    public function wrapText(string $text, float $maxW, float $size, bool $bold = false): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->clean($text)));
        if ($text === '') return [''];
        if ($this->textWidth($text, $size, $bold) <= $maxW) return [$text];
        $lines = []; $cur = '';
        foreach (explode(' ', $text) as $word) {
            while ($this->textWidth($word, $size, $bold) > $maxW && strlen($word) > 1) {   // a single very long word
                $cut = strlen($word);
                while ($cut > 1 && $this->textWidth(substr($word, 0, $cut), $size, $bold) > $maxW) $cut--;
                if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($cur !== '' && $this->textWidth($try, $size, $bold) > $maxW) { $lines[] = $cur; $cur = $word; }
            else { $cur = $try; }
        }
        if ($cur !== '') $lines[] = $cur;
        if (count($lines) > 4) { $lines = array_slice($lines, 0, 4); $lines[3] = rtrim(substr($lines[3], 0, max(1, strlen($lines[3]) - 3))) . '...'; }
        return $lines;
    }

    /** 'c' (centred) for numbers, money, dates and times; 'l' (left) for text. */
    public static function guessAlign(string $s): string
    {
        $t = trim($s);
        if ($t === '') return 'l';
        if (preg_match('/^[-+]?\d[\d,.\s]*(TZS|%)?$/i', $t)) return 'c';
        if (preg_match('/^\d{4}-\d{2}-\d{2}(\s+\d{1,2}:\d{2})?$/', $t) || preg_match('/^\d{1,2}:\d{2}$/', $t)) return 'c';
        return 'l';
    }

    private function cellLines(string $cell, float $colW, float $size, bool $bold): array
    {
        $inner = $colW - 12;
        if (self::guessAlign($cell) === 'c') return [trim($cell)];   // never split a number
        return $this->wrapText($cell, $inner, $size, $bold);
    }

    /** Height a row will take, so callers can paginate before drawing it. */
    public function rowHeight(array $cells, array $widths, bool $bold = false, bool $header = false): float
    {
        $size = $header ? 8 : 9;
        $maxLines = 1;
        foreach ($widths as $i => $w) {
            $maxLines = max($maxLines, count($this->cellLines((string)($cells[$i] ?? ''), $this->contentWidth() * $w, $size, $bold || $header)));
        }
        return max($header ? 24 : $this->minRowH, $maxLines * ($size + 2.5) + $this->rowPad);
    }

    /**
     * Render one table row across the content width, with columns sized by
     * $widths (fractions of content width, must sum to ~1). Long text wraps
     * inside its cell, numbers are centred, and every row gets the same
     * light grid as the Purchase Order. $header = dark green header row with
     * white capitals and a green accent line underneath.
     */
    public function tableRow(array $cells, array $widths, bool $bold = false, ?array $fillColor = null, ?array $textColor = null, ?array $align = null, bool $header = false): void
    {
        $W = $this->contentWidth(); $x0 = $this->margin;
        $size = $header ? 8 : 9; $lineH = $size + 2.5;
        $bold = $bold || $header;
        $rowH = $this->rowHeight($cells, $widths, $bold, $header);
        $this->ensureSpace($rowH + 4);
        $top = $this->y; $bottom = $top - $rowH;
        $grid = [212, 228, 218];

        if ($header) {
            $this->rectFill($x0, $bottom, $W, $rowH, [10, 31, 22]);
            $this->rectFill($x0, $bottom - 2, $W, 2, [22, 163, 74]);
        } else {
            if ($fillColor) $this->rectFill($x0, $bottom, $W, $rowH, $fillColor);
            $this->rectFill($x0, $bottom, $W, 0.5, $grid);
            $this->rectFill($x0, $bottom, 0.5, $rowH, $grid);
            $vx = $x0;
            foreach ($widths as $w) { $vx += $W * $w; $this->rectFill($vx - 0.25, $bottom, 0.5, $rowH, $grid); }
            if ($bold && $fillColor) $this->rectFill($x0, $bottom - 0.2, $W, 0.9, [22, 163, 74]);   // totals row accent
        }

        $this->setFont($bold ? 'F2' : 'F1', $size);
        $this->setTextColor($header ? [255, 255, 255] : ($textColor ?? ($bold ? [10, 31, 22] : [40, 55, 46])));
        $cx = $x0;
        foreach ($widths as $i => $w) {
            $colW = $W * $w;
            $cell = (string)($cells[$i] ?? '');
            if ($cell !== '') {
                $a = $align[$i] ?? self::guessAlign($cell);
                if ($header) $cell = strtoupper($cell);
                $ls = $this->cellLines($cell, $colW, $size, $bold);
                $blockTop = $top - ($rowH - count($ls) * $lineH) / 2;
                foreach ($ls as $k => $ln) {
                    $tx = $a === 'c' ? $cx + max(3, ($colW - $this->textWidth($ln, $size, $bold)) / 2) : $cx + 7;
                    $this->textAt($tx, $blockTop - $k * $lineH - ($lineH - $size) / 2 - $size * 0.78, $ln);
                }
            }
            $cx += $colW;
        }
        $this->resetTextColor();
        $this->y = $bottom - ($header ? 2 : 0);
    }

    public function output(string $filename): void
    {
        $this->endPage();

        // Fill in "Page X of N" (same width as the "@@" placeholder, so nothing shifts).
        $total = str_pad((string)count($this->pageObjIds), 2);
        foreach ($this->objects as $k => $body) {
            if (is_string($body) && strncmp($body, '<< /Length', 10) === 0) {
                $this->objects[$k] = str_replace('@@', $total, $body);
            }
        }

        $this->objects[$this->catalogId - 1] = "<< /Type /Catalog /Pages {$this->pagesId} 0 R >>";
        $kids = implode(' ', array_map(fn($id) => "{$id} 0 R", $this->pageObjIds));
        $this->objects[$this->pagesId - 1] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($this->pageObjIds) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($this->objects as $i => $body) {
            $num = $i + 1;
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $count = count($this->objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root {$this->catalogId} 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}

/**
 * Build and stream the business report as a PDF, then exit. Mirrors the
 * sections in stream_report_csv() (see report_helper.php) so both exports
 * always show the same numbers.
 */
/** Shared brand palette so every PDF (multi-section report or single-table export) looks identical. */
function report_colors(): array
{
    return [
        'navy' => [10, 31, 22],
        'blue' => [22, 163, 74],
        'lightBlue' => [167, 232, 189],
        'paleBand' => [234, 251, 240],
        'zebra' => [246, 251, 247],
        'slateMuted' => [101, 120, 108],
        'success' => [13, 148, 136],
        'danger' => [225, 75, 75],
    ];
}

/**
 * Draws the full-width navy header band with logo/company info, the
 * document title beneath it, and sets the footer text. Leaves the cursor
 * positioned right after the title, ready for a meta strip and/or content.
 */
function draw_branded_header(SimplePdf $pdf, array $company, string $documentTitle): array
{
    $c = report_colors();
    $pdf->setFooterText(($company['company_name'] ?? 'MABUMBATECH') . '  -  ' . ($company['company_tagline'] ?? 'Technology. Innovation. Solutions.'));

    $bandHeight = 86;
    $bandTop = $pdf->pageHeight();
    $bandBottom = $bandTop - $bandHeight;
    $pdf->rectFill(0, $bandBottom, $pdf->pageWidth(), $bandHeight, $c['navy']);
    $pdf->rectFill(0, $bandBottom - 3, $pdf->pageWidth(), 3, $c['blue']);

    $logoId = $pdf->addImageJpeg(__DIR__ . '/../assets/logo-full.jpg');
    $logoSize = @getimagesize(__DIR__ . '/../assets/logo-full.jpg');
    $logoRatio = $logoSize ? $logoSize[0] / $logoSize[1] : (1005 / 745);
    $logoH = 46; $logoW = $logoId ? $logoH * $logoRatio : 0;
    $logoY = $bandBottom + ($bandHeight - $logoH) / 2;
    $pdf->drawImage($logoId, $pdf->margin, $logoY, $logoW, $logoH);

    $textX = $pdf->margin + $logoW + 16;
    $pdf->setTextColor([255, 255, 255]);
    $pdf->setFont('F2', 16);
    $pdf->textAt($textX, $bandTop - 32, $company['company_name'] ?? 'MABUMBATECH');
    $pdf->setTextColor($c['lightBlue']);
    $pdf->setFont('F1', 9.5);
    $pdf->textAt($textX, $bandTop - 47, $company['company_tagline'] ?? 'Technology. Innovation. Solutions.');
    $pdf->setTextColor([156, 196, 172]);
    $pdf->setFont('F1', 8);
    $contact = trim(($company['company_location'] ?? '') . '   |   ' . ($company['company_phone'] ?? '') . '   |   ' . ($company['company_email'] ?? ''));
    $pdf->textAt($textX, $bandTop - 60, $contact);
    $pdf->resetTextColor();

    $pdf->setY($bandBottom - 34);

    $pdf->setFont('F2', 15);
    $pdf->setTextColor($c['navy']);
    $pdf->text($pdf->margin, strtoupper($documentTitle));
    $pdf->resetTextColor();
    $pdf->moveDown(36);

    return $c;
}

/** A shaded 2-or-3-column strip of short key/value facts (Period, Generated, Prepared By, etc). */
function draw_meta_strip(SimplePdf $pdf, array $items, array $colors): void
{
    $n = count($items);
    $boxH = 38;
    $top = $pdf->y() + 22;                 // top edge of the shaded box
    $pdf->rectFill($pdf->margin, $top - $boxH, $pdf->contentWidth(), $boxH, $colors['paleBand']);
    $colW = $pdf->contentWidth() / $n;

    // Label + value stacked and centred vertically inside the box.
    $pdf->setFont('F1', 9);
    $pdf->setTextColor($colors['slateMuted']);
    foreach ($items as $i => [$label, $value]) {
        $pdf->textAt($pdf->margin + 12 + $i * $colW, $top - 15, strtoupper($label));
    }
    $pdf->setFont('F2', 10);
    $pdf->setTextColor($colors['navy']);
    foreach ($items as $i => [$label, $value]) {
        $pdf->textAt($pdf->margin + 12 + $i * $colW, $top - 29, $value);
    }
    $pdf->resetTextColor();
    $pdf->moveDown(44);
}

function stream_report_pdf(array $report, array $company): void
{
    $pdf = new SimplePdf();
    $money = fn($n) => number_format((float)$n, 0) . ' TZS';

    $c = draw_branded_header($pdf, $company, 'Business Report');
    $navy = $c['navy']; $blue = $c['blue']; $paleBand = $c['paleBand']; $zebra = $c['zebra'];

    draw_meta_strip($pdf, [
        ['Period', $report['range']['start'] . ' to ' . $report['range']['end']],
        ['Generated', date('d M Y, H:i')],
        ['Prepared By', $company['generated_by'] ?? 'System'],
    ], $c);

    $pdf->moveDown(6);

    $wv = !empty($report['worker_view']);

    // ---- Summary ----
    section_title($pdf, 'Summary', $navy, $blue);
    $pdf->ensureSpace(70);
    $pdf->tableRow(['Description', 'Amount'], [0.5, 0.5], true, null, null, ['l', 'c'], true);
    $summaryRows = [['Total Sales', $money($report['sales']['total_sales'])], ['Total Transactions', (string)$report['sales']['transactions']]];
    if (!$wv) $summaryRows[] = ['Gross Profit', $money($report['sales']['total_profit'])];
    $summaryRows[] = ['Total Expenses', $money($report['expenses']['total_expenses'])];
    if (!$wv) {
        $summaryRows[] = ['Total Damage/Loss', $money($report['damages']['total_loss'])];
        $summaryRows[] = ['Net Profit', $money($report['net_profit'])];
    }
    $summaryRows[] = ['Product Revenue', $money($report['revenue_split']['product_revenue'])];
    $summaryRows[] = ['Service Revenue', $money($report['revenue_split']['service_revenue'])];
    foreach ($summaryRows as $i => $row) { $pdf->tableRow($row, [0.5, 0.5], $row[0] === 'Net Profit', $i % 2 === 1 ? $zebra : null); }
    $pdf->moveDown(16);

    // ---- Top Selling ----
    section_title($pdf, 'Top Selling Items (by quantity)', $navy, $blue);
    if ($wv) {
        draw_table($pdf, ['Name', 'Type', 'Qty', 'Revenue'], [0.42, 0.18, 0.14, 0.26], $paleBand, $zebra,
            array_map(fn($r) => [$r['name'], ucfirst($r['item_type']), (string)$r['qty_sold'], $money($r['revenue'])], $report['top_selling']));
    } else {
        draw_table($pdf, ['Name', 'Type', 'Qty', 'Revenue', 'Profit'], [0.36, 0.16, 0.12, 0.18, 0.18], $paleBand, $zebra,
            array_map(fn($r) => [$r['name'], ucfirst($r['item_type']), (string)$r['qty_sold'], $money($r['revenue']), $money($r['profit'])], $report['top_selling']));
    }
    $pdf->moveDown(16);

    // ---- Most Profitable ----
    if (!$wv) {
        section_title($pdf, 'Most Profitable Items', $navy, $blue);
        draw_table($pdf, ['Name', 'Type', 'Qty', 'Revenue', 'Profit'], [0.36, 0.16, 0.12, 0.18, 0.18], $paleBand, $zebra,
            array_map(fn($r) => [$r['name'], ucfirst($r['item_type']), (string)$r['qty_sold'], $money($r['revenue']), $money($r['profit'])], $report['most_profitable']));
        $pdf->moveDown(16);
    }

    // ---- Expense Breakdown ----
    section_title($pdf, 'Expense Breakdown', $navy, $blue);
    draw_table($pdf, ['Category', 'Amount'], [0.7, 0.3], $paleBand, $zebra,
        array_map(fn($r) => [$r['category'], $money($r['total'])], $report['expense_breakdown']));
    $pdf->moveDown(16);

    // ---- Payment Method Breakdown ----
    section_title($pdf, 'Payment Method Breakdown', $navy, $blue);
    draw_table($pdf, ['Method', 'Count', 'Total'], [0.5, 0.2, 0.3], $paleBand, $zebra,
        array_map(fn($r) => [$r['method'] ?? 'Unspecified', (string)$r['count'], $money($r['total'])], $report['payment_breakdown']));
    $pdf->moveDown(16);

    // ---- Damages ----
    section_title($pdf, 'Damages / Losses', $navy, $blue);
    if ($wv) {
        draw_table($pdf, ['Item', 'Qty', 'Reason', 'Date'], [0.34, 0.12, 0.32, 0.22], $paleBand, $zebra,
            array_map(fn($r) => [$r['product_name'] ?? 'Unknown', (string)($r['quantity'] ?? '-'), $r['reason'], $r['damage_date']], $report['damage_list']));
    } else {
        draw_table($pdf, ['Item', 'Qty', 'Reason', 'Loss Value', 'Date'], [0.28, 0.1, 0.24, 0.2, 0.18], $paleBand, $zebra,
            array_map(fn($r) => [$r['product_name'] ?? 'Unknown', (string)($r['quantity'] ?? '-'), $r['reason'], $money($r['loss_value']), $r['damage_date']], $report['damage_list']));
    }

    // ---- Signatures ----
    draw_signature_block($pdf, $navy, $company['generated_by'] ?? '');

    $filename = str_replace(' ', '_', $company['company_name'] ?? 'MABUMBATECH') . '_Report_' . $report['range']['start'] . '_to_' . $report['range']['end'] . '.pdf';
    $pdf->output($filename);
}

/**
 * Two signature areas side by side (same layout as the Purchase Order):
 * a title, a name line, and a signature/date line. A known name is printed
 * on the name line.
 */
function draw_signature_pair(SimplePdf $pdf, array $navy, string $leftTitle, string $leftName, string $rightTitle, string $rightName = ''): void
{
    $pdf->moveDown(22);
    $pdf->ensureSpace(100);
    $x0 = $pdf->margin;
    $sigW = ($pdf->contentWidth() - 40) / 2;
    $sy = $pdf->y();
    foreach ([[$x0, $leftTitle, $leftName], [$x0 + $sigW + 40, $rightTitle, $rightName]] as [$x, $title, $name]) {
        $pdf->setFont('F2', 9.5);
        $pdf->setTextColor($navy);
        $pdf->textAt($x, $sy, $title);
        if ($name !== '') {
            $pdf->setFont('F1', 9);
            $pdf->setTextColor([30, 45, 36]);
            $pdf->textAt($x, $sy - 28, $name);
        }
        $pdf->setFont('F1', 8);
        $pdf->setTextColor([101, 120, 108]);
        $pdf->line($x, $sy - 31, $x + $sigW, $sy - 31, 0.5);
        $pdf->textAt($x, $sy - 41, 'Name');
        $pdf->line($x, $sy - 63, $x + $sigW, $sy - 63, 0.5);
        $pdf->textAt($x, $sy - 73, 'Signature  /  Date');
        $pdf->resetTextColor();
    }
    $pdf->setY($sy - 82);
}

/** Prepared By / Approved By block used by reports and list exports. */
function draw_signature_block(SimplePdf $pdf, array $navy, string $preparedByName): void
{
    draw_signature_pair($pdf, $navy, 'PREPARED BY', $preparedByName, 'APPROVED BY', '');
}

/** Same block when either name may already be known (Cash Audit sheet). */
function draw_signature_block_simple(SimplePdf $pdf, array $navy, string $preparedByName, ?string $approvedByName): void
{
    draw_signature_pair($pdf, $navy, 'PREPARED BY', $preparedByName, 'APPROVED BY', (string)$approvedByName);
}

function section_title(SimplePdf $pdf, string $title, array $navy, array $blue): void
{
    // Plain section heading (no green side accent) — same as the Cash Audit sheet.
    $pdf->ensureSpace(26);
    $pdf->setFont('F2', 12);
    $pdf->setTextColor($navy);
    $pdf->text($pdf->margin, $title);
    $pdf->resetTextColor();
    $pdf->moveDown(18);
}

/**
 * Draw a styled table: dark green header (repeated on every new page), then
 * zebra-striped, grid-lined rows. Numeric columns are centred. Returns the
 * per-column alignment so a totals row can line up with it.
 */
function draw_table(SimplePdf $pdf, array $headers, array $widths, array $headerFill, array $zebraFill, array $rows): array
{
    $align = [];
    foreach ($widths as $i => $w) {
        $vals = array_values(array_filter(array_map(fn($r) => trim((string)($r[$i] ?? '')), $rows), fn($v) => $v !== ''));
        $allNum = $vals && count(array_filter($vals, fn($v) => SimplePdf::guessAlign($v) === 'c')) === count($vals);
        $align[$i] = $allNum ? 'c' : 'l';
    }
    $drawHead = fn() => $pdf->tableRow($headers, $widths, true, null, null, $align, true);

    $pdf->ensureSpace(70);
    $drawHead();
    if (empty($rows)) {
        $pdf->tableRow(['No data for this period.'], [1.0], false, null, [101, 120, 108], ['c']);
        return $align;
    }
    foreach ($rows as $i => $row) {
        $h = $pdf->rowHeight($row, $widths);
        if ($pdf->y() - $h - 4 < $pdf->margin + 20) {   // new page: repeat the header row
            $pdf->addPage();
            $drawHead();
        }
        $pdf->tableRow($row, $widths, false, $i % 2 === 1 ? $zebraFill : null, null, $align);
    }
    return $align;
}

/**
 * Generic single-table PDF export used by every list page's "Export PDF"
 * button (Sales, Products, Customers, Expenses, Suppliers, Damages, Debts,
 * Stock Movements, Purchases, Activity Log) — same branded header, meta
 * strip and signature block as the full Reports Center document, just
 * with one table instead of many sections.
 *
 * @param string      $title      Document title, e.g. "Sales List"
 * @param array       $headers    Column headings
 * @param array       $widths     Column widths as fractions of content width (sum ~1.0)
 * @param array       $rows       Array of row arrays, each matching $headers in order
 * @param array       $company    Same shape as stream_report_pdf()'s $company param
 * @param array|null  $totalsRow  Optional bold totals row, same shape as one $rows entry
 * @param array       $metaExtra  Optional extra meta-strip facts beyond Generated/Prepared By, e.g. [['Filter', 'This month']]
 */
function stream_table_pdf(string $title, array $headers, array $widths, array $rows, array $company, ?array $totalsRow = null, array $metaExtra = []): void
{
    $pdf = new SimplePdf();
    $c = draw_branded_header($pdf, $company, $title);

    $metaItems = array_merge($metaExtra, [
        ['Generated', date('d M Y, H:i')],
        ['Prepared By', $company['generated_by'] ?? 'System'],
    ]);
    draw_meta_strip($pdf, $metaItems, $c);

    $align = draw_table($pdf, $headers, $widths, $c['paleBand'], $c['zebra'], $rows);
    if ($totalsRow) {
        $pdf->tableRow($totalsRow, $widths, true, $c['paleBand'], null, $align);
    }

    draw_signature_block($pdf, $c['navy'], $company['generated_by'] ?? '');

    $safeTitle = preg_replace('/[^A-Za-z0-9_]+/', '_', $title);
    $filename = str_replace(' ', '_', $company['company_name'] ?? 'MABUMBATECH') . '_' . $safeTitle . '_' . date('Y-m-d') . '.pdf';
    $pdf->output($filename);
}
