<?php
/**
 * Product Slides Management
 * Access controlled by permissions system.
 * Recommended slide dimensions: 800x600 pixels (or suitable for product display)
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
$can_view = hasPermission($user_id, 'product_slides', 'view'); // Changed permission key
$can_add = hasPermission($user_id, 'product_slides', 'add');   // Changed permission key
$can_edit = hasPermission($user_id, 'product_slides', 'edit'); // Changed permission key

// If the user doesn't have view permission, redirect them.
if (!$can_view) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$page_title = 'إدارة سلايدر المنتجات'; // Changed page title
$error_message = '';
$success_message = '';

// Ensure product_slides table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS product_slides (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            image_path VARCHAR(500) NOT NULL,
            link_url VARCHAR(500) NULL,
            product_id INT NULL,           -- Added: Link to a product (optional)
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            -- FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL -- Optional: If you have a products table
        )
    ");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Ensure upload directory exists
$upload_dir = '../../uploads/product_slides/'; // Changed upload directory
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add new slide
    if (isset($_POST['action']) && $_POST['action'] === 'add_slide') {
        if (!$can_add) {
            $error_message = 'ليس لديك صلاحية لإضافة سلايد منتج جديد.';
        } elseif (isset($_FILES['slide_image']) && $_FILES['slide_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['slide_image'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($file['type'], $allowed_types)) {
                $error_message = 'نوع الملف غير مسموح. يرجى رفع صورة (JPG, PNG, GIF, WEBP)';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error_message = 'حجم الملف كبير جداً. الحد الأقصى 5 ميجابايت';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $new_filename = 'product_slide_' . time() . '_' . uniqid() . '.' . $ext; // Changed filename prefix
                $target_path = $upload_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $title = trim($_POST['title'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $link_url = trim($_POST['link_url'] ?? '');
                    $product_id = (int) ($_POST['product_id'] ?? 0); // Added product_id
                    $display_order = (int) ($_POST['display_order'] ?? 0);
                    $is_active = isset($_POST['is_active']) ? 1 : 0;

                    $stmt = $db->prepare("
                        INSERT INTO product_slides (title, description, image_path, link_url, product_id, display_order, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $title ?: null,
                        $description ?: null,
                        'uploads/product_slides/' . $new_filename, // Changed path for DB storage
                        $link_url ?: null,
                        $product_id > 0 ? $product_id : null, // Store null if product_id is 0
                        $display_order,
                        $is_active,
                        $user_id
                    ]);

                    $success_message = 'تم إضافة سلايد المنتج بنجاح'; // Changed success message
                } else {
                    $error_message = 'فشل في رفع الصورة';
                }
            }
        } else {
            $error_message = 'يرجى اختيار صورة لسلايد المنتج'; // Changed error message
        }
    }

    // Check for edit permissions for all other actions
    if (isset($_POST['action']) && in_array($_POST['action'], ['delete_slide', 'toggle_active', 'update_order'])) {
        if (!$can_edit) {
            $error_message = 'ليس لديك صلاحية لتعديل سلايدات المنتجات.';
        } else {
            // Delete slide
            if ($_POST['action'] === 'delete_slide') {
                $slide_id = (int) $_POST['slide_id'];

                // Get image path first
                $stmt = $db->prepare("SELECT image_path FROM product_slides WHERE id = ?");
                $stmt->execute([$slide_id]);
                $slide = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($slide) {
                    // Delete file
                    $file_path = '../../' . $slide['image_path'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }

                    // Delete record
                    $del_stmt = $db->prepare("DELETE FROM product_slides WHERE id = ?");
                    $del_stmt->execute([$slide_id]);

                    $success_message = 'تم حذف سلايد المنتج بنجاح'; // Changed success message
                }
            }

            // Toggle active status
            if ($_POST['action'] === 'toggle_active') {
                $slide_id = (int) $_POST['slide_id'];
                $db->prepare("UPDATE product_slides SET is_active = NOT is_active WHERE id = ?")->execute([$slide_id]);
                $success_message = 'تم تحديث حالة سلايد المنتج'; // Changed success message
            }

            // Update order
            if ($_POST['action'] === 'update_order') {
                $slide_id = (int) $_POST['slide_id'];
                $new_order = (int) $_POST['display_order'];
                $db->prepare("UPDATE product_slides SET display_order = ? WHERE id = ?")->execute([$new_order, $slide_id]);
                $success_message = 'تم تحديث ترتيب سلايد المنتج'; // Changed success message
            }
        }
    }
}

// Fetch all slides - STRICT ORDERING
$slides = $db->query("SELECT * FROM product_slides ORDER BY display_order ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Optional: Fetch list of products to link to (if you have a products table)
// Example:
// $products = $db->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);


include '../../includes/header.php';
?>

<style>
    /* Keep the styles largely the same, but you can adjust colors/fonts if desired for product context */
    .slides-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        background: linear-gradient(135deg, #20bf55 0%, #01baef 100%); /* Changed gradient color */
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
        color: #20bf55; /* Changed icon color */
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
        border-color: #20bf55; /* Changed focus border color */
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
        background: linear-gradient(135deg, #20bf55 0%, #01baef 100%); /* Changed gradient color */
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(32, 191, 85, 0.4); /* Changed shadow color */
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
        height: 200px; /* Adjust height for product images if needed */
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
        background: #e0f2fe; /* Changed background color */
        border: 1px solid #38bdf8; /* Changed border color */
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .dimensions-note i {
        color: #38bdf8; /* Changed icon color */
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
        <h1><i class="fas fa-cubes"></i> إدارة سلايدر المنتجات</h1> <!-- Changed icon -->
        <p>إضافة وإدارة الصور التي تظهر في سلايدر المنتجات</p> <!-- Changed description -->
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
                إضافة سلايد منتج جديد
            </div>

            <div class="dimensions-note">
                <i class="fas fa-ruler-combined"></i>
                <div>
                    <strong>الأبعاد الموصى بها:</strong> 800 × 600 بكسل (عرض × ارتفاع) (يمكن تعديلها حسب الحاجة)<br>
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
                        <input type="text" name="title" class="form-control" placeholder="عنوان سلايد المنتج">
                    </div>

                    <div class="form-group">
                        <label class="form-label">رابط (اختياري)</label>
                        <input type="url" name="link_url" class="form-control" placeholder="https://example.com/product/123">
                    </div>

                    <div class="form-group">
                        <label class="form-label">ربط بمنتج (اختياري)</label>
                        <input type="number" name="product_id" class="form-control" placeholder="معرف المنتج" min="0">
                        <!-- Or if you have a products table, you can use a select dropdown: -->
                        <!--
                        <select name="product_id" class="form-control">
                            <option value="0">-- اختر منتج --</option>
                            <?php // foreach ($products as $product): ?>
                                <option value="<?php // echo $product['id']; ?>"><?php // echo htmlspecialchars($product['name']); ?></option>
                            <?php // endforeach; ?>
                        </select>
                        -->
                    </div>

                    <div class="form-group">
                        <label class="form-label">ترتيب العرض</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">الوصف (اختياري)</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="وصف مختصر لسلايد المنتج"></textarea>
                </div>

                <div class="form-group">
                    <label class="checkbox-wrapper">
                        <input type="checkbox" name="is_active" checked>
                        <span>نشط (يظهر في سلايدر المنتجات)</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i>
                    رفع وإضافة سلايد المنتج
                </button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">
            <i class="fas fa-th-large"></i>
            سلايدات المنتجات الحالية (<?php echo count($slides); ?>)
        </div>

        <?php if (empty($slides)): ?>
            <div class="empty-state">
                <i class="fas fa-images"></i>
                <h3>لا توجد سلايدات للمنتجات</h3>
                <p>قم بإضافة أول سلايد منتج باستخدام النموذج أعلاه</p>
            </div>
        <?php else: ?>
            <div class="slides-grid">
                <?php foreach ($slides as $slide): ?>
                    <div class="slide-card">
                        <img src="../../<?php echo htmlspecialchars($slide['image_path']); ?>"
                            alt="<?php echo htmlspecialchars($slide['title'] ?? 'Product Slide'); ?>" class="slide-image">
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
                                <?php if ($slide['product_id']): ?>
                                    &nbsp;|&nbsp;
                                    المنتج: #<?php echo $slide['product_id']; ?>
                                <?php endif; ?>
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