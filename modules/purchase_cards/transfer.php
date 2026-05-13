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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $from_card_id = $_POST['from_card'];
    $to_card_id = $_POST['to_card'];
    $amount = floatval($_POST['amount']);

    if ($from_card_id === $to_card_id) {
        $error_message = 'لا يمكن التحويل إلى نفس البطاقة.';
    } elseif ($amount <= 0) {
        $error_message = 'يجب أن يكون مبلغ التحويل أكبر من صفر.';
    } else {
        try {
            $db->beginTransaction();

            // Get the balance of the source card
            $stmt = $db->prepare("SELECT balance FROM purchase_cards WHERE id = ? FOR UPDATE");
            $stmt->execute([$from_card_id]);
            $from_card = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($from_card && $from_card['balance'] >= $amount) {
                // Deduct from the source card
                $stmt = $db->prepare("UPDATE purchase_cards SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $from_card_id]);

                // Add to the destination card
                $stmt = $db->prepare("UPDATE purchase_cards SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $to_card_id]);

                $db->commit();
                $success_message = 'تم تحويل الرصيد بنجاح!';
            } else {
                $error_message = 'الرصيد في البطاقة المصدر غير كافٍ.';
                $db->rollBack();
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = 'حدث خطأ أثناء عملية التحويل.';
        }
    }
}

// Fetch all cards for the dropdowns
try {
    $stmt = $db->query("SELECT id, card_number, card_name, balance FROM purchase_cards ORDER BY card_name");
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cards = [];
    $error_message = 'لا يمكن تحميل البطاقات.';
}


include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="bg-gradient-to-r from-purple-600 to-pink-700 shadow-xl rounded-2xl mb-8 overflow-hidden">
            <div class="px-8 py-6">
                <h1 class="text-3xl font-bold text-white">
                    <i class="fas fa-random mr-3"></i>
                    تحويل رصيد
                </h1>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="bg-amber-100 border-r-4 border-amber-500 text-amber-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 rounded-lg mb-6 shadow-md">
                <p class="font-medium"><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="bg-white rounded-xl shadow-lg p-8 space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">من البطاقة</label>
                <select name="from_card" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <option value="">-- اختر بطاقة --</option>
                    <?php foreach ($cards as $card): ?>
                        <option value="<?php echo $card['id']; ?>">
                            <?php echo htmlspecialchars($card['card_name']) . " (" . htmlspecialchars($card['card_number']) . ") - الرصيد: " . htmlspecialchars(number_format($card['balance'], 2)) . " ر.س"; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">إلى البطاقة</label>
                <select name="to_card" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <option value="">-- اختر بطاقة --</option>
                    <?php foreach ($cards as $card): ?>
                        <option value="<?php echo $card['id']; ?>"><?php echo htmlspecialchars($card['card_name']) . " (" . htmlspecialchars($card['card_number']) . ")"; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">المبلغ</label>
                <input type="number" step="0.01" name="amount" required class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
            </div>
            <div class="flex gap-4 pt-4">
                <button type="submit" class="flex-1 bg-gradient-to-r from-purple-600 to-pink-600 text-white px-6 py-3 rounded-lg font-bold">
                    <i class="fas fa-check-circle ml-2"></i>
                    تأكيد التحويل
                </button>
                <a href="index.php" class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-lg font-bold text-center">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة
                </a>
            </div>
        </form>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>