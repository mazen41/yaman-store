<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

// Get purchase order ID
$order_id = intval($_GET['id'] ?? 0);

if (!$order_id) {
    header('Location: index.php');
    exit();
}

// Fetch purchase order details
$stmt = $db->prepare("
    SELECT po.*, s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
           pg.group_name,
           u.full_name as created_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_groups pg ON po.purchase_group_id = pg.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: index.php');
    exit();
}

// Fetch purchase order items
$item_columns = $db->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
$has_product_name = in_array('product_name', $item_columns);

if ($has_product_name) {
    $items_stmt = $db->prepare("
        SELECT poi.*, 
               COALESCE(poi.product_name, p.name) as product_name,
               p.product_code, p.unit
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ");
} else {
    $items_stmt = $db->prepare("
        SELECT poi.*, p.name as product_name, p.product_code, p.unit
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ?
        ORDER BY poi.id
    ");
}
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="purchase_order_' . $order['order_number'] . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write order header
fputcsv($output, ['طلب شراء رقم', $order['order_number']]);
fputcsv($output, ['المورد', $order['supplier_name']]);
fputcsv($output, ['تاريخ الطلب', date('Y-m-d', strtotime($order['created_at']))]);
fputcsv($output, ['الحالة', $order['status']]);
fputcsv($output, []);

// Write items header
fputcsv($output, ['#', 'اسم المنتج', 'الكمية', 'سعر الوحدة', 'الإجمالي']);

// Write items
$row_num = 1;
foreach ($items as $item) {
    fputcsv($output, [
        $row_num++,
        $item['product_name'] ?? 'غير محدد',
        $item['quantity'],
        number_format($item['unit_price'], 0, '', ''),
        number_format($item['total_price'], 0, '', '')
    ]);
}

// Write totals
fputcsv($output, []);
fputcsv($output, ['المجموع الفرعي', '', '', '', number_format($order['subtotal'], 0, '', '')]);
fputcsv($output, ['الضريبة (15%)', '', '', '', number_format($order['tax_amount'], 0, '', '')]);
fputcsv($output, ['المجموع الإجمالي', '', '', '', number_format($order['total_amount'], 0, '', '')]);

fclose($output);
exit();
?>
