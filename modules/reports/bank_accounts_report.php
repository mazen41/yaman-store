<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'سجل الحركات المالي الشامل';

// Date filters
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date']   ?? '';

// Search filter
$account_name_filter = trim($_GET['account_name'] ?? '');

// Currency 
$selected_currency = $_GET['currency'] ?? 'YER';
$currency_symbol = ($selected_currency == 'SAR') ? 'ر.س' : 'ر.ي';

$all_transactions = [];

// ========================================================================
// 1. جلب بيانات الإيداعات (استخدام LEFT JOIN لضمان ظهور الحركات حتى لو حذف الحساب)
// ========================================================================
try {
    $query1 = "SELECT bt.*, ba.bank_name, ba.account_name, ba.account_number 
               FROM bank_transactions bt 
               LEFT JOIN bank_accounts ba ON bt.bank_account_id = ba.id";
    $stmt1 = $db->query($query1);
    while($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
        // البحث عن أي عمود يمثل التاريخ والنوع لتجنب أخطاء اختلاف أسماء الأعمدة
        $date = $row['transaction_date'] ?? $row['created_at'] ?? $row['date'] ?? date('Y-m-d H:i:s');
        $type = $row['transaction_type'] ?? $row['type'] ?? 'deposit';
        $desc = $row['description'] ?? $row['notes'] ?? $row['note'] ?? '';
        
        // معالجة حالة الحساب المحذوف
        $bank_info = 'حساب محذوف أو غير معروف';
        if (!empty($row['bank_name']) || !empty($row['account_name'])) {
            $bank_info = trim(($row['bank_name'] ?? '') . ' - ' . ($row['account_name'] ?? ''), ' -');
        }

        $all_transactions[] = [
            'date' => $date,
            'account' => $bank_info,
            'acc_num' => $row['account_number'] ?? '-',
            'type' => $type,
            'amount' => $row['amount'] ?? 0,
            'balance_before' => $row['balance_before'] ?? null,
            'balance_after' => $row['balance_after'] ?? null,
            'desc' => $desc,
            'source' => 'bank'
        ];
    }
} catch (Exception $e) { 
    // تم التعليق لمعرفة الخطأ في حال أردت تتبع المشاكل مستقبلاً
    // error_log("Bank Transactions Error: " . $e->getMessage()); 
}

// ========================================================================
// 2. جلب بيانات التحويلات (استخدام LEFT JOIN)
// ========================================================================
try {
    $query2 = "SELECT bat.*, ba.bank_name, ba.account_name, ba.account_number 
               FROM bank_account_transactions bat 
               LEFT JOIN bank_accounts ba ON bat.account_id = ba.id";
    $stmt2 = $db->query($query2);
    while($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $date = $row['created_at'] ?? $row['transaction_date'] ?? $row['date'] ?? date('Y-m-d H:i:s');
        $type = $row['transaction_type'] ?? $row['type'] ?? 'transfer';
        $desc = $row['description'] ?? $row['notes'] ?? $row['note'] ?? '';
        
        $bank_info = 'حساب محذوف أو غير معروف';
        if (!empty($row['bank_name']) || !empty($row['account_name'])) {
            $bank_info = trim(($row['bank_name'] ?? '') . ' - ' . ($row['account_name'] ?? ''), ' -');
        }

        $all_transactions[] = [
            'date' => $date,
            'account' => $bank_info,
            'acc_num' => $row['account_number'] ?? '-',
            'type' => $type,
            'amount' => $row['amount'] ?? 0,
            'balance_before' => $row['balance_before'] ?? null,
            'balance_after' => $row['balance_after'] ?? null,
            'desc' => $desc,
            'source' => 'bank'
        ];
    }
} catch (Exception $e) { }

// ========================================================================
// 3. جلب بيانات الخزينة (الكاش)
// ========================================================================
try {
    $query3 = "SELECT * FROM cash_transactions";
    $stmt3 = $db->query($query3);
    while($row = $stmt3->fetch(PDO::FETCH_ASSOC)) {
        $date = $row['created_at'] ?? $row['transaction_date'] ?? $row['date'] ?? date('Y-m-d H:i:s');
        $type = $row['transaction_type'] ?? $row['type'] ?? 'cash';
        $desc = $row['description'] ?? $row['notes'] ?? $row['note'] ?? '';

        $all_transactions[] = [
            'date' => $date,
            'account' => 'الخزينة (كاش)',
            'acc_num' => '-',
            'type' => $type,
            'amount' => $row['amount'] ?? 0,
            'balance_before' => $row['balance_before'] ?? null,
            'balance_after' => $row['balance_after'] ?? null,
            'desc' => $desc,
            'source' => 'cash'
        ];
    }
} catch (Exception $e) { }

