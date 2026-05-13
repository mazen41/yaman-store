<?php
/**
 * Helper functions for downloading files
 */

/**
 * Download a file from a URL using the best available method
 * 
 * @param string $url The URL to download from
 * @return string|false The file content or false on failure
 */
function download_file($url) {
    // Try file_get_contents if allow_url_fopen is enabled
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'PHPMailer Installer'
            ]
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content !== false) {
            return $content;
        }
    }
    
    // Try cURL if available
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHPMailer Installer');
        $content = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!empty($content)) {
            return $content;
        }
    }
    
    // All methods failed
    return false;
}

/**
 * Extract a zip file without using ZipArchive
 * 
 * @param string $zip_file Path to the zip file
 * @param string $extract_to Directory to extract to
 * @return bool True on success, false on failure
 */
function extract_zip_without_ziparchive($zip_file, $extract_to) {
    // Check if we're on Windows and have access to the 'unzip' command
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Try to use PowerShell to extract
        $zip_file = str_replace('/', '\\', $zip_file);
        $extract_to = str_replace('/', '\\', $extract_to);
        
        $cmd = "powershell -command \"Expand-Archive -Path '$zip_file' -DestinationPath '$extract_to' -Force\"";
        @exec($cmd, $output, $return_var);
        
        return $return_var === 0;
    } else {
        // On Linux/Unix, try the unzip command
        $cmd = "unzip -o '$zip_file' -d '$extract_to'";
        @exec($cmd, $output, $return_var);
        
        return $return_var === 0;
    }
    
    // If we get here, extraction failed
    return false;
}
?>
