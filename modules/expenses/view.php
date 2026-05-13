<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Permission check for edit button
$canEdit = hasPermission($_SESSION['user_id'], 'expenses', 'edit');

$page_title = 'عرض المصروف';
$error_message = '';
$expense = null;

// 1. Validate and get the Expense ID from the URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    // Redirect to the expenses list if the ID is missing or invalid
    header('Location: index.php');
    exit();
}

$expense_id = intval($_GET['id']);

// 2. Fetch expense data from the database
try {
    $stmt = $db->prepare("
        SELECT 
            e.*,
            ec.category_name,
            ei.item_name,
            u.username as creator_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN expense_items ei ON e.item_id = ei.id
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.id = ?
    ");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no expense is found with that ID, redirect
    if (!$expense) {
        header('Location: index.php');
        exit();
    }

    // Arabic labels for enums / codes
    $payment_method_labels = [
        'cash'          => 'نقدي',
        'bank_transfer' => 'تحويل بنكي',
        'card'          => 'بطاقة بنكية',
        'check'         => 'شيك',
    ];

    $currency_labels = [
        'YER' => 'ريال يمني',
        'SAR' => 'ريال سعودي',
    ];

    $display_payment_method = $payment_method_labels[$expense['payment_method'] ?? ''] ?? ($expense['payment_method'] ?: 'غير محدد');
    $display_currency      = $currency_labels[$expense['currency'] ?? ''] ?? ($expense['currency'] ?: 'غير محددة');
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات المصروف: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
    /* Entry Animation */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in-up {
        animation: fadeInUp 0.5s ease-out forwards;
    }

    /* Staggered animation for cards */
    .info-card {
        opacity: 0; /* Start hidden */
    }

    <?php for ($i = 1; $i <= 5; $i++): ?>
    .delay-<?php echo $i; ?> {
        animation-delay: <?php echo $i * 0.1; ?>s;
    }
    <?php endfor; ?>

    /* Interactive Card Hover Effect */
    .interactive-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .interactive-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    }
</style>

<div class="min-h-screen bg-gray-100 py-8" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
                <?php echo $error_message; ?>
            </div>
        <?php elseif ($expense): ?>

        <!-- Header -->
        <div class="fade-in-up bg-white rounded-2xl shadow-lg mb-8 p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">
                    تفاصيل المصروف
                </h1>
                <p class="text-gray-500 mt-1 font-mono text-lg"><?php echo htmlspecialchars($expense['expense_number']); ?></p>
            </div>
            <div class="flex space-x-2 space-x-reverse">
                <?php if ($canEdit): ?>
                <a href="edit.php?id=<?php echo $expense_id; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-md">
                    <i class="fas fa-edit ml-2"></i> تعديل
                </a>
                <?php endif; ?>
                <a href="print.php?id=<?php echo $expense_id; ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition shadow-md">
                    <i class="fas fa-print ml-2"></i> طباعة
                </a>
                <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للقائمة
                </a>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Main Column -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Financial Details Card -->
                <div class="fade-in-up delay-1 interactive-card bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="p-6 bg-gradient-to-r from-red-600 to-pink-500 text-white">
                        <div class="flex justify-between items-center">
                            <span class="text-xl font-semibold">المبلغ الإجمالي</span>
                            <?php
                                $status_text = 'غير محدد';
                                $status_bg = 'bg-gray-200 text-gray-800';
                                switch ($expense['payment_status']) {
                                    case 'paid':
                                        $status_text = 'مدفوع بالكامل';
                                        $status_bg = 'bg-amber-100 text-amber-800';
                                        break;
                                    case 'partial':
                                        $status_text = 'مدفوع جزئياً';
                                        $status_bg = 'bg-yellow-100 text-yellow-800';
                                        break;
                                    case 'pending':
                                        $status_text = 'غير مدفوع';
                                        $status_bg = 'bg-red-200 text-red-900';
                                        break;
                                }
                            ?>
                            <span class="px-3 py-1 text-sm font-bold rounded-full <?php echo $status_bg; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </div>
                        <p class="text-5xl font-bold mt-2"><?php echo number_format($expense['amount'], 0, '', ''); ?> <span class="text-3xl font-normal opacity-80">ريال</span></p>
                        <p class="text-red-100 mt-2 text-sm">
                            (<?php echo htmlspecialchars($expense['quantity']); ?> × <?php echo number_format($expense['unit_price'], 0, '', ''); ?> ريال)
                        </p>
                    </div>
                    <div class="p-6 grid grid-cols-2 gap-6 bg-gray-50">
                        <div class="text-center">
                            <p class="text-sm text-gray-500">المبلغ المدفوع</p>
                            <p class="text-2xl font-bold text-amber-600"><?php echo number_format($expense['paid_amount'], 0, '', ''); ?> ريال</p>
                        </div>
                        <div class="text-center">
                            <p class="text-sm text-gray-500">المبلغ المتبقي</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($expense['remaining_amount'], 0, '', ''); ?> ريال</p>
                        </div>
                    </div>
                </div>

                <!-- Description & Notes Card -->
                <div class="fade-in-up delay-2 interactive-card bg-white rounded-2xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-3">
                        <i class="fas fa-align-left text-blue-500 mr-2"></i>الوصف والملاحظات
                    </h3>
                    <div class="space-y-4">
                        <div>
                            <label class="text-sm font-semibold text-gray-600">الوصف</label>
                            <p class="text-gray-800 mt-1 whitespace-pre-wrap"><?php echo htmlspecialchars($expense['description']); ?></p>
                        </div>
                        <?php if (!empty($expense['notes'])): ?>
                        <div class="border-t pt-4">
                            <label class="text-sm font-semibold text-gray-600">الملاحظات</label>
                            <p class="text-gray-800 mt-1 bg-yellow-50 p-3 rounded-lg whitespace-pre-wrap"><?php echo htmlspecialchars($expense['notes']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar Column -->
            <div class="space-y-8">
                <!-- Core Info Card -->
                <div class="fade-in-up delay-3 info-card interactive-card bg-white rounded-2xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-3">
                        <i class="fas fa-info-circle text-purple-500 mr-2"></i>معلومات أساسية
                    </h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">تاريخ المصروف:</span> <span class="font-medium text-gray-900"><?php echo date('Y-m-d', strtotime($expense['expense_date'])); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">الفئة:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['category_name'] ?? 'غير محدد'); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">البند:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['item_name'] ?? 'لا يوجد'); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">طريقة الدفع:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($display_payment_method); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">العملة:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($display_currency); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">تم الإنشاء بواسطة:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['creator_name'] ?? 'غير معروف'); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">تاريخ الإنشاء:</span> <span class="font-medium text-gray-900"><?php echo date('Y-m-d H:i', strtotime($expense['created_at'])); ?></span></div>
                    </div>
                </div>
                
                <!-- Vendor Info Card -->
                <?php if (!empty($expense['vendor_name']) || !empty($expense['vendor_phone']) || !empty($expense['invoice_number'])): ?>
                <div class="fade-in-up delay-4 info-card interactive-card bg-white rounded-2xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-3">
                        <i class="fas fa-user-tie text-yellow-500 mr-2"></i>معلومات المورد
                    </h3>
                    <div class="space-y-3 text-sm">
                        <?php if (!empty($expense['vendor_name'])): ?>
                            <div class="flex justify-between"><span class="text-gray-500">اسم المورد:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['vendor_name']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($expense['vendor_phone'])): ?>
                            <div class="flex justify-between"><span class="text-gray-500">هاتف المورد:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['vendor_phone']); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($expense['invoice_number'])): ?>
                            <div class="flex justify-between"><span class="text-gray-500">رقم الفاتورة:</span> <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['invoice_number']); ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>