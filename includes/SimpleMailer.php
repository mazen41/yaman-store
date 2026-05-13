<?php
/**
 * Simple Mailer Class
 * A basic implementation for sending emails without requiring PHPMailer
 */

// Include SimpleSMTP class
require_once __DIR__ . '/SimpleSMTP.php';

class SimpleMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $from_email;
    private $from_name;
    private $error_info = '';
    private $debug = false;
    
    /**
     * Constructor
     * 
     * @param array $settings SMTP settings
     */
    public function __construct($settings) {
        $this->host = $settings['smtp_host'] ?? '';
        $this->port = $settings['smtp_port'] ?? 587;
        $this->username = $settings['smtp_username'] ?? '';
        $this->password = $settings['smtp_password'] ?? '';
        $this->encryption = $settings['smtp_encryption'] ?? 'tls';
        $this->from_email = $settings['from_email'] ?? '';
        $this->from_name = $settings['from_name'] ?? '';
        $this->debug = isset($settings['debug']) ? (bool)$settings['debug'] : false;
    }
    
    /**
     * Send an email
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $toName Recipient name
     * @return bool True if email was sent successfully
     */
    public function send($to, $subject, $body, $toName = '') {
        // Try to send using direct SMTP connection first
        try {
            // Create SimpleSMTP instance
            $smtp = new SimpleSMTP(
                $this->host,
                $this->port,
                $this->username,
                $this->password,
                $this->encryption,
                $this->debug
            );
            
            // Send email
            $result = $smtp->send(
                $this->from_email,
                $this->from_name,
                $to,
                $toName,
                $subject,
                $body,
                true // HTML email
            );
            
            if (!$result) {
                $this->error_info = $smtp->getError();
                
                // If debug is enabled, add debug output to error info
                if ($this->debug) {
                    $this->error_info .= "\n\nDebug output:\n" . $smtp->getDebugOutput();
                }
                
                // Fall back to mail() function
                return $this->sendWithMailFunction($to, $subject, $body, $toName);
            }
            
            return true;
        } catch (Exception $e) {
            $this->error_info = "SMTP Exception: " . $e->getMessage();
            
            // Fall back to mail() function
            return $this->sendWithMailFunction($to, $subject, $body, $toName);
        }
    }
    
    /**
     * Send email using PHP mail() function as fallback
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $toName Recipient name
     * @return bool True if email was sent successfully
     */
    private function sendWithMailFunction($to, $subject, $body, $toName = '') {
        // Set headers
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $this->encodeHeader($this->from_name) . ' <' . $this->from_email . '>',
            'Reply-To: ' . $this->from_email,
            'X-Mailer: SimpleMailer'
        ];
        
        // Configure PHP mail settings using the SMTP settings
        $old_smtp = ini_get('SMTP');
        $old_port = ini_get('smtp_port');
        $old_sendmail_from = ini_get('sendmail_from');
        
        // Set new SMTP settings
        ini_set('SMTP', $this->host);
        ini_set('smtp_port', $this->port);
        ini_set('sendmail_from', $this->from_email);
        
        // Try to send email using PHP mail() function
        $success = @mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $headers));
        
        // Restore original settings
        ini_set('SMTP', $old_smtp);
        ini_set('smtp_port', $old_port);
        ini_set('sendmail_from', $old_sendmail_from);
        
        if (!$success) {
            $this->error_info .= "\nMail function error: " . (error_get_last()['message'] ?? 'Unknown error');
            return false;
        }
        
        return true;
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
     * Get last error message
     * 
     * @return string Error message
     */
    public function getErrorInfo() {
        return $this->error_info;
    }
    
    /**
     * Send email with template
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $template_path Path to template file
     * @param array $data Data to pass to template
     * @param string $toName Recipient name
     * @return bool True if email was sent successfully
     */
    public function sendWithTemplate($to, $subject, $template_path, $data = [], $toName = '') {
        if (!file_exists($template_path)) {
            $this->error_info = "Template file not found: $template_path";
            return false;
        }
        
        // Extract data to make variables available in template
        extract($data);
        
        // Capture template output
        ob_start();
        include $template_path;
        $body = ob_get_clean();
        
        // Send email
        return $this->send($to, $subject, $body, $toName);
    }
}
?>
