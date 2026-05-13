<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'financial', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$page_title = 'إدارة الحسابات المالية';

// Get date range for filtering
$from_date = $_GET['from_date'] ?? date('Y-m-01'); // First day of current month
$to_date = $_GET['to_date'] ?? date('Y-m-d'); // Today

// --- CORRECTED CALCULATION LOGIC ---

// 1. Calculate Total Revenue (This part is correct and remains unchanged)
$revenue_stmt = $db->prepare("
    SELECT SUM(amount) as total_revenue 
    FROM customer_payments 
    WHERE payment_date BETWEEN ? AND ?
");
$revenue_stmt->execute([$from_date, $to_date]);
$revenue_result = $revenue_stmt->fetch(PDO::FETCH_ASSOC);
$total_revenue = $revenue_result['total_revenue'] ?? 0;


// 2. CORRECTED: Calculate Total Expenses directly from the 'expenses' table with currency conversion.
// ===============================================================================================

// **IMPORTANT**: You must provide the current exchange rates.
// Ideally, you would fetch these from a settings or exchange_rates table in your database.
// For now, we will define them here. Please update these values regularly.
$usd_to_yer_rate = 535; // Example: 1 USD = 535 YER
$sar_to_yer_rate = 142; // Example: 1 SAR = 142 YER
$base_currency = 'YER'; // The main currency of your report

// This new query converts all amounts to the base currency BEFORE summing them.
$expenses_stmt = $db->prepare("
    SELECT SUM(
        CASE
            WHEN currency = :base_currency THEN amount
            WHEN currency = 'USD' THEN amount * :usd_rate
            WHEN currency = 'SAR' THEN amount * :sar_rate
            ELSE amount -- Fallback for any other currency, assumes it's in the base currency
        END
    ) as total_expenses
    FROM expenses
    WHERE expense_date BETWEEN :from_date AND :to_date AND status = 'approved'
");

// Bind all necessary parameters to the prepared statement
$expenses_stmt->execute([
    ':base_currency' => $base_currency,
    ':usd_rate'      => $usd_to_yer_rate,
    ':sar_rate'      => $sar_to_yer_rate,
    ':from_date'     => $from_date,
    ':to_date'       => $to_date
]);

$expenses_result = $expenses_stmt->fetch(PDO::FETCH_ASSOC);
$total_expenses = $expenses_result['total_expenses'] ?? 0;

// ===============================================================================================


// 3. Total Assets calculation (This is correct and remains unchanged)
$assets_stmt = $db->prepare("
    SELECT SUM(CASE WHEN fa.account_type = 'asset' OR fa.account_type = 'أصول' THEN ftd.debit_amount - ftd.credit_amount ELSE 0 END) as total_assets
    FROM financial_transaction_details ftd
    JOIN financial_accounts fa ON ftd.account_id = fa.id
    JOIN financial_transactions ft ON ftd.transaction_id = ft.id
    WHERE ft.transaction_date <= ?
");
$assets_stmt->execute([$to_date]);
$assets_result = $assets_stmt->fetch(PDO::FETCH_ASSOC);
$total_assets = $assets_result['total_assets'] ?? 0;

// 4. Calculate Net Profit (This is correct and remains unchanged)
$net_profit = $total_revenue - $total_expenses;

// This query for the recent transactions table is correct
$transactions_stmt = $db->prepare("
    SELECT ft.*, u.full_name as created_by_name
    FROM financial_transactions ft
    LEFT JOIN users u ON ft.created_by = u.id
    WHERE ft.transaction_date BETWEEN ? AND ?
    ORDER BY ft.transaction_date DESC, ft.created_at DESC
    LIMIT 10
");
$transactions_stmt->execute([$from_date, $to_date]);
$recent_transactions = $transactions_stmt->fetchAll();

include '../../includes/header.php';
?>


<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">إدارة الحسابات والتقارير المالية</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-2">
                        <a href="transaction-add.php" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition duration-200"><i class="fas fa-plus ml-2"></i>معاملة مالية جديدة</a>
                        <a href="accounts.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200"><i class="fas fa-list ml-2"></i>دليل الحسابات</a>
                        <a href="reports.php" class="inline-flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200"><i class="fas fa-chart-bar ml-2"></i>التقارير المالية</a>
                    </div>
                </div>
            </div>

            <!-- Date Filter -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                        <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">إلى تاريخ</label>
                        <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                    </div>
                    <button type="submit" class="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200"><i class="fas fa-filter ml-2"></i>تطبيق الفلتر</button>
                </form>
            </div>
        </div>

        <!-- Financial Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <!-- Total Revenue -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0"><i class="fas fa-arrow-up text-2xl text-green-600"></i></div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي الإيرادات</dt>
                                <dd class="text-lg font-medium text-green-600"><?php echo number_format($total_revenue, 2, '.', ','); ?> ريال</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Expenses -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0"><i class="fas fa-arrow-down text-2xl text-red-600"></i></div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي المصروفات</dt>
                                <dd class="text-lg font-medium text-red-600"><?php echo number_format($total_expenses, 2, '.', ','); ?> ريال</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Net Profit -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0"><i class="fas fa-coins text-2xl text-yellow-600"></i></div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">صافي الربح</dt>
                                <dd class="text-lg font-medium <?php echo $net_profit >= 0 ? 'text-green-600' : 'text-red-600'; ?>"><?php echo number_format($net_profit, 2, '.', ','); ?> ريال</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Assets -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0"><i class="fas fa-balance-scale text-2xl text-blue-600"></i></div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي الأصول</dt>
                                <dd class="text-lg font-medium text-blue-600"><?php echo number_format($total_assets, 2, '.', ','); ?> ريال</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Transactions Table -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">المعاملات المالية الأخيرة</h2>
                    <a href="transactions.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">عرض جميع المعاملات <i class="fas fa-arrow-left mr-1"></i></a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم المعاملة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الوصف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">النوع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">أنشئ بواسطة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($recent_transactions)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-receipt text-4xl mb-4 text-gray-300"></i>
                                    <p>لا توجد معاملات مالية في الفترة المحددة</p>
                                    <a href="transaction-add.php" class="text-yellow-600 hover:text-yellow-800 mt-2 inline-block">إضافة معاملة مالية جديدة</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($transaction['transaction_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo number_format($transaction['total_amount'], 2, '.', ','); ?> ريال</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php
                                        $type_labels = ['sale' => 'مبيعات', 'purchase' => 'مشتريات', 'payment' => 'دفع', 'receipt' => 'استلام', 'adjustment' => 'تسوية'];
                                        $type_colors = ['sale' => 'bg-green-100 text-green-800', 'purchase' => 'bg-blue-100 text-blue-800', 'payment' => 'bg-red-100 text-red-800', 'receipt' => 'bg-yellow-100 text-yellow-800', 'adjustment' => 'bg-gray-100 text-gray-800'];
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_colors[$transaction['reference_type']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                            <?php echo $type_labels[$transaction['reference_type']] ?? $transaction['reference_type']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($transaction['created_by_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2 space-x-reverse">
                                            <a href="transaction-view.php?id=<?php echo $transaction['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a>
                                            <a href="transaction-print.php?id=<?php echo $transaction['id']; ?>" class="text-purple-600 hover:text-purple-900" title="طباعة"><i class="fas fa-print"></i></a>
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