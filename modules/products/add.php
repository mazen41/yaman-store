<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_add = false;
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_add = hasPermission($_SESSION['user_id'], 'products', 'add');
}

if (!$can_add) {
    $error_message = 'ليس لديك صلاحية لإضافة منتجات.';
}

$page_title = 'إضافة منتج جديد';
$error_message = '';
$success_message = '';

// Define upload directory and base URL for products
$upload_dir = __DIR__ . '/../../uploads/products/';
$base_image_url = '/uploads/products/'; // Web-accessible path

// Ensure upload directory exists
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true); 
}

// --- Fetch data for dropdowns/checkboxes ---
$categories = [];
$attributes = [];
$attribute_values = []; 
$colors = [];

try {
    $categories_stmt = $db->query("SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY parent_id ASC, name ASC");
    $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

    $attributes_stmt = $db->query("SELECT id, name FROM attributes WHERE is_active = 1 ORDER BY display_order, name");
    $attributes = $attributes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $attribute_values_stmt = $db->query("SELECT av.id, av.attribute_id, av.value, a.name as attribute_name FROM attribute_values av JOIN attributes a ON av.attribute_id = a.id WHERE av.is_active = 1 AND a.is_active = 1 ORDER BY a.display_order, a.name, av.display_order, av.value");
    $all_attribute_values_flat = $attribute_values_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_attribute_values_flat as $val) {
        $attribute_values[$val['attribute_id']][] = $val;
    }

    $colors_stmt = $db->query("SELECT id, name, hex_code FROM colors WHERE is_active = 1 ORDER BY display_order, name");
    $colors = $colors_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "خطأ في تحميل بيانات المتطلبات: " . $e->getMessage();
}

// Function to build hierarchical category options
function buildCategoryOptions($categories, $selected_id = null, $parent_id = null, $indent = '') {
    $html = '';
    foreach ($categories as $category) {
        if (($parent_id === null && $category['parent_id'] === null) || ($parent_id !== null && $category['parent_id'] == $parent_id)) {
            $html .= '<option value="' . htmlspecialchars($category['id']) . '"';
            if ($selected_id == $category['id']) {
                $html .= ' selected';
            }
            $html .= '>' . $indent . htmlspecialchars($category['name']) . '</option>';
            $html .= buildCategoryOptions($categories, $selected_id, $category['id'], $indent . '--- ');
        }
    }
    return $html;
}

// Initialize form variables 
$name = '';
$sku = '';
$unit = 'قطعة';
$tagline = '';
$main_category_id = '';
$subcategory_id = '';
$description = '';
$price = '';
$discount_price = '';
$display_order = 0;
$purchase_amount = ''; 
$product_quantity = ''; 
$product_type = 'yaman'; 
$mandub_name = ''; 
$manager_notes = ''; 

