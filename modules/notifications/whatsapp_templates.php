<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'قوالب رسائل واتساب';
$error_message = '';
$success_message = '';

// Get templates from database
try {
    $stmt = $db->query("SELECT * FROM notification_templates WHERE type = 'whatsapp' ORDER BY name");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table doesn't exist, create it
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL,
            subject VARCHAR(255) NULL,
            content TEXT NOT NULL,
            variables TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Insert default templates
        $db->exec("INSERT INTO notification_templates (name, type, content, variables) VALUES 
            ('طلب جديد', 'whatsapp', 'مرحباً {customer_name}،\n\nتم إنشاء طلب جديد برقم {order_number}.\n\nإجمالي الطلب: {order_total} ريال.\n\nيمكنك متابعة حالة الطلب من خلال الرابط التالي: {tracking_link}\n\nشكراً لتعاملكم معنا،\n{company_name}', '{\"customer_name\":\"اسم العميل\",\"order_number\":\"رقم الطلب\",\"order_total\":\"إجمالي الطلب\",\"tracking_link\":\"رابط التتبع\",\"company_name\":\"اسم الشركة\"}'),
            ('تأكيد الدفع', 'whatsapp', 'مرحباً {customer_name}،\n\nتم تأكيد استلام الدفع للطلب رقم {order_number} بمبلغ {payment_amount} ريال.\n\nشكراً لتعاملكم معنا،\n{company_name}', '{\"customer_name\":\"اسم العميل\",\"order_number\":\"رقم الطلب\",\"payment_amount\":\"مبلغ الدفع\",\"company_name\":\"اسم الشركة\"}'),
            ('تحديث حالة الطلب', 'whatsapp', 'مرحباً {customer_name}،\n\nتم تحديث حالة الطلب رقم {order_number} إلى {order_status}.\n\n{additional_info}\n\nشكراً لتعاملكم معنا،\n{company_name}', '{\"customer_name\":\"اسم العميل\",\"order_number\":\"رقم الطلب\",\"order_status\":\"حالة الطلب\",\"additional_info\":\"معلومات إضافية\",\"company_name\":\"اسم الشركة\"}')
        ");
        
        $stmt = $db->query("SELECT * FROM notification_templates WHERE type = 'whatsapp' ORDER BY name");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        $error_message = 'حدث خطأ أثناء إنشاء جدول القوالب: ' . $e2->getMessage();
        $templates = [];
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $name = $_POST['name'] ?? '';
        $content = $_POST['content'] ?? '';
        $variables = $_POST['variables'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validate input
        if (empty($name) || empty($content)) {
            $error_message = 'يرجى تعبئة جميع الحقول المطلوبة';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO notification_templates (name, type, content, variables, is_active) VALUES (?, 'whatsapp', ?, ?, ?)");
                    $stmt->execute([$name, $content, $variables, $is_active]);
                    $success_message = 'تم إضافة القالب بنجاح';
                } else {
                    $stmt = $db->prepare("UPDATE notification_templates SET name = ?, content = ?, variables = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$name, $content, $variables, $is_active, $template_id]);
                    $success_message = 'تم تحديث القالب بنجاح';
                }
                
                // Refresh templates list
                $stmt = $db->query("SELECT * FROM notification_templates WHERE type = 'whatsapp' ORDER BY name");
                $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                $error_message = 'حدث خطأ أثناء حفظ القالب: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        
        try {
            $stmt = $db->prepare("DELETE FROM notification_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $success_message = 'تم حذف القالب بنجاح';
            
            // Refresh templates list
            $stmt = $db->query("SELECT * FROM notification_templates WHERE type = 'whatsapp' ORDER BY name");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء حذف القالب: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">قوالب رسائل واتساب</h1>
                        <p class="text-gray-600 mt-1">إدارة قوالب الرسائل المرسلة عبر واتساب</p>
                    </div>
                    <div>
                        <button id="addTemplateBtn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة قالب جديد
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Templates List -->
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">قوالب الرسائل المتاحة</h2>
            </div>
            
            <?php if (empty($templates)): ?>
            <div class="p-6 text-center text-gray-500">
                <i class="fas fa-comment-dots fa-3x mb-3"></i>
                <p>لا توجد قوالب مضافة بعد</p>
                <p class="text-sm mt-2">انقر على زر "إضافة قالب جديد" لإضافة قالب</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">اسم القالب</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">محتوى الرسالة</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($templates as $template): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($template['name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <div class="max-w-xs truncate"><?php echo htmlspecialchars(substr($template['content'], 0, 100)) . (strlen($template['content']) > 100 ? '...' : ''); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php if ($template['is_active']): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800">
                                    مفعل
                                </span>
                                <?php else: ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                    غير مفعل
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <button class="text-blue-600 hover:text-blue-900 edit-template" data-id="<?php echo $template['id']; ?>" data-name="<?php echo htmlspecialchars($template['name']); ?>" data-content="<?php echo htmlspecialchars($template['content']); ?>" data-variables="<?php echo htmlspecialchars($template['variables']); ?>" data-active="<?php echo $template['is_active']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="text-red-600 hover:text-red-900 delete-template" data-id="<?php echo $template['id']; ?>" data-name="<?php echo htmlspecialchars($template['name']); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="text-amber-600 hover:text-amber-900 preview-template" data-content="<?php echo htmlspecialchars($template['content']); ?>" data-variables="<?php echo htmlspecialchars($template['variables']); ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Help Section -->
        <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">كيفية استخدام المتغيرات</h2>
            </div>
            <div class="p-6">
                <p class="mb-4">يمكنك استخدام المتغيرات التالية في قوالب الرسائل، وسيتم استبدالها بالقيم الفعلية عند إرسال الرسالة:</p>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <ul class="list-disc list-inside space-y-2">
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{customer_name}</code> - اسم العميل</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{order_number}</code> - رقم الطلب</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{order_total}</code> - إجمالي الطلب</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{order_status}</code> - حالة الطلب</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{payment_amount}</code> - مبلغ الدفع</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{tracking_link}</code> - رابط تتبع الطلب</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{company_name}</code> - اسم الشركة</li>
                        <li><code class="bg-gray-200 px-2 py-1 rounded">{additional_info}</code> - معلومات إضافية</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Template Modal -->
<div id="templateModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900" id="modalTitle">إضافة قالب جديد</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" id="closeModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="templateForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="template_id" id="templateId" value="">
            
            <div class="px-6 py-4 space-y-4">
                <div>
                    <label for="templateName" class="block text-sm font-medium text-gray-700 mb-1">اسم القالب</label>
                    <input type="text" id="templateName" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>
                
                <div>
                    <label for="templateContent" class="block text-sm font-medium text-gray-700 mb-1">محتوى الرسالة</label>
                    <textarea id="templateContent" name="content" rows="8" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required></textarea>
                    <p class="text-xs text-gray-500 mt-1">استخدم {variable_name} لإدراج متغيرات في الرسالة</p>
                </div>
                
                <div>
                    <label for="templateVariables" class="block text-sm font-medium text-gray-700 mb-1">المتغيرات (JSON)</label>
                    <textarea id="templateVariables" name="variables" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    <p class="text-xs text-gray-500 mt-1">أدخل المتغيرات بصيغة JSON: {"variable_name":"وصف المتغير"}</p>
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" id="templateActive" name="is_active" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" checked>
                    <label for="templateActive" class="mr-2 block text-sm text-gray-900">مفعل</label>
                </div>
            </div>
            
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
                <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200 ml-2" id="cancelBtn">
                    إلغاء
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                    حفظ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Template Modal -->
<div id="previewModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">معاينة الرسالة</h3>
            <button type="button" class="text-gray-400 hover:text-gray-500" id="closePreviewModal">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="px-6 py-4">
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fab fa-whatsapp text-amber-500 text-2xl"></i>
                    </div>
                    <div class="mr-3 flex-1">
                        <p class="text-sm text-gray-700 whitespace-pre-line" id="previewContent"></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
            <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200" id="closePreviewBtn">
                إغلاق
            </button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">تأكيد الحذف</h3>
        </div>
        <div class="px-6 py-4">
            <p class="text-gray-700">هل أنت متأكد من حذف القالب <span id="deleteTemplateName" class="font-semibold"></span>؟</p>
            <p class="text-sm text-red-600 mt-2">لا يمكن التراجع عن هذا الإجراء.</p>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
            <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition duration-200 ml-2" id="cancelDeleteBtn">
                إلغاء
            </button>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="template_id" id="deleteTemplateId" value="">
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-200">
                    حذف
                </button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal elements
    const templateModal = document.getElementById('templateModal');
    const previewModal = document.getElementById('previewModal');
    const deleteModal = document.getElementById('deleteModal');
    
    // Form elements
    const templateForm = document.getElementById('templateForm');
    const formAction = document.getElementById('formAction');
    const templateId = document.getElementById('templateId');
    const templateName = document.getElementById('templateName');
    const templateContent = document.getElementById('templateContent');
    const templateVariables = document.getElementById('templateVariables');
    const templateActive = document.getElementById('templateActive');
    
    // Buttons
    const addTemplateBtn = document.getElementById('addTemplateBtn');
    const closeModal = document.getElementById('closeModal');
    const cancelBtn = document.getElementById('cancelBtn');
    const closePreviewModal = document.getElementById('closePreviewModal');
    const closePreviewBtn = document.getElementById('closePreviewBtn');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    
    // Preview elements
    const previewContent = document.getElementById('previewContent');
    
    // Delete elements
    const deleteTemplateName = document.getElementById('deleteTemplateName');
    const deleteTemplateId = document.getElementById('deleteTemplateId');
    
    // Add template button
    addTemplateBtn.addEventListener('click', function() {
        formAction.value = 'add';
        templateId.value = '';
        templateName.value = '';
        templateContent.value = '';
        templateVariables.value = '{}';
        templateActive.checked = true;
        document.getElementById('modalTitle').textContent = 'إضافة قالب جديد';
        templateModal.classList.remove('hidden');
    });
    
    // Edit template buttons
    document.querySelectorAll('.edit-template').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            const content = this.dataset.content;
            const variables = this.dataset.variables;
            const active = this.dataset.active === '1';
            
            formAction.value = 'edit';
            templateId.value = id;
            templateName.value = name;
            templateContent.value = content;
            templateVariables.value = variables;
            templateActive.checked = active;
            document.getElementById('modalTitle').textContent = 'تعديل القالب';
            templateModal.classList.remove('hidden');
        });
    });
    
    // Preview template buttons
    document.querySelectorAll('.preview-template').forEach(button => {
        button.addEventListener('click', function() {
            const content = this.dataset.content;
            const variables = JSON.parse(this.dataset.variables || '{}');
            
            // Replace variables with sample values
            let previewText = content;
            for (const [key, value] of Object.entries(variables)) {
                previewText = previewText.replace(new RegExp(`{${key}}`, 'g'), `<span class="text-blue-600">[${value}]</span>`);
            }
            
            previewContent.innerHTML = previewText;
            previewModal.classList.remove('hidden');
        });
    });
    
    // Delete template buttons
    document.querySelectorAll('.delete-template').forEach(button => {
        button.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            
            deleteTemplateId.value = id;
            deleteTemplateName.textContent = name;
            deleteModal.classList.remove('hidden');
        });
    });
    
    // Close modals
    closeModal.addEventListener('click', () => templateModal.classList.add('hidden'));
    cancelBtn.addEventListener('click', () => templateModal.classList.add('hidden'));
    closePreviewModal.addEventListener('click', () => previewModal.classList.add('hidden'));
    closePreviewBtn.addEventListener('click', () => previewModal.classList.add('hidden'));
    cancelDeleteBtn.addEventListener('click', () => deleteModal.classList.add('hidden'));
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === templateModal) {
            templateModal.classList.add('hidden');
        }
        if (event.target === previewModal) {
            previewModal.classList.add('hidden');
        }
        if (event.target === deleteModal) {
            deleteModal.classList.add('hidden');
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
