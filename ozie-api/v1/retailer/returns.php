<?php
/**
 * Ozie API — Retailer Returns & Claims
 * Endpoints:
 *   GET  /v1/retailer/returns
 *        - ?action=search_invoices&query=... (Search retailer's invoices)
 *        - ?action=fetch_invoice&invoice_number=... (Fetch invoice items with bottle & unit math)
 *        - (Default) List Return History with status filters & pagination
 * 
 *   POST /v1/retailer/returns
 *        - action: "submit_return" (Submit damaged/expired goods return request)
 *        - action: "claim_return" (Claim Credit Note to credit limit OR Replacement to Cart)
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

// ── 2. Universal Query Helpers ─────────────────────────────
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
            error_log("[Returns Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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

if (!function_exists('safe_exec')) {
    function safe_exec($db, string $sql, array $params = []): bool {
        if (!$db) return false;
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                return $stmt->execute($params);
            }
            if ($db instanceof mysqli) {
                if (empty($params)) return (bool)$db->query($sql);
                $escapedSql = $sql;
                foreach ($params as $val) {
                    $rep = is_numeric($val) ? (string)$val : "'" . mysqli_real_escape_string($db, (string)$val) . "'";
                    $pos = strpos($escapedSql, '?');
                    if ($pos !== false) $escapedSql = substr_replace($escapedSql, $rep, $pos, 1);
                }
                return (bool)$db->query($escapedSql);
            }
        } catch (\Throwable $e) {
            error_log("[Returns Exec Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return false;
    }
}

$safeDate = function ($dateStr, $format = 'd M Y'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

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

if ($userType !== 'retailer') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $userId = (int)$checkRet['user_id'];
        }
    }
}

if ($userType !== 'retailer') {
    fail('Access denied. Retailer role required.', 403);
}

$uploadDir = dirname(__DIR__, 3) . '/uploads/returns/';
if (!file_exists($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// ── 4. POST: SUBMIT RETURN OR CLAIM ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    $action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));

    // ACTION A: SUBMIT NEW RETURN REQUEST
    if ($action === 'submit_return' || isset($input['submit_return'])) {
        $inv_no     = trim($input['invoice_number'] ?? '');
        $reason     = trim($input['return_reason'] ?? '');
        $desc       = trim($input['description'] ?? '');
        $resolution = trim($input['resolution_type'] ?? 'Credit Note');
        $credit_amt = (float)($input['credit_amount'] ?? 0.0);
        $itemsData  = $input['selected_items'] ?? ($input['items'] ?? []);

        if (is_string($itemsData)) {
            $itemsData = json_decode($itemsData, true) ?: [];
        }

        if (empty($inv_no)) fail('Invoice number is required.', 422);
        if (empty($reason)) fail('Return reason is required.', 422);
        if (empty($itemsData)) fail('At least one item must be selected for return.', 422);

        // Photo Upload Handling (File or Base64)
        $photo_name = "";
        if (!empty($_FILES['return_photo']['name'])) {
            $ext = pathinfo($_FILES['return_photo']['name'], PATHINFO_EXTENSION);
            $photo_name = "RET_IMG_" . time() . "_" . uniqid() . "." . $ext;
            move_uploaded_file($_FILES['return_photo']['tmp_name'], $uploadDir . $photo_name);
        } elseif (!empty($input['return_photo_base64'])) {
            $b64 = $input['return_photo_base64'];
            if (preg_match('/^data:image\/(\w+);base64,/', $b64)) {
                $b64 = substr($b64, strpos($b64, ',') + 1);
            }
            $dec = base64_decode($b64);
            if ($dec !== false) {
                $photo_name = "RET_IMG_" . time() . "_" . uniqid() . ".jpg";
                file_put_contents($uploadDir . $photo_name, $dec);
            }
        }

        $full_desc = "Credit Amount: ₹" . number_format($credit_amt, 2) . ($desc !== '' ? "\n\n" . $desc : "");
        $jsonItems = json_encode($itemsData, JSON_UNESCAPED_UNICODE);

        safe_exec($db, "
            INSERT INTO returns (user_id, invoice_number, reason, description, resolution_type, return_photo, return_items, credit_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ", [$userId, $inv_no, $reason, $full_desc, $resolution, $photo_name, $jsonItems, $credit_amt]);

        $retRow = safe_one($db, "SELECT id FROM returns WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]);
        $return_id = (int)($retRow['id'] ?? 0);

        // Insert into return_items table
        foreach ($itemsData as $it) {
            $p_name  = (string)($it['product_name'] ?? 'Product');
            $p_id    = !empty($it['product_id']) ? (int)$it['product_id'] : null;
            $qty     = (int)($it['return_quantity'] ?? ($it['quantity'] ?? 1));
            $unitPrc = (float)($it['original_price'] ?? ($it['price'] ?? 0));

            safe_exec($db, "
                INSERT INTO return_items (return_id, product_id, product_name, quantity, price)
                VALUES (?, ?, ?, ?, ?)
            ", [$return_id, $p_id, $p_name, $qty, $unitPrc]);
        }

        // Notify Distributors
        $distributors = safe_query($db, "SELECT id FROM users WHERE user_type = 'distributor'");
        $notifMsg = "New Return Request from Outlet #$userId for Invoice: $inv_no. Credit Amount: ₹" . number_format($credit_amt, 2);
        foreach ($distributors as $d) {
            safe_exec($db, "
                INSERT INTO notifications (user_id, type, message, related_id, is_read, created_at)
                VALUES (?, 'distributor', ?, ?, 0, NOW())
            ", [(int)$d['id'], $notifMsg, $return_id]);
        }

        ok([
            'return_id'      => $return_id,
            'status'         => 'Pending',
            'credit_amount'  => $credit_amt,
            'resolution_type'=> $resolution
        ], 'Return request submitted successfully.');
    }

    // ACTION B: CLAIM APPROVED RETURN (CREDIT NOTE OR REPLACEMENT)
    if ($action === 'claim_return' || isset($input['claim_return'])) {
        $return_id = (int)($input['return_id'] ?? 0);
        if ($return_id <= 0) fail('Valid return_id is required.', 422);

        $ret = safe_one($db, "SELECT * FROM returns WHERE id = ? AND user_id = ? AND status = 'Approved' LIMIT 1", [$return_id, $userId]);
        if (!$ret) fail('Approved return record not found or already claimed.', 404);

        $resType   = $ret['resolution_type'] ?? 'Credit Note';
        $creditAmt = (float)($ret['credit_amount'] ?? 0.0);

        if ($resType === 'Credit Note') {
            if ($creditAmt > 0) {
                safe_exec($db, "UPDATE retailer SET credit_limit = credit_limit + ? WHERE user_id = ?", [$creditAmt, $userId]);
                safe_exec($db, "
                    UPDATE returns 
                    SET status = 'Claimed', claim_status = 'completed', claimed_at = NOW() 
                    WHERE id = ?
                ", [$return_id]);

                $updatedLimitRow = safe_one($db, "SELECT credit_limit FROM retailer WHERE user_id = ?", [$userId]);
                $newLimit = (float)($updatedLimitRow['credit_limit'] ?? 0.0);

                ok([
                    'return_id'            => $return_id,
                    'claimed_amount'       => $creditAmt,
                    'updated_credit_limit' => $newLimit,
                    'resolution_type'      => 'Credit Note'
                ], "Success: ₹" . number_format($creditAmt, 2) . " added to credit limit.");
            } else {
                fail('No valid credit amount found for this return.', 400);
            }
        } else {
            // Replacement: Add items to cart with price = 0
            safe_exec($db, "DELETE FROM retailer_cart WHERE return_id = ? AND is_return_claim = 1 AND user_id = ?", [$return_id, $userId]);

            $items = safe_query($db, "SELECT * FROM return_items WHERE return_id = ?", [$return_id]);
            $added = 0;

            foreach ($items as $it) {
                $pid = (int)$it['product_id'];
                $qty = (int)$it['quantity'];
                $pname = (string)$it['product_name'];

                if ($pid > 0 && $qty > 0) {
                    safe_exec($db, "
                        INSERT INTO retailer_cart (user_id, product_id, product_name, price, total_price, product_quantity, is_return_claim, return_id, created_date)
                        VALUES (?, ?, ?, 0.00, 0.00, ?, 1, ?, NOW())
                    ", [$userId, $pid, $pname, $qty, $return_id]);
                    $added++;
                }
            }

            if ($added > 0) {
                safe_exec($db, "
                    UPDATE returns 
                    SET status = 'Claimed', claim_status = 'pending_checkout', claimed_at = NOW(), cart_processed = 1
                    WHERE id = ?
                ", [$return_id]);

                ok([
                    'return_id'       => $return_id,
                    'items_added'     => $added,
                    'resolution_type' => 'Replacement',
                    'next_screen'     => 'cart'
                ], "$added replacement product(s) added to cart with ₹0 cost.");
            } else {
                fail('No products found to add to replacement cart.', 400);
            }
        }
    }
}

// ── 5. GET ACTIONS: SUGGESTIONS, INVOICE ITEMS & HISTORY ──
$action = strtolower(trim($_GET['action'] ?? ''));

// 1. Search Invoices Autocomplete
if ($action === 'search_invoices' || isset($_GET['search_invoices'])) {
    $search = trim($_GET['search_invoices'] ?? ($_GET['query'] ?? ''));
    $invoices = safe_query($db, "
        SELECT invoice_number, invoice_date, subtotal, payment_status, pdf_path 
        FROM invoices 
        WHERE (retailer_id = ? OR user_id = ?) 
          AND invoice_number LIKE ? 
        ORDER BY invoice_date DESC LIMIT 10
    ", [$userId, $userId, "%$search%"]);

    $results = [];
    foreach ($invoices as $inv) {
        $results[] = [
            'invoice_number' => $inv['invoice_number'],
            'invoice_date'   => $safeDate($inv['invoice_date']),
            'subtotal'       => (float)($inv['subtotal'] ?? 0.0),
            'payment_status' => $inv['payment_status'] ?? 'Pending',
            'has_pdf'        => !empty($inv['pdf_path'])
        ];
    }

    ok(['invoices' => $results], 'Invoice suggestions loaded.');
}

// 2. Fetch Invoice Line Items for Selection
if ($action === 'fetch_invoice' || isset($_GET['fetch_invoice'])) {
    $inv_no = trim($_GET['invoice_number'] ?? '');
    if (empty($inv_no)) fail('Invoice number required.', 422);

    $invoice = safe_one($db, "
        SELECT * FROM invoices 
        WHERE invoice_number = ? AND (retailer_id = ? OR user_id = ?) 
        LIMIT 1
    ", [$inv_no, $userId, $userId]);

    if (!$invoice) fail('Invoice not found for this retailer.', 404);

    $items = [];
    $order_id = (int)($invoice['order_id'] ?? 0);

    if ($order_id > 0) {
        $rawItems = safe_query($db, "
            SELECT rod.*, COALESCE(p.units, 1) as units, COALESCE(p.mrp_bottle, 0.00) as mrp_bottle
            FROM retailer_order_details rod
            LEFT JOIN products p ON rod.product_id = p.id
            WHERE rod.order_id = ?
            ORDER BY rod.id ASC
        ", [$order_id]);

        foreach ($rawItems as $it) {
            $units = max(1, (int)$it['units']);
            $cases = (int)$it['product_quantity'];
            $totalBottles = $cases * $units;
            $casePrice = (float)$it['product_price'];
            $bottlePrice = (float)$it['mrp_bottle'] > 0 ? (float)$it['mrp_bottle'] : ($casePrice / $units);

            $items[] = [
                'detail_id'     => (int)$it['id'],
                'product_id'    => (int)$it['product_id'],
                'product_name'  => (string)$it['product_name'],
                'cases_ordered' => $cases,
                'units_per_case'=> $units,
                'total_bottles' => $totalBottles,
                'case_price'    => $casePrice,
                'bottle_price'  => round($bottlePrice, 2)
            ];
        }
    }

    ok([
        'invoice_number' => $invoice['invoice_number'],
        'invoice_date'   => $safeDate($invoice['invoice_date']),
        'subtotal'       => (float)($invoice['subtotal'] ?? 0.0),
        'pdf_path'       => $invoice['pdf_path'] ?? null,
        'items'          => $items
    ], 'Invoice details retrieved.');
}

// 3. Default GET: List Returns History
$status_filter = trim($_GET['status'] ?? '');
$res_filter    = trim($_GET['resolution'] ?? ($_GET['res'] ?? ''));
$search_query  = trim($_GET['search'] ?? '');

$limit  = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$where_clauses = ["user_id = ?", "(invoice_number NOT LIKE 'TRF%' AND invoice_number NOT LIKE 'TRF-%')"];
$params = [$userId];

if ($status_filter !== '') {
    if ($status_filter === 'Rejected') {
        $where_clauses[] = "status LIKE 'Rejected%'";
    } else {
        $where_clauses[] = "status = ?";
        $params[] = $status_filter;
    }
}
if ($res_filter !== '') {
    $where_clauses[] = "resolution_type = ?";
    $params[] = $res_filter;
}
if ($search_query !== '') {
    $where_clauses[] = "(invoice_number LIKE ? OR reason LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$where_sql = "WHERE " . implode(' AND ', $where_clauses);

$countRow = safe_one($db, "SELECT COUNT(*) as total FROM returns $where_sql", $params);
$total_returns = (int)($countRow['total'] ?? 0);
$total_pages   = $total_returns > 0 ? (int)ceil($total_returns / $limit) : 1;

$returnsList = safe_query($db, "
    SELECT * FROM returns 
    $where_sql 
    ORDER BY created_at DESC 
    LIMIT $offset, $limit
", $params);

$formattedReturns = [];
foreach ($returnsList as $r) {
    $rid = (int)$r['id'];
    $itemsJson = json_decode($r['return_items'] ?? '[]', true) ?: [];

    $formattedReturns[] = [
        'id'              => $rid,
        'invoice_number'  => (string)$r['invoice_number'],
        'reason'          => (string)$r['reason'],
        'description'     => (string)($r['description'] ?? ''),
        'resolution_type' => (string)($r['resolution_type'] ?? 'Credit Note'),
        'credit_amount'   => (float)($r['credit_amount'] ?? 0.0),
        'status'          => (string)($r['status'] ?? 'Pending'),
        'claim_status'    => $r['claim_status'] ?? null,
        'is_claimable'    => (strtolower($r['status'] ?? '') === 'approved'),
        'created_at'      => $r['created_at'] ?? '',
        'formatted_date'  => $safeDate($r['created_at'] ?? null),
        'items'           => $itemsJson
    ];
}

ok([
    'total_returns' => $total_returns,
    'pagination'    => [
        'current_page' => $page,
        'limit'        => $limit,
        'total_pages'  => $total_pages,
        'has_next'     => ($page < $total_pages),
        'has_prev'     => ($page > 1)
    ],
    'returns'       => $formattedReturns
], 'Returns history loaded successfully.');