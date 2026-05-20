import 'dart:convert';
import 'package:http/http.dart' as http;

const String _baseUrl = 'https://yamanstore.org/';

class ScanResponse {
  final bool success;
  final String message;

  ScanResponse({required this.success, required this.message});

  factory ScanResponse.fromJson(Map<String, dynamic> json) => ScanResponse(
        success: json['success'] == true,
        message: json['message'] ?? '',
      );
}

class ApiService {
  static final ApiService instance = ApiService._();
  ApiService._();

  Future<ScanResponse> processScan(String sku) async {
    final response = await http
        .post(
          Uri.parse('${_baseUrl}modules/sorting/ajax_scan.php'),
          body: {
            'action': 'scan',
            'scan_input': sku,
            'selected_item_id': '0',
          },
        )
        .timeout(const Duration(seconds: 10));

    if (response.statusCode == 200) {
      return ScanResponse.fromJson(jsonDecode(response.body));
    }
    throw Exception('Server error ${response.statusCode}');
  }
}
