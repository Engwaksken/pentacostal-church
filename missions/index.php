<?php
require_once '../config/config.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function msh($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ms_page_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

$hasTable = ms_page_table_exists($conn, 'missions') && ms_page_table_exists($conn, 'mission_team') && ms_page_table_exists($conn, 'mission_finance');
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

$types = ['Evangelism', 'Crusade', 'Medical Outreach', 'Community Service', 'School Mission', 'Prison Visit', 'Hospital Visit', 'Church Planting', 'Other'];
$statuses = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
$roles = ['Team Leader', 'Preacher', 'Counsellor', 'Worship', 'Intercessor', 'Medical', 'Logistics', 'Team Member'];
$incomeCategories = ['Offering', 'Pledge', 'Donation', 'Church Allocation', 'Fundraising', 'Other'];
$expenseCategories = ['Transport', 'Accommodation', 'Meals', 'Materials & Bibles', 'Sound & Equipment', 'Medical Supplies', 'Allowances', 'Other'];

$members = [];
if (ms_page_table_exists($conn, 'members')) {
    $res = $conn->query("SELECT id, member_no, full_name FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name");
    while ($res && $r = $res->fetch_assoc()) $members[] = $r;
}
function ms_member_options(array $members) {
    foreach ($members as $m) {
        echo '<option value="' . (int)$m['id'] . '">' . msh(trim($m['full_name'])) . (!empty($m['member_no']) ? ' (' . msh($m['member_no']) . ')' : '') . '</option>';
    }
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2><i class="fas fa-globe-africa"></i> Missions &amp; Outreach</h2>
        <p class="text-muted mb-0">Plan missions and outreaches, send teams, track money raised and spent, and record the results.</p>
    </div>
    <?php if ($hasTable): ?>
    <button type="button" class="btn btn-primary" id="btnOpenMission"><i class="fas fa-plus"></i> New Mission</button>
    <?php endif; ?>
</div>

<?php if (!$hasTable): ?>
<div class="card"><div class="card-body"><strong>Setup required:</strong> the missions tables were not found. Run <code>database/missions.sql</code>.</div></div>
<?php else: ?>

<div class="ms-stats-grid">
    <div class="ms-stat-card"><div class="ms-stat-icon"><i class="fas fa-globe-africa"></i></div><div><div class="ms-stat-value" id="statTotal">0</div><div class="ms-stat-label">Missions</div></div></div>
    <div class="ms-stat-card"><div class="ms-stat-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="ms-stat-value" id="statUpcoming">0</div><div class="ms-stat-label">Planned / Ongoing</div></div></div>
    <div class="ms-stat-card"><div class="ms-stat-icon"><i class="fas fa-cross"></i></div><div><div class="ms-stat-value" id="statSouls">0</div><div class="ms-stat-label">Souls Won</div></div></div>
    <div class="ms-stat-card"><div class="ms-stat-icon"><i class="fas fa-users"></i></div><div><div class="ms-stat-value" id="statReached">0</div><div class="ms-stat-label">People Reached</div></div></div>
    <div class="ms-stat-card"><div class="ms-stat-icon"><i class="fas fa-hand-holding-usd"></i></div><div><div class="ms-stat-value" id="statIncome">UGX 0</div><div class="ms-stat-label">Money Raised</div></div></div>
    <div class="ms-stat-card"><div class="ms-stat-icon"><i class="fas fa-receipt"></i></div><div><div class="ms-stat-value" id="statExpense">UGX 0</div><div class="ms-stat-label">Money Spent</div></div></div>
</div>

<div class="page-toolbar">
    <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="text" id="tableSearch" placeholder="Search mission, destination, leader..."></div>
    <div class="page-toolbar-filters">
        <select id="filterStatus" class="form-control"><option value="">All Statuses</option><?php foreach ($statuses as $s): ?><option><?= msh($s) ?></option><?php endforeach; ?></select>
        <select id="filterType" class="form-control"><option value="">All Types</option><?php foreach ($types as $t): ?><option><?= msh($t) ?></option><?php endforeach; ?></select>
    </div>
    <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefresh"><i class="fas fa-rotate-right"></i> Refresh</button>
    </div>
</div>
<div id="pageAlert" class="ms-alert"></div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th>#</th><th>Mission No</th><th>Mission</th><th>Destination</th><th>Dates</th><th>Leader</th><th>Team</th><th>Souls Won</th><th>Raised / Spent (UGX)</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody id="missionTableBody"><tr><td colspan="11" class="text-muted p-3">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="ms-modal-backdrop" id="msBackdrop"></div>

<!-- New / edit mission -->
<div class="ms-modal" id="missionModal" aria-hidden="true">
    <div class="ms-dialog">
        <form id="missionForm" data-form-tabs data-form-tabs-first="Mission Details">
            <div class="ms-modal-header"><div class="ms-modal-title" id="missionTitle"><i class="fas fa-plus"></i> New Mission</div><button type="button" class="ms-close" data-close-modal>&times;</button></div>
            <div class="ms-modal-body">
                <div id="formAlert" class="ms-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= msh($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="missionId" value="">
                <div class="ms-grid">
                    <div class="ms-field ms-full"><label>Mission Title <span class="text-danger">*</span></label><input type="text" name="title" id="title" maxlength="200" placeholder="e.g. Easter Crusade in Kasese" required></div>
                    <div class="ms-field"><label>Type</label><select name="mission_type" id="mission_type"><?php foreach ($types as $t): ?><option><?= msh($t) ?></option><?php endforeach; ?></select></div>
                    <div class="ms-field"><label>Destination</label><input type="text" name="destination" id="destination" maxlength="200" placeholder="Village, parish, school or partner church"></div>
                    <div class="ms-field"><label>Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" id="start_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="ms-field"><label>End Date</label><input type="date" name="end_date" id="end_date"></div>
                    <div class="ms-field"><label>Mission Leader</label><input type="search" class="ms-member-search" data-for="leader_member_id" placeholder="Search name or member no..." autocomplete="off"><select name="leader_member_id" id="leader_member_id"><option value="">Not assigned</option><?php ms_member_options($members); ?></select></div>
                    <div class="ms-field"><label>Budget (UGX)</label><input type="number" name="budget" id="budget" min="0" step="0.01" value="0"></div>
                    <div class="ms-field"><label>Status</label><select name="status" id="status"><?php foreach ($statuses as $s): ?><option><?= msh($s) ?></option><?php endforeach; ?></select></div>
                    <div class="ms-field ms-full"><label>Objectives</label><textarea name="objectives" id="objectives" rows="3" placeholder="What the mission aims to do"></textarea></div>
                    <div class="ms-full ms-section-label"><i class="fas fa-chart-line"></i> Results <small class="text-muted">(fill in during or after the mission)</small></div>
                    <div class="ms-field"><label>Souls Won</label><input type="number" name="souls_won" id="souls_won" min="0" step="1" value="0"></div>
                    <div class="ms-field"><label>Rededications</label><input type="number" name="rededications" id="rededications" min="0" step="1" value="0"></div>
                    <div class="ms-field"><label>People Reached</label><input type="number" name="people_reached" id="people_reached" min="0" step="1" value="0"></div>
                    <div class="ms-field ms-full"><label>Mission Report</label><textarea name="report" id="report" rows="4" placeholder="What happened, testimonies, follow-up needed"></textarea></div>
                </div>
            </div>
            <div class="ms-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
        </form>
    </div>
</div>

<!-- Mission details: team and finances -->
<div class="ms-modal" id="viewModal" aria-hidden="true">
    <div class="ms-dialog ms-dialog-lg">
        <div class="ms-modal-header"><div class="ms-modal-title" id="viewTitle"><i class="fas fa-globe-africa"></i> Mission</div><button type="button" class="ms-close" data-close-modal>&times;</button></div>
        <div class="ms-modal-body">
            <div id="viewAlert" class="ms-alert"></div>
            <div id="viewDetails" class="ms-detail-grid"></div>

            <h4 class="ms-heading"><i class="fas fa-users"></i> Team <span class="badge badge-info" id="teamCount">0</span></h4>
            <form id="teamForm" class="ms-inline-form">
                <input type="hidden" name="csrf_token" value="<?= msh($csrfToken) ?>">
                <input type="hidden" name="action" value="team_add">
                <input type="hidden" name="mission_id" class="js-mission-id">
                <div class="ms-field ms-grow"><input type="search" class="ms-member-search" data-for="team_member_id" placeholder="Search name or member no..." autocomplete="off"><select name="member_id" id="team_member_id" required><option value="">Select member</option><?php ms_member_options($members); ?></select></div>
                <div class="ms-field"><select name="role"><?php foreach ($roles as $r): ?><option<?= $r === 'Team Member' ? ' selected' : '' ?>><?= msh($r) ?></option><?php endforeach; ?></select></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add</button>
            </form>
            <div class="table-responsive"><table class="table"><thead><tr><th>Member</th><th>Role</th><th>Phone</th><th></th></tr></thead><tbody id="teamRows"></tbody></table></div>

            <h4 class="ms-heading"><i class="fas fa-coins"></i> Finances</h4>
            <div class="ms-money-row" id="moneySummary"></div>
            <form id="financeForm" class="ms-inline-form">
                <input type="hidden" name="csrf_token" value="<?= msh($csrfToken) ?>">
                <input type="hidden" name="action" value="finance_add">
                <input type="hidden" name="mission_id" class="js-mission-id">
                <div class="ms-field"><select name="entry_type" id="entry_type"><option value="Income">Money raised</option><option value="Expense">Money spent</option></select></div>
                <div class="ms-field"><select name="category" id="category" required></select></div>
                <div class="ms-field"><input type="number" name="amount" min="0" step="0.01" placeholder="Amount (UGX)" required></div>
                <div class="ms-field"><input type="date" name="entry_date" id="entry_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="ms-field ms-grow"><input type="text" name="description" maxlength="255" placeholder="Details (optional)"></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button>
            </form>
            <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Amount (UGX)</th><th>Details</th><th></th></tr></thead><tbody id="financeRows"></tbody></table></div>
        </div>
        <div class="ms-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Close</button><button type="button" class="btn btn-warning" id="viewEditBtn"><i class="fas fa-edit"></i> Edit Mission</button></div>
    </div>
</div>

<div class="ms-modal" id="deleteModal" aria-hidden="true">
    <div class="ms-dialog ms-dialog-sm">
        <form id="deleteForm">
            <div class="ms-modal-header"><div class="ms-modal-title"><i class="fas fa-trash"></i> Delete Mission</div><button type="button" class="ms-close" data-close-modal>&times;</button></div>
            <div class="ms-modal-body">
                <div id="deleteAlert" class="ms-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= msh($csrfToken) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <p>Delete mission <strong id="deleteName"></strong>?</p>
                <p class="text-muted">This also removes its team list and finance entries. To keep the record, set its status to Cancelled instead.</p>
            </div>
            <div class="ms-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
        </form>
    </div>
</div>

<style>
.ms-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}.ms-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;gap:14px;align-items:center}.ms-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.12);font-size:20px;color:#1d4ed8}.ms-stat-value{font-size:22px;font-weight:800;line-height:1.2}.ms-stat-label{font-size:13px;color:#64748b;margin-top:4px}.ms-field input,.ms-field select,.ms-field textarea{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px}.ms-member-search{margin-bottom:6px}.ms-alert{display:none;margin-top:10px;border-radius:10px;padding:10px 12px}.ms-alert.success{display:block;background:#dcfce7;color:#166534}.ms-alert.error{display:block;background:#fee2e2;color:#991b1b}.ms-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;z-index:9998}.ms-modal{position:fixed;inset:0;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px;z-index:9999}.ms-modal.show{display:flex}.ms-modal-backdrop.show{display:block}.ms-dialog{width:min(760px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}.ms-dialog-sm{width:min(520px,100%)}.ms-dialog-lg{width:min(1050px,100%)}.ms-modal-header{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:16px 18px}.ms-modal-title{font-weight:800;font-size:18px}.ms-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.ms-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}.ms-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ms-full{grid-column:1/-1}.ms-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}.ms-section-label{font-weight:800;margin-top:6px;padding-top:12px;border-top:1px solid #e5e7eb}.ms-actions{display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 18px}.ms-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.ms-detail-grid div{border:1px solid #e5e7eb;background:#f8fafc;border-radius:10px;padding:10px}.ms-detail-grid b{display:block;color:#64748b;font-size:12px}.ms-detail-grid .ms-full{white-space:pre-line}.ms-heading{margin:22px 0 10px;font-size:16px;font-weight:800}.ms-inline-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:10px}.ms-inline-form .ms-field{min-width:150px}.ms-inline-form .ms-grow{flex:1 1 220px}.ms-money-row{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px}.ms-money-row span{background:#f1f5f9;border-radius:10px;padding:8px 12px;font-weight:700}.table-actions{display:flex;gap:6px;flex-wrap:wrap}@media(max-width:900px){.ms-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ms-grid,.ms-detail-grid{grid-template-columns:1fr}}@media(max-width:560px){.ms-stats-grid{grid-template-columns:1fr}.ms-inline-form .ms-field{flex:1 1 100%}}
</style>

