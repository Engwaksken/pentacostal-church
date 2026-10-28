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

$sql = "
    SELECT 
        m.id,
        m.member_no,
        m.full_name,
        m.gender,
        m.phone,
        m.marital_status,
        m.joined_date,
        COALESCE(d.name, m.department, '-') AS dept_name
    FROM members m
    LEFT JOIN departments d ON d.id = m.dept_id
    WHERE m.status = 'Active'
    ORDER BY m.full_name ASC
";

$members = mysqli_query($conn, $sql);

$totalRes = mysqli_query($conn, "SELECT COUNT(*) AS total FROM members WHERE status='Active'");
$totalRow = $totalRes ? mysqli_fetch_assoc($totalRes) : ['total' => 0];
$total = (int)($totalRow['total'] ?? 0);

$generated = date('d M Y H:i');

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Members Report</title>
<style>
  body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222;margin:20px}
  h1{color:#4a1e8f;font-size:16px;margin:0 0 2px}
  h3{color:#4a1e8f;font-size:13px;margin:0 0 2px}
  .subtitle{color:#777;font-size:10px;margin-bottom:15px}
  table{width:100%;border-collapse:collapse;font-size:10px}
  th{background:#4a1e8f;color:#fff;padding:6px 4px;text-align:left}
  td{padding:5px 4px;border-bottom:1px solid #eee}
  tr:nth-child(even) td{background:#f8f4ff}
  .footer{margin-top:15px;font-size:9px;color:#999;text-align:center}
  .header-bar{border-bottom:3px solid #d4a017;padding-bottom:10px;margin-bottom:15px}
  .meta{float:right;text-align:right;font-size:10px;color:#555}
</style>
</head>
<body>

<div class="header-bar">
  <div class="meta">
    Generated: <?= e($generated) ?><br>
    Total: <?= number_format($total) ?> members
  </div>

  <h1><?= e(strtoupper(APP_NAME)) ?></h1>
  <h3>Active Members Register</h3>
  <div class="subtitle">Official Members Report &mdash; Confidential</div>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Member No</th>
      <th>Full Name</th>
      <th>Gender</th>
      <th>Phone</th>
      <th>Department</th>
      <th>Marital Status</th>
      <th>Date Joined</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($members && mysqli_num_rows($members) > 0): ?>
      <?php $i = 1; while ($m = mysqli_fetch_assoc($members)): ?>
        <?php $fullName = trim((string)($m['full_name'] ?? '')); ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= e($m['member_no'] ?? '-') ?></td>
          <td><?= e($fullName ?: '-') ?></td>
          <td><?= e($m['gender'] ?? '-') ?></td>
          <td><?= e($m['phone'] ?? '-') ?></td>
          <td><?= e($m['dept_name'] ?? '-') ?></td>
          <td><?= e($m['marital_status'] ?? '-') ?></td>
          <td><?= e(fmtDate($m['joined_date'] ?? '')) ?></td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr>
        <td colspan="8" style="text-align:center;padding:15px;">No active members found.</td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>

<div class="footer">
  <?= e(APP_NAME) ?> &mdash; Members Report &mdash; <?= e($generated) ?>
</div>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

if (isset($_GET['pdf'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        die('DomPDF is not installed. Run: composer require dompdf/dompdf');
    }

    require_once $autoload;

    $opts = new \Dompdf\Options();
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('Members_Report_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-users"></i> Members Report</h2>

  <div>
    <a href="<?= BASE_URL ?>members/" class="btn btn-secondary mr-1">
      <i class="fas fa-arrow-left"></i> Back
    </a>

    <button onclick="window.print()" class="btn btn-primary mr-1">
      <i class="fas fa-print"></i> Print
    </button>

    <a href="?pdf=1" class="btn btn-danger">
      <i class="fas fa-file-pdf"></i> PDF
    </a>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:750px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php include '../includes/footer.php'; ?>
