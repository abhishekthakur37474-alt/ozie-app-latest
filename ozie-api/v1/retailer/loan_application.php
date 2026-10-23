<?php
/**
 * Ozie API — Retailer Bank Loan Application
 * Endpoint:
 *   GET  /v1/retailer/loan-applications (Profile auto-fill, form dropdown options, pending & completed loans)
 *   POST /v1/retailer/loan-applications (Submit new bank loan application)
 */

if (!defined('OZIE_API')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── 0. Response Compatibility Helper ───────────────────────
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

// ── 1. Database Connection Resolver (PDO & MySQLi) ─────────
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
            error_log("[LoanAPI Query Error] " . $e->getMessage() . " | SQL: " . $sql);
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
                    $rep = is_numeric($val) ? (string)$val : (is_null($val) ? "NULL" : "'" . mysqli_real_escape_string($db, (string)$val) . "'");
                    $pos = strpos($escapedSql, '?');
                    if ($pos !== false) $escapedSql = substr_replace($escapedSql, $rep, $pos, 1);
                }
                return (bool)$db->query($escapedSql);
            }
        } catch (\Throwable $e) {
            error_log("[LoanAPI Exec Error] " . $e->getMessage() . " | SQL: " . $sql);
        }
        return false;
    }
}

$safeDate = function ($dateStr, $format = 'd M Y, h:i A'): ?string {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime((string)$dateStr);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : null;
};

// ── 3. Authentication & Role Check ────────────────────────
$user = require_auth();
if (is_object($user)) {
    $user = (array)$user;
}

$userId   = (int)($user['id'] ?? $user['user_id'] ?? $user['uid'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? $user['role'] ?? $user['type'] ?? ''));

if ($userId <= 0) {
    fail('Authentication required.', 401);
}

// Fallback role check in retailer table
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
    fail('Access denied. Retailer account required.', 403);
}

