<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/auto_generate_helpers.php';

$page_title = 'إضافة عميل جديد';
$error_message = '';
$success_message = '';

// Fetch offices for dropdown
try {
    $offices_stmt = $db->prepare("SELECT id, name FROM offices WHERE is_active = 1 ORDER BY name");
    $offices_stmt->execute();
    $offices = $offices_stmt->fetchAll();
} catch (PDOException $e) {
    $offices = [];
}

// Fetch cities for dropdown
try {
    $cities_stmt = $db->prepare("SELECT id, name FROM cities WHERE is_active = 1 ORDER BY name");
    $cities_stmt->execute();
    $cities = $cities_stmt->fetchAll();
} catch (PDOException $e) {
    // If cities table doesn't exist yet, create a default list (Yemen cities)
    $cities = [
        ['id' => 1, 'name' => 'صنعاء'],
        ['id' => 2, 'name' => 'عدن'],
        ['id' => 3, 'name' => 'تعز'],
        ['id' => 4, 'name' => 'الحديدة'],
        ['id' => 5, 'name' => 'إب'],
        ['id' => 6, 'name' => 'ذمار'],
        ['id' => 7, 'name' => 'المكلا'],
        ['id' => 8, 'name' => 'حضرموت'],
        ['id' => 9, 'name' => 'صعدة'],
        ['id' => 10, 'name' => 'عمران'],
        ['id' => 11, 'name' => 'مأرب'],
        ['id' => 12, 'name' => 'لحج'],
        ['id' => 13, 'name' => 'أبين'],
        ['id' => 14, 'name' => 'شبوة'],
        ['id' => 15, 'name' => 'المهرة'],
        ['id' => 16, 'name' => 'حجة'],
        ['id' => 17, 'name' => 'الجوف'],
        ['id' => 18, 'name' => 'البيضاء'],
        ['id' => 19, 'name' => 'ريمة'],
        ['id' => 20, 'name' => 'الضالع']
    ];
}

