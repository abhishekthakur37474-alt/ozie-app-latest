<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');

if (!function_exists('ok')) { function ok($data=null,$msg='OK'){http_response_code(200);header('Content-Type: application/json');echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data]);exit;} }
if (!function_exists('fail')) { function fail($msg='Error',$code=400){http_response_code($code);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>$msg]);exit;} }

$db = null; if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? null; } }

function safe_exec($db, $sql, $params = []) {
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); return $stmt->execute($params); }
        if ($db instanceof mysqli) { $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); } return (bool)$db->query($esc); }
    } catch (\Throwable $e) {} return false;
}
function safe_query($db, $sql, $params = []) {
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[]; }
        if ($db instanceof mysqli) { $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); } $res=$db->query($esc); return $res?$res->fetch_all(MYSQLI_ASSOC):[]; }
    } catch (\Throwable $e) {} return [];
}

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) fail('Auth required.', 401);

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) { $json = json_decode($rawBody, true); if(is_array($json)) $input = array_merge($input, $json); }

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? 'get')));

// 1. UPDATE PROFILE
if ($action === 'update' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($input['name'] ?? '');
    $mobile = trim($input['mobile'] ?? '');
    $address = trim($input['address'] ?? '');
    $city = trim($input['city'] ?? '');
    $state = trim($input['state'] ?? '');
    $pincode = trim($input['pincode'] ?? '');
    
    // In actual Ozie logic, some updates go to retailer_change_requests. For API, we'll update directly for standard non-sensitive fields.
    if (!empty($name)) safe_exec($db, "UPDATE users SET name = ? WHERE id = ?", [$name, $userId]);
    
    $upds = []; $params = [];
    if (!empty($address)) { $upds[] = "address = ?"; $params[] = $address; }
    if (!empty($city)) { $upds[] = "city = ?"; $params[] = $city; }
    if (!empty($state)) { $upds[] = "state = ?"; $params[] = $state; }
    if (!empty($pincode)) { $upds[] = "pincode = ?"; $params[] = $pincode; }

    if (!empty($upds)) {
        $params[] = $userId;
        safe_exec($db, "UPDATE retailer SET " . implode(', ', $upds) . " WHERE user_id = ?", $params);
    }
    
    ok(null, 'Profile updated successfully.');
}

// 2. GET PROFILE
$u = safe_query($db, "SELECT name, email, mobile FROM users WHERE id = ?", [$userId])[0] ?? [];
$r = safe_query($db, "SELECT * FROM retailer WHERE user_id = ?", [$userId])[0] ?? [];

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$siteBaseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com') . '/';

ok([
    'id' => $userId,
    'name' => $u['name'] ?? $r['name'] ?? '',
    'email' => $u['email'] ?? '',
    'mobile' => $u['mobile'] ?? $r['mobile'] ?? '',
    'shop_name' => $r['shop_name'] ?? $r['company_name'] ?? '',
    'category' => $r['category'] ?? 'D',
    'address' => $r['address'] ?? '',
    'city' => $r['city'] ?? '',
    'state' => $r['state'] ?? '',
    'pincode' => $r['pincode'] ?? '',
    'gst_number' => $r['gst_number'] ?? $r['gstin'] ?? '',
    'pan_number' => $r['pan_number'] ?? '',
    'credit_limit' => (float)($r['credit_limit'] ?? 0),
    'current_balance' => (float)($r['current_balance'] ?? 0),
    'photo_url' => !empty($r['photo']) ? $siteBaseUrl . 'uploads/' . $r['photo'] : null
], 'Profile loaded.');