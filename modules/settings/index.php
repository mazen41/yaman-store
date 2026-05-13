<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Check if user is admin
if ($_SESSION['role'] !== 'admin') {
    header('Location: ../../index.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إعدادات النظام';

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $settings = $_POST['settings'] ?? [];
        
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$key, $value]);
        }
        
        $success_message = 'تم حفظ الإعدادات بنجاح';
    } catch (PDOException $e) {
        $error_message = 'حدث خطأ أثناء حفظ الإعدادات';
    }
}

// Get current settings
$settings_stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings");
$settings_stmt->execute();
$current_settings = [];
while ($row = $settings_stmt->fetch()) {
    $current_settings[$row['setting_key']] = $row['setting_value'];
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إعدادات النظام</h1>
                        <p class="text-gray-600 mt-1">إعدادات وتكوين النظام</p>
                    </div>
                    <div class="mt-4 sm:mt-0">
                        <button form="settingsForm" type="submit" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-save ml-2"></i>
                            حفظ الإعدادات
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <form id="settingsForm" method="POST" class="space-y-6">
            
            <!-- Company Information -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-building ml-2"></i>
                        معلومات الشركة
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">اسم الشركة</label>
                            <input 
                                type="text" 
                                name="settings[company_name]" 
                                value="<?php echo htmlspecialchars($current_settings['company_name'] ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                                placeholder="أدخل اسم الشركة"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رقم الهاتف</label>
                            <input 
                                type="tel" 
                                name="settings[company_phone]" 
                                value="<?php echo htmlspecialchars($current_settings['company_phone'] ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                                placeholder="966xxxxxxxxx"
                            >
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">عنوان الشركة</label>
                        <textarea 
                            name="settings[company_address]" 
                            rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            placeholder="أدخل عنوان الشركة"
                        ><?php echo htmlspecialchars($current_settings['company_address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">البريد الإلكتروني</label>
                            <input 
                                type="email" 
                                name="settings[company_email]" 
                                value="<?php echo htmlspecialchars($current_settings['company_email'] ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                                placeholder="info@company.com"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الموقع الإلكتروني</label>
                            <input 
                                type="url" 
                                name="settings[company_website]" 
                                value="<?php echo htmlspecialchars($current_settings['company_website'] ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                                placeholder="https://www.company.com"
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Settings -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-coins ml-2"></i>
                        الإعدادات المالية
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">العملة الأساسية</label>
                            <select 
                                name="settings[currency]" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                                <option value="ريال سعودي" <?php echo ($current_settings['currency'] ?? '') == 'ريال سعودي' ? 'selected' : ''; ?>>ريال سعودي</option>
                                <option value="ريال يمني" <?php echo ($current_settings['currency'] ?? '') == 'ريال يمني' ? 'selected' : ''; ?>>ريال يمني</option>
                                <option value="درهم إماراتي" <?php echo ($current_settings['currency'] ?? '') == 'درهم إماراتي' ? 'selected' : ''; ?>>درهم إماراتي</option>
                                <option value="دينار كويتي" <?php echo ($current_settings['currency'] ?? '') == 'دينار كويتي' ? 'selected' : ''; ?>>دينار كويتي</option>
                                <option value="دولار أمريكي" <?php echo ($current_settings['currency'] ?? '') == 'دولار أمريكي' ? 'selected' : ''; ?>>دولار أمريكي</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">نسبة الضريبة المضافة (%)</label>
                            <input 
                                type="number" 
                                name="settings[tax_rate]" 
                                value="<?php echo htmlspecialchars($current_settings['tax_rate'] ?? '15'); ?>"
                                min="0" 
                                max="100" 
                                step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رقم السجل التجاري</label>
                            <input 
                                type="text" 
                                name="settings[commercial_register]" 
                                value="<?php echo htmlspecialchars($current_settings['commercial_register'] ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                                placeholder="1010xxxxxx"
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Settings -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-cog ml-2"></i>
                        إعدادات النظام
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">المنطقة الزمنية</label>
                            <select 
                                name="settings[timezone]" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                                <option value="Asia/Riyadh" <?php echo ($current_settings['timezone'] ?? '') == 'Asia/Riyadh' ? 'selected' : ''; ?>>الرياض (UTC+3)</option>
                                <option value="Asia/Dubai" <?php echo ($current_settings['timezone'] ?? '') == 'Asia/Dubai' ? 'selected' : ''; ?>>دبي (UTC+4)</option>
                                <option value="Asia/Kuwait" <?php echo ($current_settings['timezone'] ?? '') == 'Asia/Kuwait' ? 'selected' : ''; ?>>الكويت (UTC+3)</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">تنسيق التاريخ</label>
                            <select 
                                name="settings[date_format]" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                                <option value="d/m/Y" <?php echo ($current_settings['date_format'] ?? '') == 'd/m/Y' ? 'selected' : ''; ?>>يوم/شهر/سنة</option>
                                <option value="Y-m-d" <?php echo ($current_settings['date_format'] ?? '') == 'Y-m-d' ? 'selected' : ''; ?>>سنة-شهر-يوم</option>
                                <option value="m/d/Y" <?php echo ($current_settings['date_format'] ?? '') == 'm/d/Y' ? 'selected' : ''; ?>>شهر/يوم/سنة</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">عدد العناصر في الصفحة</label>
                            <select 
                                name="settings[items_per_page]" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                                <option value="10" <?php echo ($current_settings['items_per_page'] ?? '') == '10' ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo ($current_settings['items_per_page'] ?? '') == '25' ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo ($current_settings['items_per_page'] ?? '') == '50' ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo ($current_settings['items_per_page'] ?? '') == '100' ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">لغة النظام</label>
                            <select 
                                name="settings[system_language]" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                                <option value="ar" <?php echo ($current_settings['system_language'] ?? '') == 'ar' ? 'selected' : ''; ?>>العربية</option>
                                <option value="en" <?php echo ($current_settings['system_language'] ?? '') == 'en' ? 'selected' : ''; ?>>English</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email Settings -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-envelope ml-2"></i>
                        إعدادات البريد الإلكتروني
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-gray-700">قم بتكوين إعدادات البريد الإلكتروني لإرسال الإشعارات والفواتير للعملاء.</p>
                    
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-md font-medium text-gray-900">إعدادات SMTP وقوالب البريد الإلكتروني</h3>
                            <p class="text-sm text-gray-600">قم بتكوين خادم SMTP وتخصيص قوالب البريد الإلكتروني</p>
                        </div>
                        <a href="email_settings.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-cog ml-2"></i>
                            إعدادات البريد
                        </a>
                    </div>
                    
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <div class="space-y-3">
                            <label class="flex items-center">
                                <input 
                                    type="checkbox" 
                                    name="settings[email_notifications]" 
                                    value="1"
                                    <?php echo ($current_settings['email_notifications'] ?? '') == '1' ? 'checked' : ''; ?>
                                    class="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
                                >
                                <span class="mr-3 text-sm text-gray-700">تفعيل إرسال البريد الإلكتروني</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notification Settings -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-bell ml-2"></i>
                        إعدادات التنبيهات
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="space-y-3">
                        <label class="flex items-center">
                            <input 
                                type="checkbox" 
                                name="settings[notify_low_stock]" 
                                value="1"
                                <?php echo ($current_settings['notify_low_stock'] ?? '') == '1' ? 'checked' : ''; ?>
                                class="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
                            >
                            <span class="mr-3 text-sm text-gray-700">تنبيه عند انخفاض المخزون</span>
                        </label>
                        
                        <label class="flex items-center">
                            <input 
                                type="checkbox" 
                                name="settings[notify_new_orders]" 
                                value="1"
                                <?php echo ($current_settings['notify_new_orders'] ?? '') == '1' ? 'checked' : ''; ?>
                                class="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
                            >
                            <span class="mr-3 text-sm text-gray-700">تنبيه عند وصول طلبات جديدة</span>
                        </label>
                        
                        <label class="flex items-center">
                            <input 
                                type="checkbox" 
                                name="settings[notify_payment_due]" 
                                value="1"
                                <?php echo ($current_settings['notify_payment_due'] ?? '') == '1' ? 'checked' : ''; ?>
                                class="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
                            >
                            <span class="mr-3 text-sm text-gray-700">تنبيه عند استحقاق المدفوعات</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-shield-alt ml-2"></i>
                        إعدادات الأمان
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">مدة انتهاء الجلسة (دقيقة)</label>
                            <input 
                                type="number" 
                                name="settings[session_timeout]" 
                                value="<?php echo htmlspecialchars($current_settings['session_timeout'] ?? '60'); ?>"
                                min="5" 
                                max="1440"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">الحد الأقصى لمحاولات تسجيل الدخول</label>
                            <input 
                                type="number" 
                                name="settings[max_login_attempts]" 
                                value="<?php echo htmlspecialchars($current_settings['max_login_attempts'] ?? '5'); ?>"
                                min="3" 
                                max="10"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-transparent"
                            >
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <label class="flex items-center">
                            <input 
                                type="checkbox" 
                                name="settings[force_https]" 
                                value="1"
                                <?php echo ($current_settings['force_https'] ?? '') == '1' ? 'checked' : ''; ?>
                                class="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
                            >
                            <span class="mr-3 text-sm text-gray-700">فرض استخدام HTTPS</span>
                        </label>
                        
                        <label class="flex items-center">
                            <input 
                                type="checkbox" 
                                name="settings[enable_audit_log]" 
                                value="1"
                                <?php echo ($current_settings['enable_audit_log'] ?? '') == '1' ? 'checked' : ''; ?>
                                class="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
                            >
                            <span class="mr-3 text-sm text-gray-700">تفعيل سجل التدقيق</span>
                        </label>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
