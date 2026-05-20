import 'package:flutter/material.dart';
import 'package:camera/camera.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:vibration/vibration.dart';
import '../data/database_helper.dart';
import '../network/api_service.dart';

// ─────────────────────────────────────────────────────────────────────────────
// Entry: checks auth and shows either LoginScreen or ScannerScreen
// ─────────────────────────────────────────────────────────────────────────────

class AppEntry extends StatefulWidget {
  const AppEntry({super.key});

  @override
  State<AppEntry> createState() => _AppEntryState();
}

class _AppEntryState extends State<AppEntry> {
  bool _checking = true;
  bool _loggedIn = false;

  @override
  void initState() {
    super.initState();
    _checkAuth();
  }

  Future<void> _checkAuth() async {
    final loggedIn = await ApiService.instance.isLoggedIn();
    if (mounted) setState(() { _checking = false; _loggedIn = loggedIn; });
  }

  void _onLoggedIn() {
    if (mounted) setState(() => _loggedIn = true);
  }

  void _onLoggedOut() {
    if (mounted) setState(() => _loggedIn = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) {
      return const Scaffold(
        backgroundColor: Color(0xFF111827),
        body: Center(child: CircularProgressIndicator(color: Colors.white)),
      );
    }
    return _loggedIn
        ? ScannerScreen(onLoggedOut: _onLoggedOut)
        : LoginScreen(onLoggedIn: _onLoggedIn);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Login screen
// ─────────────────────────────────────────────────────────────────────────────

class LoginScreen extends StatefulWidget {
  final VoidCallback onLoggedIn;
  const LoginScreen({super.key, required this.onLoggedIn});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _loading = false;
  String _error = '';
  bool _obscure = true;

  Future<void> _login() async {
    final u = _userCtrl.text.trim();
    final p = _passCtrl.text.trim();
    if (u.isEmpty || p.isEmpty) {
      setState(() => _error = 'يرجى إدخال اسم المستخدم وكلمة المرور');
      return;
    }
    setState(() { _loading = true; _error = ''; });
    try {
      final resp = await ApiService.instance.login(u, p);
      if (resp.success) {
        widget.onLoggedIn();
      } else {
        setState(() => _error = resp.message.isNotEmpty ? resp.message : 'بيانات غير صحيحة');
      }
    } catch (e) {
      setState(() => _error = 'تعذر الاتصال بالسيرفر');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    _userCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF111827),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 40),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 72, height: 72,
                  decoration: BoxDecoration(
                    color: const Color(0xFF3B82F6),
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [BoxShadow(
                      color: const Color(0xFF3B82F6).withOpacity(.35),
                      blurRadius: 24, offset: const Offset(0, 8),
                    )],
                  ),
                  child: const Icon(Icons.qr_code_scanner, color: Colors.white, size: 38),
                ),
                const SizedBox(height: 20),
                const Text('Yaman Scanner',
                    style: TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.bold)),
                const SizedBox(height: 6),
                const Text('تسجيل الدخول للمتابعة',
                    style: TextStyle(color: Colors.white54, fontSize: 14)),
                const SizedBox(height: 36),
                _field(
                  controller: _userCtrl,
                  hint: 'اسم المستخدم أو البريد الإلكتروني',
                  icon: Icons.person_outline,
                  keyboardType: TextInputType.emailAddress,
                  textDirection: TextDirection.ltr,
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _passCtrl,
                  obscureText: _obscure,
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(color: Colors.white),
                  onSubmitted: (_) => _login(),
                  decoration: _inputDecoration(
                    hint: 'كلمة المرور',
                    icon: Icons.lock_outline,
                    suffix: IconButton(
                      icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility,
                          color: Colors.white38, size: 20),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                ),
                const SizedBox(height: 18),
                if (_error.isNotEmpty) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.red.withOpacity(.15),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.red.withOpacity(.4)),
                    ),
                    child: Text(_error,
                        style: const TextStyle(color: Colors.redAccent, fontSize: 13),
                        textAlign: TextAlign.center),
                  ),
                  const SizedBox(height: 14),
                ],
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: _loading ? null : _login,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF3B82F6),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                      elevation: 0,
                    ),
                    child: _loading
                        ? const SizedBox(width: 22, height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Text('دخول',
                            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _field({
    required TextEditingController controller,
    required String hint,
    required IconData icon,
    TextInputType? keyboardType,
    TextDirection? textDirection,
  }) {
    return TextField(
      controller: controller,
      keyboardType: keyboardType,
      textDirection: textDirection,
      style: const TextStyle(color: Colors.white),
      decoration: _inputDecoration(hint: hint, icon: icon),
    );
  }

  InputDecoration _inputDecoration({
    required String hint,
    required IconData icon,
    Widget? suffix,
  }) {
    return InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(color: Colors.white38),
      prefixIcon: Icon(icon, color: Colors.white38, size: 20),
      suffixIcon: suffix,
      filled: true,
      fillColor: const Color(0xFF1F2937),
      border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: Color(0xFF3B82F6)),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Scanner screen
// ─────────────────────────────────────────────────────────────────────────────

class ScannerScreen extends StatefulWidget {
  final VoidCallback? onLoggedOut;
  const ScannerScreen({super.key, this.onLoggedOut});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  CameraController? _cameraController;
  final TextRecognizer _textRecognizer =
      TextRecognizer(script: TextRecognitionScript.latin);

  bool _isProcessing = false;
  bool _locked = false;
  String _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
  String _syncInfo = 'آخر مزامنة: غير متوفر';
  String _detectedSku = '';
  StatusType _statusType = StatusType.idle;

  // Tracks pending count so badge refreshes properly
  int _pendingCount = 0;

  // Stability buffer
  final List<String> _skuHistory = [];
  static const int _stabilityFrames = 3;
  static const _skuPattern = r'SK-?\d{6,}';

  // Dedup within cooldown
  String _lastProcessedSku = '';
  DateTime _lastProcessedAt = DateTime.fromMillisecondsSinceEpoch(0);
  static const _dedupCooldown = Duration(seconds: 4);

  @override
  void initState() {
    super.initState();
    _initCamera();
    _refreshBadge();
    _autoSyncOrders();
  }

  Future<void> _refreshBadge() async {
    final count = await DatabaseHelper.instance.countUnsynced();
    if (mounted) setState(() => _pendingCount = count);
  }

  Future<void> _initCamera() async {
    final cameras = await availableCameras();
    if (cameras.isEmpty) {
      setState(() => _statusMessage = 'لا توجد كاميرا');
      return;
    }
    final camera = cameras.firstWhere(
      (c) => c.lensDirection == CameraLensDirection.back,
      orElse: () => cameras.first,
    );
    _cameraController = CameraController(
      camera,
      ResolutionPreset.high,
      enableAudio: false,
      imageFormatGroup: ImageFormatGroup.nv21,
    );
    await _cameraController!.initialize();
    if (!mounted) return;
    setState(() {});
    _cameraController!.startImageStream(_processFrame);
  }

  void _processFrame(CameraImage image) async {
    if (_isProcessing || _locked) return;
    _isProcessing = true;
    try {
      final inputImage = _buildInputImage(image);
      if (inputImage == null) return;

      final recognized = await _textRecognizer.processImage(inputImage);
      final regex = RegExp(_skuPattern, caseSensitive: false);

      String? found;
      for (final block in recognized.blocks) {
        final cleaned = block.text.replaceAll(RegExp(r'[\s\-—\u2014]'), '');
        final match = regex.firstMatch(cleaned);
        if (match != null) {
          found = match.group(0)!.toUpperCase().replaceAll('-', '');
          break;
        }
      }

      if (found != null) {
        _skuHistory.add(found);
        if (_skuHistory.length > _stabilityFrames) _skuHistory.removeAt(0);
        if (_skuHistory.length == _stabilityFrames &&
            _skuHistory.every((s) => s == found)) {
          final now = DateTime.now();
          if (found == _lastProcessedSku &&
              now.difference(_lastProcessedAt) < _dedupCooldown) {
            return;
          }
          _skuHistory.clear();
          _lastProcessedSku = found;
          _lastProcessedAt = now;
          await _onStableSku(found);
        }
      } else {
        _skuHistory.clear();
      }
    } finally {
      _isProcessing = false;
    }
  }

  InputImage? _buildInputImage(CameraImage image) {
    final camera = _cameraController?.description;
    if (camera == null) return null;
    final rotation =
        InputImageRotationValue.fromRawValue(camera.sensorOrientation) ??
            InputImageRotation.rotation0deg;
    final format = InputImageFormatValue.fromRawValue(image.format.raw);
    if (format == null) return null;
    final plane = image.planes.first;
    return InputImage.fromBytes(
      bytes: plane.bytes,
      metadata: InputImageMetadata(
        size: Size(image.width.toDouble(), image.height.toDouble()),
        rotation: rotation,
        format: format,
        bytesPerRow: plane.bytesPerRow,
      ),
    );
  }

  Future<void> _autoSyncOrders() async {
    try {
      final resp = await ApiService.instance.syncOrders();
      if (resp.success) {
        await DatabaseHelper.instance.replaceOrdersCache(resp.orders, resp.items);
        if (mounted) setState(() => _syncInfo = 'آخر مزامنة: الآن');
      }
    } catch (_) {
      final cached = await DatabaseHelper.instance.countCachedItems();
      if (!mounted) return;
      setState(() {
        _syncInfo = cached > 0 ? 'وضع أوف لاين — آخر مزامنة: متاحة محلياً' : 'لا توجد بيانات — يرجى المزامنة أولاً';
      });
    }
  }

  // ── Main scan handler ────────────────────────────────────────────────────

  Future<void> _onStableSku(String sku) async {
    _locked = true;
    setState(() {
      _detectedSku = sku;
      _statusMessage = 'جارٍ البحث...';
      _statusType = StatusType.loading;
    });

    final canVibrate = await Vibration.hasVibrator() ?? false;
    final matches = await DatabaseHelper.instance.findOrdersBySku(sku);

    if (matches.isEmpty) {
      setState(() {
        _statusMessage = 'SKU غير موجود في أي طلب';
        _statusType = StatusType.error;
      });
    } else if (matches.length == 1) {
      await _processSingleLocalMatch(sku, matches.first, canVibrate);
    } else {
      _locked = false;
      await _showOrderPicker(sku, matches.map((m) => OrderMatch(itemId: m.itemId, orderId: m.orderId, orderNumber: m.orderNumber, customerName: m.customerName, customerMobile: m.customerMobile, status: m.status)).toList());
      return;
    }

    await Future.delayed(const Duration(milliseconds: 2000));
    if (mounted) {
      setState(() {
        _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
        _statusType = StatusType.idle;
      });
    }
    _locked = false;
  }

  Future<void> _processSingleLocalMatch(String sku, LocalOrderMatch match, bool canVibrate) async {
    await DatabaseHelper.instance.markItemSorted(match.itemId);
    try {
      final response = await ApiService.instance.processScan(sku, selectedItemId: match.itemId);
      await _handleScanResponse(response, sku, canVibrate);
    } catch (_) {
      await DatabaseHelper.instance.insertScan(ScanRecord(sku: sku, timestamp: DateTime.now().millisecondsSinceEpoch, selectedItemId: match.itemId));
      await _refreshBadge();
      setState(() {
        _statusMessage = 'تم الفرز (محلياً)';
        _statusType = StatusType.offline;
      });
    }
  }

  Future<void> _handleScanResponse(
      ScanResponse response, String sku, bool canVibrate) async {
    if (response.requiresSelection) {
      // Multiple orders — show picker; unlock camera while user decides
      _locked = false;
      if (mounted) {
        setState(() {
          _statusMessage = 'SKU موجود في عدة طلبات';
          _statusType = StatusType.warning;
        });
        await _showOrderPicker(sku, response.matches);
      }
      return;
    }

    if (!response.success) {
      setState(() {
        _statusMessage = response.message;
        _statusType = StatusType.error;
      });
      if (canVibrate) Vibration.vibrate(pattern: [0, 100, 100, 100]);
    } else if (response.alreadyScanned) {
      setState(() {
        _statusMessage = 'تنبيه: هذا المنتج مفروز مسبقاً';
        _statusType = StatusType.warning;
      });
      if (canVibrate) Vibration.vibrate(pattern: [0, 200, 100, 200]);
    } else {
      setState(() {
        _statusMessage = response.allDone ? '🎉 تم الفرز بالكامل!' : 'تم الفرز ✅';
        _statusType = StatusType.success;
      });
      if (canVibrate) Vibration.vibrate(duration: 200);
    }
  }

  // ── Order picker bottom sheet ────────────────────────────────────────────

  Future<void> _showOrderPicker(String sku, List<OrderMatch> matches) async {
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _OrderPickerSheet(
        sku: sku,
        matches: matches,
        onSelect: (match) async {
          Navigator.of(context).pop();
          setState(() {
            _statusMessage = 'جارٍ الفرز للطلب ${match.orderNumber}...';
            _statusType = StatusType.loading;
          });
          _locked = true;
          final canVibrate = await Vibration.hasVibrator() ?? false;
          await DatabaseHelper.instance.markItemSorted(match.itemId);
          try {
            final response = await ApiService.instance
                .processScan(sku, selectedItemId: match.itemId);
            await _handleScanResponse(response, sku, canVibrate);
          } on UnauthorizedException {
            setState(() {
              _statusMessage = 'انتهت الجلسة — يرجى تسجيل الدخول';
              _statusType = StatusType.error;
            });
            await Future.delayed(const Duration(milliseconds: 1800));
            widget.onLoggedOut?.call();
            return;
          } catch (e) {
            // Save the user selection locally so sync can retry with context.
            final existing = await DatabaseHelper.instance.getUnsyncedBySku(sku);
            if (existing == null) {
              await DatabaseHelper.instance.insertScan(ScanRecord(
                sku: sku,
                timestamp: DateTime.now().millisecondsSinceEpoch,
                selectedItemId: match.itemId,
              ));
            } else {
              await DatabaseHelper.instance
                  .updateSelectedItemId(existing.id!, match.itemId);
            }
            await _refreshBadge();

            setState(() {
              _statusMessage = 'تم الفرز (محلياً)';
              _statusType = StatusType.offline;
            });
          }
          await Future.delayed(const Duration(milliseconds: 2500));
          if (mounted) {
            setState(() {
              _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
              _statusType = StatusType.idle;
            });
          }
          _locked = false;
        },
      ),
    );

    // If user dismissed without selecting, just unlock
    if (_locked) {
      setState(() {
        _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
        _statusType = StatusType.idle;
      });
      _locked = false;
    }
  }

  // ── Manual SKU entry ─────────────────────────────────────────────────────

  Future<void> _showManualEntry() async {
    final ctrl = TextEditingController();
    final entered = await showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: const Color(0xFF1F2937),
        title: const Text('إدخال SKU يدوياً',
            style: TextStyle(color: Colors.white, fontSize: 16)),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          textDirection: TextDirection.ltr,
          style: const TextStyle(color: Colors.white, letterSpacing: 1.2),
          decoration: InputDecoration(
            hintText: 'مثال: SK123456',
            hintStyle: const TextStyle(color: Colors.white38),
            filled: true,
            fillColor: const Color(0xFF374151),
            border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: BorderSide.none),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(color: Color(0xFF3B82F6)),
            ),
          ),
          onSubmitted: (v) => Navigator.of(context).pop(v.trim().toUpperCase()),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('إلغاء', style: TextStyle(color: Colors.white54)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF3B82F6),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () =>
                Navigator.of(context).pop(ctrl.text.trim().toUpperCase()),
            child: const Text('بحث'),
          ),
        ],
      ),
    );

    if (entered != null && entered.isNotEmpty && !_locked) {
      await _onStableSku(entered);
    }
  }

  // ── Sync offline scans ───────────────────────────────────────────────────

  Future<void> _syncNow() async {
    // Verify still logged in before syncing
    final loggedIn = await ApiService.instance.isLoggedIn();
    if (!loggedIn) {
      _showSnack('يرجى تسجيل الدخول أولاً');
      widget.onLoggedOut?.call();
      return;
    }

    final unsynced = await DatabaseHelper.instance.getUnsynced();
    if (unsynced.isEmpty) {
      _showSnack('لا توجد سجلات للمزامنة');
      return;
    }

    _showSnack('جارٍ مزامنة ${unsynced.length} سجل...');
    int synced = 0;
    int skipped = 0;

    for (final record in unsynced) {
      try {
        final response = await ApiService.instance
            .processScan(record.sku, selectedItemId: record.selectedItemId);

        if (response.requiresSelection) {
          // Can't resolve multi-order conflict automatically — ask the user
          if (!mounted) break;
          final resolved = await _showSyncOrderPicker(record, response.matches);
          if (!resolved) {
            skipped++;
            continue;
          }
          // After picker resolves it, record is now marked synced inside
          synced++;
          continue;
        }

        if (response.success || response.alreadyScanned) {
          await DatabaseHelper.instance.markSynced(record.id!);
          synced++;
        }
      } on UnauthorizedException {
        _showSnack('انتهت الجلسة ($synced تمت مزامنتهم)');
        await Future.delayed(const Duration(milliseconds: 1200));
        widget.onLoggedOut?.call();
        return;
      } catch (_) {
        break; // network gone — stop, try later
      }
    }

    // Clean up synced rows and refresh badge
    await DatabaseHelper.instance.deleteSynced();
    await _refreshBadge();

    if (skipped > 0) {
      _showSnack('تمت المزامنة: $synced / ${unsynced.length} (تخطي $skipped تحتاج تحديداً)');
    } else {
      _showSnack('تمت المزامنة: $synced / ${unsynced.length}');
    }
  }

  /// Shows the order picker during sync for a record that needs selection.
  /// Returns true if the user picked an order and the sync succeeded.
  Future<bool> _showSyncOrderPicker(
      ScanRecord record, List<OrderMatch> matches) async {
    if (!mounted) return false;

    OrderMatch? chosen;
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _OrderPickerSheet(
        sku: record.sku,
        matches: matches,
        onSelect: (m) {
          chosen = m;
          Navigator.of(context).pop();
        },
      ),
    );

    if (chosen == null) return false; // dismissed

    try {
      final response = await ApiService.instance
          .processScan(record.sku, selectedItemId: chosen!.itemId);
      if (response.success || response.alreadyScanned) {
        await DatabaseHelper.instance.markSynced(record.id!);
        return true;
      }
    } catch (_) {
      // network failed mid-sync — save the chosen item_id so next sync retries with it
      await DatabaseHelper.instance.updateSelectedItemId(
          record.id!, chosen!.itemId);
    }
    return false;
  }

  void _showSnack(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(msg), duration: const Duration(seconds: 3)));
  }

  Future<void> _logout() async {
    await ApiService.instance.clearToken();
    widget.onLoggedOut?.call();
  }

  @override
  void dispose() {
    _cameraController?.dispose();
    _textRecognizer.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: const Color(0xFF111827),
        title: const Text('Yaman Scanner',
            style: TextStyle(
                color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18)),
        actions: [
          // Manual entry button
          IconButton(
            icon: const Icon(Icons.keyboard_alt_outlined, color: Colors.white70),
            tooltip: 'إدخال SKU يدوياً',
            onPressed: _showManualEntry,
          ),
          // Sync badge button
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                icon: const Icon(Icons.sync, color: Colors.white),
                tooltip: 'مزامنة السجلات المحلية',
                onPressed: _syncNow,
              ),
              if (_pendingCount > 0)
                Positioned(
                  top: 8,
                  right: 8,
                  child: Container(
                    padding: const EdgeInsets.all(3),
                    decoration: const BoxDecoration(
                        color: Colors.orange, shape: BoxShape.circle),
                    child: Text(
                      '$_pendingCount',
                      style: const TextStyle(
                          fontSize: 9,
                          color: Colors.white,
                          fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
            ],
          ),
          // Logout
          IconButton(
            icon: const Icon(Icons.logout, color: Colors.white70),
            tooltip: 'تسجيل الخروج',
            onPressed: () async {
              final confirm = await showDialog<bool>(
                context: context,
                builder: (_) => AlertDialog(
                  backgroundColor: const Color(0xFF1F2937),
                  title: const Text('تسجيل الخروج',
                      style: TextStyle(color: Colors.white)),
                  content: const Text('هل تريد تسجيل الخروج؟',
                      style: TextStyle(color: Colors.white70)),
                  actions: [
                    TextButton(
                        onPressed: () => Navigator.pop(context, false),
                        child: const Text('إلغاء')),
                    TextButton(
                        onPressed: () => Navigator.pop(context, true),
                        child: const Text('خروج',
                            style: TextStyle(color: Colors.redAccent))),
                  ],
                ),
              );
              if (confirm == true) _logout();
            },
          ),
        ],
      ),
      body: _cameraController == null ||
              !_cameraController!.value.isInitialized
          ? const Center(child: CircularProgressIndicator(color: Colors.white))
          : Column(
              children: [
                Expanded(
                  flex: 3,
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      CameraPreview(_cameraController!),
                      CustomPaint(painter: _FocusOverlayPainter()),
                      Positioned(
                        top: 12, left: 0, right: 0,
                        child: Center(
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 14, vertical: 6),
                            decoration: BoxDecoration(
                              color: Colors.black54,
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: const Text('ضع ملصق SKU داخل الإطار',
                                style: TextStyle(
                                    color: Colors.white70, fontSize: 12)),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(
                      horizontal: 20, vertical: 16),
                  color: const Color(0xFF111827),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_detectedSku.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 8),
                          child: Text(
                            'SKU: $_detectedSku',
                            style: const TextStyle(
                              color: Colors.white54,
                              fontSize: 13,
                              letterSpacing: 1.4,
                              fontFamily: 'monospace',
                            ),
                          ),
                        ),
                      _StatusBadge(message: _statusMessage, type: _statusType),
                      const SizedBox(height: 8),
                      Text(_syncInfo, style: const TextStyle(color: Colors.white54, fontSize: 12)),
                    ],
                  ),
                ),
              ],
            ),
    );
  }
}

