<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');

if (!function_exists('ok')) { function ok($data=null,$msg='OK'){http_response_code(200);header('Content-Type: application/json');echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data,'errors'=>null]);exit;} }
if (!function_exists('fail')) { function fail($msg='Error',$code=400){http_response_code($code);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>$msg,'data'=>null,'errors'=>null]);exit;} }

$db = null; if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? null; } }

function safe_exec($db, $sql, $params = []) {
    if (!$db) return false;
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); return $stmt->execute($params); }
        if ($db instanceof mysqli) {
            $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); }
            return (bool)$db->query($esc);
        }
    } catch (\Throwable $e) {} return false;
}
function safe_query($db, $sql, $params = []) {
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[]; }
        if ($db instanceof mysqli) {
            $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); }
            $res=$db->query($esc); return $res?$res->fetch_all(MYSQLI_ASSOC):[];
        }
    } catch (\Throwable $e) {} return [];
}
function safe_one($db, $sql, $params = []) { $r=safe_query($db,$sql,$params); return $r[0]??null; }

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? 'retailer'));

if ($userId <= 0) fail('Auth required.', 401);

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) { $json = json_decode($rawBody, true); if(is_array($json)) $input = array_merge($input, $json); }

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));
$typeCondition = "(type = '$userType' OR type = 'general' OR type = '' OR type IS NULL OR type = 'voice_order' OR type = 'cart_reminder' OR type = 'scheme' OR type = 'return')";

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nids = $input['notification_ids'] ?? [];
    if (!is_array($nids)) $nids = array_filter(explode(',', $nids));
    $ids_str = implode(',', array_map('intval', $nids));

    if ($action === 'mark_read') {
        if (empty($nids)) fail('Notification IDs required.');
        safe_exec($db, "UPDATE notifications SET is_read = 1 WHERE id IN ($ids_str) AND (user_id = ? OR user_id = 0) AND $typeCondition", [$userId]);
        ok(null, count($nids) . ' notifications marked as read.');
    }
    if ($action === 'delete') {
        if (empty($nids)) fail('Notification IDs required.');
        safe_exec($db, "DELETE FROM notifications WHERE id IN ($ids_str) AND (user_id = ? OR user_id = 0) AND $typeCondition", [$userId]);
        ok(null, count($nids) . ' notifications deleted.');
    }
    if ($action === 'mark_all_read') {
        safe_exec($db, "UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id = 0) AND $typeCondition", [$userId]);
        ok(null, 'All notifications marked as read.');
    }
    if ($action === 'clear_read') {
        safe_exec($db, "DELETE FROM notifications WHERE is_read = 1 AND (user_id = ? OR user_id = 0) AND $typeCondition", [$userId]);
        ok(null, 'Read notifications cleared.');
    }
    if ($action === 'delete_all') {
        safe_exec($db, "DELETE FROM notifications WHERE (user_id = ? OR user_id = 0) AND $typeCondition", [$userId]);
        ok(null, 'All notifications deleted.');
    }
}

// GET Action
safe_exec($db, "DELETE n1 FROM notifications n1 INNER JOIN notifications n2 WHERE n1.id > n2.id AND n1.user_id = n2.user_id AND n1.type = n2.type AND n1.message = n2.message AND ABS(TIMESTAMPDIFF(SECOND, n1.created_at, n2.created_at)) < 5");

$limit = (int)($_GET['limit'] ?? 20); $page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page - 1) * $limit;

$tot = safe_one($db, "SELECT COUNT(*) as c FROM notifications WHERE $typeCondition AND (user_id = ? OR user_id = 0)", [$userId]);
$unread = safe_one($db, "SELECT COUNT(*) as c FROM notifications WHERE $typeCondition AND is_read = 0 AND (user_id = ? OR user_id = 0)", [$userId]);

$list = safe_query($db, "SELECT * FROM notifications WHERE $typeCondition AND (user_id = ? OR user_id = 0) ORDER BY created_at DESC LIMIT $offset, $limit", [$userId]);

$formatted = [];
foreach ($list as $n) {
    $msg = $n['message'];
    $category = 'General';
    if (stripos($msg, 'voice') !== false || stripos($msg, 'cart') !== false) $category = 'Cart/Voice';
    elseif (stripos($msg, 'scheme') !== false || stripos($msg, 'reward') !== false) $category = 'Scheme';
    elseif (stripos($msg, 'return') !== false) $category = 'Return';
    elseif (stripos($msg, 'approved') !== false) $category = 'Order';

    $formatted[] = [
        'id'         => (int)$n['id'],
        'message'    => $msg,
        'is_read'    => (bool)$n['is_read'],
        'category'   => $category,
        'related_id' => $n['related_id'],
        'created_at' => $n['created_at'],
        'time_ago'   => date('d M, h:i A', strtotime($n['created_at']))
    ];
}

ok([
    'summary' => ['total' => (int)$tot['c'], 'unread' => (int)$unread['c']],
    'pagination' => ['page' => $page, 'limit' => $limit, 'total_pages' => ceil($tot['c']/$limit)],
    'notifications' => $formatted
], 'Notifications loaded.');