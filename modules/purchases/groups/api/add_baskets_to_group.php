<?php
/**
 * API Endpoint: Add Multiple Baskets to Purchase Group
 * Path: /modules/purchases/groups/api/add_baskets_to_group.php
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
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('بيانات JSON غير صالحة');
    }
    
    $group_id = intval($input['group_id'] ?? 0);
    $basket_ids = $input['basket_ids'] ?? [];
    
    if (!$group_id) {
        throw new Exception('معرف المجموعة مفقود');
    }
    
    if (empty($basket_ids) || !is_array($basket_ids)) {
        throw new Exception('يجب اختيار سلة واحدة على الأقل');
    }
    
    // Verify group exists
    $group_check = $db->prepare("SELECT id FROM purchase_groups WHERE id = ?");
    $group_check->execute([$group_id]);
    if (!$group_check->fetch()) {
        throw new Exception('المجموعة غير موجودة');
    }
    
    $db->beginTransaction();
    
    $success_count = 0;
    $errors = [];
    
    foreach ($basket_ids as $basket_id) {
        $basket_id = intval($basket_id);
        
        if (!$basket_id) {
            continue;
        }
        
        // Verify basket exists and is not already assigned
        $basket_check = $db->prepare("SELECT id, purchase_group_id FROM purchase_baskets WHERE id = ?");
        $basket_check->execute([$basket_id]);
        $basket = $basket_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$basket) {
            $errors[] = "السلة #{$basket_id} غير موجودة";
            continue;
        }
        
        if ($basket['purchase_group_id'] && $basket['purchase_group_id'] != $group_id) {
            $errors[] = "السلة #{$basket_id} مرتبطة بمجموعة أخرى";
            continue;
        }
        
        // Update basket to link with group
        $update_stmt = $db->prepare("UPDATE purchase_baskets SET purchase_group_id = ? WHERE id = ?");
        $update_stmt->execute([$group_id, $basket_id]);
        
        $success_count++;
    }
    
    $db->commit();
    
    $message = "تم إضافة {$success_count} سلة للمجموعة بنجاح";
    if (!empty($errors)) {
        $message .= ". أخطاء: " . implode(", ", $errors);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'added_count' => $success_count,
        'errors' => $errors
    ]);
    
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
