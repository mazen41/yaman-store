<?php
/**
 * API Endpoint: Add Multiple Baskets to Group (Bulk Operation)
 * Path: /modules/purchases/groups/api/add_baskets_bulk.php
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../../../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $group_id = intval($input['group_id'] ?? 0);
    $basket_ids = $input['basket_ids'] ?? [];
    
    if (!$group_id) {
        throw new Exception('معرف المجموعة مطلوب');
    }
    
    if (empty($basket_ids) || !is_array($basket_ids)) {
        throw new Exception('يرجى اختيار سلة واحدة على الأقل');
    }
    
    // Validate all basket IDs are integers
    $basket_ids = array_map('intval', $basket_ids);
    $basket_ids = array_filter($basket_ids, function($id) { return $id > 0; });
    
    if (empty($basket_ids)) {
        throw new Exception('معرفات السلال غير صالحة');
    }
    
    $db->beginTransaction();
    
    $success_count = 0;
    $failed_count = 0;
    $errors = [];
    
    foreach ($basket_ids as $basket_id) {
        try {
            // Check if basket exists
            $check_stmt = $db->prepare("SELECT id FROM purchase_baskets WHERE id = ?");
            $check_stmt->execute([$basket_id]);
            
            if (!$check_stmt->fetch()) {
                $errors[] = "السلة #{$basket_id} غير موجودة";
                $failed_count++;
                continue;
            }
            
            // Update basket to assign it to the group
            $update_stmt = $db->prepare("
                UPDATE purchase_baskets 
                SET purchase_group_id = ? 
                WHERE id = ?
            ");
            
            if ($update_stmt->execute([$group_id, $basket_id])) {
                $success_count++;
            } else {
                $errors[] = "فشل تحديث السلة #{$basket_id}";
                $failed_count++;
            }
            
        } catch (Exception $e) {
            $errors[] = "خطأ في السلة #{$basket_id}: " . $e->getMessage();
            $failed_count++;
        }
    }
    
    $db->commit();
    
    // Build response message
    $message = "تم إضافة {$success_count} سلة بنجاح";
    
    if ($failed_count > 0) {
        $message .= " ({$failed_count} فشلت)";
        if (!empty($errors)) {
            $message .= ": " . implode(', ', array_slice($errors, 0, 3));
        }
    }
    
    echo json_encode([
        'success' => $success_count > 0,
        'message' => $message,
        'details' => [
            'success_count' => $success_count,
            'failed_count' => $failed_count,
            'errors' => $errors
        ]
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
?>
