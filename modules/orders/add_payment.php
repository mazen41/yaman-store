<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'إضافة دفعة للطلب';
$error_message = '';
$success_message = '';

// Get order ID
$order_id = intval($_GET['id'] ?? 0);
if (!$order_id) {
    header('Location: index.php');
    exit();
}

// Fetch order details
$stmt = $db->prepare("SELECT * FROM customer_orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($amount > 0 && !empty($payment_method)) {
        try {
            $db->beginTransaction();
            
            // Generate payment number
            $payment_count = $db->query("SELECT COUNT(*) FROM order_payments")->fetchColumn();
            $payment_number = 'PAY-' . str_pad($payment_count + 1, 6, '0', STR_PAD_LEFT);
            
            // Insert payment
            $stmt = $db->prepare("
                INSERT INTO order_payments (order_id, payment_number, amount, payment_method, payment_date, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$order_id, $payment_number, $amount, $payment_method, $payment_date, $notes, $_SESSION['user_id']]);
            
            $db->commit();
            $success_message = 'تم إضافة الدفعة بنجاح';
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = 'حدث خطأ: ' . $e->getMessage();
        }
    } else {
        $error_message = 'يرجى ملء جميع الحقول المطلوبة';
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-2xl mx-auto px-4">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b">
                <h1 class="text-xl font-bold">إضافة دفعة للطلب #<?php echo htmlspecialchars($order['order_number']); ?></h1>
            </div>
            
            <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg m-6">
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success_message): ?>
            <div class="bg-amber-100 border border-amber-400 text-amber-700 px-4 py-3 rounded-lg m-6">
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">المبلغ المستحق: <?php echo number_format($order['final_amount'], 2); ?> ريال</label>
                </div>
                
                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">مبلغ الدفعة *</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0" max="<?php echo $order['final_amount']; ?>" class="form-input" required>
                </div>
                
                <div>
                    <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-1">طريقة الدفع *</label>
                    <select id="payment_method" name="payment_method" class="form-input" required>
                        <option value="">-- اختر طريقة الدفع --</option>
                        <option value="cash">نقدي</option>
                        <option value="transfer">تحويل بنكي</option>
                        <option value="credit_card">بطاقة ائتمانية</option>
                        <option value="check">شيك</option>
                    </select>
                </div>
                
                <div>
                    <label for="payment_date" class="block text-sm font-medium text-gray-700 mb-1">تاريخ الدفع</label>
                    <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="form-input">
                </div>
                
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="3" class="form-input"></textarea>
                </div>
                
                <div class="flex justify-between pt-4">
                    <button type="button" onclick="window.location.href='view.php?id=<?php echo $order_id; ?>'" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                        إلغاء
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        حفظ الدفعة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
