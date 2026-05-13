<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تقرير المخزون الحالي';

// Get filter parameters
$category_filter = $_GET['category_id'] ?? '';
$stock_filter = $_GET['stock_status'] ?? '';

// Fetch inventory data
$sql = "
    SELECT 
        p.id,
        p.product_code,
        p.name,
        p.description,
        pc.name as category_name,
        p.cost_price,
        p.selling_price,
        p.current_stock,
        p.minimum_stock,
        p.unit,
        p.created_at,
        CASE 
            WHEN p.current_stock <= 0 THEN 'out_of_stock'
            WHEN p.current_stock <= p.minimum_stock THEN 'low_stock'
            ELSE 'in_stock'
        END as stock_status
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1
";
$params = [];

if ($category_filter) {
    $sql .= " AND p.category_id = ?";
    $params[] = $category_filter;
}

if ($stock_filter) {
    switch ($stock_filter) {
        case 'low_stock':
            $sql .= " AND p.current_stock <= p.minimum_stock AND p.current_stock > 0";
            break;
        case 'out_of_stock':
            $sql .= " AND p.current_stock <= 0";
            break;
        case 'in_stock':
            $sql .= " AND p.current_stock > p.minimum_stock";
            break;
    }
}

$sql .= " ORDER BY p.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$inventory_data = $stmt->fetchAll();

// Calculate totals
$total_products = count($inventory_data);
$total_value = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;

foreach ($inventory_data as $product) {
    $total_value += ($product['current_stock'] * $product['cost_price']);
    if ($product['stock_status'] == 'low_stock') $low_stock_count++;
    if ($product['stock_status'] == 'out_of_stock') $out_of_stock_count++;
}

// Get categories for filter
$categories_stmt = $db->query("SELECT id, name FROM product_categories ORDER BY name");
$categories = $categories_stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                        <p class="text-gray-600 mt-1">حالة المخزون كما في <?php echo date('d/m/Y H:i'); ?></p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            <i class="fas fa-print ml-2"></i>
                            طباعة
                        </button>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">الفئة</label>
                        <select name="category_id" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 bg-white">
                            <option value="">جميع الفئات</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">حالة المخزون</label>
                        <select name="stock_status" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 bg-white">
                            <option value="">جميع المنتجات</option>
                            <option value="in_stock" <?php echo $stock_filter == 'in_stock' ? 'selected' : ''; ?>>متوفر</option>
                            <option value="low_stock" <?php echo $stock_filter == 'low_stock' ? 'selected' : ''; ?>>مخزون منخفض</option>
                            <option value="out_of_stock" <?php echo $stock_filter == 'out_of_stock' ? 'selected' : ''; ?>>نفد المخزون</option>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                        <i class="fas fa-filter ml-2"></i>فلترة
                    </button>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-boxes text-2xl text-blue-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">إجمالي المنتجات</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_products, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-money-bill-wave text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">قيمة المخزون</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_value, 0, '', ''); ?> ر.س</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-2xl text-orange-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">مخزون منخفض</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($low_stock_count, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-times-circle text-2xl text-red-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">نفد المخزون</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($out_of_stock_count, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المخزون</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">كود المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">اسم المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الفئة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المخزون الحالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحد الأدنى</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الوحدة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">سعر التكلفة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">سعر البيع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">قيمة المخزون</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($inventory_data)): ?>
                        <tr>
                            <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-boxes text-4xl mb-4 text-gray-300"></i>
                                <p>لا توجد منتجات تطابق الفلتر المحدد</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($inventory_data as $product): ?>
                        <tr class="hover:bg-gray-50 <?php echo $product['stock_status'] == 'out_of_stock' ? 'bg-red-50' : ($product['stock_status'] == 'low_stock' ? 'bg-yellow-50' : ''); ?>">
                            <td class="px-6 py-4 text-sm font-mono font-medium text-gray-900">
                                <?php echo htmlspecialchars($product['product_code']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div>
                                    <div class="font-medium"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <?php if ($product['description']): ?>
                                    <div class="text-gray-500 text-xs"><?php echo htmlspecialchars(substr($product['description'], 0, 50)) . (strlen($product['description']) > 50 ? '...' : ''); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($product['category_name'] ?? 'غير محدد'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($product['current_stock'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($product['minimum_stock'], 0, '', ''); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($product['unit']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($product['cost_price'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo number_format($product['selling_price'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-gray-900">
                                <?php echo number_format($product['current_stock'] * $product['cost_price'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php
                                $status_colors = [
                                    'in_stock' => 'bg-amber-100 text-amber-800',
                                    'low_stock' => 'bg-yellow-100 text-yellow-800',
                                    'out_of_stock' => 'bg-red-100 text-red-800'
                                ];
                                $status_labels = [
                                    'in_stock' => 'متوفر',
                                    'low_stock' => 'مخزون منخفض',
                                    'out_of_stock' => 'نفد المخزون'
                                ];
                                ?>
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $status_colors[$product['stock_status']]; ?>">
                                    <?php echo $status_labels[$product['stock_status']]; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- Summary Row -->
                        <tr class="bg-gray-50 font-bold">
                            <td colspan="8" class="px-6 py-4 text-sm text-gray-900">المجموع الكلي:</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo number_format($total_value, 0, '', ''); ?> ر.س</td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo $total_products; ?> منتج</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style media="print">
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .bg-gray-50 { background: white !important; }
    .shadow { box-shadow: none !important; }
    .bg-red-50, .bg-yellow-50 { background: white !important; }
</style>

<?php include '../../includes/footer.php'; ?>
