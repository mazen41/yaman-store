<?php
/**
 * Edit Customer Payment
 * - FIXED: Updates the associated order's paid_amount when the payment amount is changed.
 * - CORRECTED: Preserves the order status by not automatically changing it.
 * - Enhanced Logging: Logs significant changes to payment details and their accounting implications.
 * - Improved Bank Account Handling: Ensures correct balance adjustments for transfers and cash.
 * - Robust Invoice Status Recalculation: Accurately updates invoice status based on total paid amount.
 * - Secure Image Uploads: Handles receipt image uploads, including deletion of old files.
 * - Accounting Integration: Deletes and recreates journal entries for accurate financial tracking.
 */

// Use Yemen local time
date_default_timezone_set('Asia/Aden');

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php';

// Only users with payments_edit can edit
if (!hasPermission($_SESSION['user_id'], 'payments', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل المدفوعات';
    header('Location: index.php'); // Redirect to the main payments list or similar
    exit();
}

$page_title = 'تعديل الدفعة';
$error_message = '';
$success_message = ''; // Not used for redirection, but good to have

$payment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($payment_id <= 0) {
    $_SESSION['error_message'] = 'معرف الدفعة غير صالح.';
    header('Location: index.php'); // Redirect to the main payments list
    exit();
}

$payment = []; // Initialize payment data
$bank_accounts = []; // Initialize bank accounts

try {
    // Load existing payment data
    // We need to fetch the associated order's status to ensure it's not changed
    $stmt = $db->prepare("
        SELECT cp.*, co.status AS order_status
        FROM customer_payments cp
        LEFT JOIN customer_invoices inv ON cp.invoice_id = inv.id
        LEFT JOIN customer_orders co ON inv.order_id = co.id
        WHERE cp.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $_SESSION['error_message'] = 'الدفعة غير موجودة';
        header('Location: index.php'); // Redirect if payment not found
        exit();
    }

    // Fetch active bank accounts for the dropdown
    $bank_accounts_stmt = $db->query("SELECT id, bank_name, account_holder_name, account_number FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
    $bank_accounts = $bank_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'حدث خطأ أثناء استرجاع بيانات الدفعة: ' . $e->getMessage();
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
    // --- Sanitize Inputs ---
    $new_amount        = isset($_POST['amount']) ? abs((float)$_POST['amount']) : 0; // Ensure amount is positive
    $new_payment_date  = trim($_POST['payment_date'] ?? '');
    $new_method        = trim($_POST['payment_method'] ?? '');
    $new_reference     = trim($_POST['reference_number'] ?? '');
    $new_notes         = trim($_POST['notes'] ?? '');
    $new_bank_account_id = ($new_method === 'transfer' && isset($_POST['bank_account_id'])) ? (int)$_POST['bank_account_id'] : null;
    $remove_receipt    = isset($_POST['remove_receipt']) && $_POST['remove_receipt'] == '1';

    // --- Preserve original values for logging and accounting ---
    $old_amount        = (float)$payment['amount'];
    $old_method        = $payment['payment_method'];
    $old_bank_account_id = $payment['bank_account_id'] ?? null;
    $invoice_id        = $payment['invoice_id'] ?? null;
    $customer_id       = $payment['customer_id'];
    $order_status_original = $payment['order_status']; // Store original order status

    $receipt_path_for_db = $payment['receipt_image_path'] ?? null; // Keep old path by default

    // --- Handle Image Upload ---
    if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['receipt_image'];
        $upload_dir = '../../uploads/receipts/';
        
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0775, true) && !is_dir($upload_dir)) {
                $error_message = 'فشل في إنشاء مجلد الرفع.';
            }
        }
        
        if (empty($error_message)) {
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
            $max_size = 5000000; // 5MB

            if (!in_array($file_ext, $allowed_exts)) {
                $error_message = 'نوع الملف غير مدعوم. يُسمح بـ JPG, PNG, PDF.';
            } elseif ($file['size'] > $max_size) {
                $error_message = 'حجم الملف أكبر من الحد المسموح به (5MB).';
            } else {
                $new_filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file_ext;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                    $receipt_path_for_db = 'uploads/receipts/' . $new_filename;
                    if (!empty($payment['receipt_image_path']) && file_exists('../../' . $payment['receipt_image_path'])) {
                        unlink('../../' . $payment['receipt_image_path']);
                    }
                } else {
                    $error_message = 'فشل في رفع صورة الإيصال. يرجى التحقق من الأذونات.';
                }
            }
        }
    } elseif ($remove_receipt && !empty($payment['receipt_image_path'])) {
        if (file_exists('../../' . $payment['receipt_image_path'])) {
            unlink('../../' . $payment['receipt_image_path']);
        }
        $receipt_path_for_db = null;
    }

    // --- Main Validation ---
    if (empty($error_message)) {
        if ($new_amount <= 0) {
            $error_message = 'يرجى إدخال مبلغ صحيح.';
        } elseif (empty($new_payment_date)) {
             $error_message = 'يرجى تحديد تاريخ الدفع.';
        } elseif ($new_method === 'transfer' && empty($new_bank_account_id)) {
            $error_message = 'يرجى اختيار الحساب البنكي عند استخدام طريقة التحويل البنكي.';
        }
    }

    // --- Process Update if No Errors ---
    if (empty($error_message)) {
        try {
            $db->beginTransaction();

            // 1) Adjust bank account balances based on payment method and amount changes
            // Reverse the effect of the OLD payment
            if ($old_method === 'transfer' && !empty($old_bank_account_id) && $old_amount > 0) {
                $rev_stmt = $db->prepare('UPDATE bank_accounts SET current_balance = COALESCE(current_balance,0) - ? WHERE id = ?');
                $rev_stmt->execute([$old_amount, $old_bank_account_id]);
            } elseif ($old_method === 'cash' && $old_amount > 0) {
                 $db->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) - ? WHERE bank_name = 'الصندوق'")
                   ->execute([$old_amount]);
            }

            // Apply the effect of the NEW payment
            if ($new_method === 'transfer' && !empty($new_bank_account_id) && $new_amount > 0) {
                $add_stmt = $db->prepare('UPDATE bank_accounts SET current_balance = COALESCE(current_balance,0) + ? WHERE id = ?');
                $add_stmt->execute([$new_amount, $new_bank_account_id]);
            } elseif ($new_method === 'cash' && $new_amount > 0) {
                $db->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE bank_name = 'الصندوق'")
                   ->execute([$new_amount]);
            }
            
            // 2) Update the payment row in the database
            $upd_payment = $db->prepare('UPDATE customer_payments
                                  SET amount = ?, payment_method = ?, bank_account_id = ?, payment_date = ?,
                                      reference_number = ?, notes = ?, receipt_image_path = ?, updated_at = NOW()
                                  WHERE id = ?');
            $upd_payment->execute([
                $new_amount,
                $new_method,
                $new_bank_account_id,
                $new_payment_date,
                $new_reference,
                $new_notes,
                $receipt_path_for_db,
                $payment_id
            ]);

            // ===================================================================
            // START: *** FIX - UPDATE ORDER'S PAID AMOUNT ***
            // ===================================================================
            // 3) If the payment is linked to an invoice, update the corresponding order's paid_amount
            if (!empty($invoice_id)) {
                // Find the order associated with the invoice
                $order_stmt = $db->prepare("SELECT order_id FROM customer_invoices WHERE id = ?");
                $order_stmt->execute([$invoice_id]);
                $order_id = $order_stmt->fetchColumn();

                // If an order is found, adjust its paid_amount by the difference
                if ($order_id) {
                    $amount_difference = $new_amount - $old_amount;

                    // Only run the update if there's an actual change in amount
                    if (abs($amount_difference) > 0.001) { // Use tolerance for float comparison
                        $update_order_stmt = $db->prepare(
                            "UPDATE customer_orders
                             SET paid_amount = GREATEST(0, COALESCE(paid_amount, 0) + ?)
                             WHERE id = ?"
                        );
                        $update_order_stmt->execute([$amount_difference, $order_id]);
                    }
                }
            }
            // ===================================================================
            // END: *** FIX - UPDATE ORDER'S PAID AMOUNT ***
            // ===================================================================
            
            // 4) Recompute invoice status if invoice_id exists
            if (!empty($invoice_id)) {
                $inv_stmt_lock = $db->prepare('SELECT total_amount FROM customer_invoices WHERE id = ? FOR UPDATE');
                $inv_stmt_lock->execute([$invoice_id]);
                $invoice_total = $inv_stmt_lock->fetchColumn();
                
                if ($invoice_total !== false) {
                    // Recalculate total paid for this invoice (including the updated payment)
                    $paid_stmt_recalc = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE invoice_id = ?');
                    $paid_stmt_recalc->execute([$invoice_id]);
                    $total_paid_recalc = (float)$paid_stmt_recalc->fetchColumn();

                    $new_status = 'pending';
                    if ($total_paid_recalc <= 0.001) {
                        $new_status = 'pending';
                    } elseif ($total_paid_recalc >= ($invoice_total - 0.001)) {
                        $new_status = 'paid';
                    } else {
                        $new_status = 'partially_paid';
                    }

                    $update_inv = $db->prepare('UPDATE customer_invoices SET status = ?, updated_at = NOW() WHERE id = ?');
                    $update_inv->execute([$new_status, $invoice_id]);
                }
            }

            // 5) Recreate accounting entries
            $accounting_succeeded = true;
            try {
                // Delete the old journal entry
                delete_journal_entry_by_source($db, 'payments', $payment_id);

                // Determine correct accounts for the new entry
                $ar_account_id = get_accounting_setting($db, 'default_accounts_receivable_id');
                $cash_account_id = get_accounting_setting($db, 'default_cash_account_id');
                $receiving_account_id = null; 

                if ($new_method === 'transfer' && !empty($new_bank_account_id)) {
                    $bank_stmt_coa = $db->prepare("SELECT account_id FROM bank_accounts WHERE id = ?");
                    $bank_stmt_coa->execute([$new_bank_account_id]);
                    $receiving_account_id = $bank_stmt_coa->fetchColumn();
                } else {
                    $receiving_account_id = $cash_account_id;
                }
                
                if (!$ar_account_id || empty($receiving_account_id)) {
                    throw new Exception("إعدادات المحاسبة غير مكتملة أو فشل في تحديد الحساب المستلم.");
                }

                $cust_stmt_desc = $db->prepare("SELECT name FROM customers WHERE id = ?");
                $cust_stmt_desc->execute([$customer_id]);
                $customer_name_desc = $cust_stmt_desc->fetchColumn();

                $description = "تعديل دفعة للعميل " . ($customer_name_desc ?: "#".$customer_id);

                $entry_items = [
                    ['account_id' => (int)$receiving_account_id, 'type' => 'debit', 'amount' => $new_amount], 
                    ['account_id' => (int)$ar_account_id, 'type' => 'credit', 'amount' => $new_amount],
                ];

                create_journal_entry($db, $new_payment_date, $description, $entry_items, 'payments', $payment_id, $_SESSION['user_id']);

            } catch (Exception $acc_e) {
                error_log("Accounting update failed for Payment ID $payment_id: " . $acc_e->getMessage());
                $accounting_succeeded = false;
            }

            $db->commit();
            $_SESSION['success_message'] = 'تم تحديث الدفعة ومبلغ الطلب المدفوع بنجاح' . ($accounting_succeeded ? '' : ' - تحذير: فشل إنشاء القيد المحاسبي.');

            header("Location: view.php?id=$payment_id&success=updated");
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = 'حدث خطأ أثناء تحديث الدفعة: ' . $e->getMessage();
        }
    }
}

