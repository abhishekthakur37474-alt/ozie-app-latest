import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../core/api_client.dart';
import '../core/models.dart';
import '../core/retailer_service.dart';
import '../widgets/app_widgets.dart';
import 'coupons_screen.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  RetailerCart? _data;
  List<RetailerSchemeOffer> _offers = const [];
  String? _error;
  bool _loading = true;
  final _couponCtrl = TextEditingController();
  final Map<int, int> _appliedByProduct = {};
  final Set<int> _busy = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _couponCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final cart = await RetailerService.getCart();
      List<RetailerSchemeOffer> offers = const [];
      try {
        offers = (await RetailerService.getSchemes()).offers;
      } on ApiException {
        offers = const [];
      }
      if (!mounted) return;
      setState(() {
        _data = cart;
        _offers = offers;
        _loading = false;
        _pruneApplied();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  void _pruneApplied() {
    final ids = (_data?.items ?? const <CartItem>[]).map((e) => e.productId).toSet();
    _appliedByProduct.removeWhere((pid, _) => !ids.contains(pid));
  }

  RetailerSchemeOffer? _offerById(int? id) {
    if (id == null || id <= 0) return null;
    for (final o in _offers) {
      if (o.id == id) return o;
    }
    return null;
  }

  RetailerSchemeOffer? _offerByCode(String raw) {
    final code = raw.trim().toLowerCase();
    if (code.isEmpty) return null;
    for (final o in _offers) {
      final title = o.schemeTitle.trim().toLowerCase();
      final type = o.schemeType.trim().toLowerCase();
      if (title == code || type == code) return o;
    }
    return null;
  }

  CartItem? _itemForProduct(int productId) {
    for (final item in _data?.items ?? const <CartItem>[]) {
      if (item.productId == productId) return item;
    }
    return null;
  }

  String _benefitLabel(RetailerSchemeOffer offer) {
    if (offer.discountPercent > 0) {
      return '${offer.discountPercent.toStringAsFixed(0)}% Off';
    }
    if (offer.flatPerCase > 0) {
      return '₹${offer.flatPerCase.toStringAsFixed(0)} Flat Off/Case';
    }
    return offer.benefit.trim().isEmpty ? offer.schemeType : offer.benefit.trim();
  }

  String? _applyOffer(RetailerSchemeOffer offer) {
    final items = _data?.items ?? const <CartItem>[];
    if (items.isEmpty) return 'Cart is empty.';

    if (!offer.isAllProducts) {
      final pid = offer.productId;
      if (pid == null) return 'This offer is not available.';
      final item = _itemForProduct(pid);
      if (item == null) return 'Add ${offer.productName} to cart first.';
      if (item.quantity < offer.minQty) {
        final need = offer.minQty - item.quantity;
        return 'Add $need more case${need == 1 ? '' : 's'} for ${offer.schemeTitle}.';
      }
      final existing = _appliedByProduct[pid];
      if (existing != null && existing != offer.id) {
        return 'Another offer is already applied on this product.';
      }
      setState(() => _appliedByProduct[pid] = offer.id);
      return null;
    }

    final targets = items
        .where((e) => !_appliedByProduct.containsKey(e.productId))
        .where((e) => e.quantity >= offer.minQty)
        .toList();
    if (targets.isEmpty) {
      return 'Unattached offer applies only to items without an offer that meet Min ${offer.minQty}.';
    }
    setState(() {
      for (final item in targets) {
        _appliedByProduct[item.productId] = offer.id;
      }
    });
    return null;
  }

  void _applyFromBar() {
    final offer = _offerByCode(_couponCtrl.text);
    if (offer == null) {
      showStatus(context, 'Coupon not found.', error: true);
      return;
    }
    final err = _applyOffer(offer);
    if (err != null) {
      showStatus(context, err, error: true);
      return;
    }
    _couponCtrl.clear();
    showStatus(context, '${offer.schemeTitle} applied.');
  }

  Future<void> _openCoupons() async {
    final selected = await Navigator.of(context).push<RetailerSchemeOffer>(
      MaterialPageRoute(
        builder: (_) => CouponsScreen(
          cartItems: _data?.items ?? const [],
          appliedByProduct: Map<int, int>.from(_appliedByProduct),
        ),
      ),
    );
    if (!mounted || selected == null) return;
    final err = _applyOffer(selected);
    if (err != null) {
      showStatus(context, err, error: true);
      return;
    }
    showStatus(context, '${selected.schemeTitle} applied.');
  }

  bool _isApplied(RetailerSchemeOffer offer) {
    if (offer.isAllProducts) return _appliedByProduct.values.contains(offer.id);
    final pid = offer.productId;
    if (pid == null) return false;
    return _appliedByProduct[pid] == offer.id;
  }

  /// Quantity requirement met (cart has enough cases for this offer).
  bool _isEligible(RetailerSchemeOffer offer) {
    final items = _data?.items ?? const <CartItem>[];
    if (items.isEmpty) return false;
    if (offer.isAllProducts) {
      return items.any((e) => e.quantity >= offer.minQty);
    }
    final pid = offer.productId;
    if (pid == null) return false;
    final item = _itemForProduct(pid);
    return item != null && item.quantity >= offer.minQty;
  }

  /// Offers shown as switches: eligible now, or already applied.
  List<RetailerSchemeOffer> _switchOffers() => [
        for (final o in _offers)
          if (_isApplied(o) || _isEligible(o)) o,
      ];

  void _toggleOffer(RetailerSchemeOffer offer, bool on) {
    if (on) {
      final err = _applyOffer(offer);
      if (err != null) showStatus(context, err, error: true);
      return;
    }
    setState(() => _appliedByProduct.removeWhere((_, id) => id == offer.id));
  }

  void _setCart(RetailerCart cart) {
    _data = cart;
    _pruneApplied();
  }

  void _setItems(List<CartItem> items) {
    _setCart(RetailerCart(
      itemsCount: items.length,
      items: items,
      summary: _data?.summary ?? const CartSummary(),
    ));
  }

  Future<void> _changeQty(CartItem item, int delta) async {
    if (_busy.contains(item.productId)) return;
    final next = item.quantity + delta;
    if (next < 1) {
      await _removeItem(item);
      return;
    }
    if (item.stock > 0 && next > item.stock) {
      showStatus(context, 'Only ${item.stock} case${item.stock == 1 ? '' : 's'} available.', error: true);
      return;
    }
    final previous = _data;
    setState(() {
      _busy.add(item.productId);
      _setItems([
        for (final e in _data?.items ?? const <CartItem>[])
          if (e.productId == item.productId) e.copyWith(quantity: next) else e,
      ]);
    });
    try {
      // Server cart.php supports only action plus/minus (step of 1).
      await ApiClient.instance.post(
        'v1/retailer/cart',
        body: {
          'action': delta > 0 ? 'plus' : 'minus',
          'cart_id': item.cartId,
        },
      );
      // plus/minus return data:null, so reload cart to get the real qty.
      final cart = await RetailerService.getCart();
      if (!mounted) return;
      setState(() => _setCart(cart));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _data = previous);
      showStatus(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(item.productId));
    }
  }

  Future<void> _removeItem(CartItem item) async {
    if (_busy.contains(item.productId)) return;
    final previous = _data;
    setState(() {
      _busy.add(item.productId);
      _setItems([
        for (final e in _data?.items ?? const <CartItem>[])
          if (e.productId != item.productId) e,
      ]);
    });
    try {
      await ApiClient.instance.post(
        'v1/retailer/cart',
        body: {'action': 'delete', 'cart_id': item.cartId},
      );
      // delete returns data:null, so reload cart to show the real state.
      final cart = await RetailerService.getCart();
      if (!mounted) return;
      setState(() => _setCart(cart));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _data = previous);
      showStatus(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(item.productId));
    }
  }

  _LineTotals _line(CartItem item) {
    final offer = _offerById(_appliedByProduct[item.productId]);
    final extra = offer == null
        ? 0.0
        : offer.savingsFor(quantity: item.quantity, itemGross: item.itemGross);
    final savings = item.itemSavings + extra;
    final payable = (item.itemGross - savings).clamp(0, double.infinity).toDouble();
    return _LineTotals(gross: item.itemGross, savings: savings, payable: payable);
  }

  _Totals _totals() {
    final items = _data?.items ?? const <CartItem>[];
    var subtotal = 0.0;
    var savings = 0.0;
    for (final item in items) {
      final line = _line(item);
      subtotal += line.gross;
      savings += line.savings;
    }
    if (items.isEmpty) {
      final base = _data?.summary ?? const CartSummary();
      subtotal = base.grossTotal;
      savings = base.totalSavings;
    }
    return _Totals(
      subtotal: subtotal,
      savings: savings,
      net: (subtotal - savings).clamp(0, double.infinity).toDouble(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final empty = _data == null || _data!.items.isEmpty;
    return Scaffold(
      backgroundColor: AppColors.bg,
      appBar: AppBar(
        title: const Text(
          'Cart',
          style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.text),
        ),
        backgroundColor: AppColors.bg,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          tooltip: 'Back',
          icon: const HugeIcon(
            icon: HugeIcons.strokeRoundedChevronLeft,
            color: AppColors.text,
            strokeWidth: 1.5,
          ),
          onPressed: () => Navigator.of(context).maybePop(),
        ),
      ),
      body: _buildBody(),
      bottomNavigationBar: empty ? null : _bottom(_totals()),
    );
  }

  Widget _buildBody() {
    if (_loading && _data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _data == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.cloud_off, size: 40, color: AppColors.muted),
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: const TextStyle(color: AppColors.dangerText),
              ),
              const SizedBox(height: 16),
              FilledButton(onPressed: _load, child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final items = _data?.items ?? const <CartItem>[];
    final groups = _groupedItems();
    return RefreshIndicator(
      onRefresh: _load,
      child: items.isEmpty
          ? ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const [
                SizedBox(height: 140),
                Icon(Icons.shopping_cart_outlined, size: 56, color: AppColors.muted),
                SizedBox(height: 12),
                Center(
                  child: Text(
                    'Your cart is empty',
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w500,
                      color: AppColors.text,
                    ),
                  ),
                ),
              ],
            )
          : ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
              children: [
                for (var g = 0; g < groups.length; g++) ...[
                  if (g > 0) const SizedBox(height: 12),
                  _groupBlock(groups[g]),
                ],
                const SizedBox(height: 16),
                _couponSection(),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: _openCoupons,
                    style: TextButton.styleFrom(
                      foregroundColor: AppColors.blue,
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      minimumSize: const Size(0, 36),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: const Text(
                      'View Coupons',
                      style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
                const SizedBox(height: 4),
                _summaryCard(_totals()),
              ],
            ),
    );
  }

  int _schemeKeyFor(CartItem item) {
    final applied = _appliedByProduct[item.productId];
    if (applied != null && applied > 0) return applied;
    for (final o in _offers) {
      if (!o.isAllProducts && o.productId == item.productId) return o.id;
    }
    return 0;
  }

  List<_CartGroup> _groupedItems() {
    final items = _data?.items ?? const <CartItem>[];
    final buckets = <int, List<CartItem>>{};
    final order = <int>[];
    for (final item in items) {
      final key = _schemeKeyFor(item);
      if (!buckets.containsKey(key)) {
        buckets[key] = [];
        order.add(key);
      }
      buckets[key]!.add(item);
    }
    return [
      for (final k in order)
        _CartGroup(
          offer: k == 0 ? null : _offerById(k),
          applied: k != 0 &&
              buckets[k]!.any((e) => _appliedByProduct[e.productId] == k),
          items: buckets[k]!,
        ),
    ];
  }

  /// One separate card per offer group: banner (applied / add more / eligible)
  /// on top, then the items of that group.
  Widget _groupBlock(_CartGroup group) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: AnimatedSize(
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
        alignment: Alignment.topCenter,
        child: Column(
          children: [
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 250),
              child: KeyedSubtree(
                key: ValueKey(_headerState(group)),
                child: _schemeHeader(group),
              ),
            ),
            for (var i = 0; i < group.items.length; i++) ...[
              if (i > 0 || group.offer != null)
                const Divider(height: 1, color: AppColors.border),
              _CartItemRow(
                item: group.items[i],
                line: _line(group.items[i]),
                busy: _busy.contains(group.items[i].productId),
                onMinus: () => _changeQty(group.items[i], -1),
                onPlus: () => _changeQty(group.items[i], 1),
                onRemove: () => _removeItem(group.items[i]),
              ),
            ],
          ],
        ),
      ),
    );
  }

  String _headerState(_CartGroup group) {
    final offer = group.offer;
    if (offer == null) return 'none';
    if (group.applied) return 'applied-${offer.id}';
    final short = group.items.any((e) => e.quantity < offer.minQty);
    return short ? 'short-${offer.id}' : 'eligible-${offer.id}';
  }

  Widget _schemeHeader(_CartGroup group) {
    final offer = group.offer;
    if (offer == null) return const SizedBox.shrink();

    if (group.applied) {
      var savings = 0.0;
      for (final item in group.items) {
        savings += _line(item).savings;
      }
      return _AppliedBanner(offer: offer, savings: savings);
    }

    final short = group.items.where((e) => e.quantity < offer.minQty).toList();
    if (short.isNotEmpty) {
      final need = offer.minQty - short.first.quantity;
      final title = offer.schemeTitle.trim().isEmpty
          ? offer.originType
          : offer.schemeTitle.trim();
      return _HintBanner(
        hints: [
          _Hint(
            text:
                'Add $need more case${need == 1 ? '' : 's'} to avail this coupon · $title (${_benefitLabel(offer)})',
            minQty: offer.minQty,
          ),
        ],
      );
    }

    return _EligibleBanner(
      offer: offer,
      benefit: _benefitLabel(offer),
      onApply: () => _toggleOffer(offer, true),
    );
  }

  Widget _couponSection() {
    final offers = _switchOffers();
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: AnimatedSize(
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
        alignment: Alignment.topCenter,
        child: Column(
          children: [
            _couponCodeRow(),
            for (final offer in offers) ...[
              const Divider(height: 1, color: AppColors.border),
              _CouponSwitchRow(
                offer: offer,
                benefit: _benefitLabel(offer),
                applied: _isApplied(offer),
                onChanged: (v) => _toggleOffer(offer, v),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _couponCodeRow() {
    return Container(
      height: 52,
      padding: const EdgeInsets.only(left: 14, right: 6),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _couponCtrl,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _applyFromBar(),
              decoration: const InputDecoration(
                hintText: 'Apply Coupon Code',
                hintStyle: TextStyle(fontSize: 14, color: AppColors.muted),
                border: InputBorder.none,
                isDense: true,
              ),
            ),
          ),
          TextButton(
            onPressed: _applyFromBar,
            child: const Text(
              'Apply',
              style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }

  void _proceedToCheckout() {
    if (_busy.isNotEmpty) return;
    // TODO: open checkout screen here (API: GET/POST v1/retailer/checkout).
    showStatus(context, 'Checkout screen not connected yet.', error: true);
  }

  Widget _bottom(_Totals totals) {
    return SafeArea(
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        decoration: const BoxDecoration(
          color: AppColors.card,
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: Row(
          children: [
            Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Total',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: AppColors.muted,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  formatInr(totals.net),
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: AppColors.text,
                  ),
                ),
              ],
            ),
            const Spacer(),
            FilledButton(
              onPressed: _busy.isEmpty ? _proceedToCheckout : null,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.blue,
                minimumSize: const Size(0, 46),
                padding: const EdgeInsets.symmetric(horizontal: 22),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                textStyle: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                ),
              ),
              child: const Text('Proceed to Checkout'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryCard(_Totals totals) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Order Summary',
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: AppColors.text,
            ),
          ),
          const SizedBox(height: 12),
          _sumRow('Subtotal', formatInr(totals.subtotal)),
          if (totals.savings > 0) ...[
            const SizedBox(height: 8),
            _sumRow(
              'Savings',
              formatInr(totals.savings),
              valueColor: AppColors.successText,
            ),
          ],
          const SizedBox(height: 8),
          _sumRow('Net Payable', formatInr(totals.net), bold: true),
        ],
      ),
    );
  }

  Widget _sumRow(String label, String value, {Color? valueColor, bool bold = false}) {
    return Row(
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: bold ? 14 : 13,
            fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
            color: AppColors.text,
          ),
        ),
        const Spacer(),
        Text(
          value,
          style: TextStyle(
            fontSize: bold ? 15 : 13,
            fontWeight: bold ? FontWeight.w700 : FontWeight.w600,
            color: valueColor ?? AppColors.text,
          ),
        ),
      ],
    );
  }
}

