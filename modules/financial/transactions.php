<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'financial', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$page_title = 'سجل المعاملات المالية';

// --- 1. SETUP FILTERING AND PAGINATION ---

// Get filter parameters from URL
$search_term = trim($_GET['search'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Pagination settings
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 25;
$offset = ($page - 1) * $records_per_page;

// --- 2. BUILD DYNAMIC QUERY ---

$base_query = "
    FROM financial_transactions ft
    LEFT JOIN users u ON ft.created_by = u.id
";
$where_clauses = [];
$params = [];

// Apply filters
if (!empty($search_term)) {
    $where_clauses[] = "(ft.transaction_number LIKE :search OR ft.description LIKE :search)";
    $params[':search'] = '%' . $search_term . '%';
}
if (!empty($from_date)) {
    $where_clauses[] = "ft.transaction_date >= :from_date";
    $params[':from_date'] = $from_date;
}
if (!empty($to_date)) {
    $where_clauses[] = "ft.transaction_date <= :to_date";
    $params[':to_date'] = $to_date;
}
if (!empty($type_filter)) {
    $where_clauses[] = "ft.reference_type = :type";
    $params[':type'] = $type_filter;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);
}

// --- 3. FETCH DATA WITH PAGINATION ---

// Get total number of records for pagination
$total_records_stmt = $db->prepare("SELECT COUNT(ft.id) " . $base_query . $where_sql);
$total_records_stmt->execute($params);
$total_records = $total_records_stmt->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Get the actual transaction data for the current page
$transactions_query = "
    SELECT ft.*, u.full_name as created_by_name
    " . $base_query . $where_sql . "
    ORDER BY ft.transaction_date DESC, ft.created_at DESC
    LIMIT :limit OFFSET :offset
";

$transactions_stmt = $db->prepare($transactions_query);

// Bind pagination parameters
$transactions_stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$transactions_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

// Bind filter parameters
foreach ($params as $key => &$val) {
    $transactions_stmt->bindParam($key, $val);
}

$transactions_stmt->execute();
$transactions = $transactions_stmt->fetchAll(PDO::FETCH_ASSOC);


// Fetch distinct transaction types for the filter dropdown
$types_stmt = $db->query("SELECT DISTINCT reference_type FROM financial_transactions ORDER BY reference_type");
$transaction_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">عرض جميع المعاملات المالية مع خيارات الفلترة</p>
                    </div>
                    <div class="mt-4 sm:mt-0 flex flex-wrap gap-2">
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200"><i class="fas fa-arrow-right ml-2"></i>العودة للوحة المالية</a>
                        <a href="transaction-add.php" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition duration-200"><i class="fas fa-plus ml-2"></i>معاملة مالية جديدة</a>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="px-6 py-4">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">بحث</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="رقم المعاملة، الوصف..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">من تاريخ</label>
                        <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">إلى تاريخ</label>
                        <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">النوع</label>
                        <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                            <option value="">الكل</option>
                            <?php 
                                $type_labels = ['sale' => 'مبيعات', 'purchase' => 'مشتريات', 'payment' => 'دفع', 'receipt' => 'استلام', 'adjustment' => 'تسوية', 'expense' => 'مصروف'];
                                foreach ($transaction_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php if ($type_filter == $type) echo 'selected'; ?>>
                                    <?php echo $type_labels[$type] ?? htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-span-1 md:col-span-4 flex gap-2">
                        <button type="submit" class="flex-grow px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200"><i class="fas fa-filter ml-2"></i>تطبيق الفلتر</button>
                        <a href="transactions.php" class="flex-grow text-center px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition duration-200">إعادة تعيين</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Transactions Table -->
        <div class="bg-white shadow rounded-lg">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم المعاملة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الوصف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">النوع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">أنشئ بواسطة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-receipt text-4xl mb-4 text-gray-300"></i>
                                    <p>لا توجد معاملات تطابق معايير البحث</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($transaction['transaction_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($transaction['description']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900"><?php echo number_format($transaction['total_amount'], 2, '.', ','); ?> ريال</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php
                                        $type_colors = ['sale' => 'bg-green-100 text-green-800', 'purchase' => 'bg-blue-100 text-blue-800', 'payment' => 'bg-red-100 text-red-800', 'receipt' => 'bg-yellow-100 text-yellow-800', 'adjustment' => 'bg-gray-100 text-gray-800', 'expense' => 'bg-purple-100 text-purple-800'];
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_colors[$transaction['reference_type']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                            <?php echo $type_labels[$transaction['reference_type']] ?? $transaction['reference_type']; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($transaction['created_by_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2 space-x-reverse">
                                            <a href="transaction-view.php?id=<?php echo $transaction['id']; ?>" class="text-blue-600 hover:text-blue-900" title="عرض"><i class="fas fa-eye"></i></a>
                                            <a href="transaction-print.php?id=<?php echo $transaction['id']; ?>" class="text-purple-600 hover:text-purple-900" title="طباعة"><i class="fas fa-print"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200">
                    <nav class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            عرض <?php echo count($transactions); ?> من أصل <?php echo $total_records; ?> سجلات
                        </div>
                        <div class="flex-1 flex justify-end">
                            <?php
                                // Build query string to preserve filters
                                $query_params = $_GET;
                                
                                // Previous page
                                if ($page > 1) {
                                    $query_params['page'] = $page - 1;
                                    echo '<a href="?' . http_build_query($query_params) . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">السابق</a>';
                                }

                                // Next page
                                if ($page < $total_pages) {
                                    $query_params['page'] = $page + 1;
                                    echo '<a href="?' . http_build_query($query_params) . '" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">التالي</a>';
                                }
                            ?>
                        </div>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>