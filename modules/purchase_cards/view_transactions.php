<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تقارير البطاقة';
$card_id = intval($_GET['id'] ?? 0);

if (!$card_id) {
    header('Location: index.php');
    exit();
}

// Display messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Fetch card details
try {
    $stmt = $db->prepare("SELECT * FROM purchase_cards WHERE id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card) {
        $_SESSION['error_message'] = 'البطاقة غير موجودة';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'حدث خطأ في جلب بيانات البطاقة';
    header('Location: index.php');
    exit();
}

// Fetch transactions
try {
    $transactions_stmt = $db->prepare("
        SELECT 
            pct.*,
            u.full_name as created_by_name
        FROM purchase_card_transactions pct
        LEFT JOIN users u ON pct.created_by = u.id
        WHERE pct.purchase_card_id = ?
        ORDER BY pct.created_at DESC
    ");
    $transactions_stmt->execute([$card_id]);
    $transactions = $transactions_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    $error_message = 'حدث خطأ في جلب المعاملات: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
.transaction-add { background: #d1fae5; color: #065f46; }
.transaction-deduct { background: #fee2e2; color: #991b1b; }
.transaction-transfer { background: #dbeafe; color: #1e40af; }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            <i class="fas fa-file-invoice-dollar mr-3"></i>
                            تقارير البطاقة
                        </h1>
                        <p class="text-purple-100">سجل جميع العمليات على البطاقة</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="add_balance.php?id=<?php echo $card_id; ?>" 
                           class="bg-white text-amber-600 px-6 py-3 rounded-lg font-bold hover:bg-amber-50 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                            <i class="fas fa-plus-circle ml-2"></i>
                            إضافة رصيد
                        </a>
                        <a href="index.php" 
                           class="bg-purple-800 text-white px-6 py-3 rounded-lg font-bold hover:bg-purple-900 transition-all duration-300 shadow-lg">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Card Summary -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-blue-500">
                <p class="text-sm text-gray-600 mb-1">رقم البطاقة</p>
                <p class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($card['card_number']); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-purple-500">
                <p class="text-sm text-gray-600 mb-1">اسم البطاقة</p>
                <p class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($card['card_name']); ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-orange-500">
                <p class="text-sm text-gray-600 mb-1">مبلغ الشراء الأصلي</p>
                <p class="text-2xl font-bold text-orange-600"><?php echo number_format($card['purchase_amount'], 2); ?> ر.س</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-6 border-r-4 border-amber-500">
                <p class="text-sm text-gray-600 mb-1">الرصيد الحالي</p>
                <p class="text-2xl font-bold text-amber-600"><?php echo number_format($card['balance'], 2); ?> ر.س</p>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b-2">
                <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-history text-purple-600"></i>
                    سجل العمليات (<?php echo count($transactions); ?>)
                </h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-purple-50 to-indigo-50">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase">نوع العملية</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase">المبلغ</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase">الرصيد بعد العملية</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase">الملاحظات</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase">المستخدم</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase">التاريخ</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500 text-lg font-medium">لا توجد عمليات على هذه البطاقة</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($transaction['transaction_type'] === 'add_balance'): ?>
                                            <span class="transaction-add px-3 py-1 rounded-full text-sm font-bold">
                                                <i class="fas fa-plus-circle"></i> إضافة رصيد
                                            </span>
                                        <?php elseif ($transaction['transaction_type'] === 'purchase'): ?>
                                            <span class="transaction-deduct px-3 py-1 rounded-full text-sm font-bold">
                                                <i class="fas fa-shopping-cart"></i> شراء
                                            </span>
                                        <?php elseif ($transaction['transaction_type'] === 'deduct'): ?>
                                            <span class="transaction-deduct px-3 py-1 rounded-full text-sm font-bold">
                                                <i class="fas fa-minus-circle"></i> خصم
                                            </span>
                                        <?php elseif ($transaction['transaction_type'] === 'refund'): ?>
                                            <span class="transaction-add px-3 py-1 rounded-full text-sm font-bold">
                                                <i class="fas fa-undo"></i> استرجاع
                                            </span>
                                        <?php else: ?>
                                            <span class="transaction-transfer px-3 py-1 rounded-full text-sm font-bold">
                                                <i class="fas fa-exchange-alt"></i> تحويل
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="font-bold text-lg <?php echo $transaction['transaction_type'] === 'add_balance' ? 'text-amber-600' : 'text-red-600'; ?>">
                                            <?php echo $transaction['transaction_type'] === 'add_balance' ? '+' : '-'; ?>
                                            <?php echo number_format($transaction['amount'], 2); ?> ر.س
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="font-bold text-gray-700">
                                            <?php echo number_format($transaction['balance_after'], 2); ?> ر.س
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm">
                                            <?php if (!empty($transaction['description'])): ?>
                                                <p class="text-gray-700 font-medium"><?php echo htmlspecialchars($transaction['description']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['reference_type']) && !empty($transaction['reference_id'])): ?>
                                                <p class="text-gray-500 text-xs mt-1">
                                                    <i class="fas fa-link"></i> 
                                                    <?php if ($transaction['reference_type'] === 'basket'): ?>
                                                        <a href="/modules/purchases/view_basket.php?id=<?php echo $transaction['reference_id']; ?>" class="text-blue-600 hover:underline">
                                                            عرض السلة #<?php echo $transaction['reference_id']; ?>
                                                        </a>
                                                    <?php elseif ($transaction['reference_type'] === 'order'): ?>
                                                        <a href="/modules/orders/view.php?id=<?php echo $transaction['reference_id']; ?>" class="text-blue-600 hover:underline">
                                                            عرض الطلب #<?php echo $transaction['reference_id']; ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($transaction['reference_type']); ?> #<?php echo $transaction['reference_id']; ?>
                                                    <?php endif; ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($transaction['notes'])): ?>
                                                <p class="text-gray-600 text-xs mt-1"><?php echo htmlspecialchars($transaction['notes']); ?></p>
                                            <?php endif; ?>
                                            <?php if (empty($transaction['description']) && empty($transaction['notes'])): ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-sm text-gray-700">
                                            <?php echo htmlspecialchars($transaction['created_by_name'] ?: 'غير محدد'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm">
                                            <div class="font-medium text-gray-900">
                                                <?php echo date('Y/m/d', strtotime($transaction['created_at'])); ?>
                                            </div>
                                            <div class="text-gray-500">
                                                <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
