<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

// --- START OF CHANGES ---

// Initialize all permission variables
$can_view = false;
$can_edit_attribute = false; // For editing the main attribute
$can_add_values = false;
$can_edit_values = false; // For editing the attribute's values

if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    // Check permission to view the attribute page
    $can_view = hasPermission($_SESSION['user_id'], 'attributes', 'view');
    // Check permission to edit the attribute itself
    $can_edit_attribute = hasPermission($_SESSION['user_id'], 'attributes', 'edit');
    // Check permission to add new attribute values
    $can_add_values = hasPermission($_SESSION['user_id'], 'attribute_values', 'add');
    // Check permission to edit existing attribute values
    $can_edit_values = hasPermission($_SESSION['user_id'], 'attribute_values', 'edit');
}

// --- END OF CHANGES ---


$page_title = 'عرض تفاصيل السمة';
$error_message = '';
$attribute_id = $_GET['id'] ?? null;

if (!$attribute_id) {
    header('Location: index.php');
    exit();
}

$attribute = null;
$attribute_values = []; // To list associated values

if (!$can_view) {
    $error_message = 'ليس لديك صلاحية لعرض تفاصيل السمات.';
} else {
    try {
        // Fetch the attribute details
        $stmt = $db->prepare("SELECT * FROM attributes WHERE id = ?");
        $stmt->execute([$attribute_id]);
        $attribute = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attribute) {
            $error_message = 'السمة المطلوبة غير موجودة.';
        } else {
            // Fetch associated attribute values
            $stmt = $db->prepare("SELECT id, value, display_order, is_active FROM attribute_values WHERE attribute_id = ? ORDER BY display_order, value");
            $stmt->execute([$attribute_id]);
            $attribute_values = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب بيانات السمة: " . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">تفاصيل السمة: <?php echo htmlspecialchars($attribute['name'] ?? 'غير موجودة'); ?></h1>
                <div class="flex items-center space-x-3 space-x-reverse">
                    <a href="index.php"
                        class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للسمات
                    </a>
                    <!-- Use the correct permission variable here -->
                    <?php if ($can_edit_attribute && $attribute): ?>
                        <a href="edit.php?id=<?php echo htmlspecialchars($attribute_id); ?>"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-edit ml-2"></i> تعديل السمة
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
                <?php elseif ($attribute): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm font-medium text-gray-500">اسم السمة</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($attribute['name']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">مستوى الترتيب</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($attribute['display_order']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الحالة</p>
                            <p class="mt-1 text-lg text-gray-900">
                                <?php if ($attribute['is_active']): ?>
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
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($attribute['created_at'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">آخر تحديث</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($attribute['updated_at'])); ?></p>
                        </div>
                    </div>

                    <div class="mt-8 border-t border-gray-200 pt-8">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-xl font-bold text-gray-900">قيم السمة (المقاسات/المواصفات)</h2>
                            <?php if ($can_add_values): ?>
                                <a href="../attribute_values/add.php?attribute_id=<?php echo htmlspecialchars($attribute_id); ?>"
                                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200 text-sm">
                                    <i class="fas fa-plus ml-2"></i> إضافة قيمة جديدة
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($attribute_values)): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">القيمة</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الترتيب</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($attribute_values as $value): ?>
                                            <tr class="hover:bg-gray-50 <?php echo $value['is_active'] == 0 ? 'bg-red-50' : ''; ?>">
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center"><?php echo htmlspecialchars($value['value']); ?></td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center"><?php echo htmlspecialchars($value['display_order']); ?></td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                                    <?php if ($value['is_active']): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">نشط</span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">معطل</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-center">
                                                    <div class="flex justify-center items-center space-x-2 space-x-reverse">
                                                        <!-- Use the correct permission variable here -->
                                                        <?php if ($can_edit_values): ?>
                                                            <a href="../attribute_values/edit.php?id=<?php echo $value['id']; ?>&attribute_id=<?php echo htmlspecialchars($attribute_id); ?>"
                                                                class="text-green-600 hover:text-green-900" title="تعديل القيمة"><i
                                                                    class="fas fa-edit"></i></a>
                                                            <a href="../attribute_values/index.php?action=toggle_active&id=<?php echo $value['id']; ?>&status=<?php echo $value['is_active']; ?>&attribute_id=<?php echo htmlspecialchars($attribute_id); ?>"
                                                                class="<?php echo $value['is_active'] ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'; ?>"
                                                                title="<?php echo $value['is_active'] ? 'تعطيل القيمة' : 'تفعيل القيمة'; ?>"
                                                                onclick="return confirm('<?php echo $value['is_active'] ? 'هل أنت متأكد من تعطيل هذه القيمة؟' : 'هل أنت متأكد من تفعيل هذه القيمة؟'; ?>')">
                                                                <i class="fas fa-power-off"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-600">لا توجد قيم محددة لهذه السمة بعد.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>