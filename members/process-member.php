<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/member-fields.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$mailCandidates = [
    __DIR__ . '/../includes/mail-function.php',
    __DIR__ . '/../config/mail-function.php',
];
foreach ($mailCandidates as $mailFile) {
    if (is_file($mailFile)) {
        require_once $mailFile;
        break;
    }
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    json_out(['success' => false, 'message' => 'Database connection not found.']);
}
$conn->set_charset('utf8mb4');


function json_out(array $payload): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function csv_out(string $filename, array $rows): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
  
    fputs($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function pdf_out(string $filename, string $html): void
{
    while (ob_get_level() > 0) ob_end_clean();

   
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!is_file($autoload)) {
      
        $autoload = __DIR__ . '/../vendor/autoload.php';
    }
    if (!is_file($autoload)) {
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


function clean_value(mixed $value): string { return trim((string)($value ?? '')); }

function table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$table] = ((int)($row['c'] ?? 0) > 0);
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = "$table.$column";
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function valid_id_or_null(mysqli $conn, string $table, int $id): ?int
{
    if ($id <= 0 || !table_exists($conn, $table)) return null;
    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $found ? $id : null;
}

function zone_table(mysqli $conn): ?string
{
    if (table_exists($conn, 'church_zones')) return 'church_zones';
    if (table_exists($conn, 'zones'))        return 'zones';
    return null;
}

function zone_name_column(mysqli $conn, string $table): string
{
    foreach (['zone_name','name','title'] as $col)
        if (column_exists($conn, $table, $col)) return $col;
    return 'id';
}

/**
 * The next member number, MEM-0001, MEM-0002 ... with no year: one after the highest number in use.
 * The highest is read from the last part of every MEM-... number, so older MEM-<year>-#### numbers count too.
 */
function generate_member_no(mysqli $conn): string
{
    $prefix = 'MEM-';
    $res  = $conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(member_no, '-', -1) AS UNSIGNED)) AS last_no FROM members WHERE member_no LIKE 'MEM-%'");
    $last = $res ? (int)($res->fetch_assoc()['last_no'] ?? 0) : 0;
    $next = $last + 1;
    do {
        $no = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        $next++;
    } while (member_no_exists($conn, $no));
    return $no;
}

function member_no_exists(mysqli $conn, string $memberNo, int $excludeId = 0): bool
{
    if ($memberNo === '') return false;
    if ($excludeId > 0) {
        $stmt = $conn->prepare("SELECT id FROM members WHERE member_no=? AND id<>? LIMIT 1");
        $stmt->bind_param('si', $memberNo, $excludeId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM members WHERE member_no=? LIMIT 1");
        $stmt->bind_param('s', $memberNo);
    }
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $found;
}

function save_member_extras(mysqli $conn, int $memberId, array $data): void
{
    if (!member_fields_ensure($conn)) return;
    $pledgeNo = clean_value($data['pledge_no'] ?? '');
    $service  = clean_value($data['service_attended'] ?? '');
    if (member_fields_pledge_taken($conn, $pledgeNo, $memberId))
        throw new Exception("Pledge number {$pledgeNo} is already assigned to another member.");
    $pledgeParam  = $pledgeNo !== '' ? $pledgeNo : null;
    $serviceParam = $service  !== '' ? $service  : null;
    $stmt = $conn->prepare("UPDATE members SET pledge_no=?, service_attended=? WHERE id=?");
    $stmt->bind_param('ssi', $pledgeParam, $serviceParam, $memberId);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // members.pledge_no is unique; another save may have taken the number since the check above.
        if ($e->getCode() === 1062) throw new Exception("Pledge number {$pledgeNo} is already assigned to another member.");
        throw $e;
    }
    $stmt->close();

    // A member has one pledge number, so keep their pledges on the current one.
    if ($pledgeParam !== null && table_exists($conn, 'pledges')) {
        $stmt = $conn->prepare("UPDATE pledges SET pledge_no=? WHERE member_id=?");
        $stmt->bind_param('si', $pledgeParam, $memberId);
        $stmt->execute();
        $stmt->close();
    }
}

