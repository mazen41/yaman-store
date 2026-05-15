<?php
// modules/orders/view_approval.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php';
require_once '../../includes/auto_generate_helpers.php';
require_once '../../includes/status_helpers.php'; // Added for getOrderStatusBadge

if (!canOpenOrderApprovalDetail($_SESSION['user_id'])) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل/الموافقة على الطلبات.';
    header('Location: index.php');
    exit();
}

$can_approve_sa = canApproveOrderApprovals($_SESSION['user_id']);
$can_reject_sa = canRejectOrderApprovals($_SESSION['user_id']);

$approval_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$approval_id) {
    header('Location: approvals.php');
    exit();
}

// --- Fetch Approval Details ---
$app_stmt = $db->prepare("
    SELECT oa.*, c.name as customer_name_from_customer_table, c.customer_code, c.mobile_number as customer_mobile_number, c.whatsapp_number as customer_whatsapp_number, ct.name as customer_type_name_from_db
    FROM order_approvals oa
    LEFT JOIN customers c ON oa.customer_id = c.id
    LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
    WHERE oa.id = ?
");
$app_stmt->execute([$approval_id]);
$approval = $app_stmt->fetch(PDO::FETCH_ASSOC);

if (!$approval || $approval['status'] !== 'pending') {
    $_SESSION['error_message'] = 'الطلب غير موجود أو تمت معالجته بالفعل.';
    header('Location: approvals.php');
    exit();
}

// --- Fetch Items ---
$items_stmt = $db->prepare("SELECT * FROM order_approval_items WHERE approval_id = ?");
$items_stmt->execute([$approval_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    $items[] = ['product_link' => '', 'additional_link' => '', 'item_count' => 1, 'total' => 0, 'notes' => ''];
}

// --- Fetch Approval Images ---
$images_stmt = $db->prepare("SELECT * FROM order_approvals_images WHERE approval_id = ? ORDER BY display_order ASC");
$images_stmt->execute([$approval_id]);
$approval_images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);


// --- Data Prep ---
$customer_currency = $approval['currency'] ?? 'USD';
$customer_type_name = $approval['customer_type_name_from_db'] ?? 'عام';
$customer_name = $approval['customer_name_from_customer_table'] ?? $approval['customer_name'];
$customer_code = $approval['customer_code'] ?? 'N/A';
$payment_proof_path = $approval['payment_proof_path'] ?? ''; 

// --- Fetch Active Bank Accounts for selection ---
try {
    $bank_stmt = $db->query("SELECT id, bank_name, account_number, account_holder_name, currency FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
    $bank_accounts = $bank_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bank_accounts = [];
    $_SESSION['error_message'] = 'فشل في تحميل الحسابات البنكية.';
}


$page_title = "مراجعة وتعديل الطلب #{$approval['id']}";
include '../../includes/header.php';
?>

<!-- Custom Styles & Animations -->
<style>
    :root {
        --primary-gold: #C7A46D;
        --dark-gold: #B8956A;
        --light-gold: #FDF7EC; /* For backgrounds/borders */
        --primary-color: #2563eb; 
        --secondary-color: #1e40af; 
        --success-grad: linear-gradient(135deg, #059669 0%, #10b981 100%); 
        --danger-grad: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); 
        --card-bg: #ffffff; 
        --bg-body: #f3f4f6; 
        --custom-text-color: #C7A46D; /* This is primary-gold */
        --custom-light-gray: #e0e0e0; 
        --text-on-light: #333333;
    }
    body { background-color: var(--bg-body); font-family: 'Tajawal', sans-serif; color: var(--text-on-light); }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    .animate-entry { animation: fadeInUp 0.5s ease-out forwards; }
    .delay-1 { animation-delay: 0.1s; } .delay-2 { animation-delay: 0.2s; } .delay-3 { animation-delay: 0.3s; }
    .glass-card { background: rgba(255, 255, 255, 0.95); border: 1px solid rgba(255, 255, 255, 0.3); box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05); border-radius: 16px; backdrop-filter: blur(10px); transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .glass-card:hover { transform: translateY(-2px); box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.1); }
    .modern-input { width: 100%; padding: 0.75rem 1rem; border: 2px solid var(--custom-light-gray); border-radius: 10px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: #f9fafb; color: var(--text-on-light); }
    .modern-input:focus { border-color: var(--primary-gold); background: #fff; box-shadow: 0 0 0 4px rgba(199, 164, 109, 0.2); outline: none; }
    .image-thumbnail-container { position: relative; overflow: hidden; border-radius: 12px; border: 2px dashed var(--custom-light-gray); background: #f8fafc; transition: all 0.3s; }
    .image-thumbnail-container:hover { border-color: var(--primary-gold); background: #fdfae7; }
    .image-thumbnail-img { width: 100%; height: 100%; display: block; object-fit: cover; }
    .zoom-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; cursor: pointer; }
    .image-thumbnail-container:hover .zoom-overlay { opacity: 1; }
    #lightbox { display: none; position: fixed; z-index: 9999; padding-top: 50px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.9); }
    #lightbox-content { margin: auto; display: block; width: 80%; max-width: 900px; animation: zoom 0.6s; border-radius: 8px; }
    @keyframes zoom { from {transform:scale(0)} to {transform:scale(1)} }
    .btn-grad-primary { background: linear-gradient(135deg, var(--dark-gold) 0%, var(--primary-gold) 100%); color: white; border: none; }
    .btn-grad-danger { background: var(--danger-grad); color: white; border: none; }
    .custom-modal { display: none; position: fixed; z-index: 50; inset: 0; overflow-y: auto; background-color: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); }
    .custom-modal.show { display: flex; align-items: center; justify-content: center; }
    .item-card { background-color: #fff; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1rem; position: relative; }
    .item-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; }
    .item-card-header h4 { font-size: 1rem; font-weight: 600; color: #333; }
    .item-card-body > div { margin-bottom: 1rem; }
    .remove-item-button { position: absolute; top: 0.75rem; left: 0.75rem; color: #ef4444; cursor: pointer; font-size: 1.25rem; }
    .remove-item-button:hover { color: #dc2626; }
    .image-delete-label { position: absolute; top: 8px; right: 8px; z-index: 10; cursor: pointer; background-color: rgba(255, 255, 255, 0.8); border-radius: 50%; padding: 4px; display: flex; align-items: center; justify-content: center; }
    .image-delete-label input { opacity: 0; width: 0; height: 0; }
    .image-delete-label .fa-trash-alt { color: #ef4444; }
    .image-delete-label input:checked + .fa-trash-alt { color: #b91c1c; }

    /* Custom styles for non-editable discount fields */
    .non-editable-field {
        background-color: #e0e0e0; /* A light gray background to indicate non-editability */
        opacity: 0.8; /* Slightly faded */
        cursor: not-allowed; /* Change cursor to "not-allowed" */
    }
</style>

<div class="min-h-screen py-8 px-4 sm:px-6 lg:px-8" dir="rtl">
    <div class="max-w-7xl mx-auto">
        <div class="glass-card mb-8 p-6 bg-gray-900/90 border-none animate-entry" style="background: linear-gradient(to left, var(--primary-gold), var(--dark-gold));">
            <div class="flex flex-col md:flex-row justify-between items-center text-white">
                <div>
                    <h1 class="text-2xl font-bold">مراجعة الطلب #<?php echo $approval_id; ?></h1>
                    <p class="text-gray-100 text-sm">يرجى التحقق من البيانات قبل الموافقة.</p>
                </div>
                <a href="pending.php" class="px-5 py-2.5 rounded-xl bg-white/20 hover:bg-white/30 transition text-white text-sm font-semibold">
                    <i class="fas fa-arrow-left"></i> عودة
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">خطأ!</p>
                <p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="api/approve_order.php" id="approvalForm" enctype="multipart/form-data">
            <input type="hidden" name="approval_id" value="<?php echo $approval_id; ?>">
            <input type="hidden" name="deleted_images" id="deletedImages" value="">

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                
                <div class="lg:col-span-8 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="glass-card p-6 animate-entry delay-1">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2"><i class="fas fa-user-circle mr-2 text-primary-gold"></i> بيانات العميل</h3>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between p-2 bg-gray-50 rounded"><span>الاسم:</span><span class="font-bold"><?php echo htmlspecialchars($customer_name); ?></span></div>
                                <div class="flex justify-between p-2 bg-gray-50 rounded"><span>الكود:</span><span class="font-mono"><?php echo htmlspecialchars($customer_code); ?></span></div>
                                <div class="flex justify-between p-2 bg-gray-50 rounded"><span>التصنيف:</span><span class="font-semibold text-purple-600"><?php echo htmlspecialchars($customer_type_name); ?></span></div>
                            </div>
                        </div>
                        
                        <!-- PAYMENT PROOF - NOW EDITABLE -->
                        <div class="glass-card p-6 animate-entry delay-1">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2"><i class="fas fa-receipt mr-2 text-green-600"></i> إيصال الدفع</h3>
                            <div id="paymentProofContainer">
                                <?php if (!empty($payment_proof_path)): $img_path = "../../" . htmlspecialchars($payment_proof_path); ?>
                                    <div class="image-thumbnail-container h-40 mb-2">
                                        <img src="<?php echo $img_path; ?>" class="image-thumbnail-img">
                                        <div class="zoom-overlay" onclick="openLightbox('<?php echo $img_path; ?>')"><i class="fas fa-search-plus text-white text-3xl"></i></div>
                                        <label class="image-delete-label" title="حذف الإيصال الحالي">
                                            <input type="checkbox" name="delete_payment_proof" onchange="this.closest('.image-thumbnail-container').classList.toggle('opacity-50', this.checked)">
                                            <i class="fas fa-trash-alt"></i>
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <div class="h-40 flex items-center justify-center border-2 border-dashed rounded text-gray-400 mb-2"><span>لم يتم إرفاق صورة</span></div>
                                <?php endif; ?>
                            </div>
                             <div id="paymentProofPreviewContainer"></div>
                            <div class="mt-2">
                                <label for="new_payment_proof_image" class="text-sm font-medium text-gray-700"><?php echo !empty($payment_proof_path) ? 'تغيير الإيصال' : 'رفع إيصال جديد'; ?></label>
                                <input type="file" name="new_payment_proof_image" id="new_payment_proof_image" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100" onchange="previewPaymentProof(this)">
                            </div>
                        </div>
                        <!-- END EDITABLE PAYMENT PROOF -->
                    </div>

                    <!-- Order Approval Images Card - Editable -->
                    <div class="glass-card p-6 animate-entry delay-2">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                            <i class="fas fa-images mr-2 text-primary-gold"></i> الصور المرفقة بالطلب
                        </h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mb-4" id="existingImagesContainer">
                            <?php if (!empty($approval_images)): ?>
                                <?php foreach ($approval_images as $image): ?>
                                    <?php $image_path_full = "../../" . htmlspecialchars($image['image_path']); ?>
                                    <div class="image-thumbnail-container h-24">
                                        <img src="<?php echo $image_path_full; ?>" alt="<?php echo htmlspecialchars($image['image_name']); ?>" class="image-thumbnail-img">
                                        <div class="zoom-overlay" onclick="openLightbox('<?php echo $image_path_full; ?>')"><i class="fas fa-search-plus text-white text-xl"></i></div>
                                        <label class="image-delete-label" title="حذف الصورة">
                                            <input type="checkbox" onchange="markForDeletion(this)" value="<?php echo $image['id']; ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div id="noExistingImages" class="col-span-full h-24 flex items-center justify-center border-2 border-dashed rounded text-gray-400">
                                    <span>لم يتم إرفاق صور إضافية</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <label for="new_approval_images" class="block text-sm font-medium text-gray-700 mb-1">رفع صور جديدة (اختياري)</label>
                            <input type="file" id="new_approval_images" name="new_approval_images[]" multiple 
                                class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary-gold/10 file:text-primary-gold hover:file:bg-primary-gold/20" 
                                onchange="previewNewImages(this)">
                            <div id="newImagePreviewContainer" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mt-4 hidden"></div>
                        </div>
                    </div>
                    <!-- END Order Approval Images Card -->

                    <div class="glass-card p-6 animate-entry delay-2">
                         <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-gray-800"><i class="fas fa-box-open mr-2 text-primary-gold"></i> المنتجات المطلوبة</h3>
                       
                        </div>
                        <div id="itemsContainer" class="space-y-4">
                            <?php foreach($items as $i => $item): ?>
                            <div class="item-card item-row" data-index="<?php echo $i; ?>">
                                <button type="button" class="remove-item-button" onclick="removeItem(this)"><i class="fas fa-times-circle"></i></button>
                                <div class="item-card-header">
                                    <h4 class="item-number">المنتج #<span><?php echo $i + 1; ?></span></h4>
                                </div>
                                <div class="item-card-body">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">المنتج / الرابط الأول</label>
                                        <input type="text" name="items[<?php echo $i; ?>][product_link]" value="<?php echo htmlspecialchars($item['product_link'] ?? ''); ?>" class="modern-input" placeholder="رابط المنتج أو وصف">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">الرابط الإضافي</label>
                                        <input type="text" name="items[<?php echo $i; ?>][additional_link]" value="<?php echo htmlspecialchars($item['additional_link'] ?? ''); ?>" class="modern-input" placeholder="رابط إضافي للمنتج">
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">الكمية</label>
                                            <input type="number" name="items[<?php echo $i; ?>][item_count]" value="<?php echo $item['item_count'] ?? 1; ?>" min="1" class="modern-input item-quantity" oninput="updateTotals()">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">السعر (<?php echo $customer_currency; ?>)</label>
                                            <input type="number" name="items[<?php echo $i; ?>][total]" value="<?php echo $item['total'] ?? 0; ?>" min="0" step="0.01" class="modern-input item-total-input" oninput="updateTotals()">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                                        <textarea name="items[<?php echo $i; ?>][notes]" rows="2" class="modern-input" placeholder="ملاحظات خاصة بالمنتج"><?php echo htmlspecialchars($item['notes'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-6 pt-6 border-t">
                            <label class="block text-sm font-medium">ملاحظات عامة على الطلب (تظهر للعميل)</label>
                            <textarea name="notes" class="modern-input" rows="2"><?php echo htmlspecialchars($approval['notes']); ?></textarea>
                        </div>
                        
                        <!-- اضافة حقل ملاحظات الادارة -->
                        <div class="mt-4 pt-4 border-t border-gray-100">
                            <label class="block text-sm font-bold text-gray-700 mb-1"><i class="fas fa-user-tie text-primary-gold mr-1"></i> ملاحظات الإدارة - اختياري)</label>
                            <textarea name="admin_notes" id="main_admin_notes" class="modern-input" rows="3" placeholder="أضف أي ملاحظات إدارية هنا..."><?php echo htmlspecialchars($approval['admin_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-4 space-y-6">
                    <div class="glass-card p-6 animate-entry delay-3 sticky top-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-6"><i class="fas fa-calculator mr-2 text-primary-gold"></i> الحسابات المالية</h3>
                        <div class="space-y-4">
                             <div><label>تاريخ التسليم المتوقع</label><input type="date" name="expected_delivery_date" value="<?php echo htmlspecialchars($approval['expected_delivery_date']); ?>" class="modern-input"></div>
                            <div><label>الشحن</label><input type="number" name="shipping_cost" id="shipping_cost_admin" value="<?php echo $approval['shipping_cost']; ?>" class="modern-input" oninput="updateTotals()"></div>
                            <div class="grid grid-cols-2 gap-3">
                                <div><label>خصم (%)</label>
                                <input type="number" name="automatic_discount_percentage" id="automatic_discount_percentage" value="<?php echo $approval['automatic_discount_percentage']; ?>" class="modern-input non-editable-field" readonly></div>
                                <div><label>خصم (مبلغ)</label>
                                <input type="number" name="automatic_discount_amount" id="automatic_discount_amount" value="<?php echo $approval['automatic_discount_amount']; ?>" class="modern-input non-editable-field" readonly></div>
                            </div>
                            <div><label>كود الكوبون</label>
                            <input type="text" name="coupon_code" value="<?php echo $approval['coupon_code']; ?>" class="modern-input non-editable-field" readonly></div>
                            <div><label>قيمة خصم الكوبون</label>
                            <input type="number" name="coupon_discount_amount" id="coupon_discount_amount" value="<?php echo $approval['coupon_discount_amount'] ?? 0; ?>" class="modern-input non-editable-field" readonly></div>
                            
                            <!-- Display paid amount before buttons, remove it from here -->
                            
                            <!-- NEW: Payment Method Selection -->
                            <div class="mt-4">
                                <label for="payment_method" class="block text-sm font-bold text-gray-700 mb-2">طريقة الدفع <span class="text-red-500">*</span></label>
                                <select id="payment_method" name="payment_method" class="modern-input" required>
                                    <option value="" selected disabled>-- اختر طريقة الدفع --</option>
                                    <option value="cash">نقدي (الصندوق)</option>
                                    <option value="transfer">تحويل بنكي / إيداع</option>
                                    <option value="customer_card">بطاقة العميل</option>
                                </select>
                            </div>

                            <!-- NEW: Bank Account Selection (Initially hidden) -->
                            <div id="bankAccountDiv" class="mt-2 p-3 bg-gray-50 rounded-lg border border-gray-200" style="display: none;">
                                <label for="bank_account_id" class="block text-sm font-bold text-gray-700 mb-2">الحساب البنكي المستلم <span class="text-red-500">*</span></label>
                                <select id="bank_account_id" name="bank_account_id" class="modern-input">
                                    <option value="">-- اختر الحساب البنكي --</option>
                                    <?php foreach ($bank_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>">
                                            <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_holder_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- NEW: Customer Card Number Input (Initially hidden) -->
                            <div id="customerCardDiv" class="mt-2 p-3 bg-gray-50 rounded-lg border border-gray-200" style="display: none;">
                                <label for="customer_card_number" class="block text-sm font-bold text-gray-700 mb-2">رقم بطاقة العميل <span class="text-red-500">*</span></label>
                                <input type="text" id="customer_card_number" name="customer_card_number" 
                                       class="modern-input" 
                                       placeholder="أدخل رقم البطاقة هنا" pattern="[0-9]*" inputmode="numeric">
                                <p id="cardBalanceInfo" class="text-sm text-gray-600 mt-2" style="display: none;">الرصيد المتاح: <span class="font-bold text-blue-700"></span></p>
                            </div>

                            <!-- NEW: Reference Number -->
                            <div class="mt-4">
                                <label for="reference_number" class="block text-sm font-bold text-gray-700 mb-2">رقم المرجع (اختياري)</label>
                                <input type="text" id="reference_number" name="reference_number" class="modern-input" placeholder="مثال: رقم الحوالة، رقم الشيك...">
                            </div>


                            <hr class="my-6">
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between"><span>المجموع الفرعي:</span><span id="subtotalDisplay">0.00</span></div>
                                <div class="flex justify-between text-red-500"><span>إجمالي الخصم:</span><span>-<span id="totalDiscountDisplay">0.00</span></span></div>
                                <div class="flex justify-between"><span>الشحن:</span><span>+<span id="shippingDisplay">0.00</span></span></div>
                                <div class="flex justify-between"><span>المبلغ المدفوع:</span><span id="paidAmountDisplay" class="font-bold text-green-600">0.00</span></div>
                                <div class="flex justify-between bg-gray-800 text-white p-4 rounded-xl mt-4" style="background: linear-gradient(135deg, var(--dark-gold) 0%, var(--primary-gold) 100%);">
                                    <span class="font-bold text-lg">الصافي النهائي:</span>
                                    <span class="font-mono text-2xl font-bold" id="finalTotalDisplay">0.00</span>
                                </div>
                            </div>
                            <!-- Paid amount moved here, before approve/reject buttons -->
                            <div class="mt-4">
                                <label>المبلغ المدفوع</label>
                                <input type="number" name="paid_amount" id="paid_amount_admin" value="<?php echo $approval['paid_amount']; ?>" class="modern-input" required oninput="updateTotals()">
                            </div>

                            <div class="grid grid-cols-1 gap-3 pt-4">
                                <?php if ($can_approve_sa): ?>
                                <button type="submit" name="action" value="approve" onclick="return validateAndConfirmApproval();" class="btn-grad-primary py-3.5 rounded-xl font-bold">اعتماد وموافقة</button>
                                <?php endif; ?>
                                <?php if ($can_reject_sa): ?>
                                <button type="button" onclick="openModal()" class="btn-grad-danger py-3 rounded-xl font-bold">رفض الطلب</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<template id="itemCardTemplate">
    <div class="item-card item-row" data-index="_INDEX_">
        <button type="button" class="remove-item-button" onclick="removeItem(this)"><i class="fas fa-times-circle"></i></button>
        <div class="item-card-header">
            <h4 class="item-number">المنتج #<span></span></h4>
        </div>
        <div class="item-card-body">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">المنتج / الرابط الأول</label>
                <input type="text" name="items[_INDEX_][product_link]" class="modern-input" placeholder="رابط المنتج أو وصف">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">الرابط الإضافي</label>
                <input type="text" name="items[_INDEX_][additional_link]" class="modern-input" placeholder="رابط إضافي للمنتج">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">الكمية</label>
                    <input type="number" name="items[_INDEX_][item_count]" value="1" min="1" class="modern-input item-quantity" oninput="updateTotals()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">السعر (<?php echo $customer_currency; ?>)</label>
                    <input type="number" name="items[_INDEX_][total]" value="0" min="0" step="0.01" class="modern-input item-total-input" oninput="updateTotals()">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                <textarea name="items[_INDEX_][notes]" rows="2" class="modern-input" placeholder="ملاحظات خاصة بالمنتج"></textarea>
            </div>
        </div>
    </div>
</template>

<div id="rejectModal" class="custom-modal">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md m-4" id="modalContent">
        <h5 class="text-red-700 font-bold p-4 border-b">رفض الطلب</h5>
        <form action="api/reject_order.php" method="POST">
            <input type="hidden" name="whatsapp_contacts_form" value="1">
            <input type="hidden" name="approval_id" value="<?php echo $approval_id; ?>">
            <div class="p-6">
                <p class="mb-3 text-sm font-bold">يرجى توضيح سبب الرفض (إلزامي - سيظهر للعميل):</p>
                <textarea name="rejection_reason" class="modern-input w-full h-24 mb-4" required></textarea>
                
                <p class="mb-3 text-sm font-bold text-gray-600">ملاحظات الإدارة (داخلية فقط - اختياري):</p>
                <textarea name="admin_notes" id="modal_admin_notes" class="modern-input w-full h-20 placeholder-gray-400" placeholder="احتفظ بملاحظاتك حول سبب الرفض هنا..."></textarea>

                <p class="mb-2 mt-4 text-sm font-bold text-gray-800">إرسال إشعار واتساب بالرفض إلى:</p>
                <div class="space-y-2 text-right border border-gray-100 rounded-lg p-3 bg-gray-50">
                    <?php
                    $mob = trim($approval['customer_mobile_number'] ?? '');
                    $wa = trim($approval['customer_whatsapp_number'] ?? '');
                    $has_any = ($mob !== '' || $wa !== '');
                    if (!$has_any): ?>
                        <p class="text-xs text-gray-500">لا توجد أرقار مسجلة للعميل.</p>
                    <?php else: ?>
                        <?php if ($mob !== ''): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" name="whatsapp_contacts[]" value="mobile" class="rounded" checked>
                            <span>الجوال: <?php echo htmlspecialchars($mob); ?></span>
                        </label>
                        <?php endif; ?>
                        <?php if ($wa !== ''): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" name="whatsapp_contacts[]" value="whatsapp" class="rounded" <?php echo ($mob === '') ? 'checked' : ''; ?>>
                            <span>واتساب: <?php echo htmlspecialchars($wa); ?></span>
                        </label>
                        <?php endif; ?>
                        <p class="text-xs text-gray-500 mt-2">اترك كل الخيارات غير محددة لعدم إرسال إشعار واتساب.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bg-gray-50 p-4 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-white border rounded">إلغاء</button>
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 transition text-white rounded">تأكيد الرفض</button>
            </div>
        </form>
    </div>
</div>

<div id="lightbox" onclick="closeLightbox()"><img id="lightbox-content"></div>

<script>
    function openLightbox(src) { document.getElementById('lightbox').style.display = "flex"; document.getElementById('lightbox-content').src = src; }
    function closeLightbox() { document.getElementById('lightbox').style.display = "none"; }
    
    function openModal() { 
        document.getElementById('rejectModal').classList.add('show'); 
        // Sync the admin notes to the modal if user already typed something
        document.getElementById('modal_admin_notes').value = document.getElementById('main_admin_notes').value;
    }
    
    function closeModal() { document.getElementById('rejectModal').classList.remove('show'); }

    let itemIndex = <?php echo count($items); ?>;
    let deletedImageIds = []; 

    function markForDeletion(checkbox) {
        const imageId = checkbox.value;
        const parentContainer = checkbox.closest('.image-thumbnail-container');
        if (checkbox.checked) {
            deletedImageIds.push(imageId);
            parentContainer.classList.add('opacity-50');
        } else {
            deletedImageIds = deletedImageIds.filter(id => id !== imageId);
            parentContainer.classList.remove('opacity-50');
        }
        document.getElementById('deletedImages').value = JSON.stringify(deletedImageIds);
    }

    function removeItem(button) {
        button.closest('.item-row').remove();
        updateItemCardNumbers();
        updateTotals();
    }
    
    function updateItemCardNumbers() {
        document.querySelectorAll('#itemsContainer .item-row').forEach((card, index) => {
            card.querySelector('.item-number span').textContent = index + 1;
            card.querySelectorAll('[name^="items["]').forEach(input => {
                input.name = input.name.replace(/items\[\d+\]/, `items[${index}]`);
            });
        });
    }

    function addItem() {
        const container = document.getElementById('itemsContainer');
        const template = document.getElementById('itemCardTemplate').innerHTML.replace(/_INDEX_/g, itemIndex++);
        container.insertAdjacentHTML('beforeend', template);
        updateItemCardNumbers();
        updateTotals();
    }
    
    function updateTotals() {
        let subtotal = Array.from(document.querySelectorAll('.item-total-input')).reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
        const shippingCost = parseFloat(document.getElementById('shipping_cost_admin').value) || 0;
        const paidAmount = parseFloat(document.getElementById('paid_amount_admin').value) || 0;
        
        // Discount fields are now readonly, fetch their values directly
        const autoDiscountAmt = parseFloat(document.getElementById('automatic_discount_amount').value) || 0;
        const couponDiscountAmt = parseFloat(document.getElementById('coupon_discount_amount').value) || 0;
        const autoDiscountPercent = parseFloat(document.getElementById('automatic_discount_percentage').value) || 0;

        let totalDiscount = autoDiscountAmt + couponDiscountAmt + (subtotal * (autoDiscountPercent / 100));
        let finalAmount = Math.max(0, subtotal - totalDiscount + shippingCost);
        let finalAmountAfterPaid = finalAmount - paidAmount;

        document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
        document.getElementById('totalDiscountDisplay').textContent = totalDiscount.toFixed(2);
        document.getElementById('shippingDisplay').textContent = shippingCost.toFixed(2);
        document.getElementById('paidAmountDisplay').textContent = paidAmount.toFixed(2); // Display the paid amount
        document.getElementById('finalTotalDisplay').textContent = finalAmountAfterPaid.toFixed(2);
    }

    function previewNewImages(input) {
        const container = document.getElementById('newImagePreviewContainer');
        container.innerHTML = ''; 
        if (input.files && input.files.length > 0) {
            container.classList.remove('hidden');
            for (const file of input.files) {
                const reader = new FileReader();
                reader.onload = e => {
                    container.innerHTML += `<div class="relative group"><img src="${e.target.result}" class="w-full h-24 object-cover rounded-lg border"></div>`;
                };
                reader.readAsDataURL(file);
            }
        } else {
            container.classList.add('hidden');
        }
    }

    function previewPaymentProof(input) {
        const previewContainer = document.getElementById('paymentProofPreviewContainer');
        const existingContainer = document.getElementById('paymentProofContainer');
        previewContainer.innerHTML = ''; 
        if (input.files && input.files[0]) {
            existingContainer.style.display = 'none'; // Hide the old proof if a new one is selected
            const reader = new FileReader();
            reader.onload = e => {
                previewContainer.innerHTML = `<div class="image-thumbnail-container h-40 mb-2"><img src="${e.target.result}" class="image-thumbnail-img"><div class="absolute inset-0 bg-black bg-opacity-25 flex items-center justify-center"><span class="text-white font-bold">إيصال جديد</span></div></div>`;
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            existingContainer.style.display = 'block'; // Show the old one again if user cancels
        }
    }

    // NEW: Payment Method Logic
    const paymentMethodSelect = document.getElementById('payment_method');
    const bankAccountContainer = document.getElementById('bankAccountDiv');
    const bankAccountSelect = document.getElementById('bank_account_id');
    const customerCardContainer = document.getElementById('customerCardDiv');
    const customerCardNumberInput = document.getElementById('customer_card_number');
    const cardBalanceInfo = document.getElementById('cardBalanceInfo');
    const cardBalanceSpan = cardBalanceInfo ? cardBalanceInfo.querySelector('span') : null;

    function togglePaymentMethodFields() {
        bankAccountContainer.style.display = 'none';
        bankAccountSelect.required = false;
        bankAccountSelect.value = '';

        customerCardContainer.style.display = 'none';
        customerCardNumberInput.required = false;
        customerCardNumberInput.value = '';
        if (cardBalanceInfo) cardBalanceInfo.style.display = 'none';

        if (paymentMethodSelect.value === 'transfer') {
            bankAccountContainer.style.display = 'block';
            bankAccountSelect.required = true;
        } else if (paymentMethodSelect.value === 'customer_card') {
            customerCardContainer.style.display = 'block';
            customerCardNumberInput.required = true;
            if (cardBalanceInfo) cardBalanceInfo.style.display = 'block'; 
            if (cardBalanceSpan) cardBalanceSpan.textContent = '...جار التحقق';
            // Trigger balance check if a number is already present
            if (customerCardNumberInput.value.trim().length >= 6) {
                triggerCardBalanceCheck();
            }
        }
    }

    let balanceCheckTimeout;
    customerCardNumberInput.addEventListener('input', function() {
        clearTimeout(balanceCheckTimeout);
        if (this.value.trim().length >= 6) {
            if (cardBalanceSpan) cardBalanceSpan.textContent = '...جاري التحقق';
            balanceCheckTimeout = setTimeout(triggerCardBalanceCheck, 500);
        } else {
            if (cardBalanceSpan) cardBalanceSpan.textContent = 'أدخل رقم البطاقة للتحقق من الرصيد.';
            customerCardNumberInput.removeAttribute('data-balance');
        }
    });

    function triggerCardBalanceCheck() {
        const cardNumber = customerCardNumberInput.value.trim();
        fetch(`api/check_card_balance.php?card_number=${encodeURIComponent(cardNumber)}`)
            .then(response => response.json())
            .then(data => {
                if (cardBalanceSpan) {
                    if (data.success && data.card) {
                        cardBalanceSpan.textContent = `${parseFloat(data.card.current_balance).toFixed(2)} ريال`;
                        customerCardNumberInput.setAttribute('data-balance', data.card.current_balance);
                    } else {
                        cardBalanceSpan.textContent = data.message || 'البطاقة غير موجودة أو غير نشطة.';
                        customerCardNumberInput.removeAttribute('data-balance');
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching card balance:', error);
                if (cardBalanceSpan) cardBalanceSpan.textContent = 'خطأ في الاتصال بالخادم.';
                customerCardNumberInput.removeAttribute('data-balance');
            });
    }

    // NEW: Client-side validation for payment method and amount
    function validateAndConfirmApproval() {
        const paidAmount = parseFloat(document.getElementById('paid_amount_admin').value);
        const paymentMethod = paymentMethodSelect.value;

        if (!paymentMethod) {
            alert('يرجى اختيار طريقة الدفع قبل الموافقة.');
            paymentMethodSelect.focus();
            return false;
        }

        if (paidAmount > 0) {
            if (paymentMethod === 'transfer' && bankAccountSelect.value === '') {
                alert('يرجى اختيار الحساب البنكي المستلم عند دفع مبلغ.');
                return false;
            } else if (paymentMethod === 'customer_card') {
                const cardNumber = customerCardNumberInput.value.trim();
                const cardBalanceAttr = customerCardNumberInput.getAttribute('data-balance');

                if (cardNumber === '') {
                    alert('يرجى إدخال رقم بطاقة العميل عند استخدام طريقة "بطاقة العميل".');
                    return false;
                }
                if (!cardBalanceAttr || isNaN(parseFloat(cardBalanceAttr))) {
                    alert('يرجى التحقق من رقم بطاقة العميل والتأكد من صحتها ورصيدها قبل الموافقة.');
                    return false;
                }
                const cardBalance = parseFloat(cardBalanceAttr);
                if (paidAmount > cardBalance) {
                    alert(`خطأ: المبلغ المدفوع (${paidAmount.toFixed(2)} ريال) يتجاوز الرصيد المتاح في البطاقة (${cardBalance.toFixed(2)} ريال).`);
                    return false;
                }
            }
        }
        
        return confirm('هل أنت متأكد من الموافقة على هذا الطلب وإنشاء فاتورة؟');
    }


    document.addEventListener('DOMContentLoaded', () => {
        updateTotals();
        updateItemCardNumbers();
        togglePaymentMethodFields(); // Initialize payment method fields on load
        paymentMethodSelect.addEventListener('change', togglePaymentMethodFields); // Add event listener
    });
</script>

<?php include '../../includes/footer.php'; ?>