<?php
/**
 * Portal Slides Management
 * Access controlled by permissions system.
 * Recommended slide dimensions: 1200x400 pixels
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
// Include the permissions check functions
require_once '../../includes/check_permissions.php';

// Check permissions for the page
$user_id = $_SESSION['user_id'];
$can_view = hasPermission($user_id, 'portal_slides', 'view');
$can_add = hasPermission($user_id, 'portal_slides', 'add');
$can_edit = hasPermission($user_id, 'portal_slides', 'edit');

// If the user doesn't have view permission, redirect them.
if (!$can_view) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$page_title = 'إدارة سلايدر البوابة';
$error_message = '';
$success_message = '';

// Ensure slides table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS portal_slides (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            image_path VARCHAR(500) NOT NULL,
            link_url VARCHAR(500) NULL,
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Ensure upload directory exists
$upload_dir = '../../uploads/portal_slides/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add new slide
    if (isset($_POST['action']) && $_POST['action'] === 'add_slide') {
        if (!$can_add) {
            $error_message = 'ليس لديك صلاحية لإضافة سلايد جديد.';
        } elseif (isset($_FILES['slide_image']) && $_FILES['slide_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['slide_image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($file['type'], $allowed_types)) {
                $error_message = 'نوع الملف غير مسموح. يرجى رفع صورة (JPG, PNG, GIF, WEBP)';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error_message = 'حجم الملف كبير جداً. الحد الأقصى 5 ميجابايت';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $new_filename = 'slide_' . time() . '_' . uniqid() . '.' . $ext;
                $target_path = $upload_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $title = trim($_POST['title'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $link_url = trim($_POST['link_url'] ?? '');
                    $display_order = (int) ($_POST['display_order'] ?? 0);
                    $is_active = isset($_POST['is_active']) ? 1 : 0;

                    $stmt = $db->prepare("
                        INSERT INTO portal_slides (title, description, image_path, link_url, display_order, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $title ?: null,
                        $description ?: null,
                        'uploads/portal_slides/' . $new_filename,
                        $link_url ?: null,
                        $display_order,
                        $is_active,
                        $user_id
                    ]);

                    $success_message = 'تم إضافة السلايد بنجاح';
                } else {
                    $error_message = 'فشل في رفع الصورة';
                }
            }
        } else {
            $error_message = 'يرجى اختيار صورة للسلايد';
        }
    }

    // Check for edit permissions for all other actions
    if (isset($_POST['action']) && in_array($_POST['action'], ['delete_slide', 'toggle_active', 'update_order'])) {
        if (!$can_edit) {
            $error_message = 'ليس لديك صلاحية لتعديل السلايدات.';
        } else {
            // Delete slide
            if ($_POST['action'] === 'delete_slide') {
                $slide_id = (int) $_POST['slide_id'];

                // Get image path first
                $stmt = $db->prepare("SELECT image_path FROM portal_slides WHERE id = ?");
                $stmt->execute([$slide_id]);
                $slide = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($slide) {
                    // Delete file
                    $file_path = '../../' . $slide['image_path'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }

                    // Delete record
                    $del_stmt = $db->prepare("DELETE FROM portal_slides WHERE id = ?");
                    $del_stmt->execute([$slide_id]);

                    $success_message = 'تم حذف السلايد بنجاح';
                }
            }

            // Toggle active status
            if ($_POST['action'] === 'toggle_active') {
                $slide_id = (int) $_POST['slide_id'];
                $db->prepare("UPDATE portal_slides SET is_active = NOT is_active WHERE id = ?")->execute([$slide_id]);
                $success_message = 'تم تحديث حالة السلايد';
            }

            // Update order
            if ($_POST['action'] === 'update_order') {
                $slide_id = (int) $_POST['slide_id'];
                $new_order = (int) $_POST['display_order'];
                $db->prepare("UPDATE portal_slides SET display_order = ? WHERE id = ?")->execute([$new_order, $slide_id]);
                $success_message = 'تم تحديث ترتيب السلايد';
            }
        }
    }
}

// Fetch all slides - STRICT ORDERING
$slides = $db->query("SELECT * FROM portal_slides ORDER BY display_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<style>
    .slides-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 16px;
        margin-bottom: 30px;
    }

    .page-header h1 {
        margin: 0 0 10px 0;
        font-size: 28px;
        font-weight: 700;
    }

    .page-header p {
        margin: 0;
        opacity: 0.9;
    }

    .card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        padding: 24px;
        margin-bottom: 24px;
    }

    .card-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #1f2937;
    }

    .card-title i {
        color: #667eea;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #374151;
    }

    .form-control {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-size: 15px;
        transition: border-color 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: #667eea;
    }

    .btn {
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        text-decoration: none;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    .btn-danger {
        background: #ef4444;
        color: white;
    }

    .btn-danger:hover {
        background: #dc2626;
    }

    .btn-sm {
        padding: 8px 16px;
        font-size: 13px;
    }

    .slides-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 24px;
    }

    .slide-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .slide-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }

    .slide-image {
        width: 100%;
        height: 200px;
        object-fit: cover;
        background: #f3f4f6;
    }

    .slide-info {
        padding: 20px;
    }

    .slide-title {
        font-size: 16px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 8px;
    }

    .slide-meta {
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 12px;
    }

    .slide-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .badge-success {
        background: #d1fae5;
        color: #065f46;
    }

    .badge-secondary {
        background: #e5e7eb;
        color: #4b5563;
    }

    .dimensions-note {
        background: #fef3c7;
        border: 1px solid #f59e0b;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .dimensions-note i {
        color: #f59e0b;
        font-size: 24px;
    }

    .alert {
        padding: 16px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }

    .alert-danger {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6b7280;
    }

    .empty-state i {
        font-size: 64px;
        margin-bottom: 20px;
        opacity: 0.5;
    }

    .order-input {
        width: 60px;
        padding: 6px 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        text-align: center;
    }

    .checkbox-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .checkbox-wrapper input[type="checkbox"] {
        width: 18px;
        height: 18px;
    }

    @media (max-width: 768px) {
        .slides-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="slides-container" dir="rtl">
    <div class="page-header">
        <h1><i class="fas fa-images"></i> إدارة سلايدر البوابة</h1>
        <p>إضافة وإدارة الصور التي تظهر في بوابة العميل</p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($can_add): ?>
        <div class="card">
            <div class="card-title">
                <i class="fas fa-plus-circle"></i>
                إضافة سلايد جديد
            </div>

            <div class="dimensions-note">
                <i class="fas fa-ruler-combined"></i>
                <div>
                    <strong>الأبعاد الموصى بها:</strong> 1200 × 400 بكسل (عرض × ارتفاع)<br>
                    <small>الحد الأقصى لحجم الملف: 5 ميجابايت | الصيغ المدعومة: JPG, PNG, GIF, WEBP</small>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_slide">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">صورة السلايد <span style="color: #ef4444;">*</span></label>
                        <input type="file" name="slide_image" class="form-control" accept="image/*" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">العنوان (اختياري)</label>
                        <input type="text" name="title" class="form-control" placeholder="عنوان السلايد">
                    </div>

                    <div class="form-group">
                        <label class="form-label">رابط (اختياري)</label>
                        <input type="url" name="link_url" class="form-control" placeholder="https://example.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label">ترتيب العرض</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">الوصف (اختياري)</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="وصف مختصر للسلايد"></textarea>
                </div>

                <div class="form-group">
                    <label class="checkbox-wrapper">
                        <input type="checkbox" name="is_active" checked>
                        <span>نشط (يظهر في البوابة)</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i>
                    رفع وإضافة السلايد
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">
            <i class="fas fa-th-large"></i>
            السلايدات الحالية (<?php echo count($slides); ?>)
        </div>

        <?php if (empty($slides)): ?>
            <div class="empty-state">
                <i class="fas fa-images"></i>
                <h3>لا توجد سلايدات</h3>
                <p>قم بإضافة أول سلايد باستخدام النموذج أعلاه</p>
            </div>
        <?php else: ?>
            <div class="slides-grid">
                <?php foreach ($slides as $slide): ?>
                    <div class="slide-card">
                        <img src="../../<?php echo htmlspecialchars($slide['image_path']); ?>"
                            alt="<?php echo htmlspecialchars($slide['title'] ?? 'Slide'); ?>" class="slide-image">
                        <div class="slide-info">
                            <div class="slide-title">
                                <?php echo htmlspecialchars($slide['title'] ?: 'بدون عنوان'); ?>
                            </div>
                            <div class="slide-meta">
                                <span class="badge <?php echo $slide['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                    <?php echo $slide['is_active'] ? 'نشط' : 'غير نشط'; ?>
                                </span>
                                &nbsp;|&nbsp;
                                الترتيب: <?php echo $slide['display_order']; ?>
                                &nbsp;|&nbsp;
                                <?php echo date('Y-m-d', strtotime($slide['created_at'])); ?>
                            </div>

                            <?php if ($can_edit): ?>
                                <div class="slide-actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="slide_id" value="<?php echo $slide['id']; ?>">
                                        <button type="submit"
                                            class="btn btn-sm <?php echo $slide['is_active'] ? 'btn-secondary' : 'btn-primary'; ?>">
                                            <i class="fas <?php echo $slide['is_active'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                            <?php echo $slide['is_active'] ? 'إخفاء' : 'إظهار'; ?>
                                        </button>
                                    </form>

                                    <form method="POST" style="display: inline-flex; align-items: center; gap: 4px;">
                                        <input type="hidden" name="action" value="update_order">
                                        <input type="hidden" name="slide_id" value="<?php echo $slide['id']; ?>">
                                        <input type="number" name="display_order" value="<?php echo $slide['display_order']; ?>"
                                            class="order-input" min="0">
                                        <button type="submit" class="btn btn-sm btn-primary" title="تحديث الترتيب">
                                            <i class="fas fa-sort"></i>
                                        </button>
                                    </form>

                                    <form method="POST" style="display: inline;"
                                        onsubmit="return confirm('هل أنت متأكد من حذف هذا السلايد؟');">
                                        <input type="hidden" name="action" value="delete_slide">
                                        <input type="hidden" name="slide_id" value="<?php echo $slide['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i>
                                            حذف
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>