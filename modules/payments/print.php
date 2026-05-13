<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

// Check if payment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$payment_id = intval($_GET['id']);

// Fetch payment data
try {
    $stmt = $db->prepare("
        SELECT cp.*, ci.invoice_number, ci.total_amount as invoice_amount, 
               c.name as customer_name, c.customer_code, c.phone, c.email, c.address 
        FROM customer_payments cp
        LEFT JOIN customer_invoices ci ON cp.invoice_id = ci.id
        LEFT JOIN customers c ON cp.customer_id = c.id
        WHERE cp.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        header('Location: ../customers/index.php');
        exit();
    }
    
    // Get company information
    $company_stmt = $db->query("SELECT * FROM settings WHERE setting_key = 'company_info'");
    $company_info = $company_stmt->fetch(PDO::FETCH_ASSOC);
    $company_data = json_decode($company_info['setting_value'] ?? '{}', true);
    
} catch (PDOException $e) {
    die('حدث خطأ أثناء استرجاع بيانات الدفعة: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال دفع #<?php echo htmlspecialchars($payment['payment_number']); ?></title>
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
    <div class="max-w-3xl mx-auto my-8 bg-white shadow-lg rounded-lg overflow-hidden">
        <!-- Print Header -->
        <div class="no-print bg-gray-200 px-6 py-4 flex justify-between items-center">
            <h1 class="text-xl font-bold text-gray-800">طباعة إيصال الدفع</h1>
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
        
        <!-- Receipt Content -->
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
                    <div class="text-3xl font-bold text-gray-800 mb-2">إيصال دفع</div>
                    <div class="text-gray-600">رقم: <?php echo htmlspecialchars($payment['payment_number']); ?></div>
                    <div class="text-gray-600">التاريخ: <?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></div>
                </div>
            </div>
            
            <!-- Customer Info -->
            <div class="mb-8 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">بيانات العميل</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-gray-600">الاسم: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($payment['customer_name']); ?></span></p>
                        <p class="text-gray-600">رقم العميل: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($payment['customer_code']); ?></span></p>
                    </div>
                    <div>
                        <?php if (!empty($payment['phone'])): ?>
                        <p class="text-gray-600">رقم الهاتف: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($payment['phone']); ?></span></p>
                        <?php endif; ?>
                        <?php if (!empty($payment['email'])): ?>
                        <p class="text-gray-600">البريد الإلكتروني: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($payment['email']); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Invoice Info -->
            <?php if (!empty($payment['invoice_number'])): ?>
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">معلومات الفاتورة</h3>
                <p class="text-gray-600">رقم الفاتورة: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($payment['invoice_number']); ?></span></p>
                <?php if (!empty($payment['invoice_amount'])): ?>
                <p class="text-gray-600">إجمالي الفاتورة: <span class="font-medium text-gray-800"><?php echo number_format($payment['invoice_amount'], 0, '', ''); ?> ريال</span></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Payment Details -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">تفاصيل الدفع</h3>
                <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between mb-4">
                            <span class="text-gray-600">طريقة الدفع:</span>
                            <span class="font-medium text-gray-800">
                                <?php 
                                switch($payment['payment_method']) {
                                    case 'cash': echo 'نقدي'; break;
                                    case 'transfer': echo 'تحويل بنكي'; break;
                                    case 'credit_card': echo 'بطاقة ائتمانية'; break;
                                    case 'check': echo 'شيك'; break;
                                    default: echo 'أخرى';
                                }
                                ?>
                            </span>
                        </div>
                        <?php if (!empty($payment['reference_number'])): ?>
                        <div class="flex justify-between mb-4">
                            <span class="text-gray-600">رقم المرجع:</span>
                            <span class="font-medium text-gray-800"><?php echo htmlspecialchars($payment['reference_number']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between pt-4 border-t border-gray-200">
                            <span class="text-lg font-bold text-gray-800">المبلغ المدفوع:</span>
                            <span class="text-lg font-bold text-amber-600"><?php echo number_format($payment['amount'], 0, '', ''); ?> ريال</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notes -->
            <?php if (!empty($payment['notes'])): ?>
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">ملاحظات</h3>
                <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Signature -->
            <div class="mt-16 grid grid-cols-2 gap-8">
                <div>
                    <p class="text-gray-600 mb-8">توقيع المستلم</p>
                    <div class="border-t border-gray-300 pt-2">
                        <p class="text-sm text-gray-500">الاسم والتوقيع</p>
                    </div>
                </div>
                <div>
                    <p class="text-gray-600 mb-8">ختم الشركة</p>
                    <div class="border-t border-gray-300 pt-2">
                        <p class="text-sm text-gray-500">الختم الرسمي</p>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="text-center text-gray-500 text-sm mt-16">
                <p>شكراً لتعاملكم معنا</p>
                <p>تم إنشاء هذا الإيصال بواسطة نظام إدارة يمان</p>
            </div>
        </div>
    </div>
</body>
</html>
