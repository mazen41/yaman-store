import 'dart:convert';
import 'package:flutter/foundation.dart';
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
const String _apiPurchaseGroups = '$_baseUrl/api/purchase-groups.php';
const String _apiSortPurchaseGroup = '$_baseUrl/api/sort-purchase-group.php';
const String _apiSortingNotifications = '$_baseUrl/api/sorting-notifications.php';
const String _apiUndoSort = '$_baseUrl/api/undo-sort.php';

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
    final hasMatches = (json['matches'] is List && (json['matches'] as List).isNotEmpty);
    final requiresSel = json['requires_selection'] == true || hasMatches;
    return ScanResponse(
      success: json['success'] == true,
      message: json['message'] ?? '',
      alreadyScanned: json['already_scanned'] == true,
      allDone: json['all_done'] == true,
      requiresSelection: requiresSel,
      sku: (json['sku'] ?? '').toString(),
      matches: (json['matches'] as List<dynamic>? ?? [])
          .map((m) => OrderMatch.fromJson(m as Map<String, dynamic>))
          .toList(),
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
  final bool isSorted;
  final int totalSkus;
  final int purchaseGroupId;
  final String purchaseGroupNumber;

  OrderMatch({
    required this.itemId,
    required this.orderId,
    required this.orderNumber,
    required this.customerName,
    required this.customerMobile,
    required this.status,
    required this.isSorted,
    this.totalSkus = 0,
    this.purchaseGroupId = 0,
    this.purchaseGroupNumber = '',
  });

  static bool _parseIsSorted(Map<String, dynamic> json) {
    final rawSorted = json['is_sorted'] ?? json['isSorted'] ?? json['sorted'];
    if (rawSorted is bool) return rawSorted;
    final normalizedSorted = rawSorted?.toString().trim().toLowerCase();
    if (normalizedSorted == '1' || normalizedSorted == 'true' || normalizedSorted == 'yes') {
      return true;
    }
    if (normalizedSorted == '0' || normalizedSorted == 'false' || normalizedSorted == 'no') {
      return false;
    }

    final normalizedStatus = (json['item_status'] ?? json['status'] ?? '').toString().trim().toLowerCase();
    return normalizedStatus == 'scanned' || normalizedStatus == 'sorted' || normalizedStatus == 'تم الفرز';
  }

  factory OrderMatch.fromJson(Map<String, dynamic> json) => OrderMatch(
        itemId: int.tryParse(json['item_id'].toString()) ?? 0,
        orderId: int.tryParse(json['order_id'].toString()) ?? 0,
        orderNumber: json['order_number'] ?? '',
        customerName: json['customer_name'] ?? '',
        customerMobile: json['customer_mobile'] ?? '',
        status: (json['item_status'] ?? json['status'] ?? '').toString(),
        isSorted: _parseIsSorted(json),
        totalSkus: int.tryParse((json['total_skus'] ?? '0').toString()) ?? 0,
        purchaseGroupId: int.tryParse((json['purchase_group_id'] ?? '0').toString()) ?? 0,
        purchaseGroupNumber: (json['purchase_group_number'] ?? json['group_number'] ?? '').toString(),
      );
}

class SyncOrdersResponse {
  final bool success;
  final String message;
  final List<Map<String, dynamic>> orders;
  final List<Map<String, dynamic>> items;
  final int syncTimestamp;
  final int totalOrders;

  SyncOrdersResponse({
    required this.success,
    required this.message,
    required this.orders,
    required this.items,
    required this.syncTimestamp,
    required this.totalOrders,
  });

  factory SyncOrdersResponse.fromJson(Map<String, dynamic> json) {
    return SyncOrdersResponse(
      success: json['success'] == true,
      message: (json['message'] ?? '').toString(),
      orders: (json['orders'] as List<dynamic>? ?? [])
          .map((o) => (o as Map).cast<String, dynamic>())
          .toList(),
      items: (json['items'] as List<dynamic>? ?? [])
          .map((i) => (i as Map).cast<String, dynamic>())
          .toList(),
      syncTimestamp: int.tryParse((json['sync_timestamp'] ?? '0').toString()) ?? 0,
      totalOrders: int.tryParse((json['total_orders'] ?? '0').toString()) ?? 0,
    );
  }
}

// ── ApiService Singleton ──────────────────────────────────────────────────

class ApiService {
  ApiService._();
  static final ApiService instance = ApiService._();

  VoidCallback? onSessionExpired;

  String _extractAccessToken(Map<String, dynamic> json) =>
      (json['access_token'] ?? json['token'] ?? '').toString();

