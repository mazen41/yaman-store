<?php
session_start();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة المصروفات';

// Handle success/error messages from other actions (e.g., delete)
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);


// --- Data Fetching ---

// Get filters from GET request or set defaults
$date_from = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Base SQL query to fetch expenses
$sql = "SELECT e.*, ec.category_name, ec.color,
        u.full_name as created_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.expense_date BETWEEN ? AND ?";
$params = [$date_from, $date_to];

// Append filters to the query
if ($category_filter) {
    $sql .= " AND e.category_id = ?";
    $params[] = $category_filter;
}

if ($status_filter) {
    $sql .= " AND e.status = ?";
    $params[] = $status_filter;
}

if ($search) {
    $sql .= " AND (e.expense_number LIKE ? OR e.description LIKE ? OR e.vendor_name LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

$sql .= " ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch statistics based on the date range
$stats_stmt = $db->prepare("
    SELECT
    COUNT(*) as total_expenses,
    SUM(amount) as total_amount,
    SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_amount,
    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
");
$stats_stmt->execute([$date_from, $date_to]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch active categories for the filter dropdown
$categories = $db->query("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-100/50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Page Header -->
        <div class="bg-gradient-to-r from-blue-600 to-indigo-600 shadow-lg rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-1">
                            <i class="fas fa-wallet mr-3"></i>
                            إدارة المصروفات
                        </h1>
                        <p class="text-blue-100">عرض وتتبع وإدارة جميع نفقاتك في مكان واحد.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="add.php"
                            class="bg-white text-blue-600 px-6 py-3 rounded-lg font-bold hover:bg-blue-50 transition-all duration-300 shadow-md hover:shadow-lg hover:scale-105 transform">
                            <i class="fas fa-plus-circle ml-2"></i>
                            إضافة مصروف
                        </a>
                        <a href="categories.php"
                            class="bg-blue-700 text-white px-6 py-3 rounded-lg font-bold hover:bg-blue-800 transition-all duration-300">
                            <i class="fas fa-folder-tree ml-2"></i>
                            إدارة الفئات
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Alert Messages -->
        <?php if ($success_message): ?>
            <div id="alert-success" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg shadow-md" role="alert">
                <p class="font-bold">نجاح</p>
                <p><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div id="alert-error" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow-md" role="alert">
                <p class="font-bold">خطأ</p>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow p-6 border-l-4 border-blue-500 transition-all duration-300 hover:shadow-xl hover:border-blue-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium mb-1">إجمالي المصروفات</p>
                        <p class="text-3xl font-bold text-gray-800">
                            <?php echo number_format($stats['total_amount'] ?? 0, 0); ?>
                            <span class="text-base text-gray-500 font-medium">ريال</span>
                        </p>
                    </div>
                    <div class="bg-blue-100 p-4 rounded-full">
                        <i class="fas fa-coins text-3xl text-blue-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-6 border-l-4 border-green-500 transition-all duration-300 hover:shadow-xl hover:border-green-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium mb-1">المصروفات المعتمدة</p>
                        <p class="text-3xl font-bold text-green-700">
                             <?php echo number_format($stats['approved_amount'] ?? 0, 0); ?>
                             <span class="text-base text-gray-500 font-medium">ريال</span>
                        </p>
                    </div>
                    <div class="bg-green-100 p-4 rounded-full">
                        <i class="fas fa-check-circle text-3xl text-green-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-6 border-l-4 border-yellow-500 transition-all duration-300 hover:shadow-xl hover:border-yellow-600">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm font-medium mb-1">قيد الانتظار</p>
                        <p class="text-3xl font-bold text-yellow-700">
                             <?php echo number_format($stats['pending_amount'] ?? 0, 0); ?>
                             <span class="text-base text-gray-500 font-medium">ريال</span>
                        </p>
                    </div>
                    <div class="bg-yellow-100 p-4 rounded-full">
                        <i class="fas fa-clock text-3xl text-yellow-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow p-6 mb-8">
             <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">من تاريخ</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-shadow">
                    </div>
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">إلى تاريخ</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-shadow">
                    </div>
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-2">الفئة</label>
                        <select id="category" name="category"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-shadow">
                            <option value="">جميع الفئات</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">الحالة</label>
                        <select id="status" name="status"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-shadow">
                            <option value="">جميع الحالات</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>معتمد</option>
                        </select>
                    </div>
                    <div class="col-span-1 md:col-span-5 lg:col-span-1">
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-2">بحث سريع</label>
                         <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="رقم، وصف..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-shadow">
                    </div>
                </div>
                <div class="flex justify-start items-center gap-4 pt-2">
                    <button type="submit" class="bg-blue-600 text-white px-7 py-2.5 rounded-lg hover:bg-blue-700 transition-all duration-300 shadow-md hover:shadow-lg transform hover:scale-105">
                        <i class="fas fa-search ml-2"></i>
                        تطبيق الفلتر
                    </button>
                    <a href="index.php" class="text-gray-600 hover:text-blue-700 font-medium transition-colors">
                        <i class="fas fa-redo ml-1"></i>
                        إعادة تعيين
                    </a>
                </div>
            </form>
        </div>

        <!-- Expenses Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">رقم المصروف</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">الفئة</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">الوصف</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-600 uppercase tracking-wider">المبلغ</th>
                            <th class="px-6 py-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-16 text-center">
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-folder-open text-6xl text-gray-300 mb-4"></i>
                                        <p class="text-gray-500 text-lg font-medium">لا توجد مصروفات تطابق البحث المحدد.</p>
                                        <p class="text-gray-400 text-sm">حاول تغيير الفلاتر أو إضافة مصروف جديد.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $expense): ?>
                                <tr class="hover:bg-gray-50/70 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap font-mono text-gray-700">
                                        <?php echo htmlspecialchars($expense['expense_number']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                        <?php echo date('d M, Y', strtotime($expense['expense_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full"
                                            style="background-color: <?php echo htmlspecialchars($expense['color'] ?? '#cccccc'); ?>20; color: <?php echo htmlspecialchars($expense['color'] ?? '#333333'); ?>">
                                            <?php echo htmlspecialchars($expense['category_name'] ?? 'غير محدد'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 max-w-sm truncate text-gray-800" title="<?php echo htmlspecialchars($expense['description']); ?>">
                                        <?php echo htmlspecialchars($expense['description']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-lg font-bold text-indigo-600"><?php echo number_format($expense['amount'], 0); ?></span>
                                        <span class="text-xs text-gray-500"><?php echo htmlspecialchars($expense['currency']); ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <select class="status-select rounded-lg border-2 p-1 text-sm font-semibold focus:ring-2 focus:ring-blue-500 transition-all
                                            <?php
                                                $status_classes = [
                                                    'pending' => 'border-yellow-400 bg-yellow-50 text-yellow-800',
                                                    'approved' => 'border-green-400 bg-green-50 text-green-800',
                                                ];
                                                echo $status_classes[$expense['status']] ?? 'border-gray-300';
                                            ?>" 
                                            data-expense-id="<?php echo $expense['id']; ?>"
                                            <?php echo $expense['status'] === 'approved' ? 'disabled' : ''; ?>
                                        >
                                            <option value="pending" <?php echo $expense['status'] == 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                                            <option value="approved" <?php echo $expense['status'] == 'approved' ? 'selected' : ''; ?>>معتمد</option>
                                        </select>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-4">
                                            <a href="view.php?id=<?php echo $expense['id']; ?>" class="text-gray-500 hover:text-blue-600 transition-colors" title="عرض التفاصيل"><i class="fas fa-eye text-lg"></i></a>
                                            <a href="edit.php?id=<?php echo $expense['id']; ?>" class="text-gray-500 hover:text-yellow-600 transition-colors" title="تعديل"><i class="fas fa-edit text-lg"></i></a>
                                            <form action="delete_expense.php" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا المصروف؟ لا يمكن التراجع عن هذا الإجراء.');" class="inline">
                                                <input type="hidden" name="expense_id" value="<?php echo $expense['id']; ?>">
                                                <button type="submit" class="text-gray-500 hover:text-red-600 transition-colors" title="حذف"><i class="fas fa-trash-alt text-lg"></i></button>
                                            </form>
                                        </div>
                                    </td>
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
document.addEventListener('DOMContentLoaded', function () {
    // --- Auto-hide alert messages ---
    const hideAlerts = () => {
        const successAlert = document.getElementById('alert-success');
        const errorAlert = document.getElementById('alert-error');
        if (successAlert) {
            setTimeout(() => { successAlert.style.transition = 'opacity 1s'; successAlert.style.opacity = '0'; }, 4000);
            setTimeout(() => { successAlert.remove(); }, 5000);
        }
        if (errorAlert) {
            setTimeout(() => { errorAlert.style.transition = 'opacity 1s'; errorAlert.style.opacity = '0'; }, 4000);
            setTimeout(() => { errorAlert.remove(); }, 5000);
        }
    };
    hideAlerts();

    // --- Handle status change via AJAX ---
    const statusSelects = document.querySelectorAll('.status-select');
    statusSelects.forEach(select => {
        select.addEventListener('change', function () {
            const expenseId = this.dataset.expenseId;
            const newStatus = this.value;
            
            // --- Send request to the server to update status ---
            fetch('update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `expense_id=${expenseId}&new_status=${newStatus}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Reload to reflect changes in stats and UI state (e.g., disable select)
                    location.reload();
                } else {
                    // On failure, alert user and reload to revert the visual change
                    alert('فشل تحديث الحالة: ' + data.message);
                    location.reload(); 
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ أثناء تحديث الحالة.');
                location.reload();
            });
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>