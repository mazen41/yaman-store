<?php
/**
 * API Endpoint: Update Basket Status
 * Version: 3.0 - Validates against the statuses table.
 */
session_start();
header('Content-Type: application/json');

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';

// --- 1. SECURITY & PERMISSIONS ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// --- 2. INPUT & VALIDATION ---
try {
    $input = json_decode(file_get_contents('php://input'), true);

    $basket_id = intval($input['basket_id'] ?? 0);
    $status_key = trim($input['status'] ?? '');

    if (empty($basket_id) || empty($status_key)) {
        throw new Exception('معرف السلة أو الحالة مفقود.');
    }

    // Validate that the status key exists in our new table
    $stmt = $db->prepare("SELECT id FROM purchase_basket_statuses WHERE status_key = ?");
    $stmt->execute([$status_key]);
    if (!$stmt->fetch()) {
        throw new Exception('الحالة المحددة غير صالحة.');
    }

    // --- 3. DATABASE UPDATE ---
    $update_stmt = $db->prepare("UPDATE purchase_baskets SET status = ?, updated_at = NOW() WHERE id = ?");
    $success = $update_stmt->execute([$status_key, $basket_id]);

    if (!$success) {
        throw new Exception('فشل تحديث قاعدة البيانات.');
    }

    echo json_encode(['success' => true, 'message' => 'تم تحديث حالة السلة بنجاح.']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>