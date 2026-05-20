import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../data/database_helper.dart';

// ── Base Configurations ──────────────────────────────────────────────────
// You can point this to your local server (e.g. 'http://10.0.2.2/yaman/') when testing on Android Emulator
const String _baseUrl = 'https://yamanstore.org';
const String _apiLogin = '$_baseUrl/api/login.php';
const String _apiRefreshToken = '$_baseUrl/api/refresh-token.php';
const String _apiLogout = '$_baseUrl/api/logout.php';
const String _apiRegisterDevice = '$_baseUrl/api/register-device.php';
const String _apiOrders = '$_baseUrl/api/orders.php';
const String _apiSkuLookup = '$_baseUrl/api/sku-lookup.php';
const String _apiSyncActions = '$_baseUrl/api/sync-actions.php';

// ── Response types ────────────────────────────────────────────────────────

class ScanResponse {
  final bool success;
  final String message;
  final bool alreadyScanned;
  final bool allDone;
  final bool requiresSelection;
  final String sku;
  final List<OrderMatch> matches;

  ScanResponse({
    required this.success,
    required this.message,
    this.alreadyScanned = false,
    this.allDone = false,
    this.requiresSelection = false,
    this.sku = '',
    this.matches = const [],
  });

  factory ScanResponse.fromJson(Map<String, dynamic> json) {
    final requiresSel = json['requires_selection'] == true;
    return ScanResponse(
      success: json['success'] == true,
      message: json['message'] ?? '',
      alreadyScanned: json['already_scanned'] == true,
      allDone: json['all_done'] == true,
      requiresSelection: requiresSel,
      sku: (json['sku'] ?? '').toString(),
      matches: requiresSel
          ? (json['matches'] as List<dynamic>? ?? [])
              .map((m) => OrderMatch.fromJson(m as Map<String, dynamic>))
              .toList()
          : [],
    );
  }
}

class OrderMatch {
  final int itemId;
  final int orderId;
  final String orderNumber;
  final String customerName;
  final String customerMobile;
  final String status;

  OrderMatch({
    required this.itemId,
    required this.orderId,
    required this.orderNumber,
    required this.customerName,
    required this.customerMobile,
    required this.status,
  });

  factory OrderMatch.fromJson(Map<String, dynamic> json) => OrderMatch(
        itemId: int.tryParse(json['item_id'].toString()) ?? 0,
        orderId: int.tryParse(json['order_id'].toString()) ?? 0,
        orderNumber: json['order_number'] ?? '',
        customerName: json['customer_name'] ?? '',
        customerMobile: json['customer_mobile'] ?? '',
        status: json['status'] ?? '',
      );
}

class SyncOrdersResponse {
  final bool success;
  final String message;
  final List<Map<String, dynamic>> orders;
  final List<Map<String, dynamic>> items;
  final int syncTimestamp;

  SyncOrdersResponse({
    required this.success,
    required this.message,
    required this.orders,
    required this.items,
    required this.syncTimestamp,
  });

  factory SyncOrdersResponse.fromJson(Map<String, dynamic> json) {
    final ordersRaw = (json['orders'] as List<dynamic>? ?? []);
    final itemsRaw = (json['items'] as List<dynamic>? ?? []);
    return SyncOrdersResponse(
      success: json['success'] == true,
      message: (json['message'] ?? '').toString(),
      syncTimestamp: int.tryParse(json['sync_timestamp'].toString()) ?? (DateTime.now().millisecondsSinceEpoch ~/ 1000),
      orders: ordersRaw.map((o) {
        final m = o as Map<String, dynamic>;
        return {
          'order_id': int.tryParse(m['order_id'].toString()) ?? 0,
          'order_number': (m['order_number'] ?? '').toString(),
          'customer_name': (m['customer_name'] ?? '').toString(),
          'customer_mobile': (m['customer_mobile'] ?? '').toString(),
          'status': (m['status'] ?? '').toString(),
          'updated_at': int.tryParse(m['updated_at'].toString()) ?? 0,
        };
      }).toList(),
      items: itemsRaw.map((i) {
        final m = i as Map<String, dynamic>;
        return {
          'item_id': int.tryParse(m['item_id'].toString()) ?? 0,
          'order_id': int.tryParse(m['order_id'].toString()) ?? 0,
          'sku': (m['sku'] ?? '').toString(),
          'is_sorted': (m['is_sorted'] == true || m['is_sorted'].toString() == '1') ? 1 : 0,
          'product_name': (m['product_name'] ?? '').toString(),
          'product_image': (m['product_image'] ?? '').toString(),
        };
      }).toList(),
    );
  }
}

