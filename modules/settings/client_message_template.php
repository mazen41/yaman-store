<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php'; // Include the permissions file

// Define the module key for this specific page/feature
$module_key = 'whatsapp_templates_admin_notification'; // A specific key for this template page

// Check if the user has permission to view this page
if (!hasPermission($_SESSION['user_id'], $module_key, 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى صفحة إعداد قالب رسالة الواتساب للإدارة.';
    header('Location: ../dashboard.php'); // Redirect to dashboard or a suitable page
    exit();
}

// Check if the user has permission to edit (save) this template
$canEditWhatsappTemplate = hasPermission($_SESSION['user_id'], $module_key, 'edit');


$page_title = 'إعداد قالب رسالة الواتساب';
$success_message = '';
$error_message = '';

// Define the fixed template details
$fixed_template_name = 'إشعار طلب جديد للإدارة';
$fixed_target_event = 'order_created_admin';

// Fetch THE template for editing
$stmt = $db->prepare("SELECT * FROM whatsapp_template WHERE target_event = ? LIMIT 1");
$stmt->execute([$fixed_target_event]);
$template = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission for updating the single template
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Permission check for saving the template
    if (!$canEditWhatsappTemplate) {
        $_SESSION['error_message'] = 'ليس لديك صلاحية لحفظ تعديلات قالب رسالة الواتساب.';
        header('Location: admin_template.php'); // Redirect back to prevent re-submission and show error
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
            // Insert new template if it doesn't exist (should only happen once)
            $stmt = $db->prepare("INSERT INTO whatsapp_template (name, message_content, target_event, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fixed_template_name, $message_content, $fixed_target_event, $is_active]);
        }
        
        $success_message = 'تم حفظ القالب بنجاح.';
        // Re-fetch template to show updated status immediately
        $stmt = $db->prepare("SELECT * FROM whatsapp_template WHERE target_event = ? LIMIT 1");
        $stmt->execute([$fixed_target_event]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $error_message = 'خطأ: ' . $e->getMessage();
    }
}

include '../../includes/header.php'; // تأكد من أن هذا المسار صحيح
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">قم بتعديل الرسالة التي ترسلها للشركة بعد طلب العميل.</p>
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
                    تعديل قالب إشعار طلب جديد للإدارة
                </h2>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <!-- Fixed Hidden Fields -->
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($fixed_template_name); ?>">
                <input type="hidden" name="target_event" value="<?php echo htmlspecialchars($fixed_target_event); ?>">

                <div>
                    <label for="message_content" class="block text-sm font-medium text-gray-700 mb-1">محتوى الرسالة</label>
                    <textarea id="message_content" name="message_content" rows="10" class="w-full px-3 py-2 border border-gray-300 rounded-lg" required <?= $canEditWhatsappTemplate ? '' : 'readonly' ?>><?php echo htmlspecialchars($template['message_content'] ?? ''); ?></textarea>
                    <p class="text-xs text-gray-500 mt-2">
                        يمكنك استخدام المتغيرات التالية التي سيتم استبدالها ببيانات الطلب الفعلية: <br>
                        <code>{{customer-name}}</code>, <code>{{approval-id}}</code>, <code>{{total-amount}}</code>, 
                        <code>{{paid-amount}}</code>, <code>{{remaining-amount}}</code>, <code>{{currency}}</code>.
                    </p>
                </div>

                <div class="flex items-center">
                    <input type="checkbox" id="is_active" name="is_active" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500" 
                           <?php echo ($template && $template['is_active']) ? 'checked' : ''; ?> <?= $canEditWhatsappTemplate ? '' : 'disabled' ?>>
                    <label for="is_active" class="mr-2 text-sm font-medium text-gray-700">تفعيل هذا القالب (سيتم استخدام هذه الرسالة عند إرسال طلب جديد)</label>
                </div>

                <div class="flex justify-end space-x-4">
                    <a href="../dashboard.php" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">العودة للوحة التحكم</a>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700" <?= $canEditWhatsappTemplate ? '' : 'disabled' ?>>
                        <i class="fas fa-save ml-2"></i>
                        حفظ القالب
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; // تأكد من أن هذا المسار صحيح ?>