<?php
/**
 * Add New Customer Payment
 * - Corrected: Ensures that adding a payment does not affect the order status.
 * - Enhanced Validation: Implements robust client-side and server-side checks for amounts, methods, and dates.
 * - Dynamic Invoice Handling: Allows adding payments to specific invoices or as general payments for a customer.
 * - Integrated Accounting: Creates journal entries for each payment, linking them to relevant accounts.
 * - File Upload Handling: Securely manages receipt image uploads.
 * - User Experience Improvements: Includes dynamic form updates, loading states, and clear feedback messages.
 * - NEW: Added Customer Card as a payment method, allowing direct card number input for any customer.
 * - FIX: Correctly decreases the customer card balance when used for payment.
 * - NEW: Records the user who added the payment.
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
require_once '../../includes/accounting_functions.php';

$page_title = 'إضافة دفعة جديدة';
$error_message = '';
$success_message = ''; // Not used for redirection here, but could be for user feedback

// Capture IDs from URL parameters
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0; // Still fetch, but it might be overridden by invoice

// Redirect if no customer or invoice ID is provided
if ($invoice_id <= 0 && $customer_id <= 0) {
    header('Location: ../customers/index.php'); // Or a more appropriate index page
    exit();
}

$invoice = null;
$customer = null;
$calculated_total_amount = 0;
$remaining_amount = 0;
$unpaid_invoices = []; // To store available unpaid invoices for a customer
// $customer_cards = []; // REMOVED: No longer fetching customer-specific cards for dropdown

// ---------------------------------------------------------
// 1. DATA FETCHING - Integrated Logic
// ---------------------------------------------------------

// Fetch Invoice and Customer Data if invoice_id is provided
if ($invoice_id > 0) {
    // Fetch invoice details along with related customer and order information
    $stmt = $db->prepare("
        SELECT 
            ci.*, 
            c.name as customer_name, 
            c.id as customer_id,
            co.final_amount as order_final_amount,
            co.subtotal_amount,
            co.discount_amount,
            co.additional_discount,
            co.paid_amount as order_paid_amount, -- Fetch current order paid_amount
            co.id as order_id
        FROM customer_invoices ci 
        JOIN customers c ON ci.customer_id = c.id
        LEFT JOIN customer_orders co ON ci.order_id = co.id
        WHERE ci.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    // Redirect if invoice not found
    if (!$invoice) {
        header('Location: ../customers/index.php');
        exit();
    }
    
    $customer_id = $invoice['customer_id']; // **IMPORTANT**: Use customer ID from the invoice

    // Calculate total amount and remaining balance for the selected invoice
    $total_amount_for_invoice = 0;
    if (isset($invoice['order_final_amount']) && $invoice['order_final_amount'] > 0) {
        $total_amount_for_invoice = (float)$invoice['order_final_amount'];
    } elseif (isset($invoice['subtotal_amount']) && $invoice['subtotal_amount'] > 0) {
        $sub = (float)($invoice['subtotal_amount'] ?? 0);
        $disc = (float)($invoice['discount_amount'] ?? 0);
        $add_disc = (float)($invoice['additional_discount'] ?? 0);
        $total_amount_for_invoice = $sub - $disc - $add_disc;
    } else {
        $total_amount_for_invoice = (float)($invoice['total_amount'] ?? 0);
    }

    // Calculate currently paid amount for this invoice
    $paid_stmt = $db->prepare("SELECT SUM(amount) FROM customer_payments WHERE invoice_id = ?");
    $paid_stmt->execute([$invoice_id]);
    $total_paid_for_invoice = (float)($paid_stmt->fetchColumn() ?? 0);
    
    // Calculate remaining balance
    $remaining_amount = $total_amount_for_invoice - $total_paid_for_invoice;
    if ($remaining_amount < 0) $remaining_amount = 0; // Prevent negative values due to float precision
}

// Fetch Customer Data and Unpaid Invoices if customer_id is provided (and no specific invoice was selected)
if ($customer_id > 0) { // Check for customer_id regardless of invoice presence
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // Redirect if customer not found
    if (!$customer) {
        header('Location: ../customers/index.php');
        exit();
    }

    if ($invoice_id <= 0) { // Only load unpaid invoices if no specific invoice was selected
        // Load unpaid invoices for dropdown
        $invoices_stmt = $db->prepare("
            SELECT 
                ci.*,
                co.final_amount as order_final_amount,
                co.subtotal_amount,
                co.discount_amount,
                co.additional_discount
            FROM customer_invoices ci 
            LEFT JOIN customer_orders co ON ci.order_id = co.id
            WHERE ci.customer_id = ? 
            AND ci.status IN ('pending', 'partially_paid') 
            ORDER BY ci.due_date ASC
        ");
        $invoices_stmt->execute([$customer_id]);
        $raw_invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process each invoice to accurately calculate remaining balance
        foreach ($raw_invoices as $inv) {
            // Calculate Total for each invoice
            $inv_total = 0;
            if (isset($inv['order_final_amount']) && $inv['order_final_amount'] > 0) {
                $inv_total = (float)$inv['order_final_amount'];
            } else {
                $inv_total = (float)($inv['total_amount'] ?? 0);
            }

            // Calculate Paid for each invoice
            $p_stmt = $db->prepare("SELECT SUM(amount) FROM customer_payments WHERE invoice_id = ?");
            $p_stmt->execute([$inv['id']]);
            $inv_paid = (float)($p_stmt->fetchColumn() ?? 0);

            $inv_remaining = $inv_total - $inv_paid;

            if ($inv_remaining > 0.01) { // Only list if there's a significant amount remaining
                $inv['calculated_remaining'] = $inv_remaining;
                $unpaid_invoices[] = $inv;
            }
        }
    }
    // customer_cards fetching REMOVED as we're now entering card number directly
}


// Fetch Active Bank Accounts for selection
try {
    $bank_stmt = $db->query("SELECT id, bank_name, account_number, account_holder_name, currency FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
    $bank_accounts = $bank_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bank_accounts = [];
    $error_message = 'فشل في تحميل الحسابات البنكية.';
}

// ---------------------------------------------------------
// 2. PROCESS FORM SUBMISSION
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs from POST data
    $payment_customer_id = $customer_id; // Use the already determined customer ID

    $payment_invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
    $amount = isset($_POST['amount']) ? abs(floatval($_POST['amount'])) : 0; // Ensure amount is positive
    $currency = 'YER'; // Hardcoded currency for now
    $payment_method = trim($_POST['payment_method'] ?? 'cash');
    $bank_account_id = ($payment_method === 'transfer' && isset($_POST['bank_account_id'])) ? intval($_POST['bank_account_id']) : null;
    
    // NEW: Customer Card Number input
    $entered_card_number = ($payment_method === 'customer_card' && isset($_POST['customer_card_number'])) ? trim($_POST['customer_card_number']) : null;
    $customer_card_id = null; // This will be populated after finding the card by number

    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Get the ID of the logged-in user who is adding the payment
    $added_by_user_id = $_SESSION['user_id']; 

    // --- Server-Side Validations ---
    if ($payment_customer_id <= 0) {
        $error_message = 'يرجى اختيار العميل.';
    } elseif ($amount <= 0) {
        $error_message = 'يرجى إدخال مبلغ صحيح.';
    } elseif (empty($payment_date)) {
        $error_message = 'يرجى تحديد تاريخ الدفع.';
    } 
    elseif ($payment_method === 'transfer' && empty($bank_account_id)) {
        $error_message = 'يرجى اختيار الحساب البنكي عند استخدام طريقة التحويل البنكي.';
    }
    // NEW: Customer Card validation using entered card number
    elseif ($payment_method === 'customer_card' && empty($entered_card_number)) {
        $error_message = 'يرجى إدخال رقم بطاقة العميل عند استخدام طريقة "بطاقة العميل".';
    }
    elseif ($payment_method === 'customer_card' && !empty($entered_card_number)) {
        // Validate card existence and balance by card_number
        $card_check_stmt = $db->prepare("SELECT id, current_balance, customer_id FROM customer_cards WHERE card_number = ? AND status = 'active'");
        $card_check_stmt->execute([$entered_card_number]);
        $card_data = $card_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card_data) {
            $error_message = 'رقم بطاقة العميل المحدد غير صالح أو غير نشط.';
        } 
        // IMPORTANT: Removed the check if card_data['customer_id'] != $payment_customer_id
        // to allow any customer to use any valid card number.
        // If you want to re-enable it, uncomment the following block:
        /*
        elseif ($card_data['customer_id'] != $payment_customer_id) {
            $error_message = 'بطاقة العميل هذه لا تنتمي للعميل المحدد.';
        }
        */
        elseif ($amount > $card_data['current_balance']) {
            $error_message = 'المبلغ المدفوع ('. number_format($amount, 2) .') يتجاوز الرصيد المتاح في البطاقة ('. number_format($card_data['current_balance'], 2) .').';
        } else {
            // Card found and valid, store its ID for the payment record
            $customer_card_id = $card_data['id'];
        }
    }
    // END NEW Customer Card validation
    elseif ($payment_invoice_id > 0) {
        // Re-validate amount against the selected invoice's remaining balance from server
        $chk_stmt = $db->prepare("SELECT id, total_amount, status, customer_id, order_id FROM customer_invoices WHERE id = ?");
        $chk_stmt->execute([$payment_invoice_id]);
        $chk_inv = $chk_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chk_inv) {
            $error_message = 'الفاتورة المحددة غير موجودة.';
        } elseif ($chk_inv['customer_id'] != $payment_customer_id) {
             $error_message = 'هذه الدفعة لا تتطابق مع العميل المحدد للفاتورة.';
        } else {
            $real_total = (float)($chk_inv['total_amount'] ?? 0);
            $pd_stmt = $db->prepare("SELECT SUM(amount) FROM customer_payments WHERE invoice_id = ?");
            $pd_stmt->execute([$payment_invoice_id]);
            $real_paid = (float)($pd_stmt->fetchColumn() ?? 0);
            $current_remaining = $real_total - $real_paid;

            // No strict overpayment check for now as per previous instructions.
        }
    }

    // Process submission if no errors encountered yet
    if (empty($error_message)) {
        try {
            $db->beginTransaction();

            // Handle Receipt Image Upload
            $receipt_path_for_db = null;
            if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt_image'];
                $upload_dir = '../../uploads/receipts/';
                // Ensure directory exists
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0775, true);
                }
                
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
                $max_size = 5000000; // 5MB

                if (!in_array($file_ext, $allowed_exts)) {
                    throw new Exception('نوع الملف غير مدعوم. يُسمح بـ JPG, PNG, PDF.');
                }
                if ($file['size'] > $max_size) {
                    throw new Exception('حجم الملف أكبر من الحد المسموح به (5MB).');
                }

                $new_filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file_ext;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                    $receipt_path_for_db = 'uploads/receipts/' . $new_filename;
                } else {
                    throw new Exception('فشل في رفع صورة الإيصال. يرجى التحقق من أذونات المجلد.');
                }
            }

            // Insert Payment Record
            $payment_number = 'PAY-' . date('Ymd') . '-' . rand(1000, 9999); // Generate a unique payment number
            // Ensure `customer_card_id` column exists in `customer_payments` table
            // Added `added_by` column
            $stmt = $db->prepare("INSERT INTO customer_payments (payment_number, customer_id, invoice_id, amount, currency, payment_method, bank_account_id, customer_card_id, payment_date, reference_number, notes, receipt_image_path, created_by, added_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $payment_number, 
                $payment_customer_id, 
                $payment_invoice_id > 0 ? $payment_invoice_id : null, // Link to invoice if selected
                $amount, 
                $currency, 
                $payment_method, 
                $bank_account_id, 
                $customer_card_id, // NEW: Link to customer card ID found by number
                $payment_date, 
                $reference_number, 
                $notes, 
                $receipt_path_for_db, 
                $_SESSION['user_id'], // created_by
                $added_by_user_id // NEW: added_by
            ]);
            $new_payment_id = $db->lastInsertId();

            // NEW: Update Customer Card Balance and Log Transaction
            if ($payment_method === 'customer_card' && !empty($customer_card_id)) {
                // DECREASE CARD BALANCE HERE!
                $db->prepare("UPDATE customer_cards SET current_balance = current_balance - ? WHERE id = ?")
                   ->execute([$amount, $customer_card_id]);
                
                // Log card transaction
                $db->prepare("INSERT INTO customer_card_transactions (card_id, transaction_type, amount, description, reference_id, created_by) VALUES (?, 'spend', ?, ?, ?, ?)")
                   ->execute([$customer_card_id, $amount, 'استخدام للدفعة رقم ' . $payment_number, $new_payment_id, $_SESSION['user_id']]);

                // Check if card balance reached zero, update status if needed
                $card_balance_check = $db->prepare("SELECT current_balance FROM customer_cards WHERE id = ?");
                $card_balance_check->execute([$customer_card_id]);
                if (($card_balance_check->fetchColumn() ?? 0) <= 0.01) { // Small tolerance
                    $db->prepare("UPDATE customer_cards SET status = 'inactive' WHERE id = ?")->execute([$customer_card_id]);
                }
            }
            // END NEW Customer Card Update

            // Update Invoice Status AND Order Paid Amount if a specific invoice was linked
            if ($payment_invoice_id > 0) {
                $inv_detail_stmt = $db->prepare("SELECT total_amount, order_id FROM customer_invoices WHERE id = ?");
                $inv_detail_stmt->execute([$payment_invoice_id]);
                $inv_details = $inv_detail_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$inv_details) {
                     throw new Exception("لم يتم العثور على تفاصيل الفاتورة أثناء تحديث الحالة.");
                }

                $invoice_total_amount = (float)($inv_details['total_amount'] ?? 0);
                $order_id_to_update = (int)($inv_details['order_id'] ?? 0);

                $pd_stmt_recalc = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE invoice_id = ?");
                $pd_stmt_recalc->execute([$payment_invoice_id]);
                $new_total_paid_on_invoice = (float)($pd_stmt_recalc->fetchColumn() ?? 0);
                
                // 1. Update Invoice Status
                $new_status = 'pending'; // Default status
                if ($new_total_paid_on_invoice >= ($invoice_total_amount - 0.5)) { // Fully paid (with tolerance)
                    $new_status = 'paid';
                } else { // Partially paid
                    $new_status = 'partially_paid';
                }

                $db->prepare("UPDATE customer_invoices SET status = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$new_status, $payment_invoice_id]);

                // 2. UPDATE ORDER PAID_AMOUNT
                if ($order_id_to_update > 0) {
                    $order_paid_recalc_stmt = $db->prepare("
                        SELECT COALESCE(SUM(cp.amount), 0)
                        FROM customer_payments cp
                        JOIN customer_invoices ci ON cp.invoice_id = ci.id
                        WHERE ci.order_id = ?
                    ");
                    $order_paid_recalc_stmt->execute([$order_id_to_update]);
                    $new_order_paid_amount = (float)($order_paid_recalc_stmt->fetchColumn() ?? 0);

                    $db->prepare("UPDATE customer_orders SET paid_amount = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$new_order_paid_amount, $order_id_to_update]);
                }
            }

            // Update Bank/Cash Account Balance
            if ($payment_method === 'transfer' && !empty($bank_account_id)) {
                $db->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE id = ?")
                   ->execute([$amount, $bank_account_id]);
            } elseif ($payment_method === 'cash') {
                $db->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE bank_name = 'الصندوق'")
                   ->execute([$amount]);
            }
            
            // ===================================================================
            // START: ACCOUNTING LOGIC FOR NEW PAYMENT
            // ===================================================================
            try {
                // Get the relevant account IDs from settings
                $ar_account_id = get_accounting_setting($db, 'default_accounts_receivable_id'); // Accounts Receivable (Customer Debt)
                $cash_account_id = get_accounting_setting($db, 'default_cash_account_id'); // Default Cash Account
                $customer_deposit_liability_id = get_accounting_setting($db, 'default_customer_deposit_liability_id'); // New: Liability for customer card balances
                
                $receiving_account_id = null; // This will be the account that RECEIVES the money (or reduces liability)

                // Determine the receiving account based on payment method
                if ($payment_method === 'transfer' && !empty($bank_account_id)) {
                    $bank_stmt = $db->prepare("SELECT account_id FROM bank_accounts WHERE id = ?");
                    $bank_stmt->execute([$bank_account_id]);
                    $receiving_account_id = $bank_stmt->fetchColumn();
                } elseif ($payment_method === 'cash') {
                    $receiving_account_id = $cash_account_id;
                } elseif ($payment_method === 'customer_card') {
                    // If customer card, the money is 'received' from the liability account (Customer Deposit)
                    $receiving_account_id = $customer_deposit_liability_id;
                }

                if (empty($receiving_account_id) || empty($ar_account_id)) {
                    throw new Exception("إعدادات الحسابات المحاسبية (AR أو حساب الاستلام) مفقودة. لن يتم إنشاء قيد محاسبي.");
                }

                $cust_stmt = $db->prepare("SELECT name FROM customers WHERE id = ?");
                $cust_stmt->execute([$payment_customer_id]);
                $customer_name = $cust_stmt->fetchColumn();

                $description = "تحصيل دفعة من العميل " . ($customer_name ?: "#$payment_customer_id") . " (رقم الدفعة: $payment_number)";

                $entry_items = [];

                if ($payment_method === 'customer_card') {
                    // Debit: Customer Deposit Liability (reduces liability)
                    // Credit: Accounts Receivable (reduces customer debt)
                    $entry_items = [
                        ['account_id' => $receiving_account_id, 'type' => 'debit', 'amount' => $amount], // Debit Liability
                        ['account_id' => $ar_account_id, 'type' => 'credit', 'amount' => $amount],      // Credit AR
                    ];
                } else {
                    // Existing accounting for Cash/Transfer payments
                    // Debit: Cash/Bank (money came in)
                    // Credit: Accounts Receivable (customer debt decreased)
                    $entry_items = [
                        ['account_id' => $receiving_account_id, 'type' => 'debit', 'amount' => $amount],
                        ['account_id' => $ar_account_id, 'type' => 'credit', 'amount' => $amount],
                    ];
                }

                create_journal_entry(
                    $db,
                    $payment_date,
                    $description,
                    $entry_items,
                    'payments',
                    $new_payment_id,
                    $_SESSION['user_id']
                );

            } catch (Exception $acc_e) {
                error_log("Accounting entry failed for Payment ID $new_payment_id: " . $acc_e->getMessage());
            }
            // ===================================================================
            // END: ACCOUNTING LOGIC
            // ===================================================================

            $db->commit();
            
            header("Location: view.php?id=$new_payment_id&success=added");
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = 'حدث خطأ: ' . $e->getMessage();
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
                        <h1 class="text-2xl font-bold text-gray-900">إضافة دفعة جديدة</h1>
                        <p class="text-gray-600 mt-1">
                            <?php 
                            if ($invoice) echo "مرتبطة بالفاتورة: " . htmlspecialchars($invoice['invoice_number']);
                            elseif ($customer) echo "للعميل: " . htmlspecialchars($customer['name']); 
                            ?>
                        </p>
                    </div>
                    <!-- Back Navigation -->
                    <div>
                        <?php if ($invoice): ?>
                            <a href="../invoices/view.php?id=<?php echo $invoice_id; ?>" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"><i class="fas fa-arrow-right ml-2"></i> العودة للفاتورة</a>
                        <?php elseif ($customer): ?>
                            <a href="../customers/view_enhanced.php?id=<?php echo $customer_id; ?>&tab=payments" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition"><i class="fas fa-arrow-right ml-2"></i> العودة للعميل</a>
                        <?php endif; ?>
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

        <!-- Payment Form -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-800">بيانات الدفعة</h2>
            </div>

            <form method="POST" class="p-6" enctype="multipart/form-data">
                <!-- Hidden fields for IDs -->
                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                
                <?php if ($payment_invoice_id > 0): ?>
                    <input type="hidden" name="invoice_id" value="<?php echo $payment_invoice_id; ?>">
                <?php elseif ($invoice): ?>
                     <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                <?php endif; ?>

                <!-- Invoice Summary Box (if a specific invoice is selected) -->
                <?php if ($invoice): ?>
                    <div class="mb-8 bg-blue-50 border border-blue-100 rounded-lg p-5">
                        <h3 class="text-md font-bold text-blue-800 mb-3 flex items-center"><i class="fas fa-file-invoice ml-2"></i> تفاصيل الفاتورة</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <p class="text-xs text-blue-600 uppercase mb-1">رقم الفاتورة</p>
                                <p class="font-bold text-gray-900 text-lg"><?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-blue-600 uppercase mb-1">إجمالي الفاتورة</p>
                                <p class="font-bold text-gray-900 text-lg"><?php echo number_format($total_amount_for_invoice, 2); ?> ريال</p>
                            </div>
                            <div>
                                <p class="text-xs text-blue-600 uppercase mb-1">المبلغ المتبقي</p>
                                <p class="font-bold text-red-600 text-lg"><?php echo number_format($remaining_amount, 2); ?> ريال</p>
                            </div>
                        </div>
                    </div>
                
                <!-- Unpaid Invoices Dropdown (if customer selected but no specific invoice) -->
                <?php elseif (!empty($unpaid_invoices)): ?>
                    <div class="mb-6">
                        <label for="invoice_id_dropdown" class="block text-sm font-bold text-gray-700 mb-2">ربط الدفعة بفاتورة (اختياري)</label>
                        <div class="relative">
                            <select id="invoice_id_dropdown" name="invoice_id" class="w-full pl-3 pr-10 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none bg-white">
                                <option value="">-- لا ترتبط بفاتورة محددة --</option>
                                <?php foreach ($unpaid_invoices as $inv): ?>
                                    <option value="<?php echo $inv['id']; ?>" data-remaining="<?php echo htmlspecialchars(number_format($inv['calculated_remaining'], 2, '.', '')); ?>">
                                        فاتورة #<?php echo htmlspecialchars($inv['invoice_number']); ?> | المتبقي: <?php echo number_format($inv['calculated_remaining'], 2); ?> ريال
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 left-0 flex items-center px-2 pointer-events-none">
                                <i class="fas fa-chevron-down text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Payment Details Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Amount Input -->
                    <div>
                        <label for="amount" class="block text-sm font-bold text-gray-700 mb-2">المبلغ المدفوع <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="number" id="amount" name="amount" step="0.01" min="0.01" 
                                   value="<?php echo ($invoice && $remaining_amount > 0) ? number_format($remaining_amount, 2, '.', '') : ''; ?>" 
                                   class="w-full pl-20 pr-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-lg" required>
                            <div class="absolute inset-y-0 left-0 flex items-center bg-gray-100 border-r border-gray-300 rounded-l-lg px-3">
                                <span class="text-gray-500 font-bold text-sm">YER</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Method Selection -->
                    <div>
                        <label for="payment_method" class="block text-sm font-bold text-gray-700 mb-2">طريقة الدفع <span class="text-red-500">*</span></label>
                        <select id="payment_method" name="payment_method" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="cash" selected>نقدي (الصندوق)</option>
                            <option value="transfer">تحويل بنكي / إيداع</option>
                            <!-- ALWAYS SHOW CUSTOMER CARD OPTION -->
                            <option value="customer_card">بطاقة العميل</option>
                        </select>
                    </div>
                </div>

                <!-- Bank Account Selection (Initially hidden) -->
                <div id="bankAccountDiv" class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200" style="display: none;">
                    <label for="bank_account_id" class="block text-sm font-bold text-gray-700 mb-2">الحساب البنكي المستلم <span class="text-red-500">*</span></label>
                    <select id="bank_account_id" name="bank_account_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- اختر الحساب البنكي --</option>
                        <?php foreach ($bank_accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>">
                                <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_holder_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- NEW: Customer Card Number Input (Initially hidden) -->
                <div id="customerCardDiv" class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200" style="display: none;">
                    <label for="customer_card_number" class="block text-sm font-bold text-gray-700 mb-2">رقم بطاقة العميل <span class="text-red-500">*</span></label>
                    <input type="text" id="customer_card_number" name="customer_card_number" 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="أدخل رقم البطاقة هنا" pattern="[0-9]*" inputmode="numeric">
                    <p id="cardBalanceInfo" class="text-sm text-gray-600 mt-2" style="display: none;">الرصيد المتاح: <span class="font-bold text-blue-700"></span></p>
                </div>

                <!-- Date, Reference, Notes -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <div>
                        <label for="payment_date" class="block text-sm font-bold text-gray-700 mb-2">تاريخ الدفع <span class="text-red-500">*</span></label>
                        <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <div>
                        <label for="reference_number" class="block text-sm font-bold text-gray-700 mb-2">رقم المرجع (اختياري)</label>
                        <input type="text" id="reference_number" name="reference_number" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="مثال: رقم الحوالة، رقم الشيك...">
                    </div>
                </div>

                <div class="mt-6">
                    <label for="notes" class="block text-sm font-bold text-gray-700 mb-2">ملاحظات</label>
                    <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="أي تفاصيل إضافية..."></textarea>
                </div>

                <!-- Receipt Upload Area -->
                <div class="mt-6">
                    <label for="receipt_image" class="block text-sm font-bold text-gray-700 mb-2">صورة الإيصال (اختياري)</label>
                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:bg-gray-50 transition">
                        <div class="space-y-1 text-center">
                            <i class="fas fa-cloud-upload-alt text-gray-400 text-3xl mb-3"></i>
                            <div class="flex text-sm text-gray-600 justify-center">
                                <label for="receipt_image" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none">
                                    <span>اختر ملفاً</span>
                                    <input id="receipt_image" name="receipt_image" type="file" class="sr-only" accept="image/jpeg,image/png,application/pdf">
                                </label>
                                <p class="pr-1"> أو اسحبه هنا</p>
                            </div>
                            <p class="text-xs text-gray-500">PNG, JPG, PDF حتى 5MB</p>
                        </div>
                    </div>
                    <!-- Image Preview Container -->
                    <div id="receiptImagePreviewContainer" class="mt-4 hidden">
                        <h3 class="text-sm font-bold text-gray-700 mb-2">معاينة الإيصال:</h3>
                        <img id="receiptImagePreview" class="max-w-full h-auto max-h-60 object-contain rounded-lg border border-gray-300 shadow-sm" src="#" alt="Receipt Preview" />
                        <button type="button" id="removeReceiptImage" class="mt-2 text-red-600 hover:text-red-800 text-sm flex items-center">
                            <i class="fas fa-times-circle ml-1"></i> إزالة الصورة
                        </button>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end mt-8 pt-6 border-t border-gray-200 gap-3">
                    <a href="javascript:history.back()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-bold">إلغاء</a>
                    <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition shadow-lg font-bold flex items-center">
                        <i class="fas fa-save ml-2"></i> حفظ الدفعة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript for dynamic form interactions -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const paymentMethodSelect = document.getElementById('payment_method');
        const bankAccountContainer = document.getElementById('bankAccountDiv');
        const bankAccountSelect = document.getElementById('bank_account_id');
        const customerCardContainer = document.getElementById('customerCardDiv');
        const customerCardNumberInput = document.getElementById('customer_card_number'); // NEW: Input field
        const cardBalanceInfo = document.getElementById('cardBalanceInfo');
        const cardBalanceSpan = cardBalanceInfo ? cardBalanceInfo.querySelector('span') : null;

        const form = document.querySelector('form');
        const amountInput = document.getElementById('amount');
        const invoiceSelect = document.getElementById('invoice_id_dropdown');
        let isSubmitting = false;

        // NEW: Image preview elements
        const receiptImageInput = document.getElementById('receipt_image');
        const receiptImagePreviewContainer = document.getElementById('receiptImagePreviewContainer');
        const receiptImagePreview = document.getElementById('receiptImagePreview');
        const removeReceiptImageButton = document.getElementById('removeReceiptImage');


        // Function to toggle visibility and requirements of payment method specific fields
        function togglePaymentMethodFields() {
            // Hide all payment specific divs and remove required attributes
            bankAccountContainer.style.display = 'none';
            bankAccountSelect.required = false;
            bankAccountSelect.value = '';

            customerCardContainer.style.display = 'none';
            customerCardNumberInput.required = false; // NEW: Make input not required by default
            customerCardNumberInput.value = ''; // Clear input
            if (cardBalanceInfo) cardBalanceInfo.style.display = 'none'; // Ensure card balance info is hidden

            // Show relevant fields based on selected method
            if (paymentMethodSelect.value === 'transfer') {
                bankAccountContainer.style.display = 'block';
                bankAccountSelect.required = true;
            } else if (paymentMethodSelect.value === 'customer_card') {
                customerCardContainer.style.display = 'block';
                customerCardNumberInput.required = true; // NEW: Make input required for this method
                // We'll fetch balance info dynamically when a number is typed
                if (cardBalanceInfo) cardBalanceInfo.style.display = 'block'; 
                if (cardBalanceSpan) cardBalanceSpan.textContent = '...جار التحقق'; // Initial state
            }
        }
        paymentMethodSelect.addEventListener('change', togglePaymentMethodFields);
        togglePaymentMethodFields(); // Initial check on page load

        // NEW: Dynamic card balance check when card number is typed
        let balanceCheckTimeout;
        customerCardNumberInput.addEventListener('input', function() {
            clearTimeout(balanceCheckTimeout); // Clear previous timeout

            const cardNumber = this.value.trim();
            if (cardNumber.length >= 6) { // Check balance after a reasonable number of digits
                if (cardBalanceSpan) cardBalanceSpan.textContent = '...جاري التحقق';
                
                balanceCheckTimeout = setTimeout(() => {
                    // Make an AJAX call to check card balance and status
                    fetch(`check_card_balance.php?card_number=${encodeURIComponent(cardNumber)}`) // You'll need to create this PHP endpoint
                        .then(response => response.json())
                        .then(data => {
                            if (cardBalanceSpan) {
                                if (data.success && data.card) {
                                    cardBalanceSpan.textContent = `${parseFloat(data.card.current_balance).toFixed(2)} ريال`;
                                    // Store balance on the input for client-side validation later
                                    customerCardNumberInput.setAttribute('data-balance', data.card.current_balance);
                                } else {
                                    cardBalanceSpan.textContent = data.message || 'البطاقة غير موجودة أو غير نشطة.';
                                    customerCardNumberInput.removeAttribute('data-balance'); // Remove balance if card invalid
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching card balance:', error);
                            if (cardBalanceSpan) cardBalanceSpan.textContent = 'خطأ في الاتصال بالخادم.';
                            customerCardNumberInput.removeAttribute('data-balance');
                        });
                }, 500); // Debounce to prevent too many requests
            } else {
                if (cardBalanceSpan) cardBalanceSpan.textContent = 'أدخل رقم البطاقة للتحقق من الرصيد.';
                customerCardNumberInput.removeAttribute('data-balance');
            }
        });

        // Handle Invoice Selection Change: Auto-fill amount if a remaining balance is available
        if (invoiceSelect) {
            invoiceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const remaining = selectedOption.getAttribute('data-remaining');
                
                if (remaining) {
                    const remainingFloat = parseFloat(remaining);
                    amountInput.value = remainingFloat > 0 ? remainingFloat.toFixed(2) : '';
                    amountInput.setAttribute('data-remaining', remainingFloat);
                    amountInput.classList.add('bg-blue-50');
                    setTimeout(() => amountInput.classList.remove('bg-blue-50'), 500);
                } else {
                    amountInput.value = '';
                    amountInput.removeAttribute('data-remaining');
                }
            });
        }

        // Form Submission Handler: Prevent double submission and validate amount
        form.addEventListener('submit', function(e) {
            const amountEntered = parseFloat(amountInput.value);

            // NEW: Client-side validation for customer card balance (using fetched data)
            if (paymentMethodSelect.value === 'customer_card' && customerCardNumberInput.value) {
                const cardBalanceAttr = customerCardNumberInput.getAttribute('data-balance');
                if (!cardBalanceAttr) {
                    e.preventDefault();
                    alert('يرجى التحقق من رقم بطاقة العميل والتأكد من صحتها ورصيدها.');
                    resetSubmitButton();
                    return;
                }
                const cardBalance = parseFloat(cardBalanceAttr);
                if (amountEntered > cardBalance) {
                    e.preventDefault();
                    alert(`خطأ: المبلغ المدفوع (${amountEntered.toFixed(2)} ريال) يتجاوز الرصيد المتاح في البطاقة (${cardBalance.toFixed(2)} ريال).`);
                    resetSubmitButton();
                    return;
                }
            }

            // Prevent double submission
            if (isSubmitting) {
                e.preventDefault();
                return;
            }
            isSubmitting = true;
            
            // Show loading state on submit button
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i> جاري المعالجة...';
            submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
        });

        function resetSubmitButton() {
            isSubmitting = false;
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save ml-2"></i> حفظ الدفعة';
            submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
        }

        // NEW: Image Preview Logic
        receiptImageInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                // Check if it's an image or PDF
                const fileType = file.type;
                if (fileType.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        receiptImagePreview.src = e.target.result;
                        receiptImagePreviewContainer.classList.remove('hidden');
                        receiptImagePreview.classList.remove('hidden'); // Ensure img itself is visible
                    };
                    reader.readAsDataURL(file);
                } else if (fileType === 'application/pdf') {
                    // For PDF, we can't show a direct preview like an image.
                    // We can show a generic PDF icon or just the file name.
                    receiptImagePreview.src = 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/87/PDF_file_icon.svg/1200px-PDF_file_icon.svg.png'; // Generic PDF icon
                    receiptImagePreview.classList.remove('hidden');
                    receiptImagePreviewContainer.classList.remove('hidden');
                    receiptImagePreview.alt = 'معاينة ملف PDF'; // Change alt text
                } else {
                    // Hide if not an image or PDF (should be caught by accept attribute anyway)
                    receiptImagePreviewContainer.classList.add('hidden');
                    receiptImagePreview.src = '#';
                }
            } else {
                receiptImagePreviewContainer.classList.add('hidden');
                receiptImagePreview.src = '#';
            }
        });

        removeReceiptImageButton.addEventListener('click', function() {
            receiptImageInput.value = ''; // Clear the selected file
            receiptImagePreview.src = '#'; // Clear the preview
            receiptImagePreviewContainer.classList.add('hidden'); // Hide the container
        });

    });
</script>

<?php include '../../includes/footer.php'; ?>