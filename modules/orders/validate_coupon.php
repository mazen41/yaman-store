<?php
/**
 * Validate Coupon AJAX Endpoint
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

require_once '../../config/database.php';

header('Content-Type: application/json');

$coupon_code = strtoupper(trim($_POST['coupon_code'] ?? ''));
$subtotal = floatval($_POST['subtotal'] ?? 0);

if (empty($coupon_code)) {
    echo json_encode(['success' => false, 'message' => 'يرجى إدخال كود الكوبون']);
    exit();
}

try {
    // Check if coupons table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'coupons'");
    if ($tableCheck->rowCount() == 0) {
        echo json_encode([
            'success' => false,
            'message' => 'نظام الكوبونات غير مفعل. يرجى تشغيل create_coupons_table.php أولاً'
        ]);
        exit();
    }
    
    // First, check which columns exist
    $columns = $db->query("SHOW COLUMNS FROM coupons")->fetchAll(PDO::FETCH_COLUMN);
    
    // Build query based on available columns
    $code_column = in_array('code', $columns) ? 'code' : 'coupon_code';
    $start_column = in_array('valid_from', $columns) ? 'valid_from' : 'start_date';
    $end_column = in_array('valid_until', $columns) ? 'valid_until' : 'end_date';
    $usage_column = in_array('usage_limit', $columns) ? 'usage_limit' : 'max_uses';
    
    // Validate coupon
    $query = "
        SELECT * FROM coupons 
        WHERE $code_column = ?
        AND is_active = 1 
    ";
    
    // Add date checks if columns exist
    if (in_array($start_column, $columns)) {
        $query .= " AND ($start_column IS NULL OR $start_column <= " . 
                  (in_array('valid_from', $columns) ? "NOW()" : "CURDATE()") . ")";
    }
    
    if (in_array($end_column, $columns)) {
        $query .= " AND ($end_column IS NULL OR $end_column >= " . 
                  (in_array('valid_until', $columns) ? "NOW()" : "CURDATE()") . ")";
    }
    
    // Add usage limit check if column exists
    if (in_array($usage_column, $columns) && in_array('used_count', $columns)) {
        $query .= " AND ($usage_column IS NULL OR used_count < $usage_column)";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute([$coupon_code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$coupon) {
        echo json_encode([
            'success' => false, 
            'message' => 'الكوبون غير صحيح أو منتهي الصلاحية'
        ]);
        exit();
    }
    
    // Get values with fallback for different column names
    $min_order = $coupon['min_order_amount'] ?? $coupon['min_purchase'] ?? 0;
    $discount_type = $coupon['discount_type'] ?? 'percentage';
    $discount_value = $coupon['discount_value'] ?? $coupon['discount_amount'] ?? 0;
    $max_discount = $coupon['max_discount_amount'] ?? $coupon['max_discount'] ?? null;
    $code = $coupon['code'] ?? $coupon['coupon_code'];
    $description = $coupon['description'] ?? $coupon['coupon_name'] ?? '';
    
    // Check minimum order amount
    if ($subtotal < $min_order) {
        echo json_encode([
            'success' => false,
            'message' => 'الحد الأدنى للطلب لاستخدام هذا الكوبون هو ' . number_format($min_order, 2) . ' ريال'
        ]);
        exit();
    }
    
    // Calculate discount
    $discount_amount = 0;
    if ($discount_type === 'percentage' || $discount_type === 'percent') {
        $discount_amount = round($subtotal * ($discount_value / 100), 2);
        // Apply max discount if set
        if ($max_discount && $discount_amount > $max_discount) {
            $discount_amount = $max_discount;
        }
    } else {
        $discount_amount = min($discount_value, $subtotal);
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'تم تطبيق الكوبون بنجاح',
        'coupon' => [
            'code' => $code,
            'type' => $discount_type,
            'value' => $discount_value,
            'discount_amount' => $discount_amount,
            'description' => $description,
            'min_order' => $min_order,
            'max_discount' => $max_discount
        ]
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log('Coupon validation error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في التحقق من الكوبون: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Coupon validation exception: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'خطأ: ' . $e->getMessage()
    ]);
}
?>
