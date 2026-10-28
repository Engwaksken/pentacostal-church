<?php
require_once '../config/config.php';
require_once __DIR__ . '/checkin_token_helper.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function ath($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function at_scalar(mysqli $conn, string $sql): int {
    try {
        $res = $conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row();
        $res->close();
        return (int)($row[0] ?? 0);
    } catch (Throwable $e) { return 0; }
}

$totalMembers = at_scalar($conn, "SELECT COUNT(*) FROM members WHERE COALESCE(status,'Active')='Active'");
$totalSessions = at_scalar($conn, "SELECT COUNT(*) FROM (SELECT service_date, service_type FROM attendance GROUP BY service_date, service_type) x");
$todayPresent = at_scalar($conn, "SELECT COUNT(*) FROM attendance WHERE service_date=CURDATE() AND status='Present'");
$monthSessions = at_scalar($conn, "SELECT COUNT(*) FROM (SELECT service_date, service_type FROM attendance WHERE service_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY service_date, service_type) x");
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content attendance-page">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">

<?php echo showFlash(); ?>

<div class="page-header attendance-header">
  <div>
    <h2><i class="fas fa-clipboard-check"></i> Attendance Register</h2>
    <p class="text-muted mb-0">Mark, view and manage service attendance.</p>
  </div>
  <div class="attendance-header-actions">
    <button type="button" class="btn btn-outline" id="btnOpenShare"><i class="fas fa-qrcode"></i> Share Check-In Link</button>
    <button type="button" class="btn btn-primary" id="btnOpenMarkAttendance"><i class="fas fa-plus"></i> Mark Attendance</button>
  </div>
</div>

<div class="attendance-stats-grid">
  <div class="attendance-stat-card"><div class="attendance-stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="attendance-stat-value" id="statSessions"><?= (int)$totalSessions ?></div><div class="attendance-stat-label">Total Sessions</div></div></div>
  <div class="attendance-stat-card"><div class="attendance-stat-icon"><i class="fas fa-users"></i></div><div><div class="attendance-stat-value" id="statMembers"><?= (int)$totalMembers ?></div><div class="attendance-stat-label">Active Members</div></div></div>
  <div class="attendance-stat-card"><div class="attendance-stat-icon"><i class="fas fa-user-check"></i></div><div><div class="attendance-stat-value" id="statTodayPresent"><?= (int)$todayPresent ?></div><div class="attendance-stat-label">Today Present</div></div></div>
  <div class="attendance-stat-card"><div class="attendance-stat-icon"><i class="fas fa-chart-line"></i></div><div><div class="attendance-stat-value" id="statAverage">0%</div><div class="attendance-stat-label">Average Rate</div></div></div>
  <div class="attendance-stat-card"><div class="attendance-stat-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="attendance-stat-value" id="statMonthSessions"><?= (int)$monthSessions ?></div><div class="attendance-stat-label">This Month</div></div></div>
  <div class="attendance-stat-card"><div class="attendance-stat-icon"><i class="fas fa-user-times"></i></div><div><div class="attendance-stat-value" id="statLastAbsent">0</div><div class="attendance-stat-label">Last Session Absent</div></div></div>
</div>

<div class="card">
  <div class="card-body">
    <div class="page-toolbar">
      <div class="page-toolbar-search">
        <i class="fas fa-search"></i>
        <input type="search" id="attendanceSearch" placeholder="Search by date, service type or notes...">
      </div>

      <div class="page-toolbar-filters">
      <select id="attendancePeriod" class="form-control" aria-label="Attendance period">
        <option value="all">All periods</option>
        <option value="daily">Today</option>
        <option value="weekly">This week</option>
        <option value="range">Custom range</option>
      </select>

      <div class="attendance-date-range" id="attendanceDateRange" hidden>
        <input type="date" id="attendanceDateFrom" class="form-control" aria-label="Start date">
        <span>to</span>
        <input type="date" id="attendanceDateTo" class="form-control" aria-label="End date">
      </div>

      <select id="attendancePerPage" class="form-control" aria-label="Records per page">
        <option value="10">10 per page</option>
        <option value="25">25 per page</option>
        <option value="50">50 per page</option>
        <option value="100">100 per page</option>
      </select>
      </div>

      <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshAttendance">
          <i class="fas fa-rotate-right"></i> Refresh
        </button>
      </div>
    </div>
    <div id="attendanceAlert" class="attendance-alert"></div>
    <div class="table-responsive">
      <table class="table attendance-table">
        <thead>
          <tr>
            <th>#</th><th>Date</th><th>Service Type</th><th>Present</th><th>Total Members</th><th>Attendance %</th><th>Notes</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="attendanceTableBody">
          <tr><td colspan="8" class="text-muted">Loading attendance sessions...</td></tr>
        </tbody>
      </table>
    </div>

    <div class="attendance-pagination-wrap">
      <div class="attendance-pagination-summary" id="attendancePaginationSummary">
        Showing 0 attendance sessions
      </div>
      <nav class="attendance-pagination" id="attendancePagination" aria-label="Attendance pagination"></nav>
    </div>
  </div>
