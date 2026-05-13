<?php
/**
 * Print Basket Invoice
 * طباعة فاتورة السلة
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$basket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($basket_id === 0) {
    die('معرف السلة غير صحيح');
}

// Get basket details
try {
    $basket_columns = $db->query("SHOW COLUMNS FROM purchase_baskets")->fetchAll(PDO::FETCH_COLUMN);
    
    $select_parts = ['id', 'supplier_id', 'purchase_date', 'notes', 'status', 'created_by', 'created_at'];
    
    if (in_array('basket_code', $basket_columns)) $select_parts[] = 'basket_code';
    if (in_array('basket_name', $basket_columns)) $select_parts[] = 'basket_name';
    
    $select_sql = implode(', ', $select_parts);
    
    $stmt = $db->prepare("SELECT $select_sql FROM purchase_baskets WHERE id = ?");
    $stmt->execute([$basket_id]);
    $basket = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$basket) {
        die('السلة غير موجودة');
    }
    
    // Get basket items with order details
    $items_stmt = $db->prepare("
        SELECT 
            bi.*,
            o.order_number,
            c.name as customer_name,
            c.customer_code,
            c.mobile_number
        FROM basket_items bi
        LEFT JOIN customer_orders o ON bi.order_id = o.id
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE bi.basket_id = ?
        ORDER BY bi.added_at
    ");
    $items_stmt->execute([$basket_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get supplier name
    $supplier_name = 'غير محدد';
    if (!empty($basket['supplier_id'])) {
        $sup_stmt = $db->prepare("SELECT name FROM suppliers WHERE id = ?");
        $sup_stmt->execute([$basket['supplier_id']]);
        $supplier = $sup_stmt->fetch(PDO::FETCH_ASSOC);
        $supplier_name = $supplier ? $supplier['name'] : 'غير محدد';
    }
    
    // Calculate totals
    $total = 0;
    foreach ($items as $item) {
        $total += $item['total_price'];
    }
    
} catch (PDOException $e) {
    die('خطأ في قاعدة البيانات: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة سلة الشراء - <?php echo $basket['basket_code'] ?? 'BASKET-' . $basket_id; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            padding: 20px;
            background: white;
        }
        
        .invoice {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border: 2px solid #333;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 14px;
            color: #666;
        }
        
        .invoice-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-section {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
        }
        
        .info-section h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 10px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 5px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 14px;
        }
        
        .info-label {
            font-weight: bold;
            color: #666;
        }
        
        .info-value {
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: right;
            font-size: 14px;
            border: 1px solid #667eea;
        }
        
        td {
            padding: 10px;
            border: 1px solid #ddd;
            font-size: 13px;
        }
        
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .totals {
            background: #f0f0f0;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 16px;
        }
        
        .total-row.final {
            border-top: 3px solid #333;
            margin-top: 10px;
            padding-top: 15px;
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }
        
        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 2px solid #333;
            color: #666;
            font-size: 12px;
        }
        
        .notes {
            background: #fff9e6;
            padding: 15px;
            border-right: 4px solid #f59e0b;
            margin-bottom: 20px;
        }
        
        .notes h4 {
            color: #92400e;
            margin-bottom: 8px;
        }
        
        @media print {
            body {
                padding: 0;
            }
            
            .invoice {
                border: none;
                padding: 20px;
            }
            
            .no-print {
                display: none;
            }
        }
        
        .print-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .print-btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">
    🖨️ طباعة
</button>

<div class="invoice">
    <!-- Header -->
    <div class="header">
        <h1>فاتورة سلة الشراء</h1>
        <p>نظام إدارة يمان</p>
    </div>
    
    <!-- Invoice Info -->
    <div class="invoice-info">
        <div class="info-section">
            <h3>معلومات السلة</h3>
            <div class="info-row">
                <span class="info-label">رقم السلة:</span>
                <span class="info-value"><?php echo $basket['basket_code'] ?? 'BASKET-' . $basket_id; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">اسم السلة:</span>
                <span class="info-value"><?php echo $basket['basket_name'] ?? '-'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">تاريخ الشراء:</span>
                <span class="info-value"><?php echo date('Y-m-d', strtotime($basket['purchase_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">الحالة:</span>
                <span class="info-value">
                    <?php
                    switch($basket['status']) {
                        case 'draft': echo 'مسودة'; break;
                        case 'locked': echo 'مقفلة'; break;
                        case 'completed': echo 'مكتملة'; break;
                        default: echo $basket['status'];
                    }
                    ?>
                </span>
            </div>
        </div>
        
        <div class="info-section">
            <h3>معلومات إضافية</h3>
            <div class="info-row">
                <span class="info-label">المورد:</span>
                <span class="info-value"><?php echo $supplier_name; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">عدد الطلبات:</span>
                <span class="info-value"><?php echo count($items); ?> طلب</span>
            </div>
            <div class="info-row">
                <span class="info-label">تاريخ الإنشاء:</span>
                <span class="info-value"><?php echo date('Y-m-d H:i', strtotime($basket['created_at'])); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Notes -->
    <?php if (!empty($basket['notes'])): ?>
    <div class="notes">
        <h4>📝 ملاحظات:</h4>
        <p><?php echo nl2br(htmlspecialchars($basket['notes'])); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Items Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>رقم الطلب</th>
                <th>العميل</th>
                <th>كود العميل</th>
                <th>رقم الجوال</th>
                <th style="width: 120px;">المبلغ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
            <tr>
                <td colspan="6" style="text-align: center; padding: 30px;">
                    لا توجد طلبات في هذه السلة
                </td>
            </tr>
            <?php else: ?>
                <?php $num = 1; foreach ($items as $item): ?>
                <tr>
                    <td style="text-align: center;"><?php echo $num++; ?></td>
                    <td><strong><?php echo htmlspecialchars($item['order_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($item['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['customer_code']); ?></td>
                    <td><?php echo htmlspecialchars($item['mobile_number']); ?></td>
                    <td style="text-align: left; font-family: monospace;">
                        <strong><?php echo number_format($item['total_price'], 0, '', ''); ?></strong> ر.ي
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Totals -->
    <div class="totals">
        <div class="total-row final">
            <span>الإجمالي:</span>
            <strong><?php echo number_format($total, 0, '', ''); ?> ر.ي</strong>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <p>تم الطباعة بتاريخ: <?php echo date('Y-m-d H:i:s'); ?></p>
        <p>© 2025 نظام إدارة يمان - جميع الحقوق محفوظة</p>
    </div>
</div>

<script>
// Auto print on load (optional)
// window.onload = function() { window.print(); }
</script>

</body>
</html>
