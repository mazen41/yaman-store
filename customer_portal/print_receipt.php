<?php
/**
 * Payment Receipt Print Page for Customer Portal
 */
require_once __DIR__ . '/../config/database.php';

// Get payment ID and token
$payment_id = $_GET['id'] ?? 0;
$token = $_GET['token'] ?? '';

if (empty($payment_id) || empty($token)) {
    die('Invalid access.');
}

// Verify customer by token
$stmt = $db->prepare("SELECT * FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('Invalid or expired link.');
}

// Get payment details
$stmt = $db->prepare("
    SELECT cp.*, ci.invoice_number 
    FROM customer_payments cp
    LEFT JOIN customer_invoices ci ON cp.invoice_id = ci.id
    WHERE cp.id = ? AND cp.customer_id = ?
");
$stmt->execute([$payment_id, $customer['id']]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die('Payment not found.');
}

// Get company info from system_settings
try {
    $stmt = $db->query("SELECT * FROM system_settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $settings = [
        'company_name' => 'يمان',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال دفع - <?php echo htmlspecialchars($payment['payment_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
        .receipt-container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border: 2px solid #10B981; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #10B981; padding-bottom: 20px; }
        .header h1 { color: #10B981; font-size: 28px; margin-bottom: 5px; }
        .header h2 { color: #059669; font-size: 20px; margin-bottom: 10px; }
        .receipt-info { background: #f0fdf4; padding: 20px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #10B981; }
        .receipt-info table { width: 100%; }
        .receipt-info td { padding: 10px; border-bottom: 1px solid #d1fae5; }
        .receipt-info td:first-child { font-weight: bold; width: 40%; color: #065f46; }
        .receipt-info tr:last-child td { border-bottom: none; }
        .amount-box { background: #10B981; color: white; padding: 20px; text-align: center; margin: 20px 0; border-radius: 5px; }
        .amount-box .label { font-size: 16px; margin-bottom: 10px; }
        .amount-box .amount { font-size: 32px; font-weight: bold; }
        .customer-info { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .customer-info h3 { color: #10B981; margin-bottom: 10px; }
        .customer-info p { padding: 5px 0; }
        .footer { margin-top: 40px; text-align: center; padding-top: 20px; border-top: 2px solid #10B981; color: #666; }
        .stamp { border: 2px solid #10B981; padding: 40px 20px; text-align: center; margin: 20px 0; border-radius: 50%; width: 150px; height: 150px; margin: 20px auto; display: flex; align-items: center; justify-content: center; flex-direction: column; }
        .stamp .paid { color: #10B981; font-size: 24px; font-weight: bold; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo htmlspecialchars($settings['company_name'] ?? 'يمان'); ?></h1>
            <h2>إيصال دفع</h2>
            <p><?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?></p>
        </div>

        <!-- Customer Info -->
        <div class="customer-info">
            <h3>بيانات العميل</h3>
            <p><strong>الاسم:</strong> <?php echo htmlspecialchars($customer['name']); ?></p>
            <p><strong>الهاتف:</strong> <?php echo htmlspecialchars($customer['phone']); ?></p>
        </div>

        <!-- Receipt Info -->
        <div class="receipt-info">
            <table>
                <tr>
                    <td>رقم الإيصال</td>
                    <td><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                </tr>
                <tr>
                    <td>رقم الفاتورة</td>
                    <td><?php echo htmlspecialchars($payment['invoice_number'] ?? '-'); ?></td>
                </tr>
                <tr>
                    <td>تاريخ الدفع</td>
                    <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                </tr>
                <tr>
                    <td>طريقة الدفع</td>
                    <td><?php echo htmlspecialchars($payment['payment_method'] ?? 'نقدي'); ?></td>
                </tr>
                <?php if (!empty($payment['reference_number'])): ?>
                <tr>
                    <td>رقم المرجع</td>
                    <td><?php echo htmlspecialchars($payment['reference_number']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($payment['notes'])): ?>
                <tr>
                    <td>ملاحظات</td>
                    <td><?php echo htmlspecialchars($payment['notes']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Amount Box -->
        <div class="amount-box">
            <div class="label">المبلغ المدفوع</div>
            <div class="amount"><?php echo number_format($payment['amount'], 0, '', ''); ?> ريال</div>
        </div>

        <!-- Paid Stamp -->
        <div class="stamp">
            <div class="paid">✓ مدفوع</div>
            <div style="font-size: 12px; margin-top: 10px;"><?php echo date('Y-m-d'); ?></div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><strong>شكراً لتعاملكم معنا</strong></p>
            <p style="margin-top: 10px; font-size: 12px;">تم الطباعة في: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <!-- Print Button -->
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()" style="background: #10B981; color: white; padding: 12px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
                <i class="fas fa-print"></i> طباعة
            </button>
        </div>
    </div>

    <script>
        // Auto print on load (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
