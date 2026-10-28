<?php
declare(strict_types=1);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
if (file_exists('../config/mail-function.php'))  require_once '../config/mail-function.php';
if (file_exists('../config/sms-function.php'))   require_once '../config/sms-function.php';
if (file_exists('../config/sms-functions.php'))  require_once '../config/sms-functions.php';

if (function_exists('requireLogin')) requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}

$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===========================================================
   OUTPUT HELPERS
=========================================================== */

function json_out(bool $success, string $message = '', array $extra = []): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function mu_csv_out(string $filename, array $rows): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM - Excel-safe
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => (string)($v ?? ''), $row));
    }
    fclose($out);
    exit;
}

function mu_pdf_out(string $filename, string $html): void
{
    while (ob_get_level() > 0) ob_end_clean();

    // Locate Composer autoload - checks three common project layouts.
    // Install DOMpdf with:  composer require dompdf/dompdf
    $candidates = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];
    $autoload = null;
    foreach ($candidates as $p) {
        if (is_file($p)) { $autoload = $p; break; }
    }
    if ($autoload === null) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "DOMpdf is not installed.\n\n"
           . "Run the following command in your project root:\n\n"
           . "  composer require dompdf/dompdf\n\n"
           . "Then reload this page.";
        exit;
    }

    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $dompdf->output();
    exit;
}

/* ===========================================================
   UTILITY HELPERS
=========================================================== */

