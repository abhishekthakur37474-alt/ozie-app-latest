import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/dashboard_service.dart';
import '../../core/models.dart';
import '../../core/retailer_service.dart';
import '../../widgets/app_widgets.dart';

class HomeTab extends StatefulWidget {
  final Session session;
  final VoidCallback? onViewOrders;
  final VoidCallback? onViewCatalog;
  const HomeTab({
    super.key,
    required this.session,
    this.onViewOrders,
    this.onViewCatalog,
  });

  @override
  State<HomeTab> createState() => _HomeTabState();
}

class _HomeTabState extends State<HomeTab> {
  late Future<RetailerDashboard> _future;

  @override
  void initState() {
    super.initState();
    _future = DashboardService.getRetailer();
    RetailerService.getProducts(limit: 12);
  }

  Future<void> _refresh() async {
    final next = DashboardService.getRetailer();
    setState(() => _future = next);
    try {
      await next;
    } on ApiException {
      // error is surfaced by the FutureBuilder
    }
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _refresh,
      child: FutureBuilder<RetailerDashboard>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState == ConnectionState.waiting && !snap.hasData) {
            return const _CenteredListView(child: CircularProgressIndicator());
          }
          if (snap.hasError) {
            final error = snap.error;
            final message = error is ApiException ? error.message : 'Something went wrong.';
            return _CenteredListView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.cloud_off, size: 40, color: AppColors.muted),
                  const SizedBox(height: 12),
                  Text(
                    message,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: AppColors.dangerText),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(onPressed: _refresh, child: const Text('Retry')),
                ],
              ),
            );
          }
          return _DashboardBody(
            dashboard: snap.data!,
            onViewOrders: widget.onViewOrders,
            onViewCatalog: widget.onViewCatalog,
          );
        },
      ),
    );
  }
}

class _CenteredListView extends StatelessWidget {
  final Widget child;
  const _CenteredListView({required this.child});

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(height: MediaQuery.of(context).size.height * 0.3),
        Center(child: child),
      ],
    );
  }
}

class _DashboardBody extends StatelessWidget {
  final RetailerDashboard dashboard;
  final VoidCallback? onViewOrders;
  final VoidCallback? onViewCatalog;
  const _DashboardBody({
    required this.dashboard,
    this.onViewOrders,
    this.onViewCatalog,
  });

  @override
  Widget build(BuildContext context) {
    final d = dashboard;
    final schemes = d.allSchemes;

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
      children: [
        if (schemes.isNotEmpty) ...[
          const _SectionTitle('Active Schemes'),
          const SizedBox(height: 12),
          _SchemeStrip(schemes: schemes),
          const SizedBox(height: 20),
        ],
        _TodayRow(today: d.today, counts: d.counts),
        const SizedBox(height: 20),
        _CreditCard(credit: d.credit),
        if (d.banners.isNotEmpty) ...[
          const SizedBox(height: 28),
          const _SectionTitle('Offers & Updates'),
          const SizedBox(height: 14),
          _BannerCarousel(banners: d.banners),
        ],
        const SizedBox(height: 28),
        _SectionHeader(title: 'Latest Approved Orders', onViewAll: onViewOrders),
        const SizedBox(height: 14),
        _LatestOrders(orders: d.latestOrders),
        const SizedBox(height: 28),
        _SectionHeader(title: 'Pending Payments', onViewAll: onViewOrders),
        const SizedBox(height: 14),
        _PendingPayments(payments: d.pendingPayments),
        if (d.quickActions.isNotEmpty) ...[
          const SizedBox(height: 28),
          const _SectionTitle('Quick Actions'),
          const SizedBox(height: 14),
          _QuickActions(
            actions: d.quickActions,
            onViewOrders: onViewOrders,
            onViewCatalog: onViewCatalog,
          ),
        ],
        const SizedBox(height: 12),
      ],
    );
  }
}

/* ----------------------------- credit ----------------------------- */

class _CreditCard extends StatelessWidget {
  final DashboardCredit credit;
  const _CreditCard({required this.credit});

