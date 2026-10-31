<?php
require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }
$pageTitle = 'Pledges';
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$conn->set_charset('utf8mb4');
function ph($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$hasMemberPledgeNo = (bool)$conn->query("SHOW COLUMNS FROM members LIKE 'pledge_no'")->num_rows;
$members = $conn->query("SELECT id, member_no, full_name, " . ($hasMemberPledgeNo ? "pledge_no" : "'' AS pledge_no") . " FROM members ORDER BY full_name");
$projects = $conn->query("SELECT id, name FROM projects WHERE COALESCE(status,'Active')='Active' ORDER BY name");
require_once __DIR__ . '/../includes/pledge-types.php';
$typesReady = pledge_types_ready($conn);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$events = $typesReady && $conn->query("SHOW TABLES LIKE 'events'")->num_rows
    ? $conn->query("SELECT id, title, event_date FROM events ORDER BY event_date DESC")
    : null;
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="main-content pledges-module">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<style>.pledges-module .stat-card-click{font:inherit;text-align:left;width:100%;cursor:pointer}.pledges-module .stat-card-click:focus-visible{outline:3px solid #f0b429;outline-offset:2px}.pledges-module .stat-card-click .stat-label,.pledges-module .stat-card-click .stat-value{color:#fff}</style>
<?php if (function_exists('showFlash')) { showFlash(); } ?>
<div class="page-header module-page-header">
  <div>
    <h2><i class="fas fa-handshake text-primary"></i> Pledges</h2>
    <p class="text-muted">Manage pledges, payments, PDF receipts, and reminders.</p>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-primary" id="btnImportPledges"><i class="fas fa-file-import"></i> Import Excel</button>
    <button type="button" class="btn btn-outline-primary" id="btnUploadPaidCsv"><i class="fas fa-file-csv"></i> Upload Paid CSV</button>
    <button type="button" class="btn btn-outline" id="btnSendReminders"><i class="fas fa-bell"></i> Send Reminders</button>
    <button type="button" class="btn btn-outline-primary" id="btnBulkPledges"><i class="fas fa-layer-group"></i> Multiple Pledges</button>
    <button type="button" class="btn btn-primary" id="btnAddPledge"><i class="fas fa-plus"></i> Record Pledge</button>
  </div>
</div>

<div class="stats-grid module-stats-grid">
  <button type="button" class="stat-card stat-card-click" data-stat-action="all"><div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div><div><div class="stat-value" id="stTotal">UGX 0</div><div class="stat-label">Total Pledged</div></div></button>
  <button type="button" class="stat-card success stat-card-click" data-stat-action="all"><div class="stat-icon"><i class="fas fa-coins"></i></div><div><div class="stat-value" id="stPaid">UGX 0</div><div class="stat-label">Total Paid</div></div></button>
  <button type="button" class="stat-card accent stat-card-click" data-stat-status="Pending"><div class="stat-icon"><i class="fas fa-clock"></i></div><div><div class="stat-value" id="stPending">0</div><div class="stat-label">Pending</div></div></button>
  <button type="button" class="stat-card danger stat-card-click" data-stat-action="overdue"><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div><div><div class="stat-value" id="stOverdue">0</div><div class="stat-label">Overdue</div></div></button>
  <button type="button" class="stat-card info stat-card-click" data-stat-status="Partial"><div class="stat-icon"><i class="fas fa-adjust"></i></div><div><div class="stat-value" id="stPartial">0</div><div class="stat-label">Partial</div></div></button>
  <button type="button" class="stat-card success stat-card-click" data-stat-status="Fulfilled"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div><div class="stat-value" id="stFulfilled">0</div><div class="stat-label">Fulfilled</div></div></button>
  <button type="button" class="stat-card stat-card-click" data-stat-year="<?= (int)date('Y') ?>"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div><div><div class="stat-value" id="stThisYear">UGX 0</div><div class="stat-label">Pledged This Year (<?= (int)date('Y') ?>)</div></div></button>
  <button type="button" class="stat-card stat-card-click" data-stat-year="<?= (int)date('Y')-1 ?>"><div class="stat-icon"><i class="fas fa-calendar-minus"></i></div><div><div class="stat-value" id="stLastYear">UGX 0</div><div class="stat-label">Pledged Last Year (<?= (int)date('Y')-1 ?>)</div></div></button>
</div>

<div class="card">
  <div class="card-body">
    <div class="page-toolbar">
      <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="text" class="form-control" id="pledgeSearch" placeholder="Search pledge, member, project..."></div>
      <div class="page-toolbar-filters">
      <select id="pledgeYearFilter" class="form-control" aria-label="Filter pledges by year"><option value="">All years</option><?php for($year=(int)date('Y')+1;$year>=2000;$year--): ?><option value="<?= $year ?>"<?= $year===(int)date('Y')?' selected':'' ?>><?= $year ?></option><?php endfor; ?></select>
      <select id="pledgeTypeFilter" class="form-control" aria-label="Filter by pledge type"><option value="">All pledge types</option><?php if ($typesReady): foreach(pledge_types() as $t): ?><option value="<?= ph($t) ?>"><?= ph($t==='Annual'?'Annual Pledge':$t) ?></option><?php endforeach; else: ?><option value="Annual">Annual Pledge</option><?php endif; ?></select>
      <select id="pledgeStatusFilter" class="form-control" aria-label="Filter by pledge status"><option value="">All statuses</option><?php foreach(['Pending','Partial','Fulfilled','Cancelled'] as $s): ?><option value="<?= ph($s) ?>"><?= ph($s) ?></option><?php endforeach; ?></select>
      </div>
      <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshPledges"><i class="fas fa-rotate-right"></i> Refresh</button></div>
    </div>
    <div id="pageAlert" class="ajax-alert"></div>
    <div class="table-responsive">
      <table class="table module-table">
        <thead><tr><th>#</th><th>Pledge No</th><th>Member</th><th>Project/Reason</th><th>Pledged</th><th>Paid</th><th>Balance</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="pledgeTbody"><tr><td colspan="10" class="text-center text-muted">Loading pledges...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</div>
</div>

<div class="app-modal-backdrop" id="pledgeBackdrop"></div>

<div class="app-modal" id="pledgeImportModal" aria-hidden="true"><div class="app-dialog"><form id="pledgeImportForm" enctype="multipart/form-data">
  <div class="app-modal-header"><h3><i class="fas fa-file-export"></i> Prepare Annual Pledge Files</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="importAlert" class="ajax-alert"></div><p>Compare the pledges workbook against registered members. This creates reviewable CSV files for unmatched members and recorded pledge payments; it does not change the database.</p><div class="form-group"><label>Excel workbook (.xlsx) <span class="req">*</span></label><input type="file" name="pledge_workbook" class="form-control" accept=".xlsx" required></div></div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Cancel</button><button class="btn btn-primary" id="importSubmit"><i class="fas fa-file-download"></i> Prepare Files</button></div>
</form></div></div>

<div class="app-modal" id="paidCsvModal" aria-hidden="true"><div class="app-dialog"><form id="paidCsvForm" enctype="multipart/form-data">
  <div class="app-modal-header"><h3><i class="fas fa-file-csv"></i> Upload Prepared Paid Pledges</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="paidCsvAlert" class="ajax-alert"></div><p>Upload the <code>paid_pledges_YEAR.csv</code> file prepared from the workbook. Import missing members first so their pledge numbers exist. Existing annual pledges and payments are matched by pledge number and year; duplicate dated payments are skipped.</p><div class="form-group"><label>Prepared paid pledges CSV <span class="req">*</span></label><input type="file" name="paid_csv" class="form-control" accept=".csv,text/csv" required></div></div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Cancel</button><button class="btn btn-primary" id="paidCsvSubmit"><i class="fas fa-upload"></i> Upload Payments</button></div>
</form></div></div>

<div class="app-modal" id="pledgeFormModal" aria-hidden="true"><div class="app-dialog app-dialog-lg"><form id="pledgeForm">
  <div class="app-modal-header"><h3 id="pledgeFormTitle"><i class="fas fa-handshake"></i> Record Pledge</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="formAlert" class="ajax-alert"></div><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="pledgeId">
    <div class="form-grid">
      <div class="form-group"><label for="memberSearch">Member <span class="req">*</span></label><input type="search" id="memberSearch" class="form-control mb-2" placeholder="Search name, member no or pledge no" autocomplete="off" aria-describedby="memberSearchHelp"><select name="member_id" id="member_id" class="form-control" required><option value="">Select Member</option><?php while($m=$members?$members->fetch_assoc():null): ?><option value="<?= (int)$m['id'] ?>" data-pledge-no="<?= ph(trim((string)$m['pledge_no'])) ?>" data-member-no="<?= ph(trim((string)$m['member_no'])) ?>"><?= ph(trim($m['full_name'])) ?><?= !empty($m['member_no'])?' ('.ph($m['member_no']).')':'' ?></option><?php endwhile; ?></select><small id="memberSearchHelp" class="text-muted"></small></div>
      <div class="form-group"><label>Pledge No</label><input type="text" id="memberPledgeNo" class="form-control" readonly placeholder="Select a member"><small id="memberPledgeNoHelp" class="text-muted">Taken from the member's record.</small></div>
<?php if ($typesReady): ?>
      <div class="form-group"><label>Pledge For <span class="req">*</span></label><select name="pledge_type" id="pledge_type" class="form-control" required><option value="">Select what the pledge is for</option><?php foreach (pledge_types() as $t): ?><option value="<?= ph($t) ?>"><?= ph($t === 'Annual' ? 'Annual Pledge' : ($t === 'Other' ? 'Other (specify)' : $t)) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" data-pledge-for="Annual"><label>Year <span class="req">*</span></label><input type="number" name="pledge_year" id="pledge_year" class="form-control" min="2000" max="2100" step="1"></div>
      <div class="form-group" data-pledge-for="Event"><label>Event <span class="req">*</span></label><select name="event_id" id="event_id" class="form-control"><option value="">Select Event</option><?php while($ev=$events?$events->fetch_assoc():null): ?><option value="<?= (int)$ev['id'] ?>"><?= ph($ev['title']) ?><?= !empty($ev['event_date'])?' ('.ph($ev['event_date']).')':'' ?></option><?php endwhile; ?></select></div>
<?php endif; ?>
      <div class="form-group"<?= $typesReady ? ' data-pledge-for="Project"' : '' ?>><label>Project<?= $typesReady ? ' <span class="req">*</span>' : '' ?></label><select name="project_id" id="project_id" class="form-control"><option value=""><?= $typesReady ? 'Select Project' : 'General Pledge' ?></option><?php while($p=$projects?$projects->fetch_assoc():null): ?><option value="<?= (int)$p['id'] ?>"><?= ph($p['name']) ?></option><?php endwhile; ?></select></div>
      <div class="form-group full-span"><label id="reasonLabel">Pledge Reason / Description</label><input type="text" name="pledge_reason" id="pledge_reason" class="form-control"></div>
      <div class="form-group"><label>Amount Pledged <span class="req">*</span></label><input type="number" name="amount_pledged" id="amount_pledged" class="form-control" min="1" step="0.01" required></div>
      <div class="form-group"><label>Pledge Date <span class="req">*</span></label><input type="date" name="pledge_date" id="pledge_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-group"><label>Due Date</label><input type="date" name="due_date" id="due_date" class="form-control"></div>
      <div class="form-group"><label>Status</label><select name="status" id="status" class="form-control"><option>Pending</option><option>Partial</option><option>Fulfilled</option><option>Cancelled</option></select></div>
      <div class="form-group full-span"><label>Notes</label><textarea name="notes" id="notes" class="form-control" rows="3"></textarea></div>
    </div>
  </div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Cancel</button><button class="btn btn-primary"><i class="fas fa-save"></i> Save Pledge</button></div>
</form></div></div>

<div class="app-modal" id="bulkPledgeModal" aria-hidden="true"><div class="app-dialog app-dialog-lg"><form id="bulkPledgeForm">
  <div class="app-modal-header"><h3><i class="fas fa-layer-group"></i> Record Multiple Pledges</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="bulkPledgeAlert" class="ajax-alert"></div><p>Add several pledge entries and save them together. Entries can belong to different members.</p><div id="bulkPledgeRows"></div><button type="button" class="btn btn-outline-primary" id="addBulkPledgeRow"><i class="fas fa-plus"></i> Add another entry</button></div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Cancel</button><button class="btn btn-primary"><i class="fas fa-save"></i> Save all entries</button></div>
</form></div></div>

<div class="app-modal" id="pledgeViewModal" aria-hidden="true"><div class="app-dialog app-dialog-lg">
  <div class="app-modal-header"><h3><i class="fas fa-eye"></i> Pledge Details</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="viewBox" class="detail-grid"></div><hr><h4>Payment History</h4><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Method</th><th>Actions</th></tr></thead><tbody id="paymentRows"></tbody></table></div></div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Close</button><button type="button" class="btn btn-success" id="viewPayBtn"><i class="fas fa-coins"></i> Record Payment</button></div>
</div></div>

<div class="app-modal" id="pledgePayModal" aria-hidden="true"><div class="app-dialog"><form id="payForm">
  <div class="app-modal-header"><h3 id="payTitle"><i class="fas fa-money-bill-wave"></i> Record Payment</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="payAlert" class="ajax-alert"></div><input type="hidden" name="action" id="payAction" value="pay"><input type="hidden" name="pledge_id" id="payPledgeId"><input type="hidden" name="payment_id" id="payPaymentId"><p id="paySummary" class="text-muted"></p>
    <div class="form-grid"><div class="form-group"><label>Amount <span class="req">*</span></label><input type="number" name="amount" id="payAmount" class="form-control" min="1" step="0.01" required></div><div class="form-group"><label>Date Paid</label><input type="date" name="paid_on" id="payDate" class="form-control" value="<?= date('Y-m-d') ?>" required></div><div class="form-group"><label>Method</label><select name="method" id="payMethod" class="form-control"><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option><option>Other</option></select></div><div class="form-group"><label>Notes</label><input type="text" name="notes" id="payNotes" class="form-control"></div></div>
  </div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Cancel</button><button class="btn btn-success" id="paySubmit"><i class="fas fa-save"></i> Record Payment</button></div>
</form></div></div>

<div class="app-modal" id="pledgeDeleteModal" aria-hidden="true"><div class="app-dialog"><form id="deleteForm">
  <div class="app-modal-header"><h3><i class="fas fa-trash"></i> Delete / Cancel Pledge</h3><button type="button" class="modal-close" data-close-pledge>&times;</button></div>
  <div class="app-modal-body"><div id="deleteAlert" class="ajax-alert"></div><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"><p>Delete or cancel <strong id="deleteName"></strong>?</p><p class="text-muted">If payments exist, the pledge will be cancelled instead of permanently deleted.</p></div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-pledge>Cancel</button><button class="btn btn-danger"><i class="fas fa-trash"></i> Confirm</button></div>
</form></div></div>

<script>
window.PledgesConfig = { endpoint: 'process-pledges.php', csrfToken: <?= json_encode((string)$_SESSION['csrf_token']) ?> };
(function(){
'use strict';
const cfg = window.PledgesConfig; if(!cfg) return;
let rows = [], viewPledge = null, viewPayments = {}, overdueOnly = false;
const qs=(s,r=document)=>r.querySelector(s), qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const money=v=>'UGX '+Number(v||0).toLocaleString();
function alertBox(el,msg,type='success'){ if(!el)return; el.className='ajax-alert '+(type==='error'?'error':'success'); el.style.whiteSpace='pre-line'; el.textContent=msg; }
function clearAlert(el){ if(el){el.className='ajax-alert'; el.textContent='';} }
function openModal(id){ qs('#pledgeBackdrop').classList.add('show'); qs(id).classList.add('show'); document.body.style.overflow='hidden'; }
function closeModals(){ qsa('.app-modal,.app-modal-backdrop').forEach(x=>x.classList.remove('show')); document.body.style.overflow=''; }
async function req(url,opts={}){ const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':cfg.csrfToken||''}},opts)); const txt=await res.text(); try{return JSON.parse(txt)}catch(e){throw new Error('Invalid server response: '+txt.slice(0,250));} }
async function post(form){ return req(cfg.endpoint,{method:'POST',body:new FormData(form)}); }
function badge(s){ const map={Pending:'badge-warning',Partial:'badge-info',Fulfilled:'badge-success',Cancelled:'badge-danger'}; return `<span class="badge ${map[s]||'badge-secondary'}">${esc(s||'—')}</span>`; }
function renderStats(st){ qs('#stTotal').textContent=money(st.total||0); qs('#stPaid').textContent=money(st.paid||0); qs('#stPending').textContent=Number(st.pending||0); qs('#stOverdue').textContent=Number(st.overdue||0); qs('#stPartial').textContent=Number(st.partial||0); qs('#stFulfilled').textContent=Number(st.fulfilled||0); qs('#stThisYear').textContent=money(st.this_year||0); qs('#stLastYear').textContent=money(st.last_year||0); }
function render(items){ const tb=qs('#pledgeTbody'); if(!items.length){ tb.innerHTML='<tr><td colspan="10" class="text-center text-muted">No pledges found.</td></tr>'; return; } tb.innerHTML=items.map((p,i)=>{ const balance=Number(p.amount_pledged||0)-Number(p.amount_paid||0); const purpose=p.purpose||p.project_name||p.pledge_reason||'General Pledge'; return `<tr><td>${i+1}</td><td><b>${esc(p.pledge_no)}</b></td><td>${esc(p.full_name||'—')}<br><small>${esc(p.member_no||'')}</small></td><td>${esc(purpose)}</td><td>${money(p.amount_pledged)}</td><td>${money(p.amount_paid)}</td><td>${money(balance)}</td><td>${esc(p.due_date||'—')}</td><td>${badge(p.status)}</td><td><div class="table-action"><button class="btn btn-sm btn-info btn-icon" data-action="view" data-id="${Number(p.id)}" title="View"><i class="fas fa-eye"></i></button><button class="btn btn-sm btn-primary btn-icon" data-action="edit" data-id="${Number(p.id)}" title="Edit"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-success btn-icon" data-action="pay" data-id="${Number(p.id)}" data-balance="${balance}" data-name="${esc(p.pledge_no)}" title="Pay"><i class="fas fa-coins"></i></button><a class="btn btn-sm btn-accent btn-icon" href="pledge_card.php?pledge_id=${Number(p.id)}" target="_blank" title="Pledge Card"><i class="fas fa-id-card"></i></a><button class="btn btn-sm btn-danger btn-icon" data-action="delete" data-id="${Number(p.id)}" data-name="${esc(p.pledge_no)}" title="Delete"><i class="fas fa-trash"></i></button></div></td></tr>`; }).join(''); }
function applySearch(){ const t=(qs('#pledgeSearch').value||'').toLowerCase(); render(rows.filter(r=>Object.values(r).join(' ').toLowerCase().includes(t))); }
async function load(){ clearAlert(qs('#pageAlert')); const d=await req(cfg.endpoint+'?action=list&status='+encodeURIComponent(qs('#pledgeStatusFilter').value)+'&year='+encodeURIComponent(qs('#pledgeYearFilter').value)+'&type='+encodeURIComponent(qs('#pledgeTypeFilter').value)+'&overdue='+(overdueOnly?'1':'0')); if(!d.success) throw new Error(d.message||'Failed to load pledges.'); rows=d.items||[]; renderStats(d.stats||{}); applySearch(); }
function showMemberPledgeNo(){ const opt=qs('#member_id').selectedOptions[0]; const no=opt&&opt.value?(opt.dataset.pledgeNo||''):''; qs('#memberPledgeNo').value=no; qs('#memberPledgeNoHelp').textContent=opt&&opt.value&&!no?'This member has no pledge number yet. Set it in Members before saving.':"Taken from the member's record."; qs('#memberPledgeNoHelp').className=opt&&opt.value&&!no?'text-danger':'text-muted'; }
qs('#member_id').addEventListener('change',showMemberPledgeNo);
// Member search: narrows the member list by name, member no or pledge no, and picks the member when the match is unique or exact.
const memberOptions=qsa('#member_id option').filter(o=>o.value).map(o=>({el:o,text:[o.textContent,o.dataset.memberNo,o.dataset.pledgeNo].join(' ').toLowerCase(),codes:[o.dataset.memberNo,o.dataset.pledgeNo].map(v=>(v||'').toLowerCase()).filter(Boolean)}));
function filterMembers(term){ const sel=qs('#member_id'), t=String(term||'').trim().toLowerCase(), current=sel.value; const matches=t?memberOptions.filter(m=>m.text.includes(t)):memberOptions; sel.length=1; matches.forEach(m=>sel.appendChild(m.el)); const exact=t?matches.find(m=>m.codes.includes(t)):null; const pick=exact||(matches.length===1?matches[0]:null); sel.value=pick?pick.el.value:(matches.some(m=>m.el.value===current)?current:''); const help=qs('#memberSearchHelp'); help.textContent=t?(matches.length?`${matches.length} member${matches.length===1?'':'s'} found.`:'No member matches that name, member no or pledge no.'):''; help.className=t&&!matches.length?'text-danger':'text-muted'; showMemberPledgeNo(); }
qs('#memberSearch').addEventListener('input',e=>filterMembers(e.target.value));
qs('#memberSearch').addEventListener('keydown',e=>{ if(e.key!=='Enter')return; e.preventDefault(); const sel=qs('#member_id'); if(!sel.value&&sel.length>1){ sel.selectedIndex=1; showMemberPledgeNo(); } });
function payMode(editing){ qs('#payForm').reset(); qs('#payAmount').removeAttribute('max'); qs('#payAction').value=editing?'update_payment':'pay'; qs('#payPaymentId').value=''; qs('#payDate').value=new Date().toISOString().slice(0,10); qs('#payTitle').innerHTML=editing?'<i class="fas fa-edit"></i> Edit Payment':'<i class="fas fa-money-bill-wave"></i> Record Payment'; qs('#paySubmit').innerHTML=editing?'<i class="fas fa-save"></i> Save Changes':'<i class="fas fa-save"></i> Record Payment'; clearAlert(qs('#payAlert')); }
function syncPledgeType(){ const sel=qs('#pledge_type'); if(!sel) return; const t=sel.value; qsa('[data-pledge-for]').forEach(el=>{ const on=el.dataset.pledgeFor===t; el.style.display=on?'':'none'; const input=el.querySelector('input,select'); if(input) input.required=on; }); const reason=qs('#pledge_reason'); const label={ 'Harvest Items':'Harvest Items Pledged <span class="req">*</span>', 'Other':'Specify What the Pledge Is For <span class="req">*</span>' }[t]; qs('#reasonLabel').innerHTML=label||'Details (optional)'; reason.required=!!label; reason.placeholder=t==='Harvest Items'?'e.g. 2 goats and 1 bag of beans':''; }
if(qs('#pledge_type')) qs('#pledge_type').addEventListener('change',syncPledgeType);
function resetForm(){ qs('#pledgeForm').reset(); filterMembers(''); qs('#pledgeId').value=''; if(qs('#pledge_year')) qs('#pledge_year').value=new Date().getFullYear(); syncPledgeType(); qs('#pledgeFormTitle').innerHTML='<i class="fas fa-handshake"></i> Record Pledge'; clearAlert(qs('#formAlert')); }
async function openView(id){ const d=await req(cfg.endpoint+'?action=get&id='+encodeURIComponent(id)); if(!d.success) return alertBox(qs('#pageAlert'),d.message||'Not found','error'); const p=d.item; const purpose=p.purpose||p.project_name||p.pledge_reason||'General Pledge'; qs('#viewBox').innerHTML=[['Pledge No',p.pledge_no],['Member',p.full_name],['Pledge For',p.pledge_type||'—'],['Purpose',purpose],['Amount Pledged',money(p.amount_pledged)],['Amount Paid',money(p.amount_paid)],['Balance',money(Number(p.amount_pledged||0)-Number(p.amount_paid||0))],['Pledge Date',p.pledge_date],['Due Date',p.due_date||'—'],['Status',p.status],['Email',p.email||'—'],['Phone',p.phone||'—'],['Notes',p.notes||'—']].map(x=>`<div class="detail-item"><b>${esc(x[0])}</b><span>${esc(x[1])}</span></div>`).join(''); const payments=d.payments||[]; viewPledge=p; viewPayments={}; payments.forEach(pay=>{viewPayments[pay.id]=pay;}); qs('#viewPayBtn').style.display=(p.status!=='Cancelled'&&Number(p.amount_pledged||0)-Number(p.amount_paid||0)>0)?'':'none'; qs('#paymentRows').innerHTML=payments.length?payments.map(pay=>`<tr><td>${esc(pay.paid_on)}</td><td>${esc(pay.receipt_no||'—')}</td><td>${money(pay.amount)}</td><td>${esc(pay.method||'—')}</td><td><a class="btn btn-sm btn-primary" href="receipt.php?payment_id=${Number(pay.id)}" target="_blank"><i class="fas fa-receipt"></i> Receipt</a> <button class="btn btn-sm btn-outline" data-action="email_receipt" data-payment-id="${Number(pay.id)}" title="Email receipt"><i class="fas fa-envelope"></i></button> <button class="btn btn-sm btn-warning" data-action="edit_payment" data-payment-id="${Number(pay.id)}" title="Edit payment"><i class="fas fa-edit"></i></button> <button class="btn btn-sm btn-danger" data-action="delete_payment" data-payment-id="${Number(pay.id)}" data-receipt="${esc(pay.receipt_no||'')}" title="Delete payment"><i class="fas fa-trash"></i></button></td></tr>`).join(''):'<tr><td colspan="5" class="text-muted">No payments recorded.</td></tr>'; openModal('#pledgeViewModal'); }
async function openEdit(id){ const d=await req(cfg.endpoint+'?action=get&id='+encodeURIComponent(id)); if(!d.success) return alertBox(qs('#pageAlert'),d.message||'Not found','error'); const p=d.item; resetForm(); qs('#pledgeId').value=p.id; qs('#member_id').value=p.member_id||''; showMemberPledgeNo(); qs('#project_id').value=p.project_id||''; if(qs('#pledge_type')){ qs('#pledge_type').value=p.pledge_type||''; qs('#pledge_year').value=p.pledge_year||new Date().getFullYear(); qs('#event_id').value=p.event_id||''; } qs('#pledge_reason').value=p.pledge_reason||''; qs('#amount_pledged').value=p.amount_pledged||''; qs('#pledge_date').value=p.pledge_date||''; qs('#due_date').value=p.due_date||''; qs('#status').value=p.status||'Pending'; qs('#notes').value=p.notes||''; syncPledgeType(); qs('#pledgeFormTitle').innerHTML='<i class="fas fa-edit"></i> Edit Pledge'; openModal('#pledgeFormModal'); }
 qs('#viewPayBtn').addEventListener('click',()=>{ if(!viewPledge)return; const balance=Number(viewPledge.amount_pledged||0)-Number(viewPledge.amount_paid||0); payMode(false); qs('#payPledgeId').value=viewPledge.id; qs('#payAmount').max=balance; qs('#paySummary').textContent='Pledge: '+(viewPledge.pledge_no||'')+' | Balance: '+money(balance); closeModals(); openModal('#pledgePayModal'); });
qs('#btnAddPledge').addEventListener('click',()=>{resetForm();openModal('#pledgeFormModal');});
 qs('#pledgeBackdrop').addEventListener('click',closeModals); qsa('[data-close-pledge]').forEach(b=>b.addEventListener('click',closeModals)); document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
 qs('#btnImportPledges').addEventListener('click',()=>{qs('#pledgeImportForm').reset();clearAlert(qs('#importAlert'));openModal('#pledgeImportModal');});
  function downloadCsv(filename,rows){const csv=rows.map(row=>row.map(v=>'"'+String(v??'').replace(/"/g,'""')+'"').join(',')).join('\r\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'}));a.download=filename;a.click();URL.revokeObjectURL(a.href);}
  qs('#pledgeImportForm').addEventListener('submit',async e=>{e.preventDefault();const btn=qs('#importSubmit');btn.disabled=true;try{const fd=new FormData(e.currentTarget);fd.append('action','prepare_pledges');const d=await req(cfg.endpoint,{method:'POST',body:fd});if(!d.success)return alertBox(qs('#importAlert'),d.message,'error');const year=d.year||'annual';downloadCsv('missing_members_'+year+'.csv',d.missing_members||[]);downloadCsv('paid_pledges_'+year+'.csv',d.paid_entries||[]);closeModals();let msg=d.message||'Files prepared.';if(d.skipped&&d.skipped.length)msg+=' Review: '+d.skipped.slice(0,5).join(' | ');alertBox(qs('#pageAlert'),msg,d.skipped&&d.skipped.length?'error':'success');}catch(err){alertBox(qs('#importAlert'),err.message,'error');}finally{btn.disabled=false;}});
  qs('#btnUploadPaidCsv').addEventListener('click',()=>{qs('#paidCsvForm').reset();clearAlert(qs('#paidCsvAlert'));openModal('#paidCsvModal');});
  qs('#paidCsvForm').addEventListener('submit',async e=>{e.preventDefault();const btn=qs('#paidCsvSubmit');btn.disabled=true;try{const fd=new FormData(e.currentTarget);fd.append('action','import_prepared_paid_csv');const d=await req(cfg.endpoint,{method:'POST',body:fd});const skipped=d.skipped||[];const msg=(d.message||'')+(skipped.length?'\n\nRows not imported:\n• '+skipped.join('\n• '):'');if(!d.success)return alertBox(qs('#paidCsvAlert'),msg,'error');closeModals();alertBox(qs('#pageAlert'),msg,skipped.length?'error':'success');await load();}catch(err){alertBox(qs('#paidCsvAlert'),err.message,'error');}finally{btn.disabled=false;}});
 qs('#pledgeSearch').addEventListener('input',applySearch); ['#pledgeYearFilter','#pledgeTypeFilter','#pledgeStatusFilter'].forEach(s=>qs(s).addEventListener('change',()=>{overdueOnly=false;load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));}));
 qs('#btnRefreshPledges').addEventListener('click',()=>{qs('#pledgeSearch').value='';qs('#pledgeYearFilter').value='';qs('#pledgeTypeFilter').value='';qs('#pledgeStatusFilter').value='';overdueOnly=false;load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));});
 qsa('.stat-card-click').forEach(card=>card.addEventListener('click',()=>{overdueOnly=card.dataset.statAction==='overdue';if(card.dataset.statStatus)qs('#pledgeStatusFilter').value=card.dataset.statStatus;else if(card.dataset.statAction==='all'||card.dataset.statYear||overdueOnly)qs('#pledgeStatusFilter').value='';if(card.dataset.statYear){qs('#pledgeYearFilter').value=card.dataset.statYear;qs('#pledgeTypeFilter').value='';}load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));}));
 const bulkRows=qs('#bulkPledgeRows');
 function addBulkRow(){const wrap=document.createElement('div');wrap.className='form-grid bulk-pledge-row';wrap.style.cssText='padding:12px 0;border-bottom:1px solid #e5e7eb;align-items:end';const opts=memberOptions.map(({el:o})=>`<option value="${Number(o.value)}">${esc(o.textContent)}</option>`).join('');wrap.innerHTML=`<div class="form-group"><label>Member</label><select class="form-control bulk-member" required><option value="">Select member</option>${opts}</select></div><div class="form-group"><label>Amount pledged</label><input type="number" class="form-control bulk-amount" min="0.01" step="0.01" required></div><div class="form-group"><label>Reason / purpose</label><input type="text" class="form-control bulk-reason" placeholder="General pledge"></div><div class="form-group"><label>Pledge date</label><input type="date" class="form-control bulk-date" value="${new Date().toISOString().slice(0,10)}" required></div><div class="form-group"><label>Notes</label><input type="text" class="form-control bulk-notes"></div><div><button type="button" class="btn btn-sm btn-danger bulk-remove" title="Remove entry"><i class="fas fa-trash"></i></button></div>`;wrap.querySelector('.bulk-remove').addEventListener('click',()=>{if(bulkRows.children.length>1)wrap.remove();});bulkRows.appendChild(wrap);}
 qs('#btnBulkPledges').addEventListener('click',()=>{bulkRows.innerHTML='';addBulkRow();clearAlert(qs('#bulkPledgeAlert'));openModal('#bulkPledgeModal');});
 qs('#addBulkPledgeRow').addEventListener('click',addBulkRow);
 qs('#bulkPledgeForm').addEventListener('submit',async e=>{e.preventDefault();const entries=qsa('.bulk-pledge-row',bulkRows).map(r=>({member_id:r.querySelector('.bulk-member').value,amount_pledged:r.querySelector('.bulk-amount').value,pledge_reason:r.querySelector('.bulk-reason').value,pledge_date:r.querySelector('.bulk-date').value,notes:r.querySelector('.bulk-notes').value}));const fd=new FormData();fd.append('action','save_bulk');fd.append('entries',JSON.stringify(entries));try{const d=await req(cfg.endpoint,{method:'POST',body:fd});if(!d.success)return alertBox(qs('#bulkPledgeAlert'),d.message,'error');closeModals();alertBox(qs('#pageAlert'),d.message);await load();}catch(err){alertBox(qs('#bulkPledgeAlert'),err.message,'error');}});
document.addEventListener('click',e=>{ const b=e.target.closest('[data-action]'); if(!b)return; const a=b.dataset.action,id=b.dataset.id; if(a==='view')openView(id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error')); if(a==='edit')openEdit(id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error')); if(a==='pay'){ payMode(false); qs('#payPledgeId').value=id; qs('#payAmount').max=b.dataset.balance||''; qs('#paySummary').textContent='Pledge: '+(b.dataset.name||'')+' | Balance: '+money(b.dataset.balance||0); clearAlert(qs('#payAlert')); openModal('#pledgePayModal'); } if(a==='delete'){ qs('#deleteId').value=id; qs('#deleteName').textContent=b.dataset.name||'this pledge'; clearAlert(qs('#deleteAlert')); openModal('#pledgeDeleteModal'); } if(a==='edit_payment'){ const pay=viewPayments[b.dataset.paymentId]; if(!pay||!viewPledge)return; payMode(true); qs('#payPaymentId').value=pay.id; qs('#payPledgeId').value=viewPledge.id; qs('#payAmount').value=pay.amount; qs('#payDate').value=pay.paid_on||''; qs('#payMethod').value=pay.method||'Cash'; qs('#payNotes').value=pay.notes||''; const room=Number(viewPledge.amount_pledged||0)-Number(viewPledge.amount_paid||0)+Number(pay.amount||0); qs('#payAmount').max=room; qs('#paySummary').textContent='Pledge: '+(viewPledge.pledge_no||'')+' | Receipt: '+(pay.receipt_no||pay.id)+' | Most this payment can be: '+money(room); closeModals(); openModal('#pledgePayModal'); }
 if(a==='delete_payment'){ if(!confirm('Delete payment '+(b.dataset.receipt||'')+'? The pledge balance will be recalculated.'))return; const fd=new FormData(); fd.append('action','delete_payment'); fd.append('payment_id',b.dataset.paymentId); req(cfg.endpoint,{method:'POST',body:fd}).then(async d=>{ if(!d.success)return alertBox(qs('#pageAlert'),d.message,'error'); closeModals(); alertBox(qs('#pageAlert'),d.message); await load(); if(d.pledge_id) await openView(d.pledge_id); }).catch(err=>alertBox(qs('#pageAlert'),err.message,'error')); }
 if(a==='email_receipt'){ const fd=new FormData(); fd.append('action','send_receipt'); fd.append('payment_id',b.dataset.paymentId); req(cfg.endpoint,{method:'POST',body:fd}).then(d=>alertBox(qs('#pageAlert'),d.message,d.success?'success':'error')).catch(err=>alertBox(qs('#pageAlert'),err.message,'error')); }});
qs('#pledgeForm').addEventListener('submit',async e=>{e.preventDefault(); try{const d=await post(e.currentTarget); if(!d.success)return alertBox(qs('#formAlert'),d.message,'error'); closeModals(); alertBox(qs('#pageAlert'),d.message); await load();}catch(err){alertBox(qs('#formAlert'),err.message,'error');}});
qs('#payForm').addEventListener('submit',async e=>{e.preventDefault(); try{const editing=qs('#payAction').value==='update_payment'; const d=await post(e.currentTarget); if(!d.success)return alertBox(qs('#payAlert'),d.message,'error'); closeModals(); if(editing){ alertBox(qs('#pageAlert'),d.message); await load(); if(d.pledge_id) await openView(d.pledge_id); return; } alertBox(qs('#pageAlert'),d.message||'Payment saved'); if(d.payment_id){ const a=document.createElement('a'); a.href='receipt.php?payment_id='+Number(d.payment_id); a.target='_blank'; a.textContent='Open receipt'; qs('#pageAlert').append(' ',a); } await load();}catch(err){alertBox(qs('#payAlert'),err.message,'error');}});
qs('#deleteForm').addEventListener('submit',async e=>{e.preventDefault(); try{const d=await post(e.currentTarget); if(!d.success)return alertBox(qs('#deleteAlert'),d.message,'error'); closeModals(); alertBox(qs('#pageAlert'),d.message); await load();}catch(err){alertBox(qs('#deleteAlert'),err.message,'error');}});
qs('#btnSendReminders').addEventListener('click',async()=>{ if(!confirm('Send pledge reminders by email/SMS to all unpaid pledge members?'))return; const fd=new FormData(); fd.append('action','send_reminders'); try{const d=await req(cfg.endpoint,{method:'POST',body:fd}); alertBox(qs('#pageAlert'),`${d.message} Emails: ${d.emails_sent||0}, SMS: ${d.sms_sent||0}`,d.success?'success':'error');}catch(err){alertBox(qs('#pageAlert'),err.message,'error');} });
load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
})();
</script>
<?php include '../includes/footer.php'; ?>
