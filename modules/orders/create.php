<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php';

// Check add permission
if (!hasPermission($_SESSION['user_id'], 'orders', 'add')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لإنشاء طلب جديد';
    header('Location: index.php');
    exit();
}

// Get user role and admin status for permission checks
$user_role = $_SESSION['role'] ?? 'employee';
$is_super_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

require_once '../../includes/auto_generate_helpers.php'; // Ensure this file is included for createInvoiceForOrder and generateOrderNumber
require_once 'discount_functions.php';
require_once '../../includes/shein_helpers.php';

sheinEnsureSchema($db);

$page_title = 'إنشاء طلب جديد';
$error_message = '';
$success_message = '';
$creator_name = $_SESSION['username'] ?? 'المستخدم الحالي';

// Get discount rules
$discount_rules = getAllDiscountRules($db);

// Get customers for dropdown with customer type information
$customers_stmt = $db->prepare("
    SELECT c.id, c.name, c.customer_code, c.mobile_number, c.whatsapp_number, c.email, c.city_id, c.city_name, c.currency,
           ct.id as type_id, ct.name as type_name, ct.discount_percentage as type_discount
    FROM customers c
    LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
    WHERE c.is_active = 1
    ORDER BY c.name
");
$customers_stmt->execute();
$customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get products for dropdown
try {
    $products_stmt = $db->prepare("SELECT id, name, price FROM products WHERE is_active = 1 ORDER BY name");
    $products_stmt->execute();
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If products table doesn't exist yet
    $products = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $expected_delivery_date = $_POST['expected_delivery_date'] ?? null;
    $order_link = trim($_POST['order_link'] ?? '');
    $additional_link = trim($_POST['additional_link'] ?? '');
    $notification_method = $_POST['notification_method'] ?? [];
    $coupon_code = trim($_POST['coupon_code'] ?? '');
    $shein_items = $_POST['shein_items'] ?? [];
    
    // START: New field for editable discount
    $automatic_discount_percentage = floatval($_POST['automatic_discount_percentage'] ?? 0);
    // END: New field

    // 1. CLEAN UP ITEMS: Remove empty items to prevent "Product #1" ghost values
    $clean_items = [];
    foreach ($items as $itm) {
        $p_link = trim($itm['product_link'] ?? $itm['link'] ?? '');
        $p_total = floatval($itm['total'] ?? 0);
        $p_notes = trim($itm['notes'] ?? '');
        
        // Only add if there is a link OR a value OR notes. Skip completely empty rows.
        if (!empty($p_link) || $p_total > 0 || !empty($p_notes)) {
            $clean_items[] = $itm;
        }
    }
    $items = $clean_items;

    // Backward compatibility for order links
    if (empty($order_link) && !empty($items) && !empty($items[0]['product_link'])) {
        $order_link = trim($items[0]['product_link']);
    }
    if (empty($additional_link) && !empty($items) && !empty($items[0]['additional_link'])) {
        $additional_link = trim($items[0]['additional_link']);
    }

    // Validation
    $errors = [];

    if (empty($customer_id)) {
        $errors[] = 'يرجى اختيار العميل';
    }

    if (empty($items) || !is_array($items) || count($items) === 0) {
        $errors[] = 'يرجى إضافة منتج واحد على الأقل';
    }

    $clean_shein_items = [];
    $seen_shein_skus = [];
    if (is_array($shein_items)) {
        foreach ($shein_items as $index => $shein_item) {
            $shein_sku = sheinNormalizeSku($shein_item['sku'] ?? '');
            if ($shein_sku !== '') {
                if (isset($seen_shein_skus[$shein_sku])) {
                    $errors[] = 'لا يمكن إضافة نفس SKU أكثر من مرة في نفس الطلب: ' . $shein_sku;
                    continue;
                }

                $seen_shein_skus[$shein_sku] = true;
                $clean_shein_items[] = [
                    'sku' => $shein_sku,
                    'name' => trim($shein_item['name'] ?? ''),
                    'image' => trim($shein_item['image'] ?? ''),
                ];
            }
        }
    }
    $shein_items = $clean_shein_items;

    if (!empty($errors)) {
        $error_message = implode(' • ', $errors);
    } else {
        try {
            $db->beginTransaction();

            // Calculate subtotal (before discount)
            $subtotal_amount = 0;
            foreach ($items as $item) {
                $subtotal_amount += floatval($item['total'] ?? 0);
            }

            // Get customer type information
            $customer_stmt = $db->prepare("
                SELECT c.*, ct.name as type_name
                FROM customers c
                LEFT JOIN customer_types ct ON c.customer_type_id = ct.id
                WHERE c.id = ?
            ");
            $customer_stmt->execute([$customer_id]);
            $customer = $customer_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                $db->rollBack();
                $error_message = 'العميل المحدد غير صالح أو تم حذفه.';
                throw new Exception($error_message);
            }
            $customer_type_name = $customer['type_name'] ?? '';

            // Calculate automatic discount amount based on form input
            $automatic_discount_percentage = floatval($_POST['automatic_discount_percentage'] ?? 0);
            $automatic_discount_amount = round($subtotal_amount * ($automatic_discount_percentage / 100), 2);

            // Calculate discount using coupon
            $discount_info = [];
            $requires_approval = false;
            $coupon_id = null;

            if (!empty($coupon_code)) {
                try {
                    $tableCheck = $db->query("SHOW TABLES LIKE 'coupons'");
                    if ($tableCheck->rowCount() == 0) throw new Exception();
                } catch (PDOException $e) {
                    $errors[] = 'نظام الكوبونات غير متاح';
                    throw new Exception(implode(' • ', $errors));
                }

                $coupon_stmt = $db->prepare("
                    SELECT * FROM coupons
                    WHERE coupon_code = ?
                    AND is_active = 1
                    AND (start_date IS NULL OR start_date <= CURDATE())
                    AND (end_date IS NULL OR end_date >= CURDATE())
                    AND (usage_limit IS NULL OR used_count < usage_limit)
                ");
                $coupon_stmt->execute([$coupon_code]);
                $coupon = $coupon_stmt->fetch(PDO::FETCH_ASSOC);

                if ($coupon) {
                    // CHECK PER-CUSTOMER USAGE LIMIT
                    if (isset($coupon['user_usage_limit']) && $coupon['user_usage_limit'] !== null) {
                        $usage_check_stmt = $db->prepare("
                            SELECT COUNT(*) FROM coupon_usage
                            WHERE coupon_id = :coupon_id AND customer_id = :customer_id
                        ");
                        $usage_check_stmt->execute([
                            ':coupon_id' => $coupon['id'],
                            ':customer_id' => $customer_id
                        ]);
                        $customer_usage_count = $usage_check_stmt->fetchColumn();

                        if ($customer_usage_count >= $coupon['user_usage_limit']) {
                            $errors[] = 'لقد وصلت إلى الحد الأقصى لاستخدام هذا الكوبون';
                        }
                    }

                    if (empty($errors) && $subtotal_amount >= $coupon['min_order_amount']) {
                        $coupon_id = $coupon['id'];
                        $discount_info['discount_type'] = $coupon['discount_type'];
                        $discount_info['discount_value'] = $coupon['discount_value'];

                        if ($coupon['discount_type'] === 'percentage') {
                            $discount_amount = round($subtotal_amount * ($coupon['discount_value'] / 100), 2);
                            if ($coupon['max_discount_amount'] && $discount_amount > $coupon['max_discount_amount']) {
                                $discount_amount = $coupon['max_discount_amount'];
                            }
                            $discount_info['discount_amount'] = $discount_amount;
                        } else { // 'fixed'
                            $discount_info['discount_amount'] = min($coupon['discount_value'], $subtotal_amount);
                        }
                    } elseif (empty($errors)) {
                        $errors[] = 'الحد الأدنى للطلب لاستخدام هذا الكوبون هو ' . $coupon['min_order_amount'];
                    }
                } else {
                    $errors[] = 'الكوبون غير صحيح أو منتهي الصلاحية';
                }
            } else {
                $discount_info['discount_type'] = null;
                $discount_info['discount_value'] = 0;
                $discount_info['discount_amount'] = 0;
            }

            // CRITICAL FIX: CONFLICT RESOLUTION
            // If there is a coupon discount applied, forcefully disable automatic/category discount
            // This prevents "values from himself" adding up
            if (!empty($discount_info['discount_amount']) && $discount_info['discount_amount'] > 0) {
                $automatic_discount_percentage = 0;
                $automatic_discount_amount = 0;
            }

            if (!empty($errors)) {
                $error_message = implode(' • ', $errors);
                throw new Exception($error_message);
            }

            // Calculate total amount
            $total_amount = $subtotal_amount - $automatic_discount_amount - $discount_info['discount_amount'];
            
            // Safety Check: Total cannot be negative
            if ($total_amount < 0) $total_amount = 0;

            $final_amount = $total_amount + $shipping_cost;

            // CREDIT LIMIT CHECK
            $credit_check_stmt = $db->prepare("
                SELECT c.credit_limit,
                    COALESCE((
                        SELECT SUM(co.final_amount - co.paid_amount)
                        FROM customer_orders co
                        WHERE co.customer_id = c.id AND co.status NOT IN ('cancelled', 'returned')
                    ), 0) as outstanding_balance
                FROM customers c WHERE c.id = ?
            ");
            $credit_check_stmt->execute([$customer_id]);
            $credit_data = $credit_check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($credit_data) {
                $credit_limit = (float) $credit_data['credit_limit'];
                $outstanding_balance = (float) $credit_data['outstanding_balance'];
                if ($credit_limit > 0 && ($outstanding_balance + $final_amount) > $credit_limit) {
                    $remaining_credit = $credit_limit - $outstanding_balance;
                    throw new Exception("فشل إنشاء الطلب بسبب تجاوز الحد الائتماني.");
                }
            }

            // Validate Links
            $maxLinkLength = 255;
            if (!empty($order_link)) $order_link = mb_substr($order_link, 0, $maxLinkLength);
            if (!empty($additional_link)) $additional_link = mb_substr($additional_link, 0, $maxLinkLength);

            // Calculate total discount for DB record
            $total_discount_amount = $automatic_discount_amount + ($discount_info['discount_amount'] ?? 0);

            // --- INSERT customer_orders with a truly unique temporary order_number ---
            // We use a placeholder that won't conflict, like a timestamp + random,
            // or we could initially set it to NULL if the column allowed it.
            // Since it's NOT NULL, a unique string is required.
            
            // STEP 1: Generate the final, numeric order number.
            $final_order_number = generateOrderNumber($db);

            // STEP 2: Create the invoice number based on your desired format.
            $final_invoice_number = "inv-" . date('Y') . "-" . $final_order_number;

            // The actual final plain numeric order_number will be set AFTER invoice generation.

             // --- INSERT customer_orders with the FINAL, CORRECT numbers from the start ---
            $stmt = $db->prepare("
                INSERT INTO customer_orders (
                    order_number, customer_id, subtotal_amount, total_amount, final_amount,
                    discount_type, discount_value, discount_amount, paid_amount, status,
                    shipping_cost, expected_delivery_date, order_link, additional_link, notes,
                    requires_approval, created_by, customer_type_id, customer_type_name,
                    customer_type_discount, automatic_discount_percentage, automatic_discount_amount,
                    coupon_id, coupon_code, currency, invoice_number
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new',
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $final_order_number, // Use the final number, not a temporary one
                $customer_id,
                $subtotal_amount,
                $total_amount,
                $final_amount,
                $discount_info['discount_type'] ?? null,
                $discount_info['discount_value'] ?? 0,
                $total_discount_amount,
                0, // paid_amount
                $shipping_cost,
                $expected_delivery_date ?: null,
                $order_link ?: null,
                $additional_link ?: null,
                $notes,
                $requires_approval ? 1 : 0,
                $_SESSION['user_id'],
                $customer['customer_type_id'] ?? null,
                $customer_type_name ?? null,
                0,
                $automatic_discount_percentage ?? 0,
                $automatic_discount_amount ?? 0,
                $coupon_id ?: null,
                $coupon_code ?: null,
                $customer['currency'] ?? 'YER',
                $final_invoice_number // Insert the final invoice number right away
            ]);

            $order_id = $db->lastInsertId();
            
            // Coupon usage tracking
            if (!empty($coupon_id)) {
                $usage_stmt = $db->prepare("INSERT INTO coupon_usage (coupon_id, order_id, customer_id, discount_amount) VALUES (?, ?, ?, ?)");
                $usage_stmt->execute([$coupon_id, $order_id, $customer_id, $discount_info['discount_amount'] ?? 0]);
                $db->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$coupon_id]);
            }

            // Insert Items. If individual SHEIN products were entered, each product
            // becomes a sortable order item linked by the extracted/manual SHEIN SKU.
            if (!empty($shein_items)) {
                $shein_item_stmt = $db->prepare("
                    INSERT INTO order_items (
                        order_id, product_name, shein_product_id, shein_sku, status, quantity, unit_price, total_price, notes, product_link, product_status
                    ) VALUES (?, ?, ?, ?, 'pending', 1, 0, 0, ?, ?, ?)
                ");

                foreach ($shein_items as $index => $shein_item) {
                    $sku = sheinNormalizeSku($shein_item['sku'] ?? '');
                    if ($sku === '') {
                        throw new Exception('يرجى إدخال SKU لمنتج SHEIN رقم ' . ($index + 1));
                    }

                    $product_data = sheinExtractProductDataBySku($sku);

                    if (trim($shein_item['name'] ?? '') !== '') {
                        $product_data['name'] = trim($shein_item['name']);
                    }
                    if (trim($shein_item['image'] ?? '') !== '') {
                        $product_data['image'] = trim($shein_item['image']);
                    }

                    $shein_product_id = sheinFindOrCreateProduct($db, $product_data);
                    $product_name = $product_data['name'] !== '' ? $product_data['name'] : 'SHEIN SKU ' . $product_data['shein_sku'];

                    $shein_item_stmt->execute([
                        $order_id,
                        $product_name,
                        $shein_product_id,
                        $product_data['shein_sku'],
                        $notes,
                        $product_data['link'],
                        'available',
                    ]);
                }
            } else {
                $item_stmt = $db->prepare("
                    INSERT INTO order_items (
                        order_id, product_name, quantity, unit_price, total_price, notes, product_link, product_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $item_counter = 1;
                foreach ($items as $item) {
                    $product_name = trim($item['name'] ?? '');
                    // Default name logic only applies if user didn't provide one
                    if (empty($product_name)) {
                        $product_name = 'منتج ' . (count($items) > 1 ? '#' . $item_counter : '');
                    }
                    $item_counter++;

                    $quantity = intval($item['item_count'] ?? $item['quantity'] ?? 1);
                    $unit_price = floatval($item['price'] ?? 0);
                    $total_price = floatval($item['total'] ?? 0);
                    $product_link = trim($item['product_link'] ?? $item['link'] ?? '');
                    $item_notes = trim($item['notes'] ?? '');

                    $item_stmt->execute([
                        $order_id, $product_name, $quantity, $unit_price, $total_price, $item_notes, $product_link, 'available'
                    ]);
                }
            }

            // Status History
            $status_stmt = $db->prepare("INSERT INTO order_status_history (order_id, status, notes, created_by) VALUES (?, 'new', 'تم إنشاء الطلب', ?)");
            $status_stmt->execute([$order_id, $_SESSION['user_id']]);

            // Notifications
            if (!empty($notification_method)) {
                $notification_stmt = $db->prepare("INSERT INTO order_notifications (order_id, notification_type, status, sent_to) VALUES (?, ?, 'pending', ?)");
                foreach ($notification_method as $method) {
                    if ($method === 'whatsapp' && !empty($customer['whatsapp_number'])) {
                        $notification_stmt->execute([$order_id, 'whatsapp', $customer['whatsapp_number']]);
                    } elseif ($method === 'email' && !empty($customer['email'])) {
                        $notification_stmt->execute([$order_id, 'email', $customer['email']]);
                    }
                }
            }

            // ===================================================================
            // Image Upload
            // ===================================================================
            if (isset($_FILES['order_images']) && is_array($_FILES['order_images']['name'])) {
                $upload_dir = '../../uploads/orders/images/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                
                foreach ($_FILES['order_images']['name'] as $key => $filename) {
                    if ($_FILES['order_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_tmp = $_FILES['order_images']['tmp_name'][$key];
                        $file_type = $_FILES['order_images']['type'][$key];
                        $file_size = $_FILES['order_images']['size'][$key];

                        $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
                        $new_filename = 'order_' . $order_id . '_' . time() . '_' . $key . '.' . $file_ext;
                        
                        if (move_uploaded_file($file_tmp, $upload_dir . $new_filename)) {
                            $db->prepare("INSERT INTO order_images (order_id, image_path, image_name, image_type, image_size, display_order, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                               ->execute([$order_id, 'uploads/orders/images/' . $new_filename, $filename, $file_type, $file_size, $key, $_SESSION['user_id']]);
                        } else {
                            error_log("Failed to move uploaded file for order $order_id: " . $filename . " (Error: " . $_FILES['order_images']['error'][$key] . ")");
                        }
                    } elseif ($_FILES['order_images']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                        error_log("Upload error for order $order_id, file $filename: " . $_FILES['order_images']['error'][$key]);
                    }
                }
            }
            // ===================================================================
            // END OF Image Upload
            // ===================================================================
            
            $generated_invoice_number = null; 
            $final_order_number_for_accounting = $temporary_unique_order_number; // Default for accounting if invoice generation fails

            // ===================================================================
            // Auto Invoice AND Derive Order Number (MODIFIED LOGIC)
            // ===================================================================
           try {
                // Call our new, simplified function. It just reads the order and creates the invoice.
                $generated_invoice_number = createInvoiceForOrder($db, $order_id, $_SESSION['user_id']);
                
                if (!$generated_invoice_number) {
                     error_log("Invoice was not created for order ID: $order_id for an unknown reason.");
                }

            } catch (Exception $e) {
                // This catch is important. If the invoice fails, we now know why.
                error_log("CRITICAL: Failed to auto-generate invoice for order ID $order_id: " . $e->getMessage());
                // We will NOT roll back the transaction, because the order itself is valid.
                // The invoice can be created manually later.
                // You could add a user-facing error message here if you want.
            }
            // ===================================================================
            // END: Auto Invoice AND Derive Order Number
            // ===================================================================

            // ===================================================================
            // START: NEW ACCOUNTING LOGIC (ADDED SAFELY)
            // ===================================================================
            try {
                // Get account IDs from settings
                $ar_account_id       = get_accounting_setting($db, 'default_accounts_receivable_id');
                $sales_account_id    = get_accounting_setting($db, 'default_sales_revenue_id');
                $shipping_account_id = get_accounting_setting($db, 'default_shipping_revenue_id');
                $discount_account_id = get_accounting_setting($db, 'default_sales_discount_id');

                // Use the *final* generated order_number (numeric suffix) for the description
                $description = "إيراد الطلب رقم " . $final_order_number_for_accounting . " للعميل " . $customer['name'];
                
                // This logic correctly balances the entry:
                // Debits (What we are owed + expenses) = Credits (What we earned)
                // (Amount Owed by Customer) + (Discount Given) = (Sales Revenue) + (Shipping Revenue)
                // ($final_amount) + ($total_discount_amount) = ($subtotal_amount) + ($shipping_cost)
                $entry_items = [
                    // --- DEBITS ---
                    // The customer now owes us the final amount.
                    ['account_id' => $ar_account_id, 'type' => 'debit', 'amount' => $final_amount],
                    // The discount is an expense for us.
                    ['account_id' => $discount_account_id, 'type' => 'debit', 'amount' => $total_discount_amount],
                    
                    // --- CREDITS ---
                    // We earned revenue from the products.
                    ['account_id' => $sales_account_id, 'type' => 'credit', 'amount' => $subtotal_amount],
                    // We earned revenue from the shipping charge.
                    ['account_id' => $shipping_account_id, 'type' => 'credit', 'amount' => $shipping_cost],
                ];

                create_journal_entry(
                    $db,
                    date('Y-m-d'), // Use today's date for the entry
                    $description,
                    $entry_items,
                    'orders',      // Source Module
                    $order_id,     // Source ID
                    $_SESSION['user_id']
                );

            } catch (Exception $acc_e) {
                // If accounting fails, log the error but DO NOT stop the order from being created.
                error_log("Accounting entry failed for Order ID $order_id: " . $acc_e->getMessage());
            }
            // ===================================================================
            // END: NEW ACCOUNTING LOGIC
            // ===================================================================

            $db->commit();
            $success_message = 'تم إنشاء الطلب بنجاح';
            header("Location: /modules/orders/index.php");
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = 'خطأ: ' . $e->getMessage();
            error_log($e->getMessage());
        }
    }
}

include '../../includes/header.php';
?>

<style>
    /* Modern Card Design */
    .case-highlight {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1rem;
        position: relative;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        transition: all 0.3s ease;
    }
    @media (min-width: 768px) { .case-highlight { padding: 1.5rem; } }
    .case-highlight:hover { border-color: #cbd5e1; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08); transform: translateY(-2px); }
    .section-header { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); }
    .dir-ltr { direction: ltr; text-align: left; }
    .item-row { transition: all 0.2s ease; border-bottom: 1px solid #f1f5f9; }
    .item-row:hover { background-color: #f8fafc; }
    .notification-option { display: flex; align-items: center; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 10px; margin-bottom: 0.75rem; cursor: pointer; transition: all 0.2s ease; background: white; }
    .notification-option:hover { background-color: #f0f9ff; border-color: #3b82f6; }
    .notification-option.selected { border-color: #3b82f6; background-color: #dbeafe; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.2); }
    #customerSearch { font-size: 1rem; padding: 0.75rem 2.5rem 0.75rem 1rem; transition: all 0.2s ease; border-radius: 12px; border: 2px solid #e2e8f0; }
    #customerSearch:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
    #customerSearch.has-selection { background-color: #f0fdf4; border-color: #10b981; font-weight: 600; }
    .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: 10px; padding: 0.75rem 1.5rem; font-weight: 600; transition: all 0.2s ease; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4); }
    .form-input { width: 100%; border-radius: 8px; border: 2px solid #e2e8f0; transition: all 0.2s ease; padding: 0.5rem 0.75rem; }
    .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .customer-type-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem; }
    .search-results:not(.hidden) { display: block !important; }
    .case-highlight, .container, .row, .col-md-12 { overflow: visible !important; }
    @media (max-width: 768px) {
        .items-table thead { display: none; }
        .items-table tbody { display: block; }
        .items-table tbody tr { display: block; margin-bottom: 1rem; border: 2px solid #e5e7eb; border-radius: 12px; padding: 1rem; background: white; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        .items-table tbody td { display: block; width: 100% !important; padding: 0.5rem 0 !important; border: none; text-align: right !important; }
        .items-table tbody td:before { content: attr(data-label); font-weight: 600; color: #4b5563; display: block; margin-bottom: 0.25rem; font-size: 0.875rem; }
        .items-table tbody td:last-child:before { display: none; }
        .items-table tbody td:first-child { font-size: 1.25rem; font-weight: bold; color: #3b82f6; text-align: center !important; padding-bottom: 0.75rem !important; border-bottom: 1px solid #e5e7eb; margin-bottom: 0.5rem; }
        .items-table tbody td:first-child:before { content: 'السلة رقم '; font-size: 0.875rem; font-weight: 600; color: #6b7280; }
        .items-table tbody td input, .items-table tbody td select { width: 100% !important; }
        .items-table tbody td:last-child { text-align: center !important; padding-top: 1rem !important; border-top: 1px solid #e5e7eb; margin-top: 0.5rem; }
        .items-table tfoot tr { display: block; border-bottom: 1px solid #e5e7eb; padding: 0.75rem 0; }
        .items-table tfoot td { display: block; width: 100% !important; padding: 0.25rem 0 !important; text-align: right !important; }
        .items-table tfoot td[colspan] { display: flex; justify-content: space-between; align-items: center; }
    }
</style>

<div class="min-h-screen bg-gray-50 py-4 sm:py-6" dir="rtl">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 shadow-xl rounded-2xl mb-6 sm:mb-8">
            <div class="px-4 py-4 sm:px-8 sm:py-6">
                <div class="flex flex-col sm:flex-row items-start sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-white flex items-center">
                            <i class="fas fa-shopping-cart ml-3 text-blue-200"></i>
                            إنشاء طلب جديد
                        </h1>
                        <p class="text-blue-100 mt-2 text-sm sm:text-lg flex items-center">
                            <i class="fas fa-user-edit ml-2"></i>
                            يتم الإنشاء بواسطة: <span class="font-semibold mr-1"><?php echo htmlspecialchars($creator_name); ?></span>
                        </p>
                    </div>
                    <div class="w-full sm:w-auto">
                        <a href="index.php" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 sm:px-6 sm:py-3 bg-white text-blue-600 rounded-xl hover:bg-blue-50 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1 font-semibold">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للقائمة
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-sm">
                <i class="fas fa-exclamation-circle ml-2"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Order Form -->
        <form method="POST" id="orderForm" enctype="multipart/form-data" class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-4 py-4 sm:px-6 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">بيانات الطلب</h2>
            </div>

            <div class="p-4 sm:p-6 space-y-6">
                <!-- Customer Selection -->
                <div class="case-highlight">
                    <label for="customerSearch" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-user ml-2"></i>البحث باسم العميل *
                    </label>
                    <div class="relative">
                        <div class="relative">
                            <input type="text" id="customerSearch" class="form-input w-full pl-10" placeholder="ابحث بالاسم, الجوال, أو الكود..." autocomplete="off">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        <div id="searchResults" class="absolute z-[9999] w-full bg-white border border-gray-300 rounded-lg shadow-lg mt-1 max-h-64 overflow-y-auto hidden"></div>
                    </div>

                    <select id="customer_id" name="customer_id" class="hidden" required>
                        <option value="">-- اختر العميل --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo htmlspecialchars($customer['id']); ?>"
                                data-mobile="<?php echo htmlspecialchars($customer['mobile_number'] ?? ''); ?>"
                                data-whatsapp="<?php echo htmlspecialchars($customer['whatsapp_number'] ?? ''); ?>"
                                data-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                data-city="<?php echo htmlspecialchars($customer['city_name'] ?? ''); ?>"
                                data-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                data-code="<?php echo htmlspecialchars($customer['customer_code']); ?>"
                                data-type-id="<?php echo htmlspecialchars($customer['type_id'] ?? ''); ?>"
                                data-type-name="<?php echo htmlspecialchars($customer['type_name'] ?? ''); ?>"
                                data-type-discount="<?php echo htmlspecialchars($customer['type_discount'] ?? 0); ?>"
                                data-currency="<?php echo htmlspecialchars($customer['currency'] ?? 'YER'); ?>">
                                <?php echo htmlspecialchars($customer['name']) . ' (' . htmlspecialchars($customer['customer_code']) . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="customerDetails" class="mt-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 text-sm hidden">
                        <div>
                            <span class="block font-medium text-gray-500">الجوال:</span>
                            <span id="customerMobile" class="text-gray-900"></span>
                        </div>
                        <div>
                            <span class="block font-medium text-gray-500">الواتساب:</span>
                            <span id="customerWhatsapp" class="text-gray-900"></span>
                        </div>
                        <div>
                            <span class="block font-medium text-gray-500">الإيميل:</span>
                            <span id="customerEmail" class="text-gray-900 break-words"></span>
                        </div>
                    </div>

                    <div id="customerTypeDetails" class="mt-3 hidden">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-2">
                            <span class="text-sm font-medium text-blue-700">نوع العميل:</span>
                            <span id="customerTypeName" class="text-blue-900 font-semibold"></span>
                        </div>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="case-highlight">
                    <div class="mb-4">
                        <h3 class="text-lg font-bold text-gray-900 flex items-center mb-4">
                            <i class="fas fa-box ml-2 text-blue-600"></i>
                            منتجات الطلب
                        </h3>

                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">رابط السلة</label>
                                <input type="url" name="items[0][product_link]" class="form-input" placeholder="https://example.com/basket">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">رابط إضافي</label>
                                <input type="url" name="items[0][additional_link]" class="form-input" placeholder="https://example.com/additional">
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">عدد القطع</label>
                                    <input type="number" name="items[0][item_count]" value="1" min="1" class="form-input item-quantity" oninput="updateTotals()">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">الإجمالي (ريال)</label>
                                    <input type="number" name="items[0][total]" value="0" min="0" step="1" class="form-input item-total-input" oninput="updateTotals()">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">ملاحظات</label>
                                <textarea name="items[0][notes]" class="form-input" rows="2" placeholder="أضف ملاحظات..."></textarea>
                            </div>

                            <div class="bg-white border border-purple-200 rounded-xl p-4">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                                    <div>
                                        <h4 class="font-bold text-purple-800 flex items-center gap-2">
                                            <i class="fas fa-barcode"></i>
                                            ربط منتجات SHEIN حسب SKU
                                        </h4>
                                        <p class="text-xs text-gray-500 mt-1">يتم إنشاء حقول SKU تلقائياً حسب عدد القطع، ويتم جلب بيانات المنتج من SHEIN باستخدام SKU فقط.</p>
                                    </div>
                                    <span id="sheinGroupsCount" class="text-xs bg-purple-100 text-purple-800 px-3 py-1 rounded-full">0 منتج</span>
                                </div>
                                <div id="sheinProductGroups" class="space-y-3"></div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <div id="itemsContainer" class="hidden"></div>

                        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-gray-700">إجمالي القطع:</span>
                                <span id="totalQuantity" class="bg-gray-200 px-3 py-1 rounded-md font-bold">0</span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-gray-700">المجموع قبل الخصم:</span>
                                <span class="font-bold text-gray-900">
                                    <span id="subtotalAmount" class="bg-blue-100 px-3 py-1 rounded-md">0</span> ريال
                                </span>
                            </div>

                            <div id="automaticDiscountRow" class="hidden flex justify-between items-center bg-purple-50 p-2 rounded">
                                <span class="font-semibold text-purple-700">الخصم التلقائي (%):</span>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="automatic_discount_percentage" id="automaticDiscountPercentage" value="0" readonly class="form-input w-20 p-1 text-center dir-ltr font-semibold bg-gray-100 cursor-not-allowed">
                                    <span class="font-bold text-purple-700">
                                        <span id="automaticDiscountAmount" class="bg-purple-200 px-3 py-1 rounded-md">0</span> ريال
                                    </span>
                                </div>
                            </div>

                            <div id="discountTierRow" class="hidden bg-indigo-50 p-2 rounded">
                                <div class="flex items-center justify-center gap-2 text-xs">
                                    <i class="fas fa-info-circle text-indigo-600"></i>
                                    <span class="font-medium text-indigo-700">منطق الخصم الجديد:</span>
                                    <span id="discountTierInfo" class="font-semibold text-indigo-900"></span>
                                </div>
                            </div>

                            <div id="discountConflictMessage" class="hidden bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded-md text-sm">
                                <p><i class="fas fa-exclamation-triangle mr-2"></i>تم تطبيق الكوبون. سيتم إلغاء الخصم التلقائي.</p>
                                <p class="text-xs mt-1">يمكن تطبيق خصم واحد فقط (إما الكوبون أو الخصم التلقائي).</p>
                            </div>

                            <div id="discountRow" class="hidden flex justify-between items-center bg-green-50 p-2 rounded">
                                <span class="font-semibold text-green-700">خصم الكوبون:</span>
                                <span class="font-bold text-green-700">
                                    <span id="discountDisplay" class="bg-green-200 px-3 py-1 rounded-md">0</span> ريال
                                </span>
                            </div>

                            <div class="flex justify-between items-center bg-blue-50 p-2 rounded">
                                <span class="font-semibold text-blue-700">الإجمالي بعد الخصم:</span>
                                <span class="font-bold text-blue-700">
                                    <span id="totalAmount" class="bg-blue-200 px-3 py-1 rounded-md">0</span> ريال
                                </span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-gray-700">تكلفة الشحن:</span>
                                <input type="number" name="shipping_cost" id="shippingCost" value="0" min="0" step="0.01" class="form-input w-32 p-2 text-right dir-ltr font-semibold">
                            </div>

                            <div class="flex justify-between items-center bg-green-50 p-3 rounded border-t-2 border-green-300">
                                <span class="font-bold text-green-700 text-lg">الإجمالي النهائي:</span>
                                <span class="font-bold text-green-700 text-lg">
                                    <span id="finalTotal" class="bg-green-200 px-3 py-1 rounded-md">0</span> ريال
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="case-highlight">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">تفاصيل الشحن والدفع</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="coupon_code" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-ticket-alt ml-1 text-green-600"></i> كود الكوبون</label>
                                <div class="flex gap-2">
                                    <input type="text" id="coupon_code" name="coupon_code" class="form-input flex-1 uppercase" placeholder="أدخل الكود" style="text-transform: uppercase;">
                                    <button type="button" id="applyCouponBtn" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition font-semibold">تطبيق</button>
                                </div>
                                <div id="couponMessage" class="mt-2 text-sm hidden"></div>
                                <div id="couponDetails" class="mt-2 p-2 bg-green-50 border border-green-300 rounded-lg hidden">
                                    <div class="flex items-center justify-between text-sm">
                                        <div>
                                            <span class="font-semibold text-green-800">✅ تم تطبيق:</span>
                                            <span id="appliedCouponCode" class="font-bold text-green-900"></span>
                                        </div>
                                        <button type="button" id="removeCouponBtn" class="text-red-600 hover:text-red-800 font-semibold"><i class="fas fa-times ml-1"></i> إزالة</button>
                                    </div>
                                    <div id="couponInfo" class="text-xs text-green-700 mt-1"></div>
                                </div>
                            </div>
                            <!-- Notes -->
                            <div>
                                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                                <textarea id="notes" name="notes" rows="3" class="form-input"></textarea>
                            </div>

                            <!-- Order Images Upload -->
                            <div class="case-highlight">
                                <h3 class="text-lg font-medium text-gray-900 mb-2"><i class="fas fa-images ml-2 text-purple-600"></i> ملفات الطلب</h3>
                                <p class="text-sm text-gray-600 mb-4">يمكنك رفع عدة ملفات للطلب (لا يوجد حد للحجم أو النوع)</p>
                                <label class="flex justify-center w-full px-4 py-6 bg-white border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-purple-500 hover:bg-purple-50 transition-all">
                                    <div class="text-center">
                                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400"></i>
                                        <p class="text-sm text-gray-600"><span class="font-semibold text-purple-600">اختر الملفات</span> أو اسحبها هنا</p>
                                    </div>
                                    <input type="file" id="order_images" name="order_images[]" multiple class="hidden" onchange="previewImages(this)">
                                </label>
                                <div id="imagePreviewContainer" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mt-4 hidden"></div>
                            </div>
                        </div>
                    </div>
                </div> <!-- End of grid-cols-1 md:grid-cols-2 -->
            </div> <!-- End of p-4 sm:p-6 space-y-6 -->
            
            <div class="px-4 py-3 sm:px-6 bg-gray-50 border-t border-gray-200 flex justify-between">
                <button type="button" onclick="window.location.href='index.php'" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition duration-200 font-semibold">
                    إلغاء
                </button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-200 font-semibold">
                    <i class="fas fa-save ml-2"></i>
                    حفظ الطلب
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const customerSearch = document.getElementById('customerSearch');
    const customerSelect = document.getElementById('customer_id');
    const searchResults = document.getElementById('searchResults');
    const customerDetails = document.getElementById('customerDetails');
    const customerTypeDetails = document.getElementById('customerTypeDetails');
    const allCustomers = Array.from(customerSelect.options).slice(1);

    let isDiscountInputFocused = false;
    let appliedCoupon = null;
    let selectedCustomerType = null;
    let selectedCustomerTypeId = null;

    customerSearch.addEventListener('input', function() {
        const searchTerm = this.value.trim().toLowerCase();
        if (searchTerm.length === 0) {
            searchResults.classList.add('hidden');
            searchResults.innerHTML = '';
            return;
        }
        const filtered = allCustomers.filter(option => {
            const name = (option.dataset.name || '').toLowerCase();
            const code = (option.dataset.code || '').toLowerCase();
            const mobile = (option.dataset.mobile || '').toLowerCase();
            const whatsapp = (option.dataset.whatsapp || '').toLowerCase();
            return name.includes(searchTerm) || code.includes(searchTerm) || mobile.includes(searchTerm) || whatsapp.includes(searchTerm);
        });
        if (filtered.length > 0) {
            searchResults.innerHTML = filtered.map(option => {
                const typeBadge = option.dataset.typeName ? `<span class="customer-type-badge bg-gray-200 text-gray-700">${option.dataset.typeName}</span>` : '';
                const currencyBadge = option.dataset.currency ? `<span class="customer-type-badge bg-blue-100 text-blue-800 border border-blue-200 ml-1" style="font-size: 0.7rem;">${option.dataset.currency}</span>` : '';
                return `
                <div class="search-result-item p-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100" data-customer-id="${option.value}" onclick="selectCustomer('${option.value}')">
                    <div class="font-semibold text-gray-900 text-sm flex items-center flex-wrap gap-1">
                        ${option.dataset.name} ${typeBadge} ${currencyBadge}
                    </div>
                    <div class="text-xs text-gray-600 mt-1">
                        <span class="inline-block ml-3">📱 ${option.dataset.mobile || 'N/A'}</span>
                        <span class="inline-block">#${option.dataset.code}</span>
                    </div>
                </div>`;
            }).join('');
            searchResults.classList.remove('hidden');
        } else {
            searchResults.innerHTML = '<div class="p-3 text-center text-sm text-gray-500">❌ لا توجد نتائج</div>';
            searchResults.classList.remove('hidden');
        }
    });

    function selectCustomer(customerId) {
        customerSelect.value = customerId;
        customerSelect.dispatchEvent(new Event('change'));
        const selectedOption = customerSelect.options[customerSelect.selectedIndex];
        if (selectedOption && selectedOption.value) {
            customerSearch.value = selectedOption.dataset.name + ' (' + selectedOption.dataset.code + ')';
            customerSearch.classList.add('has-selection');
            searchResults.classList.add('hidden');
        }
    }

    document.getElementById('customer_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('customerMobile').textContent = selectedOption.dataset.mobile || 'غير متوفر';
            document.getElementById('customerWhatsapp').textContent = selectedOption.dataset.whatsapp || 'غير متوفر';
            document.getElementById('customerEmail').textContent = selectedOption.dataset.email || 'غير متوفر';
            customerDetails.classList.remove('hidden');

            selectedCustomerTypeId = selectedOption.dataset.typeId || null;
            selectedCustomerType = selectedOption.dataset.typeName || null;

            if (selectedOption.dataset.typeName) {
                document.getElementById('customerTypeName').textContent = selectedOption.dataset.typeName;
                customerTypeDetails.classList.remove('hidden');
            } else {
                customerTypeDetails.classList.add('hidden');
            }
            updateTotals();
        } else {
            customerDetails.classList.add('hidden');
            customerTypeDetails.classList.add('hidden');
            selectedCustomerTypeId = null;
            selectedCustomerType = null;
        }
    });


    function removeItem(button) {
        button.closest('tr').remove();
        if (document.querySelectorAll('#itemsContainer tr').length === 0) {
            document.getElementById('itemsContainer').innerHTML = '<tr id="noItemsRow"><td colspan="7" class="p-4 text-center text-gray-500">لا توجد سلال.</td></tr>';
        }
        updateRowNumbers();
        updateTotals();
    }

    function updateRowNumbers() {
        document.querySelectorAll('#itemsContainer .item-row').forEach((row, index) => {
            row.querySelector('.item-number').textContent = index + 1;
        });
    }

    function debounce(fn, delay = 700) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function renderSheinProductGroups() {
        const quantityInput = document.querySelector('input[name="items[0][item_count]"]');
        const container = document.getElementById('sheinProductGroups');
        const badge = document.getElementById('sheinGroupsCount');
        if (!quantityInput || !container) return;

        const count = Math.max(0, parseInt(quantityInput.value, 10) || 0);

        // Preserve existing values before re-render
        const existing = {};
        container.querySelectorAll('[data-shein-index]').forEach(group => {
            const idx = group.dataset.sheinIndex;
            existing[idx] = {
                sku:   group.querySelector('.shein-sku')?.value   || '',
                name:  group.querySelector('.shein-name')?.value  || '',
                image: group.querySelector('.shein-image')?.value || '',
            };
        });

        container.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const data = existing[i] || {};
            const fetched = data.name !== '';

            const group = document.createElement('div');
            group.className = 'border border-gray-200 rounded-lg p-3 bg-gray-50';
            group.dataset.sheinIndex = i;
            group.innerHTML = `
                <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold text-gray-700">منتج SHEIN #${i + 1}</span>
                    <span class="shein-status text-xs ${fetched ? 'text-green-600' : 'text-gray-400'}">${fetched ? '&#10003; تم الجلب' : 'بانتظار الجلب'}</span>
                </div>
                <div class="flex gap-2 items-end">
                    <div class="flex-1">
                        <label class="block text-xs font-medium text-gray-600 mb-1">SKU</label>
                        <input type="text" name="shein_items[${i}][sku]" value="${escapeHtml(data.sku || '')}"
                               class="form-input shein-sku dir-ltr" placeholder="مثال: SK2410290496477028">
                    </div>
                    <button type="button"
                            class="shein-fetch-btn flex-shrink-0 px-3 py-2 bg-purple-600 text-white text-xs font-bold rounded-lg hover:bg-purple-700 active:scale-95 transition-all"
                            title="جلب بيانات المنتج">
                        <i class="fas fa-search"></i> جلب
                    </button>
                </div>
                <input type="hidden" name="shein_items[${i}][name]"  value="${escapeHtml(data.name  || '')}" class="shein-name">
                <input type="hidden" name="shein_items[${i}][image]" value="${escapeHtml(data.image || '')}" class="shein-image">
                <div class="shein-message text-xs mt-2 rounded p-2 ${fetched ? 'bg-green-100 text-green-800 border border-green-200' : 'hidden'}">
                    ${fetched ? escapeHtml(data.name) : ''}
                </div>
            `;
            container.appendChild(group);
        }

        if (badge) badge.textContent = `${count} منتج`;
        bindSheinFetchButtons();
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    }

    function setSheinMessage(group, message, type) {
        const messageEl = group.querySelector('.shein-message');
        const statusEl  = group.querySelector('.shein-status');
        const colors = { success: 'bg-green-100 text-green-800 border border-green-200', loading: 'bg-blue-100 text-blue-800 border border-blue-200', error: 'bg-red-100 text-red-800 border border-red-200' }[type] || '';
        messageEl.textContent = message;
        messageEl.className = `shein-message text-xs mt-2 rounded p-2 ${colors}`;
        statusEl.textContent = type === 'success' ? '\u2705 تم الجلب' : (type === 'loading' ? '\u23F3 جاري...' : '\u274C خطأ');
        statusEl.className   = `shein-status text-xs ${type === 'success' ? 'text-green-600' : type === 'loading' ? 'text-blue-600' : 'text-red-600'}`;
    }

    async function doFetchSheinProduct(group) {
        const skuInput = group.querySelector('.shein-sku');
        const btn      = group.querySelector('.shein-fetch-btn');
        const sku      = (skuInput?.value || '').trim();

        if (!sku) {
            setSheinMessage(group, 'أدخل SKU أولاً ثم اضغط جلب', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري...';
        setSheinMessage(group, 'جاري البحث عن المنتج...', 'loading');

        try {
            const response = await fetch('ajax/fetch_shein_product.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    `sku=${encodeURIComponent(sku)}`,
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.message || 'تعذر جلب المنتج');

            const p = data.product;
            skuInput.value = p.sku || p.shein_sku || sku;
            group.querySelector('.shein-name').value  = p.name  || '';
            group.querySelector('.shein-image').value = p.image || '';
            setSheinMessage(group, (p.name || ('SKU: ' + skuInput.value)), 'success');
        } catch (err) {
            setSheinMessage(group, err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> جلب';
        }
    }

    function bindSheinFetchButtons() {
        document.querySelectorAll('.shein-fetch-btn').forEach(btn => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => doFetchSheinProduct(btn.closest('[data-shein-index]')));
        });
        // Enter key in SKU field also triggers fetch
        document.querySelectorAll('.shein-sku').forEach(input => {
            if (input.dataset.bound === '1') return;
            input.dataset.bound = '1';
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); doFetchSheinProduct(input.closest('[data-shein-index]')); }
            });
        });
    }

    async function updateTotals() {
        let subtotal = 0;
        let totalQuantity = 0;

        const totalInput = document.querySelector('input[name="items[0][total]"]');
        const quantityInput = document.querySelector('input[name="items[0][item_count]"]');

        if (totalInput && quantityInput) {
            subtotal = parseFloat(totalInput.value) || 0;
            totalQuantity = parseInt(quantityInput.value) || 0;
        }

        const discountInput = document.getElementById('automaticDiscountPercentage');
        const automaticDiscountRow = document.getElementById('automaticDiscountRow');
        const discountTierRow = document.getElementById('discountTierRow');
        const discountTierInfo = document.getElementById('discountTierInfo');
        const conflictMessage = document.getElementById('discountConflictMessage');

        let currentDiscountPercentage = 0;
        let automaticDiscountAmount = 0;
        let couponDiscountAmount = 0;
        let tierInfo = '';

        // CONFLICT LOGIC: If coupon exists, it kills auto-discount completely
        if (appliedCoupon) {
            conflictMessage.classList.remove('hidden');
            automaticDiscountRow.classList.add('hidden');
            discountTierRow.classList.add('hidden');
            discountInput.value = '0.00'; 
            document.getElementById('automaticDiscountAmount').textContent = '0';
            currentDiscountPercentage = 0;
        } else {
            conflictMessage.classList.add('hidden');

            if (selectedCustomerTypeId && subtotal > 0) {
                try {
                    const response = await fetch(`get_customer_discount.php?customer_type_id=${selectedCustomerTypeId}&amount=${subtotal}`);
                    const data = await response.json();
                    if (data.success) {
                        currentDiscountPercentage = data.discount_percentage;
                        tierInfo = data.tier_info;
                    } else {
                        tierInfo = 'لا يوجد خصم';
                    }
                } catch (error) {
                    tierInfo = 'خطأ في تحميل الخصم';
                }
            } else {
                tierInfo = subtotal > 0 ? 'يرجى اختيار عميل' : '';
            }

            discountInput.value = currentDiscountPercentage.toFixed(2);
            if (subtotal > 0 && tierInfo) {
                discountTierInfo.textContent = tierInfo;
                discountTierRow.classList.remove('hidden');
            } else {
                discountTierRow.classList.add('hidden');
            }
        }

        automaticDiscountAmount = (subtotal * (currentDiscountPercentage / 100));

        if (automaticDiscountAmount > 0 && !appliedCoupon) {
            document.getElementById('automaticDiscountAmount').textContent = Math.round(automaticDiscountAmount);
            automaticDiscountRow.classList.remove('hidden');
        } else {
            automaticDiscountRow.classList.add('hidden');
        }

        if (appliedCoupon && subtotal > 0) {
            if (appliedCoupon.type === 'percentage' || appliedCoupon.type === 'percent') {
                couponDiscountAmount = subtotal * (appliedCoupon.value / 100);
                if (appliedCoupon.max_discount && couponDiscountAmount > appliedCoupon.max_discount) {
                    couponDiscountAmount = appliedCoupon.max_discount;
                }
            } else { // 'fixed'
                couponDiscountAmount = Math.min(appliedCoupon.value, subtotal);
            }
            appliedCoupon.discount_amount = couponDiscountAmount;
            document.getElementById('discountDisplay').textContent = Math.round(couponDiscountAmount);
            document.getElementById('discountRow').classList.remove('hidden');
        } else {
            couponDiscountAmount = 0;
            document.getElementById('discountRow').classList.add('hidden');
        }

        const totalAfterDiscount = subtotal - automaticDiscountAmount - couponDiscountAmount;
        const shippingCost = parseFloat(document.getElementById('shippingCost')?.value) || 0;
        const finalAmount = Math.max(0, totalAfterDiscount + shippingCost);

        document.getElementById('totalQuantity').textContent = totalQuantity;
        document.getElementById('subtotalAmount').textContent = Math.round(subtotal);
        document.getElementById('totalAmount').textContent = Math.round(totalAfterDiscount);
        document.getElementById('finalTotal').textContent = Math.round(finalAmount);
    }

    const shippingCostEl = document.getElementById('shippingCost');
    if (shippingCostEl) {
        shippingCostEl.addEventListener('input', updateTotals);
    }

    document.getElementById('applyCouponBtn').addEventListener('click', function() {
        const couponCode = document.getElementById('coupon_code').value.trim().toUpperCase();
        const subtotal = parseFloat(document.getElementById('subtotalAmount').textContent) || 0;
        const customerId = document.getElementById('customer_id').value;

        if (!couponCode) return showCouponMessage('يرجى إدخال كود الكوبون', 'error');
        if (!customerId) return showCouponMessage('يرجى اختيار العميل أولاً', 'error');
        if (subtotal === 0) return showCouponMessage('يرجى إضافة سلال أولاً', 'error');

        fetch('validate_coupon.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `coupon_code=${encodeURIComponent(couponCode)}&subtotal=${subtotal}&customer_id=${customerId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    appliedCoupon = data.coupon;
                    document.getElementById('coupon_code').value = data.coupon.code;
                    showCouponDetails(data.coupon);
                    showCouponMessage(data.message, 'success');
                    updateTotals();
                } else {
                    showCouponMessage(data.message, 'error');
                    appliedCoupon = null;
                    updateTotals();
                }
            })
            .catch(() => showCouponMessage('حدث خطأ في التحقق من الكوبون', 'error'));
    });

    document.getElementById('removeCouponBtn').addEventListener('click', function() {
        appliedCoupon = null;
        document.getElementById('coupon_code').value = '';
        document.getElementById('couponDetails').classList.add('hidden');
        document.getElementById('couponMessage').classList.add('hidden');
        renderSheinProductGroups();
        updateTotals();
    });

    function showCouponMessage(message, type) {
        const messageDiv = document.getElementById('couponMessage');
        messageDiv.textContent = message;
        messageDiv.className = `mt-2 text-sm p-2 rounded-lg ${type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
        messageDiv.classList.remove('hidden');
    }

    function showCouponDetails(coupon) {
        document.getElementById('appliedCouponCode').textContent = coupon.code;
        let infoText = (coupon.type === 'percentage' || coupon.type === 'percent') ?
            `خصم ${coupon.value}%` :
            `خصم ثابت بقيمة ${coupon.value} ريال`;
        document.getElementById('couponInfo').textContent = infoText;
        document.getElementById('couponDetails').classList.remove('hidden');
    }

    document.querySelectorAll('.notification-option').forEach(option => {
        option.addEventListener('click', function(e) {
            if (e.target.tagName !== 'INPUT') {
                const checkbox = this.querySelector('input[type="checkbox"]');
                if (!checkbox.disabled) {
                    checkbox.checked = !checkbox.checked;
                    this.classList.toggle('selected', checkbox.checked);
                }
            } else {
                this.classList.toggle('selected', e.target.checked);
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        const discountInput = document.getElementById('automaticDiscountPercentage');
        const sheinQuantityInput = document.querySelector('input[name="items[0][item_count]"]');
        if (sheinQuantityInput) {
            sheinQuantityInput.addEventListener('input', renderSheinProductGroups);
        }
        if (discountInput) {
            discountInput.addEventListener('focus', () => { isDiscountInputFocused = true; });
            discountInput.addEventListener('blur', () => { isDiscountInputFocused = false; });
            discountInput.addEventListener('input', updateTotals);
        }
        renderSheinProductGroups();
        updateTotals();
    });

    function previewImages(input) {
        const container = document.getElementById('imagePreviewContainer');
        container.innerHTML = '';
        if (input.files && input.files.length > 0) {
            container.classList.remove('hidden');
            for (const file of input.files) {
                const reader = new FileReader();
                reader.onload = e => {
                    let previewHtml;
                    if (file.type.startsWith('image/')) {
                        previewHtml = `<div class="relative group"><img src="${e.target.result}" class="w-full h-24 sm:h-32 object-cover rounded-lg border"></div>`;
                    } else {
                        // Generic preview for non-image files
                        previewHtml = `<div class="relative group w-full h-24 sm:h-32 flex flex-col items-center justify-center bg-gray-100 border rounded-lg p-2">
                            <i class="fas fa-file-alt text-4xl text-gray-400"></i>
                            <span class="text-xs text-center text-gray-600 mt-2 break-all">${file.name}</span>
                        </div>`;
                    }
                    container.innerHTML += previewHtml;
                };
                reader.readAsDataURL(file);
            }
        } else {
            container.classList.add('hidden');
        }
    }
</script>

<script>
    (function() {
        'use strict';
        document.addEventListener('DOMContentLoaded', function() {
            const customerSearch = document.getElementById('customerSearch');
            const searchResults = document.getElementById('searchResults');
            if (!customerSearch || !searchResults) return;

            document.body.appendChild(searchResults);

            function positionDropdown() {
                if (searchResults.classList.contains('hidden')) return;
                const rect = customerSearch.getBoundingClientRect();
                const isRTL = document.dir === 'rtl';

                searchResults.style.position = 'fixed';
                searchResults.style.top = (rect.bottom + 4) + 'px';
                searchResults.style.width = rect.width + 'px';

                if (isRTL) {
                    searchResults.style.right = (window.innerWidth - rect.right) + 'px';
                    searchResults.style.left = 'auto';
                } else {
                    searchResults.style.left = rect.left + 'px';
                    searchResults.style.right = 'auto';
                }
            }

            const observer = new MutationObserver(positionDropdown);
            observer.observe(searchResults, { childList: true, subtree: true });

            const show = () => { searchResults.classList.remove('hidden'); positionDropdown(); };
            const hide = () => searchResults.classList.add('hidden');

            customerSearch.addEventListener('focus', show);
            customerSearch.addEventListener('input', show);

            window.addEventListener('scroll', positionDropdown, true);
            window.addEventListener('resize', positionDropdown);

            document.addEventListener('click', e => {
                if (!customerSearch.contains(e.target) && !searchResults.contains(e.target)) hide();
            });

            customerSearch.addEventListener('keydown', e => {
                if (e.key === 'Escape') hide();
            });

            const originalSelectCustomer = window.selectCustomer;
            window.selectCustomer = function() {
                if (typeof originalSelectCustomer === 'function') {
                    originalSelectCustomer.apply(this, arguments);
                }
                setTimeout(hide, 100);
            };
        });
    })();
</script>

<?php include '../../includes/footer.php'; ?>