class LoginResponse {
  final bool success;
  final String accessToken;
  final String refreshToken;
  final String name;
  final String role;
  final String message;

  LoginResponse({
    required this.success,
    this.accessToken = '',
    this.refreshToken = '',
    this.name = '',
    this.role = '',
    this.message = '',
  });

  factory LoginResponse.fromJson(Map<String, dynamic> json) => LoginResponse(
        success: json['success'] == true,
        accessToken: (json['access_token'] ?? '').toString(),
        refreshToken: (json['refresh_token'] ?? '').toString(),
        name: (json['user']?['name'] ?? '').toString(),
        role: (json['user']?['role'] ?? '').toString(),
        message: (json['message'] ?? '').toString(),
      );
}

// ── API Service ───────────────────────────────────────────────────────────

class ApiService {
  static final ApiService instance = ApiService._();
  ApiService._();

  String? _cachedAccessToken;
  String? _cachedRefreshToken;
  int? _cachedTokenExpiry;
  bool _isRefreshing = false;

  // Callback to notify UI to force logout when refresh fails
  void Function()? onSessionExpired;

  // ── Session & Token Management ───────────────────────────────────────────

  Future<void> _initTokens() async {
    if (_cachedAccessToken != null) return;
    final prefs = await SharedPreferences.getInstance();
    _cachedAccessToken = prefs.getString('access_token');
    _cachedRefreshToken = prefs.getString('refresh_token');
    _cachedTokenExpiry = prefs.getInt('token_expires_at');
  }

  Future<String?> getAccessToken() async {
    await _initTokens();
    return _cachedAccessToken;
  }

  Future<String?> getRefreshToken() async {
    await _initTokens();
    return _cachedRefreshToken;
  }

  Future<void> saveSession(LoginResponse loginResp) async {
    _cachedAccessToken = loginResp.accessToken;
    _cachedRefreshToken = loginResp.refreshToken;
    
    // access token expires in 7 days
    final expiresAt = DateTime.now().millisecondsSinceEpoch + (7 * 24 * 60 * 60 * 1000);
    _cachedTokenExpiry = expiresAt;

    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('access_token', loginResp.accessToken);
    await prefs.setString('refresh_token', loginResp.refreshToken);
    await prefs.setInt('token_expires_at', expiresAt);

    // Save details to Local DB Cache
    if (loginResp.name.isNotEmpty) {
      await DatabaseHelper.instance.cacheUser(
        0, // Dummy ID or you can parse user ID from server response
        loginResp.name,
        loginResp.role,
      );
    }
  }

  Future<bool> isLoggedIn() async {
    final token = await getAccessToken();
    return token != null && token.isNotEmpty;
  }

  Future<void> clearSession() async {
    _cachedAccessToken = null;
    _cachedRefreshToken = null;
    _cachedTokenExpiry = null;
    
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('access_token');
    await prefs.remove('refresh_token');
    await prefs.remove('token_expires_at');
    
    await DatabaseHelper.instance.clearCachedUser();
  }

  // ── Header builder with automatic bearer token ────────────────────────────

  Future<Map<String, String>> _jsonHeaders() async {
    final token = await getValidToken();
    return {
      'Content-Type': 'application/json',
      if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
    };
  }

  // ── Active Token Rotation Interceptor ─────────────────────────────────────

  Future<String?> getValidToken() async {
    await _initTokens();
    if (_cachedAccessToken == null) return null;

    final now = DateTime.now().millisecondsSinceEpoch;
    // If token expires in less than 5 minutes, force rotation preemptively
    final isExpiredOrNear = _cachedTokenExpiry == null || (_cachedTokenExpiry! - now) < 5 * 60 * 1000;

    if (isExpiredOrNear && _cachedRefreshToken != null && !_isRefreshing) {
      _isRefreshing = true;
      try {
        final rotated = await refreshRotationToken();
        if (rotated) {
          return _cachedAccessToken;
        }
      } catch (e) {
        // rotation failed
      } finally {
        _isRefreshing = false;
      }
    }

    return _cachedAccessToken;
  }

