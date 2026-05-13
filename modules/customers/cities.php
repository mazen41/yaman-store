<?php
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إدارة المدن';
$error_message = '';
$success_message = '';
$edit_mode = false;
$city_to_edit = null;

// Handle POST requests for Adding and Updating cities
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $city_name = trim($_POST['city_name'] ?? '');
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);

    // Basic validation
    if (empty($city_name)) {
        $error_message = 'اسم المدينة مطلوب.';
    } else {
        // --- ADD a new city ---
        if ($_POST['action'] == 'add') {
            try {
                // Check if city already exists
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM cities WHERE name = ?");
                $check_stmt->execute([$city_name]);
                if ($check_stmt->fetchColumn() > 0) {
                    $error_message = "المدينة '{$city_name}' موجودة بالفعل.";
                } else {
                    $stmt = $db->prepare("INSERT INTO cities (name, shipping_cost, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())");
                    $stmt->execute([$city_name, $shipping_cost]);
                    $success_message = "تمت إضافة المدينة '{$city_name}' بنجاح.";
                }
            } catch (PDOException $e) {
                $error_message = 'فشل في إضافة المدينة: ' . $e->getMessage();
            }
        }
        // --- UPDATE a city ---
        elseif ($_POST['action'] == 'update' && isset($_POST['city_id'])) {
            $city_id = intval($_POST['city_id']);
            try {
                // Check if the new name conflicts with another existing city
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM cities WHERE name = ? AND id != ?");
                $check_stmt->execute([$city_name, $city_id]);
                if ($check_stmt->fetchColumn() > 0) {
                     $error_message = "لا يمكن التحديث، الاسم '{$city_name}' مستخدم لمدينة أخرى.";
                } else {
                    $stmt = $db->prepare("UPDATE cities SET name = ?, shipping_cost = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$city_name, $shipping_cost, $city_id]);
                    $success_message = "تم تحديث المدينة بنجاح.";
                }
            } catch (PDOException $e) {
                $error_message = 'فشل في تحديث المدينة: ' . $e->getMessage();
            }
        }
    }
}

