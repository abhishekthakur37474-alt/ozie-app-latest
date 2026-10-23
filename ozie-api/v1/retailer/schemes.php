<?php
/**
 * Ozie API — Retailer Active Offers & Schemes
 * Endpoint: GET /v1/retailer/schemes
 * 
 * Supports:
 *  - Both Standard Schemes & Paper Schemes merged
 *  - Mapped Manufacturer & Brand visibility scoping
 *  - Filter by Scheme Type (all, PAPER_SCHEME, FREE, DISCOUNT, CREDIT, FLAT, LIFTING_DISCOUNT, LIFTING_FREE)
 *  - Search by product, brand, offer title, or gift
 *  - Expiry countdown days calculation
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Helpers ────────────────────────────────────
if (!function_exists('ok')) {
    function ok($data = null, $message = 'OK', $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('fail')) {
    function fail($message = 'Error', $code = 400, $errors = null) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── 1. Database Connection Resolver ───────────────────────
$db = null;
if (function_exists('db')) {
    try { $db = db(); } catch (\Throwable $e) {}
}
if (!$db && isset($GLOBALS['conn'])) {
    $db = $GLOBALS['conn'];
}
if (!$db) {
    $siteRootDb = dirname(__DIR__, 3) . '/db.php';
    if (file_exists($siteRootDb)) {
        @include_once $siteRootDb;
        $db = $conn ?? ($con ?? null);
    }
}

// ── 2. Query Helpers ──────────────────────────────────────
if (!function_exists('safe_query')) {
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
                    $rep = is_numeric($val) ? (string)$val : (is_null($val) ? "NULL" : "'" . mysqli_real_escape_string($db, (string)$val) . "'");
                    $pos = strpos($escapedSql, '?');
                    if ($pos !== false) $escapedSql = substr_replace($escapedSql, $rep, $pos, 1);
                }
                $res = $db->query($escapedSql);
                return ($res instanceof mysqli_result) ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
            }
        } catch (\Throwable $e) {
            error_log("[Schemes Query Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return [];
    }
}

if (!function_exists('safe_one')) {
    function safe_one($db, string $sql, array $params = []): ?array {
        $rows = safe_query($db, $sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }
}

// ── 3. Authentication & Scope ──────────────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$userId   = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($userId <= 0) {
    fail('Authentication required.', 401);
}

$retData = safe_one($db, "SELECT category, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
$retailerCategory = trim($retData['category'] ?? 'ALL');
if (!empty($retData['user_id'])) {
    $userId = (int)$retData['user_id'];
}

// Visible Manufacturer Scoping
$vis_ids = null;
if ($userType === 'retailer') {
    $mapRows = safe_query($db, "SELECT manufacturer_id FROM ozie_retailer_mappings WHERE retailer_id = ? AND status = 'active'", [$userId]);
    if (!empty($mapRows)) {
        $vis_ids = array_map(function($r) { return (int)$r['manufacturer_id']; }, $mapRows);
    }
}

$mfg_filter_standard = "";
$mfg_filter_paper = "";
if (is_array($vis_ids)) {
    if (count($vis_ids) === 0) {
        $mfg_filter_standard = " AND 1=0 ";
        $mfg_filter_paper = " AND 1=0 ";
    } else {
        $idList = implode(',', array_map('intval', $vis_ids));
        $mfg_filter_standard = " AND (s.manufacturer_id IN ($idList) OR s.manufacturer_id = 0 OR s.manufacturer_id IS NULL) ";
        $mfg_filter_paper = " AND (ps.manufacturer_id IN ($idList) OR ps.manufacturer_id = 0 OR ps.manufacturer_id IS NULL) ";
    }
}

// ── 4. Filters & Search Parameters ─────────────────────────
$filter_type = strtoupper(trim($_GET['type'] ?? 'ALL'));
$search      = strtolower(trim($_GET['search'] ?? ($_GET['term'] ?? '')));
$today       = date('Y-m-d');

$all_schemes = [];

// 1. Fetch Standard Schemes
$stdParams = [$today, $today];
$catClauseStd = "";
if ($retailerCategory !== 'ALL') {
    $catClauseStd = " AND (s.category = 'ALL' OR FIND_IN_SET(?, s.category) OR s.category = '' OR s.category IS NULL) ";
    $stdParams[] = $retailerCategory;
}

$sqlStandard = "
    SELECT s.*, p.name AS product_name
    FROM schemes s
    LEFT JOIN products p ON s.product_id = p.id
    WHERE s.status = 'active'
      AND s.start_date <= ? AND s.end_date >= ?
      $mfg_filter_standard
      $catClauseStd
    ORDER BY s.created_at DESC
";
$rawStandard = safe_query($db, $sqlStandard, $stdParams);

foreach ($rawStandard as $row) {
    $row['is_paper_scheme'] = false;
    $row['origin_type']     = 'Standard Scheme';
    $row['scheme_type']     = strtoupper($row['scheme_type'] ?? 'DISCOUNT');
    $all_schemes[] = $row;
}

// 2. Fetch Paper Schemes
$paperParams = [$today, $today];
$catClausePaper = "";
if ($retailerCategory !== 'ALL') {
    $catClausePaper = " AND (ps.category = 'ALL' OR FIND_IN_SET(?, ps.category) OR ps.category = '' OR ps.category IS NULL) ";
    $paperParams[] = $retailerCategory;
}

$sqlPaper = "
    SELECT ps.*, p.name AS product_name
    FROM paper_schemes ps
    LEFT JOIN products p ON ps.product_id = p.id
    WHERE ps.status = 'active'
      AND ps.start_date <= ? AND ps.end_date >= ?
      $mfg_filter_paper
      $catClausePaper
    ORDER BY ps.created_at DESC
";
$rawPaper = safe_query($db, $sqlPaper, $paperParams);

foreach ($rawPaper as $row) {
    $row['is_paper_scheme'] = true;
    $row['origin_type']     = 'Paper Scheme';
    $row['scheme_type']     = 'PAPER_SCHEME';
    $row['min_qty']         = (int)($row['min_quantity'] ?? ($row['min_qty'] ?? 0));
    $all_schemes[] = $row;
}

// ── 5. Format & Filter List ────────────────────────────────
$formattedList = [];

foreach ($all_schemes as $row) {
    $isPaper = !empty($row['is_paper_scheme']);
    $sType   = strtoupper($row['scheme_type'] ?? 'DISCOUNT');

    // Benefit Text Calculation
    $benefit = "";
    if ($isPaper) {
        $giftName = !empty($row['gift_name']) ? $row['gift_name'] : 'Free Gift';
        $giftVal  = (!empty($row['gift_value']) && (float)$row['gift_value'] > 0) ? " (₹" . floatval($row['gift_value']) . ")" : "";
        $benefit  = $giftName . $giftVal;
    } elseif ($sType === 'FREE' || $sType === 'LIFTING_FREE') {
        $benefit = ($row['free_qty'] ?? 0) . " Cases FREE";
    } elseif ($sType === 'DISCOUNT' || $sType === 'LIFTING_DISCOUNT') {
        $benefit = ($row['discount'] ?? 0) . "% OFF";
    } elseif ($sType === 'FLAT') {
        $benefit = "₹" . ($row['discount'] ?? 0) . "/Case";
    } elseif ($sType === 'CREDIT') {
        $benefit = ($row['credit_days'] ?? 0) . " Days Credit";
    } else {
        $benefit = ($row['discount'] ?? 0) . "% Discount";
    }

    // Min requirement
    $minRequirement = "";
    if ($isPaper) {
        if (!empty($row['min_quantity']) && (int)$row['min_quantity'] > 0) {
            $minRequirement = (int)$row['min_quantity'] . " Cases";
        } elseif (!empty($row['min_amount']) && (float)$row['min_amount'] > 0) {
            $minRequirement = "₹" . floatval($row['min_amount']);
        } else {
            $minRequirement = ((int)($row['min_qty'] ?? 0)) . " Cases";
        }
    } else {
        $minRequirement = ((int)($row['min_qty'] ?? 0)) . " Cases";
    }

    $title   = (string)($row['scheme_title'] ?? 'Offer');
    $product = !empty($row['brand']) ? 'Brand: ' . $row['brand'] : (!empty($row['product_name']) ? $row['product_name'] : 'All Items');
    $brand   = (string)($row['brand'] ?? '');
    $gift    = (string)($row['gift_name'] ?? '');

    // Search check
    if ($search !== '') {
        $searchString = strtolower("$title $product $brand $gift $benefit");
        if (strpos($searchString, $search) === false) {
            continue;
        }
    }

    // Type filter check
    if ($filter_type !== 'ALL') {
        if ($filter_type === 'PAPER_SCHEME' && !$isPaper) continue;
        if ($filter_type !== 'PAPER_SCHEME' && $sType !== $filter_type) continue;
    }

    // Days left calculation
    $endDateStr = $row['end_date'] ?? null;
    $daysLeft   = null;
    if (!empty($endDateStr) && $endDateStr !== '0000-00-00') {
        $tsEnd = strtotime($endDateStr);
        if ($tsEnd !== false) {
            $daysLeft = (int)ceil(($tsEnd - time()) / 86400);
        }
    }

    $formattedList[] = [
        'id'               => (int)$row['id'],
        'scheme_title'     => $title,
        'scheme_type'      => $sType,
        'is_paper_scheme'  => $isPaper,
        'origin_type'      => $row['origin_type'],
        'benefit'          => $benefit,
        'product_id'       => !empty($row['product_id']) ? (int)$row['product_id'] : null,
        'product_name'     => $row['product_name'] ?? 'All Products',
        'brand'            => $brand ?: null,
        'min_requirement'  => $minRequirement,
        'min_qty'          => (int)($row['min_qty'] ?? 0),
        'start_date'       => $row['start_date'] ?? null,
        'end_date'         => $endDateStr,
        'days_left'        => $daysLeft,
        'is_expiring_soon' => ($daysLeft !== null && $daysLeft <= 3 && $daysLeft >= 0)
    ];
}

ok([
    'total_offers' => count($formattedList),
    'filter_type'  => $filter_type,
    'offers'       => $formattedList
], 'Active schemes and offers loaded successfully.');