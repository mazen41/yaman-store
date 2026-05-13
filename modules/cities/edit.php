<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'cities', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للتعديل';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل المدن';
$id = intval($_GET['id'] ?? 0);
$error_message = '';

// Fetch item
try {
    $stmt = $db->prepare("SELECT * FROM cities WHERE id = ?");
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

// Update Logic
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'] ?? '';
    $shipping_cost = $_POST['shipping_cost'] ?? 0;
    $currency = $_POST['currency'] ?? 'YER';

    if (empty($name)) {
        $error_message = 'يرجى إدخال اسم المدينة';
    } else {
        try {
            $stmt = $db->prepare("UPDATE cities SET name = ?, shipping_cost = ?, currency = ? WHERE id = ?");
            $stmt->execute([$name, $shipping_cost, $currency, $id]);
            
            $_SESSION['success_message'] = 'تم التعديل بنجاح';
            header('Location: index.php');
            exit();
        } catch (PDOException $e) {
            $error_message = 'خطأ أثناء التحديث: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">تعديل المدينة: <?php echo htmlspecialchars($item['name']); ?></h1>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- City Name -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">اسم المدينة</label>
                    <input type="text" name="name" required 
                           value="<?php echo htmlspecialchars($item['name']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <!-- Shipping Cost -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">تكلفة الشحن</label>
                    <input type="number" step="0.01" name="shipping_cost" required 
                           value="<?php echo htmlspecialchars($item['shipping_cost'] ?? 0); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <!-- Currency -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">العملة</label>
                    <select name="currency" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="YER" <?php echo ($item['currency'] == 'YER') ? 'selected' : ''; ?>>ريال يمني (YER)</option>
                        <option value="SAR" <?php echo ($item['currency'] == 'SAR') ? 'selected' : ''; ?>>ريال سعودي (SAR)</option>
                        <option value="USD" <?php echo ($item['currency'] == 'USD') ? 'selected' : ''; ?>>دولار أمريكي (USD)</option>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-4 pt-4">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-semibold transition duration-200">
                    <i class="fas fa-save ml-2"></i>
                    حفظ التعديلات
                </button>
                <a href="index.php" class="flex-1 text-center bg-gray-200 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-300 font-semibold transition duration-200">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>