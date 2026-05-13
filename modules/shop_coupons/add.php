<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'shop_coupons', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للإضافة';
    header('Location: index.php');
    exit();
}

$page_title      = 'إضافة كوبون متجر';
$success_message = '';
$error_message   = '';

// Default form values
$form = [
    'coupon_name'         => '',
    'code'                => '',
    'discount_type'       => 'percent',
    'discount_value'      => '',
    'min_order_amount'    => '0.00',
    'max_discount_amount' => '',
    'usage_limit'         => '',
    'user_usage_limit'    => '',
    'valid_from'          => date('Y-m-d'),
    'valid_to'            => date('Y-m-d', strtotime('+30 days')),
    'active'              => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read form data
    $form['coupon_name']         = trim($_POST['coupon_name']         ?? '');
    $form['code']                = trim($_POST['code']                ?? '');
    $form['discount_type']       = $_POST['discount_type']            ?? 'percent';
    $form['discount_value']      = trim($_POST['discount_value']      ?? '');
    $form['min_order_amount']    = trim($_POST['min_order_amount']    ?? '0');
    $form['max_discount_amount'] = trim($_POST['max_discount_amount'] ?? '');
    $form['usage_limit']         = trim($_POST['usage_limit']         ?? '');
    $form['user_usage_limit']    = trim($_POST['user_usage_limit']    ?? '');
    $form['valid_from']          = $_POST['valid_from']               ?? '';
    $form['valid_to']            = $_POST['valid_to']                 ?? '';
    $form['active']              = isset($_POST['active']) ? 1 : 0;

    // Validation
    $errors = [];

    if ($form['coupon_name'] === '') {
        $errors[] = 'يرجى إدخال اسم الكوبون';
    }
    if ($form['code'] === '') {
        $errors[] = 'يرجى إدخال كود الكوبون';
    }
    if (!in_array($form['discount_type'], ['percent', 'amount'], true)) {
        $errors[] = 'نوع الخصم غير صالح';
    }
    if ($form['discount_value'] === '' || !is_numeric($form['discount_value']) || floatval($form['discount_value']) <= 0) {
        $errors[] = 'يرجى إدخال قيمة خصم صحيحة';
    }
    if ($form['min_order_amount'] !== '' && !is_numeric($form['min_order_amount'])) {
        $errors[] = 'الحد الأدنى للطلب يجب أن يكون رقمًا';
    }
    if ($form['max_discount_amount'] !== '' && !is_numeric($form['max_discount_amount'])) {
        $errors[] = 'الحد الأقصى للخصم يجب أن يكون رقمًا';
    }
    if ($form['usage_limit'] !== '' && !ctype_digit($form['usage_limit'])) {
        $errors[] = 'عدد الاستخدامات يجب أن يكون رقمًا صحيحًا';
    }
    if ($form['user_usage_limit'] !== '' && !ctype_digit($form['user_usage_limit'])) {
        $errors[] = 'عدد الاستخدامات لكل عميل يجب أن يكون رقمًا صحيحًا';
    }
    if (empty($form['valid_from']) || empty($form['valid_to'])) {
        $errors[] = 'يرجى تحديد فترة الصلاحية';
    } elseif (strtotime($form['valid_from']) > strtotime($form['valid_to'])) {
        $errors[] = 'تاريخ البداية يجب أن يكون قبل تاريخ الانتهاء';
    }

    if (empty($errors)) {
        try {
            // Map form discount_type → DB enum
            $discount_type_db = $form['discount_type'] === 'percent' ? 'percentage' : 'fixed';

            $stmt = $db->prepare("
                INSERT INTO shop_coupons
                    (coupon_name, coupon_code, discount_type, discount_value,
                     min_order_amount, max_discount_amount, usage_limit, user_usage_limit,
                     usage_count, start_date, end_date, is_active, created_at, updated_at)
                VALUES
                    (:coupon_name, :coupon_code, :discount_type, :discount_value,
                     :min_order_amount, :max_discount_amount, :usage_limit, :user_usage_limit,
                     0, :start_date, :end_date, :is_active, NOW(), NOW())
            ");

            $stmt->execute([
                ':coupon_name'         => $form['coupon_name'],
                ':coupon_code'         => $form['code'],
                ':discount_type'       => $discount_type_db,
                ':discount_value'      => (float) $form['discount_value'],
                ':min_order_amount'    => $form['min_order_amount'] === '' ? 0 : (float) $form['min_order_amount'],
                ':max_discount_amount' => $form['max_discount_amount'] === '' ? null : (float) $form['max_discount_amount'],
                ':usage_limit'         => $form['usage_limit'] === '' ? null : (int) $form['usage_limit'],
                ':user_usage_limit'    => $form['user_usage_limit'] === '' ? null : (int) $form['user_usage_limit'],
                ':start_date'          => $form['valid_from'],
                ':end_date'            => $form['valid_to'],
                ':is_active'           => $form['active'],
            ]);

            $success_message      = 'تم إضافة كوبون المتجر بنجاح';
            // Reset fields after success
            $form['coupon_name']  = '';
            $form['code']         = '';
            $form['discount_value'] = '';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error_message = 'كود الكوبون مستخدم مسبقًا، يرجى اختيار كود آخر';
            } else {
                $error_message = 'حدث خطأ أثناء حفظ الكوبون: ' . $e->getMessage();
            }
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">

        <div class="flex items-center gap-3 mb-6">
            <a href="index.php" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-arrow-right"></i>
            </a>
            <h1 class="text-2xl font-bold text-gray-900">إضافة كوبون متجر</h1>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-check-circle ml-2"></i><?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-exclamation-circle ml-2"></i><?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Coupon Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">اسم الكوبون <span class="text-red-500">*</span></label>
                    <input type="text" name="coupon_name" value="<?php echo htmlspecialchars($form['coupon_name']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500" required>
                </div>

                <!-- Coupon Code -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">كود الكوبون <span class="text-red-500">*</span></label>
                    <input type="text" name="code" value="<?php echo htmlspecialchars($form['code']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 font-mono uppercase"
                           placeholder="مثال: SHOP20" required>
                </div>

                <!-- Discount Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">نوع الخصم <span class="text-red-500">*</span></label>
                    <select name="discount_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                        <option value="percent" <?php echo $form['discount_type'] === 'percent' ? 'selected' : ''; ?>>نسبة مئوية %</option>
                        <option value="amount"  <?php echo $form['discount_type'] === 'amount'  ? 'selected' : ''; ?>>مبلغ ثابت</option>
                    </select>
                </div>

                <!-- Discount Value -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">قيمة الخصم <span class="text-red-500">*</span></label>
                    <input type="number" step="0.01" min="0" name="discount_value"
                           value="<?php echo htmlspecialchars($form['discount_value']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500" required>
                </div>

                <!-- Min Order Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأدنى لمبلغ الطلب</label>
                    <input type="number" step="0.01" min="0" name="min_order_amount"
                           value="<?php echo htmlspecialchars($form['min_order_amount']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500">
                </div>

                <!-- Max Discount Amount -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">الحد الأقصى لمبلغ الخصم</label>
                    <input type="number" step="0.01" min="0" name="max_discount_amount"
                           value="<?php echo htmlspecialchars($form['max_discount_amount']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500"
                           placeholder="اختياري">
                </div>

                <!-- Usage Limit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">عدد الاستخدامات المسموح بها</label>
                    <input type="number" min="1" name="usage_limit"
                           value="<?php echo htmlspecialchars($form['usage_limit']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500"
                           placeholder="اتركه فارغًا ليكون غير محدود">
                </div>

                <!-- Per-customer Limit -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">عدد المسموح للعميل استخدام الكوبون</label>
                    <input type="number" min="1" name="user_usage_limit"
                           value="<?php echo htmlspecialchars($form['user_usage_limit']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500"
                           placeholder="اتركه فارغًا ليكون غير محدود لكل عميل">
                </div>

                <!-- Start Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">تاريخ بداية الصلاحية <span class="text-red-500">*</span></label>
                    <input type="date" name="valid_from" value="<?php echo htmlspecialchars($form['valid_from']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500" required>
                </div>

                <!-- End Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">تاريخ نهاية الصلاحية <span class="text-red-500">*</span></label>
                    <input type="date" name="valid_to" value="<?php echo htmlspecialchars($form['valid_to']); ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500" required>
                </div>

                <!-- Active Toggle -->
                <div class="md:col-span-2 flex items-center mt-2">
                    <input type="checkbox" id="active" name="active" value="1"
                           class="h-4 w-4 text-emerald-600 border-gray-300 rounded"
                           <?php echo $form['active'] ? 'checked' : ''; ?>>
                    <label for="active" class="mr-2 text-sm text-gray-700">الكوبون نشط</label>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-4 mt-6">
                <button type="submit" class="flex-1 bg-emerald-600 text-white px-6 py-3 rounded-lg hover:bg-emerald-700 font-semibold">
                    <i class="fas fa-save ml-2"></i>حفظ
                </button>
                <a href="index.php" class="flex-1 text-center bg-gray-200 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-300 font-semibold">
                    <i class="fas fa-times ml-2"></i>إلغاء
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
