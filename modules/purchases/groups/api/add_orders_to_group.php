<?php
/**
 * API Endpoint: Add MULTIPLE Customer Orders to a Purchase Group
 * Path: /modules/purchases/groups/api/add_orders_to_group.php
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
    $order_ids = $input['order_ids'] ?? [];
    
    // Validation
    if (!$group_id) {
        throw new Exception('معرف المجموعة مفقود');
    }
    if (!is_array($order_ids) || empty($order_ids)) {
        throw new Exception('قائمة الطلبات المحددة فارغة');
    }
    
    // Sanitize all IDs to be integers
    $order_ids = array_map('intval', $order_ids);

    $db->beginTransaction();

    // Prepare a statement with placeholders for the IN clause
    // This is the efficient way to update multiple rows
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    
    $stmt = $db->prepare(
        "UPDATE customer_orders SET purchase_group_id = ? WHERE id IN ($placeholders)"
    );
    
    // Bind the group_id first, then all the order_ids
    $params = array_merge([$group_id], $order_ids);
    $stmt->execute($params);

    $affected_rows = $stmt->rowCount();

    if ($affected_rows > 0) {
        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'تم إضافة ' . $affected_rows . ' طلب للمجموعة بنجاح'
        ]);
    } else {
        $db->rollBack();
        // This could happen if the orders were already assigned in another window
        throw new Exception('فشل تحديث الطلبات. قد تكون الطلبات غير موجودة أو تم إضافتها بالفعل.');
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