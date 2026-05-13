<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إعدادات البريد الإلكتروني';
$error_message = '';
$success_message = '';

// Check if email_settings table exists, if not create it
try {
    $check_table = $db->query("SHOW TABLES LIKE 'email_settings'");
    if ($check_table->rowCount() == 0) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS email_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                smtp_host VARCHAR(255) NOT NULL,
                smtp_port INT NOT NULL DEFAULT 587,
                smtp_username VARCHAR(255) NOT NULL,
                smtp_password VARCHAR(255) NOT NULL,
                smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
                from_email VARCHAR(255) NOT NULL,
                from_name VARCHAR(255) NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        // Insert default settings
        $db->exec("
            INSERT INTO email_settings (smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name)
            VALUES ('smtp.gmail.com', 587, 'your-email@gmail.com', 'your-password', 'tls', 'notifications@yourdomain.com', 'نظام يمان للإشعارات')
        ");
    }
} catch (PDOException $e) {
    $error_message = 'خطأ في قاعدة البيانات: ' . $e->getMessage();
}

// Get current settings
try {
    $stmt = $db->query("SELECT * FROM email_settings ORDER BY id DESC LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'خطأ في استرجاع الإعدادات: ' . $e->getMessage();
    $settings = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = intval($_POST['smtp_port'] ?? 587);
    $smtp_username = trim($_POST['smtp_username'] ?? '');
    $smtp_password = trim($_POST['smtp_password'] ?? '');
    $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    $errors = [];
    
    if (empty($smtp_host)) {
        $errors[] = 'يرجى إدخال عنوان خادم SMTP';
    }
    
    if ($smtp_port <= 0 || $smtp_port > 65535) {
        $errors[] = 'يرجى إدخال منفذ SMTP صحيح';
    }
    
    if (empty($smtp_username)) {
        $errors[] = 'يرجى إدخال اسم المستخدم لـ SMTP';
    }
    
    if (empty($from_email) || !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'يرجى إدخال عنوان بريد إلكتروني صحيح للمرسل';
    }
    
    if (empty($from_name)) {
        $errors[] = 'يرجى إدخال اسم المرسل';
    }
    
    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
    } else {
        try {
            // Check if we need to update or insert
            if ($settings) {
                // If password field is empty, keep the old password
                $password_sql = empty($smtp_password) ? '' : "smtp_password = :smtp_password,";
                
                $sql = "
                    UPDATE email_settings SET 
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_username = :smtp_username,
                    $password_sql
                    smtp_encryption = :smtp_encryption,
                    from_email = :from_email,
                    from_name = :from_name,
                    is_active = :is_active,
                    updated_at = NOW()
                    WHERE id = :id
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':id', $settings['id']);
            } else {
                $sql = "
                    INSERT INTO email_settings (
                        smtp_host, smtp_port, smtp_username, smtp_password, 
                        smtp_encryption, from_email, from_name, is_active
                    ) VALUES (
                        :smtp_host, :smtp_port, :smtp_username, :smtp_password,
                        :smtp_encryption, :from_email, :from_name, :is_active
                    )
                ";
                
                $stmt = $db->prepare($sql);
            }
            
            $stmt->bindParam(':smtp_host', $smtp_host);
            $stmt->bindParam(':smtp_port', $smtp_port);
            $stmt->bindParam(':smtp_username', $smtp_username);
            if (empty($smtp_password) && $settings) {
                // Skip binding password if empty and updating
            } else {
                $stmt->bindParam(':smtp_password', $smtp_password);
            }
            $stmt->bindParam(':smtp_encryption', $smtp_encryption);
            $stmt->bindParam(':from_email', $from_email);
            $stmt->bindParam(':from_name', $from_name);
            $stmt->bindParam(':is_active', $is_active);
            
            $stmt->execute();
            
            $success_message = 'تم حفظ إعدادات البريد الإلكتروني بنجاح';
            
            // Refresh settings
            $stmt = $db->query("SELECT * FROM email_settings ORDER BY id DESC LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $error_message = 'خطأ في حفظ الإعدادات: ' . $e->getMessage();
        }
    }
}

