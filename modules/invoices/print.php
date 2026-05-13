<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

// Check if invoice ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$invoice_id = intval($_GET['id']);

// Fetch invoice data
try {
    $stmt = $db->prepare("
        SELECT ci.*, co.order_number, c.name as customer_name, c.customer_code, c.phone, c.email, c.address 
        FROM customer_invoices ci
        LEFT JOIN customer_orders co ON ci.order_id = co.id
        LEFT JOIN customers c ON ci.customer_id = c.id
        WHERE ci.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        header('Location: ../customers/index.php');
        exit();
    }
    
    // Fetch payment data for this invoice
    $payment_stmt = $db->prepare("
        SELECT * FROM customer_payments 
        WHERE invoice_id = ? 
        ORDER BY payment_date DESC
    ");
    $payment_stmt->execute([$invoice_id]);
    $payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total paid amount
    $total_paid = 0;
    foreach ($payments as $payment) {
        $total_paid += $payment['amount'];
    }
    
    // Calculate remaining amount
    $remaining_amount = $invoice['total_amount'] - $total_paid;
    
    // Get company information
    $company_stmt = $db->query("SELECT * FROM settings WHERE setting_key = 'company_info'");
    $company_info = $company_stmt->fetch(PDO::FETCH_ASSOC);
    $company_data = json_decode($company_info['setting_value'] ?? '{}', true);
    
} catch (PDOException $e) {
    die('حدث خطأ أثناء استرجاع بيانات الفاتورة: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="max-w-4xl mx-auto my-8 bg-white shadow-lg rounded-lg overflow-hidden">
        <!-- Print Header -->
        <div class="no-print bg-gray-200 px-6 py-4 flex justify-between items-center">
            <h1 class="text-xl font-bold text-gray-800">طباعة الفاتورة</h1>
            <div class="flex space-x-2 space-x-reverse">
                <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-print ml-1"></i>
                    طباعة
                </button>
                <button onclick="window.close()" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                    <i class="fas fa-times ml-1"></i>
                    إغلاق
                </button>
            </div>
        </div>
        
        <!-- Invoice Content -->
        <div class="p-8">
            <!-- Header -->
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($company_data['name'] ?? 'نظام إدارة يمان'); ?></h2>
                    <p class="text-gray-600"><?php echo htmlspecialchars($company_data['address'] ?? ''); ?></p>
                    <p class="text-gray-600"><?php echo htmlspecialchars($company_data['phone'] ?? ''); ?></p>
                    <p class="text-gray-600"><?php echo htmlspecialchars($company_data['email'] ?? ''); ?></p>
                </div>
                <div class="text-left">
                    <div class="text-3xl font-bold text-gray-800 mb-2">فاتورة</div>
                    <div class="text-gray-600">رقم: <?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                    <div class="text-gray-600">التاريخ: <?php echo date('d/m/Y', strtotime($invoice['created_at'])); ?></div>
                    <div class="text-gray-600">تاريخ الاستحقاق: <?php echo date('d/m/Y', strtotime($invoice['due_date'])); ?></div>
                </div>
            </div>
            
            <!-- Customer Info -->
            <div class="mb-8 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">بيانات العميل</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-gray-600">الاسم: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($invoice['customer_name']); ?></span></p>
                        <p class="text-gray-600">رقم العميل: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($invoice['customer_code']); ?></span></p>
                    </div>
                    <div>
                        <?php if (!empty($invoice['phone'])): ?>
                        <p class="text-gray-600">رقم الهاتف: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($invoice['phone']); ?></span></p>
                        <?php endif; ?>
                        <?php if (!empty($invoice['email'])): ?>
                        <p class="text-gray-600">البريد الإلكتروني: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($invoice['email']); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($invoice['address'])): ?>
                <p class="text-gray-600 mt-2">العنوان: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($invoice['address']); ?></span></p>
                <?php endif; ?>
            </div>
            
            <!-- Order Info -->
            <?php if (!empty($invoice['order_number'])): ?>
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">معلومات الطلب</h3>
                <p class="text-gray-600">رقم الطلب: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($invoice['order_number']); ?></span></p>
            </div>
            <?php endif; ?>
            
            <!-- Invoice Items -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">تفاصيل الفاتورة</h3>
                <table class="min-w-full bg-white border border-gray-200">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">البند</th>
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">الوصف</th>
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm">1</td>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm">
                                <?php if (!empty($invoice['order_number'])): ?>
                                طلب رقم <?php echo htmlspecialchars($invoice['order_number']); ?>
                                <?php else: ?>
                                خدمات متنوعة
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm"><?php echo number_format($invoice['amount'], 0, '', ''); ?> ريال</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="py-3 px-4 text-left font-semibold text-sm text-gray-700 border-t border-gray-200">المبلغ الأساسي</td>
                            <td class="py-3 px-4 text-right font-semibold text-sm text-gray-700 border-t border-gray-200"><?php echo number_format($invoice['amount'], 0, '', ''); ?> ريال</td>
                        </tr>
                        <tr>
                            <td colspan="2" class="py-3 px-4 text-left font-semibold text-sm text-gray-700">ضريبة القيمة المضافة (15%)</td>
                            <td class="py-3 px-4 text-right font-semibold text-sm text-gray-700"><?php echo number_format($invoice['tax_amount'], 0, '', ''); ?> ريال</td>
                        </tr>
                        <tr class="bg-gray-50">
                            <td colspan="2" class="py-3 px-4 text-left font-bold text-gray-800">المبلغ الإجمالي</td>
                            <td class="py-3 px-4 text-right font-bold text-gray-800"><?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ريال</td>
                        </tr>
                        <?php if ($total_paid > 0): ?>
                        <tr>
                            <td colspan="2" class="py-3 px-4 text-left font-semibold text-sm text-amber-700">المبلغ المدفوع</td>
                            <td class="py-3 px-4 text-right font-semibold text-sm text-amber-700"><?php echo number_format($total_paid, 0, '', ''); ?> ريال</td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($remaining_amount > 0): ?>
                        <tr>
                            <td colspan="2" class="py-3 px-4 text-left font-semibold text-sm text-red-700">المبلغ المتبقي</td>
                            <td class="py-3 px-4 text-right font-semibold text-sm text-red-700"><?php echo number_format($remaining_amount, 0, '', ''); ?> ريال</td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
            
            <!-- Payment Status -->
            <div class="mb-8">
                <div class="flex items-center">
                    <div class="text-lg font-semibold text-gray-800">حالة الفاتورة:</div>
                    <div class="mr-2 px-3 py-1 rounded-full text-sm font-semibold
                    <?php 
                        switch($invoice['status']) {
                            case 'paid': echo 'bg-amber-100 text-amber-800'; break;
                            case 'partially_paid': echo 'bg-yellow-100 text-yellow-800'; break;
                            case 'overdue': echo 'bg-red-100 text-red-800'; break;
                            case 'cancelled': echo 'bg-gray-100 text-gray-800'; break;
                            default: echo 'bg-blue-100 text-blue-800';
                        }
                    ?>">
                        <?php 
                        switch($invoice['status']) {
                            case 'paid': echo 'مدفوعة'; break;
                            case 'partially_paid': echo 'مدفوعة جزئياً'; break;
                            case 'overdue': echo 'متأخرة'; break;
                            case 'cancelled': echo 'ملغاة'; break;
                            default: echo 'قيد الانتظار';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Payments -->
            <?php if (!empty($payments)): ?>
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">المدفوعات</h3>
                <table class="min-w-full bg-white border border-gray-200">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">رقم الدفعة</th>
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">تاريخ الدفع</th>
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">طريقة الدفع</th>
                            <th class="py-3 px-4 text-right border-b border-gray-200 font-semibold text-sm text-gray-700">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm"><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm">
                                <?php 
                                switch($payment['payment_method']) {
                                    case 'cash': echo 'نقدي'; break;
                                    case 'transfer': echo 'تحويل بنكي'; break;
                                    case 'credit_card': echo 'بطاقة ائتمانية'; break;
                                    case 'check': echo 'شيك'; break;
                                    default: echo 'أخرى';
                                }
                                ?>
                            </td>
                            <td class="py-3 px-4 border-b border-gray-200 text-sm"><?php echo number_format($payment['amount'], 0, '', ''); ?> ريال</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Terms and Notes -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">الشروط والأحكام</h3>
                <p class="text-gray-600 text-sm">
                    1. يرجى دفع المبلغ المستحق قبل تاريخ الاستحقاق.<br>
                    2. جميع الأسعار تشمل ضريبة القيمة المضافة بنسبة 15%.<br>
                    3. هذه الفاتورة صالحة لمدة 30 يومًا من تاريخ الإصدار.
                </p>
            </div>
            
            <!-- Footer -->
            <div class="text-center text-gray-500 text-sm mt-16">
                <p>شكراً لتعاملكم معنا</p>
                <p>تم إنشاء هذه الفاتورة بواسطة نظام إدارة يمان</p>
            </div>
        </div>
    </div>
</body>
</html>
