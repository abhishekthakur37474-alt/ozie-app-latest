import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../core/api_client.dart';
import '../core/models.dart';
import '../core/retailer_service.dart';
import '../widgets/app_widgets.dart';
import 'catalog_screen.dart';

class SchemesScreen extends StatefulWidget {
  final bool embedded;
  final void Function({int? productId, String? productName})? onApply;
  const SchemesScreen({
    super.key,
    this.embedded = false,
    this.onApply,
  });

  @override
  State<SchemesScreen> createState() => _SchemesScreenState();
}

class _SchemesScreenState extends State<SchemesScreen> {
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

  void _apply(RetailerSchemeOffer offer) {
    final id = offer.productId;
    final name = offer.productName.trim();
    if (widget.onApply != null) {
      widget.onApply!(
        productId: (id != null && id > 0) ? id : null,
        productName: name.isEmpty || name.toLowerCase() == 'all products'
            ? null
            : name,
      );
      return;
    }
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CatalogScreen(
          highlightProductId: (id != null && id > 0) ? id : null,
          highlightProductName:
              name.isEmpty || name.toLowerCase() == 'all products' ? null : name,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final body = _buildBody();
    if (widget.embedded) return body;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Schemes'),
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
      body: body,
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
                    'No active schemes',
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
              itemCount: offers.length + 1,
              separatorBuilder: (context, i) => const SizedBox(height: 12),
              itemBuilder: (context, i) {
                if (i == 0) {
                  return Text(
                    '${_data!.totalOffers} active offer${_data!.totalOffers == 1 ? '' : 's'}',
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppColors.muted,
                    ),
                  );
                }
                return _SchemeCard(
                  offer: offers[i - 1],
                  onApply: () => _apply(offers[i - 1]),
                );
              },
            ),
    );
  }
}

class _SchemeCard extends StatelessWidget {
  final RetailerSchemeOffer offer;
  final VoidCallback onApply;
  const _SchemeCard({required this.offer, required this.onApply});

  @override
  Widget build(BuildContext context) {
    final title = offer.schemeTitle.trim().isEmpty
        ? offer.originType
        : offer.schemeTitle.trim();
    final typeLabel = offer.schemeType.trim().isEmpty
        ? offer.originType
        : offer.schemeType.trim();
    final days = offer.daysLeft;
    final daysText = days <= 0
        ? 'Ended'
        : days == 1
            ? '1 day left'
            : '$days days left';

    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
        boxShadow: const [
          BoxShadow(
            color: Color(0x0A1E2A3B),
            blurRadius: 10,
            offset: Offset(0, 2),
          ),
        ],
      ),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: const Color(0xFFEFF6FF),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  typeLabel,
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: AppColors.blue,
                  ),
                ),
              ),
              const Spacer(),
              if (offer.benefit.isNotEmpty)
                Text(
                  offer.benefit,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppColors.successText,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            title,
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: AppColors.text,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            offer.productName,
            style: const TextStyle(fontSize: 13, color: AppColors.muted),
          ),
          if (offer.brand.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(
              offer.brand,
              style: const TextStyle(fontSize: 12, color: AppColors.muted),
            ),
          ],
          const SizedBox(height: 14),
          Row(
            children: [
              if (offer.minRequirement.isNotEmpty)
                Expanded(
                  child: Text(
                    'Min ${offer.minRequirement}',
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppColors.text,
                    ),
                  ),
                )
              else
                const Spacer(),
              Text(
                daysText,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: offer.isExpiringSoon
                      ? AppColors.dangerText
                      : AppColors.muted,
                ),
              ),
            ],
          ),
          if ((offer.startDate ?? '').isNotEmpty ||
              (offer.endDate ?? '').isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              '${formatDate(offer.startDate)} - ${formatDate(offer.endDate)}',
              style: const TextStyle(fontSize: 12, color: AppColors.muted),
            ),
          ],
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: onApply,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.blue,
                minimumSize: const Size(0, 40),
                textStyle: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                ),
              ),
              child: const Text('Apply'),
            ),
          ),
        ],
      ),
    );
  }
}
