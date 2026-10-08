<?php
/**
 * MABUMBATECH — Cash Audit helpers
 *
 * Shared by cash_float.php, bank_deposits.php and cash_audits.php.
 *
 * Opening float for a date is never typed into the Cash Audit form —
 * it is either:
 *   - the most recent non-replaced 'opening' row in cash_float_entries
 *     for that exact date (the user set it by hand), or
 *   - missing that, automatically brought forward from the Remaining
 *     Cash of the closest earlier date that has a bank deposit on
 *     record (see remaining_cash_before_date()).
 * Setting a new opening amount for a date that already has one does
 * not delete the old row — it flags it `replaced` so History can show
 * it struck through.
 */

function remaining_cash_before_date(PDO $pdo, string $date): ?array
{
    $stmt = $pdo->prepare(
        "SELECT deposit_date, remaining_cash FROM bank_deposits
         WHERE deposit_date < ? ORDER BY deposit_date DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Everything the "Opening Cash & Float" panel needs for one date. */
function cash_float_state_for_date(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM cash_float_entries WHERE entry_date = ? ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute([$date]);
    $entries = $stmt->fetchAll();

    $openingManual = null;
    $added = 0.0;
    $reduced = 0.0;
    foreach ($entries as $e) {
        if ($e['type'] === 'opening' && !$e['replaced']) $openingManual = (float)$e['amount'];
        if ($e['type'] === 'add') $added += (float)$e['amount'];
        if ($e['type'] === 'reduce') $reduced += (float)$e['amount'];
    }

    $carry = remaining_cash_before_date($pdo, $date);
    $openingAuto = $carry ? (float)$carry['remaining_cash'] : 0.0;
    $openingEffective = $openingManual ?? $openingAuto;

    return [
        'date' => $date,
        'opening_auto' => round($openingAuto, 2),
        'opening_auto_from_date' => $carry['deposit_date'] ?? null,
        'opening_manual' => $openingManual !== null ? round($openingManual, 2) : null,
        'opening_effective' => round($openingEffective, 2),
        'added' => round($added, 2),
        'reduced' => round($reduced, 2),
        'current_float' => round($openingEffective + $added - $reduced, 2),
        'entries' => $entries,
    ];
}

function cash_float_total_for_date(PDO $pdo, string $date): float
{
    return cash_float_state_for_date($pdo, $date)['current_float'];
}

/** Part B of the Cash Audit sheet, computed live from real records. */
function cash_audit_summary(PDO $pdo, string $date): array
{
    $openingFloat = cash_float_state_for_date($pdo, $date)['opening_effective'];

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(st.total_amount), 0)
         FROM sale_transactions st LEFT JOIN payment_methods pm ON pm.id = st.payment_method_id
         WHERE st.sale_date = ? AND st.status = 'completed' AND st.payment_type = 'cash'
           AND (pm.slug IS NULL OR pm.slug = 'cash')"
    );
    $stmt->execute([$date]);
    $cashPhysical = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT pm.name AS method, COALESCE(SUM(st.total_amount), 0) AS total
         FROM sale_transactions st JOIN payment_methods pm ON pm.id = st.payment_method_id
         WHERE st.sale_date = ? AND st.status = 'completed' AND st.payment_type = 'cash' AND pm.slug <> 'cash'
         GROUP BY pm.id, pm.name ORDER BY total DESC"
    );
    $stmt->execute([$date]);
    $electronicTotal = 0.0;
    $electronic = [];
    foreach ($stmt->fetchAll() as $row) {
        $electronic[] = ['method' => $row['method'], 'amount' => (float)$row['total']];
        $electronicTotal += (float)$row['total'];
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) FROM sale_transactions
         WHERE sale_date = ? AND status = 'completed' AND payment_type = 'credit'"
    );
    $stmt->execute([$date]);
    $creditSales = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(dp.amount), 0) FROM debt_payments dp WHERE dp.payment_date = ?"
    );
    $stmt->execute([$date]);
    $creditCollectedToday = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = ?");
    $stmt->execute([$date]);
    $expensesTotal = (float)$stmt->fetchColumn();

    // Change left with the till today, not yet collected by the customer —
    // it IS physical cash in the drawer, but isn't in Cash Physical Sales
    // above (that reflects the sale's price, not what was physically paid).
    $stmt = $pdo->prepare(
        "SELECT id, customer_name, change_left_customer_name, (change_left_amount - change_collected_amount) AS outstanding
         FROM sale_transactions
         WHERE sale_date = ? AND (change_left_amount - change_collected_amount) > 0.001"
    );
    $stmt->execute([$date]);
    $changeRows = $stmt->fetchAll();
    $outstandingChange = 0.0;
    $changeNotes = [];
    foreach ($changeRows as $c) {
        $outstandingChange += (float)$c['outstanding'];
        $who = $c['change_left_customer_name'] ?: ($c['customer_name'] ?: 'a customer');
        $changeNotes[] = ['name' => $who, 'amount' => round((float)$c['outstanding'], 2)];
    }

    // All unpaid credit sales as of this date — informational only.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(st.total_amount - COALESCE(p.paid, 0)), 0) AS total
         FROM sale_transactions st
         LEFT JOIN (
            SELECT sale_id, SUM(amount) AS paid FROM debt_payments WHERE payment_date <= ? GROUP BY sale_id
         ) p ON p.sale_id = st.id
         WHERE st.status = 'completed' AND st.payment_type = 'credit' AND st.sale_date <= ?
           AND (st.total_amount - COALESCE(p.paid, 0)) > 0.001"
    );
    $stmt->execute([$date, $date]);
    $receivablesRow = $stmt->fetch();
    $outstandingReceivables = (float)($receivablesRow['total'] ?? 0);
    $receivablesCount = (int)($receivablesRow['n'] ?? 0);

    $totalSales = $cashPhysical + $electronicTotal + $creditSales;
    $cashAvailable = $openingFloat + $totalSales - $creditSales + $creditCollectedToday;
    $expectedCash = ($cashAvailable + $outstandingChange) - $expensesTotal;

    return [
        'opening_float' => round($openingFloat, 2),
        'cash_physical_sales' => round($cashPhysical, 2),
        'electronic_sales' => $electronic,
        'electronic_sales_total' => round($electronicTotal, 2),
        'credit_sales' => round($creditSales, 2),
        'total_sales' => round($totalSales, 2),
        'credit_collected_today' => round($creditCollectedToday, 2),
        'cash_available' => round($cashAvailable, 2),
        'outstanding_customer_change' => round($outstandingChange, 2),
        'change_notes' => $changeNotes,
        'expenses_total' => round($expensesTotal, 2),
        'expected_cash' => round($expectedCash, 2),
        'outstanding_receivables' => round($outstandingReceivables, 2),
        'outstanding_receivables_count' => $receivablesCount,
    ];
}
