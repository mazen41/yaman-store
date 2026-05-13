<?php
session_start();

// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// 2. Include necessary files
require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// 3. Permission Check
$can_edit = hasPermission($_SESSION['user_id'], 'attribute_values', 'edit');
if (!$can_edit) {
    // Optional: Set a session message and redirect
    $_SESSION['error_message'] = 'ليس لديك الصلاحية لتعديل قيم السمات.';
    // Redirect to a more appropriate page, like the main dashboard or the attributes list
    header('Location: ../attributes/index.php');
    exit();
}

// 4. Initialize variables
$page_title = 'تعديل قيمة السمة';
$error_message = '';
$success_message = '';

// Get IDs from URL. Both are required.
$value_id = $_GET['id'] ?? null;
$attribute_id = $_GET['attribute_id'] ?? null;

if (!$value_id || !$attribute_id) {
    header('Location: ../attributes/index.php'); // Redirect if IDs are missing
    exit();
}


// 5. Handle Form Submission (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and retrieve form data
    $value = trim($_POST['value'] ?? '');
    $display_order = filter_input(INPUT_POST, 'display_order', FILTER_VALIDATE_INT, ["options" => ["default" => 0]]);
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;

    // Validate form data
    if (empty($value)) {
        $error_message = 'حقل "القيمة" مطلوب.';
    }

    if (empty($error_message)) {
        try {
            // Prepare the SQL UPDATE statement
            $stmt = $db->prepare("
                UPDATE attribute_values 
                SET value = ?, display_order = ?, is_active = ?, updated_at = NOW() 
                WHERE id = ?
            ");

            // Execute the update
            $stmt->execute([$value, $display_order, $is_active, $value_id]);

            // Set success message and redirect back to the attribute's view page
            $_SESSION['success_message'] = 'تم تحديث قيمة السمة بنجاح!';
            header('Location: ../attributes/view.php?id=' . $attribute_id);
            exit();

        } catch (PDOException $e) {
            $error_message = "حدث خطأ أثناء تحديث البيانات: " . $e->getMessage();
        }
    }
}


// 6. Fetch Existing Data (for GET Request)
try {
    $stmt = $db->prepare("SELECT * FROM attribute_values WHERE id = ?");
    $stmt->execute([$value_id]);
    $attribute_value = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attribute_value) {
        $error_message = 'قيمة السمة المطلوبة غير موجودة.';
    }
} catch (PDOException $e) {
    $error_message = "حدث خطأ أثناء جلب البيانات: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-md mx-auto px-4 sm:px-6 lg:px-8">

        <div class="bg-white shadow rounded-lg">
            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                <a href="../attributes/view.php?id=<?php echo htmlspecialchars($attribute_id); ?>"
                    class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                    <i class="fas fa-arrow-right ml-2"></i> إلغاء والعودة
                </a>
            </div>

            <!-- Form Body -->
            <div class="p-6">
                <?php if ($error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($attribute_value && !$error_message): ?>
                    <form method="POST" action="edit.php?id=<?php echo htmlspecialchars($value_id); ?>&attribute_id=<?php echo htmlspecialchars($attribute_id); ?>" class="space-y-6">

                        <!-- Value -->
                        <div>
                            <label for="value" class="block text-sm font-medium text-gray-700">القيمة</label>
                            <input type="text" name="value" id="value"
                                value="<?php echo htmlspecialchars($attribute_value['value'] ?? ''); ?>" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>

                        <!-- Display Order -->
                        <div>
                            <label for="display_order" class="block text-sm font-medium text-gray-700">ترتيب العرض</label>
                            <input type="number" name="display_order" id="display_order"
                                value="<?php echo htmlspecialchars($attribute_value['display_order'] ?? '0'); ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        
                        <!-- Status -->
                        <div>
                            <label for="is_active" class="block text-sm font-medium text-gray-700">الحالة</p>
                            <select name="is_active" id="is_active" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                <option value="1" <?php echo ($attribute_value['is_active'] == 1) ? 'selected' : ''; ?>>نشط</option>
                                <option value="0" <?php echo ($attribute_value['is_active'] == 0) ? 'selected' : ''; ?>>معطل</option>
                            </select>
                        </div>

                        <!-- Form Actions -->
                        <div class="pt-4 border-t border-gray-200 flex justify-end">
                             <button type="submit" class="inline-flex items-center justify-center px-6 py-2 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-save ml-2"></i> حفظ التغييرات
                            </button>
                        </div>

                    </form>
                <?php else: ?>
                    <div class="text-center py-10">
                         <i class="fas fa-exclamation-circle text-4xl text-gray-400 mb-4"></i>
                         <p class="text-gray-600">لا يمكن تحميل بيانات قيمة السمة المطلوبة.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>