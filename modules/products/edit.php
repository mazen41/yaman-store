<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_edit = false;
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_edit = hasPermission($_SESSION['user_id'], 'products', 'edit');
}

$page_title = 'تعديل منتج';
$error_message = '';
$success_message = '';
$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    header('Location: index.php');
    exit();
}

$product = null;
$product_images = []; 
$product_variants = []; 
$selected_colors = []; 
$selected_attribute_values = []; 

$upload_dir = __DIR__ . '/../../uploads/products/';
$base_image_url = '/uploads/products/'; 

if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true); 

$categories = []; // Will hold all categories for JS
$attributes = [];
$attribute_values_by_attr = [];
$colors = [];

if (!$can_edit) {
    $error_message = 'ليس لديك صلاحية لتعديل المنتجات.';
} else {
    try {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $error_message = 'المنتج غير موجود.';
        } else {
            $stmt_img = $db->prepare("SELECT id, image_url, is_main FROM product_images WHERE product_id = ? ORDER BY display_order");
            $stmt_img->execute([$product_id]);
            $product_images = $stmt_img->fetchAll(PDO::FETCH_ASSOC);

            $stmt_variants = $db->prepare("SELECT pv.*, c.name AS color_name, c.hex_code, av.value AS attr_value_name, a.name AS attr_name
                                            FROM product_variants pv
                                            LEFT JOIN colors c ON pv.color_id = c.id
                                            LEFT JOIN attribute_values av ON pv.attribute_value_id = av.id
                                            LEFT JOIN attributes a ON av.attribute_id = a.id
                                            WHERE pv.product_id = ? AND pv.is_active = 1");
            $stmt_variants->execute([$product_id]);
            $product_variants = $stmt_variants->fetchAll(PDO::FETCH_ASSOC);

            foreach ($product_variants as $variant) {
                if ($variant['color_id'] && !in_array($variant['color_id'], $selected_colors)) $selected_colors[] = $variant['color_id'];
                if ($variant['attribute_value_id'] && !in_array($variant['attribute_value_id'], $selected_attribute_values)) $selected_attribute_values[] = $variant['attribute_value_id'];
            }
        }

        // Fetch ALL categories for JavaScript to filter
        $categories_stmt = $db->query("SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY parent_id ASC, name ASC");
        $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

        $attributes_stmt = $db->query("SELECT id, name FROM attributes WHERE is_active = 1 ORDER BY display_order, name");
        $attributes = $attributes_stmt->fetchAll(PDO::FETCH_ASSOC);

        $all_attribute_values_stmt = $db->query("SELECT av.id, av.attribute_id, av.value FROM attribute_values av JOIN attributes a ON av.attribute_id = a.id WHERE av.is_active = 1 AND a.is_active = 1 ORDER BY a.display_order, av.display_order");
        foreach ($all_attribute_values_stmt->fetchAll(PDO::FETCH_ASSOC) as $val) {
            $attribute_values_by_attr[$val['attribute_id']][] = $val;
        }

        $colors_stmt = $db->query("SELECT id, name, hex_code FROM colors WHERE is_active = 1 ORDER BY display_order, name");
        $colors = $colors_stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error_message = "خطأ في تحميل البيانات: " . $e->getMessage();
    }
}

/**
 * Helper function to build category options hierarchically.
 * This is used for the main category dropdown.
 */
function buildCategoryOptions($categories, $selected_id = null, $parent_id = null, $indent = '') {
    $html = '';
    foreach ($categories as $category) {
        // Only include top-level categories or children of the current parent_id
        if (($parent_id === null && $category['parent_id'] === null) || ($parent_id !== null && $category['parent_id'] == $parent_id)) {
            $html .= '<option value="' . htmlspecialchars($category['id']) . '"' . ($selected_id == $category['id'] ? ' selected' : '') . '>' . $indent . htmlspecialchars($category['name']) . '</option>';
            // Recursively add children
            $html .= buildCategoryOptions($categories, $selected_id, $category['id'], $indent . '--- ');
        }
    }
    return $html;
}