  @override
  Widget build(BuildContext context) {
    return _SectionCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.account_balance_wallet_outlined,
                  size: 20, color: AppColors.blue),
              const SizedBox(width: 10),
              const Text(
                'Credit',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w600,
                  color: AppColors.text,
                ),
              ),
              const Spacer(),
              if (credit.creditDays > 0)
                Text(
                  '${credit.creditDays} days',
                  style: const TextStyle(fontSize: 13, color: AppColors.muted),
                ),
            ],
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              Expanded(
                child: _AmountBlock(
                  label: 'Available',
                  value: credit.availableCredit,
                  color: AppColors.successText,
                ),
              ),
              Container(width: 1, height: 48, color: AppColors.border),
              Expanded(
                child: _AmountBlock(
                  label: 'Used',
                  value: credit.currentBalance,
                  color: AppColors.dangerText,
                ),
              ),
              Container(width: 1, height: 48, color: AppColors.border),
              Expanded(
                child: _AmountBlock(
                  label: 'Limit',
                  value: credit.limit,
                  color: AppColors.text,
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: credit.usedRatio,
              minHeight: 9,
              backgroundColor: AppColors.border,
              valueColor: const AlwaysStoppedAnimation(AppColors.blue),
            ),
          ),
        ],
      ),
    );
  }
}

class _AmountBlock extends StatelessWidget {
  final String label;
  final double value;
  final Color color;
  const _AmountBlock({required this.label, required this.value, required this.color});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(
          formatInr(value, decimals: false),
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: color),
        ),
        const SizedBox(height: 4),
        Text(label, style: const TextStyle(fontSize: 12, color: AppColors.muted)),
      ],
    );
  }
}

/* ----------------------------- today ----------------------------- */

class _TodayRow extends StatelessWidget {
  final DashboardToday today;
  final DashboardCounts counts;
  const _TodayRow({required this.today, required this.counts});

  @override
  Widget build(BuildContext context) {
    // IntrinsicHeight + stretch keeps all three tiles the same height even
    // when a label wraps to two lines.
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: _StatTile(
              icon: Icons.shopping_bag_outlined,
              label: "Today's Orders",
              value: '${today.ordersCount}',
              tint: AppColors.blue,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: _StatTile(
              icon: Icons.pending_actions_outlined,
              label: 'Pending Orders',
              value: '${counts.pendingOrders}',
              tint: AppColors.green,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: _StatTile(
              icon: Icons.local_shipping_outlined,
              label: 'Dispatches',
              value: '${counts.dispatched}',
              tint: AppColors.successText,
            ),
          ),
          // Sales, Paid and Pending Pay tiles are intentionally hidden.
          // Expanded(
          //   child: _StatTile(
          //     icon: Icons.trending_up,
          //     label: 'Sales',
          //     value: formatInr(today.salesValue, decimals: false),
          //     tint: AppColors.green,
          //   ),
          // ),
          // const SizedBox(width: 12),
          // Expanded(
          //   child: _StatTile(
          //     icon: Icons.payments_outlined,
          //     label: 'Paid',
          //     value: formatInr(today.paidValue, decimals: false),
          //     tint: AppColors.successText,
          //   ),
          // ),
        ],
      ),
    );
  }
}

class _StatTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final Color tint;
  const _StatTile({
    required this.icon,
    required this.label,
    required this.value,
    required this.tint,
  });

  @override
  Widget build(BuildContext context) {
    return _SectionCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 22, color: tint),
          const SizedBox(height: 10),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w700,
              color: AppColors.text,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: const TextStyle(fontSize: 12, color: AppColors.muted),
          ),
        ],
      ),
    );
  }
}

/* ----------------------------- banners ----------------------------- */

class _BannerCarousel extends StatefulWidget {
  final List<DashboardBanner> banners;
  const _BannerCarousel({required this.banners});

  @override
  State<_BannerCarousel> createState() => _BannerCarouselState();
}

class _BannerCarouselState extends State<_BannerCarousel> {
  final PageController _controller = PageController(viewportFraction: 0.94);
  Timer? _timer;
  int _page = 0;

