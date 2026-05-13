<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'customer_types', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للإضافة';
    header('Location: index.php');
    exit();
}

$page_title = 'إضافة أنواع العملاء';
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add your form processing logic here
    $success_message = 'تم الإضافة بنجاح';
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">إضافة أنواع العملاء</h1>
        
        <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-6">
            <!-- Add your form fields here -->
            
            <?php if (): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">العملة</label>
                <select name="currency" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    <option value="YER">ريال يمني (YER)</option>
                    <option value="SAR">ريال سعودي (SAR)</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="flex gap-4">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-semibold">
                    <i class="fas fa-save ml-2"></i>
                    حفظ
                </button>
                <a href="index.php" class="flex-1 text-center bg-gray-200 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-300 font-semibold">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>