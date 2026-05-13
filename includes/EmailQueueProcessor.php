<?php
/**
 * Email Queue Processor
 * Processes queued emails in batches
 */

require_once "config/database.php";
require_once "includes/EnterpriseEmailer.php";

class EmailQueueProcessor {
    private $db;
    private $mailer;
    
    public function __construct($db) {
        $this->db = $db;
        $this->mailer = new EnterpriseEmailer($db);
    }
    
    public function processQueue($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM email_queue 
            WHERE status = 'pending' AND attempts < max_attempts
            ORDER BY priority DESC, created_at ASC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($emails as $email) {
            $this->processEmail($email);
        }
        
        return count($emails);
    }
    
    private function processEmail($email) {
        // Update status to sending
        $this->updateEmailStatus($email["id"], "sending");
        
        if ($this->mailer->sendEmail($email["to_email"], $email["subject"], $email["body"], $email["to_name"])) {
            $this->updateEmailStatus($email["id"], "sent", null, date("Y-m-d H:i:s"));
        } else {
            $attempts = $email["attempts"] + 1;
            $status = ($attempts >= $email["max_attempts"]) ? "failed" : "pending";
            $error = $this->mailer->getLastError();
            
            $this->updateEmailStatus($email["id"], $status, $error, null, $attempts);
        }
    }
    
    private function updateEmailStatus($id, $status, $error = null, $sent_at = null, $attempts = null) {
        $sql = "UPDATE email_queue SET status = ?";
        $params = [$status];
        
        if ($error !== null) {
            $sql .= ", error_message = ?";
            $params[] = $error;
        }
        
        if ($sent_at !== null) {
            $sql .= ", sent_at = ?";
            $params[] = $sent_at;
        }
        
        if ($attempts !== null) {
            $sql .= ", attempts = ?";
            $params[] = $attempts;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
?>