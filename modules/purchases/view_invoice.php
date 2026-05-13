<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';

$invoice_id = intval($_GET['id'] ?? 0);

if (!$invoice_id) {
    header('Location: show_baskets.php');
    exit();
}

// Get invoice with related data
$stmt = $db->prepare("
    SELECT 
        pi.*,
        pb.basket_code,
        pb.basket_name,
        s.name as supplier_name,
        s.supplier_code,
        s.phone as supplier_phone,
        s.address as supplier_address,
        u.username as created_by_name
    FROM purchase_invoices pi
    LEFT JOIN purchase_baskets pb ON pi.basket_id = pb.id
    LEFT JOIN suppliers s ON pi.supplier_id = s.id
    LEFT JOIN users u ON pi.created_by = u.id
    WHERE pi.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Invoice not found");
}

$page_title = 'عرض الفاتورة';
include '../../includes/header.php';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
        
        .invoice-page {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .invoice-header {
            background: linear-gradient(135deg, #059669 0%, #C7A46D 100%);
            color: white;
            padding: 3rem 2rem;
            position: relative;
        }
        
        .invoice-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        
        .invoice-number {
            font-size: 1.25rem;
            opacity: 0.95;
        }
        
        .invoice-body {
            padding: 2rem;
        }
        
        .info-section {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .info-card {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-radius: 12px;
            padding: 1.5rem;
            border-right: 4px solid #C7A46D;
        }
        
        .info-card h3 {
            color: #059669;
            font-weight: 700;
            font-size: 1.125rem;
            margin-bottom: 1rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #6b7280;
            font-size: 0.875rem;
        }
        
        .info-value {
            color: #1f2937;
            font-weight: 600;
        }
        
        .amount-section {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 3px solid #C7A46D;
            border-radius: 16px;
            padding: 2rem;
            margin: 2rem 0;
        }
        
        .amount-title {
            color: #059669;
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .amount-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: white;
            border-radius: 8px;
        }
        
        .amount-row.total {
            background: linear-gradient(135deg, #059669 0%, #C7A46D 100%);
            color: white;
            font-size: 1.5rem;
            font-weight: 800;
            margin-top: 1rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.875rem;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.75rem 2rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 1rem;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #059669 0%, #C7A46D 100%);
            color: white;
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
            color: white;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
            color: white;
        }
        
        .btn-back {
            background: linear-gradient(135deg, #6b7280 0%, #9ca3af 100%);
            color: white;
        }
        
        .notes-section {
            background: #fffbeb;
            border: 2px solid #fbbf24;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 2rem 0;
        }
    </style>
</head>
<body>

<div class="invoice-page">
    <div class="invoice-container" id="invoice-content">
        <!-- Header -->
        <div class="invoice-header">
            <div class="invoice-title">
                <i class="fas fa-file-invoice"></i>
                فاتورة شراء
            </div>
            <div class="invoice-number">
                رقم الفاتورة: <?php echo htmlspecialchars($invoice['invoice_number']); ?>
            </div>
        </div>

        <!-- Body -->
        <div class="invoice-body">
            <!-- Status -->
            <div style="text-align: center; margin-bottom: 2rem;">
                <?php
                $statusMap = [
                    'pending' => ['معلقة', 'status-pending'],
                    'approved' => ['معتمدة', 'status-approved'],
                    'paid' => ['مدفوعة', 'status-paid'],
                    'cancelled' => ['ملغاة', 'status-cancelled']
                ];
                $status = $invoice['status'] ?? 'pending';
                [$label, $class] = $statusMap[$status] ?? ['معلقة', 'status-pending'];
                ?>
                <span class="status-badge <?php echo $class; ?>">
                    <?php echo $label; ?>
                </span>
            </div>

            <!-- Information Section -->
            <div class="info-section">
                <div class="info-card">
                    <h3><i class="fas fa-info-circle"></i> معلومات الفاتورة</h3>
                    <div class="info-row">
                        <span class="info-label">رقم السلة:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['basket_code']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تاريخ الفاتورة:</span>
                        <span class="info-value"><?php echo date('Y-m-d', strtotime($invoice['invoice_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تاريخ الإنشاء:</span>
                        <span class="info-value"><?php echo date('Y-m-d H:i', strtotime($invoice['created_at'])); ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <h3><i class="fas fa-building"></i> معلومات المورد</h3>
                    <div class="info-row">
                        <span class="info-label">اسم المورد:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['supplier_name'] ?? 'غير محدد'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">رمز المورد:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['supplier_code'] ?? '-'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">الهاتف:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['supplier_phone'] ?? '-'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Amount Section -->
            <div class="amount-section">
                <div class="amount-title">
                    <i class="fas fa-calculator"></i>
                    تفاصيل المبالغ
                </div>
                
                <div class="amount-row">
                    <span>الإجمالي الفرعي:</span>
                    <span><?php echo number_format($invoice['subtotal'], 0, '', ''); ?> ريال</span>
                </div>
                
                <div class="amount-row">
                    <span>الخصم:</span>
                    <span style="color: #ef4444;">- <?php echo number_format($invoice['discount'], 0, '', ''); ?> ريال</span>
                </div>
                
                <div class="amount-row">
                    <span>الضريبة:</span>
                    <span><?php echo number_format($invoice['tax'], 0, '', ''); ?> ريال</span>
                </div>
                
                <div class="amount-row">
                    <span>الشحن:</span>
                    <span><?php echo number_format($invoice['shipping'], 0, '', ''); ?> ريال</span>
                </div>
                
                <div class="amount-row total">
                    <span>المجموع الإجمالي:</span>
                    <span><?php echo number_format($invoice['total_amount'], 0, '', ''); ?> ريال</span>
                </div>
            </div>

            <!-- Notes -->
            <?php if (!empty($invoice['notes'])): ?>
            <div class="notes-section">
                <div style="color: #92400e; font-weight: 700; margin-bottom: 0.5rem;">
                    <i class="fas fa-sticky-note"></i> ملاحظات
                </div>
                <p style="color: #78350f; line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons no-print" style="max-width: 900px; margin: 2rem auto 0;">
        <button onclick="window.location.href='show_baskets.php'" class="btn btn-back">
            <i class="fas fa-arrow-right"></i>
            العودة إلى السلات
        </button>
        
        <button onclick="printInvoice()" class="btn btn-print">
            <i class="fas fa-print"></i>
            طباعة
        </button>
        
        <button onclick="downloadPDF()" class="btn btn-pdf">
            <i class="fas fa-download"></i>
            تحميل PDF
        </button>
        
        <button onclick="window.location.href='edit_invoice.php?id=<?php echo $invoice_id; ?>'" class="btn btn-edit">
            <i class="fas fa-edit"></i>
            تعديل
        </button>
    </div>
</div>

<script>
// Print Invoice
function printInvoice() {
    window.print();
}

// Download PDF
function downloadPDF() {
    const element = document.getElementById('invoice-content');
    const opt = {
        margin: 10,
        filename: 'invoice-<?php echo $invoice['invoice_number']; ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    
    // Show loading
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التحميل...';
    btn.disabled = true;
    
    html2pdf().set(opt).from(element).save().then(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}
</script>

</body>
</html>

<?php include '../../includes/footer.php'; ?>