$uploaded_image_urls_for_repopulate = []; 
$selected_colors = []; 
$selected_attribute_values = []; 
$posted_variants_data = []; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_add) {
    // Re-collect data from POST
    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $unit = trim($_POST['unit'] ?? 'قطعة');
    $tagline = trim($_POST['tagline'] ?? '');
    $main_category_id = $_POST['main_category_id'] ?? '';
    $subcategory_id = $_POST['subcategory_id'] ?? null;
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'] ?? '';
    $discount_price = $_POST['discount_price'] ?? null;
    $display_order = $_POST['display_order'] ?? 0;
    $purchase_amount = $_POST['purchase_amount'] ?? ''; 
    $product_quantity = $_POST['product_quantity'] ?? ''; 
    $product_type = $_POST['product_type'] ?? 'yaman'; 
    $mandub_name = ($product_type === 'mandub') ? trim($_POST['mandub_name'] ?? '') : null; 
    $manager_notes = trim($_POST['manager_notes'] ?? ''); 

    $main_image_idx = $_POST['main_image'] ?? null; 
    $existing_images = $_POST['existing_images'] ?? []; 
    $uploaded_image_urls_for_repopulate = $existing_images;

    $selected_colors = $_POST['colors'] ?? [];
    $selected_attribute_values_input = $_POST['attribute_values'] ?? [];
    foreach ($selected_attribute_values_input as $attr_id => $values_arr) {
        foreach ($values_arr as $val_id) {
            $selected_attribute_values[] = $val_id;
        }
    }
    $posted_variants_data = $_POST['variants'] ?? [];

    // Validation
    if (empty($name) || empty($unit) || empty($main_category_id) || empty($price) || empty($purchase_amount) || empty($product_quantity)) {
        $error_message = 'الرجاء ملء الحقول الأساسية المطلوبة للمنتج.';
    } elseif (!is_numeric($price) || ($discount_price !== null && !is_numeric($discount_price)) || !is_numeric($purchase_amount) || !is_numeric($product_quantity)) {
        $error_message = 'السعر وسعر الخصم ومبلغ الشراء وكمية المنتج يجب أن تكون أرقامًا.';
    } elseif ($product_type === 'mandub' && empty($mandub_name)) {
        $error_message = 'الرجاء إدخال اسم المندوب لنوع المنتج "مندوب".';
    } else {
        $db->beginTransaction();
        try {
            $uploaded_image_paths = []; 
            $file_upload_errors = [];

            // Process image uploads
            if (isset($_FILES['product_gallery_files'])) {
                $files = $_FILES['product_gallery_files'];
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
                $max_file_size = 5 * 1024 * 1024; // 5 MB

                foreach ($files['name'] as $idx => $file_name) {
                    if ($files['error'][$idx] === UPLOAD_ERR_OK) {
                        $file_tmp_name = $files['tmp_name'][$idx];
                        $file_size = $files['size'][$idx];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                        if ($file_size > $max_file_size) {
                            $file_upload_errors[] = "الصورة '{$file_name}' حجمها كبير جداً.";
                            continue;
                        }
                        if (!in_array($file_ext, $allowed_types)) {
                            $file_upload_errors[] = "نوع الصورة '{$file_name}' غير مسموح.";
                            continue;
                        }

                        $unique_filename = uniqid('prod_') . '.' . $file_ext;
                        $destination_path = $upload_dir . $unique_filename;

                        if (move_uploaded_file($file_tmp_name, $destination_path)) {
                            $key = 'new_' . $idx; 
                            $uploaded_image_paths[$key] = $destination_path; 
                            $uploaded_image_urls_for_repopulate[$key] = $base_image_url . $unique_filename; 
                        } else {
                            $file_upload_errors[] = "فشل في رفع الصورة '{$file_name}'.";
                        }
                    }
                }
            }
            
            if (!empty($file_upload_errors)) {
                $error_message = implode('<br>', $file_upload_errors);
                throw new Exception("File upload issues occurred."); 
            }
            
            // 1. Insert product data
            $stmt = $db->prepare("INSERT INTO products (name, sku, unit, tagline, category_id, subcategory_id, description, price, discount_price, display_order, total_quantity, purchase_amount, product_quantity, product_type, mandub_name, manager_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name, $sku, $unit, $tagline, $main_category_id,
                $subcategory_id === '' ? null : $subcategory_id,
                $description, $price,
                $discount_price === '' ? null : $discount_price,
                $display_order, 0, 
                $purchase_amount, 
                $product_quantity, 
                $product_type, 
                $mandub_name, 
                $manager_notes 
            ]);
            $product_id = $db->lastInsertId();

            // 2. Insert product images
            $image_order = 0;
            if (!empty($uploaded_image_urls_for_repopulate)) {
                $stmt_img = $db->prepare("INSERT INTO product_images (product_id, image_url, is_main, display_order) VALUES (?, ?, ?, ?)");
                
                // If no main image is specified, make the first one main
                $main_set = false;
                foreach ($uploaded_image_urls_for_repopulate as $key => $url) {
                    if ($key === $main_image_idx) $main_set = true;
                }
                
                foreach ($uploaded_image_urls_for_repopulate as $key => $url) {
                    if (!$main_set && $image_order === 0) {
                        $is_main = 1; // Default first to main
                    } else {
                        $is_main = ($key === $main_image_idx) ? 1 : 0;
                    }
                    $stmt_img->execute([$product_id, $url, $is_main, $image_order++]);
                }
            }
            
            // 3. Insert product variants and calculate total quantity
            $total_product_quantity = 0;
            if (!empty($posted_variants_data)) {
                $stmt_variant = $db->prepare("INSERT INTO product_variants (product_id, color_id, attribute_value_id, price, quantity, image_url) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($posted_variants_data as $variant_key => $variant_val) {
                    $parts = explode('_', $variant_key);
                    $v_color_id = $parts[0] === 'null' ? null : (int)$parts[0];
                    $v_attr_value_id = $parts[1] === 'null' ? null : (int)$parts[1];

                    $v_price = $variant_val['price'] ?? $price;
                    $v_quantity = $variant_val['quantity'] ?? 0;
                    $v_image_url = $variant_val['image_url'] ?? null;

                    if (!empty($v_quantity) && $v_quantity > 0) {
                        $stmt_variant->execute([
                            $product_id, $v_color_id, $v_attr_value_id, $v_price, $v_quantity, $v_image_url
                        ]);
                        $total_product_quantity += $v_quantity;
                    }
                }
            }

            // 4. Update total_quantity in the main products table
            $stmt_update_qty = $db->prepare("UPDATE products SET total_quantity = ? WHERE id = ?");
            $stmt_update_qty->execute([$total_product_quantity, $product_id]);

            $db->commit();
            $success_message = 'تم إضافة المنتج بنجاح!';
            header("Location: add.php?success_message=" . urlencode($success_message));
            exit();

        } catch (Exception $e) { 
            $db->rollBack();
            foreach ($uploaded_image_paths as $key => $path) {
                if (file_exists($path)) unlink($path);
                unset($uploaded_image_urls_for_repopulate[$key]); 
            }
            if (empty($error_message)) { 
                $error_message = 'حدث خطأ أثناء إضافة المنتج: ' . $e->getMessage();
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">إضافة منتج جديد</h1>
                <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للمنتجات
                </a>
            </div>

            <div class="p-6">
                <?php if (isset($_GET['success_message']) && !empty($_GET['success_message'])): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4">
                        <i class="fas fa-check-circle ml-2"></i><?php echo htmlspecialchars($_GET['success_message']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($error_message) && $error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4">
                        <i class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$can_add): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-times-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php else: ?>
                    <form id="productForm" action="add.php" method="POST" enctype="multipart/form-data" class="space-y-8">
                        
                        <!-- Basic Info -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">معلومات المنتج الأساسية</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">اسم المنتج <span class="text-red-500">*</span></label>
                                    <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($name); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">SKU (رمز المنتج)</label>
                                    <input type="text" name="sku" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($sku); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">الوحدة <span class="text-red-500">*</span></label>
                                    <input type="text" name="unit" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($unit); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">تاج تشويقي</label>
                                    <input type="text" name="tagline" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($tagline); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">الفئة الرئيسية <span class="text-red-500">*</span></label>
                                    <select name="main_category_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">اختر فئة رئيسية</option>
                                        <?php echo buildCategoryOptions($categories, $main_category_id); ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">الفئة الفرعية</label>
                                    <select name="subcategory_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">لا يوجد فئة فرعية</option>
                                        <?php echo buildCategoryOptions($categories, $subcategory_id); ?>
                                    </select>
                                </div>
                                <div class="col-span-1 md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">وصف المنتج</label>
                                    <textarea name="description" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($description); ?></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">السعر (قبل الخصم) <span class="text-red-500">*</span></label>
                                    <input type="number" name="price" id="price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($price); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">السعر بعد الخصم (اختياري)</label>
                                    <input type="number" name="discount_price" step="0.01" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($discount_price); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">ترتيب العرض</label>
                                    <input type="number" name="display_order" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($display_order); ?>">
                                </div>
                                <!-- New fields -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">مبلغ الشراء <span class="text-red-500">*</span></label>
                                    <input type="number" name="purchase_amount" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($purchase_amount); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">كمية المنتج <span class="text-red-500">*</span></label>
                                    <input type="number" name="product_quantity" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($product_quantity); ?>">
                                </div>
                                <div class="col-span-1 md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">نوع المنتج <span class="text-red-500">*</span></label>
                                    <div class="flex items-center space-x-4 space-x-reverse">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="product_type" value="yaman" class="form-radio text-blue-600" <?php echo ($product_type === 'yaman') ? 'checked' : ''; ?>>
                                            <span class="mr-2">منتج يمان</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="product_type" value="mandub" class="form-radio text-blue-600" <?php echo ($product_type === 'mandub') ? 'checked' : ''; ?>>
                                            <span class="mr-2">منتج مندوب</span>
                                        </label>
                                    </div>
                                </div>
                                <div id="mandub_name_container" class="col-span-1 md:col-span-2 <?php echo ($product_type === 'mandub') ? '' : 'hidden'; ?>">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">اسم المندوب <span class="text-red-500">*</span></label>
                                    <input type="text" name="mandub_name" id="mandub_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" value="<?php echo htmlspecialchars($mandub_name); ?>">
                                </div>
                                <div class="col-span-1 md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات المدير</label>
                                    <textarea name="manager_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($manager_notes); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Product Images Gallery -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">معرض صور المنتج</h2>
                            
                            <div id="image-gallery" class="space-y-4 mb-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php
                                if (!empty($uploaded_image_urls_for_repopulate)) {
                                    foreach ($uploaded_image_urls_for_repopulate as $key => $url) {
                                        $isChecked = ($key === $main_image_idx) ? 'checked' : '';
                                        echo '
                                        <div class="flex items-center p-3 bg-white border border-gray-200 rounded-lg shadow-sm image-item existing-item">
                                            <img src="' . htmlspecialchars($url) . '" alt="معاينة" class="h-16 w-16 object-cover rounded-md ml-3 border border-gray-300">
                                            <input type="hidden" name="existing_images[' . htmlspecialchars($key) . ']" value="' . htmlspecialchars($url) . '">
                                            <div class="flex-grow flex flex-col justify-center">
                                                <label class="flex items-center space-x-2 space-x-reverse text-sm text-gray-700 cursor-pointer">
                                                    <input type="radio" name="main_image" value="' . htmlspecialchars($key) . '" class="form-radio text-blue-600 main-image-radio" ' . $isChecked . '>
                                                    <span>صورة رئيسية</span>
                                                </label>
                                            </div>
                                            <button type="button" class="text-red-500 hover:text-red-700 remove-btn mr-2" title="حذف الصورة">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>';
                                    }
                                }
                                ?>
                            </div>

                            <div class="mt-4">
                                <input type="file" id="multi-file-input" multiple accept="image/*" class="hidden">
                                <input type="file" name="product_gallery_files[]" id="final-files" multiple class="hidden">
                                
                                <button type="button" id="trigger-file-input" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200 shadow-sm">
                                    <i class="fas fa-images ml-2"></i> إضافة صور متعددة
                                </button>
                                <p class="mt-2 text-sm text-gray-500">اختر صورة أو أكثر. الصورة الأولى يتم تعيينها كرئيسية تلقائياً.</p>
                            </div>
                        </div>

                        <!-- Colors and Attributes -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">خيارات المنتج</h2>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 mb-2">الألوان المتاحة:</label>
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <?php foreach ($colors as $color): ?>
                                        <div class="flex items-center">
                                            <input type="checkbox" name="colors[]" id="color_<?php echo $color['id']; ?>" value="<?php echo $color['id']; ?>" class="form-checkbox h-5 w-5 text-blue-600 color-checkbox" <?php if (in_array($color['id'], $selected_colors)) echo 'checked'; ?>>
                                            <label for="color_<?php echo $color['id']; ?>" class="mr-2 text-sm text-gray-700 flex items-center">
                                                <span class="w-5 h-5 rounded-full border border-gray-300 ml-2" style="background-color: <?php echo htmlspecialchars($color['hex_code']); ?>;"></span>
                                                <?php echo htmlspecialchars($color['name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php foreach ($attributes as $attribute): ?>
                                <div class="mb-6">
                                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo htmlspecialchars($attribute['name']); ?>:</label>
                                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                        <?php if (isset($attribute_values[$attribute['id']])): ?>
                                            <?php foreach ($attribute_values[$attribute['id']] as $attr_val): ?>
                                                <div class="flex items-center">
                                                    <input type="checkbox" name="attribute_values[<?php echo $attribute['id']; ?>][]" id="attr_val_<?php echo $attr_val['id']; ?>" value="<?php echo $attr_val['id']; ?>" class="form-checkbox h-5 w-5 text-purple-600 attribute-value-checkbox" data-attribute-id="<?php echo $attribute['id']; ?>" <?php if (in_array($attr_val['id'], $selected_attribute_values)) echo 'checked'; ?>>
                                                    <label for="attr_val_<?php echo $attr_val['id']; ?>" class="mr-2 text-sm text-gray-700">
                                                        <?php echo htmlspecialchars($attr_val['value']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <button type="button" id="generate-variants-btn" class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                                <i class="fas fa-sync-alt ml-2"></i> تحديث جدول الكميات
                            </button>
                        </div>

                        <!-- Variants Table -->
                        <div class="bg-gray-50 p-6 rounded-lg shadow-sm border border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800 mb-4">جدول المتغيرات</h2>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200" id="variants-table">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">اللون</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">المقاس/السمة</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">السعر</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الكمية</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr id="no-variants-row">
                                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">حدد الألوان والسمات لتوليد المتغيرات.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="flex justify-end mt-8">
                            <button type="submit" class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-md">
                                <i class="fas fa-plus ml-2"></i> إضافة المنتج
                            </button>
                        </div>
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
        
        const productTypeRadios = document.querySelectorAll('input[name="product_type"]');
        const mandubNameContainer = document.getElementById('mandub_name_container');
        const mandubNameInput = document.getElementById('mandub_name');

        triggerBtn.addEventListener('click', () => multiFileInput.click());

        multiFileInput.addEventListener('change', function() {
            for(let i = 0; i < this.files.length; i++) {
                dt.items.add(this.files[i]);
            }
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
                radioLabel.className = 'flex items-center space-x-2 space-x-reverse text-sm text-gray-700 cursor-pointer';
                
                let radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'main_image';
                radio.value = 'new_' + i; 
                radio.className = 'form-radio text-blue-600 main-image-radio';
                
                if (!hasMain && i === 0 && document.querySelectorAll('.existing-item').length === 0) {
                    radio.checked = true;
                    hasMain = true;
                }

                radioLabel.appendChild(radio);
                radioLabel.appendChild(document.createTextNode('صورة رئيسية'));
                
                controlsContainer.appendChild(nameSpan);
                controlsContainer.appendChild(radioLabel);

                let removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'text-red-500 hover:text-red-700 ml-2 remove-btn';
                removeBtn.innerHTML = '<i class="fas fa-trash-alt"></i>';
                removeBtn.onclick = function() {
                    dt.items.remove(i);
                    updateGalleryUI(); 
                };
                
                item.appendChild(img);
                item.appendChild(controlsContainer);
                item.appendChild(removeBtn);
                
                imageGallery.appendChild(item);
            }
            checkMainRadio();
        }

        imageGallery.addEventListener('click', function(e) {
            if (e.target.closest('.remove-btn') && e.target.closest('.existing-item')) {
                e.target.closest('.existing-item').remove();
                checkMainRadio();
            }
        });

        function checkMainRadio() {
            const allRadios = document.querySelectorAll('.main-image-radio');
            if (!document.querySelector('.main-image-radio:checked') && allRadios.length > 0) {
                allRadios[0].checked = true;
            }
        }

        productTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'mandub') {
                    mandubNameContainer.classList.remove('hidden');
                    mandubNameInput.setAttribute('required', 'required');
                } else {
                    mandubNameContainer.classList.add('hidden');
                    mandubNameInput.removeAttribute('required');
                }
            });
        });

        const allColors = <?php echo json_encode($colors); ?>;
        const allAttributes = <?php echo json_encode($attributes); ?>;
        const allAttributeValues = <?php echo json_encode($attribute_values); ?>;
        const generateVariantsBtn = document.getElementById('generate-variants-btn');
        const variantsTableBody = document.querySelector('#variants-table tbody');
        const noVariantsRow = document.getElementById('no-variants-row');

        generateVariantsBtn.addEventListener('click', function() {
            variantsTableBody.innerHTML = '';
            const selectedColorIds = Array.from(document.querySelectorAll('.color-checkbox:checked')).map(cb => parseInt(cb.value));
            const selectedAttributeValues = {};
            document.querySelectorAll('.attribute-value-checkbox:checked').forEach(cb => {
                const attrId = cb.dataset.attributeId;
                if (!selectedAttributeValues[attrId]) selectedAttributeValues[attrId] = [];
                selectedAttributeValues[attrId].push(parseInt(cb.value));
            });

            if (selectedColorIds.length === 0 && Object.keys(selectedAttributeValues).length === 0) {
                variantsTableBody.appendChild(noVariantsRow);
                noVariantsRow.style.display = 'table-row';
                return;
            }
            noVariantsRow.style.display = 'none';

            let variantsToGenerate = [];
            const flatSelectedAttrVals = [];
            Object.keys(selectedAttributeValues).forEach(attrId => {
                selectedAttributeValues[attrId].forEach(valId => {
                    const valueObj = allAttributeValues[attrId]?.find(av => av.id == valId);
                    if (valueObj) flatSelectedAttrVals.push({ attribute_id: attrId, value_id: valId, value_name: valueObj.value });
                });
            });

            if (selectedColorIds.length > 0 && flatSelectedAttrVals.length > 0) {
                selectedColorIds.forEach(colorId => {
                    flatSelectedAttrVals.forEach(attrVal => {
                        variantsToGenerate.push({ color: allColors.find(c => c.id === colorId), attribute_value: attrVal });
                    });
                });
            } else if (selectedColorIds.length > 0) {
                selectedColorIds.forEach(colorId => variantsToGenerate.push({ color: allColors.find(c => c.id === colorId), attribute_value: null }));
            } else if (flatSelectedAttrVals.length > 0) {
                flatSelectedAttrVals.forEach(attrVal => variantsToGenerate.push({ color: null, attribute_value: attrVal }));
            }

            const currentPrice = parseFloat(document.getElementById('price').value) || 0;
            const postedVariantsData = <?php echo json_encode($posted_variants_data); ?>;

            variantsToGenerate.forEach(variant => {
                const cId = variant.color ? variant.color.id : 'null';
                const aId = variant.attribute_value ? variant.attribute_value.value_id : 'null';
                const vKey = `${cId}_${aId}`;
                
                const pVariant = postedVariantsData[vKey];
                const vPrice = pVariant ? pVariant.price : currentPrice;
                const vQty = pVariant ? pVariant.quantity : 0;

                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50';
                row.innerHTML = `
                    <td class="px-4 py-2 text-center text-sm">${variant.color ? variant.color.name : '-'}</td>
                    <td class="px-4 py-2 text-center text-sm">${variant.attribute_value ? variant.attribute_value.value_name : '-'}</td>
                    <td class="px-4 py-2 text-center"><input type="number" name="variants[${vKey}][price]" step="0.01" value="${vPrice}" class="w-24 border rounded text-center"></td>
                    <td class="px-4 py-2 text-center"><input type="number" name="variants[${vKey}][quantity]" value="${vQty}" class="w-24 border rounded text-center"></td>
                `;
                variantsTableBody.appendChild(row);
            });
        });

        if (<?php echo json_encode(count($selected_colors) > 0 || count($selected_attribute_values) > 0); ?>) {
            generateVariantsBtn.click();
        }
    });
</script>
<?php include '../../includes/footer.php'; ?>