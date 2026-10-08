<?php
/**
 * MABUMBATECH - Customer matching, debt-restriction, and
 * payment-score logic. Shared by sales.php, debts.php, and customers.php
 * so all three agree on what a customer's status and score are - see
 * the note in customers.php about NOT building three different scoring
 * systems that can disagree with each other.
 */

/**
 * Find-or-create the customer for a sale, given whatever name/phone was
 * typed on that sale. Returns a customer id, or null if neither was given.
 *
 * Matching rule:
 *  - Phone given -> match/create by phone (the reliable identifier).
 *    If the customer's name was blank before, fill it in from this sale.
 *  - No phone, name given -> match/create by exact name among customers
 *    who ALSO have no phone on file. This is a soft match: two different
 *    real people with the same name and no phone will be merged into one
 *    customer record. There is no fix for that without requiring a phone
 *    number, which was explicitly asked to stay optional.
 *  - Neither given -> no customer record, returns null.
 */
function resolve_customer(PDO $pdo, ?string $name, ?string $phone): ?int
{
    $name = trim((string)$name);
    $phone = trim((string)$phone);

    if ($phone !== '') {
        $stmt = $pdo->prepare('SELECT id, name FROM customers WHERE phone = ?');
        $stmt->execute([$phone]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($name !== '' && empty($existing['name'])) {
                $pdo->prepare('UPDATE customers SET name = ? WHERE id = ?')->execute([$name, $existing['id']]);
            }
            return (int)$existing['id'];
        }

        $ins = $pdo->prepare('INSERT INTO customers (name, phone, first_purchase_date, created_at) VALUES (?,?,CURDATE(),NOW())');
        $ins->execute([$name !== '' ? $name : null, $phone]);
        return (int)$pdo->lastInsertId();
    }

    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone IS NULL AND LOWER(name) = LOWER(?)');
        $stmt->execute([$name]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) return (int)$existingId;

        $ins = $pdo->prepare('INSERT INTO customers (name, phone, first_purchase_date, created_at) VALUES (?, NULL, CURDATE(), NOW())');
        $ins->execute([$name]);
        return (int)$pdo->lastInsertId();
    }

    return null;
}

/**
 * Count a customer's CURRENTLY overdue debts - deadline passed AND still
 * not fully paid. A debt that was late but is now fully paid off no
 * longer counts here (it shows up in payment-history stats instead).
 */
function count_overdue_debts(PDO $pdo, int $customerId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM sale_transactions st
        WHERE st.customer_id = ? AND st.payment_type = 'credit'
          AND st.credit_deadline < CURDATE()
          AND (st.total_amount - COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st.id), 0)) > 0.01
    ");
    $stmt->execute([$customerId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Re-check whether a customer should be auto-restricted, after any event
 * that could change their overdue count (a new credit sale reaching its
 * deadline, or a debt payment being recorded).
 *
 * IMPORTANT LIMITATION: this only ever moves a customer from active ->
 * restricted, never the other way. Un-restricting is a deliberate admin
 * action (see customers.php), not automatic - so if an admin manually
 * un-restricts someone who still has 3+ overdue debts, that is treated
 * as a one-time override. If this function runs again for that same
 * customer (e.g. they make another payment) and they still meet the
 * threshold, they WILL be auto-restricted again. There is no persistent
 * "permanently ignore the rule for this customer" flag in this version.
 */
function refresh_customer_restriction(PDO $pdo, ?int $customerId): void
{
    if (!$customerId) return;

    $overdueCount = count_overdue_debts($pdo, $customerId);
    if ($overdueCount < 3) return;

    $stmt = $pdo->prepare('SELECT status FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $status = $stmt->fetchColumn();

    if ($status === 'active') {
        $pdo->prepare("UPDATE customers SET status = 'restricted', restricted_at = NOW(), restricted_reason = ? WHERE id = ?")
            ->execute(['Automatically restricted: reached ' . $overdueCount . ' overdue debts', $customerId]);
    }
}

/**
 * Throws (via respond(), which exits) if this customer is not allowed to
 * receive another credit/debt sale. Cash sales are never blocked, even
 * for a restricted customer - only checked when payment_type='credit'.
 */
function block_if_credit_restricted(PDO $pdo, ?int $customerId): void
{
    if (!$customerId) return;
    $stmt = $pdo->prepare('SELECT status FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    if ($stmt->fetchColumn() === 'restricted') {
        respond(false, null, 'Credit Sale Blocked: This customer has reached the maximum overdue-debt limit.', 422);
    }
}

/**
 * The ONE payment-reliability score and risk tier used everywhere in the
 * app - the profile header, the summary cards, sorting/filtering. Never
 * compute a second, differently-worded score elsewhere; extend this
 * function instead so every screen agrees.
 *
 * Score = on-time rate among a customer's COMPLETED (fully paid) credit
 * debts, 0-100. A customer with no completed credit debts yet has no
 * score (null) rather than a misleading default.
 *
 * Tiers:
 *   restricted  - customers.status = 'restricted' (always wins, regardless of score)
 *   excellent   - score >= 90
 *   good        - score >= 70
 *   warning     - score >= 40
 *   high_risk   - score <  40
 *   new         - no completed credit debts yet to judge by
 */
function compute_payment_profile(PDO $pdo, int $customerId, string $status): array
{
    $stmt = $pdo->prepare("
        SELECT st.id, st.total_amount, st.credit_deadline,
               COALESCE((SELECT SUM(dp.amount) FROM debt_payments dp WHERE dp.sale_id = st.id), 0) AS paid,
               (SELECT MAX(dp.payment_date) FROM debt_payments dp WHERE dp.sale_id = st.id) AS last_payment_date
        FROM sale_transactions st
        WHERE st.customer_id = ? AND st.payment_type = 'credit'
    ");
    $stmt->execute([$customerId]);
    $debts = $stmt->fetchAll();

    $totalDebts = count($debts);
    $completed = 0;
    $onTime = 0;
    $late = 0;
    $currentlyOverdue = 0;
    $outstanding = 0.0;
    $overdueAmount = 0.0;

    foreach ($debts as $d) {
        $remaining = round((float)$d['total_amount'] - (float)$d['paid'], 2);
        $isPaid = $remaining <= 0.01;
        $isOverdueNow = !$isPaid && !empty($d['credit_deadline']) && $d['credit_deadline'] < date('Y-m-d');

        if ($isPaid) {
            $completed++;
            if (!empty($d['credit_deadline']) && !empty($d['last_payment_date']) && $d['last_payment_date'] <= $d['credit_deadline']) {
                $onTime++;
            } else {
                $late++;
            }
        } else {
            $outstanding += $remaining;
            if ($isOverdueNow) {
                $currentlyOverdue++;
                $overdueAmount += $remaining;
            }
        }
    }

    $score = $completed > 0 ? round(($onTime / $completed) * 100) : null;

    if ($status === 'restricted') {
        $tier = 'restricted';
    } elseif ($score === null) {
        $tier = 'new';
    } elseif ($score >= 90) {
        $tier = 'excellent';
    } elseif ($score >= 70) {
        $tier = 'good';
    } elseif ($score >= 40) {
        $tier = 'warning';
    } else {
        $tier = 'high_risk';
    }

    return [
        'score' => $score,
        'tier' => $tier,
        'total_debts' => $totalDebts,
        'completed_debts' => $completed,
        'on_time_debts' => $onTime,
        'late_debts' => $late,
        'currently_overdue_debts' => $currentlyOverdue,
        'outstanding_debt' => round($outstanding, 2),
        'overdue_amount' => round($overdueAmount, 2),
    ];
}
