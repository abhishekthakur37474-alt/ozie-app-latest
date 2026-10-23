import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/models.dart';
import '../../core/retailer_service.dart';
import '../../widgets/app_widgets.dart';

class OrdersTab extends StatefulWidget {
  const OrdersTab({super.key});

  @override
  State<OrdersTab> createState() => _OrdersTabState();
}

class _OrdersTabState extends State<OrdersTab> with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  bool _approvedReady = false;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _tabs.addListener(() {
      if (_tabs.index == 1 && !_approvedReady) {
        setState(() => _approvedReady = true);
      }
    });
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        TabBar(
          controller: _tabs,
          labelColor: AppColors.blue,
          unselectedLabelColor: AppColors.muted,
          indicatorColor: AppColors.blue,
          labelStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
          tabs: const [
            Tab(text: 'Review'),
            Tab(text: 'Approved'),
          ],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabs,
            children: [
              const _ReviewPane(),
              _approvedReady ? const _ApprovedPane() : const SizedBox.shrink(),
            ],
          ),
        ),
      ],
    );
  }
}

class _ReviewPane extends StatefulWidget {
  const _ReviewPane();

  @override
  State<_ReviewPane> createState() => _ReviewPaneState();
}

class _ReviewPaneState extends State<_ReviewPane> {
  ReviewOrdersData? _data;
  String? _error;
  bool _loading = true;
  final Set<int> _busy = {};

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
      final next = await RetailerService.getReviewOrders();
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

  Future<void> _act(String action, int orderId, {String? reason}) async {
    if (_busy.contains(orderId)) return;
    setState(() => _busy.add(orderId));
    try {
      final msg = await RetailerService.reviewAction(
        action: action,
        orderId: orderId,
        rejectReason: reason,
      );
      if (!mounted) return;
      showStatus(context, msg);
      await _load();
    } on ApiException catch (e) {
      if (mounted) showStatus(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(orderId));
    }
  }

  Future<void> _reject(int orderId) async {
    final ctrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Reject order'),
        content: TextField(
          controller: ctrl,
          maxLines: 3,
          decoration: inputDecoration(hint: 'Reason'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Reject')),
        ],
      ),
    );
    final reason = ctrl.text.trim();
    ctrl.dispose();
    if (ok == true) await _act('reject', orderId, reason: reason.isEmpty ? 'No reason provided' : reason);
  }

  Future<void> _confirmDelete(int orderId) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete order'),
        content: const Text('This will restore stock and remove the draft.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    );
    if (ok == true) await _act('delete', orderId);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _data == null) {
      return _ErrorState(message: _error!, onRetry: _load);
    }
    final drafts = _data?.draftOrders ?? const <DraftOrder>[];
    return RefreshIndicator(
      onRefresh: _load,
      child: drafts.isEmpty
          ? ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const [
                SizedBox(height: 120),
                Icon(Icons.assignment_outlined, size: 40, color: AppColors.muted),
                SizedBox(height: 12),
                Center(child: Text('No draft orders to review', style: TextStyle(color: AppColors.muted))),
              ],
            )
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              itemCount: drafts.length,
              separatorBuilder: (context, i) => const SizedBox(height: 12),
              itemBuilder: (context, i) {
                final o = drafts[i];
                return _DraftCard(
                  order: o,
                  busy: _busy.contains(o.orderId),
                  onApprove: o.canApprove ? () => _act('approve', o.orderId) : null,
                  onReject: () => _reject(o.orderId),
                  onDelete: () => _confirmDelete(o.orderId),
                );
              },
            ),
    );
  }
}

