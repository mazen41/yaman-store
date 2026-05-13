<?php

/**
 * Purchase Baskets - Vertical Table Design
 * Version 4.5 (Edited)
 * - Added 'Account Number' to the view and search filter.
 * - Supports Custom Arabic Statuses from a dedicated 'purchase_basket_statuses' table.
 * - Includes a comprehensive table view with more columns from v3.3.
 * - Features AJAX for status updates, status creation, and basket deletion.
 * - Retains the search bar, tracking button, and responsive design.
 * - Added search by Tracking Number.
 * - Added filter by Status.
 * - Added filter for baskets with or without a Tracking Number.
 * - Added a totals row at the bottom of the table.
 * - Made basket name copyable on click.
 * - Added filter by Purchase Group Number.
 * - <<< FIXED: Replaced HAVING with WHERE EXISTS to fix the single-row collapse bug.
 */

// --- 1. INITIALIZATION & SECURITY ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// --- 2. DATABASE & CONFIGURATION ---
require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

$user_id = $_SESSION['user_id'] ?? 0;
if (!hasPermission($user_id, 'baskets', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض السلات';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add = hasPermission($user_id, 'baskets', 'add');
$can_edit_baskets = hasPermission($user_id, 'baskets', 'edit');
$can_delete_baskets = hasPermission($user_id, 'baskets', 'add');
$can_create_status = hasPermission($user_id, 'baskets', 'create_status'); // Permission for creating statuses

$page_title = 'عرض قائمة طلبات الشراء';
$error_message = '';
$baskets = [];
$all_statuses = [];
$purchase_groups = []; // To store purchase groups for the filter

// --- 3. FETCH DATA FROM DATABASE ---
try {
    // Fetch all available statuses for the dropdowns, ordered for better display
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM purchase_basket_statuses ORDER BY is_default DESC, status_name_ar ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all purchase groups for the filter dropdown
    $purchase_groups = $db->query("SELECT id, group_name FROM purchase_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all baskets, joining with other tables to get comprehensive data
    $sql = "
    SELECT
        pb.id, pb.basket_name, pb.basket_code, pb.created_at, pb.total_items,
        pb.account_number,
        pb.subtotal_amount, pb.discount_amount, pb.final_amount, pb.status,
        pbs.status_name_ar,
        pb.payment_source_type, pb.payment_source_id, pb.purchase_group_id, pg.group_name,
        u.username AS created_by_name, ba.bank_name, ba.account_number AS source_account_number,
        pc.card_name, pc.card_number,
        (SELECT GROUP_CONCAT(tracking_number SEPARATOR ', ') FROM basket_tracking WHERE basket_id = pb.id) AS tracking_numbers
    FROM purchase_baskets pb
    LEFT JOIN purchase_basket_statuses pbs ON pb.status = pbs.status_key
    LEFT JOIN purchase_groups pg ON pb.purchase_group_id = pg.id
    LEFT JOIN users u ON pb.created_by = u.id
    LEFT JOIN bank_accounts ba ON pb.payment_source_type = 'bank_account' AND pb.payment_source_id = ba.id
    LEFT JOIN purchase_cards pc ON pb.payment_source_type = 'purchase_card' AND pb.payment_source_id = pc.id
";

    // Get filter values from URL
    $search_term = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $status_filter = isset($_GET['status_filter']) ? trim((string) $_GET['status_filter']) : '';
    $tracking_filter = isset($_GET['tracking_filter']) ? trim((string) $_GET['tracking_filter']) : '';
    $group_filter = isset($_GET['group_filter']) ? trim((string) $_GET['group_filter']) : ''; 

    $conditions = [];
    $params = [];

    // Build query based on search term
    if (!empty($search_term)) {
        $conditions[] = "(
            pb.basket_name LIKE :search
            OR pb.basket_code LIKE :search
            OR pg.group_name LIKE :search
            OR pb.account_number LIKE :search
            OR EXISTS (
                SELECT 1 FROM basket_tracking bt
                WHERE bt.basket_id = pb.id AND bt.tracking_number LIKE :search
            )
        )";
        $params[':search'] = '%' . $search_term . '%';
    }

    // Add status filter condition to the query
     if (!empty($status_filter)) {
        // سنجلب الاسم العربي لهذه الحالة من قاعدة البيانات
        $stmt_status_name = $db->prepare("SELECT status_name_ar FROM purchase_basket_statuses WHERE status_key = ?");
        $stmt_status_name->execute([$status_filter]);
        $status_ar = $stmt_status_name->fetchColumn();

        // إضافة الكود الأصلي للحالة (مثلاً 'purchased') والاسم العربي ('تم الشراء')
        $temp_conditions = ["TRIM(pb.status) = :status_filter_key"];
        $params[':status_filter_key'] = $status_filter;

        if ($status_ar) {
            $temp_conditions[] = "TRIM(pb.status) = :status_filter_ar";
            $params[':status_filter_ar'] = $status_ar;
        }
        
        // إضافة بحث عن حالة 'ordered' إذا كان الفلتر هو 'purchased'
        if ($status_filter === 'purchased') {
            $temp_conditions[] = "TRIM(pb.status) = 'ordered'";
        }

        if (!empty($temp_conditions)) {
            $conditions[] = "(" . implode(" OR ", $temp_conditions) . ")";
        }
    }

    // Add group filter condition to the query
    if (!empty($group_filter)) {
        $conditions[] = "pb.purchase_group_id = :group_filter";
        $params[':group_filter'] = $group_filter;
    }
    
    // <<< FIXED: Add tracking number filter condition securely via EXISTS/NOT EXISTS instead of HAVING
    if ($tracking_filter === 'without_tracking') {
        $conditions[] = "NOT EXISTS (SELECT 1 FROM basket_tracking bt WHERE bt.basket_id = pb.id)";
    } elseif ($tracking_filter === 'with_tracking') {
        $conditions[] = "EXISTS (SELECT 1 FROM basket_tracking bt WHERE bt.basket_id = pb.id)";
    }

    // Append main conditions to the SQL query if any exist
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY pb.created_at DESC";

    $stmt = $db->prepare($sql);

    // Bind all parameters dynamically
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $baskets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "حدث خطأ أثناء جلب البيانات: " . $e->getMessage();
}

// --- 4. RENDER THE PAGE ---
include '../../includes/header.php';
?>

<!-- STYLES -->
<style>
    :root {
        --primary: #3b82f6;
        --success: #C7A46D;
        --danger: #ef4444;
        --warning: #f59e0b;
        --secondary: #6c757d;
    }

    .table-wrapper {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, .1);
        margin: 20px;
        overflow: visible
    }

    .table-container {
        overflow-x: auto !important;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        position: relative;
        width: 100%;
        display: block;
        cursor: grab;
        user-select: none
    }

    .table-container:active {
        cursor: grabbing
    }

    .table-page-header {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px
    }

    .table-page-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px
    }

    .search-filter-bar {
        padding: 15px 20px;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }

    .filter-form {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }

    .search-input {
        min-width: 150px;
        padding: 10px 15px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px
    }

    .search-input.flex-grow {
        flex-grow: 1;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, .1)
    }

    .vertical-table {
        width: 100%;
        min-width: 1400px;
        border-collapse: collapse;
        display: table;
        table-layout: auto
    }

    .vertical-table thead {
        background: #C7A46D;
        color: white
    }

    .vertical-table th {
        padding: 12px 15px;
        text-align: right;
        font-weight: 600;
        font-size: 14px;
        white-space: nowrap;
        position: sticky;
        top: 0;
        z-index: 10;
        background: #C7A46D
    }

    .vertical-table tbody tr {
        border-bottom: 1px solid #e5e7eb;
        transition: background .2s
    }

    .vertical-table tbody tr:hover {
        background: #f9fafb
    }

    .vertical-table td {
        padding: 15px;
        text-align: right;
        font-size: 14px;
        vertical-align: middle;
        white-space: nowrap
    }

    .status-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap
    }

    .status-default {
        background: #e5e7eb;
        color: #374151
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 8px 15px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        transition: all .2s;
        text-decoration: none;
        white-space: nowrap
    }

    .btn-primary {
        background: #3b82f6;
        color: white
    }

    .btn-primary:hover {
        background: #2563eb
    }

    .btn-success {
        background: #C7A46D;
        color: white
    }

    .btn-success:hover {
        background: #b8935d
    }

    .btn-danger {
        background: #ef4444;
        color: white
    }
    
    .btn-danger:hover {
        background: #dc2626
    }
    
    .btn-secondary {
        background-color: var(--secondary);
        color: white;
    }
    .btn-secondary:hover {
        background-color: #5a6268;
    }

    .btn-sm {
        padding: 5px 10px;
        font-size: 12px
    }

    .action-buttons {
        display: flex;
        gap: 5px;
        flex-wrap: wrap
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280
    }

    .empty-state i {
        font-size: 48px;
        color: #d1d5db;
        margin-bottom: 15px
    }

    .alert-danger {
        padding: 16px;
        border-radius: 8px;
        margin: 20px;
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #ef4444
    }

    .table-container::-webkit-scrollbar {
        height: 12px
    }

    .table-container::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px
    }

    .table-container::-webkit-scrollbar-thumb {
        background: #C7A46D;
        border-radius: 10px
    }

    .table-container::-webkit-scrollbar-thumb:hover {
        background: #b8935d
    }

    .scroll-indicator {
        text-align: center;
        padding: 12px;
        background: linear-gradient(135deg, #C7A46D 0%, #b8935d 100%);
        color: white;
        font-size: 14px;
        font-weight: 600;
        display: none;
        transition: opacity .3s ease
    }

    @media (max-width:1200px) {
        .scroll-indicator {
            display: block
        }
    }

    @media (max-width:768px) {
        .vertical-table {
            font-size: 12px
        }

        .vertical-table th,
        .vertical-table td {
            padding: 10px 8px
        }

        .table-page-header {
            flex-direction: column;
            align-items: stretch
        }

        .action-buttons {
            flex-direction: column
        }

        .btn {
            width: 100%;
            justify-content: center
        }
    }

    /* Styles for copyable text */
    .copyable-text {
        cursor: copy;
        position: relative;
    }

    .copyable-text::after {
        content: attr(data-tooltip);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background-color: #333;
        color: #fff;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s;
        z-index: 20;
    }

    .copyable-text:hover::after {
        opacity: 1;
    }
</style>

<!-- Page Content -->
<div class="page-header">
    <h1><i class="fas fa-archive"></i> <?php echo $page_title; ?></h1>
    <div class="breadcrumb">
        <a href="../../index.php"><i class="fas fa-home"></i> الرئيسية</a> / <a href="index.php">المشتريات</a> / <span>عرض الطلبات</span>
    </div>
</div>

<?php if ($error_message) : ?>
    <div class="alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div class="table-wrapper">
    <div class="table-page-header">
        <h2 class="table-page-title"><i class="fas fa-shopping-basket"></i> قائمة طلبات الشراء</h2>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="/modules/purchases/tracking.php" class="btn btn-primary"><i class="fas fa-truck"></i> التتبع</a>
            <?php if ($can_add) : ?>
                <a href="/modules/purchases/basket_complete.php" class="btn btn-success"><i class="fas fa-plus"></i> إنشاء سلة</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="search-filter-bar">
        <form method="GET" action="" class="filter-form">
            <input type="text" name="search" class="search-input flex-grow" placeholder="ابحث بالاسم، الكود، المجموعة، رقم الحساب، أو رقم التتبع..." value="<?php echo htmlspecialchars((string) $search_term); ?>">
            
            <select name="status_filter" class="search-input">
                <option value="">كل الحالات</option>
                <?php foreach ($all_statuses as $status) : ?>
                    <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($status_filter == $status['status_key']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status['status_name_ar']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="group_filter" class="search-input">
                <option value="">كل مجموعات الشراء</option>
                <?php foreach ($purchase_groups as $group) : ?>
                    <option value="<?php echo htmlspecialchars($group['id']); ?>" <?php echo ($group_filter == $group['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($group['group_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="tracking_filter" class="search-input">
                <option value="">الكل (التتبع)</option>
                <option value="with_tracking" <?php echo ($tracking_filter == 'with_tracking') ? 'selected' : ''; ?>>لديه رقم تتبع</option>
                <option value="without_tracking" <?php echo ($tracking_filter == 'without_tracking') ? 'selected' : ''; ?>>ليس لديه رقم تتبع</option>
            </select>
            
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
            <a href="index.php" class="btn btn-secondary">إلغاء</a>
        </form>
    </div>

    <?php if (empty($baskets) && !$error_message) : ?>
        <div class="empty-state">
            <i class="fas fa-shopping-basket"></i>
            <h3>لم يتم العثور على نتائج</h3>
            <p>حاول تغيير كلمات البحث أو إزالة الفلاتر. أو يمكنك إنشاء سلة جديدة.</p>
        </div>
    <?php else : ?>
        <div class="scroll-indicator"><i class="fas fa-arrows-alt-h"></i> اسحب لليمين أو اليسار لعرض جميع الأعمدة</div>
        <div class="table-container">
            <table class="vertical-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم السلة</th>
                        <th>الكود</th>
                        <th>رقم الحساب</th>
                        <th>رقم التتبع</th>
                        <th>عدد المنتجات</th>
                        <th>السعر قبل الخصم</th>
                        <th>السعر بعد الخصم</th>
                        <th>مصدر الدفع</th>
                        <th>الحالة</th>
                        <th>المجموعة</th>
                        <th>التاريخ</th>
                        <th>المنشئ</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_subtotal = 0;
                    $total_final_amount = 0;

                    foreach ($baskets as $index => $basket) :
                        $total_subtotal += $basket['subtotal_amount'] ?? 0;
                        $total_final_amount += $basket['final_amount'] ?? 0;
                    ?>
                        <tr id="basket-row-<?php echo $basket['id']; ?>">
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <strong class="copyable-text" data-tooltip="اضغط للنسخ" data-copy-text="<?php echo htmlspecialchars((string) ($basket['basket_name'] ?? 'سلة بدون اسم')); ?>">
                                    <?php echo htmlspecialchars((string) ($basket['basket_name'] ?? 'سلة بدون اسم')); ?>
                                </strong>
                            </td>
                            <td><code><?php echo htmlspecialchars((string) ($basket['basket_code'] ?? '')); ?></code></td>
                            <td><strong><?php echo htmlspecialchars((string) ($basket['account_number'] ?? '-')); ?></strong></td>
                            <td>
                                <?php if (!empty($basket['tracking_numbers'])) : ?>
                                    <span><i class="fas fa-truck ml-1"></i> <?php echo htmlspecialchars((string) $basket['tracking_numbers']); ?></span>
                                <?php else : ?><span>-</span><?php endif; ?>
                            </td>
                            <td><span><?php echo intval($basket['total_items']); ?> منتج</span></td>
                            <td><strong><?php echo number_format($basket['subtotal_amount'] ?? 0); ?> ر.ي</strong></td>
                            <td><strong><?php echo number_format($basket['final_amount'] ?? 0); ?> ر.ي</strong></td>
                            <td>
                                <?php
                                $payment_type = $basket['payment_source_type'] ?? '';
                                if ($payment_type == 'bank_account') {
                                    echo "<div><span>حساب بنكي</span></div><small>" . htmlspecialchars(($basket['bank_name'] ?? '') . ' - ' . ($basket['source_account_number'] ?? '')) . "</small>";
                                } elseif ($payment_type == 'purchase_card') {
                                    echo "<div><span>بطاقة شراء</span></div><small>" . htmlspecialchars(!empty($basket['card_name']) ? ($basket['card_name'] ?? '') : ($basket['card_number'] ?? '')) . "</small>";
                                } else {
                                    echo "<span>غير محدد</span>";
                                }
                                ?>
                            </td>
                          <td>
    <select class="status-dropdown" data-basket-id="<?php echo $basket['id']; ?>" data-original-status="<?php echo htmlspecialchars($basket['status']); ?>">
        <?php foreach ($all_statuses as $status) : ?>
            <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($basket['status'] == $status['status_key']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($status['status_name_ar']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</td>
                            <td><?php echo htmlspecialchars((string) ($basket['group_name'] ?? 'بدون مجموعة')); ?></td>
                            <td><small><?php echo date('d/m/Y', strtotime($basket['created_at'])); ?></small></td>
                            <td><small><?php echo htmlspecialchars((string) ($basket['created_by_name'] ?? 'N/A')); ?></small></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view_basket.php?id=<?php echo $basket['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> عرض</a>
                                    <?php if ($can_edit_baskets) : ?>
                                        <a href="edit_basket.php?id=<?php echo $basket['id']; ?>" class="btn btn-success btn-sm"><i class="fas fa-edit"></i> تعديل</a>
                                    <?php endif; ?>
                                    <?php if ($can_delete_baskets) : ?>
                                        <button class="btn btn-danger btn-sm delete-btn" data-basket-id="<?php echo $basket['id']; ?>">
                                            <i class="fas fa-trash"></i> حذف
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8f9fa; font-weight: bold; border-top: 2px solid #dee2e6;">
                        <td colspan="6" style="text-align: center; font-size: 16px;">الإجمالي</td>
                        <td style="font-size: 16px;"><strong><?php echo number_format($total_subtotal); ?> ر.ي</strong></td>
                        <td style="font-size: 16px;"><strong><?php echo number_format($total_final_amount); ?> ر.ي</strong></td>
                        <td colspan="6"></td> 
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- JAVASCRIPT -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tableBody = document.querySelector('.vertical-table tbody');

        // --- STATUS UPDATE & CREATION LOGIC ---
        if (tableBody) {
            tableBody.addEventListener('change', async function(event) {
                if (event.target.classList.contains('status-dropdown')) {
                    const dropdown = event.target;
                    const basketId = dropdown.dataset.basketId;
                    const newStatusKey = dropdown.value;
                    const originalStatusKey = dropdown.dataset.originalStatus;

                    if (newStatusKey === 'custom_status') {
                        await handleCreateNewStatus(dropdown, basketId, originalStatusKey);
                    } else {
                        if (confirm('هل أنت متأكد من تغيير حالة السلة؟')) {
                            await updateStatus(basketId, newStatusKey, dropdown);
                        } else {
                            dropdown.value = originalStatusKey; // Revert if cancelled
                        }
                    }
                }
            });
        }

        async function handleCreateNewStatus(dropdown, basketId, originalStatusKey) {
            const arabicName = prompt("الرجاء إدخال اسم الحالة الجديد (باللغة العربية):");
            if (!arabicName || arabicName.trim() === "") {
                dropdown.value = originalStatusKey;
                return;
            }

            const englishKey = prompt("الرجاء إدخال معرّف فريد للحالة (إنجليزية، بدون مسافات، مثال: arrived_jeddah):");
            if (!englishKey || !/^[a-z0-9_]+$/.test(englishKey.trim())) {
                alert("المعرّف غير صالح. الرجاء استخدام أحرف إنجليزية صغيرة، أرقام، وشرطة سفلية (_).");
                dropdown.value = originalStatusKey;
                return;
            }

            try {
                const response = await fetch('api/create_basket_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        basket_id: basketId,
                        status_key: englishKey.trim(),
                        status_name_ar: arabicName.trim()
                    })
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'حدث خطأ غير متوقع.');
                }

                const newOption = new Option(data.new_status.status_name_ar, data.new_status.status_key);
                document.querySelectorAll('.status-dropdown').forEach(d => {
                    const customOpt = d.querySelector('option[value="custom_status"]');
                    d.insertBefore(newOption.cloneNode(true), customOpt);
                });

                dropdown.value = data.new_status.status_key;
                dropdown.dataset.originalStatus = data.new_status.status_key;

                alert(data.message);

            } catch (error) {
                console.error('Error creating new status:', error);
                alert('خطأ: ' + error.message);
                dropdown.value = originalStatusKey; 
            }
        }

        async function updateStatus(basketId, newStatusKey, dropdown) {
            const originalStatusKey = dropdown.dataset.originalStatus;
            try {
                const response = await fetch('api/update_basket_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        basket_id: basketId,
                        status: newStatusKey
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'فشل تحديث الحالة');
                }
                dropdown.dataset.originalStatus = newStatusKey; 
                alert('تم تحديث الحالة بنجاح');
            } catch (error) {
                console.error('Error:', error);
                alert('خطأ: ' + error.message);
                dropdown.value = originalStatusKey; 
            }
        }

        // --- DELETE BASKET LOGIC ---
        if (tableBody) {
            tableBody.addEventListener('click', async function(event) {
                if (event.target.classList.contains('delete-btn') || event.target.closest('.delete-btn')) {
                    const deleteButton = event.target.closest('.delete-btn');
                    const basketId = deleteButton.dataset.basketId;
                    if (!confirm('هل أنت متأكد من حذف هذه السلة؟ لا يمكن التراجع عن هذا الإجراء.')) return;

                    try {
                        const response = await fetch('api/delete_basket.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                basket_id: basketId
                            })
                        });
                        if (!response.ok) {
                            let errorMsg = `HTTP error! Status: ${response.status}`;
                            try {
                                errorMsg = (await response.json()).message || 'فشل حذف السلة';
                            } catch (e) {}
                            throw new Error(errorMsg);
                        }
                        const data = await response.json();
                        if (data.success) {
                            const rowToRemove = document.getElementById('basket-row-' + basketId);
                            if (rowToRemove) {
                                rowToRemove.style.transition = 'opacity 0.5s ease';
                                rowToRemove.style.opacity = '0';
                                setTimeout(() => rowToRemove.remove(), 500);
                            }
                            alert('تم حذف السلة بنجاح');
                        } else {
                            throw new Error(data.message || 'فشل حذف السلة من الخادم');
                        }
                    } catch (error) {
                        console.error('Deletion Error:', error);
                        alert('خطأ: ' + error.message);
                    }
                }
            });
        }

        // --- HORIZONTAL SCROLLING LOGIC FOR TABLE ---
        const tableContainer = document.querySelector('.table-container');
        if (tableContainer) {
            let isDown = false,
                startX, scrollLeftStart;
            tableContainer.addEventListener('mousedown', (e) => {
                if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT') return;
                isDown = true;
                tableContainer.style.cursor = 'grabbing';
                startX = e.pageX - tableContainer.offsetLeft;
                scrollLeftStart = tableContainer.scrollLeft;
            });
            tableContainer.addEventListener('mouseleave', () => {
                isDown = false;
                tableContainer.style.cursor = 'grab';
            });
            tableContainer.addEventListener('mouseup', () => {
                isDown = false;
                tableContainer.style.cursor = 'grab';
            });
            tableContainer.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - tableContainer.offsetLeft;
                const walk = (x - startX) * 2; 
                tableContainer.scrollLeft = scrollLeftStart - walk;
            });
        }

        // --- COPYABLE TEXT LOGIC ---
        document.querySelectorAll('.copyable-text').forEach(item => {
            item.addEventListener('click', function() {
                const textToCopy = this.dataset.copyText;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalTooltip = this.dataset.tooltip;
                    this.dataset.tooltip = 'تم النسخ!';
                    setTimeout(() => {
                        this.dataset.tooltip = originalTooltip;
                    }, 1000);
                }).catch(err => {
                    console.error('Failed to copy text: ', err);
                });
            });
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>