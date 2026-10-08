<?php
/**
 * MABUMBATECH - Activity / Audit Log helper
 *
 * Records who did what, and when, for the actions that matter if
 * something goes wrong later: deleting a sale, changing a price,
 * removing a user, adjusting stock through a damage entry, etc.
 *
 * Design notes (read before changing this):
 *  - The actor's name and role are stored as plain text on the log row
 *    itself (user_name, user_role), NOT only as a foreign key to
 *    `users`. This is deliberate, not duplication for its own sake: if
 *    that user's account is later deleted, the log must still say who
 *    did it. user_id is kept too (nullable, no FK) so the two pieces
 *    of information - "who, right now" and "who, historically" - both
 *    survive independently.
 *  - Logging never throws and never blocks the real operation. A
 *    failed audit write should not stop a sale from being recorded.
 *  - Only mutations are logged (create/update/delete/payment) - never
 *    GET/list requests. Logging every page view would drown the
 *    handful of entries that actually matter under noise.
 */

/**
 * @param PDO    $pdo
 * @param array  $user        The logged-in user array (id, full_name, role).
 * @param string $action      'create' | 'update' | 'delete' | 'payment'
 * @param string $entityType  'sale' | 'product' | 'service' | 'damage' | 'expense' | 'debt_payment' | 'user'
 * @param mixed  $entityId    Row id the action applies to (nullable).
 * @param string $description Human-readable summary, e.g.
 *                             'Changed selling price of "Chalk" from TZS 5,000 to TZS 6,000'.
 */
function log_activity(PDO $pdo, array $user, string $action, string $entityType, $entityId, string $description): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_log
            (user_id, user_name, user_role, action, entity_type, entity_id, description, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())');
        $stmt->execute([
            $user['id'] ?? null,
            $user['full_name'] ?? 'Unknown user',
            $user['role'] ?? '',
            $action,
            $entityType,
            $entityId !== null ? (int)$entityId : null,
            $description,
        ]);
    } catch (Throwable $e) {
        // Deliberately swallowed: a broken/missing audit_log table (e.g.
        // before the migration is run) must never stop a real business
        // action like recording a sale.
    }
}

/**
 * Small formatting helper so "old -> new" diffs read the same way
 * everywhere in the log, e.g. money_diff(5000, 6000) -> "TZS 5,000 to TZS 6,000".
 */
function audit_money_diff($old, $new): string
{
    return 'TZS ' . number_format((float)$old) . ' to TZS ' . number_format((float)$new);
}
