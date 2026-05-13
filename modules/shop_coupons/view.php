<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Permission check
if (!hasPermission($_SESSION['user_id'], 'shop_coupons', 'view')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    header('Location: ../../index.php');
    exit();
}

$canEdit = hasPermission($_SESSION['user_id'], 'shop_coupons', 'edit');

$coupon_id = intval($_GET['id'] ?? 0);
if (!$coupon_id) {
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM shop_coupons WHERE id = ?");
$stmt->execute([$coupon_id]);
$coupon = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$coupon) {
    header('Location: index.php');
    exit();
}

$page_title = 'عرض كوبون المتجر';

// Resolve display values
$discount_type_db = $coupon['discount_type'] ?? 'fixed';
$discount_value   = floatval($coupon['discount_value'] ?? 0);
$min_order        = floatval($coupon['min_order_amount'] ?? 0);
$max_discount     = isset($coupon['max_discount_amount']) ? floatval($coupon['max_discount_amount']) : null;
$usage_limit      = $coupon['usage_limit'] ?? null;
$per_customer     = $coupon['user_usage_limit'] ?? null;
$used_count       = intval($coupon['usage_count'] ?? 0);

$start_date = !empty($coupon['start_date']) ? date('Y-m-d', strtotime($coupon['start_date'])) : '-';
$end_date   = !empty($coupon['end_date'])   ? date('Y-m-d', strtotime($coupon['end_date']))   : '-';

$today          = date('Y-m-d');
$is_active_flag = (bool)($coupon['is_active'] ?? 0);
$within_dates   = ($start_date === '-' || $end_date === '-')
                    ? true
                    : ($today >= $start_date && $today <= $end_date);
$is_active      = $is_active_flag && $within_dates;

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4">
        <div class="bg-white shadow rounded-lg overflow-hidden">

            <!-- Header Bar -->
            <div class="px-6 py-4 border-b bg-gradient-to-r from-emerald-600 to-teal-700 text-white">
                <div class="flex justify-between items-center">
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <i class="fas fa-store"></i>
                        تفاصيل كوبون المتجر:
                        <span class="font-mono bg-white bg-opacity-20 px-3 py-1 rounded text-lg">
                            <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                        </span>
                    </h1>
                    <div class="flex gap-2">
                        <?php if ($canEdit): ?>
                        <a href="edit.php?id=<?php echo $coupon_id; ?>"
                           class="px-4 py-2 bg-white text-emerald-700 rounded-lg hover:bg-emerald-50 font-semibold text-sm">
                            <i class="fas fa-edit ml-1"></i>تعديل
                        </a>
                        <?php endif; ?>
                        <a href="index.php"
                           class="px-4 py-2 bg-white bg-opacity-20 text-white rounded-lg hover:bg-opacity-30 font-semibold text-sm">
                            <i class="fas fa-arrow-right ml-1"></i>العودة
                        </a>
                    </div>
                </div>
            </div>

            <!-- Details -->
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <!-- Coupon Name -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">اسم الكوبون</p>
                        <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($coupon['coupon_name'] ?? 'غير محدد'); ?></p>
                    </div>

                    <!-- Coupon Code -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">كود الكوبون</p>
                        <span class="font-mono bg-purple-100 text-purple-800 px-3 py-1 rounded font-bold text-lg">
                            <?php echo htmlspecialchars($coupon['coupon_code']); ?>
                        </span>
                    </div>

                    <!-- Discount Type & Value -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">نوع الخصم</p>
                        <p class="font-semibold text-gray-800">
                            <?php echo $discount_type_db === 'percentage' ? 'نسبة مئوية' : 'مبلغ ثابت'; ?>
                        </p>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">قيمة الخصم</p>
                        <p class="font-bold text-emerald-700 text-xl">
                            <?php if ($discount_type_db === 'percentage'): ?>
                                <?php echo number_format($discount_value, 1); ?>%
                            <?php else: ?>
                                <?php echo number_format($discount_value, 2); ?> ريال
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Min Order -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">الحد الأدنى للطلب</p>
                        <p class="font-semibold text-gray-800"><?php echo number_format($min_order, 2); ?> ريال</p>
                    </div>

                    <!-- Max Discount -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">الحد الأقصى للخصم</p>
                        <p class="font-semibold text-gray-800">
                            <?php echo $max_discount !== null ? number_format($max_discount, 2) . ' ريال' : 'غير محدود'; ?>
                        </p>
                    </div>

                    <!-- Usage -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">عدد الاستخدامات</p>
                        <p class="font-semibold text-gray-800">
                            <?php if ($usage_limit): ?>
                                <?php echo $used_count; ?> / <?php echo intval($usage_limit); ?>
                            <?php else: ?>
                                <?php echo $used_count; ?> (غير محدود)
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Per-customer Limit -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">حد الاستخدام لكل عميل</p>
                        <p class="font-semibold text-gray-800">
                            <?php echo $per_customer ? intval($per_customer) . ' مرة' : 'غير محدود'; ?>
                        </p>
                    </div>

                    <!-- Validity -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">تاريخ البداية</p>
                        <p class="font-semibold text-gray-800"><?php echo $start_date; ?></p>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">تاريخ الانتهاء</p>
                        <p class="font-semibold text-gray-800"><?php echo $end_date; ?></p>
                    </div>

                    <!-- Status -->
                    <div class="bg-gray-50 p-4 rounded-lg md:col-span-2">
                        <p class="text-xs text-gray-400 mb-1">الحالة</p>
                        <?php if ($is_active): ?>
                            <span class="px-3 py-1 inline-flex text-sm font-bold rounded-full bg-green-100 text-green-700">
                                <i class="fas fa-check-circle ml-1"></i> نشط
                            </span>
                        <?php else: ?>
                            <span class="px-3 py-1 inline-flex text-sm font-bold rounded-full bg-gray-100 text-gray-600">
                                <i class="fas fa-times-circle ml-1"></i> متوقف
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Timestamps -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">تاريخ الإنشاء</p>
                        <p class="text-sm text-gray-600"><?php echo $coupon['created_at'] ?? '-'; ?></p>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-400 mb-1">آخر تعديل</p>
                        <p class="text-sm text-gray-600"><?php echo $coupon['updated_at'] ?? '-'; ?></p>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
