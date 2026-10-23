import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/models.dart';
import '../../core/retailer_service.dart';
import '../../widgets/app_widgets.dart';

class CatalogTab extends StatefulWidget {
  const CatalogTab({super.key});

  @override
  State<CatalogTab> createState() => _CatalogTabState();
}

class _CatalogTabState extends State<CatalogTab>
    with AutomaticKeepAliveClientMixin {

  final _searchCtrl = TextEditingController();
  final _scroll = ScrollController();

  ProductsCatalog? _data;
  String? _error;
  bool _loading = true;
  bool _loadingMore = false;
  int _page = 1;
  int _categoryId = 0;
  String _brand = '';
  bool _wishlistOnly = false;
  final Set<int> _busy = {};

  static const int _pageSize = 12;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _data = RetailerService.cachedProducts(limit: _pageSize);
    _loading = _data == null;
    _load(reset: true);
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_loadingMore || _loading) return;
    if (!_scroll.hasClients) return;
    if (_scroll.position.pixels < _scroll.position.maxScrollExtent - 400) return;
    final p = _data?.pagination;
    if (p == null || !p.hasNextPage) return;
    _load(page: _page + 1);
  }

  Future<void> _load({bool reset = false, int? page, bool force = false}) async {
    if (reset) {
      _page = 1;
      if (_data == null) {
        setState(() {
          _loading = true;
          _error = null;
        });
      }
    } else {
      if (_loadingMore) return;
      _page = page ?? _page;
      setState(() => _loadingMore = true);
    }
    try {
      final next = await RetailerService.getProducts(
        search: _searchCtrl.text.trim(),
        categoryId: _categoryId,
        brand: _brand,
        wishlistOnly: _wishlistOnly,
        page: _page,
        limit: _pageSize,
        force: force,
      );
      if (!mounted) return;
      setState(() {
        if (reset || _data == null) {
          _data = next;
        } else {
          final seen = _data!.products.map((e) => e.id).toSet();
          _data = ProductsCatalog(
            scopeNotice: next.scopeNotice,
            cartCount: next.cartCount,
            wishlistCount: next.wishlistCount,
            categories: next.categories,
            brands: next.brands,
            products: [
              ..._data!.products,
              ...next.products.where((p) => seen.add(p.id)),
            ],
            pagination: next.pagination,
          );
        }
        _loading = false;
        _loadingMore = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (_data == null) _error = e.message;
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  Future<void> _toggleWish(ProductItem p) async {
    if (_busy.contains(p.id)) return;
    setState(() => _busy.add(p.id));
    try {
      final r = await RetailerService.toggleWishlist(p.id);
      if (!mounted) return;
      setState(() {
        _data = _replaceProduct(
          p.copyWith(isInWishlist: r.isInWishlist),
          wishlistCount: r.wishlistCount,
        );
      });
    } on ApiException catch (e) {
      if (mounted) showStatus(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(p.id));
    }
  }

  Future<void> _addCart(ProductItem p) async {
    if (_busy.contains(p.id) || !p.canPurchase || p.isOutOfStock) return;
    setState(() => _busy.add(p.id));
    try {
      final r = await RetailerService.addToCart(p.id);
      if (!mounted) return;
      setState(() {
        _data = _replaceProduct(
          p.copyWith(
            cartQuantity: r.newCartQty,
            availableStock: r.availableStock,
            isOutOfStock: r.availableStock <= 0,
          ),
          cartCount: r.cartCount,
        );
      });
      showStatus(context, 'Added to cart');
    } on ApiException catch (e) {
      if (mounted) showStatus(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(p.id));
    }
  }

  ProductsCatalog? _replaceProduct(
    ProductItem updated, {
    int? cartCount,
    int? wishlistCount,
  }) {
    final d = _data;
    if (d == null) return d;
    return ProductsCatalog(
      scopeNotice: d.scopeNotice,
      cartCount: cartCount ?? d.cartCount,
      wishlistCount: wishlistCount ?? d.wishlistCount,
      categories: d.categories,
      brands: d.brands,
      products: d.products.map((e) => e.id == updated.id ? updated : e).toList(),
      pagination: d.pagination,
    );
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
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
              Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.dangerText)),
              const SizedBox(height: 16),
              FilledButton(onPressed: () => _load(reset: true), child: const Text('Retry')),
            ],
          ),
        ),
      );
    }

    final d = _data!;
    return RefreshIndicator(
      onRefresh: () => _load(reset: true, force: true),
      child: CustomScrollView(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        slivers: [
          SliverToBoxAdapter(child: _filters(d)),
          if (d.scopeNotice.isNotEmpty)
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                child: Text(d.scopeNotice, style: const TextStyle(fontSize: 13, color: AppColors.muted)),
              ),
            ),
          if (d.products.isEmpty)
            const SliverFillRemaining(
              hasScrollBody: false,
              child: Center(
                child: Text('No products found', style: TextStyle(color: AppColors.muted)),
              ),
            )
          else
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
              sliver: SliverGrid(
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: 0.62,
                ),
                delegate: SliverChildBuilderDelegate(
                  (context, i) => _ProductCard(
                    product: d.products[i],
                    busy: _busy.contains(d.products[i].id),
                    onWish: () => _toggleWish(d.products[i]),
                    onAdd: () => _addCart(d.products[i]),
                  ),
                  childCount: d.products.length,
                ),
              ),
            ),
          if (_loadingMore)
            const SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.only(bottom: 24),
                child: Center(child: CircularProgressIndicator()),
              ),
            ),
        ],
      ),
    );
  }

  Widget _filters(ProductsCatalog d) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _searchCtrl,
                  textInputAction: TextInputAction.search,
                  onSubmitted: (_) => _load(reset: true),
                  decoration: inputDecoration(
                    hint: 'Search products',
                    suffix: IconButton(
                      icon: const Icon(Icons.search, color: AppColors.muted),
                      onPressed: () => _load(reset: true),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              _countChip(Icons.favorite_border, d.wishlistCount, _wishlistOnly, () {
                setState(() => _wishlistOnly = !_wishlistOnly);
                _load(reset: true);
              }),
              const SizedBox(width: 6),
              _countChip(Icons.shopping_cart_outlined, d.cartCount, false, null),
            ],
          ),
          if (d.categories.isNotEmpty) ...[
            const SizedBox(height: 12),
            SizedBox(
              height: 36,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  _chip('All', _categoryId == 0, () {
                    setState(() => _categoryId = 0);
                    _load(reset: true);
                  }),
                  ...d.categories.map(
                    (c) => _chip(c.name, _categoryId == c.id, () {
                      setState(() => _categoryId = c.id);
                      _load(reset: true);
                    }),
                  ),
                ],
              ),
            ),
          ],
          if (d.brands.isNotEmpty) ...[
            const SizedBox(height: 8),
            SizedBox(
              height: 36,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  _chip('All brands', _brand.isEmpty, () {
                    setState(() => _brand = '');
                    _load(reset: true);
                  }),
                  ...d.brands.map(
                    (b) => _chip(b, _brand == b, () {
                      setState(() => _brand = b);
                      _load(reset: true);
                    }),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _countChip(IconData icon, int count, bool active, VoidCallback? onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        decoration: BoxDecoration(
          color: active ? const Color(0xFFDCE9FA) : AppColors.card,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: active ? AppColors.blue : AppColors.border),
        ),
        child: Row(
          children: [
            Icon(icon, size: 18, color: active ? AppColors.blue : AppColors.text),
            const SizedBox(width: 4),
            Text('$count', style: TextStyle(fontWeight: FontWeight.w600, color: active ? AppColors.blue : AppColors.text)),
          ],
        ),
      ),
    );
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
        selectedColor: const Color(0xFFDCE9FA),
        labelStyle: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: selected ? AppColors.blue : AppColors.text,
        ),
        side: BorderSide(color: selected ? AppColors.blue : AppColors.border),
        backgroundColor: AppColors.card,
        showCheckmark: false,
      ),
    );
  }
}

