class AppUser {
  final int id;
  final String? name;
  final String? email;
  final String? mobile;
  final String? userType;

  const AppUser({
    required this.id,
    this.name,
    this.email,
    this.mobile,
    this.userType,
  });

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: (j['id'] as num).toInt(),
        name: j['name']?.toString(),
        email: j['email']?.toString(),
        mobile: j['mobile']?.toString(),
        userType: j['user_type']?.toString(),
      );

  String get displayName =>
      (name != null && name!.trim().isNotEmpty) ? name!.trim() : (email ?? 'User');

  String get roleLabel {
    final t = userType;
    if (t == null || t.isEmpty) return 'No role yet';
    return t
        .split('_')
        .map((w) => w.isEmpty ? w : '${w[0].toUpperCase()}${w.substring(1)}')
        .join(' ');
  }
}

/// next_screen: dashboard | wait_for_approval | role_selection
class Session {
  final AppUser user;
  final String nextScreen;
  final bool inquirySubmitted;
  final int retailerFormFilled;

  const Session({
    required this.user,
    required this.nextScreen,
    required this.inquirySubmitted,
    required this.retailerFormFilled,
  });

  factory Session.fromData(Map<String, dynamic> d) => Session(
        user: AppUser.fromJson(d['user'] as Map<String, dynamic>),
        nextScreen: (d['next_screen'] as String?) ?? 'dashboard',
        inquirySubmitted: d['inquiry_submitted'] == true,
        retailerFormFilled: (d['retailer_form_filled'] as num?)?.toInt() ?? 0,
      );
}

/// Retailer block from GET/POST v1/profile.
class RetailerProfile {
  final String? name;
  final String? shopName;
  final String? address;
  final String? city;
  final String? state;
  final String? pincode;
  final String? gpsCoordinates;
  final String? gst;
  final String? pan;
  final String? retailerCode;
  final String? category;
  final String? status;
  final double creditLimit;
  final double currentBalance;
  final double availableCredit;
  final int creditDays;
  final String? photoUrl;

  const RetailerProfile({
    this.name,
    this.shopName,
    this.address,
    this.city,
    this.state,
    this.pincode,
    this.gpsCoordinates,
    this.gst,
    this.pan,
    this.retailerCode,
    this.category,
    this.status,
    this.creditLimit = 0,
    this.currentBalance = 0,
    this.availableCredit = 0,
    this.creditDays = 0,
    this.photoUrl,
  });

  factory RetailerProfile.fromJson(Map<String, dynamic> j) => RetailerProfile(
        name: j['name']?.toString(),
        shopName: j['shop_name']?.toString(),
        address: j['address']?.toString(),
        city: j['city']?.toString(),
        state: j['state']?.toString(),
        pincode: j['pincode']?.toString(),
        gpsCoordinates: j['gps_coordinates']?.toString(),
        gst: j['gst']?.toString(),
        pan: j['pan']?.toString(),
        retailerCode: j['retailer_code']?.toString(),
        category: j['category']?.toString(),
        status: j['status']?.toString(),
        creditLimit: (j['credit_limit'] as num?)?.toDouble() ?? 0,
        currentBalance: (j['current_balance'] as num?)?.toDouble() ?? 0,
        availableCredit: (j['available_credit'] as num?)?.toDouble() ?? 0,
        creditDays: (j['credit_days'] as num?)?.toInt() ?? 0,
        photoUrl: j['photo_url']?.toString(),
      );
}

/// License block from GET/POST v1/profile.
class LicenseProfile {
  final String? licenseCopyUrl;
  final String? holderName;
  final String? holderContact;
  final String? holderAddress;
  final String? operatorName;
  final String? operatorContact;
  final String? operatorAddress;

  const LicenseProfile({
    this.licenseCopyUrl,
    this.holderName,
    this.holderContact,
    this.holderAddress,
    this.operatorName,
    this.operatorContact,
    this.operatorAddress,
  });

  factory LicenseProfile.fromJson(Map<String, dynamic> j) => LicenseProfile(
        licenseCopyUrl: j['license_copy_url']?.toString(),
        holderName: j['holder_name']?.toString(),
        holderContact: j['holder_contact']?.toString(),
        holderAddress: j['holder_address']?.toString(),
        operatorName: j['operator_name']?.toString(),
        operatorContact: j['operator_contact']?.toString(),
        operatorAddress: j['operator_address']?.toString(),
      );
}

/// Response of GET v1/profile/get and POST v1/profile/update.
class ProfileData {
  final int id;
  final String? name;
  final String? email;
  final String? mobile;
  final String? userType;
  final bool? isVerified;
  final String? memberSince;
  final String? photoUrl;
  final RetailerProfile? retailer;
  final LicenseProfile? license;

  const ProfileData({
    required this.id,
    this.name,
    this.email,
    this.mobile,
    this.userType,
    this.isVerified,
    this.memberSince,
    this.photoUrl,
    this.retailer,
    this.license,
  });

  factory ProfileData.fromJson(Map<String, dynamic> d) {
    final p = d['profile'] is Map<String, dynamic>
        ? d['profile'] as Map<String, dynamic>
        : const <String, dynamic>{};
    final r = d['retailer'];
    final l = d['license'];
    return ProfileData(
      id: (p['id'] as num?)?.toInt() ?? 0,
      name: p['name']?.toString(),
      email: p['email']?.toString(),
      mobile: p['mobile']?.toString(),
      userType: p['user_type']?.toString(),
      isVerified: p['is_verified'] as bool?,
      memberSince: p['member_since']?.toString(),
      photoUrl: p['photo_url']?.toString(),
      retailer: r is Map<String, dynamic> ? RetailerProfile.fromJson(r) : null,
      license: l is Map<String, dynamic> ? LicenseProfile.fromJson(l) : null,
    );
  }

