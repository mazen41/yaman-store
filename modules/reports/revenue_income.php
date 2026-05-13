<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تقارير الإيرادات والخدمات';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$export_type = $_GET['export'] ?? '';

// Handle exports
if ($export_type) {
    if ($export_type == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="revenue_income_' . date('Y-m-d') . '.xls"');
    } elseif ($export_type == 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="revenue_income_' . date('Y-m-d') . '.pdf"');
    }
}

try {
    // 1. Fetch revenue from Customer Orders (طلبات العملاء)
    $orders_sql = "
        SELECT 
            'طلبات العملاء' as source,
            COUNT(id) as transaction_count,
            COALESCE(SUM(final_amount), 0) as total_amount,
            COALESCE(SUM(paid_amount), 0) as paid_amount
        FROM customer_orders
        WHERE order_date BETWEEN ? AND ?
    ";
    $stmt = $db->prepare($orders_sql);
    $stmt->execute([$start_date, $end_date]);
    $orders_revenue = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Fetch revenue from Customer Cards (بطاقات العملاء) - ADDED THIS
    // Assuming purchase_amount is the money received. Since cards are usually prepaid, 
    // we assume Total = Paid, and Remaining = 0 for this category.
    $cards_sql = "
        SELECT 
            'بطاقات العملاء' as source,
            COUNT(id) as transaction_count,
            COALESCE(SUM(purchase_amount), 0) as total_amount,
            COALESCE(SUM(purchase_amount), 0) as paid_amount
        FROM customer_cards
        WHERE issue_date BETWEEN ? AND ?
    ";
    $stmt_cards = $db->prepare($cards_sql);
    $stmt_cards->execute([$start_date, $end_date]);
    $cards_revenue = $stmt_cards->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

// Combine all revenue sources
$revenue_sources = [
    $orders_revenue,
    $cards_revenue // Added cards to the sources list
];

// Calculate totals
$grand_total = 0;
$grand_paid = 0;
$total_transactions = 0;

foreach ($revenue_sources as $source) {
    $grand_total += $source['total_amount'] ?? 0;
    $grand_paid += $source['paid_amount'] ?? 0;
    $total_transactions += $source['transaction_count'] ?? 0;
}

$grand_remaining = $grand_total - $grand_paid;

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <!-- Header Section -->
    <div class="bg-gradient-to-r from-emerald-600 to-emerald-800 text-white rounded-xl shadow-lg p-6 mb-8">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="mb-4 md:mb-0">
                <h1 class="text-3xl font-bold flex items-center gap-3">
                    <i class="fas fa-chart-line text-emerald-200"></i>
                    <?php echo $page_title; ?>
                </h1>
                <p class="text-emerald-100 mt-2 opacity-90">
                    تقرير مفصل للإيرادات (الطلبات + البطاقات) من <?php echo date('Y/m/d', strtotime($start_date)); ?> 
                    إلى <?php echo date('Y/m/d', strtotime($end_date)); ?>
                </p>
            </div>
            <div class="flex gap-3">
                <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&export=excel" 
                   class="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-lg transition-all flex items-center gap-2 backdrop-blur-sm border border-white/20">
                    <i class="fas fa-file-excel text-emerald-300"></i> Excel
                </a>
                <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&export=pdf" 
                   class="bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-lg transition-all flex items-center gap-2 backdrop-blur-sm border border-white/20">
                    <i class="fas fa-file-pdf text-red-300"></i> PDF
                </a>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-6 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">إلى تاريخ</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all">
            </div>
            <div class="md:col-span-1 lg:col-span-2">
                <button type="submit" class="w-full md:w-auto bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2.5 px-8 rounded-lg shadow-sm transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-filter"></i> تصفية النتائج
                </button>
            </div>
        </form>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border-r-4 border-emerald-500 p-6 hover:shadow-md transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm font-medium text-gray-500 mb-1">إجمالي الإيرادات</p>
                    <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($grand_total, 0, ',', '.'); ?> <span class="text-sm font-normal text-gray-500">ر.ي</span></h3>
                </div>
                <div class="bg-emerald-100 p-3 rounded-lg text-emerald-600">
                    <i class="fas fa-wallet text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-r-4 border-blue-500 p-6 hover:shadow-md transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm font-medium text-gray-500 mb-1">المبلغ المدفوع (المحصل)</p>
                    <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($grand_paid, 0, ',', '.'); ?> <span class="text-sm font-normal text-gray-500">ر.ي</span></h3>
                </div>
                <div class="bg-blue-100 p-3 rounded-lg text-blue-600">
                    <i class="fas fa-check-circle text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border-r-4 border-amber-500 p-6 hover:shadow-md transition-all">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-sm font-medium text-gray-500 mb-1">المبلغ المتبقي (آجل)</p>
                    <h3 class="text-2xl font-bold text-gray-900"><?php echo number_format($grand_remaining, 0, ',', '.'); ?> <span class="text-sm font-normal text-gray-500">ر.ي</span></h3>
                </div>
                <div class="bg-amber-100 p-3 rounded-lg text-amber-600">
                    <i class="fas fa-clock text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
            <h3 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                <i class="fas fa-table text-emerald-600"></i>
                تفاصيل الإيرادات حسب المصدر
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">المصدر</th>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">عدد المعاملات</th>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">إجمالي المبلغ</th>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">المدفوع</th>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">المتبقي</th>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider w-1/4">نسبة التحصيل</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($revenue_sources as $source): 
                        // If count is 0, we still want to show it, or check for NULL.
                        // Here we skip if transaction count is 0 to keep table clean, or remove this line to show 0s.
                        if (empty($source['source']) || ($source['transaction_count'] ?? 0) == 0) continue; 
                        
                        $source_total = $source['total_amount'] ?? 0;
                        $source_paid = $source['paid_amount'] ?? 0;
                        $remaining = $source_total - $source_paid;
                        
                        $collection_percentage = ($source_total > 0) ? ($source_paid / $source_total * 100) : 0;
                    ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="font-bold text-gray-800 text-base">
                                <?php echo htmlspecialchars($source['source']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600 font-medium">
                            <?php echo number_format($source['transaction_count'] ?? 0, 0, ',', '.'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-emerald-600 font-bold">
                            <?php echo number_format($source_total, 0, ',', '.'); ?> <span class="text-xs text-gray-400">ر.ي</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-blue-600 font-bold">
                            <?php echo number_format($source_paid, 0, ',', '.'); ?> <span class="text-xs text-gray-400">ر.ي</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-amber-600 font-bold">
                            <?php echo number_format($remaining, 0, ',', '.'); ?> <span class="text-xs text-gray-400">ر.ي</span>
                        </td>
                        <td class="px-6 py-4 align-middle">
                            <div class="flex items-center gap-3">
                                <div class="flex-1 bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                    <div class="bg-emerald-500 h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $collection_percentage; ?>%"></div>
                                </div>
                                <span class="text-xs font-bold text-gray-600 w-12 text-left ltr"><?php echo number_format($collection_percentage, 0, ',', '.'); ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Total Row -->
                    <tr class="bg-emerald-50/50 font-bold border-t-2 border-emerald-100">
                        <td class="px-6 py-4 whitespace-nowrap text-emerald-900">الإجمالي الكلي</td>
                        <td class="px-6 py-4 whitespace-nowrap text-emerald-900"><?php echo number_format($total_transactions, 0, ',', '.'); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-emerald-700 text-lg"><?php echo number_format($grand_total, 0, ',', '.'); ?> <span class="text-xs">ر.ي</span></td>
                        <td class="px-6 py-4 whitespace-nowrap text-blue-700 text-lg"><?php echo number_format($grand_paid, 0, ',', '.'); ?> <span class="text-xs">ر.ي</span></td>
                        <td class="px-6 py-4 whitespace-nowrap text-amber-700 text-lg"><?php echo number_format($grand_remaining, 0, ',', '.'); ?> <span class="text-xs">ر.ي</span></td>
                        <td class="px-6 py-4 text-emerald-900">
                            <?php 
                                $grand_collection_percentage = ($grand_total > 0) ? ($grand_paid / $grand_total * 100) : 0;
                                echo number_format($grand_collection_percentage, 0) . '%';
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>