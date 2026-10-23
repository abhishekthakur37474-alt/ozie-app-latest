import 'package:flutter/material.dart';

import '../../core/models.dart';
import '../../core/router.dart';
import '../../widgets/app_widgets.dart';
import '../notifications_screen.dart';
import '../profile_screen.dart';

class _MenuItem {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  const _MenuItem(this.icon, this.label, [this.onTap]);
}

class SettingsTab extends StatelessWidget {
  final Session session;
  final VoidCallback? onViewCatalog;
  final VoidCallback? onViewOrders;
  const SettingsTab({
    super.key,
    required this.session,
    this.onViewCatalog,
    this.onViewOrders,
  });

  Future<void> _confirmLogout(BuildContext context) async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Logout'),
        content: const Text('Do you want to logout?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Logout'),
          ),
        ],
      ),
    );
    if (yes == true && context.mounted) {
      await logoutAndGoToLogin(context);
    }
  }

  void _soon(BuildContext context, String label) {
    showStatus(context, '$label will be available in the next update.');
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first[0].toUpperCase();
    return (parts.first[0] + parts.last[0]).toUpperCase();
  }

  Widget _profileHeader() {
    final user = session.user;
    return Column(
      children: [
        const SizedBox(height: 8),
        CircleAvatar(
          radius: 44,
          backgroundColor: const Color(0xFFDCE9FA),
          child: Text(
            _initials(user.displayName),
            style: const TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w600,
              color: AppColors.blue,
            ),
          ),
        ),
        const SizedBox(height: 12),
        Text(
          user.displayName,
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.w600,
            color: AppColors.text,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          user.roleLabel,
          style: const TextStyle(fontSize: 13, color: AppColors.muted),
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _menuTile(_MenuItem m, {required bool last}) {
    return Column(
      children: [
        ListTile(
          contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
          minLeadingWidth: 32,
          leading: Icon(m.icon, color: AppColors.blue, size: 28),
          title: Text(
            m.label,
            style: const TextStyle(
              fontSize: 17,
              fontWeight: FontWeight.w500,
              color: AppColors.text,
            ),
          ),
          trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.muted, size: 28),
          onTap: m.onTap,
        ),
        if (!last) const Divider(height: 1, indent: 72, color: AppColors.border),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    VoidCallback soon(String l) => () => _soon(context, l);

    final items = <_MenuItem>[
      _MenuItem(Icons.person_outline_rounded, 'My Account', () {
        Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => const ProfileScreen()),
        );
      }),
      _MenuItem(Icons.grid_view_rounded, 'My Catalog', onViewCatalog ?? soon('My Catalog')),
      _MenuItem(Icons.receipt_long_outlined, 'View Orders', onViewOrders ?? soon('View Orders')),
      _MenuItem(Icons.account_balance_wallet_outlined, 'Ozie Finance', soon('Ozie Finance')),
      _MenuItem(Icons.inventory_2_outlined, 'Packing', soon('Packing')),
      _MenuItem(Icons.local_shipping_outlined, 'Dispatch', soon('Dispatch')),
      _MenuItem(Icons.move_to_inbox_outlined, 'Received Order', soon('Received Order')),
      _MenuItem(Icons.assignment_return_outlined, 'My Returns', soon('My Returns')),
      _MenuItem(Icons.local_offer_outlined, 'Schemes', soon('Schemes')),
      _MenuItem(Icons.bar_chart_rounded, 'Report', soon('Report')),
      _MenuItem(Icons.notifications_none_rounded, 'Notifications', () {
        Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => const NotificationsScreen()),
        );
      }),
      _MenuItem(Icons.support_agent_outlined, 'My Tickets', soon('My Tickets')),
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _profileHeader(),
        Container(
          decoration: BoxDecoration(
            color: AppColors.card,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.border),
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              for (var i = 0; i < items.length; i++)
                _menuTile(items[i], last: i == items.length - 1),
            ],
          ),
        ),
        const SizedBox(height: 20),
        OutlinedButton.icon(
          onPressed: () => _confirmLogout(context),
          icon: const Icon(Icons.logout, size: 24, color: Color(0xFFDC3545)),
          label: const Text(
            'Logout',
            style: TextStyle(fontSize: 17, color: Color(0xFFDC3545), fontWeight: FontWeight.w500),
          ),
          style: OutlinedButton.styleFrom(
            minimumSize: const Size.fromHeight(54),
            side: const BorderSide(color: Color(0xFFDC3545)),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
        ),
        const SizedBox(height: 16),
      ],
    );
  }
}