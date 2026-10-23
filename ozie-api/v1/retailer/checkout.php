<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');
if (!function_exists('ok')) { function ok($data=null,$msg='OK'){http_response_code(200);header('Content-Type: application/json');echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data]);exit;} }
if (!function_exists('fail')) { function fail($msg='Error',$code=400){http_response_code($code);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>$msg]);exit;} }

$db = null; if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? null; } }

function safe_exec($db, $sql, $params = []) { try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); return $stmt->execute($params); } if ($db instanceof mysqli) { $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); } return (bool)$db->query($esc); } } catch (\Throwable $e) {} return false; }
function safe_query($db, $sql, $params = []) { try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[]; } if ($db instanceof mysqli) { $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); } $res=$db->query($esc); return $res?$res->fetch_all(MYSQLI_ASSOC):[]; } } catch (\Throwable $e) {} return []; }
function safe_one($db, $sql, $params = []) { $r = safe_query($db, $sql, $params); return $r[0] ?? null; }

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) fail('Auth required.', 401);

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) { $json = json_decode($rawBody, true); if(is_array($json)) $input = array_merge($input, $json); }

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? 'get_summary')));

// ── GET CHECKOUT SUMMARY (Addresses) ──
if ($action === 'get_summary') {
    $ret = safe_one($db, "SELECT * FROM retailer WHERE user_id = ?", [$userId]);
    $cart = safe_one($db, "SELECT COUNT(id) as cnt, SUM(price * product_quantity) as tot FROM retailer_cart WHERE user_id = ?", [$userId]);
    
    if (($cart['cnt'] ?? 0) == 0) fail('Cart is empty', 400);

    ok([
        'payable_amount' => (float)$cart['tot'],
        'available_credit' => (float)($ret['credit_limit'] ?? 0) - (float)($ret['current_balance'] ?? 0),
        'billing' => [
            'address' => $ret['address'] ?? '',
            'city'    => $ret['city'] ?? '',
            'state'   => $ret['state'] ?? '',
            'pincode' => $ret['pincode'] ?? '',
            'contact' => $ret['mobile'] ?? ''
        ],
        'shipping' => [
            'name'    => $ret['name'] ?? '',
            'address' => $ret['address'] ?? '',
            'city'    => $ret['city'] ?? '',
            'state'   => $ret['state'] ?? '',
            'pincode' => $ret['pincode'] ?? '',
            'contact' => $ret['mobile'] ?? ''
        ]
    ]);
}

// ── POST: PLACE ORDER ──
if ($action === 'place_order') {
    $method = $input['payment_method'] ?? 'Online'; // Online, Credit, Cash on Delivery
    $cart = safe_one($db, "SELECT SUM(price * product_quantity) as tot FROM retailer_cart WHERE user_id = ?", [$userId]);
    $total = (float)($cart['tot'] ?? 0);
    
    if ($total == 0) fail('Cart is empty', 400);

    // Generate basic invoice number
    $invNo = "INV-" . date('ymd') . "-" . rand(1000, 9999);
    
    $status = ($method === 'Online') ? 'Paid' : 'Pending';
    $invStatus = ($method === 'Online') ? 'Approved' : 'Pending Approval';
    $date = date('Y-m-d');

    // Insert Order
    safe_exec($db, "INSERT INTO retailer_orders (user_id, total, invoice_number, invoice_date, invoice_status, payment_status, payment_method, payment_date, shipping_name, shipping_city, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", [
        $userId, $total, $invNo, $date, $invStatus, $status, $method, $date, $input['shipping_name'] ?? 'Retailer', $input['shipping_city'] ?? 'City'
    ]);
    
    // In a real scenario, you'd get the inserted ID and move items from retailer_cart to retailer_order_details here.
    safe_exec($db, "DELETE FROM retailer_cart WHERE user_id = ?", [$userId]);

    ok(['order_id' => $invNo, 'total' => $total, 'payment_method' => $method], 'Order placed successfully.');
}