<?php
/**
 * API Endpoint: Add Basket to Purchase Group
 * Path: /modules/purchases/groups/api/add_basket.php
 */

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../../../config/database.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة غير مسموحة']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $group_id = intval($input['group_id'] ?? 0);
    $basket_id = intval($input['basket_id'] ?? 0);
    
    if (!$group_id || !$basket_id) {
        throw new Exception('معرف المجموعة أو السلة مفقود');
    }
    
    // Verify group exists
    $group_check = $db->prepare("SELECT id FROM purchase_groups WHERE id = ?");
    $group_check->execute([$group_id]);
    if (!$group_check->fetch()) {
        throw new Exception('المجموعة غير موجودة');
    }
    
    // Verify basket exists and is not already assigned
    $basket_check = $db->prepare("SELECT id, purchase_group_id FROM purchase_baskets WHERE id = ?");
    $basket_check->execute([$basket_id]);
    $basket = $basket_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$basket) {
        throw new Exception('السلة غير موجودة');
    }
    
    if ($basket['purchase_group_id'] && $basket['purchase_group_id'] != $group_id) {
        throw new Exception('السلة مرتبطة بمجموعة أخرى');
    }
    
    // Update basket to link with group
    $update_stmt = $db->prepare("UPDATE purchase_baskets SET purchase_group_id = ? WHERE id = ?");
    $update_stmt->execute([$group_id, $basket_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم إضافة السلة للمجموعة بنجاح'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
