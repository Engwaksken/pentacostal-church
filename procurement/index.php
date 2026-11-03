<?php
declare(strict_types=1);
$pageTitle = 'Procurement';
require_once '../includes/header.php';
require_once '../includes/procurement-functions.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

$installed = pr_installed($conn);
if (!pr_can_access($conn)) {
    http_response_code(403);
    echo '<div style="font-family:Arial;padding:40px"><h2>Access denied</h2><p>Procurement is open to the procurement officer and assistants, and to department leaders.</p><p><a href="' . h(BASE_URL . 'dashboard.php') . '">Return to dashboard</a></p></div>';
    exit;
}

$isAdmin = pr_is_admin();
$canManage = $installed && pr_can_manage($conn);
$teamRole = $installed ? pr_team_role($conn) : '';
$ledDepts = $installed ? pr_led_departments($conn) : [];
$csrf = pr_csrf_token();

// Departments a request can be raised for: admins any active one, leaders their own.
$requestDepts = $ledDepts;
if ($isAdmin && pr_table_exists($conn, 'departments')) {
    $requestDepts = [];
    $res = $conn->query("SELECT id, name FROM departments WHERE COALESCE(status,'Active')='Active' ORDER BY name");
    while ($res && $r = $res->fetch_assoc()) $requestDepts[(int)$r['id']] = (string)$r['name'];
}
$members = [];
if ($isAdmin && pr_table_exists($conn, 'members')) {
    $res = $conn->query("SELECT id, member_no, full_name FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name");
    while ($res && $r = $res->fetch_assoc()) $members[] = $r;
}

if ($isAdmin) $roleText = 'You are an administrator: you can assign the procurement team and manage every request.';
elseif ($teamRole !== '') $roleText = "You are the procurement $teamRole: review department requests, approve or reject them with a comment, and record ordering and delivery.";
else $roleText = 'You lead ' . implode(', ', $ledDepts) . '. Send purchase requests to the procurement team and follow their progress here.';

require_once '../includes/sidebar.php';
?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2><i class="fas fa-cart-flatbed"></i> Procurement</h2>
        <p class="text-muted mb-0"><?= h($roleText) ?></p>
    </div>
    <?php if ($installed && $requestDepts): ?>
    <button type="button" class="btn btn-primary" id="btnNewRequest"><i class="fas fa-plus"></i> New Request</button>
    <?php endif; ?>
</div>

<?php if (!$installed): ?>
<div class="card"><div class="card-body"><strong>Setup required:</strong> the procurement tables were not found. Run <code>database/procurement.sql</code>.</div></div>
<?php else: ?>

<?php if ($isAdmin): ?>
<div class="pr-tabs"><button type="button" class="active" data-tab="requests"><i class="fas fa-file-invoice"></i> Requests</button><button type="button" data-tab="team"><i class="fas fa-user-tie"></i> Procurement Team</button></div>
<?php endif; ?>

<section id="tabRequests">
<div class="pr-stats-grid">
    <div class="pr-stat-card"><div class="pr-stat-icon"><i class="fas fa-hourglass-half"></i></div><div><div class="pr-stat-value" id="statPending">0</div><div class="pr-stat-label">Awaiting Decision</div></div></div>
    <div class="pr-stat-card"><div class="pr-stat-icon"><i class="fas fa-circle-check"></i></div><div><div class="pr-stat-value" id="statApproved">0</div><div class="pr-stat-label">Approved / Ordered</div></div></div>
    <div class="pr-stat-card"><div class="pr-stat-icon"><i class="fas fa-truck"></i></div><div><div class="pr-stat-value" id="statDelivered">0</div><div class="pr-stat-label">Delivered</div></div></div>
    <div class="pr-stat-card"><div class="pr-stat-icon"><i class="fas fa-sack-dollar"></i></div><div><div class="pr-stat-value" id="statValue">UGX 0</div><div class="pr-stat-label">Pending Estimated Value</div></div></div>
</div>

