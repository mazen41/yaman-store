<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'cities', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add = hasPermission($_SESSION['user_id'], 'cities', 'add');
$can_edit = hasPermission($_SESSION['user_id'], 'cities', 'edit');

$page_title = 'إدارة المدن';

// Fetch all data
try {
    $stmt = $db->query("SELECT * FROM cities ORDER BY id DESC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
    $error_message = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-900 flex items-center gap-3">
                <span class="p-3 bg-blue-100 text-blue-600 rounded-xl">
                    <i class="fas fa-map-marked-alt"></i>
                </span>
                إدارة المدن وتكاليف الشحن
            </h1>
            <p class="text-gray-500 mt-2 mr-14">يمكنك إضافة وتعديل المدن وتحديد أسعار الشحن لكل مدينة.</p>
        </div>
        
        <?php if ($can_add): ?>
        <a href="add.php" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-xl text-white bg-blue-600 hover:bg-blue-700 shadow-sm transition-all duration-200 transform hover:-translate-y-1 w-full md:w-auto">
            <i class="fas fa-plus ml-2"></i>
            إضافة مدينة جديدة
        </a>
        <?php endif; ?>
    </div>

    <!-- Stats Summary -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center gap-4">
            <div class="w-12 h-12 bg-green-50 text-green-600 rounded-lg flex items-center justify-center text-xl">
                <i class="fas fa-city"></i>
            </div>
            <div>
                <p class="text-sm text-gray-500">إجمالي المدن</p>
                <p class="text-xl font-bold text-gray-900"><?php echo count($items); ?></p>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="bg-green-100 border-r-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm flex justify-between items-center">
            <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
            <i class="fas fa-check-circle"></i>
        </div>
    <?php endif; ?>

    <!-- Main Table Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden w-full">
        <!-- Added w-full and touch scrolling support for mobile -->
        <div class="overflow-x-auto overflow-y-auto w-full max-h-[60vh]" style="-webkit-overflow-scrolling: touch;">
            <!-- Added min-w-[700px] to force horizontal scrolling on small screens -->
            <table class="w-full text-right border-collapse min-w-[700px]">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 sticky top-0 z-10 shadow-sm">
                        <th class="px-6 py-4 text-sm font-bold text-gray-600 uppercase tracking-wider whitespace-nowrap">#</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-600 uppercase tracking-wider whitespace-nowrap">المدينة</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-600 uppercase tracking-wider whitespace-nowrap">تكلفة الشحن</th>
                        <th class="px-6 py-4 text-sm font-bold text-gray-600 uppercase tracking-wider text-center whitespace-nowrap">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="4" class="px-6 py-16 text-center">
                            <div class="flex flex-col items-center">
                                <i class="fas fa-folder-open text-gray-300 text-5xl mb-4"></i>
                                <p class="text-gray-500 text-lg">لا توجد مدن مضافة حالياً</p>
                                <a href="add.php" class="text-blue-600 hover:underline mt-2">أضف أول مدينة الآن</a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $index => $item): ?>
                        <tr class="hover:bg-blue-50/50 transition-colors">
                            <td class="px-6 py-4 text-sm text-gray-400 font-mono whitespace-nowrap">
                                <?php echo $index + 1; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                    <?php echo number_format($item['shipping_cost'], 2); ?>
                                    <span class="mr-1 text-xs opacity-75"><?php echo htmlspecialchars($item['currency']); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center whitespace-nowrap">
                                <div class="flex justify-center items-center gap-2">
                                    <?php if ($can_edit): ?>
                                    <a href="edit.php?id=<?php echo $item['id']; ?>" 
                                       class="flex items-center gap-1 bg-white border border-blue-200 text-blue-600 px-3 py-1.5 rounded-lg hover:bg-blue-600 hover:text-white transition-all text-sm shadow-sm">
                                        <i class="fas fa-edit"></i>
                                        تعديل
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="mt-6 text-center text-gray-400 text-xs">
        عرض <?php echo count($items); ?> مدينة متوفرة في النظام
    </div>
</div>

<?php include '../../includes/footer.php'; ?>