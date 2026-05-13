<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تحويل رصيد بين البطاقات';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_card_id = filter_input(INPUT_POST, 'from_card_id', FILTER_VALIDATE_INT);
    $to_card_id = filter_input(INPUT_POST, 'to_card_id', FILTER_VALIDATE_INT);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $notes = trim($_POST['notes'] ?? '');

    try {
        // --- Validation ---
        if (!$from_card_id || !$to_card_id || !$amount) {
            throw new Exception('يرجى ملء جميع الحقول المطلوبة.');
        }
        if ($from_card_id === $to_card_id) {
            throw new Exception('لا يمكن التحويل إلى نفس البطاقة.');
        }
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون مبلغ التحويل أكبر من صفر.');
        }

        // --- Database Transaction ---
        $db->beginTransaction();

        // 1. Get and lock the source card to prevent race conditions
        $stmt_from = $db->prepare("SELECT id, card_number, current_balance FROM loyalty_cards WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmt_from->execute([$from_card_id]);
        $from_card = $stmt_from->fetch(PDO::FETCH_ASSOC);

        if (!$from_card) {
            throw new Exception('البطاقة المصدر غير موجودة أو غير نشطة.');
        }

        // 2. Check for sufficient balance
        if ($from_card['current_balance'] < $amount) {
            throw new Exception('الرصيد في البطاقة المصدر غير كافٍ. الرصيد الحالي: ' . $from_card['current_balance']);
        }

        // 3. Get the destination card
        $stmt_to = $db->prepare("SELECT id, card_number FROM loyalty_cards WHERE id = ? AND status = 'active'");
        $stmt_to->execute([$to_card_id]);
        $to_card = $stmt_to->fetch(PDO::FETCH_ASSOC);

        if (!$to_card) {
            throw new Exception('البطاقة الهدف غير موجودة أو غير نشطة.');
        }

        // 4. Deduct amount from source card
        $new_from_balance = $from_card['current_balance'] - $amount;
        $update_from = $db->prepare("UPDATE loyalty_cards SET current_balance = ? WHERE id = ?");
        $update_from->execute([$new_from_balance, $from_card_id]);

        // 5. Add amount to destination card
        $update_to = $db->prepare("UPDATE loyalty_cards SET current_balance = current_balance + ? WHERE id = ?");
        $update_to->execute([$amount, $to_card_id]);

        // 6. Log transaction for the source card (transfer_out)
        $log_from = $db->prepare(
            "INSERT INTO loyalty_card_transactions (card_id, transaction_type, amount, balance_before, balance_after, description, transfer_to_card_id, created_by) 
             VALUES (?, 'transfer_out', ?, ?, ?, ?, ?, ?)"
        );
        $description_from = "تحويل إلى بطاقة {$to_card['card_number']}. ملاحظات: {$notes}";
        $log_from->execute([$from_card_id, $amount, $from_card['current_balance'], $new_from_balance, $description_from, $to_card_id, $_SESSION['user_id']]);

        // 7. Log transaction for the destination card (transfer_in)
        $stmt_to_balance = $db->prepare("SELECT current_balance FROM loyalty_cards WHERE id = ?");
        $stmt_to_balance->execute([$to_card_id]);
        $to_card_new_balance = $stmt_to_balance->fetchColumn();
        $to_card_old_balance = $to_card_new_balance - $amount;
        
        $log_to = $db->prepare(
            "INSERT INTO loyalty_card_transactions (card_id, transaction_type, amount, balance_before, balance_after, description, created_by) 
             VALUES (?, 'transfer_in', ?, ?, ?, ?, ?)"
        );
        $description_to = "استلام رصيد من بطاقة {$from_card['card_number']}. ملاحظات: {$notes}";
        $log_to->execute([$to_card_id, $amount, $to_card_old_balance, $to_card_new_balance, $description_to, $_SESSION['user_id']]);
        
        // 8. Commit the transaction
        $db->commit();
        
        $success_message = "تم تحويل مبلغ " . number_format($amount, 0, '', '') . " ر.ي بنجاح من بطاقة {$from_card['card_number']} إلى بطاقة {$to_card['card_number']}.";

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}


// Fetch all active cards for the dropdowns
try {
    $cards_stmt = $db->query("SELECT id, card_number, current_balance, customer_name FROM loyalty_cards WHERE status = 'active' AND is_active = 1 ORDER BY card_number ASC");
    $active_cards = $cards_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $active_cards = [];
    $error_message = "خطأ في جلب بيانات البطاقات: " . $e->getMessage();
}


