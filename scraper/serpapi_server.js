/**
 * serpapi_server.js  (v3 — fixed URL matching)
 *
 * The previous versions required the link to contain "shein.com" exactly,
 * which caused misses for regional domains (nz.shein.com, m.shein.com etc.)
 * This version accepts any shein subdomain and picks the best result.
 */

'use strict';

const http  = require('http');
const https = require('https');
const url   = require('url');
const fs    = require('fs');
const path  = require('path');

// ─── Load .env ────────────────────────────────────────────────────────────────
const envPath = path.join(__dirname, '..', '.env');
if (fs.existsSync(envPath)) {
  const lines = fs.readFileSync(envPath, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) continue;
    const key = trimmed.slice(0, eq).trim();
    const val = trimmed.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    if (key && !(key in process.env)) process.env[key] = val;
  }
  console.log('[env] Loaded .env from', envPath);
}

const PORT        = parseInt(process.env.SERPAPI_PORT || '3579', 10);
const SERPAPI_KEY = process.env.SERPAPI_KEY || '';

if (!SERPAPI_KEY) {
  console.error('[ERROR] SERPAPI_KEY not set. Add it to .env');
  process.exit(1);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function normalizeSku(raw) {
  return String(raw || '')
    .trim()
    .replace(/^(SKU|SHEIN\s*SKU)\s*[::#\-]?\s*/i, '')
    .replace(/[^A-Za-z0-9_-]/g, '')
    .toUpperCase();
}

/** Returns true for any shein domain: us.shein.com, nz.shein.com, m.shein.com, shein.com … */
function isSheinUrl(link) {
  return /shein\.com/i.test(link);
}

// ─── SerpAPI call ─────────────────────────────────────────────────────────────

function serpSearch(query) {
  return new Promise((resolve, reject) => {
    const params = new URLSearchParams({
      engine:  'google',
      q:       query,
      api_key: SERPAPI_KEY,
      num:     '10',
      hl:      'en',
      gl:      'us',
    });
    console.log(`  [serpapi] "${query}"`);
    https.get(`https://serpapi.com/search.json?${params}`, res => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch { reject(new Error('SerpAPI returned invalid JSON')); }
      });
    }).on('error', e => reject(new Error('SerpAPI request failed: ' + e.message)));
  });
}

// ─── Pick best result ─────────────────────────────────────────────────────────

function pickBest(sku, organicResults) {
  const skuLower = sku.toLowerCase();
  let best = null, bestScore = -1;

  for (const r of organicResults) {
    const link    = (r.link    || '').toLowerCase();
    const snippet = (r.snippet || '').toLowerCase();
    const title   = (r.title   || '').toLowerCase();

    // Must be a SHEIN page — skip anything else
    if (!isSheinUrl(link)) continue;

    let score = 0;
    score += link.includes(skuLower)    ? 4 : 0;
    score += snippet.includes(skuLower) ? 3 : 0;
    score += title.includes(skuLower)   ? 2 : 0;
    // Prefer us.shein.com over regional sites
    score += link.includes('us.shein.com') ? 1 : 0;

    if (score > bestScore) { bestScore = score; best = r; }
  }

  // Fallback: if no shein result scored, just take the first shein result
  if (!best) {
    best = organicResults.find(r => isSheinUrl(r.link || '')) || null;
  }

  return best;
}

// ─── Main lookup — two query strategies ──────────────────────────────────────

async function findProduct(sku) {
  const queries = [
    `${sku} shein`,                  // broad — works best (confirmed by test)
    `${sku} site:shein.com`,         // site-limited fallback
  ];

  for (const query of queries) {
    let serpData;
    try { serpData = await serpSearch(query); }
    catch (e) { console.warn('  [warn]', e.message); continue; }

    if (serpData.error) throw new Error('SerpAPI: ' + serpData.error);

    const results = serpData.organic_results || [];
    console.log(`  [serpapi] ${results.length} results`);
    if (!results.length) continue;

    const pick = pickBest(sku, results);
    if (pick) {
      console.log(`  [pick] ${pick.title} — ${pick.link}`);
      return {
        sku,
        title:   pick.title   || `SHEIN SKU ${sku}`,
        url:     pick.link    || '',
        snippet: pick.snippet || '',
      };
    }
  }
  return null;
}

// ─── HTTP helpers ─────────────────────────────────────────────────────────────

function readBody(req) {
  return new Promise(resolve => {
    let b = '';
    req.on('data', c => b += c);
    req.on('end', () => resolve(b));
    req.on('error', () => resolve(''));
  });
}

function parseForm(body) {
  const out = {};
  for (const pair of body.split('&')) {
    const [k, v] = pair.split('=');
    if (k) out[decodeURIComponent(k)] = decodeURIComponent((v || '').replace(/\+/g, ' '));
  }
  return out;
}

// ─── HTTP Server ──────────────────────────────────────────────────────────────

const server = http.createServer(async (req, res) => {
  const parsed   = url.parse(req.url, true);
  const pathname = parsed.pathname;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');

  // GET /health
  if (req.method === 'GET' && pathname === '/health') {
    res.writeHead(200);
    res.end(JSON.stringify({ status: 'ok', service: 'serpapi', port: PORT }));
    return;
  }

  // POST /scrape  ← called by PHP
  if (req.method === 'POST' && pathname === '/scrape') {
    try {
      const form = parseForm(await readBody(req));
      const sku  = normalizeSku(form.sku || '');

      if (!sku) {
        res.writeHead(400);
        res.end(JSON.stringify({ success: false, error: 'يرجى إدخال SKU صالح' }));
        return;
      }

      console.log(`\n[scrape] SKU: ${sku}`);
      const result = await findProduct(sku);

      if (!result) {
        res.writeHead(404);
        res.end(JSON.stringify({
          success: false,
          error: `لم يتم العثور على نتائج لـ SKU: ${sku}`,
        }));
        return;
      }

      res.writeHead(200);
      res.end(JSON.stringify({
        success: true,
        sku:     result.sku,
        title:   result.title,
        url:     result.url,
        image:   '',
        price:   '',
        snippet: result.snippet,
      }));

    } catch (err) {
      console.error('[scrape] Error:', err.message);
      res.writeHead(500);
      res.end(JSON.stringify({ success: false, error: err.message }));
    }
    return;
  }

  res.writeHead(404);
  res.end(JSON.stringify({ success: false, error: 'Not found' }));
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`\n✅ SerpAPI Server v3 — http://127.0.0.1:${PORT}`);
  console.log(`   Key: ${SERPAPI_KEY.slice(0, 8)}...`);
  console.log(`   POST /scrape  |  GET /health\n`);
});

server.on('error', err => {
  if (err.code === 'EADDRINUSE')
    console.error(`[ERROR] Port ${PORT} busy — run: taskkill /f /im node.exe`);
  else
    console.error('[ERROR]', err.message);
  process.exit(1);
});
