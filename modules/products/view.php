<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$can_view = false;
$can_edit = false;
if (file_exists('../../includes/check_permissions.php')) {
    require_once '../../includes/check_permissions.php';
    $can_view = hasPermission($_SESSION['user_id'], 'products', 'view');
    $can_edit = hasPermission($_SESSION['user_id'], 'products', 'edit');
}

$page_title = 'عرض تفاصيل المنتج';
$error_message = '';
$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    header('Location: index.php');
    exit();
}

$product = null;
$product_images = [];
$product_variants = [];

if (!$can_view) {
    $error_message = 'ليس لديك صلاحية لعرض تفاصيل المنتجات.';
} else {
    try {
        // Fetch product details
        $stmt = $db->prepare("SELECT
                                p.*,
                                c_main.name as main_category_name,
                                c_sub.name as sub_category_name
                            FROM
                                products p
                            LEFT JOIN
                                categories c_main ON p.category_id = c_main.id
                            LEFT JOIN
                                categories c_sub ON p.subcategory_id = c_sub.id
                            WHERE p.id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $error_message = 'المنتج المطلوب غير موجود.';
        } else {
            // Fetch product images
            $stmt_img = $db->prepare("SELECT id, image_url, is_main FROM product_images WHERE product_id = ? ORDER BY display_order");
            $stmt_img->execute([$product_id]);
            $product_images = $stmt_img->fetchAll(PDO::FETCH_ASSOC);

            // Fetch product variants with color and attribute details
            $stmt_variants = $db->prepare("SELECT
                                                pv.price AS variant_price,
                                                pv.quantity AS variant_quantity,
                                                pv.image_url AS variant_image_url,
                                                c.name AS color_name,
                                                c.hex_code,
                                                a.name AS attribute_name,
                                                av.value AS attribute_value_name
                                            FROM
                                                product_variants pv
                                            LEFT JOIN
                                                colors c ON pv.color_id = c.id
                                            LEFT JOIN
                                                attribute_values av ON pv.attribute_value_id = av.id
                                            LEFT JOIN
                                                attributes a ON av.attribute_id = a.id
                                            WHERE
                                                pv.product_id = ? AND pv.is_active = 1
                                            ORDER BY
                                                c.name, av.value");
            $stmt_variants->execute([$product_id]);
            $product_variants = $stmt_variants->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $error_message = "حدث خطأ أثناء جلب بيانات المنتج: " . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">تفاصيل المنتج: <?php echo htmlspecialchars($product['name'] ?? 'غير موجود'); ?></h1>
                <div class="flex items-center space-x-3 space-x-reverse">
                    <a href="index.php"
                        class="inline-flex items-center px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition duration-200">
                        <i class="fas fa-arrow-right ml-2"></i> العودة للمنتجات
                    </a>
                    <?php if ($can_edit && $product): ?>
                        <a href="edit.php?id=<?php echo htmlspecialchars($product_id); ?>"
                            class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-edit ml-2"></i> تعديل
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="p-6">
                <?php if (isset($error_message) && $error_message): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-exclamation-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php elseif (!$can_view): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
                        <i class="fas fa-times-circle text-4xl mb-4"></i>
                        <p><?php echo $error_message; ?></p>
                    </div>
                <?php elseif ($product): ?>
                    <!-- Product Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8 border-b pb-8 border-gray-200">
                        <div>
                            <p class="text-sm font-medium text-gray-500">اسم المنتج</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($product['name']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">SKU</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($product['sku'] ?: '-'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الوحدة</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($product['unit']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">تاج تشويقي</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($product['tagline'] ?: '-'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الفئة الرئيسية</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($product['main_category_name'] ?: '-'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الفئة الفرعية</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($product['sub_category_name'] ?: '-'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">السعر (قبل الخصم)</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo number_format($product['price'], 2); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">السعر بعد الخصم</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo $product['discount_price'] ? number_format($product['discount_price'], 2) : '-'; ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الكمية الإجمالية</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo number_format($product['total_quantity']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">ترتيب العرض</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($product['display_order']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">الحالة</p>
                            <p class="mt-1 text-lg text-gray-900">
                                <?php if ($product['is_active']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">نشط</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">معطل</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="lg:col-span-3">
                            <p class="text-sm font-medium text-gray-500">وصف المنتج</p>
                            <p class="mt-1 text-base text-gray-900 prose prose-sm max-w-none"><?php echo nl2br(htmlspecialchars($product['description'] ?: '-')); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">تاريخ الإضافة</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($product['created_at'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">آخر تحديث</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('Y/m/d H:i', strtotime($product['updated_at'])); ?></p>
                        </div>
                    </div>

                    <!-- Product Images -->
                    <div class="mb-8 border-b pb-8 border-gray-200">
                        <h2 class="text-xl font-bold text-gray-900 mb-4">صور المنتج</h2>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                            <?php if (!empty($product_images)): ?>
                                <?php foreach ($product_images as $img): ?>
                                    <div class="relative group bg-white rounded-lg shadow-md overflow-hidden">
                                        <img src="<?php echo htmlspecialchars($img['image_url']); ?>" alt="صورة منتج" class="w-full h-32 object-cover">
                                        <?php if ($img['is_main']): ?>
                                            <span class="absolute top-2 right-2 bg-blue-600 text-white text-xs px-2 py-1 rounded-full">رئيسية</span>
                                        <?php endif; ?>
                                        <div class="p-2 text-xs text-gray-500 break-words overflow-hidden text-center"><?php echo htmlspecialchars($img['image_url']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-600 col-span-full">لا توجد صور لهذا المنتج.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Product Variants -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-4">متغيرات المنتج (الكميات والأسعار)</h2>
                        <?php if (!empty($product_variants)): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">اللون</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">المقاس/السمة</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">السعر</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">الكمية</th>
                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">صورة المتغير</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($product_variants as $variant): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                                    <?php if ($variant['color_name']): ?>
                                                        <span class="w-5 h-5 rounded-full border border-gray-300 inline-block ml-2 align-middle" style="background-color: <?php echo htmlspecialchars($variant['hex_code']); ?>;"></span>
                                                        <?php echo htmlspecialchars($variant['color_name']); ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                                    <?php echo htmlspecialchars($variant['attribute_value_name'] ?: '-'); ?>
                                                </td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-green-600 text-center font-bold">
                                                    <?php echo number_format($variant['variant_price'], 2); ?>
                                                </td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                                    <?php echo number_format($variant['variant_quantity']); ?>
                                                </td>
                                                <td class="px-4 py-4 text-sm text-gray-900 text-center">
                                                    <?php if ($variant['variant_image_url']): ?>
                                                        <img src="<?php echo htmlspecialchars($variant['variant_image_url']); ?>" alt="صورة متغير" class="w-12 h-12 object-cover rounded-md mx-auto">
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-600">لا توجد متغيرات لهذا المنتج.</p>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>