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
.sku-pick-list { margin-top:10px; display:grid; gap:8px; }
.sku-pick-btn { width:100%; text-align:right; border:1px solid var(--sort-border); background:#fff; border-radius:10px; padding:10px; cursor:pointer; }
.sku-pick-btn:hover { border-color:#60a5fa; background:#eff6ff; }

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

/* Mobile responsiveness improvements */
@media (max-width: 640px) {
  #sortApp { padding-bottom: 28px; }
  .sort-header { gap: 10px; }
  .sort-header h1 { width: 100%; font-size: 1.2rem; gap: 9px; }
  .sort-header h1 .icon-box { width: 38px; height: 38px; border-radius: 12px; font-size: 1rem; }
  .sort-stats { width: 100%; display: grid; grid-template-columns: 1fr; gap: 8px; }
  .sort-stat-pill { width: 100%; justify-content: center; text-align: center; }
  .sort-layout { padding: 12px; gap: 12px; }
  .s-card-head, .s-card-body { padding: 12px; }
  .scan-input { font-size: .95rem; padding: 14px 42px 14px 12px; }
  .scan-input-icon { left: 12px; font-size: 1rem; }
  .cam-btns { flex-direction: column; }
  .prod-hero { flex-direction: column; align-items: center; text-align: center; }
  .prod-img { width: 96px; height: 96px; }
  .prod-meta { width: 100%; }
  .prod-meta h2 { font-size: .96rem; line-height: 1.5; }
  .prod-sku { overflow-wrap: anywhere; word-break: break-word; text-align: center; }
  .prod-actions { justify-content: center; }
  .order-grid { grid-template-columns: 1fr; }
  .item-row { padding: 10px 11px; gap: 9px; }
  .item-row .nm p { white-space: normal; overflow: visible; text-overflow: initial; }
  .next-preview { align-items: flex-start; }
  .sort-imgs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 420px) {
  .s-btn { padding: 9px 12px; font-size: .8rem; }
  .s-btn-sm { font-size: .74rem; }
  .sort-empty { padding: 44px 12px; }
}
</style>


<!-- Load Tajawal font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;600;700;800&display=swap" rel="stylesheet">
<!-- Tesseract.js — browser-side OCR, no API key, no rate limits -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

<div id="sortApp">

  <!-- Header -->
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

  <!-- Main Layout -->
  <div class="sort-layout">

    <!-- LEFT PANEL -->
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
          <div id="skuPickWrap" style="display:none;">
            <div style="margin-top:2px;padding:10px;border:1px solid var(--sort-border);border-radius:12px;background:#f9fafb;">
              <strong>SKU موجود في عدة طلبات</strong>
              <div id="skuPickList" class="sku-pick-list"></div>
            </div>
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
          <span id="camStatus" class="s-badge other" style="font-size:.7rem">متوقف</span>
        </div>
        <div class="s-card-body" style="display:flex;flex-direction:column;gap:10px;">
          <video id="scannerVideo" playsinline muted></video>

          <!-- Start / Stop row -->
          <div class="cam-btns">
            <button id="btnCamStart" type="button" class="s-btn s-btn-green" style="flex:1;font-size:.82rem;">
              <i class="fas fa-play"></i> تشغيل الكاميرا
            </button>
            <button id="btnCamStop" type="button" class="s-btn s-btn-muted" style="flex:1;font-size:.82rem;">
              <i class="fas fa-stop"></i> إيقاف
            </button>
          </div>

          <!-- Manual OCR capture — only fires ONE request on click -->
          <button id="btnCamScan" type="button" class="s-btn s-btn-primary s-btn-full" style="display:none;">
            <i class="fas fa-camera"></i> مسح SKU الآن
          </button>

          <p style="font-size:.72rem;color:var(--sort-muted);text-align:center;margin:0;">
            وجّه الكاميرا على ملصق SKU ثم اضغط «مسح SKU الآن»
          </p>
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

    <!-- RIGHT PANEL -->
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

      <!-- Result panels -->
      <div id="resultArea" style="display:none;flex-direction:column;gap:16px;">

        <!-- All-done banner -->
        <div id="allDoneBanner" style="display:none" class="all-done-banner">
          <span class="icon">🎉</span>
          <h3>تم الفرز بالكامل!</h3>
          <p>جميع منتجات هذا الطلب مفروزة</p>
        </div>

        <!-- Product card -->
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

        <!-- Order card -->
        <div class="s-card">
          <div class="s-card-head">
            <h3>📦 تفاصيل الطلب</h3>
            <a id="orderViewLink" href="#" target="_blank" class="sort-view-link">
              <i class="fas fa-eye"></i> عرض الطلب
            </a>
          </div>
          <div class="s-card-body" style="display:flex;flex-direction:column;gap:12px;">

            <div id="groupBanner" style="display:none" class="group-banner">
              <span class="icon">📁</span>
              <div class="info">
                <strong id="groupName"></strong>
                <span id="groupNumber"></span>
              </div>
            </div>

            <div>
              <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                <span style="font-size:.78rem;color:var(--sort-muted);">تقدم الفرز</span>
                <span id="progressText" style="font-size:.78rem;font-weight:700;color:var(--sort-accent2);"></span>
              </div>
              <div class="sort-progress-wrap">
                <div id="progressBar" class="sort-progress-bar" style="width:0%"></div>
              </div>
            </div>

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

            <div id="oNotesWrap" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:10px 12px;font-size:.82rem;color:#92400e;">
              <i class="fas fa-sticky-note" style="margin-left:6px;"></i><span id="oNotes"></span>
            </div>
          </div>
        </div>

        <!-- All items -->
        <div class="s-card">
          <div class="s-card-head">
            <h3>📋 منتجات الطلب</h3>
          </div>
          <div id="itemsList" class="items-list"></div>
        </div>

        <!-- Order images -->
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

<script>
// ══════════════════════════════════════════════════════════════════════════════
// STATE
// ══════════════════════════════════════════════════════════════════════════════
const state = {
  currentItemId : null,
  currentOrderId: null,
  sessionScanned: 0,
  sessionPending: 0,
  scanLock      : false,
  lastScanVal   : '',
  lastScanAt    : 0,
  camStream     : null,   // active MediaStream (no auto-interval)
};

const $ = id => document.getElementById(id);
const scanInput   = $('scanInput');
const spinner     = $('sortSpinner');
const msgEl       = $('sortMsg');
const inputStatus = $('inputStatus');
const emptyState  = $('emptyState');
const resultArea  = $('resultArea');

// ══════════════════════════════════════════════════════════════════════════════
// SOUND
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
function showMsg(text, type) {
  type = type || 'success';
  msgEl.className = 'sort-msg ' + type;
  const icons = { success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle' };
  msgEl.innerHTML = '<i class="fas fa-' + (icons[type] || 'info-circle') + '"></i><span>' + escH(text) + '</span>';
  msgEl.style.display = 'flex';
}
function hideMsg() { msgEl.style.display = 'none'; }

// ══════════════════════════════════════════════════════════════════════════════
// CORE SCAN
// ══════════════════════════════════════════════════════════════════════════════
async function doScan(value, selectedItemId) {
  selectedItemId = selectedItemId || 0;
  value = (value || '').trim();
  if (!value) { showMsg('يرجى إدخال SKU أو رابط صالح', 'error'); return; }

  const now = Date.now();
  if (value === state.lastScanVal && now - state.lastScanAt < 2500) return;
  if (state.scanLock) return;

  state.scanLock    = true;
  state.lastScanVal = value;
  state.lastScanAt  = now;

  spinner.style.display   = 'flex';
  hideMsg();
  inputStatus.textContent = '⏳ بحث...';
  inputStatus.className   = 's-badge warning';
  scanInput.disabled      = true;

  try {
    const res  = await fetch('ajax_scan.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    'action=scan&scan_input=' + encodeURIComponent(value) + '&selected_item_id=' + selectedItemId,
    });
    const data = await res.json();

    if (!data.success) throw new Error(data.message || 'فشل البحث');

    if (data.requires_selection) {
      renderSkuSelection(data);
      showMsg(data.message || 'اختر الطلب المطلوب', 'warning');
      beep('warning');
      return;
    }
    $('skuPickWrap').style.display = 'none';

    renderResult(data);
    showMsg(data.message, data.already_scanned ? 'warning' : 'success');
    beep(data.already_scanned ? 'warning' : 'success');

    state.sessionScanned++;
    $('hdrScanned').textContent = state.sessionScanned;

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

function renderSkuSelection(data) {
  const list    = $('skuPickList');
  const matches = Array.isArray(data.matches) ? data.matches : [];
  list.innerHTML = '';
  $('skuPickWrap').style.display = matches.length ? 'block' : 'none';
  matches.forEach(function(m) {
    const btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'sku-pick-btn';
    btn.innerHTML = '<strong>' + escH(m.order_number || ('#' + m.order_id)) + '</strong>' +
      '<div style="font-size:.82rem;color:var(--sort-muted);margin-top:4px;">' +
      escH(m.customer_name || 'عميل غير محدد') + ' — ' + escH(m.customer_mobile || '—') + '</div>';
    btn.addEventListener('click', function() {
      doScan(data.sku || scanInput.value, parseInt(m.item_id || 0, 10));
    });
    list.appendChild(btn);
  });
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
function currency(n, cur) { return (parseFloat(n)||0).toLocaleString('ar-YE') + ' ' + (cur || 'ريال'); }
function fmtDate(d) {
  if (!d) return '—';
  try { return new Date(d).toLocaleDateString('ar-EG', { year:'numeric', month:'short', day:'numeric' }); }
  catch(e) { return d; }
}
function escH(s) {
  return String(s||'').replace(/[&<>"']/g, function(c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
  });
}

function renderResult(data) {
  const product = data.product      || {};
  const item    = data.item         || {};
  const counts  = data.counts       || {};
  const imgs    = data.order_images || [];
  const group   = data.group_info;
  const cur     = item.currency     || 'ريال';

  state.currentItemId  = item.id;
  state.currentOrderId = item.order_id;

  emptyState.style.display = 'none';
  resultArea.style.display = 'flex';

  const allDone = data.all_done || (counts.total_items > 0 && counts.scanned_items >= counts.total_items);
  $('allDoneBanner').style.display = allDone ? 'block' : 'none';

  const imgEl = $('prodImg');
  imgEl.src = product.image || 'data:image/svg+xml,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">' +
    '<rect width="100%" height="100%" fill="#f3f4f6"/>' +
    '<text x="50%" y="50%" fill="#9ca3af" text-anchor="middle" dominant-baseline="middle" font-size="14">No Image</text></svg>'
  );
  $('prodName').textContent = product.name || item.product_name || ('SKU: ' + (product.shein_sku || item.shein_sku || ''));
  $('prodSku').textContent  = product.shein_sku || item.shein_sku || '';

  const linkEl = $('prodLink');
  if (product.link) { linkEl.href = product.link; linkEl.style.display = 'inline-flex'; }
  else              { linkEl.style.display = 'none'; }

  const badge = $('itemBadge');
  if (data.already_scanned) { badge.textContent = '⚠️ مفروز مسبقاً'; badge.className = 's-badge warning'; }
  else                      { badge.textContent = '✅ تم الفرز';       badge.className = 's-badge scanned'; }

  inputStatus.textContent = '✅ تم';
  inputStatus.className   = 's-badge scanned';

  const orderId = item.order_id || '';
  $('orderViewLink').href    = '../../modules/orders/view.php?id=' + orderId;
  $('oNumber').textContent   = item.order_number    || ('#' + orderId);
  $('oCustomer').textContent = item.customer_name   || '—';
  $('oMobile').textContent   = item.customer_mobile || '—';
  $('oStatus').textContent   = statusLabel(item.order_status);
  $('oShipping').textContent = currency(item.shipping_cost, cur);
  $('oTotal').textContent    = currency(item.final_amount,  cur);
  $('oDate').textContent     = fmtDate(item.order_date);
  $('oCounts').textContent   = counts.scanned_items + ' / ' + counts.total_items;

  const pct = counts.total_items > 0 ? Math.round(counts.scanned_items / counts.total_items * 100) : 0;
  $('progressBar').style.width    = pct + '%';
  $('progressText').textContent   = pct + '%';

  const nw = $('oNotesWrap');
  if (item.order_notes && item.order_notes.trim()) {
    $('oNotes').textContent = item.order_notes;
    nw.style.display = 'block';
  } else { nw.style.display = 'none'; }

  const gb = $('groupBanner');
  if (group && group.id) {
    $('groupName').textContent   = group.group_name   || ('مجموعة #' + group.id);
    $('groupNumber').textContent = group.group_number || '';
    gb.style.display = 'flex';
  } else { gb.style.display = 'none'; }

  const listEl     = $('itemsList');
  listEl.innerHTML = '';
  const allItems   = data.all_items || [];
  const currentSku = product.shein_sku || item.shein_sku || '';
  if (!allItems.length) {
    listEl.innerHTML = '<p style="padding:20px;text-align:center;color:var(--sort-muted);font-size:.83rem;">لا توجد منتجات</p>';
  } else {
    allItems.forEach(function(it) {
      const isCur = it.shein_sku && it.shein_sku === currentSku;
      const sc    = it.status === 'scanned';
      const div   = document.createElement('div');
      div.className = 'item-row' + (isCur ? ' current' : '');
      div.innerHTML =
        '<img class="th" src="' + escH(it.sp_image||'') + '" onerror="this.style.visibility=\'hidden\'" alt="">' +
        '<div class="nm"><p>' + escH(it.sp_name || it.product_name || ('SKU ' + (it.shein_sku||''))) + '</p>' +
        '<small>' + escH(it.shein_sku||'—') + '</small></div>' +
        '<span class="s-badge ' + (sc ? 'scanned' : 'pending') + '">' + statusLabel(it.status) + '</span>';
      listEl.appendChild(div);
    });
  }

  const ic = $('imagesCard'), ig = $('imagesGrid');
  ig.innerHTML = '';
  if (imgs.length) {
    ic.style.display = 'block';
    imgs.forEach(function(img) {
      const src = '/uploads/' + (img.image_path || '').replace(/^\/?(?:uploads\/)?/, '');
      const a   = document.createElement('a'); a.href = src; a.target = '_blank';
      a.innerHTML = '<img src="' + escH(src) + '" alt="' + escH(img.image_name||'') + '" loading="lazy">';
      ig.appendChild(a);
    });
  } else { ic.style.display = 'none'; }

  $('hdrPending').textContent = Math.max(0, (counts.total_items||0) - (counts.scanned_items||0));
}

// ══════════════════════════════════════════════════════════════════════════════
// NEXT ITEM PREVIEW
// ══════════════════════════════════════════════════════════════════════════════
function setNextPreview(next) {
  if (!next) { $('nextPreviewWrap').style.display = 'none'; return; }
  $('nextPreviewWrap').style.display = 'block';
  $('nextImg').src              = next.sp_image || '';
  $('nextName').textContent     = next.sp_name || next.product_name || ('SKU ' + (next.shein_sku||''));
  $('nextSku').textContent      = next.shein_sku || '';
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
      body:    'action=unscan&item_id=' + state.currentItemId,
    });
    const data = await res.json();
    if (data.success) {
      showMsg('تم إلغاء الفرز — الحالة: قيد الانتظار', 'warning');
      beep('warning');
      $('itemBadge').textContent = '⏳ قيد الانتظار';
      $('itemBadge').className   = 's-badge pending';
      const c = data.counts || {};
      $('oCounts').textContent = c.scanned_items + ' / ' + c.total_items;
      const pct = c.total_items > 0 ? Math.round(c.scanned_items / c.total_items * 100) : 0;
      $('progressBar').style.width    = pct + '%';
      $('progressText').textContent   = pct + '%';
    }
  } catch(e) { showMsg(e.message, 'error'); }
}

// ══════════════════════════════════════════════════════════════════════════════
// SKU OCR PIPELINE — targets "sk" text printed BELOW the barcode on SHEIN labels
// Label format: barcode number on top, barcode stripes, then "sk2601..." text below
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Normalise raw OCR text: fix common misreads for sk-prefixed SHEIN SKUs.
 * The 's' is almost never misread; 'k' can be mistaken as 'K','x','X','k'.
 * Digits: '0' vs 'O','o'; '1' vs 'l','I','|'; '5' vs 'S' (not 's' — 's' is the prefix!).
 */
function normalizeOcrText(text) {
  return String(text || '')
    .replace(/[Oo]/g, '0')       // O → 0
    .replace(/[lI|]/g, '1')      // l/I/| → 1
    .replace(/[Ａ-Ｚａ-ｚ０-９]/g, function(ch) {   // full-width → ASCII
      return String.fromCharCode(ch.charCodeAt(0) - 0xFEE0);
    });
}

/**
 * Extract SHEIN SKU from OCR text.
 * SHEIN label SKUs always start with "sk" (lower or upper case) followed by
 * at least 15 digits (real ones are ~21 chars total, e.g. sk260113163135433919550).
 * We deliberately IGNORE plain digit strings (barcode numbers like "27674337")
 * so the barcode number printed above the stripes is never returned.
 */
function extractSkuFromText(text) {
  if (!text) return [];

  // 1. Normalise common OCR glyphs
  const norm = normalizeOcrText(text);

  // 2. Collapse all whitespace/separators inside what looks like an sk-code.
  //    OCR may insert spaces or newlines between chunks: "sk 2601 131631 35433919550"
  //    Strategy: find every run that starts with sk/SK/5k/sK then grab following digit-like chars.
  const candidates = new Set();

  // Pass A – simple regex on normalised text (handles clean reads)
  const reA = /[sS5][kKxX]\s*[\d\s]{15,35}/gi;
  let m;
  while ((m = reA.exec(norm)) !== null) {
    const digits = m[0].slice(2).replace(/\D/g, '');
    if (digits.length >= 15) candidates.add('sk' + digits);
  }

  // Pass B – compact (strip all non-alnum first), catches fragmented reads
  const compact = norm.replace(/[^a-zA-Z0-9]/g, '');
  const reB = /[sS5][kKxX]\d{15,30}/g;
  while ((m = reB.exec(compact)) !== null) {
    const digits = m[0].slice(2).replace(/\D/g, '');
    if (digits.length >= 15) candidates.add('sk' + digits);
  }

  // Pass C – lines that are mostly digits and long enough to be the bottom text line.
  //    OCR may drop the 'sk' prefix entirely on a worn label; only accept if 18+ digits.
  norm.split(/\n/).forEach(function(line) {
    const stripped = line.replace(/\s/g, '');
    // Only promote to SKU if it starts with sk-like prefix
    if (/^[sS5][kKxX]/i.test(stripped)) {
      const digits = stripped.slice(2).replace(/\D/g, '');
      if (digits.length >= 15) candidates.add('sk' + digits);
    }
  });

  // Validate: sk followed by 15–25 digits; reject anything that looks like just a barcode
  return Array.from(candidates).filter(function(sku) {
    return /^sk\d{15,25}$/.test(sku);
  });
}

function clampNumber(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

/**
 * Upscale a video region onto a canvas, targeting maxWidth px wide.
 * Larger = better OCR accuracy for small text.
 */
function drawVideoCropToCanvas(video, crop, maxWidth) {
  const scale = crop.sw < maxWidth ? maxWidth / crop.sw : 1;  // upscale small crops
  const dstW  = Math.max(1, Math.round(crop.sw * scale));
  const dstH  = Math.max(1, Math.round(crop.sh * scale));
  const canvas = document.createElement('canvas');
  canvas.width  = dstW;
  canvas.height = dstH;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(video, crop.sx, crop.sy, crop.sw, crop.sh, 0, 0, dstW, dstH);
  return canvas;
}

/**
 * Convert canvas to grayscale + contrast-stretch + optional binarize.
 * Returns a NEW canvas (does not mutate source).
 */
function enhanceCanvasForSku(sourceCanvas, options) {
  const opts = Object.assign({ threshold: 0, invert: false }, options || {});
  const canvas = document.createElement('canvas');
  canvas.width  = sourceCanvas.width;
  canvas.height = sourceCanvas.height;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(sourceCanvas, 0, 0);

  const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
  const px  = imgData.data;
  const len = canvas.width * canvas.height;
  const gray = new Uint8ClampedArray(len);

  for (let i = 0, gi = 0; i < px.length; i += 4, gi++) {
    gray[gi] = (px[i] * 0.299 + px[i+1] * 0.587 + px[i+2] * 0.114) | 0;
  }

  // Percentile-based contrast stretch (2nd–98th)
  const hist = new Int32Array(256);
  for (let i = 0; i < len; i++) hist[gray[i]]++;
  let pLow = 0, pHigh = 255, cum = 0;
  const lowT = len * 0.02, highT = len * 0.98;
  for (let v = 0; v < 256; v++) { cum += hist[v]; if (cum >= lowT)  { pLow  = v; break; } }
  cum = 0;
  for (let v = 0; v < 256; v++) { cum += hist[v]; if (cum >= highT) { pHigh = v; break; } }
  const range = Math.max(1, pHigh - pLow);

  for (let i = 0; i < len; i++) {
    let v = clampNumber(((gray[i] - pLow) / range * 255) | 0, 0, 255);
    // Slight contrast boost
    v = clampNumber(((v - 128) * 1.4 + 128) | 0, 0, 255);
    if (opts.threshold) v = v >= opts.threshold ? 255 : 0;
    if (opts.invert) v = 255 - v;
    gray[i] = v;
  }

  for (let i = 0, gi = 0; i < px.length; i += 4, gi++) {
    px[i] = px[i+1] = px[i+2] = gray[gi];
    px[i+3] = 255;
  }
  ctx.putImageData(imgData, 0, 0);
  return canvas;
}

/**
 * Produce the crop regions to OCR.
 *
 * SHEIN label anatomy (when phone is pointing at label):
 *   top ~20%  : product code + "MADE IN CHINA"
 *   mid ~50%  : barcode stripes
 *   bottom ~30%: "sk260113163135433919550" + size code
 *
 * We generate dedicated crops for the BOTTOM text row, plus fallback wider crops.
 * Each crop gets two enhancement variants (contrast + binary).
 */
function prepareSkuOcrRegions(video) {
  const VW = video.videoWidth;
  const VH = video.videoHeight;
  if (VW < 20 || VH < 20) return [];

  // Crop specs as fractions of video dimensions.
  // Priority order: narrow bottom strip first (most likely to hold the sk text),
  // then progressively wider crops as fallbacks.
  const cropSpecs = [
    // ── PRIMARY: bottom text row only (below barcode stripes)
    { name: 'bottom-strip',   x: 0.02, y: 0.68, w: 0.96, h: 0.22 },
    // ── SECONDARY: slightly taller bottom region
    { name: 'bottom-tall',    x: 0.02, y: 0.60, w: 0.96, h: 0.32 },
    // ── TERTIARY: lower half of frame (handles tilted phone)
    { name: 'lower-half',     x: 0.02, y: 0.45, w: 0.96, h: 0.50 },
    // ── QUATERNARY: middle band (label centred in frame)
    { name: 'middle-band',    x: 0.04, y: 0.30, w: 0.92, h: 0.50 },
    // ── FALLBACK: full frame (expensive but catches everything)
    { name: 'full-frame',     x: 0.00, y: 0.00, w: 1.00, h: 1.00 },
  ];

  const variants = [
    { name: 'contrast',  threshold: 0,   invert: false },
    { name: 'binary140', threshold: 140, invert: false },
    { name: 'binary180', threshold: 180, invert: false },
  ];

  const canvases = [];
  cropSpecs.forEach(function(spec) {
    const sx = Math.floor(VW * spec.x);
    const sy = Math.floor(VH * spec.y);
    const sw = Math.floor(VW * spec.w);
    const sh = Math.floor(VH * spec.h);
    if (sw < 20 || sh < 10) return;
    const base = drawVideoCropToCanvas(video, { sx, sy, sw, sh }, 1400);  // upscale to 1400px wide
    variants.forEach(function(variant) {
      const c = enhanceCanvasForSku(base, variant);
      c._ocrLabel = spec.name + ':' + variant.name;
      canvases.push(c);
    });
  });
  return canvases;
}

// ══════════════════════════════════════════════════════════════════════════════
// TESSERACT.JS OCR — 100% client-side, no API key needed
// ══════════════════════════════════════════════════════════════════════════════

let _tesseractWorker = null;

async function getTesseractWorker() {
  if (_tesseractWorker) return _tesseractWorker;
  if (typeof Tesseract === 'undefined') {
    throw new Error('مكتبة OCR لم يتم تحميلها. تحقق من اتصال الإنترنت.');
  }

  _tesseractWorker = await Tesseract.createWorker('eng', 1, {
    logger: function(m) {
      if (m && m.status === 'recognizing text' && typeof m.progress === 'number') {
        const pct = Math.round(m.progress * 100);
        const btn = $('btnCamScan');
        if (btn && btn.disabled) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> OCR ' + pct + '%';
      }
    }
  });

  if (_tesseractWorker.setParameters) {
    await _tesseractWorker.setParameters({
      // Whitelist: lowercase sk prefix + digits + a few likely OCR noise chars
      // Intentionally keep digits broad; extraction logic filters later.
      tessedit_char_whitelist: 'skSK0123456789 \n',
      // PSM 6 = Uniform block of text (best for label text rows)
      // PSM 7 = Single text line (try for narrow strips)
      tessedit_pageseg_mode: '6',
      preserve_interword_spaces: '1',
      user_defined_dpi: '300',
    });
  }
  return _tesseractWorker;
}

/**
 * Score a candidate SKU: longer + more times seen = better.
 * Prefer exact sk\d{18-22} lengths that match known SHEIN format.
 */
function rankSkuCandidate(sku, text) {
  let score = sku.length;  // longer generally better
  const normT = normalizeOcrText(text).replace(/\s/g, '');
  if (normT.toLowerCase().indexOf(sku) !== -1) score += 40;
  // Bonus for matching typical SHEIN length (sk + 19–21 digits)
  if (/^sk\d{19,21}$/.test(sku)) score += 20;
  return score;
}

/**
 * Single-frame multi-region OCR.
 * Prioritises bottom crops; returns as soon as a confident SKU is found.
 * Never returns a plain barcode number.
 */
async function recognizeSkuFromVideo(video) {
  if (!video || video.videoWidth < 20 || video.videoHeight < 20) return null;

  const ocrCanvases = prepareSkuOcrRegions(video);
  if (!ocrCanvases.length) return null;

  let worker;
  try {
    worker = await getTesseractWorker();
  } catch (initErr) {
    throw new Error('تعذّر تهيئة محرك OCR: ' + (initErr.message || initErr));
  }

  const found = new Map();
  const rawTexts = [];

  for (let i = 0; i < ocrCanvases.length; i++) {
    let result;
    try {
      result = await worker.recognize(ocrCanvases[i]);
    } catch (ocrErr) {
      _tesseractWorker = null;
      try { worker.terminate(); } catch(_) {}
      throw new Error('فشل OCR: ' + (ocrErr.message || ocrErr));
    }

    const rawText = (result && result.data && result.data.text) ? result.data.text : '';
    const conf    = (result && result.data && result.data.confidence) ? result.data.confidence : 0;
    rawTexts.push('[' + (ocrCanvases[i]._ocrLabel || i) + ' conf=' + conf + '] ' + rawText);

    const skus = extractSkuFromText(rawText);

    // High-confidence single match → return immediately
    if (skus.length === 1 && conf >= 60) {
      console.debug('[OCR] Fast match:', skus[0], 'conf=' + conf);
      return skus[0];
    }

    skus.forEach(function(sku) {
      const prev = found.get(sku) || { sku: sku, count: 0, score: 0 };
      prev.count++;
      prev.score += rankSkuCandidate(sku, rawText);
      found.set(sku, prev);
    });

    // Trust a SKU that appears in 2+ independent crops
    const strong = Array.from(found.values()).find(function(it) { return it.count >= 2; });
    if (strong) {
      console.debug('[OCR] Confirmed match:', strong.sku);
      return strong.sku;
    }
  }

  const all = Array.from(found.values()).sort(function(a, b) {
    return (b.count - a.count) || (b.score - a.score) || (b.sku.length - a.sku.length);
  });

  console.debug('[OCR] Raw texts:\n', rawTexts.join('\n'));

  if (all.length === 0) return null;
  if (all.length === 1) return all[0].sku;
  // Only return top candidate if it's clearly better than second
  if (all[0].score >= all[1].score + 15) return all[0].sku;

  // Tie-break: prefer the one whose length is closer to typical SHEIN SKU (21 chars)
  all.sort(function(a, b) {
    return Math.abs(a.sku.length - 21) - Math.abs(b.sku.length - 21);
  });
  return all[0].sku;
}

// ══════════════════════════════════════════════════════════════════════════════
// CAMERA — manual-only OCR capture; no auto-interval or repeated requests
// ══════════════════════════════════════════════════════════════════════════════

async function startCamera() {
  try {
    state.camStream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1920 },
        height: { ideal: 1080 },
        focusMode: { ideal: 'continuous' }
      }
    });
    const video = $('scannerVideo');
    video.srcObject = state.camStream;
    await video.play();

    $('btnCamScan').style.display = 'block';
    $('camStatus').textContent    = '🟢 يعمل';
    $('camStatus').className      = 's-badge scanned';
    showMsg('الكاميرا تعمل — اضغط «مسح SKU الآن» عند جهوز الملصق', 'success');
  } catch(err) {
    showMsg('تعذر تشغيل الكاميرا: ' + err.message, 'error');
  }
}