<div class="page-toolbar">
    <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="text" id="tableSearch" placeholder="Search request no, title, department, requester..."></div>
    <div class="page-toolbar-filters">
        <select id="filterStatus" class="form-control"><option value="">All Statuses</option><?php foreach (PR_STATUSES as $s) echo '<option>' . h($s) . '</option>'; ?></select>
        <?php if ($canManage || count($ledDepts) > 1): ?>
        <select id="filterDept" class="form-control"><option value="">All Departments</option></select>
        <?php endif; ?>
    </div>
    <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefresh"><i class="fas fa-rotate-right"></i> Refresh</button></div>
</div>
<div id="pageAlert" class="pr-alert"></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0">
        <thead><tr><th>Request No</th><th>Request</th><th>Department</th><th>Requested By</th><th>Priority</th><th>Needed By</th><th>Estimate (UGX)</th><th>Status</th><th></th></tr></thead>
        <tbody id="requestRows"><tr><td colspan="9" class="text-muted p-3">Loading...</td></tr></tbody>
    </table>
</div></div></div>
</section>

<?php if ($isAdmin): ?>
<section id="tabTeam" hidden>
<div class="card"><div class="card-body">
    <p class="text-muted">Members assigned here can open Procurement from their member portal to approve or reject department requests. They need a member login (set one up under Members).</p>
    <form id="teamForm" class="pr-inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="team_save">
        <div class="pr-field pr-grow"><label>Member</label><input type="search" id="teamMemberSearch" placeholder="Search name or member no..." autocomplete="off"><select name="member_id" id="teamMember" required><option value="">Choose member</option><?php foreach ($members as $m) echo '<option value="' . (int)$m['id'] . '">' . h(trim((string)$m['full_name'])) . ($m['member_no'] ? ' (' . h($m['member_no']) . ')' : '') . '</option>'; ?></select></div>
        <div class="pr-field"><label>Role</label><select name="role"><?php foreach (PR_TEAM_ROLES as $r) echo '<option>' . h($r) . '</option>'; ?></select></div>
        <div class="pr-field"><label>Status</label><select name="status"><option>Active</option><option>Inactive</option></select></div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Assign</button>
    </form>
    <div id="teamAlert" class="pr-alert"></div>
    <div class="table-responsive"><table class="table"><thead><tr><th>Member</th><th>Phone</th><th>Role</th><th>Status</th><th>Portal Login</th><th></th></tr></thead><tbody id="teamRows"></tbody></table></div>
</div></div>
</section>
<?php endif; ?>

<div class="pr-modal-backdrop" id="prBackdrop"></div>

<!-- New / edit request -->
<div class="pr-modal" id="requestModal" aria-hidden="true">
    <div class="pr-dialog pr-dialog-lg">
        <form id="requestForm">
            <div class="pr-modal-header"><div class="pr-modal-title" id="requestTitle"><i class="fas fa-plus"></i> New Procurement Request</div><button type="button" class="pr-close" data-close-modal>&times;</button></div>
            <div class="pr-modal-body">
                <div id="formAlert" class="pr-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="requestId" value="">
                <div class="pr-grid">
                    <div class="pr-field"><label>Department <span class="text-danger">*</span></label><select name="department_id" id="department_id" required><?php foreach ($requestDepts as $id => $name) echo '<option value="' . (int)$id . '">' . h($name) . '</option>'; ?></select></div>
                    <div class="pr-field"><label>Priority</label><select name="priority" id="priority"><?php foreach (PR_PRIORITIES as $p) echo '<option' . ($p === 'Normal' ? ' selected' : '') . '>' . h($p) . '</option>'; ?></select></div>
                    <div class="pr-field pr-full"><label>What is needed? <span class="text-danger">*</span></label><input type="text" name="title" id="title" maxlength="200" placeholder="e.g. Choir robes for Christmas service" required></div>
                    <div class="pr-field"><label>Needed By</label><input type="date" name="needed_by" id="needed_by"></div>
                    <div class="pr-field pr-full"><label>Reason / Justification</label><textarea name="justification" id="justification" rows="2" placeholder="Why the department needs this and how it will be used"></textarea></div>
                </div>
                <h4 class="pr-heading"><i class="fas fa-list"></i> Items <span class="badge badge-info" id="itemsTotal">UGX 0</span></h4>
                <div class="table-responsive"><table class="table pr-items-table"><thead><tr><th>Item <span class="text-danger">*</span></th><th>Specification</th><th>Qty</th><th>Unit</th><th>Est. Unit Cost (UGX)</th><th>Total</th><th></th></tr></thead><tbody id="itemRows"></tbody></table></div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddItem"><i class="fas fa-plus"></i> Add Item</button>
            </div>
            <div class="pr-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> <span id="requestSubmitText">Send to Procurement</span></button></div>
        </form>
    </div>
