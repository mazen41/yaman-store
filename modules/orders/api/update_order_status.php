<?php
/**
 * API Endpoint: Update Customer Order Status
 * Path: /modules/orders/api/update_order_status.php
 * FINAL CORRECTION:
 * - Inserts the Arabic status name (status_name_ar) into the history table's `status` column.
 * - Creates the readable note in the `notes` column.
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

$user_id = $_SESSION['user_id'];
if (!hasPermission($user_id, 'orders', 'edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لتعديل الطلبات.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// --- 2. INPUT & VALIDATION ---
try {
    $input = json_decode(file_get_contents('php://input'), true);

    $order_id = intval($input['order_id'] ?? 0);
    $new_status_key = trim($input['status'] ?? '');

    if (empty($order_id) || empty($new_status_key)) {
        throw new Exception('معرف الطلب أو الحالة مفقود.');
    }

    // Validate the new status key and get its Arabic name at the same time
    $stmt = $db->prepare("SELECT status_name_ar FROM customer_order_statuses WHERE status_key = ?");
    $stmt->execute([$new_status_key]);
    $new_status_name_ar = $stmt->fetchColumn();
    if (!$new_status_name_ar) {
        throw new Exception('الحالة المحددة غير صالحة.');
    }

    // --- 3. DATABASE TRANSACTION ---
    $db->beginTransaction();

    // Get the current (old) status key from the main order table
    $old_status_stmt = $db->prepare("SELECT status FROM customer_orders WHERE id = ?");
    $old_status_stmt->execute([$order_id]);
    $old_status_key = $old_status_stmt->fetchColumn();

    // Proceed only if the status is actually different
    if ($old_status_key !== $new_status_key) {
        
        // Step 1: Update the main `customer_orders` table.
        // THIS MUST USE THE `status_key` FOR THE APPLICATION TO WORK CORRECTLY.
        $update_stmt = $db->prepare("UPDATE customer_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        if (!$update_stmt->execute([$new_status_key, $order_id])) {
            throw new Exception('فشل تحديث حالة الطلب.');
        }

        // Step 2: Get the Arabic name for the OLD status to build our note
        $old_status_name_ar = $old_status_key; // Default to the key if not found
        if (!empty($old_status_key)) {
            $old_name_stmt = $db->prepare("SELECT status_name_ar FROM customer_order_statuses WHERE status_key = ?");
            $old_name_stmt->execute([$old_status_key]);
            $fetched_old_name = $old_name_stmt->fetchColumn();
            if ($fetched_old_name) {
                $old_status_name_ar = $fetched_old_name;
            }
        } else {
            $old_status_name_ar = 'لا يوجد'; // Text for when there was no previous status
        }
        
        // Step 3: Create the human-readable note in Arabic
        $note_text = "تم تغيير الحالة من '{$old_status_name_ar}' إلى '{$new_status_name_ar}'.";

        // Step 4: Insert the record into the history table
        $history_stmt = $db->prepare(
            "INSERT INTO order_state_history (order_id, status, changed_by_id, notes, created_at) 
             VALUES (?, ?, ?, ?, NOW())"
        );
        
        // *** THE ONLY CHANGE IS IN THIS LINE ***
        // We now pass the Arabic name ($new_status_name_ar) to be inserted into the history table's 'status' column.
        if (!$history_stmt->execute([$order_id, $new_status_name_ar, $user_id, $note_text])) {
            throw new Exception('فشل تسجيل سجل الحالة.');
        }
    }

    // Commit the transaction
    $db->commit();

    echo json_encode(['success' => true, 'message' => 'تم تحديث حالة الطلب بنجاح.']);

} catch (Exception $e) {
    // If anything goes wrong, roll back the transaction
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>