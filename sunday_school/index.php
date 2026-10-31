<?php
$pageTitle = 'Sunday School';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function ss_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ss_count(mysqli $conn, string $sql): int {
    try { $r = $conn->query($sql); $row = $r ? $r->fetch_row() : [0]; return (int)($row[0] ?? 0); }
    catch (Throwable $e) { return 0; }
}

/* Enrol child members and place pupils in classes from their dates of birth. */
require_once '../includes/sunday-school-sync.php';
// One-time switch to the standard classes (Nursery 3-5, Junior 6-12, Senior 13-17).
$ssStandard = sunday_school_apply_standard_classes_once($conn);
$ssSync = sunday_school_auto_sync($conn);
if ($ssStandard) {
    // Report the moves made while switching, which the run above no longer sees.
    foreach (['enrolled', 'linked', 'moved', 'graduated'] as $k) $ssSync[$k] += (int)($ssStandard['sync'][$k] ?? 0);
}

$totalPupils   = ss_count($conn, "SELECT COUNT(*) FROM sunday_school_pupils");
$activePupils  = ss_count($conn, "SELECT COUNT(*) FROM sunday_school_pupils WHERE COALESCE(status,'Active')='Active'");
$totalClasses  = ss_count($conn, "SELECT COUNT(*) FROM sunday_school_classes");
$baptised      = ss_count($conn, "SELECT COUNT(*) FROM sunday_school_pupils WHERE COALESCE(baptised,0)=1");
$todayAttend   = ss_count($conn, "SELECT COUNT(*) FROM sunday_school_attendance WHERE lesson_date=CURDATE()");

$classes = [];
try {
    $res = $conn->query("SELECT id, class_name, age_range, capacity FROM sunday_school_classes ORDER BY class_name ASC");
    while ($res && $row = $res->fetch_assoc()) { $classes[] = $row; }
} catch (Throwable $e) {}

$members = [];
try {
    $res = $conn->query("SELECT id, full_name FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name");
    while ($res && $row = $res->fetch_assoc()) { $members[] = $row; }
} catch (Throwable $e) {}
?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">

<div class="page-header d-flex justify-content-between align-items-center mb-3">
  <div>
    <h2><i class="fas fa-child"></i> Sunday School</h2>
    <p class="text-muted mb-0">Manage Sunday School classes, pupils and attendance.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <!-- Export / Import group -->
    <div class="ss-export-group">
      <button type="button" class="btn btn-outline btn-sm" id="btnExportCSV" title="Export current pupil list as CSV">
        <i class="fas fa-file-csv"></i> Export CSV
      </button>
      <button type="button" class="btn btn-outline btn-sm" id="btnExportPDF" title="Export current pupil list as PDF">
        <i class="fas fa-file-pdf"></i> Export PDF
      </button>
      <button type="button" class="btn btn-outline btn-sm" id="btnDownloadPupilTemplate" title="Download blank CSV import template">
        <i class="fas fa-download"></i> CSV Template
      </button>
    </div>
    <!-- Management actions -->
    <button type="button" class="btn btn-primary" id="btnAddPupil"><i class="fas fa-user-plus"></i> Add Pupil</button>
    <button type="button" class="btn btn-success" id="btnBulkAddPupils"><i class="fas fa-users"></i> Add Multiple Pupils</button>
    <button type="button" class="btn btn-secondary" id="btnAddClass"><i class="fas fa-plus"></i> Add Class</button>
  </div>
</div>

