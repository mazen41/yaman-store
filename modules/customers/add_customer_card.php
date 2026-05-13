<?php
/**
 * Add New Customer Card
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
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
require_once '../../includes/accounting_functions.php';

$page_title = 'إضافة بطاقة عميل جديدة';
$error_message = '';
$success_message = '';

$customers = [];
try {
    $stmt = $db->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'فشل في تحميل العملاء: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $customer_id    = intval($_POST['customer_id'] ?? 0);
    $card_number    = trim($_POST['card_number'] ?? '');
    $initial_amount = abs(floatval($_POST['initial_amount'] ?? 0));
    $purchase_amount= abs(floatval($_POST['purchase_amount'] ?? 0));
    $issue_date     = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $expiry_date    = trim($_POST['expiry_date'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if ($customer_id <= 0) {
        $error_message = 'يرجى اختيار العميل.';
    } elseif (!$card_number) {
        $error_message = 'رقم البطاقة مطلوب.';
    }

    if (!$error_message) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO customer_cards
                (customer_id, card_number, initial_amount, current_balance, purchase_amount, issue_date, expiry_date, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $customer_id,
                $card_number,
                $initial_amount,
                $initial_amount,
                $purchase_amount,
                $issue_date,
                $expiry_date ?: null,
                $notes,
                $_SESSION['user_id']
            ]);

            $new_card_id = $db->lastInsertId();

            // ================= ACCOUNTING =================
            if ($purchase_amount > 0 || $initial_amount > 0) {
                try {

                    $revenue_account_id = get_accounting_setting($db, 'default_sales_revenue_account_id');
                    $liability_account_id = get_accounting_setting($db, 'default_customer_deposit_liability_id');
                    $cash_account_id = get_accounting_setting($db, 'default_cash_account_id');

                    if (!$revenue_account_id || !$liability_account_id || !$cash_account_id) {
                        throw new Exception("إعدادات الحسابات غير مكتملة.");
                    }

                    $cust = $db->prepare("SELECT name FROM customers WHERE id=?");
                    $cust->execute([$customer_id]);
                    $customer_name = $cust->fetchColumn();

                    $description = "إصدار بطاقة رقم $card_number للعميل " . ($customer_name ?: $customer_id);

                    $entry_items = [];

                    if ($purchase_amount > 0) {
                        $entry_items[] = ['account_id'=>$revenue_account_id,'type'=>'credit','amount'=>$purchase_amount];
                    }

                    if ($initial_amount > 0) {
                        $entry_items[] = ['account_id'=>$liability_account_id,'type'=>'credit','amount'=>$initial_amount];
                    }

                    $total_cash = $purchase_amount + $initial_amount;

                    if ($total_cash > 0) {
                        $entry_items[] = ['account_id'=>$cash_account_id,'type'=>'debit','amount'=>$total_cash];
                    }

                    $d = $c = 0;
                    foreach ($entry_items as $i) {
                        if ($i['type']=='debit') $d += $i['amount'];
                        else $c += $i['amount'];
                    }

                    if (round($d,2) !== round($c,2)) {
                        throw new Exception("القيد غير متوازن.");
                    }

                    create_journal_entry(
                        $db,
                        $issue_date,
                        $description,
                        $entry_items,
                        'customer_cards',
                        $new_card_id,
                        $_SESSION['user_id']
                    );

                } catch (Exception $acc_e) {
                    error_log($acc_e->getMessage());
                }
            }
            // ===============================================

            $db->commit();

            header("Location: view_customer_card.php?id=$new_card_id&success=added");
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = $e->getMessage();
        }
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
                        <h1 class="text-2xl font-bold text-gray-900">إضافة بطاقة عميل جديدة</h1>
                        <p class="text-gray-600 mt-1">إنشاء بطاقة مدفوعة مسبقاً للعميل.</p>
                    </div>
                    <div>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"><i class="fas fa-arrow-right ml-2"></i> العودة لبطاقات العملاء</a>
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

        <!-- Success Display -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border-r-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <p class="font-bold">نجاح!</p>
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Card Form -->
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
                                <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Card Number -->
                    <div>
                        <label for="card_number" class="block text-sm font-bold text-gray-700 mb-2">رقم البطاقة <span class="text-red-500">*</span></label>
                        <input type="text" id="card_number" name="card_number" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="مثال: GC-001-XYZ" required>
                    </div>

                    <!-- Initial Amount (المبلغ في البطاقة) -->
                    <div>
                        <label for="initial_amount" class="block text-sm font-bold text-gray-700 mb-2">المبلغ في البطاقة (الرصيد الأولي) <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="number" id="initial_amount" name="initial_amount" step="0.01" min="0" value="0.00" class="w-full pl-20 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-lg" required>
                            <div class="absolute inset-y-0 left-0 flex items-center bg-gray-100 border-r border-gray-300 rounded-l-lg px-3">
                                <span class="text-gray-500 font-bold text-sm">YER</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">هذا هو المبلغ الذي يمكن للعميل إنفاقه من البطاقة.</p>
                    </div>

                    <!-- Purchase Amount (مبلغ الشراء - Revenue) -->
                    <div>
                        <label for="purchase_amount" class="block text-sm font-bold text-gray-700 mb-2">مبلغ الشراء (إيراد بيع البطاقة) <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="number" id="purchase_amount" name="purchase_amount" step="0.01" min="0" value="0.00" class="w-full pl-20 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-lg" required>
                            <div class="absolute inset-y-0 left-0 flex items-center bg-gray-100 border-r border-gray-300 rounded-l-lg px-3">
                                <span class="text-gray-500 font-bold text-sm">YER</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">هذا هو المبلغ الذي يمثل إيراد بيع البطاقة نفسها (قد يختلف عن الرصيد).</p>
                    </div>

                    <!-- Issue Date -->
                    <div>
                        <label for="issue_date" class="block text-sm font-bold text-gray-700 mb-2">تاريخ الإصدار <span class="text-red-500">*</span></label>
                        <input type="date" id="issue_date" name="issue_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <!-- Expiry Date (Optional) -->
                    <div>
                        <label for="expiry_date" class="block text-sm font-bold text-gray-700 mb-2">تاريخ الانتهاء (اختياري)</label>
                        <input type="date" id="expiry_date" name="expiry_date" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="mt-6">
                    <label for="notes" class="block text-sm font-bold text-gray-700 mb-2">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="أي تفاصيل إضافية..."></textarea>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end mt-8 pt-6 border-t border-gray-200 gap-3">
                    <a href="javascript:history.back()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-bold">إلغاء</a>
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-lg font-bold flex items-center">
                        <i class="fas fa-save ml-2"></i> حفظ البطاقة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>