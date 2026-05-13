<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'المنتجات منخفضة المخزون';

// Fetch low stock products
$sql = "
    SELECT 
        p.id,
        p.product_code,
        p.name,
        pc.name as category_name,
        p.current_stock,
        p.minimum_stock,
        p.unit,
        p.cost_price,
        p.selling_price,
        (p.current_stock * p.cost_price) as stock_value,
        CASE 
            WHEN p.current_stock <= 0 THEN 'out_of_stock'
            WHEN p.current_stock <= p.minimum_stock THEN 'critical'
            ELSE 'low'
        END as urgency_level
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id
    WHERE p.is_active = 1 
    AND p.current_stock <= p.minimum_stock
    ORDER BY 
        CASE 
            WHEN p.current_stock <= 0 THEN 1
            WHEN p.current_stock <= p.minimum_stock THEN 2
            ELSE 3
        END,
        p.current_stock ASC
";

$stmt = $db->prepare($sql);
$stmt->execute();
$low_stock_products = $stmt->fetchAll();

// Calculate statistics
$total_products = count($low_stock_products);
$out_of_stock_count = 0;
$critical_count = 0;
$total_value_affected = 0;

foreach ($low_stock_products as $product) {
    if ($product['urgency_level'] == 'out_of_stock') $out_of_stock_count++;
    if ($product['urgency_level'] == 'critical') $critical_count++;
    $total_value_affected += $product['stock_value'];
}

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
                        <p class="text-gray-600 mt-1">المنتجات التي تحتاج إلى إعادة تخزين عاجل</p>
                    </div>
                    <div class="flex space-x-3 space-x-reverse">
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700">
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
        </div>

        <?php if ($total_products > 0): ?>
        <!-- Alert Banner -->
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-red-400"></i>
                </div>
                <div class="mr-3">
                    <p class="text-sm text-red-700">
                        <strong>تنبيه:</strong> يوجد <?php echo $total_products; ?> منتج يحتاج إلى إعادة تخزين عاجل.
                        <?php if ($out_of_stock_count > 0): ?>
                        منها <?php echo $out_of_stock_count; ?> منتج نفد مخزونه تماماً.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-2xl text-orange-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">منتجات تحتاج تخزين</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_products, 0, '', ''); ?></p>
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
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation text-2xl text-yellow-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">حالة حرجة</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($critical_count, 0, '', ''); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-money-bill-wave text-2xl text-amber-600"></i>
                    </div>
                    <div class="mr-5">
                        <p class="text-sm font-medium text-gray-500">قيمة متأثرة</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_value_affected, 0, '', ''); ?> ر.س</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">تفاصيل المنتجات منخفضة المخزون</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الأولوية</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">كود المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">اسم المنتج</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الفئة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">المخزون الحالي</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحد الأدنى</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الوحدة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">سعر التكلفة</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">قيمة المخزون</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الكمية المطلوبة</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($low_stock_products)): ?>
                        <tr>
                            <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-check-circle text-4xl mb-4 text-amber-300"></i>
                                <p class="text-lg font-medium text-gray-900 mb-2">ممتاز! جميع المنتجات لديها مخزون كافي</p>
                                <p>لا توجد منتجات تحتاج إلى إعادة تخزين في الوقت الحالي</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($low_stock_products as $product): ?>
                        <tr class="hover:bg-gray-50 <?php echo $product['urgency_level'] == 'out_of_stock' ? 'bg-red-50' : ($product['urgency_level'] == 'critical' ? 'bg-yellow-50' : ''); ?>">
                            <td class="px-6 py-4 text-sm">
                                <?php if ($product['urgency_level'] == 'out_of_stock'): ?>
                                <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800 font-medium">
                                    <i class="fas fa-times-circle mr-1"></i>نفد
                                </span>
                                <?php elseif ($product['urgency_level'] == 'critical'): ?>
                                <span class="px-2 py-1 text-xs rounded-full bg-orange-100 text-orange-800 font-medium">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>حرج
                                </span>
                                <?php else: ?>
                                <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800 font-medium">
                                    <i class="fas fa-exclamation mr-1"></i>منخفض
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono font-medium text-gray-900">
                                <?php echo htmlspecialchars($product['product_code']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <?php echo htmlspecialchars($product['category_name'] ?? 'غير محدد'); ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-bold <?php echo $product['current_stock'] <= 0 ? 'text-red-600' : 'text-orange-600'; ?>">
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
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                <?php echo number_format($product['stock_value'], 0, '', ''); ?> ر.س
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?php 
                                $needed = max(0, $product['minimum_stock'] - $product['current_stock'] + 10); // +10 as buffer
                                ?>
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs font-medium">
                                    <?php echo number_format($needed, 0, '', ''); ?> <?php echo htmlspecialchars($product['unit']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_products > 0): ?>
        <!-- Action Recommendations -->
        <div class="bg-blue-50 rounded-lg p-6 mt-6">
            <h3 class="text-lg font-medium text-blue-900 mb-4">توصيات للإجراءات:</h3>
            <div class="space-y-2 text-sm text-blue-800">
                <div class="flex items-center">
                    <i class="fas fa-arrow-left ml-2 text-blue-600"></i>
                    <span>قم بإنشاء طلبات شراء عاجلة للمنتجات التي نفد مخزونها</span>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-arrow-left ml-2 text-blue-600"></i>
                    <span>تواصل مع الموردين لتأكيد توفر المنتجات وأوقات التوصيل</span>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-arrow-left ml-2 text-blue-600"></i>
                    <span>راجع الحدود الدنيا للمنتجات وقم بتحديثها حسب معدل الاستهلاك</span>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-arrow-left ml-2 text-blue-600"></i>
                    <span>أخطر فريق المبيعات بالمنتجات التي قد تواجه نقصاً قريباً</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style media="print">
    .no-print { display: none !important; }
    body { font-size: 12px; }
    .bg-gray-50, .bg-red-50, .bg-yellow-50, .bg-blue-50 { background: white !important; }
    .shadow { box-shadow: none !important; }
</style>

<?php include '../../includes/footer.php'; ?>
