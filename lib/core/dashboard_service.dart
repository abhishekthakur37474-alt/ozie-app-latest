import 'api_client.dart';
import 'models.dart';

/// ozie-api dashboard endpoints (v1/dashboard/*).
class DashboardService {
  DashboardService._();

  static final ApiClient _api = ApiClient.instance;

  /// Everything the retailer home screen shows, in one read-only response.
  static Future<RetailerDashboard> getRetailer() async {
    final res = await _api.get('v1/dashboard/retailer');
    final data = res['data'];
    if (data is Map) {
      return RetailerDashboard.fromJson(Map<String, dynamic>.from(data));
    }
    throw ApiException('Unexpected server response.');
  }
}