$initial_product_images_db = [];
if ($product) {
    foreach ($product_images as $img) $initial_product_images_db[$img['id']] = $img['image_url'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $product) {
    $name = trim($_POST['name']);
    $sku = trim($_POST['sku']);
    $unit = trim($_POST['unit']);
    $tagline = trim($_POST['tagline'] ?? '');
    $main_category_id = $_POST['main_category_id'];
    $subcategory_id = $_POST['subcategory_id'] ?? null;
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'];
    $discount_price = $_POST['discount_price'] ?? null;
    $display_order = $_POST['display_order'] ?? 0;
    
    // New fields collected from POST
    $purchase_amount = $_POST['purchase_amount'] ?? 0;
    $product_quantity = $_POST['product_quantity'] ?? 0;
    $product_type = $_POST['product_type'] ?? 'yaman';
    $mandub_name = ($product_type === 'mandub') ? trim($_POST['mandub_name'] ?? '') : null;
    $manager_notes = trim($_POST['manager_notes'] ?? '');

    $main_image_val = $_POST['main_image'] ?? null; 
    $images_to_delete = json_decode($_POST['images_to_delete'] ?? '[]', true) ?: [];
    $variants_data = $_POST['variants'] ?? [];

    if (empty($name) || empty($unit) || empty($main_category_id) || empty($price) || empty($purchase_amount) || empty($product_quantity)) {
        $error_message = 'الرجاء ملء الحقول الأساسية المطلوبة للمنتج.';
    } elseif ($product_type === 'mandub' && empty($mandub_name)) {
        $error_message = 'الرجاء إدخال اسم المندوب لنوع المنتج "مندوب".';
    } else {
        $db->beginTransaction();
        try {
            // Update product data
            $stmt = $db->prepare("UPDATE products SET name = ?, sku = ?, unit = ?, tagline = ?, category_id = ?, subcategory_id = ?, description = ?, price = ?, discount_price = ?, display_order = ?, purchase_amount = ?, product_quantity = ?, product_type = ?, mandub_name = ?, manager_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([
                $name, $sku, $unit, $tagline, $main_category_id,
                $subcategory_id === '' ? null : $subcategory_id, // Ensure null if empty string
                $description, $price, $discount_price === '' ? null : $discount_price,
                $display_order, $purchase_amount, $product_quantity, $product_type, $mandub_name, $manager_notes, $product_id
            ]);

            $newly_uploaded_image_paths = []; 
            $newly_uploaded_image_urls = [];   
            $file_upload_errors = [];

            // Process NEW image uploads
            if (isset($_FILES['new_product_gallery_files'])) {
                $files = $_FILES['new_product_gallery_files'];
                for ($idx = 0; $idx < count($files['name']); $idx++) {
                    if ($files['error'][$idx] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($files['name'][$idx], PATHINFO_EXTENSION));
                        $unique_filename = uniqid('prod_') . '.' . $ext;
                        $destination_path = $upload_dir . $unique_filename;

                        if (move_uploaded_file($files['tmp_name'][$idx], $destination_path)) {
                            $newly_uploaded_image_paths[] = $destination_path;
                            $newly_uploaded_image_urls["new_".$idx] = $base_image_url . $unique_filename; 
                        } else {
                            $file_upload_errors[] = "فشل الرفع.";
                        }
                    }
                }
            }
            
            if (!empty($file_upload_errors)) throw new Exception(implode('<br>', $file_upload_errors));

            $final_images_for_db = [];
            $current_db_order = 0;
            $main_image_set = false;

            // Existing images not deleted
            foreach ($initial_product_images_db as $img_id => $img_url) {
                if (!in_array($img_id, $images_to_delete)) {
                    $is_main = ($main_image_val == $img_id) ? 1 : 0;
                    if ($is_main) $main_image_set = true;
                    $final_images_for_db[] = ['id' => $img_id, 'url' => $img_url, 'is_main' => $is_main, 'display_order' => $current_db_order++];
                } else {
                    $file_to_delete = __DIR__ . '/../..' . $img_url;
                    if (is_file($file_to_delete)) unlink($file_to_delete);
                }
            }

            // New images
            foreach ($newly_uploaded_image_urls as $js_idx_key => $new_url) {
                $is_main = ($main_image_val == $js_idx_key) ? 1 : 0;
                if ($is_main) $main_image_set = true;
                $final_images_for_db[] = ['id' => null, 'url' => $new_url, 'is_main' => $is_main, 'display_order' => $current_db_order++];
            }

            if (!$main_image_set && !empty($final_images_for_db)) $final_images_for_db[0]['is_main'] = 1;

            $db->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$product_id]);
            if (!empty($final_images_for_db)) {
                $stmt_insert_img = $db->prepare("INSERT INTO product_images (product_id, image_url, is_main, display_order) VALUES (?, ?, ?, ?)");
                foreach ($final_images_for_db as $img) {
                    $stmt_insert_img->execute([$product_id, $img['url'], $img['is_main'], $img['display_order']]);
                }
            }

            // Variants
            $total_product_quantity = 0;
            $existing_variant_ids = array_column($product_variants, 'id');
            $stmt_insert_variant = $db->prepare("INSERT INTO product_variants (product_id, color_id, attribute_value_id, price, quantity) VALUES (?, ?, ?, ?, ?)");
            $stmt_update_variant = $db->prepare("UPDATE product_variants SET price = ?, quantity = ?, is_active = 1 WHERE id = ?");
            $variants_to_keep_ids = [];

            foreach ($variants_data as $variant_key => $variant_val) {
                $parts = explode('_', $variant_key);
                $v_color_id = $parts[0] === 'null' ? null : (int)$parts[0];
                $v_attr_value_id = $parts[1] === 'null' ? null : (int)$parts[1];

                $v_price = $variant_val['price'] ?? $price;
                $v_quantity = $variant_val['quantity'] ?? 0;
                $v_id = $variant_val['id'] ?? null;

                if ($v_id && in_array($v_id, $existing_variant_ids)) {
                    $stmt_update_variant->execute([$v_price, $v_quantity, $v_id]);
                    $variants_to_keep_ids[] = $v_id;
                } else if ($v_quantity > 0) {
                    $stmt_insert_variant->execute([$product_id, $v_color_id, $v_attr_value_id, $v_price, $v_quantity]);
                    $variants_to_keep_ids[] = $db->lastInsertId();
                }
                $total_product_quantity += $v_quantity;
            }

            $variants_to_deactivate = array_diff($existing_variant_ids, $variants_to_keep_ids);
            if (!empty($variants_to_deactivate)) {
                $placeholders = implode(',', array_fill(0, count($variants_to_deactivate), '?'));
                $db->prepare("UPDATE product_variants SET is_active = 0, quantity = 0 WHERE id IN ({$placeholders})")->execute($variants_to_deactivate);
            }

            $db->prepare("UPDATE products SET total_quantity = ? WHERE id = ?")->execute([$total_product_quantity, $product_id]);

            $db->commit();
            header("Location: edit.php?id=" . $product_id . "&success_message=" . urlencode('تم تحديث المنتج بنجاح!'));
            exit();

        } catch (Exception $e) {
            $db->rollBack();
            foreach ($newly_uploaded_image_paths as $path) if (is_file($path)) unlink($path);
            $error_message = 'خطأ: ' . $e->getMessage();
        }
    }
}

