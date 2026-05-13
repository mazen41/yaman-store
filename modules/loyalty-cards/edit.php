<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تعديل بيانات البطاقة';
$error_message = '';
$success_message = '';
$card_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$card_id) {
    header('Location: index.php');
    exit();
}

// Handle form submission for updating the card
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and validate form data
    $card_type = trim($_POST['card_type'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $card_password = $_POST['card_password'] ?? '';

    // Basic validation
    if (empty($card_type) || empty($status)) {
        $error_message = 'يرجى ملء الحقول الإلزامية (نوع البطاقة والحالة).';
    } else {
        try {
            // Build the query
            $sql = "UPDATE loyalty_cards SET 
                        card_type = ?, 
                        customer_name = ?, 
                        customer_phone = ?, 
                        customer_email = ?, 
                        status = ?, 
                        expiry_date = ?, 
                        notes = ?
                    WHERE id = ?";
            
            $params = [$card_type, $customer_name, $customer_phone, $customer_email, $status, $expiry_date, $notes, $card_id];

            // Only update password if a new one is provided
            if (!empty($card_password)) {
                $hashed_password = password_hash($card_password, PASSWORD_DEFAULT);
                $sql = "UPDATE loyalty_cards SET 
                            card_type = ?, customer_name = ?, customer_phone = ?, customer_email = ?, 
                            status = ?, expiry_date = ?, notes = ?, card_password = ?
                        WHERE id = ?";
                 $params = [$card_type, $customer_name, $customer_phone, $customer_email, $status, $expiry_date, $notes, $hashed_password, $card_id];
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $_SESSION['success_message'] = 'تم تحديث بيانات البطاقة بنجاح.';
            header('Location: index.php');
            exit();

        } catch (PDOException $e) {
            $error_message = 'فشل تحديث البطاقة: ' . $e->getMessage();
        }
    }
}

// Fetch current card data to populate the form
try {
    $stmt = $db->prepare("SELECT * FROM loyalty_cards WHERE id = ? AND is_active = 1");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        $_SESSION['error_message'] = 'البطاقة المطلوبة غير موجودة.';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'خطأ في جلب بيانات البطاقة.';
    header('Location: index.php');
    exit();
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 to-teal-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            <i class="fas fa-edit mr-3"></i>
                            <?php echo $page_title; ?>
                        </h1>
                        <p class="text-amber-100">بطاقة رقم: <?php echo htmlspecialchars($card['card_number']); ?></p>
                    </div>
                    <a href="index.php" class="bg-white text-amber-600 px-6 py-3 rounded-lg font-bold hover:bg-amber-50 transition-all duration-300 shadow-lg">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للقائمة
                    </a>
                </div>
            </div>
        </div>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
            <p class="font-medium"><?php echo $error_message; ?></p>
        </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <form method="POST" action="edit.php?id=<?php echo $card_id; ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Card Information -->
                    <div class="md:col-span-2">
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">معلومات البطاقة</h3>
                    </div>
                    
                    <div class="form-group">
                        <label for="card_number" class="block text-sm font-medium text-gray-700 mb-2">رقم البطاقة</label>
                        <input type="text" id="card_number" value="<?php echo htmlspecialchars($card['card_number']); ?>" class="w-full px-4 py-2 border-2 bg-gray-100 border-gray-300 rounded-lg" readonly disabled>
                    </div>

                    <div class="form-group">
                        <label for="card_type" class="block text-sm font-medium text-gray-700 mb-2">نوع البطاقة <span class="text-red-500">*</span></label>
                        <select name="card_type" id="card_type" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                            <option value="gift" <?php echo ($card['card_type'] == 'gift') ? 'selected' : ''; ?>>بطاقة هدية</option>
                            <option value="loyalty" <?php echo ($card['card_type'] == 'loyalty') ? 'selected' : ''; ?>>بطاقة ولاء</option>
                            <option value="promotional" <?php echo ($card['card_type'] == 'promotional') ? 'selected' : ''; ?>>بطاقة ترويجية</option>
                        </select>
                    </div>

                     <div class="form-group">
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">الحالة <span class="text-red-500">*</span></label>
                        <select name="status" id="status" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                            <option value="active" <?php echo ($card['status'] == 'active') ? 'selected' : ''; ?>>نشطة</option>
                            <option value="inactive" <?php echo ($card['status'] == 'inactive') ? 'selected' : ''; ?>>غير نشطة</option>
                            <option value="expired" <?php echo ($card['status'] == 'expired') ? 'selected' : ''; ?>>منتهية الصلاحية</option>
                            <option value="blocked" <?php echo ($card['status'] == 'blocked') ? 'selected' : ''; ?>>محظورة</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-2">تاريخ انتهاء الصلاحية</label>
                        <input type="date" name="expiry_date" id="expiry_date" value="<?php echo htmlspecialchars($card['expiry_date']); ?>" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>

                    <!-- Customer Information -->
                    <div class="md:col-span-2">
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 my-4">معلومات العميل</h3>
                    </div>

                    <div class="form-group">
                        <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-2">اسم العميل</label>
                        <input type="text" name="customer_name" id="customer_name" value="<?php echo htmlspecialchars($card['customer_name']); ?>" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_phone" class="block text-sm font-medium text-gray-700 mb-2">رقم هاتف العميل</label>
                        <input type="tel" name="customer_phone" id="customer_phone" value="<?php echo htmlspecialchars($card['customer_phone']); ?>" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>

                     <div class="form-group md:col-span-2">
                        <label for="customer_email" class="block text-sm font-medium text-gray-700 mb-2">البريد الإلكتروني للعميل</label>
                        <input type="email" name="customer_email" id="customer_email" value="<?php echo htmlspecialchars($card['customer_email']); ?>" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>

                    <!-- Security & Notes -->
                    <div class="md:col-span-2">
                        <h3 class="text-lg font-bold text-gray-800 border-b pb-2 my-4">الأمان والملاحظات</h3>
                    </div>

                    <div class="form-group">
                        <label for="card_password" class="block text-sm font-medium text-gray-700 mb-2">تغيير كلمة المرور</label>
                        <input type="password" name="card_password" id="card_password" placeholder="اتركه فارغاً لعدم التغيير" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500">
                    </div>

                    <div class="form-group md:col-span-2">
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">ملاحظات</label>
                        <textarea name="notes" id="notes" rows="4" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500"><?php echo htmlspecialchars($card['notes']); ?></textarea>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-8 pt-6 border-t border-gray-200 text-left">
                    <button type="submit" class="bg-teal-600 text-white px-8 py-3 rounded-lg font-bold text-lg hover:bg-teal-700 transition-all duration-300 shadow-lg">
                        <i class="fas fa-save ml-2"></i>
                        حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
