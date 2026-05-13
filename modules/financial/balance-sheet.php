<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'الميزانية العمومية';
$report_date = $_GET['report_date'] ?? date('Y-m-d');
$error_message = '';

$assets = [];
$liabilities = [];
$equity = [];
$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;
$net_income = 0;

try {
    // --- الخطوة 1: حساب صافي الربح/الخسارة حتى تاريخ التقرير ---
    $income_stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN a.type = 'revenue' THEN jei.credit - jei.debit ELSE 0 END), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN a.type = 'expense' THEN jei.debit - jei.credit ELSE 0 END), 0) as total_expenses
        FROM 
            accounts a
        JOIN 
            journal_entry_items jei ON a.id = jei.account_id
        JOIN
            journal_entries je ON jei.entry_id = je.id
        WHERE 
            a.type IN ('revenue', 'expense') AND je.entry_date <= :report_date
    ");
    $income_stmt->execute([':report_date' => $report_date]);
    $income_result = $income_stmt->fetch(PDO::FETCH_ASSOC);
    $net_income = ($income_result['total_revenue'] ?? 0) - ($income_result['total_expenses'] ?? 0);

    // --- الخطوة 2: حساب أرصدة الأصول والالتزامات وحقوق الملكية ---
    $balance_stmt = $db->prepare("
        SELECT 
            a.code,
            a.name,
            a.type,
            (COALESCE(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END), 0) - 
             COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0)) as balance
        FROM 
            accounts a
        LEFT JOIN 
            journal_entry_items jei ON a.id = jei.account_id
        LEFT JOIN
            journal_entries je ON jei.entry_id = je.id AND je.entry_date <= :report_date
        WHERE 
            a.type IN ('asset', 'liability', 'equity') AND a.is_active = 1
        GROUP BY
            a.id, a.code, a.name, a.type
        HAVING balance != 0
        ORDER BY
            a.code ASC
    ");
    $balance_stmt->execute([':report_date' => $report_date]);
    $results = $balance_stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- الخطوة 3: تصنيف الحسابات في مجموعاتها ---
    foreach ($results as $row) {
        switch ($row['type']) {
            case 'asset':
                $assets[] = $row;
                $total_assets += $row['balance'];
                break;
            case 'liability':
                // الالتزامات رصيدها دائن، لذا نعكس الإشارة لتظهر كموجب
                $row['balance'] = -$row['balance']; 
                $liabilities[] = $row;
                $total_liabilities += $row['balance'];
                break;
            case 'equity':
                 // حقوق الملكية رصيدها دائن، لذا نعكس الإشارة
                $row['balance'] = -$row['balance'];
                $equity[] = $row;
                $total_equity += $row['balance'];
                break;
        }
    }

} catch(Exception $e) {
    $error_message = "حدث خطأ أثناء إعداد التقرير: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header and Filter -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                 <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                            <i class="fas fa-file-invoice-dollar text-green-600"></i>
                             <?php echo $page_title; ?>
                        </h1>
                        <p class="text-gray-600 mt-1">عرض الوضع المالي للشركة في تاريخ محدد</p>
                    </div>
                     <a href="reports.php" class="mt-4 sm:mt-0 inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للتقارير
                    </a>
                </div>
            </div>
            <div class="px-6 py-4 border-t">
                <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">عرض التقرير في تاريخ</label>
                        <input type="date" name="report_date" value="<?php echo htmlspecialchars($report_date); ?>" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"><i class="fas fa-filter ml-2"></i>تطبيق</button>
                </form>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Balance Sheet Content -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-bold text-center text-gray-800 mb-2">الميزانية العمومية</h2>
            <p class="text-center text-gray-600 mb-6">كما في تاريخ: <?php echo htmlspecialchars($report_date); ?></p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Assets Side -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-blue-500 pb-2 mb-4">الأصول</h3>
                    <?php foreach ($assets as $asset): ?>
                    <div class="flex justify-between items-center py-2 border-b border-dashed">
                        <span class="text-sm text-gray-700"><?php echo htmlspecialchars($asset['name']); ?></span>
                        <span class="text-sm font-mono font-semibold"><?php echo number_format($asset['balance'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                     <?php if (empty($assets)): ?>
                        <p class="text-gray-500 text-sm py-2">لا توجد أصول مسجلة.</p>
                    <?php endif; ?>
                    <div class="flex justify-between items-center pt-3 mt-2 bg-blue-50 px-2 rounded">
                        <span class="font-bold text-blue-800">إجمالي الأصول</span>
                        <span class="font-bold font-mono text-blue-800"><?php echo number_format($total_assets, 2); ?></span>
                    </div>
                </div>

                <!-- Liabilities & Equity Side -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 border-b-2 border-red-500 pb-2 mb-4">الالتزامات وحقوق الملكية</h3>
                    
                    <!-- Liabilities -->
                    <h4 class="font-bold text-gray-600 mb-2 mt-4">الالتزامات</h4>
                     <?php foreach ($liabilities as $liability): ?>
                    <div class="flex justify-between items-center py-2 border-b border-dashed">
                        <span class="text-sm text-gray-700"><?php echo htmlspecialchars($liability['name']); ?></span>
                        <span class="text-sm font-mono font-semibold"><?php echo number_format($liability['balance'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($liabilities)): ?>
                        <p class="text-gray-500 text-sm py-2">لا توجد التزامات مسجلة.</p>
                    <?php endif; ?>
                    <div class="flex justify-between items-center pt-2 mt-2 bg-red-50 px-2 rounded mb-4">
                        <span class="font-semibold text-red-800">إجمالي الالتزامات</span>
                        <span class="font-semibold font-mono text-red-800"><?php echo number_format($total_liabilities, 2); ?></span>
                    </div>

                    <!-- Equity -->
                    <h4 class="font-bold text-gray-600 mb-2 mt-6">حقوق الملكية</h4>
                    <?php foreach ($equity as $eq): ?>
                    <div class="flex justify-between items-center py-2 border-b border-dashed">
                        <span class="text-sm text-gray-700"><?php echo htmlspecialchars($eq['name']); ?></span>
                        <span class="text-sm font-mono font-semibold"><?php echo number_format($eq['balance'], 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                     <div class="flex justify-between items-center py-2 border-b border-dashed">
                        <span class="text-sm text-gray-700">صافي الربح / الخسارة للفترة</span>
                        <span class="text-sm font-mono font-semibold <?php echo $net_income >= 0 ? 'text-green-700' : 'text-red-700'; ?>">
                            <?php echo number_format($net_income, 2); ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center pt-2 mt-2 bg-green-50 px-2 rounded">
                        <span class="font-semibold text-green-800">إجمالي حقوق الملكية</span>
                        <span class="font-semibold font-mono text-green-800"><?php echo number_format($total_equity + $net_income, 2); ?></span>
                    </div>

                     <!-- Grand Total -->
                    <div class="flex justify-between items-center pt-3 mt-6 bg-gray-100 px-2 rounded">
                        <span class="font-bold text-gray-800">إجمالي الالتزامات وحقوق الملكية</span>
                        <span class="font-bold font-mono text-gray-800"><?php echo number_format($total_liabilities + $total_equity + $net_income, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>