</div>

<div class="attendance-modal-backdrop" id="attendanceBackdrop"></div>

<div class="attendance-modal" id="markAttendanceModal" aria-hidden="true">
  <div class="attendance-dialog attendance-dialog-xl">
    <form id="attendanceForm">
      <div class="attendance-modal-header">
        <div class="attendance-modal-title"><i class="fas fa-clipboard-check"></i> <span id="attendanceFormTitle">Mark Attendance</span></div>
        <button type="button" class="attendance-close" data-close-attendance-modal>&times;</button>
      </div>
      <div class="attendance-modal-body">
        <div id="formAlert" class="attendance-alert"></div>
        <input type="hidden" name="csrf_token" value="<?= ath($csrfToken) ?>">
        <input type="hidden" name="action" value="save_session">
        <div class="attendance-form-grid">
          <div class="attendance-field">
            <label>Service Date <span class="req">*</span></label>
            <input type="date" name="service_date" id="service_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="attendance-field">
            <label>Service Type <span class="req">*</span></label>
            <select name="service_type" id="service_type" required>
              <option>Sunday Service</option>
              <option>Wednesday Bible Study</option>
              <option>Friday Prayer</option>
              <option>Special Service</option>
              <option>Youth Meeting</option>
              <option>Other</option>
            </select>
          </div>
          <div class="attendance-field">
            <label>Notes</label>
            <input type="text" name="notes" id="notes" placeholder="e.g. Guest preacher">
          </div>
        </div>

        <div class="attendance-member-toolbar">
          <h5><i class="fas fa-users"></i> Members List</h5>
          <div class="attendance-member-actions">
            <input type="text" id="memberSearch" placeholder="Search member...">
            <button type="button" class="btn btn-sm btn-success" id="btnMarkAll"><i class="fas fa-check-double"></i> Mark All</button>
            <button type="button" class="btn btn-sm btn-secondary" id="btnClearAll"><i class="fas fa-times"></i> Clear All</button>
            <button type="button" class="btn btn-sm btn-outline" id="btnShareFromForm"><i class="fas fa-qrcode"></i> Share Link</button>
          </div>
        </div>
        <div id="membersList" class="attendance-members-grid">
          <div class="text-muted">Loading members...</div>
        </div>
      </div>
      <div class="attendance-actions">
        <button type="button" class="btn btn-outline" data-close-attendance-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Attendance</button>
      </div>
    </form>
  </div>
</div>

<div class="attendance-modal" id="viewAttendanceModal" aria-hidden="true">
  <div class="attendance-dialog attendance-dialog-xl">
    <div class="attendance-modal-header">
      <div class="attendance-modal-title"><i class="fas fa-clipboard-list"></i> Attendance Details</div>
      <button type="button" class="attendance-close" data-close-attendance-modal>&times;</button>
    </div>
    <div class="attendance-modal-body">
      <div id="viewSessionSummary" class="attendance-view-stats"></div>
      <div class="attendance-toolbar compact"><div class="attendance-search"><input type="text" id="viewSearch" placeholder="Search records..."></div></div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>#</th><th>Member</th><th>No.</th><th>Department</th><th>Phone</th><th>Status</th></tr></thead>
          <tbody id="viewAttendanceBody"><tr><td colspan="6" class="text-muted">No records loaded.</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="attendance-actions"><button type="button" class="btn btn-outline" data-close-attendance-modal>Close</button></div>
  </div>
