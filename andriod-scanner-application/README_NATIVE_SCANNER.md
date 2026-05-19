# Native Scanner Migration

## What changed
- Removed WebView architecture.
- App opens directly to a native scanner screen.
- CameraX + ML Kit for barcode/QR and OCR-based SKU extraction.
- Room local storage with `synced` flag.
- WorkManager background sync to `https://yamanstore.org/api/scan/upload.php`.

## Run steps
1. Open `andriod-scanner-application` in Android Studio.
2. Sync Gradle and run on device (Android 7+).
3. Grant camera permission.
4. Scan barcode/QR or type SKU and tap **Submit**.

## Backend setup
1. Deploy these files:
   - `api/scan/upload.php`
   - `api/scan/sync.php`
2. Ensure table `sorting_scan_logs` exists with columns:
   - `scan_code` (string)
   - `scanned_at` (datetime)
   - `source` (string)

## API payload
`POST /api/scan/upload.php`
```json
{
  "scans": [
    {"localId": 1, "scannedData": "SK1234567890", "timestamp": 1770000000000}
  ]
}
```
