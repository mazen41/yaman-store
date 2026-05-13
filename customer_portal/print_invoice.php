<?php
/**
 * Invoice Print Page for Customer Portal
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/status_helpers.php';

// Get invoice ID and token
$invoice_id = $_GET['id'] ?? 0;
$token = $_GET['token'] ?? '';

if (empty($invoice_id) || empty($token)) {
    die('Invalid access.');
}

// Verify customer by token
$stmt = $db->prepare("SELECT * FROM customers WHERE portal_token = ?");
$stmt->execute([$token]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die('Invalid or expired link.');
}

// Get invoice details
$stmt = $db->prepare("
    SELECT ci.*, co.order_number 
    FROM customer_invoices ci
    LEFT JOIN customer_orders co ON ci.order_id = co.id
    WHERE ci.id = ? AND ci.customer_id = ?
");
$stmt->execute([$invoice_id, $customer['id']]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die('Invoice not found.');
}

// Get company info from system_settings
try {
    $stmt = $db->query("SELECT * FROM system_settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Default settings if table doesn't exist
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
    <title>فاتورة - <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border: 1px solid #ddd; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 3px solid #4F46E5; padding-bottom: 20px; }
        .header h1 { color: #4F46E5; font-size: 28px; margin-bottom: 10px; }
        .info-section { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .info-box { width: 48%; }
        .info-box h3 { background: #4F46E5; color: white; padding: 8px; margin-bottom: 10px; }
        .info-box p { padding: 5px 10px; line-height: 1.8; }
        .invoice-details { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .invoice-details table { width: 100%; }
        .invoice-details td { padding: 8px; }
        .invoice-details td:first-child { font-weight: bold; width: 150px; }
        .totals { margin-top: 30px; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 12px; border: 1px solid #ddd; }
        .totals .label { font-weight: bold; background: #f8f9fa; width: 70%; }
        .totals .amount { text-align: left; font-size: 18px; }
        .totals .final { background: #4F46E5; color: white; font-size: 20px; font-weight: bold; }
        .footer { margin-top: 40px; text-align: center; padding-top: 20px; border-top: 2px solid #ddd; color: #666; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo htmlspecialchars($settings['company_name'] ?? 'يمان'); ?></h1>
            <p><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></p>
            <p>هاتف: <?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?></p>
        </div>

        <!-- Invoice Info -->
        <div class="info-section">
            <div class="info-box">
                <h3>بيانات العميل</h3>
                <p><strong>الاسم:</strong> <?php echo htmlspecialchars($customer['name']); ?></p>
                <p><strong>الهاتف:</strong> <?php echo htmlspecialchars($customer['phone']); ?></p>
                <?php if (!empty($customer['email'])): ?>
                <p><strong>البريد:</strong> <?php echo htmlspecialchars($customer['email']); ?></p>
                <?php endif; ?>
            </div>
            <div class="info-box">
                <h3>بيانات الفاتورة</h3>
                <p><strong>رقم الفاتورة:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                <p><strong>رقم الطلب:</strong> <?php echo htmlspecialchars($invoice['order_number'] ?? '-'); ?></p>
                <p><strong>التاريخ:</strong> <?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?></p>
                <p><strong>الحالة:</strong> 
                    <span style="color: <?php echo $invoice['status'] == 'paid' ? 'green' : 'orange'; ?>">
                        <?php echo getInvoiceStatusText($invoice['status'] ?? 'pending'); ?>
                    </span>
                </p>
            </div>
        </div>

        <!-- Totals -->
        <div class="totals">
            <table>
                <tr>
                    <td class="label">المبلغ الأساسي</td>
                    <td class="amount"><?php echo number_format($invoice['amount'], 0, '', ''); ?> ريال</td>
                </tr>
                <?php if ($invoice['discount_amount'] > 0): ?>
                <tr>
                    <td class="label">الخصم</td>
                    <td class="amount" style="color: green;">-<?php echo number_format($invoice['discount_amount'], 0, '', ''); ?> ريال</td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['tax_amount'] > 0): ?>
                <tr>
                    <td class="label">الضريبة</td>
                    <td class="amount"><?php echo number_format($invoice['tax_amount'], 0, '', ''); ?> ريال</td>
                </tr>
                <?php endif; ?>
                <tr class="final">
                    <td class="label">المبلغ الإجمالي</td>
                    <td class="amount"><?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ريال</td>
                </tr>
                <tr>
                    <td class="label">المدفوع</td>
                    <td class="amount" style="color: green;"><?php echo number_format($invoice['paid_amount'] ?? 0, 0, '', ''); ?> ريال</td>
                </tr>
                <tr>
                    <td class="label">المتبقي</td>
                    <td class="amount" style="color: <?php echo $invoice['remaining_amount'] > 0 ? 'red' : 'green'; ?>;">
                        <?php echo number_format($invoice['remaining_amount'] ?? 0, 0, '', ''); ?> ريال
                    </td>
                </tr>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>شكراً لتعاملكم معنا</p>
            <p style="margin-top: 10px; font-size: 12px;">تم الطباعة في: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <!-- Print Button -->
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()" style="background: #4F46E5; color: white; padding: 12px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
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
