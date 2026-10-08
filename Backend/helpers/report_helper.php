<?php
/**
 * MABUMBATECH — Report Helper
 * Handles date-range resolution and data aggregation for reports,
 * the dashboard, and the public "today" snapshot.
 */

/**
 * Resolve a period type + anchor date into a [start, end] date pair.
 * period: today | yesterday | week | month | year | custom
 */
function resolve_period(string $period, ?string $anchor = null, ?string $endCustom = null): array
{
    $anchor = $anchor ?: date('Y-m-d');

    switch ($period) {
        case 'today':
            return [date('Y-m-d'), date('Y-m-d')];

        case 'yesterday':
            $y = date('Y-m-d', strtotime('-1 day'));
            return [$y, $y];

        case 'week':
            $start = new DateTime($anchor);
            $end   = (clone $start)->modify('+6 days');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'month':
            $d = new DateTime($anchor);
            $start = new DateTime($d->format('Y-m-01'));
            $end   = new DateTime($d->format('Y-m-t'));
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'year':
            $d = new DateTime($anchor);
            $start = new DateTime($d->format('Y-01-01'));
            $end   = new DateTime($d->format('Y-12-31'));
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'custom':
            $end = $endCustom ?: $anchor;
            return [$anchor, $end];

        default:
            return [$anchor, $anchor];
    }
}

/**
 * Build the full report payload for a date range.
 *
 * Line-item aggregates (top selling, most profitable) are computed
 * straight off sale_items — its `description`, `item_type`, `subtotal`
 * and `profit` columns are already a snapshot taken at sale time, so
 * there's no need to re-join products/services to know what was sold
 * or what it earned. Grouping key is (item_type, product/service id,
 * description) so custom one-off jobs with different descriptions
 * never get merged into a single "custom" bucket.
 */
function build_report(PDO $pdo, string $start, string $end): array
{
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(total_amount),0) AS total_sales,
               COALESCE(SUM(total_profit),0) AS total_profit,
               COALESCE(SUM(discount_amount),0) AS total_discount,
               COALESCE(SUM(tax_amount),0) AS total_tax,
               COUNT(*) AS transactions
        FROM sale_transactions WHERE sale_date BETWEEN ? AND ? AND status = "completed"
    ');
    $stmt->execute([$start, $end]);
    $salesSummary = $stmt->fetch();

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(amount),0) AS total_expenses, COUNT(*) AS entries
        FROM expenses WHERE expense_date BETWEEN ? AND ?
    ');
    $stmt->execute([$start, $end]);
    $expenseSummary = $stmt->fetch();

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(loss_value),0) AS total_loss, COUNT(*) AS entries
        FROM damages WHERE damage_date BETWEEN ? AND ?
    ');
    $stmt->execute([$start, $end]);
    $damageSummary = $stmt->fetch();

    $lineAgg = '
        SELECT
            si.item_type,
            CASE si.item_type WHEN "product" THEN si.variant_id WHEN "service" THEN si.service_id ELSE NULL END AS ref_id,
            si.description AS name,
            SUM(si.quantity) AS qty_sold,
            SUM(si.subtotal) AS revenue,
            SUM(si.profit) AS profit
        FROM sale_items si
        JOIN sale_transactions st ON st.id = si.transaction_id
        WHERE st.sale_date BETWEEN ? AND ? AND st.status = "completed"
        GROUP BY si.item_type, ref_id, si.description
    ';

    $stmt = $pdo->prepare($lineAgg . ' ORDER BY qty_sold DESC LIMIT 10');
    $stmt->execute([$start, $end]);
    $topSelling = $stmt->fetchAll();

    $stmt = $pdo->prepare($lineAgg . ' ORDER BY profit DESC LIMIT 10');
    $stmt->execute([$start, $end]);
    $mostProfitable = $stmt->fetchAll();

    // Product revenue vs. service revenue (custom services counted as services)
    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN si.item_type = "product" THEN si.subtotal ELSE 0 END), 0) AS product_revenue,
            COALESCE(SUM(CASE WHEN si.item_type IN ("service","custom") THEN si.subtotal ELSE 0 END), 0) AS service_revenue
        FROM sale_items si
        JOIN sale_transactions st ON st.id = si.transaction_id
        WHERE st.sale_date BETWEEN ? AND ? AND st.status = "completed"
    ');
    $stmt->execute([$start, $end]);
    $revenueSplit = $stmt->fetch();

    $stmt = $pdo->prepare('
        SELECT COALESCE(ec.name, "Uncategorised") AS category, SUM(e.amount) AS total
        FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id
        WHERE e.expense_date BETWEEN ? AND ?
        GROUP BY ec.id, ec.name ORDER BY total DESC
    ');
    $stmt->execute([$start, $end]);
    $expenseBreakdown = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT d.id, COALESCE(CONCAT(p.name, " — ", v.variant_name), d.item_name) AS product_name,
               d.item_type, d.quantity, d.reason, d.loss_value, d.damage_date
        FROM damages d
        LEFT JOIN product_variants v ON v.id = d.variant_id
        LEFT JOIN products p ON p.id = v.product_id
        WHERE d.damage_date BETWEEN ? AND ?
        ORDER BY d.damage_date DESC
    ');
    $stmt->execute([$start, $end]);
    $damageList = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT pm.name AS method, COUNT(*) AS count, COALESCE(SUM(st.total_amount),0) AS total
        FROM sale_transactions st LEFT JOIN payment_methods pm ON pm.id = st.payment_method_id
        WHERE st.sale_date BETWEEN ? AND ? AND st.status = "completed"
        GROUP BY COALESCE(pm.name, IF(st.payment_type = "credit", "Credit", "Unspecified"))
        ORDER BY total DESC
    ');
    $stmt->execute([$start, $end]);
    $paymentBreakdown = $stmt->fetchAll();

    $netProfit = (float)$salesSummary['total_profit'] - (float)$expenseSummary['total_expenses'] - (float)$damageSummary['total_loss'];

    return [
        'range' => ['start' => $start, 'end' => $end],
        'sales' => $salesSummary,
        'expenses' => $expenseSummary,
        'damages' => $damageSummary,
        'revenue_split' => $revenueSplit,
        'payment_breakdown' => $paymentBreakdown,
        'net_profit' => round($netProfit, 2),
        'top_selling' => $topSelling,
        'most_profitable' => $mostProfitable,
        'expense_breakdown' => $expenseBreakdown,
        'damage_list' => $damageList,
    ];
}

