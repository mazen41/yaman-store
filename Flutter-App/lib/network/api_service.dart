import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

const String _baseUrl = 'https://yamanstore.org/';
const String _apiBase = '${_baseUrl}modules/sorting/api.php';

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
      sku: json['sku'] ?? '',
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

  SyncOrdersResponse({required this.success, required this.message, required this.orders, required this.items});

  factory SyncOrdersResponse.fromJson(Map<String, dynamic> json) {
    final ordersRaw = (json['orders'] as List<dynamic>? ?? []);
    final itemsRaw = (json['items'] as List<dynamic>? ?? []);
    return SyncOrdersResponse(
      success: json['success'] == true,
      message: (json['message'] ?? '').toString(),
      orders: ordersRaw.map((o) {
        final m = o as Map<String, dynamic>;
        return {
          'order_id': int.tryParse(m['order_id'].toString()) ?? 0,
          'order_number': (m['order_number'] ?? '').toString(),
          'customer_name': (m['customer_name'] ?? '').toString(),
          'customer_mobile': (m['customer_mobile'] ?? '').toString(),
          'status': (m['status'] ?? '').toString(),
        };
      }).toList(),
      items: itemsRaw.map((i) {
        final m = i as Map<String, dynamic>;
        return {
          'item_id': int.tryParse(m['item_id'].toString()) ?? 0,
          'order_id': int.tryParse(m['order_id'].toString()) ?? 0,
          'sku': (m['sku'] ?? '').toString(),
          'is_sorted': (m['is_sorted'] == true || m['is_sorted'].toString() == '1') ? 1 : 0,
        };
      }).toList(),
    );
  }
}

class LoginResponse {
  final bool success;
  final String token;
  final String name;
  final String message;

  LoginResponse({
    required this.success,
    this.token = '',
    this.name = '',
    this.message = '',
  });

  factory LoginResponse.fromJson(Map<String, dynamic> json) => LoginResponse(
        success: json['success'] == true,
        token: (json['token'] ?? '').toString(),
        name: (json['name'] ?? '').toString(),
        message: (json['message'] ?? '').toString(),
      );
}
// ── API Service ───────────────────────────────────────────────────────────

class ApiService {
  static final ApiService instance = ApiService._();
  ApiService._();

  String? _cachedToken;

  Future<String?> getToken() async {
    if (_cachedToken != null) return _cachedToken;
    final prefs = await SharedPreferences.getInstance();
    _cachedToken = prefs.getString('api_token');
    return _cachedToken;
  }

  Future<void> saveToken(String token) async {
    _cachedToken = token;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('api_token', token);
  }

  Future<Map<String, String>> _jsonHeaders() async {
    final token = await getToken();
    return {
        'Content-Type': 'application/json',
        if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
      };
  }

  Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }

  Future<void> clearToken() async {
    _cachedToken = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('api_token');
  }

  Future<LoginResponse> login(String username, String password) async {
    final response = await http
        .post(
          Uri.parse('$_apiBase?action=token_login'),
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode({'username': username, 'password': password}),
        )
        .timeout(const Duration(seconds: 15));

    if (response.statusCode == 200 || response.statusCode == 201) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      final loginResp = LoginResponse.fromJson(json);
      if (loginResp.success && loginResp.token.isNotEmpty) {
        await saveToken(loginResp.token);
      }
      return loginResp;
    }

    try {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return LoginResponse(
        success: false,
        message: (json['message'] ?? 'فشل تسجيل الدخول (${response.statusCode})')
            .toString(),
      );
    } catch (_) {
      return LoginResponse(
        success: false,
        message: 'فشل تسجيل الدخول (${response.statusCode})',
      );
    }
  }

  Future<bool> ping() async {
    try {
      final response = await http
          .get(Uri.parse('$_apiBase?action=ping'))
          .timeout(const Duration(seconds: 6));
      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  Future<SyncOrdersResponse> syncOrders() async {
    final headers = await _jsonHeaders();

    http.Response primaryResponse;
    try {
      primaryResponse = await http
          .get(Uri.parse('$_apiBase?action=sync_orders'), headers: headers)
          .timeout(const Duration(seconds: 15));
    } catch (_) {
      primaryResponse = http.Response('', 0);
    }

    if (primaryResponse.statusCode == 200) {
      final json = jsonDecode(primaryResponse.body) as Map<String, dynamic>;
      return SyncOrdersResponse.fromJson(json);
    }

    final shouldUsePublicFallback =
        primaryResponse.statusCode == 0 ||
        primaryResponse.statusCode == 400 ||
        primaryResponse.statusCode == 401 ||
        primaryResponse.statusCode == 403;

    if (shouldUsePublicFallback) {
      final fallbackResponse = await http
          .get(Uri.parse('$_apiBase?action=sync_orders_public'), headers: headers)
          .timeout(const Duration(seconds: 15));

      if (fallbackResponse.statusCode == 200) {
        final json = jsonDecode(fallbackResponse.body) as Map<String, dynamic>;
        return SyncOrdersResponse.fromJson(json);
      }

      throw Exception('فشل المزامنة (${fallbackResponse.statusCode})');
    }

    throw Exception('فشل المزامنة (${primaryResponse.statusCode})');
  }


  Future<ScanResponse> processScan(String sku, {int selectedItemId = 0}) async {
    final response = await http
        .post(
          Uri.parse('$_apiBase?action=scan'),
          headers: await _jsonHeaders(),
          body: jsonEncode({
            'scan_input': sku,
            'selected_item_id': selectedItemId,
          }),
        )
        .timeout(const Duration(seconds: 12));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return ScanResponse.fromJson(json);
    }
    if (response.statusCode == 401 || response.statusCode == 403) {
      final fallback = await http
          .post(
            Uri.parse('$_apiBase?action=scan_public'),
            headers: await _jsonHeaders(),
            body: jsonEncode({
              'scan_input': sku,
              'selected_item_id': selectedItemId,
            }),
          )
          .timeout(const Duration(seconds: 12));
      if (fallback.statusCode == 200) {
        final json = jsonDecode(fallback.body) as Map<String, dynamic>;
        return ScanResponse.fromJson(json);
      }
    }

    try {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return ScanResponse(
        success: false,
        message: (json['message'] ?? 'فشل الطلب').toString(),
      );
    } catch (_) {
      throw Exception('Server error: ${response.statusCode}');
    }
  }
}

class UnauthorizedException implements Exception {
  final String message;
  UnauthorizedException(this.message);
  @override
  String toString() => message;
}