function department_name(mysqli $conn, ?int $deptId): string
{
    if (!$deptId || !table_exists($conn, 'departments')) return '';
    $stmt = $conn->prepare("SELECT name FROM departments WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $deptId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (string)($row['name'] ?? '');
}

function get_member(mysqli $conn, int $id): ?array
{
    $zoneJoin   = '';
    $zoneSelect = "'' AS zone_name";
    $zt         = zone_table($conn);
    if ($zt && column_exists($conn, 'members', 'church_zone_id')) {
        $zn         = zone_name_column($conn, $zt);
        $zoneSelect = "COALESCE(z.`$zn`,'') AS zone_name";
        $zoneJoin   = " LEFT JOIN `$zt` z ON z.id=m.church_zone_id ";
    }
    $sql = "
        SELECT m.*, d.name AS dept_name, dm.role AS dept_role,
               DATE_FORMAT(m.date_of_birth,'%d %b %Y') AS date_of_birth_formatted,
               DATE_FORMAT(m.joined_date,  '%d %b %Y') AS joined_date_formatted,
               DATE_FORMAT(m.baptism_date, '%d %b %Y') AS baptism_date_formatted,
               $zoneSelect
        FROM members m
        LEFT JOIN departments d ON d.id=m.dept_id
        LEFT JOIN department_members dm ON dm.member_id=m.id AND dm.dept_id=m.dept_id
        $zoneJoin
        WHERE m.id=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function sync_department_member(mysqli $conn, int $memberId, ?int $deptId, string $role, string $joinedDate): void
{
    if (!table_exists($conn, 'department_members')) return;
    $stmt = $conn->prepare("DELETE FROM department_members WHERE member_id=?");
    $stmt->bind_param('i', $memberId); $stmt->execute(); $stmt->close();
    if (!$deptId) return;
    $role = $role !== '' ? $role : 'Member';
    $stmt = $conn->prepare("INSERT INTO department_members (dept_id,member_id,role,joined_date) VALUES (?,?,?,?)");
    $stmt->bind_param('iiss', $deptId, $memberId, $role, $joinedDate);
    $stmt->execute(); $stmt->close();
}

function send_registration_email(mysqli $conn, string $email, string $fullName, string $memberNo, string $joinedDate): bool
{
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (!function_exists('sendMemberRegistrationConfirmation'))         return false;
    return sendMemberRegistrationConfirmation(
        $conn, $email, $fullName,
        $memberNo, $joinedDate, APP_NAME
    );
}


/** The member number (e.g. MEM-0043) is the login username; the name is only a fallback when there is no number. */
function unique_member_username(mysqli $conn,string $fullName,string $memberNo):string{$base=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','',$memberNo),'.-_');if($base==='')$base=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','.',$fullName),'.'));if($base==='')$base='member';$candidate=$base;$i=1;while(true){$st=$conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$st->bind_param('s',$candidate);$st->execute();$exists=(bool)$st->get_result()->fetch_row();$st->close();if(!$exists)return $candidate;$candidate=$base.'-'.(++$i);}}
function create_member_login(mysqli $conn,string $fullName,string $memberNo,string $email,string $phone,string $status,array $data=[]):array{$username=clean_value($data['login_username']??'');if($username==='')$username=unique_member_username($conn,$fullName,$memberNo);else{$st=$conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$st->bind_param('s',$username);$st->execute();$exists=(bool)$st->get_result()->fetch_row();$st->close();if($exists)throw new Exception('The selected login username is already in use.');}require_once __DIR__.'/../includes/account-setup.php';$plain=clean_value($data['login_password']??'');if($plain==='')$plain=account_temp_password();$hash=password_hash($plain,PASSWORD_DEFAULT);$full=$fullName;$role='member';$userStatus=$status==='Active'?'Active':'Inactive';$st=$conn->prepare('INSERT INTO users (username,password,full_name,role,email,phone,status) VALUES (?,?,?,?,?,?,?)');if(!$st)throw new Exception('Unable to prepare member login account: '.$conn->error);$st->bind_param('sssssss',$username,$hash,$full,$role,$email,$phone,$userStatus);if(!$st->execute())throw new Exception('Unable to create member login account: '.$st->error);$uid=(int)$conn->insert_id;$st->close();account_set_must_change($conn,$uid,true);return ['user_id'=>$uid,'username'=>$username,'temporary_password'=>$plain];}

/**
 * Insert a single member record.
 */