  AppUser toUser() => AppUser(
        id: id,
        name: name,
        email: email,
        mobile: mobile,
        userType: userType,
      );

  String get displayName =>
      (name != null && name!.trim().isNotEmpty) ? name!.trim() : (email ?? 'User');
}

/// Response of POST v1/onboarding/check-duplicate.
class DuplicateResult {
  final bool? contactAvailable;
  final bool? licenseAvailable;

  const DuplicateResult({this.contactAvailable, this.licenseAvailable});

  factory DuplicateResult.fromJson(Map<String, dynamic> j) => DuplicateResult(
        contactAvailable: j['contact_available'] as bool?,
        licenseAvailable: j['license_available'] as bool?,
      );
}

double _toDouble(dynamic v) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v.trim()) ?? 0;
  return 0;
}

int _toInt(dynamic v) {
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v.trim()) ?? 0;
  return 0;
}

String? _toStr(dynamic v) {
  if (v == null) return null;
  final s = v.toString();
  return s.isEmpty || s == 'null' ? null : s;
}

dynamic _first(Map<String, dynamic> j, List<String> keys) {
  for (final k in keys) {
    if (!j.containsKey(k) || j[k] == null) continue;
    return j[k];
  }
  return null;
}

List<T> _toList<T>(dynamic v, T Function(Map<String, dynamic>) build) {
  if (v is! List) return const [];
  return v
      .whereType<Map>()
      .map((e) => build(Map<String, dynamic>.from(e)))
      .toList();
}

Map<String, dynamic> _asMap(dynamic v) =>
    v is Map ? Map<String, dynamic>.from(v) : const <String, dynamic>{};

/// Response of GET v1/dashboard/retailer.
class RetailerDashboard {
  final DashboardGreeting greeting;
  final DashboardCredit credit;
  final DashboardToday today;
  final DashboardCounts counts;
  final double pendingPaymentsTotal;
  final DashboardBankCard bankCard;
  final List<DashboardBanner> banners;
  final List<DashboardScheme> paperSchemes;
  final List<DashboardScheme> standardSchemes;
  final List<DashboardScheme> marquee;
  final List<DashboardOrder> latestOrders;
  final List<DashboardPendingPayment> pendingPayments;
  final List<DashboardQuickAction> quickActions;

  const RetailerDashboard({
    required this.greeting,
    required this.credit,
    required this.today,
    required this.counts,
    required this.pendingPaymentsTotal,
    required this.bankCard,
    required this.banners,
    required this.paperSchemes,
    required this.standardSchemes,
    required this.marquee,
    required this.latestOrders,
    required this.pendingPayments,
    required this.quickActions,
  });

  factory RetailerDashboard.fromJson(Map<String, dynamic> j) {
    final greetingMap = _asMap(j['greeting']);
    final retailerMap = _asMap(j['retailer']);
    final creditMap = _asMap(_first(j, ['credit_overview', 'credit']));
    final todayMap = _asMap(_first(j, ['today_overview', 'today']));
    final countsMap = _asMap(_first(j, ['order_metrics', 'counts']));
    return RetailerDashboard(
      greeting: DashboardGreeting.fromJson({
        ...greetingMap,
        if (retailerMap.isNotEmpty) ...retailerMap,
      }),
      credit: DashboardCredit.fromJson(creditMap),
      today: DashboardToday.fromJson(todayMap),
      counts: DashboardCounts.fromJson(countsMap),
      pendingPaymentsTotal: _toDouble(
        _first(j, ['pending_payments_total']) ?? todayMap['due_pay_amount'],
      ),
      bankCard: DashboardBankCard.fromJson(_asMap(j['bank_card'])),
      banners: _toList(j['banners'], DashboardBanner.fromJson),
      paperSchemes: _toList(j['paper_schemes'], DashboardScheme.fromJson),
      standardSchemes: _toList(
        _first(j, ['standard_schemes', 'marquee_schemes']),
        DashboardScheme.fromJson,
      ),
      marquee: _toList(
        _first(j, ['marquee', 'marquee_schemes']),
        DashboardScheme.fromJson,
      ),
      latestOrders: _toList(
        _first(j, ['latest_approved_orders', 'latest_orders']),
        DashboardOrder.fromJson,
      ),
      pendingPayments:
          _toList(j['pending_payments'], DashboardPendingPayment.fromJson),
      quickActions: _toList(j['quick_actions'], DashboardQuickAction.fromJson),
    );
  }

  /// All active schemes with the paper ones first (same order as the ticker).
  List<DashboardScheme> get allSchemes => marquee;
}

class DashboardGreeting {
  final String name;
  final String firstName;
  final String? retailerCode;
  final String category;
  final String? date;
  final String? title;
  final String? subtitle;
  final String? photoUrl;
  final String? mobile;

  const DashboardGreeting({
    required this.name,
    required this.firstName,
    this.retailerCode,
    this.category = 'D',
    this.date,
    this.title,
    this.subtitle,
    this.photoUrl,
    this.mobile,
  });

  factory DashboardGreeting.fromJson(Map<String, dynamic> j) {
    final name = _toStr(_first(j, ['full_name', 'name'])) ?? 'Retailer';
    return DashboardGreeting(
      name: name,
      firstName: _toStr(j['first_name']) ?? name.split(' ').first,
      retailerCode: _toStr(j['retailer_code']),
      category: _toStr(j['category']) ?? 'D',
      date: _toStr(j['date']),
      title: _toStr(j['title']),
      subtitle: _toStr(j['subtitle']),
      photoUrl: _toStr(j['photo_url']),
      mobile: _toStr(j['mobile']),
    );
  }
}

