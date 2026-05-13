<?php
/**
 * Expenses Report
 * Detailed list of all expenses with filters
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$page_title = 'تقرير المصروفات';

// Filters
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$category_id = $_GET['category'] ?? '';

// Currency Filter
$selected_currency = $_GET['currency'] ?? 'YER';
if (!in_array($selected_currency, ['YER', 'SAR'])) {
    $selected_currency = 'YER';
}
$currency_symbol = ($selected_currency == 'SAR') ? 'ر.ي' : 'ر.ي';

try {
    // Get expense categories for filter
    $categories_query = "SELECT id, category_name as name FROM expense_categories ORDER BY category_name";
    $categories_stmt = $db->query($categories_query);
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if currency column exists
    $currency_column_exists = false;
    try {
        $check_column = $db->query("SHOW COLUMNS FROM expenses LIKE 'currency'");
        $currency_column_exists = ($check_column->rowCount() > 0);
    } catch (PDOException $e) {
        // Column check failed, assume it doesn't exist
    }
    
    // Build expenses query
    $where_clauses = ["e.expense_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if (!empty($category_id)) {
        $where_clauses[] = "e.category_id = ?";
        $params[] = $category_id;
    }

    // Filter by currency only if column exists
    if ($currency_column_exists) {
        $where_clauses[] = "(e.currency = ? OR e.currency IS NULL)";
        $params[] = $selected_currency;
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    
    $expenses_query = "
        SELECT 
            e.*,
            ec.category_name,
            u.full_name as created_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN users u ON e.created_by = u.id
        WHERE $where_sql
        ORDER BY e.expense_date DESC, e.created_at DESC
    ";
    
    $expenses_stmt = $db->prepare($expenses_query);
    $expenses_stmt->execute($params);
    $expenses = $expenses_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total
    $total_expenses = array_sum(array_column($expenses, 'amount'));
    
    // Debug info
    $debug_info = [
        'currency_column_exists' => $currency_column_exists,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'selected_currency' => $selected_currency,
        'query' => $expenses_query,
        'params' => $params,
        'result_count' => count($expenses),
        'total_amount' => $total_expenses
    ];
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ: ' . $e->getMessage();
    $expenses = [];
    $total_expenses = 0;
    $debug_info = [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ];
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-gradient-to-br from-red-500 to-red-600 text-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold flex items-center gap-3 mb-2">
                    <i class="fas fa-receipt"></i>
                    <?php echo $page_title; ?>
                </h1>
                <p class="text-red-100 text-sm sm:text-base opacity-90">
                    من <?php echo date('Y/m/d', strtotime($date_from)); ?> 
                    إلى <?php echo date('Y/m/d', strtotime($date_to)); ?>
                </p>
            </div>
            <div class="bg-white/20 rounded-lg p-2 backdrop-blur-sm">
                <span class="text-sm font-bold block opacity-75">العملة</span>
                <span class="text-xl font-bold"><?php echo $selected_currency; ?></span>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <form method="GET" action="">
            <!-- Persist currency in form -->
            <input type="hidden" name="currency" value="<?php echo htmlspecialchars($selected_currency); ?>">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" 
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" 
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">الفئة</label>
                    <select name="category" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">
                        <option value="">الكل</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:col-span-2 lg:col-span-2">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i> بحث
                    </button>
                    <a href="?currency=<?php echo $selected_currency; ?>" class="w-full bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center gap-2 text-center no-underline">
                        <i class="fas fa-redo"></i> إعادة تعيين
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Total Card -->
    <div class="bg-gradient-to-br from-red-500 to-red-600 text-white rounded-xl shadow-lg p-6 mb-6 text-center transform transition hover:scale-[1.01] duration-200">
        <h2 class="text-lg font-medium text-red-100 mb-2">إجمالي المصروفات (<?php echo $selected_currency; ?>)</h2>
        <div class="text-4xl sm:text-5xl font-bold mb-2" style="direction: ltr;">
            <?php echo number_format($total_expenses, 0, '', ''); ?> <span class="text-2xl sm:text-3xl"><?php echo $currency_symbol; ?></span>
        </div>
        <div class="inline-flex items-center bg-white/20 rounded-full px-3 py-1 text-sm">
            <i class="fas fa-file-invoice ml-2"></i>
            عدد المصروفات: <?php echo count($expenses); ?>
        </div>
    </div>

    <!-- Expenses Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <?php if (empty($expenses)): ?>
            <div class="p-12 text-center text-gray-500">
                <div class="bg-gray-50 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-inbox text-3xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-1">لا توجد مصروفات</h3>
                <p>لم يتم العثور على مصروفات في الفترة المحددة</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الفئة</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الوصف</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">طريقة الدفع</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المستخدم</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($expenses as $index => $expense): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $index + 1; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('Y/m/d', strtotime($expense['expense_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    <?php echo htmlspecialchars($expense['category_name'] ?: 'غير مصنف'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title="<?php echo htmlspecialchars($expense['description']); ?>">
                                <?php echo htmlspecialchars($expense['description']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600">
                                <?php echo number_format($expense['amount'], 0, '', ''); ?> <?php echo $currency_symbol; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($expense['payment_method'] ?: '-'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div class="flex items-center">
                                    <div class="h-6 w-6 rounded-full bg-gray-200 flex items-center justify-center text-xs ml-2">
                                        <?php echo strtoupper(substr($expense['created_by_name'] ?: 'U', 0, 1)); ?>
                                    </div>
                                    <?php echo htmlspecialchars($expense['created_by_name'] ?: '-'); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="mt-6 text-center">
        <a href="index.php" class="inline-flex items-center justify-center px-6 py-2 border border-transparent text-base font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 transition-colors duration-200">
            <i class="fas fa-arrow-right ml-2"></i> العودة للتقارير
        </a>
    </div>
</div>

<script>
// Debug information
console.group('🔍 Expenses Report Debug');
console.log('Debug Info:', <?php echo json_encode($debug_info ?? [], JSON_PRETTY_PRINT); ?>);
<?php if (isset($error_message)): ?>
console.error('Error:', <?php echo json_encode($error_message); ?>);
<?php endif; ?>
console.log('Expenses Data:', <?php echo json_encode($expenses ?? [], JSON_PRETTY_PRINT); ?>);
console.groupEnd();
</script>

<?php include '../../includes/footer.php'; ?>
