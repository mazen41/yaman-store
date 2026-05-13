<?php
/**
 * View Customer Invoice - Page
 * صفحة عرض تفاصيل فاتورة عميل
 */

// --- 1. INITIALIZATION & SECURITY ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
// Redirect to login if the user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// --- 2. DATABASE & CONFIGURATION ---
require_once '../../config/database.php';

$page_title = 'عرض الفاتورة'; // Default title
$error_message = '';
$invoice = null;

// --- 3. GET INVOICE ID & VALIDATE ---
$invoice_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if (!$invoice_id) {
    // If no valid ID is provided, redirect back to the list
    header('Location: show_invoices.php');
    exit();
}

// --- 4. FETCH INVOICE DATA FROM DATABASE ---
try {
    // Comprehensive query to get all invoice details and related names
    $sql = "
        SELECT 
            ci.*,
            c.name AS customer_name,
            c.email AS customer_email,
            c.phone AS customer_phone,
            c.address AS customer_address,
            u.username AS created_by_user
        FROM 
            customer_invoices ci
        LEFT JOIN 
            customers c ON ci.customer_id = c.id
        LEFT JOIN 
            users u ON ci.created_by = u.id
        WHERE 
            ci.id = :id
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);
    $stmt->execute();
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no invoice is found with that ID, set an error
    if (!$invoice) {
        $error_message = "الفاتورة المطلوبة غير موجودة.";
    } else {
        // Update the page title dynamically
        $page_title = 'فاتورة #' . htmlspecialchars($invoice['invoice_number']);
    }

} catch (Exception $e) {
    $error_message = "حدث خطأ أثناء جلب بيانات الفاتورة: " . $e->getMessage();
}

// Helper function to map status to CSS class
function getStatusClass($status) {
    switch (strtolower($status)) {
        case 'paid': return 'status-paid';
        case 'partially_paid': return 'status-partial';
        case 'overdue': return 'status-overdue';
        case 'cancelled': return 'status-cancelled';
        case 'pending': default: return 'status-pending';
    }
}

// --- 5. RENDER THE PAGE ---
include '../../includes/header.php';
?>

