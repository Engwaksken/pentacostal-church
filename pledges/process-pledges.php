<?php
declare(strict_types=1);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$mailFile = __DIR__ . '/../config/mail-function.php';
if (is_file($mailFile)) { require_once $mailFile; }
$smsFile = __DIR__ . '/../config/sms-function.php';
if (is_file($smsFile)) { require_once $smsFile; }
$notifyFile = __DIR__ . '/../includes/member-notify.php';
if (is_file($notifyFile)) { require_once $notifyFile; }

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}
$conn->set_charset('utf8mb4');

function pj(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/access.php';
    if (empty($_SESSION['user_id'])) pj(false, 'Your session has expired. Please sign in again.');
    if (is_member_user() || !current_user_can('pledges.view')) pj(false, 'Access denied.');
    // Anything that changes data must be a POST carrying the page's security token,
    // so another site cannot make a signed-in user's browser change pledges.
    $requestAction = (string)($_REQUEST['action'] ?? 'list');
    if (!in_array($requestAction, ['list', 'get', 'options'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pj(false, 'Invalid request method.');
        $sentToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        if ($sessionToken === '' || !hash_equals($sessionToken, $sentToken)) {
            pj(false, 'Security token expired. Please refresh the page and try again.');
        }
    }
}
require_once __DIR__ . '/../includes/pledge-types.php';
function clean($v): string { return trim((string)($v ?? '')); }
function hsql(string $v): string { global $conn; return $conn->real_escape_string($v); }
function table_exists(string $table): bool {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function col_exists(string $table, string $col): bool {
    global $conn;
    static $cache = [];
    $key = $table.'.'.$col;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function one(string $sql): ?array {
    global $conn;
    $res = $conn->query($sql);
    if (!$res) return null;
    $row = $res->fetch_assoc();
    $res->close();
    return $row ?: null;
}
function many(string $sql): array {
    global $conn;
    $rows = [];
    $res = $conn->query($sql);
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; $res->close(); }
    return $rows;
}
function next_no(string $prefix): string {
    return $prefix . '-' . date('Ymd-His') . '-' . random_int(100, 999);
}
/** Sets amount_paid from the pledge's payments and updates the status; a cancelled pledge stays cancelled. */
function recalc_pledge(int $pledgeId): void {
    global $conn;
    $p = one("SELECT amount_pledged, status FROM pledges WHERE id=$pledgeId LIMIT 1");
    if (!$p) return;
    $paid = (float)(one("SELECT COALESCE(SUM(amount),0) t FROM pledge_payments WHERE pledge_id=$pledgeId")['t'] ?? 0);
    $status = $p['status'] === 'Cancelled' ? 'Cancelled' : ($paid >= (float)$p['amount_pledged'] ? 'Fulfilled' : ($paid > 0 ? 'Partial' : 'Pending'));
    $stmt = $conn->prepare('UPDATE pledges SET amount_paid=?, status=? WHERE id=?');
    $stmt->bind_param('dsi', $paid, $status, $pledgeId);
    $stmt->execute();
    $stmt->close();
}
/** The pledge number assigned to the member in the members table ('' when none is set). */
function member_pledge_no(int $memberId): string {
    global $conn;
    if (!col_exists('members', 'pledge_no')) return '';
    $stmt = $conn->prepare("SELECT pledge_no FROM members WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return trim((string)($row['pledge_no'] ?? ''));
}
/**
 * Accepts YYYY-MM-DD, DD/MM/YYYY (also with - or .), Excel styles such as 4-Jan-2026 / 04-Jan-26,
 * and Excel date serial numbers. Returns YYYY-MM-DD, or '' when the date is not valid.
 */
function normalize_date(string $date): string {
    $date = trim($date);
    if ($date === '') return '';
    if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $date, $m)) {
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
    }
    if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})(?:[ T].*)?$/', $date, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
    }
    if (preg_match('/^\d{5}(?:\.\d+)?$/', $date)) { // Excel serial, e.g. 46026
        return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$date.' days')->format('Y-m-d');
    }
    $formats = preg_match('/\d{4}$/', $date) ? ['j-M-Y', 'j M Y', 'j F Y', 'M j, Y', 'F j, Y'] : ['j-M-y', 'j M y'];
    foreach ($formats as $format) {
        $d = DateTimeImmutable::createFromFormat('!'.$format, $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($d && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $d->format('Y-m-d');
    }
    return '';
}
const PLEDGE_STATUSES = ['Pending', 'Partial', 'Fulfilled', 'Cancelled'];
function member_name(array $r): string {
    $name = trim((string)($r['full_name'] ?? ''));
    return $name !== '' ? $name : 'Member';
}
function send_pledge_receipt_email(int $paymentId): bool {
    global $conn;
    if (!function_exists('sendSystemEmail')) return false;
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
    $payment = one("SELECT pp.*, p.pledge_no, p.amount_pledged, p.amount_paid, p.pledge_reason, p.status, p.due_date,
                    m.full_name AS full_name,
                    m.email, m.member_no, pr.name AS project_name $typeSelect
                    FROM pledge_payments pp
                    JOIN pledges p ON p.id = pp.pledge_id
                    LEFT JOIN members m ON m.id = p.member_id
                    LEFT JOIN projects pr ON pr.id = p.project_id $typeJoin
                    WHERE pp.id = " . (int)$paymentId . " LIMIT 1");
    if (!$payment || empty($payment['email']) || !filter_var($payment['email'], FILTER_VALIDATE_EMAIL)) return false;
    $name = member_name($payment);
    $purpose = pledge_purpose($payment);
    $amount = 'UGX ' . number_format((float)$payment['amount']);
    $receiptNo = htmlspecialchars((string)$payment['receipt_no'], ENT_QUOTES, 'UTF-8');
    $html = "<div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:20px'>
        <div style='max-width:650px;margin:auto;background:#fff;border-radius:12px;padding:22px;border:1px solid #e5e7eb'>
        <h2 style='color:#4a1e8f;margin-top:0'>Pledge Payment Receipt</h2>
        <p>Dear <strong>".htmlspecialchars($name, ENT_QUOTES, 'UTF-8')."</strong>,</p>
        <p>Thank you for your pledge payment. Your receipt has been recorded successfully.</p>
        <table style='width:100%;border-collapse:collapse;margin:15px 0'>
        <tr><td style='padding:9px;border:1px solid #e5e7eb;background:#fafafa'><b>Receipt No</b></td><td style='padding:9px;border:1px solid #e5e7eb'>{$receiptNo}</td></tr>
        <tr><td style='padding:9px;border:1px solid #e5e7eb;background:#fafafa'><b>Pledge No</b></td><td style='padding:9px;border:1px solid #e5e7eb'>".htmlspecialchars((string)$payment['pledge_no'], ENT_QUOTES, 'UTF-8')."</td></tr>
        <tr><td style='padding:9px;border:1px solid #e5e7eb;background:#fafafa'><b>Purpose</b></td><td style='padding:9px;border:1px solid #e5e7eb'>".htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')."</td></tr>
        <tr><td style='padding:9px;border:1px solid #e5e7eb;background:#fafafa'><b>Amount Paid</b></td><td style='padding:9px;border:1px solid #e5e7eb'><b>{$amount}</b></td></tr>
        <tr><td style='padding:9px;border:1px solid #e5e7eb;background:#fafafa'><b>Date Paid</b></td><td style='padding:9px;border:1px solid #e5e7eb'>".htmlspecialchars((string)$payment['paid_on'], ENT_QUOTES, 'UTF-8')."</td></tr>
        </table><p>God bless you.</p></div></div>";
    $text = "Dear {$name},\nThank you for your pledge payment.\nReceipt No: {$payment['receipt_no']}\nAmount: {$amount}\n";
    return sendSystemEmail($conn, (string)$payment['email'], $name, 'Pledge Payment Receipt - '.$payment['receipt_no'], $html, $text);
}
/** Whether a member wants this channel for a notification category (member app settings); yes when settings are not set up. */
function pledge_member_wants(?int $memberId, string $category, string $channel): bool {
    global $conn;
    if (!$memberId || !function_exists('mn_ready') || !mn_ready($conn)) return true;
    return mn_prefs($conn, $memberId)[$category][$channel] ?? true;
}
function send_pledge_reminder(array $pledge): array {
    global $conn;
    $memberId = (int)($pledge['member_id'] ?? 0) ?: null;
    $name = member_name($pledge);
    $balance = max(0, (float)$pledge['amount_pledged'] - (float)$pledge['amount_paid']);
    $purpose = pledge_purpose($pledge);
    $msg = "Dear {$name}, this is a reminder for your unpaid pledge {$pledge['pledge_no']} ({$purpose}). Balance: UGX " . number_format($balance) . ". Thank you.";
    $emailSent = false;
    $smsSent = false;
    if (!empty($pledge['email']) && filter_var($pledge['email'], FILTER_VALIDATE_EMAIL) && function_exists('sendSystemEmail') && pledge_member_wants($memberId, 'reminders', 'email')) {
        $html = "<div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:20px'><div style='max-width:650px;margin:auto;background:#fff;border-radius:12px;padding:22px;border:1px solid #e5e7eb'><h2 style='color:#4a1e8f'>Pledge Reminder</h2><p>Dear <strong>".htmlspecialchars($name, ENT_QUOTES, 'UTF-8')."</strong>,</p><p>This is a kind reminder about your unpaid pledge.</p><table style='width:100%;border-collapse:collapse'><tr><td style='border:1px solid #e5e7eb;padding:9px'><b>Pledge No</b></td><td style='border:1px solid #e5e7eb;padding:9px'>".htmlspecialchars((string)$pledge['pledge_no'], ENT_QUOTES, 'UTF-8')."</td></tr><tr><td style='border:1px solid #e5e7eb;padding:9px'><b>Purpose</b></td><td style='border:1px solid #e5e7eb;padding:9px'>".htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')."</td></tr><tr><td style='border:1px solid #e5e7eb;padding:9px'><b>Balance</b></td><td style='border:1px solid #e5e7eb;padding:9px'><b>UGX ".number_format($balance)."</b></td></tr><tr><td style='border:1px solid #e5e7eb;padding:9px'><b>Due Date</b></td><td style='border:1px solid #e5e7eb;padding:9px'>".htmlspecialchars((string)($pledge['due_date'] ?: 'Not specified'), ENT_QUOTES, 'UTF-8')."</td></tr></table><p>Thank you.</p></div></div>";
        $emailSent = sendSystemEmail($conn, (string)$pledge['email'], $name, 'Weekly Pledge Reminder - '.$pledge['pledge_no'], $html, $msg);
    }
    if (!empty($pledge['phone']) && function_exists('sendSms') && pledge_member_wants($memberId, 'reminders', 'sms')) {
        $smsSent = sendSms($conn, (string)$pledge['phone'], $msg, 'pledge_reminder', (int)$pledge['id']);
    }
    // Push goes out with the next run of cron/member_notifications.php.
    if ($memberId && function_exists('mn_ready') && mn_ready($conn)) {
        mn_enqueue($conn, $memberId, 'reminders', 'pledge_reminder:' . (int)$pledge['id'] . ':' . date('o-W'), 'Pledge reminder', $msg, ['push']);
    }
    return ['email_sent' => $emailSent, 'sms_sent' => $smsSent];
}

try {
    if (!table_exists('pledges')) pj(false, 'pledges table does not exist.');
    $action = $_REQUEST['action'] ?? 'list';

    if ($action === 'options') {
        pj(true, '', [
            'members' => many("SELECT id, member_no, full_name FROM members ORDER BY full_name"),
            'projects' => table_exists('projects') ? many("SELECT id, name FROM projects WHERE COALESCE(status,'Active')='Active' ORDER BY name") : [],
            'events' => table_exists('events') ? many("SELECT id, title, event_date FROM events ORDER BY event_date DESC") : []
        ]);
    }

    if ($action === 'list') {
        $status = clean($_GET['status'] ?? '');
        $where = $status !== '' ? "WHERE p.status='".hsql($status)."'" : '';
        [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
        $rows = many("SELECT p.*, m.full_name AS full_name, m.member_no, m.email, m.phone, pr.name AS project_name $typeSelect
            FROM pledges p
            LEFT JOIN members m ON m.id=p.member_id
            LEFT JOIN projects pr ON pr.id=p.project_id $typeJoin
            $where
            ORDER BY p.pledge_date DESC, p.id DESC");
        foreach ($rows as &$r) $r['purpose'] = pledge_purpose($r);
        unset($r);
        // Cancelled pledges are left out of the money totals.
        $stats = one("SELECT COALESCE(SUM(amount_pledged),0) total, COALESCE(SUM(amount_paid),0) paid,
            SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) pending,
            SUM(CASE WHEN status='Partial' THEN 1 ELSE 0 END) partial,
            SUM(CASE WHEN status='Fulfilled' THEN 1 ELSE 0 END) fulfilled,
            SUM(CASE WHEN status IN('Pending','Partial') AND due_date IS NOT NULL AND due_date<CURDATE() THEN 1 ELSE 0 END) overdue
            FROM pledges WHERE status<>'Cancelled'");
        pj(true, '', ['items' => $rows, 'stats' => $stats ?: []]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) pj(false, 'Invalid pledge ID.');
        [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
        $row = one("SELECT p.*, m.full_name AS full_name, m.member_no, m.email, m.phone, pr.name AS project_name $typeSelect
            FROM pledges p LEFT JOIN members m ON m.id=p.member_id LEFT JOIN projects pr ON pr.id=p.project_id $typeJoin WHERE p.id=$id LIMIT 1");
        if (!$row) pj(false, 'Pledge not found.');
        $row['purpose'] = pledge_purpose($row);
        $payments = table_exists('pledge_payments') ? many("SELECT * FROM pledge_payments WHERE pledge_id=$id ORDER BY paid_on DESC,id DESC") : [];
        pj(true, '', ['item' => $row, 'payments' => $payments]);
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $member_id = (int)($_POST['member_id'] ?? 0);
        $project_id = clean($_POST['project_id'] ?? '');
        $reason = clean($_POST['pledge_reason'] ?? '');
        $amount = (float)($_POST['amount_pledged'] ?? 0);
        $pledge_date = normalize_date(clean($_POST['pledge_date'] ?? '') ?: date('Y-m-d'));
        $dueInput = clean($_POST['due_date'] ?? '');
        $due = $dueInput !== '' ? normalize_date($dueInput) : '';
        $status = clean($_POST['status'] ?? 'Pending') ?: 'Pending';
        $notes = clean($_POST['notes'] ?? '');
        if ($member_id <= 0) pj(false, 'Please select a member.');
        if ($amount <= 0) pj(false, 'Amount pledged must be greater than zero.');
        if ($pledge_date === '') pj(false, 'Enter a valid pledge date.');
        if ($dueInput !== '' && $due === '') pj(false, 'Enter a valid due date.');
        if (!in_array($status, PLEDGE_STATUSES, true)) pj(false, 'Invalid pledge status.');
        // Only "Cancelled" is chosen by hand; other statuses are worked out from the payments.
        if ($status !== 'Cancelled') $status = 'Pending';
        $typeSet = '';
        if (pledge_types_ready($conn)) {
            $type = clean($_POST['pledge_type'] ?? '');
            if (!in_array($type, pledge_types(), true)) pj(false, 'Please choose what the pledge is for.');
            $event_id = (int)($_POST['event_id'] ?? 0);
            $year = (int)($_POST['pledge_year'] ?? 0);
            if ($type === 'Project' && (int)$project_id <= 0) pj(false, 'Please select the project.');
            if ($type === 'Event' && $event_id <= 0) pj(false, 'Please select the event.');
            if ($type === 'Annual' && ($year < 2000 || $year > 2100)) pj(false, 'Please enter the year of the annual pledge.');
            if ($type === 'Harvest Items' && $reason === '') pj(false, 'Please describe the harvest items pledged.');
            if ($type === 'Other' && $reason === '') pj(false, 'Please specify what the pledge is for.');
            if ($type !== 'Project') $project_id = '';
            $typeSet = "pledge_type='" . hsql($type) . "', pledge_year=" . ($type === 'Annual' ? $year : 'NULL') . ", event_id=" . ($type === 'Event' ? $event_id : 'NULL');
        }
        $pidSql = $project_id !== '' ? (string)(int)$project_id : 'NULL';
        $dueSql = $due !== '' ? "'".hsql($due)."'" : 'NULL';
        // The pledge number comes from the member's record, not a generated sequence.
        $memberPledgeNo = member_pledge_no($member_id);
        if ($memberPledgeNo === '') pj(false, 'This member has no pledge number. Set the Pledge No on the member record in Members first.');
        $no = hsql($memberPledgeNo);
        $amountSql = sprintf('%.2F', $amount);
        $typeSql = $typeSet !== '' ? ", $typeSet" : '';
        if ($id > 0) {
            if (!one("SELECT id FROM pledges WHERE id=$id LIMIT 1")) pj(false, 'Pledge not found.');
            $ok = $conn->query("UPDATE pledges SET pledge_no='$no', member_id=$member_id, project_id=$pidSql, pledge_reason='".hsql($reason)."', amount_pledged=$amountSql, pledge_date='".hsql($pledge_date)."', due_date=$dueSql, status='".hsql($status)."', notes='".hsql($notes)."'$typeSql WHERE id=$id");
            if (!$ok) pj(false, 'Update failed: '.$conn->error);
            recalc_pledge($id);
            pj(true, 'Pledge updated successfully.');
        }
        $ok = $conn->query("INSERT INTO pledges SET pledge_no='$no', member_id=$member_id, project_id=$pidSql, pledge_reason='".hsql($reason)."', amount_pledged=$amountSql, amount_paid=0, pledge_date='".hsql($pledge_date)."', due_date=$dueSql, status='".hsql($status)."', notes='".hsql($notes)."'$typeSql");
        if (!$ok) pj(false, 'Insert failed: '.$conn->error);
        $newId = (int)$conn->insert_id;
        pj(true, 'Pledge recorded successfully.', ['id' => $newId, 'pledge_no' => $memberPledgeNo]);
    }

    if ($action === 'pay') {
        if (!table_exists('pledge_payments')) pj(false, 'pledge_payments table does not exist.');
        $pledge_id = (int)($_POST['pledge_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $date = normalize_date(clean($_POST['paid_on'] ?? '') ?: date('Y-m-d'));
        $method = clean($_POST['method'] ?? 'Cash') ?: 'Cash';
        $notes = clean($_POST['notes'] ?? '');
        $p = $pledge_id > 0 ? one("SELECT * FROM pledges WHERE id=$pledge_id LIMIT 1") : null;
        if (!$p) pj(false, 'Pledge not found.');
        if ($p['status'] === 'Cancelled') pj(false, 'This pledge is cancelled. Change its status before recording a payment.');
        $balance = (float)$p['amount_pledged'] - (float)$p['amount_paid'];
        if ($amount <= 0) pj(false, 'Payment amount must be greater than zero.');
        if ($amount > $balance + 0.005) pj(false, 'Payment amount cannot exceed pledge balance.');
        if ($date === '') pj(false, 'Enter a valid payment date.');
        $rno = next_no('RCP');
        $stmt = $conn->prepare('INSERT INTO pledge_payments (pledge_id,amount,paid_on,method,receipt_no,notes) VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('idssss', $pledge_id, $amount, $date, $method, $rno, $notes);
        if (!$stmt->execute()) pj(false, 'Payment insert failed: '.$stmt->error);
        $paymentId = (int)$stmt->insert_id;
        $stmt->close();
        recalc_pledge($pledge_id);
        $emailSent = pledge_member_wants((int)($p['member_id'] ?? 0) ?: null, 'payments', 'email') && send_pledge_receipt_email($paymentId);
        pj(true, $emailSent ? 'Payment recorded and receipt emailed successfully.' : 'Payment recorded successfully.', [
            'payment_id' => $paymentId,
            'receipt_no' => $rno,
            'receipt_url' => 'receipt.php?payment_id=' . $paymentId,
            'email_sent' => $emailSent
        ]);
    }

    if ($action === 'update_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $date = normalize_date(clean($_POST['paid_on'] ?? ''));
        $method = clean($_POST['method'] ?? 'Cash') ?: 'Cash';
        $notes = clean($_POST['notes'] ?? '');
        $pay = $paymentId > 0 ? one("SELECT * FROM pledge_payments WHERE id=$paymentId LIMIT 1") : null;
        if (!$pay) pj(false, 'Payment not found.');
        if ($amount <= 0) pj(false, 'Payment amount must be greater than zero.');
        if ($date === '') pj(false, 'Enter a valid payment date.');
        $pledgeId = (int)$pay['pledge_id'];
        $p = one("SELECT amount_pledged FROM pledges WHERE id=$pledgeId LIMIT 1");
        if (!$p) pj(false, 'Pledge not found.');
        $others = (float)(one("SELECT COALESCE(SUM(amount),0) t FROM pledge_payments WHERE pledge_id=$pledgeId AND id<>$paymentId")['t'] ?? 0);
        $max = (float)$p['amount_pledged'] - $others;
        if ($amount > $max + 0.005) pj(false, 'Payments cannot exceed the amount pledged. The most this payment can be is UGX ' . number_format(max(0, $max)) . '.');
        $stmt = $conn->prepare('UPDATE pledge_payments SET amount=?, paid_on=?, method=?, notes=? WHERE id=?');
        $stmt->bind_param('dsssi', $amount, $date, $method, $notes, $paymentId);
        $stmt->execute();
        $stmt->close();
        recalc_pledge($pledgeId);
        pj(true, 'Payment updated. Receipt ' . ($pay['receipt_no'] ?: $paymentId) . ' now shows the corrected details.', ['pledge_id' => $pledgeId]);
    }

    if ($action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $pay = $paymentId > 0 ? one("SELECT pledge_id, receipt_no FROM pledge_payments WHERE id=$paymentId LIMIT 1") : null;
        if (!$pay) pj(false, 'Payment not found.');
        $conn->query("DELETE FROM pledge_payments WHERE id=$paymentId");
        recalc_pledge((int)$pay['pledge_id']);
        pj(true, 'Payment ' . ($pay['receipt_no'] ?: $paymentId) . ' deleted.', ['pledge_id' => (int)$pay['pledge_id']]);
    }

    if ($action === 'send_receipt') {
        $paymentId = (int)($_POST['payment_id'] ?? $_GET['payment_id'] ?? 0);
        if ($paymentId <= 0) pj(false, 'Invalid payment ID.');
        $sent = send_pledge_receipt_email($paymentId);
        pj($sent, $sent ? 'Receipt email sent successfully.' : 'Receipt email was not sent. Check member email and email settings.');
    }

    if ($action === 'send_reminders') {
        [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
        $rows = many("SELECT p.*, m.full_name AS full_name, m.email, m.phone, m.member_no, pr.name AS project_name $typeSelect
            FROM pledges p
            LEFT JOIN members m ON m.id=p.member_id
            LEFT JOIN projects pr ON pr.id=p.project_id $typeJoin
            WHERE p.status IN('Pending','Partial') AND (p.amount_pledged - p.amount_paid) > 0");
        $emailCount = 0; $smsCount = 0;
        foreach ($rows as $r) {
            $sent = send_pledge_reminder($r);
            if ($sent['email_sent']) $emailCount++;
            if ($sent['sms_sent']) $smsCount++;
        }
        pj(true, 'Pledge reminders processed.', ['pledges' => count($rows), 'emails_sent' => $emailCount, 'sms_sent' => $smsCount]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) pj(false, 'Invalid pledge ID.');
        $has = one("SELECT COUNT(*) c FROM pledge_payments WHERE pledge_id=$id");
        if ((int)($has['c'] ?? 0) > 0) {
            $conn->query("UPDATE pledges SET status='Cancelled' WHERE id=$id");
            pj(true, 'Pledge has payments, so it was cancelled instead of deleted.');
        }
        $ok = $conn->query("DELETE FROM pledges WHERE id=$id");
        if (!$ok) pj(false, 'Delete failed: '.$conn->error);
        pj(true, 'Pledge deleted successfully.');
    }

    pj(false, 'Invalid request action.');
} catch (Throwable $e) {
    if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062 && str_contains($e->getMessage(), 'pledge_no')) {
        pj(false, 'This member already has a pledge and pledge numbers are still unique in the database. Run database/pledges_member_pledge_no.sql to allow several pledges per member.');
    }
    pj(false, 'Server error: '.$e->getMessage());
}
