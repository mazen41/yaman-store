<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
$page_title = 'تقرير بطاقات العملاء';

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$where_clauses = ["cc.issue_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($search)) {
    $where_clauses[] = "(cc.card_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(' AND ', $where_clauses);

try {
    // Get Summary Totals
    $summary_query = "
        SELECT 
            COUNT(cc.id) as total_cards,
            COALESCE(SUM(cc.initial_amount), 0) as total_initial,
            COALESCE(SUM(cc.current_balance), 0) as total_current,
            COALESCE(SUM(cc.purchase_amount), 0) as total_purchase
        FROM customer_cards cc
        LEFT JOIN customers c ON cc.customer_id = c.id
        WHERE $where_sql
    ";
    $stmt_summary = $db->prepare($summary_query);
    $stmt_summary->execute($params);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);

    // Get Data
    $data_query = "
        SELECT 
            cc.*, 
            c.name as customer_name,
            u.username as created_by_name
        FROM customer_cards cc
        LEFT JOIN customers c ON cc.customer_id = c.id
        LEFT JOIN users u ON cc.created_by = u.id
        WHERE $where_sql
        ORDER BY cc.issue_date DESC, cc.id DESC
    ";
    $stmt_data = $db->prepare($data_query);
    $stmt_data->execute($params);
    $cards = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-emerald-600 to-teal-700 shadow-xl rounded-2xl mb-8 p-6 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-white flex items-center">
                    <i class="fas fa-id-card ml-3"></i>
                    <?php echo $page_title; ?>
                </h1>
                <p class="text-emerald-100 mt-2">عرض تفصيلي لبطاقات العملاء المصدرة وأرصدتها</p>
            </div>
            <div class="flex gap-2">
                <button onclick="exportReport('pdf')" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition font-semibold flex items-center shadow">
                    <i class="fas fa-file-pdf ml-2"></i> تصدير PDF
                </button>
                <button onclick="exportReport('excel')" class="px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600 transition font-semibold flex items-center shadow">
                    <i class="fas fa-file-excel ml-2"></i> تصدير Excel
                </button>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="bg-white shadow rounded-xl mb-6 p-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">من تاريخ الإصدار</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">إلى تاريخ الإصدار</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">بحث (رقم البطاقة / العميل)</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ادخل رقم البطاقة أو الاسم..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="w-full px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 transition font-bold"><i class="fas fa-search ml-1"></i> بحث</button>
                    <a href="customer_cards_report.php" class="w-full px-4 py-2 bg-gray-200 text-gray-700 text-center rounded-lg hover:bg-gray-300 transition font-bold"><i class="fas fa-redo ml-1"></i> إعادة</a>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-6 rounded-xl shadow border-r-4 border-blue-500">
                <p class="text-sm text-gray-500 font-semibold mb-1">إجمالي عدد البطاقات</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($summary['total_cards']); ?></p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow border-r-4 border-emerald-500">
                <p class="text-sm text-gray-500 font-semibold mb-1">إجمالي الأرصدة الأولية</p>
                <p class="text-2xl font-bold text-emerald-600"><?php echo number_format($summary['total_initial'], 2); ?> ر.ي</p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow border-r-4 border-amber-500">
                <p class="text-sm text-gray-500 font-semibold mb-1">إجمالي الأرصدة الحالية (المتبقية)</p>
                <p class="text-2xl font-bold text-amber-600"><?php echo number_format($summary['total_current'], 2); ?> ر.ي</p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow border-r-4 border-purple-500">
                <p class="text-sm text-gray-500 font-semibold mb-1">إجمالي مبالغ الشراء (الإيراد)</p>
                <p class="text-2xl font-bold text-purple-600"><?php echo number_format($summary['total_purchase'], 2); ?> ر.ي</p>
            </div>
        </div>

        <!-- Data Table -->
        <div class="bg-white shadow rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-right">
                    <thead class="bg-gray-50 text-gray-700 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-sm">تاريخ الإصدار</th>
                            <th class="px-4 py-3 font-semibold text-sm">رقم البطاقة</th>
                            <th class="px-4 py-3 font-semibold text-sm">اسم العميل</th>
                            <th class="px-4 py-3 font-semibold text-sm">الرصيد الأولي</th>
                            <th class="px-4 py-3 font-semibold text-sm">الرصيد الحالي</th>
                            <th class="px-4 py-3 font-semibold text-sm">مبلغ الشراء</th>
                            <th class="px-4 py-3 font-semibold text-sm">بواسطة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($cards)): ?>
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">لا توجد بيانات مطابقة للبحث</td></tr>
                        <?php else: ?>
                            <?php foreach ($cards as $row): ?>
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-4 py-3 text-sm whitespace-nowrap"><?php echo htmlspecialchars($row['issue_date']); ?></td>
                                    <td class="px-4 py-3 text-sm font-mono font-bold text-emerald-600"><?php echo htmlspecialchars($row['card_number']); ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                    <td class="px-4 py-3 text-sm font-mono"><?php echo number_format($row['initial_amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-sm font-mono <?php echo $row['current_balance'] > 0 ? 'text-amber-600 font-bold' : 'text-gray-400'; ?>">
                                        <?php echo number_format($row['current_balance'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-mono text-purple-600"><?php echo number_format($row['purchase_amount'], 2); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo htmlspecialchars($row['created_by_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    function exportReport(format) {
        const urlParams = new URLSearchParams(window.location.search);
        let dateFrom = urlParams.get('date_from') || '<?php echo $date_from; ?>';
        let dateTo = urlParams.get('date_to') || '<?php echo $date_to; ?>';
        let search = urlParams.get('search') || '';

        window.location.href = `export_report.php?type=customer_cards&format=${format}&date_from=${dateFrom}&date_to=${dateTo}&search=${encodeURIComponent(search)}`;
    }
</script>

<?php include '../../includes/footer.php'; ?>