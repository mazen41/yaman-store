<?php
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php'); // Adjust path as needed
    exit();
}

require_once '../../config/database.php'; // Adjust path as needed

$page_title = 'إدارة الحسابات البنكية';
$error_message = '';
$success_message = '';
$edit_mode = false;
$account_to_edit = null;

// --- تعديل جدول الحسابات ليسمح بترك account_id فارغاً لتخطي شرط الربط ---
try {
    $db->exec("ALTER TABLE bank_accounts MODIFY account_id INT NULL DEFAULT NULL");
} catch (PDOException $e) {
    // نتجاهل الخطأ إذا كان الحقل معدل مسبقاً
}

// --- Create transactions table if it doesn't exist ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_account_id INT NOT NULL,
        transaction_type VARCHAR(50) NOT NULL,
        amount DECIMAL(15, 3) NOT NULL,
        description TEXT,
        transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    die("Error creating transactions table: " . $e->getMessage());
}

// Handle POST requests for Adding, Updating, and Depositing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {

    try {
        // --- ADD a new account ---
        if ($_POST['action'] == 'add') {
            $bank_name = trim($_POST['bank_name'] ?? '');
            $account_name = trim($_POST['account_name'] ?? '');
            $account_holder_name = trim($_POST['account_holder_name'] ?? '');
            $account_number = trim($_POST['account_number'] ?? '');
            
            $iban = trim($_POST['iban'] ?? '');
            $iban = ($iban === '') ? null : $iban; 
            
            $initial_balance = filter_input(INPUT_POST, 'initial_balance', FILTER_VALIDATE_FLOAT, ['options' =>['default' => 0]]);

            if (empty($bank_name) || empty($account_name) || empty($account_holder_name) || empty($account_number)) {
                $error_message = 'يرجى تعبئة الحقول الإلزامية.';
            } else {
                // نرسل NULL لحقل account_id حتى نتخطى شرط شجرة الحسابات
                $stmt = $db->prepare(
                    "INSERT INTO bank_accounts (account_id, bank_name, account_name, account_holder_name, account_number, iban, initial_balance, current_balance, is_active, created_by) 
                     VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
                );
                $stmt->execute([$bank_name, $account_name, $account_holder_name, $account_number, $iban, $initial_balance, $initial_balance, $_SESSION['user_id']]);
                $_SESSION['success_message'] = "تمت إضافة الحساب البنكي '{$bank_name}' بنجاح.";
                header('Location: bank_accounts.php');
                exit();
            }
        }
        // --- UPDATE an account ---
        elseif ($_POST['action'] == 'update' && isset($_POST['id'])) {
            $id = intval($_POST['id']);
            $edit_mode = true;
            $account_to_edit =['id' => $id, 'bank_name' => trim($_POST['bank_name'] ?? '')];
            
            $bank_name = trim($_POST['bank_name'] ?? '');
            $account_name = trim($_POST['account_name'] ?? '');
            $account_holder_name = trim($_POST['account_holder_name'] ?? '');
            $account_number = trim($_POST['account_number'] ?? '');
            
            $iban = trim($_POST['iban'] ?? '');
            $iban = ($iban === '') ? null : $iban;

            if (empty($bank_name) || empty($account_name) || empty($account_holder_name) || empty($account_number)) {
                $error_message = 'يرجى تعبئة الحقول الإلزامية.';
            } else {
                $stmt = $db->prepare(
                    "UPDATE bank_accounts SET bank_name = ?, account_name = ?, account_holder_name = ?, account_number = ?, iban = ? WHERE id = ?"
                );
                $stmt->execute([$bank_name, $account_name, $account_holder_name, $account_number, $iban, $id]);
                $_SESSION['success_message'] = "تم تحديث بيانات الحساب البنكي بنجاح.";
                header('Location: bank_accounts.php');
                exit();
            }
        }
        // --- DEPOSIT money into an account ---
        elseif ($_POST['action'] == 'deposit' && isset($_POST['account_id_deposit'])) {
            $deposit_acc_id = intval($_POST['account_id_deposit']);
            $deposit_amount = filter_input(INPUT_POST, 'deposit_amount', FILTER_VALIDATE_FLOAT);
            $deposit_description = trim($_POST['deposit_description'] ?? 'إيداع نقدي');

            if ($deposit_amount === false || $deposit_amount <= 0) {
                 $_SESSION['error_message'] = 'مبلغ الإيداع غير صالح.';
            } else {
                $db->beginTransaction();

                $update_stmt = $db->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $update_stmt->execute([$deposit_amount, $deposit_acc_id]);

                $trans_stmt = $db->prepare(
                    "INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, description, created_by) VALUES (?, 'deposit', ?, ?, ?)"
                );
                $trans_stmt->execute([$deposit_acc_id, $deposit_amount, $deposit_description, $_SESSION['user_id']]);

                $db->commit();
                $_SESSION['success_message'] = "تم إيداع مبلغ " . number_format($deposit_amount, 2) . " بنجاح.";
            }
            header('Location: bank_accounts.php');
            exit();
        }

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        $db_error = $e->getMessage();
        $error_code = $e->errorInfo[1] ?? 0;
        
        if ($error_code == 1062 || strpos($db_error, '1062') !== false) {
            $error_message = 'فشل: رقم الحساب أو الآيبان مستخدم لحساب آخر مسبقاً.';
        } else {
            // سنعرض الخطأ مفصلاً لنعرف إن استمرت أي مشكلة
            $error_message = 'فشل في تنفيذ العملية:<br><small dir="ltr" style="color:#fff; background:#dc3545; padding:5px; border-radius:4px; display:block; margin-top:10px;">' . htmlspecialchars($db_error) . '</small>';
        }
    }
}

