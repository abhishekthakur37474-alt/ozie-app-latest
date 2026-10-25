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

  List<_Hint> _hintsFor(CartItem item) {
    if (_appliedByProduct.containsKey(item.productId)) return const [];
    final out = <_Hint>[];
    for (final offer in _offers) {
      if (!offer.isAllProducts) {
        if (offer.productId != item.productId) continue;
        if (item.quantity >= offer.minQty) continue;
        final need = offer.minQty - item.quantity;
        out.add(_Hint(
          text:
              'Add $need more case${need == 1 ? '' : 's'} for: ${offer.schemeTitle} (${_benefitLabel(offer)})',
          minQty: offer.minQty,
        ));
        continue;
      }
      if (item.quantity >= offer.minQty) continue;
      final need = offer.minQty - item.quantity;
      out.add(_Hint(
        text:
            'Add $need more case${need == 1 ? '' : 's'} for: ${offer.schemeTitle} (${_benefitLabel(offer)})',
        minQty: offer.minQty,
      ));
    }
    return out;
  }

  Future<void> _applyCart(Future<RetailerCart> request, int cartId) async {
    if (_busy.contains(cartId)) return;
    setState(() => _busy.add(cartId));
    try {
      final next = await request;
      if (!mounted) return;
      setState(() {
        _data = next;
        _pruneApplied();
      });
    } on ApiException catch (e) {
      try {
        await _load();
      } on ApiException {
        if (mounted) showStatus(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => _busy.remove(cartId));
    }
  }

  Future<void> _changeQty(CartItem item, int delta) async {
    final next = item.quantity + delta;
    if (next < 1) {
      await _removeItem(item);
      return;
    }
    await _applyCart(
      RetailerService.updateCartItem(
        cartId: item.cartId,
        productId: item.productId,
        quantity: next,
      ),
      item.cartId,
    );
  }

  Future<void> _removeItem(CartItem item) async {
    await _applyCart(
      RetailerService.removeCartItem(
        cartId: item.cartId,
        productId: item.productId,
      ),
      item.cartId,
    );
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
          : ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
              itemCount: items.length,
              separatorBuilder: (context, i) => const SizedBox(height: 12),
              itemBuilder: (context, i) {
                final item = items[i];
                final offer = _offerById(_appliedByProduct[item.productId]);
                return _CartItemCard(
                  item: item,
                  offer: offer,
                  hints: _hintsFor(item),
                  line: _line(item),
                  busy: _busy.contains(item.cartId),
                  onMinus: () => _changeQty(item, -1),
                  onPlus: () => _changeQty(item, 1),
                  onRemove: () => _removeItem(item),
                );
              },
            ),
    );
  }

  Widget _bottom(_Totals totals) {
    return SafeArea(
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        decoration: const BoxDecoration(
          color: AppColors.card,
          border: Border(top: BorderSide(color: AppColors.border)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _couponBar(),
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
            _summaryCard(totals),
          ],
        ),
      ),
    );
  }

  Widget _couponBar() {
    return Container(
      height: 48,
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
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

class _CartItemCard extends StatelessWidget {
  final CartItem item;
  final RetailerSchemeOffer? offer;
  final List<_Hint> hints;
  final _LineTotals line;
  final bool busy;
  final VoidCallback onMinus;
  final VoidCallback onPlus;
  final VoidCallback onRemove;

  const _CartItemCard({
    required this.item,
    required this.offer,
    required this.hints,
    required this.line,
    required this.busy,
    required this.onMinus,
    required this.onPlus,
    required this.onRemove,
  });

  @override
  Widget build(BuildContext context) {
    final photo = (item.photoUrl ?? '').trim();
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          if (offer != null)
            _AppliedBanner(offer: offer!, savings: line.savings)
          else if (hints.isNotEmpty)
            _HintBanner(hints: hints),
          Padding(
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
                          SizedBox(
                            width: 36,
                            height: 36,
                            child: IconButton(
                              tooltip: 'Remove',
                              onPressed: busy ? null : onRemove,
                              padding: EdgeInsets.zero,
                              icon: const Icon(
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
    return Container(
      height: 36,
      decoration: BoxDecoration(
        color: AppColors.inputBg,
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
    );
  }

  Widget _stepBtn(IconData icon, VoidCallback onTap, {required bool enabled}) {
    return SizedBox(
      width: 36,
      height: 36,
      child: IconButton(
        onPressed: enabled ? onTap : null,
        padding: EdgeInsets.zero,
        icon: Icon(icon, size: 16, color: enabled ? AppColors.text : AppColors.muted),
      ),
    );
  }
}