// ── Order picker bottom sheet ────────────────────────────────────────────────

class _OrderPickerSheet extends StatelessWidget {
  final String sku;
  final List<OrderMatch> matches;
  final void Function(OrderMatch) onSelect;

  const _OrderPickerSheet({
    required this.sku,
    required this.matches,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Color(0xFF1F2937),
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'SKU موجود في ${matches.length} طلبات — اختر الطلب',
            style: const TextStyle(
                color: Colors.white,
                fontSize: 15,
                fontWeight: FontWeight.bold),
            textDirection: TextDirection.rtl,
          ),
          Text(sku,
              style: const TextStyle(
                  color: Colors.blue,
                  fontSize: 13,
                  fontFamily: 'monospace',
                  letterSpacing: 1.2)),
          const SizedBox(height: 14),
          ...matches.map((m) => _MatchTile(match: m, onTap: () => onSelect(m))),
        ],
      ),
    );
  }
}

class _MatchTile extends StatelessWidget {
  final OrderMatch match;
  final VoidCallback onTap;

  const _MatchTile({required this.match, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: const Color(0xFF374151),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: Colors.blue.withOpacity(.3)),
        ),
        child: Row(
          textDirection: TextDirection.rtl,
          children: [
            const Icon(Icons.receipt_long, color: Colors.blue, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    match.orderNumber.isNotEmpty
                        ? match.orderNumber
                        : '#${match.orderId}',
                    style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 14),
                  ),
                  if (match.customerName.isNotEmpty)
                    Text(
                      '${match.customerName}  ${match.customerMobile}',
                      style: const TextStyle(
                          color: Colors.white60, fontSize: 12),
                      textDirection: TextDirection.rtl,
                    ),
                ],
              ),
            ),
            const Icon(Icons.chevron_left, color: Colors.white38, size: 20),
          ],
        ),
      ),
    );
  }
}

