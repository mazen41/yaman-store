<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'cities', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للإضافة';
    header('Location: index.php');
    exit();
}

$page_title = 'إضافة المدن';
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $city_name = $_POST['city_name'] ?? '';
    $shipping_cost = $_POST['shipping_cost'] ?? 0;
    $currency = $_POST['currency'] ?? 'YER';

    if (empty($city_name)) {
        $error_message = 'يرجى إدخال اسم المدينة';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO cities (name, shipping_cost, currency) VALUES (?, ?, ?)");
            $stmt->execute([$city_name, $shipping_cost, $currency]);
            $success_message = 'تم إضافة المدينة بنجاح';
        } catch (PDOException $e) {
            $error_message = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-900">إضافة مدينة جديدة</h1>
        </div>
        
        <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-exclamation-triangle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- City Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">اسم المدينة</label>
                    <input type="text" name="city_name" required 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
                           placeholder="مثال: صنعاء">
                </div>

                <!-- Shipping Cost -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">تكلفة الشحن</label>
                    <input type="number" step="0.01" name="shipping_cost" required 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none"
                           placeholder="0.00">
                </div>

                <!-- Currency -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">العملة</label>
                    <select name="currency" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="YER">ريال يمني (YER)</option>
                        <option value="SAR">ريال سعودي (SAR)</option>
                        <option value="USD">دولار أمريكي (USD)</option>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-4 pt-4">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-semibold transition duration-200">
                    <i class="fas fa-save ml-2"></i>
                    حفظ البيانات
                </button>
                <a href="index.php" class="flex-1 text-center bg-gray-100 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-200 font-semibold transition duration-200">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>