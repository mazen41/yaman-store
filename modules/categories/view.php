<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_view = false;
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_view = hasPermission($_SESSION['user_id'], 'categories', 'view');
}

$page_title = 'عرض تفاصيل الفئة';
$error_message = '';
$category_id = $_GET['id'] ?? null;

if (!$category_id) {
    header('Location: index.php');
    exit();
}

$category = null;
$sub_categories = []; // To list direct sub-categories

if (!$can_view) {
    $error_message = 'ليس لديك صلاحية لعرض تفاصيل الفئات.';
} else {
    try {
        // Fetch the category details
        $stmt = $db->prepare("SELECT c.*, p.name AS parent_category_name FROM categories c LEFT JOIN categories p ON c.parent_id = p.id WHERE c.id = ?");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            $error_message = 'الفئة المطلوبة غير موجودة.';
        } else {
            // Fetch direct sub-categories of this category
            $stmt = $db->prepare("SELECT id, name, is_active FROM categories WHERE parent_id = ? ORDER BY display_order, name");
            $stmt->execute([$category_id]);
            $sub_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب بيانات الفئة: " . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">تفاصيل الفئة: <?php echo htmlspecialchars($category['name'] ?? 'غير موجودة'); ?></h1>
                <div class="flex items-center space-x-3 space-x-reverse">
                    <a href="index.php"
                        class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للفئات
                    </a>
                    <?php if ($can_edit && $category): ?>
                        <a href="edit.php?id=<?php echo htmlspecialchars($category_id); ?>"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-edit ml-2"></i> تعديل
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-6">
                <?php if (isset($error_message) && $error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-exclamation-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php elseif (!$can_view): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-times-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php elseif ($category): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm font-medium text-gray-500">اسم الفئة</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($category['name']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">النوع</p>
                            <p class="mt-1 text-lg text-gray-900">
                                <?php if ($category['parent_id']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800">فرعية من: <?php echo htmlspecialchars($category['parent_category_name']); ?></span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">رئيسية</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">مستوى الترتيب</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($category['display_order']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الحالة</p>
                            <p class="mt-1 text-lg text-gray-900">
                                <?php if ($category['is_active']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle ml-1"></i>نشط
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                        <i class="fas fa-ban ml-1"></i>معطل
                                    </span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">تاريخ الإضافة</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($category['created_at'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">آخر تحديث</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($category['updated_at'])); ?></p>
                        </div>
                    </div>

                    <div class="mt-8 border-t border-gray-200 pt-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4">صورة الفئة</h2>
                        <?php if ($category['image_url']): ?>
                            <img src="<?php echo htmlspecialchars($category['image_url']); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>" class="max-w-xs h-auto object-cover rounded-lg shadow-md border border-gray-200">
                        <?php else: ?>
                            <p class="text-gray-600">لا توجد صورة لهذه الفئة.</p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($sub_categories)): ?>
                        <div class="mt-8 border-t border-gray-200 pt-8">
                            <h2 class="text-xl font-bold text-gray-900 mb-4">الفئات الفرعية المباشرة</h2>
                            <ul class="space-y-2">
                                <?php foreach ($sub_categories as $sub_cat): ?>
                                    <li class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <a href="view.php?id=<?php echo $sub_cat['id']; ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                                            <?php echo htmlspecialchars($sub_cat['name']); ?>
                                        </a>
                                        <?php if ($sub_cat['is_active']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">نشط</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">معطل</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="mt-8 border-t border-gray-200 pt-8">
                            <h2 class="text-xl font-bold text-gray-900 mb-4">الفئات الفرعية المباشرة</h2>
                            <p class="text-gray-600">لا توجد فئات فرعية مباشرة لهذه الفئة.</p>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>