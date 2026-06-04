<?php
/**
 * Helpers for writing sorting notification records.
 *
 * The table is intentionally not auto-created here. Run the SQL provided in the
 * PR/final notes before using the notification feature in production.
 */

function createSortingNotification(
    PDO $db,
    int $orderId,
    ?int $itemId,
    string $type,
    string $message,
    ?int $createdBy = null
): void {
    try {
        $stmt = $db->prepare("\n            INSERT INTO sorting_notifications (order_id, item_id, type, message, created_by, created_at)\n            VALUES (?, ?, ?, ?, ?, NOW())\n        ");
        $stmt->execute([$orderId, $itemId ?: null, $type, $message, $createdBy ?: null]);
    } catch (PDOException $e) {
        error_log('Sorting notification insert failed: ' . $e->getMessage());
    }
}

function createSortingItemNotification(PDO $db, array $item, ?int $createdBy = null): void
{
    $orderNumber = trim((string)($item['order_number'] ?? ''));
    if ($orderNumber === '') {
        $orderNumber = '#' . (int)($item['order_id'] ?? 0);
    }

    $sku = trim((string)($item['shein_sku'] ?? $item['sku'] ?? ''));
    $productName = trim((string)($item['product_name'] ?? ''));
    $label = $sku !== '' ? $sku : ($productName !== '' ? $productName : ('Item #' . (int)($item['id'] ?? 0)));

    createSortingNotification(
        $db,
        (int)$item['order_id'],
        (int)($item['id'] ?? $item['item_id'] ?? 0),
        'item_sorted',
        "Order {$orderNumber} — {$label} has been sorted ✅",
        $createdBy
    );
}

function createSortingOrderCompleteNotification(PDO $db, array $item, ?int $createdBy = null): void
{
    $orderNumber = trim((string)($item['order_number'] ?? ''));
    if ($orderNumber === '') {
        $orderNumber = '#' . (int)($item['order_id'] ?? 0);
    }

    createSortingNotification(
        $db,
        (int)$item['order_id'],
        null,
        'order_complete',
        "Order {$orderNumber} — All products have been sorted ✅",
        $createdBy
    );
}
