<?php
/**
 * includes/FcmHelper.php
 * ─────────────────────────────────────────────────────────────────
 * Helper class to trigger Firebase Cloud Messaging (FCM) silent pushes.
 * Used to notify registered scanning devices when orders are modified,
 * triggering automatic background synchronization.
 * ─────────────────────────────────────────────────────────────────
 */

class FcmHelper
{
    /**
     * Sends a silent background push to all registered devices.
     * Tells the app to sync orders in the background.
     *
     * @param PDO $db
     * @return array Status reports of the dispatch
     */
    public static function triggerSilentSync(PDO $db): array
    {
        // 1. Fetch all registered device FCM tokens
        try {
            $stmt = $db->query("
                SELECT fcm_token, platform, device_name 
                FROM registered_devices 
                WHERE fcm_token IS NOT NULL AND fcm_token != ''
            ");
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("FCM trigger failed to query registered devices: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }

        if (empty($devices)) {
            return ['success' => true, 'message' => 'No devices registered for FCM.'];
        }

        // 2. Load Firebase service account config if it exists
        $credPath = __DIR__ . '/../config/firebase_credentials.json';
        if (!file_exists($credPath)) {
            error_log("FCM credentials file not found at $credPath. Silent push skipped.");
            return [
                'success' => false, 
                'message' => 'Firebase credentials file not found. Silent push skipped.'
            ];
        }

        $credentials = json_decode(file_get_contents($credPath), true);
        if (!$credentials || empty($credentials['project_id'])) {
            error_log("FCM credentials file is invalid. Silent push skipped.");
            return [
                'success' => false,
                'message' => 'Firebase credentials file is invalid.'
            ];
        }

        $projectId = $credentials['project_id'];
        $accessToken = self::getGoogleAccessToken($credentials);

        if (!$accessToken) {
            error_log("Failed to fetch Google OAuth2 Access Token for FCM.");
            return [
                'success' => false,
                'message' => 'OAuth2 authentication failed.'
            ];
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $results = [];

        foreach ($devices as $device) {
            $token = $device['fcm_token'];

            // Construct FCM message payload
            // Silent notification includes only a data payload, no notification title/body,
            // which wakes up the application in the background to execute background sync
            $payload = [
                'message' => [
                    'token' => $token,
                    'data' => [
                        'action' => 'sync_orders',
                        'timestamp' => date('c')
                    ],
                    // High priority for Android to ensure background execution
                    'android' => [
                        'priority' => 'high'
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '5', // Background priority for iOS
                            'apns-push-type' => 'background'
                        ],
                        'payload' => [
                            'aps' => [
                                'content-available' => 1
                            ]
                        ]
                    ]
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $respDecoded = json_decode($response, true);
            $success = ($httpCode === 200);

            $results[] = [
                'device_name' => $device['device_name'],
                'platform' => $device['platform'],
                'success' => $success,
                'http_code' => $httpCode,
                'response' => $respDecoded
            ];

            if (!$success) {
                error_log("FCM push failed for device ({$device['device_name']}): " . $response);
            }
        }

        return [
            'success' => true,
            'message' => 'FCM broadcast completed.',
            'results' => $results
        ];
    }

    /**
     * Generates a Google OAuth2 Access Token using the Service Account Credentials.
     * Hand-crafted to avoid requiring external library dependencies (like Google API Client).
     */
    private static function getGoogleAccessToken(array $credentials): ?string
    {
        $privateKey = $credentials['private_key'] ?? '';
        $clientEmail = $credentials['client_email'] ?? '';
        $tokenUrl = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        if (empty($privateKey) || empty($clientEmail)) {
            return null;
        }

        // Construct JWT Header
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);

        // Construct JWT Claim Set
        $now = time();
        $claimSet = json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $tokenUrl,
            'exp' => $now + 3600,
            'iat' => $now
        ]);

        // Base64Url Encode helper
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlClaimSet = self::base64UrlEncode($claimSet);

        // Sign JWT using Private Key
        $signatureInput = $base64UrlHeader . '.' . $base64UrlClaimSet;
        $signature = '';
        
        try {
            $success = openssl_sign($signatureInput, $signature, $privateKey, 'SHA256');
            if (!$success) {
                return null;
            }
        } catch (Exception $e) {
            return null;
        }

        $base64UrlSignature = self::base64UrlEncode($signature);
        $jwt = $signatureInput . '.' . $base64UrlSignature;

        // Post request to get OAuth2 token
        $postFields = 'grant_type=' . urlencode('urn:ietf:params:oauth:grant-type:jwt-bearer') . '&assertion=' . urlencode($jwt);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $res = json_decode($response, true);
        return $res['access_token'] ?? null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