<!-- Custom CSS for the invoice view page -->
<style>
    .invoice-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 20px -5px rgba(0,0,0,0.1); margin-top: 20px; }
    .invoice-header { padding: 30px; display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #e9ecef; flex-wrap: wrap; gap: 20px; }
    .invoice-title h2 { margin: 0; font-size: 28px; font-weight: 700; color: #2c3e50; }
    .invoice-title p { margin: 5px 0 0; color: #6c757d; }
    .status-badge { display: inline-block; padding: 6px 15px; border-radius: 20px; font-size: 14px; font-weight: 600; text-transform: capitalize; }
    .status-paid { background-color: #d4edda; color: #155724; }
    .status-pending { background-color: #fff3cd; color: #856404; }
    .status-partial { background-color: #d1ecf1; color: #0c5460; }
    .status-overdue { background-color: #f8d7da; color: #721c24; }
    .status-cancelled { background-color: #e2e3e5; color: #383d41; }
    .invoice-details { padding: 30px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 30px; }
    .invoice-details .company-details, .invoice-details .customer-details { flex: 1; min-width: 280px; }
    .invoice-details h5 { font-weight: 700; color: #343a40; margin-bottom: 10px; border-bottom: 1px solid #e9ecef; padding-bottom: 5px; }
    .invoice-details p { margin: 0 0 5px; color: #495057; line-height: 1.6; }
    .invoice-body { padding: 0 30px; }
    .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .invoice-table th, .invoice-table td { padding: 12px 15px; text-align: right; border-bottom: 1px solid #e9ecef; }
    .invoice-table th { background-color: #f8f9fa; font-weight: 600; }
    .invoice-totals { padding: 30px; display: flex; justify-content: flex-end; }
    .totals-table { width: 100%; max-width: 350px; }
    .totals-table td { padding: 10px; }
    .totals-table tr.grand-total td { font-size: 1.2em; font-weight: 700; color: #000; border-top: 2px solid #333; }
    .invoice-notes { padding: 0 30px 30px; }
    .invoice-actions { padding: 20px; background-color: #f8f9fa; text-align: center; border-radius: 0 0 12px 12px; }
    .btn { padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; }
    .btn-primary { background-color: #4a90e2; color: white; }
    .btn-secondary { background-color: #6c757d; color: white; }
    .btn-success { background-color: #28a745; color: white; }
    .btn-danger { background-color: #dc3545; color: white; }
    .alert-danger { padding: 15px; border-radius: 8px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

    /* Print Styles */
    @media print {
        body * { visibility: hidden; }
        .invoice-card, .invoice-card * { visibility: visible; }
        .invoice-card { position: absolute; left: 0; top: 0; width: 100%; margin: 0; box-shadow: none; border-radius: 0; }
        .invoice-actions { display: none; }
        /* Hide elements from the main template */
        .page-header, .sidebar, .main-header, footer { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; }
    }
</style>

<div class="container-fluid">

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php else: ?>
        <div id="invoice-to-print">
            <div class="invoice-card">
                <div class="invoice-header">
                    <div class="invoice-title">
                        <h2>فاتورة #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
                        <p>تاريخ الإنشاء: <?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?></p>
                    </div>
                    <div class="invoice-status">
                        <span class="status-badge <?php echo getStatusClass($invoice['status']); ?>">
                            <?php echo htmlspecialchars(str_replace('_', ' ', $invoice['status'])); ?>
                        </span>
                    </div>
                </div>

                <div class="invoice-details">
                    <div class="company-details">
                        <h5>من:</h5>
                        <p>
                            <strong>شركتك</strong><br>
                            123 الشارع الرئيسي، المدينة<br>
                            البريد الإلكتروني: contact@yourcompany.com<br>
                            الهاتف: +123 456 7890
                        </p>
                    </div>
                    <div class="customer-details">
                        <h5>إلى:</h5>
                        <p>
                            <strong><?php echo htmlspecialchars($invoice['customer_name'] ?? 'N/A'); ?></strong><br>
                            <?php echo nl2br(htmlspecialchars($invoice['customer_address'] ?? '')); ?><br>
                            <?php echo htmlspecialchars($invoice['customer_email'] ?? ''); ?><br>
                            <?php echo htmlspecialchars($invoice['customer_phone'] ?? ''); ?>
                        </p>
                    </div>
                </div>

                <div class="invoice-body">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>الوصف</th>
                                <th>المبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>المبلغ الأساسي</td>
                                <td><?php echo number_format($invoice['amount'], 0, '', ''); ?> ر.س</td>
                            </tr>
                            <?php if ($invoice['tax_amount'] > 0): ?>
                            <tr>
                                <td>الضريبة المضافة</td>
                                <td><?php echo number_format($invoice['tax_amount'], 0, '', ''); ?> ر.س</td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($invoice['discount_amount'] > 0): ?>
                            <tr>
                                <td>الخصم</td>
                                <td>-<?php echo number_format($invoice['discount_amount'], 0, '', ''); ?> ر.س</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="invoice-totals">
                    <table class="totals-table">
                        <tr>
                            <td>الإجمالي الفرعي:</td>
                            <td><?php echo number_format($invoice['amount'] - $invoice['discount_amount'], 0, '', ''); ?> ر.س</td>
                        </tr>
                        <tr>
                            <td>الضريبة:</td>
                            <td><?php echo number_format($invoice['tax_amount'], 0, '', ''); ?> ر.س</td>
                        </tr>
                        <tr class="grand-total">
                            <td>المبلغ الإجمالي:</td>
                            <td><?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ر.س</td>
                        </tr>
                        <tr>
                            <td>المبلغ المدفوع:</td>
                            <td><?php echo number_format($invoice['paid_amount'], 0, '', ''); ?> ر.س</td>
                        </tr>
                        <tr>
                            <td><strong>المبلغ المستحق:</strong></td>
                            <td><strong><?php echo number_format($invoice['total_amount'] - $invoice['paid_amount'], 0, '', ''); ?> ر.س</strong></td>
                        </tr>
                    </table>
                </div>
                
                <?php if (!empty($invoice['notes'])): ?>
                <div class="invoice-notes">
                    <h5>ملاحظات:</h5>
                    <p><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
                </div>
                <?php endif; ?>

                <div class="invoice-actions">
                    <a href="show_invoices.php" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> العودة للقائمة</a>
                    <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> طباعة</button>
                    <a href="edit_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-success"><i class="fas fa-edit"></i> تعديل</a>
                    <a href="delete_invoice.php?id=<?php echo $invoice_id; ?>" onclick="return confirm('هل أنت متأكد من رغبتك في حذف هذه الفاتورة؟');" class="btn btn-danger"><i class="fas fa-trash"></i> حذف</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
// Include the footer file
include '../../includes/footer.php';
?>