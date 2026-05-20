import 'package:flutter/material.dart';
import 'package:camera/camera.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:vibration/vibration.dart';
import '../data/database_helper.dart';
import '../network/api_service.dart';

class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  CameraController? _cameraController;
  final TextRecognizer _textRecognizer =
      TextRecognizer(script: TextRecognitionScript.latin);

  bool _isProcessing = false;
  bool _locked = false;
  String _statusMessage = 'Point camera at SKU label';
  String _detectedSku = '';
  StatusType _statusType = StatusType.idle;

  // Stability: same SKU must appear 3 frames in a row
  final List<String> _skuHistory = [];
  static const int _stabilityFrames = 3;
  static const _skuPattern = r'SK\d+';

  @override
  void initState() {
    super.initState();
    _initCamera();
  }

  Future<void> _initCamera() async {
    final cameras = await availableCameras();
    if (cameras.isEmpty) {
      setState(() => _statusMessage = 'No camera found');
      return;
    }
    _cameraController = CameraController(
      cameras.first,
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
        final match = regex.firstMatch(block.text.replaceAll(' ', ''));
        if (match != null) {
          found = match.group(0)!.toUpperCase();
          break;
        }
      }

      if (found != null) {
        _skuHistory.add(found);
        if (_skuHistory.length > _stabilityFrames) _skuHistory.removeAt(0);
        if (_skuHistory.length == _stabilityFrames &&
            _skuHistory.every((s) => s == found)) {
          _skuHistory.clear();
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

    final rotation = InputImageRotationValue.fromRawValue(
            camera.sensorOrientation) ??
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

  Future<void> _onStableSku(String sku) async {
    _locked = true;
    if (mounted) {
      setState(() {
        _detectedSku = sku;
        _statusMessage = 'Sending...';
        _statusType = StatusType.loading;
      });
    }

    // Vibrate
    final canVibrate = await Vibration.hasVibrator() ?? false;
    if (canVibrate) Vibration.vibrate(duration: 120);

    try {
      final response = await ApiService.instance.processScan(sku);
      if (mounted) {
        setState(() {
          _statusMessage = response.message;
          _statusType =
              response.success ? StatusType.success : StatusType.error;
        });
      }
    } catch (_) {
      // Offline — save locally
      await DatabaseHelper.instance.insertScan(ScanRecord(
        sku: sku,
        timestamp: DateTime.now().millisecondsSinceEpoch,
      ));
      final count = await DatabaseHelper.instance.countUnsynced();
      if (mounted) {
        setState(() {
          _statusMessage = 'Offline saved: $sku ($count pending)';
          _statusType = StatusType.offline;
        });
      }
    }

    await Future.delayed(const Duration(milliseconds: 1500));
    if (mounted) {
      setState(() {
        _statusMessage = 'Point camera at SKU label';
        _statusType = StatusType.idle;
      });
    }
    _locked = false;
  }

  Future<void> _syncNow() async {
    final unsynced = await DatabaseHelper.instance.getUnsynced();
    if (unsynced.isEmpty) {
      _showSnack('Nothing to sync');
      return;
    }
    _showSnack('Syncing ${unsynced.length} records...');
    int synced = 0;
    for (final record in unsynced) {
      try {
        final response = await ApiService.instance.processScan(record.sku);
        if (response.success) {
          await DatabaseHelper.instance.markSynced(record.id!);
          synced++;
        }
      } catch (_) {
        break;
      }
    }
    _showSnack('Synced $synced / ${unsynced.length}');
  }

  void _showSnack(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(msg), duration: const Duration(seconds: 2)));
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
        backgroundColor: Colors.black,
        title: const Text('Yaman Scanner',
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        actions: [
          IconButton(
            icon: const Icon(Icons.sync, color: Colors.white),
            tooltip: 'Sync offline scans',
            onPressed: _syncNow,
          ),
        ],
      ),
      body: _cameraController == null || !_cameraController!.value.isInitialized
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                // Camera preview with focus box
                Expanded(
                  flex: 3,
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      CameraPreview(_cameraController!),
                      // Focus overlay
                      CustomPaint(painter: _FocusOverlayPainter()),
                    ],
                  ),
                ),
                // Status panel
                Expanded(
                  flex: 1,
                  child: Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(20),
                    color: Colors.black,
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        if (_detectedSku.isNotEmpty)
                          Text(
                            'SKU: $_detectedSku',
                            style: const TextStyle(
                              color: Colors.white70,
                              fontSize: 14,
                              letterSpacing: 1.2,
                            ),
                          ),
                        const SizedBox(height: 8),
                        _StatusBadge(
                            message: _statusMessage, type: _statusType),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}

