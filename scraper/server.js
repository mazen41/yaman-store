/**
 * SHEIN Playwright Scraper — Local HTTP API
 * Listens on http://127.0.0.1:3579
 *
 * SKU format:  SK + YYMMDD + goodsId
 * e.g.         SK2410290496477028
 *              → date: 241029 (2024-10-29)
 *              → goodsId: 0496477028
 */

'use strict';

const http         = require('http');
const { URL }      = require('url');
const { chromium } = require('playwright');

const PORT            = 3579;
const HOST            = '127.0.0.1';
const BROWSER_HEADLESS = true;

let _browser = null;

async function getBrowser() {
  if (_browser && _browser.isConnected()) return _browser;
  _browser = await chromium.launch({
    headless: BROWSER_HEADLESS,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
      '--disable-dev-shm-usage',
      '--lang=en-US,en',
    ],
  });
  _browser.on('disconnected', () => { _browser = null; });
  return _browser;
}

function normalizeSku(raw) {
  if (!raw || typeof raw !== 'string') return '';
  let s = raw.trim().replace(/^(SKU|SHEIN\s*SKU)\s*[::#-]?\s*/i, '');
  s = s.replace(/[^A-Za-z0-9_-]/g, '');
  return s.toUpperCase();
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

/**
 * Decode SHEIN SKU into possible goods IDs.
 * Format: SK + YYMMDD + goodsId
 * SK2410290496477028 → digits=2410290496477028
 *   first 6 = date (241029), rest = goodsId (0496477028)
 */
function getGoodsIds(sku) {
  const digits = sku.replace(/[^0-9]/g, '');
  const ids = new Set();

  if (digits.length > 6) {
    // Primary: skip first 6 (date), rest is goods_id
    ids.add(digits.slice(6));
    // With leading zero stripped
    ids.add(digits.slice(6).replace(/^0+/, ''));
  }

  // Fallbacks: last N digits
  [9, 10, 8, 11].forEach(n => {
    if (digits.length >= n) {
      ids.add(digits.slice(-n));
      ids.add(digits.slice(-n).replace(/^0+/, ''));
    }
  });

  return [...ids].filter(Boolean);
}

// ── Core scraper ──────────────────────────────────────────────────────────────
async function scrapeShein(sku) {
  const browser = await getBrowser();
  const context = await browser.newContext({
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' +
      'AppleWebKit/537.36 (KHTML, like Gecko) ' +
      'Chrome/124.0.0.0 Safari/537.36',
    locale:     'en-US',
    timezoneId: 'America/New_York',
    viewport:   { width: 1440, height: 900 },
    extraHTTPHeaders: { 'Accept-Language': 'en-US,en;q=0.9' },
  });

  // Intercept JSON responses from SHEIN's internal APIs
  let intercepted = null;
  const interceptedResponses = [];

  await context.route('**/*', async (route) => {
    const type = route.request().resourceType();
    if (['image', 'media', 'font'].includes(type)) return route.abort();

    const url = route.request().url();
    const isApi = url.includes('/api/') || url.includes('itemv2') ||
                  url.includes('goods_detail') || url.includes('search') ||
                  url.includes('product');

    if (isApi) {
      try {
        const response = await route.fetch();
        const text = await response.text().catch(() => '');
        if (text && text[0] === '{' || text[0] === '[') {
          try {
            const json = JSON.parse(text);
            interceptedResponses.push(json);
          } catch {}
        }
        return route.fulfill({ response });
      } catch {
        return route.continue();
      }
    }

    return route.continue();
  });

  const page = await context.newPage();

  // Helper: check intercepted API data
  function checkIntercepted() {
    for (const json of interceptedResponses) {
      const found = findInJson(json, sku, 0);
      if (found) return found;
    }
    return null;
  }

  try {

    // ── ATTEMPT 1: Direct product URL (most reliable if we know goods_id) ────
    const goodsIds = getGoodsIds(sku);
    console.log(`  Possible goods IDs: ${goodsIds.join(', ')}`);

    for (const gid of goodsIds) {
      // SHEIN product URL patterns
      const urls = [
        `https://us.shein.com/p-${gid}.html`,
        `https://us.shein.com/product-p-${gid}.html`,
      ];

      for (const directUrl of urls) {
        try {
          console.log(`  → ${directUrl}`);
          const resp = await page.goto(directUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
          const finalUrl = page.url();

          // If we got redirected to search or 404, skip
          if (finalUrl.includes('pdsearch') || finalUrl.includes('404') || (resp && resp.status() === 404)) {
            console.log(`    Redirected to: ${finalUrl} — skipping`);
            continue;
          }

          if (resp && resp.status() === 200) {
            await sleep(2000);

            // Check intercepted API data
            const api = checkIntercepted();
            if (api) { await context.close(); return api; }

            // Try DOM extraction from product page
            const product = await extractProductPage(page, sku, finalUrl);
            if (product && product.title) {
              await context.close();
              return product;
            }
          }
        } catch (e) {
          console.log(`    Error: ${e.message}`);
        }
      }
    }

    // ── ATTEMPT 2: SHEIN search page ─────────────────────────────────────────
    // Try search with full SKU and also without "SK" prefix
    const searchTerms = [sku, sku.replace(/^SK/i, '')];

    for (const term of searchTerms) {
      try {
        const searchUrl = `https://us.shein.com/pdsearch/${encodeURIComponent(term)}/`;
        console.log(`  → Search: ${searchUrl}`);
        await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 25000 });
        await sleep(3000);

        // Check API intercepts
        const api = checkIntercepted();
        if (api) { await context.close(); return api; }

        // Check page globals
        const fromGlobal = await extractFromGlobals(page, sku);
        if (fromGlobal) { await context.close(); return fromGlobal; }

        // Parse first result card
        const card = await extractFirstCard(page, sku);
        if (card && card.title) { await context.close(); return card; }

      } catch (e) {
        console.log(`    Search error: ${e.message}`);
      }
    }

    // ── ATTEMPT 3: JSON-LD structured data ────────────────────────────────────
    const jsonLd = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('script[type="application/ld+json"]'))
        .map(s => { try { return JSON.parse(s.textContent); } catch { return null; } })
        .filter(Boolean);
    }).catch(() => []);

    for (const schema of jsonLd) {
      const items = Array.isArray(schema) ? schema : [schema];
      for (const item of items) {
        if (item.name && (item['@type'] === 'Product' || item.offers)) {
          await context.close();
          return {
            sku,
            title: item.name.trim(),
            price: String(item.offers?.price || item.offers?.lowPrice || ''),
            image: Array.isArray(item.image) ? item.image[0] : (item.image || ''),
            url:   item.url || page.url(),
          };
        }
      }
    }

    // ── ATTEMPT 4: Any visible product card on the page ───────────────────────
    const anyCard = await page.evaluate(() => {
      const a = document.querySelector('a[href*="/p-"], a[href*="-p-"]');
      if (!a) return null;
      const img  = a.querySelector('img') || document.querySelector('img[src*="shein"]');
      const name = a.querySelector('[class*="name"],[class*="title"],span,p');
      return {
        url:   a.href,
        image: img?.src || img?.dataset?.src || '',
        title: name?.textContent?.trim() || a.textContent?.trim() || '',
      };
    }).catch(() => null);

    if (anyCard && anyCard.url) {
      await context.close();
      return { sku, title: anyCard.title || `SHEIN ${sku}`, price: '', image: anyCard.image, url: anyCard.url };
    }

    throw new Error(`لم يتم العثور على منتج بـ SKU: ${sku}`);

  } finally {
    await context.close().catch(() => {});
  }
}