class _DraftCard extends StatelessWidget {
  final DraftOrder order;
  final bool busy;
  final VoidCallback? onApprove;
  final VoidCallback onReject;
  final VoidCallback onDelete;
  const _DraftCard({
    required this.order,
    required this.busy,
    required this.onApprove,
    required this.onReject,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    final o = order;
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: ExpansionTile(
        tilePadding: const EdgeInsets.symmetric(horizontal: 16),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        title: Text(
          o.orderNumber.isNotEmpty ? o.orderNumber : '#ORD-${o.orderId}',
          style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.text),
        ),
        subtitle: Text(
          '${o.salesmanName} · ${o.formattedDate ?? formatDate(o.createdAt)} · ${o.totalItems} items',
          style: const TextStyle(fontSize: 12, color: AppColors.muted),
        ),
        trailing: Text(
          formatInr(o.summary.netPayable > 0 ? o.summary.netPayable : o.total),
          style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.text),
        ),
        children: [
          if (o.hasOutOfStock)
            const Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Text('Some items are out of stock', style: TextStyle(color: AppColors.dangerText, fontSize: 12)),
            ),
          ...o.items.map(
            (it) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      '${it.productName}${it.isFree ? ' (Free)' : ''}',
                      style: const TextStyle(fontSize: 13, color: AppColors.text),
                    ),
                  ),
                  Text('x${it.productQuantity}', style: const TextStyle(fontSize: 13, color: AppColors.muted)),
                  const SizedBox(width: 8),
                  Text(formatInr(it.productTotalprice), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                ],
              ),
            ),
          ),
          if (o.summary.totalSavings > 0)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                'Savings ${formatInr(o.summary.totalSavings)}',
                style: const TextStyle(fontSize: 12, color: AppColors.successText, fontWeight: FontWeight.w600),
              ),
            ),
          if (busy)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: Center(child: CircularProgressIndicator()),
            )
          else
            Row(
              children: [
                Expanded(
                  child: FilledButton(
                    onPressed: onApprove,
                    style: FilledButton.styleFrom(backgroundColor: AppColors.successText),
                    child: const Text('Approve'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton(
                    onPressed: onReject,
                    style: OutlinedButton.styleFrom(foregroundColor: AppColors.dangerText),
                    child: const Text('Reject'),
                  ),
                ),
                IconButton(
                  onPressed: onDelete,
                  tooltip: 'Delete',
                  icon: const Icon(Icons.delete_outline, color: AppColors.dangerText),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

class _ApprovedPane extends StatefulWidget {
  const _ApprovedPane();

  @override
  State<_ApprovedPane> createState() => _ApprovedPaneState();
}

class _ApprovedPaneState extends State<_ApprovedPane> {
  final _searchCtrl = TextEditingController();
  OrdersPage? _data;
  String? _error;
  bool _loading = true;
  bool _loadingMore = false;
  int _page = 1;
  DateTime? _start;
  DateTime? _end;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  String _ymd(DateTime? d) {
    if (d == null) return '';
    final m = d.month.toString().padLeft(2, '0');
    final day = d.day.toString().padLeft(2, '0');
    return '${d.year}-$m-$day';
  }

  Future<void> _pickRange() async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 2),
      lastDate: now,
      initialDateRange: _start != null && _end != null ? DateTimeRange(start: _start!, end: _end!) : null,
    );
    if (range == null) return;
    setState(() {
      _start = range.start;
      _end = range.end;
    });
    _load(reset: true);
  }

  Future<void> _load({bool reset = false, int? page}) async {
    if (reset) {
      _page = 1;
      setState(() {
        _loading = true;
        _error = null;
      });
    } else {
      _page = page ?? _page;
      setState(() => _loadingMore = true);
    }
    try {
      final next = await RetailerService.getOrders(
        search: _searchCtrl.text.trim(),
        startDate: _ymd(_start),
        endDate: _ymd(_end),
        page: _page,
        limit: 15,
      );
      if (!mounted) return;
      setState(() {
        if (reset || _data == null) {
          _data = next;
        } else {
          _data = OrdersPage(
            summary: next.summary,
            orders: [..._data!.orders, ...next.orders],
            pagination: next.pagination,
          );
        }
        _loading = false;
        _loadingMore = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _data == null) {
      return _ErrorState(message: _error!, onRetry: () => _load(reset: true));
    }
    final d = _data!;
    return RefreshIndicator(
      onRefresh: () => _load(reset: true),
      child: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.pixels > n.metrics.maxScrollExtent - 200 &&
              !_loadingMore &&
              d.pagination.hasNextPage) {
            _load(page: _page + 1);
          }
          return false;
        },
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
          children: [
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _searchCtrl,
                    textInputAction: TextInputAction.search,
                    onSubmitted: (_) => _load(reset: true),
                    decoration: inputDecoration(
                      hint: 'Invoice or customer',
                      suffix: IconButton(
                        icon: const Icon(Icons.search, color: AppColors.muted),
                        onPressed: () => _load(reset: true),
                      ),
                    ),
                  ),
                ),
                IconButton(
                  onPressed: _pickRange,
                  tooltip: 'Date range',
                  icon: Icon(
                    Icons.date_range,
                    color: (_start != null) ? AppColors.blue : AppColors.muted,
                  ),
                ),
                if (_start != null)
                  IconButton(
                    onPressed: () {
                      setState(() {
                        _start = null;
                        _end = null;
                      });
                      _load(reset: true);
                    },
                    icon: const Icon(Icons.clear, color: AppColors.muted),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(child: _metric('Orders', '${d.summary.totalOrders}')),
                const SizedBox(width: 12),
                Expanded(child: _metric('Value', formatInr(d.summary.totalAmount, decimals: false))),
              ],
            ),
            const SizedBox(height: 16),
            if (d.orders.isEmpty)
              const Padding(
                padding: EdgeInsets.only(top: 48),
                child: Center(child: Text('No approved orders', style: TextStyle(color: AppColors.muted))),
              )
            else
              ...d.orders.map(_orderTile),
            if (_loadingMore)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 16),
                child: Center(child: CircularProgressIndicator()),
              ),
          ],
        ),
      ),
    );
  }

  Widget _metric(String label, String value) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          const SizedBox(height: 4),
          Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: AppColors.text)),
        ],
      ),
    );
  }

  Widget _orderTile(ApprovedOrder o) {
    final paid = o.isPaid;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  o.orderNumber.isNotEmpty ? o.orderNumber : 'ORD-${o.id}',
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: AppColors.text),
                ),
                const SizedBox(height: 3),
                Text(
                  '${o.shippingName} · ${o.formattedDate ?? formatDate(o.createdAt)}',
                  style: const TextStyle(fontSize: 12, color: AppColors.muted),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(formatInr(o.total), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
              const SizedBox(height: 3),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(
                  color: paid ? AppColors.successBg : AppColors.dangerBg,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  o.paymentBadge,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: paid ? AppColors.successText : AppColors.dangerText,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  const _ErrorState({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off, size: 40, color: AppColors.muted),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.dangerText)),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      ),
    );
  }
}
