<?php

/**
 * Send WhatsApp Message
 * Direct WhatsApp Integration with Template Variables
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/phone_utils.php';
require_once '../../includes/check_permissions.php';

// --- PERMISSION CHECK: Main page access ---
// Check if the user has permission to view the WhatsApp module at all.
if (!canView($_SESSION['user_id'], 'whatsapp')) {
    $page_title = 'غير مصرح لك بالدخول';
    include '../../includes/header.php';
?>
    <div class="min-h-screen bg-gray-50 py-6" dir="rtl">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white shadow-lg rounded-xl p-8 text-center">
                <i class="fas fa-lock text-5xl text-red-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-900">غير مصرح لك بالدخول</h1>
                <p class="text-gray-600 mt-2">ليس لديك الصلاحيات اللازمة لعرض هذه الصفحة. يرجى التواصل مع مسؤول النظام.</p>
                <a href="../dashboard/index.php" class="mt-6 inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                    العودة للوحة التحكم
                </a>
            </div>
        </div>
    </div>
<?php
    include '../../includes/footer.php';
    exit(); // Stop script execution
}

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

    // Override phone number if provided in URL
    if (isset($_GET['phone']) && !empty($_GET['phone'])) {
        $customer['whatsapp_number'] = $_GET['phone'];
    }
    // Override customer name if provided in URL
    if (isset($_GET['customer_name']) && !empty($_GET['customer_name'])) {
        $customer['name'] = $_GET['customer_name'];
    }
}

// Get order if specified
$order = null;
if (isset($_GET['order_id'])) {
    $stmt = $db->prepare("
        SELECT o.*, c.name as customer_name, c.whatsapp_number, c.mobile_number, c.portal_token
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$_GET['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        // Normalize paid and remaining amounts for templates
        $order['paid_amount'] = isset($order['paid_amount']) ? (float)$order['paid_amount'] : 0.0;
        $order['remaining_amount'] = isset($order['remaining_amount'])
            ? (float)$order['remaining_amount']
            : ((float)($order['final_amount'] ?? 0) - $order['paid_amount']);

        // Calculate total order quantity from order_items
        try {
            $qty_stmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = ?");
            $qty_stmt->execute([$order['id']]);
            $order['order_quantity'] = (int)$qty_stmt->fetchColumn();
        } catch (PDOException $e) {
            $order['order_quantity'] = 0;
        }
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

// Get invoice if specified
$invoice = null;
if (isset($_GET['invoice_id'])) {
    $stmt = $db->prepare("
        SELECT i.*, c.name as customer_name, c.whatsapp_number, c.mobile_number, c.portal_token, co.order_number
        FROM customer_invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN customer_orders co ON i.order_id = co.id
        WHERE i.id = ?
    ");
    $stmt->execute([$_GET['invoice_id']]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($invoice) {
        // Calculate paid and remaining amounts for this invoice
        try {
            $paid_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE invoice_id = ?");
            $paid_stmt->execute([$invoice['id']]);
            $paid_amount = (float)$paid_stmt->fetchColumn();
        } catch (PDOException $e) {
            $paid_amount = 0.0;
        }

        $invoice['paid_amount'] = $paid_amount;
        $invoice_total = isset($invoice['total_amount']) ? (float)$invoice['total_amount'] : 0.0;
        $invoice['remaining_amount'] = $invoice_total - $paid_amount;
    }

    if ($invoice && !$customer) {
        $customer = [
            'id' => $invoice['customer_id'],
            'name' => $invoice['customer_name'],
            'whatsapp_number' => $invoice['whatsapp_number'],
            'mobile_number' => $invoice['mobile_number'],
            'portal_token' => $invoice['portal_token'] ?? null
        ];
    }
}

// Build account portal URL if customer has portal_token
$accountPortalUrl = null;
if ($customer && !empty($customer['portal_token'] ?? null)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'taksoride.com';
    $baseUrl = $scheme . '://' . $host;
    $accountPortalUrl = rtrim($baseUrl, '/') . '/customer_portal/portal.php?token=' . urlencode($customer['portal_token']);
}

// --- PERMISSION CHECK: Get templates for dropdown ---
// Only fetch templates if user has permission to view them.
$templates = [];
if (canView($_SESSION['user_id'], 'whatsapp')) {
    $templates = $db->query("SELECT * FROM whatsapp_templates WHERE is_active = 1 ORDER BY category, template_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get all customers for dropdown
$customers = $db->query("SELECT id, customer_code, name, whatsapp_number, mobile_number FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    .whatsapp-preview {
        background: linear-gradient(135deg, #128c7e 0%, #075e54 100%);
        border-radius: 20px;
        padding: 30px;
        min-height: 500px;
        position: relative;
    }

    .chat-header {
        background: #075e54;
        border-radius: 12px 12px 0 0;
        padding: 15px;
        margin: -30px -30px 20px -30px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .chat-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #25d366;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        font-weight: bold;
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
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .message-bubble::after {
        content: '';
        position: absolute;
        right: -8px;
        top: 0;
        width: 0;
        height: 0;
        border-left: 8px solid #dcf8c6;
        border-top: 8px solid transparent;
    }

    .message-time {
        font-size: 11px;
        color: #667781;
        text-align: left;
        margin-top: 4px;
    }

    .send-button {
        background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);
        color: white;
        padding: 16px 32px;
        border-radius: 12px;
        font-size: 18px;
        font-weight: bold;
        border: none;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
    }

    .send-button:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
    }

    .variable-chip {
        display: inline-block;
        background: #e3f2fd;
        color: #1976d2;
        padding: 4px 10px;
        border-radius: 16px;
        font-size: 12px;
        font-weight: 600;
        margin: 2px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .variable-chip:hover {
        background: #1976d2;
        color: white;
    }
</style>

<div class="container mx-auto px-4 py-8" style="max-width: 1400px;">

    <!-- Header -->
    <div class="bg-gradient-to-r from-amber-600 via-green-700 to-amber-800 rounded-2xl shadow-2xl p-10 mb-8">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-4xl font-black text-white flex items-center gap-4">
                    <i class="fab fa-whatsapp"></i>
                    إرسال رسالة واتساب
                </h1>
                <p class="text-amber-100 mt-3 text-lg">إرسال مباشر إلى واتساب مع الرسالة جاهزة</p>
            </div>
            <!-- --- PERMISSION CHECK: Link to templates page --- -->
            <?php if (canView($_SESSION['user_id'], 'whatsapp')): ?>
                <a href="templates.php" class="bg-white text-amber-600 px-6 py-3 rounded-xl font-bold hover:bg-amber-50 transition-all shadow-lg">
                    <i class="fas fa-list ml-2"></i>
                    القوالب
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$order && !$invoice): ?>
        <!-- Warning: No order or invoice selected -->
        <div class="bg-yellow-50 border-2 border-yellow-400 rounded-xl p-4 mb-6">
            <div class="flex items-center gap-3">
                <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                <div>
                    <h3 class="font-bold text-yellow-800">تنبيه: لم يتم تحديد طلب أو فاتورة</h3>
                    <p class="text-yellow-700 text-sm mt-1">
                        المتغيرات مثل <code class="bg-yellow-200 px-1 rounded">{order_number}</code> و
                        <code class="bg-yellow-200 px-1 rounded">{remaining_amount}</code> و
                        <code class="bg-yellow-200 px-1 rounded">{payment_amount}</code>
                        لن تعمل بدون فتح الصفحة من طلب أو فاتورة محددة.
                    </p>
                    <p class="text-yellow-700 text-sm mt-2">
                        <strong>الحل:</strong> افتح صفحة الواتساب من زر الواتساب في صفحة الطلبات أو الفواتير.
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

        <!-- Left Side: Form -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">
                <i class="fas fa-edit ml-2"></i>
                تخصيص الرسالة
            </h2>

            <form id="whatsappForm">

                <!-- Select Customer -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-user ml-1"></i>
                        اختر العميل *
                    </label>
                    <select id="customerId" class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none" required>
                        <option value="">-- اختر العميل --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>"
                                data-name="<?php echo htmlspecialchars($cust['name']); ?>"
                                data-phone="<?php echo $cust['whatsapp_number'] ?: $cust['mobile_number']; ?>"
                                <?php echo ($customer && $customer['id'] == $cust['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cust['name']); ?>
                                (<?php echo $cust['customer_code']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Phone Number Display -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-phone ml-1"></i>
                        رقم الواتساب
                    </label>
                    <input type="text" id="phoneNumber" readonly
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg bg-gray-50"
                        value="<?php echo $customer ? formatYemenPhone($customer['whatsapp_number'] ?: $customer['mobile_number'], false) : ''; ?>">
                </div>

                <!-- --- PERMISSION CHECK: Select Template Dropdown --- -->
                <?php if (canView($_SESSION['user_id'], 'whatsapp')): ?>
                    <div class="mb-6">
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fas fa-file-alt ml-1"></i>
                            اختر قالب (اختياري)
                        </label>
                        <select id="templateId" class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none">
                            <option value="">-- بدون قالب --</option>
                            <?php
                            $currentCategory = '';
                            foreach ($templates as $tmpl):
                                if ($tmpl['category'] != $currentCategory) {
                                    if ($currentCategory != '') echo '</optgroup>';
                                    $categories = [
                                        'order' => 'طلبات',
                                        'payment' => 'مدفوعات',
                                        'shipping' => 'شحن',
                                        'general' => 'عام'
                                    ];
                                    echo '<optgroup label="' . ($categories[$tmpl['category']] ?? $tmpl['category']) . '">';
                                    $currentCategory = $tmpl['category'];
                                }
                            ?>
                                <option value="<?php echo $tmpl['id']; ?>"
                                    data-content="<?php echo htmlspecialchars($tmpl['template_content']); ?>"
                                    <?php echo ($template && $template['id'] == $tmpl['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tmpl['template_name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($currentCategory != '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <!-- Available Variables -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-code ml-1"></i>
                        المتغيرات المتاحة
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <span class="variable-chip" onclick="insertVariable('{customer_name}')">
                            {customer_name}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{order_number}')">
                            {order_number}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{order_total}')">
                            {order_total}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{order_quantity}')">
                            {order_quantity}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{payment_amount}')">
                            {payment_amount}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{remaining_amount}')">
                            {remaining_amount}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{company_name}')">
                            {company_name}
                        </span>
                        <span class="variable-chip" onclick="insertVariable('{account_portal}')">
                            {account_portal}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">اضغط على المتغير لإضافته للرسالة</p>
                </div>

                <!-- Message Content -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-comment-alt ml-1"></i>
                        نص الرسالة *
                    </label>
                    <textarea id="messageContent" rows="8" required
                        class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none"
                        placeholder="اكتب رسالتك هنا..."><?php echo $template ? htmlspecialchars($template['template_content']) : ''; ?></textarea>
                    <div class="flex justify-between items-center mt-2">
                        <span id="charCount" class="text-sm text-gray-500">0 حرف</span>
                        <button type="button" onclick="clearMessage()" class="text-sm text-red-600 hover:text-red-800">
                            <i class="fas fa-eraser ml-1"></i>
                            مسح
                        </button>
                    </div>
                </div>

                <!-- Variable Values (if order is selected) -->
                <?php if ($order): ?>
                    <div class="mb-6 bg-blue-50 border-2 border-blue-200 rounded-lg p-4">
                        <h3 class="font-bold text-blue-900 mb-3">
                            <i class="fas fa-database ml-2"></i>
                            قيم المتغيرات
                        </h3>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <strong>customer_name:</strong>
                                <span class="text-gray-700"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                            </div>
                            <div>
                                <strong>order_number:</strong>
                                <span class="text-gray-700"><?php echo htmlspecialchars($order['order_number']); ?></span>
                            </div>
                            <div>
                                <strong>order_total:</strong>
                                <span class="text-gray-700"><?php echo number_format($order['final_amount'], 3); ?> ريال</span>
                            </div>
                            <div>
                                <strong>order_date:</strong>
                                <span class="text-gray-700"><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- --- PERMISSION CHECK: Send Button --- -->
                <?php if (canAdd($_SESSION['user_id'], 'whatsapp')): ?>
                    <button type="button" onclick="sendToWhatsApp()" class="w-full send-button">
                        <i class="fab fa-whatsapp ml-2 text-2xl"></i>
                        فتح في الواتساب
                    </button>
                    <p class="text-center text-sm text-gray-500 mt-4">
                        <i class="fas fa-info-circle ml-1"></i>
                        سيتم فتح واتساب مع الرسالة جاهزة للإرسال
                    </p>
                <?php else: ?>
                    <button type="button" class="w-full send-button" style="background: #9ca3af; cursor: not-allowed;" disabled>
                        <i class="fas fa-lock ml-2 text-2xl"></i>
                        لا تملك صلاحية الإرسال
                    </button>
                    <p class="text-center text-sm text-gray-500 mt-4">
                        <i class="fas fa-info-circle ml-1"></i>
                        أنت لا تملك الصلاحية لإرسال رسائل الواتساب.
                    </p>
                <?php endif; ?>

            </form>
        </div>

        <!-- Right Side: Preview -->
        <div class="whatsapp-preview">
            <div class="chat-header">
                <div class="chat-avatar" id="avatarInitial">
                    <i class="fas fa-user"></i>
                </div>
                <div class="flex-1">
                    <h3 class="text-white font-bold text-lg" id="previewName">اسم العميل</h3>
                    <p class="text-amber-200 text-sm" id="previewPhone">+967 XXX XXX XXX</p>
                </div>
                <i class="fas fa-ellipsis-v text-white text-xl"></i>
            </div>

            <div class="message-bubble" id="messagePreview">
                <div id="previewContent">اكتب رسالتك لرؤية المعاينة...</div>
                <div class="message-time" id="previewTime">
                    <?php echo date('H:i'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Store order and invoice data if available
    const orderData = <?php echo $order ? json_encode($order) : 'null'; ?>;
    const invoiceData = <?php echo $invoice ? json_encode($invoice) : 'null'; ?>;
    const accountPortalUrl = <?php echo json_encode($accountPortalUrl); ?>;

    // Helper: replace {variable} allowing optional spaces, e.g. { remaining_amount }
    function replaceVariable(message, key, value) {
        if (value === null || typeof value === 'undefined') return message;
        const pattern = new RegExp('\\{\\s*' + key + '\\s*\\}', 'gi');
        return message.replace(pattern, value);
    }

    // Update preview on customer change
    document.getElementById('customerId').addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        const name = option.dataset.name || 'اسم العميل';
        const phone = option.dataset.phone || '+967 XXX XXX XXX';

        document.getElementById('previewName').textContent = name;
        document.getElementById('previewPhone').textContent = phone;
        document.getElementById('phoneNumber').value = phone;
        document.getElementById('avatarInitial').textContent = name.charAt(0);

        updatePreview();
    });

    // Update preview on template change (check if element exists first)
    const templateSelect = document.getElementById('templateId');
    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            const content = option.dataset.content || '';
            document.getElementById('messageContent').value = content;
            updatePreview();
        });
    }

    // Update preview on message change
    document.getElementById('messageContent').addEventListener('input', function() {
        updatePreview();
        updateCharCount();
    });

    function updatePreview() {
        let message = document.getElementById('messageContent').value;
        const customerName = document.getElementById('customerId').options[document.getElementById('customerId').selectedIndex].dataset.name || 'العميل';

        // Replace variables with actual values
        message = replaceVariable(message, 'customer_name', customerName);

        // Use invoice data if available, otherwise order data
        const dataSource = invoiceData || orderData;

        if (dataSource) {
            if (dataSource.order_number) {
                message = replaceVariable(message, 'order_number', dataSource.order_number);
            }
            if (dataSource.invoice_number) {
                message = replaceVariable(message, 'invoice_number', dataSource.invoice_number);
            }
            if (dataSource.final_amount || dataSource.total_amount) {
                const amount = dataSource.final_amount || dataSource.total_amount;
                const formattedTotal = parseFloat(amount).toFixed(3) + ' ريال';
                message = replaceVariable(message, 'order_total', formattedTotal);
                message = replaceVariable(message, 'invoice_total', formattedTotal);
            }
            if (typeof dataSource.order_quantity !== 'undefined') {
                const qtyText = String(dataSource.order_quantity);
                message = replaceVariable(message, 'order_quantity', qtyText);
            }
            if (typeof dataSource.paid_amount !== 'undefined') {
                const formattedPaid = parseFloat(dataSource.paid_amount || 0).toFixed(3) + ' ريال';
                message = replaceVariable(message, 'payment_amount', formattedPaid);
            }
            if (typeof dataSource.remaining_amount !== 'undefined') {
                const formattedRemaining = parseFloat(dataSource.remaining_amount || 0).toFixed(3) + ' ريال';
                message = replaceVariable(message, 'remaining_amount', formattedRemaining);
            }
            if (dataSource.created_at) {
                const dateOnly = dataSource.created_at.split(' ')[0];
                message = replaceVariable(message, 'order_date', dateOnly);
                message = replaceVariable(message, 'invoice_date', dateOnly);
            }
        }

        message = replaceVariable(message, 'company_name', 'شركة يمان');
        // Account portal: use real URL if available, otherwise a generic text
        {
            const portalText = accountPortalUrl || 'رابط حسابك في البوابة (يرجى التواصل مع الإدارة لتفعيله)';
            message = replaceVariable(message, 'account_portal', portalText);
        }

        document.getElementById('previewContent').innerHTML = message.replace(/\n/g, '<br>');
    }

    function updateCharCount() {
        const count = document.getElementById('messageContent').value.length;
        document.getElementById('charCount').textContent = count + ' حرف';
    }

    function insertVariable(variable) {
        const textarea = document.getElementById('messageContent');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;

        textarea.value = text.substring(0, start) + variable + text.substring(end);
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + variable.length;

        updatePreview();
        updateCharCount();
    }

    function clearMessage() {
        if (confirm('هل أنت متأكد من مسح الرسالة؟')) {
            document.getElementById('messageContent').value = '';
            updatePreview();
            updateCharCount();
        }
    }

    function sendToWhatsApp() {
        const customerId = document.getElementById('customerId').value;
        const phone = document.getElementById('phoneNumber').value;
        let message = document.getElementById('messageContent').value;

        if (!customerId) {
            alert('يرجى اختيار العميل');
            return;
        }

        if (!message.trim()) {
            alert('يرجى كتابة الرسالة');
            return;
        }

        // Replace variables with actual values
        const customerName = document.getElementById('customerId').options[document.getElementById('customerId').selectedIndex].dataset.name || 'العميل';

        // Use invoice data if available, otherwise order data
        const dataSource = invoiceData || orderData;

        // Replace all variable patterns
        message = replaceVariable(message, 'customer_name', customerName);
        message = replaceVariable(message, 'company_name', 'شركة يمان');
        {
            const portalText = accountPortalUrl || 'رابط حسابك في البوابة (يرجى التواصل مع الإدارة لتفعيله)';
            message = replaceVariable(message, 'account_portal', portalText);
        }

        if (dataSource) {
            if (dataSource.order_number) {
                message = replaceVariable(message, 'order_number', dataSource.order_number);
            }
            if (dataSource.invoice_number) {
                message = replaceVariable(message, 'invoice_number', dataSource.invoice_number);
            }
            if (dataSource.final_amount || dataSource.total_amount) {
                const amount = parseFloat(dataSource.final_amount || dataSource.total_amount).toFixed(3) + ' ريال';
                message = replaceVariable(message, 'order_total', amount);
                message = replaceVariable(message, 'invoice_total', amount);
            }
            if (dataSource.created_at) {
                const date = dataSource.created_at.split(' ')[0];
                message = replaceVariable(message, 'order_date', date);
                message = replaceVariable(message, 'invoice_date', date);
            }
            if (dataSource.tracking_number) {
                message = replaceVariable(message, 'tracking_number', dataSource.tracking_number);
            }
            if (typeof dataSource.order_quantity !== 'undefined') {
                const qtyText = String(dataSource.order_quantity);
                message = replaceVariable(message, 'order_quantity', qtyText);
            }
            if (typeof dataSource.paid_amount !== 'undefined') {
                const paidText = parseFloat(dataSource.paid_amount || 0).toFixed(3) + ' ريال';
                message = replaceVariable(message, 'payment_amount', paidText);
            }
            if (typeof dataSource.remaining_amount !== 'undefined') {
                const remainingText = parseFloat(dataSource.remaining_amount || 0).toFixed(3) + ' ريال';
                message = replaceVariable(message, 'remaining_amount', remainingText);
            }
        }

        // Remove any remaining unreplaced variables (show warning if found)
        const unreplacedVars = message.match(/\{\s*\w+\s*\}/g);
        if (unreplacedVars && unreplacedVars.length > 0) {
            const uniqueVars = [...new Set(unreplacedVars)];
            alert('تحذير: المتغيرات التالية لم يتم استبدالها لعدم وجود بيانات:\n' + uniqueVars.join('\n') + '\n\nيرجى فتح الصفحة من طلب أو فاتورة محددة.');
            // Remove unreplaced variables from message
            message = message.replace(/\{\s*\w+\s*\}/g, '[غير متوفر]');
        }

        // Clean phone number - remove all non-digits
        let cleanPhone = phone.replace(/[^0-9]/g, '');

        // Ensure it starts with 967
        if (!cleanPhone.startsWith('967')) {
            if (cleanPhone.startsWith('0')) {
                cleanPhone = '967' + cleanPhone.substring(1);
            } else {
                cleanPhone = '967' + cleanPhone;
            }
        }

        // Create WhatsApp URL
        const whatsappUrl = `https://wa.me/${cleanPhone}?text=${encodeURIComponent(message)}`;

        console.log('Phone:', cleanPhone);
        console.log('Message:', message);
        console.log('URL:', whatsappUrl);

        // Open WhatsApp
        window.open(whatsappUrl, '_blank');
    }

    // Initialize
    updatePreview();
    updateCharCount();

    // Set initial customer if provided
    <?php if ($customer): ?>
        document.getElementById('customerId').value = '<?php echo $customer['id']; ?>';
        document.getElementById('customerId').dispatchEvent(new Event('change'));
    <?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>