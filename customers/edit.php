<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تعديل بيانات العميل';
$error_message = '';
$success_message = '';

// Check if customer ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$customer_id = intval($_GET['id']);

// Fetch offices for dropdown
try {
    $offices_stmt = $db->prepare("SELECT id, name FROM offices WHERE is_active = 1 ORDER BY name");
    $offices_stmt->execute();
    $offices = $offices_stmt->fetchAll();
} catch (PDOException $e) {
    $offices = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
    $alternative_number = trim($_POST['alternative_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $location_area = trim($_POST['location_area'] ?? '');
    $pickup_location = trim($_POST['pickup_location'] ?? '');
    $customer_type = $_POST['customer_type'] ?? 'individual';
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $office_id = intval($_POST['office_id'] ?? 0);
    $office_name = trim($_POST['office_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($name)) {
        $error_message = 'اسم العميل مطلوب';
    } else {
        try {
            // Update customer
            $stmt = $db->prepare("
                UPDATE customers SET 
                    name = ?, 
                    phone = ?, 
                    mobile_number = ?, 
                    whatsapp_number = ?, 
                    alternative_number = ?, 
                    email = ?, 
                    address = ?, 
                    location_area = ?, 
                    pickup_location = ?, 
                    customer_type = ?, 
                    credit_limit = ?, 
                    office_id = ?, 
                    office_name = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $name,
                $phone ?: null,
                $mobile_number ?: null,
                $whatsapp_number ?: null,
                $alternative_number ?: null,
                $email ?: null,
                $address ?: null,
                $location_area ?: null,
                $pickup_location ?: null,
                $customer_type,
                $credit_limit,
                $office_id ?: null,
                $office_name ?: null,
                $notes ?: null,
                $customer_id
            ]);
            
            $success_message = 'تم تحديث بيانات العميل بنجاح';
            
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تحديث بيانات العميل';
        }
    }
}

// Fetch customer data
try {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        header('Location: index.php');
        exit();
    }
    
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات العميل';
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">تعديل بيانات العميل</h1>
                        <p class="text-gray-600 mt-1">تعديل بيانات العميل: <?php echo htmlspecialchars($customer['name']); ?></p>
                    </div>
                    <div class="flex space-x-2 space-x-reverse">
                        <a href="view.php?id=<?php echo $customer_id; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-eye ml-2"></i>
                            عرض
                        </a>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للقائمة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <i class="fas fa-check-circle ml-2"></i>
                    <?php echo $success_message; ?>
                </div>
                <a href="view.php?id=<?php echo $customer_id; ?>" class="text-amber-800 hover:text-amber-900 font-medium">
                    عرض بيانات العميل
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">بيانات العميل</h2>
                <p class="text-sm text-gray-500 mt-1">رقم العميل: <?php echo htmlspecialchars($customer['customer_code']); ?></p>
            </div>
            
            <form method="POST" class="p-6 space-y-6">
                
                <!-- Customer Type -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-user-tag ml-2"></i>نوع العميل *
                        </label>
                        <select name="customer_type" required class="form-input">
                            <option value="individual" <?php echo $customer['customer_type'] == 'individual' ? 'selected' : ''; ?>>فرد</option>
                            <option value="company" <?php echo $customer['customer_type'] == 'company' ? 'selected' : ''; ?>>شركة</option>
                            <option value="delegate" <?php echo $customer['customer_type'] == 'delegate' ? 'selected' : ''; ?>>مندوب</option>
                        </select>
                    </div>
                </div>

                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user ml-2"></i>اسم العميل *
                    </label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        required 
                        value="<?php echo htmlspecialchars($customer['name']); ?>"
                        class="form-input"
                        placeholder="أدخل اسم العميل"
                    >
                </div>

                <!-- Contact Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone ml-2"></i>رقم الهاتف
                        </label>
                        <input 
                            type="tel" 
                            id="phone" 
                            name="phone" 
                            value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                            class="form-input"
                            placeholder="05xxxxxxxx"
                        >
                    </div>
                    
                    <div>
                        <label for="mobile_number" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-mobile-alt ml-2"></i>رقم الجوال
                        </label>
                        <input 
                            type="tel" 
                            id="mobile_number" 
                            name="mobile_number" 
                            value="<?php echo htmlspecialchars($customer['mobile_number'] ?? ''); ?>"
                            class="form-input"
                            placeholder="05xxxxxxxx"
                        >
                        <p class="text-xs text-gray-500 mt-1">يتم إرسال الإشعارات إلى هذا الرقم</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="whatsapp_number" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fab fa-whatsapp ml-2"></i>رقم جوال الواتساب
                        </label>
                        <input 
                            type="tel" 
                            id="whatsapp_number" 
                            name="whatsapp_number" 
                            value="<?php echo htmlspecialchars($customer['whatsapp_number'] ?? ''); ?>"
                            class="form-input"
                            placeholder="05xxxxxxxx"
                        >
                        <p class="text-xs text-gray-500 mt-1">يتم إرسال الإشعارات عبر الواتساب</p>
                    </div>
                    
                    <div>
                        <label for="alternative_number" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-phone-alt ml-2"></i>رقم هاتف آخر
                        </label>
                        <input 
                            type="tel" 
                            id="alternative_number" 
                            name="alternative_number" 
                            value="<?php echo htmlspecialchars($customer['alternative_number'] ?? ''); ?>"
                            class="form-input"
                            placeholder="05xxxxxxxx"
                        >
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope ml-2"></i>البريد الإلكتروني
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                            class="form-input"
                            placeholder="example@domain.com"
                        >
                    </div>
                </div>

                <!-- Location Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="location_area" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-map-marker-alt ml-2"></i>تحديد موقع القريبة
                        </label>
                        <input 
                            type="text" 
                            id="location_area" 
                            name="location_area" 
                            value="<?php echo htmlspecialchars($customer['location_area'] ?? ''); ?>"
                            class="form-input"
                            placeholder="مثلاً: الرياض - حي النزهة"
                        >
                    </div>
                    
                    <div>
                        <label for="pickup_location" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-map-pin ml-2"></i>موقع استلام الطلب
                        </label>
                        <input 
                            type="text" 
                            id="pickup_location" 
                            name="pickup_location" 
                            value="<?php echo htmlspecialchars($customer['pickup_location'] ?? ''); ?>"
                            class="form-input"
                            placeholder="مثلاً: أمام محطة البنزين"
                        >
                    </div>
                </div>
                
                <!-- Address -->
                <div>
                    <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-map-marker-alt ml-2"></i>العنوان
                    </label>
                    <textarea 
                        id="address" 
                        name="address" 
                        rows="3"
                        class="form-input"
                        placeholder="أدخل عنوان العميل"
                    ><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                </div>

                <!-- Office Selection -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="office_id" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-building ml-2"></i>المكتب
                        </label>
                        <select name="office_id" id="office_id" class="form-input">
                            <option value="">-- اختر المكتب --</option>
                            <?php foreach ($offices as $office): ?>
                            <option value="<?php echo $office['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($office['name']); ?>"
                                    <?php echo ($customer['office_id'] ?? '') == $office['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($office['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="office_name" id="office_name" value="<?php echo htmlspecialchars($customer['office_name'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Credit Limit -->
                <div>
                    <label for="credit_limit" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-credit-card ml-2"></i>حد الائتمان (ريال سعودي)
                    </label>
                    <input 
                        type="number" 
                        id="credit_limit" 
                        name="credit_limit" 
                        min="0" 
                        step="0.01"
                        value="<?php echo htmlspecialchars($customer['credit_limit'] ?? '0'); ?>"
                        class="form-input"
                        placeholder="0.00"
                    >
                    <p class="text-sm text-gray-500 mt-1">الحد الأقصى للمبلغ المسموح به للعميل</p>
                </div>
                
                <!-- Notes -->
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-sticky-note ml-2"></i>ملاحظات
                    </label>
                    <textarea 
                        id="notes" 
                        name="notes" 
                        rows="3"
                        class="form-input"
                        placeholder="أدخل أي ملاحظات إضافية"
                    ><?php echo htmlspecialchars($customer['notes'] ?? ''); ?></textarea>
                </div>

                <!-- Submit Buttons -->
                <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t border-gray-200">
                    <button 
                        type="submit" 
                        class="flex-1 sm:flex-none inline-flex justify-center items-center px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-200"
                    >
                        <i class="fas fa-save ml-2"></i>
                        حفظ التغييرات
                    </button>
                    
                    <a 
                        href="view.php?id=<?php echo $customer_id; ?>" 
                        class="flex-1 sm:flex-none inline-flex justify-center items-center px-6 py-3 bg-gray-600 text-white font-medium rounded-lg hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition duration-200"
                    >
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Handle office selection
    document.getElementById('office_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const officeName = selectedOption.getAttribute('data-name') || '';
        document.getElementById('office_name').value = officeName;
    });
    
    // Initialize office name on page load
    window.addEventListener('DOMContentLoaded', function() {
        const officeSelect = document.getElementById('office_id');
        const selectedOption = officeSelect.options[officeSelect.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const officeName = selectedOption.getAttribute('data-name') || '';
            document.getElementById('office_name').value = officeName;
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>