<?php
// A short note only when something changed just now, or when placement needs attention.
$ssNotes = [];
if (!empty($ssStandard)) $ssNotes[] = 'classes set to Nursery (3-5), Junior (6-12) and Senior (13-17)';
if (!empty($ssSync['ran']) && $ssSync['min'] !== null) {
    if ($ssSync['enrolled'] > 0) $ssNotes[] = (int)$ssSync['enrolled'] . ' child member' . ($ssSync['enrolled'] === 1 ? '' : 's') . ' enrolled';
    if ($ssSync['linked'] > 0) $ssNotes[] = (int)$ssSync['linked'] . ' existing pupil' . ($ssSync['linked'] === 1 ? '' : 's') . ' linked to their member record';
    if ($ssSync['moved'] > 0) $ssNotes[] = (int)$ssSync['moved'] . ' moved to the class for their age';
    if ($ssSync['graduated'] > 0) $ssNotes[] = (int)$ssSync['graduated'] . ' graduated';
}
$ssShowNoDob = !empty($ssSync['ran']) && $ssSync['min'] !== null && $ssSync['no_dob'] > 0;
?>
<?php if (!empty($ssSync['ran']) && $ssSync['min'] === null): ?>
<div class="info-box" style="margin-bottom:16px">
  <i class="fas fa-birthday-cake"></i>
  <div>
    <h4>Automatic class placement is off</h4>
    <p>No class has an age range yet. Give each class an age range such as <strong>3-5 years</strong> and pupils will be placed by date of birth, and child members enrolled automatically.</p>
  </div>
</div>
<?php elseif ($ssNotes || $ssShowNoDob): ?>
<div class="info-box" style="margin-bottom:16px">
  <i class="fas fa-birthday-cake"></i>
  <div>
    <p style="margin:0">
      <?php if ($ssNotes): ?><strong>Just now:</strong> <?= ss_h(ucfirst(implode(', ', $ssNotes))) ?>.<?php endif; ?>
      <?php if ($ssShowNoDob): ?><?= $ssNotes ? '<br>' : '' ?><?= (int)$ssSync['no_dob'] ?> active pupil<?= $ssSync['no_dob'] === 1 ? ' has' : 's have' ?> no date of birth and cannot be placed automatically.<?php endif; ?>
    </p>
  </div>
</div>
<?php endif; ?>

<div class="ss-stats-grid">
  <div class="ss-stat-card"><div class="ss-stat-icon"><i class="fas fa-users"></i></div><div><div class="ss-stat-value" id="statTotalPupils"><?= (int)$totalPupils ?></div><div class="ss-stat-label">Total Pupils</div></div></div>
  <div class="ss-stat-card"><div class="ss-stat-icon"><i class="fas fa-check-circle"></i></div><div><div class="ss-stat-value" id="statActivePupils"><?= (int)$activePupils ?></div><div class="ss-stat-label">Active Pupils</div></div></div>
  <div class="ss-stat-card"><div class="ss-stat-icon"><i class="fas fa-chalkboard-teacher"></i></div><div><div class="ss-stat-value" id="statClasses"><?= (int)$totalClasses ?></div><div class="ss-stat-label">Classes</div></div></div>
  <div class="ss-stat-card"><div class="ss-stat-icon"><i class="fas fa-water"></i></div><div><div class="ss-stat-value" id="statBaptised"><?= (int)$baptised ?></div><div class="ss-stat-label">Baptised</div></div></div>
  <div class="ss-stat-card"><div class="ss-stat-icon"><i class="fas fa-hourglass-half"></i></div><div><div class="ss-stat-value" id="statAwaiting"><?= max(0, (int)$totalPupils - (int)$baptised) ?></div><div class="ss-stat-label">Awaiting Baptism</div></div></div>
  <div class="ss-stat-card"><div class="ss-stat-icon"><i class="fas fa-clipboard-check"></i></div><div><div class="ss-stat-value" id="statTodayAttendance"><?= (int)$todayAttend ?></div><div class="ss-stat-label">Today Attendance Records</div></div></div>
</div>

<div id="ssAlert" class="ss-alert"></div>

