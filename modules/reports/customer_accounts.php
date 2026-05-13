<?php
/**
 * Customer Accounts Report
 * Shows customer balances, orders, and payment status
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$page_title = 'تقرير حسابات العملاء';

// Filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? ''; // 'has_balance', 'paid', ''

// Currency symbol (single unified display)
$currency_symbol = 'ر.ي';

try {
    // --- NEW: Fetch the absolute grand total of remaining customer balances ---
    // This query is identical to the one in daily_financial_report.php to ensure consistency.
    $absolute_grand_total_remaining = (float) $db->query("
        SELECT COALESCE(SUM(final_amount - paid_amount), 0)
        FROM customer_orders 
        WHERE (final_amount - paid_amount) > 0.01
    ")->fetchColumn();
    // --- END NEW ---

    // Build query for customers and their aggregated order data (this query can still be filtered by `search`)
    $where_clauses = [];
    $params = [];

    // Apply search filter (name, phone, email)
    if (!empty($search)) {
        $where_clauses[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $search_term = '%' . $search . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $customers_query = "
        SELECT
            c.id,
            c.name,
            c.phone,
            c.email,
            c.is_active,
            COUNT(co.id) AS total_orders,
            COALESCE(SUM(co.final_amount), 0) AS total_invoices_amount,
            COALESCE(SUM(co.paid_amount), 0) AS total_paid_amount,
            COALESCE(SUM(co.final_amount - co.paid_amount), 0) AS total_remaining_amount
        FROM customers c
        LEFT JOIN customer_orders co ON c.id = co.customer_id
        $where_sql
        GROUP BY c.id, c.name, c.phone, c.email, c.is_active
        ORDER BY total_remaining_amount DESC, c.name ASC
    ";

    $stmt = $db->prepare($customers_query);
    $stmt->execute($params);
    $customers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply status filter after fetching and aggregating data
    $filtered_customers = [];
    if ($status_filter === 'has_balance') {
        $filtered_customers = array_filter($customers_data, function($customer) {
            return floatval($customer['total_remaining_amount']) > 0;
        });
    } elseif ($status_filter === 'paid') {
        $filtered_customers = array_filter($customers_data, function($customer) {
            return floatval($customer['total_remaining_amount']) == 0 && floatval($customer['total_invoices_amount']) > 0;
        });
    } else {
        $filtered_customers = $customers_data; // Default: show all customers that match the search (if any)
    }

    // Re-index array after filtering
    $customers = array_values($filtered_customers);

    // Calculate totals for the *displayed* customers (these relate to the filtered table rows)
    $total_customers  = count($customers);
    $total_sales      = array_sum(array_column($customers, 'total_invoices_amount'));
    $total_paid       = array_sum(array_column($customers, 'total_paid_amount'));
    
    // --- MODIFIED: For the 'المتبقي' (Remaining) card, use the absolute grand total ---
    $total_remaining  = $absolute_grand_total_remaining; 
    // --- END MODIFIED ---

} catch (PDOException $e) {
    $error_message = 'حدث خطأ: ' . $e->getMessage();
    $customers = [];
    $total_customers = 0;
    $total_sales = 0;
    $total_paid = 0;
    $total_remaining = 0; // Will be 0 on error
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-gradient-to-br from-purple-600 to-purple-700 text-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold flex items-center gap-3 mb-2">
                    <i class="fas fa-users"></i>
                    <?php echo $page_title; ?>
                </h1>
                <p class="text-purple-100 text-sm sm:text-base opacity-90">
                    عرض شامل لحسابات العملاء وأرصدتهم
                </p>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <form method="GET" action="">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">بحث</label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               class="w-full pr-10 rounded-lg border-gray-300 focus:ring-purple-500 focus:border-purple-500 text-sm"
                               placeholder="اسم العميل، الهاتف، أو البريد...">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">الحالة</label>
                    <select name="status" class="w-full rounded-lg border-gray-300 focus:ring-purple-500 focus:border-purple-500 text-sm">
                        <option value="">الكل</option>
                        <option value="has_balance" <?php echo $status_filter === 'has_balance' ? 'selected' : ''; ?>>لديهم رصيد متبقي</option>
                        <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>مسددين بالكامل</option>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-search"></i> بحث
                    </button>
                    <a href="customer_accounts.php" class="flex-none bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center justify-center gap-2" title="إعادة تعيين">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md transition-shadow duration-200">
            <div class="text-gray-500 text-sm font-medium mb-1 flex items-center gap-1">
                <i class="fas fa-users text-purple-500"></i> عدد العملاء
            </div>
            <div class="text-2xl font-bold text-gray-900"><?php echo $total_customers; ?></div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md transition-shadow duration-200">
            <div class="text-gray-500 text-sm font-medium mb-1 flex items-center gap-1">
                <i class="fas fa-shopping-cart text-blue-500"></i> إجمالي المبيعات
            </div>
            <div class="text-2xl font-bold text-gray-900" style="direction: ltr;">
                <?php echo number_format($total_sales, 0, ',', '.'); ?> <span class="text-sm text-gray-500"><?php echo $currency_symbol; ?></span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md transition-shadow duration-200">
            <div class="text-gray-500 text-sm font-medium mb-1 flex items-center gap-1">
                <i class="fas fa-check-circle text-green-500"></i> المدفوع
            </div>
            <div class="text-2xl font-bold text-green-600" style="direction: ltr;">
                <?php echo number_format($total_paid, 0, ',', '.'); ?> <span class="text-sm text-gray-500"><?php echo $currency_symbol; ?></span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col items-center justify-center text-center hover:shadow-md transition-shadow duration-200">
            <div class="text-gray-500 text-sm font-medium mb-1 flex items-center gap-1">
                <i class="fas fa-exclamation-circle text-red-500"></i> المتبقي
            </div>
            <div class="text-2xl font-bold text-red-600" style="direction: ltr;">
                <?php 
                    // This now displays the absolute grand total, matching the Balance Sheet
                    echo number_format($total_remaining, 0, ',', '.'); 
                ?> <span class="text-sm text-gray-500"><?php echo $currency_symbol; ?></span>
            </div>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <?php if (empty($customers)): ?>
            <div class="p-12 text-center text-gray-500">
                <div class="bg-gray-50 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-inbox text-3xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-1">لا توجد نتائج</h3>
                <p>لم يتم العثور على عملاء مطابقين للبحث</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">اسم العميل</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الهاتف</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">عدد الطلبات</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجمالي المبلغ</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المدفوع</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المتبقي</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($customers as $index => $customer):
                            $remaining = floatval($customer['total_remaining_amount']);
                            $total     = floatval($customer['total_invoices_amount']);
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $index + 1; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dir-ltr text-right"><?php echo htmlspecialchars($customer['phone']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?php echo $customer['total_orders']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo number_format($total, 0, ',', '.'); ?> <span class="text-xs font-normal text-gray-500"><?php echo $currency_symbol; ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                <?php echo number_format(floatval($customer['total_paid_amount']), 0, ',', '.'); ?> <span class="text-xs font-normal text-gray-500"><?php echo $currency_symbol; ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-red-600">
                                <?php echo number_format($remaining, 0, ',', '.'); ?> <span class="text-xs font-normal text-gray-500"><?php echo $currency_symbol; ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <?php if ($remaining > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        لديه رصيد
                                    </span>
                                <?php elseif ($total > 0): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        مسدد
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="mt-6 text-center">
        <a href="index.php" class="inline-flex items-center justify-center px-6 py-2 border border-transparent text-base font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 transition-colors duration-200">
            <i class="fas fa-arrow-right ml-2"></i> العودة للتقارير
        </a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>