<?php

/**
 * Send WhatsApp Message
 * Direct WhatsApp Integration with Template Variables
 */

// CRITICAL FIX: Force PHP's internal number system to a neutral, non-regional format.
setlocale(LC_NUMERIC, 'C');

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/phone_utils.php';
require_once '../../includes/check_permissions.php';

function convertArabicNumeralsToEnglish($string) {
    if ($string === null) return null;
    $arabic_chars  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '٬', ',', '(', ')', ' '];
    $english_chars = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '',  '',  '',  '',  ''];
    return str_replace($arabic_chars, $english_chars, $string);
}

// --- PERMISSION CHECKS ---
$module_key = 'whatsapp'; // Define the module key for this page

// Check for 'view' permission for the whatsapp page itself
if (!hasPermission($_SESSION['user_id'], $module_key, 'view')) {
    $page_title = 'غير مصرح لك بالدخول';
    include '../../includes/header.php';
?>
    <div class="min-h-screen bg-gray-50 py-6" dir="rtl">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-lg rounded-xl p-8 text-center">
                <i class="fas fa-lock text-5xl text-red-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-900">غير مصرح لك بالدخول</h1>
                <p class="text-gray-600 mt-2">ليس لديك الصلاحيات اللازمة لعرض هذه الصفحة. يرجى التواصل مع مسؤول النظام.</p>
                <a href="../dashboard.php" class="mt-6 inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                    العودة للوحة التحكم
                </a>
            </div>
        </div>
    </div>
<?php
    include '../../includes/footer.php';
    exit();
}