class DashboardCredit {
  final double limit;
  final double currentBalance;
  final double availableCredit;
  final int creditDays;

  const DashboardCredit({
    this.limit = 0,
    this.currentBalance = 0,
    this.availableCredit = 0,
    this.creditDays = 0,
  });

  factory DashboardCredit.fromJson(Map<String, dynamic> j) => DashboardCredit(
        limit: _toDouble(_first(j, ['anchor_credit_limit', 'limit', 'credit_limit'])),
        currentBalance: _toDouble(j['current_balance']),
        availableCredit: _toDouble(j['available_credit']),
        creditDays: _toInt(_first(j, ['credit_duration_days', 'credit_days'])),
      );

  double get usedRatio =>
      limit <= 0 ? 0 : (currentBalance / limit).clamp(0.0, 1.0).toDouble();
}

class DashboardToday {
  final int ordersCount;
  final double salesValue;
  final double paidValue;
  final String? date;
  final double duePayAmount;

  const DashboardToday({
    this.ordersCount = 0,
    this.salesValue = 0,
    this.paidValue = 0,
    this.date,
    this.duePayAmount = 0,
  });

  factory DashboardToday.fromJson(Map<String, dynamic> j) => DashboardToday(
        ordersCount: _toInt(_first(j, ['today_orders_count', 'orders_count'])),
        salesValue: _toDouble(_first(j, ['today_sales_amount', 'sales_value'])),
        paidValue: _toDouble(_first(j, ['today_paid_amount', 'paid_value'])),
        date: _toStr(j['date']),
        duePayAmount: _toDouble(j['due_pay_amount']),
      );
}

class DashboardCounts {
  final int pendingOrders;
  final int dispatched;
  final int pendingPayments;

  const DashboardCounts({
    this.pendingOrders = 0,
    this.dispatched = 0,
    this.pendingPayments = 0,
  });

  factory DashboardCounts.fromJson(Map<String, dynamic> j) => DashboardCounts(
        pendingOrders: _toInt(j['pending_orders']),
        dispatched: _toInt(_first(j, ['dispatches', 'dispatched'])),
        pendingPayments: _toInt(j['pending_payments']),
      );
}

class DashboardBankCard {
  final String status;
  final String message;
  final String? action;
  final bool? activeFlag;

  const DashboardBankCard({
    this.status = 'Inactive',
    this.message = '',
    this.action,
    this.activeFlag,
  });

  factory DashboardBankCard.fromJson(Map<String, dynamic> j) => DashboardBankCard(
        status: _toStr(j['status']) ?? 'Inactive',
        message: _toStr(j['message']) ?? '',
        action: _toStr(_first(j, ['action_label', 'action'])),
        activeFlag: j['is_active'] is bool ? j['is_active'] as bool : null,
      );

  bool get isActive =>
      activeFlag == true || status.toLowerCase() == 'active';
  bool get isPending => status.toLowerCase() == 'pending';
}

class DashboardBanner {
  final String id;
  final String type;
  final String? mediaUrl;
  final String? streamUrl;
  final String? title;

  const DashboardBanner({
    required this.id,
    this.type = 'image',
    this.mediaUrl,
    this.streamUrl,
    this.title,
  });

  factory DashboardBanner.fromJson(Map<String, dynamic> j) => DashboardBanner(
        id: _toStr(j['id']) ?? '',
        type: _toStr(j['type']) ?? 'image',
        mediaUrl: _toStr(j['media_url']),
        streamUrl: _toStr(j['stream_url']),
        title: _toStr(j['title']),
      );

  bool get isVideo => type.toLowerCase() == 'video';
}

class DashboardScheme {
  final int id;
  final String originType;
  final String? title;
  final String? schemeType;
  final int? productId;
  final String productName;
  final int minQuantity;
  final double minAmount;
  final String? minText;
  final String? giftName;
  final double? giftValue;
  final String? giftImageUrl;
  final String? startDate;
  final String? endDate;
  final int? stockAvailable;
  final bool isAvailable;

  const DashboardScheme({
    required this.id,
    this.originType = 'Standard Scheme',
    this.title,
    this.schemeType,
    this.productId,
    this.productName = 'All Products',
    this.minQuantity = 0,
    this.minAmount = 0,
    this.minText,
    this.giftName,
    this.giftValue,
    this.giftImageUrl,
    this.startDate,
    this.endDate,
    this.stockAvailable,
    this.isAvailable = true,
  });

  factory DashboardScheme.fromJson(Map<String, dynamic> j) => DashboardScheme(
        id: _toInt(j['id']),
        originType: _toStr(_first(j, ['origin_type', 'type'])) ?? 'Standard Scheme',
        title: _toStr(_first(j, ['scheme_title', 'title'])),
        schemeType: _toStr(_first(j, ['scheme_type', 'type'])),
        productId: (j['product_id'] as num?)?.toInt(),
        productName: _toStr(j['product_name']) ?? 'All Products',
        minQuantity: _toInt(j['min_quantity']),
        minAmount: _toDouble(j['min_amount']),
        minText: _toStr(j['min_text']),
        giftName: _toStr(j['gift_name']),
        giftValue: (j['gift_value'] as num?)?.toDouble(),
        giftImageUrl: _toStr(j['gift_image_url']),
        startDate: _toStr(j['start_date']),
        endDate: _toStr(j['end_date']),
        stockAvailable: (j['stock_available'] as num?)?.toInt(),
        isAvailable: j['is_available'] != false,
      );

  bool get isPaper => originType.toLowerCase() == 'paper scheme';
}

