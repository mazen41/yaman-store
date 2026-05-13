<?php
/**
 * Enterprise Email System
 * Senior Developer Implementation with Multiple Fallbacks
 */

class EnterpriseEmailer {
    private $db;
    private $config;
    private $debug_output = "";
    private $last_error = "";
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadConfiguration();
    }
    
    private function loadConfiguration() {
        try {
            $stmt = $this->db->query("
                SELECT * FROM email_settings 
                WHERE is_active = 1 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$this->config) {
                // Fallback to localhost configuration
                $stmt = $this->db->query("
                    SELECT * FROM email_settings 
                    WHERE config_name = 'localhost_fallback' 
                    LIMIT 1
                ");
                $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $e) {
            $this->last_error = "Configuration error: " . $e->getMessage();
        }
    }
    
    public function sendEmail($to, $subject, $body, $toName = "") {
        if (!$this->config) {
            return $this->fallbackMailFunction($to, $subject, $body, $toName);
        }
        
        // Try multiple methods in order of preference
        $methods = [
            "sendViaSMTP",
            "sendViaMailFunction", 
            "queueEmail"
        ];
        
        foreach ($methods as $method) {
            if ($this->$method($to, $subject, $body, $toName)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function sendViaSMTP($to, $subject, $body, $toName) {
        try {
            $socket = $this->connectSMTP();
            if (!$socket) return false;
            
            // SMTP conversation
            if (!$this->smtpCommand($socket, "EHLO localhost", 250)) return false;
            
            if ($this->config["smtp_encryption"] == "tls") {
                if (!$this->smtpCommand($socket, "STARTTLS", 220)) return false;
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                if (!$this->smtpCommand($socket, "EHLO localhost", 250)) return false;
            }
            
            if (!empty($this->config["smtp_username"])) {
                if (!$this->authenticateSMTP($socket)) return false;
            }
            
            if (!$this->smtpCommand($socket, "MAIL FROM:<{$this->config['from_email']}>", 250)) return false;
            if (!$this->smtpCommand($socket, "RCPT TO:<$to>", 250)) return false;
            if (!$this->smtpCommand($socket, "DATA", 354)) return false;
            
            $message = $this->buildMessage($to, $toName, $subject, $body);
            if (!$this->smtpData($socket, $message)) return false;
            
            $this->smtpCommand($socket, "QUIT", 221);
            fclose($socket);
            
            return true;
            
        } catch (Exception $e) {
            $this->last_error = "SMTP Error: " . $e->getMessage();
            return false;
        }
    }
    
    private function connectSMTP() {
        $host = $this->config["smtp_host"];
        $port = $this->config["smtp_port"];
        
        if ($this->config["smtp_encryption"] == "ssl") {
            $host = "ssl://" . $host;
        }
        
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        
        if (!$socket) {
            $this->last_error = "Connection failed: $errstr ($errno)";
            return false;
        }
        
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) != "220") {
            $this->last_error = "Invalid SMTP greeting: $response";
            fclose($socket);
            return false;
        }
        
        return $socket;
    }
    
    private function smtpCommand($socket, $command, $expected_code) {
        fputs($socket, "$command\r\n");
        $response = fgets($socket, 515);
        
        if ($this->config["debug_mode"]) {
            $this->debug_output .= "> $command\n< $response";
        }
        
        return substr($response, 0, 3) == $expected_code;
    }
    
    private function authenticateSMTP($socket) {
        if (!$this->smtpCommand($socket, "AUTH LOGIN", 334)) return false;
        if (!$this->smtpCommand($socket, base64_encode($this->config["smtp_username"]), 334)) return false;
        if (!$this->smtpCommand($socket, base64_encode($this->config["smtp_password"]), 235)) return false;
        
        return true;
    }
    
    private function smtpData($socket, $data) {
        fputs($socket, "$data\r\n.\r\n");
        $response = fgets($socket, 515);
        return substr($response, 0, 3) == "250";
    }
    
    private function buildMessage($to, $toName, $subject, $body) {
        $headers = [
            "Date: " . date("r"),
            "To: " . ($toName ? "=?UTF-8?B?" . base64_encode($toName) . "?= <$to>" : $to),
            "From: =?UTF-8?B?" . base64_encode($this->config["from_name"]) . "?= <{$this->config['from_email']}>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: 8bit"
        ];
        
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
    
    private function sendViaMailFunction($to, $subject, $body, $toName) {
        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=UTF-8", 
            "From: {$this->config['from_name']} <{$this->config['from_email']}>",
            "Reply-To: {$this->config['from_email']}"
        ];
        
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    private function fallbackMailFunction($to, $subject, $body, $toName) {
        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=UTF-8",
            "From: Yassin System <noreply@localhost>"
        ];
        
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    private function queueEmail($to, $subject, $body, $toName) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_queue (to_email, to_name, subject, body, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            return $stmt->execute([$to, $toName, $subject, $body]);
        } catch (PDOException $e) {
            $this->last_error = "Queue error: " . $e->getMessage();
            return false;
        }
    }
    
    public function getLastError() {
        return $this->last_error;
    }
    
    public function getDebugOutput() {
        return $this->debug_output;
    }
}
?>