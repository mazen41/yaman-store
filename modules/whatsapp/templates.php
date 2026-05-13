<?php

/**
 * WhatsApp Templates Management
 * Senior Engineer Solution - Customizable Templates
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// Include the permissions check file
require_once '../../includes/check_permissions.php';

$page_title = 'قوالب رسائل الواتساب';
$success_message = '';
$error_message = '';

// Handle template actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                // --- PERMISSION CHECK: canAdd ---
                if (canAdd($_SESSION['user_id'], 'whatsapp')) {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO whatsapp_templates 
                            (template_name, template_content, category, variables, is_active, created_by)
                            VALUES (?, ?, ?, ?, 1, ?)
                        ");
                        $stmt->execute([
                            $_POST['template_name'],
                            $_POST['template_content'],
                            $_POST['category'],
                            $_POST['variables'] ?? '',
                            $_SESSION['user_id']
                        ]);
                        $success_message = 'تم إضافة القالب بنجاح';
                    } catch (PDOException $e) {
                        $error_message = 'خطأ في إضافة القالب: ' . $e->getMessage();
                    }
                } else {
                    $error_message = 'ليس لديك الصلاحية لإضافة قوالب.';
                }
                break;

            case 'edit':
                // --- PERMISSION CHECK: canEdit ---
                if (canEdit($_SESSION['user_id'], 'whatsapp')) {
                    try {
                        $stmt = $db->prepare("
                            UPDATE whatsapp_templates 
                            SET template_name = ?, template_content = ?, category = ?, variables = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $_POST['template_name'],
                            $_POST['template_content'],
                            $_POST['category'],
                            $_POST['variables'] ?? '',
                            $_POST['template_id']
                        ]);
                        $success_message = 'تم تحديث القالب بنجاح';
                    } catch (PDOException $e) {
                        $error_message = 'خطأ في تحديث القالب: ' . $e->getMessage();
                    }
                } else {
                    $error_message = 'ليس لديك الصلاحية لتعديل القوالب.';
                }
                break;

            case 'delete':
                // --- PERMISSION CHECK: canEdit (implies delete) ---
                if (canEdit($_SESSION['user_id'], 'whatsapp')) {
                    try {
                        $stmt = $db->prepare("UPDATE whatsapp_templates SET is_active = 0 WHERE id = ?");
                        $stmt->execute([$_POST['template_id']]);
                        $success_message = 'تم حذف القالب بنجاح';
                    } catch (PDOException $e) {
                        $error_message = 'خطأ في حذف القالب';
                    }
                } else {
                    $error_message = 'ليس لديك الصلاحية لحذف القوالب.';
                }
                break;
        }
    }
}

// Fetch templates
$templates = $db->query("
    SELECT t.*, u.full_name as created_by_name 
    FROM whatsapp_templates t
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.is_active = 1
    ORDER BY t.category, t.template_name
")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    .template-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: all 0.3s;
    }

    .template-card:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }

    .category-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .category-order {
        background: #3b82f6;
        color: white;
    }

    .category-payment {
        background: #C7A46D;
        color: white;
    }

    .category-shipping {
        background: #f59e0b;
        color: white;
    }

    .category-general {
        background: #6b7280;
        color: white;
    }

    .variable-tag {
        display: inline-block;
        background: #e0e7ff;
        color: #4f46e5;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        margin: 2px;
    }

    .whatsapp-preview {
        background: #e5ddd5;
        border-radius: 12px;
        padding: 20px;
        position: relative;
    }

    .whatsapp-message {
        background: #dcf8c6;
        border-radius: 8px;
        padding: 12px;
        max-width: 80%;
        margin-bottom: 10px;
        position: relative;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .whatsapp-message::after {
        content: '';
        position: absolute;
        right: -8px;
        top: 0;
        width: 0;
        height: 0;
        border-left: 8px solid #dcf8c6;
        border-top: 8px solid transparent;
    }
</style>

<div class="container mx-auto px-4 py-8" style="max-width: 1400px;">

    <!-- Header -->
    <div class="bg-gradient-to-r from-amber-600 via-green-700 to-amber-800 rounded-2xl shadow-2xl p-10 mb-8">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-4xl font-black text-white flex items-center gap-4">
                    <i class="fab fa-whatsapp"></i>
                    قوالب رسائل الواتساب
                </h1>
                <p class="text-amber-100 mt-3 text-lg">إدارة وتخصيص قوالب الرسائل للإرسال السريع</p>
            </div>
            <!-- --- PERMISSION CHECK: "Add New Template" Button --- -->
            <?php if (canAdd($_SESSION['user_id'], 'whatsapp')): ?>
                <button onclick="showAddModal()" class="bg-white text-amber-600 px-6 py-3 rounded-xl font-bold hover:bg-amber-50 transition-all shadow-lg">
                    <i class="fas fa-plus ml-2"></i>
                    قالب جديد
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success_message): ?>
        <div class="bg-amber-100 border-l-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6">
            <i class="fas fa-check-circle ml-2"></i>
            <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <!-- Available Variables Info -->
    <div class="bg-blue-50 border-2 border-blue-200 rounded-xl p-6 mb-8">
        <h3 class="text-xl font-bold text-blue-900 mb-4">
            <i class="fas fa-info-circle ml-2"></i>
            المتغيرات المتاحة
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <span class="variable-tag">{customer_name}</span>
                <p class="text-sm text-gray-600 mt-1">اسم العميل</p>
            </div>
            <div>
                <span class="variable-tag">{order_number}</span>
                <p class="text-sm text-gray-600 mt-1">رقم الطلب</p>
            </div>
            <div>
                <span class="variable-tag">{order_total}</span>
                <p class="text-sm text-gray-600 mt-1">إجمالي الطلب (بعد الخصم)</p>
            </div>
            <div>
                <span class="variable-tag">{order_date}</span>
                <p class="text-sm text-gray-600 mt-1">تاريخ الطلب</p>
            </div>
            <div>
                <span class="variable-tag">{order_quantity}</span>
                <p class="text-sm text-gray-600 mt-1">عدد القطع في الطلب</p>
            </div>
            <div>
                <span class="variable-tag">{tracking_number}</span>
                <p class="text-sm text-gray-600 mt-1">رقم التتبع</p>
            </div>
            <div>
                <span class="variable-tag">{payment_amount}</span>
                <p class="text-sm text-gray-600 mt-1">المبلغ المدفوع</p>
            </div>
            <div>
                <span class="variable-tag">{remaining_amount}</span>
                <p class="text-sm text-gray-600 mt-1">المبلغ المتبقي</p>
            </div>
            <div>
                <span class="variable-tag">{company_name}</span>
                <p class="text-sm text-gray-600 mt-1">اسم الشركة</p>
            </div>
            <div>
                <span class="variable-tag">{account_portal}</span>
                <p class="text-sm text-gray-600 mt-1">رابط بوابة حساب العميل</p>
            </div>
            <div>
                <span class="variable-tag">{delivery_date}</span>
                <p class="text-sm text-gray-600 mt-1">تاريخ التسليم المتوقع</p>
            </div>
            <div>
                <span class="variable-tag">{shipping_address}</span>
                <p class="text-sm text-gray-600 mt-1">عنوان الشحن</p>
            </div>
            <div>
                <span class="variable-tag">{payment_link}</span>
                <p class="text-sm text-gray-600 mt-1">رابط الدفع المباشر</p>
            </div>
            <div>
                <span class="variable-tag">{coupon_code}</span>
                <p class="text-sm text-gray-600 mt-1">كود الخصم</p>
            </div>
            <div>
                <span class="variable-tag">{product_name}</span>
                <p class="text-sm text-gray-600 mt-1">اسم المنتج</p>
            </div>
            <div>
                <span class="variable-tag">{agent_name}</span>
                <p class="text-sm text-gray-600 mt-1">اسم الموظف المسؤول</p>
            </div>
            <!-- --- NEW VARIABLES ADDED HERE --- -->
            <div>
                <span class="variable-tag">{gross_total}</span>
                <p class="text-sm text-gray-600 mt-1">إجمالي المبلغ قبل الخصم</p>
            </div>
            <div>
                <span class="variable-tag">{discount_amount}</span>
                <p class="text-sm text-gray-600 mt-1">مبلغ الخصم</p>
            </div>
            <div>
                <span class="variable-tag">{discount_percentage}</span>
                <p class="text-sm text-gray-600 mt-1">نسبة الخصم</p>
            </div>
            <div>
                <span class="variable-tag">{damaged_amount}</span>
                <p class="text-sm text-gray-600 mt-1">مبلغ التوالف / الأضرار</p>
            </div>
            <div>
                <span class="variable-tag">{order_link}</span>
                <p class="text-sm text-gray-600 mt-1">رابط الطلب</p>
            </div>
            <div>
                <span class="variable-tag">{additional_link}</span>
                <p class="text-sm text-gray-600 mt-1">الرابط الإضافي</p>
            </div>
            <!-- --- END OF NEW VARIABLES --- -->
        </div>
    </div>

    <!-- Templates Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($templates as $template): ?>
            <div class="template-card">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">
                            <?php echo htmlspecialchars($template['template_name']); ?>
                        </h3>
                        <span class="category-badge category-<?php echo $template['category']; ?>">
                            <?php
                            $categories = [
                                'order' => 'طلبات',
                                'payment' => 'مدفوعات',
                                'shipping' => 'شحن',
                                'general' => 'عام'
                            ];
                            echo $categories[$template['category']] ?? $template['category'];
                            ?>
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <!-- --- PERMISSION CHECK: Edit and Delete Buttons --- -->
                        <?php if (canEdit($_SESSION['user_id'], 'whatsapp')): ?>
                            <button onclick="editTemplate(<?php echo htmlspecialchars(json_encode($template)); ?>)"
                                class="text-blue-600 hover:text-blue-800 p-2">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteTemplate(<?php echo $template['id']; ?>)"
                                class="text-red-600 hover:text-red-800 p-2">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- WhatsApp Preview -->
                <div class="whatsapp-preview">
                    <div class="whatsapp-message">
                        <?php echo nl2br(htmlspecialchars($template['template_content'])); ?>
                    </div>
                    <div class="text-xs text-gray-500 text-left">
                        <i class="fas fa-clock ml-1"></i>
                        <?php echo date('H:i', strtotime($template['created_at'])); ?>
                    </div>
                </div>

                <!-- Variables Used -->
                <?php if (!empty($template['variables'])): ?>
                    <div class="mt-4">
                        <p class="text-sm text-gray-600 mb-2">المتغيرات المستخدمة:</p>
                        <div>
                            <?php
                            $vars = explode(',', $template['variables']);
                            foreach ($vars as $var):
                            ?>
                                <span class="variable-tag"><?php echo trim($var); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="mt-4 pt-4 border-t flex gap-2">
                    <button onclick="testTemplate(<?php echo $template['id']; ?>)"
                        class="flex-1 bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition-all">
                        <i class="fas fa-vial ml-2"></i>
                        اختبار
                    </button>
                    <button onclick="useTemplate(<?php echo $template['id']; ?>)"
                        class="flex-1 bg-amber-500 text-white px-4 py-2 rounded-lg hover:bg-amber-600 transition-all">
                        <i class="fab fa-whatsapp ml-2"></i>
                        استخدام
                    </button>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($templates)): ?>
            <div class="col-span-2 text-center py-20">
                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                <p class="text-xl text-gray-500">لا توجد قوالب بعد</p>
                <!-- --- PERMISSION CHECK: "Add New Template" Button (for empty state) --- -->
                <?php if (canAdd($_SESSION['user_id'], 'whatsapp')): ?>
                    <button onclick="showAddModal()" class="mt-4 bg-amber-600 text-white px-6 py-3 rounded-lg hover:bg-amber-700">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة قالب جديد
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Template Modal -->
<div id="templateModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-2xl p-8 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 id="modalTitle" class="text-2xl font-bold text-gray-900">قالب جديد</h2>
            <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-2xl"></i>
            </button>
        </div>

        <form method="POST" id="templateForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="template_id" id="templateId">

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-tag ml-1"></i>
                    اسم القالب *
                </label>
                <input type="text" name="template_name" id="templateName" required
                    class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none"
                    placeholder="مثال: رسالة تأكيد الطلب">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-folder ml-1"></i>
                    التصنيف *
                </label>
                <select name="category" id="templateCategory" required
                    class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none">
                    <option value="order">طلبات</option>
                    <option value="payment">مدفوعات</option>
                    <option value="shipping">شحن</option>
                    <option value="general">عام</option>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-comment-alt ml-1"></i>
                    محتوى الرسالة *
                </label>
                <textarea name="template_content" id="templateContent" required rows="6"
                    class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none"
                    placeholder="مرحباً {customer_name}، تم استلام طلبك رقم {order_number} بنجاح..."></textarea>
                <p class="text-sm text-gray-500 mt-2">
                    <i class="fas fa-lightbulb ml-1"></i>
                    استخدم المتغيرات مثل {customer_name} و {order_number}
                </p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-code ml-1"></i>
                    المتغيرات المستخدمة
                </label>
                <input type="text" name="variables" id="templateVariables"
                    class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-amber-500 focus:outline-none"
                    placeholder="{customer_name}, {order_number}, {gross_total}, {discount_amount}, {damaged_amount}, {order_link}, {additional_link}">
                <p class="text-sm text-gray-500 mt-2">افصل المتغيرات بفاصلة</p>
            </div>

            <div class="flex gap-4">
                <button type="button" onclick="closeModal()"
                    class="flex-1 bg-gray-500 text-white px-6 py-3 rounded-lg hover:bg-gray-600 transition-all">
                    إلغاء
                </button>
                <button type="submit"
                    class="flex-1 bg-amber-600 text-white px-6 py-3 rounded-lg hover:bg-amber-700 transition-all">
                    <i class="fas fa-save ml-2"></i>
                    حفظ القالب
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function showAddModal() {
        document.getElementById('modalTitle').textContent = 'قالب جديد';
        document.getElementById('formAction').value = 'add';
        document.getElementById('templateForm').reset();
        document.getElementById('templateModal').style.display = 'flex';
    }

    function editTemplate(template) {
        document.getElementById('modalTitle').textContent = 'تعديل القالب';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('templateId').value = template.id;
        document.getElementById('templateName').value = template.template_name;
        document.getElementById('templateCategory').value = template.category;
        document.getElementById('templateContent').value = template.template_content;
        document.getElementById('templateVariables').value = template.variables || '';
        document.getElementById('templateModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('templateModal').style.display = 'none';
    }

    function deleteTemplate(id) {
        if (confirm('هل أنت متأكد من حذف هذا القالب؟')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="template_id" value="${id}">
        `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    function testTemplate(id) {
        window.open('test_template.php?id=' + id, '_blank', 'width=400,height=600');
    }

    function useTemplate(id) {
        window.location.href = 'send.php?template_id=' + id;
    }

    // Close modal on outside click
    document.getElementById('templateModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>