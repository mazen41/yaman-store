<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$basket_id = intval($_GET['basket_id'] ?? 0);
$success = false;
$error = '';

if (!$basket_id) {
    header('Location: show_baskets.php');
    exit();
}

// Get basket details with items
$stmt = $db->prepare("
    SELECT pb.*, s.name as supplier_name, s.supplier_code
    FROM purchase_baskets pb
    LEFT JOIN suppliers s ON pb.supplier_id = s.id
    WHERE pb.id = ?
");
$stmt->execute([$basket_id]);
$basket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$basket) {
    die("Basket not found");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();
        
        // Generate invoice number
        $invoice_count = $db->query("SELECT COUNT(*) FROM purchase_invoices")->fetchColumn();
        $invoice_number = 'PINV-' . date('Y') . '-' . str_pad($invoice_count + 1, 6, '0', STR_PAD_LEFT);
        
        // Create invoice
        $stmt = $db->prepare("
            INSERT INTO purchase_invoices 
            (invoice_number, basket_id, supplier_id, invoice_date, total_amount, 
             subtotal, discount, tax, shipping, notes, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");
        
        $stmt->execute([
            $invoice_number,
            $basket_id,
            $basket['supplier_id'],
            $_POST['invoice_date'],
            $basket['final_amount'],
            $basket['subtotal_amount'],
            $basket['discount_amount'],
            $basket['tax_amount'],
            $basket['shipping_cost'],
            $_POST['notes'],
            $_SESSION['user_id']
        ]);
        
        $invoice_id = $db->lastInsertId();
        
        // Update basket status
        $stmt = $db->prepare("UPDATE purchase_baskets SET status = 'ordered' WHERE id = ?");
        $stmt->execute([$basket_id]);
        
        $db->commit();
        $success = true;
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

$page_title = 'إنشاء فاتورة من السلة';
include '../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

<style>
.invoice-container {
    background: #f3f4f6;
    min-height: 100vh;
    padding: 2rem;
    direction: rtl;
}

.invoice-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.07);
    overflow: hidden;
    max-width: 900px;
    margin: 0 auto;
}

.invoice-header {
    background: linear-gradient(135deg, #059669 0%, #C7A46D 100%);
    color: white;
    padding: 2rem;
}

.invoice-body {
    padding: 2rem;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.info-item {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    border-right: 4px solid #C7A46D;
}

.info-label {
    color: #6b7280;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}

.info-value {
    color: #1f2937;
    font-weight: 600;
    font-size: 1.125rem;
}

.amount-summary {
    background: #f0fdf4;
    border: 2px solid #C7A46D;
    border-radius: 12px;
    padding: 1.5rem;
    margin: 2rem 0;
}

.amount-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #d1fae5;
}

.amount-row:last-child {
    border-bottom: none;
    font-size: 1.25rem;
    font-weight: 700;
    color: #059669;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    color: #374151;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.form-input {
    width: 100%;
    padding: 0.75rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    transition: all 0.3s;
}

.form-input:focus {
    outline: none;
    border-color: #C7A46D;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
}

.btn {
    padding: 0.75rem 2rem;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: #C7A46D;
    color: white;
}

.btn-primary:hover {
    background: #059669;
    transform: translateY(-2px);
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 2px solid #C7A46D;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 2px solid #ef4444;
}
</style>

<div class="invoice-container">
    <div class="invoice-card">
        <div class="invoice-header">
            <h1 style="font-size: 1.875rem; font-weight: 700; margin-bottom: 0.5rem;">
                <i class="fas fa-file-invoice"></i>
                إنشاء فاتورة شراء
            </h1>
            <p style="opacity: 0.9;">من السلة: <?php echo htmlspecialchars($basket['basket_code']); ?></p>
        </div>

        <div class="invoice-body">
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <strong>تم بنجاح!</strong> تم إنشاء الفاتورة رقم: <strong><?php echo $invoice_number; ?></strong>
                <div style="margin-top: 1rem;">
                    <a href="show_baskets.php" class="btn btn-primary" style="display: inline-block; text-decoration: none;">
                        <i class="fas fa-arrow-right"></i> العودة إلى السلات
                    </a>
                    <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary" style="display: inline-block; text-decoration: none; margin-right: 1rem;">
                        <i class="fas fa-eye"></i> عرض الفاتورة
                    </a>
                </div>
            </div>
            <?php else: ?>

            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <strong>خطأ:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Basket Information -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">رقم السلة</div>
                    <div class="info-value"><?php echo htmlspecialchars($basket['basket_code']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">اسم المورد</div>
                    <div class="info-value"><?php echo htmlspecialchars($basket['supplier_name'] ?? 'غير محدد'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">رمز المورد</div>
                    <div class="info-value"><?php echo htmlspecialchars($basket['supplier_code'] ?? '-'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">تاريخ السلة</div>
                    <div class="info-value"><?php echo date('Y-m-d', strtotime($basket['created_at'])); ?></div>
                </div>
            </div>

            <!-- Amount Summary -->
            <div class="amount-summary">
                <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem; color: #059669;">
                    <i class="fas fa-calculator"></i>
                    ملخص المبالغ
                </h3>
                <div class="amount-row">
                    <span>الإجمالي الفرعي:</span>
                    <span><?php echo number_format($basket['subtotal_amount'], 0, '', ''); ?> ريال</span>
                </div>
                <div class="amount-row">
                    <span>الخصم:</span>
                    <span style="color: #ef4444;">- <?php echo number_format($basket['discount_amount'], 0, '', ''); ?> ريال</span>
                </div>
                <div class="amount-row">
                    <span>الضريبة:</span>
                    <span><?php echo number_format($basket['tax_amount'], 0, '', ''); ?> ريال</span>
                </div>
                <div class="amount-row">
                    <span>الشحن:</span>
                    <span><?php echo number_format($basket['shipping_cost'], 0, '', ''); ?> ريال</span>
                </div>
                <div class="amount-row">
                    <span>المجموع الإجمالي:</span>
                    <span><?php echo number_format($basket['final_amount'], 0, '', ''); ?> ريال</span>
                </div>
            </div>

            <!-- Invoice Form -->
            <form method="POST" style="margin-top: 2rem;">
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-calendar"></i>
                        تاريخ الفاتورة *
                    </label>
                    <input type="date" name="invoice_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-sticky-note"></i>
                        ملاحظات
                    </label>
                    <textarea name="notes" class="form-input" rows="4" placeholder="أضف أي ملاحظات إضافية..."></textarea>
                </div>

                <div style="display: flex; justify-content: space-between; margin-top: 2rem;">
                    <a href="show_baskets.php" class="btn btn-secondary" style="text-decoration: none;">
                        <i class="fas fa-arrow-right"></i> إلغاء
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> إنشاء الفاتورة
                    </button>
                </div>
            </form>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
