<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');

if (!function_exists('ok')) {
    function ok($data = null, $msg = 'OK', $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['success'=>true, 'message'=>$msg, 'data'=>$data, 'errors'=>null]); exit; }
}
if (!function_exists('fail')) {
    function fail($msg = 'Error', $code = 400, $errs = null) { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['success'=>false, 'message'=>$msg, 'data'=>null, 'errors'=>$errs]); exit; }
}

$db = null;
if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? ($con ?? null); } }

function safe_query($db, $sql, $params = []) {
    if (!$db) return [];
    try {
        if ($db instanceof PDO) { $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        if ($db instanceof mysqli) {
            if (empty($params)) { $res = $db->query($sql); return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : []; }
            $esc = $sql;
            foreach ($params as $v) {
                $r = is_numeric($v) ? (string)$v : (is_null($v) ? "NULL" : "'" . mysqli_real_escape_string($db, (string)$v) . "'");
                $pos = strpos($esc, '?'); if ($pos !== false) $esc = substr_replace($esc, $r, $pos, 1);
            }
            $res = $db->query($esc); return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
        }
    } catch (\Throwable $e) {} return [];
}
function safe_one($db, $sql, $params = []) { $r = safe_query($db, $sql, $params); return !empty($r) ? $r[0] : null; }

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? ''));

if ($userId <= 0) fail('Authentication required.', 401);

$where = ["1=1"];
$params = [];

if ($userType === 'salesman') { $where[] = "i.salesman_id = ?"; $params[] = $userId; }
elseif ($userType === 'retailer') { $where[] = "r.user_id = ?"; $params[] = $userId; }
else {
    if (safe_one($db, "SELECT id FROM retailer WHERE user_id = ?", [$userId])) {
        $where[] = "r.user_id = ?"; $params[] = $userId;
    } else fail('Retailer access required.', 403);
}

// Filters
$status = strtolower(trim($_GET['status'] ?? 'all'));
if ($status !== 'all') { $where[] = "LOWER(i.payment_status) = ?"; $params[] = $status; }

$dateFlt = strtolower(trim($_GET['date'] ?? 'all'));
if ($dateFlt === 'today') { $where[] = "DATE(i.created_at) = CURDATE()"; }
elseif ($dateFlt === 'week') { $where[] = "DATE(i.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"; }
elseif ($dateFlt === 'month') { $where[] = "DATE(i.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"; }

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $where[] = "(i.invoice_number LIKE ? OR r.shipping_name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);
$limit = (int)($_GET['limit'] ?? 15); $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * $limit;

$countRow = safe_one($db, "SELECT COUNT(DISTINCT i.id) as cnt FROM invoices i JOIN retailer_orders r ON r.id = i.order_id WHERE $whereStr", $params);
$totCnt = (int)($countRow['cnt'] ?? 0);

$list = safe_query($db, "
    SELECT DISTINCT i.id, i.invoice_number, i.subtotal, i.payment_status, i.payment_method, i.created_at, r.shipping_name, i.pdf_path
    FROM invoices i JOIN retailer_orders r ON r.id = i.order_id
    WHERE $whereStr ORDER BY i.created_at DESC LIMIT $offset, $limit
", $params);

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$siteBaseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com') . '/';

$res = [];
foreach ($list as $r) {
    $res[] = [
        'id'             => (int)$r['id'],
        'invoice_number' => $r['invoice_number'],
        'customer_name'  => $r['shipping_name'],
        'subtotal'       => (float)$r['subtotal'],
        'payment_status' => ucfirst(strtolower($r['payment_status'] ?? '')),
        'payment_method' => $r['payment_method'],
        'created_at'     => $r['created_at'],
        'pdf_url'        => !empty($r['pdf_path']) ? $siteBaseUrl . ltrim($r['pdf_path'], '/') : null
    ];
}

ok([
    'filters' => ['status' => $status, 'date' => $dateFlt, 'search' => $search],
    'pagination' => ['page' => $page, 'limit' => $limit, 'total_items' => $totCnt, 'total_pages' => ceil($totCnt/$limit)],
    'invoices' => $res
], 'Invoices loaded.');