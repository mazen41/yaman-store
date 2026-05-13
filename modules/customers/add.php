<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// We will define the new function in this file, so this helper is no longer needed for the customer code.
// require_once '../../includes/auto_generate_helpers.php'; 

$page_title = 'إضافة عميل جديد';
$error_message = '';
$success_message = '';

// **NEW FUNCTION**: Simplified customer code generation (e.g., C1, C2, C3)
function generateSimpleCustomerCode($db) {
    try {
        // Find the highest customer ID
        $stmt = $db->prepare("SELECT MAX(id) as max_id FROM customers");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If there are no customers, start with 1. Otherwise, increment the highest ID.
        $next_id = ($result && $result['max_id']) ? intval($result['max_id']) + 1 : 1;
        
        // Return the code in the format 'C' followed by the number
        return 'C' . $next_id;
        
    } catch (PDOException $e) {
        // In case of an error, return a placeholder or handle it
        return 'C-ERROR';
    }
}


// --- Database Fetches (No changes here) ---
try {
    $types_stmt = $db->prepare("SELECT id, name, discount_percentage FROM customer_types WHERE is_active = 1 ORDER BY name");
    $types_stmt->execute();
    $customer_types = $types_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer_types = [];
    $error_message = 'فشل تحميل فئات العملاء. يرجى <a href="customer_types.php">إضافة فئة واحدة على الأقل</a> للمتابعة.';
}

try {
    $cities_stmt = $db->prepare("SELECT id, name FROM cities WHERE is_active = 1 ORDER BY name");
    $cities_stmt->execute();
    $cities = $cities_stmt->fetchAll();
} catch (PDOException $e) {
    $cities = [ ['id' => 1, 'name' => 'صنعاء'] /* ... other cities ... */ ];
}

// **UPDATED**: Use the new simplified function to generate the customer code
$auto_customer_code = '';
if ($_SERVER['REQUEST_METHOD'] != 'POST' || !empty($success_message)) {
    $auto_customer_code = generateSimpleCustomerCode($db);
}

