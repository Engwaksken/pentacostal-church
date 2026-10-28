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

function money($amount): string {
    return number_format((float)$amount);
}

function sumBetween(mysqli $conn, string $table, string $amountCol, string $dateCol, string $from, string $to): float {
    $safe = '/^[a-zA-Z0-9_]+$/';

    if (!preg_match($safe, $table) || !preg_match($safe, $amountCol) || !preg_match($safe, $dateCol)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM($amountCol), 0) AS v FROM $table WHERE $dateCol BETWEEN ? AND ?");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (float)($row['v'] ?? 0);
}

function singleValue(mysqli $conn, string $sql): float {
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();
    return (float)($row['v'] ?? 0);
}

$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-01-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$generated = date('d F Y, H:i');

$inc = [
    'Offertory' => sumBetween($conn, 'offertory', 'total_amount', 'service_date', $from, $to),
    'Tithe' => sumBetween($conn, 'tithe', 'amount', 'tithe_date', $from, $to),
    'Thanksgiving Offerings' => sumBetween($conn, 'thanksgiving', 'amount', 'thanksgiving_date', $from, $to),
    'Contributions' => sumBetween($conn, 'contributions', 'amount', 'contributed_on', $from, $to),
    'Pledge Receipts' => sumBetween($conn, 'pledge_payments', 'amount', 'paid_on', $from, $to),
    'Fundraising Collections' => sumBetween($conn, 'fundraising_collections', 'amount', 'collection_date', $from, $to),
];

$total_income = array_sum($inc);

