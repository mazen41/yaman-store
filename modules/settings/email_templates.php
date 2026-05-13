<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة قوالب البريد الإلكتروني';
$error_message = '';
$success_message = '';

// Define available templates
$available_templates = [
    'order_new' => 'إشعار طلب جديد',
    'order_status' => 'تحديث حالة الطلب',
    'invoice' => 'فاتورة'
];

// Get selected template
$selected_template = isset($_GET['template']) && array_key_exists($_GET['template'], $available_templates) 
    ? $_GET['template'] 
    : 'order_new';

$template_path = "../../templates/email/{$selected_template}.php";
$template_content = '';

// Load template content
if (file_exists($template_path)) {
    $template_content = file_get_contents($template_path);
} else {
    $error_message = "لم يتم العثور على ملف القالب: {$template_path}";
}

// Handle template save
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_template'])) {
    $new_content = $_POST['template_content'] ?? '';
    
    if (empty($new_content)) {
        $error_message = 'محتوى القالب لا يمكن أن يكون فارغاً';
    } else {
        try {
            // Create templates directory if it doesn't exist
            $template_dir = "../../templates/email";
            if (!file_exists($template_dir)) {
                mkdir($template_dir, 0755, true);
            }
            
            // Save template
            file_put_contents($template_path, $new_content);
            $success_message = 'تم حفظ القالب بنجاح';
            $template_content = $new_content;
        } catch (Exception $e) {
            $error_message = 'حدث خطأ أثناء حفظ القالب: ' . $e->getMessage();
        }
    }
}

