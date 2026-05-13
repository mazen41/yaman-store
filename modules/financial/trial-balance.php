<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// يمكنك إضافة التحقق من الصلاحيات هنا إذا أردت
// require_once '../../includes/check_permissions.php';

$page_title = 'ميزان المراجعة';

// تحديد التاريخ. إذا لم يتم توفيره، استخدم تاريخ اليوم
$report_date = $_GET['report_date'] ?? date('Y-m-d');

$accounts_balances = [];
$total_debit = 0;
$total_credit = 0;
$error_message = '';

try {
    // هذه الاستعلامة هي قلب التقرير
    // تقوم بحساب إجمالي الحركات المدينة والدائنة لكل حساب حتى تاريخ التقرير
    $stmt = $db->prepare("
        SELECT 
            a.code,
            a.name,
            COALESCE(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) as total_credit
        FROM 
            accounts a
        LEFT JOIN 
            journal_entry_items jei ON a.id = jei.account_id
        LEFT JOIN
            journal_entries je ON jei.entry_id = je.id AND je.entry_date <= :report_date
        WHERE a.is_active = 1
        GROUP BY 
            a.id, a.code, a.name
        HAVING -- نستخدم HAVING لاستبعاد الحسابات التي ليس لها رصيد
            total_debit > 0 OR total_credit > 0
        ORDER BY 
            a.code ASC
    ");

    $stmt->execute([':report_date' => $report_date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // معالجة النتائج لتحديد الرصيد النهائي لكل حساب
    foreach ($results as $row) {
        $balance = $row['total_debit'] - $row['total_credit'];
        $debit_balance = 0;
        $credit_balance = 0;

        if ($balance > 0.005) { // استخدام هامش صغير لتجنب أخطاء الفاصلة العائمة
            $debit_balance = $balance;
            $total_debit += $debit_balance;
        } elseif ($balance < -0.005) {
            $credit_balance = abs($balance);
            $total_credit += $credit_balance;
        }

        $accounts_balances[] = [
            'code' => $row['code'],
            'name' => $row['name'],
            'debit' => $debit_balance,
            'credit' => $credit_balance
        ];
    }

} catch(Exception $e) {
    $error_message = "حدث خطأ أثناء إعداد التقرير: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                            <i class="fas fa-balance-scale text-blue-600"></i>
                            <?php echo $page_title; ?>
                        </h1>
                        <p class="text-gray-600 mt-1">عرض أرصدة الحسابات حتى تاريخ محدد</p>
                    </div>
                     <a href="reports.php" class="mt-4 sm:mt-0 inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للتقارير
                    </a>
                </div>
            </div>
            <!-- Date Filter -->
            <div class="px-6 py-4 border-t">
                <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">عرض التقرير حتى تاريخ</label>
                        <input type="date" name="report_date" value="<?php echo htmlspecialchars($report_date); ?>" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"><i class="fas fa-filter ml-2"></i>تطبيق</button>
                </form>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Report Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b">
                <h2 class="text-lg font-semibold text-gray-800">ميزان المراجعة في تاريخ: <?php echo htmlspecialchars($report_date); ?></h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase">رقم الحساب</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase">اسم الحساب</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase">رصيد مدين</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase">رصيد دائن</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($accounts_balances)): ?>
                        <tr><td colspan="4" class="px-6 py-12 text-center text-gray-500">لا توجد بيانات لعرضها في الفترة المحددة.</td></tr>
                    <?php else: ?>
                        <?php foreach ($accounts_balances as $acc): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($acc['code']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($acc['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-800"><?php echo $acc['debit'] > 0 ? number_format($acc['debit'], 2) : '-'; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-800"><?php echo $acc['credit'] > 0 ? number_format($acc['credit'], 2) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-100 font-bold">
                    <tr>
                        <td colspan="2" class="px-6 py-4 text-left text-gray-800">الإجمالي</td>
                        <td class="px-6 py-4 text-right font-mono text-gray-900"><?php echo number_format($total_debit, 2); ?></td>
                        <td class="px-6 py-4 text-right font-mono text-gray-900"><?php echo number_format($total_credit, 2); ?></td>
                    </tr>
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center">
                            <?php if(abs($total_debit - $total_credit) < 0.01 && $total_debit > 0): ?>
                                <span class="px-4 py-2 rounded-full bg-green-100 text-green-800 font-semibold"><i class="fas fa-check-circle ml-2"></i> متوازن</span>
                            <?php else: ?>
                                <span class="px-4 py-2 rounded-full bg-red-100 text-red-800 font-semibold"><i class="fas fa-exclamation-triangle ml-2"></i> غير متوازن</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>