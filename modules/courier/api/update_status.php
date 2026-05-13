<?php
// ULTRA SIMPLE - NO COMPLEXITY
error_reporting(E_ALL);
ini_set('display_errors', 0);

function log_it($msg) {
    $log = date('H:i:s') . " - $msg\n";
    file_put_contents('/tmp/courier.log', $log, FILE_APPEND);
    error_log($log);
}

log_it("========== API START ==========");

try {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!isset($_SESSION['user_id'])) {
        log_it("ERROR: No session");
        die(json_encode(['success' => false, 'error' => 'No session']));
    }
    
    log_it("User ID: " . $_SESSION['user_id']);
    
    require_once '../../../config/database.php';
    
    // Get input
    $raw = file_get_contents('php://input');
    log_it("Raw input: $raw");
    
    $input = json_decode($raw, true);
    log_it("Decoded: " . json_encode($input));
    
    $order_id = intval($input['order_id'] ?? 0);
    $action = trim($input['action'] ?? '');
    
    log_it("Order: $order_id, Action: $action");
    
    if (!$order_id || !$action) {
        log_it("ERROR: Invalid input");
        die(json_encode(['success' => false, 'error' => 'Invalid input']));
    }
    
    // Get order
    $stmt = $db->prepare("SELECT id, order_number, status FROM customer_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        log_it("ERROR: Order not found");
        die(json_encode(['success' => false, 'error' => 'Order not found']));
    }
    
    log_it("Current status: " . $order['status']);
    
    // SIMPLE mapping
    $new_status = ($action === 'deliver') ? 'completed' : 'معلق';
    
    log_it("New status will be: $new_status (length: " . strlen($new_status) . ")");
    
    // Update - SIMPLE
    $db->beginTransaction();
    
    $sql = "UPDATE customer_orders SET status = ? WHERE id = ?";
    log_it("SQL: $sql");
    log_it("Params: [$new_status, $order_id]");
    
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([$new_status, $order_id]);
    
    log_it("Execute result: " . ($result ? 'true' : 'false'));
    log_it("Rows affected: " . $stmt->rowCount());
    
    if ($stmt->errorInfo()[0] !== '00000') {
        log_it("SQL ERROR: " . json_encode($stmt->errorInfo()));
        throw new Exception("SQL Error: " . $stmt->errorInfo()[2]);
    }
    
    // Save additional data
    if ($action === 'deliver') {
        if (isset($input['cod_collected'])) {
            $stmt = $db->prepare("UPDATE customer_orders SET cod_collected = ?, receiver_name = ? WHERE id = ?");
            $stmt->execute([
                floatval($input['cod_collected']),
                $input['receiver_name'] ?? '',
                $order_id
            ]);
            log_it("COD saved");
        }
    } else {
        if (isset($input['fail_reason'])) {
            $stmt = $db->prepare("UPDATE customer_orders SET delivery_failed_reason = ? WHERE id = ?");
            $stmt->execute([$input['fail_reason'], $order_id]);
            log_it("Fail reason saved");
        }
    }
    
    $db->commit();
    log_it("COMMIT successful");
    
    // Verify update
    $stmt = $db->prepare("SELECT status FROM customer_orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $verify = $stmt->fetch(PDO::FETCH_ASSOC);
    log_it("Verified status: " . $verify['status']);
    
    $response = [
        'success' => true,
        'message' => 'تم التحديث بنجاح',
        'order_number' => $order['order_number'],
        'old_status' => $order['status'],
        'new_status' => $new_status,
        'verified_status' => $verify['status']
    ];
    
    log_it("SUCCESS: " . json_encode($response));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    $error = [
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'sqlstate' => $e->errorInfo[0] ?? 'unknown'
    ];
    
    log_it("PDO ERROR: " . json_encode($error));
    http_response_code(500);
    echo json_encode($error, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    $error = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    
    log_it("ERROR: " . json_encode($error));
    http_response_code(500);
    echo json_encode($error, JSON_UNESCAPED_UNICODE);
}

log_it("========== API END ==========\n");
