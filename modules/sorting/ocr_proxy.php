<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

header('Content-Type: application/json');

if (empty($_FILES['image']['tmp_name'])) {

    echo json_encode(['sku' => null, 'error' => 'no image received']);

    exit;

}

$GROQ_API_KEY = getenv('GROQ_API_KEY');

// Convert image to base64

$imageData = base64_encode(file_get_contents($_FILES['image']['tmp_name']));

$mimeType  = $_FILES['image']['type'] ?: 'image/jpeg';

$payload = json_encode([

    'model'      => 'meta-llama/llama-4-scout-17b-16e-instruct',

    'max_tokens' => 100,

    'messages'   => [[

        'role'    => 'user',

        'content' => [

            [

                'type'      => 'image_url',

                'image_url' => [

                    'url' => "data:{$mimeType};base64,{$imageData}"

                ]

            ],

            [

                'type' => 'text',

                'text' => 'Look at this product label. Find the SKU code which starts with "sk" or "SK" followed by numbers (example: sk2410290496477028). Reply with ONLY the SKU code, nothing else. If you cannot find it, reply with: NONE'

            ]

        ]

    ]]

]);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');

curl_setopt_array($ch, [

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_POST           => true,

    CURLOPT_HTTPHEADER     => [

        'Content-Type: application/json',

        'Authorization: Bearer ' . $GROQ_API_KEY,

    ],

    CURLOPT_POSTFIELDS     => $payload,

    CURLOPT_TIMEOUT        => 15,

]);

$response = curl_exec($ch);

$curlErr  = curl_error($ch);

curl_close($ch);

if ($curlErr) {

    echo json_encode(['sku' => null, 'error' => 'curl: ' . $curlErr]);

    exit;

}

$data = json_decode($response, true);

$raw  = trim($data['choices'][0]['message']['content'] ?? '');

// Extract SKU from response

$sku = null;

if (preg_match('/sk[a-z0-9_\-]{6,25}/i', $raw, $m)) {

    $sku = strtoupper($m[0]);

}

echo json_encode([

    'sku'      => $sku,

    'raw_text' => $raw,

]);