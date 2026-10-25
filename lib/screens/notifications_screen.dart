import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../core/api_client.dart';
import '../core/models.dart';
import '../core/retailer_service.dart';
import '../widgets/app_widgets.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  NotificationsPage? _data;
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
      final next = await RetailerService.getNotifications();
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
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

    final items = _data?.notifications ?? const <AppNotification>[];
    return RefreshIndicator(
      onRefresh: _load,
      child: items.isEmpty
          ? ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: const [
                SizedBox(height: 140),
                Icon(Icons.notifications_none_rounded, size: 56, color: AppColors.muted),
                SizedBox(height: 12),
                Center(
                  child: Text(
                    'No notifications yet',
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
              itemCount: items.length,
              separatorBuilder: (context, i) => const SizedBox(height: 10),
              itemBuilder: (context, i) => _NotificationTile(item: items[i]),
            ),
    );
  }
}

class _NotificationTile extends StatelessWidget {
  final AppNotification item;
  const _NotificationTile({required this.item});

  @override
  Widget build(BuildContext context) {
    final title = item.title.trim().isEmpty ? 'Notification' : item.title.trim();
    final message = item.message.trim();
    final when = item.formattedDate ?? formatDate(item.createdAt);

    return Container(
      decoration: BoxDecoration(
        color: item.isRead ? AppColors.card : const Color(0xFFEFF6FF),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: item.isRead ? AppColors.border : const Color(0xFFBFDBFE),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: item.isRead ? AppColors.inputBg : const Color(0xFFDBEAFE),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(
                Icons.notifications_none_rounded,
                size: 22,
                color: item.isRead ? AppColors.muted : AppColors.blue,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontSize: 15,
                            fontWeight: item.isRead ? FontWeight.w600 : FontWeight.w700,
                            color: AppColors.text,
                          ),
                        ),
                      ),
                      if (!item.isRead)
                        Container(
                          width: 8,
                          height: 8,
                          margin: const EdgeInsets.only(left: 8),
                          decoration: const BoxDecoration(
                            color: AppColors.blue,
                            shape: BoxShape.circle,
                          ),
                        ),
                    ],
                  ),
                  if (message.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      message,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 13, color: AppColors.muted, height: 1.35),
                    ),
                  ],
                  const SizedBox(height: 6),
                  Text(
                    when,
                    style: const TextStyle(fontSize: 12, color: AppColors.muted),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