  @override
  void initState() {
    super.initState();
    if (widget.banners.length > 1) {
      _timer = Timer.periodic(const Duration(seconds: 4), (_) {
        if (!mounted || !_controller.hasClients) return;
        _page = (_page + 1) % widget.banners.length;
        _controller.animateToPage(
          _page,
          duration: const Duration(milliseconds: 400),
          curve: Curves.easeInOut,
        );
      });
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final height = 180.0;
    return Column(
      children: [
        SizedBox(
          height: height,
          child: PageView.builder(
            controller: _controller,
            itemCount: widget.banners.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (context, i) => Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: _BannerTile(banner: widget.banners[i]),
            ),
          ),
        ),
        if (widget.banners.length > 1) ...[
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(
              widget.banners.length,
              (i) => AnimatedContainer(
                duration: const Duration(milliseconds: 250),
                margin: const EdgeInsets.symmetric(horizontal: 3),
                width: i == _page ? 18 : 6,
                height: 6,
                decoration: BoxDecoration(
                  color: i == _page ? AppColors.blue : AppColors.border,
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _BannerTile extends StatelessWidget {
  final DashboardBanner banner;
  const _BannerTile({required this.banner});

  @override
  Widget build(BuildContext context) {
    final url = banner.streamUrl ?? banner.mediaUrl;
    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Stack(
        fit: StackFit.expand,
        children: [
          const ColoredBox(color: AppColors.border),
          if (!banner.isVideo && (url ?? '').isNotEmpty)
            Image.network(
              url!,
              fit: BoxFit.cover,
              loadingBuilder: (context, child, progress) => progress == null
                  ? child
                  : const ColoredBox(color: AppColors.border),
              errorBuilder: (context, error, stack) => _bannerFallback(),
            )
          else if (banner.isVideo)
            _videoFallback(banner.title)
          else
            _bannerFallback(),
        ],
      ),
    );
  }

  Widget _bannerFallback() => const Center(
        child: Icon(Icons.image_outlined, size: 40, color: AppColors.muted),
      );

  Widget _videoFallback(String? title) => Container(
        color: const Color(0xFF0F172A),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.play_circle_fill, size: 48, color: Colors.white70),
            if ((title ?? '').isNotEmpty) ...[
              const SizedBox(height: 8),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Text(
                  title!,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontSize: 14),
                ),
              ),
            ],
          ],
        ),
      );
}

/* ----------------------------- schemes ----------------------------- */

/// Active schemes shown one full tile at a time (sliding, not half-cut).
class _SchemeStrip extends StatefulWidget {
  final List<DashboardScheme> schemes;
  const _SchemeStrip({required this.schemes});

  @override
  State<_SchemeStrip> createState() => _SchemeStripState();
}

class _SchemeStripState extends State<_SchemeStrip> {
  final PageController _controller = PageController();
  Timer? _timer;
  int _page = 0;

  @override
  void initState() {
    super.initState();
    if (widget.schemes.length > 1) {
      _timer = Timer.periodic(const Duration(seconds: 3), (_) {
        if (!mounted || !_controller.hasClients) return;
        _page = (_page + 1) % widget.schemes.length;
        _controller.animateToPage(
          _page,
          duration: const Duration(milliseconds: 500),
          curve: Curves.easeInOut,
        );
      });
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        SizedBox(
          height: 92,
          child: PageView.builder(
            controller: _controller,
            itemCount: widget.schemes.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (context, i) => _SchemeStripTile(
              scheme: widget.schemes[i],
            ),
          ),
        ),
        if (widget.schemes.length > 1) ...[
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(
              widget.schemes.length,
              (i) => AnimatedContainer(
                duration: const Duration(milliseconds: 250),
                margin: const EdgeInsets.symmetric(horizontal: 3),
                width: i == _page ? 18 : 6,
                height: 6,
                decoration: BoxDecoration(
                  color: i == _page ? AppColors.blue : AppColors.border,
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// Compact one-line tile used inside the active-schemes strip.
class _SchemeStripTile extends StatelessWidget {
  final DashboardScheme scheme;
  const _SchemeStripTile({required this.scheme});

  @override
  Widget build(BuildContext context) {
    final hasImage = (scheme.giftImageUrl ?? '').isNotEmpty;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.inputBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: hasImage
                ? Image.network(
                    scheme.giftImageUrl!,
                    width: 56,
                    height: 56,
                    fit: BoxFit.cover,
                    errorBuilder: (context, error, stack) => _thumb(),
                  )
                : _thumb(),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  scheme.title ?? scheme.schemeType ?? 'Scheme',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AppColors.text,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  scheme.productName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12, color: AppColors.muted),
                ),
                if ((scheme.giftName ?? '').isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(
                    'Gift: ${scheme.giftName}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: AppColors.successText,
                    ),
                  ),
                ] else if ((scheme.minText ?? '').isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(
                    scheme.minText!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 12, color: AppColors.text),
                  ),
                ],
              ],
            ),
          ),
          if (!scheme.isAvailable)
            const Padding(
              padding: EdgeInsets.only(left: 8),
              child: Icon(Icons.block, size: 16, color: AppColors.dangerText),
            ),
        ],
      ),
    );
  }

  Widget _thumb() => Container(
        width: 56,
        height: 56,
        decoration: BoxDecoration(
          color: AppColors.successBg,
          borderRadius: BorderRadius.circular(8),
        ),
        child: const Icon(
          Icons.card_giftcard,
          size: 24,
          color: AppColors.successText,
        ),
      );
}