class DashboardOrder {
  final int orderId;
  final String? invoiceNumber;
  final String displayNumber;
  final String customer;
  final double total;
  final String? paymentStatus;
  final bool isPaid;
  final String? createdAt;

  const DashboardOrder({
    required this.orderId,
    this.invoiceNumber,
    this.displayNumber = '',
    this.customer = 'Customer',
    this.total = 0,
    this.paymentStatus,
    this.isPaid = false,
    this.createdAt,
  });

  factory DashboardOrder.fromJson(Map<String, dynamic> j) {
    final paymentStatus = _toStr(j['payment_status']);
    return DashboardOrder(
      orderId: _toInt(_first(j, ['id', 'order_id'])),
      invoiceNumber: _toStr(j['invoice_number']),
      displayNumber: _toStr(_first(j, [
            'order_number',
            'invoice_number',
            'display_number',
          ])) ??
          '',
      customer: _toStr(_first(j, ['customer_name', 'customer'])) ?? 'Customer',
      total: _toDouble(_first(j, ['amount', 'total'])),
      paymentStatus: paymentStatus,
      isPaid: j['is_paid'] == true ||
          (paymentStatus ?? '').toLowerCase() == 'paid',
      createdAt: _toStr(_first(j, ['formatted_date', 'created_at'])),
    );
  }
}

class DashboardPendingPayment {
  final int orderId;
  final String? invoiceNumber;
  final double total;
  final double? paidAmount;
  final double? dueAmount;
  final String? dueDate;
  final String? paymentStatus;
  final bool isOverdue;
  final String badge;

  const DashboardPendingPayment({
    required this.orderId,
    this.invoiceNumber,
    this.total = 0,
    this.paidAmount,
    this.dueAmount,
    this.dueDate,
    this.paymentStatus,
    this.isOverdue = false,
    this.badge = 'Pending',
  });

  factory DashboardPendingPayment.fromJson(Map<String, dynamic> j) =>
      DashboardPendingPayment(
        orderId: _toInt(_first(j, ['id', 'order_id'])),
        invoiceNumber: _toStr(j['invoice_number']),
        total: _toDouble(j['total']),
        paidAmount: (j['paid_amount'] as num?)?.toDouble(),
        dueAmount: (j['due_amount'] as num?)?.toDouble(),
        dueDate: _toStr(_first(j, ['due_display', 'due_date'])),
        paymentStatus: _toStr(j['payment_status']),
        isOverdue: j['is_overdue'] == true,
        badge: _toStr(_first(j, ['status_label', 'badge'])) ?? 'Pending',
      );
}

class DashboardQuickAction {
  final String key;
  final String label;
  final String? web;
  final String? icon;
  final String? subtitle;
  final String? route;

  const DashboardQuickAction({
    required this.key,
    required this.label,
    this.web,
    this.icon,
    this.subtitle,
    this.route,
  });

  factory DashboardQuickAction.fromJson(Map<String, dynamic> j) =>
      DashboardQuickAction(
        key: _toStr(j['key']) ?? '',
        label: _toStr(j['label']) ?? _toStr(j['title']) ?? '',
        web: _toStr(j['web']),
        icon: _toStr(j['icon']),
        subtitle: _toStr(j['subtitle']),
        route: _toStr(j['route']),
      );
}

class PageInfo {
  final int currentPage;
  final int limit;
  final int totalItems;
  final int totalPages;
  final bool hasNextPage;
  final bool hasPrevPage;

  const PageInfo({
    this.currentPage = 1,
    this.limit = 15,
    this.totalItems = 0,
    this.totalPages = 1,
    this.hasNextPage = false,
    this.hasPrevPage = false,
  });

  factory PageInfo.fromJson(Map<String, dynamic> j) => PageInfo(
        currentPage: _toInt(j['current_page']),
        limit: _toInt(j['limit']),
        totalItems: _toInt(j['total_items'] ?? j['total_products'] ?? j['total_history']),
        totalPages: _toInt(j['total_pages']),
        hasNextPage: j['has_next_page'] == true,
        hasPrevPage: j['has_prev_page'] == true,
      );
}

class ProductScheme {
  final int id;
  final String schemeTitle;
  final String schemeType;
  final int minQty;
  final int buyQty;
  final int freeQty;
  final double discountPercent;
  final String? giftName;

  const ProductScheme({
    required this.id,
    this.schemeTitle = '',
    this.schemeType = '',
    this.minQty = 0,
    this.buyQty = 0,
    this.freeQty = 0,
    this.discountPercent = 0,
    this.giftName,
  });

  factory ProductScheme.fromJson(Map<String, dynamic> j) => ProductScheme(
        id: _toInt(j['id']),
        schemeTitle: _toStr(j['scheme_title']) ?? '',
        schemeType: _toStr(j['scheme_type']) ?? '',
        minQty: _toInt(j['min_qty']),
        buyQty: _toInt(j['buy_qty']),
        freeQty: _toInt(j['free_qty']),
        discountPercent: _toDouble(j['discount_percent']),
        giftName: _toStr(j['gift_name']),
      );
}

class ProductCategory {
  final int id;
  final String name;

  const ProductCategory({required this.id, required this.name});

  factory ProductCategory.fromJson(Map<String, dynamic> j) => ProductCategory(
        id: _toInt(j['id']),
        name: _toStr(j['name']) ?? '',
      );
}

class ProductItem {
  final int id;
  final String sku;
  final String name;
  final String brand;
  final int categoryId;
  final String? photoUrl;
  final String unitSize;
  final int units;
  final String packingType;
  final double mrpCase;
  final double billingCase;
  final int discountPct;
  final int stock;
  final int cartQuantity;
  final int availableStock;
  final String availabilityStatus;
  final bool isAvailable;
  final bool canPurchase;
  final bool isOutOfStock;
  final bool isLowStock;
  final bool isInWishlist;
  final bool hasScheme;
  final int selectableLimit;
  final List<ProductScheme> schemes;

