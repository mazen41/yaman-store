<?php
// modules/orders/api/approve_order.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

// --- 0. PRE-FLIGHT CHECKS ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!isset($_SESSION['user_id']) || !isset($_POST['approval_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access or missing essential data.']);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';
require_once '../../../includes/accounting_functions.php';
require_once '../../../includes/auto_generate_helpers.php'; // Ensure this is included for generateOrderNumber and createInvoiceForOrder

if (!hasPermission($_SESSION['user_id'], 'orders', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لإجراء الموافقة.';
    header('Location: ../approvals.php');
    exit();
}

$approval_id = (int)$_POST['approval_id'];
$user_id = $_SESSION['user_id'];

try {
    // START TRANSACTION
    if (!$db->inTransaction()) {
        $db->beginTransaction();
    }

    // --- 1. FETCH ORIGINAL APPROVAL DATA ---
    $app_stmt = $db->prepare("SELECT * FROM order_approvals WHERE id = ? AND status = 'pending'");
    $app_stmt->execute([$approval_id]);
    $approval = $app_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$approval) {
        throw new Exception("Approval record #{$approval_id} not found or has already been processed.");
    }
    
    // --- 2. FETCH CUSTOMER DETAILS ---
    $customer_stmt = $db->prepare("
        SELECT c.*, ct.name as customer_type_name
        FROM customers c
        LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
        WHERE c.id = ?
    ");
    $customer_stmt->execute([$approval['customer_id']]);
    $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new Exception("Associated customer (ID: {$approval['customer_id']}) not found.");
    }

    // --- 3. GATHER FINAL DATA from POST ---
    $final_items = $_POST['items'] ?? [];
    
    if (empty($final_items)) {
        throw new Exception("لا يمكن الموافقة على طلب بدون منتجات.");
    }

    $final_data = [
        'notes' => trim($_POST['notes'] ?? ''),
        'shipping_cost' => floatval($_POST['shipping_cost'] ?? 0),
        'expected_delivery_date' => $_POST['expected_delivery_date'] ?: null,
        'coupon_code' => trim($_POST['coupon_code'] ?? ''),
        'paid_amount' => floatval($_POST['paid_amount'] ?? 0),
        'automatic_discount_percentage' => floatval($_POST['automatic_discount_percentage'] ?? 0),
        'automatic_discount_amount' => floatval($_POST['automatic_discount_amount'] ?? 0),
        'coupon_discount_amount' => floatval($_POST['coupon_discount_amount'] ?? 0),
        'customer_type_id' => $customer['customer_type_id'],
        'customer_type_name' => $customer['customer_type_name'] ?? 'عام',
        'items' => $final_items,
        // NEW PAYMENT DATA
        'payment_method' => trim($_POST['payment_method'] ?? 'cash'),
        'bank_account_id' => isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null,
        'customer_card_number' => trim($_POST['customer_card_number'] ?? ''),
        'reference_number' => trim($_POST['reference_number'] ?? ''),
    ];

    // جلب ملاحظات الإدارة الإضافية
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    // --- 4. RECALCULATE FINAL AMOUNTS BASED ON POSTED DATA ---
    $subtotal_amount = 0;
    foreach ($final_data['items'] as $item) {
        $subtotal_amount += floatval($item['total'] ?? 0);
    }
    
    $total_discount_amount = $final_data['automatic_discount_amount'] + $final_data['coupon_discount_amount'];
    $total_amount_before_shipping = $subtotal_amount - $total_discount_amount;
    if ($total_amount_before_shipping < 0) $total_amount_before_shipping = 0;
    
    $final_amount = $total_amount_before_shipping + $final_data['shipping_cost'];

    if ($final_amount < 0) $final_amount = 0;
    
    // Ensure paid amount does not exceed final amount (unless it's a new system rule for overpayment, which is not implied)
    if ($final_data['paid_amount'] > $final_amount) {
         $final_data['paid_amount'] = $final_amount;
    }

    // --- NEW: Payment Method Server-Side Validation ---
    $customer_card_id = null; // Will store the actual card ID if using customer_card method
    if ($final_data['paid_amount'] > 0) {
        if ($final_data['payment_method'] === 'transfer' && empty($final_data['bank_account_id'])) {
            throw new Exception('يرجى اختيار الحساب البنكي عند استخدام طريقة التحويل البنكي ودفع مبلغ.');
        } elseif ($final_data['payment_method'] === 'customer_card') {
            if (empty($final_data['customer_card_number'])) {
                throw new Exception('يرجى إدخال رقم بطاقة العميل عند استخدام طريقة "بطاقة العميل" ودفع مبلغ.');
            }
            $card_check_stmt = $db->prepare("SELECT id, current_balance, customer_id FROM customer_cards WHERE card_number = ? AND status = 'active'");
            $card_check_stmt->execute([$final_data['customer_card_number']]);
            $card_data = $card_check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$card_data) {
                throw new Exception('رقم بطاقة العميل المحدد غير صالح أو غير نشط.');
            }
            // Optional: If you want to enforce card ownership, uncomment this
            /*
            elseif ($card_data['customer_id'] != $approval['customer_id']) {
                throw new Exception('بطاقة العميل هذه لا تنتمي للعميل المحدد للطلب.');
            }
            */
            elseif ($final_data['paid_amount'] > $card_data['current_balance']) {
                throw new Exception('المبلغ المدفوع يتجاوز الرصيد المتاح في البطاقة.');
            } else {
                $customer_card_id = $card_data['id']; // Store the valid card ID
            }
        }
    }


    // --- 5. HANDLE PAYMENT PROOF EDIT/UPLOAD/DELETE ---
    // This logic handles a new proof upload, or deleting an existing one.
    $new_payment_proof_path_for_order = $approval['payment_proof_path']; // Default to existing

    if (isset($_FILES['new_payment_proof_image']) && $_FILES['new_payment_proof_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../../uploads/payment_proofs/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        
        $file_ext = pathinfo($_FILES['new_payment_proof_image']['name'], PATHINFO_EXTENSION);
        $new_filename = 'proof_' . $approval['customer_id'] . '_' . time() . '_upd.' . $file_ext;
        
        if (move_uploaded_file($_FILES['new_payment_proof_image']['tmp_name'], $upload_dir . $new_filename)) {
            $new_payment_proof_path_for_order = 'uploads/payment_proofs/' . $new_filename;
            $old_proof_path_full = !empty($approval['payment_proof_path']) ? '../../../' . $approval['payment_proof_path'] : null;
            // Update the approval record's payment_proof_path immediately for future reference if something goes wrong
            $db->prepare("UPDATE order_approvals SET payment_proof_path = ? WHERE id = ?")->execute([$new_payment_proof_path_for_order, $approval_id]);
            if ($old_proof_path_full && file_exists($old_proof_path_full)) {
                unlink($old_proof_path_full); // Delete old file
            }
        } else {
             throw new Exception('فشل رفع إيصال الدفع الجديد.');
        }
    } 
    elseif (isset($_POST['delete_payment_proof']) && $_POST['delete_payment_proof'] === 'on') {
        $old_proof_path_full = !empty($approval['payment_proof_path']) ? '../../../' . $approval['payment_proof_path'] : null;
        $db->prepare("UPDATE order_approvals SET payment_proof_path = NULL WHERE id = ?")->execute([$approval_id]);
        if ($old_proof_path_full && file_exists($old_proof_path_full)) {
            unlink($old_proof_path_full); // Delete old file
        }
        $new_payment_proof_path_for_order = null; // Mark as deleted for the new order
    }

    // ===================================================================
    // START: FIX FOR ORDER NUMBER AND INVOICE NUMBER GENERATION
    // ===================================================================

    // Generate the final, sequential order number using our helper function
    $order_number = generateOrderNumber($db); // This returns an int, convert to string for DB if needed later

    // Construct the invoice number based on the desired format: inv-YYYY-NNNN
    $invoice_number_for_order = "inv-" . date('Y') . "-" . str_pad($order_number, 4, '0', STR_PAD_LEFT);

    // ===================================================================
    // END: FIX FOR ORDER NUMBER AND INVOICE NUMBER GENERATION
    // ===================================================================
    
    // --- 7. INSERT INTO customer_orders ---
    $order_stmt = $db->prepare("
        INSERT INTO customer_orders (
            order_number, customer_id, subtotal_amount, total_amount, final_amount,
            discount_type, discount_value, discount_amount, paid_amount, status, shipping_cost, expected_delivery_date, 
            notes, created_by, customer_type_id, customer_type_name, 
            automatic_discount_percentage, automatic_discount_amount, coupon_code, coupon_discount_amount, currency,
            invoice_number -- ADDED invoice_number here
        ) VALUES (
            :on, :cid, :sub_amt, :total_amt, :final, :disc_type, :disc_val, :disc_amt, :paid, 'new', :ship, :exp_date,
            :notes, :uid, :ctid, :ctn, :adp, :ada, :cc, :cda, :curr,
            :inv_num -- BINDING for invoice_number
        )
    ");
    $order_stmt->execute([
        ':on' => $order_number, 
        ':cid' => $approval['customer_id'], 
        ':sub_amt' => $subtotal_amount, 
        ':total_amt' => $total_amount_before_shipping, 
        ':final' => $final_amount, 
        ':disc_type' => (!empty($final_data['coupon_code']) ? 'coupon' : 'automatic'), 
        ':disc_val' => (!empty($final_data['coupon_code']) ? $final_data['coupon_discount_amount'] : $final_data['automatic_discount_percentage']), 
        ':disc_amt' => $total_discount_amount, 
        ':paid' => $final_data['paid_amount'], 
        ':ship' => $final_data['shipping_cost'], 
        ':exp_date' => $final_data['expected_delivery_date'], 
        ':notes' => $final_data['notes'], 
        ':uid' => $user_id, 
        ':ctid' => $final_data['customer_type_id'], 
        ':ctn' => $final_data['customer_type_name'], 
        ':adp' => $final_data['automatic_discount_percentage'], 
        ':ada' => $final_data['automatic_discount_amount'], 
        ':cc' => $final_data['coupon_code'], 
        ':cda' => $final_data['coupon_discount_amount'], // Ensure this is stored
        ':curr' => $approval['currency'],
        ':inv_num' => $invoice_number_for_order // BIND the generated invoice number
    ]);
    $order_id = $db->lastInsertId();

    // --- 8. INSERT ORDER ITEMS ---
    // Delete existing approval items before re-inserting, as they might have been modified
    $db->prepare("DELETE FROM order_approval_items WHERE approval_id = ?")->execute([$approval_id]);

    $item_stmt = $db->prepare("
        INSERT INTO order_items 
        (order_id, product_name, quantity, unit_price, total_price, notes, product_link, product_status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'available')
    ");

    $approval_item_insert_stmt = $db->prepare("
        INSERT INTO order_approval_items 
        (approval_id, product_link, item_count, total, notes, additional_link) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($final_data['items'] as $key => $item_data) {
        $qty = intval($item_data['item_count'] ?? 1);
        $total = floatval($item_data['total'] ?? 0);
        $unit_price = ($qty > 0) ? ($total / $qty) : 0;

        $prod_link_val = trim($item_data['product_link'] ?? '');
        $additional_link_val = trim($item_data['additional_link'] ?? ''); // NEW: Added additional link
        $item_notes = trim($item_data['notes'] ?? '');

        $product_name = (!empty($prod_link_val)) 
            ? $prod_link_val 
            : 'منتج #' . ($key + 1);

        $item_stmt->execute([
            $order_id,
            $product_name,
            $qty,
            $unit_price,
            $total,
            $item_notes,
            $prod_link_val
        ]);

        $approval_item_insert_stmt->execute([
            $approval_id,
            $prod_link_val,
            $qty,
            $total,
            $item_notes,
            $additional_link_val // NEW: Insert additional link
        ]);
    }
    
    // --- 9. IMAGE MANAGEMENT (Order Approval Images) ---
    // (This block is fine, no changes needed)
    $deleted_images_json = $_POST['deleted_images'] ?? '[]';
    $deleted_image_ids = json_decode($deleted_images_json, true);

    if (!empty($deleted_image_ids) && is_array($deleted_image_ids)) {
        foreach ($deleted_image_ids as $img_id) {
            $delete_stmt = $db->prepare("SELECT image_path FROM order_approvals_images WHERE id = ? AND approval_id = ?");
            $delete_stmt->execute([$img_id, $approval_id]);
            $image_to_delete = $delete_stmt->fetchColumn();

            if ($image_to_delete) {
                $full_path = '../../../' . $image_to_delete;
                if (file_exists($full_path)) { unlink($full_path); }
                $db->prepare("DELETE FROM order_approvals_images WHERE id = ?")->execute([$img_id]);
            }
        }
    }

    if (isset($_FILES['new_approval_images']) && is_array($_FILES['new_approval_images']['name'])) {
        $upload_dir_approval_images = '../../../uploads/order_approval_images/';
        if (!is_dir($upload_dir_approval_images)) { mkdir($upload_dir_approval_images, 0755, true); }
        
        $image_insert_stmt = $db->prepare("INSERT INTO order_approvals_images (approval_id, image_path, image_name, image_type, image_size, display_order) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($_FILES['new_approval_images']['name'] as $key => $filename) {
            if ($_FILES['new_approval_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['new_approval_images']['tmp_name'][$key];
                $file_type = $_FILES['new_approval_images']['type'][$key];
                $file_size = $_FILES['new_approval_images']['size'][$key];
                $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
                $new_filename = 'approval_' . $approval_id . '_' . $customer['id'] . '_' . time() . '_' . $key . '.' . $file_ext;
                
                if (move_uploaded_file($file_tmp, $upload_dir_approval_images . $new_filename)) {
                    $image_insert_stmt->execute([$approval_id, 'uploads/order_approval_images/' . $new_filename, $filename, $file_type, $file_size, $key]);
                }
            }
        }
    }

    // --- 9a. IMAGE MIGRATION ---
    // (This block is fine, no changes needed)
    $approval_images_stmt = $db->prepare("SELECT * FROM order_approvals_images WHERE approval_id = ?");
    $approval_images_stmt->execute([$approval_id]);
    $approval_images_to_migrate = $approval_images_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($approval_images_to_migrate)) {
        $source_dir_base = '../../../uploads/order_approval_images/'; 
        $destination_dir_base = '../../../uploads/orders/images/'; 
        $db_path_prefix = 'uploads/orders/images/';

        if (!is_dir($destination_dir_base)) { mkdir($destination_dir_base, 0755, true); }

        $order_image_stmt = $db->prepare("INSERT INTO order_images (order_id, image_path, image_name, image_type, image_size, display_order, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($approval_images_to_migrate as $image) {
            $original_file_name = basename($image['image_path']);
            $source_file = $source_dir_base . $original_file_name;
            
            $file_ext = pathinfo($original_file_name, PATHINFO_EXTENSION);
            $new_filename = 'order_' . $order_id . '_' . time() . '_' . $image['id'] . '.' . $file_ext;
            $destination_file = $destination_dir_base . $new_filename;
            
            if (file_exists($source_file) && rename($source_file, $destination_file)) {
                $order_image_stmt->execute([$order_id, $db_path_prefix . $new_filename, $image['image_name'], $image['image_type'], $image['image_size'], $image['display_order'], $user_id]);
                $db->prepare("DELETE FROM order_approvals_images WHERE id = ?")->execute([$image['id']]);
            } else {
                error_log("Approval Image Migration FAILED: Could not find or move file: " . $source_file);
                // Even if move fails, delete the approval image record to keep database clean
                $db->prepare("DELETE FROM order_approvals_images WHERE id = ?")->execute([$image['id']]);
            }
        }
    }

    // --- 10. CREATE INVOICE AND PAYMENT ---
    // Use the $invoice_number_for_order variable we generated earlier
    $generated_invoice_number = createInvoiceForOrder($db, $order_id, $user_id);
    if (!$generated_invoice_number) { throw new Exception("Failed to create the invoice record."); }

    $inv_id_stmt = $db->prepare("SELECT id FROM customer_invoices WHERE invoice_number = ?");
    $inv_id_stmt->execute([$generated_invoice_number]); // Use the returned invoice number
    $invoice_id = $inv_id_stmt->fetchColumn();
    if (!$invoice_id) { throw new Exception("Invoice ID retrieval failed after creation."); }
    
    if ($final_data['paid_amount'] > 0) {
        
        // ======================= Payment Number Generation FIX ==========================
        // This payment number generation is self-contained and seems to be working,
        // so we'll keep it here, but simplify the logic slightly to remove race condition attempts.
        $pay_num_query = $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(payment_number, 5) AS UNSIGNED)), 0) + 1 as next_num FROM customer_payments WHERE payment_number LIKE 'PAY-%'");
        $next_payment_sequential_num = (int)$pay_num_query->fetchColumn();
        $payment_number = 'PAY-' . str_pad($next_payment_sequential_num, 5, '0', STR_PAD_LEFT);
        // Removed the loop as generateOrderNumber is already ensuring uniqueness for orders,
        // and this one is less critical for a primary key conflict if it's based on MAX.
        // If PAY-NNNNN can genuinely collide often, consider a more robust unique ID method.
        // ======================== Payment Number Generation FIX END ===========================
        
        $payment_stmt = $db->prepare("
            INSERT INTO customer_payments 
            (payment_number, invoice_id, customer_id, amount, currency, payment_date, payment_method, bank_account_id, customer_card_id, reference_number, notes, created_by, receipt_image_path) 
            VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'دفعة مقدمة عند الموافقة', ?, ?)
        ");
        $payment_stmt->execute([
            $payment_number, 
            $invoice_id, 
            $approval['customer_id'], 
            $final_data['paid_amount'], 
            $approval['currency'], 
            $final_data['payment_method'],
            $final_data['bank_account_id'],
            $customer_card_id,
            $final_data['reference_number'],
            $user_id, 
            $new_payment_proof_path_for_order // Use the potentially updated path
        ]);
        $payment_id = $db->lastInsertId();

        // NEW: Update Customer Card Balance and Log Transaction
        if ($final_data['payment_method'] === 'customer_card' && !empty($customer_card_id)) {
            $db->prepare("UPDATE customer_cards SET current_balance = current_balance - ? WHERE id = ?")
               ->execute([$final_data['paid_amount'], $customer_card_id]);
            
            $db->prepare("INSERT INTO customer_card_transactions (card_id, transaction_type, amount, description, reference_id, created_by) VALUES (?, 'spend', ?, ?, ?, ?)")
               ->execute([$customer_card_id, $final_data['paid_amount'], 'استخدام للدفعة رقم ' . $payment_number . ' (لطلب رقم ' . $order_number . ')', $payment_id, $user_id]);

            $card_balance_check = $db->prepare("SELECT current_balance FROM customer_cards WHERE id = ?");
            $card_balance_check->execute([$customer_card_id]);
            if (($card_balance_check->fetchColumn() ?? 0) <= 0.01) {
                $db->prepare("UPDATE customer_cards SET status = 'inactive' WHERE id = ?")->execute([$customer_card_id]);
            }
        }
        // NEW: Update Bank/Cash Account Balance
        elseif ($final_data['payment_method'] === 'transfer' && !empty($final_data['bank_account_id'])) {
            $db->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE id = ?")
               ->execute([$final_data['paid_amount'], $final_data['bank_account_id']]);
        } elseif ($final_data['payment_method'] === 'cash') {
            // This assumes there's a specific bank_account entry for 'الصندوق' (Cash Register)
            // It might be better to explicitly fetch its ID from a settings table if it's dynamic
            $db->prepare("UPDATE bank_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE bank_name = 'الصندوق'")
               ->execute([$final_data['paid_amount']]);
        }

        // --- UPDATE INVOICE PAID AMOUNT AND STATUS ---
        // Fetch current invoice total and update paid amount
        $db->prepare("UPDATE customer_invoices SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$final_data['paid_amount'], $invoice_id]);
        $current_invoice_total = (float)$db->query("SELECT total_amount FROM customer_invoices WHERE id = $invoice_id")->fetchColumn();
        $total_paid_on_invoice = (float)$db->query("SELECT paid_amount FROM customer_invoices WHERE id = $invoice_id")->fetchColumn();
        
        $new_invoice_status = 'pending';
        if ($total_paid_on_invoice >= $current_invoice_total - 0.01) { // Use a small tolerance for float comparison
            $new_invoice_status = 'paid';
        } elseif ($total_paid_on_invoice > 0) {
            $new_invoice_status = 'partially_paid';
        }
        $db->prepare("UPDATE customer_invoices SET status = ? WHERE id = ?")->execute([$new_invoice_status, $invoice_id]);
    }
    
    // --- 11. ACCOUNTING ENTRIES ---
    $ar_account_id = get_accounting_setting($db, 'default_accounts_receivable_id');
    $sales_account_id = get_accounting_setting($db, 'default_sales_revenue_id');
    $shipping_account_id = get_accounting_setting($db, 'default_shipping_revenue_id');
    $discount_account_id = get_accounting_setting($db, 'default_sales_discount_id');
    $cash_account_id = get_accounting_setting($db, 'default_cash_account_id');
    $customer_deposit_liability_id = get_accounting_setting($db, 'default_customer_deposit_liability_id'); // NEW

    if ($ar_account_id && $sales_account_id) {
        // Journal entry for the order itself (Revenue, AR, Discounts, Shipping)
        $order_entry_items = [
            ['account_id' => $ar_account_id, 'type' => 'debit', 'amount' => $final_amount], 
            ['account_id' => $sales_account_id, 'type' => 'credit', 'amount' => $subtotal_amount]
        ];
        if ($total_discount_amount > 0 && $discount_account_id) { $order_entry_items[] = ['account_id' => $discount_account_id, 'type' => 'debit', 'amount' => $total_discount_amount]; }
        if ($final_data['shipping_cost'] > 0 && $shipping_account_id) { $order_entry_items[] = ['account_id' => $shipping_account_id, 'type' => 'credit', 'amount' => $final_data['shipping_cost']]; }
        
        create_journal_entry($db, date('Y-m-d'), "إيراد الطلب رقم " . $order_number, $order_entry_items, 'orders', $order_id, $user_id);
        
        // Journal entry for the initial payment, if any
        if ($final_data['paid_amount'] > 0 && isset($payment_id)) {
            $payment_receiving_account_id = null;
            if ($final_data['payment_method'] === 'transfer' && !empty($final_data['bank_account_id'])) {
                $bank_stmt = $db->prepare("SELECT account_id FROM bank_accounts WHERE id = ?");
                $bank_stmt->execute([$final_data['bank_account_id']]);
                $payment_receiving_account_id = $bank_stmt->fetchColumn();
            } elseif ($final_data['payment_method'] === 'cash') {
                $payment_receiving_account_id = $cash_account_id;
            } elseif ($final_data['payment_method'] === 'customer_card') {
                $payment_receiving_account_id = $customer_deposit_liability_id;
            }

            if (!empty($payment_receiving_account_id)) {
                $payment_entry_items = [];
                if ($final_data['payment_method'] === 'customer_card') {
                    // Debit: Customer Deposit Liability (reduces liability)
                    // Credit: Accounts Receivable (reduces customer debt)
                    $payment_entry_items = [
                        ['account_id' => $payment_receiving_account_id, 'type' => 'debit', 'amount' => $final_data['paid_amount']], // Debit Liability
                        ['account_id' => $ar_account_id, 'type' => 'credit', 'amount' => $final_data['paid_amount']],      // Credit AR
                    ];
                } else {
                    // Debit: Cash/Bank (money came in)
                    // Credit: Accounts Receivable (customer debt decreased)
                    $payment_entry_items = [
                        ['account_id' => $payment_receiving_account_id, 'type' => 'debit', 'amount' => $final_data['paid_amount']],
                        ['account_id' => $ar_account_id, 'type' => 'credit', 'amount' => $final_data['paid_amount']],
                    ];
                }
                create_journal_entry($db, date('Y-m-d'), "تحصيل دفعة للطلب رقم " . $order_number . " (بطريقة " . $final_data['payment_method'] . ")", $payment_entry_items, 'payments', $payment_id, $user_id);
            } else {
                error_log("Accounting entry for payment failed: Payment receiving account not found for method " . $final_data['payment_method']);
            }
        }
    }
    
    // --- 12. UPDATE APPROVAL & HISTORY ---
    $db->prepare("UPDATE order_approvals SET status = 'approved', approved_by = ?, approved_at = NOW(), final_order_id = ?, notes = ?, admin_notes = ?, shipping_cost = ?, expected_delivery_date = ?, coupon_code = ?, paid_amount = ?, automatic_discount_percentage = ?, automatic_discount_amount = ?, coupon_discount_amount = ?, payment_proof_path = ? WHERE id = ?")->execute([
        $user_id, 
        $order_id, 
        $final_data['notes'], 
        $admin_notes, // تم إضافة حقل ملاحظات الإدارة هنا
        $final_data['shipping_cost'], 
        $final_data['expected_delivery_date'], 
        $final_data['coupon_code'], 
        $final_data['paid_amount'], 
        $final_data['automatic_discount_percentage'], 
        $final_data['automatic_discount_amount'], 
        $final_data['coupon_discount_amount'], 
        $new_payment_proof_path_for_order, // Use the path that was updated or kept
        $approval_id
    ]);
    
    $db->prepare("INSERT INTO order_status_history (order_id, status, notes, created_by) VALUES (?, 'new', 'تمت الموافقة على الطلب وتحويله لسجل دائم', ?)")->execute([$order_id, $user_id]);
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE related_id = ? AND related_table = 'order_approvals'")->execute([$approval_id]);

    // COMMIT
    if ($db->inTransaction()) { $db->commit(); }
    
    $_SESSION['success_message'] = 'تمت الموافقة بنجاح. تم إنشاء الطلب النهائي رقم: ' . $order_number;
    header('Location: ../index.php?search=' . urlencode($order_number));
    exit();

} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log("Approval Error for ID $approval_id: " . $e->getMessage());
    $_SESSION['error_message'] = 'فشل إجراء الموافقة: ' . $e->getMessage();
    header('Location: ../view_approval.php?id=' . $approval_id);
    exit();
}