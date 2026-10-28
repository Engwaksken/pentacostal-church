<?php
declare(strict_types=1);

ob_start();

require_once '../config/config.php';
if (function_exists('requireLogin')) {
    requireLogin();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($conn) || !($conn instanceof mysqli)) {
    ss_json(false, 'Database connection not found.');
}
$conn->set_charset('utf8mb4');

/* ===========================================================
   OUTPUT HELPERS
=========================================================== */

function ss_json(bool $success, string $message = '', array $extra = []): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function ss_csv_out(string $filename, array $rows): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens correctly
    fputs($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => (string)($v ?? ''), $row));
    }
    fclose($out);
    exit;
}

function ss_pdf_out(string $filename, string $html): void
{
    while (ob_get_level() > 0) ob_end_clean();

    // Locate Composer autoload. Adjust path if your project layout differs.
    $candidates = [
        __DIR__ . '/../../vendor/autoload.php',   // vendor two levels up
        __DIR__ . '/../vendor/autoload.php',       // vendor one level up
        __DIR__ . '/vendor/autoload.php',          // vendor alongside this file
    ];
    $autoload = null;
    foreach ($candidates as $path) {
        if (is_file($path)) { $autoload = $path; break; }
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
   SCALAR HELPERS
=========================================================== */

function ss_clean($value): string  { return trim((string)($value ?? '')); }
function ss_int($value): int       { return (int)$value; }
function ss_nullable_int($value): ?int  { $v = ss_int($value); return $v > 0 ? $v : null; }
function ss_nullable_date($value): ?string { $v = ss_clean($value); return $v !== '' ? $v : null; }

function ss_scalar(mysqli $conn, string $sql): int
{
    try { $res = $conn->query($sql); $row = $res ? $res->fetch_row() : [0]; return (int)($row[0] ?? 0); }
    catch (Throwable $e) { return 0; }
}

function ss_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ss_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

/* ===========================================================
   PUPIL HELPERS
=========================================================== */

function ss_generate_pupil_no(mysqli $conn): string
{
    $prefix = 'SSP-' . date('Y') . '-';
    $stmt = $conn->prepare("SELECT pupil_no FROM sunday_school_pupils WHERE pupil_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if (!empty($row['pupil_no']) && preg_match('/(\d+)$/', (string)$row['pupil_no'], $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function ss_prepare_pupil_data(mysqli $conn, array $src, bool $requireName = true): array
{
    $pupilNo = ss_clean($src['pupil_no'] ?? '');
    if ($pupilNo === '') $pupilNo = ss_generate_pupil_no($conn);

    $fullName = ss_clean($src['full_name'] ?? '');
    if ($requireName && $fullName === '') throw new Exception('Full name is required.');

    $status = ss_clean($src['status'] ?? 'Active');
    if ($status === '') $status = 'Active';

    return [
        'pupil_no'        => $pupilNo,
        'full_name'       => $fullName,
        'gender'          => ss_clean($src['gender'] ?? ''),
        'date_of_birth'   => ss_nullable_date($src['date_of_birth'] ?? ''),
        'class_id'        => ss_nullable_int($src['class_id'] ?? 0),
        'parent_guardian' => ss_clean($src['parent_guardian'] ?? ''),
        'parent_phone'    => ss_clean($src['parent_phone'] ?? ''),
        'parent_member_id'=> ss_nullable_int($src['parent_member_id'] ?? 0),
        'baptised'        => ss_int($src['baptised'] ?? 0) === 1 ? 1 : 0,
        'status'          => $status,
        'date_enrolled'   => ss_nullable_date($src['date_enrolled'] ?? '') ?: date('Y-m-d'),
        'notes'           => ss_clean($src['notes'] ?? ''),
    ];
}

function ss_insert_pupil(mysqli $conn, array $d): int
{
    $stmt = $conn->prepare("
        INSERT INTO sunday_school_pupils
            (pupil_no, full_name, gender, date_of_birth, class_id,
             parent_guardian, parent_phone, parent_member_id,
             baptised, status, date_enrolled, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'ssssisssisss',
        $d['pupil_no'], $d['full_name'], $d['gender'], $d['date_of_birth'],
        $d['class_id'], $d['parent_guardian'], $d['parent_phone'],
        $d['parent_member_id'], $d['baptised'], $d['status'],
        $d['date_enrolled'], $d['notes']
    );
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/* ===========================================================
   EXPORT HELPERS
=========================================================== */

/**
 * Fetch all pupils, optionally filtered by class and/or status.
 */
function ss_fetch_pupils_for_export(mysqli $conn, int $classId = 0, string $status = ''): array
{
    $where  = 'WHERE 1=1';
    $params = [];
    $types  = '';

    if ($classId > 0)  { $where .= ' AND p.class_id = ?'; $params[] = $classId; $types .= 'i'; }
    if ($status !== '' && in_array($status, ['Active','Inactive'], true)) {
        $where .= ' AND COALESCE(p.status,\'Active\') = ?';
        $params[] = $status; $types .= 's';
    }

    $sql = "
        SELECT p.pupil_no, p.full_name, p.gender,
               COALESCE(p.date_of_birth,'')   AS date_of_birth,
               COALESCE(c.class_name,'')       AS class_name,
               COALESCE(p.parent_guardian,'')  AS parent_guardian,
               COALESCE(p.parent_phone,'')     AS parent_phone,
               IF(COALESCE(p.baptised,0)=1,'Yes','No') AS baptised,
               COALESCE(p.status,'Active')     AS status,
               COALESCE(p.date_enrolled,'')    AS date_enrolled,
               COALESCE(p.notes,'')            AS notes,
               CASE WHEN p.date_of_birth IS NULL OR p.date_of_birth=''
                    THEN '' ELSE TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) END AS age
        FROM sunday_school_pupils p
        LEFT JOIN sunday_school_classes c ON c.id = p.class_id
        $where
        ORDER BY c.class_name ASC, p.full_name ASC";

    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

/** Build CSV rows (header + data) for pupils. */
function ss_build_csv_rows(array $pupils): array
{
    $rows   = [];
    $rows[] = [
        'Pupil No', 'Full Name', 'Gender', 'Age', 'Date of Birth',
        'Class', 'Parent / Guardian', 'Parent Phone',
        'Baptised', 'Status', 'Date Enrolled', 'Notes',
    ];
    foreach ($pupils as $p) {
        $rows[] = [
            $p['pupil_no'], $p['full_name'], $p['gender'], $p['age'],
            $p['date_of_birth'], $p['class_name'], $p['parent_guardian'],
            $p['parent_phone'], $p['baptised'], $p['status'],
            $p['date_enrolled'], $p['notes'],
        ];
    }
    return $rows;
}

/** Build a DOMpdf-ready HTML string for the pupils list. */
function ss_build_pupils_pdf_html(array $pupils, string $classLabel, string $statusLabel): string
{
    $genDate  = date('d M Y H:i');
    $total    = count($pupils);
    $subtitle = [];
    if ($classLabel  !== '') $subtitle[] = 'Class: '  . ss_h($classLabel);
    if ($statusLabel !== '') $subtitle[] = 'Status: ' . ss_h($statusLabel);
    $subtitleStr = $subtitle ? implode(' &bull; ', $subtitle) : 'All classes &bull; All statuses';

    $rows = '';
    $i    = 1;
    foreach ($pupils as $p) {
        $bg   = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $sClr = ($p['status'] === 'Active') ? '#15803d' : '#6b7280';
        $bClr = ($p['baptised'] === 'Yes')  ? '#1d4ed8' : '#9ca3af';
        $rows .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#6b7280">' . $i . '</td>
            <td>' . ss_h($p['pupil_no']) . '</td>
            <td style="font-weight:600">' . ss_h($p['full_name']) . '</td>
            <td>' . ss_h($p['gender']) . '</td>
            <td style="text-align:center">' . ss_h($p['age'] !== '' ? $p['age'] : '-') . '</td>
            <td>' . ss_h($p['class_name'] ?: '-') . '</td>
            <td>' . ss_h($p['parent_guardian'] ?: '-') . '</td>
            <td>' . ss_h($p['parent_phone'] ?: '-') . '</td>
            <td style="text-align:center;color:' . $bClr . ';font-weight:600">' . ss_h($p['baptised']) . '</td>
            <td style="text-align:center;color:' . $sClr . ';font-weight:600">' . ss_h($p['status']) . '</td>
            <td>' . ss_h($p['date_enrolled'] ?: '-') . '</td>
        </tr>';
        $i++;
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="11" style="text-align:center;color:#9ca3af;padding:20px">No pupils found.</td></tr>';
    }

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body  { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a2e; margin:0; padding:0; }
  .hdr  { background:#4a1e8f; color:#fff; padding:14px 20px 10px; }
  .hdr h1 { margin:0 0 3px; font-size:17px; }
  .hdr p  { margin:0; font-size:9px; opacity:.85; }
  .meta { padding:5px 20px 8px; font-size:8.5px; color:#555; border-bottom:1px solid #e5e7eb; }
  table { width:100%; border-collapse:collapse; font-size:8.5px; margin-top:8px; }
  thead th { background:#4a1e8f; color:#fff; padding:5px 6px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.4px; }
  tbody td { padding:4px 6px; border-bottom:1px solid #ede9fe; vertical-align:top; }
  .footer { text-align:center; font-size:7.5px; color:#9ca3af; margin-top:14px; }
</style>
</head>
<body>
<div class="hdr">
  <h1>&#9786; Sunday School &mdash; Pupils Register</h1>
  <p>' . $subtitleStr . ' &nbsp;&bull;&nbsp; Total: ' . $total . ' &nbsp;&bull;&nbsp; Generated: ' . $genDate . '</p>
</div>
<table>
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>Pupil No</th>
      <th>Full Name</th>
      <th>Gender</th>
      <th style="text-align:center">Age</th>
      <th>Class</th>
      <th>Parent / Guardian</th>
      <th>Phone</th>
      <th style="text-align:center">Baptised</th>
      <th style="text-align:center">Status</th>
      <th>Date Enrolled</th>
    </tr>
  </thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Sunday School Register &mdash; ' . $genDate . '</div>
</body>
</html>';
}


function ss_build_attendance_pdf_html(mysqli $conn, int $classId, string $lessonDate): string
{
    $genDate = date('d M Y H:i');

   
    $cls = ss_fetch_one($conn, "SELECT class_name, age_range FROM sunday_school_classes WHERE id = ?", 'i', [$classId]);
    $className = $cls ? ($cls['class_name'] ?? 'Unknown Class') : 'Unknown Class';
    $ageRange  = $cls ? ($cls['age_range']  ?? '')              : '';

    // Fetch attendance records for this session
    $stmt = $conn->prepare("
        SELECT p.pupil_no, p.full_name, p.gender,
               COALESCE(a.status,'Absent')  AS att_status,
               COALESCE(a.notes,'')         AS att_notes
        FROM sunday_school_pupils p
        LEFT JOIN sunday_school_attendance a
               ON a.pupil_id = p.id AND a.class_id = p.class_id AND a.lesson_date = ?
        WHERE p.class_id = ? AND COALESCE(p.status,'Active') = 'Active'
        ORDER BY p.full_name ASC
    ");
    $stmt->bind_param('si', $lessonDate, $classId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $pupils = [];
    while ($row = $res->fetch_assoc()) $pupils[] = $row;
    $stmt->close();

    $present = 0;
    $absent  = 0;
    foreach ($pupils as $p) {
        if (strtolower($p['att_status']) === 'present') $present++;
        else $absent++;
    }
    $total = count($pupils);

    $rows = '';
    $i    = 1;
    foreach ($pupils as $p) {
        $bg   = ($i % 2 === 0) ? '#f7f5ff' : '#ffffff';
        $isPresent = strtolower($p['att_status']) === 'present';
        $sClr = $isPresent ? '#15803d' : '#dc2626';
        $tick = $isPresent ? '&#10003;' : '&#10007;';
        $rows .= '<tr style="background:' . $bg . '">
            <td style="text-align:center;color:#6b7280">' . $i . '</td>
            <td>' . ss_h($p['pupil_no']) . '</td>
            <td style="font-weight:600">' . ss_h($p['full_name']) . '</td>
            <td>' . ss_h($p['gender']) . '</td>
            <td style="text-align:center;color:' . $sClr . ';font-weight:700;font-size:12px">' . $tick . ' ' . ss_h($p['att_status']) . '</td>
            <td>' . ss_h($p['att_notes'] ?: '-') . '</td>
        </tr>';
        $i++;
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px">No pupils in this class.</td></tr>';
    }

    $displayDate = !empty($lessonDate) ? date('d M Y', strtotime($lessonDate)) : $lessonDate;

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body  { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a2e; margin:0; padding:0; }
  .hdr  { background:#4a1e8f; color:#fff; padding:14px 20px 10px; }
  .hdr h1 { margin:0 0 3px; font-size:17px; }
  .hdr p  { margin:0; font-size:9px; opacity:.85; }
  .summary { display:flex; gap:30px; padding:10px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
  .summary-box { text-align:center; }
  .summary-box .val { font-size:22px; font-weight:800; }
  .summary-box .lbl { font-size:8px; color:#64748b; text-transform:uppercase; }
  table { width:100%; border-collapse:collapse; font-size:8.5px; margin-top:8px; }
  thead th { background:#4a1e8f; color:#fff; padding:5px 6px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.4px; }
  tbody td { padding:4px 6px; border-bottom:1px solid #ede9fe; vertical-align:top; }
  .footer { text-align:center; font-size:7.5px; color:#9ca3af; margin-top:14px; }
</style>
</head>
<body>
<div class="hdr">
  <h1>&#9786; Sunday School &mdash; Attendance Register</h1>
  <p>Class: ' . ss_h($className) . ($ageRange ? ' (' . ss_h($ageRange) . ')' : '') . ' &nbsp;&bull;&nbsp; Lesson Date: ' . ss_h($displayDate) . ' &nbsp;&bull;&nbsp; Generated: ' . $genDate . '</p>
</div>
<div class="summary">
  <div class="summary-box"><div class="val">' . $total . '</div><div class="lbl">Total</div></div>
  <div class="summary-box"><div class="val" style="color:#15803d">' . $present . '</div><div class="lbl">Present</div></div>
  <div class="summary-box"><div class="val" style="color:#dc2626">' . $absent . '</div><div class="lbl">Absent</div></div>
  <div class="summary-box"><div class="val">' . ($total > 0 ? round($present / $total * 100) : 0) . '%</div><div class="lbl">Attendance Rate</div></div>
</div>
<table>
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th>Pupil No</th>
      <th>Full Name</th>
      <th>Gender</th>
      <th style="text-align:center">Attendance</th>
      <th>Notes</th>
    </tr>
  </thead>
  <tbody>' . $rows . '</tbody>
</table>
<div class="footer">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' &mdash; Sunday School Attendance &mdash; ' . $genDate . '</div>
</body>
</html>';
}

/* ===========================================================
   MAIN ROUTER
=========================================================== */
try {
    $action = ss_clean($_GET['action'] ?? $_POST['action'] ?? 'list');

    /* -------------------------------------------------------
       CSV TEMPLATE DOWNLOAD
    ------------------------------------------------------- */
    if ($action === 'csv_template') {
        $template = [
            ['full_name','gender','date_of_birth','class_name','parent_guardian',
             'parent_phone','baptised','status','date_enrolled','notes'],
            ['John Doe','Male','2015-03-10','Junior Class','Mary Doe',
             '+256700000001','No','Active',date('Y-m-d'),''],
            ['Jane Smith','Female','2017-07-22','Infant Class','Paul Smith',
             '+256700000002','Yes','Active',date('Y-m-d'),'Choir member'],
        ];
        ss_csv_out('sunday_school_pupils_template.csv', $template);
    }

    /* -------------------------------------------------------
       EXPORT CSV  (GET: ?action=export_csv[&class_id=X][&status=Y])
    ------------------------------------------------------- */
    if ($action === 'export_csv') {
        $classId = ss_int($_GET['class_id'] ?? 0);
        $status  = ss_clean($_GET['status'] ?? '');
        if (!in_array($status, ['Active','Inactive',''], true)) $status = '';

        $pupils   = ss_fetch_pupils_for_export($conn, $classId, $status);
        $csvRows  = ss_build_csv_rows($pupils);
        ss_csv_out('sunday_school_pupils_' . date('Ymd_His') . '.csv', $csvRows);
    }

    /* -------------------------------------------------------
       EXPORT PDF — PUPILS LIST  (GET: ?action=export_pdf[&class_id=X][&status=Y])
    ------------------------------------------------------- */
    if ($action === 'export_pdf') {
        $classId = ss_int($_GET['class_id'] ?? 0);
        $status  = ss_clean($_GET['status'] ?? '');
        if (!in_array($status, ['Active','Inactive',''], true)) $status = '';

        // Resolve class label for the PDF subtitle
        $classLabel = '';
        if ($classId > 0) {
            $cls = ss_fetch_one($conn, "SELECT class_name FROM sunday_school_classes WHERE id = ?", 'i', [$classId]);
            $classLabel = $cls['class_name'] ?? '';
        }

        $pupils   = ss_fetch_pupils_for_export($conn, $classId, $status);
        $html     = ss_build_pupils_pdf_html($pupils, $classLabel, $status);
        ss_pdf_out('sunday_school_pupils_' . date('Ymd_His') . '.pdf', $html);
    }

    /* -------------------------------------------------------
       EXPORT PDF — ATTENDANCE SESSION
       GET: ?action=export_attendance_pdf&class_id=X&lesson_date=Y-m-d
    ------------------------------------------------------- */
    if ($action === 'export_attendance_pdf') {
        $classId    = ss_int($_GET['class_id'] ?? 0);
        $lessonDate = ss_clean($_GET['lesson_date'] ?? date('Y-m-d')) ?: date('Y-m-d');

        if ($classId <= 0) {
            header('Content-Type: text/plain');
            echo 'Please select a class before exporting.';
            exit;
        }

        $html = ss_build_attendance_pdf_html($conn, $classId, $lessonDate);
        ss_pdf_out('attendance_' . $lessonDate . '_class' . $classId . '.pdf', $html);
    }

    /* -------------------------------------------------------
       STATS
    ------------------------------------------------------- */
    if ($action === 'stats') {
        ss_json(true, '', ['stats' => [
            'totalPupils'      => ss_scalar($conn, "SELECT COUNT(*) FROM sunday_school_pupils"),
            'activePupils'     => ss_scalar($conn, "SELECT COUNT(*) FROM sunday_school_pupils WHERE COALESCE(status,'Active')='Active'"),
            'classes'          => ss_scalar($conn, "SELECT COUNT(*) FROM sunday_school_classes"),
            'baptised'         => ss_scalar($conn, "SELECT COUNT(*) FROM sunday_school_pupils WHERE COALESCE(baptised,0)=1"),
            'todayAttendance'  => ss_scalar($conn, "SELECT COUNT(*) FROM sunday_school_attendance WHERE lesson_date=CURDATE()"),
        ]]);
    }

    /* -------------------------------------------------------
       LIST (classes or pupils)
    ------------------------------------------------------- */
    if ($action === 'list') {
        $type = ss_clean($_GET['type'] ?? 'pupils');

        if ($type === 'classes') {
            $sql = "SELECT c.id, c.class_name, c.age_range, c.teacher_id, c.capacity, c.notes, c.created_at,
                           m.full_name AS teacher_name,
                           (SELECT COUNT(*) FROM sunday_school_pupils p WHERE p.class_id = c.id AND COALESCE(p.status,'Active')='Active') AS pupil_count
                    FROM sunday_school_classes c
                    LEFT JOIN members m ON m.id = c.teacher_id
                    ORDER BY c.class_name ASC";
            $items = [];
            $res = $conn->query($sql);
            while ($res && $row = $res->fetch_assoc()) $items[] = $row;
            ss_json(true, '', ['items' => $items]);
        }

        $classId = ss_int($_GET['class_id'] ?? 0);
        $search = ss_clean($_GET['search'] ?? '');
        $period = ss_clean($_GET['period'] ?? '');
        $dateFrom = ss_nullable_date($_GET['date_from'] ?? '');
        $dateTo = ss_nullable_date($_GET['date_to'] ?? '');
        $page = max(1, ss_int($_GET['page'] ?? 1));
        $perPage = ss_int($_GET['per_page'] ?? 25);
        if (!in_array($perPage, [10,25,50,100], true)) $perPage = 25;
        $where=['1=1']; $types=''; $params=[];
        if($classId>0){$where[]='p.class_id = ?';$types.='i';$params[]=$classId;}
        if($search!==''){$like='%'.$search.'%';$where[]='(p.pupil_no LIKE ? OR p.full_name LIKE ? OR p.parent_guardian LIKE ? OR p.parent_phone LIKE ? OR c.class_name LIKE ?)';$types.='sssss';array_push($params,$like,$like,$like,$like,$like);}
        if($period==='daily'){$where[]='p.date_enrolled = CURDATE()';}
        elseif($period==='weekly'){$where[]='p.date_enrolled BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 6 DAY)';}
        elseif($period==='range'){if($dateFrom){$where[]='p.date_enrolled >= ?';$types.='s';$params[]=$dateFrom;}if($dateTo){$where[]='p.date_enrolled <= ?';$types.='s';$params[]=$dateTo;}}
        $whereSql=' WHERE '.implode(' AND ',$where);
        $countStmt=$conn->prepare('SELECT COUNT(*) total FROM sunday_school_pupils p LEFT JOIN sunday_school_classes c ON c.id=p.class_id'.$whereSql);
        if($types!=='')$countStmt->bind_param($types,...$params);$countStmt->execute();$total=(int)($countStmt->get_result()->fetch_assoc()['total']??0);$countStmt->close();
        $totalPages=max(1,(int)ceil($total/$perPage));if($page>$totalPages)$page=$totalPages;$offset=($page-1)*$perPage;
        $sql="SELECT p.id,p.pupil_no,p.full_name,p.gender,p.date_of_birth,p.class_id,p.parent_guardian,p.parent_phone,p.parent_member_id,p.baptised,p.status,p.date_enrolled,p.notes,p.created_at,c.class_name,CASE WHEN p.date_of_birth IS NULL OR p.date_of_birth='' THEN '' ELSE TIMESTAMPDIFF(YEAR,p.date_of_birth,CURDATE()) END age FROM sunday_school_pupils p LEFT JOIN sunday_school_classes c ON c.id=p.class_id".$whereSql." ORDER BY p.date_enrolled DESC,p.id DESC LIMIT ? OFFSET ?";
        $dataTypes=$types.'ii';$dataParams=array_merge($params,[$perPage,$offset]);$stmt=$conn->prepare($sql);$stmt->bind_param($dataTypes,...$dataParams);$stmt->execute();$res=$stmt->get_result();$items=[];while($row=$res->fetch_assoc())$items[]=$row;$stmt->close();
        ss_json(true,'',['items'=>$items,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages,'from'=>$total>0?$offset+1:0,'to'=>min($offset+$perPage,$total)]]);
    }

    /* -------------------------------------------------------
       GET SINGLE PUPIL
    ------------------------------------------------------- */
    if ($action === 'get_pupil') {
        $id  = ss_int($_GET['id'] ?? 0);
        $row = ss_fetch_one($conn,
            "SELECT p.*, c.class_name,
                    CASE WHEN p.date_of_birth IS NULL OR p.date_of_birth=''
                         THEN '' ELSE TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) END AS age
             FROM sunday_school_pupils p
             LEFT JOIN sunday_school_classes c ON c.id = p.class_id
             WHERE p.id = ?", 'i', [$id]);
        if (!$row) ss_json(false, 'Pupil not found.');
        ss_json(true, '', ['item' => $row]);
    }

    /* -------------------------------------------------------
       GET SINGLE CLASS
    ------------------------------------------------------- */
    if ($action === 'get_class') {
        $id  = ss_int($_GET['id'] ?? 0);
        $row = ss_fetch_one($conn, "SELECT * FROM sunday_school_classes WHERE id = ?", 'i', [$id]);
        if (!$row) ss_json(false, 'Class not found.');
        ss_json(true, '', ['item' => $row]);
    }

    /* -------------------------------------------------------
       ATTENDANCE PUPILS (for the attendance sheet)
    ------------------------------------------------------- */
    if ($action === 'attendance_pupils') {
        $classId    = ss_int($_GET['class_id'] ?? 0);
        $lessonDate = ss_clean($_GET['lesson_date'] ?? date('Y-m-d')) ?: date('Y-m-d');
        if ($classId <= 0) ss_json(false, 'Select a class.');

        $stmt = $conn->prepare("
            SELECT p.id, p.pupil_no, p.full_name, p.gender,
                   COALESCE(a.status,'')  AS attendance_status,
                   COALESCE(a.notes,'')   AS attendance_notes
            FROM sunday_school_pupils p
            LEFT JOIN sunday_school_attendance a
                   ON a.pupil_id = p.id AND a.class_id = p.class_id AND a.lesson_date = ?
            WHERE p.class_id = ? AND COALESCE(p.status,'Active') = 'Active'
            ORDER BY p.full_name ASC
        ");
        $stmt->bind_param('si', $lessonDate, $classId);
        $stmt->execute();
        $res   = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        $stmt->close();
        ss_json(true, '', ['items' => $items]);
    }

    /* -------------------------------------------------------
       POST-ONLY ACTIONS
    ------------------------------------------------------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ss_json(false, 'Invalid request method.');

    /* CREATE PUPIL */
    if ($action === 'create_pupil') {
        $data = ss_prepare_pupil_data($conn, $_POST);
        ss_insert_pupil($conn, $data);
        ss_json(true, 'Pupil added successfully.');
    }

    /* BULK CREATE PUPILS */
    if ($action === 'bulk_create_pupils') {
        $pupils = $_POST['pupils'] ?? [];
        if (!is_array($pupils) || empty($pupils)) ss_json(false, 'No pupil rows received.');

        $cleanRows = [];
        $errors    = [];

        foreach ($pupils as $index => $row) {
            if (!is_array($row)) continue;
            $rowNo    = ((int)$index) + 1;
            $fullName = ss_clean($row['full_name'] ?? '');
            if ($fullName === '') { $errors[] = "Row {$rowNo}: Full name is required."; continue; }
            try { $cleanRows[] = ss_prepare_pupil_data($conn, $row); }
            catch (Throwable $e) { $errors[] = "Row {$rowNo}: " . $e->getMessage(); }
        }

        if ($errors) ss_json(false, implode(' ', $errors));
        if (!$cleanRows) ss_json(false, 'No valid pupil rows found.');

        $conn->begin_transaction();
        $inserted = 0;
        $pupilNos = [];
        try {
            foreach ($cleanRows as $row) {
                ss_insert_pupil($conn, $row);
                $inserted++;
                $pupilNos[] = $row['pupil_no'];
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $msg = $inserted === 1 ? '1 pupil added successfully.' : $inserted . ' pupils added successfully.';
        ss_json(true, $msg, ['inserted' => $inserted, 'pupil_nos' => $pupilNos]);
    }

    /* UPDATE PUPIL */
    if ($action === 'update_pupil') {
        $id = ss_int($_POST['id'] ?? 0);
        if ($id <= 0) ss_json(false, 'Invalid pupil ID.');
        $data = ss_prepare_pupil_data($conn, $_POST);
        $stmt = $conn->prepare("
            UPDATE sunday_school_pupils SET
                pupil_no=?, full_name=?, gender=?, date_of_birth=?, class_id=?,
                parent_guardian=?, parent_phone=?, parent_member_id=?,
                baptised=?, status=?, date_enrolled=?, notes=?
            WHERE id=?
        ");
        $stmt->bind_param(
            'ssssisssisssi',
            $data['pupil_no'], $data['full_name'], $data['gender'], $data['date_of_birth'],
            $data['class_id'], $data['parent_guardian'], $data['parent_phone'],
            $data['parent_member_id'], $data['baptised'], $data['status'],
            $data['date_enrolled'], $data['notes'], $id
        );
        $stmt->execute();
        $stmt->close();
        ss_json(true, 'Pupil updated successfully.');
    }

    /* CREATE / UPDATE CLASS */
    if ($action === 'create_class' || $action === 'update_class') {
        $id        = ss_int($_POST['id'] ?? 0);
        $className = ss_clean($_POST['class_name'] ?? '');
        if ($className === '') ss_json(false, 'Class name is required.');

        $ageRange  = ss_clean($_POST['age_range'] ?? '');
        $teacherId = ss_nullable_int($_POST['teacher_id'] ?? 0);
        $capacity  = ss_int($_POST['capacity'] ?? 0);
        $notes     = ss_clean($_POST['notes'] ?? '');

        if ($action === 'create_class') {
            $stmt = $conn->prepare("INSERT INTO sunday_school_classes (class_name, age_range, teacher_id, capacity, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiis', $className, $ageRange, $teacherId, $capacity, $notes);
            $stmt->execute();
            $stmt->close();
            ss_json(true, 'Class added successfully.');
        }

        if ($id <= 0) ss_json(false, 'Invalid class ID.');
        $stmt = $conn->prepare("UPDATE sunday_school_classes SET class_name=?, age_range=?, teacher_id=?, capacity=?, notes=? WHERE id=?");
        $stmt->bind_param('ssiisi', $className, $ageRange, $teacherId, $capacity, $notes, $id);
        $stmt->execute();
        $stmt->close();
        ss_json(true, 'Class updated successfully.');
    }

    /* DELETE PUPIL */
    if ($action === 'delete_pupil') {
        $id = ss_int($_POST['id'] ?? 0);
        if ($id <= 0) ss_json(false, 'Invalid pupil ID.');
        $stmt = $conn->prepare("DELETE FROM sunday_school_pupils WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ss_json(true, 'Pupil deleted successfully.');
    }

    /* DELETE CLASS */
    if ($action === 'delete_class') {
        $id = ss_int($_POST['id'] ?? 0);
        if ($id <= 0) ss_json(false, 'Invalid class ID.');
        $used = ss_scalar($conn, "SELECT COUNT(*) FROM sunday_school_pupils WHERE class_id = " . $id);
        if ($used > 0) ss_json(false, 'This class has pupils. Move or delete pupils first.');
        $stmt = $conn->prepare("DELETE FROM sunday_school_classes WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ss_json(true, 'Class deleted successfully.');
    }

    /* SAVE ATTENDANCE */
    if ($action === 'save_attendance') {
        $classId    = ss_int($_POST['class_id'] ?? 0);
        $lessonDate = ss_clean($_POST['lesson_date'] ?? date('Y-m-d')) ?: date('Y-m-d');
        if ($classId <= 0) ss_json(false, 'Class is required.');

        $pupilIds = $_POST['pupil_id']           ?? [];
        $statuses = $_POST['attendance_status']  ?? [];
        $attNotes = $_POST['attendance_notes']   ?? [];

        $conn->begin_transaction();
        $stmt = $conn->prepare("
            INSERT INTO sunday_school_attendance (pupil_id, class_id, lesson_date, status, notes)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)
        ");

        foreach ($pupilIds as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;
            $statusVal = ss_clean($statuses[$pid] ?? 'Absent') ?: 'Absent';
            $noteVal   = ss_clean($attNotes[$pid] ?? '');
            $stmt->bind_param('iisss', $pid, $classId, $lessonDate, $statusVal, $noteVal);
            $stmt->execute();
        }

        $stmt->close();
        $conn->commit();
        ss_json(true, 'Attendance saved successfully.');
    }

    ss_json(false, 'Invalid action.');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }
    error_log('[process-sunday-school.php] ' . $e->getMessage());
    ss_json(false, 'Server error: ' . $e->getMessage());
}