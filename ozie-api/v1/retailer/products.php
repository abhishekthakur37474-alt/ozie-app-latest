<?php
/**
 * Ozie API — Retailer Products & Catalog
 * Endpoint:
 *   GET  /v1/retailer/products (List products, search, category/brand filter, pagination)
 *   POST /v1/retailer/products (Toggle wishlist or Add to cart)
 *
 * Implements 100% of admin-manage-categories.php:
 *  - Manufacturer visibility scoping (ozie-scope.php)
 *  - Master product stock synchronization
 *  - Retailer product alias name mapping (product_retailer_aliases)
 *  - Retailer Category / Temporary Discount pricing
 *  - Active schemes engine (Retailer -> Mapped Manufacturer -> Brand)
 *  - Wishlist checking & Cart stock calculations
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 1. Authentication & Role Check ────────────────────────
$user = require_auth();
$loggedUserId = (int)($user['id'] ?? $user['user_id'] ?? 0);
$userType     = strtolower(trim($user['user_type'] ?? ''));

if ($loggedUserId <= 0) {
    fail('Authentication required.', 401);
}

if ($userType !== 'retailer' && $userType !== 'salesman') {
    fail('Access denied. Retailer or Salesman account required.', 403);
}

// ── 2. Database & Scope Engine Initialization ─────────────
$conn = null;
$dbInstance = db();
if ($dbInstance instanceof mysqli) {
    $conn = $dbInstance;
} elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
    $conn = $GLOBALS['conn'];
} else {
    $rootDb = dirname(__DIR__, 2) . '/db.php';
    if (file_exists($rootDb)) {
        require_once $rootDb;
        if (!isset($conn) && isset($con)) {
            $conn = $con;
        }
    }
}

if (!isset($conn) || !$conn instanceof mysqli) {
    fail('Database connection unavailable.', 500);
}

// Include and run Ozie Scope
$scopeFile = dirname(__DIR__, 2) . '/ozie-scope.php';
if (file_exists($scopeFile)) {
    include_once $scopeFile;
} elseif (file_exists(__DIR__ . '/ozie-scope.php')) {
    include_once __DIR__ . '/ozie-scope.php';
}

if (function_exists('ozie_ensure_schema')) {
    ozie_ensure_schema($conn);
}

// Ensure required tables exist
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_wishlist (
    id int(11) NOT NULL AUTO_INCREMENT,
    user_id int(11) NOT NULL,
    product_id int(11) NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_prod (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS product_retailer_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    retailer_id INT NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_prod_retailer (product_id, retailer_id),
    INDEX (product_id),
    INDEX (retailer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Manufacturer Visibility Scoping
$vis_ids   = function_exists('ozie_visible_mfg_ids') ? ozie_visible_mfg_ids($conn, $loggedUserId, $userType) : null;
$mfg_and_p = function_exists('ozie_mfg_filter_sql') ? ozie_mfg_filter_sql($vis_ids, 'p.manufacturer_id') : '';
$mfg_and   = function_exists('ozie_mfg_filter_sql') ? ozie_mfg_filter_sql($vis_ids, 'manufacturer_id') : '';

$scopeNotice = '';
if (is_array($vis_ids) && count($vis_ids) === 0) {
    if ($userType === 'retailer') {
        $scopeNotice = 'No manufacturer is mapped to your outlet yet. Please contact your distributor or sales representative to get your outlet mapped.';
    } elseif ($userType === 'salesman') {
        $scopeNotice = 'Select a retailer first. Products shown here are the manufacturers mapped to that retailer.';
    } else {
        $scopeNotice = 'Your login is not linked to a manufacturer, so no products are shown.';
    }
}

// ── 3. Helper Functions ───────────────────────────────────
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com';
$siteBaseUrl = $protocol . $host . '/';

$resolveMediaUrl = function (?string $path) use ($siteBaseUrl): string {
    $p = trim($path ?? '');
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    return $siteBaseUrl . ltrim($p, '/');
};

function getCartQuantity($conn, $user_id, $product_id) {
    $uid = (int)$user_id;
    $pid = (int)$product_id;
    $query = mysqli_query($conn, "SELECT SUM(product_quantity) as total FROM retailer_cart WHERE product_id = $pid AND user_id = $uid AND (is_return_claim = 0 OR is_return_claim IS NULL)");
    if ($query && $row = mysqli_fetch_assoc($query)) {
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

function getActiveSchemesForRetailer($conn, $product_id, $retailer_category, $today, $product_info = null) {
    global $vis_ids;

    if (is_array($vis_ids) && count($vis_ids) === 0) {
        return [];
    }

    $product_id = (int)$product_id;
    $retailer_category = mysqli_real_escape_string($conn, strtoupper(trim($retailer_category)));
    $today = !empty($today) ? mysqli_real_escape_string($conn, $today) : date('Y-m-d');

    $p_mfg_id = 0;
    $p_brand  = '';
    if (is_array($product_info) && isset($product_info['manufacturer_id'])) {
        $p_mfg_id = (int)$product_info['manufacturer_id'];
        $p_brand  = trim($product_info['brand'] ?? '');
    } else {
        $pq = mysqli_query($conn, "SELECT manufacturer_id, brand FROM products WHERE id = $product_id LIMIT 1");
        if ($pq && $p_row = mysqli_fetch_assoc($pq)) {
            $p_mfg_id = (int)$p_row['manufacturer_id'];
            $p_brand  = trim($p_row['brand'] ?? '');
        }
    }

    if (is_array($vis_ids) && $p_mfg_id > 0 && !in_array($p_mfg_id, $vis_ids)) {
        return [];
    }

    $mfg_filter = "";
    if ($p_mfg_id > 0) {
        $mfg_filter = " AND (s.manufacturer_id = $p_mfg_id OR s.manufacturer_id = 0 OR s.manufacturer_id IS NULL) ";
    }
    if (is_array($vis_ids) && count($vis_ids) > 0) {
        $vis_list = implode(',', array_map('intval', $vis_ids));
        $mfg_filter .= " AND (s.manufacturer_id IN ($vis_list) OR s.manufacturer_id = 0 OR s.manufacturer_id IS NULL) ";
    }

    $esc_p_brand = mysqli_real_escape_string($conn, $p_brand);

    static $schemes_col_cache = null;
    if ($schemes_col_cache === null) {
        $schemes_col_cache = [];
        $col_res = mysqli_query($conn, "SHOW COLUMNS FROM schemes");
        if ($col_res) {
            while ($c = mysqli_fetch_assoc($col_res)) {
                $schemes_col_cache[] = $c['Field'];
            }
        }
    }
    $has_product_ids = in_array('product_ids', $schemes_col_cache);
    $has_brand_id    = in_array('brand_id', $schemes_col_cache);
    $has_brand_col   = in_array('brand', $schemes_col_cache);

    $prod_clause = "(s.product_id = $product_id OR s.product_id = 0)";
    if ($has_product_ids) {
        $prod_clause = "(s.product_id = $product_id OR FIND_IN_SET('$product_id', REPLACE(COALESCE(s.product_ids, ''), ' ', '')) OR ((s.product_id = 0 OR s.product_id IS NULL) AND (s.product_ids IS NULL OR s.product_ids = '' OR s.product_ids = 'ALL')))";
    }

    $brand_clause = "";
    if ($p_mfg_id > 0) {
        $brand_conditions = [];
        if ($has_brand_col) {
            if (!empty($esc_p_brand)) {
                $brand_conditions[] = "(s.brand = '$esc_p_brand' AND EXISTS (SELECT 1 FROM brands b WHERE b.brand_name = s.brand AND b.manufacturer_id = $p_mfg_id))";
            }
            $brand_conditions[] = "(s.brand IS NULL OR s.brand = '')";
        }
        if ($has_brand_id) {
            $brand_match_sql = !empty($esc_p_brand) ? "AND (b.brand_name = '$esc_p_brand' OR '$esc_p_brand' = '')" : "";
            $brand_conditions[] = "(s.brand_id > 0 AND EXISTS (SELECT 1 FROM brands b WHERE b.id = s.brand_id AND b.manufacturer_id = $p_mfg_id $brand_match_sql))";
            $brand_conditions[] = "(s.brand_id IS NULL OR s.brand_id = 0)";
        }
        if (!empty($brand_conditions)) {
            $brand_clause = " AND (" . implode(" OR ", $brand_conditions) . ") ";
        }
    }

    $sql = "SELECT s.* 
            FROM schemes s 
            WHERE s.status = 'active' 
              AND s.start_date IS NOT NULL AND s.start_date != '0000-00-00' 
              AND s.end_date IS NOT NULL AND s.end_date != '0000-00-00' 
              AND '$today' BETWEEN s.start_date AND s.end_date 
              AND (s.category = 'ALL' OR FIND_IN_SET('$retailer_category', REPLACE(s.category,' ',''))) 
              AND $prod_clause 
              $mfg_filter 
              $brand_clause 
            ORDER BY s.min_qty ASC";

    $result = mysqli_query($conn, $sql);
    $schemes = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) { 
            $schemes[] = $row; 
        }
    }
    return $schemes;
}

// ── 4. Retailer Category & Discount Resolution ────────────
$today = date('Y-m-d');
$retailerIdToFilter = ($userType === 'retailer') ? $loggedUserId : (int)($_SESSION['current_retailer_id'] ?? ($_GET['retailer_id'] ?? 0));

$retailer_discount = 0.0;
$retailer_category = 'ALL';
if ($retailerIdToFilter > 0) {
    $res_retailer = mysqli_query($conn, "SELECT discount, category FROM retailer WHERE user_id = '$retailerIdToFilter' LIMIT 1");
    if ($res_retailer && $r_row = mysqli_fetch_assoc($res_retailer)) {
        $retailer_discount = (float)($r_row['discount'] ?? 0);
        $retailer_category = $r_row['category'] ?? 'ALL';
    }
}

// Read JSON input or form body for POST operations
$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

// ── 5. POST ACTION: TOGGLE WISHLIST ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['toggle_wishlist']) || (isset($input['action']) && $input['action'] === 'toggle_wishlist'))) {
    $wish_pid = (int)($input['product_id'] ?? $input['wishlist_pid'] ?? 0);
    if ($wish_pid <= 0) {
        fail('Valid product ID required.', 422);
    }

    $check_wish = mysqli_query($conn, "SELECT id FROM user_wishlist WHERE user_id = '$loggedUserId' AND product_id = '$wish_pid'");
    
    if (mysqli_num_rows($check_wish) > 0) {
        mysqli_query($conn, "DELETE FROM user_wishlist WHERE user_id = '$loggedUserId' AND product_id = '$wish_pid'");
        $wCntRes = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM user_wishlist w JOIN products p ON p.id = w.product_id WHERE w.user_id = '$loggedUserId' $mfg_and_p");
        $wishlist_count = ($wCntRes && $wRow = mysqli_fetch_assoc($wCntRes)) ? (int)$wRow['cnt'] : 0;

        success([
            'product_id'     => $wish_pid,
            'is_in_wishlist' => false,
            'wishlist_count' => $wishlist_count
        ], 'Removed from Wishlist');
    } else {
        if (function_exists('ozie_product_allowed') && !ozie_product_allowed($conn, $wish_pid, $vis_ids)) {
            fail('This product is not available for your account.', 403);
        }
        mysqli_query($conn, "INSERT IGNORE INTO user_wishlist (user_id, product_id) VALUES ('$loggedUserId', '$wish_pid')");
        $wCntRes = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM user_wishlist w JOIN products p ON p.id = w.product_id WHERE w.user_id = '$loggedUserId' $mfg_and_p");
        $wishlist_count = ($wCntRes && $wRow = mysqli_fetch_assoc($wCntRes)) ? (int)$wRow['cnt'] : 0;

        success([
            'product_id'     => $wish_pid,
            'is_in_wishlist' => true,
            'wishlist_count' => $wishlist_count
        ], 'Added to Wishlist!');
    }
}

// ── 6. POST ACTION: ADD TO CART ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['add_to_cart']) || (isset($input['action']) && $input['action'] === 'add_to_cart'))) {
    $product_id = (int)($input['product_id'] ?? 0);
    $qty = (int)($input['quantity'] ?? 1);
    if ($qty <= 0) $qty = 1;

    $product_query = mysqli_query($conn, "
        SELECT p.name, p.price AS original_price, p.availability_status, td.temp_price,
               COALESCE(pra.display_name, p.name) AS display_name,
               CASE 
                   WHEN p.master_product_id > 0 THEN COALESCE(m.stock, p.stock) 
                   ELSE p.stock 
               END AS stock
        FROM products p
        LEFT JOIN products m ON p.master_product_id = m.id
        LEFT JOIN product_retailer_aliases pra ON (pra.product_id = p.id AND pra.retailer_id = '$loggedUserId')
        LEFT JOIN retailer_temp_discounts td ON td.product_id = p.id AND td.retailer_id = '$loggedUserId' AND td.status = 'active'
        WHERE p.id = '$product_id' $mfg_and_p LIMIT 1
    ");

    $product_data = mysqli_fetch_assoc($product_query);

    if (!$product_data) {
        fail('This product is not available for your account.', 404);
    }

    $avail_status = $product_data['availability_status'] ?? 'Available';
    if ($avail_status !== 'Available') {
        fail("Cannot purchase. Product is $avail_status.", 400);
    }

    $stock = (int)$product_data['stock'];
    $currentCartQty = getCartQuantity($conn, $loggedUserId, $product_id);

    if ($stock <= 0) {
        fail('Product is out of stock.', 400);
    }
    if (($currentCartQty + $qty) > $stock) {
        fail('Cannot add more than available stock.', 400);
    }

    $product_name = mysqli_real_escape_string($conn, $product_data['display_name'] ?? $product_data['name']);
    $product_price = !empty($product_data['temp_price']) ? (float)$product_data['temp_price'] : (float)$product_data['original_price'];

    $check_cart = mysqli_query($conn, "
        SELECT id, product_quantity FROM retailer_cart 
        WHERE product_id = '$product_id' AND user_id = '$loggedUserId' AND (is_return_claim = 0 OR is_return_claim IS NULL) LIMIT 1
    ");

    if (mysqli_num_rows($check_cart) > 0) {
        $cart_row = mysqli_fetch_assoc($check_cart);
        $new_qty = (int)$cart_row['product_quantity'] + $qty;
        mysqli_query($conn, "UPDATE retailer_cart SET product_quantity = '$new_qty' WHERE id = {$cart_row['id']}");
    } else {
        $sess = session_id() ?: '';
        mysqli_query($conn, "INSERT INTO retailer_cart (session_id, user_id, product_id, product_name, price, product_quantity, is_return_claim) 
                            VALUES ('$sess', '$loggedUserId', '$product_id', '$product_name', '$product_price', '$qty', 0)");
    }

    // Campaign Analytics
    $camp_query = mysqli_query($conn, "SELECT id FROM banner_campaigns WHERE product_id = '$product_id' AND status = 'active' AND start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1");
    if ($camp_query && mysqli_num_rows($camp_query) > 0) {
        $campaign_id = (int)mysqli_fetch_assoc($camp_query)['id'];
        $purchase_amount = $product_price * $qty;
        mysqli_query($conn, "INSERT INTO banner_analytics (campaign_id, retailer_id, event_type, amount) VALUES ('$campaign_id', '$loggedUserId', 'purchase', '$purchase_amount')");
    }

    $newCartQty = getCartQuantity($conn, $loggedUserId, $product_id);
    $cartRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(product_quantity) AS cnt FROM retailer_cart WHERE user_id='$loggedUserId' AND (is_return_claim = 0 OR is_return_claim IS NULL)"));
    $cartCount = (int)($cartRow['cnt'] ?? 0);

    success([
        'product_id'      => $product_id,
        'cart_count'      => $cartCount,
        'new_cart_qty'    => $newCartQty,
        'available_stock' => max(0, $stock - $newCartQty)
    ], 'Product added to cart successfully!');
}

// ── 7. GET ACTION: PRODUCTS LIST, SEARCH & FILTERS ────────
$cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : (isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0);
$brand_filter = isset($_GET['brand']) ? trim(mysqli_real_escape_string($conn, $_GET['brand'])) : '';
$search = isset($_GET['term']) ? trim($_GET['term']) : (isset($_GET['search']) ? trim($_GET['search']) : (isset($_GET['q']) ? trim($_GET['q']) : ''));
$search = mysqli_real_escape_string($conn, $search);

$highlightProduct = isset($_GET['highlight']) && in_array(strtolower((string)$_GET['highlight']), ['true', '1'], true);
$highlightProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$highlightSchemeId  = isset($_GET['scheme_id']) ? (int)$_GET['scheme_id'] : 0;

if ($highlightProduct && $highlightProductId > 0 && $cat_id == 0) {
    $cat_query = mysqli_query($conn, "SELECT category_id FROM products WHERE id = $highlightProductId $mfg_and LIMIT 1");
    if ($cat_query && $cat_row = mysqli_fetch_assoc($cat_query)) {
        $cat_id = (int)$cat_row['category_id'];
    }
}

// Wishlist Items for User
$wishlist_items = [];
$wish_q = mysqli_query($conn, "SELECT w.product_id FROM user_wishlist w
    JOIN products p ON p.id = w.product_id
    WHERE w.user_id = '$loggedUserId' $mfg_and_p");
if ($wish_q) {
    while ($w = mysqli_fetch_assoc($wish_q)) {
        $wishlist_items[] = (int)$w['product_id'];
    }
}
$wishlist_count = count($wishlist_items);

// Cart Count for User
$cartRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(product_quantity) AS cnt FROM retailer_cart WHERE user_id='$loggedUserId' AND (is_return_claim = 0 OR is_return_claim IS NULL)"));
$cartCount = (int)($cartRow['cnt'] ?? 0);

// Pagination
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 32;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Build WHERE Clause
$where = "WHERE (p.availability_status != 'Hidden' OR p.availability_status IS NULL) $mfg_and_p";

if ($cat_id > 0) {
    $where .= " AND p.category_id = $cat_id";
}
if (!empty($brand_filter)) {
    $where .= " AND p.brand = '$brand_filter'";
}
if (!empty($search)) {
    $where .= " AND (p.name LIKE '%$search%' OR p.id LIKE '%$search%' OR pra.display_name LIKE '%$search%')";
}
if (isset($_GET['wishlist_only']) && in_array(strtolower((string)$_GET['wishlist_only']), ['true', '1'], true)) {
    if (empty($wishlist_items)) {
        $where .= " AND 1=0";
    } else {
        $where .= " AND p.id IN (" . implode(',', $wishlist_items) . ")";
    }
}

// Total Count
$count_query = mysqli_query($conn, "SELECT COUNT(*) as total 
                                    FROM products p 
                                    LEFT JOIN product_retailer_aliases pra ON (p.id = pra.product_id AND pra.retailer_id = '$loggedUserId')
                                    $where");
$total_products = ($count_query && $crow = mysqli_fetch_assoc($count_query)) ? (int)$crow['total'] : 0;
$total_pages    = $total_products > 0 ? (int)ceil($total_products / $limit) : 1;

// Distinct Categories scoped to visible manufacturers
$categories_res = mysqli_query($conn, "SELECT DISTINCT c.id, c.name 
                                       FROM category c 
                                       JOIN products p ON p.category_id = c.id 
                                       WHERE (p.availability_status != 'Hidden' OR p.availability_status IS NULL) $mfg_and_p
                                       ORDER BY c.name ASC");
$category_list = [];
if ($categories_res) {
    while ($c = mysqli_fetch_assoc($categories_res)) {
        $category_list[] = [
            'id'   => (int)$c['id'],
            'name' => (string)$c['name']
        ];
    }
}

// Distinct Brands scoped to visible manufacturers
$distinct_brands_res = mysqli_query($conn, "SELECT DISTINCT p.brand 
                                           FROM products p 
                                           WHERE p.brand IS NOT NULL 
                                             AND TRIM(p.brand) != '' 
                                             AND (p.availability_status != 'Hidden' OR p.availability_status IS NULL) $mfg_and_p
                                           ORDER BY p.brand ASC");
$brand_list = [];
if ($distinct_brands_res) {
    while ($b = mysqli_fetch_assoc($distinct_brands_res)) {
        $brand_list[] = (string)$b['brand'];
    }
}

// Master & Alias Stock Sync Query
$products_sql = "
    SELECT 
        p.*, 
        COALESCE(pra.display_name, p.name) AS name, 
        CASE 
            WHEN p.master_product_id > 0 THEN COALESCE(m.stock, p.stock) 
            ELSE p.stock 
        END AS stock 
    FROM products p 
    LEFT JOIN products m ON p.master_product_id = m.id 
    LEFT JOIN product_retailer_aliases pra ON (p.id = pra.product_id AND pra.retailer_id = '$loggedUserId')
    $where 
    ORDER BY CASE p.availability_status WHEN 'Available' THEN 1 WHEN 'Upcoming' THEN 2 WHEN 'Unavailable' THEN 3 ELSE 4 END, p.id DESC 
    LIMIT $offset, $limit
";
$products_query = mysqli_query($conn, $products_sql);
$product_list = [];

if ($products_query) {
    while ($p = mysqli_fetch_assoc($products_query)) {
        $pid          = (int)$p['id'];
        $sku          = str_pad((string)$pid, 6, '0', STR_PAD_LEFT);
        $avail_status = $p['availability_status'] ?? 'Available';
        $is_available = ($avail_status === 'Available');
        $canPurchase  = $is_available;

        $mrp_case     = (float)($p['mrp_case'] ?? 0);
        $billing_case = (float)($p['billing_case'] ?? 0);
        $discount_pct = ($mrp_case > $billing_case && $mrp_case > 0) ? (int)round((($mrp_case - $billing_case) / $mrp_case) * 100) : 0;

        $stock        = (int)$p['stock'];
        $cartQty      = getCartQuantity($conn, $loggedUserId, $pid);

        if (!$is_available) {
            $availableStock = 0;
            $isActuallyOutOfStock = true;
        } else {
            $availableStock = max(0, $stock - $cartQty);
            $isActuallyOutOfStock = ($stock <= 0);
        }

        $schemes = [];
        if (!$isActuallyOutOfStock && $is_available) {
            $schemes = getActiveSchemesForRetailer($conn, $pid, $retailer_category, $today, $p);
        }

        $highestSchemeQty = 0;
        $formattedSchemes = [];
        foreach ($schemes as $s) {
            $schemeQty = (strtoupper($s['scheme_type'] ?? '') === 'FREE') ? (int)($s['buy_qty'] ?? 0) : (int)($s['min_qty'] ?? 0);
            if ($schemeQty > $highestSchemeQty) {
                $highestSchemeQty = $schemeQty;
            }
            $formattedSchemes[] = [
                'id'               => (int)$s['id'],
                'scheme_title'     => $s['scheme_title'] ?? '',
                'scheme_type'      => $s['scheme_type'] ?? '',
                'min_qty'          => (int)($s['min_qty'] ?? 0),
                'buy_qty'          => (int)($s['buy_qty'] ?? 0),
                'free_qty'         => (int)($s['free_qty'] ?? 0),
                'discount_percent' => (float)($s['discount_percent'] ?? 0),
                'gift_name'        => $s['gift_name'] ?? null,
                'start_date'       => $s['start_date'] ?? null,
                'end_date'         => $s['end_date'] ?? null,
            ];
        }

        $selectableLimit = ($availableStock <= 0 || !$canPurchase) ? 0 : min($availableStock, max($availableStock, $highestSchemeQty));
        $photoName       = trim($p['photo'] ?? '');
        $photoUrl        = !empty($photoName) ? $resolveMediaUrl('uploads/' . $photoName) : 'https://placehold.co/400x400/FFFFFF/94A3B8?text=Product';

        $product_list[] = [
            'id'                  => $pid,
            'sku'                 => $sku,
            'name'                => (string)$p['name'],
            'brand'               => (string)($p['brand'] ?? ''),
            'category_id'         => (int)($p['category_id'] ?? 0),
            'photo'               => $photoName,
            'photo_url'           => $photoUrl,
            'unit_size'           => trim($p['unit_size'] ?? ''),
            'units'               => (int)($p['units'] ?? 0),
            'packing_type'        => trim($p['packing_type'] ?? ''),
            'mrp_case'            => $mrp_case,
            'billing_case'        => $billing_case,
            'discount_pct'        => $discount_pct,
            'stock'               => $stock,
            'cart_quantity'       => $cartQty,
            'available_stock'     => $availableStock,
            'availability_status' => $avail_status,
            'is_available'        => $is_available,
            'can_purchase'        => $canPurchase,
            'is_out_of_stock'     => $isActuallyOutOfStock,
            'is_low_stock'        => ($availableStock > 0 && $availableStock <= 5),
            'is_in_wishlist'      => in_array($pid, $wishlist_items, true),
            'is_highlighted'      => ($highlightProduct && $highlightProductId === $pid),
            'has_scheme'          => !empty($formattedSchemes),
            'selectable_limit'    => $selectableLimit,
            'schemes'             => $formattedSchemes
        ];
    }
}

// ── 8. Return JSON Response ───────────────────────────────
success([
    'scope_notice'     => $scopeNotice,
    'cart_count'       => $cartCount,
    'wishlist_count'   => $wishlistCount,
    'categories'       => $category_list,
    'brands'           => $brand_list,
    'selected_filters' => [
        'category_id'          => $cat_id,
        'brand'                => $brand_filter,
        'search'               => $search,
        'highlight_product_id' => $highlightProductId,
        'highlight_scheme_id'  => $highlightSchemeId,
    ],
    'pagination' => [
        'current_page'   => $page,
        'limit'          => $limit,
        'total_products' => $total_products,
        'total_pages'    => $total_pages,
        'has_next_page'  => ($page < $total_pages),
        'has_prev_page'  => ($page > 1)
    ],
    'products' => $product_list
], 'Products catalog loaded successfully.');