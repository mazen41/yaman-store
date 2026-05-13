<?php
/**
 * Edit Customer Invoice - Page
 * صفحة تعديل فاتورة عميل
 */

// --- 1. INITIALIZATION & SECURITY ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
// Redirect to login if the user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// --- 2. DATABASE & CONFIGURATION ---
require_once '../../config/database.php';

$page_title = 'تعديل الفاتورة'; // Default title
$error_message = '';
$success_message = '';
$invoice = null;
$customers = [];

// --- 3. GET INVOICE ID & VALIDATE ---
$invoice_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if (!$invoice_id) {
    // If no valid ID is provided, redirect or show an error
    header('Location: show_invoices.php');
    exit();
}

// --- 4. HANDLE FORM SUBMISSION (POST REQUEST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and retrieve form data
    $customer_id = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $tax_amount = filter_input(INPUT_POST, 'tax_amount', FILTER_VALIDATE_FLOAT);
    $discount_amount = filter_input(INPUT_POST, 'discount_amount', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $paid_amount = filter_input(INPUT_POST, 'paid_amount', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $due_date = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);

    // Server-side validation
    $allowed_statuses = ['pending', 'paid', 'partially_paid', 'cancelled', 'overdue'];
    if (!$customer_id || !$status || !in_array($status, $allowed_statuses) || $amount === false) {
        $error_message = "الرجاء تعبئة الحقول المطلوبة بشكل صحيح.";
    } else {
        try {
            // Recalculate total amount to ensure data integrity
            $total_amount = ($amount - ($discount_amount ?? 0)) + $tax_amount;

            // Prepare the SQL UPDATE statement
            $sql = "UPDATE customer_invoices SET 
                        customer_id = :customer_id, 
                        status = :status, 
                        amount = :amount,
                        tax_amount = :tax_amount,
                        discount_amount = :discount_amount,
                        total_amount = :total_amount,
                        paid_amount = :paid_amount,
                        due_date = :due_date,
                        notes = :notes
                    WHERE id = :id";

            $stmt = $db->prepare($sql);
            
            // Bind parameters
            $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':amount', $amount);
            $stmt->bindParam(':tax_amount', $tax_amount);
            $stmt->bindParam(':discount_amount', $discount_amount);
            $stmt->bindParam(':total_amount', $total_amount);
            $stmt->bindParam(':paid_amount', $paid_amount);
            $stmt->bindParam(':due_date', empty($due_date) ? null : $due_date);
            $stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
            $stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);

            // Execute the update
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "تم تحديث الفاتورة بنجاح!";
                header('Location: show_invoices.php');
                exit();
            } else {
                $error_message = "فشل تحديث الفاتورة. الرجاء المحاولة مرة أخرى.";
            }

        } catch (Exception $e) {
            $error_message = "حدث خطأ في قاعدة البيانات: " . $e->getMessage();
        }
    }
}


// --- 5. FETCH DATA FOR THE FORM (GET REQUEST) ---
try {
    // Fetch the specific invoice details to pre-fill the form
    $stmt = $db->prepare("SELECT * FROM customer_invoices WHERE id = :id");
    $stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);
    $stmt->execute();
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no invoice is found, it's a dead end
    if (!$invoice) {
        die("الفاتورة غير موجودة.");
    }
    
    // Update page title with invoice number
    $page_title = 'تعديل الفاتورة #' . htmlspecialchars($invoice['invoice_number']);

    // Fetch all customers to populate the dropdown select menu
    $customers = $db->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_message = "لا يمكن جلب بيانات الفاتورة: " . $e->getMessage();
}

// --- 6. RENDER THE PAGE ---
include '../../includes/header.php';
?>

