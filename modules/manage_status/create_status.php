<?php
/**
 * Create Status Page
 * - This file handles CREATING new statuses only.
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

// --- 2. PERMISSIONS & INITIALIZATION ---
$user_id = $_SESSION['user_id'];
$table_name = $_GET['table'] ?? '';
$error_message = '';
$page_title = 'إضافة حالة جديدة';

$status = [ // For preserving form data on error
    'status_key' => '',
    'status_name_ar' => '',
    'is_default' => 0
];

$allowed_tables = ['customer_order_statuses', 'purchase_basket_statuses', 'purchase_group_statuses'];
if (!$table_name || !in_array($table_name, $allowed_tables)) {
     $_SESSION['error_message'] = 'جدول الحالات المحدد غير صالح.';
     header('Location: /index.php');
     exit();
}

if (!hasPermission($user_id, 'statuses', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لإضافة الحالات.';
    header('Location: /index.php');
    exit();
}

// --- 3. FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status['status_key'] = trim($_POST['status_key']);
    $status['status_name_ar'] = trim($_POST['status_name_ar']);
    $status['is_default'] = isset($_POST['is_default']) ? 1 : 0;

    if (empty($status['status_key']) || empty($status['status_name_ar'])) {
        $error_message = 'الرجاء ملء جميع الحقول المطلوبة.';
    } elseif (!preg_match('/^[a-z0-9_]+$/', $status['status_key'])) {
        $error_message = 'المعرّف (Key) يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطة سفلية (_) فقط.';
    } else {
        try {
            // Check for duplicates before inserting
            $stmt = $db->prepare("SELECT id FROM `{$table_name}` WHERE status_key = ?");
            $stmt->execute([$status['status_key']]);
            if ($stmt->fetch()) {
                $error_message = 'المعرّف (Key) مستخدم بالفعل. الرجاء اختيار معرّف آخر.';
            } else {
                // No duplicate found, proceed with insertion
                $stmt = $db->prepare("INSERT INTO `{$table_name}` (status_key, status_name_ar, is_default, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$status['status_key'], $status['status_name_ar'], $status['is_default']]);
                $new_status_id = $db->lastInsertId();

                if ($status['is_default'] == 1) {
                    $reset_stmt = $db->prepare("UPDATE `{$table_name}` SET is_default = 0 WHERE id != ?");
                    $reset_stmt->execute([$new_status_id]);
                }
                
                $_SESSION['success_message'] = 'تم إضافة الحالة بنجاح.';
                header('Location: manage_status.php');
                exit();
            }
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<!-- STYLES (Same as before) -->
<style>
    .form-wrapper { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 30px; margin: 20px auto; max-width: 700px; }
    .form-header { margin-bottom: 25px; text-align: center; color: #1f2937; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .checkbox-group { display: flex; align-items: center; gap: 10px; }
    .form-actions { display: flex; gap: 10px; margin-top: 30px; }
    .btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; color: white; display: inline-flex; align-items: center; gap: 8px; }
    .btn-primary { background-color: #3b82f6; } .btn-secondary { background-color: #6b7280; }
    .alert-danger { padding: 15px; border-radius: 8px; margin-bottom: 20px; background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
</style>

<div dir="rtl">
    <div class="form-wrapper">
        <div class="form-header"><h2><?php echo $page_title; ?></h2></div>

        <?php if (!empty($error_message)): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

        <form method="POST" action="create_status.php?table=<?php echo htmlspecialchars($table_name); ?>">
            <div class="form-group">
                <label for="status_key">المعرّف (Key)</label>
                <input type="text" id="status_key" name="status_key" class="form-control" value="<?php echo htmlspecialchars($status['status_key']); ?>" required placeholder="e.g., under_review">
                <small style="color: #6b7280;">أحرف إنجليزية صغيرة، أرقام، وشرطة سفلية (_) فقط.</small>
            </div>

            <div class="form-group">
                <label for="status_name_ar">الاسم بالعربي</label>
                <input type="text" id="status_name_ar" name="status_name_ar" class="form-control" value="<?php echo htmlspecialchars($status['status_name_ar']); ?>" required placeholder="e.g., قيد المراجعة">
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" id="is_default" name="is_default" value="1" <?php echo ($status['is_default'] == 1) ? 'checked' : ''; ?>>
                <label for="is_default" style="margin-bottom: 0;">تعيين كحالة افتراضية؟</label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
                <a href="manage_status.php" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>