function stopCamera() {
  if (state.camStream) {
    state.camStream.getTracks().forEach(function(t) { t.stop(); });
    state.camStream = null;
  }
  $('scannerVideo').srcObject   = null;
  $('btnCamScan').style.display = 'none';
  $('camStatus').textContent    = 'متوقف';
  $('camStatus').className      = 's-badge other';
  showMsg('تم إيقاف الكاميرا', 'warning');
}

/**
 * Called ONCE per button press.
 * Takes ONE frame → runs local Tesseract OCR → auto-fills SKU → runs doScan.
 * No interval. No automatic firing. One click = one OCR attempt.
 */
async function doOcrCapture() {
  const video = $('scannerVideo');
  if (!state.camStream || !video || video.videoWidth < 20) {
    showMsg('الكاميرا غير نشطة — شغّلها أولاً', 'error');
    return;
  }

  const btn = $('btnCamScan');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ المسح...';
  hideMsg();

  try {
    const sku = await recognizeSkuFromVideo(video);

    if (!sku) {
      showMsg('لم يتم العثور على SKU بوضوح. قرّب الكاميرا، ثبّت الملصق داخل الإطار، وتأكد من الإضاءة ثم حاول مرة أخرى.', 'warning');
      scanInput.focus();
      return;
    }

    // Auto-fill + trigger normal scan flow
    scanInput.value = sku;
    showMsg('\u2705 SKU \u062a\u0645 \u0627\u0633\u062a\u062e\u0631\u0627\u062c\u0647: ' + sku + ' \u2014 \u062c\u0627\u0631\u064d \u0627\u0644\u0641\u0631\u0632...', 'success');
    await doScan(sku);

  } catch(err) {
    showMsg('\u274c ' + (err.message || '\u0641\u0634\u0644 \u0627\u0633\u062a\u062e\u0631\u0627\u062c SKU'), 'error');
    console.error('[doOcrCapture]', err);
  } finally {
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-camera"><\/i> \u0645\u0633\u062d SKU \u0627\u0644\u0622\u0646';
  }
}
// EVENT BINDINGS
// ══════════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', function() {
  scanInput.focus();

  scanInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); doScan(scanInput.value); }
  });

  scanInput.addEventListener('paste', function() {
    setTimeout(function() { if (scanInput.value.length >= 6) doScan(scanInput.value); }, 80);
  });

  $('btnScan').addEventListener('click', function() { doScan(scanInput.value); });
  $('btnClear').addEventListener('click', function() {
    scanInput.value = ''; hideMsg(); scanInput.focus();
    inputStatus.textContent = 'جاهز'; inputStatus.className = 's-badge other';
  });
  $('btnUnscan').addEventListener('click', doUnscan);
  $('btnCamStart').addEventListener('click', startCamera);
  $('btnCamStop').addEventListener('click', stopCamera);
  $('btnCamScan').addEventListener('click', doOcrCapture);

  $('btnAutoScanNext').addEventListener('click', function() {
    const sku = this.dataset.sku;
    if (sku) { scanInput.value = sku; doScan(sku); }
  });

  document.addEventListener('click', function(e) {
    if (!e.target.closest('button,a,select,input:not(#scanInput),video')) {
      scanInput.focus();
    }
  });
});
</script>

<?php include '../../includes/footer.php'; ?>