/* ----------------------------- quick actions ----------------------------- */

class _QuickActions extends StatelessWidget {
  final List<DashboardQuickAction> actions;
  final VoidCallback? onViewOrders;
  final VoidCallback? onViewCatalog;
  const _QuickActions({
    required this.actions,
    this.onViewOrders,
    this.onViewCatalog,
  });

  static const Map<String, IconData> _icons = {
    'catalog': Icons.storefront_outlined,
    'store': Icons.storefront_outlined,
    'orders': Icons.receipt_long_outlined,
    'cart': Icons.shopping_bag_outlined,
    'reports': Icons.bar_chart_rounded,
    'analytics': Icons.analytics_outlined,
    'payments': Icons.payments_outlined,
    'card': Icons.credit_card_outlined,
    'create_order': Icons.mic_none_rounded,
    'camera': Icons.photo_camera_outlined,
    'returns': Icons.assignment_return_outlined,
    'approve_orders': Icons.check_circle_outline,
    'pack_orders': Icons.inventory_2_outlined,
    'dispatch': Icons.local_shipping_outlined,
    'invoice': Icons.receipt_long_outlined,
    'stock_check': Icons.warehouse_outlined,
  };

  IconData _iconFor(DashboardQuickAction a) {
    return _icons[a.key] ?? _icons[a.icon ?? ''] ?? Icons.grid_view_outlined;
  }

  void _tap(BuildContext context, DashboardQuickAction a) {
    switch (a.key) {
      case 'catalog':
        onViewCatalog?.call();
        return;
      case 'orders':
        onViewOrders?.call();
        return;
      default:
        showStatus(context, '${a.label} - coming soon');
    }
  }

  @override
  Widget build(BuildContext context) {
    // 3 items per row, no surrounding card/container.
    final itemWidth = (MediaQuery.of(context).size.width - 40 - 24) / 3;
    return Wrap(
      spacing: 12,
      runSpacing: 22,
      children: actions.map((a) {
        return SizedBox(
          width: itemWidth,
          // Fixed height so every tile stays the same size regardless of
          // how many lines the label wraps to.
          height: 100,
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: () => _tap(context, a),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    _iconFor(a),
                    size: 34,
                    color: AppColors.blue,
                  ),
                  const SizedBox(height: 10),
                  SizedBox(
                    height: 36,
                    child: Text(
                      a.label,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 13,
                        height: 1.3,
                        fontWeight: FontWeight.w500,
                        color: AppColors.text,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      }).toList(),
    );
  }
}

/* ----------------------------- latest orders ----------------------------- */

class _LatestOrders extends StatelessWidget {
  final List<DashboardOrder> orders;
  const _LatestOrders({required this.orders});

  @override
  Widget build(BuildContext context) {
    if (orders.isEmpty) {
      return const _EmptyState(
        icon: Icons.receipt_long_outlined,
        message: 'No approved orders yet',
      );
    }
    return _SectionCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          for (var i = 0; i < orders.length; i++) ...[
            if (i != 0) const Divider(height: 1, color: AppColors.border),
            _OrderRow(order: orders[i]),
          ],
        ],
      ),
    );
  }
}

