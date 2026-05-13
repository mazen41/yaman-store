<?php
/**
 * Profit & Loss Report
 * Shows revenue, expenses, and net profit/loss
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$page_title = 'تقرير الأرباح والخسائر';

// Date filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

try {
    // Get total revenue from customer orders
    $revenue_query = "
        SELECT 
            COALESCE(SUM(final_amount), 0) as total_revenue,
            COALESCE(SUM(paid_amount), 0) as paid_revenue,
            COALESCE(SUM(final_amount - paid_amount), 0) as unpaid_revenue
        FROM customer_orders
        WHERE order_date BETWEEN ? AND ?
        AND status NOT IN ('cancelled', 'rejected')
    ";
    $revenue_stmt = $db->prepare($revenue_query);
    $revenue_stmt->execute([$date_from, $date_to]);
    $revenue = $revenue_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total expenses
    $expenses_query = "
        SELECT 
            COALESCE(SUM(amount), 0) as total_expenses
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
    ";
    $expenses_stmt = $db->prepare($expenses_query);
    $expenses_stmt->execute([$date_from, $date_to]);
    $expenses = $expenses_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get expenses by category
    $expenses_by_category_query = "
        SELECT 
            ec.category_name,
            COALESCE(SUM(e.amount), 0) as category_total
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.expense_date BETWEEN ? AND ?
        GROUP BY e.category_id, ec.category_name
        ORDER BY category_total DESC
    ";
    $expenses_cat_stmt = $db->prepare($expenses_by_category_query);
    $expenses_cat_stmt->execute([$date_from, $date_to]);
    $expenses_by_category = $expenses_cat_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate profit/loss
    $total_revenue = floatval($revenue['total_revenue']);
    $paid_revenue = floatval($revenue['paid_revenue']);
    $total_expenses = floatval($expenses['total_expenses']);
    $net_profit = $total_revenue - $total_expenses;
    $profit_margin = $total_revenue > 0 ? ($net_profit / $total_revenue) * 100 : 0;
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ: ' . $e->getMessage();
    $total_revenue = $paid_revenue = $total_expenses = $net_profit = $profit_margin = 0;
    $expenses_by_category = [];
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        <!-- Header -->
        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-2xl shadow-xl px-5 py-6 sm:px-8 sm:py-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold flex items-center gap-3">
                        <i class="fas fa-chart-line text-2xl sm:text-3xl"></i>
                        <span><?php echo $page_title; ?></span>
                    </h1>
                    <p class="mt-2 text-sm sm:text-base text-indigo-100">
                        من
                        <span class="font-semibold">
                            <?php echo date('Y/m/d', strtotime($date_from)); ?>
                        </span>
                        إلى
                        <span class="font-semibold">
                            <?php echo date('Y/m/d', strtotime($date_to)); ?>
                        </span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white rounded-2xl shadow-md p-4 sm:p-6">
            <form method="GET" action="" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            من تاريخ
                        </label>
                        <input
                            type="date"
                            name="date_from"
                            value="<?php echo $date_from; ?>"
                            class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">
                            إلى تاريخ
                        </label>
                        <input
                            type="date"
                            name="date_to"
                            value="<?php echo $date_to; ?>"
                            class="w-full rounded-lg border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm sm:text-base"
                        >
                    </div>
                    <div class="flex items-end">
                        <button
                            type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2.5 rounded-lg bg-indigo-600 text-white text-sm sm:text-base font-semibold shadow hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-search ml-2"></i>
                            عرض التقرير
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 sm:gap-6">
            <!-- Total Revenue -->
            <div class="bg-white rounded-2xl shadow-md p-4 sm:p-5 border-r-4 border-emerald-500 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-600 mb-1">
                        <i class="fas fa-arrow-up text-emerald-500"></i>
                        <span>إجمالي الإيرادات</span>
                    </div>
                    <div class="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">
                        <?php echo number_format($total_revenue, 0, '', ''); ?> ر.ي
                    </div>
                </div>
                <p class="mt-2 text-xs sm:text-sm text-gray-500">
                    المدفوع:
                    <span class="font-semibold text-emerald-600">
                        <?php echo number_format($paid_revenue, 0, '', ''); ?> ر.ي
                    </span>
                </p>
            </div>

            <!-- Total Expenses -->
            <div class="bg-white rounded-2xl shadow-md p-4 sm:p-5 border-r-4 border-red-500 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-600 mb-1">
                        <i class="fas fa-arrow-down text-red-500"></i>
                        <span>إجمالي المصروفات</span>
                    </div>
                    <div class="text-2xl sm:text-3xl font-bold text-gray-900 mt-1">
                        <?php echo number_format($total_expenses, 0, '', ''); ?> ر.ي
                    </div>
                </div>
            </div>

            <!-- Net Profit -->
            <div class="bg-white rounded-2xl shadow-md p-4 sm:p-5 border-r-4 border-blue-500 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-600 mb-1">
                        <i class="fas fa-balance-scale text-blue-500"></i>
                        <span>صافي الربح/الخسارة</span>
                    </div>
                    <div class="text-2xl sm:text-3xl font-bold mt-1 <?php echo $net_profit >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                        <?php echo number_format($net_profit, 0, '', ''); ?> ر.ي
                    </div>
                </div>
            </div>

            <!-- Profit Margin -->
            <div class="bg-white rounded-2xl shadow-md p-4 sm:p-5 border-r-4 border-amber-500 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-600 mb-1">
                        <i class="fas fa-percentage text-amber-500"></i>
                        <span>هامش الربح</span>
                    </div>
                    <div class="text-2xl sm:text-3xl font-bold mt-1 <?php echo $profit_margin >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                        <?php echo number_format($profit_margin, 0, '', ''); ?>%
                    </div>
                </div>
            </div>
        </div>

        <!-- Expenses by Category -->
        <?php if (!empty($expenses_by_category)): ?>
        <div class="bg-white rounded-2xl shadow-md p-4 sm:p-6 mt-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base sm:text-lg font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-list text-gray-500"></i>
                    <span>المصروفات حسب الفئة</span>
                </h3>
            </div>

            <div class="divide-y divide-gray-200">
                <?php foreach ($expenses_by_category as $category): ?>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between py-3 gap-2">
                    <span class="text-sm sm:text-base font-semibold text-gray-800">
                        <?php echo htmlspecialchars($category['category_name'] ?: 'غير مصنف'); ?>
                    </span>
                    <span class="text-sm sm:text-base font-bold text-red-600">
                        <?php echo number_format($category['category_total'], 0, '', ''); ?> ر.ي
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="pt-2 text-center">
            <a
                href="index.php"
                class="inline-flex items-center px-5 py-2.5 rounded-lg bg-gray-700 text-white text-sm sm:text-base font-semibold shadow hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-700">
                <i class="fas fa-arrow-right ml-2"></i>
                العودة للتقارير
            </a>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