/**
 * Worker version of a report: everything that reveals profit or what
 * things cost the business is removed (gross/net profit, per-item
 * profit, the "most profitable" ranking, and damage loss values —
 * a loss value is quantity x buying price, so it would leak the cost).
 * Sales, expenses, payment methods and damage quantities stay.
 * Sets `worker_view` so the CSV/PDF/Excel renderers skip those parts.
 */
function hide_profit_from_report(array $report): array
{
    unset($report['sales']['total_profit'], $report['net_profit'], $report['most_profitable'], $report['damages']['total_loss']);
    $report['top_selling'] = strip_keys_list($report['top_selling'], ['profit']);
    $report['damage_list'] = strip_keys_list($report['damage_list'], ['loss_value']);
    $report['worker_view'] = true;
    return $report;
}

/**
 * Stream a CSV version of the report straight to the browser and exit.
 */
function stream_report_csv(array $report, string $companyName): void
{
    $filename = str_replace(' ', '_', $companyName) . '_Report_' . $report['range']['start'] . '_to_' . $report['range']['end'] . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    fputcsv($out, [$companyName . ' — Business Report']);
    fputcsv($out, ['Period', $report['range']['start'] . ' to ' . $report['range']['end']]);
    fputcsv($out, []);

    $wv = !empty($report['worker_view']);

    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Total Sales (TZS)', $report['sales']['total_sales']]);
    fputcsv($out, ['Total Transactions', $report['sales']['transactions']]);
    if (!$wv) fputcsv($out, ['Gross Profit (TZS)', $report['sales']['total_profit']]);
    fputcsv($out, ['Total Expenses (TZS)', $report['expenses']['total_expenses']]);
    if (!$wv) fputcsv($out, ['Total Damage/Loss (TZS)', $report['damages']['total_loss']]);
    if (!$wv) fputcsv($out, ['Net Profit (TZS)', $report['net_profit']]);
    fputcsv($out, ['Product Revenue (TZS)', $report['revenue_split']['product_revenue']]);
    fputcsv($out, ['Service Revenue (TZS)', $report['revenue_split']['service_revenue']]);
    fputcsv($out, []);

    fputcsv($out, ['TOP SELLING ITEMS (by quantity)']);
    fputcsv($out, $wv ? ['Name', 'Type', 'Qty Sold', 'Revenue (TZS)'] : ['Name', 'Type', 'Qty Sold', 'Revenue (TZS)', 'Profit (TZS)']);
    foreach ($report['top_selling'] as $row) {
        $line = [$row['name'], ucfirst($row['item_type']), $row['qty_sold'], $row['revenue']];
        if (!$wv) $line[] = $row['profit'];
        fputcsv($out, $line);
    }
    fputcsv($out, []);

    if (!$wv) {
        fputcsv($out, ['MOST PROFITABLE ITEMS']);
        fputcsv($out, ['Name', 'Type', 'Qty Sold', 'Revenue (TZS)', 'Profit (TZS)']);
        foreach ($report['most_profitable'] as $row) {
            fputcsv($out, [$row['name'], ucfirst($row['item_type']), $row['qty_sold'], $row['revenue'], $row['profit']]);
        }
        fputcsv($out, []);
    }

    fputcsv($out, ['EXPENSE BREAKDOWN']);
    fputcsv($out, ['Category', 'Amount (TZS)']);
    foreach ($report['expense_breakdown'] as $row) {
        fputcsv($out, [$row['category'], $row['total']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['PAYMENT METHOD BREAKDOWN']);
    fputcsv($out, ['Method', 'Count', 'Total (TZS)']);
    foreach ($report['payment_breakdown'] as $row) {
        fputcsv($out, [$row['method'], $row['count'], $row['total']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['DAMAGES / LOSSES']);
    fputcsv($out, $wv ? ['Item', 'Quantity', 'Reason', 'Date'] : ['Item', 'Quantity', 'Reason', 'Loss Value (TZS)', 'Date']);
    foreach ($report['damage_list'] as $row) {
        $line = [$row['product_name'], $row['quantity'], $row['reason']];
        if (!$wv) $line[] = $row['loss_value'];
        $line[] = $row['damage_date'];
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}
