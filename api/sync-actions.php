<?php
/**
 * api/sync-actions.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoint: POST /api/sync-actions.php
 * Synchronizes batch offline scan records to the server database.
 * Authenticated via Authorization: Bearer <token>.
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/api_helper.php';
require_once __DIR__ . '/../includes/shein_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('طريقة الطلب غير صالحة. الرجاء استخدام POST.', 405);
}

// Authenticate request
$user = authenticateRequest($db);
$userId = $user['id'];

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$scans = $body['scans'] ?? [];

if (!is_array($scans) || empty($scans)) {
    fail('يرجى إرسال قائمة بعمليات الفرز (scans) لمزامنتها.', 400);
}

// Normalize SKU function
function localNormalizeSku(string $sku): string
{
    $sku = strtoupper(trim($sku));
    return preg_replace('/[\s\-\x{00A0}\x{200B}\x{200C}\x{200D}]+/u', '', $sku) ?? '';
}

$results = [];

try {
    // NOTE: sheinEnsureSchema() intentionally removed from this hot path.
    // It runs ALTER TABLE statements on every sync which causes timeouts under load.

    foreach ($scans as $scan) {
        $id = $scan['id'] ?? null; // Local database ID in the Flutter app
        $rawSku = trim($scan['sku'] ?? '');
        $selectedItemId = (int)($scan['selected_item_id'] ?? 0);
        $timestamp = $scan['timestamp'] ?? time();

        if (empty($rawSku)) {
            $results[] = [
                'id' => $id,
                'success' => false,
                'message' => 'SKU فارغ'
            ];
            continue;
        }

        $sku = localNormalizeSku($rawSku);

        // Find the order item
        $item = null;
        if ($selectedItemId > 0) {
            // Verify if the specified item matches the SKU
            $stmt = $db->prepare("
                SELECT oi.id, oi.order_id, oi.status
                FROM order_items oi
                WHERE oi.id = ?
                  AND UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
                LIMIT 1
            ");
            $stmt->execute([$selectedItemId, $sku]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Try to find the first pending order item matching the SKU
            $stmt = $db->prepare("
                SELECT oi.id, oi.order_id, oi.status
                FROM order_items oi
                WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(oi.shein_sku), '-', ''), ' ', ''), CHAR(9), '')) = ?
                ORDER BY CASE WHEN oi.status = 'pending' THEN 0 ELSE 1 END, oi.id ASC
                LIMIT 1
            ");
            $stmt->execute([$sku]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$item) {
            $results[] = [
                'id' => $id,
                'success' => false,
                'message' => "لم يتم العثور على طلب لهذا الرمز: $sku"
            ];
            continue;
        }

        // Update order item status to 'scanned' if not already
        $alreadyScanned = ($item['status'] === 'scanned');
        if (!$alreadyScanned) {
            $updateStmt = $db->prepare("UPDATE order_items SET status = 'scanned', updated_at = NOW() WHERE id = ?");
            $updateStmt->execute([$item['id']]);
        }

        // Insert or update sorting_scans for user stats
        try {
            $scansStmt = $db->prepare("
                INSERT INTO sorting_scans (user_id, item_id, order_id, scanned_at)
                VALUES (?, ?, ?, FROM_UNIXTIME(?))
                ON DUPLICATE KEY UPDATE scanned_at = FROM_UNIXTIME(?)
            ");
            $scansStmt->execute([$userId, $item['id'], $item['order_id'], $timestamp, $timestamp]);
        } catch (PDOException $e) {
            // Silence in case table does not exist or has schema issues
        }

        $results[] = [
            'id' => $id,
            'success' => true,
            'message' => $alreadyScanned ? 'تم الفرز مسبقاً' : 'تم الفرز بنجاح'
        ];
    }

    ok([
        'message' => 'تمت معالجة المزامنة.',
        'results' => $results
    ]);

} catch (PDOException $e) {
    fail('حدث خطأ في قاعدة البيانات أثناء المزامنة الجماعية: ' . $e->getMessage(), 500);
}
