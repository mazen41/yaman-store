<?php
session_start();
require_once '../../config/database.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) throw new Exception('Order ID required');

    $customer_name = trim(strip_tags($_POST['customer_name'] ?? ''));
    $customer_phone = trim(strip_tags($_POST['customer_phone'] ?? ''));
    $customer_phone = preg_replace('/[^0-9+\-\s]/', '', $customer_phone);
    $customer_address = trim(strip_tags($_POST['customer_address'] ?? ''));

    $db->beginTransaction();
    
    $stmt = $db->prepare("SELECT customer_id FROM customer_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $customer_id = $stmt->fetchColumn();
    
    if ($customer_id) {
        $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, address = ? WHERE id = ?");
        $stmt->execute([$customer_name, $customer_phone, $customer_address, $customer_id]);
    }

    $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);

    $subtotal = 0;
    $items_saved = 0;

    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $i => $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $product_name = trim(strip_tags($item['product_name'] ?? 'منتج'));
            
            // إذا كان product_id = 0، إنشاء منتج جديد
            if ($product_id == 0 && !empty($product_name)) {
                $stmt = $db->prepare("INSERT INTO products (name, product_code, selling_price, is_active) VALUES (?, ?, ?, 1)");
                $code = 'PROD-' . time() . '-' . rand(100, 999);
                $price = floatval(str_replace([',', ' ', 'ر.س'], '', $item['price'] ?? 0));
                $stmt->execute([$product_name, $code, $price]);
                $product_id = $db->lastInsertId();
            }
            
            if (empty($product_name) && $product_id > 0) {
                $stmt = $db->prepare("SELECT name FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $prod = $stmt->fetch();
                $product_name = $prod ? $prod['name'] : 'منتج #' . $product_id;
            }
            
            $quantity = max(1, intval($item['quantity'] ?? 1));
            $unit_price = floatval(str_replace([',', ' ', 'ر.س'], '', $item['price'] ?? 0));
            $total_price = $quantity * $unit_price;
            
            if ($unit_price > 0) {
                $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $product_id, $product_name, $quantity, $unit_price, $total_price]);
                $subtotal += $total_price;
                $items_saved++;
            }
        }
    }

    if (isset($_POST['new_items']) && is_array($_POST['new_items'])) {
        foreach ($_POST['new_items'] as $i => $item) {
            $product_name = trim(strip_tags($item['product_name'] ?? ''));
            if (empty($product_name)) continue;
            
            // إنشاء منتج جديد
            $quantity = max(1, intval($item['quantity'] ?? 1));
            $unit_price = floatval(str_replace([',', ' ', 'ر.س'], '', $item['price'] ?? 0));
            
            $stmt = $db->prepare("INSERT INTO products (name, product_code, selling_price, is_active) VALUES (?, ?, ?, 1)");
            $code = 'PROD-' . time() . '-' . rand(100, 999);
            $stmt->execute([$product_name, $code, $unit_price]);
            $product_id = $db->lastInsertId();
            
            $total_price = $quantity * $unit_price;
            
            if ($unit_price > 0) {
                $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $product_id, $product_name, $quantity, $unit_price, $total_price]);
                $subtotal += $total_price;
                $items_saved++;
            }
        }
    }

    if ($items_saved == 0) {
        throw new Exception('يجب إضافة منتج واحد على الأقل');
    }

    $damaged_discount = floatval($_POST['damaged_discount'] ?? 0);
    $additional_discount = floatval($_POST['additional_discount'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $final_amount = $subtotal - $damaged_discount - $additional_discount + $tax_amount + $shipping_cost;

    $stmt = $db->prepare("UPDATE customer_orders SET subtotal = ?, discount_amount = ?, additional_discount = ?, tax_amount = ?, shipping_cost = ?, final_amount = ?, total_amount = ?, notes = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$subtotal, $damaged_discount, $additional_discount, $tax_amount, $shipping_cost, $final_amount, $final_amount, $_POST['notes'] ?? '', $order_id]);

    $db->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'تم حفظ التعديلات بنجاح (' . $items_saved . ' منتج)',
        'items_saved' => $items_saved,
        'final_amount' => $final_amount
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
