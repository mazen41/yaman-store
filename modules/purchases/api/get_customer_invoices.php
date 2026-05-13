<?php
/**
 * API endpoint to fetch invoices for a specific customer.
 */

header('Content-Type: application/json');
require_once '../../../config/database.php';

if (!isset($_GET['customer_id'])) {
    echo json_encode(['error' => 'Customer ID is required']);
    http_response_code(400);
    exit();
}

$customer_id = intval($_GET['customer_id']);

try {
    $stmt = $db->prepare("
        SELECT 
            id, 
            invoice_number, 
            total_amount, 
            paid_amount,
            status, 
            due_date, 
            created_at
        FROM 
            customer_invoices
        WHERE 
            customer_id = ?
        ORDER BY 
            created_at DESC
    ");
    $stmt->execute([$customer_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate remaining amount for each invoice
    foreach ($invoices as &$invoice) {
        $invoice['remaining_amount'] = max(0, floatval($invoice['total_amount']) - floatval($invoice['paid_amount']));
        // Format dates for display
        $invoice['due_date_formatted'] = $invoice['due_date'] ? date("Y-m-d", strtotime($invoice['due_date'])) : 'N/A';
        $invoice['created_at_formatted'] = date("Y-m-d H:i", strtotime($invoice['created_at']));
    }

    echo json_encode($invoices);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
    http_response_code(500);
}
?>