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
function safe_exec($db, $sql, $params = []) {
    if (!$db) return false;
    try {
        if ($db instanceof PDO) { $stmt = $db->prepare($sql); return $stmt->execute($params); }
        if ($db instanceof mysqli) {
            if (empty($params)) return (bool)$db->query($sql);
            $esc = $sql;
            foreach ($params as $v) {
                $r = is_numeric($v) ? (string)$v : (is_null($v) ? "NULL" : "'" . mysqli_real_escape_string($db, (string)$v) . "'");
                $pos = strpos($esc, '?'); if ($pos !== false) $esc = substr_replace($esc, $r, $pos, 1);
            }
            return (bool)$db->query($esc);
        }
    } catch (\Throwable $e) {} return false;
}

$user = require_auth();
$userId = (int)($user['id'] ?? $user['user_id'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? ''));

if ($userId <= 0) fail('Authentication required.', 401);
if ($userType !== 'retailer' && $userType !== 'salesman') {
    if (safe_one($db, "SELECT id FROM retailer WHERE user_id = ? LIMIT 1", [$userId])) {
        $userType = 'retailer';
    } else fail('Retailer access required.', 403);
}

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) { $json = json_decode($rawBody, true); if (is_array($json)) $input = array_merge($input, $json); }

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));

// ── ACTION: VERIFY RAZORPAY PAYMENT ──
if ($action === 'verify_payment') {
    $order_ids = $input['order_ids'] ?? [];
    if (!is_array($order_ids) && !empty($input['order_id'])) $order_ids = [$input['order_id']];
    if (is_string($order_ids)) $order_ids = explode(',', $order_ids);
    $order_ids = array_filter(array_map('intval', $order_ids));
    
    $rzp_id = trim($input['razorpay_payment_id'] ?? '');
    if (empty($order_ids) || empty($rzp_id)) fail('Order IDs and Razorpay ID required.', 422);

    $total_paid = 0;
    $invoices = [];
    foreach ($order_ids as $oid) {
        $ord = safe_one($db, "SELECT invoice_number, total FROM retailer_orders WHERE id = ? LIMIT 1", [$oid]);
        if ($ord) {
            $inv = $ord['invoice_number']; $tot = (float)$ord['total'];
            $total_paid += $tot; $invoices[] = $inv;
            safe_exec($db, "UPDATE retailer_orders SET payment_status = 'Paid' WHERE id = ?", [$oid]);
            safe_exec($db, "INSERT INTO invoices (invoice_number, paid_amount, due_amount, subtotal) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE paid_amount = ?, due_amount = 0", [$inv, $tot, $tot, $tot]);
        }
    }

    if (!empty($invoices)) {
        $dists = safe_query($db, "SELECT id FROM users WHERE user_type = 'distributor'");
        $msg = "Online payment of ₹" . number_format($total_paid, 2) . " verified for Invoice(s): " . implode(', ', $invoices) . ". Razorpay ID: $rzp_id";
        foreach ($dists as $d) safe_exec($db, "INSERT INTO notifications (user_id, type, message, is_read, created_at) VALUES (?, 'distributor', ?, 0, NOW())", [(int)$d['id'], $msg]);
    }

    ok(['payment_id' => $rzp_id, 'amount' => $total_paid], 'Payment successfully updated.');
}

