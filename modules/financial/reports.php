<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'التقارير المالية';

// Date filters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            <i class="fas fa-chart-bar ml-2 text-amber-600"></i>
                            التقارير المالية
                        </h1>
                        <p class="text-gray-600 mt-1">جميع التقارير المالية والإحصائيات</p>
                    </div>
                    <div class="mt-4 sm:mt-0">
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Date Filter -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                        <input 
                            type="date" 
                            name="from_date" 
                            value="<?php echo htmlspecialchars($from_date); ?>"
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">إلى تاريخ</label>
                        <input 
                            type="date" 
                            name="to_date" 
                            value="<?php echo htmlspecialchars($to_date); ?>"
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                        >
                    </div>
                    <button type="submit" class="px-6 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                        <i class="fas fa-filter ml-2"></i>تطبيق الفلتر
                    </button>
                </form>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- قائمة الدخل -->
            <div class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition duration-200">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4 mx-auto">
                        <i class="fas fa-chart-line text-3xl text-blue-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">قائمة الدخل</h3>
                    <p class="text-sm text-gray-600 text-center mb-4">عرض الإيرادات والمصروفات والأرباح</p>
                    <a href="/modules/reports/profit_loss.php?date_from=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                       class="block w-full text-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                        <i class="fas fa-eye ml-2"></i>عرض التقرير
                    </a>
                </div>
            </div>

            <!-- الميزانية العمومية -->
            <div class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition duration-200">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 bg-amber-100 rounded-full mb-4 mx-auto">
                        <i class="fas fa-balance-scale text-3xl text-amber-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">الميزانية العمومية</h3>
                    <p class="text-sm text-gray-600 text-center mb-4">عرض الأصول والخصوم وحقوق الملكية</p>
                    <a href="/modules/reports/balance_sheet.php?date_from=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                       class="block w-full text-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                        <i class="fas fa-eye ml-2"></i>عرض التقرير
                    </a>
                </div>
            </div>

            <!-- التدفقات النقدية -->
            <div class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition duration-200">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 bg-purple-100 rounded-full mb-4 mx-auto">
                        <i class="fas fa-exchange-alt text-3xl text-purple-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">التدفقات النقدية</h3>
                    <p class="text-sm text-gray-600 text-center mb-4">تتبع حركة النقد الداخل والخارج</p>
                    <a href="/modules/reports/revenue_income.php?date_from=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                       class="block w-full text-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition duration-200">
                        <i class="fas fa-eye ml-2"></i>عرض التقرير
                    </a>
                </div>
            </div>

            <!-- كشف حساب -->
            <div class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition duration-200">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 bg-yellow-100 rounded-full mb-4 mx-auto">
                        <i class="fas fa-file-invoice text-3xl text-yellow-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">كشف حساب</h3>
                    <p class="text-sm text-gray-600 text-center mb-4">عرض حركات حساب معين</p>
                    <a href="/modules/reports/bank_accounts_report.php?date_from=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                       class="block w-full text-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition duration-200">
                        <i class="fas fa-eye ml-2"></i>عرض التقرير
                    </a>
                </div>
            </div>

            <!-- ميزان المراجعة -->
            <div class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition duration-200">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 bg-indigo-100 rounded-full mb-4 mx-auto">
                        <i class="fas fa-calculator text-3xl text-indigo-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">ميزان المراجعة</h3>
                    <p class="text-sm text-gray-600 text-center mb-4">عرض أرصدة جميع الحسابات</p>
                    <a href="trial-balance.php?date_from=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                       class="block w-full text-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200">
                        <i class="fas fa-eye ml-2"></i>عرض التقرير
                    </a>
                </div>
            </div>

            <!-- دفتر اليومية -->
            <div class="bg-white shadow rounded-lg overflow-hidden hover:shadow-lg transition duration-200">
                <div class="p-6">
                    <div class="flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mb-4 mx-auto">
                        <i class="fas fa-book text-3xl text-red-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">دفتر اليومية</h3>
                    <p class="text-sm text-gray-600 text-center mb-4">عرض جميع القيود اليومية</p>
                    <a href="journal.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" 
                       class="block w-full text-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-200">
                        <i class="fas fa-eye ml-2"></i>عرض التقرير
                    </a>
                </div>
            </div>

        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