// ========================================================================
// تطبيق الفلاتر والترتيب 
// ========================================================================
$filtered_transactions = [];
$total_bank_in = 0; $total_bank_out = 0;
$total_cash_in = 0; $total_cash_out = 0;

foreach ($all_transactions as $txn) {
    $txn_time = strtotime($txn['date']);
    
    // فلتر التاريخ (تم تحسينه لتجنب إخفاء الحركات بسبب أخطاء التاريخ)
    if ($txn_time !== false) {
        if (!empty($start_date) && $txn_time < strtotime($start_date . ' 00:00:00')) continue;
        if (!empty($end_date) && $txn_time > strtotime($end_date . ' 23:59:59')) continue;
    }
    
    // فلتر البحث (في اسم الحساب أو الوصف)
    if (!empty($account_name_filter)) {
        $search = mb_strtolower($account_name_filter);
        $acc = mb_strtolower($txn['account']);
        $desc = mb_strtolower($txn['desc']);
        if (strpos($acc, $search) === false && strpos($desc, $search) === false) {
            continue;
        }
    }
    
    $filtered_transactions[] = $txn;
    
    // حساب الإحصائيات
    $amt = (float)$txn['amount'];
    $type = strtolower($txn['type']);
    
    $isIn = in_array($type, ['transfer_in', 'deposit', 'in']);
    $isOut = in_array($type, ['transfer_out', 'withdraw', 'out']);

    if ($txn['source'] === 'cash') {
        if ($isIn) $total_cash_in += $amt;
        elseif ($isOut) $total_cash_out += $amt;
    } else {
        if ($isIn) $total_bank_in += $amt;
        elseif ($isOut) $total_bank_out += $amt;
    }
}