<!-- Export filter bar (hidden until an export button is clicked) -->
<div id="ssExportBar" class="ss-export-bar" style="display:none">
  <span style="font-weight:700;font-size:13px"><i class="fas fa-filter"></i> Export filters</span>
  <select id="exportClassFilter" class="form-control" style="width:auto;min-width:160px">
    <option value="">All Classes</option>
    <?php foreach($classes as $c): ?>
      <option value="<?= (int)($c['id'] ?? 0) ?>"><?= ss_h($c['class_name'] ?? '') ?></option>
    <?php endforeach; ?>
  </select>
  <select id="exportStatusFilter" class="form-control" style="width:auto;min-width:140px">
    <option value="">All Status</option>
    <option value="Active">Active</option>
    <option value="Inactive">Inactive</option>
  </select>
  <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCloseExportBar">
    <i class="fas fa-times"></i>
  </button>
</div>

<div class="ss-tabs">
  <button type="button" class="ss-tab active" data-tab="classes"><i class="fas fa-layer-group"></i> Classes</button>
  <button type="button" class="ss-tab" data-tab="pupils"><i class="fas fa-child"></i> Pupils</button>
  <button type="button" class="ss-tab" data-tab="attendance"><i class="fas fa-clipboard-check"></i> Attendance</button>
</div>

<!-- CLASSES TAB -->
<section id="tabClasses">
  <div class="card"><div class="card-body">
    <div class="ss-toolbar"><h4 class="mb-0">Classes Overview</h4><div class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-outline-primary" id="btnStandardClasses" title="Set up Nursery 3-5, Junior 6-12 and Senior 13-17"><i class="fas fa-magic"></i> Use Standard Classes</button><button type="button" class="btn btn-outline" id="btnRefreshClasses"><i class="fas fa-sync"></i> Refresh</button></div></div>
    <div id="classesGrid" class="ss-class-grid"><div class="text-muted">Loading classes...</div></div>
  </div></div>
</section>

<!-- PUPILS TAB -->
<section id="tabPupils" class="d-none">
  <div class="page-toolbar">
      <div class="page-toolbar-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" id="pupilSearch" placeholder="Search pupil, parent, phone..." autocomplete="off">
      </div>
      <div class="page-toolbar-filters">
      <select id="classFilter" aria-label="Filter by class"><option value="">All Classes</option><?php foreach($classes as $c): ?><option value="<?= (int)($c['id'] ?? 0) ?>"><?= ss_h($c['class_name'] ?? '') ?></option><?php endforeach; ?></select>
      <select id="pupilPeriodFilter" aria-label="Filter by date period">
        <option value="">All Dates</option><option value="daily">Today</option><option value="weekly">This Week</option><option value="range">Custom Range</option>
      </select>
      <select id="pupilPerPage" aria-label="Records per page"><option value="10">10 per page</option><option value="25" selected>25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select>
      </div>
      <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-secondary" id="btnClearPupilFilters"><i class="fas fa-rotate-left"></i> Clear</button>
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshPupils"><i class="fas fa-rotate-right"></i> Refresh</button>
      </div>
  </div>
  <div class="card"><div class="card-body">
    <div class="ss-date-range" id="pupilDateRange" hidden>
      <div class="ss-field"><label for="pupilDateFrom">Start date</label><input type="date" id="pupilDateFrom"></div>
      <div class="ss-field"><label for="pupilDateTo">End date</label><input type="date" id="pupilDateTo"></div>
    </div>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>#</th><th>Pupil No</th><th>Name</th><th>Gender</th><th>Class</th><th>Age</th><th>Parent/Guardian</th><th>Phone</th><th>Baptised</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="pupilsBody"><tr><td colspan="11" class="text-muted">Loading...</td></tr></tbody>
      </table>
    </div>
    <div class="ss-pagination-wrap"><div id="pupilPaginationSummary" class="text-muted">Showing 0 pupils</div><nav id="pupilPagination" class="ss-pagination"></nav></div>
  </div></div>
</section>