</div>

<!-- View request -->
<div class="pr-modal" id="viewModal" aria-hidden="true">
    <div class="pr-dialog pr-dialog-lg">
        <div class="pr-modal-header"><div class="pr-modal-title" id="viewTitle"><i class="fas fa-file-invoice"></i> Request</div><button type="button" class="pr-close" data-close-modal>&times;</button></div>
        <div class="pr-modal-body">
            <div id="viewAlert" class="pr-alert"></div>
            <div id="viewDetails" class="pr-detail-grid"></div>
            <h4 class="pr-heading"><i class="fas fa-list"></i> Items</h4>
            <div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Specification</th><th>Qty</th><th>Unit</th><th>Unit Cost</th><th>Total</th></tr></thead><tbody id="viewItems"></tbody></table></div>

            <div id="decidePanel" class="pr-panel" hidden>
                <h4 class="pr-heading"><i class="fas fa-gavel"></i> Decision</h4>
                <form id="decideForm">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="decide">
                    <input type="hidden" name="id" class="js-request-id">
                    <input type="hidden" name="decision" id="decision">
                    <div class="pr-field"><label>Decision comment <span class="text-danger">*</span></label><textarea name="comment" rows="2" placeholder="Explain why the request is approved or rejected." required></textarea></div>
                    <div class="pr-button-row"><button type="submit" class="btn btn-success" data-decision="Approved"><i class="fas fa-check"></i> Approve</button><button type="submit" class="btn btn-danger" data-decision="Rejected"><i class="fas fa-xmark"></i> Reject</button></div>
                </form>
            </div>
            <p id="ownRequestNote" class="pr-note" hidden><i class="fas fa-circle-info"></i> You raised this request, so another member of the procurement team must approve or reject it.</p>

            <div id="progressPanel" class="pr-panel" hidden>
                <h4 class="pr-heading"><i class="fas fa-truck"></i> Purchasing</h4>
                <form id="progressForm" class="pr-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="progress">
                    <input type="hidden" name="id" class="js-request-id">
                    <div class="pr-field"><label>Step</label><select name="status" id="progressStatus"></select></div>
                    <div class="pr-field"><label>Date</label><input type="date" name="date" id="progressDate"></div>
                    <div class="pr-field pr-grow"><label>Supplier</label><input type="text" name="supplier" id="progressSupplier" maxlength="200"></div>
                    <div class="pr-field"><label>Actual Cost (UGX)</label><input type="number" name="actual_cost" id="progressCost" min="0" step="0.01"></div>
                    <div class="pr-field pr-grow"><label>Note</label><input type="text" name="comment" maxlength="500"></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                </form>
            </div>

            <h4 class="pr-heading"><i class="fas fa-comments"></i> History &amp; Comments</h4>
            <ul class="pr-timeline" id="viewLog"></ul>
            <form id="commentForm" class="pr-inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="id" class="js-request-id">
                <div class="pr-field pr-grow"><input type="text" name="comment" maxlength="1000" placeholder="Add a comment or question..." required></div>
                <button type="submit" class="btn btn-outline-primary"><i class="fas fa-comment"></i> Comment</button>
            </form>
        </div>
        <div class="pr-actions">
            <button type="button" class="btn btn-outline-danger" id="btnDelete" hidden><i class="fas fa-trash"></i> Delete</button>
            <button type="button" class="btn btn-outline-warning" id="btnCancelRequest" hidden><i class="fas fa-ban"></i> Cancel Request</button>
            <button type="button" class="btn btn-warning" id="btnEdit" hidden><i class="fas fa-edit"></i> Edit</button>
            <button type="button" class="btn btn-outline-secondary" data-close-modal>Close</button>
        </div>
    </div>