// ── Focus overlay painter ──────────────────────────────────────────────────

class _FocusOverlayPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = Colors.black54
      ..style = PaintingStyle.fill;

    final boxW = size.width * 0.75;
    final boxH = size.height * 0.25;
    final left = (size.width - boxW) / 2;
    final top = (size.height - boxH) / 2;
    final rect = Rect.fromLTWH(left, top, boxW, boxH);

    // Dark overlay around the box
    final path = Path()
      ..addRect(Rect.fromLTWH(0, 0, size.width, size.height))
      ..addRRect(RRect.fromRectAndRadius(rect, const Radius.circular(8)))
      ..fillType = PathFillType.evenOdd;
    canvas.drawPath(path, paint);

    // Box border
    final borderPaint = Paint()
      ..color = const Color(0xFF1A73E8)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    canvas.drawRRect(
        RRect.fromRectAndRadius(rect, const Radius.circular(8)), borderPaint);

    // Corner accents
    final cornerPaint = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3
      ..strokeCap = StrokeCap.round;
    const cLen = 20.0;
    // Top-left
    canvas.drawLine(Offset(left, top + cLen), Offset(left, top), cornerPaint);
    canvas.drawLine(Offset(left, top), Offset(left + cLen, top), cornerPaint);
    // Top-right
    canvas.drawLine(
        Offset(left + boxW - cLen, top), Offset(left + boxW, top), cornerPaint);
    canvas.drawLine(
        Offset(left + boxW, top), Offset(left + boxW, top + cLen), cornerPaint);
    // Bottom-left
    canvas.drawLine(
        Offset(left, top + boxH - cLen), Offset(left, top + boxH), cornerPaint);
    canvas.drawLine(
        Offset(left, top + boxH), Offset(left + cLen, top + boxH), cornerPaint);
    // Bottom-right
    canvas.drawLine(Offset(left + boxW, top + boxH - cLen),
        Offset(left + boxW, top + boxH), cornerPaint);
    canvas.drawLine(Offset(left + boxW, top + boxH),
        Offset(left + boxW - cLen, top + boxH), cornerPaint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

// ── Status badge ───────────────────────────────────────────────────────────

enum StatusType { idle, loading, success, error, offline }

class _StatusBadge extends StatelessWidget {
  final String message;
  final StatusType type;

  const _StatusBadge({required this.message, required this.type});

  @override
  Widget build(BuildContext context) {
    final (color, icon) = switch (type) {
      StatusType.idle => (Colors.white24, Icons.qr_code_scanner),
      StatusType.loading => (Colors.blue.shade700, Icons.hourglass_top),
      StatusType.success => (Colors.green.shade700, Icons.check_circle),
      StatusType.error => (Colors.red.shade700, Icons.error),
      StatusType.offline => (Colors.orange.shade700, Icons.cloud_off),
    };

    return AnimatedContainer(
      duration: const Duration(milliseconds: 300),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(24),
      ),
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
            child: Text(message,
                style: const TextStyle(color: Colors.white, fontSize: 14),
                textAlign: TextAlign.center),
          ),
        ],
      ),
    );
  }
}
