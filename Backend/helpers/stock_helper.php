<?php
/**
 * MABUMBATECH — Legacy `product_id` compatibility.
 *
 * Databases upgraded from v1 still carry the old `product_id` column (and
 * its foreign key to `products`) on purchases, damages, sale_items and
 * stock_movements. The v2 code only writes `variant_id`, so those old
 * columns were left at 0/empty and the foreign key rejected the row
 * ("Cannot add or update a child row ... fk_pur_product"). These helpers
 * let each writer also fill the old column with the real parent product id
 * — only when that column actually exists, so fresh installs are unaffected.
 */
function table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            $cache[$key] = ((int)$st->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

function product_id_for_variant(PDO $pdo, ?int $variantId): ?int
{
    if (!$variantId) return null;
    $st = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ?');
    $st->execute([$variantId]);
    $pid = $st->fetchColumn();
    return $pid !== false ? (int)$pid : null;
}

/**
 * MABUMBATECH — Stock movement ledger helper.
 *
 * Every change to a variant's stock_quantity (purchase, sale, damage,
 * manual adjustment) should call this right alongside the UPDATE to
 * product_variants.stock_quantity, so the Stock Movements page has a
 * single, append-only source of truth instead of re-deriving history by
 * unioning purchases + sale_items + damages on every page load.
 *
 * This never throws: like audit logging, a failed history write must
 * never block the real stock change it's describing.
 */
function log_stock_movement(
    PDO $pdo,
    int $variantId,
    string $movementType,
    int $quantityChange,
    ?string $referenceType,
    ?int $referenceId,
    ?string $note,
    int $userId
): void {
    try {
        if (table_has_column($pdo, 'stock_movements', 'product_id')) {
            $stmt = $pdo->prepare('INSERT INTO stock_movements
                (variant_id, product_id, movement_type, quantity_change, reference_type, reference_id, note, recorded_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$variantId, product_id_for_variant($pdo, $variantId), $movementType, $quantityChange, $referenceType, $referenceId, $note, $userId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO stock_movements
                (variant_id, movement_type, quantity_change, reference_type, reference_id, note, recorded_by, created_at)
                VALUES (?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$variantId, $movementType, $quantityChange, $referenceType, $referenceId, $note, $userId]);
        }
    } catch (Throwable $e) {
        // Deliberately swallowed — see note above.
    }
}
