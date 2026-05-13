<?php
/**
 * Edit Customer Order
 * - CORRECTED: Preserves coupon discount logic (fixed or percentage) during recalculations.
 * - Dynamic Discount Calculation based on (Subtotal - Damaged)
 * - Automatic updates for Percentage Coupons
 * - Detailed change logging in order_status_history
 * - ENHANCED: Incredibly detailed logging for every field change.
 * - FINAL: Always includes the financial summary in the log notes for every update.
 * - CUSTOM LOG: Damaged item log now specifies the reason (damaged, expired, defective).
 * - NEW LOGIC: Only logs product/item changes if they actually occurred, otherwise logs only financial/admin changes.
 * - FIX: Compares products using unique ID instead of product name string to prevent false additions/deletions.
 */

// DEBUG: Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 1. Authentication Check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php';

// Prevent caching
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");

$page_title = 'تعديل الطلب';
$error_message = '';

// 2. Validation of ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}
$order_id = intval($_GET['id']);

// 3. Permission Guard
if (!hasPermission($_SESSION['user_id'], 'orders', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل الطلب';
    header('Location: view.php?id=' . $order_id);
    exit();
}

try {
    // --- FETCH ORDER DATA (WITH CORRECTED COUPON DETAILS) ---
    $query = "
        SELECT
            o.*,
            c.name as customer_name,
            c.customer_type_id,
            coup.coupon_code,
            coup.discount_type as coupon_discount_type,
            coup.discount_value as coupon_discount_value,
            coup.max_discount_amount as coupon_max_discount_amount
        FROM customer_orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN coupons coup ON o.coupon_id = coup.id
        WHERE o.id = ?
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("Order not found in database.");
    }

    // --- Fetch all available statuses for dropdown and logging ---
    $statuses_stmt = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC");
    $all_statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    $status_translations = array_column($all_statuses, 'status_name_ar', 'status_key');


    // Fetch Items
    $items_stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $items_stmt->execute([$order_id]);
    $original_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Damaged Items
    $damaged_items_stmt = $db->prepare("SELECT * FROM order_damaged_items WHERE order_id = ? ORDER BY id");
    $damaged_items_stmt->execute([$order_id]);
    $original_damaged_items = $damaged_items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Images
    $images_stmt = $db->prepare("SELECT * FROM order_images WHERE order_id = ? ORDER BY display_order, id");
    $images_stmt->execute([$order_id]);
    $existing_images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'DATABASE ERROR: ' . $e->getMessage();
    die('<div style="background:#fee2e2; color:#991b1b; padding:20px; text-align:center; direction:ltr;"><h3>Database Error</h3><p>' . $e->getMessage() . '</p></div>');
}

