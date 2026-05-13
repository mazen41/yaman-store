<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'suppliers', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$page_title = 'الموردين';

// Currency filter
$selected_currency = $_GET['currency'] ?? 'YER';
if (!in_array($selected_currency, ['YER', 'SAR'])) {
    $selected_currency = 'YER';
}
$currency_symbol = ($selected_currency == 'SAR') ? 'ر.ي' : 'ر.ي';

// Fetch data
try {
    $where_clauses = [];
    $params = [];
    
    if (false) {
        $where_clauses[] = "currency = ?";
        $params[] = $selected_currency;
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    $stmt = $db->prepare("SELECT * FROM suppliers $where_sql ORDER BY id DESC");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
    $error_message = 'حدث خطأ: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold flex items-center gap-3">
                    <i class="fas fa-list"></i>
                    الموردين
                </h1>
                <p class="text-blue-100 mt-2">إدارة الموردين</p>
            </div>
            <?php if (hasPermission($_SESSION['user_id'], 'suppliers', 'add')): ?>
            <a href="add.php?currency=<?php echo $selected_currency; ?>" class="bg-white text-blue-600 px-6 py-3 rounded-lg hover:bg-blue-50 font-semibold transition">
                <i class="fas fa-plus ml-2"></i>
                إضافة جديد
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Currency Filter -->
    <?php if (false): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <form method="GET" class="flex items-center gap-4">
            <label class="font-semibold text-gray-700">العملة:</label>
            <select name="currency" onchange="this.form.submit()" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <option value="YER" <?php echo $selected_currency === 'YER' ? 'selected' : ''; ?>>ريال يمني (YER)</option>
            </select>
        </form>
    </div>
    <?php endif; ?>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">البيانات</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                            <p>لا توجد بيانات</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $index => $item): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $index + 1; ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars(json_encode($item)); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <?php if (hasPermission($_SESSION['user_id'], 'suppliers', 'edit')): ?>
                                <a href="edit.php?id=<?php echo $item['id']; ?>&currency=<?php echo $selected_currency; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-edit"></i> تعديل
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>