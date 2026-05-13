<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$invoice_id = intval($_GET['id'] ?? 0);
$success = false;
$error = '';

if (!$invoice_id) {
    header('Location: show_baskets.php');
    exit();
}

// Get invoice
$stmt = $db->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found");
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $stmt = $db->prepare("
            UPDATE purchase_invoices 
            SET invoice_date = ?, notes = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['invoice_date'],
            $_POST['notes'],
            $_POST['status'],
            $invoice_id
        ]);
        
        $success = true;
        header('Location: view_invoice.php?id=' . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'تعديل الفاتورة';
include '../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<div style="background: #f3f4f6; min-height: 100vh; padding: 2rem; direction: rtl;">
    <div style="max-width: 800px; margin: 0 auto; background: white; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h1 style="font-size: 2rem; font-weight: 700; color: #059669; margin-bottom: 2rem;">
            <i class="fas fa-edit"></i>
            تعديل الفاتورة: <?php echo htmlspecialchars($invoice['invoice_number']); ?>
        </h1>

        <?php if ($error): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">تاريخ الفاتورة</label>
                <input type="date" name="invoice_date" value="<?php echo $invoice['invoice_date']; ?>" 
                       style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;" required>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">الحالة</label>
                <select name="status" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;">
                    <option value="pending" <?php echo $invoice['status'] == 'pending' ? 'selected' : ''; ?>>معلقة</option>
                    <option value="approved" <?php echo $invoice['status'] == 'approved' ? 'selected' : ''; ?>>معتمدة</option>
                    <option value="paid" <?php echo $invoice['status'] == 'paid' ? 'selected' : ''; ?>>مدفوعة</option>
                    <option value="cancelled" <?php echo $invoice['status'] == 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                </select>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">ملاحظات</label>
                <textarea name="notes" rows="4" style="width: 100%; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px;"><?php echo htmlspecialchars($invoice['notes']); ?></textarea>
            </div>

            <div style="display: flex; gap: 1rem;">
                <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" 
                   style="padding: 0.75rem 2rem; background: #6b7280; color: white; border-radius: 8px; text-decoration: none;">
                    إلغاء
                </a>
                <button type="submit" 
                        style="padding: 0.75rem 2rem; background: #C7A46D; color: white; border-radius: 8px; border: none; cursor: pointer;">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
