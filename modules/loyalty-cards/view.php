<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'عرض بطاقة الهدية / الولاء';
$error_message = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = 'معرّف البطاقة غير صحيح';
    header('Location: index.php');
    exit();
}

$id = (int) $_GET['id'];

try {
    // جلب بيانات البطاقة مع عدد المعاملات وآخر عملية (بنفس منطق index)
    $stmt = $db->prepare("SELECT lc.*, 
                                  (SELECT COUNT(*) FROM loyalty_card_transactions t WHERE t.card_id = lc.id) AS transaction_count,
                                  (SELECT MAX(transaction_date) FROM loyalty_card_transactions t2 WHERE t2.card_id = lc.id) AS last_transaction_date
                           FROM loyalty_cards lc
                           WHERE lc.id = ?");
    $stmt->execute([$id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        $_SESSION['error_message'] = 'لم يتم العثور على البطاقة المطلوبة';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء تحميل بيانات البطاقة: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-gradient-to-r from-pink-600 to-purple-700 text-white rounded-2xl shadow-lg mb-8 p-6 flex justify-between items-center">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold mb-1">تفاصيل بطاقة الهدية / الولاء</h1>
                <p class="text-pink-100 text-sm sm:text-base">
                    عرض جميع معلومات البطاقة ورصيدها وحالتها وسجل العمليات
                </p>
            </div>
            <div class="hidden sm:flex items-center justify-center w-12 h-12 rounded-full bg-white bg-opacity-20">
                <i class="fas fa-gift text-2xl"></i>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
                <p class="font-semibold mb-1">خطأ</p>
                <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Basic Info -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-md p-6 space-y-4">
                <div class="flex justify-between items-center mb-4">
                    <div>
                        <p class="text-xs text-gray-500 mb-1">رقم البطاقة</p>
                        <p class="text-2xl font-extrabold text-gray-900 tracking-widest">
                            <?php echo htmlspecialchars($card['card_number']); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-300 mb-1">نوع البطاقة</p>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                            <?php echo $card['card_type'] === 'gift' ? 'bg-pink-100 text-pink-700' : ($card['card_type'] === 'loyalty' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'); ?>">
                            <i class="fas fa-tag ml-1"></i>
                            <?php
                            $type_badges = [
                                'gift' => 'بطاقة هدية',
                                'loyalty' => 'بطاقة ولاء',
                                'promotional' => 'بطاقة ترويجية',
                            ];
                            echo $type_badges[$card['card_type']] ?? $card['card_type'];
                            ?>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs mb-1">اسم العميل</span>
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($card['customer_name'] ?: 'غير محدد'); ?></span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs mb-1">رقم الهاتف</span>
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($card['customer_phone'] ?: '-'); ?></span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs mb-1">تاريخ الإنشاء</span>
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($card['created_at']); ?></span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs mb-1">تاريخ الانتهاء</span>
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($card['expiry_date'] ?? '-'); ?></span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs mb-1">عدد المعاملات</span>
                        <span class="font-semibold text-blue-600"><?php echo (int)($card['transaction_count'] ?? 0); ?></span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs mb-1">آخر عملية</span>
                        <span class="font-semibold text-gray-900">
                            <?php echo $card['last_transaction_date'] ? date('Y-m-d H:i', strtotime($card['last_transaction_date'])) : '-'; ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($card['notes'])): ?>
                <div class="mt-4">
                    <span class="text-gray-500 text-xs mb-1 block">ملاحظات</span>
                    <p class="text-sm text-gray-800 bg-gray-50 border border-gray-100 rounded-lg p-3 leading-relaxed">
                        <?php echo nl2br(htmlspecialchars($card['notes'])); ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Balance Summary -->
            <div class="space-y-4">
                <div class="bg-white rounded-xl shadow-md p-5 space-y-3">
                    <h3 class="text-md font-bold text-gray-800 flex items-center gap-2 mb-2">
                        <i class="fas fa-wallet text-amber-500"></i>
                        ملخص الأرصدة
                    </h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">الرصيد الحالي:</span>
                            <span class="font-bold text-emerald-600"><?php echo number_format($card['current_balance'], 0, '', ''); ?> ر.ي</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">رصيد المكافأة:</span>
                            <span class="font-bold text-purple-600"><?php echo number_format($card['bonus_balance'], 0, '', ''); ?> ر.ي</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">إجمالي الإنفاق:</span>
                            <span class="font-bold text-red-600"><?php echo number_format($card['total_spent'], 0, '', ''); ?> ر.ي</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-md p-5 space-y-3">
                    <h3 class="text-md font-bold text-gray-800 flex items-center gap-2 mb-2">
                        <i class="fas fa-info-circle text-blue-500"></i>
                        حالة البطاقة
                    </h3>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-500 text-sm">الحالة الحالية:</span>
                        <span class="text-sm font-semibold">
                            <?php
                            $status_badges = [
                                'active' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800"><i class="fas fa-check-circle ml-1"></i> نشطة</span>',
                                'inactive' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800"><i class="fas fa-pause-circle ml-1"></i> غير نشطة</span>',
                                'expired' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800"><i class="fas fa-clock ml-1"></i> منتهية</span>',
                                'blocked' => '<span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-ban ml-1"></i> محظورة</span>'
                            ];
                            echo $status_badges[$card['status']] ?? htmlspecialchars($card['status']);
                            ?>
                        </span>
                    </div>
                </div>

                <a href="transactions.php?card_id=<?php echo $card['id']; ?>" class="block text-center w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg shadow-md transition">
                    <i class="fas fa-history ml-2"></i>
                    عرض سجل المعاملات
                </a>

                <a href="index.php" class="block text-center w-full bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-3 rounded-lg shadow-sm transition">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة لقائمة البطاقات
                </a>
            </div>
        </div>

        <!-- Raw data table -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <h2 class="text-lg font-bold text-gray-800 border-b pb-3 mb-4 flex items-center gap-2">
                <i class="fas fa-database text-gray-500"></i>
                جميع بيانات البطاقة (من جدول loyalty_cards)
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">الحقل</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">القيمة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($card as $key => $value): ?>
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars($key); ?></td>
                                <td class="px-4 py-2 text-gray-800">
                                    <?php
                                    if ($value === null || $value === '') {
                                        echo '<span class="text-gray-400">-</span>';
                                    } else {
                                        echo nl2br(htmlspecialchars((string)$value));
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
