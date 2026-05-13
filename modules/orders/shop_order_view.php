<?php
session_start();

// --- 1. CONFIG & PERMISSIONS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Permissions check
// CHANGE START: Separate permissions for view, approve, and reject
$can_view = hasPermission($user_id, 'shop_orders', 'view');
$can_approve = hasPermission($user_id, 'shop_orders', 'approve');
$can_reject = hasPermission($user_id, 'shop_orders', 'reject');
// CHANGE END

// If user does not have view permission, they should not see anything
if (!$can_view) {
    echo "<div class='alert alert-danger'>ليس لديك صلاحية لعرض تفاصيل الطلبات.</div>";
    include '../../includes/footer.php';
    exit();
}


$page_title = 'عرض تفاصيل الطلب';
$error_message = '';
$success_message = '';

// --- 2. HANDLE POST ACTIONS (APPROVE / REJECT) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // No global permission check here, specific checks inside
    $action = $_POST['action'] ?? '';
    
    // --- APPROVE ACTION ---
    if ($action === 'approve') {
        // CHANGE START: Check for specific approve permission
        if (!$can_approve) {
            $error_message = "ليس لديك صلاحية لاعتماد الطلبات.";
        } else {
        // CHANGE END
            $bank_account_id = filter_input(INPUT_POST, 'bank_account_id', FILTER_VALIDATE_INT);
            $order_total_amount = filter_input(INPUT_POST, 'order_total', FILTER_VALIDATE_FLOAT);

            if (!$bank_account_id || !$order_total_amount) {
                $error_message = "يرجى اختيار الحساب البنكي. المبلغ غير صحيح.";
            } else {
                try {
                    $db->beginTransaction();

                    // 1. Lock bank account row to prevent race conditions
                    $stmt = $db->prepare("SELECT id, bank_name, current_balance FROM bank_accounts WHERE id = ? FOR UPDATE");
                    $stmt->execute([$bank_account_id]);
                    $bank_account = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$bank_account) throw new Exception("الحساب البنكي المختار غير موجود.");

                    // 2. Fetch order items to update product stock
                    $items_stmt = $db->prepare("SELECT product_id, quantity FROM shop_order_items WHERE order_id = ?");
                    $items_stmt->execute([$order_id]);
                    $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

                 // 3. Decrease product quantities
    $update_product_qty_stmt = $db->prepare("
        UPDATE products 
        SET product_quantity = product_quantity - ? 
        WHERE id = ? AND product_quantity >= ?
    ");

    foreach ($order_items as $item) {
        $update_product_qty_stmt->execute([
            $item['quantity'],
            $item['product_id'],
            $item['quantity']
        ]);

        if ($update_product_qty_stmt->rowCount() === 0) {
            throw new Exception("الكمية غير كافية في المخزون للمنتج رقم #{$item['product_id']}.");
        }
    }
                    // 4. Update bank account balance
                    $new_balance = $bank_account['current_balance'] + $order_total_amount;
                    $update_bank_stmt = $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
                    $update_bank_stmt->execute([$new_balance, $bank_account_id]);

                    // 5. Log the bank transaction
                    $log_desc = "إيداع من الطلب رقم #{$_POST['order_number']}";
                    $log_stmt = $db->prepare("INSERT INTO bank_account_transactions (account_id, transaction_type, amount, balance_before, balance_after, description, created_by) VALUES (?, 'deposit', ?, ?, ?, ?, ?)");
                    $log_stmt->execute([$bank_account_id, $order_total_amount, $bank_account['current_balance'], $new_balance, $log_desc, $user_id]);

                    // 6. Update order status to 'Approved'
                    $update_order_stmt = $db->prepare("UPDATE shop_orders SET order_status = 'طلب معتمد', approved_by = ? WHERE id = ?");
                    $update_order_stmt->execute([$user_id, $order_id]);

                    $db->commit();
                    $success_message = "تم اعتماد الطلب بنجاح! تم تحديث المخزون ورصيد البنك.";

                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error_message = "فشل اعتماد الطلب: " . $e->getMessage();
                }
            }
        }
    }
    // --- REJECT ACTION ---
    elseif ($action === 'reject') {
        // CHANGE START: Check for specific reject permission
        if (!$can_reject) {
            $error_message = "ليس لديك صلاحية لرفض الطلبات.";
        } else {
        // CHANGE END
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            if (empty($rejection_reason)) {
                $error_message = "سبب الرفض مطلوب.";
            } else {
                $stmt = $db->prepare("UPDATE shop_orders SET order_status = 'مرفوض', rejection_reason = ? WHERE id = ?");
                if ($stmt->execute([$rejection_reason, $order_id])) {
                    $success_message = "تم رفض الطلب بنجاح.";
                } else {
                    $error_message = "فشل تحديث حالة الطلب.";
                }
            }
        }
    }
}

