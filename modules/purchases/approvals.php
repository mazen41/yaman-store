<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'موافقات المشتريات';
$error_message = '';
$success_message = '';

// Handle approval actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action == 'approve' || $action == 'reject') {
            $approval_id = intval($_POST['approval_id']);
            $comments = trim($_POST['comments']);
            $approved_amount = floatval($_POST['approved_amount'] ?? 0);
            
            $status = ($action == 'approve') ? 'approved' : 'rejected';
            
            $stmt = $db->prepare("
                UPDATE purchase_approvals 
                SET status = ?, comments = ?, approved_amount = ?, approved_at = NOW() 
                WHERE id = ? AND approver_id = ?
            ");
            $stmt->execute([$status, $comments, $approved_amount, $approval_id, $_SESSION['user_id']]);
            
            if ($stmt->rowCount() > 0) {
                // Update purchase order status if needed
                $approval_info = $db->prepare("
                    SELECT pa.purchase_order_id, po.total_amount 
                    FROM purchase_approvals pa
                    JOIN purchase_orders po ON pa.purchase_order_id = po.id
                    WHERE pa.id = ?
                ")->execute([$approval_id]);
                $approval_data = $approval_info->fetch();
                
                if ($action == 'approve') {
                    // Check if all required approvals are complete
                    $pending_approvals = $db->prepare("
                        SELECT COUNT(*) FROM purchase_approvals 
                        WHERE purchase_order_id = ? AND status = 'pending'
                    ")->execute([$approval_data['purchase_order_id']]);
                    
                    if ($pending_approvals->fetchColumn() == 0) {
                        $db->prepare("
                            UPDATE purchase_orders 
                            SET approval_status = 'approved', status = 'approved' 
                            WHERE id = ?
                        ")->execute([$approval_data['purchase_order_id']]);
                    }
                } else {
                    // If rejected, update order status
                    $db->prepare("
                        UPDATE purchase_orders 
                        SET approval_status = 'rejected', status = 'cancelled' 
                        WHERE id = ?
                    ")->execute([$approval_data['purchase_order_id']]);
                }
                
                $success_message = ($action == 'approve') ? 'تم اعتماد الطلب بنجاح' : 'تم رفض الطلب';
            } else {
                $error_message = 'لم يتم العثور على الموافقة أو ليس لديك صلاحية';
            }
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch pending approvals for current user
$pending_stmt = $db->prepare("
    SELECT pa.*, po.order_number, po.total_amount, po.order_date, po.priority,
           s.name as supplier_name, u.full_name as created_by_name
    FROM purchase_approvals pa
    JOIN purchase_orders po ON pa.purchase_order_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON po.created_by = u.id
    WHERE pa.approver_id = ? AND pa.status = 'pending'
    ORDER BY po.priority DESC, pa.created_at ASC
");
$pending_stmt->execute([$_SESSION['user_id']]);
$pending_approvals = $pending_stmt->fetchAll();

// Fetch completed approvals for current user
$completed_stmt = $db->prepare("
    SELECT pa.*, po.order_number, po.total_amount, po.order_date, po.priority,
           s.name as supplier_name, u.full_name as created_by_name
    FROM purchase_approvals pa
    JOIN purchase_orders po ON pa.purchase_order_id = po.id
    JOIN suppliers s ON po.supplier_id = s.id
    JOIN users u ON po.created_by = u.id
    WHERE pa.approver_id = ? AND pa.status IN ('approved', 'rejected')
    ORDER BY pa.approved_at DESC
    LIMIT 20
");
$completed_stmt->execute([$_SESSION['user_id']]);
$completed_approvals = $completed_stmt->fetchAll();

// Get approval statistics
$stats_stmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_count,
        SUM(CASE WHEN status = 'approved' THEN approved_amount ELSE 0 END) as total_approved_amount
    FROM purchase_approvals 
    WHERE approver_id = ?
");
$stats_stmt->execute([$_SESSION['user_id']]);
$stats = $stats_stmt->fetch();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">موافقات المشتريات</h1>
                        <p class="text-gray-600 mt-1">إدارة موافقات طلبات الشراء والاعتمادات</p>
                    </div>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للمشتريات
                    </a>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-check-circle ml-2"></i>
                <?php echo $success_message; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle ml-2"></i>
                <?php echo $error_message; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-clock text-2xl text-yellow-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">في انتظار الموافقة</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['pending_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-2xl text-amber-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">تم اعتمادها</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['approved_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-times-circle text-2xl text-red-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">تم رفضها</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['rejected_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-money-bill-wave text-2xl text-blue-600"></i>
                        </div>
                        <div class="mr-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">إجمالي المعتمد</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['total_approved_amount'], 0, '', ''); ?> ر.س</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Approvals -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    الموافقات المطلوبة 
                    <?php if ($stats['pending_count'] > 0): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                        <?php echo $stats['pending_count']; ?>
                    </span>
                    <?php endif; ?>
                </h3>
            </div>
            
            <?php if (empty($pending_approvals)): ?>
            <div class="px-6 py-12 text-center">
                <i class="fas fa-check-circle text-4xl text-amber-300 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">لا توجد موافقات مطلوبة</h3>
                <p class="text-gray-600">جميع طلبات الشراء المخصصة لك تم التعامل معها</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المورد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الأولوية</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">طلب بواسطة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">العمليات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($pending_approvals as $approval): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($approval['order_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($approval['supplier_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo number_format($approval['total_amount'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php
                                $priority_colors = [
                                    'low' => 'bg-gray-100 text-gray-800',
                                    'medium' => 'bg-blue-100 text-blue-800',
                                    'high' => 'bg-orange-100 text-orange-800',
                                    'urgent' => 'bg-red-100 text-red-800'
                                ];
                                $priority_labels = [
                                    'low' => 'منخفضة',
                                    'medium' => 'متوسطة',
                                    'high' => 'عالية',
                                    'urgent' => 'عاجلة'
                                ];
                                ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $priority_colors[$approval['priority']]; ?>">
                                    <?php echo $priority_labels[$approval['priority']]; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($approval['created_by_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d/m/Y', strtotime($approval['order_date'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2 space-x-reverse">
                                    <button onclick="openApprovalModal(<?php echo htmlspecialchars(json_encode($approval)); ?>)" 
                                            class="text-blue-600 hover:text-blue-900" title="عرض التفاصيل">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button onclick="quickApprove(<?php echo $approval['id']; ?>, <?php echo $approval['total_amount']; ?>)" 
                                            class="text-amber-600 hover:text-amber-900" title="اعتماد سريع">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button onclick="quickReject(<?php echo $approval['id']; ?>)" 
                                            class="text-red-600 hover:text-red-900" title="رفض">
                                        <i class="fas fa-times"></i>
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

        <!-- Recent Approvals -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">الموافقات الأخيرة</h3>
            </div>
            
            <?php if (empty($completed_approvals)): ?>
            <div class="px-6 py-8 text-center">
                <i class="fas fa-history text-3xl text-gray-300 mb-2"></i>
                <p class="text-gray-600">لا توجد موافقات سابقة</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">رقم الطلب</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المورد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">المبلغ المعتمد</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاريخ الموافقة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">التعليقات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($completed_approvals as $approval): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($approval['order_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo htmlspecialchars($approval['supplier_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo number_format($approval['approved_amount'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $approval['status'] == 'approved' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $approval['status'] == 'approved' ? 'معتمد' : 'مرفوض'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo date('d/m/Y H:i', strtotime($approval['approved_at'])); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="max-w-xs truncate">
                                    <?php echo htmlspecialchars($approval['comments'] ?: '-'); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approval Modal -->
<div id="approvalModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2/3 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900" id="approvalModalTitle">تفاصيل طلب الموافقة</h3>
                <button onclick="closeApprovalModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div id="approvalDetails" class="mb-6">
                <!-- Details will be populated by JavaScript -->
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="approval_id" id="modalApprovalId">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">المبلغ المعتمد</label>
                        <input type="number" name="approved_amount" id="modalApprovedAmount" step="0.01" min="0" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">الإجراء</label>
                        <div class="flex space-x-4 space-x-reverse">
                            <button type="submit" name="action" value="approve" 
                                    class="flex-1 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors duration-200">
                                <i class="fas fa-check ml-2"></i>
                                اعتماد
                            </button>
                            <button type="submit" name="action" value="reject" 
                                    class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                                <i class="fas fa-times ml-2"></i>
                                رفض
                            </button>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">التعليقات</label>
                    <textarea name="comments" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="أضف تعليقاتك حول قرار الموافقة"></textarea>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openApprovalModal(approval) {
    document.getElementById('modalApprovalId').value = approval.id;
    document.getElementById('modalApprovedAmount').value = approval.total_amount;
    document.getElementById('approvalModalTitle').textContent = 'موافقة طلب رقم: ' + approval.order_number;
    
    const detailsHtml = `
        <div class="bg-gray-50 p-4 rounded-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <span class="text-sm text-gray-600">رقم الطلب:</span>
                    <span class="font-medium">${approval.order_number}</span>
                </div>
                <div>
                    <span class="text-sm text-gray-600">المورد:</span>
                    <span class="font-medium">${approval.supplier_name}</span>
                </div>
                <div>
                    <span class="text-sm text-gray-600">المبلغ الإجمالي:</span>
                    <span class="font-medium">${parseFloat(approval.total_amount).toLocaleString()} ر.س</span>
                </div>
                <div>
                    <span class="text-sm text-gray-600">تاريخ الطلب:</span>
                    <span class="font-medium">${new Date(approval.order_date).toLocaleDateString('ar-SA')}</span>
                </div>
                <div>
                    <span class="text-sm text-gray-600">طلب بواسطة:</span>
                    <span class="font-medium">${approval.created_by_name}</span>
                </div>
                <div>
                    <span class="text-sm text-gray-600">مستوى الموافقة:</span>
                    <span class="font-medium">المستوى ${approval.approval_level}</span>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('approvalDetails').innerHTML = detailsHtml;
    document.getElementById('approvalModal').classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}

function quickApprove(approvalId, amount) {
    if (confirm('هل أنت متأكد من اعتماد هذا الطلب؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="approval_id" value="${approvalId}">
            <input type="hidden" name="approved_amount" value="${amount}">
            <input type="hidden" name="comments" value="اعتماد سريع">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function quickReject(approvalId) {
    const reason = prompt('سبب الرفض (اختياري):');
    if (reason !== null) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="approval_id" value="${approvalId}">
            <input type="hidden" name="approved_amount" value="0">
            <input type="hidden" name="comments" value="${reason || 'تم الرفض'}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
document.getElementById('approvalModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeApprovalModal();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
