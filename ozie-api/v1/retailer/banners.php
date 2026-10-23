<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');
if (!function_exists('ok')) { function ok($data=null,$msg='OK'){http_response_code(200);header('Content-Type: application/json');echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data]);exit;} }
if (!function_exists('fail')) { function fail($msg='Error',$code=400){http_response_code($code);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>$msg]);exit;} }

$db = null; if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? null; } }

function safe_query($db, $sql, $params = []) {
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[]; }
        if ($db instanceof mysqli) { $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); } $res=$db->query($esc); return $res?$res->fetch_all(MYSQLI_ASSOC):[]; }
    } catch (\Throwable $e) {} return [];
}
function safe_one($db, $sql, $params = []) { $r = safe_query($db, $sql, $params); return $r[0] ?? null; }

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) fail('Auth required.', 401);

$ret = safe_one($db, "SELECT category FROM retailer WHERE user_id = ?", [$userId]);
$cat = $ret['category'] ?? '';

// GET: Fetch Active Banners
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bannersRaw = safe_query($db, "
        SELECT DISTINCT c.*, p.name AS product_name, p.price AS product_price
        FROM banner_campaigns c
        LEFT JOIN banner_targets t ON c.id = t.campaign_id
        LEFT JOIN products p ON c.product_id = p.id
        WHERE (t.category = ? OR c.categories = 'All Categories')
        AND c.status = 'active' 
        AND (c.start_date IS NULL OR DATE(c.start_date) <= CURDATE())
        AND (c.end_date IS NULL OR DATE(c.end_date) >= CURDATE())
        ORDER BY c.id DESC
    ", [$cat]);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $siteBaseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com') . '/';

    $results = [];
    foreach ($bannersRaw as $b) {
        if (empty($b['media_url'])) continue;
        
        // Ensure proper media URL formatting for Flutter
        $mediaUrl = $b['media_url'];
        if (!preg_match('/^https?:\/\//i', $mediaUrl)) {
            $mediaUrl = $siteBaseUrl . ltrim($mediaUrl, '/');
        }

        $results[] = [
            'id'           => (int)$b['id'],
            'title'        => $b['title'] ?? 'Promotion',
            'media_type'   => $b['banner_type'] ?? 'static', // static or dynamic (video)
            'media_url'    => $mediaUrl,
            'product_id'   => (int)$b['product_id'],
            'product_name' => $b['product_name'] ?? 'View Offer',
            'product_price'=> (float)($b['product_price'] ?? 0),
            'action_url'   => $b['link_url'] ?? null
        ];
    }
    
    ok(['banners' => $results], 'Banners loaded successfully.');
}

// POST: Add Banner Product to Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (($input['action'] ?? '') === 'add_to_cart') {
        $pid = (int)$input['product_id'];
        $qty = (int)$input['quantity'];
        
        $prod = safe_one($db, "SELECT name, price FROM products WHERE id = ?", [$pid]);
        if (!$prod) fail('Product not found.');

        safe_exec($db, "INSERT INTO retailer_cart (user_id, product_id, product_name, price, product_quantity) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_quantity = product_quantity + ?", [
            $userId, $pid, $prod['name'], $prod['price'], $qty, $qty
        ]);

        ok(null, 'Product added to cart from banner.');
    }
}