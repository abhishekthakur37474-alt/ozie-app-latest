<?php
/**
 * Ozie API — Retailer Packing Orders (Under Processing)
 * Endpoint: GET /v1/retailer/packing-orders
 * 
 * Supports:
 *  - Retailer outlet & Salesman scoping
 *  - Orders under packing & packed status
 *  - Search by invoice, name, city
 *  - Pagination and tab counts
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
            error_log("[PackingOrders Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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

$safeDate = function ($dateStr, $format = 'd M Y, h:i A'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

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

if ($userType !== 'retailer' && $userType !== 'salesman' && $userType !== 'manufacturer' && $userType !== 'distributor') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $userId = (int)$checkRet['user_id'];
        }
    }
}

$is_manufacturer = in_array($userType, ['manufacturer', 'manufacture'], true);
$tab = trim($_GET['tab'] ?? ($is_manufacturer ? 'distributor' : 'retailer'));

// ── 4. Build Scope & WHERE Clauses ────────────────────────
$where_clauses = [
    "ro.invoice_status = 'Approved'",
    "(ro.dispatch_status IS NULL OR ro.dispatch_status != 'Dispatched')",
    "(i.payment_status = 'Paid' OR i.payment_method = 'Cash on Delivery')"
];
$params = [];

if ($userType === 'retailer') {
    $where_clauses[] = "ro.user_id = ?";
    $params[] = $userId;
} elseif ($userType === 'salesman') {
    $where_clauses[] = "ro.salesman_id = ?";
    $params[] = $userId;
}

// Search
$search = trim($_GET['search'] ?? ($_GET['term'] ?? ''));
if ($search !== '') {
    $where_clauses[] = "(ro.invoice_number LIKE ? OR ro.shipping_name LIKE ? OR ro.shipping_city LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = "WHERE " . implode(' AND ', $where_clauses);

// ── 5. Total Count & Pagination ────────────────────────────
$limit  = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$countRow = safe_one($db, "
    SELECT COUNT(*) AS total 
    FROM retailer_orders ro
    INNER JOIN invoices i ON ro.id = i.order_id
    $where_sql
", $params);

$total_orders = (int)($countRow['total'] ?? 0);
$total_pages  = $total_orders > 0 ? (int)ceil($total_orders / $limit) : 1;

// ── 6. Fetch Orders List ───────────────────────────────────
$orders_sql = "
    SELECT ro.*, 
           COALESCE(ro.shipping_name, 'Retailer') as shipping_name,
           ro.shipping_city,
           'Retailer' as entity_type,
           i.invoice_number as inv_num, 
           i.payment_status as inv_payment_status,
           i.payment_method as inv_payment_method,
           i.id as invoice_id
    FROM retailer_orders ro
    INNER JOIN invoices i ON ro.id = i.order_id
    $where_sql
    ORDER BY ro.created_at DESC
    LIMIT $offset, $limit
";
$orders_res = safe_query($db, $orders_sql, $params);

$ordersList = [];
foreach ($orders_res as $row) {
    $oid = (int)$row['id'];
    $is_packed = (($row['packing_status'] ?? '') === 'Packed');
    $is_cod = (($row['inv_payment_method'] ?? '') === 'Cash on Delivery');
    $inv_disp = !empty($row['invoice_number']) ? $row['invoice_number'] : (!empty($row['inv_num']) ? $row['inv_num'] : ("ORD-" . $oid));

    $ordersList[] = [
        'order_id'          => $oid,
        'invoice_number'    => $inv_disp,
        'customer_name'     => (string)($row['shipping_name'] ?? 'Retailer'),
        'city'              => (string)($row['shipping_city'] ?? 'N/A'),
        'total_amount'      => (float)($row['total'] ?? 0.0),
        'payment_status'    => (string)($row['inv_payment_status'] ?? ($row['payment_status'] ?? 'Paid')),
        'payment_method'    => (string)($row['inv_payment_method'] ?? ($row['payment_method'] ?? 'Online')),
        'is_cod'            => $is_cod,
        'is_packed'         => $is_packed,
        'packing_status'    => $is_packed ? 'Packed & Ready' : 'Under Packing',
        'progress_step'     => $is_packed ? 2 : 1, // 1: Under Packing, 2: Packed, 3: Dispatched
        'created_at'        => $row['created_at'] ?? '',
        'formatted_date'    => $safeDate($row['created_at'] ?? null) ?? date('d M Y'),
        'shipping_details'  => [
            'name'          => $row['shipping_name'] ?? '',
            'address'       => $row['shipping_address'] ?? 'N/A',
            'city'          => $row['shipping_city'] ?? '',
            'state'         => $row['shipping_state'] ?? '',
            'pincode'       => $row['shipping_pincode'] ?? '',
            'contact'       => $row['shipping_contact'] ?? ($row['shipping_phone'] ?? 'N/A')
        ],
        'logistics'         => [
            'warehouse'     => $row['warehouse'] ?? 'Main Depot',
            'carrier'       => $row['carrier'] ?? 'Assigned on Dispatch',
            'tracking_no'   => $row['tracking_no'] ?? 'Generated on dispatch'
        ]
    ];
}

ok([
    'total_orders' => $total_orders,
    'pagination'   => [
        'current_page' => $page,
        'limit'        => $limit,
        'total_items'  => $total_orders,
        'total_pages'  => $total_pages,
        'has_next'     => ($page < $total_pages),
        'has_prev'     => ($page > 1)
    ],
    'orders'       => $ordersList
], 'Packing orders loaded successfully.');