// ترتيب المصفوفة من الأحدث إلى الأقدم
usort($filtered_transactions, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$total_transactions = count($filtered_transactions);

// دالة لتلوين نوع الحركة (تم تحسينها لدعم أنواع غير معروفة دون أن تتعطل)
function getTransactionLabel($type) {
    $t = strtolower($type);
    if (in_array($t, ['transfer_in', 'deposit', 'in'])) {
        $text = ($t == 'in') ? 'وارد خزينة' : (($t == 'deposit') ? 'إيداع نقدي' : 'تحويل وارد');
        return '<span class="bg-green-100 text-green-800 text-xs px-3 py-1 rounded-full border border-green-200"><i class="fas fa-arrow-down ml-1"></i> '.$text.'</span>';
    } elseif (in_array($t, ['transfer_out', 'withdraw', 'out'])) {
        $text = ($t == 'out') ? 'منصرف خزينة' : (($t == 'withdraw') ? 'سحب نقدي' : 'تحويل صادر');
        return '<span class="bg-red-100 text-red-800 text-xs px-3 py-1 rounded-full border border-red-200"><i class="fas fa-arrow-up ml-1"></i> '.$text.'</span>';
    } else {
        return '<span class="bg-gray-100 text-gray-800 text-xs px-3 py-1 rounded-full border border-gray-200"><i class="fas fa-exchange-alt ml-1"></i> '.htmlspecialchars($type).'</span>';
    }
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    
    <!-- Header -->
    <div class="bg-gradient-to-br from-blue-600 to-indigo-700 shadow-lg rounded-xl mb-8 p-6">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div>
                <h1 class="text-3xl font-bold text-white flex items-center gap-3">
                    <i class="fas fa-list-alt"></i>
                    السجل الشامل للحركات المالية
                </h1>
                <p class="text-blue-100 mt-2 opacity-90">سجل مفصل لجميع (الإيداعات، التحويلات، الخزينة الكاش)</p>
            </div>
            <div class="flex items-center gap-4">
                <a href="bank_accounts.php" class="px-6 py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 font-semibold transition-colors duration-200 flex items-center gap-2 shadow-sm">
                    إدارة الحسابات
                    <i class="fas fa-university"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <input type="hidden" name="currency" value="<?php echo htmlspecialchars($selected_currency); ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">إلى تاريخ</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">بحث (اسم بنك / تفاصيل)</label>
                <input type="text" name="account_name" value="<?php echo htmlspecialchars($account_name_filter); ?>" 
                       placeholder="ابحث هنا..." 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-semibold transition-colors duration-200 flex items-center justify-center gap-2 shadow-sm">
                    <i class="fas fa-filter"></i> تصفية
                </button>
                <a href="?" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-lg transition-colors duration-200 flex items-center justify-center" title="إلغاء الفلترة">
                    <i class="fas fa-redo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md border-r-4 border-r-blue-500">
            <p class="text-gray-500 text-sm font-medium mb-1">إجمالي الحركات المكتشفة</p>
            <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_transactions, 0, ',', '.'); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md border-r-4 border-r-green-500">
            <p class="text-gray-500 text-sm font-medium mb-1">إجمالي وارد البنوك</p>
            <p class="text-lg font-bold text-green-600 dir-ltr text-left"><?php echo number_format($total_bank_in, 2); ?> <span class="text-xs text-gray-500"><?php echo $currency_symbol; ?></span></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md border-r-4 border-r-red-500">
            <p class="text-gray-500 text-sm font-medium mb-1">إجمالي صادر البنوك</p>
            <p class="text-lg font-bold text-red-600 dir-ltr text-left"><?php echo number_format($total_bank_out, 2); ?> <span class="text-xs text-gray-500"><?php echo $currency_symbol; ?></span></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md border-r-4 border-r-emerald-500">
            <p class="text-gray-500 text-sm font-medium mb-1">إجمالي وارد الخزينة</p>
            <p class="text-lg font-bold text-emerald-600 dir-ltr text-left"><?php echo number_format($total_cash_in, 2); ?> <span class="text-xs text-gray-500"><?php echo $currency_symbol; ?></span></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md border-r-4 border-r-rose-500">
            <p class="text-gray-500 text-sm font-medium mb-1">إجمالي منصرف الخزينة</p>
            <p class="text-lg font-bold text-rose-600 dir-ltr text-left"><?php echo number_format($total_cash_out, 2); ?> <span class="text-xs text-gray-500"><?php echo $currency_symbol; ?></span></p>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">التاريخ والوقت</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">الحساب / البنك</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">نوع الحركة</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">المبلغ</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">الرصيد قبل</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">الرصيد بعد</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">الملاحظات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($filtered_transactions)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-folder-open text-4xl text-gray-300 mb-3 block"></i>
                                <p class="text-lg font-medium text-gray-900">لا توجد حركات مسجلة تطابق بحثك</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filtered_transactions as $txn): ?>
                            <?php 
                                $isIn = in_array(strtolower($txn['type']), ['transfer_in', 'deposit', 'in']);
                                $isOut = in_array(strtolower($txn['type']), ['transfer_out', 'withdraw', 'out']);
                                
                                $amountClass = $isIn ? 'text-green-600' : ($isOut ? 'text-red-600' : 'text-gray-600');
                                $amountPrefix = $isIn ? '+' : ($isOut ? '-' : '');
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" dir="ltr">
                                    <?php echo date('Y-m-d h:i A', strtotime($txn['date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($txn['account']); ?></div>
                                    <?php if ($txn['acc_num'] && $txn['acc_num'] !== '-'): ?>
                                        <div class="text-xs text-gray-500 font-mono mt-1">#<?php echo htmlspecialchars($txn['acc_num']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php echo getTransactionLabel($txn['type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?php echo $amountClass; ?> text-left" dir="ltr">
                                    <?php echo $amountPrefix . ' ' . number_format($txn['amount'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-left" dir="ltr">
                                    <?php echo ($txn['balance_before'] !== null) ? number_format($txn['balance_before'], 2) : '<span class="opacity-50">-</span>'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-800 text-left" dir="ltr">
                                    <?php echo ($txn['balance_after'] !== null) ? number_format($txn['balance_after'], 2) : '<span class="opacity-50">-</span>'; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700 min-w-[200px]">
                                    <?php echo htmlspecialchars($txn['desc'] ?? ''); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>