</div>

<style>
.pr-tabs{display:flex;gap:8px;margin-bottom:16px}.pr-tabs button{border:1px solid #d1d5db;background:#fff;border-radius:10px;padding:9px 14px;cursor:pointer;font-weight:600}.pr-tabs button.active{background:#4b1f91;color:#fff;border-color:#4b1f91}
.pr-stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}.pr-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;gap:14px;align-items:center}.pr-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.12);font-size:20px;color:#1d4ed8}.pr-stat-value{font-size:22px;font-weight:800;line-height:1.2}.pr-stat-label{font-size:13px;color:#64748b;margin-top:4px}
.pr-field input,.pr-field select,.pr-field textarea,.pr-items-table input{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:9px 11px}.pr-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}#teamMemberSearch{margin-bottom:6px}
.pr-alert{display:none;margin:10px 0;border-radius:10px;padding:10px 12px}.pr-alert.success{display:block;background:#dcfce7;color:#166534}.pr-alert.error{display:block;background:#fee2e2;color:#991b1b}
.pr-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;z-index:9998}.pr-modal{position:fixed;inset:0;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px;z-index:9999}.pr-modal.show{display:flex}.pr-modal-backdrop.show{display:block}.pr-dialog{width:min(760px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}.pr-dialog-lg{width:min(1050px,100%)}.pr-modal-header{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:16px 18px}.pr-modal-title{font-weight:800;font-size:18px}.pr-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.pr-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}.pr-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 18px}
.pr-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.pr-full{grid-column:1/-1}.pr-heading{margin:20px 0 10px;font-size:16px;font-weight:800}.pr-items-table td{vertical-align:middle;min-width:90px}.pr-items-table td:first-child{min-width:180px}
.pr-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.pr-detail-grid div{border:1px solid #e5e7eb;background:#f8fafc;border-radius:10px;padding:10px}.pr-detail-grid b{display:block;color:#64748b;font-size:12px}.pr-detail-grid .pr-full{white-space:pre-line}
.pr-panel{border:1px solid #e5e7eb;border-radius:12px;padding:4px 14px 14px;margin-top:16px;background:#fcfcfd}.pr-button-row{display:flex;gap:10px;margin-top:10px}.pr-note{margin-top:14px;padding:10px 12px;background:#eef6ff;border-left:4px solid #2563eb;border-radius:8px}
.pr-inline-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:10px 0}.pr-inline-form .pr-field{min-width:140px}.pr-inline-form .pr-grow{flex:1 1 220px}
.pr-timeline{list-style:none;padding:0;margin:0;border-left:3px solid #e5e7eb}.pr-timeline li{position:relative;padding:4px 0 12px 16px}.pr-timeline li:before{content:'';position:absolute;left:-8px;top:8px;width:13px;height:13px;border-radius:50%;background:#94a3b8;border:2px solid #fff}.pr-timeline li.Approved:before,.pr-timeline li.Delivered:before{background:#16a34a}.pr-timeline li.Rejected:before,.pr-timeline li.Cancelled:before{background:#dc2626}.pr-timeline li.Ordered:before{background:#2563eb}.pr-timeline small{color:#64748b}.pr-timeline p{margin:4px 0 0;white-space:pre-line}
.pr-urgent{color:#b91c1c;font-weight:700}.table-actions{display:flex;gap:6px}
@media(max-width:900px){.pr-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pr-grid,.pr-detail-grid{grid-template-columns:1fr}}@media(max-width:560px){.pr-stats-grid{grid-template-columns:1fr}.pr-inline-form .pr-field{flex:1 1 100%}}
</style>

<script>
(function(){
'use strict';
const endpoint='process.php', csrf=<?= json_encode($csrf) ?>;
let rows=[], current=null;
const qs=(s,r=document)=>r.querySelector(s), qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const num=v=>Number(v||0).toLocaleString(undefined,{maximumFractionDigits:2});
const money=v=>'UGX '+num(v);
const today=()=>new Date().toISOString().slice(0,10);
const statusClass={Pending:'warning',Approved:'success',Rejected:'danger',Ordered:'primary',Delivered:'info',Cancelled:'secondary'};
const priorityClass={Low:'secondary',Normal:'info',High:'warning',Urgent:'danger'};
const badge=(map,s)=>`<span class="badge badge-${map[s]||'secondary'}">${esc(s||'—')}</span>`;
function alertBox(el,msg,type='success'){if(!el)return;el.className='pr-alert '+(type==='error'?'error':'success');el.textContent=msg;}
function clearAlert(el){if(el){el.className='pr-alert';el.textContent='';}}
function openModal(id){qs('#prBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
function closeModals(){qsa('.pr-modal,.pr-modal-backdrop').forEach(m=>m.classList.remove('show'));document.body.style.overflow='';}
async function request(url,opts={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts));const text=await res.text();try{return JSON.parse(text);}catch(e){throw new Error(text?('Invalid server response: '+text.slice(0,220)):'Empty server response. Check the PHP error log.');}}
const postForm=form=>request(endpoint,{method:'POST',body:new FormData(form)});
function postData(data){const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf_token',csrf);return request(endpoint,{method:'POST',body:fd});}

/* ---------- Request list ---------- */
function filtered(){const t=(qs('#tableSearch').value||'').toLowerCase(),st=qs('#filterStatus').value,dp=qs('#filterDept')?qs('#filterDept').value:'';return rows.filter(r=>(!st||r.status===st)&&(!dp||String(r.department_id)===dp)&&[r.request_no,r.title,r.department_name,r.requested_by_name,r.status].join(' ').toLowerCase().includes(t));}
function renderStats(){let pending=0,approved=0,delivered=0,value=0;rows.forEach(r=>{if(r.status==='Pending'){pending++;value+=Number(r.estimated_total||0);}if(r.status==='Approved'||r.status==='Ordered')approved++;if(r.status==='Delivered')delivered++;});qs('#statPending').textContent=num(pending);qs('#statApproved').textContent=num(approved);qs('#statDelivered').textContent=num(delivered);qs('#statValue').textContent=money(value);}
function render(){const items=filtered(),tb=qs('#requestRows');if(!items.length){tb.innerHTML='<tr><td colspan="9" class="text-muted p-3">No requests found.</td></tr>';return;}
  tb.innerHTML=items.map(r=>{const late=r.needed_by&&r.needed_by<today()&&['Pending','Approved','Ordered'].includes(r.status);return `<tr><td><span class="badge badge-primary">${esc(r.request_no)}</span></td><td><strong>${esc(r.title)}</strong><br><small class="text-muted">${num(r.item_count)} item(s) · ${esc((r.created_at||'').slice(0,10))}</small></td><td>${esc(r.department_name||'—')}</td><td>${esc(r.requested_by_name||'—')}</td><td>${badge(priorityClass,r.priority)}</td><td>${r.needed_by?(late?`<span class="pr-urgent">${esc(r.needed_by)}</span>`:esc(r.needed_by)):'—'}</td><td>${num(r.estimated_total)}</td><td>${badge(statusClass,r.status)}</td><td><button type="button" class="btn btn-sm btn-info" data-action="view" data-id="${Number(r.id)}" title="Open"><i class="fas fa-eye"></i></button></td></tr>`;}).join('');}
function fillDeptFilter(){const sel=qs('#filterDept');if(!sel)return;const prev=sel.value,seen={};rows.forEach(r=>{seen[r.department_id]=r.department_name;});sel.innerHTML='<option value="">All Departments</option>'+Object.entries(seen).sort((a,b)=>String(a[1]).localeCompare(String(b[1]))).map(([id,n])=>`<option value="${esc(id)}">${esc(n||'—')}</option>`).join('');sel.value=prev;}
async function load(){const d=await request(endpoint+'?action=list');if(!d.success)throw new Error(d.message||'Failed to load requests.');rows=d.items||[];fillDeptFilter();renderStats();render();}

/* ---------- Request form ---------- */
function itemRow(it={}){const tr=document.createElement('tr');tr.innerHTML=`<td><input type="text" name="item_name[]" maxlength="200" value="${esc(it.item_name||'')}" placeholder="Item"></td><td><input type="text" name="specification[]" maxlength="255" value="${esc(it.specification||'')}" placeholder="Size, brand, colour..."></td><td><input type="number" name="quantity[]" min="0.01" step="0.01" value="${esc(it.quantity??1)}"></td><td><input type="text" name="unit[]" maxlength="30" value="${esc(it.unit||'')}" placeholder="pcs"></td><td><input type="number" name="unit_cost[]" min="0" step="0.01" value="${esc(it.unit_cost??0)}"></td><td class="js-line">0</td><td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-item title="Remove"><i class="fas fa-times"></i></button></td>`;qs('#itemRows').appendChild(tr);recalc();}
function recalc(){let total=0;qsa('#itemRows tr').forEach(tr=>{const q=Number(qs('[name="quantity[]"]',tr).value||0),c=Number(qs('[name="unit_cost[]"]',tr).value||0);total+=q*c;qs('.js-line',tr).textContent=num(q*c);});qs('#itemsTotal').textContent=money(total);}
function resetForm(){const f=qs('#requestForm');f.reset();qs('#requestId').value='';qs('#itemRows').innerHTML='';itemRow();qs('#requestTitle').innerHTML='<i class="fas fa-plus"></i> New Procurement Request';qs('#requestSubmitText').textContent='Send to Procurement';clearAlert(qs('#formAlert'));}
qs('#itemRows').addEventListener('input',recalc);
qs('#itemRows').addEventListener('click',e=>{const b=e.target.closest('[data-remove-item]');if(!b)return;if(qsa('#itemRows tr').length>1)b.closest('tr').remove();recalc();});
qs('#btnAddItem').addEventListener('click',()=>itemRow());
const btnNew=qs('#btnNewRequest');if(btnNew)btnNew.addEventListener('click',()=>{resetForm();openModal('#requestModal');});
qs('#requestForm').addEventListener('submit',async e=>{e.preventDefault();try{const d=await postForm(e.currentTarget);if(!d.success)return alertBox(qs('#formAlert'),d.message||'Save failed.','error');closeModals();alertBox(qs('#pageAlert'),d.message);await load();}catch(err){alertBox(qs('#formAlert'),err.message,'error');}});
function openEdit(){if(!current)return;const r=current.item;resetForm();qs('#requestId').value=r.id;
  const deptSel=qs('#department_id');if(!qsa('option',deptSel).some(o=>o.value===String(r.department_id)))deptSel.insertAdjacentHTML('beforeend',`<option value="${Number(r.department_id)}">${esc(r.department_name)}</option>`);
  deptSel.value=r.department_id;qs('#priority').value=r.priority;qs('#title').value=r.title;qs('#needed_by').value=r.needed_by||'';qs('#justification').value=r.justification||'';
  qs('#itemRows').innerHTML='';(current.items.length?current.items:[{}]).forEach(itemRow);
  qs('#requestTitle').innerHTML=`<i class="fas fa-edit"></i> Edit ${esc(r.request_no)}`;qs('#requestSubmitText').textContent='Save Changes';closeModals();openModal('#requestModal');}

/* ---------- View request ---------- */
async function openView(id,keepAlert){const d=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!d.success)return alertBox(qs('#pageAlert'),d.message||'Request not found.','error');current=d;const r=d.item,can=d.can||{};
  qsa('.js-request-id').forEach(x=>x.value=r.id);
  qs('#viewTitle').innerHTML=`<i class="fas fa-file-invoice"></i> ${esc(r.request_no)} · ${esc(r.title)}`;
  const det=[['Department',esc(r.department_name||'—')],['Requested By',esc(r.requested_by_name||'—')],['Date',esc((r.created_at||'').slice(0,16))],['Status',badge(statusClass,r.status)],['Priority',badge(priorityClass,r.priority)],['Needed By',esc(r.needed_by||'—')],['Estimated Total',money(r.estimated_total)]];
  if(r.decided_at)det.push([r.status==='Rejected'?'Rejected By':'Approved By',esc(r.decided_by_name||'—')+'<br><small>'+esc(r.decided_at.slice(0,16))+'</small>']);
  if(r.supplier)det.push(['Supplier',esc(r.supplier)]);if(r.actual_cost!==null&&r.actual_cost!==undefined)det.push(['Actual Cost',money(r.actual_cost)]);
  if(r.ordered_at)det.push(['Ordered',esc(r.ordered_at)]);if(r.delivered_at)det.push(['Delivered',esc(r.delivered_at)]);
  qs('#viewDetails').innerHTML=det.map(x=>`<div><b>${x[0]}</b>${x[1]}</div>`).join('')+(r.justification?`<div class="pr-full"><b>Justification</b>${esc(r.justification)}</div>`:'')+(r.decision_comment?`<div class="pr-full"><b>Procurement Comment</b>${esc(r.decision_comment)}</div>`:'');
  qs('#viewItems').innerHTML=d.items.map(i=>`<tr><td>${esc(i.item_name)}</td><td>${esc(i.specification||'')}</td><td>${num(i.quantity)}</td><td>${esc(i.unit||'')}</td><td>${num(i.unit_cost)}</td><td><strong>${num(i.quantity*i.unit_cost)}</strong></td></tr>`).join('')||'<tr><td colspan="6" class="text-muted">No items.</td></tr>';
  qs('#viewLog').innerHTML=d.log.map(l=>`<li class="${esc(l.action)}"><strong>${esc(l.action)}</strong> <small>by ${esc(l.by_name||'—')} · ${esc((l.created_at||'').slice(0,16))}</small>${l.comment?`<p>${esc(l.comment)}</p>`:''}</li>`).join('')||'<li class="text-muted">No history yet.</li>';
  qs('#decidePanel').hidden=!can.decide;
  qs('#ownRequestNote').hidden=!(r.status==='Pending'&&!can.decide&&<?= $canManage ? 'true' : 'false' ?>);
  qs('#progressPanel').hidden=!(can.order||can.deliver);
  if(can.order||can.deliver){qs('#progressStatus').innerHTML=(can.order?'<option>Ordered</option>':'')+'<option>Delivered</option>';qs('#progressDate').value=today();qs('#progressSupplier').value=r.supplier||'';qs('#progressCost').value=r.actual_cost??'';}
  qs('#btnEdit').hidden=!can.edit;qs('#btnCancelRequest').hidden=!can.cancel;qs('#btnDelete').hidden=!can.delete;
  qs('#decideForm').reset();qs('#commentForm').reset();
  if(!keepAlert)clearAlert(qs('#viewAlert'));
  if(!qs('#viewModal').classList.contains('show'))openModal('#viewModal');}
async function afterAction(d,errEl){if(!d.success)return alertBox(errEl||qs('#viewAlert'),d.message||'Failed.','error');alertBox(qs('#viewAlert'),d.message);await openView(current.item.id,true);load().catch(()=>{});}

let decision='';
qsa('#decideForm [data-decision]').forEach(b=>b.addEventListener('click',()=>{decision=b.dataset.decision;}));
qs('#decideForm').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;if(!decision)return;qs('#decision').value=decision;
  if(decision==='Rejected'&&!f.comment.value.trim())return alertBox(qs('#viewAlert'),'Give a reason for rejecting the request.','error');
  if(!confirm(decision==='Approved'?'Approve this request?':'Reject this request?'))return;
  try{await afterAction(await postForm(f));}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}});
['#progressForm','#commentForm'].forEach(s=>qs(s).addEventListener('submit',async e=>{e.preventDefault();try{await afterAction(await postForm(e.currentTarget));}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}}));
qs('#btnEdit').addEventListener('click',openEdit);
qs('#btnCancelRequest').addEventListener('click',async()=>{const reason=prompt('Cancel this request? You can add a reason (optional):','');if(reason===null)return;try{await afterAction(await postData({action:'cancel',id:current.item.id,comment:reason}));}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}});
qs('#btnDelete').addEventListener('click',async()=>{if(!confirm('Delete this request and its history permanently?'))return;try{const d=await postData({action:'delete',id:current.item.id});if(!d.success)return alertBox(qs('#viewAlert'),d.message,'error');closeModals();alertBox(qs('#pageAlert'),d.message);await load();}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}});

