<?php
// Enable detailed error reporting for debugging if needed
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Permission guard
if (!hasPermission($_SESSION['user_id'], 'customers', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل بيانات العميل';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل بيانات العميل';
$error_message = '';
$success_message = '';

// 1. CHECK FOR CUSTOMER ID
// Ensure a valid customer ID is provided in the URL.
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    // Redirect if no ID is found.
    $_SESSION['error_message'] = 'لم يتم تحديد عميل.';
    header('Location: index.php');
    exit();
}
$customer_id = intval($_GET['id']);


// 2. FETCH DATA FOR DROPDOWNS (like on the add page)
// Fetch customer types with their discount percentages.
try {
    $types_stmt = $db->prepare("SELECT id, name, discount_percentage FROM customer_types WHERE is_active = 1 ORDER BY name");
    $types_stmt->execute();
    $customer_types = $types_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer_types = [];
    $error_message = 'فشل تحميل فئات العملاء.';
}

// Fetch cities.
try {
    $cities_stmt = $db->prepare("SELECT id, name FROM cities WHERE is_active = 1 ORDER BY name");
    $cities_stmt->execute();
    $cities = $cities_stmt->fetchAll();
} catch (PDOException $e) {
    // Provide a default list if the database query fails.
    $cities = [['id' => 1, 'name' => 'صنعاء'], ['id' => 2, 'name' => 'عدن']];
    $error_message = 'فشل تحميل قائمة المدن.';
}


// 3. HANDLE FORM SUBMISSION (UPDATE LOGIC)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and retrieve all form data, just like the add page.
    $name = trim($_POST['name'] ?? '');
    $phone = preg_replace('/\D+/', '', trim($_POST['phone'] ?? ''));
    $mobile_number = preg_replace('/\D+/', '', trim($_POST['mobile_number'] ?? ''));
    $whatsapp_number = preg_replace('/\D+/', '', trim($_POST['whatsapp_number'] ?? ''));
    $alternative_number = preg_replace('/\D+/', '', trim($_POST['alternative_number'] ?? ''));
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $location_url = trim($_POST['location_url'] ?? '');
    $location_area = trim($_POST['location_area'] ?? '');
    $customer_type_id = !empty($_POST['customer_type_id']) ? intval($_POST['customer_type_id']) : null;
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $city_id = intval($_POST['city_id'] ?? 0);
    $city_name = trim($_POST['city_name'] ?? '');

    // **NEW**: Get the customer notes from the form
    $customer_notes = trim($_POST['customer_notes'] ?? '');

    // Currency is always Yemeni Rial (YER) regardless of form input
    $currency = 'YER';
    
    // Validation
    $errors = [];
    if (empty($name)) $errors[] = 'اسم العميل مطلوب.';
    if (empty($city_id)) $errors[] = 'يرجى اختيار مدينة الشحن.';
    if (empty($customer_type_id)) $errors[] = 'يرجى اختيار فئة العميل.';
    
    if (!empty($errors)) {
        $error_message = implode(' • ', $errors);
    } else {
        try {
            // **UPDATED**: Added `customer_notes` to the UPDATE statement
            $stmt = $db->prepare("
                UPDATE customers SET 
                    name = ?, phone = ?, mobile_number = ?, whatsapp_number = ?, 
                    alternative_number = ?, email = ?, address = ?, location_url = ?, location_area = ?, 
                    customer_type_id = ?, credit_limit = ?, 
                    city_id = ?, city_name = ?, 
                    currency = ?, customer_notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            // **UPDATED**: Added the `$customer_notes` variable to the execute array
            $stmt->execute([
                $name, ($phone ?: null), ($mobile_number ?: null), ($whatsapp_number ?: null), 
                ($alternative_number ?: null), ($email ?: null), ($address ?: null), 
                ($location_url ?: null), ($location_area ?: null), $customer_type_id, 
                $credit_limit, ($city_id ?: null), ($city_name ?: null), 
                $currency, ($customer_notes ?: null),
                $customer_id // The ID of the customer to update.
            ]);
            
            $success_message = "تم تحديث بيانات العميل '{$name}' بنجاح.";
            
        } catch (PDOException $e) {
            // Handle potential database errors, like duplicate entries.
            $error_message = ($e->getCode() == 23000)
                ? 'البيانات مكررة (قد يكون رقم الهاتف أو البريد الإلكتروني مستخدمًا بالفعل).'
                : 'حدث خطأ أثناء تحديث العميل: ' . $e->getMessage();
        }
    }
}


// 4. FETCH EXISTING CUSTOMER DATA TO PRE-FILL THE FORM
// This runs after a POST to show updated data, or on initial GET to show current data.
try {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no customer is found with the given ID, redirect back to the index page.
    if (!$customer) {
        $_SESSION['error_message'] = 'العميل غير موجود.';
        header('Location: index.php');
        exit();
    }
    
    // If the form was not just submitted, use the fetched data.
    // Otherwise, use the data from $_POST to re-populate the form on error.
    $display_data = ($_SERVER['REQUEST_METHOD'] == 'POST') ? $_POST : $customer;

} catch (PDOException $e) {
    $error_message = 'فشل في استرجاع بيانات العميل: ' . $e->getMessage();
    $customer = []; // Ensure $customer is an array to avoid errors in the form.
    $display_data = $_POST; // On fetch error, still try to show submitted data.
}

include '../../includes/header.php';
?>

<!-- 5. HTML STRUCTURE AND STYLES (Copied from add page) -->
<style>
    /* Your existing CSS from add_customer.php */
    * { font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .dir-ltr { direction: ltr; text-align: left; }
    .form-card { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); overflow: hidden; margin-bottom: 24px; }
    .form-card-header { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px 24px; border-bottom: 4px solid #1e40af; }
    .form-card-header h2 { font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 12px; }
    .form-card-body { padding: 32px 24px; }
    .section-divider { display: flex; align-items: center; gap: 12px; margin: 32px 0 24px 0; padding-bottom: 12px; border-bottom: 2px solid #e5e7eb; }
    .section-divider h3 { font-size: 1.125rem; font-weight: 700; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 8px; }
    .section-number { background: #3b82f6; color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; }
    .form-group { margin-bottom: 24px; }
    .form-group label { display: block; font-weight: 600; color: #374151; margin-bottom: 8px; font-size: 0.95rem; }
    .form-group label .required { color: #ef4444; margin-right: 4px; }
    .form-control { width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; background: #ffffff; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .info-box { background: #f0f9ff; border: 2px solid #bfdbfe; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    @media (max-width: 768px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
    .btn-group { display: flex; gap: 12px; flex-wrap: wrap; padding-top: 24px; border-top: 2px solid #e5e7eb; margin-top: 32px; }
    .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 1rem; }
    .alert-success { background: #d1fae5; border: 2px solid #6ee7b7; color: #065f46; }
    .alert-error { background: #fee2e2; border: 2px solid #fca5a5; color: #991b1b; }
    .page-header { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); padding: 24px; margin-bottom: 24px; }
    .page-header-content { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
    .page-title { font-size: 1.75rem; font-weight: 700; color: #111827; }
    .page-subtitle { color: #6b7280; font-size: 1rem; }
</style>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <div class="page-header">
            <div class="page-header-content">
                <div>
                    <h1 class="page-title">تعديل بيانات العميل</h1>
                    <p class="page-subtitle">تحديث بيانات: <?php echo htmlspecialchars($customer['name'] ?? '...'); ?></p>
                </div>
                <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-right"></i> العودة للقائمة</a>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><div class="flex-1"><?php echo $success_message; ?></div><a href="index.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 0.9rem;">عرض القائمة</a></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div><?php echo $error_message; ?></div></div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-card-header"><h2><i class="fas fa-user-edit"></i> تعديل بيانات العميل</h2></div>
            <form method="POST" class="form-card-body">
                
                <div class="section-divider"><div class="section-number">1</div><h3><i class="fas fa-user-circle"></i> المعلومات الأساسية</h3></div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> رقم العميل</label>
                        <input type="text" readonly value="<?php echo htmlspecialchars($customer['customer_code'] ?? ''); ?>" class="form-control" style="background: #f3f4f6; cursor: not-allowed; font-weight: 700;">
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_type_id"><i class="fas fa-list"></i> الفئة <span class="required">*</span></label>
                        <select name="customer_type_id" id="customer_type_id" required class="form-control">
                            <option value="">-- اختر الفئة --</option>
                            <?php foreach ($customer_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo (isset($display_data['customer_type_id']) && $display_data['customer_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                                <?php if (isset($type['discount_percentage']) && $type['discount_percentage'] > 0): ?>
                                    (خصم <?php echo rtrim(rtrim(number_format($type['discount_percentage'], 0, '', ''), '0'), '.'); ?>%)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name"><i class="fas fa-user"></i> الاسم الكامل للعميل <span class="required">*</span></label>
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($display_data['name'] ?? ''); ?>" class="form-control" placeholder="أدخل الاسم الكامل للعميل">
                </div>

                <div class="section-divider"><div class="section-number">2</div><h3><i class="fas fa-phone"></i> معلومات الاتصال</h3></div>
                
                <div class="grid-3">
                    <div class="form-group">
                        <label for="mobile_number"><i class="fas fa-mobile-alt"></i> رقم جوال</label>
                        <input type="tel" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($display_data['mobile_number'] ?? ''); ?>" class="form-control dir-ltr" placeholder="05xxxxxxxx">
                    </div>
                    <div class="form-group">
                        <label for="whatsapp_number"><i class="fab fa-whatsapp"></i> رقم واتساب</label>
                        <input type="tel" id="whatsapp_number" name="whatsapp_number" value="<?php echo htmlspecialchars($display_data['whatsapp_number'] ?? ''); ?>" class="form-control dir-ltr">
                    </div>
                    <div class="form-group">
                        <label for="alternative_number"><i class="fas fa-phone-alt"></i> رقم هاتف آخر</label>
                        <input type="tel" id="alternative_number" name="alternative_number" value="<?php echo htmlspecialchars($display_data['alternative_number'] ?? ''); ?>" class="form-control dir-ltr">
                    </div>
                </div>
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> البريد الإلكتروني</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($display_data['email'] ?? ''); ?>" class="form-control" placeholder="example@domain.com">
                </div>
                
                <div class="section-divider"><div class="section-number">3</div><h3><i class="fas fa-map-marked-alt"></i> معلومات الموقع والعنوان</h3></div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="city_id"><i class="fas fa-city"></i> المدينة</label>
                        <select name="city_id" id="city_id" class="form-control">
                            <option value="">-- اختر المدينة --</option>
                            <?php foreach ($cities as $city): ?>
                            <option value="<?php echo $city['id']; ?>" data-name="<?php echo htmlspecialchars($city['name']); ?>" <?php echo (isset($display_data['city_id']) && $display_data['city_id'] == $city['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="city_name" id="city_name" value="<?php echo htmlspecialchars($display_data['city_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="location_area"><i class="fas fa-map-marker-alt"></i> المنطقة أو الحي</label>
                        <input type="text" id="location_area" name="location_area" value="<?php echo htmlspecialchars($display_data['location_area'] ?? ''); ?>" class="form-control" placeholder="مثلاً: حي الزبيري">
                    </div>
                </div>
                <div class="form-group">
                    <label for="address"><i class="fas fa-map-pin"></i> العنوان التفصيلي</label>
                    <textarea id="address" name="address" rows="2" class="form-control" placeholder="أدخل عنوان العميل الكامل"><?php echo htmlspecialchars($display_data['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="location_url"><i class="fas fa-link"></i> رابط الموقع (Google Maps)</label>
                    <input type="url" id="location_url" name="location_url" value="<?php echo htmlspecialchars($display_data['location_url'] ?? ''); ?>" class="form-control" placeholder="https://maps.google.com/...">
                </div>
                
                <div class="section-divider"><div class="section-number">4</div><h3><i class="fas fa-info-circle"></i> بيانات إضافية</h3></div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="currency"><i class="fas fa-coins"></i> عملة التعامل <span class="required">*</span></label>
                        <select name="currency" id="currency" required class="form-control" disabled>
                            <option value="YER" selected>ريال يمني (YER)</option>
                        </select>
                        <input type="hidden" name="currency" value="YER">
                    </div>
                    <div class="form-group">
                        <label for="credit_limit"><i class="fas fa-money-bill-wave"></i> الحد الائتماني</label>
                        <input type="number" id="credit_limit" name="credit_limit" value="<?php echo htmlspecialchars($display_data['credit_limit'] ?? '0'); ?>" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                </div>

                <!-- **NEW**: Customer Notes Field Added Here -->
                <div class="form-group">
                    <label for="customer_notes"><i class="fas fa-sticky-note"></i> ملاحظات العميل</label>
                    <textarea id="customer_notes" name="customer_notes" rows="3" class="form-control" placeholder="أدخل أي ملاحظات إضافية حول العميل"><?php echo htmlspecialchars($display_data['customer_notes'] ?? ''); ?></textarea>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التعديلات</button>
                    <a href="index.php" class="btn btn-outline"><i class="fas fa-times"></i> إلغاء</a>
                </div>
            </form>
        </div>
        
    </div>
</div>

<!-- 6. JAVASCRIPT (Copied from add page) -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const citySelect = document.getElementById('city_id');
        if (citySelect) {
            citySelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                document.getElementById('city_name').value = selectedOption.getAttribute('data-name') || '';
            });
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>