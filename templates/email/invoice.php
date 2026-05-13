<?php
/**
 * Email template for invoice
 * 
 * Available variables:
 * $invoice - Invoice details array
 * $customer - Customer details array
 * $items - Invoice items array
 * $company - Company details array
 */
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة رقم <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
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
            max-width: 800px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #4f46e5;
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
        .badge-draft {
            background-color: #f3f4f6;
            color: #374151;
        }
        .badge-sent {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .badge-paid {
            background-color: #d1fae5;
            color: #065f46;
        }
        .badge-cancelled {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        .button {
            display: inline-block;
            background-color: #4f46e5;
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
        .invoice-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .company-info {
            text-align: right;
        }
        .invoice-info {
            text-align: left;
        }
        .logo {
            max-height: 80px;
            margin-bottom: 10px;
        }
        .divider {
            height: 1px;
            background-color: #e5e7eb;
            margin: 20px 0;
        }
        .payment-info {
            background-color: #eff6ff;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>فاتورة</h1>
            <p>رقم الفاتورة: <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
        </div>
        
        <div class="content">
            <div class="invoice-header">
                <div class="company-info">
                    <?php if (!empty($company['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($company['logo']); ?>" alt="شعار الشركة" class="logo">
                    <?php endif; ?>
                    <h2><?php echo htmlspecialchars($company['name'] ?? 'نظام يمان'); ?></h2>
                    <p><?php echo htmlspecialchars($company['address'] ?? ''); ?></p>
                    <p>هاتف: <?php echo htmlspecialchars($company['phone'] ?? ''); ?></p>
                    <p>البريد الإلكتروني: <?php echo htmlspecialchars($company['email'] ?? ''); ?></p>
                </div>
                
                <div class="invoice-info">
                    <div class="info-label">تاريخ الفاتورة:</div>
                    <div class="info-value"><?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?></div>
                    
                    <div class="info-label">حالة الفاتورة:</div>
                    <div class="info-value">
                        <?php
                        $status_class = '';
                        $status_text = '';
                        switch ($invoice['status']) {
                            case 'draft':
                                $status_class = 'badge-draft';
                                $status_text = 'مسودة';
                                break;
                            case 'sent':
                                $status_class = 'badge-sent';
                                $status_text = 'مرسلة';
                                break;
                            case 'paid':
                                $status_class = 'badge-paid';
                                $status_text = 'مدفوعة';
                                break;
                            case 'cancelled':
                                $status_class = 'badge-cancelled';
                                $status_text = 'ملغاة';
                                break;
                        }
                        ?>
                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </div>
                    
                    <?php if (!empty($invoice['due_date'])): ?>
                    <div class="info-label">تاريخ الاستحقاق:</div>
                    <div class="info-value"><?php echo date('Y-m-d', strtotime($invoice['due_date'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="divider"></div>
            
            <div class="info-section">
                <h3>معلومات العميل</h3>
                <div class="info-label">الاسم:</div>
                <div class="info-value"><?php echo htmlspecialchars($customer['name']); ?></div>
                
                <?php if (!empty($customer['customer_code'])): ?>
                <div class="info-label">رقم العميل:</div>
                <div class="info-value"><?php echo htmlspecialchars($customer['customer_code']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($customer['mobile_number'])): ?>
                <div class="info-label">رقم الجوال:</div>
                <div class="info-value"><?php echo htmlspecialchars($customer['mobile_number']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($customer['email'])): ?>
                <div class="info-label">البريد الإلكتروني:</div>
                <div class="info-value"><?php echo htmlspecialchars($customer['email']); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($customer['address'])): ?>
                <div class="info-label">العنوان:</div>
                <div class="info-value"><?php echo htmlspecialchars($customer['address']); ?></div>
                <?php endif; ?>
            </div>
            
            <h2>تفاصيل الفاتورة</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الوصف</th>
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
                        <td><?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ريال</td>
                    </tr>
                    
                    <?php if ($invoice['discount_amount'] > 0 || $invoice['discount_percentage'] > 0): ?>
                    <tr>
                        <td colspan="4" style="text-align: left;">الخصم:</td>
                        <td>
                            <?php if ($invoice['discount_percentage'] > 0): ?>
                                <?php echo number_format($invoice['discount_percentage'], 0, '', ''); ?>%
                                (<?php echo number_format($invoice['discount_amount'], 0, '', ''); ?> ريال)
                            <?php else: ?>
                                <?php echo number_format($invoice['discount_amount'], 0, '', ''); ?> ريال
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr class="total-row">
                        <td colspan="4" style="text-align: left;">المجموع النهائي:</td>
                        <td><?php echo number_format($invoice['final_amount'], 0, '', ''); ?> ريال</td>
                    </tr>
                </tfoot>
            </table>
            
            <?php if (!empty($invoice['notes'])): ?>
            <div class="info-section">
                <div class="info-label">ملاحظات:</div>
                <div class="info-value"><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($invoice['payment_method'])): ?>
            <div class="payment-info">
                <h3>معلومات الدفع</h3>
                <div class="info-label">طريقة الدفع:</div>
                <div class="info-value">
                    <?php 
                    switch ($invoice['payment_method']) {
                        case 'cash':
                            echo 'نقداً';
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
                            echo htmlspecialchars($invoice['payment_method'] ?? 'غير محدد');
                    }
                    ?>
                </div>
                
                <?php if (!empty($company['bank_name']) && !empty($company['bank_account'])): ?>
                <div class="info-label">معلومات الحساب البنكي:</div>
                <div class="info-value">
                    <p>اسم البنك: <?php echo htmlspecialchars($company['bank_name']); ?></p>
                    <p>رقم الحساب: <?php echo htmlspecialchars($company['bank_account']); ?></p>
                    <?php if (!empty($company['bank_iban'])): ?>
                    <p>رقم الآيبان: <?php echo htmlspecialchars($company['bank_iban']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="divider"></div>
            
            <p>شكراً لتعاملك معنا.</p>
            
            <?php if ($invoice['status'] != 'paid'): ?>
            <a href="<?php echo htmlspecialchars($payment_url ?? '#'); ?>" class="button">دفع الفاتورة</a>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($company['name'] ?? 'نظام يمان'); ?>. جميع الحقوق محفوظة.</p>
            <p>هذه الفاتورة تم إنشاؤها بواسطة نظام يمان للإدارة.</p>
        </div>
    </div>
</body>
</html>
