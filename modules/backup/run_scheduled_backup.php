<?php
// This script is meant to be run from the command line (CLI) via a Cron Job.
// It should NOT be accessible via the web.

// Go to the script's directory
chdir(__DIR__);

// Load database configuration
require_once '../../config/database.php'; 

// --- Configuration ---
$db_host = defined('DB_HOST') ? DB_HOST : 'localhost';
$db_name = defined('DB_NAME') ? DB_NAME : '';
$db_user = defined('DB_USER') ? DB_USER : '';
$db_pass = defined('DB_PASS') ? DB_PASS : '';

// **IMPORTANT**: Set this to your secure, non-public backup directory path
$backup_dir = '/public_html/admin/modules/backup/'; // Example: Change this!

$settings_file = __DIR__ . '/backup_settings.json';

// --- Script Logic ---

// 1. Check for valid configuration
if (empty($db_name) || empty($db_user) || !is_dir($backup_dir) || !is_writable($backup_dir)) {
    log_error("Scheduled backup failed: Invalid configuration or backup directory not writable.");
    exit;
}

// 2. Read schedule settings
if (!file_exists($settings_file)) {
    log_error("Scheduled backup failed: settings file not found.");
    exit;
}
$settings = json_decode(file_get_contents($settings_file), true);
$schedule = $settings['schedule'] ?? 'disabled';

if ($schedule === 'disabled') {
    echo "Automatic backups are disabled. Exiting.\n";
    exit;
}

// 3. Create backup
$filename = sprintf(
    '%s_backup_%s_%s.sql.gz',
    $db_name,
    $schedule,
    date('Y-m-d')
);
$backup_file_path = $backup_dir . $filename;

// We use gzip to compress the backup, saving space.
$command = sprintf(
    'mysqldump --host=%s --user=%s --password=%s %s | gzip > %s',
    escapeshellarg($db_host),
    escapeshellarg($db_user),
    escapeshellarg($db_pass),
    escapeshellarg($db_name),
    escapeshellarg($backup_file_path)
);

$output = null;
$return_var = null;
exec($command, $output, $return_var);

if ($return_var === 0) {
    echo "Successfully created backup: $filename\n";
    // 4. Clean up old backups (optional but highly recommended)
    cleanup_old_backups($backup_dir, $schedule);
} else {
    log_error("Failed to create backup: $filename");
    // Clean up failed file if it was created
    if (file_exists($backup_file_path)) {
        unlink($backup_file_path);
    }
}

exit;


// --- Helper Functions ---

function log_error($message) {
    $log_file = __DIR__ . '/backup_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

function cleanup_old_backups($dir, $schedule) {
    // Keep last 7 daily, 5 weekly, 12 monthly backups
    $files = glob($dir . '/*_backup_' . $schedule . '_*.sql.gz');
    $keep_count = 0;

    switch ($schedule) {
        case 'daily':   $keep_count = 7; break;
        case 'weekly':  $keep_count = 5; break;
        case 'monthly': $keep_count = 12; break;
    }

    if (count($files) > $keep_count) {
        // Sort files by modification time (oldest first)
        usort($files, function($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });
        
        $files_to_delete = array_slice($files, 0, count($files) - $keep_count);
        foreach ($files_to_delete as $file) {
            unlink($file);
            echo "Deleted old backup: " . basename($file) . "\n";
        }
    }
}