/* ---------- Team (admins) ---------- */
const teamForm=qs('#teamForm');
async function loadTeam(){const d=await request(endpoint+'?action=team_list');if(!d.success)return alertBox(qs('#teamAlert'),d.message,'error');
  qs('#teamRows').innerHTML=(d.team||[]).map(t=>`<tr><td><strong>${esc(t.full_name)}</strong>${t.member_no?` <small class="text-muted">(${esc(t.member_no)})</small>`:''}</td><td>${esc(t.phone||'—')}</td><td>${badge({Officer:'primary',Assistant:'info'},t.role)}</td><td>${badge({Active:'success',Inactive:'secondary'},t.status)}</td><td>${Number(t.has_login)?'<span class="badge badge-success">Yes</span>':'<span class="badge badge-warning" title="This member cannot sign in until a member login is created">No login</span>'}</td><td><div class="table-actions"><button type="button" class="btn btn-sm btn-outline-secondary" data-team-toggle="${Number(t.member_id)}" data-role="${esc(t.role)}" data-status="${t.status==='Active'?'Inactive':'Active'}">${t.status==='Active'?'Deactivate':'Activate'}</button><button type="button" class="btn btn-sm btn-outline-danger" data-team-remove="${Number(t.id)}" title="Remove"><i class="fas fa-trash"></i></button></div></td></tr>`).join('')||'<tr><td colspan="6" class="text-muted">No procurement officer or assistant assigned yet.</td></tr>';}
