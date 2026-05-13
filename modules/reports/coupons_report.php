<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تقرير الكوبونات والخصومات';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$coupon_code = $_GET['coupon_code'] ?? '';
$customer_id = $_GET['customer_id'] ?? '';
$export_type = $_GET['export'] ?? '';

// Handle exports
if ($export_type) {
    // Note: PDF/Excel export logic may need adjustment for complex layouts or full library support.
    if ($export_type == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="coupons_report_' . date('Y-m-d') . '.xls"');
    } elseif ($export_type == 'pdf') {
        // PDF generation often requires a library like FPDF or Dompdf.
        // This is a placeholder for content type.
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="coupons_report_' . date('Y-m-d') . '.pdf"');
    }
}

// =========================================================================
// ************************** التعديل هنا **********************************
// Fetch all coupon codes from the coupons table for the filter dropdown
// تم تغيير الاستعلام ليجلب جميع الكوبونات المعرفة في جدول 'coupons' لتظهر في الفلتر.
$coupons_stmt = $db->query("SELECT DISTINCT coupon_code FROM coupons WHERE coupon_code IS NOT NULL AND coupon_code != '' ORDER BY coupon_code ASC");
$available_coupons = $coupons_stmt->fetchAll(PDO::FETCH_COLUMN);
// *************************************************************************
// =========================================================================

// Fetch customers
$customers_stmt = $db->query("SELECT id, name FROM customers ORDER BY name ASC");
$available_customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);


// Base SQL query for fetching discount data from orders
// Unified approach to get the display_discount_percentage, similar to orders list
$sql = "
    SELECT 
        co.order_number,
        co.coupon_code,
        DATE(co.created_at) AS order_date,
        c.name AS customer_name,
        COALESCE(co.subtotal_amount, 0) AS subtotal_amount,
        (
            COALESCE(co.discount_amount, 0) +
            COALESCE(co.additional_discount, 0) +
            COALESCE(co.automatic_discount_amount, 0)
        ) AS total_discount_amount,
        co.final_amount,
        co.status,
        -- Calculate display_discount_percentage here
        CASE
            WHEN co.coupon_id IS NOT NULL AND coup.discount_type = 'percentage' THEN coup.discount_value
            WHEN co.coupon_id IS NOT NULL AND coup.discount_type = 'fixed' AND COALESCE(co.subtotal_amount, 0) > 0.01 THEN (
                (COALESCE(co.discount_amount, 0) + COALESCE(co.additional_discount, 0)) / COALESCE(co.subtotal_amount, 1)
            ) * 100
            ELSE COALESCE(co.automatic_discount_percentage, 0)
        END as display_discount_percentage
    FROM customer_orders co
    LEFT JOIN customers c ON co.customer_id = c.id
    LEFT JOIN coupons coup ON co.coupon_id = coup.id -- Join with coupons table
    WHERE DATE(co.created_at) BETWEEN ? AND ?
      AND co.status <> 'cancelled'
      AND (
            COALESCE(co.discount_amount, 0) + 
            COALESCE(co.additional_discount, 0) + 
            COALESCE(co.automatic_discount_amount, 0)
          ) > 0
";

$params = [$start_date, $end_date];

// Add optional filters to the query
if (!empty($coupon_code)) {
    $sql .= " AND co.coupon_code = ?";
    $params[] = $coupon_code;
}

if (!empty($customer_id)) {
    $sql .= " AND co.customer_id = ?";
    $params[] = $customer_id;
}

$sql .= " ORDER BY co.created_at DESC";

// Execute the final query
$stmt = $db->prepare($sql);
$stmt->execute($params);
$discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals based on the filtered results
$total_orders = count($discounts);
$total_subtotal = array_sum(array_column($discounts, 'subtotal_amount'));
$total_discount = array_sum(array_column($discounts, 'total_discount_amount'));
$total_final = array_sum(array_column($discounts, 'final_amount'));

// Calculate average discount percentage
// Use a higher precision for the calculation before formatting
$avg_discount_percentage = ($total_subtotal > 0) ? ($total_discount / $total_subtotal * 100) : 0;