// ── 4. Ensure Table & Supporting Columns Exist ────────────
safe_exec($db, "CREATE TABLE IF NOT EXISTS loan_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank_name VARCHAR(150) NOT NULL,
    credit_type VARCHAR(100) DEFAULT 'Bank Loan',
    company_name VARCHAR(255) NOT NULL,
    entity_type VARCHAR(150) NULL,
    year_established INT NULL,
    location VARCHAR(255) NULL,
    state VARCHAR(150) NULL,
    city VARCHAR(150) NULL,
    pincode VARCHAR(20) NULL,
    sales_turnover VARCHAR(100) NULL,
    registered_address TEXT NULL,
    phone_number VARCHAR(30) NULL,
    pan_number VARCHAR(100) NULL,
    gst_number VARCHAR(100) NULL,
    contact_person_name VARCHAR(255) NULL,
    loan_requirement VARCHAR(100) NULL,
    approved_amount DECIMAL(15,2) DEFAULT 0.00,
    remarks TEXT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_id (user_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Mark notification as read if nid provided
if (isset($_GET['nid']) && (int)$_GET['nid'] > 0) {
    $nid = (int)$_GET['nid'];
    safe_exec($db, "UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id = 0)", [$nid, $userId]);
}

// ── 5. Fetch Profile Auto-Fill Details ─────────────────────
$profile_data = [
    'name'           => '',
    'mobile'         => '',
    'email'          => '',
    'address'        => '',
    'city'           => '',
    'state'          => '',
    'pincode'        => '',
    'pan_number'     => '',
    'gst_number'     => '',
    'company_name'   => '',
    'entity_type'    => '',
    'year_established' => '2020',
    'location'       => ''
];

$u_row = safe_one($db, "SELECT name, email, mobile FROM users WHERE id = ? LIMIT 1", [$userId]);
if ($u_row) {
    $profile_data['name']   = trim($u_row['name'] ?? '');
    $profile_data['mobile'] = trim($u_row['mobile'] ?? '');
    $profile_data['email']  = trim($u_row['email'] ?? '');
}

$r_row = safe_one($db, "SELECT * FROM retailer WHERE user_id = ? OR id = ? LIMIT 1", [$userId, $userId]);
if ($r_row) {
    $profile_data['address']      = trim($r_row['address'] ?? '');
    $profile_data['city']         = trim($r_row['city'] ?? '');
    $profile_data['state']        = trim($r_row['state'] ?? '');
    $profile_data['pincode']      = trim($r_row['pincode'] ?? '');
    $profile_data['company_name'] = trim($r_row['shop_name'] ?? ($r_row['company_name'] ?? ($r_row['name'] ?? '')));
    $profile_data['pan_number']   = strtoupper(trim($r_row['pan_number'] ?? ($r_row['pan_no'] ?? ($r_row['pan'] ?? ''))));
    $profile_data['gst_number']   = strtoupper(trim($r_row['gst_number'] ?? ($r_row['gst_no'] ?? ($r_row['gstin'] ?? ''))));
    $profile_data['entity_type']  = trim($r_row['entity_type'] ?? ($r_row['business_type'] ?? ''));
    $profile_data['year_established'] = trim((string)($r_row['year_established'] ?? ($r_row['year_of_incorporation'] ?? '2020')));
    $profile_data['location']     = trim($r_row['location'] ?? ($r_row['area'] ?? ''));
}

if (empty($profile_data['location']) && !empty($profile_data['address'])) {
    $addr_parts = explode(',', $profile_data['address']);
    $profile_data['location'] = trim(($addr_parts[0] ?? '') . (isset($addr_parts[1]) ? ', ' . $addr_parts[1] : ''));
}

// ── 6. Form Static Dropdown Configuration ─────────────────
$form_options = [
    'banks' => [
        ['name' => 'Axis Bank', 'label' => 'Axis Bank', 'subtitle' => 'Financial Partner'],
        ['name' => 'Other Bank', 'label' => 'Other Bank', 'subtitle' => 'Financial Partner']
    ],
    'entity_types' => [
        'Sole Proprietorship',
        'Partnership Firm',
        'Limited Liability Partnership (LLP)',
        'Private Limited Company',
        'Public Limited Company'
    ],
    'sales_turnover_ranges' => [
        'Under ₹10 Lakhs',
        '₹10 Lakhs to ₹50 Lakhs',
        '₹50 Lakhs to ₹2 Crores',
        '₹2 Crores to ₹5 Crores',
        'Above ₹5 Crores'
    ],
    'loan_requirements' => [
        'Up to 7L',
        '7 - 15L',
        '15 - 30L',
        '30 - 50L'
    ]
];

// ── 7. POST: SUBMIT LOAN APPLICATION ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    $bank_name           = trim($input['bank_name'] ?? '');
    $credit_type         = 'Bank Loan';
    $company_name        = trim($input['company_name'] ?? $profile_data['company_name']);
    $contact_person_name = trim($input['contact_person_name'] ?? $profile_data['name']);
    $entity_type         = trim($input['entity_type'] ?? $profile_data['entity_type']);
    $year_established    = (int)($input['year_established'] ?? $profile_data['year_established']);
    $pan_number          = strtoupper(trim($input['pan_number'] ?? $profile_data['pan_number']));
    $gst_number          = strtoupper(trim($input['gst_number'] ?? $profile_data['gst_number']));
    $sales_turnover      = trim($input['sales_turnover'] ?? '');
    $loan_requirement    = trim($input['loan_requirement'] ?? '');
    $phone_number        = trim($input['phone_number'] ?? $profile_data['mobile']);
    $location            = trim($input['location'] ?? $profile_data['location']);
    $city                = trim($input['city'] ?? $profile_data['city']);
    $state               = trim($input['state'] ?? $profile_data['state']);
    $pincode             = trim($input['pincode'] ?? $profile_data['pincode']);
    $registered_address  = trim($input['registered_address'] ?? $profile_data['address']);
    $terms_acceptance    = !empty($input['terms_acceptance']);

    // Validation
    $errors = [];
    if (empty($bank_name))           $errors['bank_name'] = 'Please select a preferred financial partner bank.';
    if (empty($company_name))        $errors['company_name'] = 'Company legal name is required.';
    if (empty($contact_person_name)) $errors['contact_person_name'] = 'Contact person name is required.';
    if (empty($entity_type))         $errors['entity_type'] = 'Please select your entity structure.';
    if (empty($year_established))    $errors['year_established'] = 'Year of incorporation is required.';
    if (empty($pan_number))          $errors['pan_number'] = 'PAN number is required.';
    if (empty($sales_turnover))      $errors['sales_turnover'] = 'Please select your sales turnover range.';
    if (empty($loan_requirement))    $errors['loan_requirement'] = 'Please select your required loan amount.';
    if (empty($phone_number))        $errors['phone_number'] = 'Contact phone number is required.';
    if (empty($location))            $errors['location'] = 'Business location / area is required.';
    if (empty($city))                $errors['city'] = 'City is required.';
    if (empty($state))               $errors['state'] = 'State is required.';
    if (empty($pincode))             $errors['pincode'] = 'Pincode is required.';
    if (empty($registered_address))  $errors['registered_address'] = 'Registered address is required.';
    if (!$terms_acceptance)          $errors['terms_acceptance'] = 'You must authorize the submission terms to proceed.';

    if (!empty($errors)) {
        fail('Please complete all mandatory fields and accept terms.', 422, $errors);
    }

    $insertSql = "INSERT INTO loan_applications 
        (user_id, bank_name, credit_type, company_name, entity_type, year_established, location, state, city, pincode, sales_turnover, registered_address, phone_number, pan_number, gst_number, contact_person_name, loan_requirement, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";

    $params = [
        $userId, $bank_name, $credit_type, $company_name, $entity_type,
        $year_established, $location, $state, $city, $pincode,
        $sales_turnover, $registered_address, $phone_number,
        $pan_number, $gst_number, $contact_person_name, $loan_requirement
    ];

    $success = safe_exec($db, $insertSql, $params);
    if (!$success) {
        fail('Failed to record loan application in database.', 500);
    }

    // Get Application ID
    $appRow = safe_one($db, "SELECT id FROM loan_applications WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]);
    $app_id = (int)($appRow['id'] ?? 0);
    $appCode = "#LN-" . str_pad($app_id, 5, '0', STR_PAD_LEFT);

    // Notifications
    // 1. Retailer (Self)
    $notif_self = "Your loan application for {$bank_name} ({$appCode}) has been received and is under review.";
    safe_exec($db, "INSERT INTO notifications (user_id, message, type, related_id, is_read, created_at) VALUES (?, ?, 'loan_application', ?, 0, NOW())", [$userId, $notif_self, $app_id]);

    // 2. Brand & Manufacturer Users
    $brand_msg = "New Bank Loan Application submitted by {$company_name} for {$bank_name} (Requirement: {$loan_requirement})";
    $brand_users = safe_query($db, "SELECT id FROM users WHERE user_type = 'brand' OR user_type = 'manufacturer'");
    foreach ($brand_users as $b) {
        safe_exec($db, "INSERT INTO notifications (user_id, message, type, related_id, is_read, created_at) VALUES (?, ?, 'loan_application', ?, 0, NOW())", [(int)$b['id'], $brand_msg, $app_id]);
    }

    // 3. Bank Users
    $bank_msg = "New Bank Loan Application {$appCode} received from {$company_name}";
    $bank_users = safe_query($db, "SELECT id FROM users WHERE user_type = 'bank'");
    foreach ($bank_users as $bk) {
        safe_exec($db, "INSERT INTO notifications (user_id, message, type, related_id, is_read, created_at) VALUES (?, ?, 'loan_application', ?, 0, NOW())", [(int)$bk['id'], $bank_msg, $app_id]);
    }

    ok([
        'application_id'     => $app_id,
        'application_number' => $appCode,
        'bank_name'          => $bank_name,
        'status'             => 'Pending',
        'message_title'      => 'Form Submitted Successfully',
        'message_body'       => 'Your form has been successfully submitted to the respective bank. Your application is currently under review.'
    ], 'Loan application submitted successfully.');
}

// ── 8. GET: FETCH PROFILE, OPTIONS, PENDING & COMPLETED ───
$loans = safe_query($db, "SELECT * FROM loan_applications WHERE user_id = ? ORDER BY id DESC", [$userId]);

$pending_loans = [];
$completed_loans = [];

foreach ($loans as $row) {
    $lid = (int)$row['id'];
    $st  = strtolower(trim($row['status'] ?? 'pending'));

    $loanItem = [
        'id'                  => $lid,
        'application_number'  => "#LN-" . str_pad($lid, 5, '0', STR_PAD_LEFT),
        'bank_name'           => (string)($row['bank_name'] ?? 'Bank Partner'),
        'company_name'        => (string)($row['company_name'] ?? ''),
        'contact_person_name' => (string)($row['contact_person_name'] ?? 'N/A'),
        'loan_requirement'    => (string)($row['loan_requirement'] ?? 'N/A'),
        'approved_amount'     => (float)($row['approved_amount'] ?? 0.0),
        'remarks'             => !empty($row['remarks']) ? (string)$row['remarks'] : null,
        'status'              => (string)($row['status'] ?? 'Pending'),
        'created_at'          => $row['created_at'] ?? '',
        'formatted_date'      => $safeDate($row['created_at'] ?? null) ?? date('d M Y')
    ];

    if ($st === 'pending') {
        $pending_loans[] = $loanItem;
    } else {
        $completed_loans[] = $loanItem;
    }
}

ok([
    'profile' => [
        'company_name'     => $profile_data['company_name'],
        'contact_person'   => $profile_data['name'],
        'email'            => $profile_data['email'],
        'phone_number'     => $profile_data['mobile'],
        'entity_type'      => $profile_data['entity_type'],
        'year_established' => $profile_data['year_established'],
        'pan_number'       => $profile_data['pan_number'],
        'gst_number'       => $profile_data['gst_number'],
        'location'         => $profile_data['location'],
        'city'             => $profile_data['city'],
        'state'            => $profile_data['state'],
        'pincode'          => $profile_data['pincode'],
        'address'          => $profile_data['address'],
        'is_profile_complete' => (!empty($profile_data['pan_number']) && !empty($profile_data['company_name']))
    ],
    'options'          => $form_options,
    'pending_count'    => count($pending_loans),
    'pending_loans'    => $pending_loans,
    'completed_count'  => count($completed_loans),
    'completed_loans'  => $completed_loans
], 'Loan application data loaded successfully.');