if(teamForm){
  const sel=qs('#teamMember'),all=qsa('option',sel).filter(o=>o.value);
  qs('#teamMemberSearch').addEventListener('input',e=>{const t=e.target.value.trim().toLowerCase();while(sel.options.length>1)sel.remove(1);const m=t?all.filter(o=>o.textContent.toLowerCase().includes(t)):all;m.forEach(o=>sel.appendChild(o));sel.value=m.length===1&&t?m[0].value:'';});
  teamForm.addEventListener('submit',async e=>{e.preventDefault();try{const d=await postForm(teamForm);alertBox(qs('#teamAlert'),d.message,d.success?'success':'error');if(d.success){teamForm.reset();await loadTeam();}}catch(err){alertBox(qs('#teamAlert'),err.message,'error');}});
  qs('#teamRows').addEventListener('click',async e=>{const tg=e.target.closest('[data-team-toggle]'),rm=e.target.closest('[data-team-remove]');try{let d=null;
    if(tg)d=await postData({action:'team_save',member_id:tg.dataset.teamToggle,role:tg.dataset.role,status:tg.dataset.status});
    if(rm){if(!confirm('Remove this member from the procurement team?'))return;d=await postData({action:'team_remove',id:rm.dataset.teamRemove});}
    if(d){alertBox(qs('#teamAlert'),d.message,d.success?'success':'error');await loadTeam();}}catch(err){alertBox(qs('#teamAlert'),err.message,'error');}});
  qsa('.pr-tabs button').forEach(b=>b.addEventListener('click',()=>{qsa('.pr-tabs button').forEach(x=>x.classList.toggle('active',x===b));qs('#tabRequests').hidden=b.dataset.tab!=='requests';qs('#tabTeam').hidden=b.dataset.tab!=='team';if(b.dataset.tab==='team')loadTeam().catch(err=>alertBox(qs('#teamAlert'),err.message,'error'));}));
}

/* ---------- Wiring ---------- */
['#tableSearch','#filterStatus','#filterDept'].forEach(s=>{const el=qs(s);if(el)el.addEventListener('input',render);});
qs('#btnRefresh').addEventListener('click',()=>{load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));});
qsa('[data-close-modal]').forEach(b=>b.addEventListener('click',closeModals));qs('#prBackdrop').addEventListener('click',closeModals);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
qs('#requestRows').addEventListener('click',e=>{const b=e.target.closest('[data-action="view"]');if(b)openView(b.dataset.id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));});
load().then(()=>{const open=new URLSearchParams(location.search).get('open');if(open)openView(open).catch(()=>{});}).catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
})();
</script>
<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
