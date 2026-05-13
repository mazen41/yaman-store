<?php

/**
 * Purchase Groups - Vertical Table Design
 * Version 4.1 - Added Delete Functionality
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';

$user_id = $_SESSION['user_id'] ?? 0;
if (!hasPermission($user_id, 'purchase_groups', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض المجموعات';
    header('Location: ../../../index.php');
    exit();
}

// Get permissions
$can_edit_groups = hasPermission($user_id, 'purchase_groups', 'edit');
$can_delete_groups = hasPermission($user_id, 'purchase_groups', 'delete');
$can_create_status = hasPermission($user_id, 'purchase_groups', 'create_status');

$page_title = 'مجموعات الشراء';
$error_message = '';
$groups = [];
$all_statuses = [];

// Filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_search = $_GET['search'] ?? '';

try {
    // Fetch all available statuses for the dropdowns
    $all_statuses = $db->query("SELECT status_key, status_name_ar FROM purchase_group_statuses ORDER BY is_default DESC, status_name_ar ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Build WHERE clauses for filters
    $manual_where = [];
    if (!empty($filter_status)) $manual_where[] = "pg.status = " . $db->quote($filter_status);
    if (!empty($filter_date_from)) $manual_where[] = "pg.start_date >= " . $db->quote($filter_date_from);
    if (!empty($filter_date_to)) $manual_where[] = "pg.start_date <= " . $db->quote($filter_date_to);
    if (!empty($filter_search)) {
        $search_term = $db->quote('%' . $filter_search . '%');
        $manual_where[] = "(pg.group_number LIKE $search_term OR pg.group_name LIKE $search_term)";
    }
    $manual_where_clause = !empty($manual_where) ? 'WHERE ' . implode(' AND ', $manual_where) : '';

    // Main Query to fetch groups
    $final_query = "
        SELECT 
            pg.id, pg.group_number as group_code, pg.group_name, pg.description, pg.start_date as purchase_date,
            pg.status,
            pgs.status_name_ar,
            pg.created_at, pg.notes,
            u.username as created_by_user,
            (SELECT COUNT(DISTINCT co.id) FROM customer_orders co WHERE co.purchase_group_id = pg.id) as orders_count,
            (SELECT COUNT(DISTINCT pb.id) FROM purchase_baskets pb WHERE pb.purchase_group_id = pg.id) as baskets_count,
            (SELECT SUM(COALESCE(co.final_amount, 0)) FROM customer_orders co WHERE co.purchase_group_id = pg.id) as total_amount
        FROM purchase_groups pg
        LEFT JOIN users u ON pg.created_by = u.id
        LEFT JOIN purchase_group_statuses pgs ON pg.status = pgs.status_key
        $manual_where_clause
        ORDER BY pg.created_at DESC
    ";

    $stmt = $db->prepare($final_query);
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء جلب البيانات: ' . $e->getMessage();
}

include '../../../includes/header.php';
?>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

<!-- STYLES -->
<style>
    :root {
        --primary: #3b82f6;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
    }

    .table-wrapper { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); overflow: hidden; margin: 20px; }
    .table-page-header { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
    .table-page-title { font-size: 20px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; }
    .stats-bar { padding: 15px; background: white; border-bottom: 2px solid #e5e7eb; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    .stat-item { background: white; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); text-align: center; }
    .stat-label { font-size: 14px; color: #6b7280; margin-bottom: 8px; display: block; }
    .stat-value { font-size: 28px; font-weight: 700; color: #111827; display: block; }
    .table-container { overflow-x: auto; width: 100%; padding: 15px; background: #f9fafb; }
    .vertical-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .vertical-table thead { background: #10b981; color: white; }
    .vertical-table th { padding: 12px 15px; text-align: right; font-weight: 600; font-size: 14px; white-space: nowrap; }
    .vertical-table tbody tr { border-bottom: 1px solid #e5e7eb; transition: background 0.2s, opacity 0.3s; }
    .vertical-table tbody tr.is-deleting { opacity: 0; transform: scale(0.95); }
    .vertical-table tbody tr:hover { background: #f9fafb; }
    .vertical-table td { padding: 15px; text-align: right; font-size: 14px; vertical-align: middle; }
    .btn { display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; white-space: nowrap; }
    .btn-primary { background: #3b82f6; color: white; }
    .btn-success { background: #10b981; color: white; }
    .btn-warning { background: #f97316; color: white; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; }
    .empty-state { text-align: center; padding: 60px 20px; color: #6b7280; }
    .alert-danger { padding: 16px; border-radius: 8px; margin: 20px; background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
    .filter-section { background: white; padding: 20px; border-bottom: 1px solid #e5e7eb; }
    .status-dropdown { padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; min-width: 150px; }
    .status-badge { padding: 6px 12px; background: #f3f4f6; border-radius: 6px; font-size: 13px; color: #6b7280; }
</style>

<!-- Page Content -->
<div class="table-wrapper">
    <!-- Header -->
    <div class="table-page-header">
        <h2 class="table-page-title"><i class="fas fa-layer-group"></i> <?php echo $page_title; ?></h2>
        <a href="add.php" class="btn btn-success"><i class="fas fa-plus"></i> إضافة مجموعة يدوية</a>
    </div>

    <!-- Statistics -->
    <?php
    $total_groups = count($groups);
    $total_orders = array_sum(array_column($groups, 'orders_count'));
    $total_value = array_sum(array_column($groups, 'total_amount'));
    ?>
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-label"><i class="fas fa-layer-group"></i> إجمالي المجموعات</div>
            <div class="stat-value"><?php echo $total_groups; ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label"><i class="fas fa-file-invoice"></i> إجمالي الطلبات</div>
            <div class="stat-value"><?php echo $total_orders; ?></div>
        </div>
        <div class="stat-item">
            <div class="stat-label"><i class="fas fa-coins"></i> القيمة الإجمالية</div>
            <div class="stat-value"><?php echo number_format($total_value, 0, '', ''); ?> ر.ي</div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form method="GET" action="" id="filterForm">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
                <div>
                    <label>بحث</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="رقم أو اسم المجموعة..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label>الحالة</label>
                    <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">الكل</option>
                        <?php foreach ($all_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo $filter_status === $status['status_key'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status['status_name_ar']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">بحث</button>
                    <a href="?" class="btn" style="background:#6b7280;color:white;">إعادة تعيين</a>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (empty($groups)): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open" style="font-size: 48px; color: #d1d5db; margin-bottom: 15px;"></i>
            <h3>لا توجد مجموعات شراء تطابق البحث</h3>
            <p style="margin-bottom: 20px;">يمكنك إضافة مجموعة يدوياً لتنظيم سلال الشراء والطلبات.</p>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> إنشاء مجموعة يدوية
            </a>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="vertical-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>اسم المجموعة</th>
                        <th>عدد السلال/الطلبات</th>
                        <th>تاريخ البدء</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody id="groups-table-body">
                    <?php foreach ($groups as $index => $group): ?>
                        <tr id="group-row-<?php echo $group['id']; ?>">
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                <?php if (!empty($group['description'])): ?>
                                    <br><small style="color: #6b7280;"><?php echo htmlspecialchars($group['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span title="Baskets"><?php echo $group['baskets_count'] ?? 0; ?> <i class="fas fa-shopping-basket"></i></span> /
                                <span title="Orders"><?php echo $group['orders_count'] ?? 0; ?> <i class="fas fa-file-invoice"></i></span>
                            </td>
                            <td><small><?php echo date('Y-m-d', strtotime($group['created_at'])); ?></small></td>
                            <td>
                                <?php if ($can_edit_groups): ?>
                                    <select class="status-dropdown" data-group-id="<?php echo $group['id']; ?>" data-original-status="<?php echo htmlspecialchars($group['status']); ?>">
                                        <?php foreach ($all_statuses as $status): ?>
                                            <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($group['status'] == $status['status_key']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($status['status_name_ar']); ?>
                                            </option>
                                        <?php endforeach; ?>

                                        <?php if ($can_create_status): ?>
                                            <option value="custom_status" style="font-weight: bold; background-color: #f0f0f0;">+ إضافة حالة جديدة</option>
                                        <?php endif; ?>
                                    </select>
                                <?php else: ?>
                                    <span class="status-badge"><?php echo htmlspecialchars($group['status_name_ar'] ?? $group['status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view.php?id=<?php echo $group['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> عرض</a>
                                    <?php if ($can_edit_groups): ?>
                                        <a href="edit.php?id=<?php echo $group['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> تعديل</a>
                                    <?php endif; ?>

                                    <?php if ($can_edit_groups): ?>
                                        <button class="btn btn-danger btn-sm delete-group-btn" data-group-id="<?php echo $group['id']; ?>">
                                            <i class="fas fa-trash"></i> حذف
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tableBody = document.getElementById('groups-table-body');

        if(tableBody) {
            tableBody.addEventListener('click', function(event) {
                if (event.target.classList.contains('delete-group-btn')) {
                    handleDeleteGroup(event.target);
                }
            });

            tableBody.addEventListener('change', async function(event) {
                if (event.target.classList.contains('status-dropdown')) {
                    const dropdown = event.target;
                    const groupId = dropdown.dataset.groupId;
                    const newStatusKey = dropdown.value;
                    const originalStatusKey = dropdown.dataset.originalStatus;

                    if (newStatusKey === 'custom_status') {
                        await handleCreateNewStatus(dropdown, groupId, originalStatusKey);
                    } else {
                        if (confirm('هل أنت متأكد من تغيير حالة المجموعة؟')) {
                            await updateStatus(groupId, newStatusKey, dropdown);
                        } else {
                            dropdown.value = originalStatusKey; 
                        }
                    }
                }
            });
        }

        async function handleDeleteGroup(button) {
            const groupId = button.dataset.groupId;
            const groupRow = document.getElementById('group-row-' + groupId);

            if (!confirm('هل أنت متأكد من حذف هذه المجموعة؟ لا يمكن التراجع عن هذا الإجراء.')) return;

            try {
                const response = await fetch('api/delete_group.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ group_id: groupId })
                });

                // Check if response is real JSON to prevent HTML/PHP crash parsing
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    throw new Error("خطأ في الخادم: تم إرجاع استجابة غير صحيحة (تأكد من وجود وتصريح ملف الـ API).");
                }

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'فشل حذف المجموعة');
                }

                groupRow.classList.add('is-deleting');
                groupRow.addEventListener('transitionend', () => groupRow.remove());
                alert('تم حذف المجموعة بنجاح.');

            } catch (error) {
                console.error('Error deleting group:', error);
                alert('خطأ: ' + error.message);
            }
        }

        async function handleCreateNewStatus(dropdown, groupId, originalStatusKey) {
            const arabicName = prompt("الرجاء إدخال اسم الحالة الجديد (باللغة العربية):");
            if (!arabicName || arabicName.trim() === "") { dropdown.value = originalStatusKey; return; }

            const englishKey = prompt("الرجاء إدخال معرّف فريد للحالة (إنجليزية، بدون مسافات، مثال: customs_cleared):");
            if (!englishKey || !/^[a-z0-9_]+$/.test(englishKey.trim())) {
                alert("المعرّف غير صالح. الرجاء استخدام أحرف إنجليزية صغيرة، أرقام، وشرطة سفلية (_).");
                dropdown.value = originalStatusKey;
                return;
            }

            try {
                const response = await fetch('api/create_group_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        group_id: groupId,
                        status_key: englishKey.trim(),
                        status_name_ar: arabicName.trim()
                    })
                });

                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    throw new Error("خطأ في الخادم: تم إرجاع استجابة غير صحيحة (تأكد من مسار API).");
                }

                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || 'حدث خطأ.');

                const newOption = new Option(data.new_status.status_name_ar, data.new_status.status_key);
                document.querySelectorAll('.status-dropdown').forEach(d => {
                    const customOpt = d.querySelector('option[value="custom_status"]');
                    if (customOpt) d.insertBefore(newOption.cloneNode(true), customOpt);
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

        async function updateStatus(groupId, newStatusKey, dropdown) {
            const originalStatusKey = dropdown.dataset.originalStatus;
            try {
                const response = await fetch('api/update_group_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest' // Helps bypass unauthorized HTML redirects
                    },
                    body: JSON.stringify({
                        group_id: groupId,
                        status: newStatusKey
                    })
                });

                // Safety Check: if the API errored out and sent HTML, catch it here cleanly
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    throw new Error("مسار API غير صحيح، أو حدث خطأ داخلي في الخادم أدى لإرجاع HTML بدلاً من JSON.");
                }

                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || 'فشل تحديث الحالة');

                dropdown.dataset.originalStatus = newStatusKey;
                alert('تم تحديث الحالة بنجاح');
            } catch (error) {
                console.error('Error updating status:', error);
                alert('خطأ: ' + error.message);
                dropdown.value = originalStatusKey; 
            }
        }
    });
</script>

<?php include '../../../includes/footer.php'; ?>