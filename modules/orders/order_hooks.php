<?php
/**
 * Order Hooks - Functions that run after order operations
 * This file contains functions that automatically create invoices and payments when orders are created or updated
 */

/**
 * Create an invoice for a new order
 * 
 * @param int $order_id The order ID
 * @param array $order_data The order data
 * @return int|false The invoice ID or false on failure
 */
function create_invoice_for_order($order_id, $order_data) {
    global $db;
    
    try {
        // Check if invoice already exists for this order
        $check_stmt = $db->prepare("SELECT id FROM customer_invoices WHERE order_id = ?");
        $check_stmt->execute([$order_id]);
        if ($check_stmt->fetchColumn()) {
            // Invoice already exists
            return false;
        }
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Ymd') . '-' . sprintf('%04d', $order_id);
        
        // Calculate tax (assuming 15% VAT)
        $amount = $order_data['total_amount'] ?? 0;
        $tax_amount = $amount * 0.15;
        $total_amount = $amount + $tax_amount;
        
        // Set due date (15 days from order date)
        $due_date = date('Y-m-d', strtotime($order_data['created_at'] . ' + 15 days'));
        
        // Set status based on order status
        $status = 'pending';
        if ($order_data['status'] == 'completed') {
            $status = 'paid';
        } elseif ($order_data['status'] == 'cancelled') {
            $status = 'cancelled';
        }
        
        // Insert invoice
        $stmt = $db->prepare("INSERT INTO customer_invoices 
                             (invoice_number, customer_id, order_id, amount, tax_amount, total_amount, status, due_date, created_at, updated_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        $stmt->execute([
            $invoice_number,
            $order_data['customer_id'],
            $order_id,
            $amount,
            $tax_amount,
            $total_amount,
            $status,
            $due_date
        ]);
        
        $invoice_id = $db->lastInsertId();
        
        // If order is completed, also create a payment record
        if ($order_data['status'] == 'completed') {
            create_payment_for_invoice($invoice_id, $order_data);
        }
        
        return $invoice_id;
    } catch (PDOException $e) {
        error_log('Error creating invoice for order #' . $order_id . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Create a payment for an invoice
 * 
 * @param int $invoice_id The invoice ID
 * @param array $order_data The order data
 * @return int|false The payment ID or false on failure
 */
function create_payment_for_invoice($invoice_id, $order_data) {
    global $db;
    
    try {
        // Get invoice data
        $invoice_stmt = $db->prepare("SELECT * FROM customer_invoices WHERE id = ?");
        $invoice_stmt->execute([$invoice_id]);
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            return false;
        }
        
        // Check if payment already exists for this invoice
        $check_stmt = $db->prepare("SELECT id FROM customer_payments WHERE invoice_id = ?");
        $check_stmt->execute([$invoice_id]);
        if ($check_stmt->fetchColumn()) {
            // Payment already exists
            return false;
        }
        
        // Generate payment number
        $payment_number = 'PAY-' . date('Ymd') . '-' . sprintf('%04d', $invoice_id);
        
        // Insert payment
        $stmt = $db->prepare("INSERT INTO customer_payments 
                             (payment_number, customer_id, invoice_id, amount, payment_method, payment_date, created_at, updated_at) 
                             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        
        $stmt->execute([
            $payment_number,
            $invoice['customer_id'],
            $invoice_id,
            $invoice['total_amount'],
            $order_data['payment_method'] ?? 'cash',
            date('Y-m-d', strtotime($order_data['updated_at'] ?? 'now'))
        ]);
        
        // Update invoice status to paid
        $update_stmt = $db->prepare("UPDATE customer_invoices SET status = 'paid', updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$invoice_id]);
        
        return $db->lastInsertId();
    } catch (PDOException $e) {
        error_log('Error creating payment for invoice #' . $invoice_id . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Update invoice status when order status changes
 * 
 * @param int $order_id The order ID
 * @param string $new_status The new order status
 * @return bool True on success, false on failure
 */
function update_invoice_for_order_status($order_id, $new_status) {
    global $db;
    
    try {
        // Get invoice for this order
        $invoice_stmt = $db->prepare("SELECT id, status FROM customer_invoices WHERE order_id = ?");
        $invoice_stmt->execute([$order_id]);
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            return false;
        }
        
        // Map order status to invoice status
        $invoice_status = $invoice['status'];
        
        switch ($new_status) {
            case 'completed':
                $invoice_status = 'paid';
                break;
            case 'cancelled':
                $invoice_status = 'cancelled';
                break;
            case 'processing':
                if ($invoice['status'] != 'paid') {
                    $invoice_status = 'pending';
                }
                break;
        }
        
        // Update invoice status
        $update_stmt = $db->prepare("UPDATE customer_invoices SET status = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$invoice_status, $invoice['id']]);
        
        // If status changed to completed, create payment if it doesn't exist
        if ($new_status == 'completed') {
            // Check if payment exists
            $payment_stmt = $db->prepare("SELECT id FROM customer_payments WHERE invoice_id = ?");
            $payment_stmt->execute([$invoice['id']]);
            
            if (!$payment_stmt->fetchColumn()) {
                // Get order data
                $order_stmt = $db->prepare("SELECT * FROM customer_orders WHERE id = ?");
                $order_stmt->execute([$order_id]);
                $order_data = $order_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Create payment
                if ($order_data) {
                    create_payment_for_invoice($invoice['id'], $order_data);
                }
            }
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('Error updating invoice for order #' . $order_id . ': ' . $e->getMessage());
        return false;
    }
}
?>
