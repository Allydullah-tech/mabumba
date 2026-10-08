<?php
/**
 * MABUMBATECH — Minimal XLSX (Excel) writer
 *
 * Builds a real .xlsx file (which is just a zip of small XML files) using
 * PHP's built-in ZipArchive extension — no Composer/PhpSpreadsheet needed.
 * XAMPP ships with the zip extension enabled by default, so this works out
 * of the box. Supports one worksheet, per-cell bold styling, and plain
 * values — enough for business reports and data exports, not a general
 * spreadsheet engine.
 */

class SimpleXlsx
{
    /** @var array<int, array<int, array{value: mixed, bold: bool}>> */
    private array $rows = [];

    /** Append one row. $cells is a list of either raw values or ['value'=>..., 'bold'=>true]. */
    public function addRow(array $cells): void
    {
        $normalized = [];
        foreach ($cells as $cell) {
            if (is_array($cell)) {
                $normalized[] = ['value' => $cell['value'] ?? '', 'bold' => !empty($cell['bold'])];
            } else {
                $normalized[] = ['value' => $cell, 'bold' => false];
            }
        }
        $this->rows[] = $normalized;
    }

    /** Convenience for a bold section/header row of plain strings. */
    public function addHeaderRow(array $values): void
    {
        $this->addRow(array_map(fn($v) => ['value' => $v, 'bold' => true], $values));
    }

    public function addBlankRow(): void
    {
        $this->rows[] = [];
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function isNumericValue($value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && $value !== '' && is_numeric($value) && !preg_match('/^0[0-9]/', $value));
    }

