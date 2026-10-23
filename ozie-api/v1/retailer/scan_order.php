<?php
/**
 * Ozie API — Retailer AI Purchase Order Scanner & Matching
 * Endpoint:
 *   POST /v1/retailer/scan-order (action: "scan" | "recalculate" | "confirm")
 *   GET  /v1/retailer/scan-order (Catalog reference & schemes)
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Compatibility Wrapper ──────────────────────
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

// ── 2. Universal Bulletproof Query Helpers ─────────────────
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
            error_log("[ScanOrder Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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
            error_log("[ScanOrder Exec Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return false;
    }
}

// ── 3. Authentication & User Setup ─────────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$userId   = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($userId <= 0) {
    fail('Authentication required.', 401);
}

if ($userType !== 'retailer' && $userType !== 'salesman') {
    $checkRet = safe_one($db, "SELECT id, user_id FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
    if (!empty($checkRet)) {
        $userType = 'retailer';
        if (!empty($checkRet['user_id'])) {
            $userId = (int)$checkRet['user_id'];
        }
    }
}

// Configuration
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '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');
}

$siteRoot  = dirname(__DIR__, 3);
$uploadDir = $siteRoot . '/uploads/orders/';
if (!file_exists($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// ── 4. String & Token Fuzzy Matching Functions ────────────
function normalize_product_match_text($text) {
    $text = mb_strtolower(trim((string)$text), 'UTF-8');
    $text = str_replace(
        ['whiskey', 'millilitres', 'milliliters', 'millilitre', 'milliliter', 'litres', 'liters', 'litre', 'liter'],
        ['whisky', 'ml', 'ml', 'ml', 'ml', 'l', 'l', 'l', 'l'],
        $text
    );
    $text = preg_replace('/(\d)\s+(ml|l)\b/u', '$1$2', $text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

function product_match_tokens($text) {
    $tokens = preg_split('/\s+/u', normalize_product_match_text($text), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($tokens, static function ($token) {
        return mb_strlen($token, 'UTF-8') >= 2 && !preg_match('/^\d+$/', $token);
    }));
}

function product_text_similarity($left, $right) {
    $left = normalize_product_match_text($left);
    $right = normalize_product_match_text($right);
    if ($left === '' || $right === '') return 0.0;
    if ($left === $right) return 1.0;

    similar_text($left, $right, $percent);
    $similarScore = $percent / 100;
    $maxLength = max(strlen($left), strlen($right));
    $levenshteinScore = $maxLength > 0
        ? max(0, 1 - (levenshtein(substr($left, 0, 250), substr($right, 0, 250)) / $maxLength))
        : 0;
    return max($similarScore, $levenshteinScore);
}

function extract_product_unit($text) {
    $text = normalize_product_match_text($text);
    if (!preg_match('/\b(\d+(?:\.\d+)?)\s*(ml|l)\b/u', $text, $match)) return null;
    $amount = (float)$match[1];
    if ($match[2] === 'l') $amount *= 1000;
    return (int)round($amount) . 'ml';
}

function score_scanned_product_match($rawName, array $product) {
    $raw = normalize_product_match_text($rawName);
    $name = normalize_product_match_text(($product['name'] ?? '') . ' ' . ($product['brand'] ?? ''));
    if ($raw === '' || $name === '') return 0.0;

    $rawTokens = product_match_tokens($raw);
    $catalogTokens = product_match_tokens($name);
    $tokenTotal = 0.0;
    foreach ($rawTokens as $rawToken) {
        $bestToken = 0.0;
        foreach ($catalogTokens as $catalogToken) {
            $bestToken = max($bestToken, product_text_similarity($rawToken, $catalogToken));
        }
        $tokenTotal += $bestToken;
    }
    $tokenScore = count($rawTokens) ? $tokenTotal / count($rawTokens) : 0.0;
    $fullScore = product_text_similarity($raw, $name);
    $containsScore = (str_contains($name, $raw) || str_contains($raw, $name)) ? 1.0 : 0.0;

    $brand = normalize_product_match_text($product['brand'] ?? '');
    $brandScore = ($brand !== '' && (str_contains($raw, $brand) || product_text_similarity($raw, $brand) >= 0.75)) ? 1.0 : 0.0;

    $rawUnit = extract_product_unit($rawName);
    $catalogUnit = extract_product_unit($product['unit_size'] ?? '');
    $unitScore = ($rawUnit && $catalogUnit && $rawUnit === $catalogUnit) ? 1.0 : 0.0;

    $score = ($tokenScore * 0.52) + ($fullScore * 0.28) + ($containsScore * 0.08)
        + ($brandScore * 0.05) + ($unitScore * 0.07);
    if ($rawUnit && $catalogUnit && $rawUnit !== $catalogUnit) $score *= 0.68;
    return min(1.0, $score);
}

function suggest_scanned_products($rawName, array $catalog, $limit = 5) {
    $ranked = [];
    foreach ($catalog as $product) {
        $score = score_scanned_product_match($rawName, $product);
        if ($score >= 0.30) {
            $ranked[] = ['id' => (int)$product['id'], 'score' => $score];
        }
    }
    usort($ranked, static function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($ranked, 0, max(1, (int)$limit));
}

function apply_spelling_aware_product_matches(array $items, array $catalog) {
    $validIds = [];
    $catalogById = [];
    foreach ($catalog as $product) {
        $id = (int)$product['id'];
        $validIds[$id] = true;
        $catalogById[$id] = $product;
    }

    foreach ($items as &$item) {
        $rawName = trim((string)($item['extracted_name'] ?? ''));
        $suggestions = suggest_scanned_products($rawName, $catalog, 5);
        $item['suggested_product_ids'] = array_column($suggestions, 'id');
        $item['match_confidence'] = isset($suggestions[0]) ? round($suggestions[0]['score'] * 100) : 0;

        $currentId = (int)($item['product_id'] ?? 0);

        if ($currentId > 0 && isset($validIds[$currentId])) {
            $aiProduct = $catalogById[$currentId];
            $score = score_scanned_product_match($rawName, $aiProduct);
            if ($score < 0.25) {
                $currentId = 0;
                $item['product_id'] = null;
            }
        }

        if ($currentId > 0 && isset($validIds[$currentId])) {
            continue;
        }

        $topScore = $suggestions[0]['score'] ?? 0.0;
        $secondScore = $suggestions[1]['score'] ?? 0.0;
        $hasPackSize = extract_product_unit($rawName) !== null;
        $isClearMatch = $topScore >= 0.58 && ($hasPackSize || $secondScore === 0.0 || ($topScore - $secondScore) >= 0.045);
        $item['product_id'] = $isClearMatch ? (int)$suggestions[0]['id'] : null;
    }
    unset($item);
    return $items;
}

function analyzeImageWithVision($filePath, $catalogJson) {
    if (!file_exists($filePath)) {
        throw new Exception("The uploaded document/image file is missing.");
    }

    $imageData = base64_encode(file_get_contents($filePath));
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimes = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp'
    ];
    $mimeType = $mimes[$ext] ?? 'image/jpeg';

    $prompt = "You are an order processing extraction module. Read the text from this purchase order or invoice image. Match the line items inside the image to the most appropriate item in the provided master database catalog. All pricing is analyzed PER CASE.

Determine matches using the 'name', 'brand', and 'unit_size' properties. OCR text may contain spelling mistakes, missing letters, phonetic spellings, or visually confused characters. Use spelling-tolerant matching to select the closest catalog product, but NEVER invent a product that is unrelated. If an item in the image does not exist in the master catalog, set product_id to null. Preserve the exact text read from the image in extracted_name.
Return a structural JSON payload. Do not wrap the JSON output in markdown formatting.

Master Catalog database items:
" . $catalogJson . "

Expected JSON Format Output:
{
  \"items\": [
    {
      \"product_id\": MATCHED_PRODUCT_ID_OR_NULL,
      \"extracted_name\": \"Raw product name or text string as listed in the invoice\",
      \"quantity\": 1,
      \"price\": 1200.50
    }
  ]
}";

    $payload = [
        "model" => "gpt-4o",
        "response_format" => ["type" => "json_object"],
        "messages" => [
            [
                "role" => "user",
                "content" => [
                    [
                        "type" => "text",
                        "text" => $prompt
                    ],
                    [
                        "type" => "image_url",
                        "image_url" => [
                            "url" => "data:" . $mimeType . ";base64," . $imageData
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . OPENAI_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("OpenAI Connection Error: " . $curlError);
    }

    if ($httpCode !== 200) {
        $errorDetails = json_decode($response, true);
        $errMessage = $errorDetails['error']['message'] ?? 'OpenAI API returned error code ' . $httpCode;
        throw new Exception($errMessage);
    }

    $responseDecoded = json_decode($response, true);
    $content = $responseDecoded['choices'][0]['message']['content'] ?? '';
    $parsedJson = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse structural response from AI Vision engine.");
    }

    return $parsedJson;
}

function evaluateBackendSchemes($db, array $currentItems, $retailerCategory) {
    $today = date('Y-m-d');
    $schemes = safe_query($db, "
        SELECT s.*, p.name AS product_name, p.price AS product_price, p.brand AS product_brand, p.category AS product_category 
        FROM schemes s 
        LEFT JOIN products p ON s.product_id = p.id 
        WHERE s.status = 'active' 
          AND s.start_date IS NOT NULL AND s.start_date != '0000-00-00'
          AND s.end_date IS NOT NULL AND s.end_date != '0000-00-00'
          AND ? BETWEEN s.start_date AND s.end_date
          AND (s.category = 'ALL' OR FIND_IN_SET(?, REPLACE(s.category,' ','')) > 0 OR s.category = '' OR s.category IS NULL)
    ", [$today, $retailerCategory]);

    $totalDiscount = 0.0;
    $appliedList = [];
    $suggestedList = [];

    foreach ($schemes as $scheme) {
        $minQty = (int)($scheme['min_qty'] ?? 0) ?: (int)($scheme['buy_qty'] ?? 0);
        $schemeBrand = strtolower(trim((string)($scheme['brand'] ?? '')));
        $schemeCategory = strtolower(trim((string)($scheme['category'] ?? '')));
        $isRetailerBand = in_array($schemeCategory, ['a', 'b', 'c', 'd'], true);

        if (!empty($scheme['product_id']) && (int)$scheme['product_id'] !== 0) {
            $matchedItem = null;
            foreach ($currentItems as $it) {
                if ((int)$it['product_id'] === (int)$scheme['product_id']) {
                    $matchedItem = $it;
                    break;
                }
            }

            if ($matchedItem) {
                $qty = (int)$matchedItem['quantity'];
                $price = (float)$matchedItem['price'];

                if ($qty >= $minQty) {
                    $discountVal = 0.0;
                    $stype = strtoupper($scheme['scheme_type'] ?? '');

                    if ($stype === 'DISCOUNT') {
                        $discountVal = ($price * $qty) * ((float)$scheme['discount'] / 100);
                    } elseif ($stype === 'FLAT') {
                        $discountVal = (float)$scheme['discount'];
                    } elseif ($stype === 'FREE') {
                        $buyQty = (int)($scheme['buy_qty'] ?? 1) ?: 1;
                        $freeQty = (int)($scheme['free_qty'] ?? 1) ?: 1;
                        $multiplier = floor($qty / $buyQty);
                        $discountVal = $multiplier * $freeQty * $price;
                    }

                    if ($discountVal > 0) {
                        $totalDiscount += $discountVal;
                        $appliedList[] = [
                            'title'    => $scheme['scheme_title'] ?? 'Scheme',
                            'code'     => $scheme['coupon_code'] ?? null,
                            'discount' => round($discountVal, 2)
                        ];
                    }
                } else {
                    $needed = $minQty - $qty;
                    $suggestedList[] = [
                        'title'   => $scheme['scheme_title'] ?? 'Scheme',
                        'message' => "Add {$needed} more unit(s) of " . ($matchedItem['name'] ?? 'product') . " to qualify."
                    ];
                }
            }
        }
    }

    return [
        'total_discount' => round($totalDiscount, 2),
        'applied'        => $appliedList,
        'suggested'      => $suggestedList
    ];
}

// ── 5. Input Extraction ────────────────────────────────────
$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));

// Retailer category resolution
$retRow = safe_one($db, "SELECT category FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
$retailerCategory = trim($retRow['category'] ?? 'ALL');

// ── 6. ACTION: SCAN DOCUMENT / IMAGE (AI VISION) ───────────
if ($action === 'scan' || $action === 'upload_scan') {
    $imagePathForAPI = null;

    try {
        // Scenario A: Base64 string from Live Camera
        $cameraData = $input['camera_image_data'] ?? ($input['image_base64'] ?? '');
        if (!empty($cameraData)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $cameraData, $type)) {
                $cameraData = substr($cameraData, strpos($cameraData, ',') + 1);
            }
            $decodedData = base64_decode($cameraData);
            if ($decodedData === false) {
                fail('Failed to decode camera image payload.', 422);
            }
            $targetFile = $uploadDir . time() . '_camera.jpg';
            if (file_put_contents($targetFile, $decodedData) === false) {
                fail('Failed to write camera snapshot on server storage.', 500);
            }
            $imagePathForAPI = $targetFile;
        }
        // Scenario B: Uploaded file
        elseif (isset($_FILES['order_file']) && $_FILES['order_file']['error'] === UPLOAD_ERR_OK) {
            $fileInfo   = $_FILES['order_file'];
            $fileName   = basename($fileInfo['name']);
            $targetFile = $uploadDir . time() . '_' . preg_replace("/[^A-Za-z0-9\.\-_]/", '', $fileName);

            if (!move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
                fail('Unable to store uploaded purchase order.', 500);
            }
            $imagePathForAPI = $targetFile;
        } else {
            fail('Please provide an image file (order_file) or camera base64 data (camera_image_data).', 422);
        }

        // Fetch catalog for prompt
        $catalog = safe_query($db, "SELECT id, name, brand, billing_case as price, unit_size, packing_type, category FROM products WHERE availability_status = 'Available'");
        $catalogJson = json_encode($catalog);

        // Analyze via OpenAI Vision
        $apiResult = analyzeImageWithVision($imagePathForAPI, $catalogJson);

        if (empty($apiResult['items'])) {
            fail('No line items could be extracted from the invoice/photo.', 422);
        }

        // Apply fuzzy & spelling-tolerant catalog mapping
        $items = apply_spelling_aware_product_matches($apiResult['items'], $catalog);

        // Catalog quick lookup map
        $catalogById = [];
        foreach ($catalog as $p) {
            $catalogById[(int)$p['id']] = $p;
        }

        $formattedItems = [];
        $subtotal = 0.0;

        foreach ($items as $it) {
            $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $catItem = $pid && isset($catalogById[$pid]) ? $catalogById[$pid] : null;

            $price = $catItem ? (float)$catItem['price'] : (float)($it['price'] ?? 0.0);
            $lineTotal = $qty * $price;
            $subtotal += $lineTotal;

            $formattedItems[] = [
                'product_id'            => $pid,
                'name'                  => $catItem ? (string)$catItem['name'] : (string)($it['extracted_name'] ?? 'Unmatched Line'),
                'extracted_name'        => (string)($it['extracted_name'] ?? ''),
                'quantity'              => $qty,
                'price'                 => $price,
                'subtotal'              => round($lineTotal, 2),
                'match_confidence'      => (int)($it['match_confidence'] ?? 0),
                'is_matched'            => !empty($pid),
                'suggested_product_ids' => $it['suggested_product_ids'] ?? [],
                'unit_size'             => $catItem['unit_size'] ?? '',
                'brand'                 => $catItem['brand'] ?? '',
                'category'              => $catItem['category'] ?? ''
            ];
        }

        // Evaluate schemes
        $schemeEval = evaluateBackendSchemes($db, $formattedItems, $retailerCategory);
        $grandTotal = max(0.0, $subtotal - $schemeEval['total_discount']);

        ok([
            'image_url'       => 'uploads/orders/' . basename($imagePathForAPI),
            'items_count'     => count($formattedItems),
            'items'           => $formattedItems,
            'subtotal'        => round($subtotal, 2),
            'scheme_savings'  => $schemeEval['total_discount'],
            'grand_total'     => round($grandTotal, 2),
            'applied_schemes' => $schemeEval['applied'],
            'suggested_schemes'=> $schemeEval['suggested']
        ], 'Invoice scanned and matched successfully.');

    } catch (\Throwable $e) {
        fail('Scan failed: ' . $e->getMessage(), 500);
    }
}

// ── 7. ACTION: RECALCULATE DRAFT TOTALS & SCHEMES ─────────
if ($action === 'recalculate') {
    $items = $input['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        fail('Items array is required for recalculation.', 422);
    }

    $subtotal = 0.0;
    $evaluatedItems = [];

    foreach ($items as $it) {
        $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        $qty = max(1, (int)($it['quantity'] ?? 1));
        $price = (float)($it['price'] ?? 0.0);

        if ($pid) {
            $cat = safe_one($db, "SELECT name, brand, category, billing_case FROM products WHERE id = ?", [$pid]);
            if ($cat) {
                if ($price <= 0) $price = (float)($cat['billing_case'] ?? 0.0);
                $it['name']     = $cat['name'];
                $it['brand']    = $cat['brand'];
                $it['category'] = $cat['category'];
            }
        }

        $lineTotal = $qty * $price;
        $subtotal += $lineTotal;

        $it['product_id'] = $pid;
        $it['quantity']   = $qty;
        $it['price']      = $price;
        $it['subtotal']   = round($lineTotal, 2);
        $evaluatedItems[] = $it;
    }

    $schemeEval = evaluateBackendSchemes($db, $evaluatedItems, $retailerCategory);
    $grandTotal = max(0.0, $subtotal - $schemeEval['total_discount']);

    ok([
        'items'             => $evaluatedItems,
        'subtotal'          => round($subtotal, 2),
        'scheme_savings'    => $schemeEval['total_discount'],
        'grand_total'       => round($grandTotal, 2),
        'applied_schemes'   => $schemeEval['applied'],
        'suggested_schemes' => $schemeEval['suggested']
    ], 'Order schemes recalculated.');
}

// ── 8. ACTION: CONFIRM & COMMIT TO CART (CHECKOUT READY) ───
if ($action === 'confirm' || $action === 'save_draft' || $action === 'add_to_cart') {
    $items = $input['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        fail('No items provided to commit to cart.', 422);
    }

    // Clear old cart contents for fresh scanned invoice items
    safe_exec($db, "DELETE FROM retailer_cart WHERE user_id = ?", [$userId]);

    $addedCount = 0;
    foreach ($items as $it) {
        $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        if (!$pid) continue; // Skip unmatched lines

        $qty   = max(1, (int)($it['quantity'] ?? 1));
        $price = (float)($it['price'] ?? 0.0);

        $prod = safe_one($db, "SELECT name, billing_case FROM products WHERE id = ?", [$pid]);
        $prodName = $prod ? (string)$prod['name'] : 'Matched Product';
        if ($price <= 0 && $prod) $price = (float)($prod['billing_case'] ?? 0.0);

        $sess = session_id() ?: '';
        safe_exec($db, "
            INSERT INTO retailer_cart (session_id, user_id, product_id, product_name, price, product_quantity, is_return_claim)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ", [$sess, $userId, $pid, $prodName, $price, $qty]);
        $addedCount++;
    }

    if ($addedCount === 0) {
        fail('No valid matched catalog products found to add to cart.', 400);
    }

    $cartRow = safe_one($db, "SELECT SUM(product_quantity) AS cnt FROM retailer_cart WHERE user_id = ? AND (is_return_claim = 0 OR is_return_claim IS NULL)", [$userId]);
    $cartCount = (int)($cartRow['cnt'] ?? 0);

    ok([
        'cart_count'      => $cartCount,
        'committed_items' => $addedCount,
        'next_screen'     => 'checkout'
    ], 'Items transferred to cart successfully. Proceed to checkout.');
}

// ── 9. GET: CATALOG REFERENCE & ACTIVE SCHEMES ─────────────
$productsCatalog = safe_query($db, "
    SELECT id, name, brand, billing_case as price, unit_size, category as product_category 
    FROM products 
    WHERE availability_status = 'Available'
    ORDER BY name ASC
");

$today = date('Y-m-d');
$activeSchemes = safe_query($db, "
    SELECT s.*, p.name AS product_name 
    FROM schemes s 
    LEFT JOIN products p ON s.product_id = p.id 
    WHERE s.status = 'active' 
      AND s.start_date IS NOT NULL AND s.start_date != '0000-00-00'
      AND s.end_date IS NOT NULL AND s.end_date != '0000-00-00'
      AND ? BETWEEN s.start_date AND s.end_date
      AND (s.category = 'ALL' OR FIND_IN_SET(?, REPLACE(s.category,' ','')) > 0 OR s.category = '' OR s.category IS NULL)
", [$today, $retailerCategory]);

ok([
    'retailer_category' => $retailerCategory,
    'catalog_count'     => count($productsCatalog),
    'catalog'           => $productsCatalog,
    'active_schemes'    => $activeSchemes
], 'Catalog reference loaded.');