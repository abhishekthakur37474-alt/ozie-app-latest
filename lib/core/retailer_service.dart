import 'api_client.dart';
import 'models.dart';

class RetailerService {
  RetailerService._();

  static final ApiClient _api = ApiClient.instance;

  static ProductsCatalog? _catalogCache;
  static String? _catalogCacheKey;
  static DateTime? _catalogCacheAt;
  static Future<ProductsCatalog>? _catalogInflight;

  static Map<String, dynamic> _dataOf(Map<String, dynamic> res) {
    final data = res['data'];
    if (data is Map<String, dynamic>) return data;
    if (data is Map) return Map<String, dynamic>.from(data);
    throw ApiException('Unexpected server response.');
  }

  static String _catalogKey({
    required String search,
    required int categoryId,
    required String brand,
    required bool wishlistOnly,
    required int page,
    required int limit,
  }) =>
      '$search|$categoryId|$brand|$wishlistOnly|$page|$limit';

  static ProductsCatalog? cachedProducts({
    String search = '',
    int categoryId = 0,
    String brand = '',
    bool wishlistOnly = false,
    int page = 1,
    int limit = 12,
  }) {
    final key = _catalogKey(
      search: search,
      categoryId: categoryId,
      brand: brand,
      wishlistOnly: wishlistOnly,
      page: page,
      limit: limit,
    );
    if (_catalogCache == null || _catalogCacheKey != key || _catalogCacheAt == null) {
      return null;
    }
    if (DateTime.now().difference(_catalogCacheAt!) > const Duration(minutes: 2)) {
      return null;
    }
    return _catalogCache;
  }

  static Future<ProductsCatalog> getProducts({
    String search = '',
    int categoryId = 0,
    String brand = '',
    bool wishlistOnly = false,
    int page = 1,
    int limit = 12,
    bool force = false,
  }) async {
    final key = _catalogKey(
      search: search,
      categoryId: categoryId,
      brand: brand,
      wishlistOnly: wishlistOnly,
      page: page,
      limit: limit,
    );
    if (!force) {
      final cached = cachedProducts(
        search: search,
        categoryId: categoryId,
        brand: brand,
        wishlistOnly: wishlistOnly,
        page: page,
        limit: limit,
      );
      if (cached != null) return cached;
      if (_catalogInflight != null && _catalogCacheKey == key) {
        return _catalogInflight!;
      }
    }

    final query = <String, dynamic>{
      'page': page,
      'limit': limit,
    };
    if (search.isNotEmpty) query['search'] = search;
    if (categoryId > 0) query['cat'] = categoryId;
    if (brand.isNotEmpty) query['brand'] = brand;
    if (wishlistOnly) query['wishlist_only'] = 1;

    final future = () async {
      final res = await _api.get('v1/retailer/products', query: query);
      return ProductsCatalog.fromJson(_dataOf(res));
    }();

    if (page == 1) {
      _catalogCacheKey = key;
      _catalogInflight = future;
    }

    try {
      final result = await future;
      if (page == 1) {
        _catalogCache = result;
        _catalogCacheKey = key;
        _catalogCacheAt = DateTime.now();
      }
      return result;
    } finally {
      if (identical(_catalogInflight, future)) _catalogInflight = null;
    }
  }

  static Future<WishlistResult> toggleWishlist(int productId) async {
    final res = await _api.post(
      'v1/retailer/products',
      body: {
        'action': 'toggle_wishlist',
        'product_id': productId,
      },
    );
    return WishlistResult.fromJson(_dataOf(res));
  }

  static Future<AddToCartResult> addToCart(int productId, {int quantity = 1}) async {
    final res = await _api.post(
      'v1/retailer/products',
      body: {
        'action': 'add_to_cart',
        'product_id': productId,
        'quantity': quantity,
      },
    );
    return AddToCartResult.fromJson(_dataOf(res));
  }

  static Future<OrdersPage> getOrders({
    String search = '',
    String startDate = '',
    String endDate = '',
    int page = 1,
    int limit = 15,
  }) async {
    final query = <String, dynamic>{
      'page': page,
      'limit': limit,
    };
    if (search.isNotEmpty) query['search'] = search;
    if (startDate.isNotEmpty) query['start_date'] = startDate;
    if (endDate.isNotEmpty) query['end_date'] = endDate;
    final res = await _api.get('v1/retailer/orders', query: query);
    return OrdersPage.fromJson(_dataOf(res));
  }

  static Future<ReviewOrdersData> getReviewOrders({
    int page = 1,
    int limit = 10,
  }) async {
    final res = await _api.get(
      'v1/retailer/review-orders',
      query: {'page': page, 'limit': limit},
    );
    return ReviewOrdersData.fromJson(_dataOf(res));
  }

  static Future<String> reviewAction({
    required String action,
    required int orderId,
    String? rejectReason,
    int? detailId,
  }) async {
    final body = <String, dynamic>{
      'action': action,
      'order_id': orderId,
    };
    if (rejectReason != null && rejectReason.isNotEmpty) {
      body['reject_reason'] = rejectReason;
    }
    if (detailId != null) body['detail_id'] = detailId;
    final res = await _api.post('v1/retailer/review-orders', body: body);
    return (res['message'] as String?) ?? 'Updated.';
  }

  static NotificationsPage _parseNotifications(Map<String, dynamic> res) {
    final data = res['data'];
    if (data is List) {
      final items = data
          .whereType<Map>()
          .map((e) => AppNotification.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      return NotificationsPage(
        notifications: items,
        unreadCount: items.where((n) => !n.isRead).length,
      );
    }
    if (data is Map) {
      return NotificationsPage.fromJson(Map<String, dynamic>.from(data));
    }
    if (res['notifications'] is List) {
      return NotificationsPage.fromJson(res);
    }
    throw ApiException('Unexpected server response.');
  }

  static Future<NotificationsPage> getNotifications({
    int page = 1,
    int limit = 20,
  }) async {
    final res = await _api.get(
      'v1/retailer/notifications',
      query: {'page': page, 'limit': limit},
    );
    return _parseNotifications(res);
  }
}