  Future<Map<String, String>> _jsonHeaders() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token') ?? '';
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': 'Bearer $token',
    };
  }

  Future<bool> isLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getString('auth_token') ?? '').isNotEmpty;
  }

  Future<void> forceSessionExpiration() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('auth_token');
    onSessionExpired?.call();
  }

  Future<bool> refreshRotationToken() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final refreshToken = prefs.getString('refresh_token') ?? '';
      if (refreshToken.isEmpty) return false;

      final response = await http.post(
        Uri.parse(_apiRefreshToken),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'refresh_token': refreshToken}),
      ).timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        if (json['success'] == true) {
          final accessToken = _extractAccessToken(json);
          if (accessToken.isEmpty) return false;
          await prefs.setString('auth_token', accessToken);
          if (json['refresh_token'] != null) {
            await prefs.setString('refresh_token', json['refresh_token']);
          }
          return true;
        }
      }
    } catch (_) {}
    return false;
  }

  Future<ScanResponse> login(String username, String password) async {
    final response = await http.post(
      Uri.parse(_apiLogin),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'username': username, 'password': password}),
    ).timeout(const Duration(seconds: 15));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      if (json['success'] == true) {
        final accessToken = _extractAccessToken(json);
        if (accessToken.isEmpty) {
          return ScanResponse(success: false, message: 'لم يرسل الخادم رمز دخول صالح.');
        }
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('auth_token', accessToken);
        if (json['refresh_token'] != null) {
          await prefs.setString('refresh_token', json['refresh_token']);
        }
        return ScanResponse(success: true, message: '');
      }
      return ScanResponse(success: false, message: (json['message'] ?? '').toString());
    }
    throw Exception('فشل تسجيل الدخول: ${response.statusCode}');
  }

  Future<void> logout() async {
    try {
      final headers = await _jsonHeaders();
      await http.post(Uri.parse(_apiLogout), headers: headers).timeout(const Duration(seconds: 10));
    } catch (_) {}
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('auth_token');
    await prefs.remove('refresh_token');
  }

  Future<bool> ping() async {
    try {
      final response = await http.get(
        Uri.parse('$_baseUrl/api/login.php'),
      ).timeout(const Duration(seconds: 5));
      return response.statusCode < 500;
    } catch (_) {
      return false;
    }
  }

  Future<SyncOrdersResponse> syncOrders({String? updatedAfter}) async {
    final headers = await _jsonHeaders();
    final url = Uri.parse(_apiOrders + (updatedAfter != null && updatedAfter.isNotEmpty ? '?updated_after=$updatedAfter' : ''));

    final response = await http.get(url, headers: headers).timeout(const Duration(seconds: 30));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return SyncOrdersResponse.fromJson(json);
    } else if (response.statusCode == 401) {
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

    throw Exception('فشلت عملية المزامنة من الخادم: ${response.statusCode} | ${response.body}');
  }

  Future<ScanResponse> onlineSkuLookup(String sku, {int purchaseGroupId = 0}) async {
    final headers = await _jsonHeaders();
    final url = Uri.parse('$_apiSkuLookup?sku=${Uri.encodeComponent(sku)}&purchase_group_id=$purchaseGroupId');

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

    throw Exception('فشل البحث المتصل: ${response.statusCode} | ${response.body}');
  }

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

  Future<ScanResponse> processScan(String sku, {int? selectedItemId, int purchaseGroupId = 0}) async {
    final payload = {
      'id': 0,
      'sku': sku,
      'selected_item_id': selectedItemId ?? 0,
      'purchase_group_id': purchaseGroupId,
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

  Future<Map<String, dynamic>> sortPurchaseGroup(int purchaseGroupId) async {
    final headers = await _jsonHeaders();
    final response = await http.post(
      Uri.parse(_apiSortPurchaseGroup),
      headers: headers,
      body: jsonEncode({'purchase_group_id': purchaseGroupId}),
    ).timeout(const Duration(seconds: 30));
    if (response.statusCode == 200) {
      return jsonDecode(response.body) as Map<String, dynamic>;
    }
    throw Exception('فشل فرز مجموعة الشراء: ${response.statusCode}');
  }

  Future<List<Map<String, dynamic>>> fetchPurchaseGroups() async {
    final headers = await _jsonHeaders();
    final response = await http
        .get(Uri.parse(_apiPurchaseGroups), headers: headers)
        .timeout(const Duration(seconds: 20));
    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      final groups = (json['groups'] as List<dynamic>? ?? const []);
      return groups.map((g) => (g as Map).cast<String, dynamic>()).toList();
    }
    throw Exception('فشل تحميل مجموعات الشراء: ${response.statusCode}');
  }

  Future<Map<String, dynamic>> fetchSortingNotifications({int afterId = 0, int limit = 100}) async {
    final headers = await _jsonHeaders();
    final response = await http
        .get(Uri.parse('$_apiSortingNotifications?after_id=$afterId&limit=$limit'), headers: headers)
        .timeout(const Duration(seconds: 15));
    if (response.statusCode == 200) {
      return jsonDecode(response.body) as Map<String, dynamic>;
    }
    throw Exception('فشل جلب إشعارات الفرز: ${response.statusCode}');
  }

  Future<Map<String, dynamic>> undoSort(int itemId) async {
    final headers = await _jsonHeaders();
    final response = await http.post(
      Uri.parse(_apiUndoSort),
      headers: headers,
      body: jsonEncode({'item_id': itemId}),
    ).timeout(const Duration(seconds: 15));
    if (response.statusCode == 200) {
      return jsonDecode(response.body) as Map<String, dynamic>;
    }
    throw Exception('فشل إلغاء الفرز: ${response.statusCode}');
  }
}

class UnauthorizedException implements Exception {
  final String message;
  UnauthorizedException(this.message);
  @override
  String toString() => message;
}