$proj_list = mysqli_query($conn, "
    SELECT name, type, target_amount, status
    FROM projects
    ORDER BY type ASC, name ASC
");

$total_proj = singleValue($conn, "SELECT COALESCE(SUM(target_amount), 0) AS v FROM projects");

$pledges_pending = singleValue($conn, "
    SELECT COALESCE(SUM(amount_pledged - amount_paid), 0) AS v
    FROM pledges
    WHERE status IN ('Pending', 'Partial')
");

$pledges_total = singleValue($conn, "
    SELECT COALESCE(SUM(amount_pledged), 0) AS v
    FROM pledges
");

$pledges_received = singleValue($conn, "
    SELECT COALESCE(SUM(amount_paid), 0) AS v
    FROM pledges
");

$surplus = $total_income - $total_proj;

$pledge_rows = mysqli_query($conn, "
    SELECT 
        p.pledge_no,
        m.full_name AS full_name,
        p.amount_pledged,
        p.amount_paid,
        p.due_date,
        p.status
    FROM pledges p
    LEFT JOIN members m ON m.id = p.member_id
    WHERE p.status IN ('Pending', 'Partial')
    ORDER BY p.due_date ASC
    LIMIT 20
");

$pcStmt = $conn->prepare("
    SELECT 
        p.name,
        p.target_amount,
        COALESCE(SUM(c.amount), 0) AS raised
    FROM projects p
    LEFT JOIN contributions c
        ON c.project_id = p.id
        AND c.contributed_on BETWEEN ? AND ?
    GROUP BY p.id, p.name, p.target_amount
    ORDER BY raised DESC
");
$pcStmt->bind_param("ss", $from, $to);
$pcStmt->execute();
$project_contributions = $pcStmt->get_result();

$monthlyRows = [];
$monthlyGrandTotal = 0;

for ($i = 11; $i >= 0; $i--) {
    $mf = date('Y-m-01', strtotime("-{$i} months"));
    $mt = date('Y-m-t', strtotime($mf));
    $ml = date('M Y', strtotime($mf));

    $mo  = sumBetween($conn, 'offertory', 'total_amount', 'service_date', $mf, $mt);
    $mti = sumBetween($conn, 'tithe', 'amount', 'tithe_date', $mf, $mt);
    $mtg = sumBetween($conn, 'thanksgiving', 'amount', 'thanksgiving_date', $mf, $mt);
    $mc  = sumBetween($conn, 'contributions', 'amount', 'contributed_on', $mf, $mt);
    $mfu = sumBetween($conn, 'fundraising_collections', 'amount', 'collection_date', $mf, $mt);

    $monthTotal = $mo + $mti + $mtg + $mc + $mfu;
    $monthlyGrandTotal += $monthTotal;

    $monthlyRows[] = [
        'month' => $ml,
        'offertory' => $mo,
        'tithe' => $mti,
        'thanksgiving' => $mtg,
        'contributions' => $mc,
        'fundraising' => $mfu,
        'total' => $monthTotal,
    ];
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Income Statement</title>
<style>
  body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111;margin:0;padding:20px}
  .header-block{border-bottom:3px solid #4a1e8f;padding-bottom:12px;margin-bottom:20px}
  .header-block h1{color:#4a1e8f;font-size:15px;margin:2px 0}
  .header-block h2{color:#d4a017;font-size:13px;margin:2px 0}
  .header-block .meta{float:right;text-align:right;font-size:10px;color:#666}
  .cross{font-size:2rem;color:#4a1e8f;float:left;margin-right:12px;line-height:1}
  table{width:100%;border-collapse:collapse;margin-bottom:16px}
  th{background:#4a1e8f;color:#fff;padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase}
  td{padding:5px 8px;border-bottom:1px solid #eee;font-size:11px}
  tr:nth-child(even) td{background:#f8f4ff}
  .section-head td{background:#e8e0f5;font-weight:bold;color:#4a1e8f;font-size:11px;padding:6px 8px}
  .subtotal td{background:#d4a017;color:#9c27b0;font-weight:bold;padding:6px 8px}
  .surplus td{background:#28a745;color:#fff;font-weight:bold;font-size:12px;padding:8px}
  .deficit td{background:#dc3545;color:#fff;font-weight:bold;font-size:12px;padding:8px}
  .right{text-align:right}
  .indent{padding-left:20px!important}
  .note{background:#fff9e6;border-left:4px solid #d4a017;padding:8px 12px;font-size:10px;color:#666;margin-bottom:12px}
  .footer{margin-top:20px;text-align:center;font-size:9px;color:#aaa;border-top:1px solid #eee;padding-top:8px}
  .sig-block{display:table;width:100%;margin-top:30px}
  .sig-cell{display:table-cell;width:33%;padding:0 10px;text-align:center}
  .sig-line{border-top:1px solid #4a1e8f;padding-top:4px;font-size:10px;color:#555}
</style>
</head>
<body>

<div class="header-block">
  <div class="meta">
    Period: <?= e(fmtDate($from)) ?> - <?= e(fmtDate($to)) ?><br>
    Generated: <?= e($generated) ?>
  </div>

  <span class="cross">✝</span>
  
  <h2>STATEMENT OF INCOME AND FUNDS</h2>
 
</div>

<div class="note">
  All amounts in Uganda Shillings (UGX). This statement covers the period:
  <strong><?= e(fmtDate($from)) ?> to <?= e(fmtDate($to)) ?></strong>.
</div>

<table>
  <thead>
    <tr><th colspan="2">A. INCOME / RECEIPTS</th></tr>
  </thead>
  <tbody>
    <tr class="section-head"><td colspan="2">Regular Church Collections</td></tr>

    <?php foreach ($inc as $name => $amt): ?>
      <?php if ($amt <= 0) continue; ?>
      <tr>
        <td class="indent"><?= e($name) ?></td>
        <td class="right"><?= money($amt) ?></td>
      </tr>
    <?php endforeach; ?>

    <tr class="subtotal">
      <td>TOTAL INCOME</td>
      <td class="right"><?= money($total_income) ?></td>
    </tr>
  </tbody>
</table>

<table>
  <thead>
    <tr><th colspan="3">B. PLEDGES SUMMARY</th></tr>
  </thead>
  <tbody>
    <tr class="section-head"><td colspan="3">Pledge Commitments & Collections</td></tr>

    <tr>
      <td class="indent">Total Pledges Committed</td>
      <td></td>
      <td class="right"><?= money($pledges_total) ?></td>
    </tr>

    <tr>
      <td class="indent">Amount Received</td>
      <td></td>
      <td class="right"><?= money($pledges_received) ?></td>
    </tr>

    <tr>
      <td class="indent"><strong>Outstanding Balance</strong></td>
      <td></td>
      <td class="right"><strong><?= money($pledges_pending) ?></strong></td>
    </tr>

    <?php if ($pledge_rows && mysqli_num_rows($pledge_rows) > 0): ?>
      <?php while ($pr = mysqli_fetch_assoc($pledge_rows)): ?>
        <?php $bal = (float)$pr['amount_pledged'] - (float)$pr['amount_paid']; ?>
        <tr>
          <td class="indent" style="padding-left:30px"><?= e($pr['full_name'] ?? '-') ?></td>
          <td class="right" style="color:#999"><?= money($pr['amount_pledged'] ?? 0) ?></td>
          <td class="right">
            <?= money($bal) ?> <small>(due <?= e(fmtDate($pr['due_date'] ?? '')) ?>)</small>
          </td>
        </tr>
      <?php endwhile; ?>
    <?php endif; ?>
  </tbody>
</table>

<table>
  <thead>
    <tr><th colspan="3">C. PROJECTS &amp; BUDGET ALLOCATION</th></tr>
  </thead>
  <tbody>
    <tr class="section-head">
      <td>Project Name</td>
      <td>Type</td>
      <td class="right">Budget (UGX)</td>
    </tr>

    <?php if ($proj_list && mysqli_num_rows($proj_list) > 0): ?>
      <?php while ($p = mysqli_fetch_assoc($proj_list)): ?>
        <tr>
          <td class="indent"><?= e($p['name'] ?? '-') ?></td>
          <td><?= e($p['type'] ?? '-') ?></td>
          <td class="right"><?= money($p['target_amount'] ?? 0) ?></td>
        </tr>
      <?php endwhile; ?>
    <?php endif; ?>

    <tr class="subtotal">
      <td colspan="2">TOTAL PROJECT BUDGETS</td>
      <td class="right"><?= money($total_proj) ?></td>
    </tr>
  </tbody>
</table>

<table>
  <tbody>
    <tr class="<?= $surplus >= 0 ? 'surplus' : 'deficit' ?>">
      <td><?= $surplus >= 0 ? 'SURPLUS (Income exceeds Budgets)' : 'DEFICIT (Budgets exceed Income)' ?></td>
      <td class="right">
        <?= $surplus < 0 ? '(' : '' ?>UGX <?= money(abs($surplus)) ?><?= $surplus < 0 ? ')' : '' ?>
      </td>
    </tr>
  </tbody>
</table>

<table>
  <thead>
    <tr><th colspan="3">D. CONTRIBUTIONS RECEIVED PER PROJECT (This Period)</th></tr>
  </thead>
  <tbody>
    <tr class="section-head">
      <td>Project</td>
      <td class="right">Raised This Period</td>
      <td class="right">Overall Target</td>
    </tr>

    <?php if ($project_contributions && $project_contributions->num_rows > 0): ?>
      <?php while ($row = $project_contributions->fetch_assoc()): ?>
        <tr>
          <td class="indent"><?= e($row['name'] ?? '-') ?></td>
          <td class="right"><?= money($row['raised'] ?? 0) ?></td>
          <td class="right"><?= money($row['target_amount'] ?? 0) ?></td>
        </tr>
      <?php endwhile; ?>
    <?php endif; ?>
  </tbody>
</table>

<table>
  <thead>
    <tr><th colspan="7">E. MONTHLY INCOME BREAKDOWN</th></tr>
  </thead>
  <tbody>
    <tr class="section-head">
      <td>Month</td>
      <td class="right">Offertory</td>
      <td class="right">Tithe</td>
      <td class="right">Thanksgiving</td>
      <td class="right">Contributions</td>
      <td class="right">Fundraising</td>
      <td class="right">Total</td>
    </tr>

    <?php foreach ($monthlyRows as $mr): ?>
      <tr>
        <td><?= e($mr['month']) ?></td>
        <td class="right"><?= money($mr['offertory']) ?></td>
        <td class="right"><?= money($mr['tithe']) ?></td>
        <td class="right"><?= money($mr['thanksgiving']) ?></td>
        <td class="right"><?= money($mr['contributions']) ?></td>
        <td class="right"><?= money($mr['fundraising']) ?></td>
        <td class="right"><strong><?= money($mr['total']) ?></strong></td>
      </tr>
    <?php endforeach; ?>

    <tr class="subtotal">
      <td colspan="6">12-MONTH GRAND TOTAL</td>
      <td class="right"><?= money($monthlyGrandTotal) ?></td>
    </tr>
  </tbody>
</table>

<div class="sig-block">
  <div class="sig-cell"><div class="sig-line">Prepared by: _____________________<br>Name &amp; Signature / Date</div></div>
  <div class="sig-cell"><div class="sig-line">Reviewed by: _____________________<br>Treasurer / Date</div></div>
  <div class="sig-cell"><div class="sig-line">Approved by: _____________________<br>Vicar / Date</div></div>
</div>

<div class="footer">
 Financial Statement - Generated <?= e($generated) ?>
</div>

</body>
</html>
<?php
require_once __DIR__ . '/report-branding.php';
$html = report_brand_html(ob_get_clean());

if (isset($_GET['pdf'])) {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        die('<p>Run <code>composer require dompdf/dompdf</code> in project root. <a href="finance.php">Back</a></p>');
    }

    require_once $autoload;

    $opts = new \Dompdf\Options();
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('Income_Statement_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<div class="page-header">
  <h2><i class="fas fa-file-invoice-dollar"></i> Income Statement</h2>

  <div>
    <a href="finance.php" class="btn btn-secondary mr-1">
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
      <input type="date" name="from" value="<?= e($from) ?>" class="form-control mr-2">

      <label class="mr-2">To:</label>
      <input type="date" name="to" value="<?= e($to) ?>" class="form-control mr-2">

      <button class="btn btn-secondary">
        <i class="fas fa-filter"></i> Apply
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-2">
    <iframe srcdoc="<?= e($html) ?>" style="width:100%;min-height:900px;border:none"></iframe>
  </div>
</div>

</div>
</div>

<?php
$pcStmt->close();
include '../includes/footer.php';
?>