<!-- Custom CSS styles for the form page -->
<style>
    .form-card { background: white; border-radius: 12px; box-shadow: 0 4px 20px -5px rgba(0,0,0,0.1); padding: 30px; margin-top: 20px; }
    .form-header { margin-bottom: 25px; }
    .form-header h3 { font-size: 24px; font-weight: 700; color: #2c3e50; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; }
    .form-control, .form-select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        box-sizing: border-box; /* Important for padding */
    }
    .form-control:focus, .form-select:focus { outline: none; border-color: #4a90e2; box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1); }
    .form-footer { display: flex; justify-content: flex-end; gap: 12px; margin-top: 30px; }
    .btn { padding: 12px 25px; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; transition: all 0.2s; text-decoration: none !important; }
    .btn-primary { background-color: #4a90e2; color: white; border: none; }
    .btn-primary:hover { background-color: #357ABD; }
    .btn-secondary { background: #e5e7eb; color: #374151; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #d1d5db; }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid transparent; }
    .alert-danger { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    .alert-success { background-color: #d4edda; color: #155724; border-color: #c3e6cb;}
    .row { display: flex; flex-wrap: wrap; gap: 20px; }
    .col-md-6 { flex: 1; min-width: 300px; }
</style>

<div class="container-fluid">
    <div class="form-card">
        <div class="form-header">
            <h3><i class="fas fa-edit"></i> <?php echo $page_title; ?></h3>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($invoice): ?>
        <form method="POST" action="edit_invoice.php?id=<?php echo $invoice_id; ?>">
            <div class="row">
                <!-- Customer -->
                <div class="col-md-6 form-group">
                    <label for="customer_id">العميل</label>
                    <select id="customer_id" name="customer_id" class="form-select" required>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>" <?php echo ($customer['id'] == $invoice['customer_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Status -->
                <div class="col-md-6 form-group">
                    <label for="status">الحالة</label>
                    <select id="status" name="status" class="form-select" required>
                        <option value="pending" <?php echo ($invoice['status'] == 'pending') ? 'selected' : ''; ?>>قيد الانتظار</option>
                        <option value="paid" <?php echo ($invoice['status'] == 'paid') ? 'selected' : ''; ?>>مدفوعة</option>
                        <option value="partially_paid" <?php echo ($invoice['status'] == 'partially_paid') ? 'selected' : ''; ?>>مدفوعة جزئياً</option>
                        <option value="overdue" <?php echo ($invoice['status'] == 'overdue') ? 'selected' : ''; ?>>متأخرة</option>
                        <option value="cancelled" <?php echo ($invoice['status'] == 'cancelled') ? 'selected' : ''; ?>>ملغاة</option>
                    </select>
                </div>
            </div>
            <hr style="margin: 20px 0; border-color: #f0f0f0;">
            <div class="row">
                <!-- Amount -->
                <div class="col-md-6 form-group">
                    <label for="amount">المبلغ الأساسي</label>
                    <input type="number" id="amount" name="amount" class="form-control" step="0.01" value="<?php echo htmlspecialchars($invoice['amount']); ?>" required>
                </div>
                <!-- Tax Amount -->
                <div class="col-md-6 form-group">
                    <label for="tax_amount">مبلغ الضريبة</label>
                    <input type="number" id="tax_amount" name="tax_amount" class="form-control" step="0.01" value="<?php echo htmlspecialchars($invoice['tax_amount']); ?>">
                </div>
                <!-- Discount Amount -->
                <div class="col-md-6 form-group">
                    <label for="discount_amount">مبلغ الخصم</label>
                    <input type="number" id="discount_amount" name="discount_amount" class="form-control" step="0.001" value="<?php echo htmlspecialchars($invoice['discount_amount']); ?>">
                </div>
                <!-- Paid Amount -->
                <div class="col-md-6 form-group">
                    <label for="paid_amount">المبلغ المدفوع</label>
                    <input type="number" id="paid_amount" name="paid_amount" class="form-control" step="0.001" value="<?php echo htmlspecialchars($invoice['paid_amount']); ?>">
                </div>
                <!-- Due Date -->
                <div class="col-md-6 form-group">
                    <label for="due_date">تاريخ الاستحقاق</label>
                    <input type="date" id="due_date" name="due_date" class="form-control" value="<?php echo htmlspecialchars($invoice['due_date']); ?>">
                </div>
            </div>
            <!-- Notes -->
            <div class="form-group">
                <label for="notes">ملاحظات</label>
                <textarea id="notes" name="notes" class="form-control" rows="4"><?php echo htmlspecialchars($invoice['notes']); ?></textarea>
            </div>

            <div class="form-footer">
                <a href="show_invoices.php" class="btn btn-secondary">إلغاء</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التغييرات</button>
            </div>
        </form>
        <?php else: ?>
            <p>لم يتم العثور على الفاتورة المطلوبة.</p>
        <?php endif; ?>
    </div>
</div>

<?php
// Include the footer file
include '../../includes/footer.php'; 
?>