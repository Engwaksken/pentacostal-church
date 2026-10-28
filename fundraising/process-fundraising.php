<?php
declare(strict_types=1);

ob_start();
require_once '../config/config.php';
if (file_exists('../config/mail-function.php')) { require_once '../config/mail-function.php'; }
if (file_exists('../config/sms-function.php'))  { require_once '../config/sms-function.php'; }
if (function_exists('requireLogin')) { requireLogin(); }

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

/* ===========================================================
   OUTPUT HELPERS
=========================================================== */

function fr_json(bool $success, string $message = '', array $extra = []): void
{
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function fr_csv_out(string $filename, array $rows): void
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => (string)($v ?? ''), $row));
    }
    fclose($out);
    exit;
}

function fr_pdf_out(string $filename, string $html): void
{
    while (ob_get_level()) ob_end_clean();

    // Locate Composer autoload — checks three common project layouts.
    // Run:  composer require dompdf/dompdf  in your project root.
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

function fr_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function fr_post(string $key, string $default = ''): string { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }
function fr_get(string $key, string $default = ''): string  { return isset($_GET[$key])  ? trim((string)$_GET[$key])  : $default; }

function fr_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function fr_col_exists(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $key = "$table.$col";
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $col); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function fr_gen_no(string $prefix): string
{
    if (function_exists('genNo')) return genNo($prefix);
    return $prefix . '-' . date('YmdHis') . '-' . random_int(100, 999);
}

function fr_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function fr_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception($conn->error);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function fr_money(float $n): string { return 'UGX ' . number_format($n, 0); }

/* ===========================================================
   EXPORT DATA FETCHERS
=========================================================== */

/**
 * Fetch all campaigns with totals, optionally filtered by status.
 */
function fr_fetch_campaigns_for_export(mysqli $conn, string $status = ''): array
{
    $where  = '';
    $params = [];
    $types  = '';
    if ($status !== '' && in_array($status, ['Planning','Active','Completed','Cancelled'], true)) {
        $where  = 'WHERE f.status = ?';
        $params = [$status];
        $types  = 's';
    }
    $sql = "SELECT f.campaign_no, f.title, f.status, f.organizer, f.venue,
                   f.start_date, f.end_date, f.target_amount,
                   COALESCE(SUM(fc.amount),0) AS raised,
                   COUNT(fc.id) AS donations,
                   f.description
            FROM fundraising f
            LEFT JOIN fundraising_collections fc ON fc.campaign_id = f.id
            $where
            GROUP BY f.id
            ORDER BY f.start_date DESC, f.id DESC";
    return fr_fetch_all($conn, $sql, $types, $params);
}

/**
 * Fetch all collections for a given campaign.
 */
function fr_fetch_collections_for_export(mysqli $conn, int $campaignId): array
{
    $sql = "SELECT fc.receipt_no,
                   m.full_name AS member_name,
                   m.member_no,
                   COALESCE(fc.donor_name,'') AS donor_name,
                   fc.amount, fc.payment_method, fc.collection_date,
                   COALESCE(fc.item_description,'') AS item_description,
                   COALESCE(fc.notes,'') AS notes
            FROM fundraising_collections fc
            LEFT JOIN members m ON m.id = fc.member_id
            WHERE fc.campaign_id = ?
            ORDER BY fc.collection_date DESC, fc.id DESC";
    return fr_fetch_all($conn, $sql, 'i', [$campaignId]);
}

/* ===========================================================
   CSV BUILDERS
=========================================================== */

function fr_build_campaigns_csv(array $campaigns): array
{
    $rows   = [];
    $rows[] = ['Campaign No','Title','Status','Organizer','Venue',
               'Start Date','End Date','Target (UGX)','Raised (UGX)','Collections','Description'];
    foreach ($campaigns as $c) {
        $rows[] = [
            $c['campaign_no']  ?? '',
            $c['title']        ?? '',
            $c['status']       ?? '',
            $c['organizer']    ?? '',
            $c['venue']        ?? '',
            $c['start_date']   ?? '',
            $c['end_date']     ?? '',
            $c['target_amount']?? 0,
            $c['raised']       ?? 0,
            $c['donations']    ?? 0,
            $c['description']  ?? '',
        ];
    }
    return $rows;
}

function fr_build_collections_csv(array $collections): array
{
    $rows   = [];
    $rows[] = ['Receipt No','Member Name','Member No','Donor Name',
               'Amount (UGX)','Payment Method','Collection Date','Item Description','Notes'];
    foreach ($collections as $x) {
        $donor = trim($x['member_name']) ?: ($x['donor_name'] ?: 'Anonymous');
        $rows[] = [
            $x['receipt_no']      ?? '',
            trim($x['member_name'] ?? ''),
            $x['member_no']       ?? '',
            $x['donor_name']      ?? '',
            $x['amount']          ?? 0,
            $x['payment_method']  ?? '',
            $x['collection_date'] ?? '',
            $x['item_description']?? '',
            $x['notes']           ?? '',
        ];
    }
    return $rows;
}

/* ===========================================================
   PDF HTML BUILDERS
=========================================================== */

/** Common inline styles shared by both PDF templates. */
function fr_pdf_styles(): string
{
    return '<style>
  body  { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a2e; margin:0; padding:0; }
  .hdr  { background:#4a1e8f; color:#fff; padding:14px 20px 10px; }
  .hdr h1 { margin:0 0 3px; font-size:17px; }
  .hdr p  { margin:0; font-size:9px; opacity:.85; }
  table { width:100%; border-collapse:collapse; font-size:8.5px; margin-top:8px; }
  thead th { background:#4a1e8f; color:#fff; padding:5px 6px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.4px; }
  tbody td { padding:4px 6px; border-bottom:1px solid #ede9fe; vertical-align:top; }
  .progress-wrap { background:#e5e7eb; border-radius:4px; height:8px; margin-top:2px; }
  .progress-fill { background:#4a1e8f; height:8px; border-radius:4px; }
  .badge { padding:2px 6px; border-radius:4px; font-size:7.5px; font-weight:700; }
  .badge-active    { background:#dcfce7; color:#15803d; }
  .badge-planning  { background:#fef9c3; color:#854d0e; }
  .badge-completed { background:#dbeafe; color:#1d4ed8; }
  .badge-cancelled { background:#fee2e2; color:#991b1b; }
  .summary { display:flex; gap:20px; padding:8px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; font-size:8.5px; }
  .summary-box .val { font-size:18px; font-weight:800; }
  .summary-box .lbl { font-size:7.5px; color:#64748b; text-transform:uppercase; }
  .footer { text-align:center; font-size:7.5px; color:#9ca3af; margin-top:14px; }
</style>';
}

function fr_build_campaigns_pdf(array $campaigns, string $statusFilter): string
{
    $genDate = date('d M Y H:i');
    $total   = count($campaigns);
    $totalTarget = array_sum(array_column($campaigns, 'target_amount'));
    $totalRaised = array_sum(array_column($campaigns, 'raised'));
    $totalDons   = array_sum(array_column($campaigns, 'donations'));
    $pctOverall  = $totalTarget > 0 ? min(100, round($totalRaised / $totalTarget * 100)) : 0;
    $subtitle    = $statusFilter !== '' ? 'Status: ' . fr_h($statusFilter) : 'All Statuses';

    $rows = '';
    $i    = 1;
    foreach ($campaigns as $c) {
        $bg  = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $pct = (float)($c['target_amount'] ?? 0) > 0
            ? min(100, round((float)($c['raised'] ?? 0) / (float)$c['target_amount'] * 100))
            : 0;
        $statusVal = $c['status'] ?? 'Planning';
        $badgeCls  = match($statusVal) {
            'Active'    => 'badge-active',
            'Completed' => 'badge-completed',
            'Cancelled' => 'badge-cancelled',
            default     => 'badge-planning',
        };
        $rows .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#6b7280">' . $i . '</td>
            <td>' . fr_h($c['campaign_no'] ?? '') . '</td>
            <td style="font-weight:600">' . fr_h($c['title'] ?? '') . '</td>
            <td><span class="badge ' . $badgeCls . '">' . fr_h($statusVal) . '</span></td>
            <td>' . fr_h($c['organizer'] ?? '-') . '</td>
            <td>' . fr_h($c['start_date'] ?? '-') . '</td>
            <td>' . fr_h($c['end_date'] ?: 'Ongoing') . '</td>
            <td style="text-align:right">' . fr_h(fr_money((float)($c['target_amount'] ?? 0))) . '</td>
            <td style="text-align:right;font-weight:600">' . fr_h(fr_money((float)($c['raised'] ?? 0))) . '</td>
            <td style="text-align:center">' . (int)($c['donations'] ?? 0) . '</td>
            <td>
                <div class="progress-wrap">
                    <div class="progress-fill" style="width:' . $pct . '%"></div>
                </div>
                <div style="font-size:7px;color:#4a1e8f;text-align:right">' . $pct . '%</div>
            </td>
        </tr>';
        $i++;
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="11" style="text-align:center;color:#9ca3af;padding:20px">No campaigns found.</td></tr>';
    }

    return '<!DOCTYPE html><html><head><meta charset="utf-8">' . fr_pdf_styles() . '</head><body>
<div class="hdr">
  <h1>&#128226; Fundraising Campaigns Report</h1>
  <p>' . $subtitle . ' &bull; Total: ' . $total . ' &bull; Generated: ' . $genDate . '</p>
</div>
<div class="summary">
  <div class="summary-box"><div class="val">' . $total . '</div><div class="lbl">Campaigns</div></div>
  <div class="summary-box"><div class="val">' . fr_h(fr_money($totalTarget)) . '</div><div class="lbl">Total Target</div></div>
  <div class="summary-box"><div class="val" style="color:#4a1e8f">' . fr_h(fr_money($totalRaised)) . '</div><div class="lbl">Total Raised</div></div>
  <div class="summary-box"><div class="val">' . $totalDons . '</div><div class="lbl">Collections</div></div>
  <div class="summary-box"><div class="val">' . $pctOverall . '%</div><div class="lbl">Overall Progress</div></div>
</div>
<table>
  <thead><tr>
    <th style="width:22px">#</th>
    <th>Campaign No</th>
    <th>Title</th>
    <th>Status</th>
    <th>Organizer</th>
    <th>Start</th>
    <th>End</th>
    <th style="text-align:right">Target</th>
    <th style="text-align:right">Raised</th>
    <th style="text-align:center">Collections</th>
    <th style="width:60px">Progress</th>
  </tr></thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Fundraising Report &mdash; ' . $genDate . '</div>
</body></html>';
}

function fr_build_collections_pdf(mysqli $conn, int $campaignId, array $collections): string
{
    $genDate = date('d M Y H:i');

    // Fetch campaign info for the heading
    $camp = fr_fetch_one($conn,
        "SELECT campaign_no, title, target_amount, COALESCE(SUM(fc.amount),0) raised, COUNT(fc.id) donations
         FROM fundraising f
         LEFT JOIN fundraising_collections fc ON fc.campaign_id=f.id
         WHERE f.id=?
         GROUP BY f.id", 'i', [$campaignId]);

    $title      = $camp ? fr_h($camp['title'] ?? 'Campaign') : 'Campaign';
    $campaignNo = $camp ? fr_h($camp['campaign_no'] ?? '') : '';
    $target     = (float)($camp['target_amount'] ?? 0);
    $raised     = (float)($camp['raised']        ?? 0);
    $donations  = (int)($camp['donations']       ?? 0);
    $pct        = $target > 0 ? min(100, round($raised / $target * 100)) : 0;

    $rows   = '';
    $total  = count($collections);
    $grandTotal = 0;
    $i      = 1;
    foreach ($collections as $x) {
        $bg     = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $donor  = trim((string)($x['member_name'] ?? '')) ?: ($x['donor_name'] ?: 'Anonymous');
        $amount = (float)($x['amount'] ?? 0);
        $grandTotal += $amount;
        $rows .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#6b7280">' . $i . '</td>
            <td>' . fr_h($x['receipt_no'] ?? '') . '</td>
            <td style="font-weight:600">' . fr_h($donor) . '</td>
            <td>' . fr_h($x['member_no'] ?? '-') . '</td>
            <td style="text-align:right;font-weight:600">' . fr_h(fr_money($amount)) . '</td>
            <td>' . fr_h($x['payment_method'] ?? '-') . '</td>
            <td>' . fr_h($x['collection_date'] ?? '-') . '</td>
            <td>' . fr_h($x['item_description'] ?: '-') . '</td>
        </tr>';
        $i++;
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:20px">No collections recorded.</td></tr>';
    }

    // Grand-total footer row
    $rows .= '<tr style="background:#f0ecff;font-weight:700;border-top:2px solid #4a1e8f">
        <td colspan="4" style="text-align:right;padding:5px 6px">Grand Total</td>
        <td style="text-align:right;padding:5px 6px">' . fr_h(fr_money($grandTotal)) . '</td>
        <td colspan="3"></td>
    </tr>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8">' . fr_pdf_styles() . '</head><body>
<div class="hdr">
  <h1>&#128176; Collections Register &mdash; ' . $title . '</h1>
  <p>' . $campaignNo . ' &bull; Generated: ' . $genDate . '</p>
</div>
<div class="summary">
  <div class="summary-box"><div class="val">' . $total . '</div><div class="lbl">Records</div></div>
  <div class="summary-box"><div class="val">' . fr_h(fr_money($target)) . '</div><div class="lbl">Target</div></div>
  <div class="summary-box"><div class="val" style="color:#4a1e8f">' . fr_h(fr_money($raised)) . '</div><div class="lbl">Raised</div></div>
  <div class="summary-box"><div class="val">' . $pct . '%</div><div class="lbl">Progress</div></div>
</div>
<table>
  <thead><tr>
    <th style="width:22px">#</th>
    <th>Receipt No</th>
    <th>Donor / Member</th>
    <th>Member No</th>
    <th style="text-align:right">Amount</th>
    <th>Method</th>
    <th>Date</th>
    <th>Item / Description</th>
  </tr></thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Fundraising Collections &mdash; ' . $genDate . '</div>
</body></html>';
}

/* ===========================================================
   EMAIL / SMS CONFIRMATION  (unchanged from original)
=========================================================== */

function fr_build_message(array $r): array
{
    $donor   = trim((string)($r['full_name'] ?? '')) ?: (trim((string)($r['donor_name'] ?? '')) ?: 'Donor');
    $receipt = (string)($r['receipt_no'] ?? ('FRC-' . ($r['id'] ?? '')));
    $amount  = 'UGX ' . number_format((float)($r['amount'] ?? 0), 0);
    $campaign= (string)($r['campaign_title'] ?? 'Fundraising Campaign');
    $date    = (string)($r['collection_date'] ?? date('Y-m-d'));
    $method  = (string)($r['payment_method'] ?? 'Cash');
    $subject = 'Fundraising Payment Confirmation - ' . $receipt;
    $html = "<div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:24px'>"
        . "<div style='max-width:650px;margin:auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #e5e7eb'>"
        . "<h2 style='color:#4a1e8f;margin-top:0'>Payment Confirmation</h2>"
        . "<p>Dear <strong>" . fr_h($donor) . "</strong>,</p>"
        . "<p>Thank you for your fundraising contribution. Your payment has been received successfully.</p>"
        . "<table style='width:100%;border-collapse:collapse;margin:16px 0'>"
        . "<tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Receipt No</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>" . fr_h($receipt) . "</td></tr>"
        . "<tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Campaign</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>" . fr_h($campaign) . "</td></tr>"
        . "<tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Amount</strong></td><td style='padding:10px;border:1px solid #e5e7eb'><strong>" . fr_h($amount) . "</strong></td></tr>"
        . "<tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Date</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>" . fr_h($date) . "</td></tr>"
        . "<tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Method</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>" . fr_h($method) . "</td></tr>"
        . "</table><p>May God bless you for supporting the church fundraising campaign.</p>"
        . "<p style='margin-bottom:0'>" . fr_h(APP_NAME) . "</p></div></div>";
    $text = "Dear {$donor}, thank you for your fundraising contribution of {$amount} to {$campaign}. Receipt: {$receipt}. Date: {$date}. " . APP_NAME . ".";
    $sms  = APP_NAME . ": Thank you {$donor} for your fundraising contribution of {$amount} to {$campaign}. Receipt {$receipt}.";
    return ['subject' => $subject, 'html' => $html, 'text' => $text, 'sms' => $sms];
}

function fr_send_email_confirm(mysqli $conn, array $r): bool
{
    $email = trim((string)($r['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !function_exists('sendSystemEmail')) return false;
    $donor = trim((string)($r['full_name'] ?? '')) ?: (trim((string)($r['donor_name'] ?? '')) ?: 'Donor');
    $m = fr_build_message($r);
    return (bool)sendSystemEmail($conn, $email, $donor, $m['subject'], $m['html'], $m['text']);
}

function fr_send_sms_confirm(mysqli $conn, array $r): bool
{
    $phone = preg_replace('/\s+/', '', (string)($r['phone'] ?? ''));
    if ($phone === '') return false;
    $m = fr_build_message($r);
    if (function_exists('sendSystemSMS')) return (bool)sendSystemSMS($conn, $phone, $m['sms']);
    if (function_exists('sendSms'))       return (bool)sendSms($conn, $phone, $m['sms']);
    if (function_exists('sendSMS'))       return (bool)sendSMS($conn, $phone, $m['sms']);
    if (!fr_table_exists($conn, 'sms_settings')) return false;
    $settings = fr_fetch_one($conn, "SELECT * FROM sms_settings WHERE is_active=1 ORDER BY id DESC LIMIT 1");
    if (!$settings) return false;
    $url = (string)($settings['api_url'] ?? $settings['gateway_url'] ?? '');
    if ($url === '' || !function_exists('curl_init')) return false;
    $apiKey   = (string)($settings['api_key']     ?? '');
    $sender   = (string)($settings['sender_id']   ?? $settings['sender_name'] ?? '');
    $username = (string)($settings['username']    ?? '');
    $password = (string)($settings['password']    ?? '');
    $repl     = [
        '{phone}'=>rawurlencode($phone), '{message}'=>rawurlencode($m['sms']),
        '{api_key}'=>rawurlencode($apiKey), '{sender_id}'=>rawurlencode($sender),
        '{username}'=>rawurlencode($username), '{password}'=>rawurlencode($password),
    ];
    $finalUrl = strtr($url, $repl);
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$finalUrl, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_SSL_VERIFYPEER=>false]);
    $resp = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $err !== '') { error_log('Fundraising SMS failed: '.$err); return false; }
    return $code >= 200 && $code < 300;
}

function fr_collection_row(mysqli $conn, int $id): ?array
{
    $sql = "SELECT fc.*, f.title campaign_title, f.campaign_no,
                   m.full_name,
                   m.member_no, m.email, m.phone
            FROM fundraising_collections fc
            LEFT JOIN fundraising f ON f.id=fc.campaign_id
            LEFT JOIN members m ON m.id=fc.member_id
            WHERE fc.id=? LIMIT 1";
    return fr_fetch_one($conn, $sql, 'i', [$id]);
}

function fr_send_payment_confirmation(mysqli $conn, int $collectionId): array
{
    $row = fr_collection_row($conn, $collectionId);
    if (!$row) return ['email_sent' => false, 'sms_sent' => false];
    return [
        'email_sent' => fr_send_email_confirm($conn, $row),
        'sms_sent'   => fr_send_sms_confirm($conn, $row),
    ];
}

/* ===========================================================
   MAIN ROUTER
=========================================================== */
try {
    if (!isset($conn) || !($conn instanceof mysqli)) fr_json(false, 'Database connection not found.');
    $conn->set_charset('utf8mb4');
    if (!fr_table_exists($conn, 'fundraising')) fr_json(false, 'fundraising table not found.');

    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? fr_post('action') : fr_get('action', 'list');

    /* -------------------------------------------------------
       EXPORT: campaigns CSV
    ------------------------------------------------------- */
    if ($action === 'export_csv') {
        $status    = fr_get('status');
        if (!in_array($status, ['Planning','Active','Completed','Cancelled',''], true)) $status = '';
        $campaigns = fr_fetch_campaigns_for_export($conn, $status);
        $csvRows   = fr_build_campaigns_csv($campaigns);
        fr_csv_out('fundraising_campaigns_' . date('Ymd_His') . '.csv', $csvRows);
    }

    /* -------------------------------------------------------
       EXPORT: campaigns PDF
    ------------------------------------------------------- */
    if ($action === 'export_pdf') {
        $status    = fr_get('status');
        if (!in_array($status, ['Planning','Active','Completed','Cancelled',''], true)) $status = '';
        $campaigns = fr_fetch_campaigns_for_export($conn, $status);
        $html      = fr_build_campaigns_pdf($campaigns, $status);
        fr_pdf_out('fundraising_campaigns_' . date('Ymd_His') . '.pdf', $html);
    }

    /* -------------------------------------------------------
       EXPORT: single campaign collections CSV
    ------------------------------------------------------- */
    if ($action === 'export_collections_csv') {
        $campaignId = (int)fr_get('campaign_id');
        if ($campaignId <= 0) { header('Content-Type: text/plain'); echo 'Invalid campaign ID.'; exit; }
        $collections = fr_fetch_collections_for_export($conn, $campaignId);
        $csvRows     = fr_build_collections_csv($collections);
        fr_csv_out('collections_campaign_' . $campaignId . '_' . date('Ymd_His') . '.csv', $csvRows);
    }

    /* -------------------------------------------------------
       EXPORT: single campaign collections PDF
    ------------------------------------------------------- */
    if ($action === 'export_collections_pdf') {
        $campaignId = (int)fr_get('campaign_id');
        if ($campaignId <= 0) { header('Content-Type: text/plain'); echo 'Invalid campaign ID.'; exit; }
        $collections = fr_fetch_collections_for_export($conn, $campaignId);
        $html        = fr_build_collections_pdf($conn, $campaignId, $collections);
        fr_pdf_out('collections_campaign_' . $campaignId . '_' . date('Ymd_His') . '.pdf', $html);
    }

    /* -------------------------------------------------------
       LIST campaigns
    ------------------------------------------------------- */
    if ($action === 'list') {
        $sql = "SELECT f.*, COALESCE(SUM(fc.amount),0) raised, COUNT(fc.id) donations
                FROM fundraising f
                LEFT JOIN fundraising_collections fc ON fc.campaign_id=f.id
                GROUP BY f.id
                ORDER BY COALESCE(f.start_date, f.id) DESC, f.id DESC";
        fr_json(true, 'Loaded.', ['items' => fr_fetch_all($conn, $sql)]);
    }

    /* -------------------------------------------------------
       STATS
    ------------------------------------------------------- */
    if ($action === 'stats') {
        $row    = fr_fetch_one($conn, "SELECT COUNT(*) campaigns, COALESCE(SUM(target_amount),0) target_total, SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) active_campaigns FROM fundraising");
        $raised = fr_fetch_one($conn, "SELECT COALESCE(SUM(amount),0) total_raised, COUNT(*) collections FROM fundraising_collections");
        fr_json(true, 'Loaded.', ['stats' => array_merge($row ?: [], $raised ?: [])]);
    }

    /* -------------------------------------------------------
       MEMBERS dropdown
    ------------------------------------------------------- */
    if ($action === 'members') {
        if (!fr_table_exists($conn, 'members')) fr_json(true, 'Loaded.', ['items' => []]);
        $items = fr_fetch_all($conn, "SELECT id, member_no, full_name, email, phone FROM members ORDER BY full_name LIMIT 500");
        fr_json(true, 'Loaded.', ['items' => $items]);
    }

    /* -------------------------------------------------------
       GET single campaign
    ------------------------------------------------------- */
    if ($action === 'get') {
        $id   = (int)fr_get('id');
        $item = fr_fetch_one($conn, "SELECT * FROM fundraising WHERE id=?", 'i', [$id]);
        if (!$item) fr_json(false, 'Campaign not found.');
        $summary = fr_fetch_one($conn, "SELECT COALESCE(SUM(amount),0) raised, COUNT(id) donations FROM fundraising_collections WHERE campaign_id=?", 'i', [$id]);
        fr_json(true, 'Loaded.', ['item' => array_merge($item, $summary ?: [])]);
    }

    /* -------------------------------------------------------
       COLLECTIONS list for a campaign
    ------------------------------------------------------- */
    if ($action === 'collections') {
        $campaign_id = (int)fr_get('campaign_id');
        $sql = "SELECT fc.*, m.full_name, m.member_no, m.email, m.phone
                FROM fundraising_collections fc
                LEFT JOIN members m ON m.id=fc.member_id
                WHERE fc.campaign_id=?
                ORDER BY fc.collection_date DESC, fc.id DESC";
        fr_json(true, 'Loaded.', ['items' => fr_fetch_all($conn, $sql, 'i', [$campaign_id])]);
    }

    /* -------------------------------------------------------
       SAVE campaign (create / update)
    ------------------------------------------------------- */
    if ($action === 'save_campaign') {
        $id           = (int)fr_post('id');
        $title        = fr_post('title');
        if ($title === '') fr_json(false, 'Campaign title is required.');
        $description  = fr_post('description');
        $target_amount= (float)fr_post('target_amount', '0');
        $start_date   = fr_post('start_date', date('Y-m-d')) ?: date('Y-m-d');
        $end_date     = fr_post('end_date');
        $venue        = fr_post('venue');
        $organizer    = fr_post('organizer');
        $status       = fr_post('status', 'Planning') ?: 'Planning';

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE fundraising SET title=?,description=?,target_amount=?,start_date=?,end_date=NULLIF(?,''),venue=?,organizer=?,status=? WHERE id=?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('ssdsssssi', $title,$description,$target_amount,$start_date,$end_date,$venue,$organizer,$status,$id);
            $stmt->execute(); $stmt->close();
            fr_json(true, 'Campaign updated successfully.');
        } else {
            $campaign_no = fr_gen_no('FND');
            $stmt = $conn->prepare("INSERT INTO fundraising (campaign_no,title,description,target_amount,start_date,end_date,venue,organizer,status) VALUES (?,?,?,?,?,NULLIF(?,''),?,?,?)");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('sssdsssss', $campaign_no,$title,$description,$target_amount,$start_date,$end_date,$venue,$organizer,$status);
            $stmt->execute(); $stmt->close();
            fr_json(true, 'Campaign created successfully.');
        }
    }

    /* -------------------------------------------------------
       DELETE campaign
    ------------------------------------------------------- */
    if ($action === 'delete_campaign') {
        $id = (int)fr_post('id');
        if ($id <= 0) fr_json(false, 'Invalid campaign.');
        $stmt = $conn->prepare("DELETE FROM fundraising_collections WHERE campaign_id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("DELETE FROM fundraising WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        fr_json(true, 'Campaign deleted successfully.');
    }

    /* -------------------------------------------------------
       SAVE collection (create / update)
    ------------------------------------------------------- */
    if ($action === 'save_collection') {
        if (!fr_table_exists($conn, 'fundraising_collections')) fr_json(false, 'fundraising_collections table not found.');
        $id               = (int)fr_post('id');
        $campaign_id      = (int)fr_post('campaign_id');
        $member_id_raw    = fr_post('member_id');
        $member_id        = $member_id_raw === '' ? null : (int)$member_id_raw;
        $donor_name       = fr_post('donor_name');
        $amount           = (float)fr_post('amount', '0');
        $collection_date  = fr_post('collection_date', date('Y-m-d')) ?: date('Y-m-d');
        $payment_method   = fr_post('payment_method', 'Cash') ?: 'Cash';
        $item_description = fr_post('item_description');
        $notes            = fr_post('notes');
        if ($campaign_id <= 0) fr_json(false, 'Campaign is required.');
        if ($amount <= 0 && $item_description === '') fr_json(false, 'Enter amount or item description.');

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE fundraising_collections SET campaign_id=?,member_id=?,donor_name=?,amount=?,collection_date=?,payment_method=?,item_description=?,notes=? WHERE id=?");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('iisdssssi', $campaign_id,$member_id,$donor_name,$amount,$collection_date,$payment_method,$item_description,$notes,$id);
            $stmt->execute(); $stmt->close();
            $sent = fr_send_payment_confirmation($conn, $id);
            fr_json(true, 'Collection updated successfully.', $sent);
        } else {
            $receipt_no = fr_gen_no('FRC');
            $stmt = $conn->prepare("INSERT INTO fundraising_collections (campaign_id,receipt_no,member_id,donor_name,amount,collection_date,payment_method,item_description,notes) VALUES (?,?,?,?,?,?,?,?,?)");
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param('isisdssss', $campaign_id,$receipt_no,$member_id,$donor_name,$amount,$collection_date,$payment_method,$item_description,$notes);
            $stmt->execute(); $new_id = $stmt->insert_id; $stmt->close();
            $sent = fr_send_payment_confirmation($conn, (int)$new_id);
            fr_json(true, 'Collection recorded successfully.', array_merge(['id' => $new_id, 'receipt_url' => 'download_receipt.php?id='.$new_id], $sent));
        }
    }

    /* -------------------------------------------------------
       GET single collection
    ------------------------------------------------------- */
    if ($action === 'get_collection') {
        $id  = (int)fr_get('id');
        $sql = "SELECT fc.*, m.full_name, m.email, m.phone, f.title campaign_title
                FROM fundraising_collections fc
                LEFT JOIN members m ON m.id=fc.member_id
                LEFT JOIN fundraising f ON f.id=fc.campaign_id
                WHERE fc.id=?";
        $item = fr_fetch_one($conn, $sql, 'i', [$id]);
        if (!$item) fr_json(false, 'Collection not found.');
        fr_json(true, 'Loaded.', ['item' => $item]);
    }

    /* -------------------------------------------------------
       RESEND confirmation
    ------------------------------------------------------- */
    if ($action === 'resend_confirmation') {
        $id = (int)fr_post('id');
        if ($id <= 0) fr_json(false, 'Invalid collection.');
        $sent = fr_send_payment_confirmation($conn, $id);
        fr_json(true, 'Confirmation processed.', $sent);
    }

    /* -------------------------------------------------------
       DELETE collection
    ------------------------------------------------------- */
    if ($action === 'delete_collection') {
        $id = (int)fr_post('id');
        if ($id <= 0) fr_json(false, 'Invalid collection.');
        $stmt = $conn->prepare("DELETE FROM fundraising_collections WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        fr_json(true, 'Collection deleted successfully.');
    }

    fr_json(false, 'Invalid request action.');

} catch (Throwable $e) {
    error_log('[process-fundraising] ' . $e->getMessage());
    fr_json(false, 'Server error: ' . $e->getMessage());
}