// Auto-generate customer code for new customers
$auto_customer_code = '';
if ($_SERVER['REQUEST_METHOD'] != 'POST' || !empty($success_message)) {
    // Generate new customer code on page load or after successful submission
    $auto_customer_code = generateCustomerCode($db);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Auto-generate customer code if not provided or empty
    $customer_code = strtoupper(trim($_POST['customer_code'] ?? ''));
    if (empty($customer_code)) {
        $customer_code = generateCustomerCode($db);
    }
    
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
    $city_id = intval($_POST['city_id'] ?? 0);
    $city_name = trim($_POST['city_name'] ?? '');
    $pickup_options = $_POST['pickup_options'] ?? [];
    $pickup_notes = trim($_POST['pickup_notes'] ?? '');
    
    // Server-side validation and sanitization
    $errors = [];

    // Sanitize numbers to digits only
    $phone = preg_replace('/\D+/', '', $phone);
    $mobile_number = preg_replace('/\D+/', '', $mobile_number);
    $whatsapp_number = preg_replace('/\D+/', '', $whatsapp_number);
    $alternative_number = preg_replace('/\D+/', '', $alternative_number);

    // Validate customer code format
    if (!preg_match('/^[A-Z]{4}[0-9]{3,}$/', $customer_code)) {
        $errors[] = 'رقم العميل يجب أن يكون بالتنسيق الصحيح';
    } else {
        // Check if customer code already exists
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
        $check_stmt->execute([$customer_code]);
        if ($check_stmt->fetchColumn() > 0) {
            // If duplicate, generate a new unique code
            $customer_code = generateCustomerCode($db);
        }
    }
    
    if (empty($name)) {
        $errors[] = 'اسم العميل مطلوب';
    }
    if (empty($city_id)) {
        $errors[] = 'يرجى اختيار مدينة الشحن';
    }
    // If WhatsApp empty, default to mobile
    if (!$whatsapp_number && $mobile_number) {
        $whatsapp_number = $mobile_number;
    }
    // Validate lengths when provided (allow 8-12 digits to support 05xxxxxxxx or 9665xxxxxxx)
    $numOk = function($n){ return ($n === '' || (strlen($n) >= 8 && strlen($n) <= 12)); };
    if (!$numOk($mobile_number)) { $errors[] = 'رقم الجوال غير صحيح (8-12 رقم)'; }
    if (!$numOk($whatsapp_number)) { $errors[] = 'رقم واتساب غير صحيح (8-12 رقم)'; }
    if (!$numOk($alternative_number)) { $errors[] = 'رقم الهاتف الآخر غير صحيح (8-12 رقم)'; }

    if (!empty($errors)) {
        // Show all errors and stop before insert
        $error_message = implode(' • ', $errors);
    } else {
        try {
            // Use the manually entered customer code
            // No need to generate - user provides it
            
            // Insert customer
            $stmt = $db->prepare("
                INSERT INTO customers (customer_code, name, phone, mobile_number, whatsapp_number, alternative_number, 
                                      email, address, location_area, pickup_location, customer_type, credit_limit, 
                                      office_id, office_name, city_id, city_name, pickup_options, pickup_notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Convert pickup_options array to JSON string if it's an array
            $pickup_options_json = is_array($pickup_options) ? json_encode($pickup_options) : null;
            
            $stmt->execute([
                $customer_code,
                $name,
                ($phone !== '' ? $phone : null),
                ($mobile_number !== '' ? $mobile_number : null),
                ($whatsapp_number !== '' ? $whatsapp_number : null),
                ($alternative_number !== '' ? $alternative_number : null),
                $email ?: null,
                $address ?: null,
                $location_area ?: null,
                $pickup_location ?: null,
                $customer_type,
                $credit_limit,
                $office_id ?: null,
                $office_name ?: null,
                $city_id ?: null,
                $city_name ?: null,
                $pickup_options_json,
                $pickup_notes ?: null
            ]);
            
            $success_message = 'تم إضافة العميل بنجاح';
            
            // Clear form data
            $_POST = [];
            
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error_message = 'البيانات مكررة، يرجى المحاولة مرة أخرى';
            } else {
                $error_message = 'حدث خطأ أثناء إضافة العميل';
            }
        }
    }
}

include '../../includes/header.php';
?>

<style>
    /* Modern Professional Styling */
    * {
        font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .dir-ltr { 
        direction: ltr; 
        text-align: left; 
    }
    
    /* Clean Card Design */
    .form-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 24px;
    }
    
    .form-card-header {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
        padding: 20px 24px;
        border-bottom: 4px solid #1e40af;
    }
    
    .form-card-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .form-card-body {
        padding: 32px 24px;
    }
    
    /* Section Headers */
    .section-divider {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 32px 0 24px 0;
        padding-bottom: 12px;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .section-divider h3 {
        font-size: 1.125rem;
        font-weight: 700;
        color: #1f2937;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .section-divider i {
        color: #3b82f6;
        font-size: 1.5rem;
    }
    
    .section-number {
        background: #3b82f6;
        color: white;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
    }
    
    /* Form Groups */
    .form-group {
        margin-bottom: 24px;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
        font-size: 0.95rem;
    }
    
    .form-group label .required {
        color: #ef4444;
        margin-right: 4px;
    }
    
    .form-group label i {
        color: #6b7280;
        margin-left: 6px;
        font-size: 1rem;
    }
    
    /* Modern Inputs */
    .form-control {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 1rem;
        transition: all 0.2s ease;
        background: #ffffff;
    }
    
    .form-control:hover {
        border-color: #cbd5e1;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-control::placeholder {
        color: #9ca3af;
    }
    
    select.form-control {
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: left 12px center;
        background-size: 20px;
        padding-left: 40px;
    }
    
    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }
    
    /* Input Variants */
    .form-control-primary {
        border-color: #3b82f6;
        background: #eff6ff;
    }
    
    .form-control-primary:focus {
        border-color: #2563eb;
        background: white;
    }
    
    /* Helper Text */
    .form-help {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        margin-top: 6px;
        font-size: 0.875rem;
        color: #6b7280;
        line-height: 1.5;
    }
    
    .form-help i {
        margin-top: 2px;
        font-size: 0.875rem;
    }
    
    .form-help-primary {
        color: #3b82f6;
    }
    
    .form-help-success {
        color: #C7A46D;
    }
    
    .form-help-warning {
        color: #f59e0b;
    }
    
    /* Info Boxes */
    .info-box {
        background: #f0f9ff;
        border: 2px solid #bfdbfe;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
    }
    
    .info-box-header {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: #1e40af;
        margin-bottom: 8px;
    }
    
    .info-box-content {
        color: #1e3a8a;
        font-size: 0.95rem;
        line-height: 1.6;
    }
    
    /* Checkbox Groups */
    .checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    
    .checkbox-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .checkbox-item:hover {
        border-color: #3b82f6;
        background: #f9fafb;
    }
    
    .checkbox-item input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: #3b82f6;
    }
    
    .checkbox-item label {
        cursor: pointer;
        margin: 0;
        font-weight: 500;
        color: #374151;
    }
    
    /* Grid Layouts */
    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    
    .grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }
    
    .grid-4 {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }
    
    @media (max-width: 768px) {
        .grid-2, .grid-3, .grid-4 {
            grid-template-columns: 1fr;
        }
    }
    
    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }
    
    .btn-secondary {
        background: #6b7280;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #4b5563;
    }
    
    .btn-outline {
        background: white;
        color: #374151;
        border: 2px solid #e5e7eb;
    }
    
    .btn-outline:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }
    
    .btn-group {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        padding-top: 24px;
        border-top: 2px solid #e5e7eb;
        margin-top: 32px;
    }
    
    /* Alerts */
    .alert {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 1rem;
    }
    
    .alert i {
        font-size: 1.25rem;
    }
    
    .alert-success {
        background: #d1fae5;
        border: 2px solid #6ee7b7;
        color: #065f46;
    }
    
    .alert-error {
        background: #fee2e2;
        border: 2px solid #fca5a5;
        color: #991b1b;
    }
    
    /* Page Header */
    .page-header {
        background: white;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        padding: 24px;
        margin-bottom: 24px;
    }
    
    .page-header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .page-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: #111827;
        margin: 0 0 4px 0;
    }
    
    .page-subtitle {
        color: #6b7280;
        font-size: 1rem;
        margin: 0;
    }
    
    /* Refresh Button Hover Effect */
    .btn-refresh-code:hover {
        background: #2563eb !important;
        transform: translateY(-50%) scale(1.1) !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
    }
    
    .btn-refresh-code:active {
        transform: translateY(-50%) scale(0.95) !important;
    }
    
    .btn-refresh-code i {
        transition: transform 0.3s ease;
    }
    
    .btn-refresh-code:hover i {
        transform: rotate(180deg);
    }
