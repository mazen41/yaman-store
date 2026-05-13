<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_edit = false;
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_edit = hasPermission($_SESSION['user_id'], 'colors', 'edit');
}

$page_title = 'تعديل لون';
$error_message = '';
$success_message = '';
$color_id = $_GET['id'] ?? null;

if (!$color_id) {
    header('Location: index.php');
    exit();
}

$color = null;

if (!$can_edit) {
    $error_message = 'ليس لديك صلاحية لتعديل الألوان.';
} else {
    try {
        // Fetch the color to be edited
        $stmt = $db->prepare("SELECT * FROM colors WHERE id = ?");
        $stmt->execute([$color_id]);
        $color = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$color) {
            $error_message = 'اللون غير موجود.';
            // Consider redirecting or handling this gracefully
        }

    } catch (PDOException $e) {
        $error_message = "خطأ في تحميل البيانات: " . $e->getMessage();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $color) {
    $name = trim($_POST['name']);
    $hex_code = trim($_POST['hex_code']);
    $display_order = $_POST['display_order'] ?? 0;

    // Basic validation for hex code
    if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $hex_code)) {
        $error_message = 'رمز اللون سداسي عشري غير صالح (مثال: #RRGGBB أو #RGB).';
    } elseif (empty($name)) {
        $error_message = 'اسم اللون مطلوب.';
    } else {
        try {
            $stmt = $db->prepare("UPDATE colors SET name = ?, hex_code = ?, display_order = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $hex_code, $display_order, $color_id]);
            $success_message = 'تم تحديث اللون بنجاح!';
            // Re-fetch color data to reflect changes
            $stmt = $db->prepare("SELECT * FROM colors WHERE id = ?");
            $stmt->execute([$color_id]);
            $color = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تحديث اللون: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">تعديل اللون: <?php echo htmlspecialchars($color['name'] ?? 'غير موجود'); ?></h1>
                <a href="index.php"
                    class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للألوان
                </a>
            </div>

            <div class="p-6">
                <?php if (isset($success_message) && $success_message): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4">
                        <i class="fas fa-check-circle ml-2"></i><?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($error_message) && $error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4">
                        <i class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$can_edit): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-times-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php elseif (!$color): ?>
                     <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-exclamation-circle text-4xl mb-4"></i>
                        <p>اللون المطلوب غير موجود.</p>
                    </div>
                <?php else: ?>
                    <form action="edit.php?id=<?php echo htmlspecialchars($color_id); ?>" method="POST" class="space-y-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">اسم اللون</label>
                            <input type="text" name="name" id="name" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                value="<?php echo htmlspecialchars($color['name'] ?? ''); ?>">
                        </div>

                        <div>
                            <label for="hex_code" class="block text-sm font-medium text-gray-700 mb-1">رمز اللون (Hex Code)</label>
                            <div class="flex items-center space-x-2 space-x-reverse">
                                <input type="color" name="hex_code" id="hex_code"
                                    class="p-1 h-10 w-14 block bg-white border border-gray-300 cursor-pointer rounded-lg disabled:opacity-50 disabled:pointer-events-none"
                                    value="<?php echo htmlspecialchars($color['hex_code'] ?? '#000000'); ?>">
                                <input type="text" name="hex_code_text" id="hex_code_text"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                    value="<?php echo htmlspecialchars($color['hex_code'] ?? '#000000'); ?>" placeholder="#RRGGBB" pattern="#[A-Fa-f0-9]{6}|#[A-Fa-f0-9]{3}" required>
                            </div>
                            <p class="mt-2 text-sm text-gray-500">اختر اللون من خلال منتقي الألوان أو أدخل الرمز السداسي عشري يدوياً.</p>
                        </div>

                        <div>
                            <label for="display_order" class="block text-sm font-medium text-gray-700 mb-1">مستوى الترتيب</label>
                            <input type="number" name="display_order" id="display_order"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                value="<?php echo htmlspecialchars($color['display_order'] ?? 0); ?>" min="0">
                            <p class="mt-2 text-sm text-gray-500">الألوان ذات الترتيب الأصغر تظهر أولاً.</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit"
                                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-200">
                                <i class="fas fa-save ml-2"></i> حفظ التعديلات
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const colorPicker = document.getElementById('hex_code');
        const hexCodeText = document.getElementById('hex_code_text');

        colorPicker.addEventListener('input', function() {
            hexCodeText.value = colorPicker.value;
        });

        hexCodeText.addEventListener('input', function() {
            const hexPattern = /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/;
            if (hexPattern.test(hexCodeText.value)) {
                colorPicker.value = hexCodeText.value;
            }
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>