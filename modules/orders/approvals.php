<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// --- 1. PERMISSIONS ---
$user_id = $_SESSION['user_id'] ?? 0;
if (!canAccessOrderApprovalsPage($user_id)) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول لهذه الصفحة.';
    header('Location: ../../index.php');
    exit();
}

// --- 2. INITIALIZATION & FILTERS ---
$page_title = 'طلبات بانتظار الموافقة';
// Clear messages after reading them to prevent them from showing again on refresh
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

// Handle permanent deletion for rejected approvals only.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_rejected') {
    $delete_id = (int)($_POST['approval_id'] ?? 0);
    try {
        $db->beginTransaction();
        $status_stmt = $db->prepare("SELECT status FROM order_approvals WHERE id = ? FOR UPDATE");
        $status_stmt->execute([$delete_id]);
        $delete_status = $status_stmt->fetchColumn();

        if ($delete_status !== 'rejected') {
            throw new Exception('يمكن حذف الطلبات المرفوضة فقط.');
        }

        $db->prepare("DELETE FROM order_approval_items WHERE approval_id = ?")->execute([$delete_id]);
        $db->prepare("DELETE FROM order_approvals_images WHERE approval_id = ?")->execute([$delete_id]);
        $db->prepare("DELETE FROM notifications WHERE related_id = ? AND related_table = 'order_approvals'")->execute([$delete_id]);
        $db->prepare("DELETE FROM order_approvals WHERE id = ? AND status = 'rejected'")->execute([$delete_id]);
        $db->commit();
        $_SESSION['success_message'] = 'تم حذف الطلب المرفوض نهائياً.';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error_message'] = 'فشل حذف الطلب: ' . $e->getMessage();
    }
    header('Location: approvals.php?' . http_build_query(array_diff_key($_GET, ['page' => true])));
    exit();
}

// Get filter parameters from the URL
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'pending_rejected';
$self_order_filter = $_GET['self_order'] ?? '';
$allowed_statuses = ['pending_rejected', 'all', 'pending', 'approved', 'rejected'];
if (!in_array($status_filter, $allowed_statuses, true)) { $status_filter = 'pending_rejected'; }
$advanced_filters_active = !empty($date_from) || !empty($date_to) || $status_filter !== 'pending_rejected' || !empty($self_order_filter);
$status_filter = $_GET['status'] ?? 'pending';
$self_order_filter = $_GET['self_order'] ?? '';
$allowed_statuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($status_filter, $allowed_statuses, true)) { $status_filter = 'pending'; }
$advanced_filters_active = !empty($date_from) || !empty($date_to) || $status_filter !== 'pending' || !empty($self_order_filter);

