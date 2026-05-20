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
        // Pull latest orders immediately after login so scanner works online-first
        // without waiting for user to open manual sync.
        try {
          final lastSyncTime = await DatabaseHelper.instance.getMetadata('lastSyncTime');
          final syncResp = await ApiService.instance.syncOrders(updatedAfter: lastSyncTime);
          if (syncResp.success) {
            await DatabaseHelper.instance.syncOrdersIncremental(syncResp.orders, syncResp.items);
            final humanTime = DateTime.now().toLocal().toString().substring(0, 16);
            await DatabaseHelper.instance.setMetadata('lastSyncTime', syncResp.syncTimestamp.toString());
            await DatabaseHelper.instance.setMetadata('lastSyncTimeHuman', humanTime);
          }
        } catch (_) {
          // Don't block login success if first sync fails; scanner screen can retry.
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

class _ScannerScreenState extends State<ScannerScreen> {
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

  // Periodic timer for connectivity monitoring & background sync
  Timer? _connectivityTimer;

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
    _loadSyncMetadata();
    
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

  @override
  void dispose() {
    _connectivityTimer?.cancel();
    _cameraController?.dispose();
    _textRecognizer.close();
    super.dispose();
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

  Future<void> _initCamera() async {
    final cameras = await availableCameras();
    if (cameras.isEmpty) {
      setState(() => _statusMessage = 'لا توجد كاميرا متوفرة');
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
    _locked = true;
    setState(() {
      _detectedSku = sku;
      _statusMessage = 'جارٍ البحث...';
      _statusType = StatusType.loading;
    });

    final canVibrate = await Vibration.hasVibrator() ?? false;
    
    // 1. Search local SQLite cache
    final matches = await DatabaseHelper.instance.findOrdersBySku(sku);

    if (matches.isNotEmpty) {
      if (matches.length == 1) {
        // Single match found locally
        await _processSingleLocalMatch(sku, matches.first, canVibrate);
      } else {
        // Multiple matches found locally — show picker
        _locked = false;
        await _showOrderPicker(
          sku, 
          matches.map((m) => OrderMatch(
            itemId: m.itemId, 
            orderId: m.orderId, 
            orderNumber: m.orderNumber, 
            customerName: m.customerName, 
            customerMobile: m.customerMobile, 
            status: m.status
          )).toList()
        );
        return;
      }
    } else {
      // 2. Local cache miss — perform online fallback lookup if connected
      try {
        final onlineResponse = await ApiService.instance.onlineSkuLookup(sku);
          if (onlineResponse.success && onlineResponse.matches.isNotEmpty) {
            if (onlineResponse.matches.length == 1) {
              final match = onlineResponse.matches.first;
              
              // Optimistically mark sorted locally
              await DatabaseHelper.instance.markItemSorted(match.itemId);
              
              // Process scan on server
              final scanResponse = await ApiService.instance.processScan(sku, selectedItemId: match.itemId);
              await _handleScanResponse(scanResponse, sku, canVibrate);
            } else {
              // Multiple matches found online
              _locked = false;
              await _showOrderPicker(sku, onlineResponse.matches);
              return;
            }
          } else {
            // SKU not found anywhere on server either
            setState(() {
              _statusMessage = 'SKU غير موجود في أي طلب';
              _statusType = StatusType.error;
            });
            if (canVibrate) Vibration.vibrate(pattern: [0, 100, 100, 100]);
          }
      } on UnauthorizedException {
        await ApiService.instance.forceSessionExpiration();
      } catch (_) {
        final online = await ApiService.instance.ping();
        if (online) {
          setState(() {
            _statusMessage = 'تعذر جلب بيانات الطلب من الخادم. حاول المزامنة ثم أعد المحاولة.';
            _statusType = StatusType.error;
          });
          if (canVibrate) Vibration.vibrate(pattern: [0, 100, 100, 100]);
        } else {
          await _handleOfflineCacheMiss(sku, canVibrate);
        }
      }
    }

    await Future.delayed(const Duration(milliseconds: 2200));
    if (mounted) {
      setState(() {
        _statusMessage = 'وجّه الكاميرا نحو ملصق SKU';
        _statusType = StatusType.idle;
      });
    }
    _locked = false;
  }

  Future<void> _processSingleLocalMatch(String sku, LocalOrderMatch match, bool canVibrate) async {
    // Optimistically mark item as sorted in local SQLite database
    await DatabaseHelper.instance.markItemSorted(match.itemId);
    
    try {
      // Attempt online push
      final response = await ApiService.instance.processScan(sku, selectedItemId: match.itemId);
      await _handleScanResponse(response, sku, canVibrate);
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
                      'آخر مزامنة ناجحة:',
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

  Future<void> _handleScanResponse(ScanResponse response, String sku, bool canVibrate) async {
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
      setState(() {
        _statusMessage = response.allDone ? '🎉 تم فرز الطلب بالكامل!' : 'تم الفرز بنجاح ✅';
        _statusType = StatusType.success;
      });
      if (canVibrate) Vibration.vibrate(duration: 200);
    }
  }

  // ── Order Picker Bottom Sheets ───────────────────────────────────────────

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
            final response = await ApiService.instance.processScan(sku, selectedItemId: match.itemId);
            await _handleScanResponse(response, sku, canVibrate);
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
    final ctrl = TextEditingController();
    final entered = await showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: const Color(0xFF1F2937),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        title: const Text(
          'إدخال SKU يدوياً',
          style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
          textAlign: TextAlign.right,
        ),
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
              borderSide: BorderSide.none,
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(color: Color(0xFF3B82F6), width: 1.5),
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
            onPressed: () => Navigator.of(context).pop(ctrl.text.trim().toUpperCase()),
            child: const Text('فرز'),
          ),
        ],
      ),
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
      _showSnack('جميع السجلات متزامنة بالفعل');
      return;
    }

    // 3. For any scans with selected_item_id == 0, attempt to resolve them against newly loaded cache
    for (var i = 0; i < unsynced.length; i++) {
      final scan = unsynced[i];
      if (scan.selectedItemId == 0) {
        final matches = await DatabaseHelper.instance.findOrdersBySku(scan.sku);
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
      } catch (_) {
        // network interrupted during batch sync
      }
    }

    // 6. Resolve multi-match conflicts sequentially using bottom sheets
    if (conflictScans.isNotEmpty) {
      for (final scan in conflictScans) {
        try {
          final response = await ApiService.instance.onlineSkuLookup(scan.sku);
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
        } catch (_) {
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
      final response = await ApiService.instance.processScan(record.sku, selectedItemId: chosen!.itemId);
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
// Order picker bottom sheet component
// ─────────────────────────────────────────────────────────────────────────────

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
          const Text(
            'تم العثور على الباركود في عدة طلبات',
            style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
            textAlign: TextAlign.right,
          ),
          const SizedBox(height: 4),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                sku,
                style: const TextStyle(color: Colors.blueAccent, fontSize: 13, fontFamily: 'monospace', fontWeight: FontWeight.bold),
              ),
              const Text(
                'الرجاء اختيار الطلب الذي ترغب في فرز الشحنة إليه:',
                style: TextStyle(color: Colors.white54, fontSize: 12),
                textAlign: TextAlign.right,
              ),
            ],
          ),
          const SizedBox(height: 16),
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.45),
            child: ListView.builder(
              shrinkWrap: true,
              itemCount: matches.length,
              itemBuilder: (context, idx) {
                final match = matches[idx];
                return _MatchTile(match: match, onTap: () => onSelect(match));
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
  final VoidCallback onTap;

  const _MatchTile({required this.match, required this.onTap});

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
                  if (match.customerName.isNotEmpty)
                    Text(
                      '${match.customerName} | ${match.customerMobile}',
                      style: const TextStyle(color: Colors.white54, fontSize: 12),
                      textDirection: TextDirection.rtl,
                    ),
                ],
              ),
            ),
            const Icon(Icons.chevron_left_rounded, color: Colors.white30, size: 20),
          ],
        ),
      ),
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