  const ProductItem({
    required this.id,
    this.sku = '',
    this.name = '',
    this.brand = '',
    this.categoryId = 0,
    this.photoUrl,
    this.unitSize = '',
    this.units = 0,
    this.packingType = '',
    this.mrpCase = 0,
    this.billingCase = 0,
    this.discountPct = 0,
    this.stock = 0,
    this.cartQuantity = 0,
    this.availableStock = 0,
    this.availabilityStatus = 'Available',
    this.isAvailable = true,
    this.canPurchase = true,
    this.isOutOfStock = false,
    this.isLowStock = false,
    this.isInWishlist = false,
    this.hasScheme = false,
    this.selectableLimit = 0,
    this.schemes = const [],
  });

  factory ProductItem.fromJson(Map<String, dynamic> j) => ProductItem(
        id: _toInt(j['id']),
        sku: _toStr(j['sku']) ?? '',
        name: _toStr(j['name']) ?? '',
        brand: _toStr(j['brand']) ?? '',
        categoryId: _toInt(j['category_id']),
        photoUrl: _toStr(j['photo_url']),
        unitSize: _toStr(j['unit_size']) ?? '',
        units: _toInt(j['units']),
        packingType: _toStr(j['packing_type']) ?? '',
        mrpCase: _toDouble(j['mrp_case']),
        billingCase: _toDouble(j['billing_case']),
        discountPct: _toInt(j['discount_pct']),
        stock: _toInt(j['stock']),
        cartQuantity: _toInt(j['cart_quantity']),
        availableStock: _toInt(j['available_stock']),
        availabilityStatus: _toStr(j['availability_status']) ?? 'Available',
        isAvailable: j['is_available'] != false,
        canPurchase: j['can_purchase'] != false,
        isOutOfStock: j['is_out_of_stock'] == true,
        isLowStock: j['is_low_stock'] == true,
        isInWishlist: j['is_in_wishlist'] == true,
        hasScheme: j['has_scheme'] == true,
        selectableLimit: _toInt(j['selectable_limit']),
        schemes: _toList(j['schemes'], ProductScheme.fromJson),
      );

  ProductItem copyWith({
    int? cartQuantity,
    int? availableStock,
    bool? isInWishlist,
    bool? isOutOfStock,
    bool? isLowStock,
  }) {
    final nextCart = cartQuantity ?? this.cartQuantity;
    final nextAvail = availableStock ?? this.availableStock;
    return ProductItem(
      id: id,
      sku: sku,
      name: name,
      brand: brand,
      categoryId: categoryId,
      photoUrl: photoUrl,
      unitSize: unitSize,
      units: units,
      packingType: packingType,
      mrpCase: mrpCase,
      billingCase: billingCase,
      discountPct: discountPct,
      stock: stock,
      cartQuantity: nextCart,
      availableStock: nextAvail,
      availabilityStatus: availabilityStatus,
      isAvailable: isAvailable,
      canPurchase: canPurchase,
      isOutOfStock: isOutOfStock ?? this.isOutOfStock,
      isLowStock: isLowStock ?? (nextAvail > 0 && nextAvail <= 5),
      isInWishlist: isInWishlist ?? this.isInWishlist,
      hasScheme: hasScheme,
      selectableLimit: selectableLimit,
      schemes: schemes,
    );
  }
}

class ProductsCatalog {
  final String scopeNotice;
  final int cartCount;
  final int wishlistCount;
  final List<ProductCategory> categories;
  final List<String> brands;
  final List<ProductItem> products;
  final PageInfo pagination;

  const ProductsCatalog({
    this.scopeNotice = '',
    this.cartCount = 0,
    this.wishlistCount = 0,
    this.categories = const [],
    this.brands = const [],
    this.products = const [],
    this.pagination = const PageInfo(),
  });

  factory ProductsCatalog.fromJson(Map<String, dynamic> j) {
    final brandsRaw = j['brands'];
    return ProductsCatalog(
      scopeNotice: _toStr(j['scope_notice']) ?? '',
      cartCount: _toInt(j['cart_count']),
      wishlistCount: _toInt(j['wishlist_count']),
      categories: _toList(j['categories'], ProductCategory.fromJson),
      brands: brandsRaw is List
          ? brandsRaw.map((e) => e.toString()).where((e) => e.isNotEmpty).toList()
          : const [],
      products: _toList(j['products'], ProductItem.fromJson),
      pagination: PageInfo.fromJson(_asMap(j['pagination'])),
    );
  }
}

class WishlistResult {
  final int productId;
  final bool isInWishlist;
  final int wishlistCount;

  const WishlistResult({
    required this.productId,
    required this.isInWishlist,
    this.wishlistCount = 0,
  });

  factory WishlistResult.fromJson(Map<String, dynamic> j) => WishlistResult(
        productId: _toInt(j['product_id']),
        isInWishlist: j['is_in_wishlist'] == true,
        wishlistCount: _toInt(j['wishlist_count']),
      );
}

class AddToCartResult {
  final int productId;
  final int cartCount;
  final int newCartQty;
  final int availableStock;

  const AddToCartResult({
    required this.productId,
    this.cartCount = 0,
    this.newCartQty = 0,
    this.availableStock = 0,
  });

  factory AddToCartResult.fromJson(Map<String, dynamic> j) => AddToCartResult(
        productId: _toInt(j['product_id']),
        cartCount: _toInt(j['cart_count']),
        newCartQty: _toInt(j['new_cart_qty']),
        availableStock: _toInt(j['available_stock']),
      );
}

