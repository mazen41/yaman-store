<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}
require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
// ADD THIS LINE
require_once '../../includes/accounting_functions.php';

// Permission guard
if (!hasPermission($_SESSION['user_id'], 'shipping', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لتعديل الشحنات';
    header('Location: index.php');
    exit();
}

$page_title = 'تعديل الشحنة';
$shipment_id = intval($_GET['id'] ?? 0);
$error_message = '';
$success_message = '';

// Fetch shipment
$stmt = $db->prepare("SELECT * FROM shipments WHERE id = ?");
$stmt->execute([$shipment_id]);
$shipment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shipment) {
    die('الشحنة غير موجودة');
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // WRAP YOUR LOGIC IN A TRANSACTION
    $db->beginTransaction();
    try {
        $tracking_number = trim($_POST['tracking_number'] ?? '');
        $shipping_cost = floatval($_POST['shipping_cost']); // This is the new cost
        $delivery_address = trim($_POST['delivery_address']);
        $recipient_name = trim($_POST['recipient_name']);
        $recipient_phone = trim($_POST['recipient_phone']);
        $estimated_delivery = $_POST['estimated_delivery'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'];

        $db->prepare("
            UPDATE shipments SET 
                tracking_number = ?, shipping_cost = ?, delivery_address = ?, 
                recipient_name = ?, recipient_phone = ?, estimated_delivery = ?, 
                notes = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $tracking_number, $shipping_cost, $delivery_address, 
            $recipient_name, $recipient_phone, $estimated_delivery ?: null, 
            $notes, $status, $shipment_id
        ]);
        
        // ===================================================================
        // START: NEW ACCOUNTING LOGIC (DELETE AND RECREATE)
        // ===================================================================
        try {
            // 1. Delete the old journal entry associated with this shipment.
            delete_journal_entry_by_source($db, 'shipping', $shipment_id);

            // 2. Create a new journal entry ONLY if the new shipping cost is greater than zero.
            if ($shipping_cost > 0) {
                $shipping_expense_account_id = get_accounting_setting($db, 'default_shipping_expense_account_id');
                $shipping_payment_account_id = get_accounting_setting($db, 'default_shipping_payment_account_id');

                $description = "تعديل مصروف شحن للشحنة رقم " . $shipment['shipment_number'];
                
                $entry_items = [
                    ['account_id' => $shipping_expense_account_id, 'type' => 'debit', 'amount' => $shipping_cost],
                    ['account_id' => $shipping_payment_account_id, 'type' => 'credit', 'amount' => $shipping_cost],
                ];

                create_journal_entry(
                    $db,
                    date('Y-m-d'),      // Use today's date for the update
                    $description,
                    $entry_items,
                    'shipping',         // Source Module
                    $shipment_id,       // Source ID
                    $_SESSION['user_id']
                );
            }
        } catch (Exception $acc_e) {
            // Log the accounting error but do not stop the shipment update.
            error_log("Accounting update failed for Shipment ID $shipment_id: " . $acc_e->getMessage());
        }
        // ===================================================================
        // END: NEW ACCOUNTING LOGIC
        // ===================================================================

        // Update tracking history if status changed
        if ($status !== $shipment['status']) {
            $db->prepare("INSERT INTO shipment_tracking (shipment_id, status, description, occurred_at) VALUES (?, ?, 'تم تحديث الحالة يدوياً', NOW())")
               ->execute([$shipment_id, $status]);
               
            // Update order status too (assuming shipment_orders link table)
            $order_ids_stmt = $db->prepare("SELECT order_id FROM shipment_orders WHERE shipment_id = ?");
            $order_ids_stmt->execute([$shipment_id]);
            $linked_order_ids = $order_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($linked_order_ids)) {
                $update_order_status_stmt = $db->prepare("UPDATE customer_orders SET shipping_status = ? WHERE id = ?");
                foreach($linked_order_ids as $order_id) {
                    $update_order_status_stmt->execute([$status, $order_id]);
                }
            }
        }

        // COMMIT THE TRANSACTION
        $db->commit();

        $success_message = 'تم تحديث الشحنة بنجاح';
        // Refresh data to show the new values on the page
        $stmt->execute([$shipment_id]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        // If anything goes wrong, roll back the transaction
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Fetch companies and senders if needed
include '../../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-6" dir="rtl">
    <div class="max-w-4xl mx-auto px-4">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <h1 class="text-2xl font-bold mb-6">تعديل الشحنة #<?php echo htmlspecialchars($shipment['shipment_number']); ?></h1>
            
            <?php if ($success_message): ?><div class="bg-green-100 text-green-700 p-4 rounded mb-4"><?php echo $success_message; ?></div><?php endif; ?>
            <?php if ($error_message): ?><div class="bg-red-100 text-red-700 p-4 rounded mb-4"><?php echo $error_message; ?></div><?php endif; ?>

            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold mb-1">رقم التتبع</label>
                        <input type="text" name="tracking_number" value="<?php echo htmlspecialchars($shipment['tracking_number']); ?>" class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">الحالة</label>
                        <select name="status" class="w-full border rounded p-2">
                            <option value="preparing" <?php echo $shipment['status'] == 'preparing' ? 'selected' : ''; ?>>قيد التجهيز</option>
                            <option value="shipped" <?php echo $shipment['status'] == 'shipped' ? 'selected' : ''; ?>>تم الشحن</option>
                            <option value="in_transit" <?php echo $shipment['status'] == 'in_transit' ? 'selected' : ''; ?>>في الطريق</option>
                            <option value="delivered" <?php echo $shipment['status'] == 'delivered' ? 'selected' : ''; ?>>تم التوصيل</option>
                            <option value="cancelled" <?php echo $shipment['status'] == 'cancelled' ? 'selected' : ''; ?>>ملغي</option>
                            <option value="returned" <?php echo $shipment['status'] == 'returned' ? 'selected' : ''; ?>>مرتجع</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">تكلفة الشحن</label>
                        <input type="number" name="shipping_cost" value="<?php echo $shipment['shipping_cost']; ?>" step="0.01" class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">تاريخ التوصيل المتوقع</label>
                        <input type="date" name="estimated_delivery" value="<?php echo $shipment['estimated_delivery']; ?>" class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">اسم المستلم</label>
                        <input type="text" name="recipient_name" value="<?php echo htmlspecialchars($shipment['recipient_name']); ?>" class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">هاتف المستلم</label>
                        <input type="text" name="recipient_phone" value="<?php echo htmlspecialchars($shipment['recipient_phone']); ?>" class="w-full border rounded p-2">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-bold mb-1">العنوان</label>
                        <textarea name="delivery_address" rows="2" class="w-full border rounded p-2"><?php echo htmlspecialchars($shipment['delivery_address']); ?></textarea>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-bold mb-1">ملاحظات</label>
                        <textarea name="notes" rows="2" class="w-full border rounded p-2"><?php echo htmlspecialchars($shipment['notes']); ?></textarea>
                    </div>
                </div>
                
                <div class="flex gap-4 mt-6">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">حفظ التعديلات</button>
                    <a href="view.php?id=<?php echo $shipment_id; ?>" class="bg-gray-300 text-gray-800 px-6 py-2 rounded hover:bg-gray-400">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>