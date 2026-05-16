<?php
/**
 * serpapi_lookup.php
 *
 * Pure-PHP SerpAPI product lookup — replaces the Node.js serpapi_server.js.
 * Requires: SERPAPI_KEY in .env  OR  define('SERPAPI_KEY', '...')
 *
 * Usage:
 *   $result = serpapi_find_product($sku);
 *   // Returns: ['sku', 'title', 'url', 'image', 'price', 'snippet'] or null
 */

// ─── Load .env if not already loaded ─────────────────────────────────────────
if (!defined('SERPAPI_KEY_LOADED')) {
    define('SERPAPI_KEY_LOADED', true);
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $val = trim($val, "\"'");
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function serpapi_get_key(): string
{
    return defined('SERPAPI_KEY')
        ? SERPAPI_KEY
        : ($_ENV['SERPAPI_KEY'] ?? getenv('SERPAPI_KEY') ?? '');
}

function serpapi_is_shein_url(string $url): bool
{
    return (bool) preg_match('/shein\.com/i', $url);
}

/**
 * Call the SerpAPI Google search endpoint via cURL.
 */
function serpapi_search(string $query): ?array
{
    $key = serpapi_get_key();
    if ($key === '') return null;

    $params = http_build_query([
        'engine'  => 'google',
        'q'       => $query,
        'api_key' => $key,
        'num'     => '10',
        'hl'      => 'en',
        'gl'      => 'us',
    ]);

    $ch = curl_init("https://serpapi.com/search.json?{$params}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body   = curl_exec($ch);
    $err    = curl_error($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $err) return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    return $data;
}

/**
 * Score and pick the best SHEIN result from organic results.
 */
function serpapi_pick_best(string $sku, array $organicResults): ?array
{
    $skuLower = strtolower($sku);
    $best     = null;
    $bestScore = -1;

    foreach ($organicResults as $r) {
        $link    = strtolower($r['link']    ?? '');
        $snippet = strtolower($r['snippet'] ?? '');
        $title   = strtolower($r['title']   ?? '');

        if (!serpapi_is_shein_url($link)) continue;

        $score  = str_contains($link,    $skuLower) ? 4 : 0;
        $score += str_contains($snippet, $skuLower) ? 3 : 0;
        $score += str_contains($title,   $skuLower) ? 2 : 0;
        $score += str_contains($link, 'us.shein.com') ? 1 : 0;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }

    // fallback: first shein result regardless of score
    if (!$best) {
        foreach ($organicResults as $r) {
            if (serpapi_is_shein_url($r['link'] ?? '')) { $best = $r; break; }
        }
    }

    return $best;
}

/**
 * Main public function: find a product by SHEIN SKU.
 *
 * Returns an array with keys: sku, title, url, image, price, snippet
 * Returns null if nothing found or API key missing.
 */
function serpapi_find_product(string $sku): ?array
{
    $sku = strtoupper(trim($sku));
    if ($sku === '') return null;

    $queries = [
        $sku . ' shein',
        $sku . ' site:shein.com',
    ];

    foreach ($queries as $query) {
        $data = serpapi_search($query);
        if (!$data) continue;
        if (!empty($data['error'])) continue;

        $organic = $data['organic_results'] ?? [];
        if (empty($organic)) continue;

        $pick = serpapi_pick_best($sku, $organic);
        if (!$pick) continue;

        // Try to grab a thumbnail image from knowledge_graph or inline_images
        $image = '';
        if (!empty($data['knowledge_graph']['image'])) {
            $image = $data['knowledge_graph']['image'];
        } elseif (!empty($data['inline_images'][0]['original'])) {
            $image = $data['inline_images'][0]['original'];
        } elseif (!empty($pick['thumbnail'])) {
            $image = $pick['thumbnail'];
        }

        return [
            'sku'     => $sku,
            'title'   => $pick['title']   ?? "SHEIN SKU $sku",
            'url'     => $pick['link']    ?? '',
            'image'   => $image,
            'price'   => '',
            'snippet' => $pick['snippet'] ?? '',
        ];
    }

    return null;
}
