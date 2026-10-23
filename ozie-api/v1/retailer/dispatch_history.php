<?php
/**
 * Ozie API — Retailer Dispatch History & Delivery Confirmation
 * Endpoint:
 *   GET  /v1/retailer/dispatch-history (List regular dispatched orders or paper schemes)
 *   POST /v1/retailer/dispatch-history (Confirm delivery / Mark as received)
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Compatibility Helpers ──────────────────────
if (!function_exists('ok')) {
    function ok($data = null, $message = 'OK', $code = 200) {
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

if (!function_exists('fail')) {
    function fail($message = 'Error', $code = 400, $errors = null) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
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
                    $rep = is_numeric($val) ? (string)$val : (is_null($val) ? "NULL" : "'" . mysqli_real_escape_string($db, (string)$val) . "'");
                    $pos = strpos($escapedSql, '?');
                    if ($pos !== false) $escapedSql = substr_replace($escapedSql, $rep, $pos, 1);
                }
                $res = $db->query($escapedSql);
                return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
            }
        } catch (\Throwable $e) {
            error_log("[DispatchHistory Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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
            error_log("[DispatchHistory Exec Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return false;
    }
}

// ── 3. Authentication & Role Scoping ──────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$userId   = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($userId <= 0) {
    fail('Authentication required.', 401);
}

if ($userType !== 'retailer' && $userType !== 'salesman' && $userType !== 'distributor') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $userId = (int)$checkRet['user_id'];
        }
    }
}

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

// ── 4. POST: MARK ORDER AS RECEIVED (DELIVERY CONFIRM) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    $order_id = (int)($input['order_id'] ?? 0);
    $condition_accepted = !empty($input['condition_accepted']);

    if ($order_id <= 0) {
        fail('Valid order_id is required.', 422);
    }
    if (!$condition_accepted) {
        fail('Please confirm that goods were received in good condition.', 422);
    }

    if ($userType === 'distributor') {
        $order = safe_one($db, "SELECT id FROM manufacturer_orders WHERE id = ? AND distributor_id = ? LIMIT 1", [$order_id, $userId]);
        if (!$order) fail('Order not found or access denied.', 404);

        safe_exec($db, "UPDATE manufacturer_orders SET order_status = 'Received', updated_at = NOW() WHERE id = ?", [$order_id]);
    } else {
        $order = safe_one($db, "SELECT id FROM retailer_orders WHERE id = ? AND (user_id = ? OR salesman_id = ?) LIMIT 1", [$order_id, $userId, $userId]);
        if (!$order) fail('Order not found or access denied.', 404);

        safe_exec($db, "UPDATE retailer_orders SET dispatch_status = 'Received', received_at = NOW(), updated_at = NOW() WHERE id = ?", [$order_id]);
    }

    ok([
        'order_id'        => $order_id,
        'dispatch_status' => 'Received',
        'received_at'     => date('c')
    ], 'Order marked as received successfully.');
}

// ── 5. GET: LIST DISPATCHED ORDERS & SCHEMES ───────────────
$tab = trim($_GET['tab'] ?? 'orders'); // 'orders' or 'schemes'
$search = trim($_GET['search'] ?? ($_GET['term'] ?? ''));

$limit  = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// GLOBAL COUNTS
$total_orders_global  = 0;
$total_schemes_global = 0;

if ($userType === 'distributor') {
    $glob = safe_one($db, "SELECT COUNT(*) as t FROM manufacturer_orders WHERE distributor_id = ? AND (order_status = 'Dispatched' OR order_status = 'Received')", [$userId]);
    $total_orders_global = (int)($glob['t'] ?? 0);
} else {
    $wh = ($userType === 'salesman') ? "salesman_id = ?" : "user_id = ?";
    $glob1 = safe_one($db, "SELECT COUNT(*) as t FROM retailer_orders WHERE dispatch_status IN ('Dispatched', 'Received') AND $wh", [$userId]);
    $total_orders_global = (int)($glob1['t'] ?? 0);

    $glob2 = safe_one($db, "
        SELECT COUNT(*) as t 
        FROM scheme_celebrations sc
        JOIN retailer_orders o ON sc.order_id = o.id
        WHERE sc.status = 'completed' AND o.dispatch_status IN ('Dispatched', 'Received') AND o.$wh
    ", [$userId]);
    $total_schemes_global = (int)($glob2['t'] ?? 0);
}

// TAB 1: REGULAR ORDERS
if ($tab === 'orders' || $userType === 'distributor') {
    $where_clauses = [];
    $params = [];

    if ($userType === 'distributor') {
        $where_clauses[] = "mo.distributor_id = ?";
        $where_clauses[] = "(mo.order_status = 'Dispatched' OR mo.order_status = 'Received')";
        $params[] = $userId;

        if ($search !== '') {
            $where_clauses[] = "(mo.invoice_number LIKE ? OR mo.order_number LIKE ? OR mo.shipping_name LIKE ? OR mo.shipping_city LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $where = "WHERE " . implode(' AND ', $where_clauses);
        $countRow = safe_one($db, "SELECT COUNT(*) as total FROM manufacturer_orders mo $where", $params);
        $total_items = (int)($countRow['total'] ?? 0);

        $orders = safe_query($db, "
            SELECT mo.id, mo.order_number, mo.invoice_number, mo.total_amount AS total, 
                   COALESCE(mo.payment_status, 'Paid') AS payment_status, mo.payment_method,
                   mo.created_at, mo.order_status AS dispatch_status, mo.shipping_name, mo.shipping_city,
                   mo.shipping_address, mo.shipping_state, mo.shipping_pincode,
                   mo.bill_address, mo.bill_state, mo.bill_pincode, mo.notes as tracking_info,
                   i.pdf_path
            FROM manufacturer_orders mo
            LEFT JOIN invoices i ON mo.id = i.order_id
            $where
            ORDER BY mo.created_at DESC
            LIMIT $offset, $limit
        ", $params);

    } else {
        $where_clauses[] = "ro.dispatch_status IN ('Dispatched', 'Received')";
        if ($userType === 'salesman') {
            $where_clauses[] = "ro.salesman_id = ?";
            $params[] = $userId;
        } else {
            $where_clauses[] = "ro.user_id = ?";
            $params[] = $userId;
        }

        if ($search !== '') {
            $where_clauses[] = "(ro.invoice_number LIKE ? OR ro.shipping_name LIKE ? OR ro.shipping_city LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $where = "WHERE " . implode(' AND ', $where_clauses);
        $countRow = safe_one($db, "SELECT COUNT(*) as total FROM retailer_orders ro $where", $params);
        $total_items = (int)($countRow['total'] ?? 0);

        $orders = safe_query($db, "
            SELECT ro.*, 
                   i.invoice_number as real_inv,
                   i.payment_status,
                   i.payment_method,
                   i.created_at AS invoice_date,
                   i.pdf_path
            FROM retailer_orders ro
            LEFT JOIN invoices i ON ro.id = i.order_id
            $where
            ORDER BY ro.dispatched_at DESC, ro.id DESC
            LIMIT $offset, $limit
        ", $params);
    }

    $formattedList = [];
    foreach ($orders as $row) {
        $oid = (int)$row['id'];
        $is_received = (strcasecmp($row['dispatch_status'] ?? '', 'Received') === 0);
        $inv_disp = !empty($row['invoice_number']) ? $row['invoice_number'] : (!empty($row['order_number']) ? $row['order_number'] : ("ORD-" . $oid));

        $formattedList[] = [
            'order_id'        => $oid,
            'type'            => 'order',
            'invoice_number'  => $inv_disp,
            'customer_name'   => (string)($row['shipping_name'] ?? 'Retailer'),
            'city'            => (string)($row['shipping_city'] ?? 'N/A'),
            'total_amount'    => (float)($row['total'] ?? 0.0),
            'payment_status'  => (string)($row['payment_status'] ?? 'Paid'),
            'dispatch_date'   => $safeDate($row['dispatched_at'] ?? ($row['created_at'] ?? null)),
            'dispatch_status' => $is_received ? 'Received' : 'Dispatched',
            'is_received'     => $is_received,
            'can_receive'     => !$is_received,
            'invoice_pdf_url' => !empty($row['pdf_path']) ? $resolveMediaUrl($row['pdf_path']) : null,
            'tracking_info'   => $row['tracking_info'] ?? ($row['tracking_no'] ?? null)
        ];
    }

    $total_pages = $total_items > 0 ? (int)ceil($total_items / $limit) : 1;

    ok([
        'tab'                  => 'orders',
        'total_orders_global'  => $total_orders_global,
        'total_schemes_global' => $total_schemes_global,
        'pagination' => [
            'current_page' => $page,
            'limit'        => $limit,
            'total_items'  => $total_items,
            'total_pages'  => $total_pages,
            'has_next'     => ($page < $total_pages),
            'has_prev'     => ($page > 1)
        ],
        'items' => $formattedList
    ], 'Dispatched orders loaded.');

// TAB 2: PAPER SCHEMES
} else {
    $where_clauses = [
        "sc.status = 'completed'",
        "o.dispatch_status IN ('Dispatched', 'Received')"
    ];
    $params = [];

    if ($userType === 'salesman') {
        $where_clauses[] = "o.salesman_id = ?";
        $params[] = $userId;
    } else {
        $where_clauses[] = "o.user_id = ?";
        $params[] = $userId;
    }

    if ($search !== '') {
        $where_clauses[] = "(o.invoice_number LIKE ? OR r.name LIKE ? OR r.city LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $where = "WHERE " . implode(' AND ', $where_clauses);

    $countRow = safe_one($db, "
        SELECT COUNT(*) as total 
        FROM scheme_celebrations sc
        JOIN retailer r ON sc.user_id = r.user_id
        LEFT JOIN retailer_orders o ON sc.order_id = o.id
        $where
    ", $params);
    $total_items = (int)($countRow['total'] ?? 0);
    $total_pages = $total_items > 0 ? (int)ceil($total_items / $limit) : 1;

    $schemes = safe_query($db, "
        SELECT sc.*, 
               o.invoice_number,
               o.dispatch_status,
               o.dispatched_at,
               i.pdf_path,
               r.name as retailer_name, 
               r.city as shipping_city,
               JSON_LENGTH(sc.scheme_data) as gift_count
        FROM scheme_celebrations sc
        JOIN retailer r ON sc.user_id = r.user_id
        LEFT JOIN retailer_orders o ON sc.order_id = o.id
        LEFT JOIN invoices i ON o.id = i.order_id
        $where
        ORDER BY o.dispatched_at DESC
        LIMIT $offset, $limit
    ", $params);

    $formattedList = [];
    foreach ($schemes as $s_row) {
        $oid = (int)$s_row['order_id'];
        $is_received = (strcasecmp($s_row['dispatch_status'] ?? '', 'Received') === 0);
        $inv_disp = !empty($s_row['invoice_number']) ? $s_row['invoice_number'] : ("ORD-" . $oid);

        $formattedList[] = [
            'order_id'        => $oid,
            'type'            => 'scheme',
            'invoice_number'  => $inv_disp,
            'customer_name'   => (string)($s_row['retailer_name'] ?? 'Retailer'),
            'city'            => (string)($s_row['shipping_city'] ?? 'N/A'),
            'total_amount'    => (float)($s_row['order_total'] ?? 0.0),
            'gift_count'      => (int)($s_row['gift_count'] ?? 1),
            'dispatch_date'   => $safeDate($s_row['dispatched_at'] ?? null),
            'dispatch_status' => $is_received ? 'Received' : 'Dispatched',
            'is_received'     => $is_received,
            'can_receive'     => !$is_received,
            'invoice_pdf_url' => !empty($s_row['pdf_path']) ? $resolveMediaUrl($s_row['pdf_path']) : null
        ];
    }

    ok([
        'tab'                  => 'schemes',
        'total_orders_global'  => $total_orders_global,
        'total_schemes_global' => $total_schemes_global,
        'pagination' => [
            'current_page' => $page,
            'limit'        => $limit,
            'total_items'  => $total_items,
            'total_pages'  => $total_pages,
            'has_next'     => ($page < $total_pages),
            'has_prev'     => ($page > 1)
        ],
        'items' => $formattedList
    ], 'Dispatched schemes loaded.');
}