class _Totals {
  final double subtotal;
  final double savings;
  final double net;
  const _Totals({required this.subtotal, required this.savings, required this.net});
}

class _LineTotals {
  final double gross;
  final double savings;
  final double payable;
  const _LineTotals({required this.gross, required this.savings, required this.payable});
}

class _Hint {
  final String text;
  final int minQty;
  const _Hint({required this.text, required this.minQty});
}

class _CartGroup {
  final RetailerSchemeOffer? offer;
  final bool applied;
  final List<CartItem> items;
  const _CartGroup({
    required this.offer,
    required this.applied,
    required this.items,
  });
}

class _CartItemRow extends StatelessWidget {
  final CartItem item;
  final _LineTotals line;
  final bool busy;
  final VoidCallback onMinus;
  final VoidCallback onPlus;
  final VoidCallback onRemove;

  const _CartItemRow({
    required this.item,
    required this.line,
    required this.busy,
    required this.onMinus,
    required this.onPlus,
    required this.onRemove,
  });

  @override
  Widget build(BuildContext context) {
    final photo = (item.photoUrl ?? '').trim();
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 14),
      child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: SizedBox(
                    width: 64,
                    height: 64,
                    child: photo.isNotEmpty
                        ? Image.network(
                            photo,
                            fit: BoxFit.cover,
                            errorBuilder: (context, error, stack) => const ColoredBox(
                              color: AppColors.inputBg,
                              child: Icon(Icons.image_outlined, color: AppColors.muted),
                            ),
                          )
                        : const ColoredBox(
                            color: AppColors.inputBg,
                            child: Icon(Icons.image_outlined, color: AppColors.muted),
                          ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Text(
                              item.productName,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 14,
                                fontWeight: FontWeight.w700,
                                color: AppColors.text,
                                height: 1.3,
                              ),
                            ),
                          ),
                          GestureDetector(
                            behavior: HitTestBehavior.opaque,
                            onTap: busy ? null : onRemove,
                            child: const SizedBox(
                              width: 36,
                              height: 36,
                              child: Icon(
                                Icons.delete_outline_rounded,
                                size: 20,
                                color: AppColors.muted,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${formatInr(item.unitPrice)}/case',
                        style: const TextStyle(fontSize: 12, color: AppColors.muted),
                      ),
                      const SizedBox(height: 10),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          _QtyStepper(
                            quantity: item.quantity,
                            busy: busy,
                            onMinus: onMinus,
                            onPlus: onPlus,
                          ),
                          const Spacer(),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              if (line.savings > 0)
                                Text(
                                  formatInr(line.gross),
                                  style: const TextStyle(
                                    fontSize: 12,
                                    color: AppColors.muted,
                                    decoration: TextDecoration.lineThrough,
                                  ),
                                ),
                              Text(
                                formatInr(line.payable),
                                style: TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w700,
                                  color: line.savings > 0
                                      ? AppColors.successText
                                      : AppColors.text,
                                ),
                              ),
                              if (line.savings > 0)
                                Text(
                                  '-${formatInr(line.savings)} Saved',
                                  style: const TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w600,
                                    color: AppColors.successText,
                                  ),
                                ),
                            ],
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
    );
  }
}

