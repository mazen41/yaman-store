<?php
/**
 * Email template for new order notification
 * 
 * Available variables:
 * $order - Order details array
 * $customer - Customer details array
 * $items - Order items array
 */
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طلب جديد - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #C7A46D;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .content {
            padding: 20px;
        }
        .footer {
            background-color: #f3f4f6;
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table th, table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: right;
        }
        table th {
            background-color: #f9fafb;
        }
        .total-row {
            font-weight: bold;
            background-color: #f3f4f6;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-new {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .button {
            display: inline-block;
            background-color: #C7A46D;
            color: #ffffff;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 15px;
        }
        .info-section {
            background-color: #f3f4f6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-label {
            font-weight: bold;
            color: #4b5563;
            margin-bottom: 5px;
        }
        .info-value {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>طلب جديد</h1>
            <p>رقم الطلب: <?php echo htmlspecialchars($order['order_number']); ?></p>
        </div>
        
        <div class="content">
            <p>مرحباً <?php echo htmlspecialchars($customer['name']); ?>،</p>
            
            <p>نشكرك على طلبك. تم استلام طلبك بنجاح وهو الآن قيد المراجعة.</p>
            
            <div class="info-section">
                <div class="info-label">حالة الطلب:</div>
                <div class="info-value"><span class="badge badge-new">جديد</span></div>
                
                <div class="info-label">تاريخ الطلب:</div>
                <div class="info-value"><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></div>
                
                <?php if (!empty($order['expected_delivery_date'])): ?>
                <div class="info-label">تاريخ التسليم المتوقع:</div>
                <div class="info-value"><?php echo date('Y-m-d', strtotime($order['expected_delivery_date'])); ?></div>
                <?php endif; ?>
                
                <div class="info-label">طريقة الدفع:</div>
                <div class="info-value">
                    <?php 
                    switch ($order['payment_method']) {
                        case 'cash':
                            echo 'نقداً عند الاستلام';
                            break;
                        case 'bank_transfer':
                            echo 'تحويل بنكي';
                            break;
                        case 'credit_card':
                            echo 'بطاقة ائتمان';
                            break;
                        case 'mada':
                            echo 'مدى';
                            break;
                        default:
                            echo htmlspecialchars($order['payment_method'] ?? 'غير محدد');
                    }
                    ?>
                </div>
                
                <div class="info-label">طريقة الشحن:</div>
                <div class="info-value">
                    <?php 
                    switch ($order['shipping_method']) {
                        case 'delivery':
                            echo 'توصيل';
                            break;
                        case 'pickup':
                            echo 'استلام من المتجر';
                            break;
                        default:
                            echo htmlspecialchars($order['shipping_method'] ?? 'غير محدد');
                    }
                    ?>
                </div>
            </div>
            
            <h2>تفاصيل الطلب</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>السعر</th>
                        <th>الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo $item['quantity']; ?></td>
                        <td><?php echo number_format($item['unit_price'], 0, '', ''); ?> ريال</td>
                        <td><?php echo number_format($item['total_price'], 0, '', ''); ?> ريال</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align: left;">المجموع:</td>
                        <td><?php echo number_format($order['total_amount'], 0, '', ''); ?> ريال</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="text-align: left;">تكلفة الشحن:</td>
                        <td><?php echo number_format($order['shipping_cost'], 0, '', ''); ?> ريال</td>
                    </tr>
                    <tr class="total-row">
                        <td colspan="4" style="text-align: left;">المجموع النهائي:</td>
                        <td><?php echo number_format($order['final_amount'], 0, '', ''); ?> ريال</td>
                    </tr>
                </tfoot>
            </table>
            
            <?php if (!empty($order['notes'])): ?>
            <div class="info-section">
                <div class="info-label">ملاحظات:</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
            </div>
            <?php endif; ?>
            
            <p>سنقوم بإعلامك بأي تحديثات على طلبك. يمكنك دائماً التواصل معنا إذا كان لديك أي استفسارات.</p>
            
            <p>شكراً لك على ثقتك بنا.</p>
            
            <a href="<?php echo htmlspecialchars($tracking_url ?? '#'); ?>" class="button">تتبع طلبك</a>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> نظام يمان. جميع الحقوق محفوظة.</p>
            <p>هذا البريد الإلكتروني تم إرساله تلقائياً، يرجى عدم الرد عليه.</p>
        </div>
    </div>
</body>
</html>
