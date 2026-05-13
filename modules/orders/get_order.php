<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';

$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit();
}

try {
    // 1. Get order details
    $order_stmt = $db->prepare("
        SELECT 
            id,
            order_number,
            customer_id,
            COALESCE(discount_amount, 0) as discount_fixed,
            COALESCE(shipping_cost, 0) as shipping_cost,
            COALESCE(notes, '') as notes,
            COALESCE(subtotal_amount, 0) as subtotal_amount,
            COALESCE(final_amount, 0) as final_amount
        FROM customer_orders
        WHERE id = ?
    ");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit();
    }
    
    // 2. Get order items - استخدام product_name مباشرة
    $items_stmt = $db->prepare("
        SELECT 
            oi.id,
            COALESCE(oi.product_id, 0) as product_id,
            COALESCE(oi.product_name, 'منتج غير معروف') as name,
            oi.quantity as qty,
            COALESCE(oi.unit_price, 0) as unit_price,
            (oi.quantity * COALESCE(oi.unit_price, 0)) as line_total
        FROM order_items oi
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to proper types
    foreach ($items as &$item) {
        $item['id'] = intval($item['id']);
        $item['product_id'] = intval($item['product_id']);
        $item['qty'] = intval($item['qty']);
        $item['unit_price'] = floatval($item['unit_price']);
        $item['line_total'] = floatval($item['line_total']);
    }
    
    // 3. Get damaged items
    $damages_stmt = $db->prepare("
        SELECT 
            od.id,
            od.order_item_id,
            od.product_id,
            od.qty_damaged,
            od.deduction_type,
            COALESCE(od.deduction_value, 0) as deduction_value,
            od.reason,
            COALESCE(od.note, '') as note,
            COALESCE(oi.unit_price, 0) as unit_price
        FROM order_damages od
        LEFT JOIN order_items oi ON od.order_item_id = oi.id
        WHERE od.order_id = ?
        ORDER BY od.id
    ");
    $damages_stmt->execute([$order_id]);
    $damaged_items = $damages_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Calculate summary
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += floatval($item['line_total']);
    }
    
    $damage_deductions = 0;
    foreach ($damaged_items as &$damage) {
        $damage['id'] = intval($damage['id']);
        $damage['order_item_id'] = intval($damage['order_item_id']);
        $damage['product_id'] = intval($damage['product_id']);
        $damage['qty_damaged'] = intval($damage['qty_damaged']);
        $damage['deduction_value'] = floatval($damage['deduction_value']);
        
        $unit_price = floatval($damage['unit_price']);
        $qty_damaged = intval($damage['qty_damaged']);
        
        switch ($damage['deduction_type']) {
            case 'auto_unit_price':
                $deduction = $qty_damaged * $unit_price;
                break;
            case 'amount':
                $deduction = floatval($damage['deduction_value']);
                break;
            case 'percent':
                $deduction = ($qty_damaged * $unit_price) * (floatval($damage['deduction_value']) / 100);
                break;
            default:
                $deduction = 0;
        }
        
        $damage['calculated_deduction'] = round($deduction, 2);
        $damage_deductions += $deduction;
    }
    
    $discount_fixed = floatval($order['discount_fixed']);
    $shipping_cost = floatval($order['shipping_cost']);
    $grand_total = max(0, $subtotal - $discount_fixed - $damage_deductions + $shipping_cost);
    
    // 5. Return response
    $response = [
        'success' => true,
        'order' => [
            'id' => intval($order['id']),
            'order_number' => $order['order_number'],
            'customer_id' => intval($order['customer_id']),
            'discount_fixed' => round($discount_fixed, 2),
            'shipping_cost' => round($shipping_cost, 2),
            'notes' => $order['notes']
        ],
        'items' => $items,
        'damaged_items' => $damaged_items,
        'summary' => [
            'subtotal' => round($subtotal, 2),
            'damage_deductions' => round($damage_deductions, 2),
            'discount_total' => round($discount_fixed, 2),
            'shipping_cost' => round($shipping_cost, 2),
            'grand_total' => round($grand_total, 2)
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
