# SHEIN Playwright Scraper — Setup Guide

## One-time setup (< 5 minutes)

### 1. Install Node.js (if not already installed)
Download from https://nodejs.org/ (LTS version)

### 2. Open a terminal in this folder
```
cd C:\xampp\htdocs\yaman\scraper
```

### 3. Run the startup script (auto-installs everything)
```
start.bat
```
Or manually:
```
npm install
npx playwright install chromium
node server.js
```

The scraper listens on **http://127.0.0.1:3579**

---

## How it works

```
User types SKU
     ↓
create.php frontend (JS)
     ↓
ajax/fetch_shein_product.php
     ↓
1st try → POST http://127.0.0.1:3579/scrape  (Playwright — returns title+price+image+url)
2nd try → Google Custom Search API           (fallback — returns title+image+url, no price)
     ↓
JSON response → populate form fields
```

---

## API Reference

### POST /scrape
Body: `sku=SK2410290496477028`

**Success:**
```json
{
  "success": true,
  "sku": "SK2410290496477028",
  "title": "SHEIN Product Name",
  "price": "$12.99",
  "image": "https://img.shein.com/...",
  "url": "https://us.shein.com/..."
}
```

**Failure:**
```json
{
  "success": false,
  "error": "لم يتم العثور على منتج بهذا الـ SKU"
}
```

### GET /ping
Returns `{"ok": true}` — used by PHP to check if service is alive.

---

## Run as Windows Service (optional, for auto-start)

Using NSSM (https://nssm.cc/):
```
nssm install SheinScraper "C:\Program Files\nodejs\node.exe" "C:\xampp\htdocs\yaman\scraper\server.js"
nssm set SheinScraper AppDirectory "C:\xampp\htdocs\yaman\scraper"
nssm start SheinScraper
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Browser init failed" | Run `npx playwright install chromium` |
| "لم يتم العثور على منتج" | SHEIN may have changed markup; Google CSE fallback will activate |
| PHP can't reach scraper | Ensure `node server.js` is running; check firewall |
| Price is empty | Playwright blocked by SHEIN; Google CSE will be used (no price) |
