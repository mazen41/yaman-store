<?php
/**
 * Simple SMTP Client
 * A basic implementation for sending emails via SMTP without requiring external libraries
 */

class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $timeout = 30;
    private $debug = false;
    private $error = '';
    private $debug_output = '';
    
    /**
     * Constructor
     * 
     * @param string $host SMTP host
     * @param int $port SMTP port
     * @param string $username SMTP username
     * @param string $password SMTP password
     * @param string $encryption SMTP encryption (tls, ssl, or none)
     * @param bool $debug Enable debug output
     */
    public function __construct($host, $port = 25, $username = '', $password = '', $encryption = 'tls', $debug = false) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = strtolower($encryption);
        $this->debug = $debug;
    }
    
    /**
     * Send an email
     * 
     * @param string $from_email Sender email
     * @param string $from_name Sender name
     * @param string $to_email Recipient email
     * @param string $to_name Recipient name
     * @param string $subject Email subject
     * @param string $body Email body
     * @param bool $is_html Whether the body is HTML
     * @return bool True if email was sent successfully
     */
    public function send($from_email, $from_name, $to_email, $to_name, $subject, $body, $is_html = true) {
        // Reset error and debug output
        $this->error = '';
        $this->debug_output = '';
        
        // Connect to SMTP server
        $socket = $this->connect();
        if (!$socket) {
            return false;
        }
        
        // Send EHLO command
        $server_name = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        if (!$this->sendCommand($socket, "EHLO " . $server_name, 250)) {
            return false;
        }
        
        // Start TLS if required (but skip for SSL connections on port 465)
        if ($this->encryption == 'tls' && $this->port != 465) {
            if (!$this->sendCommand($socket, "STARTTLS", 220)) {
                return false;
            }
            
            // Enable crypto on the socket with appropriate method
            $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            
            // Use TLS 1.2 if available (PHP >= 5.6)
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto_method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            
            if (!stream_socket_enable_crypto($socket, true, $crypto_method)) {
                $this->error = "Failed to enable TLS encryption";
                $this->debug("Failed to enable TLS encryption");
                fclose($socket);
                return false;
            }
            
            // Send EHLO again after TLS
            $server_name = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
            if (!$this->sendCommand($socket, "EHLO " . $server_name, 250)) {
                return false;
            }
        }
        
        // Authenticate if username and password are provided
        if (!empty($this->username) && !empty($this->password)) {
            if (!$this->authenticate($socket)) {
                return false;
            }
        }
        
        // Set sender
        if (!$this->sendCommand($socket, "MAIL FROM:<{$from_email}>", 250)) {
            return false;
        }
        
        // Set recipient
        if (!$this->sendCommand($socket, "RCPT TO:<{$to_email}>", 250)) {
            return false;
        }
        
        // Start data
        if (!$this->sendCommand($socket, "DATA", 354)) {
            return false;
        }
        
        // Prepare headers
        $headers = [
            "Date: " . date("r"),
            "To: " . ($to_name ? $this->encodeHeader($to_name) . " <{$to_email}>" : $to_email),
            "From: " . ($from_name ? $this->encodeHeader($from_name) . " <{$from_email}>" : $from_email),
            "Subject: " . $this->encodeHeader($subject),
            "Message-ID: <" . time() . rand(1000, 9999) . "@" . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : (parse_url($this->host, PHP_URL_HOST) ? parse_url($this->host, PHP_URL_HOST) : 'localhost')) . ">",
            "X-Mailer: SimpleSMTP",
            "MIME-Version: 1.0"
        ];
        
        // Add content type
        if ($is_html) {
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }
        
        // Prepare message
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        
        // Send message
        if (!$this->sendData($socket, $message)) {
            return false;
        }
        
        // Quit
        $this->sendCommand($socket, "QUIT", 221);
        
        // Close connection
        fclose($socket);
        
        return true;
    }
    
    /**
     * Connect to SMTP server
     * 
     * @return resource|bool Socket resource or false on failure
     */
    private function connect() {
        $this->debug("Connecting to {$this->host}:{$this->port} using {$this->encryption}...");
        
        $errno = 0;
        $errstr = '';
        
        // Special handling for SSL on port 465
        if ($this->encryption == 'ssl' || ($this->port == 465 && $this->encryption != 'tls')) {
            // Force SSL for port 465
            $this->debug("Using SSL encryption for connection");
            $socket = @fsockopen('ssl://' . $this->host, $this->port, $errno, $errstr, $this->timeout);
        } else {
            $this->debug("Using plain connection (TLS will be started later if needed)");
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        }
        
        if (!$socket) {
            $this->error = "Failed to connect to SMTP server: {$errstr} ({$errno})";
            $this->debug($this->error);
            return false;
        }
        
        // Set socket timeout
        stream_set_timeout($socket, $this->timeout);
        
        // Get greeting
        $response = fgets($socket, 515);
        $this->debug("Server greeting: {$response}");
        
        if (substr($response, 0, 3) != '220') {
            $this->error = "Invalid greeting from SMTP server: {$response}";
            $this->debug($this->error);
            fclose($socket);
            return false;
        }
        
        return $socket;
    }
    
    /**
     * Send command to SMTP server
     * 
     * @param resource $socket Socket resource
     * @param string $command Command to send
     * @param int $expected_code Expected response code
     * @return bool True if command was successful
     */
    private function sendCommand($socket, $command, $expected_code) {
        $this->debug("Sending: {$command}");
        
        fputs($socket, "{$command}\r\n");
        
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            $this->debug("Response: {$line}");
            
            // If this is the last line of the response
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        
        $code = substr($response, 0, 3);
        
        if ($code != $expected_code) {
            $this->error = "SMTP Error: Expected {$expected_code}, got {$code}. Response: {$response}";
            $this->debug($this->error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Send data to SMTP server
     * 
     * @param resource $socket Socket resource
     * @param string $data Data to send
     * @return bool True if data was sent successfully
     */
    private function sendData($socket, $data) {
        $this->debug("Sending data...");
        
        fputs($socket, $data . "\r\n");
        
        $response = fgets($socket, 515);
        $this->debug("Response: {$response}");
        
        $code = substr($response, 0, 3);
        
        if ($code != 250) {
            $this->error = "SMTP Error: Failed to send data. Response: {$response}";
            $this->debug($this->error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Authenticate with SMTP server
     * 
     * @param resource $socket Socket resource
     * @return bool True if authentication was successful
     */
    private function authenticate($socket) {
        $this->debug("Authenticating...");
        
        // Try different authentication methods
        // First try AUTH LOGIN
        $this->debug("Trying AUTH LOGIN...");
        fputs($socket, "AUTH LOGIN\r\n");
        
        $response = fgets($socket, 515);
        $this->debug("Response: {$response}");
        
        if (substr($response, 0, 3) == '334') {
            // AUTH LOGIN accepted
            // Send username
            $this->debug("Sending username...");
            fputs($socket, base64_encode($this->username) . "\r\n");
            
            $response = fgets($socket, 515);
            $this->debug("Response: {$response}");
            
            if (substr($response, 0, 3) != '334') {
                $this->error = "SMTP Error: Authentication failed (username). Response: {$response}";
                $this->debug($this->error);
                return false;
            }
            
            // Send password
            $this->debug("Sending password...");
            fputs($socket, base64_encode($this->password) . "\r\n");
            
            $response = fgets($socket, 515);
            $this->debug("Response: {$response}");
            
            if (substr($response, 0, 3) != '235') {
                $this->error = "SMTP Error: Authentication failed (password). Response: {$response}";
                $this->debug($this->error);
                return false;
            }
            
            $this->debug("Authentication successful");
            return true;
        } else {
            // AUTH LOGIN failed, try AUTH PLAIN
            $this->debug("AUTH LOGIN failed, trying AUTH PLAIN...");
            fputs($socket, "AUTH PLAIN\r\n");
            
            $response = fgets($socket, 515);
            $this->debug("Response: {$response}");
            
            if (substr($response, 0, 3) == '334') {
                // AUTH PLAIN accepted
                // Send authentication string (format: \0username\0password)
                $auth = base64_encode("\0" . $this->username . "\0" . $this->password);
                $this->debug("Sending AUTH PLAIN credentials...");
                fputs($socket, $auth . "\r\n");
                
                $response = fgets($socket, 515);
                $this->debug("Response: {$response}");
                
                if (substr($response, 0, 3) != '235') {
                    $this->error = "SMTP Error: AUTH PLAIN authentication failed. Response: {$response}";
                    $this->debug($this->error);
                    return false;
                }
                
                $this->debug("Authentication successful with AUTH PLAIN");
                return true;
            } else {
                $this->error = "SMTP Error: Server does not support AUTH LOGIN or AUTH PLAIN. Response: {$response}";
                $this->debug($this->error);
                return false;
            }
        }
    }
    
    /**
     * Encode header for non-ASCII characters
     * 
     * @param string $text Text to encode
     * @return string Encoded text
     */
    private function encodeHeader($text) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    
    /**
     * Debug message
     * 
     * @param string $message Debug message
     */
    private function debug($message) {
        if ($this->debug) {
            $this->debug_output .= date('Y-m-d H:i:s') . ": {$message}\n";
        }
    }
    
    /**
     * Get error message
     * 
     * @return string Error message
     */
    public function getError() {
        return $this->error;
    }
    
    /**
     * Get debug output
     * 
     * @return string Debug output
     */
    public function getDebugOutput() {
        return $this->debug_output;
    }
}
?>