    private function buildSheetXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($this->rows as $rIdx => $cells) {
            $rowNum = $rIdx + 1;
            $xml .= "<row r=\"{$rowNum}\">";
            foreach ($cells as $cIdx => $cell) {
                $ref = $this->colLetter($cIdx) . $rowNum;
                $value = $cell['value'];
                $styleAttr = $cell['bold'] ? ' s="1"' : '';

                if ($this->isNumericValue($value)) {
                    $xml .= "<c r=\"{$ref}\"{$styleAttr}><v>" . $this->xmlEscape((string)$value) . '</v></c>';
                } else {
                    $xml .= "<c r=\"{$ref}\" t=\"inlineStr\"{$styleAttr}><is><t xml:space=\"preserve\">" . $this->xmlEscape((string)$value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /** Stream the finished .xlsx to the browser and exit. */
    public function output(string $filename): void
    {
        if (!class_exists('ZipArchive')) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Excel export requires the PHP zip extension, which is not enabled on this server. Enable "extension=zip" in php.ini (it ships with XAMPP but may need turning on) and try again.']);
            exit;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mbt_xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');

        // Two cell styles: 0 = default, 1 = bold (used for headers/section titles).
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><sz val="10"/><name val="Calibri"/><b/></font></fonts>' .
            '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="2">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
            '</cellXfs></styleSheet>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildSheetXml());
        $zip->close();

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        unlink($tmpFile);
        exit;
    }
}

/**
 * Build and stream the business report as an .xlsx workbook, then exit.
 * Mirrors stream_report_csv()/stream_report_pdf() so every export format
 * always shows the same numbers.
 */
/**
 * Generic single-table Excel export used by every list page's "Export
 * Excel" button — same letterhead/meta/signature convention as
 * stream_table_pdf(), just as a real .xlsx workbook.
 */
function stream_table_xlsx(string $title, array $headers, array $rows, array $company, ?array $totalsRow = null, array $metaExtra = []): void
{
    $xlsx = new SimpleXlsx();

    $xlsx->addHeaderRow([$company['company_name'] ?? 'MABUMBATECH']);
    $xlsx->addRow([$company['company_tagline'] ?? 'Technology. Innovation. Solutions.']);
    $xlsx->addRow([trim(($company['company_location'] ?? '') . '   ' . ($company['company_phone'] ?? '') . '   ' . ($company['company_email'] ?? ''))]);
    $xlsx->addBlankRow();
    $xlsx->addHeaderRow([$title]);
    foreach ($metaExtra as [$label, $value]) { $xlsx->addRow([$label, $value]); }
    $xlsx->addRow(['Generated', date('d M Y, H:i')]);
    if (!empty($company['generated_by'])) $xlsx->addRow(['Prepared by', $company['generated_by']]);
    $xlsx->addBlankRow();

    $xlsx->addHeaderRow($headers);
    foreach ($rows as $row) { $xlsx->addRow($row); }
    if ($totalsRow) { $xlsx->addHeaderRow($totalsRow); }

    $xlsx->addBlankRow();
    $xlsx->addBlankRow();
    $xlsx->addRow(['Prepared By: ' . ($company['generated_by'] ?? '________________________'), '', 'Approved By: ________________________']);
    $xlsx->addRow(['Signature: ________________________', '', 'Signature: ________________________']);
    $xlsx->addRow(['Date: ________________________', '', 'Date: ________________________']);

    $safeTitle = preg_replace('/[^A-Za-z0-9_]+/', '_', $title);
    $filename = str_replace(' ', '_', $company['company_name'] ?? 'MABUMBATECH') . '_' . $safeTitle . '_' . date('Y-m-d') . '.xlsx';
    $xlsx->output($filename);
}

/**
 * Build and stream the business report as an .xlsx workbook, then exit.
 * Mirrors stream_report_csv()/stream_report_pdf() so every export format
 * always shows the same numbers.
 */
function stream_report_xlsx(array $report, array $company): void
{
    $xlsx = new SimpleXlsx();

    $xlsx->addHeaderRow([$company['company_name'] ?? 'MABUMBATECH']);
    $xlsx->addRow([$company['company_tagline'] ?? 'Technology. Innovation. Solutions.']);
    $xlsx->addRow([trim(($company['company_location'] ?? '') . '   ' . ($company['company_phone'] ?? '') . '   ' . ($company['company_email'] ?? ''))]);
    $xlsx->addBlankRow();
    $xlsx->addHeaderRow(['Business Report']);
    $xlsx->addRow(['Period', $report['range']['start'] . ' to ' . $report['range']['end']]);
    $xlsx->addRow(['Generated', date('d M Y, H:i')]);
    if (!empty($company['generated_by'])) $xlsx->addRow(['Prepared by', $company['generated_by']]);
    $xlsx->addBlankRow();

    $wv = !empty($report['worker_view']);

    $xlsx->addHeaderRow(['SUMMARY']);
    $xlsx->addRow(['Total Sales (TZS)', $report['sales']['total_sales']]);
    $xlsx->addRow(['Total Transactions', $report['sales']['transactions']]);
    if (!$wv) $xlsx->addRow(['Gross Profit (TZS)', $report['sales']['total_profit']]);
    $xlsx->addRow(['Total Expenses (TZS)', $report['expenses']['total_expenses']]);
    if (!$wv) $xlsx->addRow(['Total Damage/Loss (TZS)', $report['damages']['total_loss']]);
    if (!$wv) $xlsx->addRow(['Net Profit (TZS)', $report['net_profit']]);
    $xlsx->addRow(['Product Revenue (TZS)', $report['revenue_split']['product_revenue']]);
    $xlsx->addRow(['Service Revenue (TZS)', $report['revenue_split']['service_revenue']]);
    $xlsx->addBlankRow();

    $xlsx->addHeaderRow(['TOP SELLING ITEMS (by quantity)']);
    $xlsx->addHeaderRow($wv ? ['Name', 'Type', 'Qty Sold', 'Revenue (TZS)'] : ['Name', 'Type', 'Qty Sold', 'Revenue (TZS)', 'Profit (TZS)']);
    foreach ($report['top_selling'] as $row) {
        $line = [$row['name'], ucfirst($row['item_type']), $row['qty_sold'], $row['revenue']];
        if (!$wv) $line[] = $row['profit'];
        $xlsx->addRow($line);
    }
    $xlsx->addBlankRow();

    if (!$wv) {
        $xlsx->addHeaderRow(['MOST PROFITABLE ITEMS']);
        $xlsx->addHeaderRow(['Name', 'Type', 'Qty Sold', 'Revenue (TZS)', 'Profit (TZS)']);
        foreach ($report['most_profitable'] as $row) {
            $xlsx->addRow([$row['name'], ucfirst($row['item_type']), $row['qty_sold'], $row['revenue'], $row['profit']]);
        }
        $xlsx->addBlankRow();
    }

    $xlsx->addHeaderRow(['EXPENSE BREAKDOWN']);
    $xlsx->addHeaderRow(['Category', 'Amount (TZS)']);
    foreach ($report['expense_breakdown'] as $row) {
        $xlsx->addRow([$row['category'], $row['total']]);
    }
    $xlsx->addBlankRow();

    $xlsx->addHeaderRow(['PAYMENT METHOD BREAKDOWN']);
    $xlsx->addHeaderRow(['Method', 'Count', 'Total (TZS)']);
    foreach ($report['payment_breakdown'] as $row) {
        $xlsx->addRow([$row['method'] ?? 'Unspecified', $row['count'], $row['total']]);
    }
    $xlsx->addBlankRow();

    $xlsx->addHeaderRow(['DAMAGES / LOSSES']);
    $xlsx->addHeaderRow($wv ? ['Item', 'Quantity', 'Reason', 'Date'] : ['Item', 'Quantity', 'Reason', 'Loss Value (TZS)', 'Date']);
    foreach ($report['damage_list'] as $row) {
        $line = [$row['product_name'], $row['quantity'], $row['reason']];
        if (!$wv) $line[] = $row['loss_value'];
        $line[] = $row['damage_date'];
        $xlsx->addRow($line);
    }
    $xlsx->addBlankRow();
    $xlsx->addBlankRow();

    $xlsx->addRow(['Prepared By: ' . ($company['generated_by'] ?? '________________________'), '', 'Approved By: ________________________']);
    $xlsx->addRow(['Signature: ________________________', '', 'Signature: ________________________']);
    $xlsx->addRow(['Date: ________________________', '', 'Date: ________________________']);

    $filename = str_replace(' ', '_', $company['company_name'] ?? 'MABUMBATECH') . '_Report_' . $report['range']['start'] . '_to_' . $report['range']['end'] . '.xlsx';
    $xlsx->output($filename);
}