class _ProductCard extends StatelessWidget {
  final ProductItem product;
  final bool busy;
  final VoidCallback onWish;
  final VoidCallback onAdd;
  const _ProductCard({
    required this.product,
    required this.busy,
    required this.onWish,
    required this.onAdd,
  });

  @override
  Widget build(BuildContext context) {
    final p = product;
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Stack(
              fit: StackFit.expand,
              children: [
                ColoredBox(
                  color: AppColors.inputBg,
                  child: (p.photoUrl ?? '').isNotEmpty
                      ? Image.network(
                          p.photoUrl!,
                          fit: BoxFit.cover,
                          cacheWidth: 400,
                          filterQuality: FilterQuality.low,
                          errorBuilder: (context, error, stack) => const Icon(Icons.image_outlined, color: AppColors.muted),
                        )
                      : const Icon(Icons.image_outlined, color: AppColors.muted),
                ),
                Positioned(
                  top: 6,
                  right: 6,
                  child: IconButton(
                    visualDensity: VisualDensity.compact,
                    style: IconButton.styleFrom(backgroundColor: Colors.white),
                    onPressed: busy ? null : onWish,
                    icon: Icon(
                      p.isInWishlist ? Icons.favorite : Icons.favorite_border,
                      color: p.isInWishlist ? AppColors.dangerText : AppColors.muted,
                      size: 20,
                    ),
                  ),
                ),
                if (p.discountPct > 0)
                  Positioned(
                    top: 8,
                    left: 8,
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: AppColors.successText,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        '${p.discountPct}% OFF',
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  p.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.text),
                ),
                if (p.brand.isNotEmpty)
                  Text(p.brand, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11, color: AppColors.muted)),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Text(
                      formatInr(p.billingCase, decimals: false),
                      style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.text),
                    ),
                    if (p.mrpCase > p.billingCase) ...[
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          formatInr(p.mrpCase, decimals: false),
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 11,
                            color: AppColors.muted,
                            decoration: TextDecoration.lineThrough,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  p.isOutOfStock
                      ? 'Out of stock'
                      : p.isLowStock
                          ? 'Low stock · ${p.availableStock}'
                          : 'Stock ${p.availableStock}',
                  style: TextStyle(
                    fontSize: 11,
                    color: p.isOutOfStock ? AppColors.dangerText : AppColors.muted,
                  ),
                ),
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: (!p.canPurchase || p.isOutOfStock || busy) ? null : onAdd,
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.blue,
                      minimumSize: const Size(0, 34),
                      padding: EdgeInsets.zero,
                      textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                    ),
                    child: busy
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : Text(p.cartQuantity > 0 ? 'Add more (${p.cartQuantity})' : 'Add to cart'),
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