// ── Extract from page globals ─────────────────────────────────────────────────
async function extractFromGlobals(page, sku) {
  return page.evaluate((skuVal) => {
    function walk(obj, depth) {
      if (depth > 10 || !obj || typeof obj !== 'object') return null;
      const sn = String(obj.goods_sn || obj.goodsSn || obj.sku || obj.skuCode || '').toUpperCase();
      if (sn && sn === skuVal) return obj;
      for (const v of Object.values(obj)) {
        const r = walk(v, depth + 1);
        if (r) return r;
      }
      return null;
    }

    const sources = [window.__gb_data__, window._gb_app_data, window.__NEXT_DATA__, window.__NUXT__];
    for (const src of sources) {
      if (!src) continue;
      const r = walk(src, 0);
      if (r) {
        const title = r.goods_name || r.goodsName || r.name || r.title || '';
        const price = String(r.salePrice?.amount || r.retailPrice?.amount || r.salePrice || r.price || '');
        const image = r.goods_img || r.goodsImg || r.thumbnail || r.mainImage?.url || '';
        const url   = r.goods_url_name || r.detail_url || r.url || '';
        if (title) return { sku: skuVal, title, price, image, url };
      }
    }
    return null;
  }, sku).catch(() => null);
}

// ── Extract first product card from search results ────────────────────────────
async function extractFirstCard(page, sku) {
  const selectors = [
    '[class*="product-card"]',
    '[class*="product-item"]',
    '[class*="goods-item"]',
    '[class*="product-list"] li',
    '[data-expose-id]',
    '.j-common-product-item',
    '.S-product-item',
  ];

  for (const sel of selectors) {
    try {
      const count = await page.$$(sel).then(e => e.length).catch(() => 0);
      if (!count) continue;

      const data = await page.evaluate((s) => {
        const card = document.querySelector(s);
        if (!card) return null;
        const link  = card.querySelector('a');
        const img   = card.querySelector('img');
        const nameEl = card.querySelector(
          '[class*="name"],[class*="title"],[class*="goods-name"],[class*="product-title"],h3,h2,p'
        );
        const priceEl = card.querySelector('[class*="price"],[class*="sale"],strong');
        return {
          title: nameEl?.textContent?.trim() || link?.textContent?.trim() || '',
          price: priceEl?.textContent?.trim()?.match(/[\$£€]?\s*\d[\d,.]+/)?.[0] || '',
          image: img?.src || img?.dataset?.src || '',
          url:   link?.href || '',
        };
      }, sel);

      if (data && (data.title || data.url)) {
        return { sku, ...data };
      }
    } catch {}
  }
  return null;
}