// Handle GET requests for Editing and Toggling Status
if (isset($_GET['action'])) {
    $city_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // --- Prepare for EDITING a city ---
    if ($_GET['action'] == 'edit' && $city_id > 0) {
        $edit_stmt = $db->prepare("SELECT id, name, shipping_cost FROM cities WHERE id = ?");
        $edit_stmt->execute([$city_id]);
        $city_to_edit = $edit_stmt->fetch(PDO::FETCH_ASSOC);
        if ($city_to_edit) {
            $edit_mode = true;
        } else {
            $error_message = 'المدينة المطلوبة غير موجودة.';
        }
    }

    // --- TOGGLE active status ---
    if ($_GET['action'] == 'toggle_status' && $city_id > 0) {
        try {
            // First, get the current status
            $status_stmt = $db->prepare("SELECT is_active FROM cities WHERE id = ?");
            $status_stmt->execute([$city_id]);
            $current_status = $status_stmt->fetchColumn();
            
            // Toggle the status
            $new_status = $current_status == 1 ? 0 : 1;
            
            $toggle_stmt = $db->prepare("UPDATE cities SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $toggle_stmt->execute([$new_status, $city_id]);
            
            $status_text = $new_status == 1 ? 'تفعيل' : 'إلغاء تفعيل';
            $success_message = "تم {$status_text} المدينة بنجاح.";

        } catch (PDOException $e) {
            $error_message = 'فشل تغيير حالة المدينة: ' . $e->getMessage();
        }
    }
}

// Fetch all cities to display in the table
try {
    $cities_stmt = $db->prepare("SELECT id, name, shipping_cost, is_active, created_at FROM cities ORDER BY name ASC");
    $cities_stmt->execute();
    $cities = $cities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cities = [];
    $error_message = 'فشل في تحميل قائمة المدن.';
}

include '../../includes/header.php';
?>

<style>
    /* Using the same professional style from your customer page */
    * { font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .form-card { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 24px; }
    .form-card-header { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px 24px; }
    .form-card-header.edit-mode { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
    .form-card-header h2 { font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 12px; }
    .form-card-body { padding: 32px 24px; }
    .form-group { margin-bottom: 24px; }
    .form-group label { display: block; font-weight: 600; color: #374151; margin-bottom: 8px; font-size: 0.95rem; }
    .form-control { width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.2s ease; border: none; text-decoration: none; }
    .btn-primary { background: #2563eb; color: white; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-warning { background: #f97316; color: white; }
    .btn-warning:hover { background: #ea580c; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-secondary:hover { background: #4b5563; }
    .btn-sm { padding: 6px 12px; font-size: 0.85rem; gap: 6px; }
    .btn-success { background-color: #C7A46D; color: white; }
    .btn-danger { background-color: #dc2626; color: white; }
    .btn-group { display: flex; gap: 12px; flex-wrap: wrap; }
    .alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 1rem; }
    .alert-success { background: #d1fae5; border: 2px solid #6ee7b7; color: #065f46; }
    .alert-error { background: #fee2e2; border: 2px solid #fca5a5; color: #991b1b; }
    
    /* Responsive Form Row */
    .form-row { display: flex; gap: 20px; flex-wrap: wrap; }
    .form-row .form-group.flex-2 { flex: 2; min-width: 250px; }
    .form-row .form-group.flex-1 { flex: 1; min-width: 150px; }

    /* Responsive Table Styles */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .table { width: 100%; border-collapse: collapse; min-width: 750px; }
    .table th, .table td { padding: 12px 15px; text-align: right; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
    .table th { background-color: #f9fafb; font-weight: 700; color: #374151; }
    .table tr:last-child td { border-bottom: none; }
    .table tr:hover { background-color: #f3f4f6; }
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .status-active { background-color: #dcfce7; color: #166534; }
    .status-inactive { background-color: #fee2e2; color: #991b1b; }
</style>

<div class="container-fluid py-4" dir="rtl">
    <div class="page-header mb-4">
        <h1><?php echo $page_title; ?></h1>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div><?php endif; ?>

    <!-- Add/Edit City Form Card -->
    <div class="form-card" id="form-card">
        <div class="form-card-header <?php echo $edit_mode ? 'edit-mode' : ''; ?>">
            <h2>
                <?php if ($edit_mode): ?>
                    <i class="fas fa-edit"></i> تعديل مدينة: <?php echo htmlspecialchars($city_to_edit['name']); ?>
                <?php else: ?>
                    <i class="fas fa-plus-circle"></i> إضافة مدينة جديدة
                <?php endif; ?>
            </h2>
        </div>
        <form method="POST" action="cities.php" class="form-card-body">
            <!-- Hidden inputs to control form action -->
            <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update' : 'add'; ?>">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="city_id" value="<?php echo $city_to_edit['id']; ?>">
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group flex-2">
                    <label for="city_name">اسم المدينة</label>
                    <input type="text" id="city_name" name="city_name" class="form-control" 
                           value="<?php echo htmlspecialchars($city_to_edit['name'] ?? ($_POST['city_name'] ?? '')); ?>" 
                           placeholder="مثال: صنعاء، عدن..." required>
                </div>

                <div class="form-group flex-1">
                    <label for="shipping_cost">تكلفة الشحن</label>
                    <input type="number" step="0.01" id="shipping_cost" name="shipping_cost" class="form-control" 
                           value="<?php echo htmlspecialchars($city_to_edit['shipping_cost'] ?? ($_POST['shipping_cost'] ?? '0')); ?>" 
                           placeholder="0.00">
                </div>
            </div>

            <div class="btn-group">
                <?php if ($edit_mode): ?>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save"></i> تحديث المدينة</button>
                    <a href="cities.php" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء التعديل</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة المدينة</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Cities List Card -->
    <div class="form-card">
        <div class="form-card-header">
            <h2><i class="fas fa-list-ul"></i> قائمة المدن</h2>
        </div>
        <div class="form-card-body" style="padding: 0;">
            <?php if (empty($cities)): ?>
                <div class="p-4 text-center">لا توجد مدن مضافة حالياً.</div>
            <?php else: ?>
                <!-- Added Table Responsive Wrapper -->
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم المدينة</th>
                                <th>تكلفة الشحن</th>
                                <th>الحالة</th>
                                <th>تاريخ الإضافة</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cities as $index => $city): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($city['name']); ?></td>
                                <td><strong><?php echo number_format($city['shipping_cost'], 2); ?></strong></td>
                                <td>
                                    <?php if ($city['is_active']): ?>
                                        <span class="status-badge status-active">مفعل</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive">غير مفعل</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($city['created_at'])); ?></td>
                                <td>
                                    <div class="btn-group" style="gap: 6px; flex-wrap: nowrap;">
                                        <a href="cities.php?action=edit&id=<?php echo $city['id']; ?>#form-card" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i> تعديل</a>
                                        <?php if ($city['is_active']): ?>
                                            <a href="cities.php?action=toggle_status&id=<?php echo $city['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('هل أنت متأكد من إلغاء تفعيل هذه المدينة؟')"><i class="fas fa-times-circle"></i> إلغاء التفعيل</a>
                                        <?php else: ?>
                                            <a href="cities.php?action=toggle_status&id=<?php echo $city['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('هل أنت متأكد من تفعيل هذه المدينة؟')"><i class="fas fa-check-circle"></i> تفعيل</a>
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
    </div>
</div>

<?php include '../../includes/footer.php'; ?>