</style>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <div>
                    <h1 class="page-title">إضافة عميل جديد</h1>
                    <p class="page-subtitle">أضف بيانات عميل جديد إلى النظام</p>
                </div>
                <a href="index.php" class="btn btn-outline">
                    <i class="fas fa-arrow-right"></i>
                    العودة للقائمة
                </a>
            </div>
        </div>

        <!-- Success Alert -->
        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div class="flex-1"><?php echo $success_message; ?></div>
            <a href="index.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 0.9rem;">
                عرض القائمة
            </a>
        </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php echo $error_message; ?></div>
        </div>
        <?php endif; ?>

        <!-- Main Form Card -->
        <div class="form-card">
            <div class="form-card-header">
                <h2><i class="fas fa-user-plus"></i> بيانات العميل</h2>
            </div>
            
            <form method="POST" class="form-card-body">
                
                <!-- Section 1: Basic Information -->
                <div class="section-divider">
                    <div class="section-number">1</div>
                    <h3><i class="fas fa-user-circle"></i> المعلومات الأساسية</h3>
                </div>
                
                <div class="info-box">
                    <div class="info-box-header">
                        <i class="fas fa-info-circle"></i>
                        <span>ملاحظة</span>
                    </div>
                    <div class="info-box-content">
                        يرجى تعبئة البيانات الأساسية للعميل. الحقول المميزة بـ <span class="required">*</span> إلزامية.
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>
                            <i class="fas fa-hashtag"></i>
                            رقم العميل (تلقائي)
                        </label>
                        <input 
                            type="text" 
                            id="customer_code" 
                            name="customer_code" 
                            readonly
                            value="<?php echo htmlspecialchars($auto_customer_code); ?>"
                            class="form-control form-control-primary"
                            style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); cursor: not-allowed; font-weight: 700; font-size: 1.15rem; letter-spacing: 2px; text-align: center;"
                        >
                        <div class="form-help form-help-success">
                            <i class="fas fa-check-circle"></i>
                            <span>تم إنشاء الرقم تلقائياً</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <i class="fas fa-list"></i>
                            الفئة
                            <span class="required">*</span>
                        </label>
                        <select name="customer_type" required class="form-control">
                            <option value="individual" <?php echo ($_POST['customer_type'] ?? '') == 'individual' ? 'selected' : ''; ?>>فرد</option>
                            <option value="company" <?php echo ($_POST['customer_type'] ?? '') == 'company' ? 'selected' : ''; ?>>شركة</option>
                            <option value="delegate" <?php echo ($_POST['customer_type'] ?? '') == 'delegate' ? 'selected' : ''; ?>>مندوب</option>
                        </select>
                        <div class="form-help">
                            <i class="fas fa-info-circle"></i>
                            <span>اختر نوع العميل من القائمة</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name">
                        <i class="fas fa-user"></i>
                        الاسم الكامل للعميل
                        <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        required 
                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                        class="form-control"
                        placeholder="أدخل الاسم الكامل للعميل (الاسم الأول والعائلة)"
                    >
                </div>

                <!-- Section 2: Contact Information -->
                <div class="section-divider">
                    <div class="section-number">2</div>
                    <h3><i class="fas fa-phone"></i> معلومات الاتصال</h3>
                </div>
                
                <div class="grid-3">
                    <div class="form-group">
                        <label for="mobile_number">
                            <i class="fas fa-mobile-alt"></i>
                            رقم جوال
                        </label>
                        <input 
                            type="tel" 
                            id="mobile_number" 
                            name="mobile_number" 
                            value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>"
                            class="form-control dir-ltr"
                            placeholder="05xxxxxxxx"
                        >
                        <div class="form-help form-help-success">
                            <i class="fas fa-check-circle"></i>
                            <span>عند تعبئة الطلب يتم ارسال الاشعارات اليه عبر الواتساب</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="whatsapp_number">
                            <i class="fab fa-whatsapp"></i>
                            رقم جوال الواتساب
                        </label>
                        <input 
                            type="tel" 
                            id="whatsapp_number" 
                            name="whatsapp_number" 
                            value="<?php echo htmlspecialchars($_POST['whatsapp_number'] ?? ''); ?>"
                            class="form-control dir-ltr"
                            placeholder="05xxxxxxxx"
                        >
                        <div class="form-help">
                            <i class="fas fa-copy"></i>
                            <span>يتم نسخ رقم الجوال بشكل تلقائي مع امكانية تعديله</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="alternative_number">
                            <i class="fas fa-phone-alt"></i>
                            رقم هاتف آخر
                        </label>
                        <input 
                            type="tel" 
                            id="alternative_number" 
                            name="alternative_number" 
                            value="<?php echo htmlspecialchars($_POST['alternative_number'] ?? ''); ?>"
                            class="form-control dir-ltr"
                            placeholder="05xxxxxxxx"
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        البريد الإلكتروني
                    </label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        class="form-control"
                        placeholder="example@domain.com"
                    >
                </div>

                <!-- Section 3: Location Information -->
                <div class="section-divider">
                    <div class="section-number">3</div>
                    <h3><i class="fas fa-map-marked-alt"></i> معلومات الموقع والعنوان</h3>
                </div>
                
                <div class="info-box">
                    <div class="info-box-header">
                        <i class="fas fa-info-circle"></i>
                        <span>بيانات الموقع</span>
                    </div>
                    <div class="info-box-content">
                        يرجى تحديد المحافظة والمنطقة وموقع استلام الطلب بدقة لضمان التوصيل السريع.
                    </div>
                </div>
                
                <div class="grid-4">
                    <div class="form-group">
                        <label for="city_id">
                            <i class="fas fa-city"></i>
                            المدينة
                        </label>
                        <select name="city_id" id="city_id" class="form-control">
                            <option value="">-- اختر المدينة --</option>
                            <?php foreach ($cities as $city): ?>
                            <option value="<?php echo $city['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($city['name']); ?>"
                                    <?php echo ($_POST['city_id'] ?? '') == $city['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="city_name" id="city_name" value="<?php echo htmlspecialchars($_POST['city_name'] ?? ''); ?>">
                        <div class="form-help">
                            <i class="fas fa-map"></i>
                            <span>اختر المدينة من القائمة</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="location_area">
                            <i class="fas fa-map-marker-alt"></i>
                            المنطقة أو الحي
                        </label>
                        <input 
                            type="text" 
                            id="location_area" 
                            name="location_area" 
                            value="<?php echo htmlspecialchars($_POST['location_area'] ?? ''); ?>"
                            class="form-control"
                            placeholder="مثلاً: حي الزبيري، منطقة الحصبة"
                        >
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="pickup_location">
                            <i class="fas fa-map-pin"></i>
                            موقع استلام الطلب (نقطة مرجعية)
                        </label>
                        <input 
                            type="text" 
                            id="pickup_location" 
                            name="pickup_location" 
                            value="<?php echo htmlspecialchars($_POST['pickup_location'] ?? ''); ?>"
                            class="form-control"
                            placeholder="مثلاً: أمام مسجد الصالح، بجوار محطة الوقود، قرب السوق المركزي"
                        >
                    </div>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>
                            <i class="fas fa-truck-loading"></i>
                            طريقة استلام الطلب
                        </label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="pickup_option_1" name="pickup_options[]" value="customer_location" <?php echo in_array('customer_location', $_POST['pickup_options'] ?? []) ? 'checked' : ''; ?>>
                                <label for="pickup_option_1">توصيل عبر مندوب من الموقع</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" id="pickup_option_2" name="pickup_options[]" value="delegate" <?php echo in_array('delegate', $_POST['pickup_options'] ?? []) ? 'checked' : ''; ?>>
                                <label for="pickup_option_2">توصيل عبر مندوب للتوصيل</label>
                            </div>
                        </div>
                        <div class="form-help form-help-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>هناك خياران لكيفية استلام الطلب</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="office_id">
                            <i class="fas fa-building"></i>
                            مكتب النقل
                        </label>
                        <select name="office_id" id="office_id" class="form-control">
                            <option value="">-- اختر مكتب النقل (إن وجد) --</option>
                            <?php foreach ($offices as $office): ?>
                            <option value="<?php echo $office['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($office['name']); ?>"
                                    <?php echo ($_POST['office_id'] ?? '') == $office['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($office['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="office_name" id="office_name" value="<?php echo htmlspecialchars($_POST['office_name'] ?? ''); ?>">
                        <div class="form-help">
                            <i class="fas fa-info-circle"></i>
                            <span>يظهر حقل اسم مكتب النقل ورقم المكتب</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">
                        <i class="fas fa-map-marker-alt"></i>
                        العنوان
                    </label>
                    <textarea 
                        id="address" 
                        name="address" 
                        rows="3"
                        class="form-control"
                        placeholder="أدخل عنوان العميل الكامل"
                    ><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                </div>

                <!-- Section 4: Additional Information -->
                <div class="section-divider">
                    <div class="section-number">4</div>
                    <h3><i class="fas fa-info-circle"></i> الملاحظات</h3>
                </div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="credit_limit">
                            <i class="fas fa-money-bill-wave"></i>
                            الحد الائتماني
                        </label>
                        <input 
                            type="number" 
                            id="credit_limit" 
                            name="credit_limit" 
                            value="<?php echo htmlspecialchars($_POST['credit_limit'] ?? '0'); ?>"
                            class="form-control"
                            placeholder="0.00"
                            step="0.01"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="pickup_notes">
                            <i class="fas fa-sticky-note"></i>
                            ملاحظات الاستلام
                        </label>
                        <textarea 
                            id="pickup_notes" 
                            name="pickup_notes" 
                            rows="2"
                            class="form-control"
                            placeholder="أي ملاحظات خاصة بالاستلام"
                        ><?php echo htmlspecialchars($_POST['pickup_notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        حفظ العميل
                    </button>
                    
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-undo"></i>
                        إعادة تعيين
                    </button>
                    
                    <a href="index.php" class="btn btn-outline">
                        <i class="fas fa-times"></i>
                        إلغاء
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Instructions Card -->
        <div class="form-card">
            <div class="form-card-header" style="background: linear-gradient(135deg, #C7A46D 0%, #059669 100%); border-bottom-color: #047857;">
                <h2><i class="fas fa-lightbulb"></i> إرشادات مهمة</h2>
            </div>
            <div class="form-card-body" style="padding: 20px 24px;">
                <div class="grid-2" style="gap: 16px;">
                    <div class="info-box" style="margin: 0;">
                        <div class="info-box-header">
                            <i class="fas fa-check-circle"></i>
                            <span>الحقول المطلوبة</span>
                        </div>
                        <div class="info-box-content">
                            الحقول المميزة بـ <span class="required">*</span> إلزامية ويجب تعبئتها قبل الحفظ.
                        </div>
                    </div>
                    
                    <div class="info-box" style="margin: 0;">
                        <div class="info-box-header">
                            <i class="fas fa-edit"></i>
                            <span>التعديل</span>
                        </div>
                        <div class="info-box-content">
                            يمكن تعديل جميع بيانات العميل لاحقاً من صفحة التعديل.
                        </div>
                    </div>
                    
                    <div class="info-box" style="margin: 0;">
                        <div class="info-box-header">
                            <i class="fas fa-id-card"></i>
                            <span>رقم العميل</span>
                        </div>
                        <div class="info-box-content">
                            يجب إدخال رقم العميل بالتنسيق الصحيح (4 أحرف + 3 أرقام).
                        </div>
                    </div>
                    
                    <div class="info-box" style="margin: 0;">
                        <div class="info-box-header">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>الحد الائتماني</span>
                        </div>
                        <div class="info-box-content">
                            يحدد المبلغ الأقصى المسموح للعميل بالشراء بالآجل.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Refresh customer code
    function refreshCustomerCode() {
        console.log('Refresh button clicked!');
        
        const codeInput = document.getElementById('customer_code');
        const btn = document.querySelector('.btn-refresh-code');
        
        if (!codeInput || !btn) {
            console.error('Elements not found!');
            return;
        }
        
        console.log('Current code:', codeInput.value);
        
        // Add loading animation
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;
        
        // Fetch new customer code via AJAX
        console.log('Fetching new code...');
        fetch('generate_customer_code.php')
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Received data:', data);
                if (data.success && data.code) {
                    console.log('New code:', data.code);
                    codeInput.value = data.code;
                    // Flash effect (green for success)
                    codeInput.style.background = 'linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)';
                    setTimeout(() => {
                        codeInput.style.background = 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)';
                    }, 500);
                } else {
                    throw new Error('Invalid response: ' + JSON.stringify(data));
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                // Fallback: generate code based on current value
                const currentCode = codeInput.value;
                let newNumber = 1;
                
                // Extract number from current code
                const match = currentCode.match(/CUST(\d+)/);
                if (match) {
                    newNumber = parseInt(match[1]) + 1;
                }
                
                // Generate new code with padding
                const newCode = 'CUST' + String(newNumber).padStart(3, '0');
                codeInput.value = newCode;
                
                // Flash effect (yellow for fallback)
                codeInput.style.background = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
                setTimeout(() => {
                    codeInput.style.background = 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)';
                }, 500);
            })
            .finally(() => {
                btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
                btn.disabled = false;
            });
    }
    
    // Handle office selection
    document.getElementById('office_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const officeName = selectedOption.getAttribute('data-name') || '';
        document.getElementById('office_name').value = officeName;
    });
    
    // Handle city selection
    document.getElementById('city_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const cityName = selectedOption.getAttribute('data-name') || '';
        document.getElementById('city_name').value = cityName;
    });
    
    // Auto-copy mobile number to WhatsApp number
    document.getElementById('mobile_number').addEventListener('input', function() {
        const mobileNumber = this.value;
        const whatsappInput = document.getElementById('whatsapp_number');
        if (!whatsappInput.dataset.userModified) {
            whatsappInput.value = mobileNumber;
        }
    });
    
    // Mark WhatsApp number as user-modified when changed
    document.getElementById('whatsapp_number').addEventListener('input', function() {
        this.dataset.userModified = 'true';
    });
    
    // Initialize on page load
    window.addEventListener('DOMContentLoaded', function() {
        // Initialize office name
        const officeSelect = document.getElementById('office_id');
        const selectedOfficeOption = officeSelect.options[officeSelect.selectedIndex];
        if (selectedOfficeOption && selectedOfficeOption.value) {
            const officeName = selectedOfficeOption.getAttribute('data-name') || '';
            document.getElementById('office_name').value = officeName;
        }
        
        // Initialize city name
        const citySelect = document.getElementById('city_id');
        const selectedCityOption = citySelect.options[citySelect.selectedIndex];
        if (selectedCityOption && selectedCityOption.value) {
            const cityName = selectedCityOption.getAttribute('data-name') || '';
            document.getElementById('city_name').value = cityName;
        }

        updateWaLink();
    });
</script>

<?php include '../../includes/footer.php'; ?>
