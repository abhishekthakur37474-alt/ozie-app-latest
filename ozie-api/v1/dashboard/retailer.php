<?php
/**
 * Ozie API — Retailer Dashboard
 * Endpoint: GET /v1/dashboard/retailer
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 1. Database Connection Resolver ───────────────────────
$db = null;
if (function_exists('db')) {
    $db = db();
}
if (!$db && isset($GLOBALS['conn'])) {
    $db = $GLOBALS['conn'];
}
if (!$db) {
    $rootDb = dirname(__DIR__, 2) . '/db.php';
    if (file_exists($rootDb)) {
        require_once $rootDb;
        $db = $conn ?? ($con ?? null);
    }
}

// ── 2. Universal Bulletproof Query Helpers ─────────────────
function db_query_all($db, string $sql, array $params = []): array {
    if (!$db) return [];
    try {
        // PDO Driver
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // MySQLi Driver
        if ($db instanceof mysqli) {
            if (empty($params)) {
                $res = $db->query($sql);
                if ($res instanceof mysqli_result) {
                    return $res->fetch_all(MYSQLI_ASSOC) ?: [];
                }
                return [];
            }

            // Safely escape parameters to guarantee compatibility across all PHP versions
            $escapedSql = $sql;
            foreach ($params as $val) {
                if (is_int($val) || is_float($val)) {
                    $replacement = (string)$val;
                } elseif (is_null($val)) {
                    $replacement = "NULL";
                } else {
                    $replacement = "'" . mysqli_real_escape_string($db, (string)$val) . "'";
                }
                $escapedSql = preg_replace('/\?/', $replacement, $escapedSql, 1);
            }

            $res = $db->query($escapedSql);
            if ($res instanceof mysqli_result) {
                return $res->fetch_all(MYSQLI_ASSOC) ?: [];
            }
            return [];
        }
    } catch (\Throwable $e) {
        error_log("[Dashboard API Error] " . $e->getMessage() . " | SQL: " . $sql);
    }
    return [];
}

function db_query_one($db, string $sql, array $params = []): ?array {
    $rows = db_query_all($db, $sql, $params);
    return !empty($rows) ? $rows[0] : null;
}

// ── 3. Authentication & Flexible Role Check ───────────────
$user = require_auth();

$uid = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
if ($uid <= 0) {
    fail('Invalid authenticated user identifier.', 401);
}

// Check role from token or verify directly from retailer table
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($userType !== 'retailer') {
    $checkRet = db_query_one($db, "SELECT user_id FROM retailer WHERE user_id = ? LIMIT 1", [$uid]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
    }
}

if ($userType !== 'retailer') {
    fail('Access denied. Retailer account required.', 403);
}

// ── 4. Ensure Supporting Schema Exists ─────────────────────
if ($db instanceof mysqli) {
    @mysqli_query($db, "
        CREATE TABLE IF NOT EXISTS `retailer_license` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `retailer_id` int(11) NOT NULL,
          `license_copy` varchar(255) DEFAULT NULL,
          `license_holder_name` varchar(150) DEFAULT NULL,
          `license_holder_contact` varchar(20) DEFAULT NULL,
          `license_holder_address` text DEFAULT NULL,
          `operator_license_name` varchar(150) DEFAULT NULL,
          `operator_license_contact` varchar(20) DEFAULT NULL,
          `operator_license_address` text DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `retailer_id` (`retailer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
    ");
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
$retailerSql = "
    SELECT r.credit_limit AS mfg_credit_limit,
           r.current_balance AS ret_current_balance,
           r.credit_duration, r.category,
           r.photo, r.name, r.mobile, r.address, r.city, r.state, r.pincode,
           r.retailer_code,
           rl.license_copy, rl.license_holder_name, rl.license_holder_contact, rl.license_holder_address,
           rl.operator_license_name, rl.operator_license_contact, rl.operator_license_address
    FROM retailer r
    LEFT JOIN retailer_license rl ON r.user_id = rl.retailer_id
    WHERE r.user_id = ?
    LIMIT 1
";
$retData = db_query_one($db, $retailerSql, [$uid]) ?: [];

$retailerName     = trim($retData['name'] ?? 'Retailer');
$retailerCat      = trim($retData['category'] ?? 'D');
$retailerCode     = trim($retData['retailer_code'] ?? 'N/A');
$ozieCreditLimit  = (float)($retData['mfg_credit_limit'] ?? 0);
$creditDays       = (int)($retData['credit_duration'] ?? 0);

$nameParts        = explode(' ', $retailerName);
$firstName        = !empty($nameParts[0]) ? $nameParts[0] : 'Retailer';

// ── 7. Upcoming Pay & Available Credit ─────────────────────
$pendingSumSql = "
    SELECT COALESCE(SUM(ro.total), 0) AS total_amount 
    FROM retailer_orders ro 
    WHERE ro.payment_status IN ('Pending', 'pending', 'Requested', 'requested', 'Due', 'due', 'Unpaid', 'unpaid', 'Partially Paid', 'Partial Paid', 'Partially paid')
      AND ro.invoice_number IS NOT NULL
      AND TRIM(ro.invoice_number) != ''
      AND ro.invoice_number NOT LIKE 'PO%'
      AND ro.user_id = ?
";
$pendingSumRow = db_query_one($db, $pendingSumSql, [$uid]);
$pendingOrdersSum = (float)($pendingSumRow['total_amount'] ?? 0);

$retBalance = (float)($retData['ret_current_balance'] ?? 0);
$currentBalance = ($retBalance > 0) ? $retBalance : $pendingOrdersSum;
$availableCredit = max(0.0, $ozieCreditLimit - $currentBalance);

// ── 8. Today's Dynamic Metrics ─────────────────────────────
$todayMetricsSql = "
    SELECT 
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS today_orders_count,
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total ELSE 0 END), 0) AS today_sales_val,
        COALESCE(SUM(CASE WHEN payment_status = 'Paid' AND DATE(updated_at) = CURDATE() THEN total ELSE 0 END), 0) AS today_paid_val
    FROM retailer_orders
    WHERE user_id = ?
";
$todayRow = db_query_one($db, $todayMetricsSql, [$uid]) ?: [];
$todayOrdersCount = (int)($todayRow['today_orders_count'] ?? 0);
$todaySalesVal    = (float)($todayRow['today_sales_val'] ?? 0);
$todayPaidVal     = (float)($todayRow['today_paid_val'] ?? 0);

// Total Pending Orders Count
$pendingCntSql = "SELECT COUNT(*) AS total FROM retailer_orders WHERE user_id = ? AND invoice_status = 'Pending'";
$pendingCntRow = db_query_one($db, $pendingCntSql, [$uid]);
$pendingOrdersCount = (int)($pendingCntRow['total'] ?? 0);

// Dispatches Count
$dispatchCntSql = "SELECT COUNT(*) AS total FROM retailer_orders WHERE user_id = ? AND dispatch_status IN ('Dispatched', 'Received')";
$dispatchCntRow = db_query_one($db, $dispatchCntSql, [$uid]);
$dispatchesCount = (int)($dispatchCntRow['total'] ?? 0);

// ── 9. Bank Credit Card Status ─────────────────────────────
$loanSql = "SELECT status FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1";
$loanRow = db_query_one($db, $loanSql, [$uid]);

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
$bannerSql = "
    SELECT DISTINCT bc.* 
    FROM banner_campaigns bc
    LEFT JOIN banner_targets bt ON bc.id = bt.campaign_id
    WHERE bc.status = 'active' 
      AND DATE(bc.start_date) <= CURDATE()
      AND DATE(bc.end_date) >= CURDATE()
      AND bc.media_url IS NOT NULL
      AND (bt.category = ? OR bc.categories = 'All Categories')
    ORDER BY bc.id DESC
";
$rawBanners = db_query_all($db, $bannerSql, [$retailerCat]);
$bannersList = [];

foreach ($rawBanners as $b) {
    $mediaUrl = trim($b['media_url'] ?? '');
    if ($mediaUrl === 'uploads/interface for app.png') continue;

    $ext = strtolower(pathinfo($mediaUrl, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ogg'], true) || (($b['media_type'] ?? '') === 'dynamic');

    $bannersList[] = [
        'id'         => (string)$b['id'],
        'title'      => (string)($b['title'] ?? 'Promotion'),
        'media_url'  => $resolveMediaUrl($mediaUrl),
        'is_video'   => $isVideo,
        'link_url'   => $b['link_url'] ?? null
    ];
}

// ── 11. Active Paper Schemes ───────────────────────────────
$paperSchemeSql = "
    SELECT s.*, p.name AS product_name
    FROM paper_schemes s
    LEFT JOIN products p ON s.product_id = p.id
    WHERE s.status = 'active'
      AND s.start_date <= CURDATE()
      AND s.end_date >= CURDATE()
      AND (s.category = 'ALL' OR FIND_IN_SET(?, s.category))
    ORDER BY s.id DESC
";
$rawPaperSchemes = db_query_all($db, $paperSchemeSql, [$retailerCat]);
$paperSchemes = [];

foreach ($rawPaperSchemes as $ps) {
    $defaultGiftImg = 'https://cdn-icons-png.flaticon.com/512/4213/4213958.png';
    $giftImg = !empty($ps['gift_image']) ? $resolveMediaUrl($ps['gift_image']) : $defaultGiftImg;

    $paperSchemes[] = [
        'id'             => (int)$ps['id'],
        'title'          => (string)($ps['scheme_title'] ?? 'Special Offer'),
        'scheme_type'    => (string)($ps['scheme_type'] ?? 'Paper Scheme'),
        'product_id'     => !empty($ps['product_id']) ? (int)$ps['product_id'] : null,
        'product_name'   => (string)($ps['product_name'] ?? 'All Products'),
        'min_quantity'   => (int)($ps['min_quantity'] ?? 0),
        'min_amount'     => (float)($ps['min_amount'] ?? 0),
        'gift_name'      => !empty($ps['gift_name']) ? (string)$ps['gift_name'] : 'Free Gift',
        'gift_image_url' => $giftImg,
        'gift_value'     => (float)($ps['gift_value'] ?? 0),
        'valid_till'     => !empty($ps['end_date']) ? date('d M Y', strtotime($ps['end_date'])) : null,
        'origin_type'    => 'Paper Scheme'
    ];
}

// ── 12. Standard Schemes (for Marquee) ─────────────────────
$stdSchemeSql = "
    SELECT s.*, p.name AS product_name
    FROM schemes s
    LEFT JOIN products p ON s.product_id = p.id
    WHERE s.status = 'active'
      AND s.start_date <= CURDATE()
      AND s.end_date >= CURDATE()
      AND (s.category = 'ALL' OR FIND_IN_SET(?, s.category) OR s.retailer_id = ?)
    ORDER BY s.id DESC
";
$rawStdSchemes = db_query_all($db, $stdSchemeSql, [$retailerCat, $uid]);
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
        'id'           => (int)$ss['id'],
        'scheme_title' => (string)($ss['scheme_title'] ?? 'Scheme'),
        'product_name' => (string)($ss['product_name'] ?? 'All Products'),
        'type'         => 'Standard Scheme'
    ];
}

// ── 13. Latest Approved Orders (Top 5) ─────────────────────
$latestOrdersSql = "
    SELECT id, invoice_number, shipping_name, total, payment_status, created_at
    FROM retailer_orders 
    WHERE user_id = ? AND invoice_status = 'Approved' 
    ORDER BY created_at DESC 
    LIMIT 5
";
$rawApproved = db_query_all($db, $latestOrdersSql, [$uid]);
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
        'created_at'     => $ord['created_at'],
        'formatted_date' => date('d M Y', strtotime($ord['created_at']))
    ];
}

// ── 14. Pending Payments (Top 5) ──────────────────────────
$todayDate = date('Y-m-d');
$currentTimestamp = time();

$pendingListSql = "
    SELECT ro.*, 
           inv.paid_amount AS invoice_paid, 
           inv.due_amount AS invoice_due,
           inv.subtotal AS invoice_subtotal
    FROM retailer_orders ro 
    LEFT JOIN invoices inv ON ro.invoice_number = inv.invoice_number
    WHERE ro.payment_status IN ('Pending', 'pending', 'Requested', 'requested', 'Due', 'due', 'Unpaid', 'unpaid', 'Partially Paid', 'Partial Paid', 'Partially paid')
      AND ro.invoice_number IS NOT NULL
      AND TRIM(ro.invoice_number) != ''
      AND ro.invoice_number NOT LIKE 'PO%'
      AND ro.user_id = ?
    ORDER BY 
      CASE 
          WHEN ro.due_date < '$todayDate' AND ro.due_date IS NOT NULL AND ro.due_date != '0000-00-00' THEN 0 
          ELSE 1 
      END ASC,
      ro.due_date ASC,
      ro.id DESC 
    LIMIT 5
";
$rawPending = db_query_all($db, $pendingListSql, [$uid]);
$pendingPaymentsList = [];

foreach ($rawPending as $due) {
    $invNumber = trim($due['invoice_number'] ?? '');
    $pStatus   = $due['payment_status'] ?? 'Pending';
    $isOverdue = false;
    $dueDisplay = 'N/A';

    if (!empty($due['due_date']) && $due['due_date'] !== '0000-00-00') {
        $dueTs = strtotime($due['due_date']);
        $dueDisplay = date('d M Y', $dueTs);
        if ($currentTimestamp > ($dueTs + 86400) && !in_array($pStatus, ['Requested', 'Paid'], true)) {
            $isOverdue = true;
        }
    }

    $statusLabel = $isOverdue ? 'Overdue' : ($pStatus === 'Requested' ? 'Collection Requested' : 'Pending');

    $pendingPaymentsList[] = [
        'id'             => (int)$due['id'],
        'invoice_number' => $invNumber,
        'total'          => (float)($due['total'] ?? 0),
        'due_date'       => $due['due_date'],
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
        'id'              => $uid,
        'name'            => $retailerName,
        'category'        => $retailerCat,
        'retailer_code'   => $retailerCode,
        'mobile'          => $retData['mobile'] ?? '',
        'photo_url'       => $resolveMediaUrl($retData['photo'] ?? null)
    ],
    'credit_overview' => [
        'anchor_credit_limit' => $ozieCreditLimit,
        'current_balance'     => $currentBalance,
        'available_credit'    => $availableCredit,
        'credit_duration_days'=> $creditDays
    ],
    'today_overview' => [
        'date'                => date('d M Y'),
        'today_orders_count'  => $todayOrdersCount,
        'today_sales_amount'  => $todaySalesVal,
        'today_paid_amount'   => $todayPaidVal,
        'due_pay_amount'      => $currentBalance
    ],
    'order_metrics' => [
        'today_orders'   => $todayOrdersCount,
        'pending_orders' => $pendingOrdersCount,
        'dispatches'     => $dispatchesCount
    ],
    'bank_card' => [
        'status'         => $bankCardStatus,
        'is_active'      => ($bankCardStatus === 'Active'),
        'message'        => $bankCardMessage,
        'action_label'   => $bankCardAction
    ],
    'voice_order_promo' => [
        'badge'        => 'AI VOICE ORDERING',
        'title'        => 'Order Fast with AI Voice Agent!',
        'subtitle'     => 'Speak naturally to place orders in seconds',
        'image_url'    => $resolveMediaUrl('uploads/interface for app.png'),
        'action_route' => '/voice-order'
    ],
    'banners'                 => $bannersList,
    'paper_schemes'           => $paperSchemes,
    'marquee_schemes'         => $marqueeItems,
    'latest_approved_orders'  => $latestApprovedOrders,
    'pending_payments'        => $pendingPaymentsList,
    'quick_actions' => [
        ['key' => 'catalog',      'title' => 'Catalog',      'subtitle' => 'Browse Products', 'route' => '/catalog',      'icon' => 'store'],
        ['key' => 'orders',       'title' => 'Orders',       'subtitle' => 'Track & Review',  'route' => '/orders',       'icon' => 'cart'],
        ['key' => 'reports',      'title' => 'Reports',      'subtitle' => 'Sales & Analytics','route' => '/reports',     'icon' => 'analytics'],
        ['key' => 'payments',     'title' => 'Payments',     'subtitle' => 'Pay & Dues',      'route' => '/payments',     'icon' => 'card'],
        ['key' => 'create_order', 'title' => 'Create Order', 'subtitle' => 'AI & Scan Upload', 'route' => '/voice-order', 'icon' => 'camera'],
        ['key' => 'returns',      'title' => 'Report',       'subtitle' => 'Returns Report',  'route' => '/returns',      'icon' => 'returns']
    ]
];

success($payload, 'Retailer dashboard data loaded successfully.');