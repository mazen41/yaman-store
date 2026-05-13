<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';

// Check permission
if (!hasPermission($_SESSION['user_id'], 'purchase_cards', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية للتعديل';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل بطاقات الشراء';
$id = intval($_GET['id'] ?? 0);

// Fetch item
try {
    $stmt = $db->prepare("SELECT * FROM purchase_cards WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        $_SESSION['error_message'] = 'العنصر غير موجود';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'حدث خطأ: ' . $e->getMessage();
    header('Location: index.php');
    exit();
}

// Prepare default values for form fields
$card_number = $item['card_number'] ?? '';
$card_name   = $item['card_name'] ?? '';
// مبلغ شراء البطاقة (ما تم دفعه لشراء الكرت)
$card_purchase_amount = $item['card_purchase_amount'] ?? 0;
// الرصيد المتاح للشراء (إن لم يوجد العمود نستخدم balance + purchase_amount كتقدير)
$initial_balance = $item['initial_balance'] ?? ($item['balance'] + $item['purchase_amount']);
// مبلغ المشتريات الحالي محفوظ في الحقل purchase_amount ولا يعدل من الفورم
$current_purchase_amount = $item['purchase_amount'] ?? 0;

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $card_number      = trim($_POST['card_number'] ?? '');
    $card_name        = trim($_POST['card_name'] ?? '');
    $card_purchase_amount_input = $_POST['card_purchase_amount'] ?? '';
    $initial_balance_input      = $_POST['initial_balance'] ?? '';

    $errors = [];

    if ($card_number === '') {
        $errors[] = 'يرجى إدخال رقم البطاقة';
    }
    if ($card_name === '') {
        $errors[] = 'يرجى إدخال اسم البطاقة';
    }

    $card_purchase_amount_value = floatval($card_purchase_amount_input);
    if ($card_purchase_amount_input === '' || $card_purchase_amount_value <= 0) {
        $errors[] = 'يرجى إدخال مبلغ شراء البطاقة بقيمة أكبر من صفر';
    }

    $initial_balance_value = floatval($initial_balance_input);
    if ($initial_balance_input === '' || $initial_balance_value < 0) {
        $errors[] = 'يرجى إدخال الرصيد المتاح للشراء بقيمة صحيحة (يمكن أن يساوي مبلغ الشراء أو أقل)';
    }

    // Ensure card_number uniqueness (excluding current record)
    if (empty($errors)) {
        try {
            $check_stmt = $db->prepare('SELECT COUNT(*) FROM purchase_cards WHERE card_number = ? AND id <> ?');
            $check_stmt->execute([$card_number, $id]);
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = 'رقم البطاقة مستخدم من قبل، يرجى اختيار رقم آخر';
            }
        } catch (PDOException $e) {
            $errors[] = 'حدث خطأ أثناء التحقق من رقم البطاقة';
        }
    }

    if (empty($errors)) {
        try {
            // نحافظ على مبلغ المشتريات الحالي، ونحدّث الرصيد المتاح والرصيد الحالي
            $card_purchase_amount = $card_purchase_amount_value;
            $initial_balance      = $initial_balance_value;
            $new_balance          = $initial_balance_value - $current_purchase_amount;

            $update_stmt = $db->prepare('UPDATE purchase_cards SET card_number = ?, card_name = ?, card_purchase_amount = ?, initial_balance = ?, balance = ? WHERE id = ?');
            $update_stmt->execute([
                $card_number,
                $card_name,
                $card_purchase_amount,
                $initial_balance,
                $new_balance,
                $id,
            ]);

            $_SESSION['success_message'] = 'تم التعديل بنجاح';
            header('Location: index.php');
            exit();
        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء حفظ التعديلات: ' . $e->getMessage();
        }
    } else {
        $error_message = implode(' • ', $errors);
    }
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8" dir="rtl">
    <div class="bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">تعديل بطاقات الشراء</h1>

        <?php if (!empty($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <!-- Card Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">رقم البطاقة <span class="text-red-500">*</span></label>
                <input type="text" name="card_number" value="<?php echo htmlspecialchars($card_number); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
            </div>

            <!-- Card Name -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">اسم البطاقة <span class="text-red-500">*</span></label>
                <input type="text" name="card_name" value="<?php echo htmlspecialchars($card_name); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
            </div>

            <!-- Card Purchase Amount (مبلغ شراء البطاقة) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">مبلغ شراء البطاقة (ر.ي) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" name="card_purchase_amount" 
                       value="<?php echo htmlspecialchars($card_purchase_amount); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                <p class="text-xs text-gray-500 mt-1">المبلغ الذي تم دفعه لشراء هذه البطاقة.</p>
            </div>

            <!-- Initial Balance (الرصيد المتاح للشراء) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">الرصيد المتاح للشراء (ر.ي) <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" name="initial_balance" 
                       value="<?php echo htmlspecialchars($initial_balance); ?>" 
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                <p class="text-xs text-gray-500 mt-1">تعديل هذا الرصيد سيقوم بإعادة احتساب الرصيد الحالي (الرصيد المتاح - مبلغ المشتريات).</p>
            </div>

            <div class="flex gap-4">
                <button type="submit" class="flex-1 bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 font-semibold">
                    <i class="fas fa-save ml-2"></i>
                    حفظ التعديلات
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