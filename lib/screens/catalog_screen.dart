import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../widgets/app_widgets.dart';
import 'cart_screen.dart';
import 'tabs/catalog_tab.dart';

class CatalogScreen extends StatefulWidget {
  final int? highlightProductId;
  final String? highlightProductName;
  const CatalogScreen({
    super.key,
    this.highlightProductId,
    this.highlightProductName,
  });

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen> {
  final _catalogKey = GlobalKey<CatalogTabState>();
  final _searchCtrl = TextEditingController();
  final _searchFocus = FocusNode();
  bool _searching = false;
  int _cartCount = 0;

  @override
  void dispose() {
    _searchCtrl.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _syncCartCount() {
    final count = _catalogKey.currentState?.cartCount ?? 0;
    if (count != _cartCount) {
      setState(() => _cartCount = count);
    }
  }

  void _openSearch() {
    setState(() => _searching = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _searchFocus.requestFocus();
    });
  }

  void _closeSearch() {
    _searchCtrl.clear();
    _catalogKey.currentState?.clearSearch();
    setState(() => _searching = false);
  }

  void _submitSearch(String value) {
    _catalogKey.currentState?.applySearch(value);
  }

  Future<void> _openCart() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => const CartScreen()),
    );
    if (!mounted) return;
    _catalogKey.currentState?.applySearch(_searchCtrl.text.trim());
    _syncCartCount();
  }

  @override
  Widget build(BuildContext context) {
    WidgetsBinding.instance.addPostFrameCallback((_) => _syncCartCount());
    return Scaffold(
      appBar: AppBar(
        backgroundColor: AppColors.card,
        surfaceTintColor: Colors.transparent,
        centerTitle: false,
        titleSpacing: 0,
        leadingWidth: 48,
        leading: IconButton(
          tooltip: 'Back',
          icon: const HugeIcon(
            icon: HugeIcons.strokeRoundedChevronLeft,
            color: AppColors.text,
            strokeWidth: 1.5,
          ),
          onPressed: () {
            if (_searching) {
              _closeSearch();
              return;
            }
            Navigator.of(context).maybePop();
          },
        ),
        title: _searching
            ? TextField(
                controller: _searchCtrl,
                focusNode: _searchFocus,
                textInputAction: TextInputAction.search,
                onSubmitted: _submitSearch,
                onChanged: _submitSearch,
                decoration: const InputDecoration(
                  hintText: 'Search products',
                  hintStyle: TextStyle(fontSize: 16, color: AppColors.muted),
                  border: InputBorder.none,
                  isDense: true,
                ),
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w600,
                  color: AppColors.text,
                ),
              )
            : const Text(
                'Catalog',
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                  color: AppColors.text,
                ),
              ),
        actions: [
          if (_searching)
            IconButton(
              tooltip: 'Close search',
              onPressed: _closeSearch,
              icon: const Icon(Icons.close, color: AppColors.text),
            )
          else
            IconButton(
              tooltip: 'Search',
              onPressed: _openSearch,
              icon: const Icon(Icons.search, color: AppColors.text),
            ),
          IconButton(
            tooltip: 'Cart',
            onPressed: _openCart,
            icon: Badge(
              isLabelVisible: _cartCount > 0,
              label: Text('$_cartCount'),
              child: const Icon(Icons.shopping_cart_outlined, color: AppColors.text),
            ),
          ),
          const SizedBox(width: 4),
        ],
      ),
      body: CatalogTab(
        key: _catalogKey,
        highlightProductId: widget.highlightProductId,
        highlightProductName: widget.highlightProductName,
      ),
    );
  }
}
