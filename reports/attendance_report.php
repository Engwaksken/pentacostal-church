<?php
require_once '../config/config.php';
requireLogin();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fmtDate($date): string {
    if (empty($date) || strtotime((string)$date) === false) {
        return '-';
    }
    return date('d M Y', strtotime((string)$date));
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$stmt = $conn->prepare("
    SELECT 
        a.service_date,
        a.service_type,
        COUNT(*) AS total,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent
    FROM attendance a
    WHERE a.service_date BETWEEN ? AND ?
    GROUP BY a.service_date, a.service_type
    ORDER BY a.service_date DESC
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$sessions = $stmt->get_result();

$generated = date('d M Y H:i');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Attendance Report</title>
<style>
  body{font-family:DejaVu Sans,sans-serif;font-size:10px;margin:20px;color:#222}
  h1{color:#4a1e8f;font-size:16px;margin:0 0 2px}
  h3{color:#4a1e8f;font-size:12px;margin:0 0 4px}
  .subtitle{color:#777;font-size:9px;margin-bottom:12px}
  table{width:100%;border-collapse:collapse;font-size:9px}
  th{background:#4a1e8f;color:#fff;padding:5px 3px;text-align:left}
  td{padding:4px 3px;border-bottom:1px solid #eee}
  tr:nth-child(even) td{background:#f8f4ff}
  .header-bar{border-bottom:3px solid #d4a017;padding-bottom:8px;margin-bottom:12px}
  .meta{float:right;font-size:9px;color:#555;text-align:right}
  .footer{margin-top:10px;font-size:8px;color:#999;text-align:center}
</style>
</head>

<body>

<div class="header-bar">
  <div class="meta">
    Period: <?= e(fmtDate($from)) ?> to <?= e(fmtDate($to)) ?><br>
    Generated: <?= e($generated) ?>
  </div>

  <h1><?= e(strtoupper(APP_NAME)) ?></h1>
  <h3>Attendance Summary Report</h3>
  <div class="subtitle">Period: <?= e(fmtDate($from)) ?> &mdash; <?= e(fmtDate($to)) ?></div>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Date</th>
      <th>Service Type</th>
      <th>Total Members</th>
      <th>Present</th>
      <th>Absent</th>
      <th>Attendance %</th>
    </tr>
  </thead>

  <tbody>
  <?php if ($sessions && $sessions->num_rows > 0): ?>
    <?php $i = 1; while ($s = $sessions->fetch_assoc()): ?>
      <?php
        $total = (int)($s['total'] ?? 0);
        $present = (int)($s['present'] ?? 0);
        $absent = (int)($s['absent'] ?? 0);
        $pct = $total > 0 ? round(($present / $total) * 100) : 0;
      ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><?= e(fmtDate($s['service_date'] ?? '')) ?></td>
        <td><?= e($s['service_type'] ?? '-') ?></td>
        <td><?= number_format($total) ?></td>
        <td><?= number_format($present) ?></td>
        <td><?= number_format($absent) ?></td>
        <td><?= $pct ?>%</td>
      </tr>
    <?php endwhile; ?>
  <?php else: ?>
    <tr>
      <td colspan="7" style="text-align:center;padding:15px;">No attendance records found.</td>
    </tr>
  <?php endif; ?>
  </tbody>
</table>

<div class="footer">
Attendance Report &mdash; <?= e($generated) ?>
</div>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

if (isset($_GET['pdf'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        die('<p>Run <code>composer require dompdf/dompdf</code>. <a href="index.php">Back</a></p>');
    }

    require_once $autoload;

    $opts = new \Dompdf\Options();
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Attendance_Report_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-clipboard-check"></i> Attendance Report</h2>

  <div>
    <a href="index.php" class="btn btn-secondary mr-1">
      <i class="fas fa-arrow-left"></i> Back
    </a>

    <button onclick="window.print()" class="btn btn-primary mr-1">
      <i class="fas fa-print"></i> Print
    </button>

    <a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&pdf=1" class="btn btn-danger">
      <i class="fas fa-file-pdf"></i> PDF
    </a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="GET" class="form-inline">
      <label class="mr-2">From:</label>
      <input type="date" name="from" value="<?= e($from) ?>" class="form-control mr-3">

      <label class="mr-2">To:</label>
      <input type="date" name="to" value="<?= e($to) ?>" class="form-control mr-3">

      <button class="btn btn-primary mr-2">
        <i class="fas fa-filter"></i> Filter
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:600px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php
$stmt->close();
include '../includes/footer.php';
?>
