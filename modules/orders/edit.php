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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/accounting_functions.php';

header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");

$page_title = 'تعديل الطلب';
$error_message = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}
$order_id = intval($_GET['id']);

if (!hasPermission($_SESSION['user_id'], 'orders', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل الطلب';
    header('Location: view.php?id=' . $order_id);
    exit();
}

try {
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

    $statuses_stmt = $db->query("SELECT status_key, status_name_ar FROM customer_order_statuses ORDER BY is_default DESC, id ASC");
    $all_statuses = $statuses_stmt->fetchAll(PDO::FETCH_ASSOC);
    $status_translations = array_column($all_statuses, 'status_name_ar', 'status_key');

    $items_stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $items_stmt->execute([$order_id]);
    $original_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $damaged_items_stmt = $db->prepare("SELECT * FROM order_damaged_items WHERE order_id = ? ORDER BY id");
    $damaged_items_stmt->execute([$order_id]);
    $original_damaged_items = $damaged_items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $images_stmt = $db->prepare("SELECT * FROM order_images WHERE order_id = ? ORDER BY display_order, id");
    $images_stmt->execute([$order_id]);
    $existing_images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('<div style="background:#fee2e2; color:#991b1b; padding:20px; text-align:center; direction:ltr;"><h3>Database Error</h3><p>' . $e->getMessage() . '</p></div>');
}

// ---------------------------------------------------------
// HANDLE FORM SUBMISSION
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($order)) {

    $notes        = trim($_POST['notes'] ?? '');
    $order_status = $order['status'];
    if (isset($_POST['status']) && $_POST['status'] !== $order['status']) {
        $order_status = $_POST['status'];
    }
    $posted_items         = $_POST['items'] ?? [];
    $posted_damaged_items = $_POST['damaged_items'] ?? [];
    $order_link           = trim($_POST['order_link'] ?? '');
    $additional_link      = trim($_POST['additional_link'] ?? '');

    $shipping_cost           = floatval($_POST['shipping_cost'] ?? 0);
    $additional_discount     = floatval($_POST['additional_discount'] ?? 0);
    $manual_override         = isset($_POST['manual_override']) && $_POST['manual_override'] == '1';
    $manual_subtotal         = $manual_override ? floatval($_POST['manual_subtotal'] ?? 0) : 0;
    $manual_primary_discount = $manual_override ? floatval($_POST['manual_primary_discount'] ?? 0) : 0;
    $manual_total_after_discount = $manual_override ? floatval($_POST['manual_total_after_discount'] ?? 0) : 0;
    $manual_final_total      = $manual_override ? floatval($_POST['manual_final_total'] ?? 0) : 0;

    if (empty($posted_items)) {
        $error_message = 'يجب إضافة منتج واحد على الأقل';
    } else {
        try {
            $db->beginTransaction();

            // --- Detect if financial values should be preserved (no manual override, no shipping/discount changes) ---
            $preserve_financial_values = !$manual_override &&
                                         abs(floatval($order['shipping_cost']) - $shipping_cost) <= 0.001 &&
                                         abs(floatval($order['additional_discount']) - $additional_discount) <= 0.001;

            // --- 1. Calculate New Totals (or preserve original values) ---
            if ($preserve_financial_values) {
                // Preserve original financial values from database
                $subtotal_amount = floatval($order['subtotal_amount']);
                $calculated_discount = floatval($order['automatic_discount_amount'] ?? 0) + floatval($order['discount_amount'] ?? 0);
                $final_automatic_discount_amount = floatval($order['automatic_discount_amount'] ?? 0);
                $total_amount = floatval($order['total_amount']);
                $final_amount = floatval($order['final_amount']);
                $discount_percentage_for_calculation = floatval($order['automatic_discount_percentage']);
                $new_total_quantity = 0;
                foreach ($posted_items as $item) {
                    $new_total_quantity += intval($item['quantity'] ?? 0);
                }
                $damaged_total = 0;
                foreach ($posted_damaged_items as $damaged) {
                    $damaged_total += floatval($damaged['price']);
                }
                $net_value_for_discount = max(0, $subtotal_amount - $damaged_total);
                $total_discount_for_journal = $calculated_discount + $additional_discount;
            } else {
                // Normal recalculation when manual override or financial changes
                $subtotal_amount    = 0;
                $new_total_quantity = 0;
                foreach ($posted_items as $item) {
                    $subtotal_amount    += floatval($item['total']);
                    $new_total_quantity += intval($item['quantity'] ?? 0);
                }

                $damaged_total = 0;
                foreach ($posted_damaged_items as $damaged) {
                    $damaged_total += floatval($damaged['price']);
                }

                $net_value_for_discount = max(0, $subtotal_amount - $damaged_total);

                // --- Discount Calculation ---
                $calculated_discount              = 0;
                $final_automatic_discount_amount  = 0;
                $discount_percentage_for_calculation = floatval($order['automatic_discount_percentage']);
                $coupon_details = [];

                if (!empty($order['coupon_id']) && !empty($order['coupon_discount_type'])) {
                    $coupon_details = [
                        'type'       => $order['coupon_discount_type'],
                        'value'      => floatval($order['coupon_discount_value']),
                        'max_amount' => floatval($order['coupon_max_discount_amount']),
                        'code'       => $order['coupon_code'],
                    ];
                    $discount_percentage_for_calculation = 0;
                }

                if ($coupon_details) {
                    if ($coupon_details['type'] === 'percentage') {
                        $calculated_discount = $net_value_for_discount * ($coupon_details['value'] / 100);
                        if ($coupon_details['max_amount'] > 0 && $calculated_discount > $coupon_details['max_amount']) {
                            $calculated_discount = $coupon_details['max_amount'];
                        }
                    } elseif ($coupon_details['type'] === 'fixed') {
                        $calculated_discount = min($coupon_details['value'], $net_value_for_discount);
                    }
                    $final_automatic_discount_amount = 0;
                } elseif ($discount_percentage_for_calculation > 0) {
                    $calculated_discount             = $net_value_for_discount * ($discount_percentage_for_calculation / 100);
                    $final_automatic_discount_amount = $calculated_discount;
                }

                // --- Final totals (with optional manual override) ---
                if ($manual_override) {
                    $subtotal_amount     = $manual_subtotal > 0 ? $manual_subtotal : $subtotal_amount;
                    $calculated_discount = $manual_primary_discount;
                    $total_amount        = $manual_total_after_discount > 0
                        ? $manual_total_after_discount
                        : max(0, $subtotal_amount - $calculated_discount - $damaged_total - $additional_discount);
                    $final_amount        = $manual_final_total > 0
                        ? $manual_final_total
                        : max(0, $total_amount + $shipping_cost);
                } else {
                    $total_amount = max(0, $subtotal_amount - $calculated_discount - $damaged_total - $additional_discount);
                    $final_amount = $total_amount + $shipping_cost;
                }

                $total_discount_for_journal = $calculated_discount + $additional_discount;
            }

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
                $discount_percentage_for_calculation,
                $final_automatic_discount_amount,
                $calculated_discount,
                $total_amount,
                $final_amount,
                $shipping_cost,
                $additional_discount,
                $notes,
                $order_status,
                empty($order_link) ? null : $order_link,
                empty($additional_link) ? null : $additional_link,
                $order_id,
            ]);

            // --- 3. Sync Invoice ---
            if (!$preserve_financial_values) {
                $invoice_base_amount  = $total_amount;
                $invoice_tax_amount   = $invoice_base_amount * 0.15;
                $invoice_total_amount = $invoice_base_amount + $invoice_tax_amount;
                $db->prepare("UPDATE customer_invoices SET amount = ?, tax_amount = ?, total_amount = ?, updated_at = NOW() WHERE order_id = ?")
                   ->execute([$invoice_base_amount, $invoice_tax_amount, $invoice_total_amount, $order_id]);
            }

            // --- 4. Update Order Items ---
            $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);
            $item_stmt = $db->prepare("INSERT INTO order_items (order_id, product_name, quantity, unit_price, total_price, notes, product_link, product_status, shein_sku) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($posted_items as $item) {
                $qty        = intval($item['quantity']);
                $total_price = floatval($item['total']);
                $unit_price  = ($qty > 0) ? ($total_price / $qty) : 0;
                $sku_value   = trim($item['sku'] ?? '') !== '' ? trim($item['sku']) : null;
                $item_status = trim($item['status'] ?? 'available');
                if (empty($item_status)) {
                    $item_status = 'available';
                }
                $item_notes  = $item['notes'] ?? ($item['notes_hidden'] ?? '');
                $item_link   = $item['link'] ?? ($item['link_hidden'] ?? '');
                $item_stmt->execute([$order_id, $item['name'], $qty, $unit_price, $total_price, $item_notes, $item_link, $item_status, $sku_value]);
            }

            // --- 5. Update Damaged Items ---
            $db->prepare("DELETE FROM order_damaged_items WHERE order_id = ?")->execute([$order_id]);
            if (!empty($posted_damaged_items)) {
                $damaged_stmt = $db->prepare("INSERT INTO order_damaged_items (order_id, product_name, product_link, price, reason, notes) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($posted_damaged_items as $damaged) {
                    $damaged_stmt->execute([$order_id, $damaged['name'], $damaged['link'] ?? '', floatval($damaged['price']), $damaged['reason'], $damaged['notes'] ?? '']);
                }
            }

            // --- 6. Handle Image Uploads ---
            if (isset($_FILES['order_images']) && !empty($_FILES['order_images']['name'][0])) {
                $upload_dir = '../../uploads/orders/images/';
                if (!file_exists($upload_dir)) { mkdir($upload_dir, 0755, true); }
                foreach ($_FILES['order_images']['name'] as $key => $filename) {
                    if ($_FILES['order_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_tmp = $_FILES['order_images']['tmp_name'][$key];
                        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        if (in_array($file_ext, ['jpg','jpeg','png','gif','webp'])) {
                            $new_filename = 'order_' . $order_id . '_' . time() . '_' . $key . '.' . $file_ext;
                            $file_path    = $upload_dir . $new_filename;
                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $db->prepare("INSERT INTO order_images (order_id, image_path, image_name, image_type, image_size, display_order, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                                   ->execute([$order_id, 'uploads/orders/images/' . $new_filename, $filename, $_FILES['order_images']['type'][$key], $_FILES['order_images']['size'][$key], 1, $_SESSION['user_id']]);
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

            // --- 8. Accounting (delete & recreate) ---
            if (!$preserve_financial_values) {
                try {
                    delete_journal_entry_by_source($db, 'orders', $order_id);
                    $ar_account_id       = get_accounting_setting($db, 'default_accounts_receivable_id');
                    $sales_account_id    = get_accounting_setting($db, 'default_sales_revenue_id');
                    $shipping_account_id = get_accounting_setting($db, 'default_shipping_revenue_id');
                    $discount_account_id = get_accounting_setting($db, 'default_sales_discount_id');
                    $description         = "تعديل إيراد الطلب رقم " . $order['order_number'];
                    $entry_items = [
                        ['account_id' => $ar_account_id,       'type' => 'debit',  'amount' => $final_amount],
                        ['account_id' => $discount_account_id, 'type' => 'debit',  'amount' => $total_discount_for_journal],
                        ['account_id' => $sales_account_id,    'type' => 'credit', 'amount' => $subtotal_amount],
                        ['account_id' => $shipping_account_id, 'type' => 'credit', 'amount' => $shipping_cost],
                    ];
                    create_journal_entry($db, date('Y-m-d'), $description, $entry_items, 'orders', $order_id, $_SESSION['user_id']);
                } catch (Exception $acc_e) {
                    error_log("Accounting update failed for Order ID $order_id: " . $acc_e->getMessage());
                }
            }

            // --- 9. Change Logging ---
            $change_descriptions = [];
            $old_final_amount = floatval($order['final_amount'] ?? 0);

            // Build maps for comparison
            $original_items_map = array_column($original_items, null, 'id');
            $posted_items_map   = [];
            foreach ($posted_items as $item) {
                $item_id = intval($item['id'] ?? 0);
                if ($item_id > 0) {
                    $posted_items_map[$item_id] = $item;
                }
            }

            // Detect deleted items
            foreach ($original_items_map as $orig_id => $orig_item) {
                if (!isset($posted_items_map[$orig_id])) {
                    $change_descriptions[] = "تم حذف المنتج \"{$orig_item['product_name']}\".";
                }
            }

            // Detect added / modified items
            foreach ($posted_items as $item) {
                $item_id   = intval($item['id'] ?? 0);
                $item_name = trim($item['name'] ?? '');
                $item_qty  = intval($item['quantity'] ?? 0);
                $item_total = floatval($item['total'] ?? 0);

                if ($item_id === 0) {
                    $change_descriptions[] = "تم إضافة منتج جديد \"{$item_name}\" (الكمية: {$item_qty}، الإجمالي: " . number_format($item_total, 2) . " ريال).";
                } elseif (isset($original_items_map[$item_id])) {
                    $orig = $original_items_map[$item_id];
                    $changes = [];
                    if (intval($orig['quantity']) !== $item_qty) {
                        $changes[] = "تم تغيير كمية المنتج \"{$item_name}\" من {$orig['quantity']} إلى {$item_qty}.";
                    }
                    if (abs(floatval($orig['total_price']) - $item_total) > 0.001) {
                        $changes[] = "تم تغيير إجمالي المنتج \"{$item_name}\" من " . number_format(floatval($orig['total_price']), 2) . " ريال إلى " . number_format($item_total, 2) . " ريال.";
                    }
                    if ($orig['product_name'] !== $item_name) {
                        $changes[] = "تم تغيير اسم المنتج من \"{$orig['product_name']}\" إلى \"{$item_name}\".";
                    }
                    $change_descriptions = array_merge($change_descriptions, $changes);
                }
            }

            // Damaged items log
            $original_damaged_map = array_column($original_damaged_items, null, 'id');
            $reason_labels = ['damaged' => 'تالف', 'expired' => 'منتهي الصلاحية', 'defective' => 'مفقود'];

            foreach ($original_damaged_map as $orig_id => $orig_d) {
                $found = false;
                foreach ($posted_damaged_items as $d) {
                    if (intval($d['id'] ?? 0) === $orig_id) { $found = true; break; }
                }
                if (!$found) {
                    $change_descriptions[] = "تم إزالة تسجيل المنتج {$reason_labels[$orig_d['reason']]} \"{$orig_d['product_name']}\".";
                }
            }
            foreach ($posted_damaged_items as $d) {
                $d_id    = intval($d['id'] ?? 0);
                $d_name  = trim($d['name'] ?? '');
                $d_price = floatval($d['price'] ?? 0);
                $d_reason = $reason_labels[$d['reason'] ?? 'damaged'] ?? $d['reason'];
                if ($d_id === 0) {
                    $change_descriptions[] = "تم تسجيل منتج {$d_reason} \"{$d_name}\" (القيمة: " . number_format($d_price, 2) . " ريال).";
                } elseif (isset($original_damaged_map[$d_id])) {
                    $orig_d  = $original_damaged_map[$d_id];
                    if (abs(floatval($orig_d['price']) - $d_price) > 0.001) {
                        $change_descriptions[] = "تم تحديث قيمة المنتج {$d_reason} \"{$d_name}\" من " . number_format(floatval($orig_d['price']), 2) . " ريال إلى " . number_format($d_price, 2) . " ريال.";
                    }
                }
            }

            // Financial / admin changes
            if (abs(floatval($order['shipping_cost']) - $shipping_cost) > 0.001) {
                $change_descriptions[] = "تم تغيير تكلفة الشحن من " . number_format(floatval($order['shipping_cost']), 2) . " ريال إلى " . number_format($shipping_cost, 2) . " ريال.";
            }
            if (abs(floatval($order['additional_discount']) - $additional_discount) > 0.001) {
                $change_descriptions[] = "تم تغيير الخصم الإضافي من " . number_format(floatval($order['additional_discount']), 2) . " ريال إلى " . number_format($additional_discount, 2) . " ريال.";
            }
            if ($order['status'] !== $order_status) {
                $old_status_label = $status_translations[$order['status']] ?? $order['status'];
                $new_status_label = $status_translations[$order_status] ?? $order_status;
                $change_descriptions[] = "تم تغيير حالة الطلب من \"{$old_status_label}\" إلى \"{$new_status_label}\".";
            }
            if (($order['order_link'] ?? '') !== $order_link || ($order['additional_link'] ?? '') !== $additional_link) {
                $change_descriptions[] = "تم تحديث روابط الطلب.";
            }
            if (($order['notes'] ?? '') !== $notes) {
                $change_descriptions[] = "تم تحديث ملاحظات الطلب.";
            }

            // Build natural Arabic log
            if (!empty($change_descriptions)) {
                $log_notes = "تم إجراء التعديلات التالية:\n\n";
                foreach ($change_descriptions as $description) {
                    $log_notes .= "• {$description}\n";
                }
                // Only show financial summary if it changed or if not preserving values
                if ($preserve_financial_values) {
                    $log_notes .= "\nملاحظة: تم الاحتفاظ بالملخص المالي الأصلي (الإجمالي: " . number_format($final_amount, 2) . " ريال).";
                } else {
                    $log_notes .= "\nالإجمالي السابق: " . number_format($old_final_amount, 2) . " ريال.\n";
                    $log_notes .= "الإجمالي الجديد: " . number_format($final_amount, 2) . " ريال.";
                }
            } else {
                $log_notes = "تم حفظ الطلب بدون تغييرات ملحوظة.\n\nالإجمالي: " . number_format($final_amount, 2) . " ريال.";
            }

            $log_stmt = $db->prepare("
                INSERT INTO order_status_history (order_id, status, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([$order_id, $order_status, $log_notes, $_SESSION['user_id']]);

            $db->commit();

            $_SESSION['success_message'] = 'تم تحديث الطلب بنجاح';
            header('Location: view.php?id=' . $order_id);
            exit();

        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = 'حدث خطأ أثناء تحديث الطلب: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// PREPARE VIEW VARIABLES (used whether GET or failed POST)
// ---------------------------------------------------------
$is_coupon_discount = !empty($order['coupon_id']);

$val_subtotal          = floatval($order['subtotal_amount'] ?? 0);
$val_damaged           = 0;
foreach ($original_damaged_items as $d) { $val_damaged += floatval($d['price']); }
$val_primary_discount  = floatval($order['discount_amount'] ?? 0);
$val_additional        = floatval($order['additional_discount'] ?? 0);
$val_total_after_discount = floatval($order['total_amount'] ?? 0);
$val_shipping          = floatval($order['shipping_cost'] ?? 0);
$val_final             = floatval($order['final_amount'] ?? 0);

// Build the discount badge HTML
if ($is_coupon_discount) {
    $coupon_type  = $order['coupon_discount_type'] ?? '';
    $coupon_val   = floatval($order['coupon_discount_value'] ?? 0);
    $badge_label  = ($coupon_type === 'percentage') ? number_format($coupon_val, 0) . '%' : number_format($coupon_val, 2) . ' ريال';
    $discount_display_html = '<span class="text-xs bg-amber-100 text-amber-700 border border-amber-300 rounded px-1 py-0.5 mr-1">كوبون ' . $badge_label . '</span>';
} elseif (floatval($order['automatic_discount_percentage'] ?? 0) > 0) {
    $auto_pct = number_format(floatval($order['automatic_discount_percentage']), 0);
    $discount_display_html = '<span class="text-xs bg-green-100 text-green-700 border border-green-300 rounded px-1 py-0.5 mr-1">خصم تلقائي ' . $auto_pct . '%</span>';
} else {
    $discount_display_html = '';
}

include '../../includes/header.php';
?>

<style>
    .case-card { background: #fff; border-radius: 0.75rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,.06); overflow: hidden; }
    .form-input { width: 100%; border: 1px solid #d1d5db; border-radius: 0.375rem; padding: 0.45rem 0.75rem; font-size: 0.9rem; transition: border-color .15s, box-shadow .15s; background: #fff; }
    .form-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
    .form-input[readonly] { cursor: default; }
    .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.9rem; cursor: pointer; border: none; transition: background .15s, transform .1s; }
    .btn:active { transform: scale(.97); }
    .btn-primary { background: #6366f1; color: #fff; }
    .btn-primary:hover { background: #4f46e5; }
    .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #e5e7eb; }
    .btn-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
    .btn-danger:hover { background: #dc2626; color: #fff; }
    .summary-panel { position: sticky; top: 1.5rem; }
    .dir-ltr { direction: ltr; }
    .money-input-group { margin-bottom: 0.75rem; }
    .money-input-group label { display: flex; align-items: center; justify-content: space-between; font-size: 0.85rem; color: #4b5563; margin-bottom: 0.25rem; font-weight: 600; }
    .money-input-group .input-wrapper { position: relative; }
    .money-input-group input { padding-left: 3rem; text-align: left; direction: ltr; font-weight: bold; }
    .money-input-group .currency { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 0.85rem; pointer-events: none; }
    .btn-delete-row { background-color: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 0.375rem; padding: 0.3rem 0.65rem; font-size: 0.85rem; font-weight: 700; cursor: pointer; line-height: 1; transition: background .15s; }
    .btn-delete-row:hover { background-color: #dc2626; color: #fff; border-color: #dc2626; }
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
                        <div class="p-4 border-b flex justify-between items-center" style="cursor:pointer;" onclick="toggleOrderItems(event)">
                            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-shopping-basket text-indigo-500"></i>منتجات الطلب
                                <span id="items-count-badge" class="text-xs font-normal bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full"></span>
                            </h3>
                            <div class="flex items-center gap-2">
                <i id="order-items-chevron" class="fas fa-chevron-down text-gray-400" style="transition:transform .2s; transform:rotate(180deg);"></i>
                                <button type="button" class="btn btn-primary text-sm" onclick="event.stopPropagation(); addItem()">+ إضافة منتج</button>
                            </div>
                        </div>
                        <div id="order-items-body">
                        <div class="p-4 overflow-x-auto">
                            <table class="w-full min-w-[600px]">
                                <thead class="border-b bg-gray-50">
                                    <tr class="text-sm text-gray-600">
                                        <th class="py-2 text-right px-2">المنتج</th>
                                        <th class="p-2 w-24 text-center">الكمية</th>
                                        <th class="p-2 w-32 text-center">الإجمالي</th>
                                        <th class="p-2 w-36 text-center">SKU</th>
                                        <th class="w-16 text-center">حذف</th>
                                    </tr>
                                </thead>
                                <tbody id="items-container">
                                    <?php foreach ($original_items as $index => $item): ?>
                                    <tr class="item-row border-b last:border-0">
                                        <td class="p-2">
                                            <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                                            <input type="hidden" name="items[<?php echo $index; ?>][status]" value="<?php echo htmlspecialchars($item['product_status'] ?? 'available'); ?>">
                                            <input type="hidden" name="items[<?php echo $index; ?>][notes_hidden]" value="<?php echo htmlspecialchars($item['notes'] ?? ''); ?>">
                                            <input type="hidden" name="items[<?php echo $index; ?>][link_hidden]" value="<?php echo htmlspecialchars($item['product_link'] ?? ''); ?>">
                                            <input type="text" name="items[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($item['product_name'] ?? ''); ?>" class="form-input text-sm" placeholder="اسم المنتج" required>
                                        </td>
                                        <td class="p-2">
                                            <input type="number" name="items[<?php echo $index; ?>][quantity]" value="<?php echo $item['quantity'] ?? 1; ?>" min="1" class="form-input text-center item-quantity text-sm">
                                        </td>
                                        <td class="p-2">
                                            <input type="number" name="items[<?php echo $index; ?>][total]" value="<?php echo number_format(floatval($item['total_price'] ?? 0), 2, '.', ''); ?>" step="0.01" class="form-input text-center dir-ltr item-total text-sm">
                                        </td>
                                        <td class="p-2">
                                            <input type="text" name="items[<?php echo $index; ?>][sku]" value="<?php echo htmlspecialchars($item['shein_sku'] ?? ''); ?>" class="form-input text-sm dir-ltr" placeholder="SKU" autocomplete="off" spellcheck="false">
                                        </td>
                                        <td class="p-2 text-center">
                                            <button type="button" class="btn-delete-row" onclick="removeItem(this)" title="حذف">&#10005;</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                    </div>

                    <!-- 2. Damaged Items -->
                    <div class="case-card">
                        <div class="p-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-exclamation-triangle text-red-500"></i>خصم المنتجات التالفة
                            </h3>
                            <button type="button" class="btn btn-danger text-sm" onclick="addModificationRow()">+ إضافة منتج تالف</button>
                        </div>
                        <div class="p-4 overflow-x-auto">
                            <table class="w-full min-w-[600px]">
                                <thead class="border-b bg-gray-50">
                                    <tr class="text-sm text-gray-600">
                                        <th class="py-2 text-right px-2">المنتج</th>
                                        <th class="p-2 w-32">السبب</th>
                                        <th class="p-2 w-32 text-center">القيمة المخصومة</th>
                                        <th class="w-16 text-center">حذف</th>
                                    </tr>
                                </thead>
                                <tbody id="modification-table-body">
                                    <?php if (empty($original_damaged_items)): ?>
                                        <tr id="no-damaged-row"><td colspan="4" class="py-8 text-center text-gray-500 text-sm">لا توجد منتجات تالفة مضافة.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($original_damaged_items as $index => $item): ?>
                                        <tr class="modification-row border-b last:border-0">
                                            <td class="p-2">
                                                <input type="hidden" name="damaged_items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                                                <input type="text" name="damaged_items[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($item['product_name']); ?>" class="form-input text-sm" required>
                                            </td>
                                            <td class="p-2">
                                                <select name="damaged_items[<?php echo $index; ?>][reason]" class="form-input text-sm" required>
                                                    <option value="damaged"   <?php echo $item['reason'] == 'damaged'   ? 'selected' : ''; ?>>تالف</option>
                                                    <option value="expired"   <?php echo $item['reason'] == 'expired'   ? 'selected' : ''; ?>>منتهي</option>
                                                    <option value="defective" <?php echo $item['reason'] == 'defective' ? 'selected' : ''; ?>>مفقود</option>
                                                </select>
                                            </td>
                                            <td class="p-2">
                                                <input type="number" name="damaged_items[<?php echo $index; ?>][price]" value="<?php echo number_format(floatval($item['price']), 2, '.', ''); ?>" step="0.01" min="0" class="form-input text-center dir-ltr damaged-price text-sm" required>
                                            </td>
                                            <td class="p-2 text-center">
                                                <button type="button" class="btn-delete-row" onclick="removeModificationRow(this)" title="حذف">&#10005;</button>
                                            </td>
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
                            <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
                                <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-receipt text-indigo-500"></i>الملخص المالي
                                </h3>
                                <button type="button" id="toggleOverrideBtn" onclick="toggleManualOverride()" class="btn text-xs px-3 py-1.5" style="background:#f3f4f6; color:#374151; border:1px solid #d1d5db;">
                                    <i class="fas fa-lock" id="overrideIcon"></i>
                                    <span id="overrideBtnText">تفعيل التعديل اليدوي</span>
                                </button>
                            </div>
                            <input type="hidden" name="manual_override"              id="manual_override_flag"             value="0">
                            <input type="hidden" name="manual_subtotal"              id="manual_subtotal_hidden"            value="<?php echo number_format($val_subtotal, 2, '.', ''); ?>">
                            <input type="hidden" name="manual_primary_discount"      id="manual_primary_discount_hidden"    value="<?php echo number_format($val_primary_discount, 2, '.', ''); ?>">
                            <input type="hidden" name="manual_total_after_discount"  id="manual_total_after_discount_hidden" value="<?php echo number_format($val_total_after_discount, 2, '.', ''); ?>">
                            <input type="hidden" name="manual_final_total"           id="manual_final_total_hidden"         value="<?php echo number_format($val_final, 2, '.', ''); ?>">
                            <div class="p-5 space-y-1">

                                <div class="money-input-group">
                                    <label><span>المجموع الفرعي:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" id="subtotal_input" readonly class="form-input bg-gray-50 text-gray-700 summary-field" value="<?php echo number_format($val_subtotal, 2, '.', ''); ?>">
                                    </div>
                                </div>

                                <div class="money-input-group">
                                    <label><span class="text-red-600">خصم التوالف:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" id="damaged_total_input" readonly class="form-input bg-red-50 text-red-700 border-red-200 summary-field" value="<?php echo number_format($val_damaged, 2, '.', ''); ?>">
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
                                               data-is-coupon="<?php echo $is_coupon_discount ? 'true' : 'false'; ?>"
                                               data-discount-type="<?php echo htmlspecialchars($order['coupon_discount_type'] ?? 'automatic'); ?>"
                                               data-discount-value="<?php echo htmlspecialchars($is_coupon_discount ? $order['coupon_discount_value'] : $order['automatic_discount_percentage']); ?>"
                                               data-max-discount="<?php echo floatval($order['coupon_max_discount_amount'] ?? 0); ?>"
                                               step="0.01" class="form-input bg-amber-50 text-amber-700 border-amber-200 summary-field"
                                               value="<?php echo number_format($val_primary_discount, 2, '.', ''); ?>" readonly>
                                    </div>
                                    <?php if (!empty($order['coupon_code'])): ?>
                                        <div class="text-xs text-gray-400 mt-1 dir-ltr text-right">كود الكوبون: <span class="font-mono bg-gray-100 px-1 rounded"><?php echo htmlspecialchars($order['coupon_code']); ?></span></div>
                                    <?php endif; ?>
                                </div>

                                <div class="money-input-group">
                                    <label><span class="text-orange-600">خصم إضافي:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" name="additional_discount" id="additional_discount_input" step="0.01" class="form-input text-orange-700 border-orange-200 focus:border-orange-500" value="<?php echo number_format($val_additional, 2, '.', ''); ?>">
                                    </div>
                                </div>

                                <hr class="border-gray-200 my-4">

                                <div class="money-input-group">
                                    <label><span>الإجمالي بعد الخصم:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" id="total_after_discount" readonly class="form-input bg-gray-50 summary-field" value="<?php echo number_format(max(0, $val_total_after_discount), 2, '.', ''); ?>">
                                    </div>
                                </div>

                                <div class="money-input-group">
                                    <label><span>تكلفة الشحن:</span></label>
                                    <div class="input-wrapper">
                                        <span class="currency">ريال</span>
                                        <input type="number" name="shipping_cost" id="shipping_cost_input" step="0.01" class="form-input" value="<?php echo number_format($val_shipping, 2, '.', ''); ?>">
                                    </div>
                                </div>

                                <div class="pt-3 border-t-2 border-dashed mt-2 bg-indigo-50 -mx-5 px-5 pb-3 mb-[-1.25rem] rounded-b-lg">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-xl font-bold text-indigo-700">الإجمالي النهائي:</span>
                                    </div>
                                    <div class="input-wrapper">
                                        <input type="number" id="final_total_display" readonly class="form-input bg-white text-2xl text-indigo-700 border-indigo-200 text-center font-black summary-field" value="<?php echo number_format(max(0, $val_final), 2, '.', ''); ?>">
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
let itemIndex        = <?php echo count($original_items); ?>;
let modificationIndex = <?php echo count($original_damaged_items); ?>;
let manualOverrideActive = false;
let savedSummaryValues = null;

function toggleOrderItems(e) {
    if (e && e.target && e.target.closest('button')) return;
    const body    = document.getElementById('order-items-body');
    const chevron = document.getElementById('order-items-chevron');
    const isHidden = body.classList.contains('hidden');
    body.classList.toggle('hidden', !isHidden);
    chevron.style.transform = isHidden ? 'rotate(180deg)' : '';
}

function updateItemsBadge() {
    const count = document.querySelectorAll('#items-container .item-row').length;
    const badge = document.getElementById('items-count-badge');
    if (badge) badge.textContent = count + ' منتج';
}

function toggleManualOverride() {
    manualOverrideActive = !manualOverrideActive;
    const btn     = document.getElementById('toggleOverrideBtn');
    const icon    = document.getElementById('overrideIcon');
    const btnText = document.getElementById('overrideBtnText');
    const flag    = document.getElementById('manual_override_flag');

    const summaryFields = [
        document.getElementById('subtotal_input'),
        document.getElementById('damaged_total_input'),
        document.getElementById('primary_discount_input'),
        document.getElementById('total_after_discount'),
        document.getElementById('final_total_display')
    ];

    if (manualOverrideActive) {
        // Save current values so we can restore them if user cancels
        savedSummaryValues = {
            subtotal:           document.getElementById('subtotal_input').value,
            damaged:            document.getElementById('damaged_total_input').value,
            primaryDiscount:    document.getElementById('primary_discount_input').value,
            totalAfterDiscount: document.getElementById('total_after_discount').value,
            finalTotal:         document.getElementById('final_total_display').value
        };
        // Sync hidden fields with current displayed values when activating manual override
        syncHiddenManualFields();
        summaryFields.forEach(f => {
            f.removeAttribute('readonly');
            f.classList.remove('bg-gray-50', 'bg-red-50', 'bg-amber-50');
            f.classList.add('bg-white', 'ring-2', 'ring-yellow-400');
        });
        btn.style.background   = '#fef3c7';
        btn.style.color        = '#92400e';
        btn.style.borderColor  = '#f59e0b';
        icon.className         = 'fas fa-lock-open';
        btnText.textContent    = 'إلغاء التعديل اليدوي';
        flag.value             = '1';
        summaryFields.forEach(f => f.addEventListener('input', syncHiddenManualFields));
        syncHiddenManualFields();
    } else {
        summaryFields.forEach(f => {
            f.setAttribute('readonly', true);
            f.classList.add('bg-gray-50');
            f.classList.remove('bg-white', 'ring-2', 'ring-yellow-400');
        });
        document.getElementById('damaged_total_input').classList.add('bg-red-50');
        document.getElementById('primary_discount_input').classList.add('bg-amber-50');
        btn.style.background   = '#f3f4f6';
        btn.style.color        = '#374151';
        btn.style.borderColor  = '#d1d5db';
        icon.className         = 'fas fa-lock';
        btnText.textContent    = 'تفعيل التعديل اليدوي';
        flag.value             = '0';
        summaryFields.forEach(f => f.removeEventListener('input', syncHiddenManualFields));
        // Restore original values instead of resetting to zero
        if (savedSummaryValues) {
            document.getElementById('subtotal_input').value             = savedSummaryValues.subtotal;
            document.getElementById('damaged_total_input').value        = savedSummaryValues.damaged;
            document.getElementById('primary_discount_input').value     = savedSummaryValues.primaryDiscount;
            document.getElementById('total_after_discount').value       = savedSummaryValues.totalAfterDiscount;
            document.getElementById('final_total_display').value        = savedSummaryValues.finalTotal;
            savedSummaryValues = null;
        } else {
            updateAllTotals();
        }
    }
}

function syncHiddenManualFields() {
    document.getElementById('manual_subtotal_hidden').value             = document.getElementById('subtotal_input').value             || '0';
    document.getElementById('manual_primary_discount_hidden').value     = document.getElementById('primary_discount_input').value     || '0';
    document.getElementById('manual_total_after_discount_hidden').value = document.getElementById('total_after_discount').value       || '0';
    document.getElementById('manual_final_total_hidden').value          = document.getElementById('final_total_display').value        || '0';
}

document.addEventListener('DOMContentLoaded', () => {
    updateItemsBadge();
    document.getElementById('items-container').addEventListener('input', (e) => { if (!manualOverrideActive && (e.target.classList.contains('item-total') || e.target.classList.contains('item-quantity'))) updateAllTotals(); });
    document.getElementById('modification-table-body').addEventListener('input', () => { if (!manualOverrideActive) updateAllTotals(); });
    document.getElementById('shipping_cost_input').addEventListener('input',     () => { if (!manualOverrideActive) updateAllTotals(); });
    document.getElementById('additional_discount_input').addEventListener('input',() => { if (!manualOverrideActive) updateAllTotals(); });

        // FIX: Sync hidden manual fields on form submit so values are never zero
        document.getElementById('editOrderForm').addEventListener('submit', function() {
            if (manualOverrideActive) {
                syncHiddenManualFields();
            }
        });
});

function updateAllTotals() {
    let subtotal = 0;
    document.querySelectorAll('#items-container .item-row').forEach(row => {
        const val = parseFloat(row.querySelector('.item-total').value);
        subtotal += isNaN(val) ? 0 : val;
    });
    document.getElementById('subtotal_input').value = subtotal.toFixed(2);

    let damagedTotal = 0;
    document.querySelectorAll('#modification-table-body .modification-row').forEach(row => {
        const val = parseFloat(row.querySelector('.damaged-price').value);
        damagedTotal += isNaN(val) ? 0 : val;
    });
    document.getElementById('damaged_total_input').value = damagedTotal.toFixed(2);

    const discountInput   = document.getElementById('primary_discount_input');
    const dataset         = discountInput.dataset;
    let netValueForDiscount = Math.max(0, subtotal - damagedTotal);
    let calculatedDiscount  = 0;

    if (dataset.isCoupon === 'true') {
        const discountValue = parseFloat(dataset.discountValue) || 0;
        if (dataset.discountType === 'percentage') {
            calculatedDiscount = netValueForDiscount * (discountValue / 100);
            const maxDiscount  = parseFloat(dataset.maxDiscount) || 0;
            if (maxDiscount > 0 && calculatedDiscount > maxDiscount) calculatedDiscount = maxDiscount;
        } else if (dataset.discountType === 'fixed') {
            calculatedDiscount = Math.min(discountValue, netValueForDiscount);
        }
    } else {
        const discountPercentage = parseFloat(dataset.discountValue) || 0;
        if (discountPercentage > 0) calculatedDiscount = netValueForDiscount * (discountPercentage / 100);
    }
    discountInput.value = calculatedDiscount.toFixed(2);

    const additionalDiscount  = parseFloat(document.getElementById('additional_discount_input').value) || 0;
    const shippingCost        = parseFloat(document.getElementById('shipping_cost_input').value)       || 0;
    const totalAfterDeductions = subtotal - calculatedDiscount - damagedTotal - additionalDiscount;
    const finalTotal           = (totalAfterDeductions > 0 ? totalAfterDeductions : 0) + shippingCost;

    document.getElementById('total_after_discount').value = (totalAfterDeductions > 0 ? totalAfterDeductions : 0).toFixed(2);
    document.getElementById('final_total_display').value  = finalTotal.toFixed(2);
}

function addItem() {
    const container = document.getElementById('items-container');
    const newRow    = document.createElement('tr');
    newRow.className = 'item-row border-b last:border-0';
    newRow.innerHTML = `
        <td class="p-2">
            <input type="hidden" name="items[${itemIndex}][id]" value="0">
            <input type="text" name="items[${itemIndex}][name]" class="form-input text-sm" placeholder="اسم المنتج" required>
        </td>
        <td class="p-2"><input type="number" name="items[${itemIndex}][quantity]" value="1" min="1" class="form-input text-center item-quantity text-sm"></td>
        <td class="p-2"><input type="number" name="items[${itemIndex}][total]" value="0.00" step="0.01" class="form-input text-center dir-ltr item-total text-sm"></td>
        <td class="p-2"><input type="text" name="items[${itemIndex}][sku]" class="form-input text-sm dir-ltr" placeholder="SKU" autocomplete="off" spellcheck="false"></td>
        <td class="p-2 text-center"><button type="button" class="btn-delete-row" onclick="removeItem(this)" title="حذف">&#10005;</button></td>
    `;
    container.appendChild(newRow);
    itemIndex++;
    updateAllTotals();
    updateItemsBadge();
}

function removeItem(button) {
    if (document.querySelectorAll('.item-row').length > 1) {
        button.closest('.item-row').remove();
        // Don't update financial totals when deleting items - preserve original values
        // updateAllTotals();
        updateItemsBadge();
    } else {
        alert('يجب أن يحتوي الطلب على منتج واحد على الأقل.');
    }
}

function addModificationRow() {
    const tbody = document.getElementById('modification-table-body');
    document.getElementById('no-damaged-row')?.remove();
    const newRow = document.createElement('tr');
    newRow.className = 'modification-row border-b last:border-0';
    newRow.innerHTML = `
        <td class="p-2">
            <input type="hidden" name="damaged_items[${modificationIndex}][id]" value="0">
            <input type="text" name="damaged_items[${modificationIndex}][name]" class="form-input text-sm" required>
        </td>
        <td class="p-2">
            <select name="damaged_items[${modificationIndex}][reason]" class="form-input text-sm" required>
                <option value="damaged">تالف</option>
                <option value="expired">منتهي</option>
                <option value="defective">مفقود</option>
            </select>
        </td>
        <td class="p-2"><input type="number" name="damaged_items[${modificationIndex}][price]" value="0.00" step="0.01" min="0" class="form-input text-center dir-ltr damaged-price text-sm" required></td>
        <td class="p-2 text-center"><button type="button" class="btn-delete-row" onclick="removeModificationRow(this)" title="حذف">&#10005;</button></td>
    `;
    tbody.appendChild(newRow);
    modificationIndex++;
    updateAllTotals();
}

function removeModificationRow(button) {
    button.closest('.modification-row').remove();
    if (document.querySelectorAll('.modification-row').length === 0) {
        document.getElementById('modification-table-body').innerHTML =
            `<tr id="no-damaged-row"><td colspan="4" class="py-8 text-center text-gray-500 text-sm">لا توجد منتجات تالفة مضافة.</td></tr>`;
    }
    updateAllTotals();
}

function previewNewImages(input) {
    const container = document.getElementById('newImagePreview');
    container.innerHTML = '';
    if (input.files && input.files.length > 0) {
        container.classList.remove('hidden');
        Array.from(input.files).forEach(file => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = e => {
                const div = document.createElement('div');
                div.className = 'relative group bg-gray-100 rounded-lg overflow-hidden h-24 border border-green-300';
                div.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                container.appendChild(div);
            };
            reader.readAsDataURL(file);
        });
    } else {
        container.classList.add('hidden');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>