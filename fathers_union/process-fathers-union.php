<?php
declare(strict_types=1);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
try {
    if (file_exists('../config/mail-function.php')) require_once '../config/mail-function.php';
} catch (Throwable $e) { error_log($e->getMessage()); }
if (file_exists('../config/sms-function.php'))  require_once '../config/sms-function.php';
if (file_exists('../config/sms-functions.php')) require_once '../config/sms-functions.php';

if (function_exists('requireLogin')) requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}
$conn->set_charset('utf8mb4');

/* ===========================================================
   OUTPUT HELPERS
=========================================================== */

function fu_out(array $payload): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function fu_csv_out(string $filename, array $rows): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM � Excel-safe
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => (string)($v ?? ''), $row));
    }
    fclose($out);
    exit;
}

function fu_pdf_out(string $filename, string $html): void
{
    while (ob_get_level() > 0) ob_end_clean();

    // Locate Composer autoload � checks three common project layouts.
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

function fu_clean($v): string { return trim((string)($v ?? '')); }
function fu_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function fu_money(float $n): string { return 'UGX ' . number_format($n, 0); }

function fu_table_exists(string $table): bool
{
    global $conn;
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $st = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->bind_param('s', $table); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $cache[$table] = ((int)($r['c'] ?? 0) > 0);
}

function fu_col_exists(string $table, string $col): bool
{
    global $conn;
    static $cache = [];
    $k = "$table.$col"; if (isset($cache[$k])) return $cache[$k];
    $st = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->bind_param('ss', $table, $col); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $cache[$k] = ((int)($r['c'] ?? 0) > 0);
}

function fu_gen_no(mysqli $conn): string
{
    if (function_exists('genNo')) { $no = genNo('FU'); if ($no) return (string)$no; }
    $prefix = 'FU-' . date('Y') . '-';
    $res    = $conn->query("SELECT fu_no FROM fathers_union WHERE fu_no LIKE '" .
                           $conn->real_escape_string($prefix) . "%' ORDER BY id DESC LIMIT 1");
    $next = 1;
    if ($res && ($row = $res->fetch_assoc()) && preg_match('/(\d+)$/', (string)$row['fu_no'], $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function fu_fetch(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        "SELECT f.*, m.full_name AS full_name,
                m.member_no, m.phone, m.email
         FROM fathers_union f
         LEFT JOIN members m ON m.id = f.member_id
         WHERE f.id = ? LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function fu_payment_history(array $row): array
{
    $amount = (float)($row['subscription'] ?? 0);
    if ($amount <= 0) return [];
    return [[
        'receipt_no'   => 'FU-RCT-' . str_pad((string)($row['id'] ?? 0), 5, '0', STR_PAD_LEFT),
        'payment_date' => $row['date_joined'] ?? date('Y-m-d'),
        'amount'       => $amount,
        'method'       => 'Subscription',
        'description'  => "Fathers' Union Annual Subscription",
        'receipt_url'  => 'download_receipt.php?id=' . (int)($row['id'] ?? 0),
    ]];
}

/* ===========================================================
   EXPORT DATA FETCHER
=========================================================== */

/**
 * Fetch all Fathers' Union records, optionally filtered by status.
 */
function fu_fetch_all_for_export(mysqli $conn, string $status = ''): array
{
    $where  = '';
    $params = [];
    $types  = '';
    if ($status !== '' && in_array($status, ['Active','Inactive','Suspended'], true)) {
        $where  = "WHERE COALESCE(f.status,'Active') = ?";
        $params = [$status];
        $types  = 's';
    }
    $sql = "SELECT f.fu_no, f.position, f.date_joined, f.subscription, f.status, f.notes,
                   m.full_name AS full_name,
                   m.member_no, m.phone, m.email
            FROM fathers_union f
            LEFT JOIN members m ON m.id = f.member_id
            $where
            ORDER BY m.full_name, f.id";
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

function fu_build_csv(array $members): array
{
    $rows   = [];
    $rows[] = [
        'FU No.', 'Full Name', 'Member No', 'Phone', 'Email',
        'Position', 'Date Joined', 'Subscription (UGX)', 'Status', 'Notes',
    ];
    foreach ($members as $r) {
        $rows[] = [
            $r['fu_no']       ?? '',
            $r['full_name']   ?? '',
            $r['member_no']   ?? '',
            $r['phone']       ?? '',
            $r['email']       ?? '',
            $r['position']    ?? '',
            $r['date_joined'] ?? '',
            $r['subscription']?? 0,
            $r['status']      ?? '',
            $r['notes']       ?? '',
        ];
    }
    return $rows;
}

/* ===========================================================
   PDF HTML BUILDER
=========================================================== */

function fu_build_pdf(array $members, string $statusFilter): string
{
    $genDate  = date('d M Y H:i');
    $total    = count($members);
    $active   = count(array_filter($members, fn($r) => ($r['status'] ?? 'Active') === 'Active'));
    $totalSub = array_sum(array_column($members, 'subscription'));
    $subtitle = $statusFilter !== '' ? 'Status: ' . fu_h($statusFilter) : 'All Statuses';

    $rows = '';
    $i    = 1;
    foreach ($members as $r) {
        $bg      = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $status  = $r['status'] ?? 'Active';
        $sClr    = match($status) {
            'Active'    => '#15803d',
            'Suspended' => '#854d0e',
            default     => '#6b7280',
        };
        $pos   = $r['position'] ?? 'Member';
        $pClr  = $pos !== 'Member' ? '#1d4ed8' : '#374151';
        $sub   = (float)($r['subscription'] ?? 0);
        $rows .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#6b7280">' . $i . '</td>
            <td style="font-weight:600">' . fu_h($r['fu_no'] ?? '-') . '</td>
            <td style="font-weight:700">' . fu_h($r['full_name'] ?? '-') . '</td>
            <td>' . fu_h($r['member_no'] ?? '-') . '</td>
            <td>' . fu_h($r['phone'] ?? '-') . '</td>
            <td style="color:' . $pClr . ';font-weight:600">' . fu_h($pos) . '</td>
            <td>' . fu_h($r['date_joined'] ?? '-') . '</td>
            <td style="text-align:right;font-weight:600">' . fu_h($sub > 0 ? fu_money($sub) : '-') . '</td>
            <td style="color:' . $sClr . ';font-weight:700;text-align:center">' . fu_h($status) . '</td>
        </tr>';
        $i++;
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:20px">No records found.</td></tr>';
    }

    // Grand-total footer row
    $rows .= '<tr style="background:#f0ecff;font-weight:700;border-top:2px solid #4a1e8f">
        <td colspan="7" style="text-align:right;padding:5px 6px">Total Subscriptions</td>
        <td style="text-align:right;padding:5px 6px">' . fu_h(fu_money($totalSub)) . '</td>
        <td></td>
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
  .summary { display:flex; gap:24px; padding:8px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; font-size:8.5px; }
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
  <h1>&#128104; Fathers\' Union Register</h1>
  <p>' . $subtitle . ' &bull; Total: ' . $total . ' &bull; Generated: ' . $genDate . '</p>
</div>
<div class="summary">
  <div class="summary-box"><div class="val">' . $total . '</div><div class="lbl">Total Enrolled</div></div>
  <div class="summary-box"><div class="val" style="color:#15803d">' . $active . '</div><div class="lbl">Active</div></div>
  <div class="summary-box"><div class="val" style="color:#6b7280">' . ($total - $active) . '</div><div class="lbl">Inactive / Suspended</div></div>
  <div class="summary-box"><div class="val" style="color:#4a1e8f">' . fu_h(fu_money($totalSub)) . '</div><div class="lbl">Total Subscriptions</div></div>
</div>
<table>
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>FU No.</th>
      <th>Full Name</th>
      <th>Member No</th>
      <th>Phone</th>
      <th>Position</th>
      <th>Date Joined</th>
      <th style="text-align:right">Subscription</th>
      <th style="text-align:center">Status</th>
    </tr>
  </thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Fathers\' Union Register &mdash; ' . $genDate . '</div>
</body>
</html>';
}

/* ===========================================================
   EMAIL / SMS CONFIRMATION  (unchanged from original)
=========================================================== */

function fu_send_email_confirmation(array $row): bool
{
    global $conn;
    $email = fu_clean($row['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !function_exists('sendSystemEmail'))
        return false;
    $name      = trim((string)($row['full_name'] ?? '')) ?: 'Member';
    $receiptNo = 'FU-RCT-' . str_pad((string)($row['id'] ?? 0), 5, '0', STR_PAD_LEFT);
    $amount    = number_format((float)($row['subscription'] ?? 0));
    $date      = fu_clean($row['date_joined'] ?? date('Y-m-d'));
    $fuNo      = fu_clean($row['fu_no'] ?? '');
    $subject   = "Fathers' Union Payment Confirmation - {$receiptNo}";
    $nameH     = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
    $rcptH     = htmlspecialchars($receiptNo, ENT_QUOTES, 'UTF-8');
    $fuNoH     = htmlspecialchars($fuNo,      ENT_QUOTES, 'UTF-8');
    $dateH     = htmlspecialchars($date,      ENT_QUOTES, 'UTF-8');
    $html = "<div style='font-family:Arial,sans-serif;background:#f6f7fb;padding:24px'>
        <div style='max-width:650px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px'>
            <h2 style='color:#4a1e8f;margin-top:0'>Fathers' Union Payment Confirmation</h2>
            <p>Dear <strong>{$nameH}</strong>,</p>
            <p>Thank you. Your Fathers' Union subscription payment has been received successfully.</p>
            <table style='width:100%;border-collapse:collapse;margin:18px 0'>
                <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Receipt No</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>{$rcptH}</td></tr>
                <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>FU No</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>{$fuNoH}</td></tr>
                <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Amount</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>UGX {$amount}</td></tr>
                <tr><td style='padding:10px;border:1px solid #e5e7eb;background:#fafafa'><strong>Date</strong></td><td style='padding:10px;border:1px solid #e5e7eb'>{$dateH}</td></tr>
            </table>
            <p style='margin-bottom:0'>Blessings,<br><strong>" . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . "</strong></p>
        </div>
    </div>";
    $text = "Dear {$name},\n\nThank you. Your Fathers' Union subscription payment has been received.\n"
          . "Receipt No: {$receiptNo}\nFU No: {$fuNo}\nAmount: UGX {$amount}\nDate: {$date}\n\n" . APP_NAME;
    try { return sendSystemEmail($conn, $email, $name, $subject, $html, $text); }
    catch (Throwable $e) { error_log('FU email: ' . $e->getMessage()); return false; }
}

function fu_send_sms_confirmation(array $row): bool
{
    global $conn;
    $phone = fu_clean($row['phone'] ?? '');
    if ($phone === '') return false;
    $receiptNo = 'FU-RCT-' . str_pad((string)($row['id'] ?? 0), 5, '0', STR_PAD_LEFT);
    $amount    = number_format((float)($row['subscription'] ?? 0));
    $date      = fu_clean($row['date_joined'] ?? date('Y-m-d'));
    $msg = APP_NAME . ": Fathers' Union payment received. UGX {$amount}. Receipt: {$receiptNo}. Date: {$date}. Thank you.";
    try {
        foreach (['sendSms','sendSystemSms','sendSMS','sendSystemSMS'] as $fn) {
            if (function_exists($fn)) return (bool)$fn($conn, $phone, $msg);
        }
    } catch (Throwable $e) { error_log('FU SMS: ' . $e->getMessage()); }
    return false;
}

function fu_send_confirmation(int $id): array
{
    global $conn;
    $row = fu_fetch($conn, $id);
    if (!$row || (float)($row['subscription'] ?? 0) <= 0)
        return ['email_sent' => false, 'sms_sent' => false];
    return [
        'email_sent' => fu_send_email_confirmation($row),
        'sms_sent'   => fu_send_sms_confirmation($row),
    ];
}

/* ===========================================================
   MAIN ROUTER
=========================================================== */
try {
    if (!fu_table_exists('fathers_union'))
        fu_out(['success' => false, 'message' => "fathers_union table does not exist."]);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? 'list');

    /* -------------------------------------------------------
       EXPORT: members CSV  (GET ?action=export_csv[&status=X])
    ------------------------------------------------------- */
    if ($action === 'export_csv') {
        $status  = fu_clean($_GET['status'] ?? '');
        if (!in_array($status, ['Active','Inactive','Suspended',''], true)) $status = '';
        $members = fu_fetch_all_for_export($conn, $status);
        $csvRows = fu_build_csv($members);
        fu_csv_out('fathers_union_' . date('Ymd_His') . '.csv', $csvRows);
    }

    /* -------------------------------------------------------
       EXPORT: members PDF  (GET ?action=export_pdf[&status=X])
    ------------------------------------------------------- */
    if ($action === 'export_pdf') {
        $status  = fu_clean($_GET['status'] ?? '');
        if (!in_array($status, ['Active','Inactive','Suspended',''], true)) $status = '';
        $members = fu_fetch_all_for_export($conn, $status);
        $html    = fu_build_pdf($members, $status);
        fu_pdf_out('fathers_union_' . date('Ymd_His') . '.pdf', $html);
    }

    /* -------------------------------------------------------
       LIST
    ------------------------------------------------------- */
    if ($action === 'list') {
        $search=trim((string)($_GET['search']??'')); $position=trim((string)($_GET['position']??'')); $status=trim((string)($_GET['status']??''));
        $period=trim((string)($_GET['period']??'')); $dateFrom=trim((string)($_GET['date_from']??'')); $dateTo=trim((string)($_GET['date_to']??''));
        $page=max(1,(int)($_GET['page']??1)); $perPage=(int)($_GET['per_page']??25); if(!in_array($perPage,[10,25,50,100],true))$perPage=25;
        $where=['1=1'];
        if($search!==''){$q='%'.$conn->real_escape_string($search).'%';$where[]="(f.fu_no LIKE '$q' OR f.position LIKE '$q' OR f.status LIKE '$q' OR m.member_no LIKE '$q' OR m.phone LIKE '$q' OR m.email LIKE '$q' OR m.full_name LIKE '$q')";}
        if($position!=='')$where[]="f.position='".$conn->real_escape_string($position)."'";
        if($status!=='')$where[]="COALESCE(f.status,'Active')='".$conn->real_escape_string($status)."'";
        if($period==='daily')$where[]="f.date_joined=CURDATE()";
        elseif($period==='weekly')$where[]="f.date_joined BETWEEN DATE_SUB(CURDATE(),INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE_ADD(DATE_SUB(CURDATE(),INTERVAL WEEKDAY(CURDATE()) DAY),INTERVAL 6 DAY)";
        elseif($period==='range'){if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))$where[]="f.date_joined>='".$conn->real_escape_string($dateFrom)."'";if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))$where[]="f.date_joined<='".$conn->real_escape_string($dateTo)."'";}
        $whereSql=implode(' AND ',$where); $cr=$conn->query("SELECT COUNT(*) total FROM fathers_union f LEFT JOIN members m ON m.id=f.member_id WHERE $whereSql"); $total=(int)(($cr?$cr->fetch_assoc():[])['total']??0);
        $pages=max(1,(int)ceil($total/$perPage)); if($page>$pages)$page=$pages; $offset=($page-1)*$perPage;
        $sql="SELECT f.*,m.full_name,m.member_no,m.phone,m.email FROM fathers_union f LEFT JOIN members m ON m.id=f.member_id WHERE $whereSql ORDER BY f.date_joined DESC,f.id DESC LIMIT $perPage OFFSET $offset";
        $items=[];$res=$conn->query($sql);while($res&&$row=$res->fetch_assoc())$items[]=$row;
        $pagination=['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)]; fu_out(['success'=>true,'items'=>$items,'pagination'=>$pagination]);
    }

    /* -------------------------------------------------------
       GET single record
    ------------------------------------------------------- */
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) fu_out(['success' => false, 'message' => 'Invalid record ID.']);
        $row = fu_fetch($conn, $id);
        if (!$row) fu_out(['success' => false, 'message' => 'Record not found.']);
        fu_out(['success' => true, 'item' => $row, 'payments' => fu_payment_history($row)]);
    }

    if ($method !== 'POST') fu_out(['success' => false, 'message' => 'Invalid request method.']);

    /* -------------------------------------------------------
       CREATE (single or bulk via member_ids[])
    ------------------------------------------------------- */
    if ($action === 'create') {
        $memberIds = [];
        if (!empty($_POST['member_ids']) && is_array($_POST['member_ids'])) {
            foreach ($_POST['member_ids'] as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) $memberIds[] = $mid;
            }
        } elseif (!empty($_POST['member_id'])) {
            $mid = (int)$_POST['member_id'];
            if ($mid > 0) $memberIds[] = $mid;
        }

        if (empty($memberIds)) fu_out(['success' => false, 'message' => 'Please select at least one member.']);

        $position   = fu_clean($_POST['position']    ?? 'Member') ?: 'Member';
        $dateJoined = fu_clean($_POST['date_joined'] ?? date('Y-m-d')) ?: date('Y-m-d');
        $sub        = max(0, (float)($_POST['subscription'] ?? 0));
        $status     = fu_clean($_POST['status']       ?? 'Active') ?: 'Active';
        $notes      = fu_clean($_POST['notes']        ?? '');

        if (!in_array($position, ['Member','Chairman','Vice Chairman','Secretary','Treasurer','Chaplain'], true))
            $position = 'Member';
        if (!in_array($status, ['Active','Inactive','Suspended'], true))
            $status = 'Active';

        $inserted = 0; $skipped = 0; $lastFuNo = ''; $errors = [];
        $lastSent = ['email_sent' => false, 'sms_sent' => false];

        foreach ($memberIds as $memberId) {
            $dup = $conn->prepare("SELECT id FROM fathers_union WHERE member_id=? LIMIT 1");
            $dup->bind_param('i', $memberId); $dup->execute();
            if ($dup->get_result()->fetch_assoc()) { $dup->close(); $skipped++; continue; }
            $dup->close();

            try {
                $fuNo = fu_gen_no($conn);
                $stmt = $conn->prepare(
                    "INSERT INTO fathers_union (fu_no, member_id, position, date_joined, subscription, status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
                $stmt->bind_param('sissdss', $fuNo, $memberId, $position, $dateJoined, $sub, $status, $notes);
                if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
                $newId = (int)$conn->insert_id;
                $stmt->close();
                $sent     = fu_send_confirmation($newId);
                $inserted++;
                $lastFuNo = $fuNo;
                $lastSent = $sent;
            } catch (Throwable $e) {
                error_log('[FU create] member#' . $memberId . ': ' . $e->getMessage());
                $errors[] = 'Member #' . $memberId . ': ' . $e->getMessage();
            }
        }

        if ($inserted === 0 && $skipped > 0)
            fu_out(['success' => false, 'message' => "All selected members ({$skipped}) are already enrolled in Fathers' Union."]);

        $msg = $inserted === 1
            ? "Member enrolled successfully. No: {$lastFuNo}."
            : "{$inserted} members enrolled successfully.";
        if ($skipped) $msg .= " ({$skipped} skipped � already enrolled.)";
        if ($inserted === 1) {
            $msg .= ' Email: ' . ($lastSent['email_sent'] ? 'sent' : 'not sent') .
                    ', SMS: '  . ($lastSent['sms_sent']   ? 'sent' : 'not sent') . '.';
        }
        if ($errors) $msg .= ' Errors: ' . implode('; ', $errors);

        fu_out(array_merge(
            ['success' => true, 'message' => $msg, 'inserted' => $inserted, 'skipped' => $skipped],
            $lastSent
        ));
    }

    $id = (int)($_POST['id'] ?? 0);

    /* -------------------------------------------------------
       UPDATE
    ------------------------------------------------------- */
    if ($action === 'update') {
        if ($id <= 0) fu_out(['success' => false, 'message' => 'Invalid record ID.']);

        $memberId   = (int)($_POST['member_id'] ?? 0);
        $position   = fu_clean($_POST['position']    ?? 'Member') ?: 'Member';
        $dateJoined = fu_clean($_POST['date_joined'] ?? date('Y-m-d')) ?: date('Y-m-d');
        $sub        = max(0, (float)($_POST['subscription'] ?? 0));
        $status     = fu_clean($_POST['status']       ?? 'Active') ?: 'Active';
        $notes      = fu_clean($_POST['notes']        ?? '');

        if (!in_array($position, ['Member','Chairman','Vice Chairman','Secretary','Treasurer','Chaplain'], true))
            $position = 'Member';
        if (!in_array($status, ['Active','Inactive','Suspended'], true))
            $status = 'Active';

        if ($memberId > 0) {
            $dup = $conn->prepare("SELECT id FROM fathers_union WHERE member_id=? AND id<>? LIMIT 1");
            $dup->bind_param('ii', $memberId, $id); $dup->execute();
            if ($dup->get_result()->fetch_assoc())
                fu_out(['success' => false, 'message' => "This member is already enrolled in Fathers' Union."]);
            $dup->close();
        }

        $stmt = $conn->prepare(
            "UPDATE fathers_union SET position=?, date_joined=?, subscription=?, status=?, notes=? WHERE id=?"
        );
        if (!$stmt) fu_out(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        $stmt->bind_param('ssdssi', $position, $dateJoined, $sub, $status, $notes, $id);
        if (!$stmt->execute()) fu_out(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
        $stmt->close();

        $sent = fu_send_confirmation($id);
        fu_out(array_merge(
            ['success' => true,
             'message' => 'Record updated successfully. Email: ' . ($sent['email_sent'] ? 'sent' : 'not sent') .
                          ', SMS: ' . ($sent['sms_sent'] ? 'sent' : 'not sent') . '.'],
            $sent
        ));
    }

    /* -------------------------------------------------------
       RESEND CONFIRMATION
    ------------------------------------------------------- */
    if ($action === 'resend_confirmation') {
        if ($id <= 0) fu_out(['success' => false, 'message' => 'Invalid record ID.']);
        $sent = fu_send_confirmation($id);
        fu_out(['success'    => true,
                'message'    => 'Confirmation processed.',
                'email_sent' => $sent['email_sent'],
                'sms_sent'   => $sent['sms_sent']]);
    }

    /* -------------------------------------------------------
       DELETE
    ------------------------------------------------------- */
    if ($action === 'delete') {
        if ($id <= 0) fu_out(['success' => false, 'message' => 'Invalid record ID.']);
        $stmt = $conn->prepare("DELETE FROM fathers_union WHERE id=?");
        if (!$stmt) fu_out(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) fu_out(['success' => false, 'message' => 'Delete failed: ' . $stmt->error]);
        $stmt->close();
        fu_out(['success' => true, 'message' => "Member removed from Fathers' Union."]);
    }

    fu_out(['success' => false, 'message' => 'Invalid request action.']);

} catch (Throwable $e) {
    error_log('[process-fathers-union] ' . $e->getMessage());
    fu_out(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}