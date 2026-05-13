<?php
/**
 * Email Sender Class
 * 
 * Handles sending emails using PHPMailer with templates
 */

class EmailSender {
    private $db;
    private $settings;
    private $mailer;
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     */
    public function __construct($db) {
        $this->db = $db;
        $this->loadSettings();
        $this->initMailer();
    }
    
    /**
     * Load email settings from database
     */
    private function loadSettings() {
        try {
            $stmt = $this->db->query("SELECT * FROM email_settings WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            $this->settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$this->settings) {
                throw new Exception("No active email settings found");
            }
        } catch (PDOException $e) {
            throw new Exception("Failed to load email settings: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize mailer
     */
    private function initMailer() {
        // Check if PHPMailer is available
        $phpmailer_exists = false;
        
        // First, check if it's already loaded
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $phpmailer_exists = true;
        } else {
            // Try to load it from vendor directory
            $autoloadPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
                $phpmailer_exists = class_exists('PHPMailer\PHPMailer\PHPMailer');
            }
            
            // If still not found, try manual inclusion
            if (!$phpmailer_exists) {
                $phpmailerPath = __DIR__ . '/../includes/phpmailer/';
                if (file_exists($phpmailerPath . 'PHPMailer.php')) {
                    require_once $phpmailerPath . 'PHPMailer.php';
                    require_once $phpmailerPath . 'SMTP.php';
                    require_once $phpmailerPath . 'Exception.php';
                    $phpmailer_exists = class_exists('PHPMailer\PHPMailer\PHPMailer');
                }
            }
            
            // If still not found, try direct inclusion from the same directory
            if (!$phpmailer_exists) {
                $phpmailerPath = __DIR__ . '/phpmailer/';
                if (file_exists($phpmailerPath . 'PHPMailer.php')) {
                    require_once $phpmailerPath . 'PHPMailer.php';
                    require_once $phpmailerPath . 'SMTP.php';
                    require_once $phpmailerPath . 'Exception.php';
                    $phpmailer_exists = class_exists('PHPMailer\PHPMailer\PHPMailer');
                }
            }
        }
        
        // If PHPMailer is found, use it
        if ($phpmailer_exists) {
            $this->mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->settings['smtp_host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->settings['smtp_username'];
            $this->mailer->Password = $this->settings['smtp_password'];
            $this->mailer->SMTPSecure = $this->settings['smtp_encryption'] == 'none' ? false : $this->settings['smtp_encryption'];
            $this->mailer->Port = $this->settings['smtp_port'];
            $this->mailer->CharSet = 'UTF-8';
            
            // Set default sender
            $this->mailer->setFrom($this->settings['from_email'], $this->settings['from_name']);
        } else {
            // If PHPMailer is not found, use SimpleMailer as fallback
            require_once __DIR__ . '/SimpleMailer.php';
            $this->mailer = new SimpleMailer($this->settings);
        }
    }
    
    /**
     * Send new order notification email
     * 
     * @param array $order Order details
     * @param array $customer Customer details
     * @param array $items Order items
     * @param string $trackingUrl Optional tracking URL
     * @return bool True if email was sent successfully
     */
    public function sendNewOrderEmail($order, $customer, $items, $trackingUrl = null) {
        if (empty($customer['email'])) {
            throw new Exception("Customer email is required");
        }
        
        try {
            // Check if we're using PHPMailer or SimpleMailer
            if ($this->mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
                // Reset mailer
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                
                // Set recipient
                $this->mailer->addAddress($customer['email'], $customer['name']);
                
                // Set content
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'طلب جديد #' . $order['order_number'];
                
                // Load template
                ob_start();
                include __DIR__ . '/../templates/email/order_new.php';
                $this->mailer->Body = ob_get_clean();
                
                // Send email
                return $this->mailer->send();
            } else {
                // Using SimpleMailer
                $subject = 'طلب جديد #' . $order['order_number'];
                $template_path = __DIR__ . '/../templates/email/order_new.php';
                $data = [
                    'order' => $order,
                    'customer' => $customer,
                    'items' => $items,
                    'trackingUrl' => $trackingUrl
                ];
                
                return $this->mailer->sendWithTemplate($customer['email'], $subject, $template_path, $data, $customer['name']);
            }
        } catch (Exception $e) {
            throw new Exception("Failed to send new order email: " . $e->getMessage());
        }
    }
    
    /**
     * Send order status update notification email
     * 
     * @param array $order Order details
     * @param array $customer Customer details
     * @param array $items Order items
     * @param array $status_history Status history entry
     * @param string $trackingUrl Optional tracking URL
     * @return bool True if email was sent successfully
     */
    public function sendOrderStatusEmail($order, $customer, $items, $status_history, $trackingUrl = null) {
        if (empty($customer['email'])) {
            throw new Exception("Customer email is required");
        }
        
        try {
            // Check if we're using PHPMailer or SimpleMailer
            if ($this->mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
                // Reset mailer
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                
                // Set recipient
                $this->mailer->addAddress($customer['email'], $customer['name']);
                
                // Set content
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'تحديث حالة الطلب #' . $order['order_number'];
                
                // Load template
                ob_start();
                include __DIR__ . '/../templates/email/order_status.php';
                $this->mailer->Body = ob_get_clean();
                
                // Send email
                return $this->mailer->send();
            } else {
                // Using SimpleMailer
                $subject = 'تحديث حالة الطلب #' . $order['order_number'];
                $template_path = __DIR__ . '/../templates/email/order_status.php';
                $data = [
                    'order' => $order,
                    'customer' => $customer,
                    'items' => $items,
                    'status_history' => $status_history,
                    'trackingUrl' => $trackingUrl
                ];
                
                return $this->mailer->sendWithTemplate($customer['email'], $subject, $template_path, $data, $customer['name']);
            }
        } catch (Exception $e) {
            throw new Exception("Failed to send order status email: " . $e->getMessage());
        }
    }
    
    /**
     * Send invoice email
     * 
     * @param array $invoice Invoice details
     * @param array $customer Customer details
     * @param array $items Invoice items
     * @param array $company Company details
     * @param string $paymentUrl Optional payment URL
     * @return bool True if email was sent successfully
     */
    public function sendInvoiceEmail($invoice, $customer, $items, $company = [], $paymentUrl = null) {
        if (empty($customer['email'])) {
            throw new Exception("Customer email is required");
        }
        
        try {
            // Check if we're using PHPMailer or SimpleMailer
            if ($this->mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
                // Reset mailer
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                
                // Set recipient
                $this->mailer->addAddress($customer['email'], $customer['name']);
                
                // Set content
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'فاتورة #' . $invoice['invoice_number'];
                
                // Load template
                ob_start();
                include __DIR__ . '/../templates/email/invoice.php';
                $this->mailer->Body = ob_get_clean();
                
                // Send email
                return $this->mailer->send();
            } else {
                // Using SimpleMailer
                $subject = 'فاتورة #' . $invoice['invoice_number'];
                $template_path = __DIR__ . '/../templates/email/invoice.php';
                $data = [
                    'invoice' => $invoice,
                    'customer' => $customer,
                    'items' => $items,
                    'company' => $company,
                    'paymentUrl' => $paymentUrl
                ];
                
                return $this->mailer->sendWithTemplate($customer['email'], $subject, $template_path, $data, $customer['name']);
            }
        } catch (Exception $e) {
            throw new Exception("Failed to send invoice email: " . $e->getMessage());
        }
    }
    
    /**
     * Send custom email
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $toName Recipient name
     * @return bool True if email was sent successfully
     */
    public function sendCustomEmail($to, $subject, $body, $toName = '') {
        try {
            // Check if we're using PHPMailer or SimpleMailer
            if ($this->mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
                // Reset mailer
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                
                // Set recipient
                $this->mailer->addAddress($to, $toName);
                
                // Set content
                $this->mailer->isHTML(true);
                $this->mailer->Subject = $subject;
                $this->mailer->Body = $body;
                
                // Send email
                return $this->mailer->send();
            } else {
                // Using SimpleMailer
                return $this->mailer->send($to, $subject, $body, $toName);
            }
        } catch (Exception $e) {
            throw new Exception("Failed to send custom email: " . $e->getMessage());
        }
    }
    
    /**
     * Get last error message
     * 
     * @return string Error message
     */
    public function getErrorInfo() {
        if ($this->mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
            return $this->mailer->ErrorInfo;
        } else {
            return $this->mailer->getErrorInfo();
        }
    }
}
