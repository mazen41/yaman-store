<?php
/**
 * Auto-create invoice for order
 * This script automatically generates an invoice when an order is created or updated
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/auto_generate_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = $_POST['order_id'] ?? null;
    
    if (!$orderId) {
        echo json_encode(['success' => false, 'message' => 'رقم الطلب مطلوب']);
        exit();
    }
    
    try {
        $invoiceNumber = getOrCreateInvoiceForOrder($db, $orderId, $_SESSION['user_id']);
        
        if ($invoiceNumber) {
            echo json_encode([
                'success' => true,
                'message' => 'تم إنشاء الفاتورة بنجاح',
                'invoice_number' => $invoiceNumber
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'فشل إنشاء الفاتورة'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة غير صحيحة']);
}
?>