</div>

<div class="attendance-modal" id="deleteAttendanceModal" aria-hidden="true">
  <div class="attendance-dialog attendance-dialog-sm">
    <form id="deleteAttendanceForm">
      <div class="attendance-modal-header"><div class="attendance-modal-title"><i class="fas fa-trash"></i> Delete Attendance Session</div><button type="button" class="attendance-close" data-close-attendance-modal>&times;</button></div>
      <div class="attendance-modal-body">
        <div id="deleteAlert" class="attendance-alert"></div>
        <input type="hidden" name="csrf_token" value="<?= ath($csrfToken) ?>">
        <input type="hidden" name="action" value="delete_session">
        <input type="hidden" name="service_date" id="deleteDate">
        <input type="hidden" name="service_type" id="deleteType">
        <p>Delete attendance for <strong id="deleteSessionLabel"></strong>?</p>
        <p class="text-muted">This removes all member attendance records for this session.</p>
      </div>
      <div class="attendance-actions"><button type="button" class="btn btn-outline" data-close-attendance-modal>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
    </form>
  </div>
</div>

<div class="attendance-modal" id="shareAttendanceModal" aria-hidden="true">
  <div class="attendance-dialog attendance-dialog-sm">
    <div class="attendance-modal-header">
      <div class="attendance-modal-title"><i class="fas fa-qrcode"></i> Share Check-In Link</div>
      <button type="button" class="attendance-close" data-close-attendance-modal>&times;</button>
    </div>
    <div class="attendance-modal-body">
      <div id="shareAlert" class="attendance-alert"></div>
      <p class="text-muted" style="margin-top:-4px">Members can open this link or scan the QR code to mark themselves present for the selected service - no login required.</p>

      <div class="attendance-form-grid share-grid">
        <div class="attendance-field">
          <label>Service Date <span class="req">*</span></label>
          <input type="date" id="share_service_date" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="attendance-field">
          <label>Service Type <span class="req">*</span></label>
          <select id="share_service_type">
            <option>Sunday Service</option>
            <option>Wednesday Bible Study</option>
            <option>Friday Prayer</option>
            <option>Special Service</option>
            <option>Youth Meeting</option>
            <option>Other</option>
          </select>
        </div>
        <div class="attendance-field">
          <label>Link Expires</label>
          <select id="share_expiry">
            <option value="0">No expiry</option>
            <option value="2">2 hours</option>
            <option value="6" selected>6 hours</option>
            <option value="24">24 hours</option>
            <option value="168">7 days</option>
          </select>
        </div>
      </div>

      <button type="button" class="btn btn-primary btn-block" id="btnGenerateShareLink" style="width:100%;margin-bottom:16px">
        <i class="fas fa-link"></i> Generate Link
      </button>

      <div id="shareResult" class="share-result" style="display:none">
        <div class="share-qr-wrap"><img id="shareQrImage" width="220" height="220" alt="Check-in QR code"></div>
        <div class="share-url-row">
          <input type="text" id="shareUrlInput" readonly>
          <button type="button" class="btn btn-outline" id="btnCopyShareUrl" title="Copy link"><i class="fas fa-copy"></i></button>
        </div>
        <div class="share-meta">
          <a href="#" id="shareOpenLink" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open link</a>
          <span id="shareExpiryLabel" class="text-muted"></span>
        </div>
      </div>
    </div>
    <div class="attendance-actions"><button type="button" class="btn btn-outline" data-close-attendance-modal>Close</button></div>
  </div>
</div>

</div>
</div>

