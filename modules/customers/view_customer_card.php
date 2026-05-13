<?php
/**
 * View Customer Card Details
 * - Displays all details of a specific customer card.
 * - Shows recent transactions for the card.
 */

date_default_timezone_set('Asia/Aden');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// Removed accounting_functions.php as it's no longer needed for adding money

$page_title = 'عرض تفاصيل بطاقة العميل';
$card = null;
$transactions = [];
$error_message = '';
$success_message = '';

$card_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($card_id <= 0) {
    header('Location: index.php'); // Redirect to cards list if no ID
    exit();
}

// Fetch card details
try {
    $stmt = $db->prepare("
        SELECT 
            cc.*,
            c.name as customer_name,
            u.username as created_by_username
        FROM customer_cards cc
        JOIN customers c ON cc.customer_id = c.id
        LEFT JOIN users u ON cc.created_by = u.id
        WHERE cc.id = ?
    ");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        $error_message = 'البطاقة غير موجودة.';
        // Optionally redirect or show a generic error page
        // header('Location: index.php?error=notfound'); exit();
    }

    // Fetch card transactions
    $trans_stmt = $db->prepare("
        SELECT 
            *,
            DATE(transaction_date) as transaction_date_only
        FROM customer_card_transactions 
        WHERE card_id = ? 
        ORDER BY transaction_date DESC
        LIMIT 10
    ");
    $trans_stmt->execute([$card_id]);
    $transactions = $trans_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'فشل في تحميل بيانات البطاقة: ' . $e->getMessage();
}

// Re-fetch card data if there was a redirect with success message (only for 'added' or 'updated')
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $success_message = 'تم إنشاء البطاقة بنجاح.';
    } elseif ($_GET['success'] == 'updated') {
        $success_message = 'تم تحديث بيانات البطاقة بنجاح.';
    }
    // Re-fetch card details after any successful operation to get updated data
    try {
        $stmt = $db->prepare("
            SELECT 
                cc.*,
                c.name as customer_name,
                u.username as created_by_username
            FROM customer_cards cc
            JOIN customers c ON cc.customer_id = c.id
            LEFT JOIN users u ON cc.created_by = u.id
            WHERE cc.id = ?
        ");
        $stmt->execute([$card_id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        $trans_stmt = $db->prepare("
            SELECT 
                *,
                DATE(transaction_date) as transaction_date_only
            FROM customer_card_transactions 
            WHERE card_id = ? 
            ORDER BY transaction_date DESC
            LIMIT 10
        ");
        $trans_stmt->execute([$card_id]);
        $transactions = $trans_stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error_message = 'فشل في إعادة تحميل بيانات البطاقة: ' . $e->getMessage();
    }
}

// Removed fetching bank accounts for top-up as the "add money" feature is removed

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header Section -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">تفاصيل بطاقة العميل</h1>
                        <p class="text-gray-600 mt-1">عرض وإدارة بطاقة العميل #<?php echo htmlspecialchars($card['card_number'] ?? 'N/A'); ?></p>
                    </div>
                    <!-- Back Navigation / Actions -->
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"><i class="fas fa-arrow-right ml-2"></i> العودة للبطاقات</a>
                        <?php if ($card): ?>
                            <a href="edit_customer_card.php?id=<?php echo $card['id']; ?>" class="inline-flex items-center px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition"><i class="fas fa-edit ml-2"></i> تعديل</a>
                            <!-- Removed the "Add Balance" button -->
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error Display -->
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">خطأ!</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Success Display -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border-r-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">نجاح!</p>
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($card): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Card Details -->
            <div class="lg:col-span-2 bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-800">بيانات البطاقة</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-y-4 gap-x-6">
                        <div>
                            <p class="text-sm text-gray-500">رقم البطاقة</p>
                            <p class="font-bold text-gray-900"><?php echo htmlspecialchars($card['card_number']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">العميل</p>
                            <p class="font-bold text-gray-900"><a href="../customers/view_enhanced.php?id=<?php echo $card['customer_id']; ?>" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($card['customer_name']); ?></a></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">الرصيد الحالي</p>
                            <p class="font-bold text-green-600 text-xl"><?php echo number_format($card['current_balance'], 2); ?> YER</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">المبلغ الأولي في البطاقة</p>
                            <p class="font-bold text-gray-900"><?php echo number_format($card['initial_amount'], 2); ?> YER</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">مبلغ الشراء (إيراد)</p>
                            <p class="font-bold text-gray-900"><?php echo number_format($card['purchase_amount'], 2); ?> YER</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">تاريخ الإصدار</p>
                            <p class="font-bold text-gray-900"><?php echo date('Y-m-d', strtotime($card['issue_date'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">تاريخ الانتهاء</p>
                            <p class="font-bold text-gray-900"><?php echo $card['expiry_date'] ? date('Y-m-d', strtotime($card['expiry_date'])) : 'غير محدد'; ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">الحالة</p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                switch($card['status']) {
                                    case 'active': echo 'bg-green-100 text-green-800'; break;
                                    case 'inactive': echo 'bg-gray-100 text-gray-800'; break;
                                    case 'expired': echo 'bg-red-100 text-red-800'; break;
                                    case 'blocked': echo 'bg-red-100 text-red-800'; break;
                                    default: echo 'bg-blue-100 text-blue-800'; break;
                                }
                            ?>">
                                <?php 
                                    $status_map = ['active' => 'نشط', 'inactive' => 'غير نشط', 'expired' => 'منتهي', 'blocked' => 'محظور'];
                                    echo htmlspecialchars($status_map[$card['status']] ?? $card['status']); 
                                ?>
                            </span>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">الملاحظات</p>
                            <p class="text-gray-900"><?php echo nl2br(htmlspecialchars($card['notes'] ?? 'لا يوجد')); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">تم الإنشاء بواسطة</p>
                            <p class="text-gray-900"><?php echo htmlspecialchars($card['created_by_username'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">تاريخ الإنشاء</p>
                            <p class="text-gray-900"><?php echo date('Y-m-d H:i', strtotime($card['created_at'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">آخر تحديث</p>
                            <p class="text-gray-900"><?php echo date('Y-m-d H:i', strtotime($card['updated_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="lg:col-span-1 bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-800">أحدث الحركات</h2>
                </div>
                <div class="p-6">
                    <?php if (!empty($transactions)): ?>
                        <ul class="divide-y divide-gray-200">
                            <?php foreach ($transactions as $trans): ?>
                                <li class="py-3 flex justify-between items-center">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($trans['description']); ?></p>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo date('Y-m-d H:i', strtotime($trans['transaction_date'])); ?></p>
                                    </div>
                                    <span class="text-sm font-semibold <?php echo $trans['transaction_type'] === 'load' ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo $trans['transaction_type'] === 'load' ? '+' : '-'; ?> <?php echo number_format($trans['amount'], 2); ?> YER
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-gray-600 text-center">لا توجد حركات لهذه البطاقة بعد.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php else: ?>
            <div class="bg-blue-100 border-r-4 border-blue-500 text-blue-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">معلومة</p>
                <p>الرجاء التأكد من صحة رقم البطاقة.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Removed Add Money Modal entirely -->
<!-- Removed Add Money Modal JavaScript entirely -->

<?php include '../../includes/footer.php'; ?>