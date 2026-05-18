# Yaman Scanner Android Wrapper

This app wraps the existing web scanner page in a full-screen Android WebView.
It does not change, reimplement, or replace any scanning logic.

## URLs used
- Local emulator URL: `http://10.0.2.2/yaman/modules/sorting/index.php`
- Production URL: `https://yamanstore.org/modules/sorting/index.php`

Update `useLocalUrl` in `MainActivity.kt`:
- `true` = local
- `false` = production

## Build & run
1. Open `andriod-scanner-application` in Android Studio.
2. Allow Gradle sync.
3. Run on emulator or connected device.

## Build APK
- Debug APK: `./gradlew assembleDebug`
- Output path: `app/build/outputs/apk/debug/app-debug.apk`

## XAMPP connectivity (emulator)
1. Run Apache in XAMPP on host machine.
2. Ensure project is available at `http://localhost/yaman/modules/sorting/index.php` on host.
3. Android emulator accesses host localhost through `10.0.2.2`, so app URL works as:
   `http://10.0.2.2/yaman/modules/sorting/index.php`

## Required permissions
- `INTERNET`
- `CAMERA`
- `READ_MEDIA_IMAGES` (Android 13+)
- `READ_EXTERNAL_STORAGE` (Android 12 and below)

## Behavior notes
- JavaScript enabled.
- DOM storage enabled.
- File input (`<input type="file">`) enabled.
- Camera capture supported through file chooser.
- HTTPS URL supported directly.
- Cleartext traffic enabled for local HTTP testing.
