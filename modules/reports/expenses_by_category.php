<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'المصروفات حسب الفئات';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$export_type = $_GET['export'] ?? '';

// Currency Filter
$selected_currency = $_GET['currency'] ?? 'YER';
if (!in_array($selected_currency, ['YER', 'SAR'])) {
    $selected_currency = 'YER';
}
$currency_symbol = ($selected_currency == 'SAR') ? 'ر.س' : 'ر.ي';

// Handle exports
if ($export_type) {
    if ($export_type == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="expenses_by_category_' . $selected_currency . '_' . date('Y-m-d') . '.xls"');
    } elseif ($export_type == 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="expenses_by_category_' . $selected_currency . '_' . date('Y-m-d') . '.pdf"');
    }
}

// Fetch expenses by category
$sql = "
    SELECT 
        COALESCE(ec.category_name, 'غير مصنف') as category_name,
        ec.color,
        COUNT(e.id) as expense_count,
        SUM(e.amount) as total_amount,
        AVG(e.amount) as avg_amount
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.expense_date BETWEEN ? AND ?
    AND e.currency = ?
    GROUP BY e.category_id, ec.category_name, ec.color
    ORDER BY total_amount DESC
";

$stmt = $db->prepare($sql);
$stmt->execute([$start_date, $end_date, $selected_currency]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$grand_total = array_sum(array_column($expenses, 'total_amount'));
$total_count = array_sum(array_column($expenses, 'expense_count'));

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 px-6 py-4 text-white flex justify-between items-center">
            <h4 class="text-xl font-bold flex items-center gap-2">
                <i class="fas fa-tags"></i>
                <?php echo $page_title; ?>
            </h4>
            <div class="bg-white/20 rounded-lg px-3 py-1 backdrop-blur-sm">
                <span class="text-sm font-bold"><?php echo $selected_currency; ?></span>
            </div>
        </div>
        
        <div class="p-6">
            <!-- Filters -->
            <form method="GET" class="mb-8 bg-gray-50 p-4 rounded-lg border border-gray-200">
                <!-- Persist currency -->
                <input type="hidden" name="currency" value="<?php echo htmlspecialchars($selected_currency); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                        <input type="date" name="start_date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" value="<?php echo $start_date; ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                        <input type="date" name="end_date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center gap-2">
                            <i class="fas fa-search"></i> بحث
                        </button>
                        <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&currency=<?php echo $selected_currency; ?>&export=excel" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-file-excel"></i>
                        </a>
                        <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&currency=<?php echo $selected_currency; ?>&export=pdf" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                        <a href="?currency=<?php echo $selected_currency; ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-redo"></i>
                        </a>
                    </div>
                </div>
            </form>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <!-- Total Expenses Card -->
                <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-md p-6 text-white transform transition hover:scale-[1.01] duration-200">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-red-100 text-sm font-medium mb-1">إجمالي المصروفات (<?php echo $selected_currency; ?>)</p>
                            <h3 class="text-3xl font-bold" style="direction: ltr;">
                                <?php echo number_format($grand_total, 0, '', ''); ?> <span class="text-xl"><?php echo $currency_symbol; ?></span>
                            </h3>
                        </div>
                        <div class="bg-white/20 p-3 rounded-lg">
                            <i class="fas fa-wallet text-2xl text-white"></i>
                        </div>
                    </div>
                </div>

                <!-- Total Count Card -->
                <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-md p-6 text-white transform transition hover:scale-[1.01] duration-200">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-blue-100 text-sm font-medium mb-1">عدد المصروفات</p>
                            <h3 class="text-3xl font-bold"><?php echo $total_count; ?></h3>
                        </div>
                        <div class="bg-white/20 p-3 rounded-lg">
                            <i class="fas fa-receipt text-2xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">الفئة</th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">عدد المصروفات</th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">المبلغ الإجمالي</th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider w-1/3">النسبة</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i>
                                        <p>لا توجد مصروفات في هذه الفترة</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($expenses as $expense): 
                                $percentage = ($grand_total > 0) ? ($expense['total_amount'] / $grand_total * 100) : 0;
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <span class="w-3 h-3 rounded-full mr-2" style="background-color: <?php echo $expense['color'] ?? '#6b7280'; ?>"></span>
                                        <span class="font-medium text-gray-900"><?php echo htmlspecialchars($expense['category_name']); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php echo $expense['expense_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                    <?php echo number_format($expense['total_amount'], 0, '', ''); ?> <?php echo $currency_symbol; ?>
                                </td>
                                <td class="px-6 py-4 align-middle">
                                    <div class="flex items-center">
                                        <span class="text-xs font-medium text-gray-600 w-12 ml-2 text-left"><?php echo number_format($percentage, 0, '', ''); ?>%</span>
                                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                                            <div class="bg-indigo-600 h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="bg-gray-50 font-bold">
                                <td class="px-6 py-4 text-gray-900">الإجمالي</td>
                                <td class="px-6 py-4 text-gray-900"><?php echo $total_count; ?></td>
                                <td class="px-6 py-4 text-indigo-600 text-lg"><?php echo number_format($grand_total, 0, '', ''); ?> <?php echo $currency_symbol; ?></td>
                                <td class="px-6 py-4 text-indigo-600">100%</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
