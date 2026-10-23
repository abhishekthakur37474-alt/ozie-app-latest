<?php
if (!defined('OZIE_API')) exit('Direct access not permitted.');

if (!function_exists('ok')) { function ok($data=null,$msg='OK'){http_response_code(200);header('Content-Type: application/json');echo json_encode(['success'=>true,'message'=>$msg,'data'=>$data]);exit;} }
if (!function_exists('fail')) { function fail($msg='Error',$code=400){http_response_code($code);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>$msg]);exit;} }

$db = null; if (function_exists('db')) { try { $db = db(); } catch (\Throwable $e) {} }
if (!$db && isset($GLOBALS['conn'])) $db = $GLOBALS['conn'];
if (!$db) { $siteRoot = dirname(__DIR__, 3); if(file_exists($siteRoot.'/db.php')) { @include_once $siteRoot.'/db.php'; $db = $conn ?? null; } }

function safe_exec($db, $sql, $params = []) {
    if (!$db) return false;
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); return $stmt->execute($params); }
        if ($db instanceof mysqli) {
            $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); }
            return (bool)$db->query($esc);
        }
    } catch (\Throwable $e) {} return false;
}
function safe_query($db, $sql, $params = []) {
    try { if ($db instanceof PDO) { $stmt=$db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[]; }
        if ($db instanceof mysqli) {
            $esc=$sql; foreach($params as $v){ $r=is_numeric($v)?$v:"'".mysqli_real_escape_string($db,$v)."'"; $esc=preg_replace('/\?/',$r,$esc,1); }
            $res=$db->query($esc); return $res?$res->fetch_all(MYSQLI_ASSOC):[];
        }
    } catch (\Throwable $e) {} return [];
}

// Ensure schema is updated
safe_exec($db, "ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS satisfaction_rating VARCHAR(20) DEFAULT NULL");

$user = require_auth();
$userId = (int)($user['id'] ?? 0);
$userType = strtolower(trim($user['user_type'] ?? 'retailer'));
if ($userId <= 0) fail('Auth required.', 401);

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) { $json = json_decode($rawBody, true); if(is_array($json)) $input = array_merge($input, $json); }

$action = strtolower(trim($input['action'] ?? ($_GET['action'] ?? '')));
$uploadDir = dirname(__DIR__, 3) . '/uploads/';

// 1. SUBMIT TICKET
if ($action === 'submit_ticket') {
    $type = trim($input['ticket_type'] ?? '');
    $subject = trim($input['subject'] ?? '');
    $desc = trim($input['description'] ?? '');
    $orderId = trim($input['order_id'] ?? '');

    if (empty($type) || empty($subject) || empty($desc)) fail('Ticket type, subject, and description are required.', 422);

    $ticketNo = "TK-" . mt_rand(100000, 999999);
    $attachment = null;

    if (!empty($_FILES['attachment']['name'])) {
        $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $attachment = "TKT_" . time() . "_" . uniqid() . "." . $ext;
        move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $attachment);
    } elseif (!empty($input['attachment_base64'])) {
        $b64 = preg_replace('/^data:image\/\w+;base64,/', '', $input['attachment_base64']);
        $attachment = "TKT_" . time() . "_" . uniqid() . ".jpg";
        file_put_contents($uploadDir . $attachment, base64_decode($b64));
    }

    $success = safe_exec($db, "INSERT INTO support_tickets (ticket_number, user_id, user_type, ticket_type, subject, description, order_id, attachment, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Open', NOW(), NOW())", [$ticketNo, $userId, $userType, $type, $subject, $desc, $orderId, $attachment]);

    if ($success) {
        $dbId = safe_query($db, "SELECT id FROM support_tickets WHERE ticket_number = ?", [$ticketNo])[0]['id'] ?? 0;
        $msg = "New support ticket raised: $ticketNo ($type)";
        safe_exec($db, "INSERT INTO notifications (user_id, type, message, related_id, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())", [$userId, $userType, $msg, $dbId]);
        safe_exec($db, "INSERT INTO admin_notifications (title, message, is_read, created_at, user_id) VALUES (?, ?, 0, NOW(), ?)", ["Ticket Raised: $ticketNo", "Ticket #$ticketNo raised by Retailer #$userId: $subject", $userId]);

        ok(['ticket_number' => $ticketNo, 'status' => 'Open'], 'Ticket submitted successfully.');
    } else fail('Failed to submit ticket.', 500);
}

// 2. RATE TICKET
if ($action === 'rate_ticket') {
    $tid = (int)($input['ticket_id'] ?? 0);
    $rating = strtolower(trim($input['rating'] ?? ''));
    if ($tid <= 0 || !in_array($rating, ['satisfied', 'unsatisfied'])) fail('Invalid ticket ID or rating.', 422);

    $upd = safe_exec($db, "UPDATE support_tickets SET satisfaction_rating = ?, updated_at = NOW() WHERE id = ? AND user_id = ?", [$rating, $tid, $userId]);
    if ($upd) ok(['ticket_id' => $tid, 'rating' => $rating], 'Feedback recorded successfully.');
    else fail('Failed to save rating.', 500);
}

// 3. GET HISTORY
$list = safe_query($db, "SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$siteBaseUrl = $protocol . ($_SERVER['HTTP_HOST'] ?? 'demo.orderezee.com') . '/';

$res = [];
foreach ($list as $t) {
    $res[] = [
        'id'                  => (int)$t['id'],
        'ticket_number'       => $t['ticket_number'],
        'ticket_type'         => $t['ticket_type'],
        'subject'             => $t['subject'],
        'description'         => $t['description'],
        'status'              => $t['status'],
        'satisfaction_rating' => $t['satisfaction_rating'],
        'solution_comment'    => $t['solution_comment'] ?? null,
        'solved_by'           => $t['solved_by'] ?? null,
        'solved_at'           => $t['solved_at'] ?? null,
        'attachment_url'      => !empty($t['attachment']) ? $siteBaseUrl . 'uploads/' . $t['attachment'] : null,
        'solution_doc_url'    => !empty($t['solution_attachment']) ? $siteBaseUrl . 'uploads/' . $t['solution_attachment'] : null,
        'created_at'          => $t['created_at']
    ];
}

ok(['tickets' => $res], 'Support history loaded.');