class _AppliedBanner extends StatelessWidget {
  final RetailerSchemeOffer offer;
  final double savings;
  const _AppliedBanner({required this.offer, required this.savings});

  @override
  Widget build(BuildContext context) {
    final title = offer.schemeTitle.trim().isEmpty
        ? offer.originType
        : offer.schemeTitle.trim();
    final code = offer.schemeType.trim();
    return Container(
      width: double.infinity,
      color: const Color(0xFFD6F5EA),
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      child: Row(
        children: [
          const Icon(Icons.check_circle, size: 16, color: AppColors.successText),
          const SizedBox(width: 8),
          Expanded(
            child: Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              runSpacing: 4,
              children: [
                Text(
                  '$title · ${offer.benefit}',
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: AppColors.successText,
                  ),
                ),
                if (code.isNotEmpty)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFFEDE9FE),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      code,
                      style: const TextStyle(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: Color(0xFF6D28D9),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          if (savings > 0)
            Text(
              '-${formatInr(savings)}',
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.successText,
              ),
            ),
        ],
      ),
    );
  }
}

class _HintBanner extends StatelessWidget {
  final List<_Hint> hints;
  const _HintBanner({required this.hints});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: const Color(0xFFFFF7ED),
      child: Column(
        children: [
          for (var i = 0; i < hints.length; i++) ...[
            if (i != 0) const Divider(height: 1, color: Color(0xFFFED7AA)),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              child: Row(
                children: [
                  const Icon(Icons.local_offer_outlined, size: 16, color: Color(0xFFB45309)),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      hints[i].text,
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF9A3412),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFED7AA),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      'Min ${hints[i].minQty}',
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: Color(0xFF9A3412),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _QtyStepper extends StatelessWidget {
  final int quantity;
  final bool busy;
  final VoidCallback onMinus;
  final VoidCallback onPlus;
  const _QtyStepper({
    required this.quantity,
    required this.busy,
    required this.onMinus,
    required this.onPlus,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.inputBg,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        height: 36,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: AppColors.border),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _stepBtn(Icons.remove, onMinus, enabled: !busy),
            SizedBox(
              width: 28,
              child: Center(
                child: busy
                    ? const SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(
                        '$quantity',
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: AppColors.text,
                        ),
                      ),
              ),
            ),
            _stepBtn(Icons.add, onPlus, enabled: !busy),
          ],
        ),
      ),
    );
  }

  Widget _stepBtn(IconData icon, VoidCallback onTap, {required bool enabled}) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: enabled ? onTap : null,
      child: SizedBox(
        width: 36,
        height: 36,
        child: Icon(icon, size: 16, color: enabled ? AppColors.text : AppColors.muted),
      ),
    );
  }
}

