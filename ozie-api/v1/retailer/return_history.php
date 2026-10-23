<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');

if (!function_exists('ok')) {
    function ok($data = null, $msg = 'OK') { http_response_code(200); header('Content-Type: application/json'); echo json_encode(['success'=>true, 'message'=>$msg, 'data'=>$data, 'errors'=>null]); exit; }
}
if (!function_exists('fail')) {
    function fail($msg = 'Error', $code = 400) { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['success'=>false, 'message'=>$msg, 'data'=>null, 'errors'=>null]); exit; }
}

$db = null; if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? ($con ?? null); } }

function safe_query($db, $sql, $params = []) {
    if (!$db) return [];
    try {
        if ($db instanceof PDO) { $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        if ($db instanceof mysqli) {
            if (empty($params)) { $res = $db->query($sql); return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : []; }
            $esc = $sql; foreach ($params as $v) { $r = is_numeric($v) ? (string)$v : "'".mysqli_real_escape_string($db, (string)$v)."'"; $pos = strpos($esc, '?'); if ($pos !== false) $esc = substr_replace($esc, $r, $pos, 1); }
            $res = $db->query($esc); return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
        }
    } catch (\Throwable $e) {} return [];
}
function safe_one($db, $sql, $params = []) { $r = safe_query($db, $sql, $params); return !empty($r) ? $r[0] : null; }
function safe_exec($db, $sql, $params = []) {
    if (!$db) return false;
    try {
        if ($db instanceof PDO) { $stmt = $db->prepare($sql); return $stmt->execute($params); }
        if ($db instanceof mysqli) {
            if (empty($params)) return (bool)$db->query($sql);
            $esc = $sql; foreach ($params as $v) { $r = is_numeric($v) ? (string)$v : "'".mysqli_real_escape_string($db, (string)$v)."'"; $pos = strpos($esc, '?'); if ($pos !== false) $esc = substr_replace($esc, $r, $pos, 1); }
            return (bool)$db->query($esc);
        }
    } catch (\Throwable $e) {} return false;
}

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? ''));

if ($userId <= 0) fail('Auth required.', 401);
if ($userType !== 'retailer' && $userType !== 'salesman') {
    if (safe_one($db, "SELECT id FROM retailer WHERE user_id = ?", [$userId])) $userType = 'retailer';
    else fail('Retailer access required.', 403);
}

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) { $json = json_decode($rawBody, true); if (is_array($json)) $input = array_merge($input, $json); }

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));

// ── ACTION: CLAIM RETURN ──
if ($action === 'claim_return' && $userType === 'retailer') {
    $claim_id = (int)($input['return_id'] ?? 0);
    $ret = safe_one($db, "SELECT * FROM returns WHERE id = ? AND user_id = ? AND status = 'Approved'", [$claim_id, $userId]);
    if (!$ret) fail('Approved return not found.', 404);

    $resType = $ret['resolution_type'];
    $credAmt = (float)($ret['credit_amount'] ?? 0);
    
    if ($resType === 'Credit Note') {
        if ($credAmt > 0) {
            safe_exec($db, "UPDATE retailer SET credit_limit = credit_limit + ? WHERE user_id = ?", [$credAmt, $userId]);
            safe_exec($db, "UPDATE returns SET status = 'Claimed', claim_status = 'completed', claimed_at = NOW() WHERE id = ?", [$claim_id]);
            ok(['claimed_amount' => $credAmt, 'type' => 'Credit Note'], "₹" . number_format($credAmt, 2) . " added to credit limit.");
        } else fail('No credit amount found.', 400);
    } else {
        // Replacement
        safe_exec($db, "DELETE FROM retailer_cart WHERE return_id = ? AND is_return_claim = 1 AND user_id = ?", [$claim_id, $userId]);
        $items = safe_query($db, "SELECT * FROM return_items WHERE return_id = ?", [$claim_id]);
        $added = 0;
        foreach ($items as $it) {
            if ($it['product_id'] > 0 && $it['quantity'] > 0) {
                safe_exec($db, "INSERT INTO retailer_cart (user_id, product_id, product_name, price, product_quantity, is_return_claim, return_id, created_date) VALUES (?, ?, ?, 0.00, ?, 1, ?, NOW())", [$userId, $it['product_id'], $it['product_name'], $it['quantity'], $claim_id]);
                $added++;
            }
        }
        if ($added > 0) {
            safe_exec($db, "UPDATE returns SET status = 'Claimed', claim_status = 'pending_checkout', claimed_at = NOW(), cart_processed = 1 WHERE id = ?", [$claim_id]);
            ok(['items_added' => $added, 'type' => 'Replacement'], "$added replacement items added to cart.");
        } else {
            safe_exec($db, "UPDATE returns SET claim_status = 'failed', claimed_at = NOW() WHERE id = ?", [$claim_id]);
            fail('Failed to parse replacement items.', 400);
        }
    }
}

// ── GET: LIST RETURNS ──
$where = ["(r.invoice_number NOT LIKE 'TRF%' AND r.invoice_number NOT LIKE 'TRF-%')"];
$params = [];
if ($userType === 'salesman') {
    $where[] = "ro.salesman_id = ?"; $params[] = $userId;
    $join = "INNER JOIN retailer_orders ro ON r.invoice_number = ro.invoice_number";
} else {
    $where[] = "r.user_id = ?"; $params[] = $userId;
    $join = "";
}

$status = trim($_GET['status'] ?? ''); if ($status) { $where[] = ($status === 'Rejected') ? "r.status LIKE 'Rejected%'" : "r.status = ?"; if ($status !== 'Rejected') $params[] = $status; }
$res = trim($_GET['res'] ?? ''); if ($res) { $where[] = "r.resolution_type = ?"; $params[] = $res; }
$search = trim($_GET['search'] ?? ''); if ($search) { $where[] = "(r.invoice_number LIKE ? OR r.reason LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereStr = "WHERE " . implode(' AND ', $where);
$limit = (int)($_GET['limit'] ?? 15); $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * $limit;

$tot = safe_one($db, "SELECT COUNT(*) as cnt FROM returns r $join $whereStr", $params);
$totCnt = (int)($tot['cnt'] ?? 0);

$list = safe_query($db, "SELECT r.* FROM returns r $join $whereStr ORDER BY r.created_at DESC LIMIT $offset, $limit", $params);

$formatted = [];
foreach ($list as $r) {
    $r['items'] = json_decode($r['return_items'] ?? '[]', true) ?: [];
    $formatted[] = $r;
}

ok([
    'filters' => ['status' => $status, 'resolution' => $res, 'search' => $search],
    'pagination' => ['page' => $page, 'limit' => $limit, 'total_items' => $totCnt, 'total_pages' => ceil($totCnt/$limit)],
    'returns' => $formatted
], 'Return history loaded.');