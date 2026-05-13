<?php
/**
 * Manage Statuses Page
 * - Displays statuses from all relevant tables.
 * - Links to separate pages for creating and editing.
 */

// --- 1. SETUP ---
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

// --- 2. PERMISSIONS ---
$user_id = $_SESSION['user_id'];

if (!hasPermission($user_id, 'statuses', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك الصلاحية لعرض هذه الصفحة.';
    header('Location: ../../index.php');
    exit();
}

$can_add_status = hasPermission($user_id, 'statuses', 'add');
$can_edit_status = hasPermission($user_id, 'statuses', 'edit');
$can_delete_status = hasPermission($user_id, 'statuses', 'delete');

// --- 3. INITIALIZATION ---
$page_title = 'إدارة الحالات';
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

$status_tables = [
    'customer_order_statuses' => 'حالات طلبات العملاء',
    'purchase_basket_statuses' => 'حالات سلات الشراء',
    'purchase_group_statuses' => 'حالات مجموعات الشراء'
];

$all_statuses_data = [];

// --- 4. FETCH DATA ---
try {
    foreach ($status_tables as $table_name => $table_title) {
        $stmt = $db->query("SELECT id, status_key, status_name_ar, is_default, created_at FROM `{$table_name}` ORDER BY is_default DESC, id ASC");
        $all_statuses_data[$table_name] = [
            'title' => $table_title,
            'statuses' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع البيانات: ' . $e->getMessage();
    $all_statuses_data = [];
}

include '../../includes/header.php';
?>

<!-- STYLES (Same as before) -->
<style>
    :root {
        --primary: #3b82f6;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-700: #374151;
        --gray-800: #1f2937;
    }
    .page-container { padding: 20px; }
    .status-section { background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); overflow: hidden; margin-bottom: 30px; }
    .section-header { background: linear-gradient(135deg, var(--primary) 0%, #2563eb 100%); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
    .section-title { font-size: 18px; font-weight: 700; margin: 0; }
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; color: white; }
    .btn-success { background: var(--success); } .btn-success:hover { background: #059669; }
    .btn-danger { background: var(--danger); } .btn-danger:hover { background: #b91c1c; }
    .btn-warning { background: var(--warning); color: var(--gray-800); } .btn-warning:hover { background: #d97706; }
    .table-responsive { overflow-x: auto; }
    .status-table { width: 100%; border-collapse: collapse; }
    .status-table thead { background-color: var(--gray-100); }
    .status-table th, .status-table td { padding: 12px 15px; text-align: right; border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
    .status-table th { font-size: 13px; font-weight: 600; color: var(--gray-700); }
    .status-table tbody tr:hover { background-color: #f9fafb; }
    .status-table .actions-cell { display: flex; gap: 8px; }
    .status-table .action-btn { padding: 6px 10px; font-size: 12px; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .badge-success { background-color: #d1fae5; color: #065f46; }
    .badge-secondary { background-color: var(--gray-200); color: var(--gray-700); }
    .alert { padding: 15px; border-radius: 8px; margin: 0 20px 20px 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
</style>

<div dir="rtl" class="page-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="font-size: 24px; color: var(--gray-800);"><i class="fas fa-tags" style="color: var(--primary);"></i> <?php echo $page_title; ?></h1>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <?php foreach ($all_statuses_data as $table_name => $data): ?>
    <div class="status-section">
        <div class="section-header">
            <h2 class="section-title"><i class="fas fa-list-ul"></i> <?php echo htmlspecialchars($data['title']); ?></h2>
            <?php if ($can_add_status): ?>
                <!-- *** LINK UPDATED HERE *** -->
                <a href="create_status.php?table=<?php echo urlencode($table_name); ?>" class="btn btn-success">
                    <i class="fas fa-plus"></i> إضافة حالة جديدة
                </a>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="status-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المعرّف (Key)</th>
                        <th>الاسم بالعربي</th>
                        <th>افتراضي</th>
                        <th>تاريخ الإنشاء</th>
                        <?php if ($can_edit_status || $can_delete_status): ?><th>الإجراءات</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data['statuses'])): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">لا توجد حالات معرفة حاليًا.</td></tr>
                    <?php else: ?>
                        <?php foreach ($data['statuses'] as $status): ?>
                            <tr>
                                <td><?php echo $status['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($status['status_key']); ?></strong></td>
                                <td><?php echo htmlspecialchars($status['status_name_ar']); ?></td>
                                <td>
                                    <?php if ($status['is_default']): ?><span class="badge badge-success">نعم</span>
                                    <?php else: ?><span class="badge badge-secondary">لا</span><?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($status['created_at'])); ?></td>
                                <?php if ($can_edit_status || $can_delete_status): ?>
                                <td>
                                    <div class="actions-cell">
                                        <?php if ($can_edit_status): ?>
                                            <!-- *** LINK UPDATED HERE *** -->
                                            <a href="edit_status.php?id=<?php echo $status['id']; ?>&table=<?php echo urlencode($table_name); ?>" class="btn btn-warning action-btn">
                                                <i class="fas fa-edit"></i> تعديل
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($can_edit_status): ?>
                                            <form action="delete_status.php" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذه الحالة؟ لا يمكن التراجع عن هذا الإجراء.');">
                                                <input type="hidden" name="id" value="<?php echo $status['id']; ?>">
                                                <input type="hidden" name="table" value="<?php echo urlencode($table_name); ?>">
                                                <button type="submit" class="btn btn-danger action-btn"><i class="fas fa-trash-alt"></i> حذف</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include '../../includes/footer.php'; ?>