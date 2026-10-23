<?php
/**
 * Ozie API — Retailer Products & Catalog
 * Endpoint:
 *   GET  /v1/retailer/products
 *   POST /v1/retailer/products (Toggle wishlist or Add to cart)
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Compatibility Wrapper ──────────────────────
if (!function_exists('success')) {
    function success($data = [], $message = 'Success', $code = 200) {
        if (function_exists('ok')) {
            ok($data, $message, $code);
        } else {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $message,
                'data'    => $data,
                'errors'  => null
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// ── 1. Database Connection Resolver (PDO & MySQLi) ─────────
$db = null;
if (function_exists('db')) {
    try { $db = db(); } catch (\Throwable $e) {}
}
if (!$db && isset($GLOBALS['conn'])) {
    $db = $GLOBALS['conn'];
}
if (!$db) {
    $siteRootDb = dirname(__DIR__, 3) . '/db.php';
    if (file_exists($siteRootDb)) {
        @include_once $siteRootDb;
        $db = $conn ?? ($con ?? null);
    }
}

// ── 2. Universal Bulletproof Query Helpers ─────────────────
if (!function_exists('safe_query')) {
    function safe_query($db, string $sql, array $params = []): array {
        if (!$db) return [];
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            if ($db instanceof mysqli) {
                if (empty($params)) {
                    $res = $db->query($sql);
                    return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
                }
                $escapedSql = $sql;
                foreach ($params as $val) {
                    if (is_int($val) || is_float($val)) {
                        $rep = (string)$val;
                    } elseif (is_null($val)) {
                        $rep = "NULL";
                    } else {
                        $rep = "'" . mysqli_real_escape_string($db, (string)$val) . "'";
                    }
                    $pos = strpos($escapedSql, '?');
                    if ($pos !== false) {
                        $escapedSql = substr_replace($escapedSql, $rep, $pos, 1);
                    }
                }
                $res = $db->query($escapedSql);
                return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
            }
        } catch (\Throwable $e) {
            error_log("[SafeQuery Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return [];
    }
}

if (!function_exists('safe_one')) {
    function safe_one($db, string $sql, array $params = []): ?array {
        $rows = safe_query($db, $sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }
}

if (!function_exists('safe_exec')) {
    function safe_exec($db, string $sql, array $params = []): bool {
        if (!$db) return false;
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                return $stmt->execute($params);
            }
            if ($db instanceof mysqli) {
                if (empty($params)) return (bool)$db->query($sql);
                $escapedSql = $sql;
                foreach ($params as $val) {
                    $rep = is_numeric($val) ? (string)$val : "'" . mysqli_real_escape_string($db, (string)$val) . "'";
                    $pos = strpos($escapedSql, '?');
                    if ($pos !== false) $escapedSql = substr_replace($escapedSql, $rep, $pos, 1);
                }
                return (bool)$db->query($escapedSql);
            }
        } catch (\Throwable $e) {
            error_log("[SafeExec Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return false;
    }
}

// ── 3. Authentication & Role Check ────────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$loggedUserId = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
$userType     = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($loggedUserId <= 0) {
    fail('Authentication required.', 401);
}

// Verify Role with database fallback
if ($userType !== 'retailer' && $userType !== 'salesman') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$loggedUserId, $loggedUserId]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $loggedUserId = (int)$checkRet['user_id'];
        }
    }
}

// Ensure required tables exist
safe_exec($db, "CREATE TABLE IF NOT EXISTS user_wishlist (
    id int(11) NOT NULL AUTO_INCREMENT,
    user_id int(11) NOT NULL,
    product_id int(11) NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_prod (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

safe_exec($db, "CREATE TABLE IF NOT EXISTS product_retailer_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    retailer_id INT NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_prod_retailer (product_id, retailer_id),
    INDEX (product_id),
    INDEX (retailer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ── 4. Manufacturer Visibility Scoping ─────────────────────
$vis_ids = null;
$scopeNotice = '';

// Check mapped manufacturers if retailer
if ($userType === 'retailer') {
    $mapRows = safe_query($db, "SELECT manufacturer_id FROM ozie_retailer_mappings WHERE retailer_id = ? AND status = 'active'", [$loggedUserId]);
    if (!empty($mapRows)) {
        $vis_ids = array_map(function($r) { return (int)$r['manufacturer_id']; }, $mapRows);
    } else {
        $hasAnyMap = safe_one($db, "SELECT 1 FROM ozie_retailer_mappings WHERE retailer_id = ? LIMIT 1", [$loggedUserId]);
        if ($hasAnyMap) {
            $vis_ids = [];
            $scopeNotice = 'No active manufacturer is mapped to your outlet yet.';
        }
    }
}

$mfg_and_p = '';
$mfg_and   = '';
if (is_array($vis_ids)) {
    if (count($vis_ids) === 0) {
        $mfg_and_p = " AND 1=0 ";
        $mfg_and   = " AND 1=0 ";
    } else {
        $idList = implode(',', array_map('intval', $vis_ids));
        $mfg_and_p = " AND p.manufacturer_id IN ($idList) ";
        $mfg_and   = " AND manufacturer_id IN ($idList) ";
    }
}

// ── 5. Helper Functions ───────────────────────────────────
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com';
$siteBaseUrl = $protocol . $host . '/';

$resolveMediaUrl = function (?string $path) use ($siteBaseUrl): string {
    $p = trim($path ?? '');
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    return $siteBaseUrl . ltrim($p, '/');
};

$safeDate = function ($dateStr, $format = 'd M Y'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

function getCartQuantity($db, $user_id, $product_id) {
    $row = safe_one($db, "SELECT SUM(product_quantity) as total FROM retailer_cart WHERE product_id = ? AND user_id = ? AND (is_return_claim = 0 OR is_return_claim IS NULL)", [(int)$product_id, (int)$user_id]);
    return (int)($row['total'] ?? 0);
}

function getActiveSchemesForRetailer($db, $product_id, $retailer_category, $today, $product_info = null, $vis_ids = null) {
    if (is_array($vis_ids) && count($vis_ids) === 0) return [];

    $pid = (int)$product_id;
    $today = !empty($today) ? $today : date('Y-m-d');

    $p_mfg_id = (int)($product_info['manufacturer_id'] ?? 0);
    $p_brand  = trim($product_info['brand'] ?? '');

    if ($p_mfg_id <= 0) {
        $pq = safe_one($db, "SELECT manufacturer_id, brand FROM products WHERE id = ? LIMIT 1", [$pid]);
        if ($pq) {
            $p_mfg_id = (int)($pq['manufacturer_id'] ?? 0);
            $p_brand  = trim($pq['brand'] ?? '');
        }
    }

    if (is_array($vis_ids) && $p_mfg_id > 0 && !in_array($p_mfg_id, $vis_ids, true)) {
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

    $sql = "SELECT s.* 
            FROM schemes s 
            WHERE s.status = 'active' 
              AND s.start_date IS NOT NULL AND s.start_date != '0000-00-00' 
              AND s.end_date IS NOT NULL AND s.end_date != '0000-00-00' 
              AND ? BETWEEN s.start_date AND s.end_date 
              AND (s.category = 'ALL' OR FIND_IN_SET(?, REPLACE(s.category,' ','')) > 0 OR s.category = '' OR s.category IS NULL) 
              AND (s.product_id = ? OR s.product_id = 0)
              $mfg_filter 
            ORDER BY s.min_qty ASC";

    return safe_query($db, $sql, [$today, $retailer_category, $pid]);
}

// ── 6. Retailer Category Resolution ───────────────────────
$today = date('Y-m-d');
$retailerIdToFilter = ($userType === 'retailer') ? $loggedUserId : (int)($_SESSION['current_retailer_id'] ?? ($_GET['retailer_id'] ?? 0));

$retailer_discount = 0.0;
$retailer_category = 'ALL';
if ($retailerIdToFilter > 0) {
    $r_row = safe_one($db, "SELECT discount, category FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$retailerIdToFilter, $retailerIdToFilter]);
    if ($r_row) {
        $retailer_discount = (float)($r_row['discount'] ?? 0);
        $retailer_category = !empty($r_row['category']) ? $r_row['category'] : 'ALL';
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

// ── 7. POST ACTION: TOGGLE WISHLIST ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['toggle_wishlist']) || (isset($input['action']) && $input['action'] === 'toggle_wishlist'))) {
    $wish_pid = (int)($input['product_id'] ?? $input['wishlist_pid'] ?? 0);
    if ($wish_pid <= 0) fail('Valid product ID required.', 422);

    $check_wish = safe_one($db, "SELECT id FROM user_wishlist WHERE user_id = ? AND product_id = ?", [$loggedUserId, $wish_pid]);
    
    if ($check_wish) {
        safe_exec($db, "DELETE FROM user_wishlist WHERE user_id = ? AND product_id = ?", [$loggedUserId, $wish_pid]);
        $wRow = safe_one($db, "SELECT COUNT(*) as cnt FROM user_wishlist WHERE user_id = ?", [$loggedUserId]);
        $wishlist_count = (int)($wRow['cnt'] ?? 0);

        ok([
            'product_id'     => $wish_pid,
            'is_in_wishlist' => false,
            'wishlist_count' => $wishlist_count
        ], 'Removed from Wishlist');
    } else {
        safe_exec($db, "INSERT IGNORE INTO user_wishlist (user_id, product_id) VALUES (?, ?)", [$loggedUserId, $wish_pid]);
        $wRow = safe_one($db, "SELECT COUNT(*) as cnt FROM user_wishlist WHERE user_id = ?", [$loggedUserId]);
        $wishlist_count = (int)($wRow['cnt'] ?? 0);

        ok([
            'product_id'     => $wish_pid,
            'is_in_wishlist' => true,
            'wishlist_count' => $wishlist_count
        ], 'Added to Wishlist!');
    }
}

// ── 8. POST ACTION: ADD TO CART ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($input['add_to_cart']) || (isset($input['action']) && $input['action'] === 'add_to_cart'))) {
    $product_id = (int)($input['product_id'] ?? 0);
    $qty = max(1, (int)($input['quantity'] ?? 1));

    $product_data = safe_one($db, "
        SELECT p.name, p.price AS original_price, p.availability_status,
               COALESCE(pra.display_name, p.name) AS display_name,
               CASE 
                   WHEN p.master_product_id > 0 THEN COALESCE(m.stock, p.stock) 
                   ELSE p.stock 
               END AS stock
        FROM products p
        LEFT JOIN products m ON p.master_product_id = m.id
        LEFT JOIN product_retailer_aliases pra ON (pra.product_id = p.id AND pra.retailer_id = ?)
        WHERE p.id = ? $mfg_and_p LIMIT 1
    ", [$loggedUserId, $product_id]);

    if (!$product_data) fail('This product is not available for your account.', 404);

    $avail_status = $product_data['availability_status'] ?? 'Available';
    if ($avail_status !== 'Available') fail("Cannot purchase. Product is $avail_status.", 400);

    $stock = (int)$product_data['stock'];
    $currentCartQty = getCartQuantity($db, $loggedUserId, $product_id);

    if ($stock <= 0) fail('Product is out of stock.', 400);
    if (($currentCartQty + $qty) > $stock) fail('Cannot add more than available stock.', 400);

    $product_name  = (string)($product_data['display_name'] ?? $product_data['name']);
    $product_price = (float)($product_data['original_price'] ?? 0);

    $check_cart = safe_one($db, "
        SELECT id, product_quantity FROM retailer_cart 
        WHERE product_id = ? AND user_id = ? AND (is_return_claim = 0 OR is_return_claim IS NULL) LIMIT 1
    ", [$product_id, $loggedUserId]);

    if ($check_cart) {
        $new_qty = (int)$check_cart['product_quantity'] + $qty;
        safe_exec($db, "UPDATE retailer_cart SET product_quantity = ? WHERE id = ?", [$new_qty, (int)$check_cart['id']]);
    } else {
        $sess = session_id() ?: '';
        safe_exec($db, "
            INSERT INTO retailer_cart (session_id, user_id, product_id, product_name, price, product_quantity, is_return_claim) 
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ", [$sess, $loggedUserId, $product_id, $product_name, $product_price, $qty]);
    }

    $newCartQty = getCartQuantity($db, $loggedUserId, $product_id);
    $cartRow = safe_one($db, "SELECT SUM(product_quantity) AS cnt FROM retailer_cart WHERE user_id = ? AND (is_return_claim = 0 OR is_return_claim IS NULL)", [$loggedUserId]);
    $cartCount = (int)($cartRow['cnt'] ?? 0);

    ok([
        'product_id'      => $product_id,
        'cart_count'      => $cartCount,
        'new_cart_qty'    => $newCartQty,
        'available_stock' => max(0, $stock - $newCartQty)
    ], 'Product added to cart successfully!');
}

// ── 9. GET ACTION: PRODUCTS LIST, SEARCH & FILTERS ────────
$cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : (isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0);
$brand_filter = trim($_GET['brand'] ?? '');
$search = trim($_GET['term'] ?? ($_GET['search'] ?? ($_GET['q'] ?? '')));

$highlightProduct = isset($_GET['highlight']) && in_array(strtolower((string)$_GET['highlight']), ['true', '1'], true);
$highlightProductId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$highlightSchemeId  = isset($_GET['scheme_id']) ? (int)$_GET['scheme_id'] : 0;

if ($highlightProduct && $highlightProductId > 0 && $cat_id == 0) {
    $cat_row = safe_one($db, "SELECT category_id FROM products WHERE id = ? $mfg_and LIMIT 1", [$highlightProductId]);
    if ($cat_row) {
        $cat_id = (int)$cat_row['category_id'];
    }
}

// Wishlist Items for User
$wishlistRows = safe_query($db, "SELECT product_id FROM user_wishlist WHERE user_id = ?", [$loggedUserId]);
$wishlist_items = array_map(function($w) { return (int)$w['product_id']; }, $wishlistRows);
$wishlist_count = count($wishlist_items);

// Cart Count for User
$cartRow = safe_one($db, "SELECT SUM(product_quantity) AS cnt FROM retailer_cart WHERE user_id = ? AND (is_return_claim = 0 OR is_return_claim IS NULL)", [$loggedUserId]);
$cartCount = (int)($cartRow['cnt'] ?? 0);

// Pagination
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 32;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Build WHERE Clause with prepared params
$where_clauses = ["(p.availability_status != 'Hidden' OR p.availability_status IS NULL)"];
$params = [];

if ($cat_id > 0) {
    $where_clauses[] = "p.category_id = ?";
    $params[] = $cat_id;
}
if ($brand_filter !== '') {
    $where_clauses[] = "p.brand = ?";
    $params[] = $brand_filter;
}
if ($search !== '') {
    $where_clauses[] = "(p.name LIKE ? OR p.id LIKE ? OR pra.display_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (isset($_GET['wishlist_only']) && in_array(strtolower((string)$_GET['wishlist_only']), ['true', '1'], true)) {
    if (empty($wishlist_items)) {
        $where_clauses[] = "1=0";
    } else {
        $where_clauses[] = "p.id IN (" . implode(',', $wishlist_items) . ")";
    }
}

$where = "WHERE " . implode(' AND ', $where_clauses) . $mfg_and_p;

// Total Count
$count_query = safe_one($db, "
    SELECT COUNT(*) as total 
    FROM products p 
    LEFT JOIN product_retailer_aliases pra ON (p.id = pra.product_id AND pra.retailer_id = $loggedUserId)
    $where
", $params);

$total_products = (int)($count_query['total'] ?? 0);
$total_pages    = $total_products > 0 ? (int)ceil($total_products / $limit) : 1;

// Distinct Categories
$category_list = safe_query($db, "
    SELECT DISTINCT c.id, c.name 
    FROM category c 
    JOIN products p ON p.category_id = c.id 
    WHERE (p.availability_status != 'Hidden' OR p.availability_status IS NULL) $mfg_and_p
    ORDER BY c.name ASC
");

// Distinct Brands
$brand_rows = safe_query($db, "
    SELECT DISTINCT p.brand 
    FROM products p 
    WHERE p.brand IS NOT NULL 
      AND TRIM(p.brand) != '' 
      AND (p.availability_status != 'Hidden' OR p.availability_status IS NULL) $mfg_and_p
    ORDER BY p.brand ASC
");
$brand_list = array_values(array_filter(array_map(function($b) { return (string)$b['brand']; }, $brand_rows)));

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
    LEFT JOIN product_retailer_aliases pra ON (p.id = pra.product_id AND pra.retailer_id = $loggedUserId)
    $where 
    ORDER BY CASE p.availability_status WHEN 'Available' THEN 1 WHEN 'Upcoming' THEN 2 WHEN 'Unavailable' THEN 3 ELSE 4 END, p.id DESC 
    LIMIT $offset, $limit
";
$products_query = safe_query($db, $products_sql, $params);
$product_list = [];

foreach ($products_query as $p) {
    $pid          = (int)$p['id'];
    $sku          = str_pad((string)$pid, 6, '0', STR_PAD_LEFT);
    $avail_status = $p['availability_status'] ?? 'Available';
    $is_available = ($avail_status === 'Available');
    $canPurchase  = $is_available;

    $mrp_case     = (float)($p['mrp_case'] ?? 0);
    $billing_case = (float)($p['billing_case'] ?? 0);
    $discount_pct = ($mrp_case > $billing_case && $mrp_case > 0) ? (int)round((($mrp_case - $billing_case) / $mrp_case) * 100) : 0;

    $stock        = (int)$p['stock'];
    $cartQty      = getCartQuantity($db, $loggedUserId, $pid);

    if (!$is_available) {
        $availableStock = 0;
        $isActuallyOutOfStock = true;
    } else {
        $availableStock = max(0, $stock - $cartQty);
        $isActuallyOutOfStock = ($stock <= 0);
    }

    $schemes = [];
    if (!$isActuallyOutOfStock && $is_available) {
        $schemes = getActiveSchemesForRetailer($db, $pid, $retailer_category, $today, $p, $vis_ids);
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
            'start_date'       => $safeDate($s['start_date'] ?? null),
            'end_date'         => $safeDate($s['end_date'] ?? null)
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

// ── 10. Return Clean JSON Response ─────────────────────────
ok([
    'scope_notice'     => $scopeNotice,
    'cart_count'       => $cartCount,
    'wishlist_count'   => $wishlist_count,
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