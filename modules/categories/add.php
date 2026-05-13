<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_add = false;
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_add = hasPermission($_SESSION['user_id'], 'categories', 'add');
}

if (!$can_add) {
    $error_message = 'ليس لديك صلاحية لإضافة فئات.';
    // Display error and stop further execution or redirect
    // For now, we'll let it proceed to show the error in the page design
}

$page_title = 'إضافة فئة جديدة';
$error_message = '';
$success_message = '';

// Define upload directory and base URL
// Ensure 'uploads/categories/' directory exists at your project root and is writable
$upload_dir = __DIR__ . '/../../uploads/categories/';
$base_image_url = '/uploads/categories/'; // Web-accessible path

// Ensure upload directory exists
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true); // Create directory if it doesn't exist, with write permissions
}

// Fetch main categories for the parent_id dropdown
$main_categories = [];
try {
    $stmt = $db->query("SELECT id, name FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY name");
    $main_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "خطأ في تحميل الفئات الرئيسية: " . $e->getMessage();
}

// Initialize form values
$name = '';
$parent_id = null;
$display_order = 0;
$image_url = null; // This will hold the path to the uploaded image

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_add) {
    $name = trim($_POST['name']);
    $parent_id = $_POST['parent_id'] ?? null;
    $display_order = $_POST['display_order'] ?? 0;

    // --- Image Upload Logic ---
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_name = $_FILES['image_file']['tmp_name'];
        $file_name = $_FILES['image_file']['name'];
        $file_size = $_FILES['image_file']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        $max_file_size = 5 * 1024 * 1024; // 5 MB

        if ($file_size > $max_file_size) {
            $error_message = 'حجم الصورة كبير جداً. الحد الأقصى 5 ميجابايت.';
        } elseif (!in_array($file_ext, $allowed_types)) {
            $error_message = 'نوع الملف غير مسموح. الأنواع المسموح بها: JPG, JPEG, PNG, GIF.';
        } else {
            // Generate unique filename
            $unique_filename = uniqid('category_') . '.' . $file_ext;
            $destination_path = $upload_dir . $unique_filename;

            // Move file
            if (move_uploaded_file($file_tmp_name, $destination_path)) {
                $image_url = $base_image_url . $unique_filename; // Store web-accessible path
            } else {
                $error_message = 'فشل في رفع الصورة.';
            }
        }
    } elseif (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors
        $error_message = 'حدث خطأ في رفع الملف: ' . $_FILES['image_file']['error'];
    }
    // --- End Image Upload Logic ---


    if (empty($name)) {
        $error_message = 'اسم الفئة مطلوب.';
    } elseif (empty($error_message)) { // Only proceed if no image upload errors
        try {
            $stmt = $db->prepare("INSERT INTO categories (name, parent_id, display_order, image_url) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $name,
                $parent_id === '' ? null : $parent_id, // Convert empty string to null for parent_id
                $display_order,
                $image_url // Will be null if no file uploaded
            ]);
            $success_message = 'تم إضافة الفئة بنجاح!';
            // Clear form fields
            $name = '';
            $parent_id = null;
            $display_order = 0;
            $image_url = null;
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء إضافة الفئة: ' . $e->getMessage();
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
                <h1 class="text-2xl font-bold text-gray-900">إضافة فئة جديدة</h1>
                <a href="index.php"
                    class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للفئات
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

                <?php if (!$can_add): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-times-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php else: ?>
                    <!-- Add enctype for file uploads -->
                    <form action="add.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">اسم الفئة</label>
                            <input type="text" name="name" id="name" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                value="<?php echo htmlspecialchars($name); ?>">
                        </div>

                        <div>
                            <label for="parent_id" class="block text-sm font-medium text-gray-700 mb-1">نوع الفئة</label>
                            <select name="parent_id" id="parent_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">فئة رئيسية (لا يوجد أب)</option>
                                <?php foreach ($main_categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"
                                        <?php if (($parent_id ?? '') == $cat['id']) echo 'selected'; ?>>
                                        فرعية من: <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-2 text-sm text-gray-500">اختر فئة رئيسية لإنشاء فئة فرعية. اتركها فارغة لإنشاء فئة رئيسية.</p>
                        </div>

                        <div>
                            <label for="display_order" class="block text-sm font-medium text-gray-700 mb-1">مستوى الترتيب</label>
                            <input type="number" name="display_order" id="display_order"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                value="<?php echo htmlspecialchars($display_order); ?>" min="0">
                            <p class="mt-2 text-sm text-gray-500">الفئات ذات الترتيب الأصغر تظهر أولاً.</p>
                        </div>

                        <div>
                            <label for="image_file" class="block text-sm font-medium text-gray-700 mb-1">صورة الفئة (اختياري)</label>
                            <input type="file" name="image_file" id="image_file" accept="image/*"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <p class="mt-2 text-sm text-gray-500">يمكنك رفع صورة (JPG, JPEG, PNG, GIF) بحد أقصى 5 ميجابايت.</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit"
                                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-200">
                                <i class="fas fa-save ml-2"></i> حفظ الفئة
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>