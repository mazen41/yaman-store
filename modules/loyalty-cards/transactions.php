<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$card_id = filter_input(INPUT_GET, 'card_id', FILTER_VALIDATE_INT);

if (!$card_id) {
    header('Location: index.php');
    exit();
}

// Fetch card details for the header
try {
    $card_stmt = $db->prepare("SELECT * FROM loyalty_cards WHERE id = ?");
    $card_stmt->execute([$card_id]);
    $card = $card_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        $_SESSION['error_message'] = 'البطاقة المطلوبة غير موجودة.';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'خطأ في جلب بيانات البطاقة.';
    header('Location: index.php');
    exit();
}

$page_title = 'سجل معاملات البطاقة: ' . htmlspecialchars($card['card_number']);

// Pagination setup
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Fetch transactions for the card
try {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM loyalty_card_transactions WHERE card_id = ?");
    $count_stmt->execute([$card_id]);
    $total_transactions = $count_stmt->fetchColumn();
    $total_pages = ceil($total_transactions / $limit);

    $trans_stmt = $db->prepare("SELECT * FROM loyalty_card_transactions WHERE card_id = ? ORDER BY transaction_date DESC LIMIT ? OFFSET ?");
    $trans_stmt->bindParam(1, $card_id, PDO::PARAM_INT);
    $trans_stmt->bindParam(2, $limit, PDO::PARAM_INT);
    $trans_stmt->bindParam(3, $offset, PDO::PARAM_INT);
    $trans_stmt->execute();
    $transactions = $trans_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    $error_message = 'فشل في تحميل سجل المعاملات: ' . $e->getMessage();
}

// Helper for translating transaction types
$transaction_types_ar = [
    'purchase' => ['text' => 'شراء', 'color' => 'red', 'icon' => 'fas fa-shopping-cart'],
    'refund' => ['text' => 'إرجاع', 'color' => 'green', 'icon' => 'fas fa-undo'],
    'transfer_in' => ['text' => 'استلام رصيد', 'color' => 'green', 'icon' => 'fas fa-arrow-down'],
    'transfer_out' => ['text' => 'تحويل رصيد', 'color' => 'red', 'icon' => 'fas fa-arrow-up'],
    'add_balance' => ['text' => 'إضافة رصيد', 'color' => 'green', 'icon' => 'fas fa-plus-circle'],
    'bonus' => ['text' => 'مكافأة', 'color' => 'purple', 'icon' => 'fas fa-gift'],
];

include '../../includes/header.php';
?>

<style>
    .transaction-row:hover { background-color: #f9fafb; }
    .amount-in { color: #C7A46D; font-weight: bold; }
    .amount-out { color: #dc2626; font-weight: bold; }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-purple-600 to-indigo-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-white mb-2">
                            <i class="fas fa-history mr-3"></i>
                            <?php echo $page_title; ?>
                        </h1>
                        <p class="text-purple-100">العميل: <?php echo htmlspecialchars($card['customer_name'] ?: 'غير محدد'); ?></p>
                    </div>
                    <a href="index.php" class="bg-white text-purple-600 px-6 py-3 rounded-lg font-bold hover:bg-purple-50 transition-all duration-300 shadow-lg">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للقائمة
                    </a>
                </div>
            </div>
             <!-- Balances Summary -->
            <div class="bg-black bg-opacity-20 grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-gray-500 divide-opacity-30">
                <div class="p-4 text-center text-white">
                    <p class="text-sm opacity-80">الرصيد الحالي</p>
                    <p class="text-2xl font-bold"><?php echo number_format($card['current_balance'], 0, '', ''); ?> ر.ي</p>
                </div>
                <div class="p-4 text-center text-white">
                    <p class="text-sm opacity-80">رصيد المكافأة</p>
                    <p class="text-2xl font-bold"><?php echo number_format($card['bonus_balance'], 0, '', ''); ?> ر.ي</p>
                </div>
                <div class="p-4 text-center text-white">
                    <p class="text-sm opacity-80">إجمالي الإنفاق</p>
                    <p class="text-2xl font-bold"><?php echo number_format($card['total_spent'], 0, '', ''); ?> ر.ي</p>
                </div>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Transactions Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
             <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">التاريخ والوقت</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">نوع العملية</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">المبلغ</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">الرصيد قبل / بعد</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">الوصف</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-folder-open text-4xl mb-3"></i>
                                    <p>لا توجد معاملات لعرضها لهذه البطاقة.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr class="transaction-row">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?php echo date("Y-m-d H:i", strtotime($tx['transaction_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php 
                                            $type_info = $transaction_types_ar[$tx['transaction_type']] ?? ['text' => $tx['transaction_type'], 'color' => 'gray', 'icon' => 'fas fa-question-circle'];
                                        ?>
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo $type_info['color']; ?>-100 text-<?php echo $type_info['color']; ?>-800">
                                            <i class="<?php echo $type_info['icon']; ?> ml-1"></i>
                                            <?php echo $type_info['text']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-lg <?php echo in_array($tx['transaction_type'], ['purchase', 'transfer_out']) ? 'amount-out' : 'amount-in'; ?>">
                                        <?php echo in_array($tx['transaction_type'], ['purchase', 'transfer_out']) ? '-' : '+'; ?>
                                        <?php echo number_format($tx['amount'], 0, '', ''); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="text-gray-500">قبل: <?php echo number_format($tx['balance_before'], 0, '', ''); ?></div>
                                        <div class="text-gray-900 font-semibold">بعد: <?php echo number_format($tx['balance_after'], 0, '', ''); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700 max-w-xs break-words"><?php echo htmlspecialchars($tx['description'] ?: 'لا يوجد'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

             <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                    <span class="text-sm text-gray-700">صفحة <?php echo $page; ?> من <?php echo $total_pages; ?></span>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="?card_id=<?php echo $card_id; ?>&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition-colors">السابق</a>
                        <?php endif; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?card_id=<?php echo $card_id; ?>&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition-colors">التالي</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>