<?php
/**
 * modules/sorting/ajax_ocr.php
 * Receives a base64 image frame from the browser camera,
 * sends it to Google Gemini Vision API, returns the SK code found.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

define('GEMINI_API_KEY', 'AIzaSyADAksM1ApfkulWL_TshRtUczOSNZbeX4g');
define('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . GEMINI_API_KEY);

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['image'])) {
    echo json_encode(['success' => false, 'message' => 'لم يتم إرسال صورة']);
    exit();
}

// Strip data URL prefix if present
$imageData = $input['image'];
if (strpos($imageData, 'data:') === 0) {
    $imageData = preg_replace('/^data:[^;]+;base64,/', '', $imageData);
}
$imageData = trim($imageData);

if (strlen($imageData) < 100) {
    echo json_encode(['success' => false, 'message' => 'الصورة فارغة أو غير صالحة']);
    exit();
}

$payload = json_encode([
    'contents' => [[
        'parts' => [
            [
                'inline_data' => [
                    'mime_type' => 'image/jpeg',
                    'data'      => $imageData,
                ]
            ],
            [
                'text' => 'Look at this product label image. Find the SKU code that starts with "SK" followed by digits (for example: SK26011316354). Reply with ONLY the SK code itself, no spaces, no extra text. If you cannot find it clearly, reply with exactly: NOT_FOUND'
            ]
        ]
    ]]
]);

$ch = curl_init(GEMINI_ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['success' => false, 'message' => 'خطأ في الاتصال: ' . $curlErr]);
    exit();
}

$body = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $body['error']['message'] ?? ('HTTP ' . $httpCode);
    echo json_encode(['success' => false, 'message' => 'خطأ Gemini API: ' . $errMsg]);
    exit();
}

$rawText = trim($body['candidates'][0]['content']['parts'][0]['text'] ?? '');
$rawText = strtoupper(preg_replace('/\s+/', '', $rawText));

if ($rawText === 'NOT_FOUND' || !preg_match('/^SK\d{6,}$/', $rawText)) {
    echo json_encode([
        'success' => false,
        'message' => 'لم يُعثر على SKU في الصورة. (Gemini قرأ: «' . htmlspecialchars(substr($rawText, 0, 60)) . '»)'
    ]);
    exit();
}

echo json_encode(['success' => true, 'sku' => $rawText]);