class _OrderRow extends StatelessWidget {
  final DashboardOrder order;
  const _OrderRow({required this.order});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  order.displayNumber.isNotEmpty
                      ? order.displayNumber
                      : 'ORD-${order.orderId}',
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AppColors.text,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${order.customer} · ${_prettyDate(order.createdAt)}',
                  style: const TextStyle(fontSize: 12, color: AppColors.muted),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                formatInr(order.total),
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.text,
                ),
              ),
              const SizedBox(height: 3),
              _StatusBadge(
                text: order.isPaid ? 'Paid' : (order.paymentStatus ?? 'Pending'),
                positive: order.isPaid,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/* ----------------------------- pending payments ----------------------------- */

class _PendingPayments extends StatelessWidget {
  final List<DashboardPendingPayment> payments;
  const _PendingPayments({required this.payments});

  @override
  Widget build(BuildContext context) {
    if (payments.isEmpty) {
      return const _EmptyState(
        icon: Icons.payments_outlined,
        message: 'No pending payments',
      );
    }
    return _SectionCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          for (var i = 0; i < payments.length; i++) ...[
            if (i != 0) const Divider(height: 1, color: AppColors.border),
            _PaymentRow(payment: payments[i]),
          ],
        ],
      ),
    );
  }
}

class _PaymentRow extends StatelessWidget {
  final DashboardPendingPayment payment;
  const _PaymentRow({required this.payment});

  @override
  Widget build(BuildContext context) {
    final amount = payment.dueAmount ?? payment.total;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  payment.invoiceNumber ?? 'Order #${payment.orderId}',
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AppColors.text,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  (payment.dueDate ?? '').isNotEmpty
                      ? 'Due ${_prettyDate(payment.dueDate)}'
                      : (payment.paymentStatus ?? ''),
                  style: const TextStyle(fontSize: 12, color: AppColors.muted),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                formatInr(amount),
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.text,
                ),
              ),
              const SizedBox(height: 3),
              _StatusBadge(text: payment.badge, positive: !payment.isOverdue),
            ],
          ),
        ],
      ),
    );
  }
}

String _prettyDate(String? raw) {
  final s = (raw ?? '').trim();
  if (s.isEmpty) return '-';
  if (RegExp(r'[A-Za-z]').hasMatch(s)) return s;
  return formatDate(s);
}

/* ----------------------------- shared ----------------------------- */

class _EmptyState extends StatelessWidget {
  final IconData icon;
  final String message;
  const _EmptyState({required this.icon, required this.message});

  @override
  Widget build(BuildContext context) {
    return _SectionCard(
      child: Center(
        child: Column(
          children: [
            Icon(icon, size: 30, color: AppColors.muted),
            const SizedBox(height: 10),
            Text(
              message,
              style: const TextStyle(fontSize: 13, color: AppColors.muted),
            ),
          ],
        ),
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  const _SectionCard({
    required this.child,
    this.padding = const EdgeInsets.all(18),
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
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
      child: child,
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String text;
  const _SectionTitle(this.text);

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
        fontSize: 17,
        fontWeight: FontWeight.w700,
        color: AppColors.text,
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String title;
  final VoidCallback? onViewAll;
  const _SectionHeader({required this.title, this.onViewAll});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: _SectionTitle(title)),
        TextButton(
          onPressed: onViewAll,
          style: TextButton.styleFrom(
            foregroundColor: AppColors.blue,
            padding: const EdgeInsets.symmetric(horizontal: 8),
            minimumSize: const Size(0, 32),
            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
          ),
          child: const Text(
            'View All',
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
          ),
        ),
      ],
    );
  }
}

class _StatusBadge extends StatelessWidget {
  final String text;
  final bool positive;
  const _StatusBadge({required this.text, required this.positive});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: positive ? AppColors.successBg : AppColors.dangerBg,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: positive ? AppColors.successText : AppColors.dangerText,
        ),
      ),
    );
  }
}
