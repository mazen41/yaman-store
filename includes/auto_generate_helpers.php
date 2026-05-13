<?php
/**
 * Auto-Generate Helper Functions
 */

/**
 * Generate unique customer code.
 * (This function is fine, no changes needed)
 */
function generateCustomerCode($db, $customerName = '') {
    try {
        $prefix = 'CUST';
        if (!empty($customerName)) {
            $nameParts = preg_split('/\s+/', trim($customerName));
            $prefix = 'CUST';
        }
        $stmt = $db->prepare("SELECT customer_code FROM customers WHERE customer_code LIKE ? ORDER BY customer_code DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $lastNumber = intval(preg_replace('/[^0-9]/', '', substr($result['customer_code'], strlen($prefix))));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        $customerCode = $prefix . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
        $checkStmt->execute([$customerCode]);
        if ($checkStmt->fetchColumn() > 0) {
            $customerCode = $prefix . date('ymd') . rand(100, 999);
        }
        return $customerCode;
    } catch (PDOException $e) {
        error_log("Error generating customer code: " . $e->getMessage());
        return 'CUST' . date('ymd') . rand(100, 999);
    }
}

/**
 * **FIXED: Generate a final, sequential order number.**
 * This is now the primary function for getting the next order number.
 */
function generateOrderNumber($db) {
    try {
        // Find the highest NUMERIC order_number to avoid issues with old temporary text-based numbers.
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(CAST(order_number AS UNSIGNED)), 0) AS max_order_number
            FROM customer_orders
            WHERE order_number REGEXP '^[0-9]+$' -- Only consider pure numbers
        ");
        $stmt->execute();
        $lastNumber = (int)$stmt->fetchColumn();
        return $lastNumber + 1; // Return the next number in the sequence
    } catch (PDOException $e) {
        error_log("FATAL: Could not generate a new order number: " . $e->getMessage());
        // Fallback to a timestamp to ensure uniqueness if the query fails
        return time();
    }
}

/**
 * **REMOVED: `generateInvoiceNumber` function.**
 * We no longer need this because the invoice number is now directly created
 * from the order number in the main script. This simplifies the logic.
 */

/**
 * **FIXED & SIMPLIFIED: Create invoice for an order automatically.**
 * This function now assumes the order already has its final invoice number.
 * It just reads the order data and creates the corresponding invoice record.
 */
/**
 * **FIXED & SIMPLIFIED: Create invoice for an order automatically.**
 * This function now assumes the order already has its final invoice number.
 * It just reads the order data and creates the corresponding invoice record.
 */
function createInvoiceForOrder($db, $orderId, $userId = null) {
    try {
        // Get order details, including the invoice number we already created and saved
        $orderStmt = $db->prepare("
            SELECT o.*, c.name as customer_name, c.currency
            FROM customer_orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            WHERE o.id = ?
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order not found when trying to create invoice.");
        }

        $invoiceNumber = $order['invoice_number'];
        if (empty($invoiceNumber)) {
            throw new Exception("Invoice number was not found on the order record.");
        }

        $checkStmt = $db->prepare("SELECT id FROM customer_invoices WHERE order_id = ? OR invoice_number = ?");
        $checkStmt->execute([$orderId, $invoiceNumber]);
        if ($checkStmt->fetch()) {
            return $invoiceNumber; // Invoice already exists, do nothing.
        }

        // Prepare amounts for the invoice table
        $subtotal = $order['subtotal_amount'] ?? 0;
        $discountAmount = $order['discount_amount'] ?? 0;
        $finalAmount = $order['final_amount'] ?? 0;
        $paidAmount = $order['paid_amount'] ?? 0;
        $remainingAmount = $finalAmount - $paidAmount;

        // Determine invoice status
        $status = 'pending';
        if ($finalAmount > 0 && $paidAmount >= $finalAmount) {
            $status = 'paid';
        } elseif ($paidAmount > 0) {
            $status = 'partially_paid';
        }

        // ===================================================================
        // THIS IS THE CORRECTED PART
        // The column list now matches your database table exactly.
        // We added `tax_amount` and removed `currency`.
        // ===================================================================
        $invoiceStmt = $db->prepare("
            INSERT INTO customer_invoices
            (invoice_number, customer_id, order_id, amount, discount_amount, tax_amount, total_amount, paid_amount, remaining_amount, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $invoiceStmt->execute([
            $invoiceNumber,
            $order['customer_id'],
            $orderId,
            $subtotal,
            $discountAmount,
            0, // Value for tax_amount (we assume 0 for now)
            $finalAmount,
            $paidAmount,
            $remainingAmount,
            $status,
            $userId
            // The value for 'currency' has been removed
        ]);

        return $invoiceNumber;

    } catch (PDOException $e) {
        error_log("DATABASE ERROR creating invoice for order ID $orderId: " . $e->getMessage());
        throw $e;
    } catch (Exception $e) {
        error_log("LOGIC ERROR creating invoice for order ID $orderId: " . $e->getMessage());
        throw $e;
    }
}
/**
 * **SIMPLIFIED: getOrCreateInvoiceForOrder function.**
 * It's much simpler now.
 */
function getOrCreateInvoiceForOrder($db, $orderId, $userId = null) {
    try {
        $stmt = $db->prepare("SELECT invoice_number FROM customer_invoices WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return $result['invoice_number']; // It exists, just return it.
        }

        // If not found, create it.
        return createInvoiceForOrder($db, $orderId, $userId);

    } catch (Exception $e) {
        error_log("Error in getOrCreateInvoiceForOrder: " . $e->getMessage());
        return false;
    }
}

// (Validation functions below are fine, no changes needed)
function validateCustomerCode($code) {
    $code = strtoupper(trim($code));
    if (preg_match('/^CUST\d{3,}$/', $code)) { return $code; }
    return false;
}

function validateOrderNumber($number) {
    $number = trim($number);
    if (ctype_digit($number) && (int)$number > 0) { return (int)$number; }
    return false;
}

function validateInvoiceNumber($number) {
    $number = strtoupper(trim($number));
    if (preg_match('/^INV-\d{4}-\d{4}$/', $number) || preg_match('/^INV-\d{4}-\d+$/', strtolower($number))) { return $number; }
    return false;
}