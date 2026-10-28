<?php
require_once '../config/config.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function cah($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ca_page_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

$hasTable = ca_page_table_exists($conn, 'church_assets') && ca_page_table_exists($conn, 'church_asset_maintenance') && ca_page_table_exists($conn, 'church_asset_loans');
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

$categories = ['Furniture', 'Musical Instruments', 'Sound & Media', 'Electronics & IT', 'Vehicles', 'Land & Buildings', 'Tents & Canopies', 'Kitchen & Catering', 'Office Equipment', 'Tools & Machinery', 'Other'];
$acquisitionTypes = ['Purchased', 'Donated', 'Built', 'Transferred'];
$conditions = ['New', 'Good', 'Fair', 'Poor', 'Damaged'];
$statuses = ['In Use', 'In Store', 'Under Repair', 'Lent Out', 'Lost', 'Disposed'];
$maintenanceTypes = ['Service', 'Repair', 'Inspection', 'Cleaning', 'Replacement of Parts', 'Other'];

$members = [];
if (ca_page_table_exists($conn, 'members')) {
    $res = $conn->query("SELECT id, member_no, full_name FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name");
    while ($res && $r = $res->fetch_assoc()) $members[] = $r;
}
function ca_member_options(array $members) {
    foreach ($members as $m) {
        echo '<option value="' . (int)$m['id'] . '">' . cah(trim($m['full_name'])) . (!empty($m['member_no']) ? ' (' . cah($m['member_no']) . ')' : '') . '</option>';
    }
}
function ca_options(array $values, $selected = null) {
    foreach ($values as $v) echo '<option' . ($v === $selected ? ' selected' : '') . '>' . cah($v) . '</option>';
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
        <h2><i class="fas fa-boxes-stacked"></i> Church Assets</h2>
        <p class="text-muted mb-0">Keep a register of church property, who looks after it and where it is, its value, its maintenance, and what has been lent out.</p>
    </div>
    <?php if ($hasTable): ?>
    <button type="button" class="btn btn-primary" id="btnOpenAsset"><i class="fas fa-plus"></i> New Asset</button>
    <?php endif; ?>
</div>

<?php if (!$hasTable): ?>
<div class="card"><div class="card-body"><strong>Setup required:</strong> the church assets tables were not found. Run <code>database/church_assets.sql</code>.</div></div>
<?php else: ?>

<div class="ca-stats-grid">
    <div class="ca-stat-card"><div class="ca-stat-icon"><i class="fas fa-boxes-stacked"></i></div><div><div class="ca-stat-value" id="statTotal">0</div><div class="ca-stat-label">Assets (<span id="statUnits">0</span> items)</div></div></div>
    <div class="ca-stat-card"><div class="ca-stat-icon"><i class="fas fa-sack-dollar"></i></div><div><div class="ca-stat-value" id="statValue">UGX 0</div><div class="ca-stat-label">Current Value</div></div></div>
    <div class="ca-stat-card"><div class="ca-stat-icon"><i class="fas fa-tags"></i></div><div><div class="ca-stat-value" id="statCost">UGX 0</div><div class="ca-stat-label">Purchase Cost</div></div></div>
    <div class="ca-stat-card"><div class="ca-stat-icon"><i class="fas fa-handshake"></i></div><div><div class="ca-stat-value" id="statLent">0</div><div class="ca-stat-label">Items Lent Out</div></div></div>
    <div class="ca-stat-card"><div class="ca-stat-icon"><i class="fas fa-screwdriver-wrench"></i></div><div><div class="ca-stat-value" id="statRepair">0</div><div class="ca-stat-label">Under Repair / Damaged</div></div></div>
    <div class="ca-stat-card"><div class="ca-stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="ca-stat-value" id="statDue">0</div><div class="ca-stat-label">Service Due (30 days)</div></div></div>
</div>

<div class="page-toolbar">
    <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="text" id="tableSearch" placeholder="Search asset, serial no, location, custodian..."></div>
    <div class="page-toolbar-filters">
        <select id="filterCategory" class="form-control"><option value="">All Categories</option><?php ca_options($categories); ?></select>
        <select id="filterStatus" class="form-control"><option value="">All Statuses</option><?php ca_options($statuses); ?></select>
        <select id="filterCondition" class="form-control"><option value="">All Conditions</option><?php ca_options($conditions); ?></select>
    </div>
    <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefresh"><i class="fas fa-rotate-right"></i> Refresh</button>
    </div>
</div>
<div id="pageAlert" class="ca-alert"></div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th>#</th><th>Asset No</th><th>Asset</th><th>Qty</th><th>Location</th><th>Custodian</th><th>Condition</th><th>Value (UGX)</th><th>Next Service</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody id="assetTableBody"><tr><td colspan="11" class="text-muted p-3">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="ca-modal-backdrop" id="caBackdrop"></div>

<!-- New / edit asset -->
<div class="ca-modal" id="assetModal" aria-hidden="true">
    <div class="ca-dialog">
        <form id="assetForm">
            <div class="ca-modal-header"><div class="ca-modal-title" id="assetTitle"><i class="fas fa-plus"></i> New Asset</div><button type="button" class="ca-close" data-close-modal>&times;</button></div>
            <div class="ca-modal-body">
                <div id="formAlert" class="ca-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= cah($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="assetId" value="">
                <div class="ca-grid">
                    <div class="ca-field ca-full"><label>Asset Name <span class="text-danger">*</span></label><input type="text" name="name" id="name" maxlength="200" placeholder="e.g. Yamaha PSR keyboard, plastic chairs, church van" required></div>
                    <div class="ca-field"><label>Category</label><select name="category" id="category"><?php ca_options($categories); ?></select></div>
                    <div class="ca-field"><label>Quantity</label><input type="number" name="quantity" id="quantity" min="1" step="1" value="1"></div>
                    <div class="ca-field"><label>Serial / Tag / Reg. No</label><input type="text" name="serial_no" id="serial_no" maxlength="120" placeholder="Serial number, number plate or land title"></div>
                    <div class="ca-field"><label>Location</label><input type="text" name="location" id="location" maxlength="200" placeholder="e.g. Main sanctuary, store, pastor's office"></div>
                    <div class="ca-field"><label>Custodian</label><input type="search" class="ca-member-search" data-for="custodian_member_id" placeholder="Search name or member no..." autocomplete="off"><select name="custodian_member_id" id="custodian_member_id"><option value="">Not assigned</option><?php ca_member_options($members); ?></select></div>
                    <div class="ca-field"><label>Condition</label><select name="item_condition" id="item_condition"><?php ca_options($conditions, 'Good'); ?></select></div>
                    <div class="ca-field"><label>Status</label><select name="status" id="status"><?php ca_options($statuses); ?></select></div>
                    <div class="ca-field ca-full"><label>Description</label><textarea name="description" id="description" rows="2" placeholder="Make, model, colour, size..."></textarea></div>
                    <div class="ca-full ca-section-label"><i class="fas fa-file-invoice-dollar"></i> Acquisition &amp; Value</div>
                    <div class="ca-field"><label>How Acquired</label><select name="acquisition_type" id="acquisition_type"><?php ca_options($acquisitionTypes); ?></select></div>
                    <div class="ca-field"><label id="sourceLabel">Supplier</label><input type="text" name="source_name" id="source_name" maxlength="200"></div>
                    <div class="ca-field"><label>Date Acquired</label><input type="date" name="acquired_date" id="acquired_date"></div>
                    <div class="ca-field"><label>Warranty Until</label><input type="date" name="warranty_until" id="warranty_until"></div>
                    <div class="ca-field"><label>Purchase Cost (UGX)</label><input type="number" name="purchase_cost" id="purchase_cost" min="0" step="0.01" value="0"></div>
                    <div class="ca-field"><label>Current Value (UGX)</label><input type="number" name="current_value" id="current_value" min="0" step="0.01" value="0"></div>
                    <div class="ca-field ca-full"><label>Notes</label><textarea name="notes" id="notes" rows="2"></textarea></div>
                </div>
            </div>
            <div class="ca-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
        </form>
    </div>
</div>

<!-- Asset details: maintenance and loans -->
<div class="ca-modal" id="viewModal" aria-hidden="true">
    <div class="ca-dialog ca-dialog-lg">
        <div class="ca-modal-header"><div class="ca-modal-title" id="viewTitle"><i class="fas fa-box"></i> Asset</div><button type="button" class="ca-close" data-close-modal>&times;</button></div>
        <div class="ca-modal-body">
            <div id="viewAlert" class="ca-alert"></div>
            <div id="viewDetails" class="ca-detail-grid"></div>

            <h4 class="ca-heading"><i class="fas fa-screwdriver-wrench"></i> Maintenance &amp; Repairs <span class="badge badge-info" id="maintenanceTotal">UGX 0</span></h4>
            <form id="maintenanceForm" class="ca-inline-form">
                <input type="hidden" name="csrf_token" value="<?= cah($csrfToken) ?>">
                <input type="hidden" name="action" value="maintenance_add">
                <input type="hidden" name="asset_id" class="js-asset-id">
                <div class="ca-field"><label>Date</label><input type="date" name="maintenance_date" id="maintenance_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="ca-field"><label>Work</label><select name="maintenance_type"><?php ca_options($maintenanceTypes); ?></select></div>
                <div class="ca-field ca-grow"><label>Details</label><input type="text" name="description" maxlength="255" placeholder="What was done"></div>
                <div class="ca-field"><label>Cost (UGX)</label><input type="number" name="cost" min="0" step="0.01" value="0"></div>
                <div class="ca-field"><label>Done By</label><input type="text" name="done_by" maxlength="150" placeholder="Technician or company"></div>
                <div class="ca-field"><label>Next Due</label><input type="date" name="next_due_date"></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button>
            </form>
            <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Work</th><th>Details</th><th>Cost (UGX)</th><th>Done By</th><th>Next Due</th><th></th></tr></thead><tbody id="maintenanceRows"></tbody></table></div>

            <h4 class="ca-heading"><i class="fas fa-handshake"></i> Loans <span class="badge badge-info" id="availableBadge"></span></h4>
            <form id="loanForm" class="ca-inline-form">
                <input type="hidden" name="csrf_token" value="<?= cah($csrfToken) ?>">
                <input type="hidden" name="action" value="loan_add">
                <input type="hidden" name="asset_id" class="js-asset-id">
                <div class="ca-field ca-grow"><label>Member</label><input type="search" class="ca-member-search" data-for="borrower_member_id" placeholder="Search name or member no..." autocomplete="off"><select name="borrower_member_id" id="borrower_member_id"><option value="">Not a member</option><?php ca_member_options($members); ?></select></div>
                <div class="ca-field"><label>Or Borrower Name</label><input type="text" name="borrower_name" maxlength="200" placeholder="Person, church or group"></div>
                <div class="ca-field"><label>Phone</label><input type="text" name="borrower_phone" maxlength="40"></div>
                <div class="ca-field ca-narrow"><label>Qty</label><input type="number" name="quantity" min="1" step="1" value="1" required></div>
                <div class="ca-field"><label>Loan Date</label><input type="date" name="loan_date" id="loan_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="ca-field"><label>Return By</label><input type="date" name="due_date"></div>
                <div class="ca-field ca-grow"><label>Purpose</label><input type="text" name="purpose" maxlength="255" placeholder="e.g. Wedding, funeral, crusade"></div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Lend</button>
            </form>
            <div class="table-responsive"><table class="table"><thead><tr><th>Borrower</th><th>Phone</th><th>Qty</th><th>Loan Date</th><th>Return By</th><th>Returned</th><th>Purpose</th><th></th></tr></thead><tbody id="loanRows"></tbody></table></div>
        </div>
        <div class="ca-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Close</button><button type="button" class="btn btn-warning" id="viewEditBtn"><i class="fas fa-edit"></i> Edit Asset</button></div>
    </div>
</div>

<div class="ca-modal" id="returnModal" aria-hidden="true">
    <div class="ca-dialog ca-dialog-sm">
        <form id="returnForm">
            <div class="ca-modal-header"><div class="ca-modal-title"><i class="fas fa-rotate-left"></i> Mark as Returned</div><button type="button" class="ca-close" data-close-return>&times;</button></div>
            <div class="ca-modal-body">
                <div id="returnAlert" class="ca-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= cah($csrfToken) ?>">
                <input type="hidden" name="action" value="loan_return">
                <input type="hidden" name="id" id="returnId">
                <p>Returned by <strong id="returnName"></strong></p>
                <div class="ca-grid">
                    <div class="ca-field"><label>Date Returned</label><input type="date" name="returned_date" id="returned_date" required></div>
                    <div class="ca-field"><label>Condition on Return</label><select name="return_condition"><?php ca_options($conditions, 'Good'); ?></select></div>
                </div>
            </div>
            <div class="ca-actions"><button type="button" class="btn btn-outline-secondary" data-close-return>Cancel</button><button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Returned</button></div>
        </form>
    </div>
</div>

<div class="ca-modal" id="deleteModal" aria-hidden="true">
    <div class="ca-dialog ca-dialog-sm">
        <form id="deleteForm">
            <div class="ca-modal-header"><div class="ca-modal-title"><i class="fas fa-trash"></i> Delete Asset</div><button type="button" class="ca-close" data-close-modal>&times;</button></div>
            <div class="ca-modal-body">
                <div id="deleteAlert" class="ca-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= cah($csrfToken) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <p>Delete asset <strong id="deleteName"></strong>?</p>
                <p class="text-muted">This also removes its maintenance history and loans. If the item was sold, given away or written off, set its status to Disposed instead so the record is kept.</p>
            </div>
            <div class="ca-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
        </form>
    </div>
</div>

<style>
.ca-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}.ca-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;gap:14px;align-items:center}.ca-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(37,99,235,.12);font-size:20px;color:#1d4ed8}.ca-stat-value{font-size:22px;font-weight:800;line-height:1.2}.ca-stat-label{font-size:13px;color:#64748b;margin-top:4px}.ca-field input,.ca-field select,.ca-field textarea{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px}.ca-member-search{margin-bottom:6px}.ca-alert{display:none;margin-top:10px;border-radius:10px;padding:10px 12px}.ca-alert.success{display:block;background:#dcfce7;color:#166534}.ca-alert.error{display:block;background:#fee2e2;color:#991b1b}.ca-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;z-index:9998}.ca-modal{position:fixed;inset:0;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px;z-index:9999}.ca-modal.show{display:flex}.ca-modal-backdrop.show{display:block}.ca-dialog{width:min(760px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}.ca-dialog-sm{width:min(520px,100%)}.ca-dialog-lg{width:min(1100px,100%)}.ca-modal-header{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:16px 18px}.ca-modal-title{font-weight:800;font-size:18px}.ca-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.ca-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}.ca-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ca-full{grid-column:1/-1}.ca-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}.ca-section-label{font-weight:800;margin-top:6px;padding-top:12px;border-top:1px solid #e5e7eb}.ca-actions{display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 18px}.ca-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.ca-detail-grid div{border:1px solid #e5e7eb;background:#f8fafc;border-radius:10px;padding:10px}.ca-detail-grid b{display:block;color:#64748b;font-size:12px}.ca-detail-grid .ca-full{white-space:pre-line}.ca-heading{margin:22px 0 10px;font-size:16px;font-weight:800}.ca-inline-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:10px}.ca-inline-form .ca-field{min-width:140px}.ca-inline-form .ca-field label{font-size:12px;margin-bottom:4px}.ca-inline-form .ca-grow{flex:1 1 220px}.ca-inline-form .ca-narrow{min-width:80px;width:80px}.ca-overdue{color:#b91c1c;font-weight:700}.table-actions{display:flex;gap:6px;flex-wrap:wrap}@media(max-width:900px){.ca-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ca-grid,.ca-detail-grid{grid-template-columns:1fr}}@media(max-width:560px){.ca-stats-grid{grid-template-columns:1fr}.ca-inline-form .ca-field{flex:1 1 100%}}
</style>

