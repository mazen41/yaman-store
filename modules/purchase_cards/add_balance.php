<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إضافة رصيد للبطاقة';
$card_id = intval($_GET['id'] ?? 0);

if (!$card_id) {
    header('Location: index.php');
    exit();
}

// Fetch card details
try {
    $stmt = $db->prepare("SELECT * FROM purchase_cards WHERE id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$card) {
        $_SESSION['error_message'] = 'البطاقة غير موجودة';
        header('Location: index.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'حدث خطأ في جلب بيانات البطاقة';
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    if ($amount <= 0) {
        $error_message = 'يرجى إدخال مبلغ صحيح';
    } else {
        try {
            $db->beginTransaction();
            
            // Update card balance
            $new_balance = $card['balance'] + $amount;
            $update_stmt = $db->prepare("
                UPDATE purchase_cards 
                SET balance = balance + ? 
                WHERE id = ?
            ");
            $update_stmt->execute([$amount, $card_id]);
            
            // Create transaction record (use purchase_card_id column)
            $transaction_stmt = $db->prepare("
                INSERT INTO purchase_card_transactions 
                (purchase_card_id, transaction_type, amount, balance_after, notes, created_by, created_at) 
                VALUES (?, 'add_balance', ?, ?, ?, ?, NOW())
            ");
            $transaction_stmt->execute([
                $card_id,
                $amount,
                $new_balance,
                $notes,
                $_SESSION['user_id']
            ]);
            
            $db->commit();
            
            $_SESSION['success_message'] = 'تم إضافة الرصيد بنجاح';
            header('Location: view_transactions.php?id=' . $card_id);
            exit();
            
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = 'حدث خطأ أثناء إضافة الرصيد: ' . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 to-emerald-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-plus-circle mr-3"></i>
                    إضافة رصيد للبطاقة
                </h1>
                <p class="text-amber-100">إضافة رصيد إلى بطاقة: <?php echo htmlspecialchars($card['card_name']); ?></p>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Card Info -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-credit-card text-blue-600"></i>
                معلومات البطاقة
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <p class="text-sm text-gray-600">رقم البطاقة</p>
                    <p class="font-bold text-gray-900"><?php echo htmlspecialchars($card['card_number']); ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">اسم البطاقة</p>
                    <p class="font-bold text-gray-900"><?php echo htmlspecialchars($card['card_name']); ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">الرصيد الحالي</p>
                    <p class="font-bold text-amber-600 text-xl"><?php echo number_format($card['balance'], 2); ?> ر.س</p>
                </div>
            </div>
        </div>

        <!-- Add Balance Form -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-amber-50 to-emerald-50 border-b">
                <h3 class="text-lg font-bold text-gray-800">إضافة رصيد جديد</h3>
            </div>
            
            <form method="POST" class="p-6 space-y-6">
                <div>
                    <label for="amount" class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-money-bill-wave text-amber-600 mr-1"></i>
                        المبلغ المراد إضافته *
                    </label>
                    <input type="number" 
                           id="amount" 
                           name="amount" 
                           step="0.01" 
                           min="0.01"
                           required
                           class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500 text-lg font-bold"
                           placeholder="0.00">
                    <p class="text-sm text-gray-500 mt-1">أدخل المبلغ بالريال السعودي</p>
                </div>

                <div>
                    <label for="notes" class="block text-sm font-bold text-gray-700 mb-2">
                        <i class="fas fa-sticky-note text-blue-600 mr-1"></i>
                        ملاحظات (اختياري)
                    </label>
                    <textarea id="notes" 
                              name="notes" 
                              rows="3"
                              class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-amber-500"
                              placeholder="أضف ملاحظات حول هذه العملية..."></textarea>
                </div>

                <div class="bg-blue-50 border-r-4 border-blue-500 p-4 rounded-lg">
                    <p class="text-sm text-blue-800">
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>ملاحظة:</strong> سيتم إضافة المبلغ إلى الرصيد الحالي وسيظهر في تقارير البطاقة.
                    </p>
                </div>

                <div class="flex gap-4 pt-4">
                    <button type="submit" 
                            class="flex-1 bg-amber-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-amber-700 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                        <i class="fas fa-check-circle ml-2"></i>
                        إضافة الرصيد
                    </button>
                    <a href="index.php" 
                       class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-bold hover:bg-gray-300 transition-all duration-300 text-center">
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