// ── Focus overlay ────────────────────────────────────────────────────────────

class _FocusOverlayPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final boxW = size.width * 0.78;
    final boxH = size.height * 0.22;
    final left = (size.width - boxW) / 2;
    final top = (size.height - boxH) / 2;
    final rect = Rect.fromLTWH(left, top, boxW, boxH);

    final overlayPath = Path()
      ..addRect(Rect.fromLTWH(0, 0, size.width, size.height))
      ..addRRect(RRect.fromRectAndRadius(rect, const Radius.circular(10)))
      ..fillType = PathFillType.evenOdd;
    canvas.drawPath(
        overlayPath,
        Paint()
          ..color = Colors.black.withOpacity(.55)
          ..style = PaintingStyle.fill);

    canvas.drawRRect(
        RRect.fromRectAndRadius(rect, const Radius.circular(10)),
        Paint()
          ..color = const Color(0xFF3B82F6)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5);

    final cp = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3
      ..strokeCap = StrokeCap.round;
    const c = 22.0;
    final tl = Offset(left, top);
    final tr = Offset(left + boxW, top);
    final bl = Offset(left, top + boxH);
    final br = Offset(left + boxW, top + boxH);

    canvas.drawLine(tl, tl + const Offset(c, 0), cp);
    canvas.drawLine(tl, tl + const Offset(0, c), cp);
    canvas.drawLine(tr, tr + const Offset(-c, 0), cp);
    canvas.drawLine(tr, tr + const Offset(0, c), cp);
    canvas.drawLine(bl, bl + const Offset(c, 0), cp);
    canvas.drawLine(bl, bl + const Offset(0, -c), cp);
    canvas.drawLine(br, br + const Offset(-c, 0), cp);
    canvas.drawLine(br, br + const Offset(0, -c), cp);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ── Status badge ─────────────────────────────────────────────────────────────

enum StatusType { idle, loading, success, error, offline, warning }

class _StatusBadge extends StatelessWidget {
  final String message;
  final StatusType type;

  const _StatusBadge({required this.message, required this.type});

  @override
  Widget build(BuildContext context) {
    final (color, icon) = switch (type) {
      StatusType.idle    => (const Color(0xFF374151), Icons.qr_code_scanner),
      StatusType.loading => (const Color(0xFF1D4ED8), Icons.hourglass_top),
      StatusType.success => (const Color(0xFF065F46), Icons.check_circle),
      StatusType.error   => (const Color(0xFF991B1B), Icons.error),
      StatusType.offline => (const Color(0xFF92400E), Icons.cloud_off),
      StatusType.warning => (const Color(0xFF92400E), Icons.warning_amber),
    };

    return AnimatedContainer(
      duration: const Duration(milliseconds: 250),
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
      decoration:
          BoxDecoration(color: color, borderRadius: BorderRadius.circular(28)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          type == StatusType.loading
              ? const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: Colors.white))
              : Icon(icon, color: Colors.white, size: 18),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              message,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 14,
                  fontWeight: FontWeight.w600),
              textAlign: TextAlign.center,
            ),
          ),
        ],
      ),
    );
  }
}
