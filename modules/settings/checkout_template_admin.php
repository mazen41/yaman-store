<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php'; // Include the permissions file

// Define the module key for this specific page/feature
$module_key = 'whatsapp_templates_checkout_admin'; // مفتاح صلاحية جديد خاص بصفحة الدفع

// Check if the user has permission to view this page
if (!hasPermission($_SESSION['user_id'], $module_key, 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى صفحة إعداد قالب رسالة الدفع (Checkout).';
    header('Location: ../dashboard.php'); 
    exit();
}

// Check if the user has permission to edit (save) this template
$canEditWhatsappTemplate = hasPermission($_SESSION['user_id'], $module_key, 'edit');


$page_title = 'إعداد قالب إشعار طلبات البوابة (Checkout)';
$success_message = '';
$error_message = '';

// Define the fixed template details
$fixed_template_name = 'إشعار طلب جديد من البوابة (Checkout)';
// قمنا بتغيير هذا الحدث ليكون خاص بصفحة الشيك اوت
$fixed_target_event = 'checkout_order_created_admin'; 

// Fetch THE template for editing
$stmt = $db->prepare("SELECT * FROM whatsapp_template WHERE target_event = ? LIMIT 1");
$stmt->execute([$fixed_target_event]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission for updating the single template
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$canEditWhatsappTemplate) {
        $_SESSION['error_message'] = 'ليس لديك صلاحية لحفظ التعديلات.';
        header('Location: checkout_template_admin.php'); 
        exit();
    }

    try {
        $message_content = trim($_POST['message_content']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($message_content)) {
            throw new Exception('محتوى الرسالة إلزامي.');
        }

        if ($template) {
            // Update existing template
            $stmt = $db->prepare("UPDATE whatsapp_template SET message_content = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$message_content, $is_active, $template['id']]);
        } else {
            // Insert new template if it doesn't exist
            $stmt = $db->prepare("INSERT INTO whatsapp_template (name, message_content, target_event, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fixed_template_name, $message_content, $fixed_target_event, $is_active]);
        }
        
        $success_message = 'تم حفظ قالب البوابة (Checkout) بنجاح.';
        
        $stmt = $db->prepare("SELECT * FROM whatsapp_template WHERE target_event = ? LIMIT 1");
        $stmt->execute([$fixed_target_event]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error_message = 'خطأ: ' . $e->getMessage();
    }
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
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">قم بتعديل الرسالة التي يرسلها العميل للإدارة عبر الواتساب عند إتمام طلب من صفحة البوابة.</p>
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

        <!-- Edit Template Form -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-edit ml-2"></i>
                    تعديل قالب رسالة تأكيد الدفع
                </h2>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <!-- Fixed Hidden Fields -->
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($fixed_template_name); ?>">
                <input type="hidden" name="target_event" value="<?php echo htmlspecialchars($fixed_target_event); ?>">

                <div>
                    <label for="message_content" class="block text-sm font-medium text-gray-700 mb-1">محتوى الرسالة</label>
                    <textarea id="message_content" name="message_content" rows="10" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500" required <?= $canEditWhatsappTemplate ? '' : 'readonly' ?>><?php 
                        // Default template text if empty
                        $default_text = "مرحباً،\nلدي طلب جديد من البوابة.\n\n*الاسم:* {{customer-name}}\n*رقم الطلب:* {{order-number}}\n*الإجمالي:* {{total-amount}} {{currency}}\n\nيرجى مراجعة واعتماد الطلب في النظام.";
                        echo htmlspecialchars($template['message_content'] ?? $default_text); 
                    ?></textarea>
                    
                    <div class="bg-blue-50 p-4 rounded-lg mt-3 border border-blue-100">
                        <p class="text-sm text-blue-800 font-bold mb-2">
                            <i class="fas fa-info-circle ml-1"></i> المتغيرات المتاحة:
                        </p>
                        <p class="text-xs text-gray-700 leading-relaxed">
                            سيتم استبدال هذه الأكواد تلقائياً ببيانات الطلب عند الإرسال:<br>
                            <span class="inline-block bg-white border border-gray-200 rounded px-2 py-1 mt-1 font-mono text-blue-600 cursor-copy" onclick="navigator.clipboard.writeText('{{customer-name}}');"><code>{{customer-name}}</code></span> : اسم العميل<br>
                            <span class="inline-block bg-white border border-gray-200 rounded px-2 py-1 mt-1 font-mono text-blue-600 cursor-copy" onclick="navigator.clipboard.writeText('{{order-number}}');"><code>{{order-number}}</code></span> : رقم الطلب المُنشأ<br>
                            <span class="inline-block bg-white border border-gray-200 rounded px-2 py-1 mt-1 font-mono text-blue-600 cursor-copy" onclick="navigator.clipboard.writeText('{{total-amount}}');"><code>{{total-amount}}</code></span> : المبلغ الإجمالي المطلوب دفعه<br>
                            <span class="inline-block bg-white border border-gray-200 rounded px-2 py-1 mt-1 font-mono text-blue-600 cursor-copy" onclick="navigator.clipboard.writeText('{{currency}}');"><code>{{currency}}</code></span> : العملة المعتمدة
                        </p>
                    </div>
                </div>

                <div class="flex items-center bg-gray-50 p-3 rounded-lg border border-gray-200">
                    <input type="checkbox" id="is_active" name="is_active" class="rounded border-gray-300 w-5 h-5 text-blue-600 shadow-sm focus:ring-blue-500" 
                           <?php echo (!isset($template['is_active']) || $template['is_active']) ? 'checked' : ''; ?> <?= $canEditWhatsappTemplate ? '' : 'disabled' ?>>
                    <label for="is_active" class="mr-3 text-sm font-bold text-gray-700">تفعيل هذا القالب (إذا تم الإلغاء، سيتم إرسال رسالة افتراضية)</label>
                </div>

                <div class="flex justify-end space-x-4 pt-4">
                    <a href="../dashboard.php" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition">العودة للوحة التحكم</a>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-bold shadow" <?= $canEditWhatsappTemplate ? '' : 'disabled' ?>>
                        <i class="fas fa-save ml-2"></i>
                        حفظ القالب
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>