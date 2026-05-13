
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_view = false;
$can_edit = false; // Assuming general edit permission for colors
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_view = hasPermission($_SESSION['user_id'], 'colors', 'view');
    $can_edit = hasPermission($_SESSION['user_id'], 'colors', 'edit');
}

$page_title = 'عرض تفاصيل اللون';
$error_message = '';
$color_id = $_GET['id'] ?? null;

if (!$color_id) {
    header('Location: index.php');
    exit();
}

$color = null;

if (!$can_view) {
    $error_message = 'ليس لديك صلاحية لعرض تفاصيل الألوان.';
} else {
    try {
        // Fetch the color details
        $stmt = $db->prepare("SELECT * FROM colors WHERE id = ?");
        $stmt->execute([$color_id]);
        $color = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$color) {
            $error_message = 'اللون المطلوب غير موجود.';
        }

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب بيانات اللون: " . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">تفاصيل اللون: <?php echo htmlspecialchars($color['name'] ?? 'غير موجود'); ?></h1>
                <div class="flex items-center space-x-3 space-x-reverse">
                    <a href="index.php"
                        class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للألوان
                    </a>
                    <?php if ($can_edit && $color): ?>
                        <a href="edit.php?id=<?php echo htmlspecialchars($color_id); ?>"
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
                <?php elseif ($color): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm font-medium text-gray-500">اسم اللون</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($color['name']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">رمز اللون (Hex Code)</p>
                            <p class="mt-1 text-lg text-gray-900">
                                <span class="inline-block w-8 h-8 rounded-full border border-gray-300 ml-2" style="background-color: <?php echo htmlspecialchars($color['hex_code']); ?>;"></span>
                                <?php echo htmlspecialchars($color['hex_code']); ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">مستوى الترتيب</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($color['display_order']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الحالة</p>
                            <p class="mt-1 text-lg text-gray-900">
                                <?php if ($color['is_active']): ?>
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
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($color['created_at'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">آخر تحديث</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($color['updated_at'])); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