// --- 3. FETCH DATA FOR VIEW ---
try {
    // Fetch main order details
    $stmt = $db->prepare("
        SELECT o.*, c.name as customer_name, c.mobile_number, c.whatsapp_number
        FROM shop_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("الطلب غير موجود.");
    }

    // Fetch order items with cost and profit calculation
    $items_stmt = $db->prepare("
        SELECT 
            soi.*,
            p.purchase_amount,
            (soi.unit_price * soi.quantity) as item_total_sale,
            (p.purchase_amount * soi.quantity) as item_total_cost,
            ((soi.unit_price * soi.quantity) - (p.purchase_amount * soi.quantity)) as item_profit
        FROM shop_order_items soi
        LEFT JOIN products p ON soi.product_id = p.id
        WHERE soi.order_id = ?
    ");
    $items_stmt->execute([$order_id]);
    $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active bank accounts for the approval modal (only if user can approve)
    $bank_accounts = [];
    if ($can_approve) { // Only fetch if user might need to approve
        $bank_accounts = $db->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $error_message = $e->getMessage();
    $order = null; // Set order to null to hide content on error
}

include '../../includes/header.php';
?>
<style>
    .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; }
    .card-header { padding: 16px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; }
    .card-header h2 { font-size: 1.125rem; font-weight: 700; color: #111827; margin: 0; }
    .card-body { padding: 24px; }
    .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-weight: 600; color: #4b5563; }
    .detail-value { font-weight: 500; color: #111827; }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; border: none; text-decoration: none; }
    .btn-success { background-color: #10b981; color: white; }
    .btn-danger { background-color: #ef4444; color: white; }
    .btn-secondary { background-color: #6b7280; color: white; }
    .btn-whatsapp { background-color: #25d366; color: white; }
    
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 999; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-box { background: white; border-radius: 12px; padding: 32px; max-width: 500px; width: 90%; text-align: right; }
</style>

<div class="container-fluid py-4" dir="rtl">
    <div class="page-header mb-4 flex justify-between items-center">
        <h1><?php echo $page_title; ?> - #<?php echo htmlspecialchars($order['order_number'] ?? ''); ?></h1>
        <a href="shop_orders_manage.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-2"></i> العودة لقائمة الطلبات</a>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

    <?php if ($order): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Main Column -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Items -->
            <div class="card">
                <div class="card-header"><i class="fas fa-cubes text-blue-500"></i><h2>منتجات الطلب</h2></div>
                <div class="card-body p-0 overflow-x-auto">
                    <table class="w-full text-right">
                        <thead class="bg-gray-50"><tr>
                            <th class="p-3 font-semibold text-gray-600">المنتج</th>
                            <th class="p-3 font-semibold text-gray-600">الكمية</th>
                            <th class="p-3 font-semibold text-gray-600">سعر البيع</th>
                            <th class="p-3 font-semibold text-gray-600">التكلفة</th>
                            <th class="p-3 font-semibold text-gray-600">الربح</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($order_items as $item): ?>
                            <tr class="border-b last:border-b-0"><td class="p-3"><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td class="p-3 font-bold"><?php echo $item['quantity']; ?></td>
                                <td class="p-3 text-green-600 font-bold"><?php echo number_format($item['item_total_sale'], 2); ?></td>
                                <td class="p-3 text-red-600 font-bold"><?php echo number_format($item['item_total_cost'], 2); ?></td>
                                <td class="p-3 font-extrabold <?php echo $item['item_profit'] >= 0 ? 'text-blue-600' : 'text-red-600'; ?>"><?php echo number_format($item['item_profit'], 2); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Payment Evidence -->
            <div class="card">
                <div class="card-header"><i class="fas fa-file-invoice-dollar text-green-500"></i><h2>إيصال التحويل</h2></div>
                <div class="card-body text-center">
                    <?php if (!empty($order['payment_evidence_url'])): ?>
                        <a href="../../<?php echo htmlspecialchars($order['payment_evidence_url']); ?>" target="_blank">
                            <img src="../../<?php echo htmlspecialchars($order['payment_evidence_url']); ?>" class="max-w-md mx-auto rounded-lg shadow-md cursor-pointer">
                        </a>
                    <?php else: ?>
                        <p class="text-gray-500">لم يتم رفع إيصال الدفع.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Action Buttons -->
            <?php if ($order['order_status'] === 'طلب جديد'): // Only show if order is new ?>
            <div class="card">
                <div class="card-header"><i class="fas fa-tasks text-yellow-500"></i><h2>اتخاذ إجراء</h2></div>
                <div class="card-body flex gap-4">
                    <?php if ($can_approve): ?>
                        <button onclick="openApproveModal()" class="btn btn-success flex-1"><i class="fas fa-check-circle"></i> موافقة</button>
                    <?php endif; ?>
                    <?php if ($can_reject): ?>
                        <button onclick="openRejectModal()" class="btn btn-danger flex-1"><i class="fas fa-times-circle"></i> رفض</button>
                    <?php endif; ?>
                    <?php // CHANGE START: Message if user has neither approve nor reject permission ?>
                    <?php if (!$can_approve && !$can_reject): ?>
                        <p class="text-gray-500 text-center w-full">ليس لديك صلاحية لاتخاذ إجراء على هذا الطلب.</p>
                    <?php endif; ?>
                    <?php // CHANGE END ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($order['order_status'] === 'طلب معتمد'): 
                 $whatsapp_msg = "مرحباً {$order['customer_name']}\nتم اعتماد طلبك رقم #{$order['order_number']} بنجاح!\nسيتم شحنه قريباً. شكراً لثقتكم.";
                 $whatsapp_url = "https://wa.me/{$order['whatsapp_number']}?text=" . urlencode($whatsapp_msg);
            ?>
            <div class="card bg-green-50 border-green-300">
                <div class="card-body text-center">
                     <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
                     <p class="font-bold text-green-700">تم اعتماد هذا الطلب.</p>
                     <a href="<?php echo $whatsapp_url; ?>" target="_blank" class="mt-4 btn btn-whatsapp w-full">
                        <i class="fab fa-whatsapp"></i> إرسال إشعار للعميل
                     </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($order['order_status'] === 'مرفوض'): ?>
             <div class="card bg-red-50 border-red-300">
                <div class="card-body">
                    <div class="text-center mb-3">
                         <i class="fas fa-times-circle text-red-500 text-3xl"></i>
                         <p class="font-bold text-red-700">تم رفض هذا الطلب.</p>
                    </div>
                     <p class="text-sm"><strong class="text-gray-700">سبب الرفض:</strong> <span class="text-red-800"><?php echo htmlspecialchars($order['rejection_reason']); ?></span></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Order Summary -->
            <div class="card">
                <div class="card-header"><i class="fas fa-receipt text-purple-500"></i><h2>ملخص الطلب</h2></div>
                <div class="card-body space-y-2">
                    <div class="detail-row"><span class="detail-label">رقم الطلب</span><span class="detail-value font-bold"><?php echo htmlspecialchars($order['order_number']); ?></span></div>
                    <div class="detail-row"><span class="detail-label">تاريخ الإنشاء</span><span class="detail-value text-sm"><?php echo date('Y-m-d h:i A', strtotime($order['created_at'])); ?></span></div>
                    <div class="detail-row"><span class="detail-label">العميل</span><span class="detail-value text-blue-600 font-bold"><?php echo htmlspecialchars($order['customer_name']); ?></span></div>
                    <div class="detail-row"><span class="detail-label">رقم الجوال</span><span class="detail-value"><?php echo htmlspecialchars($order['mobile_number']); ?></span></div>
                    <hr class="my-3">
                    <div class="detail-row"><span class="detail-label">مجموع المنتجات</span><span class="detail-value"><?php echo number_format($order['subtotal'], 2); ?></span></div>
                    <div class="detail-row"><span class="detail-label">رسوم الشحن</span><span class="detail-value"><?php echo number_format($order['shipping_fee'], 2); ?></span></div>
                    <div class="detail-row text-red-500"><span class="detail-label">الخصم</span><span class="detail-value">-<?php echo number_format($order['discount_amount'], 2); ?></span></div>
                    <div class="detail-row text-xl mt-2 pt-2 border-t-2 border-black"><span class="detail-label">الإجمالي النهائي</span><span class="detail-value font-black text-green-600"><?php echo number_format($order['total_amount'], 2); ?></span></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODALS -->
    <!-- Approve Modal -->
    <?php if ($can_approve): // Only render modal if user has permission ?>
    <div id="approveModal" class="modal-overlay" onclick="closeApproveModal()">
        <div class="modal-box" onclick="event.stopPropagation()">
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="order_total" value="<?php echo $order['total_amount']; ?>">
                <input type="hidden" name="order_number" value="<?php echo $order['order_number']; ?>">
                <h3 class="text-xl font-bold mb-4 text-center">تأكيد الموافقة على الطلب</h3>
                <p class="text-center text-gray-600 mb-6">سيتم خصم الكميات من المخزون وإضافة المبلغ إلى الحساب البنكي.</p>
                <div>
                    <label for="bank_account_id" class="block font-bold text-gray-700 mb-2">اختر الحساب البنكي لإيداع المبلغ:</label>
                    <select name="bank_account_id" id="bank_account_id" required class="w-full border-2 border-gray-300 rounded-lg p-3 focus:border-blue-500 outline-none">
                        <option value="">-- اختر حساب --</option>
                        <?php foreach ($bank_accounts as $bank): ?>
                            <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mt-8 flex gap-4">
                    <button type="button" onclick="closeApproveModal()" class="btn btn-secondary flex-1">إلغاء</button>
                    <button type="submit" class="btn btn-success flex-1">تأكيد الموافقة</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; // End can_approve ?>

    <!-- Reject Modal -->
    <?php if ($can_reject): // Only render modal if user has permission ?>
    <div id="rejectModal" class="modal-overlay" onclick="closeRejectModal()">
        <div class="modal-box" onclick="event.stopPropagation()">
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <h3 class="text-xl font-bold mb-4 text-center">رفض الطلب</h3>
                <p class="text-center text-gray-600 mb-6">سيتم تغيير حالة الطلب إلى "مرفوض".</p>
                <div>
                    <label for="rejection_reason" class="block font-bold text-gray-700 mb-2">اكتب سبب الرفض (مطلوب):</label>
                    <textarea name="rejection_reason" id="rejection_reason" rows="4" required class="w-full border-2 border-gray-300 rounded-lg p-3 focus:border-red-500 outline-none" placeholder="مثال: صورة الحوالة غير واضحة..."></textarea>
                </div>
                <div class="mt-8 flex gap-4">
                    <button type="button" onclick="closeRejectModal()" class="btn btn-secondary flex-1">إلغاء</button>
                    <button type="submit" class="btn btn-danger flex-1">تأكيد الرفض</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; // End can_reject ?>
</div>

<script>
    function openApproveModal() { document.getElementById('approveModal').style.display = 'flex'; }
    function closeApproveModal() { document.getElementById('approveModal').style.display = 'none'; }
    function openRejectModal() { document.getElementById('rejectModal').style.display = 'flex'; }
    function closeRejectModal() { document.getElementById('rejectModal').style.display = 'none'; }
</script>

<?php include '../../includes/footer.php'; ?>