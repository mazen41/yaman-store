<?php
/**
 * API Endpoint: Add Customer Order to Purchase Group (CORRECTED LOGIC)
 * Path: /modules/purchases/groups/api/add_order.php
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة غير مسموحة']);
    exit();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $group_id = intval($input['group_id'] ?? 0);
    $order_id = intval($input['order_id'] ?? 0);
    
    if (!$group_id || !$order_id) {
        throw new Exception('معرف المجموعة أو الطلب مفقود');
    }
    
    // --- START OF FIX ---
    // The old logic that checked for a basket_id has been removed.
    // This is the correct logic: Directly update the customer_orders table.

    $db->beginTransaction();

    $stmt = $db->prepare(
        "UPDATE customer_orders SET purchase_group_id = ? WHERE id = ?"
    );
    
    $stmt->execute([$group_id, $order_id]);

    // Check if the update was successful
    if ($stmt->rowCount() > 0) {
        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'تم إضافة الطلب للمجموعة بنجاح'
        ]);
    } else {
        $db->rollBack();
        throw new Exception('فشل تحديث الطلب. قد يكون الطلب غير موجود أو تم إضافته بالفعل.');
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}