include '../../includes/header.php';
?>

<style>
    .stat-card {
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    }

    .data-table {
        overflow-x: auto;
    }

    .data-table::-webkit-scrollbar {
        height: 8px;
    }

    .data-table::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .data-table::-webkit-scrollbar-thumb {
        background: #f59e0b;
        border-radius: 10px;
    }

    .data-table::-webkit-scrollbar-thumb:hover {
        background: #d97706;
    }
</style>

<div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div
            class="bg-gradient-to-r from-amber-500 via-orange-500 to-amber-600 shadow-2xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-white flex items-center">
                            <i class="fas fa-ticket-alt ml-3 text-amber-200"></i>
                            تقرير الكوبونات والخصومات
                        </h1>
                        <p class="text-amber-100 mt-2 text-sm md:text-base">
                            من <?php echo date('Y/m/d', strtotime($start_date)); ?>
                            إلى <?php echo date('Y/m/d', strtotime($end_date)); ?>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&coupon_code=<?php echo $coupon_code; ?>&customer_id=<?php echo $customer_id; ?>&export=excel"
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all shadow-lg hover:shadow-xl">
                            <i class="fas fa-file-excel ml-2"></i>
                            تصدير Excel
                        </a>
                        <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&coupon_code=<?php echo $coupon_code; ?>&customer_id=<?php echo $customer_id; ?>&export=pdf"
                            class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all shadow-lg hover:shadow-xl">
                            <i class="fas fa-file-pdf ml-2"></i>
                            تصدير PDF
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">من تاريخ</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">إلى تاريخ</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
                </div>
                <div>
                    <label for="coupon_code" class="block text-sm font-semibold text-gray-700 mb-2">كود الكوبون</label>
                    <select name="coupon_code" id="coupon_code" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
                        <option value="">الكل</option>
                        <?php foreach ($available_coupons as $code): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo ($coupon_code == $code) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($code); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="customer_id" class="block text-sm font-semibold text-gray-700 mb-2">العميل</label>
                    <select name="customer_id" id="customer_id" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
                        <option value="">الكل</option>
                         <?php foreach ($available_customers as $customer): ?>
                            <option value="<?php echo htmlspecialchars($customer['id']); ?>" <?php echo ($customer_id == $customer['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit"
                        class="w-full px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-xl hover:from-amber-600 hover:to-orange-600 font-semibold shadow-lg hover:shadow-xl transition-all">
                        <i class="fas fa-search ml-2"></i>
                        تصفية
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Orders -->
            <div class="stat-card bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl shadow-xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-shopping-cart text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">الطلبات</span>
                </div>
                <h3 class="text-4xl font-bold mb-2"><?php echo number_format($total_orders, 0, ',', '.'); ?></h3>
                <p class="text-blue-100 text-sm">عدد الطلبات بالخصم</p>
            </div>

            <!-- Total Discount -->
            <div class="stat-card bg-gradient-to-br from-red-500 to-red-600 rounded-2xl shadow-xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-tags text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">الخصومات</span>
                </div>
                <h3 class="text-4xl font-bold mb-2"><?php echo number_format($total_discount, 0, ',', '.'); ?></h3>
                <p class="text-red-100 text-sm">إجمالي الخصومات (ر.ي)</p>
            </div>

            <!-- Average Discount -->
            <div class="stat-card bg-gradient-to-br from-amber-500 to-orange-500 rounded-2xl shadow-xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-percent text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">المتوسط</span>
                </div>
                <h3 class="text-4xl font-bold mb-2"><?php echo number_format($avg_discount_percentage, 2, ',', '.'); ?>%
                </h3> <!-- Changed to 2 decimal places -->
                <p class="text-amber-100 text-sm">متوسط الخصم</p>
            </div>

            <!-- Final Amount -->
            <div class="stat-card bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-xl p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-white bg-opacity-20 rounded-xl">
                        <i class="fas fa-money-bill-wave text-2xl"></i>
                    </div>
                    <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">النهائي</span>
                </div>
                <h3 class="text-4xl font-bold mb-2"><?php echo number_format($total_final, 0, ',', '.'); ?></h3>
                <p class="text-green-100 text-sm">المبلغ النهائي (ر.ي)</p>
            </div>
        </div>

        <!-- Data Table -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b-2 border-amber-500">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-list ml-2 text-amber-500"></i>
                    تفاصيل الخصومات
                </h2>
            </div>

            <div class="data-table overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gradient-to-r from-amber-500 to-orange-500 text-white">
                        <tr>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">رقم الطلب</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">كود الكوبون</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">التاريخ</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">العميل</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">المبلغ الأصلي</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">الخصم</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">نسبة الخصم</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">المبلغ النهائي</th>
                            <th class="px-6 py-4 text-right text-sm font-bold whitespace-nowrap">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($discounts)): ?>
                            <tr>
                                <td colspan="9" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center text-gray-400">
                                        <i class="fas fa-inbox text-6xl mb-4"></i>
                                        <p class="text-lg font-medium">لا توجد خصومات تطابق معايير البحث</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($discounts as $discount):
                                // Use the pre-calculated display_discount_percentage from the SQL query
                                $display_discount_percentage = floatval($discount['display_discount_percentage'] ?? 0);
                                
                                // Status translation and colors
                                $status_config = [
                                    'new' => ['label' => 'جديد', 'color' => 'bg-blue-100 text-blue-800'],
                                    'approved' => ['label' => 'موافق عليه', 'color' => 'bg-green-100 text-green-800'],
                                    'in_preparation' => ['label' => 'قيد التحضير', 'color' => 'bg-yellow-100 text-yellow-800'],
                                    'shipped' => ['label' => 'تم الشحن', 'color' => 'bg-purple-100 text-purple-800'],
                                    'completed' => ['label' => 'مكتمل', 'color' => 'bg-emerald-100 text-emerald-800'],
                                    'cancelled' => ['label' => 'ملغي', 'color' => 'bg-red-100 text-red-800']
                                ];
                                $status = $status_config[$discount['status']] ?? ['label' => $discount['status'], 'color' => 'bg-gray-100 text-gray-800'];
                                ?>
                                <tr class="hover:bg-amber-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            class="font-bold text-gray-900"><?php echo htmlspecialchars($discount['order_number']); ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="font-semibold text-gray-700 bg-gray-100 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($discount['coupon_code'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                        <?php echo date('Y-m-d', strtotime($discount['order_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-700">
                                        <?php echo htmlspecialchars($discount['customer_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-900 font-medium">
                                        <?php echo number_format($discount['subtotal_amount'], 0, ',', '.'); ?> ر.ي
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-red-600 font-bold">
                                            <?php echo number_format($discount['total_discount_amount'], 0, ',', '.'); ?> ر.ي
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($display_discount_percentage > 0.01): ?>
                                            <span
                                                class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-amber-100 text-amber-800">
                                                <?php echo number_format($display_discount_percentage, 2, ',', '.'); ?>%
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-green-600 font-bold">
                                            <?php echo number_format($discount['final_amount'], 0, ',', '.'); ?> ر.ي
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo $status['color']; ?>">
                                            <?php echo $status['label']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Totals Row -->
                            <tr class="bg-gradient-to-r from-amber-50 to-orange-50 font-bold border-t-4 border-amber-500">
                                <td colspan="4" class="px-6 py-4 text-gray-900 text-lg">الإجمالي</td>
                                <td class="px-6 py-4 text-gray-900">
                                    <?php echo number_format($total_subtotal, 0, ',', '.'); ?> ر.ي</td>
                                <td class="px-6 py-4 text-red-600">
                                    <?php echo number_format($total_discount, 0, ',', '.'); ?> ر.ي</td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-amber-200 text-amber-900">
                                        <?php echo number_format($avg_discount_percentage, 2, ',', '.'); ?>%
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-green-600"><?php echo number_format($total_final, 0, ',', '.'); ?>
                                    ر.ي</td>
                                <td class="px-6 py-4">-</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>