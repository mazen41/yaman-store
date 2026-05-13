<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$page_title = 'عرض الفاتورة';
$error_message = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php'); // Or a more appropriate error page
    exit();
}

$invoice_id = intval($_GET['id']);

try {
    // MODIFIED: Added `co.additional_discount` to the query to fetch this specific value.
    $stmt = $db->prepare("
        SELECT 
            ci.*, 
            co.order_number, 
            co.subtotal_amount,
            co.discount_type,
            co.discount_amount,
            co.additional_discount, -- Fetched the additional discount here
            co.final_amount   AS order_final_amount,
            co.paid_amount    AS order_paid_amount,
            c.name as customer_name, 
            c.phone, 
            c.email, 
            c.address,
            ct.name as customer_type_name,
            ct.discount_percentage
        FROM customer_invoices ci
        LEFT JOIN customer_orders co ON ci.order_id = co.id
        LEFT JOIN customers c ON ci.customer_id = c.id
        LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
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

    // ========= توحيد الأرقام مع شاشة الطلبات =========
    // المبلغ الأصلي (نفس المبلغ الأصلي في جدول الطلبات)
    $original_amount = isset($invoice['subtotal_amount']) && $invoice['subtotal_amount'] > 0
        ? $invoice['subtotal_amount']
        : ($invoice['amount'] ?? 0);

    // الخصومات (الرئيسية + الإضافية)
    $main_discount = (float) ($invoice['discount_amount'] ?? 0);
    $additional_discount = (float) ($invoice['additional_discount'] ?? 0);

    // المبلغ النهائي حسب الطلب (نفس final_amount في orders/index)
    $order_final_amount = isset($invoice['order_final_amount']) && $invoice['order_final_amount'] > 0
        ? (float) $invoice['order_final_amount']
        : ($original_amount - $main_discount - $additional_discount);

    // المبلغ المدفوع
    // نأخذ مجموع المدفوعات من جدول customer_payments، وإذا كان order_paid_amount أكبر نستخدمه حتى لا يحصل اختلاف
    $payments_total = array_sum(array_column($payments, 'amount'));
    $order_paid = (float) ($invoice['order_paid_amount'] ?? 0);
    $total_paid = max($payments_total, $order_paid);

    // المبلغ المتبقي = المبلغ النهائي - المدفوع
    $remaining_amount = $order_final_amount - $total_paid;

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات الفاتورة: ' . $e->getMessage();
    error_log($e->getMessage());
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header & Actions -->
        <div class="bg-white shadow-lg rounded-xl mb-8 p-6 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">تفاصيل الفاتورة</h1>
                <p class="text-gray-500 mt-1 font-mono">
                    <?php echo htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?>
                </p>
            </div>
            <div class="flex space-x-2 space-x-reverse">
                <a href="print.php?id=<?php echo $invoice_id; ?>" target="_blank"
                    class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200 shadow-md transform hover:-translate-y-0.5">
                    <i class="fas fa-print ml-2"></i> طباعة
                </a>
                <?php if (!empty($invoice['phone'])): ?>
                    <a href="../notifications/send_whatsapp.php?invoice_id=<?php echo $invoice_id; ?>&customer_id=<?php echo $invoice['customer_id']; ?>"
                        class="inline-flex items-center px-4 py-2 bg-whatsapp text-white rounded-lg hover:bg-whatsapp-dark transition duration-200 shadow-md transform hover:-translate-y-0.5">
                        <i class="fab fa-whatsapp ml-2"></i> واتساب
                    </a>
                <?php endif; ?>
                <a href="../customers/view_enhanced.php?id=<?php echo $invoice['customer_id']; ?>&tab=invoices"
                    class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200 shadow-md transform hover:-translate-y-0.5">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للعميل
                </a>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6" role="alert">
                <p class="font-bold">خطأ</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Invoice Details Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">

            <!-- Left Column: Financials & Payments -->
            <div class="lg:col-span-3 space-y-8">

                <!-- Invoice Amounts -->
                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-800">تفاصيل المبالغ</h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify_between items-center">
                            <span class="text-md text-gray-600">المبلغ الأصلي:</span>
                            <span
                                class="text-md font-semibold text-gray-800"><?php echo number_format($original_amount, 2, '.', ','); ?>
                                ريال</span>
                        </div>

                        <?php if ($main_discount > 0): ?>
                            <div class="flex justify-between items-center text-indigo-600">
                                <span class="text-md">
                                    <i class="fas fa-tags ml-2"></i>
                                    الخصم المطبق
                                    <?php if (isset($invoice['customer_type_name'])): ?>
                                        <span
                                            class="text-xs bg-indigo-100 p-1 rounded"><?php echo htmlspecialchars($invoice['customer_type_name']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="text-md font-semibold">-
                                    <?php echo number_format($main_discount, 2, '.', ','); ?> ريال</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($additional_discount > 0): ?>
                            <div class="flex justify-between items-center text-orange-600">
                                <span class="text-md">
                                    <i class="fas fa-percent ml-2"></i>
                                    خصم إضافي
                                </span>
                                <span class="text-md font-semibold">-
                                    <?php echo number_format($additional_discount, 2, '.', ','); ?> ريال</span>
                            </div>
                        <?php endif; ?>

                        <div class="border-t-2 border-dashed border-gray-200 my-4"></div>

                        <div
                            class="flex justify-between items-center text-2xl font-bold text-blue-600 bg-blue-50 p-4 rounded-lg">
                            <span>المبلغ النهائي (بعد الخصم):</span>
                            <span><?php echo number_format($order_final_amount, 2, '.', ','); ?> ريال</span>
                        </div>

                        <div class="flex justify-between items-center pt-2">
                            <span class="text-md font-medium text-gray-600">المبلغ المدفوع:</span>
                            <span
                                class="text-md font-bold text-amber-600"><?php echo number_format($total_paid, 2, '.', ','); ?>
                                ريال</span>
                        </div>

                        <?php if ($remaining_amount > 0.01): ?>
                            <div class="flex justify-between items-center bg-red-50 p-3 rounded-lg">
                                <span class="text-md font-medium text-red-700">المبلغ المتبقي:</span>
                                <span
                                    class="text-lg font-bold text-red-700"><?php echo number_format($remaining_amount, 2, '.', ','); ?>
                                    ريال</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>



                <!-- Payments -->
                <div class="bg-white shadow-lg rounded-xl overflow-hidden">
                    <?php if (hasPermission($_SESSION['user_id'], 'customer_invoices', 'add')): ?>
                        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                            <h2 class="text-xl font-bold text-gray-800">المدفوعات المسجلة</h2>
                            <?php 
                            // MODIFIED CONDITION: Show "Add Payment" only if remaining_amount > 0.01
                            if ($remaining_amount > 0.01): 
                            ?>
                                <a href="../payments/add.php?invoice_id=<?php echo $invoice_id; ?>"
                                    class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 transition">
                                    <i class="fas fa-plus ml-2"></i> إضافة دفعة
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="p-2">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-10">
                                <i class="fas fa-receipt text-5xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500">لا توجد مدفوعات مسجلة لهذه الفاتورة.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">رقم
                                                الدفعة</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                                                التاريخ</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">
                                                المبلغ</th>
                                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">
                                                عمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td class="px-4 py-3 text-sm text-gray-800">
                                                    <?php echo htmlspecialchars($payment['payment_number']); ?>
                                                </td>
                                                <td class="px-4 py-3 text-sm text-gray-500">
                                                    <?php echo date('Y/m/d', strtotime($payment['payment_date'])); ?>
                                                </td>
                                                <td class="px-4 py-3 text-sm font-semibold text-gray-900">
                                                    <?php echo number_format($payment['amount'], 2, '.', ','); ?> ريال
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <a href="../payments/view.php?id=<?php echo $payment['id']; ?>"
                                                        class="text-blue-600 hover:text-blue-900" title="عرض"><i
                                                            class="fas fa-eye"></i></a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Info -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Invoice Info -->
                <div class="bg-white shadow-lg rounded-xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-3">معلومات الفاتورة</h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">الحالة:</span> <span class="px-2 py-1 font-semibold rounded-full <?php
                        switch ($invoice['status']) {
                            case 'paid':
                                echo 'bg-amber-100 text-amber-800';
                                break;
                            case 'partially_paid':
                                echo 'bg-yellow-100 text-yellow-800';
                                break;
                            case 'cancelled':
                                echo 'bg-red-100 text-red-800';
                                break;
                            default:
                                echo 'bg-blue-100 text-blue-800';
                        }
                        ?>"><?php
                        switch ($invoice['status']) {
                            case 'paid':
                                echo 'مدفوعة';
                                break;
                            case 'partially_paid':
                                echo 'مدفوعة جزئياً';
                                break;
                            case 'cancelled':
                                echo 'ملغاة';
                                break;
                            default:
                                echo 'قيد الانتظار';
                        }
                        ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">رقم الطلب المرتبط:</span> <a
                                href="../orders/view.php?id=<?php echo $invoice['order_id']; ?>"
                                class="font-medium text-blue-600 hover:underline"><?php echo htmlspecialchars($invoice['order_number'] ?? '-'); ?></a>
                        </div>
                        <div class="flex justify-between"><span class="text-gray-500">تاريخ الإصدار:</span> <span
                                class="font-medium text-gray-800"><?php echo date('d M, Y', strtotime($invoice['created_at'])); ?></span>
                        </div>
                        <div class="flex justify-between"><span class="text-gray-500">تاريخ الاستحقاق:</span> <span
                                class="font-medium text-gray-800"><?php echo date('d M, Y', strtotime($invoice['due_date'])); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Customer Info -->
                <div class="bg-white shadow-lg rounded-xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-3">بيانات العميل</h3>
                    <div class="grid grid-cols-1 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500 block">الاسم</span>
                            <span
                                class="font-semibold text-lg text-gray-900"><?php echo htmlspecialchars($invoice['customer_name']); ?></span>
                        </div>
                        <?php if (isset($invoice['customer_type_name'])): ?>
                            <div>
                                <span class="text-gray-500 block">نوع العميل</span>
                                <span
                                    class="font-semibold text-gray-900 bg-gray-100 px-2 py-1 rounded-md"><?php echo htmlspecialchars($invoice['customer_type_name']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['phone'])): ?>
                            <div>
                                <span class="text-gray-500 block">رقم الهاتف</span>
                                <span
                                    class="font-semibold text-gray-900"><?php echo htmlspecialchars($invoice['phone']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['email'])): ?>
                            <div>
                                <span class="text-gray-500 block">البريد الإلكتروني</span>
                                <span
                                    class="font-semibold text-gray-900"><?php echo htmlspecialchars($invoice['email']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>