// ── Extract from product detail page ─────────────────────────────────────────
async function extractProductPage(page, sku, url) {
  try {
    await page.waitForSelector('h1, [class*="product-name"], [class*="goods-name"]', { timeout: 8000 }).catch(() => null);

    return await page.evaluate((skuVal, pageUrl) => {
      const titleEl = document.querySelector(
        'h1, [class*="product-intro__head-name"], [class*="product-name"], [class*="goods-name"]'
      );
      const priceEl = document.querySelector(
        '[class*="product-intro__head-price"], [class*="price-new"], [class*="sale-price"], [class*="Price"], .price'
      );
      const imgEl = document.querySelector(
        '.product-intro__main-img img, [class*="product-image"] img, [class*="main-image"] img, .crop-image-container img'
      );

      const title = titleEl?.textContent?.trim();
      if (!title) return null;

      return {
        sku:   skuVal,
        title,
        price: priceEl?.textContent?.trim()?.match(/[\$£€]?\s*\d[\d,.]+/)?.[0] || '',
        image: imgEl?.src || imgEl?.dataset?.src || '',
        url:   pageUrl,
      };
    }, sku, url);
  } catch {
    return null;
  }
}

// ── Walk JSON tree looking for matching SKU ───────────────────────────────────
function findInJson(obj, sku, depth) {
  if (depth > 12 || !obj) return null;
  if (typeof obj === 'object' && !Array.isArray(obj)) {
    const sn = String(obj.goods_sn || obj.goodsSn || obj.sku || obj.skuCode || '').toUpperCase();
    if (sn && sn === sku) {
      const title = (obj.goods_name || obj.goodsName || obj.name || obj.title || '').trim();
      if (!title) return null;
      return {
        sku,
        title,
        price: String(obj.salePrice?.amount || obj.retailPrice?.amount || obj.price || ''),
        image: resolveUrl(obj.goods_img || obj.goodsImg || obj.thumbnail || obj.mainImage?.url || ''),
        url:   resolveUrl(obj.goods_url_name || obj.detail_url || obj.url || ''),
      };
    }
    for (const v of Object.values(obj)) {
      const r = findInJson(v, sku, depth + 1);
      if (r) return r;
    }
  } else if (Array.isArray(obj)) {
    for (const item of obj) {
      const r = findInJson(item, sku, depth + 1);
      if (r) return r;
    }
  }
  return null;
}