<!-- ATTENDANCE TAB -->
<section id="tabAttendance" class="d-none">
  <div class="card"><div class="card-body">
    <div class="ss-grid">
      <div class="ss-field"><label>Class</label><select id="attendanceClass"><option value="">-- Select Class --</option><?php foreach($classes as $c): ?><option value="<?= (int)($c['id'] ?? 0) ?>"><?= ss_h($c['class_name'] ?? '') ?></option><?php endforeach; ?></select></div>
      <div class="ss-field"><label>Lesson Date</label><input type="date" id="attendanceDate" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="ss-toolbar mt-3">
      <h5 class="mb-0">Attendance List</h5>
      <div>
        <button type="button" class="btn btn-sm btn-outline" id="btnExportAttendancePDF" title="Export this attendance session as PDF">
          <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button type="button" class="btn btn-sm btn-success" id="btnAllPresent">All Present</button>
        <button type="button" class="btn btn-sm btn-secondary" id="btnClearAttendance">Clear</button>
      </div>
    </div>
    <form id="attendanceForm">
      <input type="hidden" name="action" value="save_attendance">
      <input type="hidden" name="class_id" id="attClassId">
      <input type="hidden" name="lesson_date" id="attLessonDate">
      <div id="attendanceList" class="ss-att-grid"><div class="text-muted">Choose a class to load pupils.</div></div>
      <div class="ss-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Attendance</button></div>
    </form>
  </div></div>
</section>

<!-- ======================================================
     MODALS
====================================================== -->
<div class="ss-modal-backdrop" id="ssBackdrop"></div>

<!-- Add / Edit Pupil -->
<div class="ss-modal" id="pupilModal"><div class="ss-dialog"><form id="pupilForm">
  <div class="ss-modal-header"><div class="ss-modal-title" id="pupilTitle">Add Pupil</div><button type="button" class="ss-close" data-close>&times;</button></div>
  <div class="ss-modal-body">
    <div id="pupilAlert" class="ss-alert"></div>
    <input type="hidden" name="action" id="pupilAction" value="create_pupil">
    <input type="hidden" name="id" id="pupilId">
    <div class="ss-grid">
      <div class="ss-field"><label>Pupil No</label><input type="text" name="pupil_no" id="pupil_no" placeholder="Auto if empty"></div>
      <div class="ss-field"><label>Full Name *</label><input type="text" name="full_name" id="full_name" required></div>
      <div class="ss-field"><label>Gender</label><select name="gender" id="gender"><option value="">Select</option><option>Male</option><option>Female</option></select></div>
      <div class="ss-field"><label>Date of Birth</label><input type="date" name="date_of_birth" id="date_of_birth"></div>
      <div class="ss-field"><label>Class</label><select name="class_id" id="class_id"><option value="">-- Select Class --</option><?php foreach($classes as $c): ?><option value="<?= (int)($c['id'] ?? 0) ?>"><?= ss_h($c['class_name'] ?? '') ?></option><?php endforeach; ?></select></div>
      <div class="ss-field"><label>Status</label><select name="status" id="status"><option>Active</option><option>Inactive</option></select></div>
      <div class="ss-field"><label>Parent / Guardian</label><input type="text" name="parent_guardian" id="parent_guardian"></div>
      <div class="ss-field"><label>Parent Phone</label><input type="text" name="parent_phone" id="parent_phone"></div>
      <div class="ss-field"><label>Parent Member</label><select name="parent_member_id" id="parent_member_id"><option value="">-- None --</option><?php foreach($members as $m): ?><option value="<?= (int)($m['id'] ?? 0) ?>"><?= ss_h(trim((string)($m['full_name'] ?? ''))) ?></option><?php endforeach; ?></select></div>
      <div class="ss-field"><label>Baptised?</label><select name="baptised" id="baptised"><option value="0">No</option><option value="1">Yes</option></select></div>
      <div class="ss-field"><label>Date Enrolled</label><input type="date" name="date_enrolled" id="date_enrolled" value="<?= date('Y-m-d') ?>"></div>
      <div class="ss-field ss-full"><label>Notes</label><textarea name="notes" id="notes"></textarea></div>
    </div>
  </div>
  <div class="ss-actions"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
</form></div></div>