// --- 3. FETCH DATA ---
try {
    // Base query setup for approvals
    $from_joins = "FROM order_approvals oa
                   LEFT JOIN customers c ON oa.customer_id = c.id
                   LEFT JOIN customer_types ct ON c.customer_type_id = ct.id";

    // Build the WHERE clause and parameters
    $where_clause = " WHERE 1=1";
    $params = [];

    if ($status_filter === 'pending_rejected') {
        $where_clause .= " AND oa.status IN ('pending', 'rejected')";
    } elseif ($status_filter !== 'all') {
    if ($status_filter !== 'all') {
        $where_clause .= " AND oa.status = ?";
        $params[] = $status_filter;
    }

    if ($search) {
        $search_param = "%$search%";
        $where_clause .= " AND (oa.id LIKE ? OR c.name LIKE ? OR oa.customer_name LIKE ? OR c.mobile_number LIKE ? OR c.whatsapp_number LIKE ?)";
        array_push($params, $search_param, $search_param, $search_param, $search_param, $search_param);
    }
    if ($date_from) {
        $where_clause .= " AND DATE(oa.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $where_clause .= " AND DATE(oa.created_at) <= ?";
        $params[] = $date_to;
    }
    if ($self_order_filter === 'yes') {
        $where_clause .= " AND oa.customer_id IS NOT NULL";
    }

    // Get total count for pagination
    $count_query = "SELECT COUNT(oa.id) " . $from_joins . $where_clause;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    
    // Pagination setup
    $records_per_page = 15;
    $total_pages = ceil($total_records / $records_per_page);
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $page = max(1, min($page, $total_pages)); // Ensure page is within valid range
    $offset = ($page - 1) * $records_per_page;

    // --- FIX: The main SQL query now selects `oa.id` correctly ---
    $query = "SELECT
        oa.id,
        oa.currency, oa.created_at, oa.customer_id, oa.status, oa.final_order_id,
        oa.notes, oa.payment_proof_path, oa.subtotal_amount, oa.automatic_discount_amount, 
        oa.shipping_cost, oa.automatic_discount_percentage, oa.paid_amount,
        c.name as customer_name, c.mobile_number, c.whatsapp_number,
        ct.name as customer_type_name_from_table,
        (oa.subtotal_amount - oa.automatic_discount_amount + oa.shipping_cost) as final_amount,
        oa.expected_delivery_date, oa.coupon_code, oa.coupon_discount_amount,
        (SELECT TRIM(oai.product_link) FROM order_approval_items oai WHERE oai.approval_id = oa.id AND TRIM(COALESCE(oai.product_link,'')) <> '' ORDER BY oai.id ASC LIMIT 1) AS first_product_link,
        (SELECT TRIM(oai.additional_link) FROM order_approval_items oai WHERE oai.approval_id = oa.id AND TRIM(COALESCE(oai.additional_link,'')) <> '' ORDER BY oai.id ASC LIMIT 1) AS first_additional_link
    " . $from_joins . $where_clause . " ORDER BY oa.created_at DESC LIMIT $records_per_page OFFSET $offset";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع الطلبات: ' . $e->getMessage();
    $orders = [];
    $total_records = 0;
    $total_pages = 0;
}

// Helper function for page totals
function calculatePageTotals($orders) {
    $totals = ['final_amount_sum' => 0, 'paid_amount_sum' => 0];
    foreach ($orders as $order) {
        $totals['final_amount_sum'] += (float)($order['final_amount'] ?? 0);
        $totals['paid_amount_sum'] += (float)($order['paid_amount'] ?? 0);
    }
    return $totals;
}

include '../../includes/header.php';
?>

<!-- STYLES -->
<style>
    :root {
        --primary: #3b82f6; --success: #10b981; --danger: #ef4444; --accent-gold: #C7A46D; --dark-gold: #9e7f4e;
    }
    body { background-color: #f3f4f6; }
    .table-wrapper { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); margin: 20px; overflow: hidden; }
    .table-page-header { background: linear-gradient(135deg, var(--accent-gold) 0%, var(--dark-gold) 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
    .table-page-title { font-size: 22px; font-weight: 700; }
    .filter-section { background: #f9fafb; padding: 20px; border-bottom: 1px solid #e5e7eb; }
    .form-control { width: 100%; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; }
    .vertical-table { width: 100%; border-collapse: collapse; }
    .vertical-table thead { background: var(--primary); color: white; }
    .vertical-table th, .vertical-table td { padding: 12px 10px; text-align: right; vertical-align: middle; }
    .vertical-table tbody tr { border-bottom: 1px solid #e5e7eb; }
    .vertical-table tbody tr:hover { background: #f0f9ff; }
    .btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; transition: all 0.2s; color: white; }
    .btn-primary { background: var(--primary); } .btn-primary:hover { background: #2563eb; }
    .btn-success { background: var(--success); } .btn-success:hover { background: #059669; }
    .btn-danger { background: var(--danger); } .btn-danger:hover { background: #dc2626; }
    .btn-secondary { background: #6b7280; } .btn-secondary:hover { background: #4b5563; }
    .pagination { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; }
    .pagination-links a, .pagination-links span { padding: 6px 12px; border-radius: 6px; border: 1px solid #e5e7eb; background: white; text-decoration: none; margin: 0 2px; }
    .pagination-links a.active { background: var(--primary); color: white; border-color: var(--primary); }
    .alert { padding: 15px; border-radius: 8px; margin: 20px; font-weight: 600; }
    .alert-success { background: #d1fae5; color: var(--success); }
    .alert-danger { background: #fee2e2; color: var(--danger); }
    .action-cell > div { display: flex; flex-direction: column; gap: 5px; min-width: 80px; }
    .action-icon { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; text-decoration: none; transition: all 0.2s; }
    .action-icon:hover { transform: scale(1.05); }
    .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; align-items: end; }
    .order-date-small { display: block; margin-top: 4px; color: #6b7280; font-size: 11px; font-weight: 500; }
    .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; border: 1px solid transparent; }
    .status-pending { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .status-approved { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .status-rejected { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
</style>

<div class="min-h-screen" dir="rtl">
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

    <div class="table-wrapper">
        <div class="table-page-header">
            <h2 class="table-page-title"><i class="fas fa-hourglass-start"></i> <?php echo $page_title; ?> (<?php echo $total_records; ?>)</h2>
        </div>

        <form method="GET" action="" class="filter-section">
            <div class="filters-grid">
                <div><label>البحث (رقم طلب، اسم، هاتف)</label><input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control" placeholder="ابحث هنا..."></div>
                <div><label>حالة الطلب</label><select name="status" class="form-control">
                    <option value="pending_rejected" <?php echo $status_filter === 'pending_rejected' ? 'selected' : ''; ?>>قيد المراجعة والمرفوضة</option>
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>كل الحالات</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>قيد المراجعة</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>تمت الموافقة</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select></div>
                <div><label>الطلبات الذاتية</label><select name="self_order" class="form-control">
                    <option value="" <?php echo $self_order_filter === '' ? 'selected' : ''; ?>>الكل</option>
                    <option value="yes" <?php echo $self_order_filter === 'yes' ? 'selected' : ''; ?>>طلبات العملاء الذاتية</option>
                </select></div>
                <div><label>من تاريخ</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control"></div>
                <div><label>إلى تاريخ</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control"></div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
                    <a href="approvals.php" class="btn btn-secondary"><i class="fas fa-redo"></i> إلغاء</a>
                </div>
            </div>
        </form>

        <div style="overflow-x: auto;">
            <table class="vertical-table">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>الحالة</th>
                        <th>العميل</th>
                        <th>رابط الطلب</th>
                        <th>رابط إضافي</th>
                        <th>الإجمالي النهائي</th>
                        <th>المدفوع</th>
                        <th>إثبات الدفع</th>
                        <th>ملاحظات العميل</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="10" style="text-align: center; padding: 40px;">لا توجد طلبات مطابقة للفلاتر</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr id="row-<?php echo $order['id']; ?>">
                                <td><strong>#<?php echo htmlspecialchars($order['id']); ?></strong><small class="order-date-small"><i class="far fa-clock"></i> <?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></small></td>
                                <td>
                                    <?php
                                    $status_labels = ['pending' => 'قيد المراجعة', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض'];
                                    $status_icons = ['pending' => 'fa-clock', 'approved' => 'fa-check-circle', 'rejected' => 'fa-times-circle'];
                                    $status_key = $order['status'] ?? 'pending';
                                    ?>
                                    <span class="status-badge status-<?php echo htmlspecialchars($status_key); ?>"><i class="fas <?php echo $status_icons[$status_key] ?? 'fa-info-circle'; ?>"></i><?php echo $status_labels[$status_key] ?? htmlspecialchars($status_key); ?></span>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></div>
                                    <small><?php echo htmlspecialchars($order['mobile_number'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $primary_link = trim($order['first_product_link'] ?? '');
                                    if (!empty($primary_link)): ?>
                                        <a href="<?php echo htmlspecialchars($primary_link); ?>" target="_blank" class="action-icon" style="background: #dbeafe; color: #1e40af;" title="فتح رابط الطلب"><i class="fas fa-external-link-alt"></i></a>
                                    <?php else: ?><span>-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $addl = trim($order['first_additional_link'] ?? '');
                                    if (!empty($addl)): ?>
                                        <a href="<?php echo htmlspecialchars($addl); ?>" target="_blank" class="action-icon" style="background: #fef3c7; color: #92400e;" title="فتح الرابط الإضافي"><i class="fas fa-link"></i></a>
                                    <?php else: ?><span>-</span><?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: bold; color: var(--dark-gold);"><?php echo number_format($order['final_amount'], 2); ?></span>
                                    <small><?php echo htmlspecialchars($order['currency']); ?></small>
                                </td>
                                <td style="color: var(--success);">
                                    <strong><?php echo number_format($order['paid_amount'], 2); ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($order['payment_proof_path'])): ?>
                                        <a href="/<?php echo htmlspecialchars($order['payment_proof_path']); ?>" target="_blank" class="btn btn-primary"><i class="fas fa-eye"></i></a>
                                    <?php else: ?>
                                        <span>-</span>
                                    <?php endif; ?>
                                </td>
                                <td title="<?php echo htmlspecialchars($order['notes']); ?>">
                                    <?php echo !empty($order['notes']) ? mb_substr(htmlspecialchars($order['notes']), 0, 40) . '...' : '-'; ?>
                                </td>
                                <td class="action-cell">
                                    <div>
                                        <a href="view_approval.php?id=<?php echo $order['id']; ?>" class="btn btn-primary"><i class="fas fa-search-plus"></i> مراجعة</a>
                                        <?php if (($order['status'] ?? '') === 'rejected'): ?>
                                            <form method="POST" action="" onsubmit="return confirm('سيتم حذف الطلب المرفوض نهائياً من قاعدة البيانات. هل أنت متأكد؟');">
                                                <input type="hidden" name="action" value="delete_rejected">
                                                <input type="hidden" name="approval_id" value="<?php echo (int)$order['id']; ?>">
                                                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> حذف</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                 <?php if (!empty($orders)): $page_totals = calculatePageTotals($orders); ?>
                    <tfoot style="background: #f3f4f6; border-top: 2px solid var(--primary); font-weight: bold;">
                        <tr>
                            <td colspan="5">إجمالي الصفحة</td>
                            <td><?php echo number_format($page_totals['final_amount_sum'], 2); ?></td>
                            <td><?php echo number_format($page_totals['paid_amount_sum'], 2); ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div>عرض <?php echo $offset + 1; ?> - <?php echo min($offset + $records_per_page, $total_records); ?> من <?php echo $total_records; ?></div>
                <div class="pagination-links">
                    <?php
                    $page_params = $_GET;
                    if ($page > 1) {
                        $page_params['page'] = $page - 1;
                        echo '<a href="?' . http_build_query($page_params) . '">السابق</a>';
                    }
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $page_params['page'] = $i;
                        echo '<a href="?' . http_build_query($page_params) . '" class="' . ($i == $page ? 'active' : '') . '">' . $i . '</a>';
                    }
                    if ($page < $total_pages) {
                        $page_params['page'] = $page + 1;
                        echo '<a href="?' . http_build_query($page_params) . '">التالي</a>';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    async function submitApprovalAction(action, approvalId) {
        const actionText = action === 'approve' ? 'الموافقة' : 'الرفض';
        if (!confirm(`هل أنت متأكد من ${actionText} على الطلب #${approvalId}؟`)) {
            return;
        }

        let url;
        let reason = '';

        if (action === 'approve') {
            url = 'api/approve_order.php';
        } else {
            reason = prompt("الرجاء إدخال سبب الرفض:");
            if (reason === null) { // User cancelled prompt
                return;
            }
            if (!reason.trim()) {
                alert("سبب الرفض مطلوب.");
                return;
            }

            // WhatsApp contact selection
            const whatsappContact = prompt("اختر رقم الواتساب للإرسال:\n1. رقم الهاتف المحمول\n2. رقم الواتساب\nأدخل 1 أو 2:", "1");
            if (whatsappContact === null) {
                return;
            }
            if (whatsappContact !== '1' && whatsappContact !== '2') {
                alert("الرجاء اختيار 1 أو 2.");
                return;
            }

            url = 'api/reject_order.php';
        }

        let approvalDetails;
        try {
            const detailsResponse = await fetch(`api/get_approval_details.php?id=${approvalId}`);
            if (!detailsResponse.ok) {
                throw new Error('فشل جلب تفاصيل الطلب من الخادم.');
            }
            
            const responseJson = await detailsResponse.json();
            
            if (!responseJson.success) {
                throw new Error(responseJson.message || 'فشل جلب تفاصيل الطلب: خطأ غير معروف.');
            }
            
            approvalDetails = responseJson.data; // THIS IS THE CRITICAL LINE

            if (action === 'approve' && (!approvalDetails.items || approvalDetails.items.length === 0)) {
                alert("لا يمكن الموافقة على طلب بدون منتجات. يرجى مراجعة الطلب أولاً.");
                return;
            }

        } catch (error) {
            alert(`حدث خطأ أثناء جلب تفاصيل الطلب: ${error.message}`);
            return;
        }

        // Create a temporary form to submit the data
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        form.style.display = 'none'; // Hide the form

        // Add approval_id
        const approvalIdInput = document.createElement('input');
        approvalIdInput.type = 'hidden';
        approvalIdInput.name = 'approval_id';
        approvalIdInput.value = approvalId;
        form.appendChild(approvalIdInput);

        // Add rejection_reason if action is 'reject'
        if (action === 'reject') {
            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'rejection_reason';
            reasonInput.value = reason;
            form.appendChild(reasonInput);

            const whatsappContactInput = document.createElement('input');
            whatsappContactInput.type = 'hidden';
            whatsappContactInput.name = 'whatsapp_contact';
            whatsappContactInput.value = whatsappContact;
            form.appendChild(whatsappContactInput);
        } else { // If approving, add all the other required fields from approvalDetails
            // Add items from fetched details
            approvalDetails.items.forEach((item, index) => {
                const prefix = `items[${index}]`;
                for (const key in item) {
                    // Exclude 'id' and 'approval_id' as they are specific to the approval_items table
                    // and not expected by order_items table for insertion
                    if (key !== 'id' && key !== 'approval_id') {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = `${prefix}[${key}]`;
                        input.value = item[key];
                        form.appendChild(input);
                    }
                }
            });

            // Add other necessary fields from approvalDetails (these are from the `order_approvals` table itself)
            const fieldsToTransfer = [
                'notes', 'shipping_cost', 'expected_delivery_date', 'coupon_code',
                'paid_amount', 'automatic_discount_percentage', 'automatic_discount_amount',
                'coupon_discount_amount', 'payment_proof_path' // Include payment proof path
            ];

            fieldsToTransfer.forEach(field => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = field;
                // Use || '' or || 0 to handle null/undefined values gracefully
                input.value = approvalDetails[field] !== null && approvalDetails[field] !== undefined ? approvalDetails[field] : (typeof approvalDetails[field] === 'number' ? 0 : '');
                form.appendChild(input);
            });
            
            // For new_payment_proof_image and deleted_images, we assume nothing changes for quick approve
            const emptyDeletedImagesInput = document.createElement('input');
            emptyDeletedImagesInput.type = 'hidden';
            emptyDeletedImagesInput.name = 'deleted_images';
            emptyDeletedImagesInput.value = '[]'; // Send an empty JSON array
            form.appendChild(emptyDeletedImagesInput);
            
            // If approve_order.php expects 'new_payment_proof_image' even if empty, add a placeholder
            // This is generally not needed for file inputs if no file is selected.
            // But if it causes issues, you could add an input with type="file" but no value.
            // For now, let's omit unless it's strictly required by the PHP.
        }
        
        // Append form to body and submit
        document.body.appendChild(form);
        form.submit(); // This will trigger a full page navigation, and the PHP redirect will work.
    }
</script>

<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('button[title*="الوضع"], button[title*="مظلم"], button[title*="داكن"], #darkModeToggle, #themeToggle, .theme-toggle-btn').forEach(function (el) {
            el.remove();
        });
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>