<?php
session_start();

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'غير مصرح بالوصول'
    ]);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'طريقة طلب غير صالحة'
    ]);
    exit();
}

// Check if action is test_connection
if (!isset($_POST['action']) || $_POST['action'] !== 'test_connection') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'إجراء غير صالح'
    ]);
    exit();
}

// Get SMTP settings from POST data
$smtp_host = $_POST['smtp_host'] ?? '';
$smtp_port = intval($_POST['smtp_port'] ?? 0);
$smtp_username = $_POST['smtp_username'] ?? '';
$smtp_password = $_POST['smtp_password'] ?? '';
$smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';

// Validate required fields
if (empty($smtp_host) || empty($smtp_port) || empty($smtp_username)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'يرجى ملء جميع الحقول المطلوبة'
    ]);
    exit();
}

// Try to connect to SMTP server
try {
    // Start output buffering to capture debug info
    ob_start();
    
    // Create socket connection
    $errno = 0;
    $errstr = '';
    $timeout = 10;
    
    echo "Attempting connection to $smtp_host:$smtp_port...\n";
    
    // Try to connect to the SMTP server
    $socket = fsockopen(($smtp_encryption == 'ssl' ? 'ssl://' : '') . $smtp_host, $smtp_port, $errno, $errstr, $timeout);
    
    if (!$socket) {
        throw new Exception("Connection failed: $errstr ($errno)");
    }
    
    echo "Connected successfully to $smtp_host:$smtp_port\n";
    
    // Read greeting
    $response = fgets($socket, 515);
    echo "Server greeting: $response";
    
    if (substr($response, 0, 3) !== '220') {
        throw new Exception("SMTP server did not respond with a proper greeting: $response");
    }
    
    // Send EHLO command
    echo "Sending EHLO command...\n";
    fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
    
    // Read response
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }
    echo "EHLO response: $response";
    
    if (substr($response, 0, 3) !== '250') {
        throw new Exception("SMTP server did not accept EHLO command: $response");
    }
    
    // If TLS is required and not already using SSL
    if ($smtp_encryption == 'tls' && strpos($smtp_host, 'ssl://') !== 0) {
        echo "Starting TLS negotiation...\n";
        fputs($socket, "STARTTLS\r\n");
        
        $response = fgets($socket, 515);
        echo "STARTTLS response: $response";
        
        if (substr($response, 0, 3) !== '220') {
            throw new Exception("SMTP server does not support STARTTLS: $response");
        }
        
        // Enable crypto on the socket
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception("Failed to enable TLS encryption");
        }
        
        echo "TLS negotiation successful\n";
        
        // Send EHLO again after TLS
        fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
        
        // Read response
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') break;
        }
        echo "EHLO after TLS response: $response";
    }
    
    // Try authentication if username and password are provided
    if (!empty($smtp_username) && !empty($smtp_password)) {
        echo "Attempting authentication...\n";
        fputs($socket, "AUTH LOGIN\r\n");
        
        $response = fgets($socket, 515);
        echo "AUTH LOGIN response: $response";
        
        if (substr($response, 0, 3) !== '334') {
            throw new Exception("SMTP server does not support AUTH LOGIN: $response");
        }
        
        // Send username (base64 encoded)
        fputs($socket, base64_encode($smtp_username) . "\r\n");
        $response = fgets($socket, 515);
        echo "Username response: $response";
        
        if (substr($response, 0, 3) !== '334') {
            throw new Exception("SMTP server rejected username: $response");
        }
        
        // Send password (base64 encoded)
        fputs($socket, base64_encode($smtp_password) . "\r\n");
        $response = fgets($socket, 515);
        echo "Password response: $response";
        
        if (substr($response, 0, 3) !== '235') {
            throw new Exception("SMTP authentication failed: $response");
        }
        
        echo "Authentication successful\n";
    }
    
    // Quit properly
    fputs($socket, "QUIT\r\n");
    $response = fgets($socket, 515);
    echo "QUIT response: $response";
    
    // Close socket
    fclose($socket);
    
    // Get debug output
    $debug = ob_get_clean();
    
    // Return success
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'تم الاتصال بخادم SMTP بنجاح',
        'debug' => $debug
    ]);
    
} catch (Exception $e) {
    // Get debug output
    $debug = ob_get_clean();
    
    // Return error
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => $debug
    ]);
}
?>