class _EligibleBanner extends StatelessWidget {
  final RetailerSchemeOffer offer;
  final String benefit;
  final VoidCallback onApply;
  const _EligibleBanner({
    required this.offer,
    required this.benefit,
    required this.onApply,
  });

  @override
  Widget build(BuildContext context) {
    final title = offer.schemeTitle.trim().isEmpty
        ? offer.originType
        : offer.schemeTitle.trim();
    return Container(
      width: double.infinity,
      color: const Color(0xFFEFF6FF),
      padding: const EdgeInsets.fromLTRB(12, 6, 6, 6),
      child: Row(
        children: [
          const Icon(Icons.local_offer_outlined, size: 16, color: AppColors.blue),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '$title · $benefit available',
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: AppColors.blue,
              ),
            ),
          ),
          TextButton(
            onPressed: onApply,
            style: TextButton.styleFrom(
              minimumSize: const Size(0, 32),
              padding: const EdgeInsets.symmetric(horizontal: 10),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            ),
            child: const Text(
              'Apply',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}

class _CouponSwitchRow extends StatelessWidget {
  final RetailerSchemeOffer offer;
  final String benefit;
  final bool applied;
  final ValueChanged<bool> onChanged;
  const _CouponSwitchRow({
    required this.offer,
    required this.benefit,
    required this.applied,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final title = offer.schemeTitle.trim().isEmpty
        ? offer.originType
        : offer.schemeTitle.trim();
    final scope = offer.isAllProducts ? 'All products' : offer.productName;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 250),
      curve: Curves.easeOut,
      color: applied ? const Color(0xFFF0FBF6) : AppColors.card,
      padding: const EdgeInsets.fromLTRB(14, 8, 8, 8),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Apply $title',
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.text,
                  ),
                ),
                const SizedBox(height: 2),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 200),
                  child: Text(
                    applied ? 'Applied · $benefit' : '$benefit · $scope',
                    key: ValueKey(applied),
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: applied ? AppColors.successText : AppColors.muted,
                    ),
                  ),
                ),
              ],
            ),
          ),
          Switch(
            value: applied,
            onChanged: onChanged,
            thumbColor: WidgetStateProperty.resolveWith(
              (states) => states.contains(WidgetState.selected)
                  ? Colors.white
                  : null,
            ),
            trackColor: WidgetStateProperty.resolveWith(
              (states) => states.contains(WidgetState.selected)
                  ? AppColors.successText
                  : null,
            ),
          ),
        ],
      ),
    );
  }
}