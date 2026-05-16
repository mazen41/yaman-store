<?php
/**
 * modules/sorting/index.php  — إدارة الفرز
 *
 * Pure-PHP SerpAPI backend (Node.js / .bat scraper fully removed).
 * Scanner-like warehouse workflow:  scan → auto-mark → auto-next.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/check_permissions.php';
require_once '../../includes/shein_helpers.php';

$user_id = $_SESSION['user_id'] ?? 0;
if (!hasPermission($user_id, 'orders', 'view') && !hasPermission($user_id, 'orders', 'edit')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية لإدارة الفرز';
    header('Location: ../../index.php');
    exit();
}

sheinEnsureSchema($db);

$page_title = 'إدارة الفرز';
include '../../includes/header.php';
?>

<style>
:root {
  --sort-bg: #f8fafc;
  --sort-surface: #ffffff;
  --sort-card: #ffffff;
  --sort-border: #e5e7eb;
  --sort-border-strong: #d1d5db;
  --sort-accent: #3b82f6;
  --sort-accent2: #2563eb;
  --sort-accent-soft: #eff6ff;
  --sort-green: #10b981;
  --sort-green-soft: #ecfdf5;
  --sort-yellow: #f59e0b;
  --sort-yellow-soft: #fffbeb;
  --sort-red: #ef4444;
  --sort-text: #1f2937;
  --sort-muted: #6b7280;
  --sort-radius: 16px;
  --sort-shadow: 0 10px 25px rgba(15, 23, 42, .06);
  --sort-shadow-sm: 0 2px 8px rgba(15, 23, 42, .05);
  --sort-focus: 0 0 0 4px rgba(59, 130, 246, .12);
}

#sortApp { font-family: 'Tajawal', 'Segoe UI', system-ui, sans-serif; direction: rtl; background: var(--sort-bg); min-height: 100vh; padding: 0 0 80px; color: var(--sort-text); }
#sortApp * { box-sizing: border-box; }

.sort-header {
  background: linear-gradient(135deg, #ffffff 0%, #eff6ff 52%, #eef2ff 100%);
  padding: 24px 28px;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;
  border-bottom: 1px solid var(--sort-border);
  box-shadow: var(--sort-shadow-sm);
}
.sort-header h1 { font-size: 1.55rem; font-weight: 800; letter-spacing: -.02em; margin: 0; display: flex; align-items: center; gap: 12px; color: #111827; }
.sort-header h1 .icon-box { background: var(--sort-accent); color: #fff; border-radius: 14px; width: 44px; height: 44px; display: grid; place-items: center; font-size: 1.1rem; box-shadow: 0 8px 18px rgba(59, 130, 246, .24); }
.sort-stats { display: flex; gap: 10px; flex-wrap: wrap; }
.sort-stat-pill { background: rgba(255,255,255,.86); border: 1px solid var(--sort-border); border-radius: 999px; padding: 8px 14px; font-size: .8rem; font-weight: 700; color: var(--sort-text); display: flex; align-items: center; gap: 7px; box-shadow: var(--sort-shadow-sm); }
.sort-stat-pill .dot { width: 8px; height: 8px; border-radius: 50%; }

.sort-layout { display: grid; grid-template-columns: minmax(320px, 390px) 1fr; gap: 22px; padding: 24px 28px; max-width: 1420px; margin: 0 auto; }
@media (max-width: 900px) { .sort-layout { grid-template-columns: 1fr; padding: 16px; } .sort-header { padding: 18px 16px; } }

.s-card { background: var(--sort-card); border: 1px solid var(--sort-border); border-radius: var(--sort-radius); overflow: hidden; box-shadow: var(--sort-shadow-sm); }
.s-card-head { padding: 15px 18px; border-bottom: 1px solid var(--sort-border); display: flex; align-items: center; justify-content: space-between; gap: 10px; background: linear-gradient(180deg, #fff, #f9fafb); }
.s-card-head h3 { margin: 0; font-size: .96rem; font-weight: 800; display: flex; align-items: center; gap: 8px; color: var(--sort-text); }
.s-card-body { padding: 18px; }

.scan-input-wrap { position: relative; }
.scan-input {
  width: 100%; background: #fff; border: 2px solid var(--sort-border);
  border-radius: 14px; padding: 16px 52px 16px 18px;
  font-size: 1.02rem; color: var(--sort-text); font-family: 'Courier New', monospace;
  direction: ltr; text-align: left; letter-spacing: .05em;
  transition: border-color .2s, box-shadow .2s, background .2s; outline: none;
}
.scan-input:focus { border-color: var(--sort-accent); box-shadow: var(--sort-focus); background: #fff; }
.scan-input::placeholder { color: #9ca3af; letter-spacing: 0; font-family: 'Tajawal', sans-serif; direction: rtl; }
.scan-input-icon { position: absolute; left: 17px; top: 50%; transform: translateY(-50%); color: var(--sort-accent); font-size: 1.15rem; pointer-events: none; }

.sort-spinner { display: none; align-items: center; gap: 10px; font-size: .86rem; color: var(--sort-accent2); padding: 8px 0; }
.sort-spinner .ring { width: 20px; height: 20px; border: 2px solid #bfdbfe; border-top-color: var(--sort-accent); border-radius: 50%; animation: spin .7s linear infinite; flex-shrink: 0; }
@keyframes spin { to { transform: rotate(360deg); } }

.s-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 18px; border-radius: 12px; font-size: .88rem; font-weight: 800; border: 1px solid transparent; cursor: pointer; transition: all .18s; text-decoration: none; }
.s-btn-primary { background: var(--sort-accent); color: #fff; box-shadow: 0 8px 18px rgba(59, 130, 246, .2); }
.s-btn-primary:hover { background: var(--sort-accent2); transform: translateY(-1px); }
.s-btn-green { background: var(--sort-green); color: #fff; box-shadow: 0 8px 18px rgba(16, 185, 129, .18); }
.s-btn-muted { background: #f3f4f6; color: #374151; border-color: var(--sort-border); }
.s-btn-muted:hover { background: #e5e7eb; color: #111827; }
.s-btn-sm { padding: 7px 12px; font-size: .78rem; }
.s-btn-full { width: 100%; }

#scannerVideo { width: 100%; border-radius: 14px; background: #111827; min-height: 190px; border: 1px solid var(--sort-border); }
.cam-btns { display:flex; gap:8px; }

.sort-msg { border-radius: 14px; padding: 11px 13px; display: flex; gap: 9px; align-items: center; font-size: .84rem; font-weight: 700; border: 1px solid transparent; }
.sort-msg.success { background: var(--sort-green-soft); color: #047857; border-color: #a7f3d0; }
.sort-msg.warning { background: var(--sort-yellow-soft); color: #92400e; border-color: #fde68a; }
.sort-msg.error { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

.sort-empty { text-align: center; padding: 70px 20px; background: radial-gradient(circle at top, #eff6ff 0, #fff 42%); }
.sort-empty .icon { font-size: 4rem; color: #bfdbfe; display: block; margin-bottom: 16px; }
.sort-empty h3 { font-size: 1.15rem; color: var(--sort-text); margin: 0 0 6px; font-weight: 800; }
.sort-empty p { font-size: .88rem; color: var(--sort-muted); margin: 0; }

.prod-hero { display: flex; gap: 16px; align-items: flex-start; }
.prod-img { width: 112px; height: 112px; object-fit: cover; border-radius: 14px; border: 1px solid var(--sort-border); flex-shrink: 0; background: #f3f4f6; }
.prod-meta h2 { margin: 0 0 6px; font-size: 1.08rem; font-weight: 800; line-height: 1.45; color: var(--sort-text); }
.prod-sku { font-family: monospace; font-size: .8rem; color: var(--sort-muted); direction: ltr; display: block; margin-bottom: 10px; }
.prod-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.s-badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 999px; font-size: .74rem; font-weight: 800; border: 1px solid transparent; white-space: nowrap; }
.s-badge.scanned, .s-badge.done { background: var(--sort-green-soft); color: #047857; border-color: #a7f3d0; }
.s-badge.pending { background: var(--sort-yellow-soft); color: #92400e; border-color: #fde68a; }
.s-badge.warning { background: var(--sort-yellow-soft); color: #92400e; border-color: #fde68a; }
.s-badge.other { background: #f3f4f6; color: #4b5563; border-color: var(--sort-border); }

.order-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 10px; }
.o-cell { background: #f9fafb; border-radius: 12px; padding: 11px 12px; border: 1px solid var(--sort-border); }
.o-cell .lbl { font-size: .72rem; color: var(--sort-muted); margin-bottom: 5px; display: block; }
.o-cell .val { font-size: .92rem; font-weight: 800; color: var(--sort-text); display: block; }
.o-cell.accent .val { color: var(--sort-accent2); }
.o-cell.green .val { color: #047857; }
.o-cell.yellow .val { color: #92400e; }

.group-banner { background: var(--sort-accent-soft); border: 1px solid #bfdbfe; border-radius: 14px; padding: 12px 14px; display: flex; align-items: center; gap: 10px; }
.group-banner .icon { font-size: 1.2rem; }
.group-banner .info strong { display: block; font-size: .9rem; color: var(--sort-accent2); }
.group-banner .info span { font-size: .76rem; color: var(--sort-muted); }

.items-list { max-height: 340px; overflow-y: auto; background: #fff; }
.items-list::-webkit-scrollbar { width: 6px; }
.items-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
.item-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--sort-border); transition: background .15s, border-color .15s; }
.item-row:last-child { border-bottom: none; }
.item-row.current { background: var(--sort-accent-soft); border-right: 4px solid var(--sort-accent); box-shadow: inset 0 0 0 1px #bfdbfe; }
.item-row:hover { background: #f9fafb; }
.item-row .th { width: 38px; height: 38px; border-radius: 10px; object-fit: cover; background: #f3f4f6; flex-shrink: 0; border: 1px solid var(--sort-border); }
.item-row .nm { flex: 1; min-width: 0; }
.item-row .nm p { margin: 0; font-size: .84rem; font-weight: 700; color: var(--sort-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-row .nm small { color: var(--sort-muted); font-size: .71rem; font-family: monospace; direction: ltr; }

.sort-progress-wrap { background: #e5e7eb; border-radius: 999px; height: 8px; overflow: hidden; }
.sort-progress-bar { height: 100%; background: linear-gradient(90deg, var(--sort-accent), var(--sort-green)); border-radius: 999px; transition: width .5s ease; }
.next-preview { background: var(--sort-green-soft); border: 1px dashed #6ee7b7; border-radius: 14px; padding: 12px 14px; display: flex; align-items: center; gap: 10px; }
.next-preview .th { width: 38px; height: 38px; border-radius: 10px; object-fit: cover; background: #f3f4f6; flex-shrink: 0; }
.next-preview .info strong { display: block; font-size: .84rem; color: #047857; }
.next-preview .info small { font-size: .71rem; color: var(--sort-muted); font-family: monospace; direction: ltr; }

.all-done-banner { background: linear-gradient(135deg, #ecfdf5, #f0fdf4); border: 1px solid #a7f3d0; border-radius: 16px; padding: 20px; text-align: center; box-shadow: var(--sort-shadow-sm); animation: pulse .6s ease; }
@keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.01)} }
.all-done-banner .icon { font-size: 2.5rem; display: block; margin-bottom: 8px; }
.all-done-banner h3 { margin: 0 0 4px; color: #047857; font-size: 1.08rem; font-weight: 800; }
.all-done-banner p { margin: 0; color: #059669; font-size: .84rem; }

.sort-imgs { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 10px; }
.sort-imgs a img { width: 100%; height: 84px; object-fit: cover; border-radius: 12px; border: 1px solid var(--sort-border); transition: transform .2s, border-color .2s; }
.sort-imgs a img:hover { transform: scale(1.03); border-color: var(--sort-accent); }
.sort-view-link { color: var(--sort-accent2); font-size: .8rem; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: var(--sort-accent-soft); border: 1px solid #bfdbfe; border-radius: 999px; padding: 6px 11px; }
.sort-view-link:hover { background: #dbeafe; }
</style>


<!-- Load Tajawal font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700;800&display=swap" rel="stylesheet">

<div id="sortApp">

  <!-- ── Header ─────────────────────────────────────────────────────── -->
  <div class="sort-header">
    <h1>
      <span class="icon-box"><i class="fas fa-qrcode"></i></span>
      إدارة الفرز
    </h1>
    <div class="sort-stats">
      <div class="sort-stat-pill">
        <span class="dot" style="background:#22c55e"></span>
        <span id="hdrScanned">0</span> مفروز
      </div>
      <div class="sort-stat-pill">
        <span class="dot" style="background:#f59e0b"></span>
        <span id="hdrPending">0</span> متبقي
      </div>
      <div class="sort-stat-pill">
        <span class="dot" style="background:#6366f1"></span>
        الجلسة الحالية
      </div>
    </div>
  </div>

  <!-- ── Main Layout ────────────────────────────────────────────────── -->
  <div class="sort-layout">

    <!-- LEFT PANEL: Scanner + Input ──────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

      <!-- SKU Input card -->
      <div class="s-card">
        <div class="s-card-head">
          <h3><i class="fas fa-keyboard" style="color:#3b82f6"></i> إدخال SKU</h3>
          <span id="inputStatus" class="s-badge other" style="font-size:.7rem">جاهز</span>
        </div>
        <div class="s-card-body" style="display:flex;flex-direction:column;gap:12px;">
          <div class="scan-input-wrap">
            <input id="scanInput" type="text" class="scan-input"
                   placeholder="أدخل SKU أو امسح QR / باركود..."
                   autocomplete="off" autocorrect="off" spellcheck="false">
            <i class="fas fa-barcode scan-input-icon"></i>
          </div>

          <div id="sortSpinner" class="sort-spinner">
            <div class="ring"></div>
            <span>جاري البحث عن المنتج...</span>
          </div>

          <div id="sortMsg" style="display:none"></div>

          <div style="display:flex;gap:8px;">
            <button id="btnScan" type="button" class="s-btn s-btn-primary" style="flex:1;">
              <i class="fas fa-search"></i> بحث وفرز
            </button>
            <button id="btnClear" type="button" class="s-btn s-btn-muted s-btn-sm">
              <i class="fas fa-times"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Camera scanner card -->
      <div class="s-card">
        <div class="s-card-head">
          <h3><i class="fas fa-camera" style="color:#22c55e"></i> الماسح بالكاميرا</h3>
        </div>
        <div class="s-card-body" style="display:flex;flex-direction:column;gap:10px;">
          <video id="scannerVideo" playsinline muted></video>
          <div class="cam-btns">
            <button id="btnCamStart" type="button" class="s-btn s-btn-green" style="flex:1;font-size:.82rem;">
              <i class="fas fa-play"></i> تشغيل الكاميرا
            </button>
            <button id="btnCamStop" type="button" class="s-btn s-btn-muted" style="flex:1;font-size:.82rem;">
              <i class="fas fa-stop"></i> إيقاف
            </button>
          </div>
          <p style="font-size:.72rem;color:var(--sort-muted);text-align:center;margin:0;">وجّه الكاميرا نحو رمز QR أو الباركود</p>
        </div>
      </div>

      <!-- Next item preview -->
      <div id="nextPreviewWrap" style="display:none" class="s-card">
        <div class="s-card-head">
          <h3 style="color:#047857;"><i class="fas fa-forward"></i> المنتج التالي</h3>
        </div>
        <div class="s-card-body">
          <div id="nextPreview" class="next-preview">
            <img class="th" id="nextImg" src="" alt="">
            <div class="info">
              <strong id="nextName"></strong>
              <small id="nextSku"></small>
            </div>
          </div>
          <button id="btnAutoScanNext" type="button" class="s-btn s-btn-primary s-btn-full" style="margin-top:10px;">
            <i class="fas fa-bolt"></i> فرز التالي تلقائياً
          </button>
        </div>
      </div>

    </div>

    <!-- RIGHT PANEL: Result ──────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:16px;">

      <!-- Empty state -->
      <div id="emptyState">
        <div class="s-card">
          <div class="sort-empty">
            <span class="icon"><i class="fas fa-barcode"></i></span>
            <h3>بانتظار المسح</h3>
            <p>ستظهر تفاصيل المنتج والطلب هنا بعد الإدخال</p>
          </div>
        </div>
      </div>

      <!-- Result panels (hidden until scan) -->
      <div id="resultArea" style="display:none;flex-direction:column;gap:16px;">

        <!-- All-done banner -->
        <div id="allDoneBanner" style="display:none" class="all-done-banner">
          <span class="icon">🎉</span>
          <h3>تم الفرز بالكامل!</h3>
          <p>جميع منتجات هذا الطلب مفروزة</p>
        </div>

        <!-- ① Product card -->
        <div class="s-card">
          <div class="s-card-head">
            <h3>🛍️ المنتج الممسوح</h3>
            <span id="itemBadge" class="s-badge scanned"></span>
          </div>
          <div class="s-card-body">
            <div class="prod-hero">
              <img id="prodImg" class="prod-img" src="" alt="">
              <div class="prod-meta" style="flex:1;min-width:0;">
                <h2 id="prodName"></h2>
                <code class="prod-sku" id="prodSku"></code>
                <div class="prod-actions">
                  <a id="prodLink" href="#" target="_blank" class="s-btn s-btn-muted s-btn-sm" style="display:none;">
                    <i class="fas fa-external-link-alt"></i> فتح في SHEIN
                  </a>
                  <button id="btnUnscan" type="button" class="s-btn s-btn-muted s-btn-sm">
                    <i class="fas fa-undo"></i> إلغاء الفرز
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ② Order card -->
        <div class="s-card">
          <div class="s-card-head">
            <h3>📦 تفاصيل الطلب</h3>
            <a id="orderViewLink" href="#" target="_blank" class="sort-view-link">
              <i class="fas fa-eye"></i> عرض الطلب
            </a>
          </div>
          <div class="s-card-body" style="display:flex;flex-direction:column;gap:12px;">

            <!-- Group banner -->
            <div id="groupBanner" style="display:none" class="group-banner">
              <span class="icon">📁</span>
              <div class="info">
                <strong id="groupName"></strong>
                <span id="groupNumber"></span>
              </div>
            </div>

            <!-- Progress -->
            <div>
              <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                <span style="font-size:.78rem;color:var(--sort-muted);">تقدم الفرز</span>
                <span id="progressText" style="font-size:.78rem;font-weight:700;color:var(--sort-accent2);"></span>
              </div>
              <div class="sort-progress-wrap">
                <div id="progressBar" class="sort-progress-bar" style="width:0%"></div>
              </div>
            </div>

            <!-- Order grid -->
            <div class="order-grid">
              <div class="o-cell accent"><span class="lbl">رقم الطلب</span><span class="val" id="oNumber" style="direction:ltr;"></span></div>
              <div class="o-cell"><span class="lbl">العميل</span><span class="val" id="oCustomer"></span></div>
              <div class="o-cell"><span class="lbl">الجوال</span><span class="val" id="oMobile" style="direction:ltr;"></span></div>
              <div class="o-cell"><span class="lbl">الحالة</span><span class="val" id="oStatus"></span></div>
              <div class="o-cell"><span class="lbl">الشحن</span><span class="val" id="oShipping"></span></div>
              <div class="o-cell green"><span class="lbl">الإجمالي</span><span class="val" id="oTotal"></span></div>
              <div class="o-cell"><span class="lbl">تاريخ الطلب</span><span class="val" id="oDate"></span></div>
              <div class="o-cell yellow"><span class="lbl">مفروز / الكل</span><span class="val" id="oCounts"></span></div>
            </div>

            <!-- Order notes -->
            <div id="oNotesWrap" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:10px 12px;font-size:.82rem;color:#92400e;">
              <i class="fas fa-sticky-note" style="margin-left:6px;"></i><span id="oNotes"></span>
            </div>
          </div>
        </div>

        <!-- ③ All items -->
        <div class="s-card">
          <div class="s-card-head">
            <h3>📋 منتجات الطلب</h3>
          </div>
          <div id="itemsList" class="items-list"></div>
        </div>

        <!-- ④ Order images -->
        <div id="imagesCard" style="display:none" class="s-card">
          <div class="s-card-head"><h3>🖼️ صور الطلب</h3></div>
          <div class="s-card-body">
            <div id="imagesGrid" class="sort-imgs"></div>
          </div>
        </div>

      </div><!-- /resultArea -->
    </div>

  </div><!-- /sort-layout -->
</div><!-- /sortApp -->

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
// ══════════════════════════════════════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════════════════════════════════════
const state = {
  currentItemId : null,
  currentOrderId: null,
  sessionScanned: 0,
  sessionPending: 0,
  scanLock      : false,   // debounce rapid scans
  lastScanVal   : '',
  lastScanAt    : 0,
  camStream     : null,
  camTimer      : null,
  camOcrBusy    : false,
};

// ══════════════════════════════════════════════════════════════════════════════
// DOM refs
// ══════════════════════════════════════════════════════════════════════════════
const $ = id => document.getElementById(id);
const scanInput     = $('scanInput');
const spinner       = $('sortSpinner');
const msgEl         = $('sortMsg');
const inputStatus   = $('inputStatus');
const emptyState    = $('emptyState');
const resultArea    = $('resultArea');

// ══════════════════════════════════════════════════════════════════════════════
// SOUND FEEDBACK
// ══════════════════════════════════════════════════════════════════════════════
function beep(type) {
  try {
    const ctx  = new (window.AudioContext || window.webkitAudioContext)();
    const osc  = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    const freqs = { success: 880, warning: 550, error: 220 };
    osc.frequency.value = freqs[type] || 440;
    gain.gain.value = 0.06;
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(); setTimeout(() => { osc.stop(); ctx.close(); }, 160);
  } catch(e) {}
}

// ══════════════════════════════════════════════════════════════════════════════
// MESSAGE BANNER
// ══════════════════════════════════════════════════════════════════════════════
function showMsg(text, type = 'success') {
  msgEl.className = `sort-msg ${type}`;
  const icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle' };
  msgEl.innerHTML = `<i class="fas fa-${icons[type]||'info-circle'}"></i><span>${escH(text)}</span>`;
  msgEl.style.display = 'flex';
}
function hideMsg() { msgEl.style.display = 'none'; }

// ══════════════════════════════════════════════════════════════════════════════
// CORE SCAN FUNCTION
// ══════════════════════════════════════════════════════════════════════════════
async function doScan(value) {
  value = (value || '').trim();
  if (!value) { showMsg('يرجى إدخال SKU أو رابط صالح', 'error'); return; }

  // Debounce: prevent double-fire within 2.5s for same value
  const now = Date.now();
  if (value === state.lastScanVal && now - state.lastScanAt < 2500) return;
  if (state.scanLock) return;

  state.scanLock = true;
  state.lastScanVal = value;
  state.lastScanAt  = now;

  // UI: busy
  spinner.style.display = 'flex';
  hideMsg();
  inputStatus.textContent = '⏳ بحث...';
  inputStatus.className   = 's-badge warning';
  scanInput.disabled = true;

  try {
    const res  = await fetch('ajax_scan.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `action=scan&scan_input=${encodeURIComponent(value)}`,
    });
    const data = await res.json();

    if (!data.success) throw new Error(data.message || 'فشل البحث');

    renderResult(data);
    showMsg(data.message, data.already_scanned ? 'warning' : 'success');
    beep(data.already_scanned ? 'warning' : 'success');

    // Update header stats
    state.sessionScanned++;
    $('hdrScanned').textContent = state.sessionScanned;

    // Auto-next flow
    if (!data.already_scanned && data.next_item) {
      setNextPreview(data.next_item);
    } else if (data.all_done) {
      $('nextPreviewWrap').style.display = 'none';
    }

    scanInput.value = '';
  } catch(err) {
    showMsg(err.message, 'error');
    beep('error');
    inputStatus.textContent = '❌ خطأ';
    inputStatus.className   = 's-badge other';
  } finally {
    spinner.style.display = 'none';
    scanInput.disabled    = false;
    scanInput.focus();
    state.scanLock = false;
  }
}

// ══════════════════════════════════════════════════════════════════════════════
// RENDER RESULT
// ══════════════════════════════════════════════════════════════════════════════
const STATUS_LABELS = {
  new:'جديد', pending:'قيد الانتظار', processing:'معالجة',
  scanned:'تم الفرز', shipped:'شُحن', delivered:'تسليم',
  cancelled:'ملغى', returned:'مُرتجع', available:'متاح',
};
function statusLabel(s) { return STATUS_LABELS[s] || s || '—'; }

function currency(n, cur) {
  return (parseFloat(n)||0).toLocaleString('ar-YE') + ' ' + (cur || 'ريال');
}
function fmtDate(d) {
  if (!d) return '—';
  try { return new Date(d).toLocaleDateString('ar-EG', { year:'numeric', month:'short', day:'numeric' }); }
  catch { return d; }
}
function escH(s) {
  return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}

function renderResult(data) {
  const product = data.product    || {};
  const item    = data.item       || {};
  const counts  = data.counts     || {};
  const imgs    = data.order_images || [];
  const group   = data.group_info;
  const cur     = item.currency   || 'ريال';

  state.currentItemId  = item.id;
  state.currentOrderId = item.order_id;

  emptyState.style.display  = 'none';
  resultArea.style.display  = 'flex';

  // All-done banner
  const allDone = data.all_done || (counts.total_items > 0 && counts.scanned_items >= counts.total_items);
  $('allDoneBanner').style.display = allDone ? 'block' : 'none';

  // ① Product
  const imgSrc = product.image || '';
  const imgEl  = $('prodImg');
  imgEl.src = imgSrc || 'data:image/svg+xml,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">' +
    '<rect width="100%" height="100%" fill="#f3f4f6"/>' +
    '<text x="50%" y="50%" fill="#9ca3af" text-anchor="middle" dominant-baseline="middle" font-size="14">No Image</text></svg>'
  );
  const name = product.name || item.product_name || ('SKU: ' + (product.shein_sku || item.shein_sku || ''));
  $('prodName').textContent = name;
  $('prodSku').textContent  = product.shein_sku || item.shein_sku || '';

  const linkEl = $('prodLink');
  if (product.link) { linkEl.href = product.link; linkEl.style.display = 'inline-flex'; }
  else              { linkEl.style.display = 'none'; }

  const badge = $('itemBadge');
  if (data.already_scanned) {
    badge.textContent = '⚠️ مفروز مسبقاً'; badge.className = 's-badge warning';
  } else {
    badge.textContent = '✅ تم الفرز'; badge.className = 's-badge scanned';
  }

  inputStatus.textContent = '✅ تم';
  inputStatus.className   = 's-badge scanned';

  // ② Order
  const orderId = item.order_id || '';
  $('orderViewLink').href = `../../modules/orders/view.php?id=${orderId}`;
  $('oNumber').textContent  = item.order_number || ('#' + orderId);
  $('oCustomer').textContent = item.customer_name   || '—';
  $('oMobile').textContent   = item.customer_mobile || '—';
  $('oStatus').textContent   = statusLabel(item.order_status);
  $('oShipping').textContent = currency(item.shipping_cost, cur);
  $('oTotal').textContent    = currency(item.final_amount,  cur);
  $('oDate').textContent     = fmtDate(item.order_date);
  $('oCounts').textContent   = `${counts.scanned_items} / ${counts.total_items}`;

  // Progress bar
  const pct = counts.total_items > 0 ? Math.round(counts.scanned_items / counts.total_items * 100) : 0;
  $('progressBar').style.width = pct + '%';
  $('progressText').textContent = pct + '%';

  // Notes
  const nw = $('oNotesWrap');
  if (item.order_notes && item.order_notes.trim()) {
    $('oNotes').textContent = item.order_notes;
    nw.style.display = 'block';
  } else { nw.style.display = 'none'; }

  // Group banner
  const gb = $('groupBanner');
  if (group && group.id) {
    $('groupName').textContent   = group.group_name   || ('مجموعة #' + group.id);
    $('groupNumber').textContent = group.group_number || '';
    gb.style.display = 'flex';
  } else { gb.style.display = 'none'; }

  // ③ All items
  const listEl = $('itemsList');
  listEl.innerHTML = '';
  const allItems = data.all_items || [];
  const currentSku = product.shein_sku || item.shein_sku || '';
  if (!allItems.length) {
    listEl.innerHTML = '<p style="padding:20px;text-align:center;color:var(--sort-muted);font-size:.83rem;">لا توجد منتجات</p>';
  } else {
    allItems.forEach(it => {
      const isCur = it.shein_sku && it.shein_sku === currentSku;
      const sc    = it.status === 'scanned';
      const div   = document.createElement('div');
      div.className = 'item-row' + (isCur ? ' current' : '');
      div.innerHTML = `
        <img class="th" src="${escH(it.sp_image||'')}"
             onerror="this.style.visibility='hidden'" alt="">
        <div class="nm">
          <p>${escH(it.sp_name || it.product_name || ('SKU ' + (it.shein_sku||'')))}</p>
          <small>${escH(it.shein_sku||'—')}</small>
        </div>
        <span class="s-badge ${sc ? 'scanned' : 'pending'}">${statusLabel(it.status)}</span>
      `;
      listEl.appendChild(div);
    });
  }

  // ④ Images
  const ic = $('imagesCard'), ig = $('imagesGrid');
  ig.innerHTML = '';
  if (imgs.length) {
    ic.style.display = 'block';
    imgs.forEach(img => {
      const src = '/uploads/' + (img.image_path || '').replace(/^\/?(?:uploads\/)?/, '');
      const a   = document.createElement('a'); a.href = src; a.target = '_blank';
      a.innerHTML = `<img src="${escH(src)}" alt="${escH(img.image_name||'')}" loading="lazy">`;
      ig.appendChild(a);
    });
  } else { ic.style.display = 'none'; }

  // Update header
  $('hdrPending').textContent = Math.max(0, (counts.total_items||0) - (counts.scanned_items||0));
}

// ══════════════════════════════════════════════════════════════════════════════
// NEXT ITEM PREVIEW
// ══════════════════════════════════════════════════════════════════════════════
function setNextPreview(next) {
  if (!next) { $('nextPreviewWrap').style.display = 'none'; return; }
  $('nextPreviewWrap').style.display = 'block';
  $('nextImg').src  = next.sp_image || '';
  $('nextName').textContent = next.sp_name || next.product_name || ('SKU ' + (next.shein_sku||''));
  $('nextSku').textContent  = next.shein_sku || '';
  $('btnAutoScanNext').dataset.sku = next.shein_sku || '';
}

// ══════════════════════════════════════════════════════════════════════════════
// UNSCAN
// ══════════════════════════════════════════════════════════════════════════════
async function doUnscan() {
  if (!state.currentItemId) return;
  try {
    const res  = await fetch('ajax_scan.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    `action=unscan&item_id=${state.currentItemId}`,
    });
    const data = await res.json();
    if (data.success) {
      showMsg('تم إلغاء الفرز — الحالة: قيد الانتظار', 'warning');
      beep('warning');
      $('itemBadge').textContent = '⏳ قيد الانتظار';
      $('itemBadge').className   = 's-badge pending';
      const c = data.counts || {};
      $('oCounts').textContent = `${c.scanned_items} / ${c.total_items}`;
      const pct = c.total_items > 0 ? Math.round(c.scanned_items / c.total_items * 100) : 0;
      $('progressBar').style.width = pct + '%';
      $('progressText').textContent = pct + '%';
    }
  } catch(e) { showMsg(e.message, 'error'); }
}

// ══════════════════════════════════════════════════════════════════════════════
// CAMERA SCANNER
// ══════════════════════════════════════════════════════════════════════════════
function extractSkuFromText(text) {
  const normalized = String(text || '').replace(/\s+/g, '').toLowerCase();
  const match = normalized.match(/sk\d{8,}/i);
  return match ? match[0].toLowerCase() : '';
}

async function recognizeSkuFromVideo(video) {
  if (!window.Tesseract || video.videoWidth < 20 || video.videoHeight < 20) return '';

  const canvas = document.createElement('canvas');
  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

  const { data } = await Tesseract.recognize(canvas, 'eng', {
    tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
  });

  return extractSkuFromText(data?.text || '');
}

async function startCamera() {
  if (!window.Tesseract) {
    showMsg('تعذر تحميل OCR — تحقق من الاتصال ثم أعد المحاولة', 'error');
    return;
  }

  try {
    state.camStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    const video = $('scannerVideo');
    video.srcObject = state.camStream;
    await video.play();

    state.camTimer = setInterval(async () => {
      if (state.camOcrBusy || state.scanLock) return;
      state.camOcrBusy = true;
      try {
        const sku = await recognizeSkuFromVideo(video);
        if (sku) doScan(sku);
      } catch(e) {
      } finally {
        state.camOcrBusy = false;
      }
    }, 1200);

    showMsg('✅ الكاميرا تعمل — يتم استخراج SKU من نص الملصق (OCR)', 'success');
  } catch(err) { showMsg('تعذر تشغيل الكاميرا: ' + err.message, 'error'); }
}
function stopCamera() {
  if (state.camTimer)  { clearInterval(state.camTimer);  state.camTimer  = null; }
  state.camOcrBusy = false;
  if (state.camStream) { state.camStream.getTracks().forEach(t => t.stop()); state.camStream = null; }
  $('scannerVideo').srcObject = null;
  showMsg('تم إيقاف الكاميرا', 'warning');
}

// ══════════════════════════════════════════════════════════════════════════════
// EVENT BINDINGS
// ══════════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  scanInput.focus();

  // Scan on Enter
  scanInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); doScan(scanInput.value); }
  });

  // Auto-scan on paste (scanner guns often paste + Enter; handle paste alone too)
  scanInput.addEventListener('paste', () => {
    setTimeout(() => { if (scanInput.value.length >= 6) doScan(scanInput.value); }, 80);
  });

  $('btnScan').addEventListener('click', () => doScan(scanInput.value));
  $('btnClear').addEventListener('click', () => { scanInput.value = ''; hideMsg(); scanInput.focus(); inputStatus.textContent = 'جاهز'; inputStatus.className = 's-badge other'; });
  $('btnUnscan').addEventListener('click', doUnscan);
  $('btnCamStart').addEventListener('click', startCamera);
  $('btnCamStop').addEventListener('click', stopCamera);

  // Auto-scan next
  $('btnAutoScanNext').addEventListener('click', function() {
    const sku = this.dataset.sku;
    if (sku) { scanInput.value = sku; doScan(sku); }
  });

  // Keep focus on scan input when clicking anywhere on page
  document.addEventListener('click', e => {
    if (!e.target.closest('button,a,select,input:not(#scanInput),video')) {
      scanInput.focus();
    }
  });
});
</script>

<?php include '../../includes/footer.php'; ?>
