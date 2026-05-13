<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'تحويل رصيد';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_account_id = filter_input(INPUT_POST, 'from_account_id', FILTER_VALIDATE_INT);
    $to_target = $_POST['to_account_id']; // Can be an ID or "cash"
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $description = trim($_POST['description'] ?? '');

    try {
        if (!$from_account_id || !$to_target || !$amount) {
            throw new Exception('يرجى ملء جميع الحقول المطلوبة.');
        }
        if ($from_account_id == $to_target) {
            throw new Exception('لا يمكن التحويل إلى نفس الحساب.');
        }
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون مبلغ التحويل أكبر من صفر.');
        }

        $db->beginTransaction();

        // 1. Get Source Bank Account
        $stmt_from = $db->prepare("SELECT id, bank_name, current_balance FROM bank_accounts WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt_from->execute([$from_account_id]);
        $from_account = $stmt_from->fetch(PDO::FETCH_ASSOC);

        if (!$from_account) throw new Exception('الحساب المصدر غير موجود.');
        if ($from_account['current_balance'] < $amount) throw new Exception('الرصيد غير كافٍ.');

        // 2. Subtract from Source Bank
        $new_from_balance = $from_account['current_balance'] - $amount;
        $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?")->execute([$new_from_balance, $from_account_id]);

        if ($to_target === 'cash') {
            // --- LOGIC FOR TRANSFER TO CASH ---
            $target_name = "الخزينة (كاش)";
            
            // Log in cash_transactions table
            $ins_cash = $db->prepare("INSERT INTO cash_transactions (amount, type, description, created_by) VALUES (?, 'in', ?, ?)");
            $ins_cash->execute([$amount, "تحويل من حساب {$from_account['bank_name']}. " . $description, $_SESSION['user_id']]);

            // Log in bank transactions
            $log_bank = $db->prepare("INSERT INTO bank_account_transactions (account_id, transaction_type, amount, balance_before, balance_after, description, created_by) VALUES (?, 'transfer_out', ?, ?, ?, ?, ?)");
            $log_bank->execute([$from_account_id, $amount, $from_account['current_balance'], $new_from_balance, "سحب نقدي للخزينة. " . $description, $_SESSION['user_id']]);
            
        } else {
            // --- LOGIC FOR BANK TO BANK ---
            $to_account_id = (int)$to_target;
            $stmt_to = $db->prepare("SELECT id, bank_name, current_balance FROM bank_accounts WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt_to->execute([$to_account_id]);
            $to_account = $stmt_to->fetch(PDO::FETCH_ASSOC);

            if (!$to_account) throw new Exception('الحساب الهدف غير موجود.');

            $new_to_balance = $to_account['current_balance'] + $amount;
            $db->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?")->execute([$new_to_balance, $to_account_id]);
            $target_name = $to_account['bank_name'];

            // Log bank-to-bank transactions and link both sides so reports can reconcile every movement
            $desc_out = "تحويل إلى {$to_account['bank_name']}. " . $description;
            $log_out = $db->prepare("INSERT INTO bank_account_transactions (account_id, transaction_type, amount, balance_before, balance_after, description, created_by) VALUES (?, 'transfer_out', ?, ?, ?, ?, ?)");
            $log_out->execute([$from_account_id, $amount, $from_account['current_balance'], $new_from_balance, $desc_out, $_SESSION['user_id']]);
            $out_transaction_id = $db->lastInsertId();
            
            $desc_in = "استلام من {$from_account['bank_name']}. " . $description;
            $log_in = $db->prepare("INSERT INTO bank_account_transactions (account_id, transaction_type, amount, balance_before, balance_after, description, related_transaction_id, created_by) VALUES (?, 'transfer_in', ?, ?, ?, ?, ?, ?)");
            $log_in->execute([$to_account_id, $amount, $to_account['current_balance'], $new_to_balance, $desc_in, $out_transaction_id, $_SESSION['user_id']]);
            $in_transaction_id = $db->lastInsertId();
            $db->prepare("UPDATE bank_account_transactions SET related_transaction_id = ? WHERE id = ?")->execute([$in_transaction_id, $out_transaction_id]);
        }

        $db->commit();
        $success_message = "تم تحويل " . number_format($amount, 2) . " ر.ي بنجاح إلى " . $target_name;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

try {
    $active_accounts = $db->query("SELECT id, bank_name, account_number, current_balance FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $active_accounts = []; }

include '../../includes/header.php';
?>

<style>
    .form-card { background: white; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 24px; }
    .form-card-header { background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%); color: white; padding: 20px 24px; }
    .form-card-body { padding: 32px 24px; }
    .form-control { width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; }
    .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; }
    .btn-primary { background-color: #2563eb; color: white; }
</style>

<div class="container-fluid py-4" dir="rtl">
    <div class="page-header mb-4"><h1><?php echo $page_title; ?></h1></div>

    <?php if ($success_message): ?><div class="bg-green-100 text-green-700 p-4 rounded mb-4"><?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="bg-red-100 text-red-700 p-4 rounded mb-4"><?php echo $error_message; ?></div><?php endif; ?>

    <div class="form-card">
        <div class="form-card-header"><h2><i class="fas fa-exchange-alt ml-2"></i> تنفيذ عملية تحويل جديدة</h2></div>
        <form method="POST" action="" class="form-card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div>
                    <label class="block font-bold mb-2">من الحساب (المصدر)</label>
                    <select name="from_account_id" id="from_account_id" required class="form-control">
                        <option value="">-- اختر الحساب --</option>
                        <?php foreach ($active_accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" data-bal="<?php echo $acc['current_balance']; ?>">
                                <?php echo $acc['bank_name']; ?> (الرصيد: <?php echo number_format($acc['current_balance'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-bold mb-2">إلى (الهدف)</label>
                    <select name="to_account_id" id="to_account_id" required class="form-control">
                        <option value="">-- اختر الوجهة --</option>
                        <option value="cash" style="font-weight: bold; color: green;">💰 الخزينة (كاش اليوم)</option>
                        <optgroup label="الحسابات البنكية">
                            <?php foreach ($active_accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>"><?php echo $acc['bank_name']; ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mt-6">
                <div>
                    <label class="block font-bold mb-2">المبلغ</label>
                    <input type="number" name="amount" step="0.01" required class="form-control text-lg">
                </div>
                <div>
                    <label class="block font-bold mb-2">ملاحظات</label>
                    <input type="text" name="description" class="form-control">
                </div>
            </div>

            <div class="mt-8 flex justify-between">
                <a href="index.php" class="text-gray-500">إلغاء</a>
                <button type="submit" class="btn btn-primary">تأكيد التحويل</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>