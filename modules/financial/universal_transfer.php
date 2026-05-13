<?php
// /modules/financial/universal_transfer.php

session_start();

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$page_title = 'التحويل المباشر بين الحسابات';
$error_message = '';
$success_message = '';

// Handle form submission for the transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_type = $_POST['from_type'] ?? '';
    $from_id = filter_input(INPUT_POST, 'from_id', FILTER_VALIDATE_INT);
    $to_type = $_POST['to_type'] ?? '';
    $to_id = filter_input(INPUT_POST, 'to_id', FILTER_VALIDATE_INT);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $description = trim($_POST['description'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');

    try {
        // --- 1. Basic Validation ---
        if (!$from_type || !$from_id || !$to_type || !$to_id || !$amount) {
            throw new Exception('يرجى ملء جميع الحقول المطلوبة (النوع، الحساب، المبلغ).');
        }
        if ($from_type === $to_type && $from_id === $to_id) {
            throw new Exception('لا يمكن التحويل من وإلى نفس الحساب.');
        }
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون مبلغ التحويل أكبر من صفر.');
        }

        // --- 2. Start Database Transaction for safety ---
        $db->beginTransaction();

        $from_display_name = '';
        $to_display_name = '';

        // --- 3. Process the SOURCE Account (FROM) ---
        if ($from_type === 'bank') {
            // Get current balance and lock the row to prevent race conditions
            $stmt_from = $db->prepare("SELECT bank_name, account_name, current_balance FROM bank_accounts WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt_from->execute([$from_id]);
            $from_account = $stmt_from->fetch(PDO::FETCH_ASSOC);

            if (!$from_account) throw new Exception('الحساب البنكي المصدر غير موجود أو غير نشط.');
            if ($from_account['current_balance'] < $amount) throw new Exception('الرصيد في الحساب البنكي المصدر غير كافٍ.');

            $from_display_name = $from_account['bank_name'] . ' - ' . $from_account['account_name'];

            // Decrease balance
            $update_from = $db->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
            $update_from->execute([$amount, $from_id]);

        } elseif ($from_type === 'account') {
            // Get current balance and lock the row
            $stmt_from = $db->prepare("SELECT name, current_balance FROM accounts WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt_from->execute([$from_id]);
            $from_account = $stmt_from->fetch(PDO::FETCH_ASSOC);

            if (!$from_account) throw new Exception('الحساب المالي المصدر غير موجود أو غير نشط.');
            if ($from_account['current_balance'] < $amount) throw new Exception('الرصيد في الحساب المالي المصدر غير كافٍ.');

            $from_display_name = $from_account['name'];

            // Decrease balance
            $update_from = $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?");
            $update_from->execute([$amount, $from_id]);
        }

        // --- 4. Process the DESTINATION Account (TO) ---
        if ($to_type === 'bank') {
            $stmt_to = $db->prepare("SELECT bank_name, account_name FROM bank_accounts WHERE id = ? AND is_active = 1");
            $stmt_to->execute([$to_id]);
            $to_account = $stmt_to->fetch(PDO::FETCH_ASSOC);
            if (!$to_account) throw new Exception('الحساب البنكي الهدف غير موجود أو غير نشط.');
            $to_display_name = $to_account['bank_name'] . ' - ' . $to_account['account_name'];

            // Increase balance
            $update_to = $db->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $update_to->execute([$amount, $to_id]);

        } elseif ($to_type === 'account') {
            $stmt_to = $db->prepare("SELECT name FROM accounts WHERE id = ? AND is_active = 1");
            $stmt_to->execute([$to_id]);
            $to_account = $stmt_to->fetch(PDO::FETCH_ASSOC);
            if (!$to_account) throw new Exception('الحساب المالي الهدف غير موجود أو غير نشط.');
            $to_display_name = $to_account['name'];

            // Increase balance
            $update_to = $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?");
            $update_to->execute([$amount, $to_id]);
        }

        // --- 5. Log the transaction in our new table ---
        $log_stmt = $db->prepare(
            "INSERT INTO money_transfers (transfer_date, amount, from_type, from_id, from_name_snapshot, to_type, to_id, to_name_snapshot, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $log_stmt->execute([
            $transaction_date,
            $amount,
            $from_type,
            $from_id,
            $from_display_name,
            $to_type,
            $to_id,
            $to_display_name,
            $description,
            $_SESSION['user_id']
        ]);

        // --- 6. Commit all changes if successful ---
        $db->commit();
        $success_message = "تم تحويل مبلغ " . number_format($amount, 2) . " بنجاح من '{$from_display_name}' إلى '{$to_display_name}'.";

    } catch (Exception $e) {
        // If anything fails, undo all changes
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Fetch all active bank accounts and financial accounts for the dropdowns
try {
    $bank_accounts_stmt = $db->query("SELECT id, bank_name, account_name, current_balance FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name, account_name ASC");
    $bank_accounts = $bank_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all accounts from the Chart of Accounts
    $accounts_stmt = $db->query("SELECT id, name, code, current_balance FROM accounts WHERE is_active = 1 ORDER BY code ASC");
    $all_accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $bank_accounts = [];
    $all_accounts = [];
    $error_message = "خطأ في جلب بيانات الحسابات: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<!-- Your existing Tailwind/CSS styles would go here -->
<style>
    /* Reusing and adapting styles from your provided files */
    :root {
        --primary-color: #3b82f6;
        --primary-hover: #2563eb;
        --danger-color: #ef4444;
        --success-color: #22c55e;
        --secondary-color: #6b7280;
        --light-gray: #f3f4f6;
        --border-color: #e5e7eb;
        --text-dark: #1f2937;
    }
    body { background-color: var(--light-gray); font-family: 'Tajawal', sans-serif; }
    .container-fluid { max-width: 1200px; margin-left: auto; margin-right: auto; padding-left: 1rem; padding-right: 1rem; }
    .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 2rem; }
    .card-header { background-color: transparent; padding: 1.5rem; border-bottom: 1px solid var(--border-color); }
    .card-header h2 { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin: 0; display: flex; align-items: center; gap: 0.75rem; }
    .card-body { padding: 1.5rem; }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; font-weight: 600; color: var(--text-dark); margin-bottom: 0.5rem; }
    .form-group label .required { color: var(--danger-color); margin-left: 0.25rem; }
    .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; background-color: white; color: var(--text-dark); appearance: none; }
    .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59,130,246,0.2); }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; border: none; text-decoration: none; }
    .btn-primary { background-color: var(--primary-color); color: white; }
    .btn-primary:hover { background-color: var(--primary-hover); }
    .btn-secondary { background-color: var(--secondary-color); color: white; }
    .btn-secondary:hover { background-color: #4b5563; }
    .alert { padding: 1rem 1.5rem; margin-bottom: 1.5rem; border-radius: 8px; font-weight: 600; display: flex; align-items: center; gap: 0.75rem;}
    .alert-success { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .radio-group { display: flex; gap: 1rem; margin-top: 0.5rem; }
    .radio-group label { display: flex; align-items: center; cursor: pointer; padding: 0.5rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; transition: all 0.2s ease; }
    .radio-group input[type="radio"] { margin-left: 0.5rem; }
    .radio-group input[type="radio"]:checked + span { font-weight: bold; color: var(--primary-color); }
    .radio-group label:has(input:checked) { border-color: var(--primary-color); background-color: #eff6ff; }
    .balance-display { background-color: #e0f2fe; border: 1px solid #bfdbfe; padding: 0.75rem; border-radius: 8px; text-align: center; margin-top: 1rem; display: none; }
    .balance-display span { font-weight: 700; color: var(--primary-color); font-size: 1.1rem; }
    .section-title { font-size: 1.15rem; font-weight: 700; color: var(--text-dark); border-bottom: 2px solid var(--primary-color); padding-bottom: 0.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;}
    .section-title.from { border-color: var(--danger-color); }
    .section-title.to { border-color: var(--success-color); }
    .hr-divider { border: 0; height: 1px; background-color: var(--border-color); margin: 2rem 0; }
</style>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?php echo $page_title; ?></h1>
        <a href="bank_accounts.php" class="btn btn-secondary">
            <i class="fas fa-chevron-right ml-2"></i>
            العودة
        </a>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-exchange-alt"></i> تنفيذ تحويل جديد</h2>
        </div>
        <form method="POST" action="universal_transfer.php" id="universalTransferForm">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="transaction_date"><span class="required">*</span> تاريخ التحويل</label>
                            <input type="date" id="transaction_date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="amount"><span class="required">*</span> المبلغ</label>
                            <input type="number" step="0.01" min="0.01" id="amount" name="amount" class="form-control" placeholder="0.00" required>
                        </div>
                    </div>
                </div>

                <hr class="hr-divider">

                <div class="row">
                    <!-- From Account Section -->
                    <div class="col-md-6">
                        <h3 class="section-title from"><i class="fas fa-arrow-up text-danger"></i> من الحساب (المصدر)</h3>
                        <div class="form-group">
                            <label><span class="required">*</span> نوع الحساب المصدر</label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="from_type" value="bank" checked onchange="toggleAccountFields('from')">
                                    <span>حساب بنكي</span>
                                </label>
                                <label>
                                    <input type="radio" name="from_type" value="account" onchange="toggleAccountFields('from')">
                                    <span>حساب مالي (من الدليل)</span>
                                </label>
                            </div>
                        </div>

                        <div id="from_bank_group">
                            <div class="form-group">
                                <label for="from_bank_id"><span class="required">*</span> اختر الحساب البنكي</label>
                                <select name="from_id_bank" id="from_bank_id" class="form-control" onchange="updateBalanceDisplay('from', this)">
                                    <option value="">-- اختر حساب بنكي --</option>
                                    <?php foreach ($bank_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" data-balance="<?php echo htmlspecialchars($account['current_balance']); ?>">
                                            <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="from_bank_balance_display" class="balance-display">
                                الرصيد المتاح: <span id="from_bank_balance_value">0.00 ريال</span>
                            </div>
                        </div>

                        <div id="from_account_group" style="display:none;">
                           <div class="form-group">
                                <label for="from_account_id"><span class="required">*</span> اختر الحساب المالي</label>
                                <select name="from_id_account" id="from_account_id" class="form-control" onchange="updateBalanceDisplay('from', this)" disabled>
                                    <option value="">-- اختر حساب مالي --</option>
                                    <?php foreach ($all_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" data-balance="<?php echo htmlspecialchars($account['current_balance']); ?>">
                                            [<?php echo htmlspecialchars($account['code']); ?>] <?php echo htmlspecialchars($account['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="from_account_balance_display" class="balance-display">
                                الرصيد المتاح: <span id="from_account_balance_value">0.00 ريال</span>
                            </div>
                        </div>
                    </div>

                    <!-- To Account Section -->
                    <div class="col-md-6">
                        <h3 class="section-title to"><i class="fas fa-arrow-down text-success"></i> إلى الحساب (الهدف)</h3>
                        <div class="form-group">
                            <label><span class="required">*</span> نوع الحساب الهدف</label>
                            <div class="radio-group">
                                <label>
                                    <input type="radio" name="to_type" value="bank" checked onchange="toggleAccountFields('to')">
                                    <span>حساب بنكي</span>
                                </label>
                                <label>
                                    <input type="radio" name="to_type" value="account" onchange="toggleAccountFields('to')">
                                    <span>حساب مالي (من الدليل)</span>
                                </label>
                            </div>
                        </div>

                         <div id="to_bank_group">
                            <div class="form-group">
                                <label for="to_bank_id"><span class="required">*</span> اختر الحساب البنكي</label>
                                <select name="to_id_bank" id="to_bank_id" class="form-control" onchange="updateBalanceDisplay('to', this)">
                                    <option value="">-- اختر حساب بنكي --</option>
                                    <?php foreach ($bank_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" data-balance="<?php echo htmlspecialchars($account['current_balance']); ?>">
                                            <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="to_bank_balance_display" class="balance-display">
                                الرصيد المتاح: <span id="to_bank_balance_value">0.00 ريال</span>
                            </div>
                        </div>

                        <div id="to_account_group" style="display:none;">
                            <div class="form-group">
                                <label for="to_account_id"><span class="required">*</span> اختر الحساب المالي</label>
                                <select name="to_id_account" id="to_account_id" class="form-control" onchange="updateBalanceDisplay('to', this)" disabled>
                                    <option value="">-- اختر حساب مالي --</option>
                                    <?php foreach ($all_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>" data-balance="<?php echo htmlspecialchars($account['current_balance']); ?>">
                                            [<?php echo htmlspecialchars($account['code']); ?>] <?php echo htmlspecialchars($account['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="to_account_balance_display" class="balance-display">
                                الرصيد المتاح: <span id="to_account_balance_value">0.00 ريال</span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="hr-divider">

                <div class="form-group">
                    <label for="description">الوصف / البيان (اختياري)</label>
                    <textarea id="description" name="description" class="form-control" rows="3" placeholder="أضف تفاصيل إضافية حول التحويل"></textarea>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-check-circle ml-2"></i> تنفيذ التحويل</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        toggleAccountFields('from');
        toggleAccountFields('to');

        const form = document.getElementById('universalTransferForm');
        form.addEventListener('submit', function(event) {
            // Set the correct 'from_id' and 'to_id' before submitting
            const fromType = document.querySelector('input[name="from_type"]:checked').value;
            const toType = document.querySelector('input[name="to_type"]:checked').value;
            
            const fromSelect = document.getElementById(`from_${fromType}_id`);
            const toSelect = document.getElementById(`to_${toType}_id`);
            
            // Create or update hidden inputs for from_id and to_id
            updateHiddenInput('from_id', fromSelect.value);
            updateHiddenInput('to_id', toSelect.value);

            if (!fromSelect.value || !toSelect.value) {
                alert('الرجاء اختيار الحساب المصدر والهدف.');
                event.preventDefault();
                return;
            }

            if (fromType === toType && fromSelect.value === toSelect.value) {
                alert('خطأ: لا يمكن التحويل من وإلى نفس الحساب!');
                event.preventDefault();
            }
        });
    });

    function updateHiddenInput(name, value) {
        let input = document.getElementById(name);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.id = name;
            input.name = name;
            document.getElementById('universalTransferForm').appendChild(input);
        }
        input.value = value;
    }

    function toggleAccountFields(direction) {
        const type = document.querySelector(`input[name="${direction}_type"]:checked`).value;

        const bankGroup = document.getElementById(`${direction}_bank_group`);
        const accountGroup = document.getElementById(`${direction}_account_group`);
        const bankSelect = document.getElementById(`${direction}_bank_id`);
        const accountSelect = document.getElementById(`${direction}_account_id`);

        if (type === 'bank') {
            bankGroup.style.display = 'block';
            accountGroup.style.display = 'none';
            bankSelect.removeAttribute('disabled');
            accountSelect.setAttribute('disabled', 'disabled');
            updateBalanceDisplay(direction, bankSelect); // Update balance display
        } else { // type is 'account'
            bankGroup.style.display = 'none';
            accountGroup.style.display = 'block';
            bankSelect.setAttribute('disabled', 'disabled');
            accountSelect.removeAttribute('disabled');
            updateBalanceDisplay(direction, accountSelect); // Update balance display
        }
    }

    function updateBalanceDisplay(direction, selectElement) {
        // Find the correct balance display elements based on direction and type
        const type = selectElement.id.includes('bank') ? 'bank' : 'account';
        const balanceDisplayDiv = document.getElementById(`${direction}_${type}_balance_display`);
        const balanceValueSpan = document.getElementById(`${direction}_${type}_balance_value`);

        if (selectedOption = selectElement.options[selectElement.selectedIndex]) {
            if (selectedOption.value && selectedOption.hasAttribute('data-balance')) {
                const balance = parseFloat(selectedOption.getAttribute('data-balance'));
                balanceValueSpan.textContent = balance.toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ريال';
                balanceDisplayDiv.style.display = 'block';
            } else {
                balanceValueSpan.textContent = '0.00 ريال'; // Reset if no account selected or no balance
                balanceDisplayDiv.style.display = 'none';
            }
        } else { // No option is selected
             balanceValueSpan.textContent = '0.00 ريال';
             balanceDisplayDiv.style.display = 'none';
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>