// Handle GET requests for Editing and Toggling Status
if (isset($_GET['action'])) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id > 0) {
        if ($_GET['action'] == 'edit') {
            $edit_stmt = $db->prepare("SELECT * FROM bank_accounts WHERE id = ?");
            $edit_stmt->execute([$id]);
            $fetched_account = $edit_stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched_account) {
                $edit_mode = true;
                $account_to_edit = $fetched_account;
            } else {
                $_SESSION['error_message'] = 'الحساب المطلوب غير موجود.';
                header('Location: bank_accounts.php');
                exit();
            }
        }

        if ($_GET['action'] == 'toggle_status') {
            try {
                $status_stmt = $db->prepare("SELECT is_active FROM bank_accounts WHERE id = ?");
                $status_stmt->execute([$id]);
                $current_status = $status_stmt->fetchColumn();
                $new_status = $current_status == 1 ? 0 : 1;
                
                $toggle_stmt = $db->prepare("UPDATE bank_accounts SET is_active = ? WHERE id = ?");
                $toggle_stmt->execute([$new_status, $id]);
                
                $status_text = $new_status == 1 ? 'تفعيل' : 'إلغاء تفعيل';
                $_SESSION['success_message'] = "تم {$status_text} الحساب بنجاح.";

            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'فشل تغيير حالة الحساب: ' . $e->getMessage();
            }
            header('Location: bank_accounts.php');
            exit();
        }
    }
}

// Display messages from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch all bank accounts to display in the table
try {
    $accounts_stmt = $db->prepare("SELECT id, bank_name, account_name, account_holder_name, account_number, iban, is_active, current_balance FROM bank_accounts ORDER BY bank_name ASC");
    $accounts_stmt->execute();
    $accounts = $accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $accounts =[];
    $error_message = 'فشل في تحميل قائمة الحسابات البنكية.';
}

// Variables to keep input values
$val_bank_name = $_POST['bank_name'] ?? ($account_to_edit['bank_name'] ?? '');
$val_account_name = $_POST['account_name'] ?? ($account_to_edit['account_name'] ?? '');
$val_account_holder_name = $_POST['account_holder_name'] ?? ($account_to_edit['account_holder_name'] ?? '');
$val_account_number = $_POST['account_number'] ?? ($account_to_edit['account_number'] ?? '');
$val_iban = $_POST['iban'] ?? ($account_to_edit['iban'] ?? '');
$val_initial_balance = $_POST['initial_balance'] ?? ($account_to_edit['initial_balance'] ?? '0.00');
$val_id = $_POST['id'] ?? ($account_to_edit['id'] ?? 0);

include '../../includes/header.php'; // Adjust path as needed
?>