<!-- Bulk Add Pupils -->
<div class="ss-modal" id="bulkPupilModal">
  <div class="ss-dialog" style="width:min(1200px,100%)">
    <form id="bulkPupilForm" autocomplete="off">
      <div class="ss-modal-header">
        <div class="ss-modal-title"><i class="fas fa-users"></i> Add Multiple Pupils <span class="badge badge-primary" id="bulkPupilCount">1 pupil</span></div>
        <button type="button" class="ss-close" data-close>&times;</button>
      </div>
      <div class="ss-modal-body">
        <div id="bulkPupilAlert" class="ss-alert"></div>
        <input type="hidden" name="action" value="bulk_create_pupils">
        <div class="ss-bulk-shared">
          <div class="ss-field">
            <label>Default Class</label>
            <select id="bulkDefaultClass"><option value="">-- Select Class --</option><?php foreach($classes as $c): ?><option value="<?= (int)($c['id'] ?? 0) ?>"><?= ss_h($c['class_name'] ?? '') ?></option><?php endforeach; ?></select>
          </div>
          <div class="ss-field">
            <label>Default Date Enrolled</label>
            <input type="date" id="bulkDefaultDate" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="ss-field">
            <label>Default Status</label>
            <select id="bulkDefaultStatus"><option>Active</option><option>Inactive</option></select>
          </div>
          <div class="ss-field">
            <label>&nbsp;</label>
            <button type="button" class="btn btn-outline" id="btnApplyBulkDefaults"><i class="fas fa-magic"></i> Apply Defaults</button>
          </div>
        </div>
        <div class="ss-import-box">
          <div>
            <strong><i class="fas fa-file-csv"></i> CSV Import</strong>
            <p class="text-muted mb-0">Download the template, fill it in, then upload here. Existing rows below will be replaced.</p>
          </div>
          <div class="ss-import-actions">
            <button type="button" class="btn btn-outline btn-sm" id="btnDownloadPupilTemplateModal"><i class="fas fa-download"></i> Download CSV Template</button>
            <label class="btn btn-primary btn-sm mb-0" for="pupilCsvUpload"><i class="fas fa-upload"></i> Upload CSV</label>
            <input type="file" id="pupilCsvUpload" accept=".csv,text/csv" style="display:none">
          </div>
        </div>
        <div class="ss-bulk-table-wrap">
          <table class="table ss-bulk-table">
            <thead><tr>
              <th style="width:40px">#</th>
              <th>Full Name *</th>
              <th>Gender</th>
              <th>Date of Birth</th>
              <th>Class</th>
              <th>Parent/Guardian</th>
              <th>Parent Phone</th>
              <th>Baptised</th>
              <th>Status</th>
              <th>Date Enrolled</th>
              <th>Notes</th>
              <th style="width:45px"></th>
            </tr></thead>
            <tbody id="bulkPupilRows"></tbody>
          </table>
        </div>
        <button type="button" class="btn btn-outline btn-sm" id="btnAddBulkPupilRow"><i class="fas fa-plus"></i> Add Another Pupil</button>
      </div>
      <div class="ss-actions">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="bulkPupilSubmit"><i class="fas fa-save"></i> Save Pupils</button>
      </div>
    </form>
  </div>
</div>

