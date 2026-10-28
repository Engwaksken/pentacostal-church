<?php
require_once '../config/config.php';
requireLogin();
// Quick stats for reports dashboard
$total_members = numRows("SELECT id FROM members WHERE status='Active'");
$total_youth   = numRows("SELECT id FROM youth");
$total_contrib = fetchOne("SELECT COALESCE(SUM(amount),0) as t FROM contributions")['t'];
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<div class="page-header">
  <h2><i class="fas fa-chart-bar"></i> Reports Centre</h2>
  <a href="finance.php" class="btn btn-primary"><i class="fas fa-file-invoice-dollar"></i> Financial Statements Dashboard</a>
</div>
<!-- Financial Statements Hero Card -->
<div class="card mb-4" style="background:linear-gradient(135deg,#4a1e8f,#7b3fcf);color:#fff;border:none">
  <div class="card-body d-flex align-items-center justify-content-between p-4">
    <div>
      <h4 class="mb-1"><i class="fas fa-file-invoice-dollar mr-2"></i> Financial Statements</h4>
      <p class="mb-2 opacity-75">Full church finance dashboard with Income Statement, Fund Balance, Tithe, Offertory, Thanksgiving, Pledges, Fundraising and Year-on-Year comparison charts.</p>
      <a href="finance.php" class="btn btn-light mr-2"><i class="fas fa-chart-line mr-1"></i> Open Dashboard</a>
      <a href="income_statement.php?from=<?=date('Y-01-01')?>&to=<?=date('Y-m-d')?>&pdf=1" class="btn btn-warning"><i class="fas fa-file-pdf mr-1"></i> Download Income Statement PDF</a>
    </div>
    <div style="font-size:4rem;opacity:.3"></div>
  </div>
</div>
<h5 class="mb-3 text-muted"><i class="fas fa-print mr-1"></i> Document Reports</h5>
<div class="alert alert-light border d-flex justify-content-between align-items-center"><span><i class="fas fa-receipt text-danger mr-2"></i>Record and review operating expenses</span><a href="../expenses/" class="btn btn-sm btn-primary">Manage Expenses</a></div>
<div class="row">
  <!-- Members Report -->
  <div class="col-md-4 mb-4">
    <div class="card report-card h-100">
      <div class="card-body text-center">
        <div class="report-icon" style="background:#4a1e8f"><i class="fas fa-users"></i></div>
        <h5 class="mt-3">Members Report</h5>
        <p class="text-muted">Full list of all registered church members with departments.</p>
        <div class="badge badge-primary mb-3"><?=$total_members?> Active Members</div>
        <div class="d-flex justify-content-center gap-2">
          <a href="members_report.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> Preview</a>
          <a href="members_report.php?pdf=1" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
      </div>
    </div>
  </div>
  <!-- Contributions Report -->
  <div class="col-md-4 mb-4">
    <div class="card report-card h-100">
      <div class="card-body text-center">
        <div class="report-icon" style="background:#28a745"><i class="fas fa-hand-holding-usd"></i></div>
        <h5 class="mt-3">Contributions Report</h5>
        <p class="text-muted">All contributions per project with totals and member breakdown.</p>
        <div class="badge badge-success mb-3">UGX <?=number_format($total_contrib)?></div>
        <div class="d-flex justify-content-center gap-2">
          <a href="contributions_report.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> Preview</a>
          <a href="contributions_report.php?pdf=1" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
      </div>
    </div>
  </div>
  <!-- Attendance Report -->
  <div class="col-md-4 mb-4">
    <div class="card report-card h-100">
      <div class="card-body text-center">
        <div class="report-icon" style="background:#17a2b8"><i class="fas fa-clipboard-check"></i></div>
        <h5 class="mt-3">Attendance Report</h5>
        <p class="text-muted">Service attendance summary by date range and service type.</p>
        <div class="badge badge-info mb-3">Monthly Summary</div>
        <div class="d-flex justify-content-center gap-2">
          <a href="attendance_report.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> Preview</a>
          <a href="attendance_report.php?pdf=1" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
      </div>
    </div>
  </div>
  <!-- Youth Report -->
  <div class="col-md-4 mb-4">
    <div class="card report-card h-100">
      <div class="card-body text-center">
        <div class="report-icon" style="background:#6610f2"><i class="fas fa-child"></i></div>
        <h5 class="mt-3">Youth Report</h5>
        <p class="text-muted">Youth members list with project participation summary.</p>
        <div class="badge badge-secondary mb-3"><?=$total_youth?> Youth Members</div>
        <div class="d-flex justify-content-center gap-2">
          <a href="youth_report.php" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> Preview</a>
          <a href="youth_report.php?pdf=1" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
</div>
<style>
.report-icon{width:70px;height:70px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:1.8rem;color:#fff}
.gap-2{gap:.5rem}
</style>
<?php include '../includes/footer.php'; ?>