// --- Form Processing Logic ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_code = trim($_POST['customer_code'] ?? '');
    if (empty($customer_code)) {
        // Regenerate if it's empty for any reason
        $customer_code = generateSimpleCustomerCode($db);
    }
    
    $name = trim($_POST['name'] ?? '');
    $phone = preg_replace('/\D+/', '', trim($_POST['phone'] ?? ''));
    $mobile_number = preg_replace('/\D+/', '', trim($_POST['mobile_number'] ?? ''));
    $whatsapp_number = preg_replace('/\D+/', '', trim($_POST['whatsapp_number'] ?? ''));
    $alternative_number = preg_replace('/\D+/', '', trim($_POST['alternative_number'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $location_url = trim($_POST['location_url'] ?? '');
    $location_area = trim($_POST['location_area'] ?? '');
    $customer_type_id = !empty($_POST['customer_type_id']) ? intval($_POST['customer_type_id']) : null;
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $city_id = intval($_POST['city_id'] ?? 0);
    $city_name = trim($_POST['city_name'] ?? '');
    
    // **NEW**: Get the new customer notes from the form
    $customer_notes = trim($_POST['customer_notes'] ?? '');
    
    // **NEW**: Get currency (Yemeni Riyal only)
    // مهما كانت القيمة القادمة من الفورم، نجبر العملة أن تكون ريال يمني (YER)
    $currency = 'YER';
    
    $errors = [];
    // **UPDATED**: New validation for the "C1" format
    if (!preg_match('/^C[0-9]+$/', $customer_code)) {
        $errors[] = 'رقم العميل يجب أن يكون بالتنسيق الصحيح (مثال: C1, C2).';
    } else {
        // Check if the code already exists (unlikely with our new method, but good practice)
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
        $check_stmt->execute([$customer_code]);
        if ($check_stmt->fetchColumn() > 0) {
            // If it exists, generate a fresh one
            $customer_code = generateSimpleCustomerCode($db);
        }
    }
    if (empty($name)) $errors[] = 'اسم العميل مطلوب';
    if (empty($city_id)) $errors[] = 'يرجى اختيار المدينة';
    if (empty($customer_type_id)) $errors[] = 'يرجى اختيار فئة العميل.';
    if (!$whatsapp_number && $mobile_number) $whatsapp_number = $mobile_number;
    
    if (!empty($errors)) {
        $error_message = implode(' • ', $errors);
    } else {
        try {
            // **UPDATED**: Generate unique portal token
            $portal_token = bin2hex(random_bytes(32));
            
            // **UPDATED**: Removed `email` and added `customer_notes` to the INSERT statement
            $stmt = $db->prepare("
                INSERT INTO customers (
                    customer_code, name, phone, mobile_number, whatsapp_number, alternative_number, 
                    address, location_url, location_area, customer_type_id, credit_limit, city_id, city_name, 
                    currency, portal_token, customer_notes, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
            
            // **UPDATED**: Removed `$email` and added `$customer_notes` to the execute array
            $stmt->execute([
                $customer_code, $name, ($phone ?: null), ($mobile_number ?: null), ($whatsapp_number ?: null), ($alternative_number ?: null),
                ($address ?: null), ($location_url ?: null), ($location_area ?: null), $customer_type_id,
                $credit_limit, ($city_id ?: null), ($city_name ?: null), $currency, $portal_token, ($customer_notes ?: null)
            ]);
            
            $success_message = "تم إضافة العميل '{$name}' بنجاح برقم ({$customer_code}).";
            $_POST = []; // Clear form data on success
            
        } catch (PDOException $e) {
            $error_message = ($e->getCode() == 23000)
                ? 'البيانات مكررة (قد يكون رقم الهاتف مستخدمًا بالفعل).'
                : 'حدث خطأ أثناء إضافة العميل: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<!-- Your existing CSS styles are fine, no changes needed -->
<style>
    * { font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .form-card { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); margin-bottom: 24px; }
    .form-card-header { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px 24px; }
    .form-card-header h2 { font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 12px; }
    .form-card-body { padding: 32px 24px; }
    .section-divider { display: flex; align-items: center; gap: 12px; margin: 32px 0 24px 0; padding-bottom: 12px; border-bottom: 2px solid #e5e7eb; }
    .section-divider h3 { font-size: 1.125rem; font-weight: 700; color: #1f2937; margin: 0; display: flex; align-items: center; gap: 8px; }
    .section-number { background: #3b82f6; color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
    .form-group { margin-bottom: 24px; }
    .form-group label { display: block; font-weight: 600; color: #374151; margin-bottom: 8px; }
    .form-group label .required { color: #ef4444; }
    .form-control { width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; transition: all 0.2s ease; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    @media (max-width: 768px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
    .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
    .alert-success { background: #d1fae5; border: 2px solid #6ee7b7; color: #065f46; }
    .alert-error { background: #fee2e2; border: 2px solid #fca5a5; color: #991b1b; }
    .page-header { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); padding: 24px; margin-bottom: 24px; }
    .page-header-content { display: flex; align-items: center; justify-content: space-between; }
    .page-title { font-size: 1.75rem; font-weight: 700; color: #111827; }
</style>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-5xl mx-auto px-4">
        
        <div class="page-header">
            <div class="page-header-content">
                <h1 class="page-title">إضافة عميل جديد</h1>
                <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-right"></i> العودة للقائمة</a>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-card-header"><h2><i class="fas fa-user-plus"></i> بيانات العميل</h2></div>
            <form method="POST" class="form-card-body">
                
                <div class="section-divider"><div class="section-number">1</div><h3><i class="fas fa-user-circle"></i> المعلومات الأساسية</h3></div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> رقم العميل (تلقائي)</label>
                        <input type="text" id="customer_code" name="customer_code" readonly value="<?php echo htmlspecialchars($auto_customer_code); ?>" class="form-control" style="background: #f3f4f6; cursor: not-allowed; font-weight: 700;">
                    </div>
                    
                    <div class="form-group">
                        <label for="customer_type_id"><i class="fas fa-list"></i> الفئة <span class="required">*</span></label>
                        <select name="customer_type_id" id="customer_type_id" required class="form-control">
                            <option value="">-- اختر الفئة --</option>
                            <?php foreach ($customer_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo ($_POST['customer_type_id'] ?? '') == $type['id'] ? 'selected' : ''; ?>>
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
                    <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" class="form-control" placeholder="أدخل الاسم الكامل للعميل">
                </div>

                <div class="section-divider"><div class="section-number">2</div><h3><i class="fas fa-phone"></i> معلومات الاتصال</h3></div>
                
                <div class="grid-3">
                    <div class="form-group">
                        <label for="mobile_number"><i class="fas fa-mobile-alt"></i> رقم جوال</label>
                        <input type="tel" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>" class="form-control" placeholder="05xxxxxxxx">
                    </div>
                    <div class="form-group">
                        <label for="whatsapp_number"><i class="fab fa-whatsapp"></i> رقم واتساب</label>
                        <input type="tel" id="whatsapp_number" name="whatsapp_number" value="<?php echo htmlspecialchars($_POST['whatsapp_number'] ?? ''); ?>" class="form-control" placeholder="سيتم نسخه من رقم الجوال">
                    </div>
                    <div class="form-group">
                        <label for="alternative_number"><i class="fas fa-phone-alt"></i> رقم هاتف آخر</label>
                        <input type="tel" id="alternative_number" name="alternative_number" value="<?php echo htmlspecialchars($_POST['alternative_number'] ?? ''); ?>" class="form-control">
                    </div>
                </div>
                <!-- EMAIL FIELD REMOVED -->
                
                <div class="section-divider"><div class="section-number">3</div><h3><i class="fas fa-map-marked-alt"></i> معلومات الموقع والعنوان</h3></div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="city_id"><i class="fas fa-city"></i> المدينة <span class="required">*</span></label>
                        <select name="city_id" id="city_id" required class="form-control">
                            <option value="">-- اختر المدينة --</option>
                            <?php foreach ($cities as $city): ?>
                            <option value="<?php echo $city['id']; ?>" data-name="<?php echo htmlspecialchars($city['name']); ?>" <?php echo ($_POST['city_id'] ?? '') == $city['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="city_name" id="city_name" value="<?php echo htmlspecialchars($_POST['city_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="location_area"><i class="fas fa-map-marker-alt"></i> المنطقة أو الحي</label>
                        <input type="text" id="location_area" name="location_area" value="<?php echo htmlspecialchars($_POST['location_area'] ?? ''); ?>" class="form-control" placeholder="مثلاً: حي الزبيري">
                    </div>
                </div>
                <div class="form-group">
                    <label for="address"><i class="fas fa-map-pin"></i> العنوان التفصيلي</label>
                    <textarea id="address" name="address" rows="2" class="form-control" placeholder="أدخل عنوان العميل الكامل"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="location_url"><i class="fas fa-link"></i> رابط الموقع (Google Maps)</label>
                    <input type="url" id="location_url" name="location_url" value="<?php echo htmlspecialchars($_POST['location_url'] ?? ''); ?>" class="form-control" placeholder="https://maps.google.com/...">
                </div>
                
                <div class="section-divider"><div class="section-number">4</div><h3><i class="fas fa-info-circle"></i> بيانات إضافية</h3></div>
                <div class="grid-2">
                    <div class="form-group">
                        <label for="currency"><i class="fas fa-coins"></i> عملة التعامل <span class="required">*</span></label>
                        <select name="currency" id="currency" required class="form-control">
                            <option value="YER" selected>ريال يمني (YER)</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">سيتم استخدام هذه العملة للفواتير والتقارير الخاصة بهذا العميل</p>
                    </div>
                    <div class="form-group">
                        <label for="credit_limit"><i class="fas fa-money-bill-wave"></i> الحد الائتماني</label>
                        <input type="number" id="credit_limit" name="credit_limit" value="<?php echo htmlspecialchars($_POST['credit_limit'] ?? '0'); ?>" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                </div>

                <!-- **NEW**: Customer Notes Field Added Here -->
                <div class="form-group">
                    <label for="customer_notes"><i class="fas fa-sticky-note"></i> ملاحظات العميل</label>
                    <textarea id="customer_notes" name="customer_notes" rows="3" class="form-control" placeholder="أدخل أي ملاحظات إضافية حول العميل"><?php echo htmlspecialchars($_POST['customer_notes'] ?? ''); ?></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 30px;">
                    <button 
                        type="submit" 
                        style="background: linear-gradient(135deg, #007bff, #0056d2); color: white; border: none; padding: 10px 22px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 3px 6px rgba(0,0,0,0.1); transition: all 0.3s;">
                        <i class="fas fa-save"></i> حفظ العميل
                    </button>

                    <button 
                        type="reset" 
                        style="background: #f1f1f1; color: #333; border: 1px solid #ccc; padding: 10px 22px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.3s;">
                        <i class="fas fa-undo"></i> إعادة تعيين
                    </button>

                    <a 
                        href="index.php" 
                        style="background: linear-gradient(135deg, #dc3545, #a71d2a); color: white; text-decoration: none; padding: 10px 22px; border-radius: 8px; font-weight: 600; display: flex; align-items: center; gap: 8px; box-shadow: 0 3px 6px rgba(0,0,0,0.1); transition: all 0.3s;">
                        <i class="fas fa-times"></i> إلغاء
                    </a>
                </div>

            </form>
        </div>
        
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // This script automatically fills the city_name based on selection
        const citySelect = document.getElementById('city_id');
        if (citySelect) {
            citySelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                document.getElementById('city_name').value = selectedOption.getAttribute('data-name') || '';
            });
        }
         document.querySelectorAll('button, a[style*="background"]').forEach(el => {
            el.addEventListener('mouseenter', () => el.style.transform = 'translateY(-2px)');
            el.addEventListener('mouseleave', () => el.style.transform = 'translateY(0)');
        });
        // This script copies the mobile number to the WhatsApp field automatically
        const mobileInput = document.getElementById('mobile_number');
        const whatsappInput = document.getElementById('whatsapp_number');
        if (mobileInput && whatsappInput) {
            mobileInput.addEventListener('input', function() {
                // Only copy if the user hasn't typed in the WhatsApp field yet
                if (!whatsappInput.dataset.userModified) {
                    whatsappInput.value = this.value;
                }
            });
            whatsappInput.addEventListener('input', function() {
                // Mark that the user has manually changed the WhatsApp field
                this.dataset.userModified = 'true';
            });
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>