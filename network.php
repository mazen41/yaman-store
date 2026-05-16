<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'غير مصرح']);
    exit();
}

function fetchUrl(string $url, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
        ], $extraHeaders),
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'error' => $error ?: null, 'body' => $body ?: ''];
}

$sku     = trim($_GET['sku'] ?? 'sk2410290496477028');
$results = [];

// ── Strategy 1: Google ────────────────────────────────────────────────────────
$google = fetchUrl('https://www.google.com/search?' . http_build_query([
    'q'   => $sku . ' site:us.shein.com',
    'num' => 3,
    'hl'  => 'en',
]));
$results['google'] = ['status' => $google['status'], 'error' => $google['error']];
if ($google['status'] === 200) {
    // Shein link
    preg_match('/<a[^>]+href="(https:\/\/us\.shein\.com\/[^"&]+)"/i', $google['body'], $lm);
    // Title near that link — look for any h3
    preg_match_all('/<h3[^>]*>(.*?)<\/h3>/is', $google['body'], $hm);
    $name = null;
    foreach (($hm[1] ?? []) as $h) {
        $t = trim(strip_tags($h));
        if (strlen($t) > 8) { $name = $t; break; }
    }
    $results['google']['link']    = $lm[1] ?? null;
    $results['google']['name']    = $name;
    $results['google']['snippet'] = substr(strip_tags($google['body']), 200, 600);
}

// ── Strategy 2: Bing ──────────────────────────────────────────────────────────
$bing = fetchUrl('https://www.bing.com/search?' . http_build_query([
    'q' => $sku . ' site:us.shein.com',
]));
$results['bing'] = ['status' => $bing['status'], 'error' => $bing['error']];
if ($bing['status'] === 200) {
    preg_match('/<a[^>]+href="(https:\/\/us\.shein\.com\/[^"]+)"[^>]*>(.*?)<\/a>/is', $bing['body'], $bm);
    $results['bing']['link']    = $bm[1] ?? null;
    $results['bing']['name']    = isset($bm[2]) ? trim(strip_tags($bm[2])) : null;
    $results['bing']['snippet'] = substr(strip_tags($bing['body']), 200, 600);
}

// ── Strategy 3: DuckDuckGo HTML ───────────────────────────────────────────────
$ddg = fetchUrl('https://html.duckduckgo.com/html/?' . http_build_query([
    'q' => $sku . ' site:us.shein.com',
]));
$results['ddg'] = ['status' => $ddg['status'], 'error' => $ddg['error']];
if ($ddg['status'] === 200) {
    preg_match('/<a[^>]+class="result__a"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $ddg['body'], $dm);
    $results['ddg']['link']    = $dm[1] ?? null;
    $results['ddg']['name']    = isset($dm[2]) ? trim(strip_tags($dm[2])) : null;
    $results['ddg']['snippet'] = substr(strip_tags($ddg['body']), 200, 600);
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);