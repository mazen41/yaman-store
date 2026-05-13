<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'coupons', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

// Get user's permissions
$can_add = hasPermission($_SESSION['user_id'], 'coupons', 'add');
$can_edit = hasPermission($_SESSION['user_id'], 'coupons', 'edit');

$page_title = 'الكوبونات';

// Fetch all data
try {
    $stmt = $db->query("SELECT * FROM coupons ORDER BY id DESC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
    $error_message = 'حدث خطأ: ' . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold flex items-center gap-3">
                    <i class="fas fa-list"></i>
                    الكوبونات
                </h1>
                <p class="text-blue-100 mt-2">إدارة الكوبونات</p>
            </div>
            <?php if (hasPermission($_SESSION['user_id'], 'coupons', 'add')): ?>
            <a href="add.php" class="bg-white text-blue-600 px-6 py-3 rounded-lg hover:bg-blue-50 font-semibold transition">
                <i class="fas fa-plus ml-2"></i>
                إضافة جديد
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">اسم الكوبون</th> <!-- Added coupon_name header -->
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">كود الكوبون</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">نوع الخصم</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحد الأدنى للطلب</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحد الأقصى للخصم</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عدد الاستخدامات</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">حد الاستخدام لكل عميل</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">فترة الصلاحية</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">الحالة</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="11" class="px-6 py-12 text-center text-gray-500"> <!-- colspan adjusted for new column -->
                            <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                            <p>لا توجد بيانات</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $index => $item): ?>
                        <?php
                            // Map DB fields to display values (support old + new schema)
                            $discount_type_db = $item['discount_type'] ?? 'fixed_amount';
                            $discount_value = floatval($item['discount_value'] ?? 0);
                            $min_order = floatval($item['min_order_amount'] ?? 0);
                            $max_discount = isset($item['max_discount_amount']) ? floatval($item['max_discount_amount']) : null;
                            $usage_limit = $item['usage_limit'] ?? null;
                            $per_customer_limit = $item['user_usage_limit'] ?? null;

                            // usage_count (new) or used_count (old)
                            $used_count = isset($item['usage_count'])
                                ? intval($item['usage_count'])
                                : intval($item['used_count'] ?? 0);

                            // Dates: prefer start_date/end_date, fallback to valid_from/valid_to
                            $start_raw = $item['start_date'] ?? $item['valid_from'] ?? null;
                            $end_raw   = $item['end_date']   ?? $item['valid_to']   ?? null;

                            $start_date = !empty($start_raw) ? date('Y-m-d', strtotime($start_raw)) : '-';
                            $end_date   = !empty($end_raw)   ? date('Y-m-d', strtotime($end_raw))   : '-';

                            // Determine if coupon is currently active
                            $today = date('Y-m-d');
                            // is_active (new) or active (old)
                            $is_active_flag = isset($item['is_active'])
                                ? (bool)$item['is_active']
                                : (!empty($item['active']));

                            // If لا توجد تواريخ محفوظة نعتبر فقط is_active_flag
                            if ($start_date === '-' || $end_date === '-') {
                                $within_dates = true;
                            } else {
                                $within_dates = ($today >= $start_date && $today <= $end_date);
                            }

                            $is_active = $is_active_flag && $within_dates;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <!-- # -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $index + 1; ?></td>
                            <!-- Coupon Name -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['coupon_name'] ?? ''); ?></td> <!-- Display coupon_name -->
                            <!-- Code -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($item['coupon_code'] ?? ''); ?></td>
                            <!-- Discount type/value -->
                           <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
    <?php if ($discount_type_db === 'percentage' || $discount_type_db === 'percent'): ?>
        <span class="text-emerald-700 font-bold"><?php echo number_format($discount_value, 1, '.', ','); ?>%</span>
    <?php else: ?>
        <span class="text-emerald-700 font-bold"><?php echo number_format($discount_value, 2, '.', ','); ?></span>
    <?php endif; ?>
</td>
<!-- Min order -->
<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?php echo number_format($min_order, 2, '.', ','); ?></td>
<!-- Max discount -->
<td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
    <?php echo $max_discount !== null ? number_format($max_discount, 2, '.', ',') : '-'; ?>
</td>

                            <!-- Usage -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
                                <?php if ($usage_limit): ?>
                                    <span class="font-semibold"><?php echo $used_count; ?> / <?php echo intval($usage_limit); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-500">غير محدود (<?php echo $used_count; ?> مستخدم)</span>
                                <?php endif; ?>
                            </td>
                            <!-- Per-customer limit -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
                                <?php echo $per_customer_limit ? intval($per_customer_limit) : 'غير محدود لكل عميل'; ?>
                            </td>
                            <!-- Validity -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800">
                                <div><?php echo $start_date; ?> → <?php echo $end_date; ?></div>
                            </td>
                            <!-- Status -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if ($is_active): ?>
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-green-100 text-green-700">نشط</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-gray-100 text-gray-600">متوقف</span>
                                <?php endif; ?>
                            </td>
                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <?php if (hasPermission($_SESSION['user_id'], 'coupons', 'edit')): ?>
                                <a href="edit.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-edit"></i> تعديل
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>