  Future<bool> refreshRotationToken() async {
    if (_cachedRefreshToken == null) return false;

    try {
      final response = await http.post(
        Uri.parse(_apiRefreshToken),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'refresh_token': _cachedRefreshToken}),
      ).timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        if (json['success'] == true) {
          _cachedAccessToken = json['access_token']?.toString() ?? '';
          _cachedRefreshToken = json['refresh_token']?.toString() ?? '';
          
          final expiresIn = int.tryParse(json['expires_in'].toString()) ?? (7 * 24 * 60 * 60);
          final expiresAt = DateTime.now().millisecondsSinceEpoch + (expiresIn * 1000);
          _cachedTokenExpiry = expiresAt;

          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('access_token', _cachedAccessToken!);
          await prefs.setString('refresh_token', _cachedRefreshToken!);
          await prefs.setInt('token_expires_at', expiresAt);
          return true;
        }
      }
      
      if (response.statusCode == 401 || response.statusCode == 403) {
        // Refresh token itself is expired or invalid, trigger logout
        await forceSessionExpiration();
      }
    } catch (_) {
      // Network issues, allow using existing token if still valid as best-effort
    }
    return false;
  }

  Future<void> forceSessionExpiration() async {
    await clearSession();
    if (onSessionExpired != null) {
      onSessionExpired!();
    }
  }

  // ── Authentication Endpoints ─────────────────────────────────────────────

  Future<LoginResponse> login(String username, String password) async {
    try {
      final response = await http.post(
        Uri.parse(_apiLogin),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'username': username, 'password': password}),
      ).timeout(const Duration(seconds: 15));

      final json = jsonDecode(response.body) as Map<String, dynamic>;
      final loginResp = LoginResponse.fromJson(json);
      
      if (loginResp.success && loginResp.accessToken.isNotEmpty) {
        await saveSession(loginResp);
        // Automatically register the device after successful login
        await registerDevice();
      }
      return loginResp;
    } catch (e) {
      return LoginResponse(
        success: false,
        message: 'خطأ في الاتصال بالخادم: $e',
      );
    }
  }

  Future<void> logout() async {
    try {
      final headers = await _jsonHeaders();
      await http.post(
        Uri.parse(_apiLogout),
        headers: headers,
      ).timeout(const Duration(seconds: 5));
    } catch (_) {}
    await clearSession();
  }

  Future<bool> registerDevice() async {
    try {
      final headers = await _jsonHeaders();
      final response = await http.post(
        Uri.parse(_apiRegisterDevice),
        headers: headers,
        body: jsonEncode({
          'device_id': 'flutter_device_unique_id', // Ideally retrieve via device_info_plus or package_info
          'device_name': 'Mobile Scanner Client',
          'platform': 'android',
          'app_version': '1.1.0',
          'fcm_token': '' // FCM push token if available
        }),
      ).timeout(const Duration(seconds: 8));
      
      if (response.statusCode == 401) {
        // Retry once with refreshed token in case token was expired
        final retryHeaders = await _jsonHeaders();
        final retryResponse = await http.post(
          Uri.parse(_apiRegisterDevice),
          headers: retryHeaders,
          body: jsonEncode({
            'device_id': 'flutter_device_unique_id',
            'device_name': 'Mobile Scanner Client',
            'platform': 'android',
            'app_version': '1.1.0',
            'fcm_token': ''
          }),
        ).timeout(const Duration(seconds: 8));
        return retryResponse.statusCode == 200;
      }
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  // ── Network health check ──────────────────────────────────────────────────

  Future<bool> ping() async {
    try {
      // Connectivity probe should only verify internet reachability, not auth state.
      final response = await http.get(
        Uri.parse('$_baseUrl/api/login.php'),
      ).timeout(const Duration(seconds: 5));
      return response.statusCode < 500;
    } catch (_) {
      return false;
    }
  }

  // ── Core Sync Actions ─────────────────────────────────────────────────────

  Future<SyncOrdersResponse> syncOrders({String? updatedAfter}) async {
    final headers = await _jsonHeaders();
    final url = Uri.parse(_apiOrders + (updatedAfter != null && updatedAfter.isNotEmpty ? '?updated_after=$updatedAfter' : ''));

    final response = await http.get(url, headers: headers).timeout(const Duration(seconds: 30));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return SyncOrdersResponse.fromJson(json);
    } else if (response.statusCode == 401) {
      // Trigger token rotation and retry once
      final tokenRefreshed = await refreshRotationToken();
      if (tokenRefreshed) {
        final retryHeaders = await _jsonHeaders();
        final retryResponse = await http.get(url, headers: retryHeaders).timeout(const Duration(seconds: 30));
        if (retryResponse.statusCode == 200) {
          final json = jsonDecode(retryResponse.body) as Map<String, dynamic>;
          return SyncOrdersResponse.fromJson(json);
        }
      }
      throw UnauthorizedException('انتهت الجلسة. الرجاء تسجيل الدخول مجدداً.');
    }

    throw Exception('فشلت عملية المزامنة من الخادم: ${response.statusCode}');
  }

  // ── Online Lookup Fallback ───────────────────────────────────────────────

  Future<ScanResponse> onlineSkuLookup(String sku) async {
    final headers = await _jsonHeaders();
    final url = Uri.parse('$_apiSkuLookup?sku=${Uri.encodeComponent(sku)}');

    final response = await http.get(url, headers: headers).timeout(const Duration(seconds: 15));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return ScanResponse.fromJson(json);
    } else if (response.statusCode == 401) {
      final tokenRefreshed = await refreshRotationToken();
      if (tokenRefreshed) {
        final retryHeaders = await _jsonHeaders();
        final retryResponse = await http.get(url, headers: retryHeaders).timeout(const Duration(seconds: 15));
        if (retryResponse.statusCode == 200) {
          final json = jsonDecode(retryResponse.body) as Map<String, dynamic>;
          return ScanResponse.fromJson(json);
        }
      }
      throw UnauthorizedException('انتهت الجلسة.');
    }

    throw Exception('فشل البحث المتصل: ${response.statusCode}');
  }

  // ── Batch Sync Offline Scans ──────────────────────────────────────────────

  Future<Map<String, dynamic>> syncOfflineScans(List<Map<String, dynamic>> scansList) async {
    final headers = await _jsonHeaders();
    final response = await http.post(
      Uri.parse(_apiSyncActions),
      headers: headers,
      body: jsonEncode({'scans': scansList}),
    ).timeout(const Duration(seconds: 25));

    if (response.statusCode == 200) {
      return jsonDecode(response.body) as Map<String, dynamic>;
    } else if (response.statusCode == 401) {
      final tokenRefreshed = await refreshRotationToken();
      if (tokenRefreshed) {
        final retryHeaders = await _jsonHeaders();
        final retryResponse = await http.post(
          Uri.parse(_apiSyncActions),
          headers: retryHeaders,
          body: jsonEncode({'scans': scansList}),
        ).timeout(const Duration(seconds: 25));
        if (retryResponse.statusCode == 200) {
          return jsonDecode(retryResponse.body) as Map<String, dynamic>;
        }
      }
      throw UnauthorizedException('انتهت الجلسة.');
    }

    throw Exception('فشل رفع العمليات: ${response.statusCode}');
  }

  Future<ScanResponse> processScan(String sku, {int? selectedItemId}) async {
    final payload = {
      'id': 0,
      'sku': sku,
      'selected_item_id': selectedItemId ?? 0,
      'timestamp': DateTime.now().millisecondsSinceEpoch ~/ 1000,
    };

    final result = await syncOfflineScans([payload]);
    final results = result['results'] as List<dynamic>? ?? const [];
    if (results.isEmpty) {
      return ScanResponse(
        success: result['success'] == true,
        message: (result['message'] ?? 'فشل تنفيذ المسح').toString(),
        alreadyScanned: false,
        allDone: false,
        sku: sku,
      );
    }

    final first = results.first;
    if (first is! Map<String, dynamic>) {
      return ScanResponse(
        success: false,
        message: 'استجابة غير صالحة من الخادم',
        alreadyScanned: false,
        allDone: false,
        sku: sku,
      );
    }

    return ScanResponse.fromJson(first);
  }

}

class UnauthorizedException implements Exception {
  final String message;
  UnauthorizedException(this.message);
  @override
  String toString() => message;
}