class ApprovedOrder {
  final int id;
  final String orderNumber;
  final String? invoiceNumber;
  final String shippingName;
  final double total;
  final String invoiceStatus;
  final String paymentStatus;
  final String paymentBadge;
  final String paymentColor;
  final String? createdAt;
  final String? formattedDate;

  const ApprovedOrder({
    required this.id,
    this.orderNumber = '',
    this.invoiceNumber,
    this.shippingName = '',
    this.total = 0,
    this.invoiceStatus = 'Approved',
    this.paymentStatus = 'Pending',
    this.paymentBadge = 'Pending',
    this.paymentColor = 'warning',
    this.createdAt,
    this.formattedDate,
  });

  factory ApprovedOrder.fromJson(Map<String, dynamic> j) => ApprovedOrder(
        id: _toInt(j['id'] ?? j['order_id']),
        orderNumber: _toStr(j['order_number']) ?? '',
        invoiceNumber: _toStr(j['invoice_number']),
        shippingName: _toStr(j['shipping_name']) ?? '',
        total: _toDouble(j['total']),
        invoiceStatus: _toStr(j['invoice_status']) ?? 'Approved',
        paymentStatus: _toStr(j['payment_status']) ?? 'Pending',
        paymentBadge: _toStr(j['payment_badge']) ?? _toStr(j['payment_status']) ?? 'Pending',
        paymentColor: _toStr(j['payment_color']) ?? 'warning',
        createdAt: _toStr(j['created_at']),
        formattedDate: _toStr(j['formatted_date']),
      );

  bool get isPaid => paymentStatus.toLowerCase() == 'paid';
}

class OrdersSummary {
  final int totalOrders;
  final double totalAmount;

  const OrdersSummary({this.totalOrders = 0, this.totalAmount = 0});

  factory OrdersSummary.fromJson(Map<String, dynamic> j) => OrdersSummary(
        totalOrders: _toInt(j['total_orders']),
        totalAmount: _toDouble(j['total_amount']),
      );
}

class OrdersPage {
  final OrdersSummary summary;
  final List<ApprovedOrder> orders;
  final PageInfo pagination;

  const OrdersPage({
    this.summary = const OrdersSummary(),
    this.orders = const [],
    this.pagination = const PageInfo(),
  });

  factory OrdersPage.fromJson(Map<String, dynamic> j) => OrdersPage(
        summary: OrdersSummary.fromJson(_asMap(j['summary'])),
        orders: _toList(j['orders'], ApprovedOrder.fromJson),
        pagination: PageInfo.fromJson(_asMap(j['pagination'])),
      );
}

class OrderSchemeInfo {
  final String title;
  final String message;
  final String type;
  final bool applied;
  final int needed;

  const OrderSchemeInfo({
    this.title = '',
    this.message = '',
    this.type = '',
    this.applied = false,
    this.needed = 0,
  });

  factory OrderSchemeInfo.fromJson(Map<String, dynamic> j) => OrderSchemeInfo(
        title: _toStr(j['title']) ?? '',
        message: _toStr(j['message']) ?? '',
        type: _toStr(j['type']) ?? '',
        applied: j['applied'] == true,
        needed: _toInt(j['needed']),
      );
}

class DraftOrderItem {
  final int id;
  final int productId;
  final String productName;
  final double productPrice;
  final int productQuantity;
  final double productTotalprice;
  final int availableStock;
  final int maxAllowed;
  final String stockClass;
  final String stockLabel;
  final bool isFree;
  final List<OrderSchemeInfo> appliedSchemes;
  final List<OrderSchemeInfo> unlockableSchemes;

  const DraftOrderItem({
    required this.id,
    this.productId = 0,
    this.productName = '',
    this.productPrice = 0,
    this.productQuantity = 0,
    this.productTotalprice = 0,
    this.availableStock = 0,
    this.maxAllowed = 0,
    this.stockClass = '',
    this.stockLabel = '',
    this.isFree = false,
    this.appliedSchemes = const [],
    this.unlockableSchemes = const [],
  });

  factory DraftOrderItem.fromJson(Map<String, dynamic> j) {
    final schemes = _asMap(j['schemes']);
    return DraftOrderItem(
      id: _toInt(j['id']),
      productId: _toInt(j['product_id']),
      productName: _toStr(j['product_name']) ?? '',
      productPrice: _toDouble(j['product_price']),
      productQuantity: _toInt(j['product_quantity']),
      productTotalprice: _toDouble(j['product_totalprice']),
      availableStock: _toInt(j['available_stock']),
      maxAllowed: _toInt(j['max_allowed']),
      stockClass: _toStr(j['stock_class']) ?? '',
      stockLabel: _toStr(j['stock_label']) ?? '',
      isFree: j['is_free'] == true,
      appliedSchemes: _toList(schemes['applied'], OrderSchemeInfo.fromJson),
      unlockableSchemes: _toList(schemes['unlockable'], OrderSchemeInfo.fromJson),
    );
  }
}

class DraftOrderSummary {
  final double totalPrice;
  final double totalSavings;
  final double netPayable;
  final int creditDays;

  const DraftOrderSummary({
    this.totalPrice = 0,
    this.totalSavings = 0,
    this.netPayable = 0,
    this.creditDays = 0,
  });

  factory DraftOrderSummary.fromJson(Map<String, dynamic> j) => DraftOrderSummary(
        totalPrice: _toDouble(j['total_price']),
        totalSavings: _toDouble(j['total_savings']),
        netPayable: _toDouble(j['net_payable']),
        creditDays: _toInt(j['credit_days']),
      );
}