$name = $product['name'] ?? '';
$sku = $product['sku'] ?? '';
$unit = $product['unit'] ?? 'قطعة';
$tagline = $product['tagline'] ?? '';
$main_category_id = $product['category_id'] ?? '';
$subcategory_id = $product['subcategory_id'] ?? '';
$description = $product['description'] ?? '';
$price = $product['price'] ?? '';
$discount_price = $product['discount_price'] ?? '';
$display_order = $product['display_order'] ?? 0;

$purchase_amount = $product['purchase_amount'] ?? '';
$product_quantity = $product['product_quantity'] ?? '';
$product_type = $product['product_type'] ?? 'yaman';
$mandub_name = $product['mandub_name'] ?? '';
$manager_notes = $product['manager_notes'] ?? '';

$js_product_images = [];
foreach ($product_images as $img) {
    $js_product_images[] = ['id' => $img['id'], 'url' => $img['image_url'], 'is_main' => $img['is_main']];
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">تعديل المنتج</h1>
                <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-300 rounded-lg hover:bg-gray-400">
                    <i class="fas fa-arrow-right ml-2"></i> العودة
                </a>
            </div>

            <div class="p-6">
                <?php if (isset($_GET['success_message'])): ?>
                    <div class="bg-green-100 text-green-700 px-4 py-3 rounded-lg mb-4"><?php echo htmlspecialchars($_GET['success_message']); ?></div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="bg-red-100 text-red-700 px-4 py-3 rounded-lg mb-4"><?php echo $error_message; ?></div>
                <?php endif; ?>

                <?php if ($can_edit && $product): ?>
                    <form id="productForm" action="edit.php?id=<?php echo $product_id; ?>" method="POST" enctype="multipart/form-data" class="space-y-8">
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border">
                            <h2 class="text-xl font-semibold mb-4">معلومات المنتج الأساسية</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div><label>اسم المنتج *</label><input type="text" name="name" required class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($name); ?>"></div>
                                <div><label>SKU</label><input type="text" name="sku" class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($sku); ?>"></div>
                                <div><label>الوحدة *</label><input type="text" name="unit" required class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($unit); ?>"></div>
                                <div><label>تاج تشويقي</label><input type="text" name="tagline" class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($tagline); ?>"></div>
                                <div>
                                    <label>الفئة الرئيسية *</label>
                                    <select name="main_category_id" id="main_category_id" required class="w-full px-3 py-2 border rounded">
                                        <?php echo buildCategoryOptions($categories, $main_category_id); ?>
                                    </select>
                                </div>
                                <div>
                                    <label>الفئة الفرعية</label>
                                    <select name="subcategory_id" id="subcategory_id" class="w-full px-3 py-2 border rounded">
                                        <option value="">لا يوجد</option>
                                        <!-- Options will be populated by JavaScript -->
                                    </select>
                                </div>
                                <div class="col-span-1 md:col-span-2"><label>الوصف</label><textarea name="description" rows="3" class="w-full px-3 py-2 border rounded"><?php echo htmlspecialchars($description); ?></textarea></div>
                                <div><label>السعر *</label><input type="number" name="price" id="price" step="0.01" required class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($price); ?>"></div>
                                <div><label>السعر بعد الخصم</label><input type="number" name="discount_price" step="0.01" class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($discount_price); ?>"></div>
                                <div><label>ترتيب العرض</label><input type="number" name="display_order" class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($display_order); ?>"></div>
                                
                                <!-- Missing Fields Added Here -->
                                <div><label>مبلغ الشراء *</label><input type="number" name="purchase_amount" step="0.01" required class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($purchase_amount); ?>"></div>
                                <div><label>كمية المنتج *</label><input type="number" name="product_quantity" required class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($product_quantity); ?>"></div>
                                <div class="col-span-1 md:col-span-2">
                                    <label class="block text-sm mb-1">نوع المنتج *</label>
                                    <div class="flex items-center space-x-4 space-x-reverse">
                                        <label><input type="radio" name="product_type" value="yaman" <?php echo ($product_type === 'yaman') ? 'checked' : ''; ?>> منتج يمان</label>
                                        <label><input type="radio" name="product_type" value="mandub" <?php echo ($product_type === 'mandub') ? 'checked' : ''; ?>> منتج مندوب</label>
                                    </div>
                                </div>
                                <div id="mandub_name_container" class="col-span-1 md:col-span-2 <?php echo ($product_type === 'mandub') ? '' : 'hidden'; ?>">
                                    <label>اسم المندوب *</label><input type="text" name="mandub_name" id="mandub_name" class="w-full px-3 py-2 border rounded" value="<?php echo htmlspecialchars($mandub_name); ?>">
                                </div>
                                <div class="col-span-1 md:col-span-2"><label>ملاحظات المدير</label><textarea name="manager_notes" rows="2" class="w-full px-3 py-2 border rounded"><?php echo htmlspecialchars($manager_notes); ?></textarea></div>
                            </div>
                        </div>

                        <!-- Gallery -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">معرض صور المنتج</h2>
                            
                            <!-- Existing Images -->
                            <div id="image-gallery" class="space-y-4 mb-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($js_product_images as $img): ?>
                                    <div class="flex items-center p-3 bg-white border border-gray-200 rounded-lg shadow-sm image-item existing-item" data-id="<?php echo $img['id']; ?>">
                                        <img src="<?php echo htmlspecialchars($img['url']); ?>" class="h-16 w-16 object-cover rounded-md ml-3 border border-gray-300">
                                        <div class="flex-grow flex flex-col justify-center">
                                            <label class="flex items-center space-x-2 space-x-reverse text-sm cursor-pointer">
                                                <input type="radio" name="main_image" value="<?php echo $img['id']; ?>" class="form-radio main-image-radio" <?php echo $img['is_main'] ? 'checked' : ''; ?>>
                                                <span>صورة رئيسية</span>
                                            </label>
                                        </div>
                                        <button type="button" class="text-red-500 hover:text-red-700 remove-btn mr-2" data-id="<?php echo $img['id']; ?>"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <input type="hidden" name="images_to_delete" id="images_to_delete_input" value="[]">

                            <div class="mt-4">
                                <input type="file" id="multi-file-input" multiple accept="image/*" class="hidden">
                                <input type="file" name="new_product_gallery_files[]" id="final-files" multiple class="hidden">
                                <button type="button" id="trigger-file-input" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-images ml-2"></i> إضافة صور جديدة
                                </button>
                            </div>
                        </div>

                        <!-- Variants Options -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border">
                            <h2 class="text-xl font-semibold mb-4">خيارات المنتج</h2>
                            <div class="mb-6">
                                <label class="block mb-2">الألوان:</label>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <?php foreach ($colors as $color): ?>
                                        <label class="flex items-center">
                                            <input type="checkbox" name="colors[]" value="<?php echo $color['id']; ?>" class="color-checkbox form-checkbox ml-2" <?php if (in_array($color['id'], $selected_colors)) echo 'checked'; ?>>
                                            <span class="w-4 h-4 rounded-full ml-1 border" style="background-color: <?php echo $color['hex_code']; ?>"></span> <?php echo $color['name']; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php foreach ($attributes as $attr): ?>
                                <div class="mb-6">
                                    <label class="block mb-2"><?php echo $attr['name']; ?>:</label>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                        <?php if (isset($attribute_values_by_attr[$attr['id']])) foreach ($attribute_values_by_attr[$attr['id']] as $val): ?>
                                            <label class="flex items-center">
                                                <input type="checkbox" name="attribute_values[<?php echo $attr['id']; ?>][]" value="<?php echo $val['id']; ?>" data-attribute-id="<?php echo $attr['id']; ?>" class="attribute-value-checkbox form-checkbox ml-2" <?php if (in_array($val['id'], $selected_attribute_values)) echo 'checked'; ?>>
                                                <?php echo $val['value']; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button type="button" id="generate-variants-btn" class="px-6 py-2 bg-indigo-600 text-white rounded-lg">تحديث الجدول</button>
                        </div>

                        <!-- Variants Table -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border">
                            <table class="min-w-full" id="variants-table">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2">اللون</th><th class="px-4 py-2">المقاس</th>
                                        <th class="px-4 py-2">السعر</th><th class="px-4 py-2">الكمية</th>
                                    </tr>
                                </thead>
                                <tbody><tr id="no-variants-row"><td colspan="4" class="text-center py-4 text-gray-500">اختر لتوليد</td></tr></tbody>
                            </table>
                        </div>

                        <div class="flex justify-end"><button type="submit" class="px-6 py-3 bg-green-600 text-white rounded-lg"><i class="fas fa-save ml-2"></i> حفظ التعديلات</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const dt = new DataTransfer();
        const imageGallery = document.getElementById('image-gallery');
        const multiFileInput = document.getElementById('multi-file-input');
        const finalFiles = document.getElementById('final-files');
        const triggerBtn = document.getElementById('trigger-file-input');
        const imagesToDeleteInput = document.getElementById('images_to_delete_input');
        let imagesToDelete = [];

        triggerBtn.addEventListener('click', () => multiFileInput.click());

        multiFileInput.addEventListener('change', function() {
            for(let i = 0; i < this.files.length; i++) dt.items.add(this.files[i]);
            updateGalleryUI();
            this.value = ''; 
        });

        function updateGalleryUI() {
            finalFiles.files = dt.files;
            document.querySelectorAll('.new-image-item').forEach(el => el.remove());

            let hasMain = document.querySelector('.main-image-radio:checked') !== null;

            for(let i = 0; i < dt.files.length; i++) {
                let file = dt.files[i];
                let item = document.createElement('div');
                item.className = 'flex items-center p-3 bg-white border border-gray-200 rounded-lg shadow-sm image-item new-image-item';
                
                let img = document.createElement('img');
                img.className = 'h-16 w-16 object-cover rounded-md ml-3 border border-gray-300';
                img.src = URL.createObjectURL(file);
                
                let controlsContainer = document.createElement('div');
                controlsContainer.className = 'flex-grow flex flex-col justify-center';

                let nameSpan = document.createElement('span');
                nameSpan.className = 'text-xs text-gray-500 mb-1 truncate w-32';
                nameSpan.textContent = file.name;

                let radioLabel = document.createElement('label');
                radioLabel.className = 'flex items-center space-x-2 space-x-reverse text-sm cursor-pointer';
                
                let radio = document.createElement('input');
                radio.type = 'radio'; radio.name = 'main_image'; radio.value = 'new_' + i; 
                radio.className = 'form-radio main-image-radio';
                
                if (!hasMain && i === 0 && document.querySelectorAll('.existing-item:not(.hidden)').length === 0) {
                    radio.checked = true; hasMain = true;
                }

                radioLabel.appendChild(radio); radioLabel.appendChild(document.createTextNode('صورة رئيسية'));
                controlsContainer.appendChild(nameSpan); controlsContainer.appendChild(radioLabel);

                let removeBtn = document.createElement('button');
                removeBtn.type = 'button'; removeBtn.className = 'text-red-500 hover:text-red-700 ml-2 remove-btn';
                removeBtn.innerHTML = '<i class="fas fa-trash-alt"></i>';
                removeBtn.onclick = function() { dt.items.remove(i); updateGalleryUI(); };
                
                item.appendChild(img); item.appendChild(controlsContainer); item.appendChild(removeBtn);
                imageGallery.appendChild(item);
            }
            checkMainRadio();
        }

        imageGallery.addEventListener('click', function(e) {
            const btn = e.target.closest('.remove-btn');
            if (btn && btn.closest('.existing-item')) {
                const id = btn.dataset.id;
                imagesToDelete.push(id);
                imagesToDeleteInput.value = JSON.stringify(imagesToDelete);
                btn.closest('.existing-item').classList.add('hidden');
                btn.closest('.existing-item').querySelector('.main-image-radio').checked = false;
                checkMainRadio();
            }
        });

        function checkMainRadio() {
            const allRadios = document.querySelectorAll('.image-item:not(.hidden) .main-image-radio');
            if (!document.querySelector('.main-image-radio:checked') && allRadios.length > 0) {
                allRadios[0].checked = true;
            }
        }

        // Product Type Toggle
        document.querySelectorAll('input[name="product_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const cont = document.getElementById('mandub_name_container');
                const inp = document.getElementById('mandub_name');
                if (this.value === 'mandub') {
                    cont.classList.remove('hidden'); inp.setAttribute('required', 'required');
                } else {
                    cont.classList.add('hidden'); inp.removeAttribute('required');
                }
            });
        });

        // =========================================================
        // START: Subcategory Filtering Logic
        // =========================================================
        const mainCategorySelect = document.getElementById('main_category_id');
        const subcategorySelect = document.getElementById('subcategory_id');
        const allCategories = <?php echo json_encode($categories); ?>;
        const initialProductMainCategoryId = <?php echo json_encode($main_category_id); ?>;
        const initialProductSubcategoryId = <?php echo json_encode($subcategory_id); ?>;

        /**
         * Populates the subcategory dropdown based on the selected main category.
         * @param {string} selectedMainId The ID of the currently selected main category.
         * @param {string|null} currentProductSubId The subcategory ID of the product being edited (for initial load).
         */
        function populateSubcategories(selectedMainId, currentProductSubId = null) {
            subcategorySelect.innerHTML = '<option value="">لا يوجد</option>'; // Clear existing and add default
            if (!selectedMainId) {
                return; // No main category selected, no subcategories to show
            }

            // Filter for subcategories whose parent_id matches the selected main category ID
            const subcategories = allCategories.filter(cat => cat.parent_id == selectedMainId);

            subcategories.forEach(subCat => {
                const option = document.createElement('option');
                option.value = subCat.id;
                option.textContent = subCat.name;
                // If this subcategory matches the product's current subcategory, mark it as selected
                if (currentProductSubId && subCat.id == currentProductSubId) {
                    option.selected = true;
                }
                subcategorySelect.appendChild(option);
            });
        }

        // Add event listener to main category select
        mainCategorySelect.addEventListener('change', function() {
            const selectedMainId = this.value;
            // When main category changes, repopulate subcategories.
            // Don't pass currentProductSubId, as the user is making a new choice.
            populateSubcategories(selectedMainId);
        });

        // Initial call to populate subcategories when the page loads
        // This ensures the subcategory dropdown is correct for the product being edited
        populateSubcategories(initialProductMainCategoryId, initialProductSubcategoryId);
        // =========================================================
        // END: Subcategory Filtering Logic
        // =========================================================


        // Variants Logic
        const allColors = <?php echo json_encode($colors); ?>;
        const allAttributes = <?php echo json_encode($attribute_values_by_attr); ?>;
        const existingVariants = <?php echo json_encode($product_variants); ?>;
        
        document.getElementById('generate-variants-btn').addEventListener('click', function() {
            const tbody = document.querySelector('#variants-table tbody');
            tbody.innerHTML = '';
            const selColors = Array.from(document.querySelectorAll('.color-checkbox:checked')).map(cb => parseInt(cb.value));
            const selAttrs = {};
            document.querySelectorAll('.attribute-value-checkbox:checked').forEach(cb => {
                const aId = cb.dataset.attributeId;
                if (!selAttrs[aId]) selAttrs[aId] = [];
                selAttrs[aId].push(parseInt(cb.value));
            });

            if (selColors.length === 0 && Object.keys(selAttrs).length === 0) {
                 tbody.innerHTML = '<tr id="no-variants-row"><td colspan="4" class="text-center py-4 text-gray-500">اختر لتوليد</td></tr>';
                 return;
            }

            const flatAttrs = [];
            Object.keys(selAttrs).forEach(aId => {
                selAttrs[aId].forEach(vId => {
                    const obj = allAttributes[aId]?.find(x => x.id == vId);
                    if (obj) flatAttrs.push({ id: vId, name: obj.value, attribute_id: aId }); // Include attribute_id for variant matching
                });
            });

            let toGen = [];
            if (selColors.length > 0 && flatAttrs.length > 0) {
                selColors.forEach(cId => flatAttrs.forEach(a => toGen.push({ c: allColors.find(x => x.id == cId), a: a })));
            } else if (selColors.length > 0) {
                selColors.forEach(cId => toGen.push({ c: allColors.find(x => x.id == cId), a: null }));
            } else { // Only attributes selected, no colors
                flatAttrs.forEach(a => toGen.push({ c: null, a: a }));
            }

            const basePrice = document.getElementById('price').value || 0;

            toGen.forEach(item => {
                const cId = item.c ? item.c.id : null;
                const aId = item.a ? item.a.id : null;
                const key = `${cId || 'null'}_${aId || 'null'}`; // Consistent key for POST data
                
                // Find existing variant (handle nulls correctly)
                const existing = existingVariants.find(v => 
                    (v.color_id == cId || (v.color_id === null && cId === null)) && 
                    (v.attribute_value_id == aId || (v.attribute_value_id === null && aId === null))
                );

                const vPrice = existing ? existing.price : basePrice;
                const vQty = existing ? existing.quantity : 0;
                const idInput = existing ? `<input type="hidden" name="variants[${key}][id]" value="${existing.id}">` : '';

                tbody.innerHTML += `
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-center text-sm">${item.c ? item.c.name : '-'}</td>
                        <td class="px-4 py-2 text-center text-sm">${item.a ? item.a.name : '-'}</td>
                        <td class="px-4 py-2 text-center">${idInput}<input type="number" name="variants[${key}][price]" step="0.01" value="${vPrice}" class="w-24 border rounded text-center"></td>
                        <td class="px-4 py-2 text-center"><input type="number" name="variants[${key}][quantity]" value="${vQty}" class="w-24 border rounded text-center"></td>
                    </tr>
                `;
            });
        });

        // Trigger variant generation on page load if there are existing variants,
        // or if any checkboxes are already checked.
        if (existingVariants.length > 0 || 
            document.querySelectorAll('.color-checkbox:checked').length > 0 ||
            document.querySelectorAll('.attribute-value-checkbox:checked').length > 0
        ) {
            document.getElementById('generate-variants-btn').click();
        }
    });
</script>
<?php include '../../includes/footer.php'; ?>