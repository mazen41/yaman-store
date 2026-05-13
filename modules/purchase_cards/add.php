<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'purchase_cards', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للإضافة';
    header('Location: index.php');
    exit();
}

$page_title = 'إضافة بطاقات الشراء';
$success_message = '';
$error_message = '';

// Keep simple local variables to repopulate the form on validation errors
$card_number = trim($_POST['card_number'] ?? '');
$card_name = trim($_POST['card_name'] ?? '');
$card_purchase_amount = $_POST['card_purchase_amount'] ?? '';
$initial_balance = $_POST['initial_balance'] ?? '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $errors = [];

    // Basic validation
    if ($card_number === '') {
        $errors[] = 'يرجى إدخال رقم البطاقة';
    }
    if ($card_name === '') {
        $errors[] = 'يرجى إدخال اسم البطاقة';
    }

    $card_purchase_amount_value = floatval($card_purchase_amount);
    if ($card_purchase_amount === '' || $card_purchase_amount_value <= 0) {
        $errors[] = 'يرجى إدخال مبلغ شراء البطاقة بقيمة أكبر من صفر';
    }

    $initial_balance_value = floatval($initial_balance);
    if ($initial_balance === '' || $initial_balance_value < 0) {
        $errors[] = 'يرجى إدخال الرصيد المتاح للشراء (يمكن أن يساوي مبلغ الشراء أو أقل)';
    }

    if (empty($errors)) {
        try {
            // Ensure card_number is unique
            $check_stmt = $db->prepare('SELECT COUNT(*) FROM purchase_cards WHERE card_number = ?');
            $check_stmt->execute([$card_number]);
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = 'رقم البطاقة مستخدم من قبل، يرجى اختيار رقم آخر';
            }
        } catch (PDOException $e) {
            $errors[] = 'حدث خطأ أثناء التحقق من رقم البطاقة';
        }
    }

    if (!empty($errors)) {
        $error_message = implode(' • ', $errors);
    } else {
        try {
            // Insert new purchase card:
            // - card_purchase_amount = مبلغ شراء البطاقة
            // - initial_balance     = الرصيد المتاح للشراء
            // - purchase_amount     = 0 (يتم تحديثه عند استخدام البطاقة في الشراء)
            // - balance             = initial_balance (الرصيد الحالي، يتناقص مع كل عملية شراء)
            $stmt = $db->prepare("INSERT INTO purchase_cards (card_number, card_name, card_purchase_amount, initial_balance, purchase_amount, balance, created_at) VALUES (?, ?, ?, ?, 0, ?, NOW())");
            $stmt->execute([
                $card_number,
                $card_name,
                $card_purchase_amount_value,
                $initial_balance_value,
                $initial_balance_value
            ]);

            $success_message = 'تم إضافة البطاقة بنجاح';

            // Reset form fields after success
            $card_number = '';
            $card_name = '';
            $card_purchase_amount = '';
            $initial_balance = '';
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء حفظ البطاقة: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">إضافة بطاقات الشراء</h1>

        <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <!-- Card Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">رقم البطاقة <span class="text-red-500">*</span></label>
                <input type="text" name="card_number" value="<?php echo htmlspecialchars($card_number); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                       placeholder="مثال: PC-0001" required>
            </div>

            <!-- Card Name -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">اسم البطاقة <span class="text-red-500">*</span></label>
                <input type="text" name="card_name" value="<?php echo htmlspecialchars($card_name); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                       placeholder="مثال: بطاقة مشتريات المورد فلان" required>
            </div>

            <!-- Card Purchase Amount (مبلغ شراء البطاقة) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">مبلغ شراء البطاقة (ر.ي) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" name="card_purchase_amount" 
                       value="<?php echo htmlspecialchars($card_purchase_amount); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                       placeholder="0.00" required>
                <p class="text-xs text-gray-500 mt-1">المبلغ الذي تم دفعه لشراء هذه البطاقة.</p>
            </div>

            <!-- Initial Balance (الرصيد المتاح للشراء) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">الرصيد المتاح للشراء (ر.ي) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" name="initial_balance" 
                       value="<?php echo htmlspecialchars($initial_balance); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" 
                       placeholder="0.00" required>
                <p class="text-xs text-gray-500 mt-1">هذا هو الرصيد المتاح للاستخدام في الشراء (يمكن أن يساوي مبلغ شراء البطاقة أو أقل).</p>
            </div>

            <div class="flex gap-4">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-semibold">
                    <i class="fas fa-save ml-2"></i>
                    حفظ
                </button>
                <a href="index.php" class="flex-1 text-center bg-gray-200 text-gray-700 px-6 py-3 rounded-lg hover:bg-gray-300 font-semibold">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>