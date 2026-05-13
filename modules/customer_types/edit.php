<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'customer_types', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للتعديل';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل أنواع العملاء';
$id = intval($_GET['id'] ?? 0);

// Fetch item
try {
    $stmt = $db->prepare("SELECT * FROM customer_types WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        $_SESSION['error_message'] = 'العنصر غير موجود';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'حدث خطأ: ' . $e->getMessage();
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add your update logic here
    $_SESSION['success_message'] = 'تم التعديل بنجاح';
    header('Location: index.php');
    exit();
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">تعديل أنواع العملاء</h1>
        
        <form method="POST" class="space-y-6">
            <!-- Add your form fields here -->
            
            <?php if (): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">العملة</label>
                <select name="currency" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    <option value="YER" <?php echo ($item['currency'] ?? '') == 'YER' ? 'selected' : ''; ?>>ريال يمني (YER)</option>
                    <option value="SAR" <?php echo ($item['currency'] ?? '') == 'SAR' ? 'selected' : ''; ?>>ريال سعودي (SAR)</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="flex gap-4">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-semibold">
                    <i class="fas fa-save ml-2"></i>
                    حفظ التعديلات
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