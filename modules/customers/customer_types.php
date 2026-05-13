<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check view permission
if (!hasPermission($_SESSION['user_id'], 'customer_types', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لعرض هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

// Get permissions for UI control
$can_add = hasPermission($_SESSION['user_id'], 'customer_types', 'add');
$can_edit = hasPermission($_SESSION['user_id'], 'customer_types', 'edit');

$page_title = 'إدارة أنواع العملاء';
$error_message = '';
$success_message = '';

// Handle form submissions (Add, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // --- Add new type with discount tiers ---
        if (isset($_POST['add_type'])) {
            // Check add permission
            if (!$can_add) {
                throw new Exception('ليس لديك صلاحية لإضافة نوع جديد');
            }
            $name = trim($_POST['name']);

            if (empty($name)) {
                throw new Exception('اسم النوع مطلوب.');
            }

            $db->beginTransaction();
            
            // Insert customer type
            $stmt = $db->prepare("INSERT INTO customer_types (name, discount_percentage) VALUES (?, 0)");
            $stmt->execute([$name]);
            $type_id = $db->lastInsertId();
            
            // Insert discount tiers
            for ($i = 1; $i <= 3; $i++) {
                $min = floatval($_POST["tier{$i}_min"] ?? 0);
                $max = !empty($_POST["tier{$i}_max"]) ? floatval($_POST["tier{$i}_max"]) : null;
                $discount = floatval($_POST["tier{$i}_discount"] ?? 0);
                
                if ($discount < 0 || $discount > 100) {
                    throw new Exception("نسبة الخصم للمستوى {$i} يجب أن تكون بين 0 و 100.");
                }
                
                $stmt = $db->prepare("
                    INSERT INTO customer_type_discount_tiers 
                    (customer_type_id, tier_number, min_amount, max_amount, discount_percentage) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$type_id, $i, $min, $max, $discount]);
            }
            
            $db->commit();
            $success_message = 'تم إضافة النوع بنجاح.';
        }

        // --- Update existing type with discount tiers ---
        if (isset($_POST['edit_type'])) {
            // Check edit permission
            if (!$can_edit) {
                throw new Exception('ليس لديك صلاحية لتعديل هذا النوع');
            }
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);

            if (empty($name)) {
                throw new Exception('اسم النوع لا يمكن أن يكون فارغاً.');
            }

            $db->beginTransaction();
            
            // Update customer type name
            $stmt = $db->prepare("UPDATE customer_types SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            
            // Delete existing tiers
            $stmt = $db->prepare("DELETE FROM customer_type_discount_tiers WHERE customer_type_id = ?");
            $stmt->execute([$id]);
            
            // Insert new discount tiers
            for ($i = 1; $i <= 3; $i++) {
                $min = floatval($_POST["tier{$i}_min"] ?? 0);
                $max = !empty($_POST["tier{$i}_max"]) ? floatval($_POST["tier{$i}_max"]) : null;
                $discount = floatval($_POST["tier{$i}_discount"] ?? 0);
                
                if ($discount < 0 || $discount > 100) {
                    throw new Exception("نسبة الخصم للمستوى {$i} يجب أن تكون بين 0 و 100.");
                }
                
                $stmt = $db->prepare("
                    INSERT INTO customer_type_discount_tiers 
                    (customer_type_id, tier_number, min_amount, max_amount, discount_percentage) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id, $i, $min, $max, $discount]);
            }
            
            $db->commit();
            $success_message = 'تم تحديث النوع بنجاح.';
        }

        // --- Toggle active status ---
        if (isset($_POST['toggle_active'])) {
            $id = intval($_POST['id']);
            $new_status = isset($_POST['new_status']) && intval($_POST['new_status']) === 0 ? 0 : 1;

            $stmt = $db->prepare("UPDATE customer_types SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);

            $success_message = 'تم تحديث حالة النوع بنجاح.';
        }

        // --- Delete type ---
        if (isset($_POST['delete_type'])) {
            $id = intval($_POST['id']);
            // Check if type is in use
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_type_id = ?");
            $check_stmt->execute([$id]);
            if ($check_stmt->fetchColumn() > 0) {
                throw new Exception('لا يمكن حذف هذا النوع لأنه مرتبط بعملاء حاليين.');
            }
            
            // Delete type (tiers will be deleted automatically due to CASCADE)
            $stmt = $db->prepare("DELETE FROM customer_types WHERE id = ?");
            $stmt->execute([$id]);
            $success_message = 'تم حذف النوع بنجاح.';
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Fetch all customer types with their discount tiers
try {
    $types_stmt = $db->query("SELECT id, name, created_at, is_active FROM customer_types ORDER BY name ASC");
    $customer_types = $types_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch tiers for each type
    foreach ($customer_types as &$type) {
        $tiers_stmt = $db->prepare("
            SELECT tier_number, min_amount, max_amount, discount_percentage 
            FROM customer_type_discount_tiers 
            WHERE customer_type_id = ? 
            ORDER BY tier_number ASC
        ");
        $tiers_stmt->execute([$type['id']]);
        $type['tiers'] = $tiers_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $customer_types = [];
    $error_message = "خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<style>
    :root {
        --primary-color: #4f46e5;
        --primary-hover: #4338ca;
        --danger-color: #ef4444;
        --danger-hover: #dc2626;
        --background-color: #f9fafb;
        --card-background: #ffffff;
        --border-color: #e5e7eb;
        --text-color-dark: #1f2937;
        --text-color-light: #6b7280;
        --font-family: 'Cairo', sans-serif;
    }
    body { font-family: var(--font-family); background-color: var(--background-color); }
    .card { background: var(--card-background); border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border-color); margin-bottom: 1.5rem; overflow: hidden; }
    .card-header { padding: 1rem; border-bottom: 1px solid var(--border-color); }
    .card-body { padding: 1rem; }
    .btn { padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; display: inline-flex; align-items: center; gap: 0.5rem; }
    .btn-primary { background: var(--primary-color); color: white; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-danger { background: var(--danger-color); color: white; }
    .btn-danger:hover { background: var(--danger-hover); }
    .btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; }
    .form-group { margin-bottom: 1rem; }
    .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--text-color-dark); font-size: 0.875rem; }
    .form-control { width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 0.5rem; font-size: 1rem; }
    .tier-section { background: #f3f4f6; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
    .tier-grid { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }
    .tier-badge { display: inline-block; padding: 0.25rem 0.75rem; background: var(--primary-color); color: white; border-radius: 0.25rem; font-size: 0.875rem; margin-bottom: 0.5rem; font-weight: 600; }
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); overflow-y: auto; }
    .modal-content { background: white; margin: 1rem; padding: 1.5rem; border-radius: 1rem; max-width: 800px; max-height: calc(100vh - 2rem); overflow-y: auto; }
    
    /* Responsive table */
    .table-container { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 0.75rem; text-align: right; border-bottom: 1px solid var(--border-color); font-size: 0.875rem; }
    th { background: #f9fafb; font-weight: 600; white-space: nowrap; }
    td { word-wrap: break-word; }
    
    /* Mobile responsive */
    @media (min-width: 640px) {
        .card-header { padding: 1.5rem; }
        .card-body { padding: 1.5rem; }
        .tier-grid { grid-template-columns: 1fr 1fr; }
        th, td { padding: 1rem; font-size: 1rem; }
    }
    
    @media (min-width: 768px) {
        .tier-grid { grid-template-columns: 1fr 1fr 1fr; }
        .modal-content { margin: 2rem auto; padding: 2rem; }
    }
    
    @media (max-width: 639px) {
        .hide-mobile { display: none; }
        .btn { padding: 0.5rem 1rem; font-size: 0.875rem; }
        .card-header h1 { font-size: 1.25rem; }
        table { min-width: 100%; }
        th, td { padding: 0.5rem; font-size: 0.75rem; }
        .tier-info { font-size: 0.7rem; line-height: 1.4; }
    }
</style>

<div class="container" style="padding: 1rem; max-width: 1400px; margin: 0 auto;" dir="rtl">
    <div class="card">
        <div class="card-header" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
            <h1 style="margin: 0; font-size: 1.5rem; color: var(--text-color-dark); flex: 1; min-width: 200px;">
                <i class="fas fa-users-cog"></i> <?php echo $page_title; ?>
            </h1>
<?php if ($can_add): ?>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> <span class="hide-mobile">إضافة نوع جديد</span><span class="sm:hidden">إضافة</span>
            </button>
            <?php endif; ?>
        </div>
        
        <div class="card-body">
            <?php if ($error_message): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
                <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم النوع</th>
                            <th>نسبة الخصم</th>
                            <th class="hide-mobile">تاريخ الإنشاء</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($customer_types)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-color-light);">
                                لا توجد أنواع مسجلة
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customer_types as $type): ?>
                            <?php $is_active = array_key_exists('is_active', $type) ? (int)$type['is_active'] : 1; ?>
                            <tr>
                                <td><?php echo $type['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($type['name']); ?></strong></td>
                                <td>
                                    <?php if (!empty($type['tiers'])): ?>
                                        <?php foreach ($type['tiers'] as $tier): ?>
                                            <div class="tier-info" style="margin-bottom: 0.25rem;">
                                                <span class="tier-badge">المستوى <?php echo $tier['tier_number']; ?></span>
                                                <span style="white-space: nowrap;">
                                                    <?php echo number_format($tier['min_amount'], 0); ?> - 
                                                    <?php echo $tier['max_amount'] ? number_format($tier['max_amount'], 0) : '∞'; ?> ر.ي
                                                    = <strong><?php echo $tier['discount_percentage']; ?>%</strong>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-color-light); font-size: 0.875rem;">لا توجد مستويات</span>
                                    <?php endif; ?>
                                </td>
                                <td class="hide-mobile"><?php echo date('Y-m-d', strtotime($type['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                                        <?php if ($can_edit): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $is_active ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_active" class="btn btn-sm" style="background: <?php echo $is_active ? '#22c55e' : '#9ca3af'; ?>; color: #ffffff;" title="<?php echo $is_active ? 'تعطيل النوع' : 'تفعيل النوع'; ?>">
                                                <i class="fas <?php echo $is_active ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                                            </button>
                                        </form>
                                        <button class="btn btn-primary btn-sm" onclick='openEditModal(<?php echo json_encode($type); ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من الحذف؟');">
                                            <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
                                            <button type="submit" name="delete_type" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span style="color: #9ca3af; font-size: 0.875rem;">عرض فقط</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-bottom: 1.5rem;">إضافة نوع جديد</h2>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">اسم النوع</label>
                <input type="text" name="name" class="form-control" required placeholder="مثال: عميل، تاجر، موزع">
            </div>
            
            <h3 style="margin: 1.5rem 0 1rem;">مستويات الخصم</h3>
            
            <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="tier-section">
                    <div class="tier-badge">المستوى <?php echo $i; ?></div>
                    <div class="tier-grid">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">من (ر.ي)</label>
                            <input type="number" step="0.01" name="tier<?php echo $i; ?>_min" class="form-control" value="<?php echo $i == 1 ? '0' : ''; ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">إلى (ر.ي) <small>(اتركه فارغاً = غير محدود)</small></label>
                            <input type="number" step="0.01" name="tier<?php echo $i; ?>_max" class="form-control" placeholder="غير محدود">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">نسبة الخصم (%)</label>
                            <input type="number" step="0.01" name="tier<?php echo $i; ?>_discount" class="form-control" value="0" required min="0" max="100">
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
            
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" name="add_type" class="btn btn-primary">حفظ النوع</button>
                <button type="button" class="btn" style="background: #e5e7eb;" onclick="closeAddModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-bottom: 1.5rem;">تعديل النوع</h2>
        <form method="POST" id="editForm">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="form-group">
                <label class="form-label">اسم النوع</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            
            <h3 style="margin: 1.5rem 0 1rem;">مستويات الخصم</h3>
            
            <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="tier-section">
                    <div class="tier-badge">المستوى <?php echo $i; ?></div>
                    <div class="tier-grid">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">من (ر.ي)</label>
                            <input type="number" step="0.01" name="tier<?php echo $i; ?>_min" id="edit_tier<?php echo $i; ?>_min" class="form-control" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">إلى (ر.ي) <small>(اتركه فارغاً = غير محدود)</small></label>
                            <input type="number" step="0.01" name="tier<?php echo $i; ?>_max" id="edit_tier<?php echo $i; ?>_max" class="form-control" placeholder="غير محدود">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">نسبة الخصم (%)</label>
                            <input type="number" step="0.01" name="tier<?php echo $i; ?>_discount" id="edit_tier<?php echo $i; ?>_discount" class="form-control" required min="0" max="100">
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
            
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" name="edit_type" class="btn btn-primary">حفظ التعديلات</button>
                <button type="button" class="btn" style="background: #e5e7eb;" onclick="closeEditModal()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
}

function openEditModal(type) {
    document.getElementById('edit_id').value = type.id;
    document.getElementById('edit_name').value = type.name;
    
    // Populate tiers
    for (let i = 1; i <= 3; i++) {
        const tier = type.tiers.find(t => t.tier_number == i);
        if (tier) {
            document.getElementById(`edit_tier${i}_min`).value = tier.min_amount;
            document.getElementById(`edit_tier${i}_max`).value = tier.max_amount || '';
            document.getElementById(`edit_tier${i}_discount`).value = tier.discount_percentage;
        } else {
            document.getElementById(`edit_tier${i}_min`).value = '';
            document.getElementById(`edit_tier${i}_max`).value = '';
            document.getElementById(`edit_tier${i}_discount`).value = '0';
        }
    }
    
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
