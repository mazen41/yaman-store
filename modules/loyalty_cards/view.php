<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'loyalty_cards', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض هذه البطاقة';
    header('Location: index.php');
    exit();
}

$page_title = 'عرض بطاقة الهدية';
$error_message = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = 'معرّف البطاقة غير صحيح';
    header('Location: index.php');
    exit();
}

$id = (int) $_GET['id'];

try {
    $stmt = $db->prepare('SELECT * FROM loyalty_cards WHERE id = ?');
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
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white rounded-2xl shadow-lg mb-8 p-6 flex justify-between items-center">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold mb-1">تفاصيل بطاقة الهدية</h1>
                <p class="text-blue-100 text-sm sm:text-base">
                    عرض جميع معلومات البطاقة ورصيدها وحالتها
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

        <!-- Main Card Info -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-white rounded-xl shadow-md p-6 space-y-4">
                <h2 class="text-lg font-bold text-gray-800 border-b pb-3 mb-3 flex items-center gap-2">
                    <i class="fas fa-id-card-alt text-blue-500"></i>
                    معلومات البطاقة الأساسية
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <?php
                    // محاولة استخدام بعض الحقول المعروفة إذا كانت موجودة
                    $knownFields = [
                        'card_number' => 'رقم البطاقة',
                        'card_code'   => 'كود البطاقة',
                        'card_name'   => 'اسم البطاقة',
                        'status'      => 'الحالة',
                        'currency'    => 'العملة',
                        'initial_balance' => 'الرصيد الابتدائي',
                        'current_balance' => 'الرصيد الحالي',
                        'balance'         => 'الرصيد الحالي',
                        'expiry_date'     => 'تاريخ الانتهاء',
                        'created_at'      => 'تاريخ الإنشاء',
                        'updated_at'      => 'تاريخ آخر تعديل',
                    ];

                    foreach ($knownFields as $field => $label):
                        if (!array_key_exists($field, $card)) continue;
                        $value = $card[$field];
                        if ($value === null || $value === '') $value = '-';
                    ?>
                        <div class="flex flex-col">
                            <span class="text-gray-500 text-xs mb-1"><?php echo $label; ?></span>
                            <span class="font-semibold text-gray-900">
                                <?php echo htmlspecialchars((string)$value); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Summary Box -->
            <div class="space-y-4">
                <div class="bg-white rounded-xl shadow-md p-5 space-y-3">
                    <h3 class="text-md font-bold text-gray-800 flex items-center gap-2 mb-2">
                        <i class="fas fa-wallet text-emerald-500"></i>
                        ملخص الرصيد
                    </h3>
                    <?php
                    $initial = isset($card['initial_balance']) ? (float)$card['initial_balance'] : null;
                    $current = isset($card['current_balance']) ? (float)$card['current_balance'] : (isset($card['balance']) ? (float)$card['balance'] : null);
                    $used    = null;
                    if ($initial !== null && $current !== null) {
                        $used = $initial - $current;
                    }
                    ?>
                    <div class="space-y-2 text-sm">
                        <?php if ($initial !== null): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-500">الرصيد الابتدائي:</span>
                            <span class="font-semibold text-blue-600"><?php echo number_format($initial, 2); ?> ريال</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($used !== null): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-500">المستخدم حتى الآن:</span>
                            <span class="font-semibold text-amber-600"><?php echo number_format($used, 2); ?> ريال</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($current !== null): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-500">الرصيد الحالي:</span>
                            <span class="font-bold text-emerald-600"><?php echo number_format($current, 2); ?> ريال</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($initial === null && $current === null): ?>
                        <p class="text-gray-500 text-xs">لا توجد معلومات رصيد محددة لهذه البطاقة في قاعدة البيانات.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="index.php" class="block text-center w-full bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-3 rounded-lg shadow-sm transition">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة لقائمة البطاقات
                </a>
            </div>
        </div>

        <!-- All raw fields table -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <h2 class="text-lg font-bold text-gray-800 border-b pb-3 mb-4 flex items-center gap-2">
                <i class="fas fa-database text-gray-500"></i>
                جميع بيانات البطاقة (من الجدول loyalty_cards)
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
