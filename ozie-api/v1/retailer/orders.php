<?php
/**
 * Ozie API — Retailer Approved Orders (View Orders)
 * Endpoint: GET /v1/retailer/orders
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Compatibility Helper ───────────────────────
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
            error_log("[Orders Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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

// ── 3. Safe Date Formatter ─────────────────────────────────
$safeDate = function ($dateStr, $format = 'd M Y, h:i A'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

// ── 4. Authentication & Role Handling ─────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$user_id = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
if ($user_id <= 0) {
    fail('Invalid authenticated user identifier.', 401);
}

$user_type = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? 'retailer'));

if ($user_type !== 'salesman' && $user_type !== 'retailer') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$user_id, $user_id]);
    if (!empty($checkRet)) {
        $user_type = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $user_id = (int)$checkRet['user_id'];
        }
    }
}

// ── 5. Build Filters & Dynamic WHERE Clause ───────────────
$where_clauses = [];
$params = [];

if ($user_type === 'salesman') {
    $where_clauses[] = "salesman_id = ?";
    $params[] = $user_id;
} else {
    $where_clauses[] = "user_id = ?";
    $params[] = $user_id;
}

$where_clauses[] = "invoice_status = 'Approved'";

// Search Filter
$search = trim($_GET['search'] ?? ($_GET['term'] ?? ($_GET['q'] ?? '')));
if ($search !== '') {
    $where_clauses[] = "(invoice_number LIKE ? OR shipping_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Date Range Filters
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');

if ($start_date !== '') {
    $where_clauses[] = "DATE(created_at) >= ?";
    $params[] = $start_date;
}
if ($end_date !== '') {
    $where_clauses[] = "DATE(created_at) <= ?";
    $params[] = $end_date;
}

$where_sql = implode(' AND ', $where_clauses);
$has_active_filters = (!empty($search) || !empty($start_date) || !empty($end_date));

// ── 6. Summary & Count Totals Query ───────────────────────
$summarySql = "SELECT COUNT(*) as total_items, COALESCE(SUM(total), 0) as total_amount FROM retailer_orders WHERE $where_sql";
$summaryRow = safe_one($db, $summarySql, $params) ?: [];

$total_items  = (int)($summaryRow['total_items'] ?? 0);
$total_amount = (float)($summaryRow['total_amount'] ?? 0.0);

// ── 7. Pagination Logic ────────────────────────────────────
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 15;
$page  = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$total_pages = $total_items > 0 ? (int)ceil($total_items / $limit) : 1;

// ── 8. Fetch Paginated Orders ──────────────────────────────
$ordersSql = "SELECT * FROM retailer_orders WHERE $where_sql ORDER BY created_at DESC LIMIT $offset, $limit";
$ordersList = safe_query($db, $ordersSql, $params);

$orders = [];
foreach ($ordersList as $row) {
    $oid            = (int)$row['id'];
    $payment_status = $row['payment_status'] ?? 'Pending';
    $invoice_num    = !empty($row['invoice_number']) ? $row['invoice_number'] : ('ORD-' . $oid);
    $shipping_name  = !empty($row['shipping_name']) ? trim($row['shipping_name']) : 'N/A';

    if ($payment_status === 'Paid') {
        $payment_badge = 'Paid';
        $payment_color = 'success';
    } elseif ($payment_status === 'Partially Paid' || $payment_status === 'Partial Paid') {
        $payment_badge = 'Partial';
        $payment_color = 'primary';
    } else {
        $payment_badge = 'Pending';
        $payment_color = 'warning';
    }

    $orders[] = [
        'id'             => $oid,
        'order_number'   => $invoice_num,
        'invoice_number' => $row['invoice_number'] ?? null,
        'shipping_name'  => $shipping_name,
        'total'          => (float)($row['total'] ?? 0.0),
        'invoice_status' => 'Approved',
        'payment_status' => $payment_status,
        'payment_badge'  => $payment_badge,
        'payment_color'  => $payment_color,
        'created_at'     => $row['created_at'] ?? '',
        'formatted_date' => $safeDate($row['created_at'] ?? null) ?? date('d M Y, h:i A')
    ];
}

// ── 9. Return Unified JSON Response (Using native ok()) ───
ok([
    'summary' => [
        'total_orders' => $total_items,
        'total_amount' => $total_amount
    ],
    'filters' => [
        'search'             => $search,
        'start_date'         => $start_date,
        'end_date'           => $end_date,
        'has_active_filters' => $has_active_filters
    ],
    'pagination' => [
        'current_page'  => $page,
        'limit'         => $limit,
        'total_items'   => $total_items,
        'total_pages'   => $total_pages,
        'has_next_page' => ($page < $total_pages),
        'has_prev_page' => ($page > 1)
    ],
    'orders' => $orders
], 'Approved orders loaded successfully.');