<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Permission guard
if (!hasPermission($_SESSION['user_id'], 'expenses', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل المصروفات';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل المصروف';
$error_message = '';
$success_message = '';

// Validate ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$expense_id = (int) $_GET['id'];

// Fetch categories
$categories = $db->query("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing expense
try {
    $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء تحميل بيانات المصروف: ' . $e->getMessage();
}

// Initialize form values from DB
$form = [
    'expense_date'   => $expense['expense_date'] ?? date('Y-m-d'),
    'category_id'    => $expense['category_id'] ?? '',
    'amount'         => $expense['amount'] ?? 0,
    'currency'       => $expense['currency'] ?? 'YER',
    'payment_method' => $expense['payment_method'] ?? 'cash',
    'vendor_name'    => $expense['vendor_name'] ?? '',
    'description'    => $expense['description'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
    $form['expense_date']   = !empty($_POST['expense_date']) ? $_POST['expense_date'] : date('Y-m-d');
    $form['category_id']    = $_POST['category_id'] ?? '';
    $form['amount']         = (float) ($_POST['amount'] ?? 0);
    $form['currency']       = $_POST['currency'] ?? 'YER';
    $form['payment_method'] = $_POST['payment_method'] ?? 'cash';
    $form['vendor_name']    = trim($_POST['vendor_name'] ?? '');
    $form['description']    = trim($_POST['description'] ?? '');

    // Basic validation
    if (empty($form['category_id'])) {
        $error_message = 'يرجى اختيار فئة المصروف';
    } elseif ($form['amount'] <= 0) {
        $error_message = 'يرجى إدخال مبلغ صحيح';
    } else {
        try {
            $update = $db->prepare("UPDATE expenses SET 
                    expense_date   = ?,
                    category_id    = ?,
                    description    = ?,
                    amount         = ?,
                    currency       = ?,
                    payment_method = ?,
                    vendor_name    = ?,
                    updated_at     = NOW()
                WHERE id = ?");

            $update->execute([
                $form['expense_date'],
                $form['category_id'],
                $form['description'],
                $form['amount'],
                $form['currency'],
                $form['payment_method'],
                $form['vendor_name'],
                $expense_id,
            ]);

            $success_message = 'تم تحديث المصروف بنجاح';

            // Refresh original record
            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
            $stmt->execute([$expense_id]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تحديث المصروف: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-3xl mx-auto px-4">
        <!-- Header -->
        <div class="mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-edit text-blue-600"></i>
                    تعديل المصروف
                </h1>
                <p class="text-gray-600 mt-1 text-sm">
                    رقم المصروف:
                    <span class="font-mono font-semibold text-gray-800"><?php echo htmlspecialchars($expense['expense_number'] ?? ''); ?></span>
                </p>
            </div>
            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2 rounded-lg font-bold transition-colors text-sm flex items-center gap-2">
                <i class="fas fa-arrow-right"></i>
                العودة للقائمة
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="p-6 sm:p-8">
                <form method="POST" action="" class="space-y-6">
                    <!-- Date & Category -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">تاريخ المصروف <span class="text-red-500">*</span></label>
                            <input
                                type="date"
                                name="expense_date"
                                value="<?php echo htmlspecialchars(substr($form['expense_date'], 0, 10)); ?>"
                                required
                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">الفئة <span class="text-red-500">*</span></label>
                            <select
                                name="category_id"
                                required
                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                            >
                                <option value="">اختر الفئة...</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($form['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Amount / Currency / Payment Method -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">المبلغ <span class="text-red-500">*</span></label>
                            <input
                                type="number"
                                name="amount"
                                step="0.01"
                                min="0"
                                value="<?php echo htmlspecialchars($form['amount']); ?>"
                                required
                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">العملة <span class="text-red-500">*</span></label>
                            <select
                                name="currency"
                                required
                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                            >
                                <option value="YER" <?php echo $form['currency'] === 'YER' ? 'selected' : ''; ?>>ريال يمني (YER)</option>
                                <option value="SAR" <?php echo $form['currency'] === 'SAR' ? 'selected' : ''; ?>>ريال سعودي (SAR)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">طريقة الدفع</label>
                            <select
                                name="payment_method"
                                class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                            >
                                <option value="cash" <?php echo $form['payment_method'] === 'cash' ? 'selected' : ''; ?>>نقدي</option>
                                <option value="bank_transfer" <?php echo $form['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>>تحويل بنكي</option>
                                <option value="card" <?php echo $form['payment_method'] === 'card' ? 'selected' : ''; ?>>بطاقة</option>
                                <option value="check" <?php echo $form['payment_method'] === 'check' ? 'selected' : ''; ?>>شيك</option>
                            </select>
                        </div>
                    </div>

                    <!-- Vendor Name -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">اسم المستفيد / المورد</label>
                        <input
                            type="text"
                            name="vendor_name"
                            value="<?php echo htmlspecialchars($form['vendor_name']); ?>"
                            placeholder="اسم الشخص أو الجهة المستفيدة"
                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                        >
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">التفاصيل / الملاحظات</label>
                        <textarea
                            name="description"
                            rows="4"
                            placeholder="أدخل تفاصيل المصروف..."
                            class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all text-sm"
                        ><?php echo htmlspecialchars($form['description']); ?></textarea>
                    </div>

                    <!-- Buttons -->
                    <div class="flex flex-col sm:flex-row gap-3 mt-4">
                        <button
                            type="submit"
                            class="flex-1 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-3 rounded-lg font-bold shadow-lg transform hover:-translate-y-0.5 transition-all duration-200 text-sm sm:text-base flex items-center justify-center gap-2"
                        >
                            <i class="fas fa-save"></i>
                            حفظ التغييرات
                        </button>
                        <a
                            href="view.php?id=<?php echo $expense_id; ?>"
                            class="flex-1 bg-gray-100 text-gray-800 px-6 py-3 rounded-lg font-semibold hover:bg-gray-200 border border-gray-300 text-sm sm:text-base flex items-center justify-center gap-2"
                        >
                            <i class="fas fa-eye"></i>
                            عرض المصروف
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
