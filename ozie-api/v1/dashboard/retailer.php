<?php
/**
 * Ozie API — Retailer Dashboard
 * Endpoint: GET /v1/dashboard/retailer
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Immediate Error & Exception Trap (Real Error Revealer) ──
set_exception_handler(function (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'PHP Exception: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
        'data'    => null,
        'errors'  => [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});

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

// ── 2. Isolated Safe Query Helper ─────────────────────────
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
        error_log("[SafeQuery Error] " . $e->getMessage() . " | SQL: " . $sql);
    }
    return [];
}

function safe_one($db, string $sql, array $params = []): ?array {
    $rows = safe_query($db, $sql, $params);
    return !empty($rows) ? $rows[0] : null;
}

// ── 3. Safe Date Formatter (PHP 8 TypeError Immune) ────────
$safeDate = function ($dateStr, $format = 'd M Y'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

// ── 4. Authentication & Role Check ────────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$uid = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
if ($uid <= 0) {
    fail('Invalid authenticated user identifier.', 401);
}

// Flexible Retailer Verification
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));
if ($userType !== 'retailer') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$uid, $uid]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $uid = (int)$checkRet['user_id'];
        }
    }
}

// ── 5. Media URL Resolver ──────────────────────────────────
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com';
$siteBaseUrl = $protocol . $host . '/';

$resolveMediaUrl = function (?string $path) use ($siteBaseUrl): string {
    $p = trim($path ?? '');
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    return $siteBaseUrl . ltrim($p, '/');
};

// ── 6. Retailer Profile & Credit Limit ─────────────────────
// Uses SELECT * to avoid "column not found" errors
$ret = safe_one($db, "SELECT * FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$uid, $uid]) ?: [];

$retailerName    = !empty($ret['name']) ? trim($ret['name']) : (!empty($user['name']) ? trim($user['name']) : 'Retailer');
$retailerCat     = !empty($ret['category']) ? trim($ret['category']) : 'D';
$retailerCode    = !empty($ret['retailer_code']) ? trim($ret['retailer_code']) : 'N/A';
$ozieCreditLimit = (float)($ret['credit_limit'] ?? ($ret['mfg_credit_limit'] ?? 0));
$creditDays      = (int)($ret['credit_duration'] ?? ($ret['credit_days'] ?? 0));
$retailerPhoto   = $ret['photo'] ?? null;

$nameParts = explode(' ', (string)$retailerName);
$firstName = !empty($nameParts[0]) ? $nameParts[0] : 'Retailer';

// ── 7. Upcoming Pay & Available Revolving Credit ───────────
$pendingSumRow = safe_one($db, "
    SELECT COALESCE(SUM(total), 0) AS total_amount 
    FROM retailer_orders 
    WHERE payment_status IN ('Pending', 'pending', 'Requested', 'requested', 'Due', 'due', 'Unpaid', 'unpaid', 'Partially Paid', 'Partial Paid', 'Partially paid')
      AND invoice_number IS NOT NULL
      AND TRIM(invoice_number) != ''
      AND invoice_number NOT LIKE 'PO%'
      AND user_id = ?
", [$uid]);

$pendingOrdersSum = (float)($pendingSumRow['total_amount'] ?? 0);
$retBalance       = (float)($ret['current_balance'] ?? ($ret['ret_current_balance'] ?? 0));
$currentBalance   = ($retBalance > 0) ? $retBalance : $pendingOrdersSum;
$availableCredit  = max(0.0, $ozieCreditLimit - $currentBalance);

// ── 8. Today's Dynamic Metrics ─────────────────────────────
$todayRow = safe_one($db, "
    SELECT 
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS today_orders_count,
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total ELSE 0 END), 0) AS today_sales_val,
        COALESCE(SUM(CASE WHEN payment_status = 'Paid' AND DATE(updated_at) = CURDATE() THEN total ELSE 0 END), 0) AS today_paid_val
    FROM retailer_orders
    WHERE user_id = ?
", [$uid]) ?: [];

$todayOrdersCount = (int)($todayRow['today_orders_count'] ?? 0);
$todaySalesVal    = (float)($todayRow['today_sales_val'] ?? 0);
$todayPaidVal     = (float)($todayRow['today_paid_val'] ?? 0);

// Order Counts
$pendingCntRow = safe_one($db, "SELECT COUNT(*) AS total FROM retailer_orders WHERE user_id = ? AND invoice_status = 'Pending'", [$uid]);
$pendingOrdersCount = (int)($pendingCntRow['total'] ?? 0);

$dispatchCntRow = safe_one($db, "SELECT COUNT(*) AS total FROM retailer_orders WHERE user_id = ? AND dispatch_status IN ('Dispatched', 'Received')", [$uid]);
$dispatchesCount = (int)($dispatchCntRow['total'] ?? 0);

// ── 9. Bank Credit Card Status ─────────────────────────────
$loanRow = safe_one($db, "SELECT status FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$uid]);
$rawCardStatus = strtolower(trim($loanRow['status'] ?? ''));

if ($rawCardStatus === 'approved') {
    $bankCardStatus  = 'Active';
    $bankCardMessage = 'Bank card approved and activated for direct ordering.';
    $bankCardAction  = null;
} elseif ($rawCardStatus === 'rejected') {
    $bankCardStatus  = 'Inactive';
    $bankCardMessage = 'Bank credit card is currently inactive. Apply to activate facility.';
    $bankCardAction  = 'Apply for Card';
} elseif (!empty($rawCardStatus)) {
    $bankCardStatus  = 'Pending';
    $bankCardMessage = 'Bank credit card application is currently under review.';
    $bankCardAction  = 'Track Status';
} else {
    $bankCardStatus  = 'Inactive';
    $bankCardMessage = 'Bank credit card facility is available. Apply now.';
    $bankCardAction  = 'Apply for Card';
}

// ── 10. Active Promotional Banners ─────────────────────────
$rawBanners = safe_query($db, "
    SELECT DISTINCT bc.* 
    FROM banner_campaigns bc
    LEFT JOIN banner_targets bt ON bc.id = bt.campaign_id
    WHERE bc.status = 'active' 
      AND DATE(bc.start_date) <= CURDATE()
      AND DATE(bc.end_date) >= CURDATE()
      AND bc.media_url IS NOT NULL
      AND (bt.category = ? OR bc.categories = 'All Categories')
    ORDER BY bc.id DESC
", [$retailerCat]);

if (empty($rawBanners)) {
    $rawBanners = safe_query($db, "
        SELECT * FROM banner_campaigns 
        WHERE status = 'active' 
          AND DATE(start_date) <= CURDATE() 
          AND DATE(end_date) >= CURDATE() 
          AND media_url IS NOT NULL 
        ORDER BY id DESC LIMIT 5
    ");
}

$bannersList = [];
foreach ($rawBanners as $b) {
    $mediaUrl = trim($b['media_url'] ?? '');
    if ($mediaUrl === 'uploads/interface for app.png') continue;

    $ext = strtolower(pathinfo($mediaUrl, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ogg'], true) || (($b['media_type'] ?? '') === 'dynamic');

    $bannersList[] = [
        'id'        => (string)($b['id'] ?? ''),
        'title'     => (string)($b['title'] ?? 'Promotion'),
        'media_url' => $resolveMediaUrl($mediaUrl),
        'is_video'  => $isVideo,
        'link_url'  => $b['link_url'] ?? null
    ];
}

// ── 11. Active Paper Schemes ───────────────────────────────
$rawPaperSchemes = safe_query($db, "
    SELECT s.*, p.name AS product_name
    FROM paper_schemes s
    LEFT JOIN products p ON s.product_id = p.id
    WHERE s.status = 'active'
      AND s.start_date <= CURDATE()
      AND s.end_date >= CURDATE()
      AND (s.category = 'ALL' OR FIND_IN_SET(?, s.category))
    ORDER BY s.id DESC
", [$retailerCat]);

$paperSchemes = [];
foreach ($rawPaperSchemes as $ps) {
    $defaultGiftImg = 'https://cdn-icons-png.flaticon.com/512/4213/4213958.png';
    $giftImg = !empty($ps['gift_image']) ? $resolveMediaUrl($ps['gift_image']) : $defaultGiftImg;

    $paperSchemes[] = [
        'id'             => (int)($ps['id'] ?? 0),
        'title'          => (string)($ps['scheme_title'] ?? 'Special Offer'),
        'scheme_type'    => (string)($ps['scheme_type'] ?? 'Paper Scheme'),
        'product_id'     => !empty($ps['product_id']) ? (int)$ps['product_id'] : null,
        'product_name'   => (string)($ps['product_name'] ?? 'All Products'),
        'min_quantity'   => (int)($ps['min_quantity'] ?? 0),
        'min_amount'     => (float)($ps['min_amount'] ?? 0),
        'gift_name'      => !empty($ps['gift_name']) ? (string)$ps['gift_name'] : 'Free Gift',
        'gift_image_url' => $giftImg,
        'gift_value'     => (float)($ps['gift_value'] ?? 0),
        'valid_till'     => $safeDate($ps['end_date'] ?? null),
        'origin_type'    => 'Paper Scheme'
    ];
}

// ── 12. Standard Schemes (for Marquee) ─────────────────────
$rawStdSchemes = safe_query($db, "
    SELECT s.*, p.name AS product_name
    FROM schemes s
    LEFT JOIN products p ON s.product_id = p.id
    WHERE s.status = 'active'
      AND s.start_date <= CURDATE()
      AND s.end_date >= CURDATE()
      AND (s.category = 'ALL' OR FIND_IN_SET(?, s.category) OR s.retailer_id = ?)
    ORDER BY s.id DESC
", [$retailerCat, $uid]);

$marqueeItems = [];
foreach ($paperSchemes as $ps) {
    $marqueeItems[] = [
        'id'           => $ps['id'],
        'scheme_title' => $ps['title'],
        'product_name' => $ps['product_name'],
        'type'         => 'Paper Scheme'
    ];
}
foreach ($rawStdSchemes as $ss) {
    $marqueeItems[] = [
        'id'           => (int)($ss['id'] ?? 0),
        'scheme_title' => (string)($ss['scheme_title'] ?? 'Scheme'),
        'product_name' => (string)($ss['product_name'] ?? 'All Products'),
        'type'         => 'Standard Scheme'
    ];
}

// ── 13. Latest Approved Orders (Top 5) ─────────────────────
$rawApproved = safe_query($db, "
    SELECT id, invoice_number, shipping_name, total, payment_status, created_at
    FROM retailer_orders 
    WHERE user_id = ? AND invoice_status = 'Approved' 
    ORDER BY created_at DESC 
    LIMIT 5
", [$uid]);

$latestApprovedOrders = [];
foreach ($rawApproved as $ord) {
    $invNum = trim($ord['invoice_number'] ?? '');
    $displayId = ($invNum !== '') ? $invNum : ('ORD-' . $ord['id']);

    $latestApprovedOrders[] = [
        'id'             => (int)$ord['id'],
        'order_number'   => $displayId,
        'invoice_number' => $invNum ?: null,
        'customer_name'  => (string)($ord['shipping_name'] ?? 'Customer'),
        'amount'         => (float)($ord['total'] ?? 0),
        'payment_status' => (string)($ord['payment_status'] ?? 'Pending'),
        'created_at'     => (string)($ord['created_at'] ?? ''),
        'formatted_date' => $safeDate($ord['created_at'] ?? null) ?? date('d M Y')
    ];
}

// ── 14. Pending Payments (Top 5) ──────────────────────────
$todayDate = date('Y-m-d');
$currentTimestamp = time();

$rawPending = safe_query($db, "
    SELECT id, invoice_number, total, payment_status, due_date
    FROM retailer_orders
    WHERE payment_status IN ('Pending', 'pending', 'Requested', 'requested', 'Due', 'due', 'Unpaid', 'unpaid', 'Partially Paid', 'Partial Paid', 'Partially paid')
      AND invoice_number IS NOT NULL
      AND TRIM(invoice_number) != ''
      AND invoice_number NOT LIKE 'PO%'
      AND user_id = ?
    ORDER BY 
      CASE 
          WHEN due_date < '$todayDate' AND due_date IS NOT NULL AND due_date != '0000-00-00' THEN 0 
          ELSE 1 
      END ASC,
      due_date ASC,
      id DESC 
    LIMIT 5
", [$uid]);

$pendingPaymentsList = [];
foreach ($rawPending as $due) {
    $invNumber  = trim($due['invoice_number'] ?? '');
    $pStatus    = $due['payment_status'] ?? 'Pending';
    $dueDate    = $due['due_date'] ?? null;
    $dueDisplay = $safeDate($dueDate) ?? 'N/A';
    $isOverdue  = false;

    if (!empty($dueDate) && $dueDate !== '0000-00-00') {
        $dueTs = strtotime((string)$dueDate);
        if ($dueTs !== false && $currentTimestamp > ($dueTs + 86400) && !in_array($pStatus, ['Requested', 'Paid'], true)) {
            $isOverdue = true;
        }
    }

    $statusLabel = $isOverdue ? 'Overdue' : ($pStatus === 'Requested' ? 'Collection Requested' : 'Pending');

    $pendingPaymentsList[] = [
        'id'             => (int)$due['id'],
        'invoice_number' => $invNumber,
        'total'          => (float)($due['total'] ?? 0),
        'due_date'       => $dueDate,
        'due_display'    => $dueDisplay,
        'is_overdue'     => $isOverdue,
        'payment_status' => $pStatus,
        'status_label'   => $statusLabel
    ];
}

// ── 15. Final Payload Response ─────────────────────────────
$payload = [
    'greeting' => [
        'first_name' => $firstName,
        'full_name'  => $retailerName,
        'title'      => "Hello {$firstName} 👋",
        'subtitle'   => "Tracking today’s orders and business, moving towards growth."
    ],
    'retailer' => [
        'id'            => $uid,
        'name'          => $retailerName,
        'category'      => $retailerCat,
        'retailer_code' => $retailerCode,
        'mobile'        => $ret['mobile'] ?? '',
        'photo_url'     => $resolveMediaUrl($retailerPhoto)
    ],
    'credit_overview' => [
        'anchor_credit_limit'  => $ozieCreditLimit,
        'current_balance'      => $currentBalance,
        'available_credit'     => $availableCredit,
        'credit_duration_days' => $creditDays
    ],
    'today_overview' => [
        'date'               => date('d M Y'),
        'today_orders_count' => $todayOrdersCount,
        'today_sales_amount' => $todaySalesVal,
        'today_paid_amount'  => $todayPaidVal,
        'due_pay_amount'     => $currentBalance
    ],
    'order_metrics' => [
        'today_orders'   => $todayOrdersCount,
        'pending_orders' => $pendingOrdersCount,
        'dispatches'     => $dispatchesCount
    ],
    'bank_card' => [
        'status'       => $bankCardStatus,
        'is_active'    => ($bankCardStatus === 'Active'),
        'message'      => $bankCardMessage,
        'action_label' => $bankCardAction
    ],
    'voice_order_promo' => [
        'badge'        => 'AI VOICE ORDERING',
        'title'        => 'Order Fast with AI Voice Agent!',
        'subtitle'     => 'Speak naturally to place orders in seconds',
        'image_url'    => $resolveMediaUrl('uploads/interface for app.png'),
        'action_route' => '/voice-order'
    ],
    'banners'                => $bannersList,
    'paper_schemes'          => $paperSchemes,
    'marquee_schemes'        => $marqueeItems,
    'latest_approved_orders' => $latestApprovedOrders,
    'pending_payments'       => $pendingPaymentsList,
    'quick_actions' => [
        ['key' => 'catalog',      'title' => 'Catalog',      'subtitle' => 'Browse Products', 'route' => '/catalog',      'icon' => 'store'],
        ['key' => 'orders',       'title' => 'Orders',       'subtitle' => 'Track & Review',  'route' => '/orders',       'icon' => 'cart'],
        ['key' => 'reports',      'title' => 'Reports',      'subtitle' => 'Sales & Analytics','route' => '/reports',     'icon' => 'analytics'],
        ['key' => 'payments',     'title' => 'Payments',     'subtitle' => 'Pay & Dues',      'route' => '/payments',     'icon' => 'card'],
        ['key' => 'create_order', 'title' => 'Create Order', 'subtitle' => 'AI & Scan Upload', 'route' => '/voice-order', 'icon' => 'camera'],
        ['key' => 'returns',      'title' => 'Report',       'subtitle' => 'Returns Report',  'route' => '/returns',      'icon' => 'returns']
    ]
];

// Return clean JSON
if (function_exists('success')) {
    success($payload, 'Retailer dashboard data loaded successfully.');
} else {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Retailer dashboard data loaded successfully.',
        'data'    => $payload,
        'errors'  => null
    ]);
    exit;
}