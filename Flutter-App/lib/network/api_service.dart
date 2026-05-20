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
        token: json['token'] ?? '',
        name: json['name'] ?? '',
        message: json['message'] ?? '',
      );
}

// ── API Service ───────────────────────────────────────────────────────────

class ApiService {
  static final ApiService instance = ApiService._();
  ApiService._();

  String? _cachedToken;

  // ── Token management ──────────────────────────────────────────────

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

  Future<void> clearToken() async {
    _cachedToken = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('api_token');
  }

  Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }

  // ── Auth header ───────────────────────────────────────────────────

  Future<Map<String, String>> _authHeaders() async {
    final token = await getToken();
    return {
      'Content-Type': 'application/json',
      if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
    };
  }

  // ── Login ─────────────────────────────────────────────────────────

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
    // Try to parse error body
    try {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return LoginResponse(
        success: false,
        message: json['message'] ?? 'فشل تسجيل الدخول (${response.statusCode})',
      );
    } catch (_) {
      return LoginResponse(
        success: false,
        message: 'فشل تسجيل الدخول (${response.statusCode})',
      );
    }
  }

  // ── Ping / health check ───────────────────────────────────────────

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

  // ── Scan ──────────────────────────────────────────────────────────
  ///
  /// Throws [UnauthorizedException] when the server returns 401.
  /// Throws generic [Exception] on other HTTP errors.
  /// Returns [ScanResponse] on success (including requires_selection).

  Future<ScanResponse> processScan(String sku, {int selectedItemId = 0}) async {
    final headers = await _authHeaders();
    final response = await http
        .post(
          Uri.parse('$_apiBase?action=scan'),
          headers: headers,
          body: jsonEncode({
            'scan_input': sku,
            'selected_item_id': selectedItemId,
          }),
        )
        .timeout(const Duration(seconds: 12));

    if (response.statusCode == 401) {
      await clearToken(); // token expired or invalid
      throw UnauthorizedException('انتهت جلسة العمل — يرجى تسجيل الدخول مجدداً');
    }

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return ScanResponse.fromJson(json);
    }

    // Try to surface the server's error message
    try {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      throw Exception(json['message'] ?? 'خطأ في السيرفر ${response.statusCode}');
    } catch (e) {
      if (e is Exception) rethrow;
      throw Exception('خطأ في السيرفر ${response.statusCode}');
    }
  }
}

// ── Custom exceptions ─────────────────────────────────────────────────────

class UnauthorizedException implements Exception {
  final String message;
  UnauthorizedException(this.message);
  @override
  String toString() => message;
}