<script>
(function(){
'use strict';
const endpoint='process-missions.php';
const categories={Income:<?= json_encode($incomeCategories) ?>,Expense:<?= json_encode($expenseCategories) ?>};
let rows=[], current=null;
const qs=(s,r=document)=>r.querySelector(s); const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const num=v=>Number(v||0).toLocaleString();
const money=v=>'UGX '+num(v);
const statusClass={Planned:'info',Ongoing:'warning',Completed:'success',Cancelled:'secondary'};
function alertBox(el,msg,type='success'){if(!el)return;el.className='ms-alert '+(type==='error'?'error':'success');el.textContent=msg;}
function clearAlert(el){if(el){el.className='ms-alert';el.textContent='';}}
function openModal(id){qs('#msBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
function closeModal(id){qs(id).classList.remove('show');if(!qsa('.ms-modal.show').length){qs('#msBackdrop').classList.remove('show');document.body.style.overflow='';}}
function closeModals(){qsa('.ms-modal,.ms-modal-backdrop').forEach(m=>m.classList.remove('show'));document.body.style.overflow='';}
async function request(url,opts={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts));const text=await res.text();try{return JSON.parse(text);}catch(e){throw new Error(text?('Invalid server response: '+text.slice(0,220)):'Empty server response. Check the PHP error log.');}}
async function postForm(form){return request(endpoint,{method:'POST',body:new FormData(form)});}
async function postData(data){const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf_token',<?= json_encode($csrfToken) ?>);return request(endpoint,{method:'POST',body:fd});}
function dates(m){const s=m.start_date||'',e=m.end_date||'';return e&&e!==s?esc(s)+' to '+esc(e):esc(s);}
function statusBadge(s){return `<span class="badge badge-${statusClass[s]||'secondary'}">${esc(s||'Planned')}</span>`;}

/* Member search boxes: filter the select below them by name or member number. */
const memberLists={};
qsa('.ms-member-search').forEach(input=>{const sel=qs('#'+input.dataset.for);memberLists[sel.id]=qsa('option',sel).filter(o=>o.value);input.addEventListener('input',()=>filterMembers(sel,input.value));});
function filterMembers(sel,term){const t=(term||'').trim().toLowerCase(),prev=sel.value,all=memberLists[sel.id];const matches=t?all.filter(o=>o.textContent.toLowerCase().includes(t)):all;while(sel.options.length>1)sel.remove(1);matches.forEach(o=>sel.appendChild(o));sel.value=matches.length===1&&t?matches[0].value:(matches.some(o=>o.value===prev)?prev:'');}
function resetMemberSearch(form){qsa('.ms-member-search',form).forEach(input=>{input.value='';filterMembers(qs('#'+input.dataset.for),'');});}

function renderStats(items){let open=0,souls=0,reached=0,inc=0,exp=0;items.forEach(m=>{if(m.status==='Planned'||m.status==='Ongoing')open++;souls+=Number(m.souls_won||0);reached+=Number(m.people_reached||0);inc+=Number(m.total_income||0);exp+=Number(m.total_expense||0);});qs('#statTotal').textContent=num(items.length);qs('#statUpcoming').textContent=num(open);qs('#statSouls').textContent=num(souls);qs('#statReached').textContent=num(reached);qs('#statIncome').textContent=money(inc);qs('#statExpense').textContent=money(exp);}
function filteredRows(){const term=(qs('#tableSearch').value||'').toLowerCase(),st=qs('#filterStatus').value,ty=qs('#filterType').value;return rows.filter(m=>(!st||m.status===st)&&(!ty||m.mission_type===ty)&&Object.values(m).join(' ').toLowerCase().includes(term));}
function renderTable(){const items=filteredRows(),tbody=qs('#missionTableBody');renderStats(items);if(!items.length){tbody.innerHTML='<tr><td colspan="11" class="text-muted p-3">No missions found.</td></tr>';return;}tbody.innerHTML=items.map((m,i)=>`<tr><td>${i+1}</td><td><span class="badge badge-primary">${esc(m.mission_no)}</span></td><td><strong>${esc(m.title)}</strong><br><small class="text-muted">${esc(m.mission_type)}</small></td><td>${esc(m.destination||'—')}</td><td>${dates(m)}</td><td>${esc(m.leader_name||'—')}</td><td>${num(m.team_count)}</td><td>${num(m.souls_won)}</td><td>${num(m.total_income)} / ${num(m.total_expense)}</td><td>${statusBadge(m.status)}</td><td><div class="table-actions"><button type="button" class="btn btn-sm btn-info" data-action="view" data-id="${Number(m.id)}" title="Team, finances and details"><i class="fas fa-eye"></i></button><button type="button" class="btn btn-sm btn-warning" data-action="edit" data-id="${Number(m.id)}" title="Edit"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${Number(m.id)}" data-name="${esc(m.title)}" title="Delete"><i class="fas fa-trash"></i></button></div></td></tr>`).join('');}
async function load(){clearAlert(qs('#pageAlert'));const data=await request(endpoint+'?action=list');if(!data.success)throw new Error(data.message||'Failed to load missions.');rows=data.items||[];renderTable();}

function resetForm(){const f=qs('#missionForm');f.reset();resetMemberSearch(f);qs('#formAction').value='create';qs('#missionId').value='';qs('#missionTitle').innerHTML='<i class="fas fa-plus"></i> New Mission';qs('#start_date').value=new Date().toISOString().slice(0,10);clearAlert(qs('#formAlert'));}
async function openEdit(id){resetForm();const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#pageAlert'),data.message||'Mission not found.','error');const m=data.item;qs('#formAction').value='update';qs('#missionId').value=m.id;qs('#missionTitle').innerHTML='<i class="fas fa-edit"></i> Edit Mission';['title','mission_type','destination','start_date','end_date','leader_member_id','budget','objectives','status','souls_won','rededications','people_reached','report'].forEach(k=>{if(qs('#'+k))qs('#'+k).value=m[k]??'';});closeModals();openModal('#missionModal');}

function fillCategories(){const t=qs('#entry_type').value;qs('#category').innerHTML='<option value="">Category</option>'+categories[t].map(c=>`<option>${esc(c)}</option>`).join('');}
async function openView(id,keepOpen){const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#pageAlert'),data.message||'Mission not found.','error');const m=data.item,team=data.team||[],fin=data.finance||[];current=m;
  qsa('.js-mission-id').forEach(x=>x.value=m.id);
  qs('#viewTitle').innerHTML=`<i class="fas fa-globe-africa"></i> ${esc(m.mission_no)} · ${esc(m.title)}`;
  qs('#viewDetails').innerHTML=[['Type',esc(m.mission_type)],['Destination',esc(m.destination||'—')],['Dates',dates(m)],['Leader',esc(m.leader_name||'—')],['Status',statusBadge(m.status)],['Budget',money(m.budget)],['Souls Won',num(m.souls_won)],['Rededications',num(m.rededications)],['People Reached',num(m.people_reached)]].map(x=>`<div><b>${x[0]}</b>${x[1]}</div>`).join('')+`<div class="ms-full"><b>Objectives</b>${esc(m.objectives||'—')}</div><div class="ms-full"><b>Mission Report</b>${esc(m.report||'Not written yet')}</div>`;
  qs('#teamCount').textContent=team.length;
  qs('#teamRows').innerHTML=team.length?team.map(t=>`<tr><td>${esc(t.full_name||'Member #'+t.member_id)}${t.member_no?' <small class="text-muted">('+esc(t.member_no)+')</small>':''}</td><td>${esc(t.role)}</td><td>${esc(t.phone||'—')}</td><td><button type="button" class="btn btn-sm btn-outline-danger" data-action="team_remove" data-id="${Number(t.id)}" data-name="${esc(t.full_name||'')}" title="Remove from team"><i class="fas fa-user-minus"></i></button></td></tr>`).join(''):'<tr><td colspan="4" class="text-muted">No team members yet.</td></tr>';
  const inc=Number(m.total_income||0),exp=Number(m.total_expense||0),budget=Number(m.budget||0);
  qs('#moneySummary').innerHTML=`<span>Budget: ${money(budget)}</span><span class="text-success">Raised: ${money(inc)}</span><span class="text-danger">Spent: ${money(exp)}</span><span>Balance: ${money(inc-exp)}</span>`+(budget>0?`<span>${exp>budget?'Over budget by '+money(exp-budget):'Budget left: '+money(budget-exp)}</span>`:'');
  qs('#financeRows').innerHTML=fin.length?fin.map(f=>`<tr><td>${esc(f.entry_date)}</td><td><span class="badge badge-${f.entry_type==='Income'?'success':'danger'}">${f.entry_type==='Income'?'Raised':'Spent'}</span></td><td>${esc(f.category)}</td><td><strong>${num(f.amount)}</strong></td><td>${esc(f.description||'')}</td><td><button type="button" class="btn btn-sm btn-outline-danger" data-action="finance_delete" data-id="${Number(f.id)}" title="Delete entry"><i class="fas fa-trash"></i></button></td></tr>`).join(''):'<tr><td colspan="6" class="text-muted">No money recorded yet.</td></tr>';
  if(!keepOpen){clearAlert(qs('#viewAlert'));qs('#teamForm').reset();resetMemberSearch(qs('#teamForm'));qs('#financeForm').reset();qs('#entry_date').value=new Date().toISOString().slice(0,10);fillCategories();openModal('#viewModal');}}
async function refreshView(msg){alertBox(qs('#viewAlert'),msg);await openView(current.id,true);load().catch(()=>{});}

qs('#btnOpenMission').addEventListener('click',()=>{resetForm();openModal('#missionModal');});
qs('#btnRefresh').addEventListener('click',()=>{['#tableSearch','#filterStatus','#filterType'].forEach(s=>qs(s).value='');load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));});
['#tableSearch','#filterStatus','#filterType'].forEach(s=>qs(s).addEventListener('input',renderTable));
qsa('[data-close-modal]').forEach(b=>b.addEventListener('click',closeModals));qs('#msBackdrop').addEventListener('click',closeModals);document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
qs('#entry_type').addEventListener('change',fillCategories);
qs('#viewEditBtn').addEventListener('click',()=>{if(current)openEdit(current.id);});
document.addEventListener('click',async e=>{const b=e.target.closest('[data-action]');if(!b)return;const id=b.dataset.id,a=b.dataset.action;
  if(a==='view')openView(id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
  if(a==='edit')openEdit(id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
  if(a==='delete'){qs('#deleteId').value=id;qs('#deleteName').textContent=b.dataset.name||'';clearAlert(qs('#deleteAlert'));openModal('#deleteModal');}
  if(a==='team_remove'||a==='finance_delete'){if(!confirm(a==='team_remove'?'Remove '+(b.dataset.name||'this member')+' from the team?':'Delete this finance entry?'))return;try{const d=await postData({action:a,id});if(!d.success)return alertBox(qs('#viewAlert'),d.message||'Failed.','error');await refreshView(d.message);}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}}
});
qs('#missionForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#formAlert'),data.message||'Save failed.','error');closeModals();alertBox(qs('#pageAlert'),data.message||'Saved.');await load();}catch(err){alertBox(qs('#formAlert'),err.message,'error');}});
['#teamForm','#financeForm'].forEach(sel=>qs(sel).addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget;try{const data=await postForm(form);if(!data.success)return alertBox(qs('#viewAlert'),data.message||'Save failed.','error');form.reset();if(sel==='#teamForm')resetMemberSearch(form);else{qs('#entry_date').value=new Date().toISOString().slice(0,10);fillCategories();}await refreshView(data.message);}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}}));
qs('#deleteForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#deleteAlert'),data.message||'Delete failed.','error');closeModals();alertBox(qs('#pageAlert'),data.message||'Deleted.');await load();}catch(err){alertBox(qs('#deleteAlert'),err.message,'error');}});
load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
})();
</script>
<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
