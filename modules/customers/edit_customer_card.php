<?php
/**
 * Edit Customer Card
 * - Allows modification of existing customer card details.
 * - Does not allow direct editing of current_balance (use 'Add Money' feature).
 */

date_default_timezone_set('Asia/Aden');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تعديل بطاقة العميل';
$card = null;
$customers = [];
$error_message = '';
$success_message = '';

$card_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($card_id <= 0) {
    header('Location: index.php');
    exit();
}

// Fetch all customers for dropdown
try {
    $stmt = $db->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'فشل في تحميل العملاء: ' . $e->getMessage();
}

// Fetch current card details
try {
    $stmt = $db->prepare("SELECT * FROM customer_cards WHERE id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        header('Location: index.php?error=notfound'); // Redirect if card not found
        exit();
    }
} catch (PDOException $e) {
    $error_message = 'فشل في تحميل بيانات البطاقة: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $card_number = trim($_POST['card_number'] ?? '');
    // initial_amount and purchase_amount are not editable directly after creation
    $issue_date = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $notes = trim($_POST['notes'] ?? '');

    // Server-side validation
    if ($customer_id <= 0) {
        $error_message = 'يرجى اختيار العميل.';
    } elseif (empty($card_number)) {
        $error_message = 'رقم البطاقة مطلوب.';
    } elseif (empty($issue_date)) {
        $error_message = 'تاريخ الإصدار مطلوب.';
    } elseif (!in_array($status, ['active', 'inactive', 'expired', 'blocked'])) {
        $error_message = 'حالة البطاقة غير صالحة.';
    } else {
        // Check for duplicate card number, excluding the current card
        $stmt = $db->prepare("SELECT COUNT(*) FROM customer_cards WHERE card_number = ? AND id != ?");
        $stmt->execute([$card_number, $card_id]);
        if ($stmt->fetchColumn() > 0) {
            $error_message = 'رقم البطاقة هذا موجود بالفعل لبطاقة أخرى. يرجى إدخال رقم فريد.';
        }
    }

    if (empty($error_message)) {
        try {
            $stmt = $db->prepare("
                UPDATE customer_cards 
                SET customer_id = ?, card_number = ?, issue_date = ?, expiry_date = ?, status = ?, notes = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([
                $customer_id,
                $card_number,
                $issue_date,
                empty($expiry_date) ? null : $expiry_date,
                $status,
                $notes,
                $card_id
            ]);

            $success_message = 'تم تحديث بيانات البطاقة بنجاح.';
            header("Location: view_customer_card.php?id=$card_id&success=updated");
            exit();

        } catch (PDOException $e) {
            $error_message = 'حدث خطأ أثناء تحديث البطاقة: ' . $e->getMessage();
        }
    }
    // Re-fetch card data if there was an error, to populate form with POST data
    try {
        $stmt = $db->prepare("SELECT * FROM customer_cards WHERE id = ?");
        $stmt->execute([$card_id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message .= ' فشل في إعادة تحميل بيانات البطاقة بعد التقديم.';
    }
}

include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header Section -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">تعديل بطاقة العميل</h1>
                        <p class="text-gray-600 mt-1">تحديث بيانات البطاقة #<?php echo htmlspecialchars($card['card_number'] ?? 'N/A'); ?></p>
                    </div>
                    <div>
                        <a href="view_customer_card.php?id=<?php echo $card_id; ?>" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"><i class="fas fa-arrow-right ml-2"></i> العودة للبطاقة</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error Display -->
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">خطأ!</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Success Display (Though redirect typically handles it) -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border-r-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">نجاح!</p>
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($card): ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-800">بيانات البطاقة</h2>
            </div>

            <form method="POST" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Customer Selection -->
                    <div>
                        <label for="customer_id" class="block text-sm font-bold text-gray-700 mb-2">العميل <span class="text-red-500">*</span></label>
                        <select id="customer_id" name="customer_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">-- اختر العميل --</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo ($card['customer_id'] == $customer['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Card Number -->
                    <div>
                        <label for="card_number" class="block text-sm font-bold text-gray-700 mb-2">رقم البطاقة <span class="text-red-500">*</span></label>
                        <input type="text" id="card_number" name="card_number" value="<?php echo htmlspecialchars($card['card_number']); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="مثال: GC-001-XYZ" required>
                    </div>

                    <!-- Initial Amount (Display Only - Not editable) -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">المبلغ الأولي في البطاقة</label>
                        <p class="w-full px-4 py-3 border border-gray-200 bg-gray-100 rounded-lg text-gray-700">
                            <?php echo number_format($card['initial_amount'], 2); ?> YER
                        </p>
                        <p class="text-xs text-gray-500 mt-1">لا يمكن تعديل المبلغ الأولي بعد الإنشاء.</p>
                    </div>

                    <!-- Purchase Amount (Display Only - Not editable) -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">مبلغ الشراء (إيراد)</label>
                        <p class="w-full px-4 py-3 border border-gray-200 bg-gray-100 rounded-lg text-gray-700">
                            <?php echo number_format($card['purchase_amount'], 2); ?> YER
                        </p>
                        <p class="text-xs text-gray-500 mt-1">لا يمكن تعديل مبلغ الشراء بعد الإنشاء.</p>
                    </div>
                    
                    <!-- Current Balance (Display Only - Not editable directly) -->
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">الرصيد الحالي</label>
                        <p class="w-full px-4 py-3 border border-gray-200 bg-gray-100 rounded-lg font-bold text-green-600">
                            <?php echo number_format($card['current_balance'], 2); ?> YER
                        </p>
                        <p class="text-xs text-gray-500 mt-1">لتغيير الرصيد، استخدم زر "إضافة رصيد" في صفحة العرض.</p>
                    </div>

                    <!-- Issue Date -->
                    <div>
                        <label for="issue_date" class="block text-sm font-bold text-gray-700 mb-2">تاريخ الإصدار <span class="text-red-500">*</span></label>
                        <input type="date" id="issue_date" name="issue_date" value="<?php echo date('Y-m-d', strtotime($card['issue_date'])); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <!-- Expiry Date (Optional) -->
                    <div>
                        <label for="expiry_date" class="block text-sm font-bold text-gray-700 mb-2">تاريخ الانتهاء (اختياري)</label>
                        <input type="date" id="expiry_date" name="expiry_date" value="<?php echo $card['expiry_date'] ? date('Y-m-d', strtotime($card['expiry_date'])) : ''; ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="block text-sm font-bold text-gray-700 mb-2">الحالة <span class="text-red-500">*</span></label>
                        <select id="status" name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="active" <?php echo ($card['status'] === 'active') ? 'selected' : ''; ?>>نشط</option>
                            <option value="inactive" <?php echo ($card['status'] === 'inactive') ? 'selected' : ''; ?>>غير نشط</option>
                            <option value="expired" <?php echo ($card['status'] === 'expired') ? 'selected' : ''; ?>>منتهي</option>
                            <option value="blocked" <?php echo ($card['status'] === 'blocked') ? 'selected' : ''; ?>>محظور</option>
                        </select>
                    </div>
                </div>

                <div class="mt-6">
                    <label for="notes" class="block text-sm font-bold text-gray-700 mb-2">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="أي تفاصيل إضافية..."><?php echo htmlspecialchars($card['notes'] ?? ''); ?></textarea>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end mt-8 pt-6 border-t border-gray-200 gap-3">
                    <a href="view_customer_card.php?id=<?php echo $card_id; ?>" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-bold">إلغاء</a>
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-lg font-bold flex items-center">
                        <i class="fas fa-save ml-2"></i> حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>