<style>
.attendance-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}.attendance-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;gap:14px;align-items:center}.attendance-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.12);font-size:20px}.attendance-stat-value{font-size:26px;font-weight:800;line-height:1}.attendance-stat-label{font-size:13px;color:#64748b;margin-top:5px}.attendance-header-actions{display:flex;gap:10px;flex-wrap:wrap}.attendance-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px;flex-wrap:wrap}.attendance-toolbar.compact{justify-content:flex-start}.attendance-search{min-width:260px;max-width:430px;flex:1}.attendance-search input,.attendance-field input,.attendance-field select,.attendance-member-actions input{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px}.attendance-alert{display:none;margin-bottom:12px;border-radius:10px;padding:10px 12px}.attendance-alert.success{display:block;background:#dcfce7;color:#166534}.attendance-alert.error{display:block;background:#fee2e2;color:#991b1b}.attendance-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:9998;display:none}.attendance-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:28px 14px}.attendance-modal.show{display:flex}.attendance-modal-backdrop.show{display:block}.attendance-dialog{background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;width:min(900px,100%)}.attendance-dialog-xl{width:min(1120px,100%)}.attendance-dialog-sm{width:min(540px,100%)}.attendance-modal-header{padding:16px 18px;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center}.attendance-modal-title{font-size:18px;font-weight:800}.attendance-close{border:0;background:transparent;font-size:26px;line-height:1;cursor:pointer}.attendance-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}.attendance-actions{padding:14px 18px;background:#f8fafc;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px}.attendance-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.share-grid{margin-bottom:16px}.attendance-field label{font-weight:700;font-size:13px;display:block;margin-bottom:6px}.attendance-member-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:12px 0;flex-wrap:wrap}.attendance-member-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.attendance-member-actions input{width:230px}.attendance-members-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.attendance-member-card{border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fff;display:flex;gap:10px;align-items:flex-start}.attendance-member-card input{margin-top:4px}.attendance-member-card small{display:block;color:#64748b}.attendance-progress{min-width:90px;background:#e5e7eb;border-radius:999px;height:22px;overflow:hidden}.attendance-progress-bar{height:100%;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;background:#dc3545}.attendance-progress-bar.good{background:#28a745}.attendance-progress-bar.mid{background:#f59e0b}.attendance-view-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.attendance-mini-stat{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#f8fafc}.attendance-mini-stat strong{font-size:22px;display:block}.table-actions{display:flex;gap:6px;flex-wrap:wrap}.btn-icon{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;padding:0}.badge{display:inline-flex;align-items:center;gap:4px}.badge-success{background:#dcfce7;color:#166534}.badge-danger{background:#fee2e2;color:#991b1b}.badge-primary{background:#dbeafe;color:#1e40af}.text-muted{color:#64748b}.mb-0{margin-bottom:0}
.share-qr-wrap{display:flex;justify-content:center;padding:14px;background:#f8fafc;border-radius:14px;margin-bottom:14px}
.share-url-row{display:flex;gap:8px;margin-bottom:10px}
.share-url-row input{flex:1;border:1px solid #d1d5db;border-radius:10px;padding:9px 10px;font-size:13px;background:#f8fafc}
.share-meta{display:flex;justify-content:space-between;align-items:center;font-size:13px}
.share-meta a{color:var(--primary);font-weight:700;text-decoration:none}
@media(max-width:950px){.attendance-stats-grid,.attendance-members-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.attendance-form-grid,.attendance-view-stats{grid-template-columns:1fr 1fr}}
@media(max-width:620px){.attendance-stats-grid,.attendance-members-grid,.attendance-form-grid,.attendance-view-stats{grid-template-columns:1fr}.attendance-search,.attendance-member-actions input{min-width:100%;max-width:100%;width:100%}.attendance-member-actions{width:100%}.attendance-header-actions{width:100%}.attendance-header-actions .btn{flex:1}}

.page-toolbar-filters>.attendance-date-range{display:flex;align-items:center;gap:8px;flex:0 1 300px;min-width:280px}
.attendance-date-range[hidden]{display:none!important}
.attendance-date-range span{color:#64748b;font-size:13px;white-space:nowrap}
.attendance-pagination-wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-top:1px solid #e5e7eb;background:#fff}
.attendance-pagination-summary{color:#64748b;font-size:13px}
.attendance-pagination{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.attendance-page-btn{min-width:36px;height:36px;padding:0 10px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#334155;font-weight:700;cursor:pointer}
.attendance-page-btn:hover:not(:disabled){border-color:var(--primary,#4a1e8f);color:var(--primary,#4a1e8f)}
.attendance-page-btn.active{border-color:var(--primary,#4a1e8f);background:var(--primary,#4a1e8f);color:#fff}
.attendance-page-btn:disabled{opacity:.5;cursor:not-allowed}
@media(max-width:780px){.page-toolbar-filters>.attendance-date-range{grid-column:1/-1;min-width:0}.attendance-pagination-wrap{align-items:flex-start;flex-direction:column}}
@media(max-width:520px){.attendance-date-range{flex-direction:column;align-items:stretch}.attendance-date-range span{text-align:center}.attendance-pagination{width:100%;justify-content:center}}

</style>

<script>
(function(){
  'use strict';
  const endpoint='process-attendance.php';
  const shareEndpoint='generate-checkin-link.php';
  const csrfToken=<?= json_encode($csrfToken) ?>;
  let sessions=[], members=[], viewRecords=[];
  let attendancePage=1;
  let attendancePagination={page:1,per_page:10,total:0,total_pages:1,from:0,to:0};
  const qs=(s,r=document)=>r.querySelector(s);
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const badge=(txt,cls)=>`<span class="badge ${cls}">${esc(txt)}</span>`;
  function showAlert(el,msg,type='success'){if(!el)return;el.className='attendance-alert '+(type==='error'?'error':'success');el.textContent=msg;}
  function clearAlert(el){if(el){el.className='attendance-alert';el.textContent='';}}
  async function request(url,opts={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts));const text=await res.text();try{return JSON.parse(text)}catch(e){throw new Error('Invalid server response: '+text.slice(0,220));}}
  async function postForm(form){return request(endpoint,{method:'POST',body:new FormData(form)});}
  function openModal(id){qs('#attendanceBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
  function closeModals(){qsa('.attendance-modal,.attendance-modal-backdrop').forEach(x=>x.classList.remove('show'));document.body.style.overflow='';}
  function progress(p){let cls=p>=70?'good':(p>=40?'mid':'');return `<div class="attendance-progress"><div class="attendance-progress-bar ${cls}" style="width:${Number(p)||0}%">${Number(p)||0}%</div></div>`;}
  function renderStats(items){
    qs('#statSessions').textContent=attendancePagination.total||0;
    const avg=items.length?Math.round(items.reduce((a,b)=>a+(Number(b.percentage)||0),0)/items.length):0;
    qs('#statAverage').textContent=avg+'%';
    qs('#statLastAbsent').textContent=items.length?(items[0].absent||0):0;
  }

  function renderTable(items){
    const tbody=qs('#attendanceTableBody');

    if(!items.length){
      tbody.innerHTML='<tr><td colspan="8" class="text-muted">No attendance sessions found for the selected filters.</td></tr>';
      return;
    }

    const rowStart=Math.max(0,(attendancePagination.from||1)-1);

    tbody.innerHTML=items.map((s,i)=>`<tr>
      <td>${rowStart+i+1}</td><td>${esc(s.service_date)}</td><td>${badge(s.service_type,'badge-primary')}</td>
      <td><strong class="text-success">${Number(s.present)||0}</strong></td><td>${Number(s.total_members)||0}</td><td>${progress(s.percentage)}</td><td>${esc(s.notes||'-')}</td>
      <td><div class="table-actions">
        <button type="button" class="btn btn-sm btn-info btn-icon" data-action="view" data-date="${esc(s.service_date)}" data-type="${esc(s.service_type)}" title="View"><i class="fas fa-eye"></i></button>
        <button type="button" class="btn btn-sm btn-primary btn-icon" data-action="edit" data-date="${esc(s.service_date)}" data-type="${esc(s.service_type)}" title="Edit"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-sm btn-secondary btn-icon" data-action="share" data-date="${esc(s.service_date)}" data-type="${esc(s.service_type)}" title="Share check-in link"><i class="fas fa-qrcode"></i></button>
        <button type="button" class="btn btn-sm btn-danger btn-icon" data-action="delete" data-date="${esc(s.service_date)}" data-type="${esc(s.service_type)}" title="Delete"><i class="fas fa-trash"></i></button>
      </div></td></tr>`).join('');
  }

  function pageWindow(current,total){
    const pages=[];
    const start=Math.max(1,current-2);
    const end=Math.min(total,current+2);

    if(start>1)pages.push(1);
    if(start>2)pages.push('...');
    for(let page=start;page<=end;page++)pages.push(page);
    if(end<total-1)pages.push('...');
    if(end<total)pages.push(total);

    return pages;
  }

  function renderPagination(){
    const nav=qs('#attendancePagination');
    const summary=qs('#attendancePaginationSummary');
    const p=attendancePagination;

    summary.textContent=p.total>0
      ?`Showing ${p.from}-${p.to} of ${p.total} attendance sessions`
      :'Showing 0 attendance sessions';

    const buttons=[];
    buttons.push(`<button type="button" class="attendance-page-btn" data-page="${p.page-1}" ${p.page<=1?'disabled':''}>Previous</button>`);

    pageWindow(p.page,p.total_pages).forEach(page=>{
      if(page==='...'){
        buttons.push('<span class="attendance-page-ellipsis">...</span>');
      }else{
        buttons.push(`<button type="button" class="attendance-page-btn ${page===p.page?'active':''}" data-page="${page}">${page}</button>`);
      }
    });

    buttons.push(`<button type="button" class="attendance-page-btn" data-page="${p.page+1}" ${p.page>=p.total_pages?'disabled':''}>Next</button>`);
    nav.innerHTML=buttons.join('');
  }

  function toggleDateRange(){
    const range=qs('#attendanceDateRange');
    const visible=qs('#attendancePeriod').value==='range';
    range.hidden=!visible;
  }

  function buildSessionQuery(){
    const params=new URLSearchParams({
      action:'list_sessions',
      page:String(attendancePage),
      per_page:qs('#attendancePerPage').value||'10',
      period:qs('#attendancePeriod').value||'all',
      search:(qs('#attendanceSearch').value||'').trim()
    });

    if(qs('#attendancePeriod').value==='range'){
      params.set('date_from',qs('#attendanceDateFrom').value||'');
      params.set('date_to',qs('#attendanceDateTo').value||'');
    }

    return params.toString();
  }

  async function loadSessions(resetPage=false){
    if(resetPage)attendancePage=1;

    clearAlert(qs('#attendanceAlert'));
    qs('#attendanceTableBody').innerHTML='<tr><td colspan="8" class="text-muted">Loading attendance sessions...</td></tr>';

    const data=await request(endpoint+'?'+buildSessionQuery());

    if(!data.success)throw new Error(data.message||'Failed to load.');

    sessions=data.items||[];
    attendancePagination=data.pagination||{page:1,per_page:10,total:0,total_pages:1,from:0,to:0};
    attendancePage=Number(attendancePagination.page)||1;

    renderStats(sessions);
    renderTable(sessions);
    renderPagination();
  }
  function renderMembers(){
    const t=(qs('#memberSearch').value||'').toLowerCase();
    const filtered=members.filter(m=>Object.values(m).join(' ').toLowerCase().includes(t));
    const box=qs('#membersList');
    if(!filtered.length){box.innerHTML='<div class="text-muted">No members found.</div>';return;}
    box.innerHTML=filtered.map(m=>`<label class="attendance-member-card">
      <input type="checkbox" name="present[]" value="${Number(m.id)}" ${m.attendance_status==='Present'?'checked':''}>
      <span><strong>${esc(m.full_name||'')}</strong><small>${esc(m.member_no||'-')} - ${esc(m.department||'No department')}</small><small>${esc(m.phone||'')}</small></span>
    </label>`).join('');
  }
  async function loadMembers(date='',type=''){
    qs('#membersList').innerHTML='<div class="text-muted">Loading members...</div>';
    const data=await request(endpoint+'?action=members&service_date='+encodeURIComponent(date)+'&service_type='+encodeURIComponent(type));
    if(!data.success)throw new Error(data.message||'Failed to load members.');
    members=data.items||[];renderMembers();
  }
  async function openMark(){
    qs('#attendanceForm').reset();clearAlert(qs('#formAlert'));qs('#attendanceFormTitle').textContent='Mark Attendance';
    qs('#service_date').value=new Date().toISOString().slice(0,10);
    await loadMembers(qs('#service_date').value,qs('#service_type').value);
    openModal('#markAttendanceModal');
  }
  async function openEdit(date,type){
    qs('#attendanceForm').reset();clearAlert(qs('#formAlert'));qs('#attendanceFormTitle').textContent='Edit Attendance';
    qs('#service_date').value=date;qs('#service_type').value=type;
    const data=await request(endpoint+'?action=get_session&date='+encodeURIComponent(date)+'&type='+encodeURIComponent(type));
    if(data.success && data.session) qs('#notes').value=data.session.notes||'';
    await loadMembers(date,type);
    openModal('#markAttendanceModal');
  }
  function renderViewRecords(){
    const t=(qs('#viewSearch').value||'').toLowerCase();
    const items=viewRecords.filter(r=>Object.values(r).join(' ').toLowerCase().includes(t));
    const tbody=qs('#viewAttendanceBody');
    if(!items.length){tbody.innerHTML='<tr><td colspan="6" class="text-muted">No attendance records found.</td></tr>';return;}
    tbody.innerHTML=items.map((r,i)=>`<tr><td>${i+1}</td><td>${esc(r.member_name)}</td><td>${esc(r.member_no||'')}</td><td>${esc(r.department||'')}</td><td>${esc(r.phone||'')}</td><td>${badge(r.status,r.status==='Present'?'badge-success':'badge-danger')}</td></tr>`).join('');
  }
  async function openView(date,type){
    const data=await request(endpoint+'?action=get_session&date='+encodeURIComponent(date)+'&type='+encodeURIComponent(type));
    if(!data.success)return showAlert(qs('#attendanceAlert'),data.message||'Session not found.','error');
    const s=data.session;viewRecords=data.records||[];
    qs('#viewSessionSummary').innerHTML=`<div class="attendance-mini-stat"><span>Date</span><strong>${esc(s.service_date)}</strong></div><div class="attendance-mini-stat"><span>Present</span><strong>${Number(s.present)||0}</strong></div><div class="attendance-mini-stat"><span>Absent</span><strong>${Number(s.absent)||0}</strong></div><div class="attendance-mini-stat"><span>Rate</span><strong>${Number(s.percentage)||0}%</strong></div>`;
    qs('#viewSearch').value='';renderViewRecords();openModal('#viewAttendanceModal');
  }

  // ---- Share Check-In Link ----
  function resetShareResult(){
    qs('#shareResult').style.display='none';
    clearAlert(qs('#shareAlert'));
  }
  function openShare(date,type){
    resetShareResult();
    qs('#share_service_date').value=date||new Date().toISOString().slice(0,10);
    if(type) qs('#share_service_type').value=type;
    openModal('#shareAttendanceModal');
  }
  async function generateShareLink(){
    clearAlert(qs('#shareAlert'));
    const date=qs('#share_service_date').value;
    const type=qs('#share_service_type').value;
    const hours=qs('#share_expiry').value;
    if(!date){return showAlert(qs('#shareAlert'),'Please choose a service date.','error');}
    try{
      const data=await request(shareEndpoint+'?action=generate&service_date='+encodeURIComponent(date)+'&service_type='+encodeURIComponent(type)+'&hours='+encodeURIComponent(hours)+'&csrf_token='+encodeURIComponent(csrfToken));
      if(!data.success)return showAlert(qs('#shareAlert'),data.message||'Could not generate link.','error');
      qs('#shareUrlInput').value=data.url;
      qs('#shareOpenLink').href=data.url;
      qs('#shareExpiryLabel').textContent=data.expires_at?('Expires '+data.expires_at):'No expiry';
      qs('#shareResult').style.display='block';
      qs('#shareQrImage').src='https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=2&data='+encodeURIComponent(data.url);
    }catch(err){
      showAlert(qs('#shareAlert'),err.message,'error');
    }
  }

  qs('#btnOpenMarkAttendance').addEventListener('click',()=>openMark().catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error')));

  let attendanceSearchTimer;
  qs('#attendanceSearch').addEventListener('input',()=>{
    clearTimeout(attendanceSearchTimer);
    attendanceSearchTimer=setTimeout(()=>{
      loadSessions(true).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
    },300);
  });

  qs('#attendancePeriod').addEventListener('change',()=>{
    toggleDateRange();
    loadSessions(true).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
  });

  qs('#attendanceDateFrom').addEventListener('change',()=>{
    if(qs('#attendancePeriod').value==='range'){
      loadSessions(true).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
    }
  });

  qs('#attendanceDateTo').addEventListener('change',()=>{
    if(qs('#attendancePeriod').value==='range'){
      loadSessions(true).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
    }
  });

  qs('#attendancePerPage').addEventListener('change',()=>{
    loadSessions(true).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
  });

  qs('#btnRefreshAttendance').addEventListener('click',()=>{
    qs('#attendanceSearch').value='';
    qs('#attendancePeriod').value='all';
    qs('#attendanceDateFrom').value='';
    qs('#attendanceDateTo').value='';
    qs('#attendancePerPage').value='10';
    toggleDateRange();
    loadSessions(true).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
  });

  qs('#attendancePagination').addEventListener('click',event=>{
    const button=event.target.closest('[data-page]');
    if(!button||button.disabled)return;
    attendancePage=Math.max(1,Number(button.dataset.page)||1);
    loadSessions(false).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
  });
  qs('#memberSearch').addEventListener('input',renderMembers);
  qs('#viewSearch').addEventListener('input',renderViewRecords);
  qs('#btnMarkAll').addEventListener('click',()=>qsa('#membersList input[type="checkbox"]').forEach(cb=>cb.checked=true));
  qs('#btnClearAll').addEventListener('click',()=>qsa('#membersList input[type="checkbox"]').forEach(cb=>cb.checked=false));
  qs('#service_date').addEventListener('change',()=>loadMembers(qs('#service_date').value,qs('#service_type').value).catch(err=>showAlert(qs('#formAlert'),err.message,'error')));
  qs('#service_type').addEventListener('change',()=>loadMembers(qs('#service_date').value,qs('#service_type').value).catch(err=>showAlert(qs('#formAlert'),err.message,'error')));
  qs('#attendanceBackdrop').addEventListener('click',closeModals);qsa('[data-close-attendance-modal]').forEach(b=>b.addEventListener('click',closeModals));document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
  document.addEventListener('click',e=>{const btn=e.target.closest('[data-action]');if(!btn)return;const d=btn.dataset.date,t=btn.dataset.type;if(btn.dataset.action==='view')openView(d,t);if(btn.dataset.action==='edit')openEdit(d,t);if(btn.dataset.action==='share')openShare(d,t);if(btn.dataset.action==='delete'){qs('#deleteDate').value=d;qs('#deleteType').value=t;qs('#deleteSessionLabel').textContent=t+' on '+d;clearAlert(qs('#deleteAlert'));openModal('#deleteAttendanceModal');}});
  qs('#attendanceForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return showAlert(qs('#formAlert'),data.message||'Save failed.','error');closeModals();showAlert(qs('#attendanceAlert'),data.message||'Saved successfully.');await loadSessions();}catch(err){showAlert(qs('#formAlert'),err.message,'error');}});
  qs('#deleteAttendanceForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return showAlert(qs('#deleteAlert'),data.message||'Delete failed.','error');closeModals();showAlert(qs('#attendanceAlert'),data.message||'Deleted successfully.');await loadSessions();}catch(err){showAlert(qs('#deleteAlert'),err.message,'error');}});

  qs('#btnOpenShare').addEventListener('click',()=>openShare(new Date().toISOString().slice(0,10),''));
  qs('#btnShareFromForm').addEventListener('click',()=>openShare(qs('#service_date').value,qs('#service_type').value));
  qs('#btnGenerateShareLink').addEventListener('click',generateShareLink);
  qs('#share_service_date').addEventListener('change',resetShareResult);
  qs('#share_service_type').addEventListener('change',resetShareResult);
  qs('#share_expiry').addEventListener('change',resetShareResult);
  qs('#btnCopyShareUrl').addEventListener('click',async ()=>{
    const input=qs('#shareUrlInput');
    try{
      await navigator.clipboard.writeText(input.value);
      showAlert(qs('#shareAlert'),'Link copied to clipboard.');
    }catch(e){
      input.select();
      showAlert(qs('#shareAlert'),'Press Ctrl+C / Cmd+C to copy the selected link.');
    }
  });

  toggleDateRange();
  loadSessions(false).catch(err=>showAlert(qs('#attendanceAlert'),err.message,'error'));
})();
</script>

<?php include '../includes/footer.php'; ?>