class DraftOrder {
  final int orderId;
  final String orderNumber;
  final String salesmanName;
  final double total;
  final String invoiceStatus;
  final String? createdAt;
  final String? formattedDate;
  final bool canApprove;
  final bool hasOutOfStock;
  final bool hasLowStock;
  final int totalItems;
  final List<DraftOrderItem> items;
  final DraftOrderSummary summary;

  const DraftOrder({
    required this.orderId,
    this.orderNumber = '',
    this.salesmanName = '',
    this.total = 0,
    this.invoiceStatus = 'Draft',
    this.createdAt,
    this.formattedDate,
    this.canApprove = false,
    this.hasOutOfStock = false,
    this.hasLowStock = false,
    this.totalItems = 0,
    this.items = const [],
    this.summary = const DraftOrderSummary(),
  });

  factory DraftOrder.fromJson(Map<String, dynamic> j) => DraftOrder(
        orderId: _toInt(j['order_id']),
        orderNumber: _toStr(j['order_number']) ?? '',
        salesmanName: _toStr(j['salesman_name']) ?? '',
        total: _toDouble(j['total']),
        invoiceStatus: _toStr(j['invoice_status']) ?? 'Draft',
        createdAt: _toStr(j['created_at']),
        formattedDate: _toStr(j['formatted_date']),
        canApprove: j['can_approve'] == true,
        hasOutOfStock: j['has_out_of_stock'] == true,
        hasLowStock: j['has_low_stock'] == true,
        totalItems: _toInt(j['total_items']),
        items: _toList(j['items'], DraftOrderItem.fromJson),
        summary: DraftOrderSummary.fromJson(_asMap(j['summary'])),
      );
}

class HistoryOrder {
  final int orderId;
  final String orderNumber;
  final double total;
  final String paymentStatus;
  final String? createdAt;
  final String? formattedDate;

  const HistoryOrder({
    required this.orderId,
    this.orderNumber = '',
    this.total = 0,
    this.paymentStatus = 'Pending',
    this.createdAt,
    this.formattedDate,
  });

  factory HistoryOrder.fromJson(Map<String, dynamic> j) => HistoryOrder(
        orderId: _toInt(j['order_id']),
        orderNumber: _toStr(j['order_number']) ?? '',
        total: _toDouble(j['total']),
        paymentStatus: _toStr(j['payment_status']) ?? 'Pending',
        createdAt: _toStr(j['created_at']),
        formattedDate: _toStr(j['formatted_date']),
      );
}

class ReviewOrdersData {
  final int draftCount;
  final List<DraftOrder> draftOrders;
  final List<HistoryOrder> historyOrders;
  final PageInfo pagination;

  const ReviewOrdersData({
    this.draftCount = 0,
    this.draftOrders = const [],
    this.historyOrders = const [],
    this.pagination = const PageInfo(),
  });

  factory ReviewOrdersData.fromJson(Map<String, dynamic> j) => ReviewOrdersData(
        draftCount: _toInt(j['draft_count']),
        draftOrders: _toList(j['draft_orders'], DraftOrder.fromJson),
        historyOrders: _toList(j['history_orders'], HistoryOrder.fromJson),
        pagination: PageInfo.fromJson(_asMap(j['pagination'])),
      );
}

class AppNotification {
  final int id;
  final String title;
  final String message;
  final String? type;
  final bool isRead;
  final String? createdAt;
  final String? formattedDate;

  const AppNotification({
    required this.id,
    this.title = '',
    this.message = '',
    this.type,
    this.isRead = true,
    this.createdAt,
    this.formattedDate,
  });

  factory AppNotification.fromJson(Map<String, dynamic> j) {
    final readRaw = j['is_read'] ?? j['read'] ?? j['isRead'];
    final status = _toStr(j['status'])?.toLowerCase();
    final isRead = readRaw == true ||
        readRaw == 1 ||
        readRaw == '1' ||
        readRaw == 'true' ||
        status == 'read';
    return AppNotification(
      id: _toInt(j['id'] ?? j['notification_id']),
      title: _toStr(j['title'] ?? j['subject'] ?? j['heading']) ?? '',
      message: _toStr(
            j['message'] ??
                j['body'] ??
                j['content'] ??
                j['text'] ??
                j['description'] ??
                j['notification'],
          ) ??
          '',
      type: _toStr(j['type'] ?? j['notification_type'] ?? j['category']),
      isRead: isRead,
      createdAt: _toStr(
        j['created_at'] ??
            j['date_added'] ??
            j['date'] ??
            j['time'] ??
            j['created_on'] ??
            j['sent_at'],
      ),
      formattedDate: _toStr(j['formatted_date'] ?? j['formatted_time']),
    );
  }
}

class NotificationsPage {
  final List<AppNotification> notifications;
  final int unreadCount;
  final PageInfo pagination;

  const NotificationsPage({
    this.notifications = const [],
    this.unreadCount = 0,
    this.pagination = const PageInfo(),
  });

  factory NotificationsPage.fromJson(Map<String, dynamic> j) {
    final list = j['notifications'] ?? j['items'] ?? j['list'];
    final items = _toList(list, AppNotification.fromJson);
    final unread = _toInt(j['unread_count'] ?? j['unread']);
    return NotificationsPage(
      notifications: items,
      unreadCount: unread > 0
          ? unread
          : items.where((n) => !n.isRead).length,
      pagination: PageInfo.fromJson(_asMap(j['pagination'])),
    );
  }
}

/// Response of GET v1/retailer/schemes.
class RetailerSchemeOffer {
  final int id;
  final String schemeTitle;
  final String schemeType;
  final bool isPaperScheme;
  final String originType;
  final String benefit;
  final int? productId;
  final String productName;
  final String brand;
  final String minRequirement;
  final int minQty;
  final String? startDate;
  final String? endDate;
  final int daysLeft;
  final bool isExpiringSoon;