<!-- Add / Edit Class -->
<div class="ss-modal" id="classModal"><div class="ss-dialog ss-dialog-sm"><form id="classForm">
  <div class="ss-modal-header"><div class="ss-modal-title" id="classTitle">Add Class</div><button type="button" class="ss-close" data-close>&times;</button></div>
  <div class="ss-modal-body">
    <div id="classAlert" class="ss-alert"></div>
    <input type="hidden" name="action" id="classAction" value="create_class">
    <input type="hidden" name="id" id="classId">
    <div class="ss-grid">
      <div class="ss-field ss-full"><label>Class Name *</label><input type="text" name="class_name" id="class_name" required></div>
      <div class="ss-field"><label for="age_from">From Age (years)</label><input type="number" name="age_from" id="age_from" min="0" max="99" step="1" placeholder="e.g. 3"></div>
      <div class="ss-field"><label for="age_to">To Age (years)</label><input type="number" name="age_to" id="age_to" min="0" max="99" step="1" placeholder="e.g. 5"></div>
      <div class="ss-field ss-full"><small class="text-muted">Pupils are placed in this class automatically when their age is in this range. Classes cannot overlap. Leave both empty for a class pupils are only added to by hand.</small></div>
      <div class="ss-field"><label>Capacity</label><input type="number" name="capacity" id="capacity" min="0" value="0"></div>
      <div class="ss-field ss-full"><label>Teacher</label><select name="teacher_id" id="teacher_id"><option value="">-- Select Teacher --</option><?php foreach($members as $m): ?><option value="<?= (int)($m['id'] ?? 0) ?>"><?= ss_h(trim((string)($m['full_name'] ?? ''))) ?></option><?php endforeach; ?></select></div>
      <div class="ss-field ss-full"><label>Notes</label><textarea name="notes" id="class_notes"></textarea></div>
    </div>
  </div>
  <div class="ss-actions"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn btn-primary"><i class="fas fa-save"></i> Save Class</button></div>
</form></div></div>

<!-- View Pupil -->
<div class="ss-modal" id="viewModal"><div class="ss-dialog">
  <div class="ss-modal-header"><div class="ss-modal-title"><i class="fas fa-eye"></i> Pupil Details</div><button type="button" class="ss-close" data-close>&times;</button></div>
  <div class="ss-modal-body"><div id="viewContent" class="ss-view-grid"></div></div>
  <div class="ss-actions"><button type="button" class="btn btn-outline" data-close>Close</button></div>
</div></div>

<!-- Delete Confirm -->
<div class="ss-modal" id="deleteModal"><div class="ss-dialog ss-dialog-sm"><form id="deleteForm">
  <div class="ss-modal-header"><div class="ss-modal-title"><i class="fas fa-trash"></i> Confirm Delete</div><button type="button" class="ss-close" data-close>&times;</button></div>
  <div class="ss-modal-body">
    <div id="deleteAlert" class="ss-alert"></div>
    <input type="hidden" name="action" id="deleteAction">
    <input type="hidden" name="id" id="deleteId">
    <p>Are you sure you want to delete <strong id="deleteName"></strong>?</p>
  </div>
  <div class="ss-actions"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
</form></div></div>

</div><!-- /.content-area -->
</div><!-- /.main-content -->