// Check if the user has 'edit' permission for whatsapp, which includes sending/copying messages
$canEditWhatsapp = hasPermission($_SESSION['user_id'], $module_key, 'edit');
// Keep isAdmin for potential broader admin-only features not covered by specific module permissions
$isAdmin = (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');


$page_title = 'إرسال رسالة واتساب';

// Get template if specified
$template = null;
if (isset($_GET['template_id'])) {
    $stmt = $db->prepare("SELECT * FROM whatsapp_templates WHERE id = ?");
    $stmt->execute([$_GET['template_id']]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get customer if specified
$customer = null;
if (isset($_GET['customer_id'])) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$_GET['customer_id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (isset($_GET['phone']) && !empty($_GET['phone'])) {
        $customer['whatsapp_number'] = $_GET['phone'];
    }
}

// Get order if specified
$order = null;
if (isset($_GET['order_id'])) {
    $stmt = $db->prepare("
        SELECT 
            o.*, 
            c.name as customer_name, c.whatsapp_number, c.mobile_number, c.portal_token,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id), 0) as total_quantity,
            (SELECT COALESCE(SUM(price), 0) FROM order_damaged_items odi WHERE odi.order_id = o.id) as damaged_amount,
            coup.discount_type, coup.discount_value
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN coupons coup ON o.coupon_id = coup.id
        WHERE o.id = ?
    ");
    $stmt->execute([$_GET['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        // Get clean numbers as floats
        $subtotal_amount = (float)convertArabicNumeralsToEnglish($order['subtotal_amount'] ?? 0);
        $final_amount = (float)convertArabicNumeralsToEnglish($order['final_amount'] ?? 0);
        $paid_amount = (float)convertArabicNumeralsToEnglish($order['paid_amount'] ?? 0);
        $discount_amount = (float)convertArabicNumeralsToEnglish($order['discount_amount'] ?? 0);
        $damaged_amount = (float)convertArabicNumeralsToEnglish($order['damaged_amount'] ?? 0);

        $remaining_amount = $final_amount - $paid_amount;
        
        // Calculate dynamic discount percentage if not already present or from automatic
        $discount_percentage = 0;
        if ($order['coupon_id'] !== null) {
            if ($order['discount_type'] === 'percentage') {
                $discount_percentage = $order['discount_value'];
            } elseif ($order['discount_type'] === 'fixed' && $subtotal_amount > 0.01) {
                $discount_percentage = ($discount_amount / $subtotal_amount) * 100;
            }
        } else {
            $discount_percentage = $order['automatic_discount_percentage'] ?? 0;
        }


        // Create NEW pre-formatted ENGLISH STRINGS to send to JavaScript.
        // Add ' ريال يمني' after the formatted amounts
        $order['gross_total_formatted'] = number_format($subtotal_amount, 0, '.', ',') . ' ريال يمني'; // NEW
        $order['final_amount_formatted'] = number_format($final_amount, 0, '.', ',') . ' ريال يمني';
        $order['paid_amount_formatted'] = number_format($paid_amount, 0, '.', ',') . ' ريال يمني';
        $order['remaining_amount_formatted'] = number_format($remaining_amount, 0, '.', ',') . ' ريال يمني';
        $order['discount_amount_formatted'] = number_format($discount_amount, 0, '.', ',') . ' ريال يمني'; // NEW
        $order['discount_percentage_formatted'] = number_format($discount_percentage, 0, '.', ',') . '%'; // NEW
        $order['damaged_amount_formatted'] = number_format($damaged_amount, 0, '.', ',') . ' ريال يمني'; // NEW

        // Use the total_quantity from the subquery directly
        $order['order_quantity'] = (int)$order['total_quantity'];
    }

    if ($order && !$customer) {
        $customer = [
            'id' => $order['customer_id'],
            'name' => $order['customer_name'],
            'whatsapp_number' => $order['whatsapp_number'],
            'mobile_number' => $order['mobile_number'],
            'portal_token' => $order['portal_token'] ?? null
        ];
    }
}

// FIXED: Build account portal URL logic is restored and correct
$accountPortalUrl = null;
if ($customer && !empty($customer['portal_token'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST']; // Dynamically get the host, e.g., 'yamanstore.org'
    $baseUrl = $scheme . '://' . $host;
    $accountPortalUrl = rtrim($baseUrl, '/') . '/customer_portal/portal.php?token=' . urlencode($customer['portal_token']);
}

// Get templates & customers
$templates = $db->query("SELECT * FROM whatsapp_templates WHERE is_active = 1 ORDER BY category, template_name")->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query("SELECT id, customer_code, name, whatsapp_number, mobile_number FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    .whatsapp-preview {
        background: linear-gradient(135deg, #128c7e 0%, #075e54 100%); border-radius: 20px; padding: 30px; min-height: 500px; position: relative;
    }
    .chat-header {
        background: #075e54; border-radius: 12px 12px 0 0; padding: 15px; margin: -30px -30px 20px -30px; display: flex; align-items: center; gap: 12px;
    }
    .chat-avatar {
        width: 50px; height: 50px; border-radius: 50%; background: #25d366; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: bold;
    }
   .message-bubble {
    background: #dcf8c6;
    border-radius: 12px;
    padding: 12px 16px;
    max-width: 80%;
    margin-bottom: 12px;
    position: relative;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    animation: slideIn 0.3s ease;

    /* --- ADD THESE TWO LINES TO FIX THE URL OVERFLOW --- */
    overflow-wrap: break-word;
    word-wrap: break-word;
}
    @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .message-bubble::after {
        content: ''; position: absolute; right: -8px; top: 0; width: 0; height: 0; border-left: 8px solid #dcf8c6; border-top: 8px solid transparent;
    }
    .message-time {
        font-size: 11px; color: #667781; text-align: left; margin-top: 4px;
    }
    .send-button {
        background: linear-gradient(135deg, #25d366 0%, #128c7e 100%); color: white; padding: 16px 32px; border-radius: 12px; font-size: 18px; font-weight: bold; border: none; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
    }
    .send-button:hover:not(:disabled) {
        transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
    }
    .copy-button {
        background: #6c757d; /* A neutral gray color */
        color: white;
        padding: 16px 32px;
        border-radius: 12px;
        font-size: 18px;
        font-weight: bold;
        border: none;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        margin-top: 10px; /* Add some spacing between buttons */
    }
    .copy-button:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }
    .variable-chip {
        display: inline-block; background: #e3f2fd; color: #1976d2; padding: 4px 10px; border-radius: 16px; font-size: 12px; font-weight: 600; margin: 2px; cursor: pointer; transition: all 0.2s;
    }
    .variable-chip:hover {
        background: #1976d2; color: white;
    }
</style>

<div class="container mx-auto px-4 py-8" style="max-width: 1400px;">
    <div class="bg-gradient-to-r from-amber-600 via-green-700 to-amber-800 rounded-2xl shadow-2xl p-10 mb-8">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-4xl font-black text-white flex items-center gap-4"><i class="fab fa-whatsapp"></i> إرسال رسالة واتساب</h1>
                <p class="text-amber-100 mt-3 text-lg">إرسال مباشر إلى واتساب مع الرسالة جاهزة</p>
            </div>
            <?php if ($isAdmin): // Assuming 'Templates' button is an admin-level feature ?>
                <a href="templates.php" class="bg-white text-amber-600 px-6 py-3 rounded-xl font-bold hover:bg-amber-50 transition-all shadow-lg"><i class="fas fa-list ml-2"></i> القوالب</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Left Side: Form -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6"><i class="fas fa-edit ml-2"></i> تخصيص الرسالة</h2>
            <form id="whatsappForm">
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2"><i class="fas fa-user ml-1"></i> اختر العميل *</label>
                    <select id="customerId" class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none" required>
                        <option value="">-- اختر العميل --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>" data-name="<?php echo htmlspecialchars($cust['name']); ?>" data-phone="<?php echo htmlspecialchars($cust['whatsapp_number'] ?: $cust['mobile_number']); ?>" <?php echo ($customer && $customer['id'] == $cust['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cust['name']); ?> (<?php echo htmlspecialchars($cust['customer_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- WhatsApp number field removed from display, but its value is still accessible via JavaScript -->
                <input type="hidden" id="phoneNumber" value="<?php echo $customer ? htmlspecialchars($customer['whatsapp_number'] ?: $customer['mobile_number']) : ''; ?>">
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2"><i class="fas fa-file-alt ml-1"></i> اختر قالب (اختياري)</label>
                    <select id="templateId" class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none">
                        <option value="">-- بدون قالب --</option>
                        <?php
                        $currentCategory = '';
                        foreach ($templates as $tmpl):
                            if ($tmpl['category'] != $currentCategory) {
                                if ($currentCategory != '') echo '</optgroup>';
                                $categories = ['order' => 'طلبات', 'payment' => 'مدفوعات', 'shipping' => 'شحن', 'general' => 'عام'];
                                echo '<optgroup label="' . htmlspecialchars($categories[$tmpl['category']] ?? $tmpl['category']) . '">';
                                $currentCategory = $tmpl['category'];
                            }
                        ?>
                            <option value="<?php echo $tmpl['id']; ?>" data-content="<?php echo htmlspecialchars($tmpl['template_content']); ?>" <?php echo ($template && $template['id'] == $tmpl['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tmpl['template_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentCategory != '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <?php if ($canEditWhatsapp): // Show "المتغيرات المتاحة" only if user can edit/send whatsapp messages ?>
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2"><i class="fas fa-code ml-1"></i> المتغيرات المتاحة</label>
                    <div class="flex flex-wrap gap-2">
                        <!-- FIXED: Restored account_portal variable chip -->
                        <span class="variable-chip" onclick="insertVariable('{customer_name}')">{customer_name}</span>
                        <span class="variable-chip" onclick="insertVariable('{order_number}')">{order_number}</span>
                        <span class="variable-chip" onclick="insertVariable('{order_total}')">{order_total}</span>
                        <span class="variable-chip" onclick="insertVariable('{remaining_amount}')">{remaining_amount}</span>
                        <span class="variable-chip" onclick="insertVariable('{company_name}')">{company_name}</span>
                        <span class="variable-chip" onclick="insertVariable('{account_portal}')">{account_portal}</span>
                        <!-- NEW VARIABLES HERE -->
                        <span class="variable-chip" onclick="insertVariable('{gross_total}')">{gross_total}</span>
                        <span class="variable-chip" onclick="insertVariable('{discount_amount}')">{discount_amount}</span>
                        <span class="variable-chip" onclick="insertVariable('{discount_percentage}')">{discount_percentage}</span>
                        <span class="variable-chip" onclick="insertVariable('{damaged_amount}')">{damaged_amount}</span>
                        <span class="variable-chip" onclick="insertVariable('{order_link}')">{order_link}</span>
                        <span class="variable-chip" onclick="insertVariable('{additional_link}')">{additional_link}</span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2"><i class="fas fa-comment-alt ml-1"></i> نص الرسالة *</label>
                    <textarea id="messageContent" rows="8" required class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none" placeholder="اكتب رسالتك هنا..." <?= $canEditWhatsapp ? '' : 'readonly' ?>><?php echo $template ? htmlspecialchars($template['template_content']) : ''; ?></textarea>
                </div>
                <?php if ($canEditWhatsapp): ?>
                    <button type="button" onclick="sendToWhatsApp()" class="w-full send-button">
                        <i class="fab fa-whatsapp ml-2 text-2xl"></i> إرسال
                    </button>
                    <button type="button" onclick="copyMessage()" class="w-full copy-button">
                        <i class="fas fa-copy ml-2 text-2xl"></i> نسخ
                    </button>
                <?php else: ?>
                    <button type="button" class="w-full send-button" style="background: #9ca3af; cursor: not-allowed;" disabled>
                        <i class="fas fa-lock ml-2 text-2xl"></i> لا تملك صلاحية الإرسال
                    </button>
                    <button type="button" class="w-full copy-button" style="background: #9ca3af; cursor: not-allowed;" disabled>
                        <i class="fas fa-lock ml-2 text-2xl"></i> لا تملك صلاحية النسخ
                    </button>
                <?php endif; ?>
            </form>
        </div>
        <!-- Right Side: Preview -->
        <?php if ($canEditWhatsapp): // Show preview only if user can edit/send whatsapp messages ?>
        <div class="whatsapp-preview">
            <div class="chat-header">
                <div class="chat-avatar" id="avatarInitial"><i class="fas fa-user"></i></div>
                <div>
                    <h3 class="text-white font-bold text-lg" id="previewName">اسم العميل</h3>
                    <!-- Display order number in the preview header -->
                    <p class="text-amber-200 text-sm" id="previewOrderNumber"></p>
                </div>
            </div>
            <div class="message-bubble">
                <div id="previewContent">اكتب رسالتك لرؤية المعاينة...</div>
                <div class="message-time"><?php echo date('H:i'); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const orderData = <?php echo $order ? json_encode($order, JSON_UNESCAPED_UNICODE) : 'null'; ?>;
    const accountPortalUrl = <?php echo json_encode($accountPortalUrl, JSON_UNESCAPED_UNICODE); ?>;
    const canEditWhatsapp = <?php echo json_encode($canEditWhatsapp); ?>; // Pass permission status to JavaScript

    function replaceVariable(message, key, value) {
        if (value === null || typeof value === 'undefined' || value === '') return message; // Also handle empty string
        const pattern = new RegExp('\\{\\s*' + key + '\\s*\\}', 'gi');
        return message.replace(pattern, value);
    }

    function getFormattedMessage() {
        let message = document.getElementById('messageContent').value;
        const customerSelect = document.getElementById('customerId');
        const selectedOption = customerSelect.options[customerSelect.selectedIndex];
        const customerName = selectedOption ? selectedOption.dataset.name : 'العميل';

        message = replaceVariable(message, 'customer_name', customerName);

        if (orderData) {
            message = replaceVariable(message, 'order_number', orderData.order_number);
            message = replaceVariable(message, 'order_total', orderData.final_amount_formatted || '0 ريال يمني');
            message = replaceVariable(message, 'remaining_amount', orderData.remaining_amount_formatted || '0 ريال يمني');
            message = replaceVariable(message, 'payment_amount', orderData.paid_amount_formatted || '0 ريال يمني');
            // NEW VARIABLES REPLACEMENT
            message = replaceVariable(message, 'gross_total', orderData.gross_total_formatted || '0 ريال يمني');
            message = replaceVariable(message, 'discount_amount', orderData.discount_amount_formatted || '0 ريال يمني');
            message = replaceVariable(message, 'discount_percentage', orderData.discount_percentage_formatted || '0%');
            message = replaceVariable(message, 'damaged_amount', orderData.damaged_amount_formatted || '0 ريال يمني');
            // NEW LINK VARIABLES
            message = replaceVariable(message, 'order_link', orderData.order_link || '');
            message = replaceVariable(message, 'additional_link', orderData.additional_link || '');
        }

        const portalText = accountPortalUrl || 'رابط حسابك في البوابة غير متوفر';
        message = replaceVariable(message, 'account_portal', portalText);
        message = replaceVariable(message, 'company_name', 'شركة يمان'); // Placeholder for company name

        return message.replace(/\{\s*[\w-]+\s*\}/g, ''); // Remove any remaining unreplaced variables
    }

    function updatePreview() {
        if (canEditWhatsapp) { // Update preview only if user can edit/send
            document.getElementById('previewContent').innerHTML = getFormattedMessage().replace(/\n/g, '<br>');
        }
    }
    
    function sendToWhatsApp() {
        if (!canEditWhatsapp) {
            alert('ليس لديك صلاحية لإرسال رسائل الواتساب.');
            return;
        }

        const customerId = document.getElementById('customerId').value;
        const phone = document.getElementById('phoneNumber').value; 
        
        if (!customerId) {
            alert('يرجى اختيار العميل');
            return;
        }

        const message = getFormattedMessage();
        
        if (!message.trim()) {
            alert('يرجى كتابة الرسالة');
            return;
        }

        let cleanPhone = phone.replace(/[^0-9]/g, '');
        if (!cleanPhone.startsWith('967')) {
             if (cleanPhone.startsWith('0')) {
                cleanPhone = '967' + cleanPhone.substring(1);
            } else {
                cleanPhone = '967' + cleanPhone;
            }
        }

        const whatsappUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`;
        window.open(whatsappUrl, '_blank');
    }

    function copyMessage() {
        if (!canEditWhatsapp) {
            alert('ليس لديك صلاحية لنسخ رسائل الواتساب.');
            return;
        }

        const message = getFormattedMessage();

        if (!message.trim()) {
            alert('لا توجد رسالة لنسخها.');
            return;
        }

        navigator.clipboard.writeText(message).then(function() {
            alert('تم نسخ الرسالة إلى الحافظة.');
        }).catch(function(err) {
            console.error('Could not copy text: ', err);
            alert('فشل نسخ الرسالة إلى الحافظة. يرجى النسخ يدوياً.');
        });
    }

    function insertVariable(variable) {
        if (!canEditWhatsapp) {
            alert('ليس لديك صلاحية لإضافة متغيرات للرسالة.');
            return;
        }
        const textarea = document.getElementById('messageContent');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        textarea.value = textarea.value.substring(0, start) + variable + textarea.value.substring(end);
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + variable.length;
        updatePreview();
    }
    
    document.getElementById('customerId').addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option && option.value) {
            const name = option.dataset.name || 'اسم العميل';
            const phone = option.dataset.phone || '';
            const orderNumber = orderData ? orderData.order_number : '';

            if (canEditWhatsapp) { // Update preview only if user can edit/send
                document.getElementById('previewName').textContent = name;
                document.getElementById('previewOrderNumber').textContent = orderNumber ? `طلب رقم: ${orderNumber}` : '';
                document.getElementById('avatarInitial').textContent = name.charAt(0).toUpperCase();
            }
            document.getElementById('phoneNumber').value = phone;
        } else {
            // Reset if no customer is selected
            if (canEditWhatsapp) { // Update preview only if user can edit/send
                document.getElementById('previewName').textContent = 'اسم العميل';
                document.getElementById('previewOrderNumber').textContent = ''; // Clear order number
                document.getElementById('avatarInitial').innerHTML = '<i class="fas fa-user"></i>';
            }
            document.getElementById('phoneNumber').value = '';
        }
        updatePreview();
    });

    document.getElementById('templateId').addEventListener('change', function() {
        if (canEditWhatsapp) { // Only update messageContent if user has edit permission
            const option = this.options[this.selectedIndex];
            if(option) {
                document.getElementById('messageContent').value = option.dataset.content || '';
            }
            updatePreview();
        } else {
            alert('ليس لديك صلاحية لتغيير محتوى الرسالة.');
        }
    });

    // Only add input listener for message content if user can edit
    if (canEditWhatsapp) {
        document.getElementById('messageContent').addEventListener('input', updatePreview);
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('customerId').dispatchEvent(new Event('change'));
    });
</script>

<?php include '../../includes/footer.php'; ?>