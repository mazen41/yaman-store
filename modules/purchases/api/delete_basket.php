<?php
/**
 * API Endpoint to delete a purchase basket and its related items.
 * Version 2.0:
 * - Reverses financial transactions upon deletion.
 * - If paid by a purchase card, it restores the balance and DECREASES the card's purchase_amount.
 * - If paid by a bank account, it restores the balance.
 * - Deletes related basket_items and basket_tracking records.
 * - All operations are performed within a single database transaction for safety.
 */

// --- 1. INITIALIZATION & SECURITY ---
header('Content-Type: application/json');
ini_set('display_errors', 0); // Don't display errors in JSON output for production
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك بالقيام بهذا الإجراء']);
    exit();
}

require_once '../../../config/database.php';
require_once '../../../includes/check_permissions.php';

$user_id = $_SESSION['user_id'];
if (!hasPermission($user_id, 'baskets', 'delete')) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لحذف السلات']);
    exit();
}

// --- 3. INPUT VALIDATION ---
$data = json_decode(file_get_contents('php://input'), true);
$basket_id = $data['basket_id'] ?? null;

if (empty($basket_id) || !is_numeric($basket_id)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'معرف السلة غير صالح']);
    exit();
}

// --- 4. DATABASE DELETION LOGIC ---
try {
    // Begin a transaction to ensure all operations succeed or fail together.
    $db->beginTransaction();

    // Step 1: Fetch basket details for financial reversal BEFORE deleting it.
    // Lock the row for update to prevent race conditions.
    $stmt_get_basket = $db->prepare("
        SELECT 
            final_amount, 
            final_price_override, 
            payment_source_type, 
            payment_source_id,
            basket_code,
            basket_name
        FROM purchase_baskets 
        WHERE id = :basket_id 
        FOR UPDATE
    ");
    $stmt_get_basket->bindParam(':basket_id', $basket_id, PDO::PARAM_INT);
    $stmt_get_basket->execute();
    $basket = $stmt_get_basket->fetch(PDO::FETCH_ASSOC);

    if (!$basket) {
        $db->rollBack();
        http_response_code(404); // Not Found
        echo json_encode(['success' => false, 'message' => 'السلة غير موجودة']);
        exit();
    }

    // Step 2: Reverse financial transactions if a payment source exists.
    if ($basket['payment_source_type'] && $basket['payment_source_id']) {
        // Determine the exact amount that was deducted.
        $amount_to_revert = ($basket['final_price_override'] !== null && $basket['final_price_override'] > 0)
            ? (float) $basket['final_price_override']
            : (float) $basket['final_amount'];

        if ($amount_to_revert > 0) {
            // ** LOGIC FOR PURCHASE CARD REVERSAL **
            if ($basket['payment_source_type'] === 'purchase_card') {
                $card_stmt = $db->prepare("SELECT balance, purchase_amount FROM purchase_cards WHERE id = :card_id FOR UPDATE");
                $card_stmt->bindParam(':card_id', $basket['payment_source_id'], PDO::PARAM_INT);
                $card_stmt->execute();
                $card = $card_stmt->fetch(PDO::FETCH_ASSOC);

                if ($card) {
                    // Calculate the new values: restore balance, decrease purchase amount.
                    $new_balance = $card['balance'] + $amount_to_revert;
                    $new_purchase_amount = $card['purchase_amount'] - $amount_to_revert;

                    // Update the purchase card.
                    $update_card = $db->prepare("
                        UPDATE purchase_cards 
                        SET balance = :new_balance, purchase_amount = :new_purchase_amount 
                        WHERE id = :card_id
                    ");
                    $update_card->execute([
                        ':new_balance' => $new_balance,
                        ':new_purchase_amount' => ($new_purchase_amount < 0) ? 0 : $new_purchase_amount, // Prevent negative values
                        ':card_id' => $basket['payment_source_id']
                    ]);
                }
            } 
            // ** LOGIC FOR BANK ACCOUNT REVERSAL **
            elseif ($basket['payment_source_type'] === 'bank_account') {
                $update_bank = $db->prepare("
                    UPDATE bank_accounts 
                    SET current_balance = current_balance + :amount 
                    WHERE id = :account_id
                ");
                $update_bank->execute([
                    ':amount' => $amount_to_revert,
                    ':account_id' => $basket['payment_source_id']
                ]);
            }
        }
    }

    // Step 3: Delete associated items from the 'basket_items' table.
    $stmt_items = $db->prepare("DELETE FROM basket_items WHERE basket_id = :basket_id");
    $stmt_items->bindParam(':basket_id', $basket_id, PDO::PARAM_INT);
    $stmt_items->execute();
    
    // Step 4: Delete associated tracking numbers from the 'basket_tracking' table.
    $stmt_tracking = $db->prepare("DELETE FROM basket_tracking WHERE basket_id = :basket_id");
    $stmt_tracking->bindParam(':basket_id', $basket_id, PDO::PARAM_INT);
    $stmt_tracking->execute();

    // Step 5: Finally, delete the basket itself from the 'purchase_baskets' table.
    $stmt_basket = $db->prepare("DELETE FROM purchase_baskets WHERE id = :basket_id");
    $stmt_basket->bindParam(':basket_id', $basket_id, PDO::PARAM_INT);
    $stmt_basket->execute();
    
    // Step 6: Commit the transaction.
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => 'تم حذف السلة وكل البيانات المرتبطة بها بنجاح.']);

} catch (PDOException $e) {
    // If any database error occurs, rollback all changes.
    $db->rollBack();
    http_response_code(500); // Internal Server Error
    // For production, log the detailed error instead of sending it to the client.
    error_log("Basket Deletion Failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء حذف السلة.']);
}
?>