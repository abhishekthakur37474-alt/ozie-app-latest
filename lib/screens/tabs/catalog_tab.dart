import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/models.dart';
import '../../core/retailer_service.dart';
import '../../widgets/app_widgets.dart';
import '../../widgets/product_qty_button.dart';

class CatalogTab extends StatefulWidget {
  final int? highlightProductId;
  final String? highlightProductName;
  const CatalogTab({
    super.key,
    this.highlightProductId,
    this.highlightProductName,
  });

  @override
  State<CatalogTab> createState() => CatalogTabState();
}

class CatalogTabState extends State<CatalogTab>
    with AutomaticKeepAliveClientMixin {

  final _searchCtrl = TextEditingController();
  final _scroll = ScrollController();
  final Map<int, GlobalKey> _productKeys = {};

  ProductsCatalog? _data;
  String? _error;
  bool _loading = true;
  bool _loadingMore = false;
  int _page = 1;
  int _categoryId = 0;
  String _brand = '';
  bool _wishlistOnly = false;
  final Set<int> _busy = {};
  final Map<int, int> _cartIds = {}; // productId -> cartId (for minus/remove)

  static const int _pageSize = 12;

  @override
  bool get wantKeepAlive => true;

  int get cartCount => _data?.cartCount ?? 0;

  void applySearch(String query) {
    _searchCtrl.text = query;
    _load(reset: true);
  }

  void clearSearch() {
    if (_searchCtrl.text.isEmpty) return;
    _searchCtrl.clear();
    _load(reset: true);
  }

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    final highlightName = (widget.highlightProductName ?? '').trim();
    if (highlightName.isNotEmpty &&
        highlightName.toLowerCase() != 'all products') {
      _searchCtrl.text = highlightName;
    }
    _data = RetailerService.cachedProducts(
      search: _searchCtrl.text.trim(),
      limit: _pageSize,
    );
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
      _cartIds.clear();
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
      _scrollToHighlight();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (_data == null) _error = e.message;
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  GlobalKey _keyFor(int id) =>
      _productKeys.putIfAbsent(id, GlobalKey.new);

  void _scrollToHighlight() {
    final id = widget.highlightProductId;
    final name = (widget.highlightProductName ?? '').trim().toLowerCase();
    final hasId = id != null && id > 0;
    final hasName = name.isNotEmpty && name != 'all products';
    if (!hasId && !hasName) return;
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      final products = _data?.products ?? const <ProductItem>[];
      var index = hasId ? products.indexWhere((p) => p.id == id) : -1;
      if (index < 0 && hasName) {
        index = products.indexWhere(
          (p) => p.name.toLowerCase().contains(name),
        );
      }
      if (index < 0) {
        final p = _data?.pagination;
        if (p != null && p.hasNextPage && !_loadingMore) {
          await _load(page: _page + 1);
        }
        return;
      }
      final ctx = _productKeys[products[index].id]?.currentContext;
      if (ctx != null) {
        await Scrollable.ensureVisible(
          ctx,
          duration: const Duration(milliseconds: 450),
          curve: Curves.easeInOut,
          alignment: 0.15,
        );
      }
    });
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
    } on ApiException catch (e) {
      if (mounted) showStatus(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(p.id));
    }
  }

  void _cacheCartIds(RetailerCart cart) {
    _cartIds
      ..clear()
      ..addEntries(cart.items.map((e) => MapEntry(e.productId, e.cartId)));
  }

  Future<void> _decCart(ProductItem p) async {
    if (_busy.contains(p.id) || p.cartQuantity <= 0) return;
    final nextQty = p.cartQuantity - 1;
    setState(() => _busy.add(p.id));
    try {
      final cartId = _cartIds[p.id] ?? 0;
      final cart = nextQty < 1
          ? await RetailerService.removeCartItem(cartId: cartId, productId: p.id)
          : await RetailerService.updateCartItem(
              cartId: cartId,
              productId: p.id,
              quantity: nextQty,
            );
      if (cart != null) _cacheCartIds(cart);
      if (nextQty < 1) _cartIds.remove(p.id);
      if (!mounted) return;
      setState(() {
        _data = _replaceProduct(
          p.copyWith(
            cartQuantity: nextQty < 1 ? 0 : nextQty,
            availableStock: p.availableStock + 1,
            isOutOfStock: false,
          ),
          cartCount: cart?.itemsCount ??
              ((_data?.cartCount ?? 1) - (nextQty < 1 ? 1 : 0)),
        );
      });
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
                  childAspectRatio: 0.74,
                ),
                delegate: SliverChildBuilderDelegate(
                  (context, i) => _ProductCard(
                    key: _keyFor(d.products[i].id),
                    product: d.products[i],
                    busy: _busy.contains(d.products[i].id),
                    highlighted: widget.highlightProductId == d.products[i].id,
                    onWish: () => _toggleWish(d.products[i]),
                    onAdd: () => _addCart(d.products[i]),
                    onMinus: () => _decCart(d.products[i]),
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

  int get _activeFilterCount =>
      (_categoryId != 0 ? 1 : 0) + (_brand.isNotEmpty ? 1 : 0);

  void _applyFilters(int categoryId, String brand) {
    setState(() {
      _categoryId = categoryId;
      _brand = brand;
    });
    _load(reset: true);
  }

  Widget _filters(ProductsCatalog d) {
    final allSelected = _categoryId == 0 && _brand.isEmpty;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Row(
        children: [
          Expanded(
            child: SizedBox(
              height: 36,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  _chip('All', allSelected, () => _applyFilters(0, '')),
                  ...d.brands.map(
                    (b) => _chip(
                      b,
                      _brand == b,
                      () => _applyFilters(_categoryId, _brand == b ? '' : b),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          Badge(
            isLabelVisible: _activeFilterCount > 0,
            label: Text('$_activeFilterCount'),
            child: Material(
              color: AppColors.card,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
                side: BorderSide(
                  color: _activeFilterCount > 0 ? AppColors.blue : AppColors.border,
                ),
              ),
              child: InkWell(
                borderRadius: BorderRadius.circular(10),
                onTap: () => _openFilterSheet(d),
                child: SizedBox(
                  width: 36,
                  height: 36,
                  child: Icon(
                    Icons.tune_rounded,
                    size: 20,
                    color: _activeFilterCount > 0 ? AppColors.blue : AppColors.text,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _openFilterSheet(ProductsCatalog d) async {
    var cat = _categoryId;
    var brand = _brand;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.of(ctx).size.height * 0.8,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 8, 0),
                  child: Row(
                    children: [
                      const Expanded(
                        child: Text(
                          'Filters',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w700,
                            color: AppColors.text,
                          ),
                        ),
                      ),
                      IconButton(
                        onPressed: () => Navigator.of(ctx).pop(),
                        icon: const Icon(Icons.close, color: AppColors.text),
                      ),
                    ],
                  ),
                ),
                Flexible(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (d.categories.isNotEmpty) ...[
                          _sheetTitle('Category'),
                          Wrap(
                            children: [
                              _chip('All', cat == 0, () => setSheet(() => cat = 0)),
                              ...d.categories.map(
                                (c) => _chip(
                                  c.name,
                                  cat == c.id,
                                  () => setSheet(() => cat = c.id),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                        ],
                        if (d.brands.isNotEmpty) ...[
                          _sheetTitle('Brand'),
                          Wrap(
                            children: [
                              _chip('All brands', brand.isEmpty, () => setSheet(() => brand = '')),
                              ...d.brands.map(
                                (b) => _chip(
                                  b,
                                  brand == b,
                                  () => setSheet(() => brand = b),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                  child: Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => setSheet(() {
                            cat = 0;
                            brand = '';
                          }),
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size(0, 44),
                            foregroundColor: AppColors.text,
                            side: const BorderSide(color: AppColors.border),
                          ),
                          child: const Text('Reset'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton(
                          onPressed: () {
                            Navigator.of(ctx).pop();
                            if (cat != _categoryId || brand != _brand) {
                              _applyFilters(cat, brand);
                            }
                          },
                          style: FilledButton.styleFrom(
                            backgroundColor: AppColors.blue,
                            minimumSize: const Size(0, 44),
                          ),
                          child: const Text('Apply'),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _sheetTitle(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 8, top: 8),
        child: Text(
          t,
          style: const TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w700,
            color: AppColors.text,
          ),
        ),
      );

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
  final bool highlighted;
  final VoidCallback onWish;
  final VoidCallback onAdd;
  final VoidCallback onMinus;
  const _ProductCard({
    super.key,
    required this.product,
    required this.busy,
    this.highlighted = false,
    required this.onWish,
    required this.onAdd,
    required this.onMinus,
  });

  @override
  Widget build(BuildContext context) {
    final p = product;
    return Container(
      decoration: BoxDecoration(
        color: AppColors.card,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: highlighted ? AppColors.blue : AppColors.border,
          width: highlighted ? 2 : 1,
        ),
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
                if (p.cartQuantity > 0 || (p.canPurchase && !p.isOutOfStock))
                  Positioned(
                    right: 8,
                    bottom: 8,
                    child: ProductQtyButton(
                      quantity: p.cartQuantity,
                      busy: busy,
                      canIncrease: p.canPurchase && !p.isOutOfStock,
                      color: AppColors.blue,
                      onAdd: onAdd,
                      onPlus: onAdd,
                      onMinus: onMinus,
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
              ],
            ),
          ),
        ],
      ),
    );
  }
}