// ── ACTION: REQUEST COLLECTION ──
if ($action === 'send_pay_request' && $userType === 'retailer') {
    $order_ids = $input['order_ids'] ?? [];
    if (is_string($order_ids)) $order_ids = explode(',', $order_ids);
    $order_ids = array_filter(array_map('intval', $order_ids));
    if (empty($order_ids)) fail('No orders selected.', 422);

    $ids_str = implode(',', $order_ids);
    $chk = safe_one($db, "SELECT id FROM retailer_orders WHERE id IN ($ids_str) AND payment_status = 'Requested' LIMIT 1");
    if ($chk) fail('One or more selected orders are already requested.', 400);

    $sumRow = safe_one($db, "SELECT SUM(total) as tot FROM retailer_orders WHERE id IN ($ids_str) AND user_id = ?", [$userId]);
    $reqTotal = (float)($sumRow['tot'] ?? 0);

    // Notifications
    $dmsg = "Retailer ID: $userId submitted a payment collection request of ₹" . number_format($reqTotal, 2) . ".";
    foreach (safe_query($db, "SELECT id FROM users WHERE user_type = 'distributor'") as $d) {
        safe_exec($db, "INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, 'distributor', ?, NOW())", [(int)$d['id'], $dmsg]);
    }
    $tmsg = "Retailer ID: $userId requested payment collection of ₹" . number_format($reqTotal, 2) . ". Visit scheduled.";
    foreach (safe_query($db, "SELECT id FROM users WHERE user_type = 'sales_team_leader'") as $l) {
        safe_exec($db, "INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, 'sales_team_leader', ?, NOW())", [(int)$l['id'], $tmsg]);
    }

    // Salesman Task
    $ret = safe_one($db, "SELECT id, salesman_id FROM retailer WHERE user_id = ? LIMIT 1", [$userId]);
    if ($ret && $ret['salesman_id'] > 0) {
        $sid = (int)$ret['salesman_id']; $rid = (int)$ret['id'];
        $tl = safe_one($db, "SELECT team_leader_id FROM salesman WHERE id = ?", [$sid]);
        $tlid = !empty($tl['team_leader_id']) ? (int)$tl['team_leader_id'] : null;
        $date = (int)date('G') < 12 ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d', strtotime('+2 days'));

        foreach ($order_ids as $oid) {
            $ord = safe_one($db, "SELECT ro.invoice_number, ro.total, i.paid_amount, i.due_amount FROM retailer_orders ro LEFT JOIN invoices i ON ro.invoice_number = i.invoice_number WHERE ro.id = ?", [$oid]);
            if ($ord) {
                safe_exec($db, "UPDATE retailer_orders SET payment_status = 'Requested' WHERE id = ?", [$oid]);
                $due = ((float)($ord['due_amount']??0) > 0) ? (float)$ord['due_amount'] : ((float)$ord['total'] - (float)($ord['paid_amount']??0));
                $note = "Auto-assigned: Collect payment for Invoice {$ord['invoice_number']}. Due: ₹" . number_format($due, 2);
                
                safe_exec($db, "INSERT INTO salesman_tasks (team_leader_id, salesman_id, shop_id, task_date, task_time, priority, notes, status, created_at) VALUES (?, ?, ?, ?, '10:00:00', 'high', ?, 'pending', NOW())", [$tlid, $sid, $rid, $date, $note]);
            }
        }
    }
    ok(['requested_amount' => $reqTotal], "Collection request submitted.");
}

// ── GET: LIST PENDING PAYMENTS ──
$where = ["(ro.payment_status IN ('Pending', 'Requested', 'Due', 'Unpaid', 'Partially Paid', 'Partial Paid'))", "ro.invoice_number IS NOT NULL", "TRIM(ro.invoice_number) != ''", "ro.invoice_number NOT LIKE 'PO%'"];
$params = [];

if ($userType === 'salesman') { $where[] = "ro.salesman_id = ?"; $params[] = $userId; }
else { $where[] = "ro.user_id = ?"; $params[] = $userId; }

$search = trim($_GET['search'] ?? '');
if ($search !== '') { $where[] = "(ro.invoice_number LIKE ? OR ro.shipping_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$sd = trim($_GET['start_date'] ?? ''); if ($sd !== '') { $where[] = "ro.due_date >= ?"; $params[] = $sd; }
$ed = trim($_GET['end_date'] ?? '');   if ($ed !== '') { $where[] = "ro.due_date <= ?"; $params[] = $ed; }

$whereStr = implode(' AND ', $where);
$limit = (int)($_GET['limit'] ?? 15); $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * $limit;

$sumRow = safe_one($db, "SELECT COUNT(*) as cnt, SUM(ro.total) as amt FROM retailer_orders ro WHERE $whereStr", $params);
$totAmt = (float)($sumRow['amt'] ?? 0); $totCnt = (int)($sumRow['cnt'] ?? 0);

$today = date('Y-m-d');
$list = safe_query($db, "
    SELECT ro.*, i.paid_amount, i.due_amount
    FROM retailer_orders ro LEFT JOIN invoices i ON ro.invoice_number = i.invoice_number
    WHERE $whereStr 
    ORDER BY CASE WHEN ro.due_date < '$today' AND ro.due_date IS NOT NULL AND ro.due_date != '0000-00-00' THEN 0 ELSE 1 END ASC, ro.due_date ASC, ro.id DESC
    LIMIT $offset, $limit
", $params);

$res = [];
foreach ($list as $r) {
    $paid = (float)($r['paid_amount'] ?? 0);
    $due  = ((float)($r['due_amount'] ?? 0) > 0) ? (float)$r['due_amount'] : ((float)$r['total'] - $paid);
    $isOverdue = (!empty($r['due_date']) && $r['due_date'] !== '0000-00-00' && strtotime($r['due_date']) + 86400 < time() && !in_array($r['payment_status'], ['Requested','Paid']));
    if ($paid > 0 && $due > 0) $isOverdue = true;

    $res[] = [
        'id'             => (int)$r['id'],
        'invoice_number' => $r['invoice_number'],
        'total'          => (float)$r['total'],
        'paid_amount'    => $paid,
        'due_amount'     => $due,
        'due_date'       => $r['due_date'] ?? null,
        'is_overdue'     => $isOverdue,
        'payment_status' => $r['payment_status']
    ];
}

ok([
    'summary' => ['total_pending_amount' => $totAmt, 'total_pending_count' => $totCnt],
    'pagination' => ['page' => $page, 'limit' => $limit, 'total_pages' => ceil($totCnt/$limit)],
    'invoices' => $res
], 'Pending payments loaded.');