// Handle template test
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_template'])) {
    $test_email = $_POST['test_email'] ?? '';
    
    if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'يرجى إدخال عنوان بريد إلكتروني صحيح للاختبار';
    } else {
        try {
            // Include EmailSender class
            require_once '../../includes/EmailSender.php';
            
            // Get test data based on template type
            switch ($selected_template) {
                case 'order_new':
                    // Get a sample order
                    $order_stmt = $db->query("
                        SELECT o.*, c.name as customer_name, c.email, c.mobile_number, c.whatsapp_number
                        FROM customer_orders o
                        JOIN customers c ON o.customer_id = c.id
                        ORDER BY o.id DESC LIMIT 1
                    ");
                    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$order) {
                        throw new Exception('لم يتم العثور على بيانات طلب للاختبار');
                    }
                    
                    // Get order items
                    $items_stmt = $db->prepare("
                        SELECT * FROM order_items WHERE order_id = ? ORDER BY id
                    ");
                    $items_stmt->execute([$order['id']]);
                    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Prepare data for template
                    $customer = [
                        'id' => $order['customer_id'],
                        'name' => $order['customer_name'],
                        'email' => $test_email, // Use test email
                        'mobile_number' => $order['mobile_number'],
                        'whatsapp_number' => $order['whatsapp_number']
                    ];
                    
                    // Create EmailSender instance
                    $emailSender = new EmailSender($db);
                    
                    // Send test email
                    $emailSender->sendNewOrderEmail($order, $customer, $items);
                    $success_message = 'تم إرسال بريد اختبار القالب بنجاح إلى ' . $test_email;
                    break;
                    
                case 'order_status':
                    // Get a sample order
                    $order_stmt = $db->query("
                        SELECT o.*, c.name as customer_name, c.email, c.mobile_number, c.whatsapp_number
                        FROM customer_orders o
                        JOIN customers c ON o.customer_id = c.id
                        ORDER BY o.id DESC LIMIT 1
                    ");
                    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$order) {
                        throw new Exception('لم يتم العثور على بيانات طلب للاختبار');
                    }
                    
                    // Get order items
                    $items_stmt = $db->prepare("
                        SELECT * FROM order_items WHERE order_id = ? ORDER BY id
                    ");
                    $items_stmt->execute([$order['id']]);
                    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Prepare data for template
                    $customer = [
                        'id' => $order['customer_id'],
                        'name' => $order['customer_name'],
                        'email' => $test_email, // Use test email
                        'mobile_number' => $order['mobile_number'],
                        'whatsapp_number' => $order['whatsapp_number']
                    ];
                    
                    $status_history = [
                        'status' => 'completed',
                        'notes' => 'هذا اختبار لقالب تحديث حالة الطلب',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Create EmailSender instance
                    $emailSender = new EmailSender($db);
                    
                    // Send test email
                    $emailSender->sendOrderStatusEmail($order, $customer, $items, $status_history);
                    $success_message = 'تم إرسال بريد اختبار القالب بنجاح إلى ' . $test_email;
                    break;
                    
                case 'invoice':
                    // Get a sample invoice or create dummy data
                    $invoice_stmt = $db->query("
                        SELECT i.*, c.name as customer_name, c.email, c.mobile_number, c.whatsapp_number
                        FROM invoices i
                        JOIN customers c ON i.customer_id = c.id
                        ORDER BY i.id DESC LIMIT 1
                    ");
                    $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$invoice) {
                        // Create dummy invoice data
                        $invoice = [
                            'id' => 1,
                            'invoice_number' => 'INV-' . date('Ymd') . '-001',
                            'status' => 'sent',
                            'total_amount' => 1500.00,
                            'discount_amount' => 150.00,
                            'discount_percentage' => 10,
                            'final_amount' => 1350.00,
                            'created_at' => date('Y-m-d H:i:s'),
                            'due_date' => date('Y-m-d', strtotime('+15 days'))
                        ];
                        
                        // Create dummy items
                        $items = [
                            [
                                'product_name' => 'منتج اختبار 1',
                                'quantity' => 2,
                                'unit_price' => 500.00,
                                'total_price' => 1000.00
                            ],
                            [
                                'product_name' => 'منتج اختبار 2',
                                'quantity' => 1,
                                'unit_price' => 500.00,
                                'total_price' => 500.00
                            ]
                        ];
                    } else {
                        // Get invoice items
                        $items_stmt = $db->prepare("
                            SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id
                        ");
                        $items_stmt->execute([$invoice['id']]);
                        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    
                    // Prepare data for template
                    $customer = [
                        'id' => $invoice['customer_id'] ?? 1,
                        'name' => $invoice['customer_name'] ?? 'عميل اختبار',
                        'email' => $test_email, // Use test email
                        'mobile_number' => $invoice['mobile_number'] ?? '966500000000',
                        'whatsapp_number' => $invoice['whatsapp_number'] ?? '966500000000'
                    ];
                    
                    // Get company info
                    $company = [
                        'name' => 'شركة يمان',
                        'email' => 'info@yassin.com',
                        'phone' => '966500000000',
                        'address' => 'الرياض، المملكة العربية السعودية',
                        'logo' => 'https://via.placeholder.com/200x80?text=Yassin'
                    ];
                    
                    // Create EmailSender instance
                    $emailSender = new EmailSender($db);
                    
                    // Send test email
                    $emailSender->sendInvoiceEmail($invoice, $customer, $items, $company);
                    $success_message = 'تم إرسال بريد اختبار القالب بنجاح إلى ' . $test_email;
                    break;
            }
            
        } catch (Exception $e) {
            $error_message = 'فشل إرسال بريد الاختبار: ' . $e->getMessage();
        }
    }
}

// Handle create new template
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_template'])) {
    $new_template_name = trim($_POST['new_template_name'] ?? '');
    $new_template_title = trim($_POST['new_template_title'] ?? '');
    
    if (empty($new_template_name) || empty($new_template_title)) {
        $error_message = 'يرجى إدخال اسم وعنوان للقالب الجديد';
    } elseif (!preg_match('/^[a-z0-9_]+$/', $new_template_name)) {
        $error_message = 'اسم القالب يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطات سفلية فقط';
    } else {
        $new_template_path = "../../templates/email/{$new_template_name}.php";
        
        if (file_exists($new_template_path)) {
            $error_message = 'يوجد قالب بهذا الاسم بالفعل';
        } else {
            try {
                // Create template directory if it doesn't exist
                $template_dir = "../../templates/email";
                if (!file_exists($template_dir)) {
                    mkdir($template_dir, 0755, true);
                }
                
                // Create basic template structure
                $basic_template = <<<EOT
<?php
/**
 * Email template: {$new_template_title}
 * 
 * Available variables:
 * \$customer - Customer details array
 */
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$new_template_title}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #C7A46D;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .content {
            padding: 20px;
        }
        .footer {
            background-color: #f3f4f6;
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$new_template_title}</h1>
        </div>
        
        <div class="content">
            <p>مرحباً <?php echo htmlspecialchars(\$customer['name']); ?>،</p>
            
            <p>هذا قالب بريد إلكتروني جديد. يمكنك تعديله حسب احتياجاتك.</p>
            
            <p>مع تحيات،<br>فريق نظام يمان</p>
        </div>
        
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> نظام يمان. جميع الحقوق محفوظة.</p>
        </div>
    </div>
</body>
</html>
EOT;
                
                // Save new template
                file_put_contents($new_template_path, $basic_template);
                
                // Add to available templates
                $available_templates[$new_template_name] = $new_template_title;
                
                $success_message = 'تم إنشاء القالب الجديد بنجاح';
                $selected_template = $new_template_name;
                $template_content = $basic_template;
            } catch (Exception $e) {
                $error_message = 'حدث خطأ أثناء إنشاء القالب: ' . $e->getMessage();
            }
        }
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
    .template-nav {
        display: flex;
        overflow-x: auto;
        padding-bottom: 1px;
        margin-bottom: 1rem;
        gap: 0.5rem;
    }
    .template-nav-item {
        white-space: nowrap;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 500;
        transition: all 0.2s;
    }
    .template-nav-item.active {
        background-color: #C7A46D;
        color: white;
    }
    .template-nav-item:not(.active) {
        background-color: #f3f4f6;
        color: #4b5563;
    }
    .template-nav-item:not(.active):hover {
        background-color: #e5e7eb;
    }
    .code-editor {
        font-family: monospace;
        min-height: 400px;
        border-radius: 0.375rem;
        border: 1px solid #d1d5db;
        padding: 1rem;
        font-size: 0.875rem;
        line-height: 1.5;
        resize: vertical;
    }
</style>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">إدارة قوالب البريد الإلكتروني</h1>
                        <p class="text-gray-600 mt-1">تعديل وتخصيص قوالب البريد الإلكتروني</p>
                    </div>
                    <div>
                        <a href="email_settings.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                            <i class="fas fa-cog ml-2"></i>
                            إعدادات SMTP
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
        
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Template Selection -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-medium text-gray-900">القوالب المتاحة</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-2">
                            <?php foreach ($available_templates as $name => $title): ?>
                            <a href="?template=<?php echo $name; ?>" class="flex items-center px-3 py-2 rounded-md <?php echo $name === $selected_template ? 'bg-amber-100 text-amber-700 font-medium' : 'hover:bg-gray-100'; ?>">
                                <i class="fas fa-file-alt ml-2"></i>
                                <?php echo $title; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-6 pt-4 border-t border-gray-200">
                            <button type="button" onclick="document.getElementById('createTemplateModal').classList.remove('hidden')" class="w-full flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                                <i class="fas fa-plus ml-2"></i>
                                إنشاء قالب جديد
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Test Template -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-medium text-gray-900">اختبار القالب</h2>
                    </div>
                    <div class="p-6">
                        <form method="POST" class="space-y-4">
                            <div>
                                <label for="test_email" class="block text-sm font-medium text-gray-700 mb-1">إرسال اختبار إلى</label>
                                <input type="email" id="test_email" name="test_email" class="form-input" required placeholder="أدخل البريد الإلكتروني">
                            </div>
                            
                            <button type="submit" name="test_template" value="1" class="w-full flex items-center justify-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition duration-200">
                                <i class="fas fa-paper-plane ml-2"></i>
                                إرسال اختبار
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="lg:col-span-3 space-y-6">
                <!-- Template Editor -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-medium text-gray-900">
                            تحرير قالب: <?php echo $available_templates[$selected_template]; ?>
                        </h2>
                    </div>
                    <div class="p-6">
                        <form method="POST">
                            <div class="case-highlight mb-6">
                                <textarea id="template_editor" name="template_content" class="code-editor w-full" rows="20"><?php echo htmlspecialchars($template_content); ?></textarea>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="save_template" value="1" class="flex items-center px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                                    <i class="fas fa-save ml-2"></i>
                                    حفظ القالب
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Template Preview -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-medium text-gray-900">معاينة القالب</h2>
                    </div>
                    <div class="p-6">
                        <div class="case-highlight">
                            <div class="bg-gray-100 p-4 rounded-lg">
                                <p class="text-gray-700 text-sm mb-4">لمعاينة القالب بشكل كامل، استخدم زر "إرسال اختبار" لإرسال بريد إلكتروني تجريبي.</p>
                                
                                <div class="flex justify-center">
                                    <button type="button" onclick="previewTemplate()" class="flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200">
                                        <i class="fas fa-eye ml-2"></i>
                                        معاينة سريعة
                                    </button>
                                </div>
                            </div>
                            
                            <div id="template_preview" class="mt-4 border rounded-lg overflow-hidden" style="height: 500px;">
                                <iframe id="preview_iframe" style="width: 100%; height: 100%; border: none;"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Template Modal -->
<div id="createTemplateModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">إنشاء قالب جديد</h3>
                <button type="button" onclick="document.getElementById('createTemplateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <form method="POST" class="p-6">
            <div class="space-y-4">
                <div>
                    <label for="new_template_name" class="block text-sm font-medium text-gray-700 mb-1">اسم القالب (بالإنجليزية)</label>
                    <input type="text" id="new_template_name" name="new_template_name" class="form-input" required placeholder="مثال: customer_welcome">
                    <p class="text-xs text-gray-500 mt-1">استخدم أحرف إنجليزية صغيرة وأرقام وشرطات سفلية فقط</p>
                </div>
                
                <div>
                    <label for="new_template_title" class="block text-sm font-medium text-gray-700 mb-1">عنوان القالب (بالعربية)</label>
                    <input type="text" id="new_template_title" name="new_template_title" class="form-input" required placeholder="مثال: رسالة ترحيب للعملاء">
                </div>
            </div>
            
            <div class="mt-6 flex justify-end">
                <button type="button" onclick="document.getElementById('createTemplateModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition duration-200 ml-2">
                    إلغاء
                </button>
                <button type="submit" name="create_template" value="1" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                    إنشاء القالب
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function previewTemplate() {
        const templateContent = document.getElementById('template_editor').value;
        const iframe = document.getElementById('preview_iframe');
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        
        iframeDoc.open();
        iframeDoc.write(templateContent.replace(/\<\?php.*?\?\>/gs, ''));
        iframeDoc.close();
    }
    
    // Initialize preview on page load
    document.addEventListener('DOMContentLoaded', function() {
        previewTemplate();
    });
</script>

<?php include '../../includes/footer.php'; ?>
