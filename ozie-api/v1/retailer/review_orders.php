<?php
/**
 * Ozie API — Retailer Review Orders
 * Endpoint:
 *   GET  /v1/retailer/review-orders (List drafts, approved history, calculated schemes & summaries)
 *   POST /v1/retailer/review-orders (Approve, Reject, Delete, Remove Product, Update Quantities, Preview)
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

// ── 1. Database Connection Resolver ───────────────────────
$db = null;
if (function_exists('db')) {
    try { $db = db(); } catch (\Throwable $e) {}
}
if (!$db && isset($GLOBALS['conn'])) {
    $db = $GLOBALS['conn'];
}
if (!$db && isset($GLOBALS['con'])) {
    $db = $GLOBALS['con'];
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
                if (empty($params)) {
                    return (bool)$db->query($sql);
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
                return (bool)$db->query($escapedSql);
            }
        } catch (\Throwable $e) {
            error_log("[SafeExec Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return false;
    }
}

// ── 3. Safe Date Formatter ─────────────────────────────────
$safeDate = function ($dateStr, $format = 'd M, Y'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

// ── 4. Authentication & Retailer Scope ─────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$retailer_id = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
if ($retailer_id <= 0) {
    fail('Invalid authenticated user identifier.', 401);
}

$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));
if ($userType !== 'retailer') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$retailer_id, $retailer_id]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $retailer_id = (int)$checkRet['user_id'];
        }
    }
}

if ($userType !== 'retailer') {
    fail('Access denied. Retailer account required.', 403);
}

// Retailer category and credit duration
$retInfo = safe_one($db, "SELECT name, category, credit_duration FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$retailer_id, $retailer_id]) ?: [];
$retailer_name     = !empty($retInfo['name']) ? trim($retInfo['name']) : (!empty($user['name']) ? trim($user['name']) : 'Retailer');
$retailer_category = !empty($retInfo['category']) ? trim($retInfo['category']) : 'ALL';
$credit_duration   = (int)($retInfo['credit_duration'] ?? 0);

// ── 5. Helper Functions: Schemes, Stock & Recalculation ────

function calculateSchemeBenefits($db, $product_id, $quantity, $price, $retailer_category, $retailer_id) {
    $benefits = [
        'free_qty'             => 0,
        'discount_percent'     => 0,
        'flat_discount_saving' => 0,
        'cashback_amount'      => 0,
        'credit_days'          => 0,
        'bonus_qty'            => 0,
        'total_savings'        => 0,
        'scheme_messages'      => []
    ];

    $pid = (int)$product_id;
    $qty = (int)$quantity;
    $prc = (float)$price;

    $sql = "SELECT * FROM schemes 
            WHERE status = 'active'
              AND (start_date IS NOT NULL AND start_date != '0000-00-00')
              AND (end_date IS NOT NULL AND end_date != '0000-00-00')
              AND CURDATE() BETWEEN start_date AND end_date
              AND (product_id = ? OR product_id = 0)
              AND (retailer_id = 0 OR retailer_id = ?)
              AND (category = 'ALL' OR FIND_IN_SET(?, REPLACE(category, ' ', '')) > 0 OR category = '' OR category IS NULL)
            ORDER BY min_qty ASC";

    $res = safe_query($db, $sql, [$pid, $retailer_id, $retailer_category]);

    foreach ($res as $scheme) {
        $type = strtoupper($scheme['scheme_type'] ?? '');
        $buy_qty = (int)($scheme['buy_qty'] ?? 0) > 0 ? (int)$scheme['buy_qty'] : (int)($scheme['min_qty'] ?? 0);
        $target_qty = in_array($type, ['FREE', 'LIFTING_FREE'], true) ? $buy_qty : (int)($scheme['min_qty'] ?? 0);
        if ($type === 'LIFTING_DISCOUNT' || $type === 'LIFTING_FREE') {
            $target_qty = (int)($scheme['min_qty'] ?? 0);
        }

        if ($qty < $target_qty) continue;

        $message_text = '';
        switch ($type) {
            case 'FREE':
            case 'LIFTING_FREE':
                if ($buy_qty > 0) {
                    $free_units = floor($qty / $buy_qty) * (int)($scheme['free_qty'] ?? 0);
                    $benefits['free_qty'] += $free_units;
                    $benefits['total_savings'] += $free_units * $prc;
                    $message_text = "Get {$free_units} Free cases";
                }
                break;
            case 'DISCOUNT':
            case 'LIFTING_DISCOUNT':
                $discount_percent = (float)($scheme['discount'] ?? 0);
                $benefits['discount_percent'] += $discount_percent;
                $benefits['total_savings'] += ($qty * $prc) * ($discount_percent / 100);
                $message_text = "{$discount_percent}% Discount Applied";
                break;
            case 'FLAT':
                $flat_discount = (float)($scheme['discount'] ?? 0);
                $flat_saving = $qty * $flat_discount;
                $benefits['flat_discount_saving'] += $flat_saving;
                $benefits['total_savings'] += $flat_saving;
                $message_text = "Flat ₹{$flat_discount} off per case applied";
                break;
            case 'CASHBACK':
                $cashback = (float)($scheme['discount'] ?? 0);
                $benefits['cashback_amount'] += $cashback;
                $benefits['total_savings'] += $cashback;
                $message_text = "₹{$cashback} Cashback Applied";
                break;
            case 'CREDIT':
                $benefits['credit_days'] = max($benefits['credit_days'], (int)($scheme['credit_days'] ?? 0));
                $message_text = "{$scheme['credit_days']} Days Credit Applied";
                break;
            case 'BONUS':
                $bonus_qty = (int)($scheme['free_qty'] ?? 0);
                $benefits['bonus_qty'] += $bonus_qty;
                $benefits['total_savings'] += $bonus_qty * $prc;
                $message_text = "{$bonus_qty} Bonus Items Applied";
                break;
        }

        if (!empty($message_text)) {
            $benefits['scheme_messages'][] = [
                'title'   => $scheme['scheme_title'] ?? '',
                'message' => $message_text,
                'type'    => strtolower($type)
            ];
        }
    }

    return $benefits;
}

function getApplicableSchemesWithMessages($db, $product_id, $quantity, $price, $retailer_category, $retailer_id) {
    $schemes = ['applied' => [], 'unlockable' => []];

    $pid = (int)$product_id;
    $qty = (int)$quantity;
    $prc = (float)$price;

    $stock_row = safe_one($db, "SELECT stock FROM products WHERE id = ?", [$pid]);
    $current_stock = (int)($stock_row['stock'] ?? 0);

    $sql = "SELECT * FROM schemes 
            WHERE status = 'active'
              AND (start_date IS NOT NULL AND start_date != '0000-00-00')
              AND (end_date IS NOT NULL AND end_date != '0000-00-00')
              AND CURDATE() BETWEEN start_date AND end_date
              AND (product_id = ? OR product_id = 0)
              AND (retailer_id = 0 OR retailer_id = ?)
              AND (category = 'ALL' OR FIND_IN_SET(?, REPLACE(category, ' ', '')) > 0 OR category = '' OR category IS NULL)
            ORDER BY min_qty ASC";

    $res = safe_query($db, $sql, [$pid, $retailer_id, $retailer_category]);

    foreach ($res as $scheme) {
        $type = strtoupper($scheme['scheme_type'] ?? '');
        $min_qty = (int)($scheme['min_qty'] ?? 0);
        $buy_qty = (int)($scheme['buy_qty'] ?? 0) > 0 ? (int)$scheme['buy_qty'] : $min_qty;
        $free_qty = (int)($scheme['free_qty'] ?? 0);
        $discount = (float)($scheme['discount'] ?? 0);

        $target_qty = in_array($type, ['FREE', 'LIFTING_FREE'], true) ? $buy_qty : $min_qty;
        if ($type === 'LIFTING_DISCOUNT' || $type === 'LIFTING_FREE') {
            $target_qty = $min_qty;
        }

        $scheme_info = [
            'scheme_id'   => (int)($scheme['id'] ?? 0),
            'title'       => (string)($scheme['scheme_title'] ?? ''),
            'type'        => $type,
            'min_qty'     => $min_qty,
            'buy_qty'     => $buy_qty,
            'free_qty'    => $free_qty,
            'discount'    => $discount,
            'credit_days' => (int)($scheme['credit_days'] ?? 0)
        ];

        if ($qty >= $target_qty) {
            $message_text = '';
            $applied_qty = 0;
            $savings = 0.0;

            switch ($type) {
                case 'FREE':
                case 'LIFTING_FREE':
                    if ($buy_qty > 0) {
                        $complete_bundles = floor($qty / $buy_qty);
                        $remaining_items = $qty % $buy_qty;
                        $applied_free = $complete_bundles * $free_qty;
                        if ($applied_free > 0) {
                            $message_text = "Buy {$buy_qty} Get {$free_qty} Free. Unlocked: {$applied_free} cases FREE!";
                            $applied_qty = $applied_free;
                            $savings = $applied_free * $prc;
                        }
                        if ($remaining_items > 0) {
                            $needed = $buy_qty - $remaining_items;
                            if ($needed <= $current_stock) {
                                $add_sch = $scheme_info;
                                $add_sch['message'] = "Add More Get More: Add {$needed} more cases to get {$free_qty} additional FREE cases!";
                                $add_sch['applied'] = false;
                                $add_sch['needed']  = $needed;
                                $schemes['unlockable'][] = $add_sch;
                            }
                        } else {
                            if ($current_stock >= $buy_qty) {
                                $add_sch = $scheme_info;
                                $add_sch['message'] = "Add More Get More: Add {$buy_qty} more cases to get {$free_qty} additional FREE cases!";
                                $add_sch['applied'] = false;
                                $add_sch['needed']  = $buy_qty;
                                $schemes['unlockable'][] = $add_sch;
                            }
                        }
                    } else {
                        $message_text = "Get {$free_qty} Free Cases";
                        $applied_qty  = $free_qty;
                        $savings      = $free_qty * $prc;
                    }
                    break;
                case 'DISCOUNT':
                case 'LIFTING_DISCOUNT':
                    $message_text = "{$discount}% Discount Applied";
                    $savings = ($qty * $prc) * ($discount / 100);
                    break;
                case 'FLAT':
                    $message_text = "Flat ₹{$discount} off per case applied";
                    $savings = $qty * $discount;
                    break;
                case 'CASHBACK':
                    $message_text = "₹{$discount} Cashback";
                    $savings = $discount;
                    break;
                case 'CREDIT':
                    $message_text = "{$scheme['credit_days']} Days Credit";
                    break;
                case 'BONUS':
                    $message_text = "{$free_qty} Bonus Items";
                    $savings = $free_qty * $prc;
                    break;
            }

            $scheme_info['message']     = $message_text;
            $scheme_info['applied']     = true;
            $scheme_info['savings']     = $savings;
            $scheme_info['applied_qty'] = $applied_qty;
            if (!empty($message_text)) {
                $schemes['applied'][] = $scheme_info;
            }
        } else {
            $needed = $target_qty - $qty;
            if ($current_stock >= $needed) {
                switch ($type) {
                    case 'FREE':
                    case 'LIFTING_FREE':
                        $message_text = ($qty == 0) ? "Add {$target_qty} more cases to get {$free_qty} FREE cases!" : "Add " . ($buy_qty - ($qty % $buy_qty)) . " more cases to get {$free_qty} FREE cases!";
                        break;
                    case 'DISCOUNT':
                    case 'LIFTING_DISCOUNT':
                        $message_text = "Add {$needed} more cases for {$discount}% discount!";
                        break;
                    case 'FLAT':
                        $message_text = "Add {$needed} more cases for ₹{$discount} flat off per case!";
                        break;
                    case 'CASHBACK':
                        $message_text = "Add {$needed} more for ₹{$discount} cashback!";
                        break;
                    case 'CREDIT':
                        $message_text = "Add {$needed} more for {$scheme['credit_days']} days credit!";
                        break;
                    case 'BONUS':
                        $message_text = "Add {$needed} more for {$free_qty} bonus items!";
                        break;
                }
                $scheme_info['message'] = $message_text;
                $scheme_info['applied'] = false;
                $scheme_info['needed']  = $needed;
                $schemes['unlockable'][] = $scheme_info;
            }
        }
    }

    return $schemes;
}

function canApproveOrder($db, $order_id) {
    $oid = (int)$order_id;
    $items = safe_query($db, "
        SELECT rod.product_name, rod.product_quantity, p.stock 
        FROM retailer_order_details rod 
        JOIN products p ON rod.product_id = p.id 
        WHERE rod.order_id = ?
    ", [$oid]);

    $stock_issues = [];
    foreach ($items as $item) {
        if ((int)$item['stock'] < (int)$item['product_quantity']) {
            $stock_issues[] = [
                'product_name'    => $item['product_name'] ?? 'Product',
                'ordered_qty'     => (int)$item['product_quantity'],
                'available_stock' => (int)$item['stock']
            ];
        }
    }

    return [
        'can_approve'  => empty($stock_issues),
        'stock_issues' => $stock_issues
    ];
}

function restoreStockFromOrder($db, $order_id, $reason = 'Order rejected by retailer') {
    $oid = (int)$order_id;
    $items = safe_query($db, "SELECT product_id, product_quantity FROM retailer_order_details WHERE order_id = ?", [$oid]);
    foreach ($items as $item) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['product_quantity'];
        if ($pid > 0 && $qty > 0) {
            safe_exec($db, "UPDATE products SET stock = stock + ? WHERE id = ?", [$qty, $pid]);
            safe_exec($db, "INSERT INTO stock_logs (product_id, order_id, action, quantity, notes, created_at) VALUES (?, ?, 'RESTORE', ?, ?, NOW())", [$pid, $oid, $qty, $reason]);
        }
    }
}

function updateFreeProductQuantity($db, $order_id, $product_id, $main_qty, $retailer_id, $retailer_category) {
    $oid = (int)$order_id;
    $pid = (int)$product_id;
    $mqty = (int)$main_qty;

    $scheme_sql = "SELECT * FROM schemes 
            WHERE status = 'active'
              AND (start_date IS NOT NULL AND start_date != '0000-00-00')
              AND (end_date IS NOT NULL AND end_date != '0000-00-00')
              AND CURDATE() BETWEEN start_date AND end_date
              AND (product_id = ? OR product_id = 0)
              AND (retailer_id = 0 OR retailer_id = ?)
              AND (category = 'ALL' OR FIND_IN_SET(?, REPLACE(category, ' ', '')) > 0 OR category = '' OR category IS NULL)
              AND (scheme_type = 'FREE' OR scheme_type = 'LIFTING_FREE')
            ORDER BY min_qty ASC";

    $schemes = safe_query($db, $scheme_sql, [$pid, $retailer_id, $retailer_category]);

    foreach ($schemes as $scheme) {
        $buy_qty = (int)($scheme['buy_qty'] ?? 0) > 0 ? (int)$scheme['buy_qty'] : (int)($scheme['min_qty'] ?? 0);
        $free_qty_per_bundle = (int)($scheme['free_qty'] ?? 0);

        $total_free_qty = 0;
        if ($mqty >= $buy_qty && $buy_qty > 0) {
            $total_free_qty = floor($mqty / $buy_qty) * $free_qty_per_bundle;
        }

        $free_product = safe_one($db, "
            SELECT id, product_quantity FROM retailer_order_details 
            WHERE order_id = ? AND product_id = ? AND product_price = 0 AND product_name LIKE '%[FREE%'
            LIMIT 1
        ", [$oid, $pid]);

        if ($free_product) {
            if ($total_free_qty > 0) {
                safe_exec($db, "UPDATE retailer_order_details SET product_quantity = ? WHERE id = ?", [$total_free_qty, (int)$free_product['id']]);
            } else {
                safe_exec($db, "DELETE FROM retailer_order_details WHERE id = ?", [(int)$free_product['id']]);
            }
        } elseif ($total_free_qty > 0) {
            $prod_info = safe_one($db, "SELECT name, product_name FROM products WHERE id = ?", [$pid]);
            $product_name = !empty($prod_info['product_name']) ? $prod_info['product_name'] : (!empty($prod_info['name']) ? $prod_info['name'] : 'Product');
            $scheme_title = $scheme['scheme_title'] ?? 'Scheme';
            safe_exec($db, "
                INSERT INTO retailer_order_details (order_id, product_id, product_name, product_quantity, product_price, product_totalprice)
                VALUES (?, ?, ?, ?, 0, 0)
            ", [$oid, $pid, "{$product_name} [FREE - {$scheme_title}]", $total_free_qty]);
        }

        return $total_free_qty;
    }
    return 0;
}

function recheckAndApplySchemes($db, $order_id, $product_id, $qty, $retailer_id, $retailer_category) {
    $oid = (int)$order_id;
    $pid = (int)$product_id;
    $quantity = (int)$qty;

    safe_exec($db, "DELETE FROM order_applied_schemes WHERE order_id = ? AND product_id = ?", [$oid, $pid]);

    $sql = "SELECT * FROM schemes 
            WHERE status = 'active'
              AND (start_date IS NOT NULL AND start_date != '0000-00-00')
              AND (end_date IS NOT NULL AND end_date != '0000-00-00')
              AND CURDATE() BETWEEN start_date AND end_date
              AND (product_id = ? OR product_id = 0)
              AND (retailer_id = 0 OR retailer_id = ?)
              AND (category = 'ALL' OR FIND_IN_SET(?, REPLACE(category, ' ', '')) > 0 OR category = '' OR category IS NULL)
            ORDER BY min_qty ASC";

    $schemes = safe_query($db, $sql, [$pid, $retailer_id, $retailer_category]);

    foreach ($schemes as $sch) {
        $type = strtoupper($sch['scheme_type'] ?? '');
        $buy_qty = (int)($sch['buy_qty'] ?? 0) > 0 ? (int)$sch['buy_qty'] : (int)($sch['min_qty'] ?? 0);
        $min_qty = (int)($sch['min_qty'] ?? 0);

        $target_qty = in_array($type, ['FREE', 'LIFTING_FREE'], true) ? $buy_qty : $min_qty;
        if ($type === 'LIFTING_DISCOUNT') $target_qty = $min_qty;

        if ($quantity < $target_qty) continue;

        $stitle = $sch['scheme_title'] ?? '';
        $discount_pct = 0.0;
        $free_qty_val = 0;
        $credit_days_val = null;

        if ($type === 'FREE' || $type === 'LIFTING_FREE') {
            $free_qty_val = floor($quantity / $buy_qty) * (int)($sch['free_qty'] ?? 0);
            if ($free_qty_val <= 0) continue;
        } elseif ($type === 'DISCOUNT' || $type === 'LIFTING_DISCOUNT' || $type === 'FLAT' || $type === 'CASHBACK') {
            $discount_pct = (float)($sch['discount'] ?? 0);
        } elseif ($type === 'CREDIT') {
            $credit_days_val = (int)($sch['credit_days'] ?? 0);
        } elseif ($type === 'BONUS') {
            $free_qty_val = (int)($sch['free_qty'] ?? 0);
        }

        safe_exec($db, "
            INSERT INTO order_applied_schemes (order_id, product_id, scheme_id, scheme_title, scheme_type, discount_percent, free_qty, credit_days)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$oid, $pid, (int)$sch['id'], $stitle, $type, $discount_pct, $free_qty_val, $credit_days_val]);
    }
}

// ── 6. Parse JSON or Form Input for POST Requests ─────────
$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));

// ── 7. POST ACTIONS (APPROVE, REJECT, DELETE, UPDATE) ─────

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($action)) {

    // 1. APPROVE ORDER
    if ($action === 'approve' || isset($input['approve_order'])) {
        $order_id = (int)($input['order_id'] ?? 0);
        if ($order_id <= 0) fail('Valid order_id is required.', 422);

        $order = safe_one($db, "SELECT id, user_id FROM retailer_orders WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $retailer_id]);
        if (!$order) fail('Order not found or permission denied.', 404);

        $approval_check = canApproveOrder($db, $order_id);
        if (!$approval_check['can_approve']) {
            fail('Cannot approve order due to insufficient stock.', 400, $approval_check['stock_issues']);
        }

        safe_exec($db, "UPDATE retailer_orders SET invoice_status = 'Approved', payment_status = 'Pending', updated_at = NOW() WHERE id = ?", [$order_id]);

        $order_items = safe_query($db, "SELECT product_id, product_quantity FROM retailer_order_details WHERE order_id = ?", [$order_id]);
        foreach ($order_items as $item) {
            safe_exec($db, "
                INSERT INTO stock_logs (product_id, order_id, action, quantity, notes, created_at) 
                VALUES (?, ?, 'APPROVE_FINAL', ?, 'Order approved by retailer - stock status finalized', NOW())
            ", [(int)$item['product_id'], $order_id, (int)$item['product_quantity']]);
        }

        // Notify Distributors
        $distributors = safe_query($db, "SELECT id FROM users WHERE user_type = 'distributor'");
        foreach ($distributors as $d) {
            $msg = "✅ Order #$order_id APPROVED by $retailer_name.";
            safe_exec($db, "INSERT INTO notifications (user_id, type, message, related_id, is_read, created_at) VALUES (?, 'distributor', ?, ?, 0, NOW())", [(int)$d['id'], $msg, $order_id]);
        }

        // Notify Manufacturers
        $manufacturers = safe_query($db, "SELECT id FROM users WHERE user_type = 'manufacturer'");
        foreach ($manufacturers as $m) {
            $msg = "✅ Order #$order_id APPROVED by $retailer_name.";
            safe_exec($db, "INSERT INTO notifications (user_id, type, message, related_id, is_read, created_at) VALUES (?, 'manufacturer', ?, ?, 0, NOW())", [(int)$m['id'], $msg, $order_id]);
        }

        ok([
            'order_id'       => $order_id,
            'invoice_status' => 'Approved',
            'payment_status' => 'Pending'
        ], "Order #$order_id approved successfully.");
    }

    // 2. REJECT ORDER
    if ($action === 'reject' || isset($input['reject_order'])) {
        $order_id = (int)($input['order_id'] ?? 0);
        $reject_reason = trim($input['reject_reason'] ?? 'No reason provided');
        if ($order_id <= 0) fail('Valid order_id is required.', 422);

        $order = safe_one($db, "SELECT id FROM retailer_orders WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $retailer_id]);
        if (!$order) fail('Order not found or permission denied.', 404);

        restoreStockFromOrder($db, $order_id, $reject_reason);
        safe_exec($db, "UPDATE retailer_orders SET invoice_status = 'Rejected', payment_status = 'Cancelled', updated_at = NOW() WHERE id = ?", [$order_id]);
        safe_exec($db, "DELETE FROM order_applied_schemes WHERE order_id = ?", [$order_id]);

        ok(['order_id' => $order_id], "Order #$order_id has been rejected and stock restored.");
    }

    // 3. DELETE ENTIRE ORDER
    if ($action === 'delete' || isset($input['delete_order'])) {
        $order_id = (int)($input['order_id'] ?? 0);
        if ($order_id <= 0) fail('Valid order_id is required.', 422);

        $order = safe_one($db, "SELECT id FROM retailer_orders WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $retailer_id]);
        if (!$order) fail('Order not found or permission denied.', 404);

        restoreStockFromOrder($db, $order_id, 'Order deleted by retailer');
        safe_exec($db, "DELETE FROM order_applied_schemes WHERE order_id = ?", [$order_id]);
        safe_exec($db, "DELETE FROM retailer_order_details WHERE order_id = ?", [$order_id]);
        safe_exec($db, "DELETE FROM retailer_orders WHERE id = ? AND user_id = ?", [$order_id, $retailer_id]);

        ok(['order_id' => $order_id], "Order #$order_id deleted successfully and stock restored.");
    }

    // 4. DELETE SPECIFIC PRODUCT FROM ORDER
    if ($action === 'delete_product' || (isset($input['delete_product']) && $input['delete_product'] == '1')) {
        $order_id  = (int)($input['order_id'] ?? 0);
        $detail_id = (int)($input['detail_id'] ?? 0);
        if ($order_id <= 0 || $detail_id <= 0) fail('order_id and detail_id are required.', 422);

        $order = safe_one($db, "SELECT id FROM retailer_orders WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $retailer_id]);
        if (!$order) fail('Order not found or permission denied.', 404);

        $p_row = safe_one($db, "SELECT product_id, product_quantity FROM retailer_order_details WHERE id = ? AND order_id = ?", [$detail_id, $order_id]);
        if ($p_row) {
            $pid = (int)$p_row['product_id'];
            $qty = (int)$p_row['product_quantity'];

            safe_exec($db, "UPDATE products SET stock = stock + ? WHERE id = ?", [$qty, $pid]);
            safe_exec($db, "INSERT INTO stock_logs (product_id, order_id, action, quantity, notes, created_at) VALUES (?, ?, 'RESTORE_PARTIAL', ?, 'Product removed from order by retailer', NOW())", [$pid, $order_id, $qty]);
            safe_exec($db, "DELETE FROM order_applied_schemes WHERE order_id = ? AND product_id = ?", [$order_id, $pid]);
            safe_exec($db, "DELETE FROM retailer_order_details WHERE order_id = ? AND product_id = ? AND product_price = 0 AND product_name LIKE '%[FREE%'", [$order_id, $pid]);
            safe_exec($db, "DELETE FROM retailer_order_details WHERE id = ?", [$detail_id]);

            $total_row = safe_one($db, "SELECT SUM(product_totalprice) AS new_total FROM retailer_order_details WHERE order_id = ?", [$order_id]);
            $new_total = (float)($total_row['new_total'] ?? 0);
            safe_exec($db, "UPDATE retailer_orders SET total = ? WHERE id = ?", [$new_total, $order_id]);

            ok(['order_id' => $order_id, 'detail_id' => $detail_id, 'new_total' => $new_total], "Product removed from order and stock restored.");
        }
        fail('Product item not found in order.', 404);
    }

    // 5. UPDATE QUANTITIES (SAVE CHANGES)
    if ($action === 'save_changes' || $action === 'update_quantities' || isset($input['save_changes'])) {
        $order_id = (int)($input['order_id'] ?? 0);
        $qtyInput = $input['qty'] ?? ($input['items'] ?? []);
        if ($order_id <= 0) fail('Valid order_id is required.', 422);

        $order = safe_one($db, "SELECT id FROM retailer_orders WHERE id = ? AND user_id = ? LIMIT 1", [$order_id, $retailer_id]);
        if (!$order) fail('Order not found or permission denied.', 404);

        $subtotal = 0.0;
        $stock_errors = [];

        $normalizedQty = [];
        if (is_array($qtyInput)) {
            foreach ($qtyInput as $k => $v) {
                if (is_array($v) && isset($v['detail_id'])) {
                    $normalizedQty[(int)$v['detail_id']] = max(1, (int)($v['quantity'] ?? 1));
                } else {
                    $normalizedQty[(int)$k] = max(1, (int)$v);
                }
            }
        }

        foreach ($normalizedQty as $detail_id => $new_quantity) {
            $row = safe_one($db, "
                SELECT rod.*, p.stock as current_stock, p.name as product_name
                FROM retailer_order_details rod 
                JOIN products p ON rod.product_id = p.id 
                WHERE rod.id = ? AND rod.order_id = ?
            ", [$detail_id, $order_id]);

            if (!$row) continue;

            if ((float)$row['product_price'] == 0 && strpos($row['product_name'], '[FREE') !== false) {
                continue;
            }

            $pid = (int)$row['product_id'];
            $price = (float)$row['product_price'];
            $old_quantity = (int)$row['product_quantity'];
            $current_db_stock = (int)$row['current_stock'];
            $max_allowed = $old_quantity + $current_db_stock;

            if ($new_quantity > $max_allowed) {
                $stock_errors[] = "{$row['product_name']}: Cannot increase to $new_quantity. Max possible is $max_allowed.";
                $new_quantity = $max_allowed;
            }

            $line_total = $new_quantity * $price;
            $subtotal += $line_total;

            safe_exec($db, "UPDATE retailer_order_details SET product_quantity = ?, product_totalprice = ? WHERE id = ?", [$new_quantity, $line_total, $detail_id]);

            if ($new_quantity !== $old_quantity) {
                $diff = $new_quantity - $old_quantity;
                if ($diff > 0) {
                    safe_exec($db, "UPDATE products SET stock = stock - ? WHERE id = ?", [$diff, $pid]);
                    safe_exec($db, "INSERT INTO stock_logs (product_id, order_id, action, quantity, notes, created_at) VALUES (?, ?, 'DRAFT_ADJUST_ADD', ?, 'Draft quantity adjusted by retailer', NOW())", [$pid, $order_id, $diff]);
                } else {
                    $restore_qty = abs($diff);
                    safe_exec($db, "UPDATE products SET stock = stock + ? WHERE id = ?", [$restore_qty, $pid]);
                    safe_exec($db, "INSERT INTO stock_logs (product_id, order_id, action, quantity, notes, created_at) VALUES (?, ?, 'DRAFT_ADJUST_REDUCE', ?, 'Draft quantity adjusted by retailer', NOW())", [$pid, $order_id, $restore_qty]);
                }
            }

            updateFreeProductQuantity($db, $order_id, $pid, $new_quantity, $retailer_id, $retailer_category);
            recheckAndApplySchemes($db, $order_id, $pid, $new_quantity, $retailer_id, $retailer_category);
        }

        safe_exec($db, "UPDATE retailer_orders SET total = ? WHERE id = ?", [$subtotal, $order_id]);

        ok([
            'order_id'     => $order_id,
            'subtotal'     => $subtotal,
            'stock_errors' => $stock_errors
        ], empty($stock_errors) ? 'Quantity & schemes updated successfully.' : 'Some items capped to maximum stock.');
    }

    // 6. REAL-TIME ITEM PREVIEW
    if ($action === 'preview_item') {
        $detail_id    = (int)($input['detail_id'] ?? 0);
        $new_quantity = (int)($input['quantity'] ?? 1);
        $order_id     = (int)($input['order_id'] ?? 0);

        $prod = safe_one($db, "
            SELECT rod.*, p.stock as current_stock 
            FROM retailer_order_details rod 
            JOIN products p ON rod.product_id = p.id 
            WHERE rod.id = ? AND rod.order_id = ?
        ", [$detail_id, $order_id]);

        if (!$prod) fail('Product detail not found.', 404);

        $price         = (float)$prod['product_price'];
        $product_id    = (int)$prod['product_id'];
        $old_quantity  = (int)$prod['product_quantity'];
        $current_stock = (int)$prod['current_stock'];
        $max_allowed   = $current_stock + $old_quantity;

        if ($new_quantity > $max_allowed) {
            $new_quantity = $max_allowed;
        }

        $line_total = $new_quantity * $price;
        $benefits   = calculateSchemeBenefits($db, $product_id, $new_quantity, $price, $retailer_category, $retailer_id);
        $schemes    = getApplicableSchemesWithMessages($db, $product_id, $new_quantity, $price, $retailer_category, $retailer_id);

        ok([
            'detail_id'         => $detail_id,
            'quantity'          => $new_quantity,
            'line_total'        => $line_total,
            'max_allowed'       => $max_allowed,
            'available_stock'   => $current_stock,
            'expected_free_qty' => $benefits['free_qty'] ?? 0,
            'benefits'          => $benefits,
            'schemes'           => $schemes
        ], 'Item scheme calculations updated.');
    }
}

// ── 8. GET ACTION: LIST DRAFTS & APPROVED ORDERS HISTORY ──

// Fetch Draft Orders
$draft_orders_rows = safe_query($db, "
    SELECT ro.*, u.name as salesman_name 
    FROM retailer_orders ro 
    LEFT JOIN users u ON ro.salesman_id = u.id 
    WHERE ro.user_id = ? AND ro.invoice_status = 'Draft' 
    ORDER BY ro.created_at DESC
", [$retailer_id]);

$draft_orders = [];

foreach ($draft_orders_rows as $order) {
    $oid = (int)$order['id'];

    $items_res = safe_query($db, "
        SELECT rod.*, p.stock as current_stock 
        FROM retailer_order_details rod 
        JOIN products p ON rod.product_id = p.id 
        WHERE rod.order_id = ?
        ORDER BY rod.product_price DESC, rod.id ASC
    ", [$oid]);

    $gross_total          = 0.0;
    $total_items          = 0;
    $has_low_stock        = false;
    $has_out_of_stock     = false;
    $low_stock_products   = [];
    $out_of_stock_products= [];
    $items                = [];

    $order_summary = [
        'total_price'      => 0.0,
        'free_savings'     => 0.0,
        'discount_savings' => 0.0,
        'flat_savings'     => 0.0,
        'cashback_savings' => 0.0,
        'bonus_savings'    => 0.0,
        'total_savings'    => 0.0,
        'net_payable'      => 0.0,
        'credit_days'      => ($credit_duration > 0) ? $credit_duration : 0
    ];

    foreach ($items_res as $item) {
        $price = (float)$item['product_price'];
        $qty   = (int)$item['product_quantity'];
        $pid   = (int)$item['product_id'];
        $is_free_product = ($price == 0 && strpos($item['product_name'], '[FREE') !== false);

        if (!$is_free_product) {
            $line_total = $qty * $price;
            $order_summary['total_price'] += $line_total;
            $gross_total += $line_total;

            $benefits = calculateSchemeBenefits($db, $pid, $qty, $price, $retailer_category, $retailer_id);
            $order_summary['free_savings']     += $benefits['free_qty'] * $price;
            $order_summary['discount_savings'] += ($line_total * ($benefits['discount_percent'] / 100));
            $order_summary['flat_savings']     += $benefits['flat_discount_saving'];
            $order_summary['cashback_savings'] += $benefits['cashback_amount'];
            $order_summary['bonus_savings']    += $benefits['bonus_qty'] * $price;
            $order_summary['credit_days']       = max($order_summary['credit_days'], $benefits['credit_days']);
        }

        $total_items += $qty;
        $remaining_stock = (int)$item['current_stock'];

        if ($is_free_product) {
            $stock_class = 'in_stock';
            $stock_label = 'Free Item';
        } elseif ($remaining_stock < 0) {
            $stock_class = 'insufficient';
            $stock_label = 'Insufficient';
            $has_out_of_stock = true;
            $out_of_stock_products[] = [
                'name'      => $item['product_name'],
                'ordered'   => $qty,
                'available' => $remaining_stock + $qty
            ];
        } elseif ($remaining_stock < 5) {
            $stock_class = 'low_stock';
            $stock_label = 'Low Stock';
            $has_low_stock = true;
            $low_stock_products[] = [
                'name'      => $item['product_name'],
                'ordered'   => $qty,
                'available' => $remaining_stock
            ];
        } else {
            $stock_class = 'in_stock';
            $stock_label = 'In Stock';
        }

        $schemes = !$is_free_product ? getApplicableSchemesWithMessages($db, $pid, $qty, $price, $retailer_category, $retailer_id) : ['applied' => [], 'unlockable' => []];

        $items[] = [
            'id'                  => (int)$item['id'],
            'product_id'          => $pid,
            'product_name'        => (string)$item['product_name'],
            'product_price'       => $price,
            'product_quantity'    => $qty,
            'product_totalprice'  => (float)$item['product_totalprice'],
            'available_stock'     => $remaining_stock,
            'max_allowed'         => $qty + $remaining_stock,
            'stock_class'         => $stock_class,
            'stock_label'         => $stock_label,
            'is_free'             => $is_free_product,
            'schemes'             => $schemes
        ];
    }

    $order_summary['total_savings'] = $order_summary['free_savings'] + $order_summary['discount_savings'] + $order_summary['flat_savings'] + $order_summary['cashback_savings'] + $order_summary['bonus_savings'];
    $order_summary['net_payable']   = max(0.0, $order_summary['total_price'] - $order_summary['total_savings']);

    $approval_check = canApproveOrder($db, $oid);

    $draft_orders[] = [
        'order_id'              => $oid,
        'order_number'          => "#ORD-$oid",
        'salesman_name'         => (string)($order['salesman_name'] ?? 'Self Order'),
        'total'                 => (float)($order['total'] ?? 0),
        'invoice_status'        => 'Draft',
        'created_at'            => $order['created_at'] ?? '',
        'formatted_date'        => $safeDate($order['created_at'] ?? null),
        'can_approve'           => $approval_check['can_approve'],
        'has_out_of_stock'      => $has_out_of_stock,
        'has_low_stock'         => $has_low_stock,
        'out_of_stock_products' => $out_of_stock_products,
        'low_stock_products'    => $low_stock_products,
        'total_items'           => $total_items,
        'items'                 => $items,
        'summary'               => $order_summary
    ];
}

// Fetch Approved Order History with Pagination
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 10;
$offset = ($page - 1) * $limit;

$histCountRow  = safe_one($db, "SELECT COUNT(*) as total FROM retailer_orders WHERE user_id = ? AND invoice_status = 'Approved'", [$retailer_id]);
$total_history = (int)($histCountRow['total'] ?? 0);
$total_pages   = $total_history > 0 ? (int)ceil($total_history / $limit) : 1;

$history_rows = safe_query($db, "
    SELECT id, invoice_number, total, payment_status, created_at, updated_at
    FROM retailer_orders 
    WHERE user_id = ? AND invoice_status = 'Approved' 
    ORDER BY updated_at DESC 
    LIMIT $offset, $limit
", [$retailer_id]);

$history_orders = [];
foreach ($history_rows as $ord) {
    $oid = (int)$ord['id'];
    $history_orders[] = [
        'order_id'       => $oid,
        'order_number'   => !empty($ord['invoice_number']) ? $ord['invoice_number'] : "#ORD-$oid",
        'total'          => (float)($ord['total'] ?? 0),
        'payment_status' => (string)($ord['payment_status'] ?? 'Pending'),
        'created_at'     => $ord['created_at'] ?? '',
        'formatted_date' => $safeDate($ord['created_at'] ?? null) ?? date('d M, Y')
    ];
}

// ── 9. Return Unified JSON Response ───────────────────────
ok([
    'retailer' => [
        'id'              => $retailer_id,
        'name'            => $retailer_name,
        'category'        => $retailer_category,
        'credit_duration' => $credit_duration
    ],
    'draft_count'    => count($draft_orders),
    'draft_orders'   => $draft_orders,
    'history_orders' => $history_orders,
    'pagination'     => [
        'current_page'   => $page,
        'limit'          => $limit,
        'total_history'  => $total_history,
        'total_pages'    => $total_pages,
        'has_next_page'  => ($page < $total_pages),
        'has_prev_page'  => ($page > 1)
    ]
], 'Review orders loaded successfully.');