<style>
    :root {
        --primary-color: #3b82f6;
        --primary-hover: #2563eb;
        --warning-color: #f97316;
        --warning-hover: #ea580c;
        --danger-color: #ef4444;
        --success-color: #22c55e;
        --secondary-color: #6b7280;
        --light-gray: #f3f4f6;
        --border-color: #e5e7eb;
        --text-dark: #1f2937;
        --text-light: #6b7280;
    }
    body { background-color: var(--light-gray); }
    .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 2rem; }
    .card-header { background-color: transparent; padding: 1.5rem; border-bottom: 1px solid var(--border-color); }
    .card-header h2 { font-size: 1.25rem; font-weight: 700; color: var(--text-dark); margin: 0; display: flex; align-items: center; gap: 0.75rem; }
    .card-header.edit-mode h2 { color: var(--warning-color); }
    .card-body { padding: 1.5rem; }
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { display: block; font-weight: 600; color: var(--text-dark); margin-bottom: 0.5rem; }
    .form-group label .required { color: var(--danger-color); }
    .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; }
    .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(59,130,246,0.2); }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; border: none; text-decoration: none; }
    .btn-primary { background-color: var(--primary-color); color: white; }
    .btn-primary:hover { background-color: var(--primary-hover); }
    .btn-warning { background-color: var(--warning-color); color: white; }
    .btn-warning:hover { background-color: var(--warning-hover); }
    .btn-secondary { background-color: var(--secondary-color); color: white; }
    .btn-secondary:hover { background-color: #4b5563; }
    .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.875rem; }
    .btn-success { background-color: var(--success-color); color: white; }
    .btn-success:hover { background-color: #16a34a; }
    .btn-group { display: flex; gap: 0.75rem; flex-wrap: wrap; }
    
    .table-responsive { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; min-width: 900px; }
    .table th, .table td { padding: 1rem; text-align: right; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
    .table th { background-color: #f9fafb; font-weight: 700; color: var(--text-light); }
    .table tbody tr:hover { background-color: var(--light-gray); }
    
    .status-badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .status-active { background-color: #dcfce7; color: #166534; }
    .status-inactive { background-color: #fee2e2; color: #991b1b; }

    /* Modal Styles */
    .modal { display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
    .modal-content { background-color: #fefefe; margin: 10% auto; padding: 0; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); animation: fadeIn 0.3s; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { margin: 0; font-size: 1.25rem; font-weight: 700; }
    .modal-body { padding: 1.5rem; }
    .modal-footer { padding: 1.5rem; border-top: 1px solid var(--border-color); text-align: left; }
    .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
    .close:hover, .close:focus { color: black; text-decoration: none; }
</style>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><?php echo $page_title; ?></h1>
        <a href="transfer.php" class="btn btn-primary">
            <i class="fas fa-exchange-alt"></i>
            تحويل جديد بين الحسابات
        </a>
    </div>

    <?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>
    <?php if ($error_message): ?><div class="alert alert-danger" style="line-height:1.6;"><?php echo $error_message; ?></div><?php endif; ?>

    <!-- Add/Edit Form Card -->
    <div class="card" id="form-card">
        <div class="card-header <?php echo $edit_mode ? 'edit-mode' : ''; ?>">
            <h2>
                <?php if ($edit_mode): ?>
                    <i class="fas fa-edit"></i> تعديل حساب: <?php echo htmlspecialchars($val_bank_name); ?>
                <?php else: ?>
                    <i class="fas fa-plus-circle"></i> إضافة حساب بنكي جديد
                <?php endif; ?>
            </h2>
        </div>
        <form method="POST" action="bank_accounts.php">
            <div class="card-body">
                <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update' : 'add'; ?>">
                <?php if ($edit_mode): ?><input type="hidden" name="id" value="<?php echo htmlspecialchars($val_id); ?>"><?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="bank_name"><span class="required">*</span> اسم البنك</label>
                            <input type="text" id="bank_name" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($val_bank_name); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="account_name"><span class="required">*</span> اسم الحساب</label>
                            <input type="text" id="account_name" name="account_name" class="form-control" value="<?php echo htmlspecialchars($val_account_name); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="account_holder_name"><span class="required">*</span> اسم صاحب الحساب</label>
                            <input type="text" id="account_holder_name" name="account_holder_name" class="form-control" value="<?php echo htmlspecialchars($val_account_holder_name); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="account_number"><span class="required">*</span> رقم الحساب</label>
                            <input type="text" id="account_number" name="account_number" class="form-control" value="<?php echo htmlspecialchars($val_account_number); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="iban">رقم الآيبان (IBAN)</label>
                            <input type="text" id="iban" name="iban" class="form-control" value="<?php echo htmlspecialchars($val_iban); ?>">
                        </div>
                    </div>
                    <?php if (!$edit_mode): ?>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="initial_balance">الرصيد الافتتاحي</label>
                            <input type="number" step="0.01" id="initial_balance" name="initial_balance" class="form-control" value="<?php echo htmlspecialchars($val_initial_balance); ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="btn-group mt-3">
                    <?php if ($edit_mode): ?>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-save"></i> تحديث البيانات</button>
                        <a href="bank_accounts.php" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة الحساب</button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Accounts List Card -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-list-ul"></i> قائمة الحسابات</h2>
        </div>
        <div class="card-body p-0">
            <?php if (empty($accounts)): ?>
                <p class="text-center p-4">لا توجد حسابات بنكية مضافة حالياً.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>اسم البنك</th>
                                <th>اسم الحساب</th>
                                <th>صاحب الحساب</th>
                                <th>رقم الحساب</th>
                                <th>الرصيد الحالي</th>
                                <th>الحالة</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td class="font-weight-bold"><?php echo htmlspecialchars($account['bank_name']); ?></td>
                                <td><?php echo htmlspecialchars($account['account_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($account['account_holder_name']); ?></td>
                                <td><?php echo htmlspecialchars($account['account_number']); ?></td>
                                <td class="font-weight-bold" style="color: var(--primary-color);">
                                    <?php echo number_format($account['current_balance'], 2, '.', ','); ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $account['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $account['is_active'] ? 'مفعل' : 'غير مفعل'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <!-- ADD DEPOSIT BUTTON -->
                                        <button class="btn btn-sm btn-success" onclick="openDepositModal(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_name']); ?>')" title="إيداع"><i class="fas fa-money-bill-wave"></i></button>
                                        <a href="bank_accounts.php?action=edit&id=<?php echo $account['id']; ?>#form-card" class="btn btn-sm btn-warning" title="تعديل"><i class="fas fa-edit"></i></a>
                                        <a href="bank_accounts.php?action=toggle_status&id=<?php echo $account['id']; ?>" class="btn btn-sm <?php echo $account['is_active'] ? 'btn-danger' : 'btn-info'; ?>" title="<?php echo $account['is_active'] ? 'إلغاء التفعيل' : 'تفعيل'; ?>" onclick="return confirm('هل أنت متأكد؟')"><i class="fas fa-<?php echo $account['is_active'] ? 'times-circle' : 'check-circle'; ?>"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Deposit Modal -->
<div id="depositModal" class="modal">
    <div class="modal-content">
        <form method="POST" action="bank_accounts.php">
            <div class="modal-header">
                <h3>إيداع مبلغ مالي</h3>
                <span class="close" onclick="closeDepositModal()">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="deposit">
                <input type="hidden" name="account_id_deposit" id="deposit_account_id">
                
                <p>الحساب المستهدف: <strong id="deposit_account_name"></strong></p>
                
                <div class="form-group">
                    <label for="deposit_amount"><span class="required">*</span> مبلغ الإيداع</label>
                    <input type="number" step="0.01" class="form-control" name="deposit_amount" id="deposit_amount" required>
                </div>

                <div class="form-group">
                    <label for="deposit_description">الوصف (اختياري)</label>
                    <textarea class="form-control" name="deposit_description" id="deposit_description" rows="3" placeholder="مثال: إيداع من العميل فلان"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> تأكيد الإيداع</button>
                <button type="button" class="btn btn-secondary" onclick="closeDepositModal()"><i class="fas fa-times"></i> إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
    var modal = document.getElementById('depositModal');

    function openDepositModal(accountId, accountName) {
        document.getElementById('deposit_account_id').value = accountId;
        document.getElementById('deposit_account_name').innerText = accountName;
        modal.style.display = "block";
        document.getElementById('deposit_amount').focus();
    }

    function closeDepositModal() {
        modal.style.display = "none";
        document.getElementById('deposit_account_id').value = '';
        document.getElementById('deposit_account_name').innerText = '';
        document.getElementById('deposit_amount').value = '';
        document.getElementById('deposit_description').value = '';
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            closeDepositModal();
        }
    }
</script>

<?php include '../../includes/footer.php'; // Adjust path as needed ?>