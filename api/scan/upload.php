<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['scans']) || !is_array($input['scans'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare("INSERT INTO sorting_scan_logs (scan_code, scanned_at, source) VALUES (:scan_code, :scanned_at, 'android_native')");

    foreach ($input['scans'] as $scan) {
        $code = trim((string)($scan['scannedData'] ?? ''));
        $ts = (int)($scan['timestamp'] ?? 0);
        if ($code === '' || $ts <= 0) {
            throw new RuntimeException('Invalid scan row');
        }
        $stmt->execute([
            ':scan_code' => $code,
            ':scanned_at' => date('Y-m-d H:i:s', (int) floor($ts / 1000)),
        ]);
    }

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Scans synced']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sync failed: ' . $e->getMessage()]);
}