include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-teal-500 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-2">
                            <i class="fas fa-random mr-3"></i>
                            <?php echo $page_title; ?>
                        </h1>
                        <p class="text-blue-100">نقل الأرصدة بين بطاقات العملاء بسهولة وأمان</p>
                    </div>
                    <a href="index.php" class="bg-white text-blue-600 px-6 py-3 rounded-lg font-bold hover:bg-blue-50 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105">
                        <i class="fas fa-arrow-right ml-2"></i>
                        العودة للإدارة
                    </a>
                </div>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-md" role="alert">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $success_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md" role="alert">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-2xl ml-3"></i>
                    <p class="font-medium"><?php echo $error_message; ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Transfer Form -->
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <form method="POST" action="transfer.php" id="transferForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
                    
                    <!-- From Card -->
                    <div class="space-y-4">
                        <h3 class="text-xl font-bold text-gray-800 border-b-2 border-blue-500 pb-2 flex items-center">
                           <i class="fas fa-arrow-circle-up text-blue-500 ml-3"></i> من البطاقة (المصدر)
                        </h3>
                        <div class="form-group">
                            <label for="from_card_id" class="block text-sm font-medium text-gray-700 mb-2">اختر البطاقة المصدر <span class="text-red-500">*</span></label>
                            <select name="from_card_id" id="from_card_id" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">-- اختر بطاقة --</option>
                                <?php foreach ($active_cards as $card): ?>
                                    <option value="<?php echo $card['id']; ?>" data-balance="<?php echo $card['current_balance']; ?>">
                                        <?php echo htmlspecialchars($card['card_number'] . ' (' . ($card['customer_name'] ?: 'N/A') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="from_card_balance_display" class="bg-gray-100 p-4 rounded-lg text-center hidden">
                            <p class="text-gray-600 text-sm">الرصيد الحالي المتاح</p>
                            <p id="from_balance_value" class="text-2xl font-bold text-blue-600"></p>
                        </div>
                    </div>

                    <!-- To Card -->
                    <div class="space-y-4">
                        <h3 class="text-xl font-bold text-gray-800 border-b-2 border-teal-500 pb-2 flex items-center">
                           <i class="fas fa-arrow-circle-down text-teal-500 ml-3"></i> إلى البطاقة (الهدف)
                        </h3>
                        <div class="form-group">
                            <label for="to_card_id" class="block text-sm font-medium text-gray-700 mb-2">اختر البطاقة الهدف <span class="text-red-500">*</span></label>
                            <select name="to_card_id" id="to_card_id" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                                <option value="">-- اختر بطاقة --</option>
                                <?php foreach ($active_cards as $card): ?>
                                     <option value="<?php echo $card['id']; ?>" data-balance="<?php echo $card['current_balance']; ?>">
                                        <?php echo htmlspecialchars($card['card_number'] . ' (' . ($card['customer_name'] ?: 'N/A') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                         <div id="to_card_balance_display" class="bg-gray-100 p-4 rounded-lg text-center hidden">
                            <p class="text-gray-600 text-sm">الرصيد الحالي</p>
                            <p id="to_balance_value" class="text-2xl font-bold text-teal-600"></p>
                        </div>
                    </div>

                </div>

                <hr class="my-8">

                <!-- Amount and Notes -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-2">المبلغ المراد تحويله <span class="text-red-500">*</span></label>
                        <div class="relative">
                             <input type="number" name="amount" id="amount" step="0.001" min="0.001" required placeholder="0.000" class="w-full pl-12 pr-4 py-3 border-2 border-gray-300 rounded-lg text-lg focus:ring-2 focus:ring-pink-500">
                             <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-bold">ر.ي</span>
                        </div>
                    </div>
                     <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">ملاحظات (اختياري)</label>
                        <textarea name="notes" id="notes" rows="3" placeholder="مثال: تسوية حساب" class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-400"></textarea>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-10 pt-6 border-t border-gray-200 text-left">
                    <button type="submit" class="bg-pink-600 text-white px-8 py-4 rounded-lg font-bold text-lg hover:bg-pink-700 transition-all duration-300 shadow-lg hover:shadow-xl w-full md:w-auto">
                        <i class="fas fa-check-circle ml-2"></i>
                        تنفيذ التحويل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fromCardSelect = document.getElementById('from_card_id');
    const fromCardBalanceDisplay = document.getElementById('from_card_balance_display');
    const fromBalanceValue = document.getElementById('from_balance_value');
    
    const toCardSelect = document.getElementById('to_card_id');
    const toCardBalanceDisplay = document.getElementById('to_card_balance_display');
    const toBalanceValue = document.getElementById('to_balance_value');

    const amountInput = document.getElementById('amount');
    const transferForm = document.getElementById('transferForm');

    function updateBalanceDisplay(selectElement, displayElement, valueElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const balance = selectedOption.getAttribute('data-balance');

        if (balance !== null) {
            valueElement.textContent = parseFloat(balance).toFixed(3) + ' ر.ي';
            displayElement.classList.remove('hidden');
        } else {
            displayElement.classList.add('hidden');
        }
    }

    fromCardSelect.addEventListener('change', () => updateBalanceDisplay(fromCardSelect, fromCardBalanceDisplay, fromBalanceValue));
    toCardSelect.addEventListener('change', () => updateBalanceDisplay(toCardSelect, toCardBalanceDisplay, toBalanceValue));

    transferForm.addEventListener('submit', function(event) {
        const fromCardId = fromCardSelect.value;
        const toCardId = toCardSelect.value;
        const transferAmount = parseFloat(amountInput.value);
        const selectedFromOption = fromCardSelect.options[fromCardSelect.selectedIndex];
        const sourceBalance = parseFloat(selectedFromOption.getAttribute('data-balance'));

        if (fromCardId === toCardId && fromCardId !== '') {
            alert('خطأ: لا يمكن اختيار نفس البطاقة للمصدر والهدف.');
            event.preventDefault();
            return;
        }

        if (transferAmount > sourceBalance) {
             if (!confirm('تحذير: مبلغ التحويل أكبر من الرصيد المتاح. هل ترغب في المتابعة على أي حال؟')) {
                 event.preventDefault();
             }
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>