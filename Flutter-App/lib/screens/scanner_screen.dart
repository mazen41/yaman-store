import 'dart:async';
import 'package:flutter/material.dart';
import 'package:camera/camera.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:vibration/vibration.dart';
import '../data/database_helper.dart';
import '../network/api_service.dart';

// ─────────────────────────────────────────────────────────────────────────────
// App entry point with authentication checker
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
    if (mounted) {
      setState(() {
        _checking = false;
        _loggedIn = loggedIn;
      });
    }
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
// Login Screen Component
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

  Future<void> _login() async {
    final u = _userCtrl.text.trim();
    final p = _passCtrl.text.trim();
    if (u.isEmpty || p.isEmpty) {
      setState(() => _error = 'يرجى إدخال اسم المستخدم وكلمة المرور');
      return;
    }
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final resp = await ApiService.instance.login(u, p);
      if (resp.success) {
        bool syncOk = false;
        try {
          final lastSyncTime = await DatabaseHelper.instance.getMetadata('lastSyncTime');
          final syncResp = await ApiService.instance.syncOrders(updatedAfter: lastSyncTime);
          if (syncResp.success) {
            await DatabaseHelper.instance.replaceOrdersCache(syncResp.orders, syncResp.items);
            final humanTime = DateTime.now().toLocal().toString().substring(0, 16);
            await DatabaseHelper.instance.setMetadata('lastSyncTime', syncResp.syncTimestamp.toString());
            await DatabaseHelper.instance.setMetadata('lastSyncTimeHuman', humanTime);
            debugPrint('[Login] sync success: orders=${syncResp.orders.length}, items=${syncResp.items.length}, total=${syncResp.totalOrders}');
            syncOk = true;
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text('Fetched ${syncResp.totalOrders} total orders', textDirection: TextDirection.ltr),
                backgroundColor: Colors.green,
                duration: const Duration(seconds: 4),
              ));
            }
          }
        } catch (e) {
          debugPrint('[Login] Auto-sync failed: $e');
        }
        if (mounted && !syncOk) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: const Text('تم تسجيل الدخول، لكن فشلت المزامنة. اضغط زر المزامنة.', textDirection: TextDirection.rtl),
            backgroundColor: Colors.orange, duration: const Duration(seconds: 5),
          ));
        }
        widget.onLoggedIn();
      } else {
        setState(() => _error = resp.message.isNotEmpty ? resp.message : 'بيانات الدخول غير صحيحة');
      }
    } catch (_) {
      setState(() => _error = 'تعذر الاتصال بالخادم. يرجى التحقق من اتصالك بالإنترنت');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF111827),
      body: SafeArea(
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 60),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                const SizedBox(height: 40),
                const Icon(Icons.qr_code_scanner_rounded, size: 80, color: Color(0xFF3B82F6)),
                const SizedBox(height: 16),
                const Text(
                  'Yaman Scanner',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 28,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 1.1,
                  ),
                ),
                const SizedBox(height: 8),
                const Text(
                  'نظام فرز الشحنات والباركود الذكي',
                  style: TextStyle(color: Colors.white54, fontSize: 14),
                ),
                const SizedBox(height: 60),
                Container(
                  padding: const EdgeInsets.all(24),
                  decoration: BoxDecoration(
                    color: const Color(0xFF1F2937),
                    borderRadius: BorderRadius.circular(16),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.2),
                        blurRadius: 10,
                        offset: const Offset(0, 4),
                      )
                    ],
                  ),
                  child: Column(
                    children: [
                      TextField(
                        controller: _userCtrl,
                        style: const TextStyle(color: Colors.white),
                        decoration: InputDecoration(
                          hintText: 'اسم المستخدم أو البريد الإلكتروني',
                          hintStyle: const TextStyle(color: Colors.white38),
                          prefixIcon: const Icon(Icons.person_outline, color: Colors.white38),
                          filled: true,
                          fillColor: const Color(0xFF374151),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide.none,
                          ),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: const BorderSide(color: Color(0xFF3B82F6), width: 1.5),
                          ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      TextField(
                        controller: _passCtrl,
                        obscureText: true,
                        style: const TextStyle(color: Colors.white),
                        decoration: InputDecoration(
                          hintText: 'كلمة المرور',
                          hintStyle: const TextStyle(color: Colors.white38),
                          prefixIcon: const Icon(Icons.lock_outline, color: Colors.white38),
                          filled: true,
                          fillColor: const Color(0xFF374151),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide.none,
                          ),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: const BorderSide(color: Color(0xFF3B82F6), width: 1.5),
                          ),
                        ),
                      ),
                      const SizedBox(height: 20),
                      if (_error.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 16),
                          child: Text(
                            _error,
                            style: const TextStyle(color: Colors.redAccent, fontSize: 13),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      SizedBox(
                        width: double.infinity,
                        height: 50,
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF3B82F6),
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          onPressed: _loading ? null : _login,
                          child: _loading
                              ? const SizedBox(
                                  width: 24,
                                  height: 24,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                )
                              : const Text('تسجيل الدخول', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Scanner Screen Component with Offline-First architecture
// ─────────────────────────────────────────────────────────────────────────────

class ScannerScreen extends StatefulWidget {
  final VoidCallback? onLoggedOut;

  const ScannerScreen({super.key, this.onLoggedOut});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> with WidgetsBindingObserver {
  CameraController? _cameraController;
  final TextRecognizer _textRecognizer = TextRecognizer(script: TextRecognitionScript.latin);

  bool _isProcessing = false;
  bool _locked = false;
  String _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
  String _syncInfo = 'آخر مزامنة: غير متوفر';
  String _detectedSku = '';
  StatusType _statusType = StatusType.idle;

  // Tracks pending count so badge refreshes properly
  int _pendingCount = 0;
  int _selectedPurchaseGroupId = 0;
  String _selectedPurchaseGroupLabel = 'كل المجموعات';
  List<Map<String, dynamic>> _purchaseGroups = [];
  List<Map<String, dynamic>> _sortingNotifications = [];
  int _sortingUnreadCount = 0;
  bool _loadingSortingNotifications = false;

  // Camera switching support
  List<CameraDescription> _backCameras = [];
  int _currentCameraIndex = 0;

  // Periodic timer for connectivity monitoring & background sync
  Timer? _connectivityTimer;

  // Stability buffer
  final List<String> _skuHistory = [];
  static const int _stabilityFrames = 3;
  static const _skuPattern = r'S[KA]-?\d{6,}';

  // Dedup within cooldown
  String _lastProcessedSku = '';
  DateTime _lastProcessedAt = DateTime.fromMillisecondsSinceEpoch(0);
  static const _dedupCooldown = Duration(seconds: 4);

  int _scanRequestSeq = 0;

  String _normalizeSku(String? value) {
    if (value == null) return '';
    return value.trim().toUpperCase().replaceAll(RegExp(r'[-\s\u00A0\u200B\u200C\u200D]'), '');
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _initCamera();
    _refreshBadge();
    _loadSyncMetadata();
    _loadPurchaseGroups();
    _loadSortingNotifications();
    
    // Register auto-logout on session expiration
    ApiService.instance.onSessionExpired = () {
      if (mounted) {
        widget.onLoggedOut?.call();
      }
    };

    // Auto sync on start
    _autoSyncOrders();

    // Start background network connectivity polling & auto synchronization
    _connectivityTimer = Timer.periodic(const Duration(seconds: 30), (timer) {
      _checkConnectivityAndAutoSync();
    });
  }

  Future<void> _loadPurchaseGroups() async {
    try {
      final groups = await ApiService.instance.fetchPurchaseGroups();
      if (mounted) setState(() => _purchaseGroups = groups);
    } catch (_) {}
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _connectivityTimer?.cancel();
    _cameraController?.dispose();
    _textRecognizer.close();
    super.dispose();
  }


  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.inactive || state == AppLifecycleState.paused || state == AppLifecycleState.detached) {
      _cameraController?.dispose();
      _cameraController = null;
      _isProcessing = false;
    } else if (state == AppLifecycleState.resumed && mounted) {
      _initCamera();
    }
  }

  Future<void> _refreshBadge() async {
    final count = await DatabaseHelper.instance.countUnsynced();
    if (mounted) setState(() => _pendingCount = count);
  }

  Future<void> _loadSyncMetadata() async {
    final lastSyncHuman = await DatabaseHelper.instance.getMetadata('lastSyncTimeHuman');
    final cached = await DatabaseHelper.instance.countCachedItems();
    if (mounted) {
      setState(() {
        if (lastSyncHuman != null) {
          _syncInfo = 'آخر مزامنة ناجحة: $lastSyncHuman';
        } else if (cached > 0) {
          _syncInfo = 'متاح بالفرز المحلي — بحاجة لمزامنة أولية';
        } else {
          _syncInfo = 'لم تتم المزامنة بعد — يرجى الضغط على زر التحديث';
        }
      });
    }
  }

  Future<void> _initCamera({int cameraIndex = 0}) async {
    if (_cameraController != null) {
      await _cameraController!.dispose();
      _cameraController = null;
    }
    final cameras = await availableCameras();
    if (cameras.isEmpty) {
      setState(() => _statusMessage = 'لا توجد كاميرا متوفرة');
      return;
    }
    final backCameras = cameras.where((c) => c.lensDirection == CameraLensDirection.back).toList();
    if (backCameras.isEmpty) {
      setState(() => _statusMessage = 'لا توجد كاميرا خلفية متوفرة');
      return;
    }
    // Store all back cameras for switching
    if (_backCameras.isEmpty) {
      _backCameras = backCameras;
    }
    final idx = cameraIndex.clamp(0, _backCameras.length - 1);
    _currentCameraIndex = idx;
    final camera = _backCameras[idx];
    _cameraController = CameraController(
      camera,
      ResolutionPreset.high,
      enableAudio: false,
      imageFormatGroup: ImageFormatGroup.nv21,
    );
    try {
      await _cameraController!.initialize();
      // Force minimum zoom to avoid telephoto lens on multi-camera phones
      final minZoom = await _cameraController!.getMinZoomLevel();
      await _cameraController!.setZoomLevel(minZoom);
    } catch (e) {
      debugPrint('[Camera] init failed: $e');
      if (mounted) setState(() => _statusMessage = 'تعذّر تشغيل الكاميرا');
      return;
    }
    if (!mounted) return;
    setState(() {});
    if (!_cameraController!.value.isStreamingImages) {
      await _cameraController!.startImageStream(_processFrame);
    }
  }

  Future<void> _switchCamera() async {
    if (_backCameras.length < 2) return;
    final nextIndex = (_currentCameraIndex + 1) % _backCameras.length;
    _showSnack('تبديل الكاميرا ${nextIndex + 1}/${_backCameras.length}');
    await _initCamera(cameraIndex: nextIndex);
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
    } catch (_) {
      // silent fail to avoid interruption of scanner preview
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

  // ── Auto & Background Sync Operations ────────────────────────────────────

  Future<void> _autoSyncOrders() async {
    final loggedIn = await ApiService.instance.isLoggedIn();
    if (!loggedIn) return;

    try {
      final lastSyncTime = await DatabaseHelper.instance.getMetadata('lastSyncTime');
      final resp = await ApiService.instance.syncOrders(updatedAfter: lastSyncTime);
      
      if (resp.success) {
        await DatabaseHelper.instance.syncOrdersIncremental(resp.orders, resp.items);
        final humanTime = DateTime.now().toLocal().toString().substring(0, 16);
        
        await DatabaseHelper.instance.setMetadata('lastSyncTime', resp.syncTimestamp.toString());
        await DatabaseHelper.instance.setMetadata('lastSyncTimeHuman', humanTime);
        
        _loadSyncMetadata();
      }
    } on UnauthorizedException {
      await ApiService.instance.forceSessionExpiration();
    } catch (_) {
      // Fallback: load metadata from sqlite cache
      _loadSyncMetadata();
    }
  }

  Future<void> _checkConnectivityAndAutoSync() async {
    final online = await ApiService.instance.ping();
    if (online) {
      final unsynced = await DatabaseHelper.instance.countUnsynced();
      if (unsynced > 0) {
        await _syncNow();
      } else {
        await _autoSyncOrders();
      }
    }
  }

  // ── Main scan logic ──────────────────────────────────────────────────────

  Future<void> _onStableSku(String sku) async {
    final normalizedSku = _normalizeSku(sku);
    if (normalizedSku.isEmpty) {
      debugPrint('[Scan] ignored empty/invalid SKU. raw="$sku"');
      return;
    }

    final int requestId = ++_scanRequestSeq;
    _locked = true;
    if (!mounted) return;
    setState(() {
      _detectedSku = normalizedSku;
      _statusMessage = 'جارٍ البحث...';
      _statusType = StatusType.loading;
    });

    debugPrint('[Scan] scanned SKU="$normalizedSku" (requestId=$requestId)');
    final canVibrate = await Vibration.hasVibrator() ?? false;

    try {
      final matches = [
        ...await DatabaseHelper.instance.findOrdersBySku(normalizedSku, sorted: false),
        ...await DatabaseHelper.instance.findOrdersBySku(normalizedSku, sorted: true),
      ];
      debugPrint('[Scan] fetched local orders count=${matches.length} for SKU="$normalizedSku"');

      if (!mounted || requestId != _scanRequestSeq) {
        debugPrint('[Scan] stale scan result discarded (requestId=$requestId, active=$_scanRequestSeq)');
        return;
      }

      if (matches.isNotEmpty) {
        debugPrint('[Scan] matched orders count=${matches.length} for SKU="$normalizedSku"');
        _locked = false;
        await _showOrderPicker(
          normalizedSku,
          matches
              .map<OrderMatch>((m) => OrderMatch(
                    itemId: m.itemId,
                    orderId: m.orderId,
                    orderNumber: m.orderNumber,
                    customerName: m.customerName,
                    customerMobile: m.customerMobile,
                    status: m.status,
                    isSorted: m.isSorted,
                    totalSkus: m.totalSkus,
                  ))
              .toList(),
          isMultipleSameSku: matches.length > 1 &&
              matches.every((x) => x.orderId == matches.first.orderId),
        );
        return;
      }

      final onlineResponse = await ApiService.instance.onlineSkuLookup(
        normalizedSku,
        purchaseGroupId: _selectedPurchaseGroupId,
      );

      if (!mounted || requestId != _scanRequestSeq) {
        debugPrint('[Scan] stale online result discarded (requestId=$requestId, active=$_scanRequestSeq)');
        return;
      }

      debugPrint('[Scan] fetched online orders count=${onlineResponse.matches.length} for SKU="$normalizedSku"');

      if (onlineResponse.success && onlineResponse.matches.isNotEmpty) {
        debugPrint('[Scan] matched orders count=${onlineResponse.matches.length} for SKU="$normalizedSku"');
        _locked = false;
        await _showOrderPicker(normalizedSku, onlineResponse.matches);
        return;
      }

      setState(() {
        _statusMessage = 'هذا الـ SKU غير موجود في أي طلب';
        _statusType = StatusType.error;
      });
      if (canVibrate) Vibration.vibrate(pattern: [0, 100, 100, 100]);
    } on UnauthorizedException {
      await ApiService.instance.forceSessionExpiration();
    } catch (e) {
      debugPrint('[Scan] lookup failed for SKU="$normalizedSku": $e');
      final online = await ApiService.instance.ping();
      if (online) {
        if (!mounted || requestId != _scanRequestSeq) return;
        setState(() {
          _statusMessage = 'تعذر جلب بيانات الطلب من الخادم: $e';
          _statusType = StatusType.error;
        });
        if (canVibrate) Vibration.vibrate(pattern: [0, 100, 100, 100]);
      } else {
        await _handleOfflineCacheMiss(normalizedSku, canVibrate);
      }
    } finally {
      await Future.delayed(const Duration(milliseconds: 1800));
      if (mounted && requestId == _scanRequestSeq && !_locked) {
        setState(() {
          _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
          _statusType = StatusType.idle;
        });
      }
      _locked = false;
    }
  }

  Future<void> _processSingleLocalMatch(String sku, LocalOrderMatch match, bool canVibrate) async {
    try {
      // Attempt online push
      final response = await ApiService.instance.processScan(sku, selectedItemId: match.itemId, purchaseGroupId: _selectedPurchaseGroupId);
      await _handleScanResponse(response, sku, canVibrate, sortedItemId: match.itemId);
    } catch (_) {
      // Save scan to pending local queue for automatic retry
      await DatabaseHelper.instance.insertScan(ScanRecord(
        sku: sku, 
        timestamp: DateTime.now().millisecondsSinceEpoch, 
        selectedItemId: match.itemId
      ));
      await _refreshBadge();
      setState(() {
        _statusMessage = 'تم الحفظ في وضع عدم الاتصال (محلياً)';
        _statusType = StatusType.offline;
      });
      if (canVibrate) Vibration.vibrate(duration: 150);
    }
  }

  Future<void> _handleOfflineCacheMiss(String sku, bool canVibrate) async {
    final lastSyncHuman = await DatabaseHelper.instance.getMetadata('lastSyncTimeHuman') ?? 'غير متوفر';
    
    if (canVibrate) {
      Vibration.vibrate(pattern: [0, 150, 100, 150, 100, 150]);
    }

    // Save scan as unresolved (selected_item_id = 0) to local SQLite pending queue
    await DatabaseHelper.instance.insertScan(ScanRecord(
      sku: sku,
      timestamp: DateTime.now().millisecondsSinceEpoch,
      selectedItemId: 0,
    ));
    await _refreshBadge();

    // Show Arabic alert dialog detailing cache miss warning
    if (mounted) {
      await showDialog(
        context: context,
        barrierDismissible: true,
        builder: (_) => AlertDialog(
          backgroundColor: const Color(0xFF1F2937),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              Text(
                'الطلب غير متوفر محلياً',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18),
                textAlign: TextAlign.right,
              ),
              SizedBox(width: 10),
              Icon(Icons.wifi_off_rounded, color: Colors.orangeAccent, size: 24),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                'أنت في وضع عدم الاتصال بالإنترنت. الطلبات الجديدة التي تم إنشاؤها بعد المزامنة الأخيرة قد لا تكون متوفرة.',
                style: TextStyle(color: Colors.white.withOpacity(0.75), fontSize: 14, height: 1.4),
                textAlign: TextAlign.right,
                textDirection: TextDirection.rtl,
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFF374151),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      lastSyncHuman,
                      style: const TextStyle(color: Colors.orangeAccent, fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                    const Text(
                      'آخر مزامنة للطلبات كانت:',
                      style: TextStyle(color: Colors.white60, fontSize: 13),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              RichText(
                textDirection: TextDirection.rtl,
                text: TextSpan(
                  text: 'الرمز الممسوح: ',
                  style: const TextStyle(color: Colors.white38, fontSize: 13),
                  children: [
                    TextSpan(
                      text: sku,
                      style: const TextStyle(color: Colors.blueAccent, fontWeight: FontWeight.bold, fontFamily: 'monospace'),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'تمت إضافة هذا المسح إلى قائمة الانتظار المحلية وسيتم إرساله وتأكيده تلقائياً فور استعادة الاتصال بالإنترنت.',
                style: TextStyle(color: Colors.white54, fontSize: 12, height: 1.3),
                textAlign: TextAlign.right,
                textDirection: TextDirection.rtl,
              ),
            ],
          ),
          actions: [
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF3B82F6),
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('موافق'),
            ),
          ],
        ),
      );
    }

    setState(() {
      _statusMessage = 'حفظ بالانتظار (غير متوفر محلياً) ⏳';
      _statusType = StatusType.offline;
    });
  }

  Future<void> _handleScanResponse(ScanResponse response, String sku, bool canVibrate, {int? sortedItemId}) async {
    if (response.requiresSelection) {
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
      if (sortedItemId != null && sortedItemId > 0) {
        await DatabaseHelper.instance.markItemSorted(sortedItemId);
      }
      setState(() {
        _statusMessage = response.allDone ? '🎉 تم فرز الطلب بالكامل!' : 'تم الفرز بنجاح ✅';
        _statusType = StatusType.success;
      });
      if (canVibrate) Vibration.vibrate(duration: 200);
    }
  }

  // ── Order Picker Bottom Sheets ───────────────────────────────────────────

  Future<void> _showOrderPicker(String sku, List<OrderMatch> matches, {
    bool isMultipleSameSku = false,
  }) async {
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _OrderPickerSheet(
        sku: sku,
        matches: matches,
        isMultipleSameSku: isMultipleSameSku,
        onUndo: (match) async {
          Navigator.of(context).pop();
          try {
            await ApiService.instance.undoSort(match.itemId);
            await DatabaseHelper.instance.markItemUnsorted(match.itemId);
            _showSnack('تم إلغاء الفرز للطلب ${match.orderNumber}');
          } catch (e) {
            _showSnack('فشل إلغاء الفرز: $e');
          }
        },
        onSelect: (match) async {
          Navigator.of(context).pop();
          setState(() {
            _statusMessage = 'جارٍ الفرز للطلب ${match.orderNumber}...';
            _statusType = StatusType.loading;
          });
          _locked = true;
          final canVibrate = await Vibration.hasVibrator() ?? false;
          
          try {
            final response = await ApiService.instance.processScan(sku, selectedItemId: match.itemId, purchaseGroupId: _selectedPurchaseGroupId);
            await _handleScanResponse(response, sku, canVibrate, sortedItemId: match.itemId);
          } catch (e) {
            // Save selection locally for resilient background syncing
            final existing = await DatabaseHelper.instance.getUnsyncedBySku(sku);
            if (existing == null) {
              await DatabaseHelper.instance.insertScan(ScanRecord(
                sku: sku,
                timestamp: DateTime.now().millisecondsSinceEpoch,
                selectedItemId: match.itemId,
              ));
            } else {
              await DatabaseHelper.instance.updateSelectedItemId(existing.id!, match.itemId);
            }
            await _refreshBadge();

            setState(() {
              _statusMessage = 'تم الحفظ في الانتظار (محلياً)';
              _statusType = StatusType.offline;
            });
            if (canVibrate) Vibration.vibrate(duration: 150);
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

    if (_locked) {
      setState(() {
        _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
        _statusType = StatusType.idle;
      });
      _locked = false;
    }
  }

  // ── Manual Entry Form ─────────────────────────────────────────────────────

  Future<void> _showManualEntry() async {
    final entered = await showDialog<String>(
      context: context,
      builder: (_) => const _ManualSkuDialog(),
    );
    if (entered != null && entered.isNotEmpty && !_locked) {
      await _onStableSku(entered);
    }
  }

  // ── Sync Actions Trigger ──────────────────────────────────────────────────

  Future<void> _syncNow() async {
    final loggedIn = await ApiService.instance.isLoggedIn();
    if (!loggedIn) {
      _showSnack('انتهت الجلسة. يرجى تسجيل الدخول.');
      widget.onLoggedOut?.call();
      return;
    }

    final online = await ApiService.instance.ping();
    if (!online) {
      _showSnack('تعذر الاتصال بالخادم. يرجى التحقق من اتصالك بالشبكة.');
      return;
    }

    // 1. First trigger incremental sync to pull down updates
    await _autoSyncOrders();

    // 2. Load unsynced records
    List<ScanRecord> unsynced = await DatabaseHelper.instance.getUnsynced();
    if (unsynced.isEmpty) {
      final cached = await DatabaseHelper.instance.countCachedItems();
      _showSnack(cached > 0 ? 'تمت مزامنة الطلبات ✅ ($cached منتج محلياً)' : 'تمت المزامنة — لا طلبات نشطة حالياً');
      await _loadSyncMetadata();
      return;
    }

    // 3. For any scans with selected_item_id == 0, attempt to resolve them against newly loaded cache
    for (var i = 0; i < unsynced.length; i++) {
      final scan = unsynced[i];
      if (scan.selectedItemId == 0) {
        final matches = await DatabaseHelper.instance.findUnsortedOrdersBySku(scan.sku);
        if (matches.length == 1) {
          await DatabaseHelper.instance.updateSelectedItemId(scan.id!, matches.first.itemId);
          unsynced[i] = ScanRecord(
            id: scan.id,
            sku: scan.sku,
            timestamp: scan.timestamp,
            selectedItemId: matches.first.itemId,
            synced: scan.synced,
          );
        }
      }
    }

    _showSnack('جارٍ رفع ${unsynced.length} عملية فرز...');

    // 4. Split queue into immediately dispatchable scans vs conflict resolution scans
    List<ScanRecord> readyToSync = [];
    List<ScanRecord> conflictScans = [];

    for (final scan in unsynced) {
      if (scan.selectedItemId > 0) {
        readyToSync.add(scan);
      } else {
        conflictScans.add(scan);
      }
    }

    int successCount = 0;

    // 5. Batch synchronize simple scans to the server
    if (readyToSync.isNotEmpty) {
      try {
        final scansList = readyToSync.map((s) => {
          'id': s.id,
          'sku': s.sku,
          'selected_item_id': s.selectedItemId,
          'timestamp': s.timestamp ~/ 1000
        }).toList();

        final response = await ApiService.instance.syncOfflineScans(scansList);
        final results = response['results'] as List<dynamic>? ?? [];

        for (final res in results) {
          final localId = int.tryParse(res['id'].toString());
          final success = res['success'] == true;
          
          if (localId != null && success) {
            await DatabaseHelper.instance.markSynced(localId);
            successCount++;
          }
        }
      } catch (e) {
        // network interrupted during batch sync
      }
    }

    // 6. Resolve multi-match conflicts sequentially using bottom sheets
    if (conflictScans.isNotEmpty) {
      for (final scan in conflictScans) {
        try {
          final response = await ApiService.instance.onlineSkuLookup(scan.sku, purchaseGroupId: _selectedPurchaseGroupId);
          if (response.success && response.matches.isNotEmpty) {
            if (!mounted) break;
            final resolved = await _showSyncOrderPicker(scan, response.matches);
            if (resolved) {
              successCount++;
            }
          } else {
            // Not found anywhere, delete local dead scan to avoid blocking
            await DatabaseHelper.instance.markSynced(scan.id!);
          }
        } catch (e) {
          break; // network disconnected
        }
      }
    }

    // Purge successfully synced scans and refresh
    await DatabaseHelper.instance.deleteSynced();
    await _refreshBadge();

    final remaining = await DatabaseHelper.instance.countUnsynced();
    if (remaining > 0) {
      _showSnack('تمت المزامنة: $successCount فرز بنجاح. المتبقي: $remaining شحنات');
    } else {
      _showSnack('🎉 تمت مزامنة جميع عمليات الفرز بنجاح!');
    }
  }


  Future<void> _loadSortingNotifications({bool showErrors = false}) async {
    if (_loadingSortingNotifications) return;
    setState(() => _loadingSortingNotifications = true);
    try {
      final response = await ApiService.instance.fetchSortingNotifications(afterId: 0, limit: 500);
      final notifications = (response['notifications'] as List<dynamic>? ?? const [])
          .whereType<Map>()
          .map((n) => n.cast<String, dynamic>())
          .toList()
          .reversed
          .toList();
      final unreadCount = int.tryParse((response['unread_count'] ?? '0').toString()) ??
          notifications.where((n) => int.tryParse((n['is_read'] ?? '0').toString()) == 0).length;
      if (mounted) {
        setState(() {
          _sortingNotifications = notifications;
          _sortingUnreadCount = unreadCount;
          _loadingSortingNotifications = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _loadingSortingNotifications = false);
      if (showErrors) _showSnack('تعذر تحميل إشعارات الفرز: $e');
    }
  }

  Future<void> _showSortingNotificationsSheet() async {
    await _loadSortingNotifications(showErrors: true);
    if (!mounted) return;

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF111827),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => _SortingNotificationsSheet(
        notifications: _sortingNotifications,
        unreadCount: _sortingUnreadCount,
        loading: _loadingSortingNotifications,
      ),
    );
  }

  Future<void> _forceRefreshOrders() async {
    final loggedIn = await ApiService.instance.isLoggedIn();
    if (!loggedIn) {
      _showSnack('انتهت الجلسة. يرجى تسجيل الدخول.');
      widget.onLoggedOut?.call();
      return;
    }

    final online = await ApiService.instance.ping();
    if (!online) {
      _showSnack('تعذر الاتصال بالخادم. يرجى التحقق من اتصالك بالشبكة.');
      return;
    }

    try {
      final resp = await ApiService.instance.syncOrders(updatedAfter: null);
      if (!resp.success) {
        _showSnack('فشل تحديث الطلبات من الخادم');
        return;
      }

      await DatabaseHelper.instance.replaceOrdersCache(resp.orders, resp.items);
      final humanTime = DateTime.now().toLocal().toString().substring(0, 16);
      await DatabaseHelper.instance.setMetadata('lastSyncTime', resp.syncTimestamp.toString());
      await DatabaseHelper.instance.setMetadata('lastSyncTimeHuman', humanTime);
      await _loadSyncMetadata();

      final cached = await DatabaseHelper.instance.countCachedItems();
      _showSnack(cached > 0 ? 'تم تحديث الطلبات ✅ ($cached منتج محلياً)' : 'تم تحديث الطلبات — لا طلبات نشطة حالياً');
    } on UnauthorizedException {
      await ApiService.instance.forceSessionExpiration();
      widget.onLoggedOut?.call();
    } catch (_) {
      _showSnack('تعذر تحديث الطلبات حالياً، حاول مرة أخرى');
    }
  }

  Future<bool> _showSyncOrderPicker(ScanRecord record, List<OrderMatch> matches) async {
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

    if (chosen == null) return false;

    try {
      final response = await ApiService.instance.processScan(record.sku, selectedItemId: chosen!.itemId, purchaseGroupId: _selectedPurchaseGroupId);
      if (response.success || response.alreadyScanned) {
        await DatabaseHelper.instance.markSynced(record.id!);
        return true;
      }
    } catch (_) {
      // Save local selection state for subsequent sync retries
      await DatabaseHelper.instance.updateSelectedItemId(record.id!, chosen!.itemId);
    }
    return false;
  }

  void _showSnack(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg, textDirection: TextDirection.rtl),
        behavior: SnackBarBehavior.floating,
        duration: const Duration(seconds: 3),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
    );
  }

  Future<void> _selectPurchaseGroup() async {
    final selected = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      backgroundColor: const Color(0xFF1F2937),
      builder: (_) => ListView(
        children: [
          ListTile(
            title: const Text('كل المجموعات', style: TextStyle(color: Colors.white)),
            onTap: () => Navigator.pop(context, {'id': 0, 'label': 'كل المجموعات'}),
          ),
          ..._purchaseGroups.map((g) => ListTile(
                title: Text((g['label'] ?? '').toString(), style: const TextStyle(color: Colors.white)),
                onTap: () => Navigator.pop(context, {'id': g['id'] ?? 0, 'label': g['label'] ?? ''}),
              )),
        ],
      ),
    );
    if (selected != null && mounted) {
      setState(() {
        _selectedPurchaseGroupId = int.tryParse(selected['id'].toString()) ?? 0;
        _selectedPurchaseGroupLabel = selected['label'].toString();
      });
    }
  }

  Future<void> _sortSelectedPurchaseGroup() async {
    if (_selectedPurchaseGroupId <= 0) {
      _showSnack('اختر مجموعة شراء أولاً');
      return;
    }
    setState(() {
      _statusType = StatusType.loading;
      _statusMessage = 'جارٍ فرز مجموعة الشراء...';
    });
    try {
      final result = await ApiService.instance.sortPurchaseGroup(_selectedPurchaseGroupId);
      setState(() {
        _statusType = StatusType.success;
        _statusMessage = (result['message'] ?? 'تم فرز المجموعة').toString();
      });
      await _autoSyncOrders();
      await _loadSortingNotifications();
    } catch (e) {
      setState(() {
        _statusType = StatusType.error;
        _statusMessage = 'فشل فرز المجموعة: $e';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: const Color(0xFF111827),
        elevation: 0,
        title: const Text(
          'Yaman Scanner',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.layers_outlined, color: Colors.white70),
            tooltip: 'اختيار مجموعة الشراء',
            onPressed: _selectPurchaseGroup,
          ),
          IconButton(
            icon: const Icon(Icons.sort_rounded, color: Colors.greenAccent),
            tooltip: 'فرز المجموعة المحددة',
            onPressed: _sortSelectedPurchaseGroup,
          ),
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                icon: const Icon(Icons.notifications_outlined, color: Colors.white70),
                tooltip: 'إشعارات الفرز',
                onPressed: _showSortingNotificationsSheet,
              ),
              if (_sortingUnreadCount > 0)
                Positioned(
                  top: 8,
                  right: 8,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                    decoration: const BoxDecoration(color: Colors.redAccent, shape: BoxShape.circle),
                    child: Text(
                      _sortingUnreadCount > 99 ? '99+' : '$_sortingUnreadCount',
                      style: const TextStyle(fontSize: 8, color: Colors.white, fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
            ],
          ),
          IconButton(
            icon: const Icon(Icons.logout_rounded, color: Colors.white70),
            tooltip: 'تسجيل الخروج',
            onPressed: () async {
              await ApiService.instance.logout();
              widget.onLoggedOut?.call();
            },
          ),
          IconButton(
            icon: const Icon(Icons.keyboard_alt_outlined, color: Colors.white70),
            tooltip: 'إدخال SKU يدوياً',
            onPressed: _showManualEntry,
          ),
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                icon: const Icon(Icons.sync_rounded, color: Colors.white),
                tooltip: 'مزامنة السجلات',
                onPressed: _syncNow,
              ),
              if (_pendingCount > 0)
                Positioned(
                  top: 8,
                  right: 8,
                  child: Container(
                    padding: const EdgeInsets.all(4),
                    decoration: const BoxDecoration(color: Colors.orange, shape: BoxShape.circle),
                    child: Text(
                      '$_pendingCount',
                      style: const TextStyle(fontSize: 8, color: Colors.white, fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
      body: _cameraController == null || !_cameraController!.value.isInitialized
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
                        top: 16,
                        left: 0,
                        right: 0,
                        child: Center(
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                            decoration: BoxDecoration(
                              color: Colors.black.withOpacity(0.74),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: const Text(
                              'ضع ملصق SKU داخل الإطار المخصص للفرز',
                              style: TextStyle(color: Colors.white70, fontSize: 12),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 20),
                  decoration: const BoxDecoration(
                    color: Color(0xFF111827),
                    borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_detectedSku.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Text(
                            'SKU: $_detectedSku',
                            style: const TextStyle(
                              color: Colors.blueAccent,
                              fontSize: 14,
                              letterSpacing: 1.4,
                              fontWeight: FontWeight.bold,
                              fontFamily: 'monospace',
                            ),
                          ),
                        ),
                      _StatusBadge(message: _statusMessage, type: _statusType),
                      const SizedBox(height: 8),
                      Text(
                        'مجموعة الشراء: $_selectedPurchaseGroupLabel',
                        style: const TextStyle(color: Colors.white70, fontSize: 12),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 12),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          OutlinedButton.icon(
                            icon: const Icon(Icons.refresh_rounded),
                            label: const Text('تحديث الطلبات'),
                            onPressed: _forceRefreshOrders,
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.info_outline_rounded, size: 14, color: Colors.white38),
                          const SizedBox(width: 4),
                          Text(
                            _syncInfo,
                            style: const TextStyle(color: Colors.white38, fontSize: 11),
                            textAlign: TextAlign.center,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
    );
  }
}


// ─────────────────────────────────────────────────────────────────────────────
// Sorting notifications bottom sheet component
// ─────────────────────────────────────────────────────────────────────────────

class _SortingNotificationsSheet extends StatelessWidget {
  final List<Map<String, dynamic>> notifications;
  final int unreadCount;
  final bool loading;

  const _SortingNotificationsSheet({
    required this.notifications,
    required this.unreadCount,
    required this.loading,
  });

  String _text(Map<String, dynamic> notification, String key) => (notification[key] ?? '').toString();

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: DraggableScrollableSheet(
        initialChildSize: 0.72,
        minChildSize: 0.4,
        maxChildSize: 0.92,
        expand: false,
        builder: (context, scrollController) {
          return Column(
            children: [
              Container(
                width: 44,
                height: 4,
                margin: const EdgeInsets.only(top: 12, bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: Row(
                  children: [
                    const Icon(Icons.notifications_active_outlined, color: Colors.amberAccent),
                    const SizedBox(width: 10),
                    const Expanded(
                      child: Text(
                        'إشعارات الفرز',
                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 18),
                        textDirection: TextDirection.rtl,
                      ),
                    ),
                    if (unreadCount > 0)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                        decoration: BoxDecoration(
                          color: Colors.redAccent.withOpacity(0.18),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(color: Colors.redAccent.withOpacity(0.5)),
                        ),
                        child: Text(
                          '$unreadCount جديد',
                          style: const TextStyle(color: Colors.redAccent, fontSize: 12, fontWeight: FontWeight.bold),
                          textDirection: TextDirection.rtl,
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              if (loading)
                const Expanded(child: Center(child: CircularProgressIndicator(color: Colors.white)))
              else if (notifications.isEmpty)
                const Expanded(
                  child: Center(
                    child: Text(
                      'لا توجد إشعارات فرز حالياً',
                      style: TextStyle(color: Colors.white60),
                      textDirection: TextDirection.rtl,
                    ),
                  ),
                )
              else
                Expanded(
                  child: ListView.separated(
                    controller: scrollController,
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
                    itemBuilder: (context, index) {
                      final notification = notifications[index];
                      final isUnread = int.tryParse(_text(notification, 'is_read')) == 0;
                      final type = _text(notification, 'type');
                      final orderNumber = _text(notification, 'order_number').isNotEmpty
                          ? '#${_text(notification, 'order_number')}'
                          : 'طلب #${_text(notification, 'order_id')}';
                      final sku = _text(notification, 'sku');
                      final createdBy = _text(notification, 'created_by_name');
                      return Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: isUnread ? const Color(0xFF312331) : const Color(0xFF1F2937),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: isUnread ? Colors.redAccent.withOpacity(0.4) : Colors.white10),
                        ),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(
                              type == 'order_complete' ? Icons.task_alt_rounded : Icons.inventory_2_outlined,
                              color: type == 'order_complete' ? Colors.greenAccent : Colors.lightBlueAccent,
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          orderNumber,
                                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                                          textDirection: TextDirection.rtl,
                                        ),
                                      ),
                                      if (isUnread)
                                        const Text(
                                          'جديد',
                                          style: TextStyle(color: Colors.redAccent, fontSize: 11, fontWeight: FontWeight.bold),
                                          textDirection: TextDirection.rtl,
                                        ),
                                    ],
                                  ),
                                  const SizedBox(height: 6),
                                  Text(
                                    _text(notification, 'message'),
                                    style: const TextStyle(color: Colors.white70, height: 1.35),
                                    textDirection: TextDirection.rtl,
                                  ),
                                  const SizedBox(height: 8),
                                  Wrap(
                                    spacing: 8,
                                    runSpacing: 4,
                                    children: [
                                      if (sku.isNotEmpty) _MetaChip(label: 'SKU: $sku'),
                                      if (createdBy.isNotEmpty) _MetaChip(label: createdBy),
                                      _MetaChip(label: _text(notification, 'created_at')),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      );
                    },
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemCount: notifications.length,
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

class _MetaChip extends StatelessWidget {
  final String label;

  const _MetaChip({required this.label});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: Colors.white10,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: const TextStyle(color: Colors.white54, fontSize: 11),
        textDirection: TextDirection.rtl,
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Order picker bottom sheet component
// ─────────────────────────────────────────────────────────────────────────────

class _OrderPickerSheet extends StatefulWidget {
  final String sku;
  final List<OrderMatch> matches;
  final void Function(OrderMatch) onSelect;
  final Future<void> Function(OrderMatch)? onUndo;
  final bool isMultipleSameSku;

  const _OrderPickerSheet({
    required this.sku,
    required this.matches,
    required this.onSelect,
    this.onUndo,
    this.isMultipleSameSku = false,
  });

  @override
  State<_OrderPickerSheet> createState() => _OrderPickerSheetState();
}

class _OrderPickerSheetState extends State<_OrderPickerSheet> {
  bool _showSorted = false;

  @override
  Widget build(BuildContext context) {
    final filteredMatches = widget.matches.where((m) => _showSorted ? m.isSorted : !m.isSorted).toList();
    final bool sameOrder = widget.isMultipleSameSku || (filteredMatches.isNotEmpty && filteredMatches.every((m) => m.orderId == filteredMatches.first.orderId));
    final Map<int, int> perOrderCounter = {};
    final List<_OrderPickerViewItem> viewItems = filteredMatches.map((m) {
      final idx = (perOrderCounter[m.orderId] ?? 0) + 1;
      perOrderCounter[m.orderId] = idx;
      return _OrderPickerViewItem(match: m, itemIndexInOrder: idx);
    }).toList();

    return Container(
      decoration: const BoxDecoration(
        color: Color(0xFF1F2937),
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 36),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 45,
              height: 4,
              decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 20),
          Text(
            widget.isMultipleSameSku
                ? 'يوجد أكثر من منتج بنفس الـ SKU لهذا الطلب، اختر المنتج المراد فرزه'
                : 'تم العثور على الباركود في عدة طلبات',
            style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
            textAlign: TextAlign.right,
          ),
          const SizedBox(height: 6),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                widget.sku,
                style: const TextStyle(color: Colors.blueAccent, fontSize: 13, fontFamily: 'monospace', fontWeight: FontWeight.bold),
              ),
              Text(
                sameOrder ? 'الرجاء اختيار المنتج المطلوب:' : 'الرجاء اختيار الطلب الذي ترغب في فرز الشحنة إليه:',
                style: const TextStyle(color: Colors.white54, fontSize: 12),
                textAlign: TextAlign.right,
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            textDirection: TextDirection.rtl,
            children: [
              ChoiceChip(
                label: const Text('Not Sorted'),
                selected: !_showSorted,
                onSelected: (_) => setState(() => _showSorted = false),
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                label: const Text('Sorted'),
                selected: _showSorted,
                onSelected: (_) => setState(() => _showSorted = true),
              ),
            ],
          ),
          const SizedBox(height: 16),
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.45),
            child: viewItems.isEmpty
                ? Center(
                    child: Text(
                      _showSorted ? 'لا توجد منتجات مفروزة لهذا SKU' : 'لا توجد منتجات غير مفروزة لهذا SKU',
                      style: const TextStyle(color: Colors.white54),
                      textAlign: TextAlign.center,
                    ),
                  )
                : ListView.builder(
              shrinkWrap: true,
              itemCount: viewItems.length,
              itemBuilder: (context, idx) {
                final item = viewItems[idx];
                return _MatchTile(
                  match: item.match,
                  itemIndexInOrder: item.itemIndexInOrder,
                  sameOrderMode: sameOrder,
                  onTap: () => widget.onSelect(item.match),
                  onUndo: item.match.isSorted && widget.onUndo != null
                      ? () => widget.onUndo!(item.match)
                      : null,
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _MatchTile extends StatelessWidget {
  final OrderMatch match;
  final int itemIndexInOrder;
  final bool sameOrderMode;
  final VoidCallback onTap;
  final VoidCallback? onUndo;

  const _MatchTile({
    required this.match,
    required this.itemIndexInOrder,
    required this.sameOrderMode,
    required this.onTap,
    this.onUndo,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: const Color(0xFF374151),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.blueAccent.withOpacity(.15), width: 1),
        ),
        child: Row(
          textDirection: TextDirection.rtl,
          children: [
            const Icon(Icons.receipt_long_rounded, color: Colors.blueAccent, size: 24),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    match.orderNumber.isNotEmpty ? match.orderNumber : '#${match.orderId}',
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15),
                  ),
                  const SizedBox(height: 4),
                  if (sameOrderMode)
                    Text(
                      'منتج #$itemIndexInOrder',
                      style: const TextStyle(color: Colors.white70, fontSize: 12),
                      textDirection: TextDirection.rtl,
                    )
                  else if (match.customerName.isNotEmpty)
                    Text(
                      '${match.customerName} | ${match.customerMobile} | SKUs: ${match.totalSkus}',
                      style: const TextStyle(color: Colors.white54, fontSize: 12),
                      textDirection: TextDirection.rtl,
                    ),
                ],
              ),
            ),
            if (onUndo != null)
              IconButton(
                icon: const Icon(Icons.undo_rounded, color: Colors.orangeAccent),
                tooltip: 'Undo sort',
                onPressed: onUndo,
              )
            else
              const Icon(Icons.chevron_left_rounded, color: Colors.white30, size: 20),
          ],
        ),
      ),
    );
  }
}

class _OrderPickerViewItem {
  final OrderMatch match;
  final int itemIndexInOrder;

  const _OrderPickerViewItem({
    required this.match,
    required this.itemIndexInOrder,
  });
}

// ─────────────────────────────────────────────────────────────────────────────
// Manual SKU Entry Dialog with live autocomplete
// ─────────────────────────────────────────────────────────────────────────────

class _ManualSkuDialog extends StatefulWidget {
  const _ManualSkuDialog();

  @override
  State<_ManualSkuDialog> createState() => _ManualSkuDialogState();
}

class _ManualSkuDialogState extends State<_ManualSkuDialog> {
  final _ctrl = TextEditingController();
  List<Map<String, String>> _suggestions = [];
  bool _loading = false;
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    _ctrl.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    final q = value.trim().toUpperCase();
    _debounce?.cancel();
    if (q.length < 2) {
      setState(() => _suggestions = []);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), () => _fetch(q));
  }

  Future<void> _fetch(String prefix) async {
    setState(() => _loading = true);
    try {
      final results = await DatabaseHelper.instance.searchSkusByPrefix(prefix);
      if (mounted) setState(() => _suggestions = results);
    } catch (_) {}
    if (mounted) setState(() => _loading = false);
  }

  void _submit(String val) {
    final v = val.trim().toUpperCase();
    if (v.isNotEmpty) Navigator.of(context).pop(v);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: const Color(0xFF1F2937),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      title: const Text(
        'إدخال SKU يدوياً',
        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
        textAlign: TextAlign.right,
      ),
      content: SizedBox(
        width: double.maxFinite,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: _ctrl,
              autofocus: true,
              textDirection: TextDirection.ltr,
              textCapitalization: TextCapitalization.characters,
              style: const TextStyle(color: Colors.white, letterSpacing: 1.2),
              decoration: InputDecoration(
                hintText: 'مثال: SK123456',
                hintStyle: const TextStyle(color: Colors.white38),
                filled: true,
                fillColor: const Color(0xFF374151),
                suffixIcon: _loading
                    ? const Padding(
                        padding: EdgeInsets.all(12),
                        child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white38)),
                      )
                    : null,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10),
                  borderSide: const BorderSide(color: Color(0xFF3B82F6), width: 1.5),
                ),
              ),
              onChanged: _onChanged,
              onSubmitted: _submit,
            ),
            if (_suggestions.isNotEmpty)
              Container(
                margin: const EdgeInsets.only(top: 6),
                constraints: const BoxConstraints(maxHeight: 200),
                decoration: BoxDecoration(
                  color: const Color(0xFF374151),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: Colors.white12),
                ),
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: _suggestions.length,
                  separatorBuilder: (_, __) => const Divider(height: 1, color: Colors.white12),
                  itemBuilder: (_, i) {
                    final item = _suggestions[i];
                    final sku = item['sku'] ?? '';
                    final orderNumber = item['order_number'] ?? '';
                    final customerName = item['customer_name'] ?? '';
                    return InkWell(
                      onTap: () => _submit(sku),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        child: Row(
                          textDirection: TextDirection.rtl,
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.end,
                                children: [
                                  Text(
                                    sku,
                                    style: const TextStyle(color: Colors.white, fontFamily: 'monospace', letterSpacing: 1.1, fontWeight: FontWeight.bold),
                                  ),
                                  if (orderNumber.isNotEmpty || customerName.isNotEmpty)
                                    Text(
                                      [if (orderNumber.isNotEmpty) '#$orderNumber', if (customerName.isNotEmpty) customerName].join(' | '),
                                      style: const TextStyle(color: Colors.white54, fontSize: 11),
                                      textDirection: TextDirection.rtl,
                                    ),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            const Icon(Icons.chevron_left_rounded, color: Colors.white30, size: 18),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
          ],
        ),
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
          onPressed: () => _submit(_ctrl.text),
          child: const Text('فرز'),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Focus Framing Overlay Custom Painter
// ─────────────────────────────────────────────────────────────────────────────

class _FocusOverlayPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final boxW = size.width * 0.82;
    final boxH = size.height * 0.24;
    final left = (size.width - boxW) / 2;
    final top = (size.height - boxH) / 2;
    final rect = Rect.fromLTWH(left, top, boxW, boxH);

    final overlayPath = Path()
      ..addRect(Rect.fromLTWH(0, 0, size.width, size.height))
      ..addRRect(RRect.fromRectAndRadius(rect, const Radius.circular(12)))
      ..fillType = PathFillType.evenOdd;
    canvas.drawPath(
      overlayPath,
      Paint()
        ..color = Colors.black.withOpacity(.6)
        ..style = PaintingStyle.fill,
    );

    canvas.drawRRect(
      RRect.fromRectAndRadius(rect, const Radius.circular(12)),
      Paint()
        ..color = const Color(0xFF3B82F6).withOpacity(0.5)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.0,
    );

    final cp = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3.5
      ..strokeCap = StrokeCap.round;
    const c = 24.0;
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

// ─────────────────────────────────────────────────────────────────────────────
// Styled status badge
// ─────────────────────────────────────────────────────────────────────────────

enum StatusType { idle, loading, success, error, offline, warning }

class _StatusBadge extends StatelessWidget {
  final String message;
  final StatusType type;

  const _StatusBadge({required this.message, required this.type});

  @override
  Widget build(BuildContext context) {
    final (color, icon) = switch (type) {
      StatusType.idle    => (const Color(0xFF374151), Icons.qr_code_scanner_rounded),
      StatusType.loading => (const Color(0xFF1D4ED8), Icons.hourglass_top_rounded),
      StatusType.success => (const Color(0xFF065F46), Icons.check_circle_rounded),
      StatusType.error   => (const Color(0xFF991B1B), Icons.error_rounded),
      StatusType.offline => (const Color(0xFFB45309), Icons.wifi_off_rounded),
      StatusType.warning => (const Color(0xFFB45309), Icons.warning_amber_rounded),
    };

    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(28)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          type == StatusType.loading
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                )
              : Icon(icon, color: Colors.white, size: 20),
          const SizedBox(width: 10),
          Flexible(
            child: Text(
              message,
              style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w600),
              textAlign: TextAlign.center,
              textDirection: TextDirection.rtl,
            ),
          ),
        ],
      ),
    );
  }
}
