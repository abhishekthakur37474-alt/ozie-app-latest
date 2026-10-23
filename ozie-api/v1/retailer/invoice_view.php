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

$user = require_auth();
$userId = (int)($user['id'] ?? 0);

$inv_no = trim($_GET['inv'] ?? ($_GET['invoice_number'] ?? ''));
if (empty($inv_no)) fail('Invoice number is required.', 422);

$invoice = safe_one($db, "
    SELECT i.*, r.shipping_name, r.shipping_address, r.shipping_city, r.shipping_state, r.shipping_pincode, r.shipping_contact,
           r.bill_contactnumber, r.bill_address, r.bill_city, r.bill_state, r.bill_pincode, r.bill_gst
    FROM invoices i JOIN retailer_orders r ON r.id = i.order_id
    WHERE i.invoice_number = ? AND (r.user_id = ? OR r.salesman_id = ?) LIMIT 1
", [$inv_no, $userId, $userId]);

if (!$invoice) fail('Invoice not found or access denied.', 404);

$order_id = (int)$invoice['order_id'];
$retailer_id = (int)$invoice['retailer_id'];

// Get Items
$items_list = safe_query($db, "
    SELECT rod.*, COALESCE(p.units, 1) as units, COALESCE(p.mrp_bottle, 0.00) as mrp_bottle, p.name as catalog_name
    FROM retailer_order_details rod LEFT JOIN products p ON rod.product_id = p.id
    WHERE rod.order_id = ? ORDER BY rod.id ASC
", [$order_id]);

// Get Special Discounts
$special_discounts = [];
$tds = safe_query($db, "SELECT * FROM retailer_temp_discounts WHERE retailer_id = ? AND order_id = ? AND status = 'used'", [$retailer_id, $order_id]);
foreach ($tds as $td) $special_discounts[$td['product_id']] = $td;

$gross_total = 0; $sum_perc_discount = 0; $sum_free_value = 0; $sum_return_credit = 0; $special_discount_savings = 0; $net_total = 0;
$formattedItems = [];

foreach ($items_list as $item) {
    $pid = $item['product_id'];
    $p_qty = (int)$item['product_quantity'];
    $p_price = (float)$item['product_price'];
    $original_price = $p_price;
    $p_name = $item['product_name'] ?: $item['catalog_name'];
    
    $has_special = false; $sp_saving = 0;
    if (isset($special_discounts[$pid])) {
        $has_special = true;
        $original_price = (float)$special_discounts[$pid]['original_price'];
        $p_price = (float)$special_discounts[$pid]['temp_price'];
        $sp_saving = ($original_price - $p_price) * $p_qty;
        $special_discount_savings += $sp_saving;
    }

    $is_return = (strpos($p_name, 'Return Replacement') !== false);
    $row_gross = $original_price * $p_qty;
    $row_net = $p_price * $p_qty;
    $schemes_applied = [];

    if ($is_return) {
        $row_net = 0; $sum_return_credit += $row_gross;
        $schemes_applied[] = "Return Replacement Item (No Charge)";
    } elseif (!$has_special) {
        // Fetch applied schemes
        $sch = safe_query($db, "SELECT * FROM order_applied_schemes WHERE order_id = ? AND product_id = ?", [$order_id, $pid]);
        foreach ($sch as $s) {
            if ($s['scheme_type'] === 'DISCOUNT' || $s['scheme_type'] === 'LIFTING_DISCOUNT') {
                $amt = $row_net * ($s['discount_percent'] / 100);
                $row_net -= $amt; $sum_perc_discount += $amt;
                $schemes_applied[] = "{$s['discount_percent']}% Discount (-₹" . round($amt, 2) . ")";
            } elseif ($s['scheme_type'] === 'FREE' || $s['scheme_type'] === 'LIFTING_FREE') {
                $val = $s['free_qty'] * $p_price;
                $row_net -= $val; $sum_free_value += $val;
                $schemes_applied[] = "{$s['free_qty']} FREE Items (-₹" . round($val, 2) . ")";
            }
        }
    }

    $gross_total += $row_gross;
    $net_total += $row_net;

    $formattedItems[] = [
        'product_id'   => $pid,
        'product_name' => $p_name,
        'quantity'     => $p_qty,
        'unit_price'   => $is_return ? 0 : $p_price,
        'original_price'=> $original_price,
        'total_amount' => round($row_net, 2),
        'is_special'   => $has_special,
        'special_saving'=> round($sp_saving, 2),
        'is_return'    => $is_return,
        'schemes'      => $schemes_applied
    ];
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$siteBaseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com') . '/';

ok([
    'invoice_number' => $invoice['invoice_number'],
    'invoice_date'   => $invoice['invoice_date'],
    'due_date'       => $invoice['due_date'],
    'payment_status' => $invoice['payment_status'],
    'payment_method' => $invoice['payment_method'],
    'billing' => [
        'contact' => $invoice['bill_contactnumber'],
        'address' => $invoice['bill_address'],
        'city'    => $invoice['bill_city'],
        'state'   => $invoice['bill_state'],
        'pincode' => $invoice['bill_pincode'],
        'gstin'   => $invoice['bill_gst']
    ],
    'shipping' => [
        'name'    => $invoice['shipping_name'],
        'address' => $invoice['shipping_address'],
        'city'    => $invoice['shipping_city'],
        'state'   => $invoice['shipping_state'],
        'pincode' => $invoice['shipping_pincode'],
        'contact' => $invoice['shipping_contact']
    ],
    'items' => $formattedItems,
    'summary' => [
        'gross_total'      => round($gross_total, 2),
        'special_discount' => round($special_discount_savings, 2),
        'scheme_discount'  => round($sum_perc_discount, 2),
        'free_value'       => round($sum_free_value, 2),
        'return_credit'    => round($sum_return_credit, 2),
        'total_savings'    => round($special_discount_savings + $sum_perc_discount + $sum_free_value + $sum_return_credit, 2),
        'net_payable'      => round($net_total, 2)
    ],
    'pdf_url' => !empty($invoice['pdf_path']) ? $siteBaseUrl . ltrim($invoice['pdf_path'], '/') : null
], 'Invoice loaded successfully.');