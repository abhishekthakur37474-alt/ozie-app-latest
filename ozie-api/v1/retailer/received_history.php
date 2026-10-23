<?php
/**
 * Ozie API — Retailer Received History (Delivered Orders)
 * Endpoint: GET /v1/retailer/received-history
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Helpers ────────────────────────────────────
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
if (!$db) {
    $siteRootDb = dirname(__DIR__, 3) . '/db.php';
    if (file_exists($siteRootDb)) {
        @include_once $siteRootDb;
        $db = $conn ?? ($con ?? null);
    }
}

// ── 2. Universal Query Helpers ─────────────────────────────
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
            error_log("[ReceivedHistory Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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

// ── 3. Authentication & Scope ──────────────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$userId   = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($userId <= 0) {
    fail('Authentication required.', 401);
}

// ── 4. Query Received Orders ──────────────────────────────
$search = trim($_GET['search'] ?? ($_GET['term'] ?? ''));
$limit  = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$where_clauses = ["ro.dispatch_status = 'Received'"];
$params = [];

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

$where_sql = "WHERE " . implode(' AND ', $where_clauses);

$countRow = safe_one($db, "SELECT COUNT(*) as total FROM retailer_orders ro $where_sql", $params);
$total_orders = (int)($countRow['total'] ?? 0);
$total_pages  = $total_orders > 0 ? (int)ceil($total_orders / $limit) : 1;

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com';
$siteBaseUrl = $protocol . $host . '/';

$orders = safe_query($db, "
    SELECT ro.*, i.invoice_number as inv_no, i.pdf_path, ro.total as order_total, ro.received_at
    FROM retailer_orders ro
    LEFT JOIN invoices i ON ro.id = i.order_id
    $where_sql
    ORDER BY ro.received_at DESC, ro.id DESC
    LIMIT $offset, $limit
", $params);

$formattedOrders = [];
foreach ($orders as $row) {
    $oid = (int)$row['id'];
    $inv_disp = !empty($row['inv_no']) ? $row['inv_no'] : (!empty($row['invoice_number']) ? $row['invoice_number'] : "ORD-$oid");

    $formattedOrders[] = [
        'order_id'       => $oid,
        'invoice_number' => $inv_disp,
        'customer_name'  => (string)($row['shipping_name'] ?? 'Retailer'),
        'city'           => (string)($row['shipping_city'] ?? 'N/A'),
        'total_amount'   => (float)($row['order_total'] ?? ($row['total'] ?? 0.0)),
        'payment_method' => (string)($row['payment_method'] ?? 'N/A'),
        'received_date'  => $safeDate($row['received_at'] ?? null),
        'dispatched_date'=> $safeDate($row['dispatched_at'] ?? null),
        'order_date'     => $safeDate($row['created_at'] ?? null),
        'status'         => 'Received',
        'tracking'       => [
            'carrier'     => $row['carrier'] ?? 'N/A',
            'tracking_no' => $row['tracking_no'] ?? 'N/A',
            'warehouse'   => $row['warehouse'] ?? 'N/A'
        ],
        'invoice_pdf_url'=> !empty($row['pdf_path']) ? ($siteBaseUrl . ltrim($row['pdf_path'], '/')) : null
    ];
}

ok([
    'total_received_orders' => $total_orders,
    'pagination' => [
        'current_page' => $page,
        'limit'        => $limit,
        'total_pages'  => $total_pages,
        'has_next'     => ($page < $total_pages),
        'has_prev'     => ($page > 1)
    ],
    'orders' => $formattedOrders
], 'Received orders history loaded successfully.');