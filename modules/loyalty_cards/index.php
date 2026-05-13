<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'loyalty_cards', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add = hasPermission($_SESSION['user_id'], 'loyalty_cards', 'add');
$can_edit = hasPermission($_SESSION['user_id'], 'loyalty_cards', 'edit');

$page_title = 'بطاقات الهدية';

// Fetch all data
try {
    $stmt = $db->query("SELECT * FROM loyalty_cards ORDER BY id DESC");
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
                    بطاقات الهدية
                </h1>
                <p class="text-blue-100 mt-2">إدارة بطاقات الهدية</p>
            </div>
            <?php if (hasPermission($_SESSION['user_id'], 'loyalty_cards', 'add')): ?>
            <a href="add.php" class="bg-white text-blue-600 px-6 py-3 rounded-lg hover:bg-blue-50 font-semibold transition">
                <i class="fas fa-plus ml-2"></i>
                إضافة جديد
            </a>
            <?php endif; ?>
        </div>
    </div>

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
                                <?php if (hasPermission($_SESSION['user_id'], 'loyalty_cards', 'edit')): ?>
                                <a href="edit.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
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