<script>
(function(){
'use strict';
const endpoint='process-church-assets.php';
let rows=[], current=null;
const qs=(s,r=document)=>r.querySelector(s); const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const num=v=>Number(v||0).toLocaleString();
const money=v=>'UGX '+num(v);
const today=()=>new Date().toISOString().slice(0,10);
const daysFromNow=n=>{const d=new Date();d.setDate(d.getDate()+n);return d.toISOString().slice(0,10);};
const statusClass={'In Use':'success','In Store':'info','Under Repair':'warning','Lent Out':'primary',Lost:'danger',Disposed:'secondary'};
const conditionClass={New:'success',Good:'success',Fair:'info',Poor:'warning',Damaged:'danger'};
const gone=a=>a.status==='Lost'||a.status==='Disposed';
function alertBox(el,msg,type='success'){if(!el)return;el.className='ca-alert '+(type==='error'?'error':'success');el.textContent=msg;}
function clearAlert(el){if(el){el.className='ca-alert';el.textContent='';}}
function openModal(id){qs('#caBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
function closeModals(){qsa('.ca-modal,.ca-modal-backdrop').forEach(m=>m.classList.remove('show'));document.body.style.overflow='';}
async function request(url,opts={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts));const text=await res.text();try{return JSON.parse(text);}catch(e){throw new Error(text?('Invalid server response: '+text.slice(0,220)):'Empty server response. Check the PHP error log.');}}
async function postForm(form){return request(endpoint,{method:'POST',body:new FormData(form)});}
async function postData(data){const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));fd.append('csrf_token',<?= json_encode($csrfToken) ?>);return request(endpoint,{method:'POST',body:fd});}
function badge(map,s){return `<span class="badge badge-${map[s]||'secondary'}">${esc(s||'—')}</span>`;}
function dueCell(d){if(!d)return '—';return d<today()?`<span class="ca-overdue" title="Overdue">${esc(d)}</span>`:esc(d);}

/* Member search boxes: filter the select below them by name or member number. */
const memberLists={};
qsa('.ca-member-search').forEach(input=>{const sel=qs('#'+input.dataset.for);memberLists[sel.id]=qsa('option',sel).filter(o=>o.value);input.addEventListener('input',()=>filterMembers(sel,input.value));});
function filterMembers(sel,term){const t=(term||'').trim().toLowerCase(),prev=sel.value,all=memberLists[sel.id];const matches=t?all.filter(o=>o.textContent.toLowerCase().includes(t)):all;while(sel.options.length>1)sel.remove(1);matches.forEach(o=>sel.appendChild(o));sel.value=matches.length===1&&t?matches[0].value:(matches.some(o=>o.value===prev)?prev:'');}
function resetMemberSearch(form){qsa('.ca-member-search',form).forEach(input=>{input.value='';filterMembers(qs('#'+input.dataset.for),'');});}

function renderStats(items){let units=0,value=0,cost=0,lent=0,repair=0,due=0;const soon=daysFromNow(30);items.forEach(a=>{if(gone(a))return;units+=Number(a.quantity||0);value+=Number(a.current_value||0);cost+=Number(a.purchase_cost||0);lent+=Number(a.qty_lent||0);if(a.status==='Under Repair'||a.item_condition==='Damaged')repair++;if(a.next_due_date&&a.next_due_date<=soon)due++;});qs('#statTotal').textContent=num(items.filter(a=>!gone(a)).length);qs('#statUnits').textContent=num(units);qs('#statValue').textContent=money(value);qs('#statCost').textContent=money(cost);qs('#statLent').textContent=num(lent);qs('#statRepair').textContent=num(repair);qs('#statDue').textContent=num(due);}
function filteredRows(){const term=(qs('#tableSearch').value||'').toLowerCase(),cat=qs('#filterCategory').value,st=qs('#filterStatus').value,co=qs('#filterCondition').value;return rows.filter(a=>(!cat||a.category===cat)&&(!st||a.status===st)&&(!co||a.item_condition===co)&&Object.values(a).join(' ').toLowerCase().includes(term));}
function renderTable(){const items=filteredRows(),tbody=qs('#assetTableBody');renderStats(items);if(!items.length){tbody.innerHTML='<tr><td colspan="11" class="text-muted p-3">No assets found.</td></tr>';return;}tbody.innerHTML=items.map((a,i)=>`<tr><td>${i+1}</td><td><span class="badge badge-primary">${esc(a.asset_no)}</span></td><td><strong>${esc(a.name)}</strong><br><small class="text-muted">${esc(a.category)}${a.serial_no?' · '+esc(a.serial_no):''}</small></td><td>${num(a.quantity)}${Number(a.qty_lent)>0?`<br><small class="text-muted">${num(a.qty_lent)} lent</small>`:''}</td><td>${esc(a.location||'—')}</td><td>${esc(a.custodian_name||'—')}</td><td>${badge(conditionClass,a.item_condition)}</td><td>${num(a.current_value)}</td><td>${dueCell(a.next_due_date)}</td><td>${badge(statusClass,a.status)}</td><td><div class="table-actions"><button type="button" class="btn btn-sm btn-info" data-action="view" data-id="${Number(a.id)}" title="Maintenance, loans and details"><i class="fas fa-eye"></i></button><button type="button" class="btn btn-sm btn-warning" data-action="edit" data-id="${Number(a.id)}" title="Edit"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${Number(a.id)}" data-name="${esc(a.name)}" title="Delete"><i class="fas fa-trash"></i></button></div></td></tr>`).join('');}
async function load(){clearAlert(qs('#pageAlert'));const data=await request(endpoint+'?action=list');if(!data.success)throw new Error(data.message||'Failed to load assets.');rows=data.items||[];renderTable();}

function sourceLabel(){qs('#sourceLabel').textContent=qs('#acquisition_type').value==='Donated'?'Donated By':(qs('#acquisition_type').value==='Transferred'?'Transferred From':'Supplier / Contractor');}
function resetForm(){const f=qs('#assetForm');f.reset();resetMemberSearch(f);qs('#formAction').value='create';qs('#assetId').value='';qs('#assetTitle').innerHTML='<i class="fas fa-plus"></i> New Asset';sourceLabel();clearAlert(qs('#formAlert'));}
async function openEdit(id){resetForm();const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#pageAlert'),data.message||'Asset not found.','error');const a=data.item;qs('#formAction').value='update';qs('#assetId').value=a.id;qs('#assetTitle').innerHTML='<i class="fas fa-edit"></i> Edit Asset';['name','category','quantity','serial_no','location','custodian_member_id','item_condition','status','description','acquisition_type','source_name','acquired_date','warranty_until','purchase_cost','current_value','notes'].forEach(k=>{if(qs('#'+k))qs('#'+k).value=a[k]??'';});sourceLabel();closeModals();openModal('#assetModal');}

async function openView(id,keepOpen){const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#pageAlert'),data.message||'Asset not found.','error');const a=data.item,maint=data.maintenance||[],loans=data.loans||[];current=a;
  qsa('.js-asset-id').forEach(x=>x.value=a.id);
  qs('#viewTitle').innerHTML=`<i class="fas fa-box"></i> ${esc(a.asset_no)} · ${esc(a.name)}`;
  const warranty=a.warranty_until?(a.warranty_until<today()?esc(a.warranty_until)+' (expired)':esc(a.warranty_until)):'—';
  qs('#viewDetails').innerHTML=[['Category',esc(a.category)],['Quantity',num(a.quantity)],['Serial / Tag',esc(a.serial_no||'—')],['Location',esc(a.location||'—')],['Custodian',esc(a.custodian_name||'—')],['Status',badge(statusClass,a.status)],['Condition',badge(conditionClass,a.item_condition)],['How Acquired',esc(a.acquisition_type)+(a.source_name?' · '+esc(a.source_name):'')],['Date Acquired',esc(a.acquired_date||'—')],['Purchase Cost',money(a.purchase_cost)],['Current Value',money(a.current_value)],['Warranty Until',warranty]].map(x=>`<div><b>${x[0]}</b>${x[1]}</div>`).join('')+`<div class="ca-full"><b>Description</b>${esc(a.description||'—')}</div>`+(a.notes?`<div class="ca-full"><b>Notes</b>${esc(a.notes)}</div>`:'');
  qs('#maintenanceTotal').textContent=money(a.maintenance_cost);
  qs('#maintenanceRows').innerHTML=maint.length?maint.map((m,i)=>`<tr><td>${esc(m.maintenance_date)}</td><td>${esc(m.maintenance_type)}</td><td>${esc(m.description||'')}</td><td><strong>${num(m.cost)}</strong></td><td>${esc(m.done_by||'—')}</td><td>${i===0?dueCell(m.next_due_date):esc(m.next_due_date||'—')}</td><td><button type="button" class="btn btn-sm btn-outline-danger" data-action="maintenance_delete" data-id="${Number(m.id)}" title="Delete entry"><i class="fas fa-trash"></i></button></td></tr>`).join(''):'<tr><td colspan="7" class="text-muted">No maintenance recorded yet.</td></tr>';
  const available=Number(a.quantity||0)-Number(a.qty_lent||0);
  qs('#availableBadge').textContent=num(available)+' of '+num(a.quantity)+' available';
  qs('#loanRows').innerHTML=loans.length?loans.map(l=>{const open=!l.returned_date,late=open&&l.due_date&&l.due_date<today();return `<tr><td>${esc(l.borrower||'—')}${l.member_no?' <small class="text-muted">('+esc(l.member_no)+')</small>':''}</td><td>${esc(l.phone||'—')}</td><td>${num(l.quantity)}</td><td>${esc(l.loan_date)}</td><td>${l.due_date?(late?`<span class="ca-overdue">${esc(l.due_date)} (overdue)</span>`:esc(l.due_date)):'—'}</td><td>${open?'<span class="badge badge-warning">Not yet</span>':esc(l.returned_date)+(l.return_condition?' · '+badge(conditionClass,l.return_condition):'')}</td><td>${esc(l.purpose||'')}</td><td><div class="table-actions">${open?`<button type="button" class="btn btn-sm btn-success" data-action="loan_return" data-id="${Number(l.id)}" data-name="${esc(l.borrower||'')}" title="Mark as returned"><i class="fas fa-rotate-left"></i></button>`:''}<button type="button" class="btn btn-sm btn-outline-danger" data-action="loan_delete" data-id="${Number(l.id)}" title="Delete loan"><i class="fas fa-trash"></i></button></div></td></tr>`;}).join(''):'<tr><td colspan="8" class="text-muted">Nothing lent out yet.</td></tr>';
  if(!keepOpen){clearAlert(qs('#viewAlert'));qs('#maintenanceForm').reset();qs('#maintenance_date').value=today();qs('#loanForm').reset();resetMemberSearch(qs('#loanForm'));qs('#loan_date').value=today();openModal('#viewModal');}}
async function refreshView(msg){alertBox(qs('#viewAlert'),msg);await openView(current.id,true);load().catch(()=>{});}

qs('#btnOpenAsset').addEventListener('click',()=>{resetForm();openModal('#assetModal');});
qs('#acquisition_type').addEventListener('change',sourceLabel);
qs('#btnRefresh').addEventListener('click',()=>{['#tableSearch','#filterCategory','#filterStatus','#filterCondition'].forEach(s=>qs(s).value='');load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));});
['#tableSearch','#filterCategory','#filterStatus','#filterCondition'].forEach(s=>qs(s).addEventListener('input',renderTable));
qsa('[data-close-modal]').forEach(b=>b.addEventListener('click',closeModals));qs('#caBackdrop').addEventListener('click',closeModals);
/* The return dialog sits on top of the asset details, so closing it leaves the details open. */
function closeReturn(){qs('#returnModal').classList.remove('show');}
qsa('[data-close-return]').forEach(b=>b.addEventListener('click',closeReturn));
document.addEventListener('keydown',e=>{if(e.key!=='Escape')return;if(qs('#returnModal').classList.contains('show'))closeReturn();else closeModals();});
qs('#viewEditBtn').addEventListener('click',()=>{if(current)openEdit(current.id);});
document.addEventListener('click',async e=>{const b=e.target.closest('[data-action]');if(!b)return;const id=b.dataset.id,a=b.dataset.action;
  if(a==='view')openView(id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
  if(a==='edit')openEdit(id).catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
  if(a==='delete'){qs('#deleteId').value=id;qs('#deleteName').textContent=b.dataset.name||'';clearAlert(qs('#deleteAlert'));openModal('#deleteModal');}
  if(a==='loan_return'){qs('#returnForm').reset();qs('#returnId').value=id;qs('#returnName').textContent=b.dataset.name||'the borrower';qs('#returned_date').value=today();clearAlert(qs('#returnAlert'));qs('#returnModal').classList.add('show');}
  if(a==='maintenance_delete'||a==='loan_delete'){if(!confirm(a==='loan_delete'?'Delete this loan record?':'Delete this maintenance entry?'))return;try{const d=await postData({action:a,id});if(!d.success)return alertBox(qs('#viewAlert'),d.message||'Failed.','error');await refreshView(d.message);}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}}
});
qs('#assetForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#formAlert'),data.message||'Save failed.','error');closeModals();alertBox(qs('#pageAlert'),data.message||'Saved.');await load();}catch(err){alertBox(qs('#formAlert'),err.message,'error');}});
['#maintenanceForm','#loanForm'].forEach(sel=>qs(sel).addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget;try{const data=await postForm(form);if(!data.success)return alertBox(qs('#viewAlert'),data.message||'Save failed.','error');form.reset();if(sel==='#loanForm'){resetMemberSearch(form);qs('#loan_date').value=today();}else qs('#maintenance_date').value=today();await refreshView(data.message);}catch(err){alertBox(qs('#viewAlert'),err.message,'error');}}));
qs('#returnForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#returnAlert'),data.message||'Failed.','error');closeReturn();await refreshView(data.message);}catch(err){alertBox(qs('#returnAlert'),err.message,'error');}});
qs('#deleteForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#deleteAlert'),data.message||'Delete failed.','error');closeModals();alertBox(qs('#pageAlert'),data.message||'Deleted.');await load();}catch(err){alertBox(qs('#deleteAlert'),err.message,'error');}});
load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
})();
</script>
<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
