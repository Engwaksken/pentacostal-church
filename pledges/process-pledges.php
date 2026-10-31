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
    if (!in_array($requestAction, ['list', 'get', 'options', 'payments'], true)) {
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
/** A receipt/reference number. Pass $seq when creating many in one request (imports) so they cannot collide. */
function next_no(string $prefix, ?int $seq = null): string {
    // Same shape and length as before (e.g. RCP-20261031-213503-0042), so it fits the receipt_no column.
    return $prefix . '-' . date('Ymd-His') . '-' . ($seq !== null ? sprintf('%04d', $seq) : random_int(100, 999));
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

/** Read the first worksheet of an Excel .xlsx workbook without third-party packages. */
function read_pledges_xlsx(string $path): array {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) throw new RuntimeException('Server XLSX support is not available.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('The uploaded workbook could not be opened.');
    try {
        $workbook = simplexml_load_string((string)$zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string)$zip->getFromName('xl/_rels/workbook.xml.rels'));
        if (!$workbook || !$rels || !isset($workbook->sheets->sheet[0])) throw new RuntimeException('The workbook has no readable first worksheet.');
        $rid = (string)$workbook->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        $target = '';
        foreach ($rels->Relationship as $rel) if ((string)$rel['Id'] === $rid) $target = (string)$rel['Target'];
        if ($target === '') throw new RuntimeException('The first worksheet could not be located.');
        $sheetPath = ltrim($target, '/');
        if (strpos($sheetPath, 'xl/') !== 0) $sheetPath = 'xl/'.$sheetPath;
        $sheet = new DOMDocument();
        if (!$sheet->loadXML((string)$zip->getFromName($sheetPath))) throw new RuntimeException('The first worksheet is invalid.');
        $strings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $shared = new DOMDocument(); $shared->loadXML($sharedXml);
            $sx = new DOMXPath($shared); $sx->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($sx->query('//m:si') as $si) $strings[] = $si->textContent;
        }
        $xp = new DOMXPath($sheet); $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') as $rowNode) {
            $row = []; foreach ($xp->query('m:c', $rowNode) as $cell) {
                $ref = $cell->getAttribute('r'); if (!preg_match('/^([A-Z]+)/', $ref, $m)) continue;
                $col = 0; foreach (str_split($m[1]) as $letter) $col = $col * 26 + ord($letter) - 64;
                $v = $xp->query('m:v', $cell)->item(0); $value = $v ? $v->textContent : '';
                if ($cell->getAttribute('t') === 's' && $value !== '') $value = $strings[(int)$value] ?? '';
                elseif ($cell->getAttribute('t') === 'inlineStr') $value = $xp->query('m:is', $cell)->item(0)?->textContent ?? '';
                $row[$col] = trim((string)$value);
            }
            $rows[(int)$rowNode->getAttribute('r')] = $row;
        }
        if (strtoupper(trim((string)($rows[2][2] ?? ''))) !== 'NAME' || strtoupper(trim((string)($rows[2][6] ?? ''))) !== 'PLEDGE') {
            throw new RuntimeException('The first sheet does not look like a pledges worksheet.');
        }
        if (!preg_match('/\b(20\d{2})\b/', (string)($rows[1][2] ?? ''), $yearMatch)) throw new RuntimeException('Add the pledge year (for example, 2025) to the worksheet title in cell B1.');
        $year = (int)$yearMatch[1];
        $pledgeNoCol = 0;
        foreach (($rows[2] ?? []) as $col => $heading) {
            $heading = strtolower(trim((string)$heading));
            if (preg_match('/pledge\s*(?:no\.?|number|#)/', $heading)) { $pledgeNoCol = (int)$col; break; }
        }
        $result = [];
        foreach ($rows as $number => $row) {
            if ($number <= 2) continue;
            $name = trim((string)($row[2] ?? '')); $pledged = (float)str_replace([',', ' '], '', (string)($row[6] ?? ''));
            $pledgeNo = $pledgeNoCol ? trim((string)($row[$pledgeNoCol] ?? '')) : '';
            if ($name === '' || $pledged <= 0) continue;
            $payments = [];
            for ($col = 8; $col <= 61; $col++) {
                $amount = (float)str_replace([',', ' '], '', (string)($row[$col] ?? ''));
                if ($amount <= 0) continue;
                $heading = (string)($rows[2][$col] ?? '');
                if (is_numeric($heading) && (float)$heading >= 1) {
                    $date = (new DateTimeImmutable('1899-12-30'))->modify('+'.(int)$heading.' days')->format('Y-m-d');
                } else {
                    $date = normalize_date($heading); // date typed as text, e.g. 05/01/2025
                    if ($date === '') continue;
                }
                $payments[] = ['date' => $date, 'amount' => $amount];
            }
            if ($pledgeNo === '') $pledgeNo = pledge_import_extract_no($name);
            $result[] = ['row' => $number, 'name' => $name, 'display_name' => pledge_import_display_name($name), 'phone' => trim((string)($row[4] ?? '')), 'pledge_no' => $pledgeNo, 'pledged' => $pledged, 'payments' => $payments];
        }
        return ['year' => $year, 'rows' => $result];
    } finally { $zip->close(); }
}
function pledge_import_name_key(string $name): string {
    $name = trim($name);
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return preg_replace('/[^\pL\pN]+/u', '', $name) ?? '';
}
/**
 * Pledge-number suffixes written into names in the workbook:
 *   "John Doe - No 12", "Jane No. 7", "Kitaka Patrick-No 22 RIP", "Okatch Edison- N0 46" (zero for O),
 *   and a bare trailing number after a dash: "Sentamu -286", "Joy-515", "Jennifer - 605".
 */