function insert_member(mysqli $conn, array $data): array
{
    $fullName     = member_full_name($data);
    $gender       = clean_value($data['gender']        ?? '');
    $dob          = clean_value($data['date_of_birth'] ?? '');
    $phone        = clean_value($data['phone']         ?? '');
    $email        = clean_value($data['email']         ?? '');
    $address      = clean_value($data['address']       ?? '');
    $marital      = clean_value($data['marital_status']?? '');
    $occupation   = clean_value($data['occupation']    ?? '');
    $deptRole     = clean_value($data['dept_role']     ?? 'Member');
    $joinedDate   = clean_value($data['joined_date']   ?? date('Y-m-d'))  ?: date('Y-m-d');
    $baptismDate  = clean_value($data['baptism_date']  ?? '');
    $status       = clean_value($data['status']        ?? 'Active')       ?: 'Active';
    $notes        = clean_value($data['notes']         ?? '');
    $memberNo     = clean_value($data['member_no']     ?? '');

    $deptId    = valid_id_or_null($conn, 'departments', (int)($data['dept_id']        ?? 0));
    $department= department_name($conn, $deptId);
    $churchZoneId = null;
    $zt        = zone_table($conn);
    if ($zt && column_exists($conn, 'members', 'church_zone_id')) {
        $churchZoneId = valid_id_or_null($conn, $zt, (int)($data['church_zone_id'] ?? 0));
    }

    if ($memberNo === '') $memberNo = generate_member_no($conn);

    $pledgeNo = clean_value($data['pledge_no'] ?? '');
    if (member_fields_ensure($conn) && member_fields_pledge_taken($conn, $pledgeNo))
        throw new Exception("Pledge number {$pledgeNo} is already assigned to another member.");

    $dobParam     = $dob        !== '' ? $dob        : null;
    $baptismParam = $baptismDate !== '' ? $baptismDate : null;

    if (!column_exists($conn, 'members', 'user_id')) {
        throw new Exception('The members.user_id column is missing. Run database_member_user_relationship.sql first.');
    }
    $login = create_member_login($conn,$fullName,$memberNo,$email,$phone,$status,$data);
    $userId = (int)$login['user_id'];

    if (column_exists($conn, 'members', 'church_zone_id')) {
        $stmt = $conn->prepare("
            INSERT INTO members
                (user_id,member_no,full_name,gender,date_of_birth,phone,email,address,
                 marital_status,occupation,department,dept_id,church_zone_id,joined_date,
                 baptism_date,status,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('issssssssssiissss',
            $userId,$memberNo,$fullName,$gender,$dobParam,$phone,$email,$address,
            $marital,$occupation,$department,$deptId,$churchZoneId,$joinedDate,$baptismParam,$status,$notes
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO members
                (user_id,member_no,full_name,gender,date_of_birth,phone,email,address,
                 marital_status,occupation,department,dept_id,joined_date,baptism_date,status,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('issssssssssissss',
            $userId,$memberNo,$fullName,$gender,$dobParam,$phone,$email,$address,
            $marital,$occupation,$department,$deptId,$joinedDate,$baptismParam,$status,$notes
        );
    }

    if (!$stmt->execute()) throw new Exception($stmt->error);
    $newId = (int)$conn->insert_id;
    $stmt->close();

    save_member_extras($conn, $newId, $data);
    sync_department_member($conn, $newId, $deptId, $deptRole, $joinedDate);
    // The welcome email carries the member number, username and temporary password.
    $welcome = account_send_welcome_email($conn, $email, $fullName, $login['username'], $login['temporary_password'], 'A member account (member no. ' . $memberNo . ')');
    $emailSent = $welcome['sent'];

    return ['id'=>$newId,'member_no'=>$memberNo,'email_sent'=>$emailSent,'username'=>$login['username'],'temporary_password'=>$login['temporary_password']];
}

/* ===========================================================
   EXPORT / IMPORT HELPERS
=========================================================== */

/**
 * Fetch all members matching optional search/status filters (no pagination).
 */
function fetch_all_members(mysqli $conn, string $search = '', string $status = '', string $dateFrom = '', string $dateTo = '', int $zoneId = 0, string $service = ''): array
{
    $hasExtras = member_fields_ensure($conn);
    $extraSelect = $hasExtras ? 'm.pledge_no, m.service_attended,' : "'' AS pledge_no, '' AS service_attended,";
    $zt = zone_table($conn);
    $zoneSelect = "'' AS zone_name";
    $zoneJoin   = '';
    if ($zt && column_exists($conn, 'members', 'church_zone_id')) {
        $zn         = zone_name_column($conn, $zt);
        $zoneSelect = "COALESCE(z.`$zn`,'') AS zone_name";
        $zoneJoin   = " LEFT JOIN `$zt` z ON z.id=m.church_zone_id ";
    }

    $where  = 'WHERE 1=1';
    $params = [];
    $types  = '';

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= " AND (m.full_name LIKE ?
                     OR m.member_no LIKE ? OR m.phone LIKE ? OR m.email LIKE ?
                     OR m.occupation LIKE ? OR m.department LIKE ? OR d.name LIKE ?";
        for ($i = 0; $i < 7; $i++) { $params[] = $like; $types .= 's'; }
        if ($hasExtras) {
            $where .= ' OR m.pledge_no LIKE ? OR m.service_attended LIKE ?';
            $params[] = $like; $params[] = $like; $types .= 'ss';
        }
        if ($zt && column_exists($conn, 'members', 'church_zone_id')) {
            $zn2 = zone_name_column($conn, $zt);
            $where .= " OR z.`$zn2` LIKE ?";
            $params[] = $like; $types .= 's';
        }
        $where .= ')';
    }
    if ($status !== '' && in_array($status, ['Active','Inactive'], true)) {
        $where .= ' AND m.status=?';
        $params[] = $status; $types .= 's';
    }
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where .= ' AND DATE(m.joined_date)>=?';
        $params[] = $dateFrom; $types .= 's';
    }
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where .= ' AND DATE(m.joined_date)<=?';
        $params[] = $dateTo; $types .= 's';
    }
    if ($zoneId > 0 && $zt && column_exists($conn, 'members', 'church_zone_id')) {
        $where .= ' AND m.church_zone_id=?';
        $params[] = $zoneId; $types .= 'i';
    }
    if ($service !== '' && $hasExtras) {
        $where .= ' AND m.service_attended=?';
        $params[] = $service; $types .= 's';
    }

    $sql = "
        SELECT m.id, m.member_no, $extraSelect m.full_name, m.gender,
               m.date_of_birth, m.phone, m.email, m.address,
               m.marital_status, m.occupation,
               COALESCE(d.name, m.department, '') AS dept_name,
               m.joined_date, m.baptism_date, m.status, m.notes,
               $zoneSelect
        FROM members m
        LEFT JOIN departments d ON d.id=m.dept_id
        $zoneJoin
        $where
        ORDER BY m.full_name ASC";

    $stmt = $conn->prepare($sql);
    if ($types !== '' && $params) {
        $refs = [$types];
        foreach ($params as $k => $v) $refs[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

/** Build the array of rows for CSV (header + data). */
function build_csv_rows(array $members): array
{
    $rows   = [];
    $rows[] = [
        'Member No', 'Pledge No', 'Full Name', 'Gender',
        'Date of Birth', 'Phone', 'Email', 'Address',
        'Marital Status', 'Occupation', 'Department', 'Zone', 'Service Attended',
        'Joined Date', 'Baptism Date', 'Status', 'Notes',
    ];
    foreach ($members as $m) {
        $rows[] = [
            $m['member_no']    ?? '',
            $m['pledge_no']    ?? '',
            $m['full_name']    ?? '',
            $m['gender']       ?? '',
            $m['date_of_birth']?? '',
            $m['phone']        ?? '',
            $m['email']        ?? '',
            $m['address']      ?? '',
            $m['marital_status']?? '',
            $m['occupation']   ?? '',
            $m['dept_name']    ?? '',
            $m['zone_name']    ?? '',
            $m['service_attended'] ?? '',
            $m['joined_date']  ?? '',
            $m['baptism_date'] ?? '',
            $m['status']       ?? '',
            $m['notes']        ?? '',
        ];
    }
    return $rows;
}

/** Build an HTML string suitable for DOMpdf rendering. */
function build_pdf_html(array $members, string $search, string $status): string
{
    $genDate  = date('d M Y H:i');
    $totalCnt = count($members);
    $filterNote = '';
    if ($search !== '') $filterNote .= ' | Search: ' . htmlspecialchars($search, ENT_QUOTES);
    if ($status !== '') $filterNote .= ' | Status: ' . htmlspecialchars($status, ENT_QUOTES);

    $rows = '';
    $i    = 1;
    foreach ($members as $m) {
        $bg   = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $sClr = ($m['status'] ?? '') === 'Active' ? '#15803d' : '#6b7280';
        $sTag = '<span style="color:' . $sClr . ';font-weight:600">' . htmlspecialchars((string)($m['status'] ?? '-'), ENT_QUOTES) . '</span>';
        $full = htmlspecialchars((string)($m['full_name'] ?? ''), ENT_QUOTES);
        $rows .= '<tr style="background:' . $bg . '">
            <td style="text-align:center">' . $i . '</td>
            <td>' . $full . '</td>
            <td>' . htmlspecialchars((string)($m['member_no']??'-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)(($m['pledge_no']??'') ?: '-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)($m['gender']??'-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)($m['dept_name']??'-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)($m['zone_name']??'-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)(($m['service_attended']??'') ?: '-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)($m['phone']??'-'), ENT_QUOTES) . '</td>
            <td>' . htmlspecialchars((string)($m['occupation']??'-'), ENT_QUOTES) . '</td>
            <td style="text-align:center">' . $sTag . '</td>
            <td>' . htmlspecialchars(!empty($m['joined_date']) ? date('d M Y', strtotime($m['joined_date'])) : '-', ENT_QUOTES) . '</td>
        </tr>';
        $i++;
    }

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body  { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a2e; margin:0; padding:0; }
  .hdr  { background:#4a1e8f; color:#fff; padding:14px 20px 10px; margin-bottom:10px; }
  .hdr h1 { margin:0 0 2px; font-size:16px; }
  .hdr p  { margin:0; font-size:9px; opacity:.85; }
  .meta { padding: 4px 20px 8px; font-size:8.5px; color:#555; }
  table { width:100%; border-collapse:collapse; font-size:8.5px; }
  thead th { background:#4a1e8f; color:#fff; padding:5px 6px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.4px; }
  tbody td { padding:4px 6px; border-bottom:1px solid #e5e0fa; vertical-align:top; }
  .footer { text-align:center; font-size:7.5px; color:#9ca3af; margin-top:14px; padding:0 20px; }
</style>
</head>
<body>
<div class="hdr">
  <h1>&#9995; Church Members Report</h1>
  <p>Generated: ' . $genDate . ' &nbsp;&bull;&nbsp; Total records: ' . $totalCnt . $filterNote . '</p>
</div>
<table>
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>Full Name</th>
      <th>Member No</th>
      <th>Pledge No</th>
      <th>Gender</th>
      <th>Department</th>
      <th>Zone</th>
      <th>Service</th>
      <th>Phone</th>
      <th>Occupation</th>
      <th>Status</th>
      <th>Joined</th>
    </tr>
  </thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Members Register &mdash; ' . $genDate . '</div>
</body>
</html>';
}

/** Parse an uploaded CSV file into an array of assoc rows keyed by header names. */
function parse_csv_upload(string $filePath, bool $skipHeader = true): array
{
    $rows = [];
    if (!is_readable($filePath)) throw new Exception('Cannot read uploaded file.');
    if (($fh = fopen($filePath, 'r')) === false) throw new Exception('Failed to open CSV file.');

    // Strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $headers = null;
    while (($line = fgetcsv($fh, 4096, ',')) !== false) {
        if ($line === [null]) continue; // blank line
        if ($headers === null && $skipHeader) {
            // Normalise header names: lowercase, trim, spaces?underscores
            $headers = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', '_', $h))), $line);
            continue;
        }
        if ($headers === null) {
            // No-header mode: numeric keys
            $rows[] = $line;
        } else {
            $assoc = [];
            foreach ($headers as $i => $key) {
                $assoc[$key] = isset($line[$i]) ? trim($line[$i]) : '';
            }
            $rows[] = $assoc;
        }
    }
    fclose($fh);
    return $rows;
}

/** Map CSV column aliases to canonical field names. */
function normalise_csv_row(array $row): array
{
    $aliases = [
        'fullname'       => 'full_name',
        'full_name'      => 'full_name',
        'name'           => 'full_name',
        'firstname'      => 'first_name',
        'first_name'     => 'first_name',
        'surname'        => 'last_name',
        'lastname'       => 'last_name',
        'last_name'      => 'last_name',
        'sex'            => 'gender',
        'gender'         => 'gender',
        'dob'            => 'date_of_birth',
        'date_of_birth'  => 'date_of_birth',
        'birth_date'     => 'date_of_birth',
        'mobile'         => 'phone',
        'phone'          => 'phone',
        'telephone'      => 'phone',
        'email'          => 'email',
        'email_address'  => 'email',
        'job'            => 'occupation',
        'occupation'     => 'occupation',
        'marital_status' => 'marital_status',
        'marital'        => 'marital_status',
        'joined'         => 'joined_date',
        'joined_date'    => 'joined_date',
        'date_joined'    => 'joined_date',
        'baptism'        => 'baptism_date',
        'baptism_date'   => 'baptism_date',
        'address'        => 'address',
        'notes'          => 'notes',
        'remarks'        => 'notes',
        'status'         => 'status',
        'pledge_no'      => 'pledge_no',
        'pledge_number'  => 'pledge_no',
        'pledge'         => 'pledge_no',
        'service'        => 'service_attended',
        'service_attended' => 'service_attended',
    ];
    $out = [];
    foreach ($row as $key => $val) {
        $norm = strtolower(trim(preg_replace('/\s+/', '_', $key)));
        $canonical = $aliases[$norm] ?? $norm;
        $out[$canonical] = $val;
    }
    return $out;
}



/* ===========================================================
   PUBLIC MEMBER SELF-REGISTRATION LINKS
=========================================================== */
function ensure_member_registration_links_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS member_registration_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        label VARCHAR(120) NULL,
        expires_at DATETIME NULL,
        max_uses INT UNSIGNED NULL,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_member_registration_token_hash (token_hash),
        KEY idx_member_registration_active (is_active, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to prepare registration links: ' . $conn->error);
    }
}

function member_public_registration_url(string $token): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));

    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/members/process-member.php')));
    $scriptDir = rtrim($scriptDir, '/');
    return $scheme . '://' . $host . $scriptDir . '/register.php?token=' . rawurlencode($token);
}

function current_user_id_for_registration_link(): ?int
{
    foreach (['user_id','id','uid'] as $key) {
        if (isset($_SESSION[$key]) && (int)$_SESSION[$key] > 0) return (int)$_SESSION[$key];
    }
    return null;
}


/* ===========================================================
   MAIN ROUTER
=========================================================== */
try {
    if (!table_exists($conn, 'members'))
        json_out(['success' => false, 'message' => 'members table does not exist.']);
    // Run schema changes before any transaction: ALTER TABLE would implicitly commit it.
    member_fields_ensure($conn);

    $action = clean_value($_REQUEST['action'] ?? 'list');


    /* --------------------------------------------------------
       GENERATE PUBLIC SELF-REGISTRATION LINK + QR TARGET
    -------------------------------------------------------- */
    if ($action === 'generate_registration_link') {
        if (current_user_id_for_registration_link() === null) {
            json_out(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
        }
        ensure_member_registration_links_table($conn);

        $validDays = (int)($_POST['valid_days'] ?? 30);
        if (!in_array($validDays, [0, 7, 30, 90, 365], true)) $validDays = 30;

        $maxUses = (int)($_POST['max_uses'] ?? 0);
        if ($maxUses < 0) $maxUses = 0;
        if ($maxUses > 100000) $maxUses = 100000;

        $label = clean_value($_POST['label'] ?? 'Member self-registration');
        if ($label === '') $label = 'Member self-registration';
        if (function_exists('mb_substr')) $label = mb_substr($label, 0, 120, 'UTF-8');
        else $label = substr($label, 0, 120);

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = $validDays > 0 ? date('Y-m-d H:i:s', time() + ($validDays * 86400)) : null;
        $maxUsesDb = $maxUses > 0 ? $maxUses : null;
        $createdBy = current_user_id_for_registration_link();

        // Keep one simple, shareable current invitation. Generating a new one revokes previous links.
        $conn->begin_transaction();
        try {
            $conn->query("UPDATE member_registration_links SET is_active=0 WHERE is_active=1");
            $stmt = $conn->prepare("INSERT INTO member_registration_links
                (token_hash,label,expires_at,max_uses,used_count,is_active,created_by)
                VALUES (?,?,?,?,0,1,?)");
            if (!$stmt) throw new RuntimeException('Unable to prepare registration link: ' . $conn->error);
            $stmt->bind_param('sssii', $tokenHash, $label, $expiresAt, $maxUsesDb, $createdBy);
            if (!$stmt->execute()) throw new RuntimeException('Unable to save registration link: ' . $stmt->error);
            $linkId = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        json_out([
            'success'    => true,
            'message'    => 'Public member registration link generated successfully.',
            'id'         => $linkId,
            'url'        => member_public_registration_url($token),
            'expires_at' => $expiresAt,
            'max_uses'   => $maxUses,
        ]);
    }

    /* --------------------------------------------------------
       CSV TEMPLATE DOWNLOAD
    -------------------------------------------------------- */
    if ($action === 'csv_template') {
        $template = [
            ['full_name','gender','phone','email','occupation',
             'marital_status','date_of_birth','joined_date','baptism_date',
             'address','notes','status','pledge_no','service_attended'],
            ['John Doe','Male','+256700000001','john@example.com','Teacher',
             'Married','1985-06-15','2020-01-10','2019-12-25',
             '123 Main St, Kampala','Choir leader','Active','P-001','First Service'],
            ['Jane Smith','Female','+256700000002','jane@example.com','Nurse',
             'Single','1992-03-22','2021-05-01','',
             '45 Church Road','','Active','','Second Service'],
        ];
        csv_out('members_import_template.csv', $template);
    }

    /* --------------------------------------------------------
       EXPORT CSV
    -------------------------------------------------------- */
    if ($action === 'export_csv') {
        $search   = clean_value($_GET['search'] ?? '');
        $status   = clean_value($_GET['status'] ?? '');
        $dateFrom = clean_value($_GET['date_from'] ?? '');
        $dateTo   = clean_value($_GET['date_to'] ?? '');
        $zoneId   = max(0, (int)($_GET['zone_id'] ?? 0));
        $service  = clean_value($_GET['service'] ?? '');
        if (!in_array($status, ['Active','Inactive',''], true)) $status = '';

        $members  = fetch_all_members($conn, $search, $status, $dateFrom, $dateTo, $zoneId, $service);
        $csvRows  = build_csv_rows($members);
        $filename = 'members_export_' . date('Ymd_His') . '.csv';
        csv_out($filename, $csvRows);
    }

    /* --------------------------------------------------------
       EXPORT PDF  (requires DOMpdf via Composer)
    -------------------------------------------------------- */
    if ($action === 'export_pdf') {
        $search   = clean_value($_GET['search'] ?? '');
        $status   = clean_value($_GET['status'] ?? '');
        $dateFrom = clean_value($_GET['date_from'] ?? '');
        $dateTo   = clean_value($_GET['date_to'] ?? '');
        $zoneId   = max(0, (int)($_GET['zone_id'] ?? 0));
        $service  = clean_value($_GET['service'] ?? '');
        if (!in_array($status, ['Active','Inactive',''], true)) $status = '';

        $members  = fetch_all_members($conn, $search, $status, $dateFrom, $dateTo, $zoneId, $service);
        $html     = build_pdf_html($members, $search, $status);
        $filename = 'members_report_' . date('Ymd_His') . '.pdf';
        pdf_out($filename, $html);
    }

    /* --------------------------------------------------------
       IMPORT CSV
    -------------------------------------------------------- */
    if ($action === 'import_csv') {
        if (empty($_FILES['csv_file']['tmp_name']))
            json_out(['success' => false, 'message' => 'No file uploaded.']);

        $fileErr  = (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fileErr !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'File too large (server limit).',
                UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit).',
                UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            ];
            json_out(['success' => false, 'message' => $errMap[$fileErr] ?? 'Upload error code '.$fileErr]);
        }

        $tmpPath = (string)$_FILES['csv_file']['tmp_name'];
        $origName = strtolower((string)($_FILES['csv_file']['name'] ?? ''));
        if (!str_ends_with($origName, '.csv'))
            json_out(['success' => false, 'message' => 'Only .csv files are accepted.']);

        $skipHeader = !empty($_POST['skip_header']);
        $csvRows    = parse_csv_upload($tmpPath, $skipHeader);

        if (empty($csvRows))
            json_out(['success' => false, 'message' => 'The CSV file appears to be empty.']);

        /* Pre-validate every row */
        $errors = [];
        foreach ($csvRows as $idx => $raw) {
            $row = normalise_csv_row($raw);
            $lineNo = $idx + ($skipHeader ? 2 : 1); // human-readable line number
            $gn = trim($row['gender']     ?? '');
            if (member_full_name($row) === '') $errors[] = "Line {$lineNo}: full_name is required.";
            if ($gn === '') $errors[] = "Line {$lineNo}: gender is required.";
            $em = trim($row['email'] ?? '');
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL))
                $errors[] = "Line {$lineNo}: invalid email '{$em}'.";
            if (count($errors) >= 10) { $errors[] = '…(further errors suppressed)'; break; }
        }
        if ($errors)
            json_out(['success' => false, 'message' => implode(' | ', $errors)]);

        /* Insert all inside a transaction */
        $conn->begin_transaction();
        $inserted   = 0;
        $emailsSent = 0;
        $memberNos  = [];
        $rowErrors  = [];

        foreach ($csvRows as $idx => $raw) {
            $row    = normalise_csv_row($raw);
            $lineNo = $idx + ($skipHeader ? 2 : 1);
            try {
                $result     = insert_member($conn, $row);
                $memberNos[]= $result['member_no'];
                if ($result['email_sent']) $emailsSent++;
                $inserted++;
            } catch (Throwable $e) {
                error_log("[import_csv] line {$lineNo}: " . $e->getMessage());
                $rowErrors[] = "Line {$lineNo}: " . $e->getMessage();
            }
        }

        if ($inserted === 0) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'No members could be imported. ' . implode(' | ', $rowErrors)]);
        }

        $conn->commit();

        $total = count($csvRows);
        $msg = $inserted === $total
            ? "{$inserted} member(s) imported successfully."
            : "{$inserted} of {$total} members imported.";
        if ($rowErrors) $msg .= ' Issues: ' . implode(' | ', array_slice($rowErrors, 0, 5));
        $msg .= " Sign-in details (username and temporary password) emailed to {$emailsSent} member(s).";
        if ($inserted > $emailsSent)
            $msg .= ' ' . ($inserted - $emailsSent) . ' had no valid email address or the email failed: reset their password on the Users page and give it to them.';

        json_out([
            'success'     => true,
            'message'     => $msg,
            'inserted'    => $inserted,
            'member_nos'  => $memberNos,
            'emails_sent' => $emailsSent,
        ]);
    }

    /* --------------------------------------------------------
       GET / VIEW
    -------------------------------------------------------- */
    if ($action === 'get' || $action === 'view') {
        $id = (int)($_REQUEST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid member ID.']);
        $member = get_member($conn, $id);
        if (!$member) json_out(['success' => false, 'message' => 'Member not found.']);
        json_out(['success' => true, 'member' => $member, 'item' => $member]);
    }

    /* --------------------------------------------------------
       CREATE (single)
    -------------------------------------------------------- */
    if ($action === 'create') {
        $email     = clean_value($_POST['email']      ?? '');

        if (member_full_name($_POST) === '') json_out(['success' => false, 'message' => 'Full name is required.']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            json_out(['success' => false, 'message' => 'Invalid email address.']);

        $conn->begin_transaction();
        try {
            $result = insert_member($conn, $_POST);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        json_out([
            'success'    => true,
            'message'    => $result['email_sent']
                ? 'Member registered. Their username and temporary password were emailed; they must change the password at first sign-in.'
                : 'Member registered, but no welcome email was sent (no valid email address, or sending failed). Give them these details yourself - username: '
                    . ($result['username'] ?? '') . ', temporary password: ' . ($result['temporary_password'] ?? '') . '. They must change it at first sign-in.',
            'id'         => $result['id'],
            'member_no'  => $result['member_no'],
            'email_sent' => $result['email_sent'],
            'username' => $result['username'] ?? '',
            'temporary_password' => $result['temporary_password'] ?? '',
        ]);
    }

    /* --------------------------------------------------------
       BULK CREATE
    -------------------------------------------------------- */
    if ($action === 'bulk_create') {
        $members = $_POST['members'] ?? [];
        if (!is_array($members) || empty($members))
            json_out(['success' => false, 'message' => 'No member data received.']);

        $errors = [];
        foreach ($members as $i => $m) {
            $row  = (int)$i + 1;
            $gn   = clean_value($m['gender']     ?? '');
            $em   = clean_value($m['email']      ?? '');
            if (member_full_name($m) === '') $errors[] = "Row {$row}: Full name is required.";
            if ($gn === '') $errors[] = "Row {$row}: Gender is required.";
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL))
                $errors[] = "Row {$row}: Invalid email address.";
        }
        if ($errors) json_out(['success' => false, 'message' => implode(' ', $errors)]);

        $conn->begin_transaction();
        $inserted   = 0;
        $emailsSent = 0;
        $memberNos  = [];
        $rowErrors  = [];

        foreach ($members as $i => $m) {
            try {
                $result     = insert_member($conn, $m);
                $memberNos[]= $result['member_no'];
                if ($result['email_sent']) $emailsSent++;
                $inserted++;
            } catch (Throwable $e) {
                $row = (int)$i + 1;
                error_log("[bulk_create] row {$row}: " . $e->getMessage());
                $rowErrors[] = "Row {$row} (" . member_full_name($m) . '): ' . $e->getMessage();
            }
        }

        if ($inserted === 0) {
            $conn->rollback();
            json_out(['success' => false, 'message' => 'No members could be saved. ' . implode(' ', $rowErrors)]);
        }

        $conn->commit();

        $total = count($members);
        if ($inserted === $total) {
            $msg = $inserted === 1
                ? "Member {$memberNos[0]} registered successfully."
                : "{$inserted} members registered successfully.";
        } else {
            $msg = "{$inserted} of {$total} members registered.";
            if ($rowErrors) $msg .= ' Issues: ' . implode(' | ', $rowErrors);
        }
        $msg .= " Sign-in details (username and temporary password) emailed to {$emailsSent} member(s).";
        if ($inserted > $emailsSent)
            $msg .= ' ' . ($inserted - $emailsSent) . ' had no valid email address or the email failed: reset their password on the Users page and give it to them.';

        json_out([
            'success'     => true,
            'message'     => $msg,
            'inserted'    => $inserted,
            'member_nos'  => $memberNos,
            'emails_sent' => $emailsSent,
        ]);
    }

    /* --------------------------------------------------------
       UPDATE (single)
    -------------------------------------------------------- */
    if ($action === 'update') {
        $id        = (int)($_POST['id'] ?? 0);
        $fullName  = member_full_name($_POST);
        $email     = clean_value($_POST['email']      ?? '');

        if ($id <= 0)        json_out(['success' => false, 'message' => 'Invalid member ID.']);
        if ($fullName==='')  json_out(['success' => false, 'message' => 'Full name is required.']);
        if ($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))
            json_out(['success' => false, 'message' => 'Invalid email address.']);
        if (!get_member($conn, $id))
            json_out(['success' => false, 'message' => 'Member not found.']);

        $memberNo     = clean_value($_POST['member_no']     ?? '');
        $gender       = clean_value($_POST['gender']        ?? '');
        $dob          = clean_value($_POST['date_of_birth'] ?? '');
        $phone        = clean_value($_POST['phone']         ?? '');
        $address      = clean_value($_POST['address']       ?? '');
        $marital      = clean_value($_POST['marital_status']?? '');
        $occupation   = clean_value($_POST['occupation']    ?? '');
        $deptRole     = clean_value($_POST['dept_role']     ?? 'Member');
        $joinedDate   = clean_value($_POST['joined_date']   ?? date('Y-m-d')) ?: date('Y-m-d');
        $baptismDate  = clean_value($_POST['baptism_date']  ?? '');
        $status       = clean_value($_POST['status']        ?? 'Active') ?: 'Active';
        $notes        = clean_value($_POST['notes']         ?? '');

        $deptId    = valid_id_or_null($conn, 'departments', (int)($_POST['dept_id'] ?? 0));
        $department= department_name($conn, $deptId);
        $churchZoneId = null;
        $zt        = zone_table($conn);
        if ($zt && column_exists($conn, 'members', 'church_zone_id'))
            $churchZoneId = valid_id_or_null($conn, $zt, (int)($_POST['church_zone_id'] ?? 0));

        if ($memberNo === '') $memberNo = generate_member_no($conn);
        if (member_no_exists($conn, $memberNo, $id))
            json_out(['success' => false, 'message' => 'Member number already exists.']);
        $pledgeNo = clean_value($_POST['pledge_no'] ?? '');
        if (member_fields_ensure($conn) && member_fields_pledge_taken($conn, $pledgeNo, $id))
            json_out(['success' => false, 'message' => 'Pledge number already assigned to another member.']);

        $dobParam     = $dob         !== '' ? $dob         : null;
        $baptismParam = $baptismDate !== '' ? $baptismDate : null;

        if (column_exists($conn, 'members', 'church_zone_id')) {
            $stmt = $conn->prepare("
                UPDATE members SET
                    member_no=?,full_name=?,gender=?,date_of_birth=?,
                    phone=?,email=?,address=?,marital_status=?,occupation=?,
                    department=?,dept_id=?,church_zone_id=?,joined_date=?,baptism_date=?,
                    status=?,notes=?
                WHERE id=?");
            $stmt->bind_param('ssssssssssiissssi',
                $memberNo,$fullName,$gender,$dobParam,$phone,$email,$address,
                $marital,$occupation,$department,$deptId,$churchZoneId,$joinedDate,$baptismParam,$status,$notes,$id
            );
        } else {
            $stmt = $conn->prepare("
                UPDATE members SET
                    member_no=?,full_name=?,gender=?,date_of_birth=?,
                    phone=?,email=?,address=?,marital_status=?,occupation=?,
                    department=?,dept_id=?,joined_date=?,baptism_date=?,status=?,notes=?
                WHERE id=?");
            $stmt->bind_param('ssssssssssissssi',
                $memberNo,$fullName,$gender,$dobParam,$phone,$email,$address,
                $marital,$occupation,$department,$deptId,$joinedDate,$baptismParam,$status,$notes,$id
            );
        }
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $stmt->close();

        save_member_extras($conn, $id, $_POST);
        sync_department_member($conn, $id, $deptId, $deptRole, $joinedDate);
        $userStatus = $status === 'Active' ? 'Active' : 'Inactive';
        $ust = $conn->prepare('UPDATE users u INNER JOIN members m ON m.user_id=u.id SET u.full_name=?,u.email=?,u.phone=?,u.status=? WHERE m.id=?');
        if($ust){$ust->bind_param('ssssi',$fullName,$email,$phone,$userStatus,$id);$ust->execute();$ust->close();}
        json_out(['success' => true, 'message' => 'Member and linked login account updated successfully.']);
    }

    /* --------------------------------------------------------
       DELETE (soft)
    -------------------------------------------------------- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? $_REQUEST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid member ID.']);
        $member = get_member($conn, $id);
        if (!$member) json_out(['success' => false, 'message' => 'Member not found or already deleted.']);

        if (column_exists($conn, 'members', 'status')) {
            $s = 'Inactive';
            $stmt = $conn->prepare("UPDATE members SET status=? WHERE id=?");
            $stmt->bind_param('si', $s, $id); $stmt->execute(); $stmt->close();
            $ust=$conn->prepare("UPDATE users u INNER JOIN members m ON m.user_id=u.id SET u.status='Inactive' WHERE m.id=?");
            if($ust){$ust->bind_param('i',$id);$ust->execute();$ust->close();}
            json_out(['success' => true, 'message' => 'Member and linked login account deactivated successfully.']);
        }

        if (table_exists($conn, 'department_members')) {
            $stmt = $conn->prepare("DELETE FROM department_members WHERE member_id=?");
            $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM members WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        json_out(['success' => true, 'message' => 'Member deleted successfully.']);
    }

    json_out(['success' => false, 'message' => 'Invalid action.']);

} catch (Throwable $e) {
    json_out(['success' => false, 'message' => $e->getMessage()]);
}