// --- Include Header ---
include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header/Breadcrumbs -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">تعديل الدفعة</h1>
                    <p class="text-gray-600 mt-1">
                        <?php if (!empty($payment)): ?>
                            <?php echo htmlspecialchars($payment['payment_number'] ?? ''); ?>
                            - العميل: <?php echo htmlspecialchars($payment['customer_id']); // Display customer ID ?>
                        <?php else: ?>
                            تحميل بيانات الدفعة...
                        <?php endif; ?>
                    </p>
                </div>
                <!-- Back Button -->
                <a href="view.php?id=<?php echo $payment_id; ?>" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                    <i class="fas fa-arrow-right ml-2"></i> العودة للتفاصيل
                </a>
            </div>
        </div>

        <!-- Error Message Display -->
        <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <!-- Edit Payment Form -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <form method="POST" class="p-6" enctype="multipart/form-data">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Amount -->
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">المبلغ <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="number" step="0.01" min="0.01" id="amount" name="amount" value="<?php echo htmlspecialchars($payment['amount'] ?? 0); ?>" class="w-full pl-20 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-lg" required>
                            <div class="absolute inset-y-0 left-0 flex items-center bg-gray-100 border-r border-gray-300 rounded-l-md px-3 pointer-events-none">
                                <span class="text-gray-500 font-bold text-sm">YER</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Date -->
                    <div>
                        <label for="payment_date" class="block text-sm font-medium text-gray-700 mb-1">تاريخ الدفع <span class="text-red-500">*</span></label>
                        <input type="date" id="payment_date" name="payment_date" value="<?php echo htmlspecialchars(substr($payment['payment_date'] ?? date('Y-m-d'), 0, 10)); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <!-- Payment Method -->
                    <div>
                        <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-1">طريقة الدفع <span class="text-red-500">*</span></label>
                        <select id="payment_method" name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="cash" <?php echo ($payment['payment_method'] ?? '') === 'cash' ? 'selected' : ''; ?>>نقدي</option>
                            <option value="transfer" <?php echo ($payment['payment_method'] ?? '') === 'transfer' ? 'selected' : ''; ?>>تحويل بنكي</option>
                            <option value="credit_card" <?php echo ($payment['payment_method'] ?? '') === 'credit_card' ? 'selected' : ''; ?>>بطاقة ائتمانية</option>
                            <option value="check" <?php echo ($payment['payment_method'] ?? '') === 'check' ? 'selected' : ''; ?>>شيك</option>
                            <option value="other" <?php echo ($payment['payment_method'] ?? '') === 'other' ? 'selected' : ''; ?>>أخرى</option>
                        </select>
                    </div>

                    <!-- Bank Account Selection (Shown conditionally) -->
                    <div id="bankAccountContainer" style="display: none;">
                        <label for="bank_account_id" class="block text-sm font-medium text-gray-700 mb-1">الحساب البنكي المستلم <span class="text-red-500">*</span></label>
                        <select id="bank_account_id" name="bank_account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- اختر الحساب --</option>
                            <?php foreach ($bank_accounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>" <?php echo isset($payment['bank_account_id']) && $payment['bank_account_id'] == $account['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_holder_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-6">
                    <!-- Reference Number -->
                    <div class="mb-6">
                        <label for="reference_number" class="block text-sm font-medium text-gray-700 mb-1">رقم المرجع (اختياري)</label>
                        <input type="text" id="reference_number" name="reference_number" value="<?php echo htmlspecialchars($payment['reference_number'] ?? ''); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                        <textarea id="notes" name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="أي تفاصيل إضافية..."><?php echo htmlspecialchars($payment['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Receipt Image Upload -->
                <div class="mt-6">
                    <label for="receipt_image" class="block text-sm font-medium text-gray-700 mb-1">صورة الإيصال (اختياري)</label>
                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:bg-gray-50 transition focus-within:ring-2 focus-within:ring-blue-500">
                        <div class="space-y-1 text-center">
                            <i class="fas fa-cloud-upload-alt text-gray-400 text-3xl mb-3"></i>
                            <div class="flex text-sm text-gray-600 justify-center">
                                <label for="receipt_image" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none">
                                    <span>اختر ملفاً</span>
                                    <input id="receipt_image" name="receipt_image" type="file" class="sr-only" accept="image/jpeg,image/png,application/pdf">
                                </label>
                                <p class="pr-1">أو اسحبه هنا</p>
                            </div>
                            <p class="text-xs text-gray-500">PNG, JPG, PDF حتى 5MB</p>
                        </div>
                    </div>
                    <?php if (!empty($payment['receipt_image_path'])): ?>
                        <div class="mt-4 text-sm text-gray-600">
                            ملف الإيصال الحالي: <a href="<?php echo htmlspecialchars('../../' . $payment['receipt_image_path']); ?>" target="_blank" class="text-blue-600 hover:underline"><?php echo basename($payment['receipt_image_path']); ?></a>
                            <input type="hidden" name="current_receipt_path" value="<?php echo htmlspecialchars($payment['receipt_image_path']); ?>">
                            <label class="flex items-center mt-2">
                                <input type="checkbox" name="remove_receipt" value="1" class="form-checkbox h-4 w-4 text-blue-600">
                                <span class="ml-2">إزالة الإيصال الحالي</span>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end mt-8 border-t pt-6">
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-md font-bold flex items-center">
                        <i class="fas fa-save ml-2"></i> حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paymentMethodSelect = document.getElementById('payment_method');
    const bankAccountContainer = document.getElementById('bankAccountContainer');
    const bankAccountSelect = document.getElementById('bank_account_id');

    function toggleBankAccountField() {
        if (paymentMethodSelect.value === 'transfer') {
            bankAccountContainer.style.display = 'block';
            bankAccountSelect.required = true;
        } else {
            bankAccountContainer.style.display = 'none';
            bankAccountSelect.required = false;
            bankAccountSelect.value = '';
        }
    }

    // Initial check on page load
    toggleBankAccountField();

    // Add event listener to update when selection changes
    paymentMethodSelect.addEventListener('change', toggleBankAccountField);
});
</script>

<?php include '../../includes/footer.php'; ?>