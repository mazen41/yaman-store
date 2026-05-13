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
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة طلب شراء - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <style>
        @media print {
            .no-print { display: none; }
            @page { margin: 1cm; }
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #1e40af;
            margin: 0 0 10px 0;
        }
        
        .order-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-box {
            border: 2px solid #e5e7eb;
            padding: 15px;
            border-radius: 8px;
        }
        
        .info-box h3 {
            margin: 0 0 10px 0;
            color: #374151;
            font-size: 16px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        
        .info-label {
            font-weight: 600;
            color: #6b7280;
        }
        
        .info-value {
            color: #111827;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th, td {
            border: 1px solid #d1d5db;
            padding: 12px;
            text-align: right;
        }
        
        th {
            background: #f3f4f6;
            font-weight: 600;
            color: #374151;
        }
        
        tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .totals {
            margin-top: 20px;
            text-align: left;
        }
        
        .totals table {
            width: 300px;
            margin-right: 0;
            margin-left: auto;
        }
        
        .total-row {
            font-weight: bold;
            background: #dbeafe !important;
        }
        
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 12px 24px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .print-btn:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-btn no-print">
        🖨️ طباعة
    </button>
    
    <div class="container">
        <div class="header">
            <h1>طلب شراء</h1>
            <p style="margin: 5px 0; color: #6b7280;">رقم الطلب: <?php echo htmlspecialchars($order['order_number']); ?></p>
            <p style="margin: 5px 0; color: #6b7280;">التاريخ: <?php echo date('Y-m-d', strtotime($order['created_at'])); ?></p>
        </div>
        
        <div class="order-info">
            <div class="info-box">
                <h3>معلومات المورد</h3>
                <div class="info-row">
                    <span class="info-label">اسم المورد:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['supplier_name']); ?></span>
                </div>
                <?php if ($order['contact_person']): ?>
                <div class="info-row">
                    <span class="info-label">جهة الاتصال:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['contact_person']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($order['phone']): ?>
                <div class="info-row">
                    <span class="info-label">الهاتف:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['phone']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <h3>معلومات الطلب</h3>
                <div class="info-row">
                    <span class="info-label">الحالة:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['status']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">أنشئ بواسطة:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['created_by_name']); ?></span>
                </div>
                <?php if ($order['group_name']): ?>
                <div class="info-row">
                    <span class="info-label">المجموعة:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['group_name']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <h3>تفاصيل المنتجات</h3>
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>اسم المنتج</th>
                    <th style="width: 100px;">الكمية</th>
                    <th style="width: 120px;">سعر الوحدة</th>
                    <th style="width: 120px;">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php $row_num = 1; foreach ($items as $item): ?>
                <tr>
                    <td style="text-align: center;"><?php echo $row_num++; ?></td>
                    <td><?php echo htmlspecialchars($item['product_name'] ?? 'غير محدد'); ?></td>
                    <td style="text-align: center;"><?php echo number_format($item['quantity'], 0, '', ''); ?></td>
                    <td style="text-align: left;"><?php echo number_format($item['unit_price'], 0, '', ''); ?> ريال</td>
                    <td style="text-align: left;"><?php echo number_format($item['total_price'], 0, '', ''); ?> ريال</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="totals">
            <table>
                <tr>
                    <td><strong>المجموع الفرعي:</strong></td>
                    <td style="text-align: left;"><?php echo number_format($order['subtotal'], 0, '', ''); ?> ريال</td>
                </tr>
                <tr>
                    <td><strong>الضريبة (15%):</strong></td>
                    <td style="text-align: left;"><?php echo number_format($order['tax_amount'], 0, '', ''); ?> ريال</td>
                </tr>
                <tr class="total-row">
                    <td><strong>المجموع الإجمالي:</strong></td>
                    <td style="text-align: left;"><strong><?php echo number_format($order['total_amount'], 0, '', ''); ?> ريال</strong></td>
                </tr>
            </table>
        </div>
        
        <?php if ($order['notes']): ?>
        <div class="info-box" style="margin-top: 30px;">
            <h3>ملاحظات</h3>
            <p style="margin: 10px 0; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            <p>تم إنشاء هذا المستند بواسطة نظام إدارة المشتريات</p>
            <p>تاريخ الطباعة: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
    
    <script>
        // Auto print on load (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