// ---------------------------------------------------------
// HANDLE FORM SUBMISSION
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($order)) {

    // Basic Inputs
    $notes = trim($_POST['notes'] ?? '');
    // IMPORTANT: Status is no longer automatically updated from POST. It will only be updated if explicitly changed.
    $order_status = $order['status']; // Default to current status to prevent auto-change
    if (isset($_POST['status']) && $_POST['status'] !== $order['status']) {
        $order_status = $_POST['status']; // Update only if a new status is selected by the user
    }
    $posted_items = $_POST['items'] ?? [];
    $posted_damaged_items = $_POST['damaged_items'] ?? [];
    $order_link = trim($_POST['order_link'] ?? '');
    $additional_link = trim($_POST['additional_link'] ?? '');

    // Financial Inputs
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $additional_discount = floatval($_POST['additional_discount'] ?? 0);

    if (empty($posted_items)) {
        $error_message = 'يجب إضافة منتج واحد على الأقل';
    } else {
        try {
            $db->beginTransaction();

            // --- 1. Calculate New Totals ---
            $subtotal_amount = 0;
            $new_total_quantity = 0;
            foreach ($posted_items as $item) {
                $qty = intval($item['quantity'] ?? 0);
                $row_total = floatval($item['total']);
                $subtotal_amount += $row_total;
                $new_total_quantity += $qty;
            }

            $damaged_total = 0;
            foreach ($posted_damaged_items as $damaged) {
                $damaged_total += floatval($damaged['price']);
            }

            $net_value_for_discount = $subtotal_amount - $damaged_total;
            if ($net_value_for_discount < 0) $net_value_for_discount = 0;

            // ===================================================================
            // START: CORRECTED DISCOUNT CALCULATION LOGIC
            // ===================================================================
            $calculated_discount = 0;
            $final_automatic_discount_amount = 0; // This is specifically for the automatic_discount_amount column
            // Keep original auto % or use coupon value if a coupon is present
            $discount_percentage_for_calculation = floatval($order['automatic_discount_percentage']);
            $coupon_details = [];

            if (!empty($order['coupon_id']) && !empty($order['coupon_discount_type'])) {
                $coupon_details = [
                    'type' => $order['coupon_discount_type'],
                    'value' => floatval($order['coupon_discount_value']),
                    'max_amount' => floatval($order['coupon_max_discount_amount']),
                    'code' => $order['coupon_code']
                ];
                // If a coupon is active, the automatic discount percentage is not used for calculation.
                $discount_percentage_for_calculation = 0;
            }
            
            // Calculate the primary discount (either from coupon or automatic)
            if ($coupon_details) {
                if ($coupon_details['type'] === 'percentage') {
                    $calculated_discount = $net_value_for_discount * ($coupon_details['value'] / 100);
                    if ($coupon_details['max_amount'] > 0 && $calculated_discount > $coupon_details['max_amount']) {
                        $calculated_discount = $coupon_details['max_amount'];
                    }
                } elseif ($coupon_details['type'] === 'fixed') {
                    $calculated_discount = min($coupon_details['value'], $net_value_for_discount);
                }
                $final_automatic_discount_amount = 0; // Coupon discount means no automatic discount is applied separately.
            } elseif ($discount_percentage_for_calculation > 0) {
                $calculated_discount = $net_value_for_discount * ($discount_percentage_for_calculation / 100);
                $final_automatic_discount_amount = $calculated_discount; // This discount IS the automatic discount.
            }
            // ===================================================================
            // END: CORRECTED DISCOUNT CALCULATION LOGIC
            // ===================================================================

            // Final Calculation using the correctly calculated discount
            $total_amount = $subtotal_amount - $calculated_discount - $damaged_total - $additional_discount;
            if ($total_amount < 0) $total_amount = 0;
            $final_amount = $total_amount + $shipping_cost;

            // This is for the accounting journal entry
            $total_discount_for_journal = $calculated_discount + $additional_discount;

            // --- 2. Update Main Order ---
            $update_stmt = $db->prepare("
                UPDATE customer_orders SET
                    subtotal_amount = ?, automatic_discount_percentage = ?,
                    automatic_discount_amount = ?, discount_amount = ?,
                    total_amount = ?, final_amount = ?, shipping_cost = ?,
                    additional_discount = ?, notes = ?, status = ?,
                    order_link = ?, additional_link = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([
                $subtotal_amount,
                $discount_percentage_for_calculation, // Store the original automatic %
                $final_automatic_discount_amount,     // Store the calculated automatic discount amount
                $calculated_discount,                 // Store the total primary discount (coupon or auto)
                $total_amount,
                $final_amount,
                $shipping_cost,
                $additional_discount,
                $notes,
                $order_status, // Use the determined status (original or updated)
                empty($order_link) ? null : $order_link,
                empty($additional_link) ? null : $additional_link,
                $order_id
            ]);

            // --- 3. Sync Invoice ---
            $invoice_base_amount = $total_amount;
            $invoice_tax_amount = $invoice_base_amount * 0.15; // Assuming 15% tax
            $invoice_total_amount = $invoice_base_amount + $invoice_tax_amount;
            $db->prepare("UPDATE customer_invoices SET amount = ?, tax_amount = ?, total_amount = ?, updated_at = NOW() WHERE order_id = ?")
               ->execute([$invoice_base_amount, $invoice_tax_amount, $invoice_total_amount, $order_id]);

            // --- 4. Update Order Items ---
            // Note: We delete all and re-insert, so we must rely on the ID check for logging only.
            $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);
            $item_stmt = $db->prepare("INSERT INTO order_items (order_id, product_name, quantity, unit_price, total_price, notes, product_link, product_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($posted_items as $item) {
                $qty = intval($item['quantity']);
                $total_price = floatval($item['total']);
                $unit_price = ($qty > 0) ? ($total_price / $qty) : 0;
                // Note: The original item ID is IGNORED here as we are re-inserting, but it is VITAL for logging.
                $item_stmt->execute([$order_id, $item['name'], $qty, $unit_price, $total_price, $item['notes'] ?? '', $item['link'] ?? '', 'available']);
            }

            // --- 5. Update Damaged Items ---
            $db->prepare("DELETE FROM order_damaged_items WHERE order_id = ?")->execute([$order_id]);
            if (!empty($posted_damaged_items)) {
                $damaged_stmt = $db->prepare("INSERT INTO order_damaged_items (order_id, product_name, product_link, price, reason, notes) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($posted_damaged_items as $damaged) {
                    $damaged_stmt->execute([$order_id, $damaged['name'], $damaged['link'] ?? '', floatval($damaged['price']), $damaged['reason'], $damaged['notes'] ?? '']);
                }
            }

            // --- 6. Handle Images ---
            if (isset($_FILES['order_images']) && !empty($_FILES['order_images']['name'][0])) {
                $upload_dir = '../../uploads/orders/images/';
                if (!file_exists($upload_dir)) { mkdir($upload_dir, 0755, true); }
                foreach ($_FILES['order_images']['name'] as $key => $filename) {
                    if ($_FILES['order_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_tmp = $_FILES['order_images']['tmp_name'][$key];
                        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        if (in_array($file_ext, $allowed_extensions)) {
                            $new_filename = 'order_' . $order_id . '_' . time() . '_' . $key . '.' . $file_ext;
                            $file_path = $upload_dir . $new_filename;
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $db->prepare("INSERT INTO order_images (order_id, image_path, image_name, image_type, image_size, display_order, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$order_id, 'uploads/orders/images/' . $new_filename, $filename, $_FILES['order_images']['type'][$key], $_FILES['order_images']['size'][$key], 1, $_SESSION['user_id']]);
                            }
                        }
                    }
                }
            }

            // --- 7. Image Deletion ---
            if (!empty($_POST['delete_images'])) {
                foreach ($_POST['delete_images'] as $image_id) {
                    $img_stmt = $db->prepare("SELECT image_path FROM order_images WHERE id = ? AND order_id = ?");
                    $img_stmt->execute([$image_id, $order_id]);
                    $img = $img_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($img && file_exists('../../' . $img['image_path'])) { unlink('../../' . $img['image_path']); }
                    $db->prepare("DELETE FROM order_images WHERE id = ? AND order_id = ?")->execute([$image_id, $order_id]);
                }
            }
            
            // --- 8. ACCOUNTING LOGIC (DELETE AND RECREATE) ---
            try {
                delete_journal_entry_by_source($db, 'orders', $order_id);
                $ar_account_id = get_accounting_setting($db, 'default_accounts_receivable_id');
                $sales_account_id = get_accounting_setting($db, 'default_sales_revenue_id');
                $shipping_account_id = get_accounting_setting($db, 'default_shipping_revenue_id');
                $discount_account_id = get_accounting_setting($db, 'default_sales_discount_id');
                $description = "تعديل إيراد الطلب رقم " . $order['order_number'];
                $entry_items = [
                    ['account_id' => $ar_account_id, 'type' => 'debit', 'amount' => $final_amount],
                    ['account_id' => $discount_account_id, 'type' => 'debit', 'amount' => $total_discount_for_journal],
                    ['account_id' => $sales_account_id, 'type' => 'credit', 'amount' => $subtotal_amount],
                    ['account_id' => $shipping_account_id, 'type' => 'credit', 'amount' => $shipping_cost],
                ];
                create_journal_entry($db, date('Y-m-d'), $description, $entry_items, 'orders', $order_id, $_SESSION['user_id']);
            } catch (Exception $acc_e) {
                error_log("Accounting update failed for Order ID $order_id: " . $acc_e->getMessage());
            }
            
            // --- 9. Assemble and Log The Changes to General History (FIXED: Comparison by ID) ---
            $item_log_notes = []; // Array to collect product/item change descriptions
            $other_log_notes = []; // Array to collect financial/status/link change descriptions

            // Compare Order Items (Products)
            $original_items_map = array_column($original_items, null, 'id'); // <--- FIX: Map by ID
            $posted_items_map = [];
            foreach ($posted_items as $item) {
                $p_name = trim($item['name'] ?? '');
                $item_id = intval($item['id'] ?? 0); // Get the ID, 0 if new
                $posted_items_map[$item_id] = $item; // Map posted items by ID

                if ($item_id > 0 && isset($original_items_map[$item_id])) {
                    // Existing Item - Check for changes
                    $original_item = $original_items_map[$item_id];
                    
                    // Check Name Change
                    if (trim($original_item['product_name']) != $p_name) {
                        $item_log_notes[] = "تم تغيير اسم المنتج من '{$original_item['product_name']}' إلى '{$p_name}'";
                    }
                    // Check Quantity Change
                    if (intval($original_item['quantity']) != intval($item['quantity'])) {
                        $item_log_notes[] = "تم تغيير كمية المنتج '{$p_name}' من " . intval($original_item['quantity']) . " إلى " . intval($item['quantity']);
                    }
                    // Check Price Change
                    if (abs(floatval($original_item['total_price']) - floatval($item['total'])) > 0.01) {
                        $item_log_notes[] = "تم تغيير سعر المنتج '{$p_name}' من " . number_format($original_item['total_price'], 2) . " إلى " . number_format($item['total'], 2);
                    }
                } elseif ($item_id === 0) {
                    // New Item Added
                    $item_log_notes[] = "تم إضافة منتج جديد: '{$p_name}' (الكمية: " . intval($item['quantity'] ?? 1) . ", الإجمالي: " . number_format($item['total'] ?? 0, 2) . ")";
                }
            }
            
            // Check for Deleted Items
            foreach ($original_items_map as $id => $item) {
                if (!isset($posted_items_map[$id])) { // If original ID is missing from posted map
                    $item_log_notes[] = "تم حذف المنتج: '{$item['product_name']}'";
                }
            }

            // Compare Damaged Items (Product Modifications)
            $damage_reason_map = ['damaged' => 'تالف', 'expired' => 'منتهي ', 'defective' => 'مفقود'];
            $original_damaged_map = array_column($original_damaged_items, null, 'id'); // <--- FIX: Map by ID
            $posted_damaged_map = [];
            foreach ($posted_damaged_items as $item) {
                $p_name = trim($item['name'] ?? '');
                $damaged_item_id = intval($item['id'] ?? 0); // Get the ID, 0 if new
                $posted_damaged_map[$damaged_item_id] = $item;

                if ($damaged_item_id > 0 && isset($original_damaged_map[$damaged_item_id])) {
                    // Existing Damaged Item - Check for changes
                    $original_item = $original_damaged_map[$damaged_item_id];
                    
                    // Check Name Change
                    if (trim($original_item['product_name']) != $p_name) {
                        $item_log_notes[] = "تم تغيير اسم المنتج التالف من '{$original_item['product_name']}' إلى '{$p_name}'";
                    }
                    // Check Price Change
                    if (abs(floatval($original_item['price']) - floatval($item['price'])) > 0.01) {
                        $item_log_notes[] = "تم تغيير قيمة خصم التالف للمنتج '{$p_name}' من " . number_format($original_item['price'], 2) . " إلى " . number_format($item['price'], 2);
                    }
                    // Check Reason Change
                    if ($original_item['reason'] != $item['reason']) {
                        $original_reason = $damage_reason_map[$original_item['reason']] ?? $original_item['reason'];
                        $new_reason = $damage_reason_map[$item['reason']] ?? $item['reason'];
                        $item_log_notes[] = "تم تغيير سبب تلف المنتج '{$p_name}' من '{$original_reason}' إلى '{$new_reason}'";
                    }
                } elseif ($damaged_item_id === 0) {
                    // New Damaged Item Added
                    $reason_key = $item['reason'] ?? 'damaged';
                    $reason_text = $damage_reason_map[$reason_key] ?? $reason_key;
                    $item_log_notes[] = "تم إضافة منتج {$reason_text}: '{$p_name}' (القيمة: " . number_format($item['price'] ?? 0, 2) . ")";
                }
            }
            
            // Check for Deleted Damaged Items
            foreach ($original_damaged_map as $id => $item) {
                if (!isset($posted_damaged_map[$id])) {
                    $item_log_notes[] = "تم حذف المنتج التالف: '{$item['product_name']}'";
                }
            }
            
            // Compare Financial and Other Fields
            if (abs(floatval($order['shipping_cost']) - $shipping_cost) > 0.01) { $other_log_notes[] = "تم تغيير تكلفة الشحن من " . number_format($order['shipping_cost'], 2) . " إلى " . number_format($shipping_cost, 2); }
            if (abs(floatval($order['additional_discount']) - $additional_discount) > 0.01) { $other_log_notes[] = "تم تغيير الخصم الإضافي من " . number_format($order['additional_discount'], 2) . " إلى " . number_format($additional_discount, 2); }
            if (trim($order['notes'] ?? '') != $notes) { $other_log_notes[] = "تم تحديث الملاحظات"; }
            if (trim($order['order_link'] ?? '') != $order_link) { $other_log_notes[] = "تم تحديث رابط الطلب"; }
            if (trim($order['additional_link'] ?? '') != $additional_link) { $other_log_notes[] = "تم تحديث الرابط الإضافي"; }

            // Compare Status - only log if it was actually changed by user selection
            $status_changed_by_user = ($order['status'] != $order_status);
            if ($status_changed_by_user) {
                $original_status_name_ar = $status_translations[$order['status']] ?? $order['status'];
                $new_status_name_ar = $status_translations[$order_status] ?? $order_status;
                $other_log_notes[] = "تم تغيير حالة الطلب من '{$original_status_name_ar}' إلى '{$new_status_name_ar}'";
            }

            // Assemble the log note based on the new logic
            $change_descriptions = [];
            
            // 1. Include Item/Product Changes only if the array is NOT empty
            if (!empty($item_log_notes)) {
                $change_descriptions[] = implode('، ', $item_log_notes);
            }

            // 2. Include Other Changes only if the array is NOT empty
            if (!empty($other_log_notes)) {
                $change_descriptions[] = implode('، ', $other_log_notes);
            }
            
            // 3. Final Assembly
            $final_log_note = '';
            if (!empty($change_descriptions)) {
                 $final_log_note = "تم إجراء التعديلات التالية: " . implode('، ', $change_descriptions) . ".";
            } else {
                $final_log_note = "تم تحديث الطلب بدون تغييرات جوهرية.";
            }
            
            // 4. Always add the financial summary
            $final_log_note .= " الإجمالي السابق: " . number_format(floatval($order['final_amount'] ?? 0), 2) . "، والإجمالي الجديد: " . number_format($final_amount, 2) . ".";

            // Insert into general history log
            $db->prepare("INSERT INTO order_status_history (order_id, status, notes, created_by, created_at) VALUES (?, 'modified', ?, ?, NOW())")->execute([$order_id, $final_log_note, $_SESSION['user_id']]);

            // Log to State History ONLY if status was actually changed by user selection
            if ($status_changed_by_user) {
                $state_log_notes = "تم تغيير الحالة بواسطة المستخدم.";
                 $db->prepare("INSERT INTO order_state_history (order_id, status, notes, changed_by_id, created_at) VALUES (?, ?, ?, ?, NOW())")
                   ->execute([$order_id, $order_status, $state_log_notes, $_SESSION['user_id']]);
            }

            $db->commit();
            header("Location: view.php?id=$order_id&success=updated");
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            die('<div style="background:#fee2e2; color:#991b1b; padding:20px; direction:ltr;"><h3>Update Failed</h3><p>' . $e->getMessage() . '</p></div>');
        }
    }
}

include '../../includes/header.php';

// Calculate original total damaged for comparison later in the POST request
$original_damaged_total = 0;
foreach ($original_damaged_items as $d) {
    $original_damaged_total += floatval($d['price']);
}

// PREPARE VALUES FOR DISPLAY
$val_subtotal = floatval($order['subtotal_amount'] ?? 0);
// This is the main discount from coupon OR automatic, which is now correctly stored in discount_amount
$val_primary_discount = floatval($order['discount_amount'] ?? 0);
$val_additional = floatval($order['additional_discount'] ?? 0);
$val_shipping = floatval($order['shipping_cost'] ?? 0);
$val_damaged = $original_damaged_total;
$val_total_after_discount = $val_subtotal - $val_primary_discount - $val_damaged - $val_additional;
$val_final = $val_total_after_discount + $val_shipping;

// DISPLAY LOGIC
$discount_display_html = '';
$is_coupon_discount = !empty($order['coupon_id']);
$discount_percentage_for_display = 0; // Default to 0

// Determine the percentage to display, prioritizing coupon if applicable
if ($is_coupon_discount) {
    if ($order['coupon_discount_type'] === 'percentage') {
        $discount_percentage_for_display = floatval($order['coupon_discount_value']);
    }
} else {
    // If not a coupon, use the stored automatic discount percentage
    $discount_percentage_for_display = floatval($order['automatic_discount_percentage'] ?? 0);
}

if ($discount_percentage_for_display > 0.01) {
    $discount_display_html = '<span class="mr-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dir-ltr" style="gap:5px;">';
    $discount_display_html .= number_format($discount_percentage_for_display, 0) . '%';
    if ($is_coupon_discount) {
        $discount_display_html .= ' <i class="fas fa-ticket-alt" title="خصم كوبون" style="color: #16a34a;"></i>';
    } else {
        $discount_display_html .= ' <i class="fas fa-percent" title="خصم تلقائي" style="color: #d97706;"></i>';
    }
    $discount_display_html .= '</span>';
}
?>

<style>
    :root { --body-bg: #f9fafb; --card-bg: #ffffff; --border-color: #e5e7eb; --primary-color: #4f46e5; }
    .case-card { background: var(--card-bg); border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05), 0 1px 2px rgba(0,0,0,0.02); }
    .summary-panel { position: sticky; top: 1.5rem; }
    .form-input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border-color); border-radius: 0.5rem; transition: all 0.2s; font-size: 0.95rem; }
    .form-input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.3); }
    .btn { padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; }
    .btn-primary { background-color: var(--primary-color); color: white; } .btn-primary:hover { background-color: #4338ca; }
    .btn-secondary { background-color: #e5e7eb; color: #374151; } .btn-secondary:hover { background-color: #d1d5db; }
    .btn-danger { background-color: #ef4444; color: white; } .btn-danger:hover { background-color: #dc2626; }
    .dir-ltr { direction: ltr; text-align: left; }
    .money-input-group { position: relative; margin-bottom: 0.5rem; }
    .money-input-group label { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: #4b5563; margin-bottom: 0.25rem; font-weight: 600; }
    .money-input-group .input-wrapper { position: relative; }
    .money-input-group input { padding-left: 3rem; text-align: left; direction: ltr; font-weight: bold; }
    .money-input-group .currency { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 0.85rem; pointer-events: none; }
</style>

<div class="min-h-screen bg-gray-50 py-8" dir="rtl">
    <div class="max-w-7xl mx-auto px-4">

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 p-4 mb-4 rounded shadow-sm">
                <p class="font-bold">خطأ!</p>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" id="editOrderForm" enctype="multipart/form-data">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

                <!-- Right Column: Products & Damaged -->
                <div class="lg:col-span-2 space-y-8">

                    <!-- 1. Order Items -->
                    <div class="case-card">
                        <div class="p-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-shopping-basket text-indigo-500"></i>منتجات الطلب</h3>
                            <button type="button" class="btn btn-primary text-sm" onclick="addItem()"><i class="fas fa-plus"></i>إضافة منتج</button>
                        </div>
                        <div class="p-4 overflow-x-auto">
                            <table class="w-full min-w-[600px]">
                                <thead class="border-b bg-gray-50">
                                    <tr class="text-sm text-gray-600">
                                        <th class="py-2 text-right px-2">المنتج</th>
                                        <th class="p-2 w-24 text-center">الكمية</th>
                                        <th class="p-2 w-32 text-center">الإجمالي</th>
                                        <th class="w-12"></th>
                                    </tr>
                                </thead>
                                <tbody id="items-container">
                                    <?php foreach ($original_items as $index => $item): ?>
                                    <tr class="item-row border-b last:border-0">
                                        <td class="p-2">
                                            <!-- FIX: Include the original item ID as a hidden field -->
                                            <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                                            <input type="text" name="items[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($item['product_name'] ?? ''); ?>" class="form-input text-sm" placeholder="اسم المنتج" required>
                                        </td>
                                        <td class="p-2">
                                            <input type="number" name="items[<?php echo $index; ?>][quantity]" value="<?php echo $item['quantity'] ?? 1; ?>" min="1" class="form-input text-center item-quantity text-sm">
                                        </td>
                                        <td class="p-2">
                                            <input type="number" name="items[<?php echo $index; ?>][total]" value="<?php echo number_format($item['total_price'] ?? 0, 2, '.', ''); ?>" step="0.01" class="form-input text-center dir-ltr item-total text-sm">
                                        </td>
                                        <td class="p-2 text-center">
                                            <button type="button" class="text-red-500 hover:text-red-700 p-2 rounded-full hover:bg-red-50" onclick="removeItem(this)"><i class="fas fa-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 2. Damaged Items -->
                    <div class="case-card">
                        <div class="p-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-exclamation-triangle text-red-500"></i>خصم المنتجات التالفة</h3>
                             <button type="button" class="btn btn-danger text-sm" onclick="addModificationRow()"><i class="fas fa-plus"></i>إضافة منتج تالف</button>
                        </div>
                        <div class="p-4 overflow-x-auto">
                            <table class="w-full min-w-[600px]">
                                <thead class="border-b bg-gray-50">
                                    <tr class="text-sm text-gray-600">
                                        <th class="py-2 text-right px-2">المنتج</th>
                                        <th class="p-2 w-32">السبب</th>
                                        <th class="p-2 w-32 text-center">القيمة المخصومة</th>
                                        <th class="w-12"></th>
                                    </tr>
                                </thead>
                                <tbody id="modification-table-body">
                                    <?php if (empty($original_damaged_items)): ?>
                                        <tr id="no-damaged-row"><td colspan="4" class="py-8 text-center text-gray-500 text-sm">لا توجد منتجات تالفة مضافة.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($original_damaged_items as $index => $item): ?>
                                        <tr class="modification-row border-b last:border-0">
                                            <td class="p-2">
                                                <!-- FIX: Include the original item ID as a hidden field -->
                                                <input type="hidden" name="damaged_items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                                                <input type="text" name="damaged_items[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($item['product_name']); ?>" class="form-input text-sm" required>
                                            </td>
                                            <td class="p-2">
                                                <select name="damaged_items[<?php echo $index; ?>][reason]" class="form-input text-sm" required>
                                                    <option value="damaged" <?php echo $item['reason'] == 'damaged' ? 'selected' : ''; ?>>تالف</option>
                                                    <option value="expired" <?php echo $item['reason'] == 'expired' ? 'selected' : ''; ?>>منتهي </option>
                                                    <option value="defective" <?php echo $item['reason'] == 'defective' ? 'selected' : ''; ?>>مفقود</option>
                                                </select>
                                            </td>
                                            <td class="p-2"><input type="number" name="damaged_items[<?php echo $index; ?>][price]" value="<?php echo number_format(floatval($item['price']), 2, '.', ''); ?>" step="0.01" min="0" class="form-input text-center dir-ltr damaged-price text-sm" required></td>
                                            <td class="p-2 text-center"><button type="button" class="text-red-500 hover:text-red-700 p-2 rounded-full hover:bg-red-50" onclick="removeModificationRow(this)"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Left Column: Summary & Actions -->
                <div class="lg:col-span-1">
                    <div class="summary-panel space-y-6">

                        <!-- Financial Summary -->
                        <div class="case-card">
                             <div class="p-4 border-b bg-gray-50"><h3 class="text-xl font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-receipt text-indigo-500"></i>الملخص المالي</h3></div>
                             <div class="p-5 space-y-1">

                                <div class="money-input-group">
                                    <label><span>المجموع الفرعي:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" id="subtotal_input" readonly class="form-input bg-gray-50 text-gray-700" value="<?php echo number_format($val_subtotal, 2); ?>">
                                    </div>
                                </div>

                                <div class="money-input-group">
                                    <label><span class="text-red-600">خصم التوالف:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" id="damaged_total_input" readonly class="form-input bg-red-50 text-red-700 border-red-200" value="<?php echo number_format($val_damaged, 2); ?>">
                                    </div>
                                </div>

                                <div class="money-input-group">
                                    <label>
                                        <span class="text-amber-600">الخصم (تلقائي/كوبون):</span>
                                        <?php echo $discount_display_html; ?>
                                    </label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" name="primary_discount_amount" id="primary_discount_input"
                                               data-is-coupon="<?php echo !empty($order['coupon_id']) ? 'true' : 'false'; ?>"
                                               data-discount-type="<?php echo htmlspecialchars($order['coupon_discount_type'] ?? 'automatic'); ?>"
                                               data-discount-value="<?php echo htmlspecialchars($is_coupon_discount ? $order['coupon_discount_value'] : $order['automatic_discount_percentage']); ?>"
                                               data-max-discount="<?php echo floatval($order['coupon_max_discount_amount'] ?? 0); ?>"
                                               step="0.01" class="form-input bg-amber-50 text-amber-700 border-amber-200"
                                               value="<?php echo number_format($val_primary_discount, 2); ?>" readonly>
                                    </div>
                                    <?php if(!empty($order['coupon_code'])): ?>
                                        <div class="text-xs text-gray-400 mt-1 dir-ltr text-right">كود الكوبون: <span class="font-mono bg-gray-100 px-1 rounded"><?php echo htmlspecialchars($order['coupon_code']); ?></span></div>
                                    <?php endif; ?>
                                </div>

                                <div class="money-input-group">
                                    <label><span class="text-orange-600">خصم إضافي:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" name="additional_discount" id="additional_discount_input" step="0.01" class="form-input text-orange-700 border-orange-200 focus:border-orange-500" value="<?php echo number_format($val_additional, 2); ?>">
                                    </div>
                                </div>

                                <hr class="border-gray-200 my-4">

                                <div class="money-input-group">
                                    <label><span>الإجمالي بعد الخصم:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" id="total_after_discount" readonly class="form-input bg-gray-50" value="<?php echo number_format(max(0, $val_total_after_discount), 2); ?>">
                                    </div>
                                </div>

                                <div class="money-input-group">
                                    <label><span>تكلفة الشحن:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" name="shipping_cost" id="shipping_cost_input" step="0.01" class="form-input" value="<?php echo number_format($val_shipping, 2); ?>">
                                    </div>
                                </div>

                                <div class="pt-3 border-t-2 border-dashed mt-2 bg-indigo-50 -mx-5 px-5 pb-3 mb-[-1.25rem] rounded-b-lg">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-xl font-bold text-indigo-700">الإجمالي النهائي:</span>
                                    </div>
                                    <div class="input-wrapper">
                                        <input type="number" id="final_total_display" readonly class="form-input bg-white text-2xl text-indigo-700 border-indigo-200 text-center font-black" value="<?php echo number_format(max(0, $val_final), 2); ?>">
                                    </div>
                                    <div class="text-center text-gray-400 text-xs mt-1">ريال يمني</div>
                                </div>
                             </div>
                        </div>

                         <div class="case-card p-5 space-y-4">
                            <div>
                                <label for="order_link" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-link ml-1 text-blue-500"></i> رابط الطلب</label>
                                <input type="url" id="order_link" name="order_link" value="<?php echo htmlspecialchars($order['order_link'] ?? ''); ?>" class="form-input" placeholder="https://example.com/order">
                            </div>
                            <div>
                                <label for="additional_link" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-link ml-1 text-purple-500"></i> رابط إضافي</label>
                                <input type="url" id="additional_link" name="additional_link" value="<?php echo htmlspecialchars($order['additional_link'] ?? ''); ?>" class="form-input" placeholder="https://example.com/additional">
                            </div>
                            <div>
                                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">ملاحظات الطلب</label>
                                <textarea id="notes" name="notes" rows="3" class="form-input"><?php echo htmlspecialchars($order['notes'] ?? ''); ?></textarea>
                            </div>
                         </div>

                        <div class="case-card p-5 space-y-4">
                            <h4 class="font-bold text-gray-800 flex items-center gap-2"><i class="fas fa-images text-purple-600"></i> صور الطلب</h4>
                            <?php if (!empty($existing_images)): ?>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                <?php foreach ($existing_images as $img):
                                    $image_url = '../../' . $img['image_path'];
                                ?>
                                <div class="relative group bg-gray-100 rounded-lg overflow-hidden border">
                                    <img src="<?php echo htmlspecialchars($image_url); ?>" class="w-full h-24 object-cover" onerror="this.src='../../assets/img/placeholder.png'">
                                    <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                        <label class="cursor-pointer text-white bg-red-600 px-2 py-1 rounded text-xs">
                                            <input type="checkbox" name="delete_images[]" value="<?php echo $img['id']; ?>" class="mr-1"> حذف
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="border-t pt-3">
                                <label class="block text-sm font-medium text-gray-700 mb-2">إضافة صور جديدة</label>
                                <input type="file" id="order_images" name="order_images[]" multiple accept="image/*" class="form-input text-sm" onchange="previewNewImages(this)">
                                <div id="newImagePreview" class="grid grid-cols-2 gap-3 mt-3 hidden"></div>
                            </div>
                        </div>

                        <div class="case-card p-5 space-y-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-info-circle text-blue-600 ml-1"></i>حالة الطلب</label>
                                <select id="status" name="status" class="form-input">
                                    <?php foreach ($all_statuses as $status): ?>
                                        <option value="<?php echo htmlspecialchars($status['status_key']); ?>" <?php echo ($order['status'] == $status['status_key']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($status['status_name_ar']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <button type="submit" class="btn btn-primary w-full justify-center text-lg py-3"><i class="fas fa-save"></i>حفظ التعديلات</button>
                            <a href="view.php?id=<?php echo $order_id; ?>" class="btn btn-secondary w-full justify-center text-lg py-3">إلغاء</a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let itemIndex = <?php echo count($original_items); ?>; // Start indexing from the number of original items
let modificationIndex = <?php echo count($original_damaged_items); ?>; // Start indexing from the number of original damaged items

document.addEventListener('DOMContentLoaded', () => {
    // Initial calculation when the page loads
    updateAllTotals();

    // Event listeners for dynamic updates
    document.getElementById('items-container').addEventListener('input', updateAllTotals);
    document.getElementById('modification-table-body').addEventListener('input', updateAllTotals);
    document.getElementById('shipping_cost_input').addEventListener('input', updateAllTotals);
    document.getElementById('additional_discount_input').addEventListener('input', updateAllTotals);
});

function updateAllTotals() {
    let subtotal = 0;
    document.querySelectorAll('#items-container .item-row').forEach(row => {
        const totalInput = row.querySelector('.item-total');
        const val = parseFloat(totalInput.value);
        subtotal += isNaN(val) ? 0 : val;
    });
    document.getElementById('subtotal_input').value = subtotal.toFixed(2);

    let damagedTotal = 0;
    document.querySelectorAll('#modification-table-body .modification-row').forEach(row => {
        const priceInput = row.querySelector('.damaged-price');
        const val = parseFloat(priceInput.value);
        damagedTotal += isNaN(val) ? 0 : val;
    });
    document.getElementById('damaged_total_input').value = damagedTotal.toFixed(2);

    // --- START: CORRECTED JAVASCRIPT DISCOUNT LOGIC ---
    const discountInput = document.getElementById('primary_discount_input');
    const dataset = discountInput.dataset;

    let netValueForDiscount = subtotal - damagedTotal;
    if (netValueForDiscount < 0) netValueForDiscount = 0;

    let calculatedDiscount = 0;
    
    if (dataset.isCoupon === 'true') {
        const discountValue = parseFloat(dataset.discountValue) || 0;
        if (dataset.discountType === 'percentage') {
            calculatedDiscount = netValueForDiscount * (discountValue / 100);
            const maxDiscount = parseFloat(dataset.maxDiscount) || 0;
            if (maxDiscount > 0 && calculatedDiscount > maxDiscount) {
                calculatedDiscount = maxDiscount;
            }
        } else if (dataset.discountType === 'fixed') {
            calculatedDiscount = Math.min(discountValue, netValueForDiscount);
        }
    } else { // Automatic discount logic
        const discountPercentage = parseFloat(dataset.discountValue) || 0;
        if (discountPercentage > 0) {
            calculatedDiscount = netValueForDiscount * (discountPercentage / 100);
        }
    }
    
    discountInput.value = calculatedDiscount.toFixed(2);
    // --- END: CORRECTED JAVASCRIPT DISCOUNT LOGIC ---

    const additionalDiscount = parseFloat(document.getElementById('additional_discount_input').value) || 0;
    const shippingCost = parseFloat(document.getElementById('shipping_cost_input').value) || 0;

    const totalAfterDeductions = subtotal - calculatedDiscount - damagedTotal - additionalDiscount;
    const finalTotal = (totalAfterDeductions > 0 ? totalAfterDeductions : 0) + shippingCost;

    document.getElementById('total_after_discount').value = (totalAfterDeductions > 0 ? totalAfterDeductions : 0).toFixed(2);
    document.getElementById('final_total_display').value = finalTotal.toFixed(2);
}

function addItem() {
    const container = document.getElementById('items-container');
    const newRow = document.createElement('tr');
    newRow.className = 'item-row border-b last:border-0';
    newRow.innerHTML = `
        <td class="p-2">
            <!-- New item has ID 0 for comparison logic -->
            <input type="hidden" name="items[${itemIndex}][id]" value="0"> 
            <input type="text" name="items[${itemIndex}][name]" class="form-input text-sm" placeholder="اسم المنتج" required>
        </td>
        <td class="p-2"><input type="number" name="items[${itemIndex}][quantity]" value="1" min="1" class="form-input text-center item-quantity text-sm"></td>
        <td class="p-2"><input type="number" name="items[${itemIndex}][total]" value="0.00" step="0.01" class="form-input text-center dir-ltr item-total text-sm"></td>
        <td class="p-2 text-center"><button type="button" class="text-red-500 hover:text-red-700 p-2 rounded-full hover:bg-red-50" onclick="removeItem(this)"><i class="fas fa-trash"></i></button></td>
    `;
    container.appendChild(newRow);
    itemIndex++;
    updateAllTotals(); // Ensure totals are updated after adding a new item
}

function removeItem(button) {
    // Prevent removing the last item
    if (document.querySelectorAll('.item-row').length > 1) {
        button.closest('.item-row').remove();
        updateAllTotals();
    } else {
        alert('يجب أن يحتوي الطلب على منتج واحد على الأقل.');
    }
}

function addModificationRow() {
    const tbody = document.getElementById('modification-table-body');
    // Remove the "no items" row if it exists
    document.getElementById('no-damaged-row')?.remove(); 
    
    const newRow = document.createElement('tr');
    newRow.className = 'modification-row border-b last:border-0';
    newRow.innerHTML = `
        <td class="p-2">
            <!-- New item has ID 0 for comparison logic -->
            <input type="hidden" name="damaged_items[${modificationIndex}][id]" value="0">
            <input type="text" name="damaged_items[${modificationIndex}][name]" class="form-input text-sm" required>
        </td>
        <td class="p-2"><select name="damaged_items[${modificationIndex}][reason]" class="form-input text-sm" required><option value="damaged">تالف</option><option value="expired">منتهي </option><option value="defective">مفقود</option></select></td>
        <td class="p-2"><input type="number" name="damaged_items[${modificationIndex}][price]" value="0.00" step="0.01" min="0" class="form-input text-center dir-ltr damaged-price text-sm" required></td>
        <td class="p-2 text-center"><button type="button" class="text-red-500 hover:text-red-700 p-2 rounded-full hover:bg-red-50" onclick="removeModificationRow(this)"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(newRow);
    modificationIndex++;
    updateAllTotals(); // Ensure totals are updated after adding a new row
}

function removeModificationRow(button) {
    button.closest('.modification-row').remove();
    // If no more modification rows exist, re-add the "no items" placeholder
    if (document.querySelectorAll('.modification-row').length === 0) {
        document.getElementById('modification-table-body').innerHTML = `<tr id="no-damaged-row"><td colspan="4" class="py-8 text-center text-gray-500 text-sm">لا توجد منتجات تالفة مضافة.</td></tr>`;
    }
    updateAllTotals(); // Ensure totals are updated after removing a row
}

function previewNewImages(input) {
    const container = document.getElementById('newImagePreview');
    container.innerHTML = ''; // Clear previous previews
    if (input.files && input.files.length > 0) {
        container.classList.remove('hidden');
        Array.from(input.files).forEach((file) => {
            if (!file.type.startsWith('image/')) return; // Only process image files
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'relative group bg-gray-100 rounded-lg overflow-hidden h-24 border border-green-300';
                div.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                container.appendChild(div);
            };
            reader.readAsDataURL(file);
        });
    } else { 
        container.classList.add('hidden'); // Hide preview container if no files are selected
    }
}
</script>

<?php include '../../includes/footer.php'; ?>