const PLEDGE_NAME_NO_PATTERNS = [
    '/\s*[-–]?\s*\bN[o0]\.?\s*[-:]?\s*(\d+)\b\s*/iu',
    '/\s*[-–]\s*(\d+)\s*$/u',
];
function pledge_import_name_base_key(string $name): string {
    return pledge_import_name_key(pledge_import_display_name($name));
}
function pledge_import_extract_no(string $name): string {
    foreach (PLEDGE_NAME_NO_PATTERNS as $pattern) {
        if (preg_match($pattern, trim($name), $m)) return $m[1];
    }
    return '';
}
function pledge_import_display_name(string $name): string {
    $name = trim($name);
    foreach (PLEDGE_NAME_NO_PATTERNS as $pattern) $name = (string)preg_replace($pattern, ' ', $name);
    return trim((string)preg_replace('/\s{2,}/', ' ', $name), " \t-–");
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
/** First day of a YYYY-MM month and first day of the next month, for paid_on >= start AND paid_on < end. */
function pledge_month_range(string $month): array {
    $start = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable('first day of this month');
    return [$start->format('Y-m-01'), $start->modify('first day of next month')->format('Y-m-d')];
}
function pledge_import_phone_key(string $phone): string { return preg_replace('/\D+/', '', $phone) ?? ''; }
function pledge_import_no_key(string $number): string { return strtoupper(preg_replace('/[^\pL\pN]+/u', '', trim($number)) ?? ''); }

try {
    if (!table_exists('pledges')) pj(false, 'pledges table does not exist.');
    $action = $_REQUEST['action'] ?? 'list';

    if ($action === 'prepare_pledges') {
        if (!table_exists('pledge_payments')) pj(false, 'pledge_payments table does not exist.');
        $file = $_FILES['pledge_workbook'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) pj(false, 'Select the pledges Excel workbook first.');
        if (($file['size'] ?? 0) > 12 * 1024 * 1024 || strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') pj(false, 'Upload an .xlsx workbook smaller than 12 MB.');
        try { $workbookData = read_pledges_xlsx((string)$file['tmp_name']); }
        catch (Throwable $e) { pj(false, $e->getMessage()); }
        $importYear = (int)$workbookData['year']; $sourceRows = $workbookData['rows'];
        if (!$sourceRows) pj(false, 'No pledge rows with an amount were found on the first worksheet.');
        $members = many("SELECT id, full_name, member_no, ".(col_exists('members','pledge_no') ? 'pledge_no' : "'' AS pledge_no").", ".(col_exists('members','phone') ? 'phone' : (col_exists('members','mobile') ? 'mobile AS phone' : "'' AS phone"))." FROM members");
        $byName = []; $byBaseName = []; $byPhone = []; $byPledgeNo = [];
        foreach ($members as $member) {
            $nk = pledge_import_name_key((string)$member['full_name']); if ($nk !== '') $byName[$nk][] = $member;
            $bk = pledge_import_name_base_key((string)$member['full_name']); if ($bk !== '') $byBaseName[$bk][] = $member;
            $pk = pledge_import_phone_key((string)$member['phone']); if ($pk !== '') $byPhone[$pk][] = $member;
            $pledgeKey = pledge_import_no_key((string)$member['pledge_no']); if ($pledgeKey !== '') $byPledgeNo[$pledgeKey][] = $member;
        }
        // Preparation is deliberately read-only: export only unmatched people and
        // recorded payments so they can be reviewed/imported separately.
        $missingMembers = []; $missingSeen = []; $paidEntries = []; $skipped = [];
        foreach ($sourceRows as $source) {
            $phoneKey = pledge_import_phone_key($source['phone']); $nameKey = pledge_import_name_key($source['name']); $baseNameKey = pledge_import_name_base_key($source['name']); $pledgeKey = pledge_import_no_key($source['pledge_no'] ?? '');
            $matches = []; $ambiguous = false;
            if ($pledgeKey !== '' && isset($byPledgeNo[$pledgeKey])) { $matches = $byPledgeNo[$pledgeKey]; $ambiguous = count($matches) !== 1; }
            if (!$matches && $phoneKey !== '' && isset($byPhone[$phoneKey])) { $matches = $byPhone[$phoneKey]; $ambiguous = count($matches) !== 1; }
            if (!$matches && isset($byName[$nameKey])) { $matches = $byName[$nameKey]; $ambiguous = count($matches) !== 1; }
            if (!$matches && isset($byBaseName[$baseNameKey])) { $matches = $byBaseName[$baseNameKey]; $ambiguous = count($matches) !== 1; }
            // Use the matched member's pledge number when the workbook row has none, so the
            // paid-entries CSV can be imported without blank pledge numbers.
            $exportPledgeNo = (string)$source['pledge_no'];
            if ($exportPledgeNo === '' && count($matches) === 1) $exportPledgeNo = trim((string)$matches[0]['pledge_no']);
            if ($source['payments'] && $exportPledgeNo === '') {
                $skipped[] = 'No pledge number for '.$source['name'].' (Excel row '.$source['row'].'); its payments were not exported.';
            } else {
                foreach ($source['payments'] as $payment) $paidEntries[] = [$source['display_name'], $source['phone'], $exportPledgeNo, $importYear, $source['pledged'], $payment['date'], $payment['amount']];
            }
            if (count($matches) !== 1) {
                if ($ambiguous) { $skipped[] = 'Ambiguous member match; not exported as missing: '.$source['name'].' (Excel row '.$source['row'].')'; continue; }
                $missingKey = $pledgeKey !== '' ? 'pledge:'.$pledgeKey : ($phoneKey !== '' ? 'phone:'.$phoneKey : 'name:'.$nameKey);
                if (!isset($missingSeen[$missingKey])) {
                    $missingSeen[$missingKey] = true;
                    $missingMembers[] = [$source['display_name'], 'Not Specified', $source['phone'], '', '', '', '', sprintf('%04d-01-01', $importYear), '', '', 'Prepared from pledges workbook '.$importYear, 'Active', $source['pledge_no'], ''];
                }
                continue;
            }
            $memberPledgeNo = pledge_import_no_key((string)$matches[0]['pledge_no']);
            if ($pledgeKey !== '' && $memberPledgeNo !== '' && $pledgeKey !== $memberPledgeNo) $skipped[] = 'Pledge number mismatch for '.$source['name'].': workbook '.$source['pledge_no'].' vs member record '.$matches[0]['pledge_no'];
        }
        pj(true, "Prepared {$importYear} review files: ".count($missingMembers)." unmatched member(s), ".count($paidEntries)." paid pledge entry/entries. Nothing was imported.", [
            'year' => $importYear,
            'missing_members' => array_merge([['full_name','gender','phone','email','occupation','marital_status','date_of_birth','joined_date','baptism_date','address','notes','status','pledge_no','service_attended']], $missingMembers),
            'paid_entries' => array_merge([['member_name','phone','pledge_no','pledge_year','amount_pledged','paid_on','amount_paid']], $paidEntries),
            'skipped' => $skipped
        ]);
    }

    if ($action === 'import_prepared_paid_csv') {
        if (!table_exists('pledge_payments')) pj(false, 'pledge_payments table does not exist.');
        $file = $_FILES['paid_csv'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) pj(false, 'Choose the prepared paid-pledges CSV file.');
        if (($file['size'] ?? 0) > 12 * 1024 * 1024 || strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') pj(false, 'Upload a CSV file smaller than 12 MB.');
        $fh = fopen((string)$file['tmp_name'], 'rb');
        if (!$fh) pj(false, 'Could not read the uploaded CSV file.');
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); pj(false, 'The uploaded CSV is empty.'); }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $columns = array_flip(array_map(static fn($v) => strtolower(trim((string)$v)), $header));
        foreach (['pledge_no','pledge_year','amount_pledged','paid_on','amount_paid'] as $required) {
            if (!isset($columns[$required])) { fclose($fh); pj(false, 'This is not a prepared paid-pledges CSV. Missing column: '.$required); }
        }
        // Rows that cannot be imported are skipped and reported (grouped by person), rather than
        // rejecting the whole file. Re-uploading a corrected file is safe: payments already
        // imported are recognised as duplicates.
        $entries = []; $line = 1; $rejected = [];
        $reject = static function (string $who, string $reason, int $line) use (&$rejected): void {
            $key = $who.'|'.$reason;
            if (!isset($rejected[$key])) $rejected[$key] = ['who' => $who, 'reason' => $reason, 'lines' => []];
            $rejected[$key]['lines'][] = $line;
        };
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if (!array_filter($row, static fn($c) => trim((string)$c) !== '')) continue; // blank line
            $value = static fn($key) => isset($columns[$key]) ? trim((string)($row[$columns[$key]] ?? '')) : '';
            $memberName = $value('member_name');
            $no = $value('pledge_no');
            if ($no === '' && $memberName !== '') $no = pledge_import_extract_no($memberName);
            $year = (int)$value('pledge_year');
            $amount = (float)str_replace([',',' '], '', $value('amount_paid'));
            $pledged = (float)str_replace([',',' '], '', $value('amount_pledged'));
            $date = normalize_date($value('paid_on'));
            $problems = [];
            if ($no === '') $problems[] = 'no pledge number (add it in the pledge_no column)';
            if ($year < 2000 || $year > 2100) $problems[] = 'pledge year "'.$value('pledge_year').'" is invalid';
            if ($pledged <= 0) $problems[] = 'amount pledged is missing';
            if ($amount <= 0) $problems[] = 'amount paid is missing';
            if ($date === '') $problems[] = 'paid date "'.$value('paid_on').'" is not a valid date';
            if ($problems) { $reject($memberName !== '' ? $memberName : 'Pledge '.$no, implode('; ', $problems), $line); continue; }
            $entries[] = ['line'=>$line,'name'=>$memberName,'pledge_no'=>$no,'year'=>$year,'amount_pledged'=>$pledged,'paid_on'=>$date,'amount_paid'=>$amount];
        }
        fclose($fh);
        $typesReady = pledge_types_ready($conn); $created = 0; $paymentsAdded = 0; $duplicates = 0;
        $hasPledgeNoCol = col_exists('members', 'pledge_no');
        $membersByNo = [];
        $conn->begin_transaction();
        try {
            foreach ($entries as $i => $entry) {
                $who = $entry['name'] !== '' ? $entry['name'] : 'Pledge '.$entry['pledge_no'];
                if (!$hasPledgeNoCol) { $reject($who, 'members table has no pledge_no column', $entry['line']); continue; }
                $noKey = $entry['pledge_no'];
                if (!isset($membersByNo[$noKey])) {
                    $number = hsql($noKey);
                    $membersByNo[$noKey] = many("SELECT id, pledge_no FROM members WHERE TRIM(pledge_no)='$number' LIMIT 2");
                }
                $memberMatches = $membersByNo[$noKey];
                if (count($memberMatches) === 0) { $reject($who, 'pledge number '.$noKey.' is not on any member (import the missing members file first)', $entry['line']); continue; }
                if (count($memberMatches) > 1) { $reject($who, 'pledge number '.$noKey.' is on more than one member', $entry['line']); continue; }
                $member = $memberMatches[0];
                $memberId = (int)$member['id']; $year = (int)$entry['year'];
                $where = $typesReady ? "pledge_type='Annual' AND pledge_year=$year" : "pledge_reason='Annual Pledge $year'";
                $pledge = one("SELECT id,amount_pledged FROM pledges WHERE member_id=$memberId AND $where AND status<>'Cancelled' ORDER BY id DESC LIMIT 1");
                if (!$pledge) {
                    $pledgeNo = (string)$member['pledge_no']; $reason = 'Annual Pledge '.$year; $date = sprintf('%04d-01-01',$year); $pending='Pending'; $paid=0.0; $note='Imported from prepared paid-pledges CSV';
                    if ($typesReady) {
                        $stmt=$conn->prepare("INSERT INTO pledges (pledge_no,member_id,pledge_reason,amount_pledged,amount_paid,pledge_date,status,notes,pledge_type,pledge_year) VALUES (?,?,?,?,?,?,?,?,'Annual',?)");
                        $stmt->bind_param('sisddsssi',$pledgeNo,$memberId,$reason,$entry['amount_pledged'],$paid,$date,$pending,$note,$year);
                    } else {
                        $stmt=$conn->prepare('INSERT INTO pledges (pledge_no,member_id,pledge_reason,amount_pledged,amount_paid,pledge_date,status,notes) VALUES (?,?,?,?,?,?,?,?)');
                        $stmt->bind_param('sisddsss',$pledgeNo,$memberId,$reason,$entry['amount_pledged'],$paid,$date,$pending,$note);
                    }
                    if (!$stmt->execute()) throw new RuntimeException('Could not create annual pledge for '.$entry['pledge_no'].': '.$stmt->error);
                    $pledgeId=(int)$stmt->insert_id; $stmt->close(); $created++;
                } else {
                    $pledgeId=(int)$pledge['id'];
                    if (abs((float)$pledge['amount_pledged']-(float)$entry['amount_pledged'])>0.005) {
                        $amountPledged=(float)$entry['amount_pledged']; $stmt=$conn->prepare('UPDATE pledges SET amount_pledged=? WHERE id=?'); $stmt->bind_param('di',$amountPledged,$pledgeId); $stmt->execute(); $stmt->close();
                        recalc_pledge($pledgeId);
                    }
                }
                $dup=$conn->prepare('SELECT id FROM pledge_payments WHERE pledge_id=? AND amount=? AND paid_on=? LIMIT 1');
                $amountPaid=(float)$entry['amount_paid']; $paidOn=$entry['paid_on']; $dup->bind_param('ids',$pledgeId,$amountPaid,$paidOn); $dup->execute(); $already=$dup->get_result()->fetch_assoc(); $dup->close();
                if ($already) { $duplicates++; continue; }
                $receipt=next_no('RCP', $i + 1); $method='Cash'; $notes='Imported from prepared paid-pledges CSV';
                $stmt=$conn->prepare('INSERT INTO pledge_payments (pledge_id,amount,paid_on,method,receipt_no,notes) VALUES (?,?,?,?,?,?)');
                $stmt->bind_param('idssss',$pledgeId,$amountPaid,$paidOn,$method,$receipt,$notes);
                if (!$stmt->execute()) throw new RuntimeException('Payment import failed for pledge '.$entry['pledge_no'].': '.$stmt->error);
                $stmt->close(); $paymentsAdded++; recalc_pledge($pledgeId);
            }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
        $rejectedRows = 0; $notes = [];
        foreach ($rejected as $r) {
            $rejectedRows += count($r['lines']);
            $lines = count($r['lines']) > 1 ? 'lines '.$r['lines'][0].'–'.end($r['lines']).' ('.count($r['lines']).' rows)' : 'line '.$r['lines'][0];
            $notes[] = $r['who'].': '.$r['reason'].' — '.$lines;
        }
        $message = "$paymentsAdded payment(s) imported; $created annual pledge(s) created; $duplicates already-imported payment(s) skipped.";
        if ($rejectedRows) $message .= " $rejectedRows row(s) NOT imported — fix them in the CSV and upload it again.";
        pj($paymentsAdded + $duplicates > 0 || !$rejectedRows, $message, ['payments'=>$paymentsAdded,'created'=>$created,'duplicates'=>$duplicates,'rejected_rows'=>$rejectedRows,'skipped'=>$notes]);
    }

    if ($action === 'options') {
        pj(true, '', [
            'members' => many("SELECT id, member_no, full_name FROM members ORDER BY full_name"),
            'projects' => table_exists('projects') ? many("SELECT id, name FROM projects WHERE COALESCE(status,'Active')='Active' ORDER BY name") : [],
            'events' => table_exists('events') ? many("SELECT id, title, event_date FROM events ORDER BY event_date DESC") : []
        ]);
    }

    if ($action === 'list') {
        $status = clean($_GET['status'] ?? '');
        $year = (int)($_GET['year'] ?? 0);
        $type = clean($_GET['type'] ?? '');
        $overdueOnly = (int)($_GET['overdue'] ?? 0) === 1;
        $conditions = [];
        if ($status !== '') $conditions[] = "p.status='".hsql($status)."'";
        if ($overdueOnly) $conditions[] = "p.status IN('Pending','Partial') AND p.due_date IS NOT NULL AND p.due_date<CURDATE()";
        if ($type !== '' && in_array($type, pledge_types(), true)) {
            $conditions[] = pledge_types_ready($conn) ? "p.pledge_type='".hsql($type)."'" : ($type === 'Annual' ? "p.pledge_reason LIKE 'Annual Pledge %'" : '1=0');
        }
        if ($year >= 2000 && $year <= 2100) {
            $conditions[] = pledge_types_ready($conn)
                ? "(p.pledge_year=$year OR (p.pledge_year IS NULL AND YEAR(p.pledge_date)=$year))"
                : "YEAR(p.pledge_date)=$year";
        }
        $where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';
        [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
        $rows = many("SELECT p.*, m.full_name AS full_name, m.member_no, m.email, m.phone, pr.name AS project_name $typeSelect
            FROM pledges p
            LEFT JOIN members m ON m.id=p.member_id
            LEFT JOIN projects pr ON pr.id=p.project_id $typeJoin
            $where
            ORDER BY p.pledge_date DESC, p.id DESC");
        foreach ($rows as &$r) $r['purpose'] = pledge_purpose($r);
        unset($r);
        // Cancelled pledges are left out of money totals unless the user filtered to them.
        $statsWhere = $where;
        if ($status !== 'Cancelled') $statsWhere .= ($statsWhere === '' ? 'WHERE ' : ' AND ')."p.status<>'Cancelled'";
        $stats = one("SELECT COALESCE(SUM(amount_pledged),0) total, COALESCE(SUM(amount_paid),0) paid,
            SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) pending,
            SUM(CASE WHEN status='Partial' THEN 1 ELSE 0 END) partial,
            SUM(CASE WHEN status='Fulfilled' THEN 1 ELSE 0 END) fulfilled,
            SUM(CASE WHEN status IN('Pending','Partial') AND due_date IS NOT NULL AND due_date<CURDATE() THEN 1 ELSE 0 END) overdue
            FROM pledges p $statsWhere");
        $yearColumn = pledge_types_ready($conn) ? 'COALESCE(p.pledge_year,YEAR(p.pledge_date))' : 'YEAR(p.pledge_date)';
        $yearStats = one("SELECT COALESCE(SUM(CASE WHEN $yearColumn=".(int)date('Y')." THEN p.amount_pledged ELSE 0 END),0) this_year,
            COALESCE(SUM(CASE WHEN $yearColumn=".((int)date('Y')-1)." THEN p.amount_pledged ELSE 0 END),0) last_year FROM pledges p WHERE p.status<>'Cancelled'");
        $stats = array_merge($stats ?: [], $yearStats ?: []);
        // Money received this calendar month on pledges that are not cancelled.
        [$monthStart, $monthEnd] = pledge_month_range(date('Y-m'));
        $stats['paid_this_month'] = table_exists('pledge_payments')
            ? (float)(one("SELECT COALESCE(SUM(pp.amount),0) t FROM pledge_payments pp JOIN pledges p ON p.id=pp.pledge_id
                WHERE pp.paid_on>='$monthStart' AND pp.paid_on<'$monthEnd' AND p.status<>'Cancelled'")['t'] ?? 0)
            : 0.0;
        pj(true, '', ['items' => $rows, 'stats' => $stats ?: []]);
    }

    if ($action === 'payments') {
        // Payments received in one month (YYYY-MM, default this month), for the "Paid This Month" list.
        if (!table_exists('pledge_payments')) pj(true, '', ['items' => [], 'month' => date('Y-m')]);
        $month = clean($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
        [$monthStart, $monthEnd] = pledge_month_range($month);
        [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
        $items = many("SELECT pp.id, pp.pledge_id, pp.amount, pp.paid_on, pp.method, pp.receipt_no,
                p.pledge_no, p.pledge_reason, p.status, m.full_name, m.member_no, pr.name AS project_name $typeSelect
            FROM pledge_payments pp
            JOIN pledges p ON p.id=pp.pledge_id
            LEFT JOIN members m ON m.id=p.member_id
            LEFT JOIN projects pr ON pr.id=p.project_id $typeJoin
            WHERE pp.paid_on>='$monthStart' AND pp.paid_on<'$monthEnd' AND p.status<>'Cancelled'
            ORDER BY pp.paid_on DESC, pp.id DESC");
        foreach ($items as &$r) $r['purpose'] = pledge_purpose($r);
        unset($r);
        pj(true, '', ['items' => $items, 'month' => $month]);
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

    if ($action === 'save_bulk') {
        $entries = json_decode((string)($_POST['entries'] ?? ''), true);
        if (!is_array($entries) || !$entries) pj(false, 'Add at least one pledge entry.');
        if (count($entries) > 100) pj(false, 'A maximum of 100 pledge entries can be saved at once.');
        $stmt = $conn->prepare('INSERT INTO pledges (pledge_no,member_id,pledge_reason,amount_pledged,amount_paid,pledge_date,status,notes) VALUES (?,?,?,?,0,?,\'Pending\',?)');
        if (!$stmt) pj(false, 'Could not prepare pledge entries: '.$conn->error);
        $saved = 0; $errors = [];
        $conn->begin_transaction();
        try {
            foreach ($entries as $i => $entry) {
                $memberId = (int)($entry['member_id'] ?? 0);
                $amount = (float)($entry['amount_pledged'] ?? 0);
                $reason = clean($entry['pledge_reason'] ?? '');
                $date = normalize_date(clean($entry['pledge_date'] ?? '') ?: date('Y-m-d'));
                $notes = clean($entry['notes'] ?? '');
                if ($memberId <= 0 || $amount <= 0 || $date === '') throw new RuntimeException('Entry '.($i+1).': select a member, enter a positive amount, and provide a valid date.');
                $pledgeNo = member_pledge_no($memberId);
                if ($pledgeNo === '') throw new RuntimeException('Entry '.($i+1).': the selected member has no pledge number.');
                $stmt->bind_param('sisdss', $pledgeNo, $memberId, $reason, $amount, $date, $notes);
                if (!$stmt->execute()) throw new RuntimeException('Entry '.($i+1).' failed: '.$stmt->error);
                $saved++;
            }
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); $stmt->close(); throw $e; }
        $stmt->close();
        pj(true, $saved.' pledge entries recorded successfully.', ['created' => $saved]);
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
