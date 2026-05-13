<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Include necessary files
require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// **Permission Check**: Use canView to check if the user has permission for this module.
if (!canView($_SESSION['user_id'], 'customer_invoices')) {
    // If the user does not have permission, show an access denied message and exit.
    $page_title = 'غير مصرح لك بالدخول';
    include '../../includes/header.php';
    ?>
    <div class="min-h-screen bg-gray-50 py-6" dir="rtl">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-lg rounded-xl p-8 text-center">
                <i class="fas fa-lock text-5xl text-red-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-900">غير مصرح لك بالدخول</h1>
                <p class="text-gray-600 mt-2">ليس لديك الصلاحيات اللازمة لعرض هذه الصفحة. يرجى التواصل مع مسؤول النظام.</p>
                <a href="../dashboard/index.php" class="mt-6 inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                    العودة للوحة التحكم
                </a>
            </div>
        </div>
    </div>
    <?php
    include '../../includes/footer.php';
    exit(); // Stop script execution
}

$page_title = 'عرض الدفعة';
$error_message = '';
$success_message = ''; // For the success alert

// Check for success message from URL
if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}

// Check if payment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ../customers/index.php');
    exit();
}

$payment_id = intval($_GET['id']);

// Fetch payment data
try {
    $stmt = $db->prepare("
        SELECT
            cp.*,
            ci.invoice_number,
            ci.total_amount as invoice_amount,
            c.name as customer_name,
            c.phone,
            c.email,
            ba.bank_name,
            ba.account_holder_name,
            ba.account_number
        FROM customer_payments cp
        LEFT JOIN customer_invoices ci ON cp.invoice_id = ci.id
        LEFT JOIN customers c ON cp.customer_id = c.id
        LEFT JOIN bank_accounts ba ON cp.bank_account_id = ba.id
        WHERE cp.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        header('Location: ../customers/index.php?error=payment_not_found');
        exit();
    }
    
    // *** NEW: Calculate the remaining amount for the invoice to decide if the "Add Payment" button should be shown ***
    $remaining_amount_on_invoice = 0;
    if (!empty($payment['invoice_id'])) {
        // Get the sum of all payments for this invoice
        $paid_stmt = $db->prepare("SELECT SUM(amount) FROM customer_payments WHERE invoice_id = ?");
        $paid_stmt->execute([$payment['invoice_id']]);
        $total_paid = $paid_stmt->fetchColumn() ?? 0;

        // Calculate the remaining amount
        $remaining_amount_on_invoice = $payment['invoice_amount'] - $total_paid;
    }


} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات الدفعة: ' . $e->getMessage();
    $payment = []; // Initialize to avoid fatal errors
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow-lg rounded-xl mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 flex items-center">
                            <i class="fas fa-receipt text-blue-500 mr-3"></i>
                            تفاصيل الدفعة
                        </h1>
                        <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($payment['payment_number'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <!-- NEW: Edit Payment Button -->
                        <a href="edit.php?id=<?php echo $payment_id; ?>" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200 shadow-md">
                            <i class="fas fa-edit mr-2"></i> تعديل
                        </a>
                        <a href="print.php?id=<?php echo $payment_id; ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200 shadow-md">
                            <i class="fas fa-print mr-2"></i> طباعة
                        </a>
                        <?php if (!empty($payment['invoice_id'])): ?>
                        <a href="../invoices/view.php?id=<?php echo $payment['invoice_id']; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200 shadow-md">
                            <i class="fas fa-file-invoice mr-2"></i> عرض الفاتورة
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Success message block -->
        <?php if ($success_message): ?>
        <div class="bg-amber-100 border-l-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-bold"><i class="fas fa-check-circle mr-2"></i>نجاح</p>
                    <p><?php echo $success_message; ?></p>
                </div>
                
                <!-- ** EDITED CONDITION **: Only show this button if the invoice is not fully paid -->
                <?php if (!empty($payment['invoice_id']) && $remaining_amount_on_invoice > 0): ?>
                    <a href="add.php?invoice_id=<?php echo $payment['invoice_id']; ?>" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200 shadow-md whitespace-nowrap">
                        <i class="fas fa-plus mr-2"></i> إضافة دفعة أخرى لهذه الفاتورة
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment Information -->
        <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900">معلومات الدفعة</h2>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                    <!-- Payment Details Column -->
                    <div>
                        <h3 class="text-md font-semibold text-gray-800 mb-3 border-b pb-2">بيانات الدفعة</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center"><span class="text-sm text-gray-600">رقم الدفعة:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['payment_number']); ?></span></div>
                            <div class="flex justify-between items-center"><span class="text-sm text-gray-600">تاريخ الدفع:</span><span class="text-sm font-medium text-gray-900"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></span></div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">طريقة الدفع:</span>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                    <?php echo htmlspecialchars($payment['payment_method'] === 'transfer' ? 'تحويل بنكي' : ($payment['payment_method'] === 'cash' ? 'نقدي' : ($payment['payment_method'] === 'customer_card' ? 'بطاقة العميل' : 'أخرى'))); ?>
                                </span>
                            </div>
                            <?php if (!empty($payment['reference_number'])): ?>
                            <div class="flex justify-between items-center"><span class="text-sm text-gray-600">رقم المرجع:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['reference_number']); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Customer Details Column -->
                    <div>
                        <h3 class="text-md font-semibold text-gray-800 mb-3 border-b pb-2">بيانات العميل</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center"><span class="text-sm text-gray-600">اسم العميل:</span><a href="../customers/view_enhanced.php?id=<?php echo $payment['customer_id']; ?>" class="text-sm font-medium text-blue-600 hover:underline"><?php echo htmlspecialchars($payment['customer_name']); ?></a></div>
                            <?php if (!empty($payment['phone'])): ?>
                            <div class="flex justify-between items-center"><span class="text-sm text-gray-600">رقم الهاتف:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['phone']); ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($payment['email'])): ?>
                            <div class="flex justify-between items-center"><span class="text-sm text-gray-600">البريد الإلكتروني:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['email']); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bank Details (if applicable) -->
                <?php if ($payment['payment_method'] === 'transfer' && !empty($payment['bank_name'])): ?>
                <div class="mt-6 border-t pt-4">
                    <h3 class="text-md font-semibold text-gray-800 mb-3">تفاصيل التحويل البنكي</h3>
                    <div class="bg-gray-50 p-4 rounded-lg space-y-2">
                        <div class="flex justify-between items-center"><span class="text-sm text-gray-600">البنك المستلم:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['bank_name']); ?></span></div>
                        <div class="flex justify-between items-center"><span class="text-sm text-gray-600">اسم صاحب الحساب:</span><span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['account_holder_name']); ?></span></div>
                        <div class="flex justify-between items-center"><span class="text-sm text-gray-600">رقم الحساب:</span><span class="text-sm font-medium text-gray-900 dir-ltr text-left"><?php echo htmlspecialchars($payment['account_number']); ?></span></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Amount & Invoice Link -->
        <div class="bg-white shadow-lg rounded-xl overflow-hidden mb-6">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">المبلغ المدفوع</p>
                        <p class="text-3xl font-bold text-amber-600"><?php echo number_format($payment['amount'], 0, '', ''); ?> <span class="text-lg font-medium">ريال</span></p>
                    </div>
                    <?php if (!empty($payment['invoice_number'])): ?>
                    <div class="text-left">
                         <p class="text-sm text-gray-500">للفاتورة رقم</p>
                         <a href="../invoices/view.php?id=<?php echo $payment['invoice_id']; ?>" class="text-xl font-bold text-blue-600 hover:underline"><?php echo htmlspecialchars($payment['invoice_number']); ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Notes & Receipt Image -->
        <div class="grid grid-cols-1 <?php if (!empty($payment['notes']) && !empty($payment['receipt_image_path'])) echo 'md:grid-cols-2'; ?> gap-6">
            <?php if (!empty($payment['notes'])): ?>
            <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50"><h2 class="text-lg font-semibold text-gray-900">ملاحظات</h2></div>
                <div class="p-6"><p class="text-gray-700"><?php echo nl2br(htmlspecialchars($payment['notes'])); ?></p></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($payment['receipt_image_path'])): ?>
            <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50"><h2 class="text-lg font-semibold text-gray-900">إيصال الدفع</h2></div>
                <div class="p-6">
                    <a href="../../<?php echo htmlspecialchars($payment['receipt_image_path']); ?>" target="_blank" title="عرض الإيصال بالحجم الكامل">
                        <img src="../../<?php echo htmlspecialchars($payment['receipt_image_path']); ?>" alt="إيصال الدفع" class="w-full h-auto max-h-80 object-contain rounded-lg border">
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>