<style>
/* -- Layout ------------------------------------------- */
.ss-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}
.ss-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;align-items:center;gap:14px}
.ss-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--primary) 12%,transparent);color:var(--primary);font-size:22px}
.ss-stat-value{font-size:26px;font-weight:800;line-height:1}
.ss-stat-label{font-size:13px;color:#64748b;margin-top:4px}

/* -- Export bar --------------------------------------- */
.ss-export-group{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.ss-export-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:#f5f1ff;border:1px solid #c4b5fd;border-radius:12px}

/* -- Tabs --------------------------------------------- */
.ss-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
.ss-tab{border:1px solid #d1d5db;background:#fff;padding:10px 14px;border-radius:10px;cursor:pointer;font-weight:700}
.ss-tab.active{background:var(--primary);color:#fff;border-color:var(--primary)}

/* -- Toolbar ------------------------------------------ */
.ss-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:14px}
.ss-toolbar-left{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.ss-search{min-width:260px;max-width:420px;flex:1}
.ss-search input,.ss-toolbar select{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px}

/* -- Class cards -------------------------------------- */
.ss-class-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.ss-class-card{background:#fff;border-radius:16px;border:1px solid rgba(148,163,184,.25);box-shadow:0 8px 20px rgba(15,23,42,.06);overflow:hidden}
.ss-class-head{background:var(--primary);color:#fff;padding:14px 16px}
.ss-class-body{padding:16px}
.ss-class-count{text-align:center;font-size:42px;font-weight:800;color:var(--primary)}

/* -- Modals ------------------------------------------- */
.ss-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:9998;display:none}
.ss-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px}
.ss-modal.show,.ss-modal-backdrop.show{display:flex}
.ss-modal-backdrop.show{display:block}
.ss-dialog{width:min(900px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}
.ss-dialog-sm{width:min(560px,100%)}
.ss-modal-header{padding:16px 18px;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}
.ss-modal-title{font-weight:800;font-size:18px}
.ss-close{border:0;background:transparent;font-size:26px;line-height:1;cursor:pointer}
.ss-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}
.ss-actions{padding:14px 18px;background:#f8fafc;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px}

/* -- Form helpers ------------------------------------- */
.ss-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.ss-full{grid-column:1/-1}
.ss-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}
.ss-field input,.ss-field select,.ss-field textarea{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px}
.ss-field textarea{min-height:90px;resize:vertical}

/* -- Alerts ------------------------------------------- */
.ss-alert{display:none;margin-bottom:12px;border-radius:10px;padding:10px 12px}
.ss-alert.success{display:block;background:#dcfce7;color:#166534}
.ss-alert.error{display:block;background:#fee2e2;color:#991b1b}

/* -- Misc --------------------------------------------- */
.ss-view-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ss-detail{padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc}
.ss-detail b{display:block;font-size:12px;color:#64748b;margin-bottom:3px}
.ss-att-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.ss-att-pupil{padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
.ss-table-actions{display:flex;gap:6px;flex-wrap:wrap}
.btn-danger{background:#dc2626;color:#fff}
.text-muted{color:#64748b}
.d-none{display:none!important}

/* -- Bulk-add table ----------------------------------- */
.ss-bulk-shared{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px;padding:12px;border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px}
.ss-import-box{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;padding:12px;border:1px dashed #94a3b8;background:#f8fafc;border-radius:14px}
.ss-import-box p{font-size:13px}
.ss-import-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ss-bulk-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:12px}
.ss-bulk-table{min-width:1250px;margin-bottom:0}
.ss-bulk-table th{font-size:12px;white-space:nowrap;background:#f8fafc}
.ss-bulk-table td{vertical-align:top}
.ss-bulk-table input,.ss-bulk-table select{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 9px;font-size:13px}
.ss-row-num{font-weight:800;color:#64748b;text-align:center;padding-top:8px}
.ss-remove-row{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center}

.ss-pupil-filters{flex:1;min-width:0}.ss-pupil-filters select,.ss-pupil-filters input[type="date"]{min-width:145px}.ss-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap}.ss-pagination-wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:14px;border-top:1px solid #e5e7eb}.ss-pagination{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.ss-page-btn{min-width:36px;height:36px;padding:0 10px;border:1px solid #d1d5db;border-radius:9px;background:#fff;color:#334155;font-weight:700;cursor:pointer}.ss-page-btn:hover:not(:disabled),.ss-page-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}.ss-page-btn:disabled{opacity:.45;cursor:not-allowed}
/* -- Responsive --------------------------------------- */
@media(max-width:992px){
  .ss-stats-grid,.ss-class-grid,.ss-att-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ss-grid,.ss-view-grid{grid-template-columns:1fr}
  .ss-bulk-shared{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:560px){
  .ss-stats-grid,.ss-class-grid,.ss-att-grid{grid-template-columns:1fr}
  .ss-search{min-width:100%;max-width:100%}
  .ss-bulk-shared{grid-template-columns:1fr}
  .ss-pupil-filters>*{width:100%;min-width:100%!important}
  .ss-toolbar-actions{width:100%}.ss-toolbar-actions .btn{flex:1}
}

/* -- Pupil custom date range ---------------------------- */
.ss-date-range{display:grid;grid-template-columns:repeat(2,minmax(180px,260px));gap:12px;align-items:end;margin:-2px 0 14px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}
.ss-date-range[hidden]{display:none!important}
@media(max-width:900px){.ss-date-range{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.ss-date-range{grid-template-columns:1fr}}

</style>

<script src="sunday-school.js?v=<?= time() ?>"></script>
<?php require_once '../includes/footer.php'; ?>