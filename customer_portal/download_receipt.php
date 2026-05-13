<?php
session_start();

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';

$customer_id = $_SESSION['customer_id'];
$payment_id = $_GET['payment_id'] ?? 0;

// Get payment details
$stmt = $db->prepare("
    SELECT cp.*, 
           c.name as customer_name, 
           c.customer_code,
           c.mobile_number,
           co.order_number,
           ci.invoice_number
    FROM customer_payments cp
    LEFT JOIN customers c ON cp.customer_id = c.id
    LEFT JOIN customer_invoices ci ON cp.invoice_id = ci.id
    LEFT JOIN customer_orders co ON ci.order_id = co.id
    WHERE cp.id = ? AND cp.customer_id = ?
");
$stmt->execute([$payment_id, $customer_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die('إيصال غير موجود');
}

// Payment methods in Arabic
$payment_methods_ar = [
    'cash' => 'نقداً',
    'card' => 'بطاقة',
    'bank_transfer' => 'تحويل بنكي',
    'online' => 'دفع إلكتروني',
    'partially_paid' => 'دفع جزئي'
];
$payment_method_ar = $payment_methods_ar[$payment['payment_method']] ?? $payment['payment_method'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إيصال دفع - <?php echo htmlspecialchars($payment['payment_number']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Cairo', sans-serif; }
        body { padding: 20px; background: #f5f5f5; }
        .receipt { max-width: 800px; margin: 0 auto; background: white; padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 3px solid #4f46e5; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { font-size: 32px; color: #4f46e5; margin-bottom: 10px; }
        .header .receipt-number { font-size: 18px; color: #666; font-weight: 600; }
        .section { margin-bottom: 25px; }
        .section-title { background: #4f46e5; color: white; padding: 10px 15px; font-weight: 700; font-size: 16px; margin-bottom: 15px; }
        .info-row { display: flex; border-bottom: 1px solid #e5e7eb; padding: 12px 0; }
        .info-label { width: 200px; font-weight: 600; color: #374151; }
        .info-value { flex: 1; color: #6b7280; }
        .amount-box { background: #dcfce7; border: 2px solid #D4B87D; padding: 20px; text-align: center; margin: 30px 0; border-radius: 8px; }
        .amount-box .label { font-size: 18px; font-weight: 600; color: #166534; margin-bottom: 10px; }
        .amount-box .value { font-size: 32px; font-weight: 700; color: #B8956A; }
        .footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #e5e7eb; color: #9ca3af; font-size: 14px; }
        .print-btn { background: #4f46e5; color: white; border: none; padding: 12px 30px; font-size: 16px; font-weight: 600; cursor: pointer; border-radius: 6px; margin: 20px auto; display: block; }
        .print-btn:hover { background: #4338ca; }
        @media print {
            body { background: white; padding: 0; }
            .receipt { box-shadow: none; }
            .print-btn { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h1>إيصال دفع</h1>
            <div class="receipt-number">رقم الإيصال: <?php echo htmlspecialchars($payment['payment_number']); ?></div>
        </div>

        <div class="section">
            <div class="section-title">معلومات العميل</div>
            <div class="info-row">
                <div class="info-label">اسم العميل:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['customer_name']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">رقم العميل:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['customer_code']); ?></div>
            </div>
            <?php if ($payment['mobile_number']): ?>
            <div class="info-row">
                <div class="info-label">رقم الجوال:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['mobile_number']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-title">معلومات الدفع</div>
            <?php if ($payment['order_number']): ?>
            <div class="info-row">
                <div class="info-label">رقم الطلب:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['order_number']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($payment['invoice_number']): ?>
            <div class="info-row">
                <div class="info-label">رقم الفاتورة:</div>
                <div class="info-value"><?php echo htmlspecialchars($payment['invoice_number']); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">تاريخ الدفع:</div>
                <div class="info-value"><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">طريقة الدفع:</div>
                <div class="info-value"><?php echo $payment_method_ar; ?></div>
            </div>
        </div>

        <div class="amount-box">
            <div class="label">المبلغ المدفوع</div>
            <div class="value"><?php echo number_format($payment['amount'], 0, '', ''); ?> ريال</div>
        </div>

        <?php if (!empty($payment['notes'])): ?>
        <div class="section">
            <div class="section-title">ملاحظات</div>
            <div style="padding: 15px; background: #f9fafb; border-radius: 6px;">
                <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p>تم إصدار هذا الإيصال إلكترونياً في: <?php echo date('Y-m-d H:i:s'); ?></p>
            <p style="margin-top: 5px;">نظام إدارة يمان - جميع الحقوق محفوظة</p>
        </div>

        <button class="print-btn" onclick="window.print()">طباعة الإيصال</button>
    </div>
</body>
</html>
