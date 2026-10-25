import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../core/api_client.dart';
import '../core/models.dart';
import '../core/retailer_service.dart';
import '../widgets/app_widgets.dart';

class CouponsScreen extends StatefulWidget {
  final List<CartItem> cartItems;
  final Map<int, int> appliedByProduct;
  const CouponsScreen({
    super.key,
    required this.cartItems,
    required this.appliedByProduct,
  });

  @override
  State<CouponsScreen> createState() => _CouponsScreenState();
}

class _CouponsScreenState extends State<CouponsScreen> {
  RetailerSchemesPage? _data;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final next = await RetailerService.getSchemes();
      if (!mounted) return;
      setState(() {
        _data = next;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  CartItem? _itemFor(RetailerSchemeOffer offer) {
    if (offer.isAllProducts) return null;
    for (final item in widget.cartItems) {
      if (item.productId == offer.productId) return item;
    }
    return null;
  }

  bool _isApplied(RetailerSchemeOffer offer) {
    if (offer.isAllProducts) {
      return widget.appliedByProduct.values.contains(offer.id);
    }
    final pid = offer.productId;
    if (pid == null) return false;
    return widget.appliedByProduct[pid] == offer.id;
  }

  bool _itemHasOtherOffer(CartItem item, RetailerSchemeOffer offer) {
    final applied = widget.appliedByProduct[item.productId];
    return applied != null && applied != offer.id;
  }

  _CouponStatus _statusFor(RetailerSchemeOffer offer) {
    if (_isApplied(offer)) {
      return _CouponStatus(label: 'Applied', canApply: false, applied: true);
    }
    if (!offer.isAllProducts) {
      final item = _itemFor(offer);
      if (item == null) {
        return const _CouponStatus(
          label: 'Add this product to cart',
          canApply: false,
        );
      }
      if (_itemHasOtherOffer(item, offer)) {
        return const _CouponStatus(
          label: 'Another offer is already on this product',
          canApply: false,
        );
      }
      if (item.quantity < offer.minQty) {
        final need = offer.minQty - item.quantity;
        return _CouponStatus(
          label: 'Add $need more case${need == 1 ? '' : 's'} · Min ${offer.minQty}',
          canApply: false,
        );
      }
      return const _CouponStatus(
        label: 'Applies only to this product',
        canApply: true,
      );
    }

    final free = widget.cartItems
        .where((e) => !widget.appliedByProduct.containsKey(e.productId))
        .toList();
    if (free.isEmpty) {
      return const _CouponStatus(
        label: 'Unattached · all items already have an offer',
        canApply: false,
      );
    }
    final ready = free.where((e) => e.quantity >= offer.minQty).toList();
    if (ready.isEmpty) {
      CartItem? closest;
      for (final item in free) {
        if (closest == null || item.quantity > closest.quantity) closest = item;
      }
      final need = offer.minQty - (closest?.quantity ?? 0);
      return _CouponStatus(
        label: 'Unattached · add $need more case${need == 1 ? '' : 's'} · Min ${offer.minQty}',
        canApply: false,
      );
    }
    return _CouponStatus(
      label: 'Unattached · applies to ${ready.length} item${ready.length == 1 ? '' : 's'} without an offer',
      canApply: true,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Coupons'),
        backgroundColor: AppColors.card,
        surfaceTintColor: Colors.transparent,
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

    final offers = _data?.offers ?? const <RetailerSchemeOffer>[];
    return RefreshIndicator(
      onRefresh: _load,
      child: offers.isEmpty
          ? ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const [
                SizedBox(height: 140),
                Icon(Icons.local_offer_outlined, size: 56, color: AppColors.muted),
                SizedBox(height: 12),
                Center(
                  child: Text(
                    'No coupons available',
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
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              itemCount: offers.length,
              separatorBuilder: (context, i) => const SizedBox(height: 12),
              itemBuilder: (context, i) {
                final offer = offers[i];
                return _CouponCard(
                  offer: offer,
                  status: _statusFor(offer),
                  onApply: () => Navigator.of(context).pop(offer),
                );
              },
            ),
    );
  }
}

class _CouponStatus {
  final String label;
  final bool canApply;
  final bool applied;
  const _CouponStatus({
    required this.label,
    required this.canApply,
    this.applied = false,
  });
}

class _CouponCard extends StatelessWidget {
  final RetailerSchemeOffer offer;
  final _CouponStatus status;
  final VoidCallback onApply;
  const _CouponCard({
    required this.offer,
    required this.status,
    required this.onApply,
  });

  @override
  Widget build(BuildContext context) {
    final title = offer.schemeTitle.trim().isEmpty
        ? offer.originType
        : offer.schemeTitle.trim();
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: status.applied ? AppColors.successText : AppColors.border,
        ),
      ),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppColors.text,
                  ),
                ),
              ),
              if (offer.benefit.isNotEmpty)
                Text(
                  offer.benefit,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: AppColors.successText,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            offer.isAllProducts
                ? 'All Products · Unattached'
                : offer.productName,
            style: const TextStyle(fontSize: 13, color: AppColors.muted),
          ),
          const SizedBox(height: 8),
          Text(
            status.label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: status.applied
                  ? AppColors.successText
                  : status.canApply
                      ? AppColors.blue
                      : const Color(0xFFB45309),
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: status.canApply ? onApply : null,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.blue,
                minimumSize: const Size(0, 40),
                textStyle: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                ),
              ),
              child: Text(status.applied ? 'Applied' : 'Apply'),
            ),
          ),
        ],
      ),
    );
  }
}