function clean($v): string { return trim((string)($v ?? '')); }
function mu_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function mu_money(float $n): string { return 'UGX ' . number_format($n, 0); }

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function col_exists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $col); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function ensure_mu_payments_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS mothers_union_payments (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        mothers_union_id  INT NOT NULL,
        member_id         INT NOT NULL,
        receipt_no        VARCHAR(80) NOT NULL UNIQUE,
        amount            DECIMAL(15,2) NOT NULL DEFAULT 0,
        payment_date      DATE NOT NULL,
        payment_method    VARCHAR(80) NULL,
        notes             TEXT NULL,
        email_sent        TINYINT(1) NOT NULL DEFAULT 0,
        sms_sent          TINYINT(1) NOT NULL DEFAULT 0,
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mu_id   (mothers_union_id),
        INDEX idx_mem_id  (member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function next_mu_no(mysqli $conn): string
{
    $n   = 1;
    $res = $conn->query("SELECT mu_no FROM mothers_union WHERE mu_no LIKE 'MU-%' ORDER BY id DESC LIMIT 1");
    if ($res && ($row = $res->fetch_assoc()) && preg_match('/(\d+)$/', (string)$row['mu_no'], $m)) {
        $n = ((int)$m[1]) + 1;
    }
    return 'MU-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function next_mu_receipt_no(): string
{
    return 'MU-RCPT-' . date('Ymd-His') . '-' . random_int(100, 999);
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $session = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
    if ($session && !hash_equals((string)$session, (string)($_POST['csrf_token'] ?? ''))) {
        json_out(false, 'Invalid security token. Refresh the page and try again.');
    }
}

function get_mu_record(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        "SELECT mu.*, m.full_name AS full_name,
                m.member_no, m.phone, m.email
         FROM mothers_union mu
         JOIN members m ON m.id = mu.member_id
         WHERE mu.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function get_mu_payment(mysqli $conn, int $paymentId): ?array
{
    ensure_mu_payments_table($conn);
    $stmt = $conn->prepare(
        "SELECT p.*, mu.mu_no, mu.position,
                m.full_name AS full_name,
                m.member_no, m.phone, m.email
         FROM mothers_union_payments p
         JOIN mothers_union mu ON mu.id = p.mothers_union_id
         JOIN members m        ON m.id  = p.member_id
         WHERE p.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $paymentId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function list_mu_payments(mysqli $conn, int $muId): array
{
    ensure_mu_payments_table($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM mothers_union_payments WHERE mothers_union_id=? ORDER BY payment_date DESC, id DESC"
    );
    $stmt->bind_param('i', $muId); $stmt->execute();
    $res   = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
    return $items;
}

function record_mu_payment(mysqli $conn, int $muId, int $memberId, float $amount, string $date, string $method, string $notes): ?int
{
    if ($amount <= 0) return null;
    ensure_mu_payments_table($conn);
    $receiptNo = next_mu_receipt_no();
    $stmt = $conn->prepare(
        "INSERT INTO mothers_union_payments (mothers_union_id, member_id, receipt_no, amount, payment_date, payment_method, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisdsss', $muId, $memberId, $receiptNo, $amount, $date, $method, $notes);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

function send_sms_message(mysqli $conn, string $phone, string $message): bool
{
    $phone = trim($phone);
    if ($phone === '') return false;
    foreach (['sendSystemSMS','sendSystemSms','sendSMS','sendSms','send_sms','sendSmsMessage'] as $fn) {
        if (function_exists($fn)) {
            try { return (bool)$fn($conn, $phone, $message); }
            catch (Throwable $e) { error_log('[MU SMS helper] ' . $e->getMessage()); }
        }
    }
    if (!table_exists($conn, 'sms_settings')) return false;
    try {
        $res = $conn->query("SELECT * FROM sms_settings WHERE is_active=1 ORDER BY id DESC LIMIT 1");
        if (!$res || $res->num_rows === 0) return false;
        $s   = $res->fetch_assoc();
        $url = (string)($s['api_url'] ?? $s['gateway_url'] ?? '');
        if ($url === '') return false;
        $apiKey  = (string)($s['api_key']   ?? $s['token']  ?? '');
        $sender  = (string)($s['sender_id'] ?? $s['sender'] ?? '');
        $payload = ['phone'=>$phone,'to'=>$phone,'message'=>$message,'text'=>$message,
                    'api_key'=>$apiKey,'token'=>$apiKey,'sender'=>$sender,'sender_id'=>$sender];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                                CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_TIMEOUT=>20]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) { error_log('[MU SMS curl] ' . $err); return false; }
        return $code >= 200 && $code < 300 && $body !== false;
    } catch (Throwable $e) { error_log('[MU SMS settings] ' . $e->getMessage()); return false; }
}

function send_mu_payment_confirmation(mysqli $conn, int $paymentId): array
{
    $p = get_mu_payment($conn, $paymentId);
    if (!$p) return ['email_sent' => false, 'sms_sent' => false, 'message' => 'Payment not found.'];

    $name      = trim((string)($p['full_name']     ?? 'Member')) ?: 'Member';
    $amount    = (float)($p['amount']      ?? 0);
    $receiptNo = (string)($p['receipt_no'] ?? '');
    $date      = (string)($p['payment_date'] ?? date('Y-m-d'));
    $method    = (string)($p['payment_method'] ?? 'Cash');

    $emailSent = false;
    if (!empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL) && function_exists('sendSystemEmail')) {
        $subject = "Mothers' Union Payment Confirmation - {$receiptNo}";
        $nameH   = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
        $rcptH   = htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8');
        $dateH   = htmlspecialchars($date,      ENT_QUOTES, 'UTF-8');
        $methodH = htmlspecialchars($method,    ENT_QUOTES, 'UTF-8');
        $amtFmt  = number_format($amount);
        $html = "<div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:20px;'>
            <div style='max-width:650px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;'>
                <h2 style='color:#4a1e8f;margin-top:0;'>Payment Confirmation</h2>
                <p>Dear <strong>{$nameH}</strong>,</p>
                <p>We have received your Mothers' Union payment.</p>
                <table style='width:100%;border-collapse:collapse;margin-top:14px;'>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Receipt No</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>{$rcptH}</td></tr>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Amount</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>UGX {$amtFmt}</td></tr>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Date</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>{$dateH}</td></tr>
                    <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa;'><strong>Method</strong></td><td style='padding:10px;border:1px solid #e5e7eb;'>{$methodH}</td></tr>
                </table>
                <p>Thank you for supporting Mothers' Union.</p>
                <p style='margin-bottom:0;'>Blessings,<br><strong>" . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . "</strong></p>
            </div>
        </div>";
        $text = "Dear {$name},\nWe have received your Mothers' Union payment.\nReceipt: {$receiptNo}\nAmount: UGX {$amtFmt}\nDate: {$date}\nMethod: {$method}\nThank you.";
        $emailSent = sendSystemEmail($conn, (string)$p['email'], $name, $subject, $html, $text);
    }

    $smsSent = false;
    if (!empty($p['phone'])) {
        $msg = APP_NAME . ": Mothers' Union payment received. Receipt {$receiptNo}, Amount UGX " . number_format($amount) . ", Date {$date}. Thank you.";
        $smsSent = send_sms_message($conn, (string)$p['phone'], $msg);
    }

    ensure_mu_payments_table($conn);
    $e    = $emailSent ? 1 : 0;
    $s    = $smsSent   ? 1 : 0;
    $stmt = $conn->prepare("UPDATE mothers_union_payments SET email_sent=?, sms_sent=? WHERE id=?");
    $stmt->bind_param('iii', $e, $s, $paymentId); $stmt->execute(); $stmt->close();

    return ['email_sent' => $emailSent, 'sms_sent' => $smsSent, 'message' => 'Confirmation processed.'];
}

/* ===========================================================
   EXPORT DATA FETCHER
=========================================================== */

/**
 * Fetch all MU members with subscription totals, optionally filtered by status.
 */
function mu_fetch_all_for_export(mysqli $conn, string $status = ''): array
{
    $where  = '';
    $params = [];
    $types  = '';
    if ($status !== '' && in_array($status, ['Active','Inactive','Suspended'], true)) {
        $where  = "WHERE COALESCE(mu.status,'Active') = ?";
        $params = [$status];
        $types  = 's';
    }
    $sql = "SELECT mu.mu_no, mu.position, mu.date_joined, mu.subscription, mu.status, mu.notes,
                   m.full_name AS full_name,
                   m.member_no, m.phone, m.email,
                   COALESCE((SELECT SUM(p.amount) FROM mothers_union_payments p WHERE p.mothers_union_id=mu.id),0) AS paid_total,
                   (SELECT COUNT(*) FROM mothers_union_payments p WHERE p.mothers_union_id=mu.id) AS payment_count
            FROM mothers_union mu
            JOIN members m ON m.id = mu.member_id
            $where
            ORDER BY m.full_name, mu.id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

/* ===========================================================
   CSV BUILDER
=========================================================== */

function mu_build_csv(array $members): array
{
    $rows   = [];
    $rows[] = [
        'MU No.', 'Full Name', 'Member No', 'Phone', 'Email',
        'Position', 'Date Joined', 'Subscription (UGX)', 'Amount Paid (UGX)', 'Payments', 'Status', 'Notes',
    ];
    foreach ($members as $r) {
        $rows[] = [
            $r['mu_no']        ?? '',
            $r['full_name']    ?? '',
            $r['member_no']    ?? '',
            $r['phone']        ?? '',
            $r['email']        ?? '',
            $r['position']     ?? '',
            $r['date_joined']  ?? '',
            $r['subscription'] ?? 0,
            $r['paid_total']   ?? 0,
            $r['payment_count']?? 0,
            $r['status']       ?? '',
            $r['notes']        ?? '',
        ];
    }
    return $rows;
}

/* ===========================================================
   PDF HTML BUILDER
=========================================================== */

function mu_build_pdf(array $members, string $statusFilter): string
{
    $genDate  = date('d M Y H:i');
    $total    = count($members);
    $active   = count(array_filter($members, fn($r) => ($r['status'] ?? 'Active') === 'Active'));
    $totalSub = array_sum(array_column($members, 'subscription'));
    $totalPaid= array_sum(array_column($members, 'paid_total'));
    $subtitle = $statusFilter !== '' ? 'Status: ' . mu_h($statusFilter) : 'All Statuses';

    $rows = '';
    $i    = 1;
    foreach ($members as $r) {
        $bg     = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $status = $r['status'] ?? 'Active';
        $sClr   = match($status) {
            'Active'    => '#15803d',
            'Suspended' => '#854d0e',
            default     => '#6b7280',
        };
        $pos    = $r['position'] ?? 'Member';
        $pClr   = $pos !== 'Member' ? '#1d4ed8' : '#374151';
        $sub    = (float)($r['subscription'] ?? 0);
        $paid   = (float)($r['paid_total']   ?? 0);
        $rows  .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#6b7280">' . $i . '</td>
            <td style="font-weight:600">' . mu_h($r['mu_no'] ?? '-') . '</td>
            <td style="font-weight:700">' . mu_h($r['full_name'] ?? '-') . '</td>
            <td>' . mu_h($r['member_no'] ?? '-') . '</td>
            <td>' . mu_h($r['phone'] ?? '-') . '</td>
            <td style="color:' . $pClr . ';font-weight:600">' . mu_h($pos) . '</td>
            <td>' . mu_h($r['date_joined'] ?? '-') . '</td>
            <td style="text-align:right">' . mu_h($sub > 0 ? mu_money($sub) : '-') . '</td>
            <td style="text-align:right;font-weight:600">' . mu_h($paid > 0 ? mu_money($paid) : '-') . '</td>
            <td style="text-align:center">' . (int)($r['payment_count'] ?? 0) . '</td>
            <td style="color:' . $sClr . ';font-weight:700;text-align:center">' . mu_h($status) . '</td>
        </tr>';
        $i++;
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="11" style="text-align:center;color:#9ca3af;padding:20px">No records found.</td></tr>';
    }

    // Grand-total footer
    $rows .= '<tr style="background:#f0ecff;font-weight:700;border-top:2px solid #4a1e8f">
        <td colspan="7" style="text-align:right;padding:5px 6px">Totals</td>
        <td style="text-align:right;padding:5px 6px">' . mu_h(mu_money($totalSub)) . '</td>
        <td style="text-align:right;padding:5px 6px">' . mu_h(mu_money($totalPaid)) . '</td>
        <td colspan="2"></td>
    </tr>';

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body  { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a2e; margin:0; padding:0; }
  .hdr  { background:#4a1e8f; color:#fff; padding:14px 20px 10px; }
  .hdr h1 { margin:0 0 3px; font-size:17px; }
  .hdr p  { margin:0; font-size:9px; opacity:.85; }
  .summary { display:flex; gap:22px; padding:8px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; font-size:8.5px; }
  .summary-box .val { font-size:18px; font-weight:800; }
  .summary-box .lbl { font-size:7.5px; color:#64748b; text-transform:uppercase; }
  table { width:100%; border-collapse:collapse; font-size:8.5px; margin-top:8px; }
  thead th { background:#4a1e8f; color:#fff; padding:5px 6px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.4px; }
  tbody td { padding:4px 6px; border-bottom:1px solid #ede9fe; vertical-align:top; }
  .footer { text-align:center; font-size:7.5px; color:#9ca3af; margin-top:14px; }
</style>
</head>
<body>
<div class="hdr">
  <h1>&#128105; Mothers\' Union Register</h1>
  <p>' . $subtitle . ' &bull; Total: ' . $total . ' &bull; Generated: ' . $genDate . '</p>
</div>
<div class="summary">
  <div class="summary-box"><div class="val">' . $total . '</div><div class="lbl">Total Enrolled</div></div>
  <div class="summary-box"><div class="val" style="color:#15803d">' . $active . '</div><div class="lbl">Active</div></div>
  <div class="summary-box"><div class="val" style="color:#6b7280">' . ($total - $active) . '</div><div class="lbl">Inactive / Suspended</div></div>
  <div class="summary-box"><div class="val" style="color:#4a1e8f">' . mu_h(mu_money($totalSub)) . '</div><div class="lbl">Subscriptions</div></div>
  <div class="summary-box"><div class="val" style="color:#1d4ed8">' . mu_h(mu_money($totalPaid)) . '</div><div class="lbl">Total Paid</div></div>
</div>
<table>
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>MU No.</th>
      <th>Full Name</th>
      <th>Member No</th>
      <th>Phone</th>
      <th>Position</th>
      <th>Date Joined</th>
      <th style="text-align:right">Subscription</th>
      <th style="text-align:right">Amount Paid</th>
      <th style="text-align:center">Payments</th>
      <th style="text-align:center">Status</th>
    </tr>
  </thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Mothers\' Union Register &mdash; ' . $genDate . '</div>
</body>
</html>';
}

/* ===========================================================
   MAIN ROUTER
=========================================================== */
try {
    if (!table_exists($conn, 'mothers_union') || !table_exists($conn, 'members'))
        json_out(false, 'Required tables missing.');
    ensure_mu_payments_table($conn);

    $action = $_REQUEST['action'] ?? 'list';

    /* -------------------------------------------------------
       EXPORT: members CSV  (GET ?action=export_csv[&status=X])
    ------------------------------------------------------- */
    if ($action === 'export_csv') {
        $status  = clean($_GET['status'] ?? '');
        if (!in_array($status, ['Active','Inactive','Suspended',''], true)) $status = '';
        $members = mu_fetch_all_for_export($conn, $status);
        $csvRows = mu_build_csv($members);
        mu_csv_out('mothers_union_' . date('Ymd_His') . '.csv', $csvRows);
    }

    /* -------------------------------------------------------
       EXPORT: members PDF  (GET ?action=export_pdf[&status=X])
    ------------------------------------------------------- */
    if ($action === 'export_pdf') {
        $status  = clean($_GET['status'] ?? '');
        if (!in_array($status, ['Active','Inactive','Suspended',''], true)) $status = '';
        $members = mu_fetch_all_for_export($conn, $status);
        $html    = mu_build_pdf($members, $status);
        mu_pdf_out('mothers_union_' . date('Ymd_His') . '.pdf', $html);
    }

    /* -------------------------------------------------------
       LIST
    ------------------------------------------------------- */
    if ($action === 'list') {
        $search=trim((string)($_GET['search']??'')); $position=trim((string)($_GET['position']??'')); $status=trim((string)($_GET['status']??''));
        $period=trim((string)($_GET['period']??'')); $dateFrom=trim((string)($_GET['date_from']??'')); $dateTo=trim((string)($_GET['date_to']??''));
        $page=max(1,(int)($_GET['page']??1)); $perPage=(int)($_GET['per_page']??25); if(!in_array($perPage,[10,25,50,100],true))$perPage=25;
        $where=['1=1'];
        if($search!==''){$q='%'.$conn->real_escape_string($search).'%';$where[]="(mu.mu_no LIKE '$q' OR mu.position LIKE '$q' OR mu.status LIKE '$q' OR m.member_no LIKE '$q' OR m.phone LIKE '$q' OR m.email LIKE '$q' OR m.full_name LIKE '$q')";}
        if($position!=='')$where[]="mu.position='".$conn->real_escape_string($position)."'";
        if($status!=='')$where[]="COALESCE(mu.status,'Active')='".$conn->real_escape_string($status)."'";
        if($period==='daily')$where[]="mu.date_joined=CURDATE()";
        elseif($period==='weekly')$where[]="mu.date_joined BETWEEN DATE_SUB(CURDATE(),INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE_ADD(DATE_SUB(CURDATE(),INTERVAL WEEKDAY(CURDATE()) DAY),INTERVAL 6 DAY)";
        elseif($period==='range'){if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))$where[]="mu.date_joined>='".$conn->real_escape_string($dateFrom)."'";if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))$where[]="mu.date_joined<='".$conn->real_escape_string($dateTo)."'";}
        $whereSql=implode(' AND ',$where); $cr=$conn->query("SELECT COUNT(*) total FROM mothers_union mu LEFT JOIN members m ON m.id=mu.member_id WHERE $whereSql"); $total=(int)(($cr?$cr->fetch_assoc():[])['total']??0);
        $pages=max(1,(int)ceil($total/$perPage)); if($page>$pages)$page=$pages; $offset=($page-1)*$perPage;
        $sql="SELECT mu.*,m.full_name,m.member_no,m.phone,m.email, COALESCE((SELECT SUM(p.amount) FROM mothers_union_payments p WHERE p.mothers_union_id=mu.id),0) AS paid_total, (SELECT COUNT(*) FROM mothers_union_payments p WHERE p.mothers_union_id=mu.id) AS payment_count FROM mothers_union mu LEFT JOIN members m ON m.id=mu.member_id WHERE $whereSql ORDER BY mu.date_joined DESC,mu.id DESC LIMIT $perPage OFFSET $offset";
        $items=[];$res=$conn->query($sql);while($res&&$row=$res->fetch_assoc())$items[]=$row;
        $pagination=['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)]; json_out(true, '', ['items'=>$items,'pagination'=>$pagination]);
    }

    /* -------------------------------------------------------
       GET single
    ------------------------------------------------------- */
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(false, 'Invalid record ID.');
        $row = get_mu_record($conn, $id);
        if (!$row) json_out(false, 'Record not found.');
        json_out(true, '', ['item' => $row, 'payments' => list_mu_payments($conn, $id)]);
    }

    check_csrf();

    /* -------------------------------------------------------
       CREATE (single or bulk via member_ids[])
    ------------------------------------------------------- */
    if ($action === 'create') {
        $memberIds = [];
        if (!empty($_POST['member_ids']) && is_array($_POST['member_ids'])) {
            foreach ($_POST['member_ids'] as $mid) {
                $mid = (int)$mid; if ($mid > 0) $memberIds[] = $mid;
            }
        } elseif (!empty($_POST['member_id'])) {
            $mid = (int)$_POST['member_id']; if ($mid > 0) $memberIds[] = $mid;
        }
        if (empty($memberIds)) json_out(false, 'Please select at least one member.');

        $position      = clean($_POST['position']       ?? 'Member') ?: 'Member';
        $date          = clean($_POST['date_joined']    ?? date('Y-m-d')) ?: date('Y-m-d');
        $sub           = (float)($_POST['subscription'] ?? 0);
        $status        = clean($_POST['status']         ?? 'Active') ?: 'Active';
        $notes         = clean($_POST['notes']          ?? '');
        $paymentMethod = clean($_POST['payment_method'] ?? 'Cash') ?: 'Cash';

        $inserted   = 0; $skipped = 0; $lastMuNo = '';
        $lastConfirm = ['email_sent' => false, 'sms_sent' => false];

        foreach ($memberIds as $memberId) {
            $chk = $conn->prepare("SELECT id FROM mothers_union WHERE member_id=? LIMIT 1");
            $chk->bind_param('i', $memberId); $chk->execute();
            if ($chk->get_result()->fetch_assoc()) { $chk->close(); $skipped++; continue; }
            $chk->close();

            try {
                $muNo = next_mu_no($conn);
                $stmt = $conn->prepare(
                    "INSERT INTO mothers_union (mu_no, member_id, position, date_joined, subscription, status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('sissdss', $muNo, $memberId, $position, $date, $sub, $status, $notes);
                $stmt->execute();
                $muId = (int)$conn->insert_id;
                $stmt->close();

                $paymentId = record_mu_payment($conn, $muId, $memberId, $sub, $date, $paymentMethod, $notes);
                $confirm   = $paymentId ? send_mu_payment_confirmation($conn, $paymentId)
                                        : ['email_sent' => false, 'sms_sent' => false];
                $inserted++;
                $lastMuNo    = $muNo;
                $lastConfirm = $confirm;
            } catch (Throwable $e) {
                error_log('[MU create] member#' . $memberId . ': ' . $e->getMessage());
            }
        }

        if ($inserted === 0 && $skipped > 0)
            json_out(false, "All selected members ({$skipped}) are already enrolled in Mothers' Union.");

        $msg = $inserted === 1
            ? "Member enrolled successfully. No: {$lastMuNo}"
            : "{$inserted} members enrolled successfully.";
        if ($skipped) $msg .= " ({$skipped} skipped - already enrolled.)";
        json_out(true, $msg, ['inserted' => $inserted, 'skipped' => $skipped, 'confirmation' => $lastConfirm]);
    }

    /* -------------------------------------------------------
       UPDATE
    ------------------------------------------------------- */
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(false, 'Invalid record ID.');
        $old = get_mu_record($conn, $id);
        if (!$old) json_out(false, 'Record not found.');

        $position      = clean($_POST['position']       ?? 'Member') ?: 'Member';
        $date          = clean($_POST['date_joined']    ?? date('Y-m-d')) ?: date('Y-m-d');
        $sub           = (float)($_POST['subscription'] ?? 0);
        $status        = clean($_POST['status']         ?? 'Active') ?: 'Active';
        $notes         = clean($_POST['notes']          ?? '');
        $paymentMethod = clean($_POST['payment_method'] ?? 'Cash') ?: 'Cash';

        $stmt = $conn->prepare(
            "UPDATE mothers_union SET position=?, date_joined=?, subscription=?, status=?, notes=? WHERE id=?"
        );
        $stmt->bind_param('ssdssi', $position, $date, $sub, $status, $notes, $id);
        $stmt->execute(); $stmt->close();

        $paymentId = null;
        if ($sub > 0 && abs($sub - (float)($old['subscription'] ?? 0)) > 0.001) {
            $paymentId = record_mu_payment($conn, $id, (int)$old['member_id'], $sub, $date, $paymentMethod, $notes);
            if ($paymentId) send_mu_payment_confirmation($conn, $paymentId);
        }
        json_out(true, 'Record updated successfully.', ['payment_id' => $paymentId]);
    }

    /* -------------------------------------------------------
       RESEND CONFIRMATION
    ------------------------------------------------------- */
    if ($action === 'resend_confirmation') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId <= 0) json_out(false, 'Invalid payment ID.');
        $result = send_mu_payment_confirmation($conn, $paymentId);
        json_out(true, 'Payment confirmation sent where contact details exist.', $result);
    }

    /* -------------------------------------------------------
       DELETE
    ------------------------------------------------------- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(false, 'Invalid record ID.');
        $stmt = $conn->prepare("DELETE FROM mothers_union WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        json_out(true, 'Record deleted successfully.');
    }

    json_out(false, 'Invalid request action.');

} catch (Throwable $e) {
    error_log('[process-mothers-union] ' . $e->getMessage());
    json_out(false, 'Server error: ' . $e->getMessage());
}