  const RetailerSchemeOffer({
    required this.id,
    this.schemeTitle = '',
    this.schemeType = '',
    this.isPaperScheme = false,
    this.originType = 'Standard Scheme',
    this.benefit = '',
    this.productId,
    this.productName = 'All Products',
    this.brand = '',
    this.minRequirement = '',
    this.minQty = 0,
    this.startDate,
    this.endDate,
    this.daysLeft = 0,
    this.isExpiringSoon = false,
  });

  factory RetailerSchemeOffer.fromJson(Map<String, dynamic> j) =>
      RetailerSchemeOffer(
        id: _toInt(j['id']),
        schemeTitle: _toStr(j['scheme_title']) ?? '',
        schemeType: _toStr(j['scheme_type']) ?? '',
        isPaperScheme: j['is_paper_scheme'] == true,
        originType: _toStr(j['origin_type']) ?? 'Standard Scheme',
        benefit: _toStr(j['benefit']) ?? '',
        productId: (j['product_id'] as num?)?.toInt(),
        productName: _toStr(j['product_name']) ?? 'All Products',
        brand: _toStr(j['brand']) ?? '',
        minRequirement: _toStr(j['min_requirement']) ?? '',
        minQty: _toInt(j['min_qty']),
        startDate: _toStr(j['start_date']),
        endDate: _toStr(j['end_date']),
        daysLeft: _toInt(j['days_left']),
        isExpiringSoon: j['is_expiring_soon'] == true,
      );

  bool get isAllProducts {
    final n = productName.trim().toLowerCase();
    return productId == null || productId == 0 || n.isEmpty || n == 'all products';
  }

  double get discountPercent {
    final m = RegExp(r'([\d.]+)\s*%').firstMatch(benefit);
    return m == null ? 0 : double.tryParse(m.group(1)!) ?? 0;
  }

  double get flatPerCase {
    final m = RegExp(r'₹\s*([\d.]+)').firstMatch(benefit);
    return m == null ? 0 : double.tryParse(m.group(1)!) ?? 0;
  }

  double savingsFor({required int quantity, required double itemGross}) {
    if (quantity <= 0) return 0;
    final pct = discountPercent;
    if (pct > 0) return itemGross * pct / 100;
    final flat = flatPerCase;
    if (flat > 0) return flat * quantity;
    return 0;
  }
}

class RetailerSchemesPage {
  final int totalOffers;
  final String filterType;
  final List<RetailerSchemeOffer> offers;

  const RetailerSchemesPage({
    this.totalOffers = 0,
    this.filterType = 'ALL',
    this.offers = const [],
  });

  factory RetailerSchemesPage.fromJson(Map<String, dynamic> j) {
    final offers = _toList(j['offers'], RetailerSchemeOffer.fromJson);
    return RetailerSchemesPage(
      totalOffers: _toInt(j['total_offers']) == 0
          ? offers.length
          : _toInt(j['total_offers']),
      filterType: _toStr(j['filter_type']) ?? 'ALL',
      offers: offers,
    );
  }
}

/// Response of GET v1/retailer/cart.
class CartItem {
  final int cartId;
  final int productId;
  final String productName;
  final int quantity;
  final double unitPrice;
  final double itemGross;
  final double itemSavings;
  final double itemFinal;
  final bool isReturn;
  final int stock;
  final String? photoUrl;

  const CartItem({
    required this.cartId,
    required this.productId,
    this.productName = '',
    this.quantity = 0,
    this.unitPrice = 0,
    this.itemGross = 0,
    this.itemSavings = 0,
    this.itemFinal = 0,
    this.isReturn = false,
    this.stock = 0,
    this.photoUrl,
  });

  factory CartItem.fromJson(Map<String, dynamic> j) => CartItem(
        cartId: _toInt(j['cart_id']),
        productId: _toInt(j['product_id']),
        productName: _toStr(j['product_name']) ?? '',
        quantity: _toInt(j['quantity']),
        unitPrice: _toDouble(j['unit_price']),
        itemGross: _toDouble(j['item_gross']),
        itemSavings: _toDouble(j['item_savings']),
        itemFinal: _toDouble(j['item_final']),
        isReturn: j['is_return'] == true,
        stock: _toInt(j['stock']),
        photoUrl: _toStr(j['photo_url']),
      );
}

class CartSummary {
  final double grossTotal;
  final double totalSavings;
  final double netPayable;
  final int creditDays;
  final String? appliedCoupon;

  const CartSummary({
    this.grossTotal = 0,
    this.totalSavings = 0,
    this.netPayable = 0,
    this.creditDays = 0,
    this.appliedCoupon,
  });

  factory CartSummary.fromJson(Map<String, dynamic> j) => CartSummary(
        grossTotal: _toDouble(j['gross_total']),
        totalSavings: _toDouble(j['total_savings']),
        netPayable: _toDouble(j['net_payable']),
        creditDays: _toInt(j['credit_days']),
        appliedCoupon: _toStr(j['applied_coupon']),
      );
}

class RetailerCart {
  final int itemsCount;
  final List<CartItem> items;
  final CartSummary summary;

  const RetailerCart({
    this.itemsCount = 0,
    this.items = const [],
    this.summary = const CartSummary(),
  });

  factory RetailerCart.fromJson(Map<String, dynamic> j) {
    final items = _toList(j['items'], CartItem.fromJson);
    return RetailerCart(
      itemsCount: _toInt(j['items_count']) == 0 ? items.length : _toInt(j['items_count']),
      items: items,
      summary: CartSummary.fromJson(_asMap(j['summary'])),
    );
  }
}
