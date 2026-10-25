import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../core/models.dart';
import '../core/push_service.dart';
import '../widgets/app_widgets.dart';
import 'bank_card_screen.dart';
import 'notifications_screen.dart';
import 'tabs/catalog_tab.dart';
import 'tabs/home_tab.dart';
import 'tabs/orders_tab.dart';
import 'tabs/settings_tab.dart';

/// App shell after login: top bar (logo + notifications) and bottom menu.
class MainShell extends StatefulWidget {
  final Session session;
  const MainShell({super.key, required this.session});

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  int _index = 0;
  bool _catalogReady = false;
  bool _ordersReady = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => PushService.openPendingIfAny(),
    );
  }

  static const List<String> _titles = ['Home', 'Catalog', 'View Orders', 'Settings'];

  /// Home shows logo + app name. Other tabs show the tab name.
  Widget _buildTitle() {
    if (_index == 0) {
      return const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          AppLogoImage(size: 32, radius: 8),
          SizedBox(width: 10),
          Text(
            'Ozie',
            style: TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.w700,
              color: AppColors.text,
            ),
          ),
        ],
      );
    }
    return Text(
      _titles[_index],
      style: const TextStyle(
        fontSize: 24,
        fontWeight: FontWeight.w700,
        color: AppColors.text,
      ),
    );
  }

  void _openNotifications() {
    Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => const NotificationsScreen()),
    );
  }

  void _openBankCard() {
    Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => const BankCardScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_index == 1) _catalogReady = true;
    if (_index == 2) _ordersReady = true;

    final pages = <Widget>[
      HomeTab(
        session: widget.session,
        onViewCatalog: () => setState(() {
          _catalogReady = true;
          _index = 1;
        }),
        onViewOrders: () => setState(() {
          _ordersReady = true;
          _index = 2;
        }),
      ),
      _catalogReady ? const CatalogTab() : const SizedBox.shrink(),
      _ordersReady ? const OrdersTab() : const SizedBox.shrink(),
      SettingsTab(
        session: widget.session,
        onViewCatalog: () => setState(() {
          _catalogReady = true;
          _index = 1;
        }),
        onViewOrders: () => setState(() {
          _ordersReady = true;
          _index = 2;
        }),
      ),
    ];

    return PopScope(
      canPop: _index == 0,
      onPopInvokedWithResult: (didPop, result) {
        if (!didPop) setState(() => _index = 0);
      },
      child: Scaffold(
        appBar: AppBar(
          backgroundColor: AppColors.bg,
          surfaceTintColor: Colors.transparent,
          scrolledUnderElevation: 0,
          elevation: 0,
          titleSpacing: 16,
          title: _buildTitle(),
          actions: [
            // Opens the bank credit card details/application screen.
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: OutlinedButton(
                onPressed: _openBankCard,
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.blue,
                  side: const BorderSide(color: AppColors.blue),
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  minimumSize: const Size(0, 36),
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                child: const Text(
                  'Apply For Card',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                ),
              ),
            ),
            const SizedBox(width: 4),
            IconButton(
              tooltip: 'Notifications',
              icon: const HugeIcon(
                icon: HugeIcons.strokeRoundedNotification01,
                color: AppColors.text,
              ),
              onPressed: _openNotifications,
            ),
            const SizedBox(width: 4),
          ],
        ),
        body: IndexedStack(index: _index, children: pages),
        bottomNavigationBar: Container(
          margin: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          decoration: BoxDecoration(
            color: AppColors.card,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: AppColors.border),
            boxShadow: const [
              BoxShadow(
                color: Color(0x14000000),
                blurRadius: 20,
                offset: Offset(0, 6),
              ),
            ],
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(20),
            child: NavigationBar(
              selectedIndex: _index,
              onDestinationSelected: (i) => setState(() {
                if (i == 1) _catalogReady = true;
                if (i == 2) _ordersReady = true;
                _index = i;
              }),
              backgroundColor: AppColors.card,
              surfaceTintColor: Colors.transparent,
              indicatorColor: Colors.transparent,
              elevation: 0,
              height: 64,
              labelBehavior:
                  NavigationDestinationLabelBehavior.alwaysShow,
              destinations: const [
                NavigationDestination(
                  icon: HugeIcon(icon: HugeIcons.strokeRoundedHome03),
                  selectedIcon: HugeIcon(
                    icon: HugeIcons.strokeRoundedHome03,
                    color: AppColors.blue,
                  ),
                  label: 'Home',
                ),
                NavigationDestination(
                  icon: HugeIcon(icon: HugeIcons.strokeRoundedStore03),
                  selectedIcon: HugeIcon(
                    icon: HugeIcons.strokeRoundedStore03,
                    color: AppColors.blue,
                  ),
                  label: 'Catalog',
                ),
                NavigationDestination(
                  icon: HugeIcon(icon: HugeIcons.strokeRoundedShoppingBag01),
                  selectedIcon: HugeIcon(
                    icon: HugeIcons.strokeRoundedShoppingBag01,
                    color: AppColors.blue,
                  ),
                  label: 'View Orders',
                ),
                NavigationDestination(
                  icon: HugeIcon(icon: HugeIcons.strokeRoundedSettings02),
                  selectedIcon: HugeIcon(
                    icon: HugeIcons.strokeRoundedSettings02,
                    color: AppColors.blue,
                  ),
                  label: 'Settings',
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}