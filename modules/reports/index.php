<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'التقارير والطباعة';

// --- CORRECTED & EXPANDED STATISTICS ---
// Single-currency system: all amounts are treated as Yemeni Rial
$currency_symbol = 'ر.ي';

try {
    // Correctly calculate sales by excluding invalid statuses
    $valid_order_statuses = "'completed', 'shipped', 'delivered', 'in_delivery', 'received', 'sorted', 'under_sorting', 'in_preparation', 'approved', 'new'";

    $orders_today = $db->query("
            SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total 
            FROM customer_orders 
            WHERE DATE(created_at) = CURDATE() AND status IN ($valid_order_statuses)
        ")->fetch();

    $orders_month = $db->query("
            SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total 
            FROM customer_orders 
            WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status IN ($valid_order_statuses)
        ")->fetch();

    // Calculate total purchases (cost) for the month
    $purchases_month = $db->query("
            SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total 
            FROM purchase_baskets 
            WHERE MONTH(purchase_date) = MONTH(CURDATE()) AND YEAR(purchase_date) = YEAR(CURDATE()) AND status = 'ordered'
        ")->fetch();

    // Calculate total expenses for the month
    $expenses_month = $db->query("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM expenses 
            WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
        ")->fetch();
} catch (PDOException $e) {
    // Fallback if currency column doesn't exist yet
    error_log("Report Error (Currency Migration likely missing): " . $e->getMessage());
    $orders_today = $orders_month = ['count' => 0, 'total' => 0];
    $purchases_month = ['count' => 0, 'total' => 0];
    $expenses_month = ['total' => 0];
}

include '../../includes/header.php';
?>

<style>
    .report-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        border: 2px solid transparent;
        cursor: pointer;
    }

    .report-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        border-color: #3b82f6;
    }

    .report-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin-bottom: 1rem;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border-right: 4px solid;
    }

    .export-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.2s;
        cursor: pointer;
        border: none;
    }

    .export-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .btn-pdf {
        background: #ef4444;
        color: white;
    }

    .btn-excel {
        background: #C7A46D;
        color: white;
    }

    .section-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .section-title i {
        color: #3b82f6;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">

        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 shadow-xl rounded-2xl mb-8 p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-chart-bar ml-3"></i>
                        التقارير والطباعة
                    </h1>
                    <p class="text-blue-100 mt-2">تقارير شاملة ومفصلة لجميع العمليات المالية</p>
                </div>
                <button onclick="window.print()" class="px-6 py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 font-semibold transition">
                    <i class="fas fa-print ml-2"></i>
                    طباعة
                </button>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="bg-white shadow-lg rounded-2xl mb-8 p-6">
            <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-filter ml-2 text-blue-600"></i>
                تصفية التقارير
            </h3>
            <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Date From -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">من التاريخ</label>
                    <input type="date" name="date_from" value="<?php echo $_GET['date_from'] ?? ''; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">إلى التاريخ</label>
                    <input type="date" name="date_to" value="<?php echo $_GET['date_to'] ?? ''; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <!-- Report Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">نوع التقرير</label>
                    <select name="report_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">الكل</option>
                        <option value="financial">التقارير المالية</option>
                        <option value="accounts">التقارير الحسابية</option>
                        <option value="detailed">التقارير التفصيلية</option>
                    </select>
                </div>

                <!-- Buttons -->
                <div class="flex items-end gap-2 md:col-span-4">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold">
                        <i class="fas fa-search ml-1"></i>
                        بحث
                    </button>
                    <button type="button" onclick="window.location.href='index.php'" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-semibold">
                        <i class="fas fa-redo ml-1"></i>
                        إعادة
                    </button>
                </div>
            </form>
        </div>

        <!-- CORRECTED Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-card" style="border-right-color: #C7A46D;">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-gray-600 text-sm">مبيعات اليوم</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($orders_today['count'], 0, '.', ','); ?></p>
                        <p class="text-amber-600 text-sm font-semibold mt-1">
                            <?php echo number_format($orders_today['total'], 0, '.', ','); ?>
                            <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                        </p>
                    </div>

                    <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-shopping-cart text-3xl text-amber-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card" style="border-right-color: #3b82f6;">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-gray-600 text-sm">مبيعات الشهر</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($orders_month['count'], 0, '.', ','); ?></p>
                        <p class="text-blue-600 text-sm font-semibold mt-1">
                            <?php echo number_format($orders_month['total'], 0, '.', ','); ?>
                            <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                        </p>
                    </div>

                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-calendar-alt text-3xl text-blue-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card" style="border-right-color: #ef4444;">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-gray-600 text-sm">مشتريات الشهر</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2"><?php echo number_format($purchases_month['count'], 0, '.', ','); ?></p>
                        <p class="text-red-600 text-sm font-semibold mt-1">
                            <?php echo number_format($purchases_month['total'], 0, '.', ','); ?>
                            <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                        </p>
                    </div>

                    <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-shopping-basket text-3xl text-red-600"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card" style="border-right-color: #f59e0b;">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-gray-600 text-sm">مصروفات الشهر</p>
                        <p class="text-3xl font-bold text-gray-900 mt-2">
                            <?php echo number_format($expenses_month['total'], 0, '.', ','); ?>
                        </p>
                        <p class="text-orange-600 text-sm font-semibold mt-1">
                            <span class="currency-symbol"><?php echo $currency_symbol; ?></span>
                        </p>
                    </div>

                    <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-3xl text-orange-600"></i>
                    </div>
                </div>
            </div>
        </div>
<!-- Customer Cards Report -->
<div class="report-card" onclick="window.location.href='customer_cards_report.php'">
    <div class="report-icon" style="background: linear-gradient(135deg, #10b981 0%, #047857 100%); color: white;">
        <i class="fas fa-id-card"></i>
    </div>
    <h3 class="text-lg font-bold text-gray-900 mb-2">تقارير بطاقات العملاء</h3>
    <p class="text-gray-600 text-sm mb-4">أرصدة ومبيعات بطاقات العملاء</p>
    <div class="flex gap-2">
        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('customer_cards', 'pdf')">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('customer_cards', 'excel')">
            <i class="fas fa-file-excel"></i> Excel
        </button>
    </div>
</div>
        <!-- Financial Reports Section -->
        <div class="mb-8">
            <h2 class="section-title">
                <i class="fas fa-file-invoice-dollar"></i>
                التقارير المالية
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <!-- Balance Sheet -->
                <div class="report-card" onclick="window.location.href='balance_sheet.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">الميزانية العمومية</h3>
                    <p class="text-gray-600 text-sm mb-4">عرض الأصول والخصوم وحقوق الملكية</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('balance_sheet', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('balance_sheet', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

                <!-- Profit & Loss -->
                <div class="report-card" onclick="window.location.href='profit_loss.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">الأرباح والخسائر</h3>
                    <p class="text-gray-600 text-sm mb-4">تقرير الإيرادات والمصروفات والأرباح</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('profit_loss', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('profit_loss', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

                <!-- Expenses Report -->
                <div class="report-card" onclick="window.location.href='expenses_report.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تقرير المصروفات</h3>
                    <p class="text-gray-600 text-sm mb-4">جميع المصروفات والنفقات</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('expenses', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('expenses', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

            </div>
        </div>
<div class="report-card" onclick="window.location.href='purchase_groups_report.php'">
    <div class="report-icon" style="background: linear-gradient(135deg, #2563eb 0%, #0d9488 100%); color: white;">
        <i class="fas fa-layer-group"></i>
    </div>
    <h3 class="text-lg font-bold text-gray-900 mb-2">تقرير مجموعات الشراء</h3>
    <p class="text-gray-600 text-sm mb-4">عرض وتحليل مجموعات الشراء وحالاتها</p>
    <div class="flex gap-2">
        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('purchase_groups', 'pdf')">
            <i class="fas fa-file-pdf"></i>
            PDF
        </button>
        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('purchase_groups', 'excel')">
            <i class="fas fa-file-excel"></i>
            Excel
        </button>
    </div>
</div>
<!-- Orders Reports -->
<div class="report-card" onclick="window.location.href='orders_reports.php'">
    <div class="report-icon" style="background: linear-gradient(135deg, #10B981 0%, #3B82F6 100%); color: white;">
        <i class="fas fa-receipt"></i>
    </div>
    <h3 class="text-lg font-bold text-gray-900 mb-2">تقارير الطلبات</h3>
    <p class="text-gray-600 text-sm mb-4">عرض جميع الطلبات والمبيعات اليومية والشهرية</p>
    <div class="flex gap-2">
        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('orders', 'pdf')">
            <i class="fas fa-file-pdf"></i>
            PDF
        </button>
        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('orders', 'excel')">
            <i class="fas fa-file-excel"></i>
            Excel
        </button>
    </div>
</div>

        <!-- Customer & Accounts Reports -->
        <div class="mb-8">
            <h2 class="section-title">
                <i class="fas fa-users"></i>
                تقارير العملاء والحسابات
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

                <!-- Customer Accounts -->
                <div class="report-card" onclick="window.location.href='customer_accounts.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">حسابات العملاء</h3>
                    <p class="text-gray-600 text-sm mb-4">كشف حساب لكل عميل</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('customer_accounts', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('customer_accounts', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

                <!-- Purchase Baskets Report -->
                <div class="report-card" onclick="window.location.href='baskets_report.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <i class="fas fa-shopping-basket"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تقرير سلال الشراء</h3>
                    <p class="text-gray-600 text-sm mb-4">تفاصيل السلال والمشتريات</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('baskets', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('baskets', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>
                <!-- Basket Specific Details Report -->
                <div class="report-card" onclick="window.location.href='basket_details_report.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #43cea2 0%, #185a9d 100%); color: white;">
                        <i class="fas fa-shopping-basket"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تفاصيل السلال</h3>
                    <p class="text-gray-600 text-sm mb-4">تقرير تفصيلي لكل سلة والمحتويات والمعاملات</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('financial_report_purchase_baskets', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('financial_report_purchase_baskets', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>
<!-- Detailed Payments Report -->
<div class="report-card" onclick="window.location.href='detail_payments_reports.php'">
    <div class="report-icon" style="background: linear-gradient(135deg, #ff9966 0%, #ff5e62 100%); color: white;">
        <i class="fas fa-file-invoice-dollar"></i>
    </div>

    <h3 class="text-lg font-bold text-gray-900 mb-2">تفاصيل المدفوعات</h3>
    <p class="text-gray-600 text-sm mb-4">
        تقرير تفصيلي بجميع المدفوعات وحالاتها وطرق الدفع
    </p>

    <div class="flex gap-2">
        <button class="export-btn btn-pdf"
            onclick="event.stopPropagation(); exportReport('detail_payments_reports', 'pdf')">
            <i class="fas fa-file-pdf"></i>
            PDF
        </button>

        <button class="export-btn btn-excel"
            onclick="event.stopPropagation(); exportReport('detail_payments_reports', 'excel')">
            <i class="fas fa-file-excel"></i>
            Excel
        </button>
    </div>
</div>

                <!-- Purchase Cards -->
                <div class="report-card" onclick="window.location.href='purchase_cards_report.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تقارير بطاقات الشراء</h3>
                    <p class="text-gray-600 text-sm mb-4">حركة البطاقات والأرصدة</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('purchase_cards', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('purchase_cards', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

                <!-- Bank Accounts -->
                <div class="report-card" onclick="window.location.href='bank_accounts_report.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                        <i class="fas fa-university"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تقارير الحسابات البنكية</h3>
                    <p class="text-gray-600 text-sm mb-4">كشوفات الحسابات البنكية</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('bank_accounts', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('bank_accounts', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Expense & Revenue Reports -->
        <div class="mb-8">
            <h2 class="section-title">
                <i class="fas fa-chart-pie"></i>
                تقارير المصروفات والإيرادات
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <!-- Expenses by Category -->
                <div class="report-card" onclick="window.location.href='expenses_by_category.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #374151;">
                        <i class="fas fa-tags"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">المصروفات حسب الفئات</h3>
                    <p class="text-gray-600 text-sm mb-4">تصنيف المصروفات حسب النوع</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('expenses_category', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('expenses_category', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

                <!-- Revenue & Income -->
                <div class="report-card" onclick="window.location.href='revenue_income.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); color: #374151;">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تقارير الإيرادات والدخل</h3>
                    <p class="text-gray-600 text-sm mb-4">جميع مصادر الدخل والإيرادات</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('revenue_income', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('revenue_income', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

                <!-- Coupons Report -->
                <div class="report-card" onclick="window.location.href='coupons_report.php'">
                    <div class="report-icon" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); color: white;">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">تقارير الكوبونات</h3>
                    <p class="text-gray-600 text-sm mb-4">استخدام الكوبونات والخصومات</p>
                    <div class="flex gap-2">
                        <button class="export-btn btn-pdf" onclick="event.stopPropagation(); exportReport('coupons', 'pdf')">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                        <button class="export-btn btn-excel" onclick="event.stopPropagation(); exportReport('coupons', 'excel')">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i>
                إجراءات سريعة
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <a href="../orders/index.php" class="flex items-center gap-3 p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition">
                    <i class="fas fa-shopping-cart text-2xl text-blue-600"></i>
                    <span class="font-semibold text-gray-900">طلبات العملاء</span>
                </a>
                <a href="../purchases/show_baskets.php" class="flex items-center gap-3 p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition">
                    <i class="fas fa-shopping-basket text-2xl text-purple-600"></i>
                    <span class="font-semibold text-gray-900">سلال الشراء</span>
                </a>
                <a href="../financial/accounts.php" class="flex items-center gap-3 p-4 bg-amber-50 rounded-lg hover:bg-amber-100 transition">
                    <i class="fas fa-file-invoice-dollar text-2xl text-amber-600"></i>
                    <span class="font-semibold text-gray-900">دليل الحسابات</span>
                </a>
                <a href="../customers/index.php" class="flex items-center gap-3 p-4 bg-orange-50 rounded-lg hover:bg-orange-100 transition">
                    <i class="fas fa-users text-2xl text-orange-600"></i>
                    <span class="font-semibold text-gray-900">إدارة العملاء</span>
                </a>
            </div>
        </div>

    </div>
</div>

<script>
    function exportReport(reportType, format) {
        // Show loading notification
        showNotification('جاري تصدير التقرير...', 'info');

        // Redirect to export endpoint (single-currency: Yemeni Rial)
        window.location.href = `export_report.php?type=${reportType}&format=${format}`;
    }

    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        padding: 15px 25px;
        border-radius: 8px;
        font-weight: 600;
        z-index: 10000;
        animation: slideDown 0.3s ease-out;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    `;

        if (type === 'info') {
            notification.style.background = '#3b82f6';
            notification.style.color = 'white';
        } else if (type === 'success') {
            notification.style.background = '#C7A46D';
            notification.style.color = 'white';
        }

        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.animation = 'slideUp 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }, 2000);
    }

    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }
    @keyframes slideUp {
        from {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        to {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
    }
`;
    document.head.appendChild(style);
</script>

<?php include '../../includes/footer.php'; ?>