function resolveUrl(u) {
  if (!u) return '';
  if (u.startsWith('//')) return 'https:' + u;
  if (u.startsWith('/'))  return 'https://us.shein.com' + u;
  return u;
}

// ── HTTP Server ───────────────────────────────────────────────────────────────
async function parseBody(req) {
  return new Promise((res, rej) => {
    const chunks = [];
    req.on('data', c => chunks.push(c));
    req.on('end',  () => res(Buffer.concat(chunks).toString('utf-8')));
    req.on('error', rej);
  });
}

function parseFormBody(body) {
  const out = {};
  for (const pair of body.split('&')) {
    const [k, v] = pair.split('=');
    if (k) out[decodeURIComponent(k.replace(/\+/g, ' '))] = decodeURIComponent((v||'').replace(/\+/g,' '));
  }
  return out;
}

function sendJson(res, code, data) {
  const body = JSON.stringify(data, null, 2);
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

const server = http.createServer(async (req, res) => {
  const parsed = new URL(req.url, `http://${req.headers.host}`);
  const path   = parsed.pathname;

  if (req.method === 'OPTIONS') { res.writeHead(204); return res.end(); }
  if (path === '/ping') return sendJson(res, 200, { ok: true });

  if (path === '/scrape') {
    let rawSku = '';
    if (req.method === 'GET') {
      rawSku = parsed.searchParams.get('sku') || '';
    } else {
      const body = await parseBody(req);
      const ct   = (req.headers['content-type'] || '').toLowerCase();
      rawSku = ct.includes('json') ? (JSON.parse(body).sku || '') : (parseFormBody(body).sku || '');
    }

    const sku = normalizeSku(rawSku);
    if (!sku) return sendJson(res, 400, { success: false, error: 'sku مطلوب' });

    console.log(`\n[${new Date().toISOString()}] ▶ Scraping: ${sku}`);
    try {
      const product = await scrapeShein(sku);
      console.log(`[${new Date().toISOString()}] ✓ ${product.title}`);
      return sendJson(res, 200, { success: true, ...product });
    } catch (err) {
      console.error(`[${new Date().toISOString()}] ✗ ${err.message}`);
      return sendJson(res, 422, { success: false, error: err.message });
    }
  }

  sendJson(res, 404, { error: 'Not found' });
});

async function main() {
  console.log('Warming up Playwright browser...');
  await getBrowser();
  console.log('Browser ready.');
  server.listen(PORT, HOST, () => {
    console.log(`SHEIN scraper API → http://${HOST}:${PORT}`);
    console.log(`  POST /scrape   body: sku=SK2410290496477028`);
    console.log(`  GET  /ping`);
  });
}

process.on('SIGTERM', async () => { if (_browser) await _browser.close(); process.exit(0); });
process.on('SIGINT',  async () => { if (_browser) await _browser.close(); process.exit(0); });

main().catch(err => { console.error('Fatal:', err); process.exit(1); });
