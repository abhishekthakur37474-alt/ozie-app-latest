import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/retailer_dashboard_model.dart';

class RetailerApiService {
  static const String baseUrl = 'https://demo.orderezee.com/ozie-api';

  Future<RetailerDashboardModel> fetchDashboard(String token) async {
    final uri = Uri.parse('$baseUrl/v1/dashboard/retailer');

    final response = await http.get(
      uri,
      headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      },
    );

    if (response.statusCode == 200) {
      final body = jsonDecode(utf8.decode(response.bodyBytes));
      if (body['success'] == true && body['data'] != null) {
        return RetailerDashboardModel.fromJson(body['data']);
      }
      throw Exception(body['message'] ?? 'Failed to parse dashboard data.');
    } else if (response.statusCode == 401) {
      throw Exception('Session expired. Please login again.');
    } else if (response.statusCode == 403) {
      throw Exception('Unauthorized access. Retailer account required.');
    } else {
      throw Exception('Server error: HTTP ${response.statusCode}');
    }
  }
}