// Test email functionality
if (isset($_POST['test_email'])) {
    $test_email = trim($_POST['test_email_address'] ?? '');
    
    if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'يرجى إدخال عنوان بريد إلكتروني صحيح للاختبار';
    } else {
        // Create dummy data for testing
        $dummy_customer = [
            'id' => 1,
            'name' => 'عميل اختباري',
            'email' => $test_email,
            'mobile_number' => '966500000000',
            'whatsapp_number' => '966500000000',
            'customer_code' => 'TEST001',
            'city_name' => 'الرياض'
        ];
        
        $dummy_order = [
            'id' => 1,
            'order_number' => 'ORD-' . date('Ymd') . '-001',
            'customer_id' => 1,
            'status' => 'new',
            'total_amount' => 1500.00,
            'shipping_cost' => 50.00,
            'final_amount' => 1550.00,
            'payment_method' => 'cash',
            'shipping_method' => 'delivery',
            'expected_delivery_date' => date('Y-m-d', strtotime('+3 days')),
            'created_at' => date('Y-m-d H:i:s'),
            'notes' => 'هذا طلب اختباري لفحص إعدادات البريد الإلكتروني'
        ];
        
        $dummy_items = [
            [
                'id' => 1,
                'order_id' => 1,
                'product_name' => 'منتج اختباري 1',
                'quantity' => 2,
                'unit_price' => 500.00,
                'total_price' => 1000.00
            ],
            [
                'id' => 2,
                'order_id' => 1,
                'product_name' => 'منتج اختباري 2',
                'quantity' => 1,
                'unit_price' => 500.00,
                'total_price' => 500.00
            ]
        ];
        
        $dummy_status_history = [
            'id' => 1,
            'order_id' => 1,
            'status' => 'new',
            'notes' => 'تم إنشاء الطلب',
            'created_by' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        // Check if PHPMailer is installed
        $vendor_autoload = '../../vendor/autoload.php';
        $phpmailer_exists = false;
        
        if (file_exists($vendor_autoload)) {
            require_once $vendor_autoload;
            $phpmailer_exists = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
        }
        
        // Get test email subject and message
        $test_subject = isset($_POST['test_subject']) ? trim($_POST['test_subject']) : 'اختبار إعدادات البريد الإلكتروني - نظام يمان';
        $test_message = isset($_POST['test_message']) ? trim($_POST['test_message']) : 'هذا اختبار لإعدادات SMTP. إذا وصلتك هذه الرسالة، فهذا يعني أن الإعدادات صحيحة.';
        $debug_mode = isset($_POST['debug_mode']) ? true : false;
        
        // Format message as HTML
        $html_message = '
            <div dir="rtl" style="font-family: Arial, sans-serif; line-height: 1.6;">
                <h2 style="color: #C7A46D;">اختبار إعدادات البريد الإلكتروني</h2>
                <p>' . nl2br(htmlspecialchars($test_message)) . '</p>
                <p style="margin-top: 20px;">مع تحيات،<br>فريق نظام يمان</p>
            </div>
        ';
        
        // Add debug mode to settings
        $settings['debug'] = $debug_mode;
        
        // Special handling for Hostinger SMTP
        if (strpos($settings['smtp_host'], 'hostinger') !== false) {
            // Force SSL for Hostinger with port 465
            if ($settings['smtp_port'] == 465) {
                $settings['smtp_encryption'] = 'ssl';
            }
        }
        
        if (!$phpmailer_exists) {
            // Use SimpleMailer as fallback
            require_once '../../includes/SimpleMailer.php';
            $mail = new SimpleMailer($settings);
            
            try {
                // Try to send a simple test email first
                $result = $mail->send($test_email, $test_subject, $html_message);
                
                if ($result) {
                    $success_message = 'تم إرسال بريد الاختبار بنجاح إلى ' . $test_email . ' (باستخدام SimpleMailer)';
                    
                    // Store debug info if debug mode is enabled
                    if ($debug_mode) {
                        $_SESSION['smtp_debug_info'] = 'تم الإرسال بنجاح باستخدام SimpleMailer';
                    }
                    
                    // Try to send a template email with dummy data
                    try {
                        $mail->sendWithTemplate(
                            $test_email, 
                            'اختبار قالب البريد #' . $dummy_order['order_number'],
                            '../../templates/email/order_new.php',
                            [
                                'order' => $dummy_order,
                                'customer' => $dummy_customer,
                                'items' => $dummy_items,
                                'trackingUrl' => '#'
                            ]
                        );
                        
                        $success_message .= ' وتم إرسال قالب الطلب الجديد بنجاح';
                    } catch (Exception $e) {
                        // Just log the error, don't show it to the user since the simple email worked
                        if ($debug_mode) {
                            $_SESSION['smtp_debug_info'] .= "\n\nملاحظة: نجح إرسال البريد البسيط لكن فشل إرسال قالب البريد: " . $e->getMessage();
                        }
                    }
                } else {
                    $error_message = 'فشل إرسال بريد الاختبار: ' . $mail->getErrorInfo();
                    
                    // Store debug info if debug mode is enabled
                    if ($debug_mode) {
                        $_SESSION['smtp_debug_info'] = $mail->getErrorInfo();
                    }
                }
            } catch (Exception $e) {
                $error_message = 'فشل إرسال بريد الاختبار: ' . $e->getMessage();
            }
        } else {
            // Use PHPMailer
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            try {
                // Enable debug mode if requested
                if ($debug_mode) {
                    $mail->SMTPDebug = 2; // 2 = client and server messages
                    ob_start(); // Start output buffering to capture debug output
                }
                
                // Server settings
                $mail->isSMTP();
                $mail->Host = $settings['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $settings['smtp_username'];
                $mail->Password = $settings['smtp_password'];
                $mail->SMTPSecure = $settings['smtp_encryption'] == 'none' ? false : $settings['smtp_encryption'];
                $mail->Port = $settings['smtp_port'];
                $mail->CharSet = 'UTF-8';
                
                // Recipients
                $mail->setFrom($settings['from_email'], $settings['from_name']);
                $mail->addAddress($test_email);
                
                // Content
                $mail->isHTML(true);
                $mail->Subject = $test_subject;
                $mail->Body = $html_message;
                
                $mail->send();
                $success_message = 'تم إرسال بريد الاختبار بنجاح إلى ' . $test_email;
                
                // Store debug info if debug mode is enabled
                if ($debug_mode) {
                    $_SESSION['smtp_debug_info'] = ob_get_clean();
                }
            } catch (Exception $e) {
                // Capture debug output if debug mode is enabled
                if ($debug_mode) {
                    $debug_output = ob_get_clean();
                    $_SESSION['smtp_debug_info'] = $debug_output . "\n\nError: " . $mail->ErrorInfo;
                }
                
                $error_message = 'فشل إرسال بريد الاختبار: ' . $mail->ErrorInfo;
            }
        } // Close the else statement from PHPMailer check
    }
}

include '../../includes/header.php';
?>

<style>
    .case-highlight { 
        border: 2px solid #111827; 
        border-radius: 0.75rem; 
        padding: 1rem; 
        position: relative; 
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إعدادات البريد الإلكتروني</h1>
                        <p class="text-gray-600 mt-1">إعداد خادم SMTP لإرسال الإشعارات عبر البريد الإلكتروني</p>
                    </div>
                    <div>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة إلى الإعدادات
                        </a>
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
        
        <!-- Settings Form -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">إعدادات SMTP</h2>
            </div>
            
            <form method="POST" class="p-6 space-y-6">
                <div class="case-highlight">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="smtp_host" class="block text-sm font-medium text-gray-700 mb-1">خادم SMTP *</label>
                            <input type="text" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>" class="form-input" required placeholder="مثال: smtp.gmail.com">
                            <p class="text-xs text-gray-500 mt-1">عنوان خادم البريد الإلكتروني الصادر</p>
                        </div>
                        
                        <div>
                            <label for="smtp_port" class="block text-sm font-medium text-gray-700 mb-1">منفذ SMTP *</label>
                            <input type="number" id="smtp_port" name="smtp_port" value="<?php echo $settings['smtp_port'] ?? 587; ?>" class="form-input" required min="1" max="65535">
                            <p class="text-xs text-gray-500 mt-1">عادة ما يكون 587 (TLS) أو 465 (SSL)</p>
                        </div>
                        
                        <div>
                            <label for="smtp_encryption" class="block text-sm font-medium text-gray-700 mb-1">تشفير SMTP</label>
                            <select id="smtp_encryption" name="smtp_encryption" class="form-input">
                                <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo ($settings['smtp_encryption'] ?? '') == 'none' ? 'selected' : ''; ?>>بدون تشفير</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">نوع التشفير المستخدم للاتصال</p>
                        </div>
                        
                        <div>
                            <label for="smtp_username" class="block text-sm font-medium text-gray-700 mb-1">اسم المستخدم SMTP *</label>
                            <input type="text" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>" class="form-input" required placeholder="مثال: your-email@gmail.com">
                            <p class="text-xs text-gray-500 mt-1">عادة ما يكون عنوان البريد الإلكتروني الكامل</p>
                        </div>
                        
                        <div>
                            <label for="smtp_password" class="block text-sm font-medium text-gray-700 mb-1">كلمة المرور SMTP <?php echo $settings ? '(اتركها فارغة للإبقاء على الحالية)' : '*'; ?></label>
                            <input type="password" id="smtp_password" name="smtp_password" class="form-input" <?php echo $settings ? '' : 'required'; ?>>
                            <p class="text-xs text-gray-500 mt-1">كلمة المرور أو مفتاح التطبيق للحساب</p>
                        </div>
                        
                        <div>
                            <label for="from_email" class="block text-sm font-medium text-gray-700 mb-1">البريد الإلكتروني للمرسل *</label>
                            <input type="email" id="from_email" name="from_email" value="<?php echo htmlspecialchars($settings['from_email'] ?? ''); ?>" class="form-input" required placeholder="مثال: notifications@yourdomain.com">
                            <p class="text-xs text-gray-500 mt-1">عنوان البريد الإلكتروني الذي سيظهر كمرسل</p>
                        </div>
                        
                        <div>
                            <label for="from_name" class="block text-sm font-medium text-gray-700 mb-1">اسم المرسل *</label>
                            <input type="text" id="from_name" name="from_name" value="<?php echo htmlspecialchars($settings['from_name'] ?? ''); ?>" class="form-input" required placeholder="مثال: نظام يمان للإشعارات">
                            <p class="text-xs text-gray-500 mt-1">الاسم الذي سيظهر كمرسل للبريد الإلكتروني</p>
                        </div>
                        
                        <div class="md:col-span-2">
                            <div class="flex items-center">
                                <input type="checkbox" id="is_active" name="is_active" class="ml-2" <?php echo ($settings['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                <label for="is_active" class="text-sm text-gray-700">تفعيل إرسال البريد الإلكتروني</label>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">قم بإلغاء التحديد لتعطيل إرسال البريد الإلكتروني مؤقتاً</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                        <i class="fas fa-save ml-2"></i>
                        حفظ الإعدادات
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Test Email -->
        <div class="bg-white shadow rounded-lg overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">اختبار الإعدادات</h2>
            </div>
            
            <form method="POST" class="p-6">
                <div class="case-highlight">
                    <div class="mb-4">
                        <label for="test_email_address" class="block text-sm font-medium text-gray-700 mb-1">إرسال بريد اختبار إلى</label>
                        <input type="email" id="test_email_address" name="test_email_address" class="form-input" required placeholder="أدخل عنوان البريد الإلكتروني للاختبار">
                    </div>
                    
                    <div class="mb-4">
                        <label for="test_subject" class="block text-sm font-medium text-gray-700 mb-1">عنوان الرسالة</label>
                        <input type="text" id="test_subject" name="test_subject" class="form-input" value="اختبار إعدادات SMTP" placeholder="عنوان رسالة الاختبار">
                    </div>
                    
                    <div class="mb-4">
                        <label for="test_message" class="block text-sm font-medium text-gray-700 mb-1">محتوى الرسالة</label>
                        <textarea id="test_message" name="test_message" rows="5" class="form-input">هذا اختبار لإعدادات SMTP. إذا وصلتك هذه الرسالة، فهذا يعني أن الإعدادات صحيحة.</textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="debug_mode" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 ml-2">
                            <span class="text-sm text-gray-700">وضع تصحيح الأخطاء (إظهار تفاصيل الاتصال بخادم SMTP)</span>
                        </label>
                    </div>
                    
                    <div class="flex justify-end space-x-2 space-x-reverse">
                        <button type="submit" name="test_email" value="1" class="px-6 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                            <i class="fas fa-paper-plane ml-2"></i>
                            إرسال بريد اختبار
                        </button>
                        
                        <button type="button" onclick="testConnection()" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-plug ml-2"></i>
                            اختبار الاتصال فقط
                        </button>
                    </div>
                    
                    <div id="connection_result" class="mt-4 hidden">
                        <!-- Connection test results will appear here -->
                    </div>
                </div>
            </form>
            
            <?php if (isset($_SESSION['smtp_debug_info']) && !empty($_SESSION['smtp_debug_info'])): ?>
            <div class="mt-4 case-highlight bg-gray-50">
                <h3 class="text-md font-medium text-gray-900 mb-2">معلومات تصحيح الأخطاء SMTP</h3>
                <pre class="text-xs bg-gray-100 p-3 rounded-lg overflow-auto max-h-96 whitespace-pre-wrap" dir="ltr"><?php echo htmlspecialchars($_SESSION['smtp_debug_info']); ?></pre>
                
                <div class="mt-4 text-sm text-gray-600">
                    <p><strong>ملاحظات للمطورين:</strong></p>
                    <ul class="list-disc list-inside mt-2 space-y-1">
                        <li>تأكد من أن خادم SMTP يسمح بالاتصال من عنوان IP الخاص بك</li>
                        <li>تحقق من صحة اسم المستخدم وكلمة المرور</li>
                        <li>تأكد من أن المنفذ الصحيح مستخدم (25، 465، 587)</li>
                        <li>تحقق من إعدادات التشفير (TLS/SSL)</li>
                    </ul>
                </div>
            </div>
            <?php unset($_SESSION['smtp_debug_info']); endif; ?>
        </div>
        
        <!-- Email Templates Management -->
        <div class="bg-white shadow rounded-lg overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">قوالب البريد الإلكتروني</h2>
            </div>
            
            <div class="p-6">
                <div class="case-highlight">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-md font-medium text-gray-900 mb-2">القوالب الأساسية</h3>
                            <ul class="list-disc list-inside space-y-1 mb-4">
                                <li>إشعار بطلب جديد</li>
                                <li>تحديث حالة الطلب</li>
                                <li>إرسال فاتورة</li>
                            </ul>
                        </div>
                        
                        <div>
                            <h3 class="text-md font-medium text-gray-900 mb-2">مميزات القوالب</h3>
                            <ul class="list-disc list-inside space-y-1 mb-4">
                                <li>تصميم متجاوب يعمل على جميع الأجهزة</li>
                                <li>دعم كامل للغة العربية والاتجاه RTL</li>
                                <li>إمكانية تخصيص HTML و CSS</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="flex justify-between items-center mt-4">
                        <p class="text-sm text-gray-600">يمكنك إنشاء وتعديل قوالب البريد الإلكتروني من خلال محرر HTML مخصص</p>
                        
                        <a href="email_templates.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-edit ml-2"></i>
                            إدارة قوالب البريد الإلكتروني
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SMTP Troubleshooting -->
        <div class="bg-white shadow rounded-lg overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">استكشاف الأخطاء وإصلاحها</h2>
            </div>
            
            <div class="p-6">
                <div class="case-highlight">
                    <h3 class="text-md font-medium text-gray-900 mb-3">مشاكل شائعة</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-800">خطأ في الاتصال بخادم SMTP</h4>
                            <p class="text-sm text-gray-600">تأكد من صحة عنوان الخادم والمنفذ، وأن الخادم يسمح بالاتصال من عنوان IP الخاص بك.</p>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-semibold text-gray-800">خطأ في المصادقة</h4>
                            <p class="text-sm text-gray-600">تأكد من صحة اسم المستخدم وكلمة المرور. قد تحتاج إلى إنشاء كلمة مرور خاصة بالتطبيقات من إعدادات حسابك.</p>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-semibold text-gray-800">مشاكل في التشفير</h4>
                            <p class="text-sm text-gray-600">تأكد من اختيار نوع التشفير المناسب (TLS/SSL) والمنفذ المناسب له.</p>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <button type="button" onclick="showSmtpDebug()" class="flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-bug ml-2"></i>
                            عرض معلومات تصحيح الأخطاء
                        </button>
                        
                        <div id="smtp_debug" class="mt-4 bg-gray-100 p-4 rounded-lg hidden">
                            <pre class="text-xs overflow-auto max-h-60" dir="ltr"><!-- SMTP debug info will appear here --></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function testConnection() {
    // Get SMTP settings from form
    const smtpHost = document.getElementById('smtp_host').value;
    const smtpPort = document.getElementById('smtp_port').value;
    const smtpUser = document.getElementById('smtp_username').value;
    const smtpPass = document.getElementById('smtp_password').value;
    const smtpEncryption = document.getElementById('smtp_encryption').value;
    
    // Validate required fields
    if (!smtpHost || !smtpPort || !smtpUser) {
        showConnectionResult('error', 'يرجى ملء جميع الحقول المطلوبة (الخادم، المنفذ، اسم المستخدم)');
        return;
    }
    
    // Show loading state
    showConnectionResult('loading', 'جاري اختبار الاتصال بخادم SMTP...');
    
    // Create a form data object to send to server
    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('smtp_host', smtpHost);
    formData.append('smtp_port', smtpPort);
    formData.append('smtp_username', smtpUser);
    formData.append('smtp_password', smtpPass || ''); // Empty if not provided
    formData.append('smtp_encryption', smtpEncryption);
    
    // Send AJAX request
    fetch('smtp_test.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showConnectionResult('success', 'تم الاتصال بخادم SMTP بنجاح!', data.debug);
        } else {
            showConnectionResult('error', 'فشل الاتصال بخادم SMTP: ' + data.message, data.debug);
        }
    })
    .catch(error => {
        showConnectionResult('error', 'حدث خطأ أثناء اختبار الاتصال: ' + error.message);
    });
}

function showConnectionResult(type, message, debug = '') {
    const resultDiv = document.getElementById('connection_result');
    resultDiv.classList.remove('hidden');
    
    let bgColor, icon;
    switch (type) {
        case 'success':
            bgColor = 'bg-amber-100 border-amber-400 text-amber-700';
            icon = '<i class="fas fa-check-circle ml-2"></i>';
            break;
        case 'error':
            bgColor = 'bg-red-100 border-red-400 text-red-700';
            icon = '<i class="fas fa-exclamation-circle ml-2"></i>';
            break;
        case 'loading':
            bgColor = 'bg-blue-100 border-blue-400 text-blue-700';
            icon = '<i class="fas fa-spinner fa-spin ml-2"></i>';
            break;
        default:
            bgColor = 'bg-gray-100 border-gray-400 text-gray-700';
            icon = '<i class="fas fa-info-circle ml-2"></i>';
    }
    
    let debugInfo = '';
    if (debug) {
        debugInfo = `
            <div class="mt-3 pt-3 border-t border-gray-200">
                <div class="text-xs font-medium text-gray-700 mb-1">معلومات تصحيح الأخطاء:</div>
                <pre class="text-xs bg-gray-50 p-2 rounded overflow-auto max-h-40" dir="ltr">${debug}</pre>
            </div>
        `;
    }
    
    resultDiv.innerHTML = `
        <div class="${bgColor} border px-4 py-3 rounded-lg">
            ${icon}
            <span>${message}</span>
            ${debugInfo}
        </div>
    `;
}

function showSmtpDebug() {
    const debugDiv = document.getElementById('smtp_debug');
    if (debugDiv.classList.contains('hidden')) {
        debugDiv.classList.remove('hidden');
        
        // Get SMTP settings from form
        const smtpHost = document.getElementById('smtp_host').value;
        const smtpPort = document.getElementById('smtp_port').value;
        const smtpEncryption = document.getElementById('smtp_encryption').value;
        
        // Show debug info
        const debugInfo = `SMTP Server Information:
-----------------------------
Host: ${smtpHost}
Port: ${smtpPort}
Encryption: ${smtpEncryption}

Common SMTP Ports:
- Port 25: Standard SMTP (usually blocked by ISPs)
- Port 465: SMTP with SSL
- Port 587: SMTP with TLS (recommended)
- Port 2525: Alternative SMTP port

Common SMTP Servers:
- Gmail: smtp.gmail.com (requires App Password)
- Outlook/Hotmail: smtp.office365.com
- Yahoo: smtp.mail.yahoo.com
- Zoho: smtp.zoho.com

Troubleshooting Steps:
1. Check if the SMTP server is accessible from your server
2. Verify credentials are correct
3. Check if your email provider requires special settings
4. Make sure your hosting provider allows outgoing SMTP connections
5. Try different encryption methods and ports`;
        
        debugDiv.querySelector('pre').textContent = debugInfo;
    } else {
        debugDiv.classList.add('hidden');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
