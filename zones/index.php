<?php
require_once '../config/config.php';
requireLogin();
$pageTitle = 'Church Zones';

if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$conn->set_charset('utf8mb4');

function zh($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function z_table_exists(mysqli $conn, string $table): bool {
    $st = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->bind_param('s', $table); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return (int)($row['c'] ?? 0) > 0;
}
function z_scalar(mysqli $conn, string $sql): float {
    try { $r=$conn->query($sql); if(!$r) return 0; $row=$r->fetch_row(); return (float)($row[0] ?? 0); } catch(Throwable $e){ return 0; }
}


$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
$totalZones = z_scalar($conn, "SELECT COUNT(*) FROM church_zones");
$activeZones = z_scalar($conn, "SELECT COUNT(*) FROM church_zones WHERE status='Active'");
$inactiveZones = z_scalar($conn, "SELECT COUNT(*) FROM church_zones WHERE status='Inactive'");
$withLeaders = z_scalar($conn, "SELECT COUNT(*) FROM church_zones WHERE leader_member_id IS NOT NULL AND leader_member_id > 0");

$members = [];
if (z_table_exists($conn, 'members')) {
    $res = $conn->query("SELECT id, member_no, full_name, phone, email FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name");
    while($res && $row=$res->fetch_assoc()) $members[]=$row;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="main-content zones-module">
  <?php include '../includes/topbar.php'; ?>
  <div class="content-area">
    <?php if (function_exists('showFlash')) showFlash(); ?>

    <div class="page-header module-page-header">
      <div>
        <h2><i class="fas fa-map-marked-alt text-primary"></i> Church Zones</h2>
        <p>Manage church zones, leaders, meeting places, and locations.</p>
      </div>
      <button type="button" class="btn btn-primary" id="btnOpenZone"><i class="fas fa-plus"></i> Add Zone</button>
    </div>

    <div class="stats-grid module-stats-grid">
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-map"></i></div><div><div class="stat-value" id="zoneStatTotal"><?= number_format($totalZones) ?></div><div class="stat-label">Total Zones</div></div></div>
      <div class="stat-card success"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div><div class="stat-value" id="zoneStatActive"><?= number_format($activeZones) ?></div><div class="stat-label">Active Zones</div></div></div>
      <div class="stat-card danger"><div class="stat-icon"><i class="fas fa-pause-circle"></i></div><div><div class="stat-value" id="zoneStatInactive"><?= number_format($inactiveZones) ?></div><div class="stat-label">Inactive Zones</div></div></div>
      <div class="stat-card accent"><div class="stat-icon"><i class="fas fa-user-tie"></i></div><div><div class="stat-value" id="zoneStatLeaders"><?= number_format($withLeaders) ?></div><div class="stat-label">With Leaders</div></div></div>
    </div>

    <div class="page-toolbar">
      <div class="page-toolbar-search">
        <i class="fas fa-search"></i>
        <input type="text" id="zoneSearch" class="form-control" placeholder="Search zone, leader, location...">
      </div>
      <div class="page-toolbar-filters">
        <select id="zoneStatusFilter" class="form-control">
          <option value="">All Status</option>
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
        </select>
      </div>
      <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshZones"><i class="fas fa-rotate-right"></i> Refresh</button>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div id="zoneAlert" class="ajax-alert"></div>
        <div class="table-responsive">
          <table class="module-table">
            <thead><tr><th>#</th><th>Zone</th><th>Leader</th><th>Contact</th><th>Meeting</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="zoneTableBody"><tr><td colspan="8" class="text-center text-muted">Loading zones...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="app-modal-backdrop" id="zoneBackdrop"></div>

<div class="app-modal" id="zoneFormModal" aria-hidden="true"><div class="app-dialog app-dialog-lg">
  <form id="zoneForm">
    <div class="app-modal-header"><h3 id="zoneFormTitle"><i class="fas fa-map-marker-alt"></i> Add Zone</h3><button type="button" class="modal-close" data-close-zone>&times;</button></div>
    <div class="app-modal-body">
      <div id="zoneFormAlert" class="ajax-alert"></div>
      <input type="hidden" name="csrf_token" value="<?= zh($csrfToken) ?>">
      <input type="hidden" name="action" id="zoneAction" value="create">
      <input type="hidden" name="id" id="zoneId">
      <div class="form-grid">
        <div class="form-group"><label>Zone Name <span class="req">*</span></label><input type="text" name="zone_name" id="zone_name" class="form-control" required maxlength="150"></div>
        <div class="form-group"><label>Zone Code</label><input type="text" name="zone_code" id="zone_code" class="form-control" maxlength="50" placeholder="e.g. ZONE-001"></div>
        <div class="form-group"><label>Zone Leader</label><select name="leader_member_id" id="leader_member_id" class="form-control"><option value="0">-- Select Leader --</option><?php foreach($members as $m): $name=trim((string)($m['full_name']??'')); ?><option value="<?= (int)$m['id'] ?>" data-phone="<?= zh($m['phone'] ?? '') ?>" data-email="<?= zh($m['email'] ?? '') ?>"><?= zh($name) ?><?= !empty($m['member_no'])?' ('.zh($m['member_no']).')':'' ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Status</label><select name="status" id="zone_status" class="form-control"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
        <div class="form-group"><label>Phone</label><input type="text" name="phone" id="zone_phone" class="form-control" maxlength="50"></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" id="zone_email" class="form-control" maxlength="150"></div>
        <div class="form-group"><label>Location / Area</label><input type="text" name="location" id="zone_location" class="form-control" maxlength="255"></div>
        <div class="form-group"><label>Meeting Place</label><input type="text" name="meeting_place" id="meeting_place" class="form-control" maxlength="255"></div>
        <div class="form-group"><label>Meeting Day</label><select name="meeting_day" id="meeting_day" class="form-control"><option value="">Select</option><option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option></select></div>
        <div class="form-group"><label>Meeting Time</label><input type="time" name="meeting_time" id="meeting_time" class="form-control"></div>
        <div class="form-group full-span"><label>Description / Notes</label><textarea name="description" id="description" class="form-control" rows="3"></textarea></div>
      </div>
    </div>
    <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-zone>Cancel</button><button type="submit" class="btn btn-primary" id="saveZoneBtn"><i class="fas fa-save"></i> Save Zone</button></div>
  </form>
</div></div>

<div class="app-modal" id="viewZoneModal" aria-hidden="true"><div class="app-dialog">
  <div class="app-modal-header"><h3><i class="fas fa-eye"></i> Zone Details</h3><button type="button" class="modal-close" data-close-zone>&times;</button></div>
  <div class="app-modal-body"><div id="viewZoneContent" class="detail-grid"></div></div>
  <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-zone>Close</button></div>
</div></div>

<div class="app-modal" id="deleteZoneModal" aria-hidden="true"><div class="app-dialog app-dialog-sm">
  <form id="deleteZoneForm">
    <div class="app-modal-header"><h3><i class="fas fa-trash"></i> Delete Zone</h3><button type="button" class="modal-close" data-close-zone>&times;</button></div>
    <div class="app-modal-body"><div id="deleteZoneAlert" class="ajax-alert"></div><input type="hidden" name="csrf_token" value="<?= zh($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteZoneId"><p>Delete zone <strong id="deleteZoneName"></strong>?</p><p class="text-muted">This will remove the zone record permanently.</p></div>
    <div class="app-modal-footer"><button type="button" class="btn btn-outline" data-close-zone>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
  </form>
</div></div>

<script>window.ChurchZonesConfig={endpoint:'process-zones.php'};</script>
<script src="../assets/js/main.js?v=<?= time() ?>"></script>
<script>
(function(){
'use strict';
const cfg=window.ChurchZonesConfig||{}; if(!cfg.endpoint) return;
let rows=[];
const qs=(s,r=document)=>r.querySelector(s), qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const money=v=>Number(v||0).toLocaleString();
function alertBox(el,msg,type='success'){ if(!el)return; el.className='ajax-alert '+(type==='error'?'error':'success'); el.textContent=msg; }
function clearAlert(el){ if(el){el.className='ajax-alert'; el.textContent='';} }
function openModal(id){ qs('#zoneBackdrop')?.classList.add('show'); qs(id)?.classList.add('show'); document.body.style.overflow='hidden'; }
function closeModals(){ qsa('#zoneBackdrop,.app-modal').forEach(x=>x.classList.remove('show')); document.body.style.overflow=''; }
async function req(url,opts={}){ const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts)); const txt=await res.text(); try{return JSON.parse(txt);}catch(e){throw new Error('Invalid server response: '+txt.slice(0,250));} }
async function post(form){ return req(cfg.endpoint,{method:'POST',body:new FormData(form)}); }
function badge(t){ return `<span class="badge ${t==='Active'?'badge-success':'badge-secondary'}">${esc(t||'—')}</span>`; }
function setStats(items){ qs('#zoneStatTotal').textContent=money(items.length); qs('#zoneStatActive').textContent=money(items.filter(x=>x.status==='Active').length); qs('#zoneStatInactive').textContent=money(items.filter(x=>x.status==='Inactive').length); qs('#zoneStatLeaders').textContent=money(items.filter(x=>Number(x.leader_member_id||0)>0).length); }
function render(items){ const tb=qs('#zoneTableBody'); if(!items.length){ tb.innerHTML='<tr><td colspan="8" class="text-center text-muted">No zones found.</td></tr>'; return; } tb.innerHTML=items.map((z,i)=>`<tr><td>${i+1}</td><td><strong>${esc(z.zone_name)}</strong><br><small class="text-muted">${esc(z.zone_code||'No code')}</small></td><td>${esc(z.leader_name||'—')}</td><td>${esc(z.phone||'—')}<br><small>${esc(z.email||'')}</small></td><td>${esc(z.meeting_day||'—')} ${z.meeting_time?'<br><small>'+esc(z.meeting_time)+'</small>':''}</td><td>${esc(z.location||'—')}</td><td>${badge(z.status)}</td><td><div class="table-action"><button class="btn btn-sm btn-info btn-icon" data-zone-action="view" data-id="${Number(z.id)}"><i class="fas fa-eye"></i></button><button class="btn btn-sm btn-primary btn-icon" data-zone-action="edit" data-id="${Number(z.id)}"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-danger btn-icon" data-zone-action="delete" data-id="${Number(z.id)}" data-name="${esc(z.zone_name)}"><i class="fas fa-trash"></i></button></div></td></tr>`).join(''); }
function applyFilters(){ const s=(qs('#zoneSearch')?.value||'').toLowerCase(); const st=qs('#zoneStatusFilter')?.value||''; render(rows.filter(x=>(!st||x.status===st) && Object.values(x).join(' ').toLowerCase().includes(s))); }
async function loadZones(){ clearAlert(qs('#zoneAlert')); const d=await req(cfg.endpoint+'?action=list'); if(!d.success) throw new Error(d.message||'Failed to load zones.'); rows=d.items||[]; setStats(rows); applyFilters(); }
function resetForm(){ qs('#zoneForm').reset(); qs('#zoneAction').value='create'; qs('#zoneId').value=''; qs('#zoneFormTitle').innerHTML='<i class="fas fa-map-marker-alt"></i> Add Zone'; qs('#saveZoneBtn').innerHTML='<i class="fas fa-save"></i> Save Zone'; clearAlert(qs('#zoneFormAlert')); }
async function openEdit(id){ resetForm(); const d=await req(cfg.endpoint+'?action=get&id='+encodeURIComponent(id)); if(!d.success) return alertBox(qs('#zoneAlert'),d.message||'Not found','error'); const z=d.item; qs('#zoneAction').value='update'; qs('#zoneId').value=z.id||''; qs('#zoneFormTitle').innerHTML='<i class="fas fa-edit"></i> Edit Zone'; qs('#saveZoneBtn').innerHTML='<i class="fas fa-save"></i> Update Zone'; ['zone_name','zone_code','leader_member_id','zone_phone','zone_email','zone_location','meeting_place','meeting_day','meeting_time','description'].forEach(()=>{}); qs('#zone_name').value=z.zone_name||''; qs('#zone_code').value=z.zone_code||''; qs('#leader_member_id').value=z.leader_member_id||0; qs('#zone_phone').value=z.phone||''; qs('#zone_email').value=z.email||''; qs('#zone_location').value=z.location||''; qs('#meeting_place').value=z.meeting_place||''; qs('#meeting_day').value=z.meeting_day||''; qs('#meeting_time').value=z.meeting_time||''; qs('#description').value=z.description||''; qs('#zone_status').value=z.status||'Active'; openModal('#zoneFormModal'); }
async function openView(id){ const d=await req(cfg.endpoint+'?action=get&id='+encodeURIComponent(id)); if(!d.success) return alertBox(qs('#zoneAlert'),d.message||'Not found','error'); const z=d.item; const fields=[['Zone Name',z.zone_name],['Zone Code',z.zone_code],['Leader',z.leader_name],['Phone',z.phone],['Email',z.email],['Location',z.location],['Meeting Place',z.meeting_place],['Meeting Day',z.meeting_day],['Meeting Time',z.meeting_time],['Status',z.status],['Description',z.description]]; qs('#viewZoneContent').innerHTML=fields.map(([k,v])=>`<div class="detail-item"><b>${esc(k)}</b><span>${esc(v||'—')}</span></div>`).join(''); openModal('#viewZoneModal'); }
document.addEventListener('click',e=>{ const add=e.target.closest('#btnOpenZone'); if(add){ resetForm(); openModal('#zoneFormModal'); return; } if(e.target.closest('[data-close-zone]')||e.target.id==='zoneBackdrop'){ closeModals(); return; } const btn=e.target.closest('[data-zone-action]'); if(!btn) return; const id=btn.dataset.id; const a=btn.dataset.zoneAction; if(a==='view') openView(id).catch(err=>alertBox(qs('#zoneAlert'),err.message,'error')); if(a==='edit') openEdit(id).catch(err=>alertBox(qs('#zoneAlert'),err.message,'error')); if(a==='delete'){ qs('#deleteZoneId').value=id; qs('#deleteZoneName').textContent=btn.dataset.name||'this zone'; clearAlert(qs('#deleteZoneAlert')); openModal('#deleteZoneModal'); } });
qs('#leader_member_id')?.addEventListener('change',function(){ const o=this.options[this.selectedIndex]; if(o){ if(!qs('#zone_phone').value) qs('#zone_phone').value=o.dataset.phone||''; if(!qs('#zone_email').value) qs('#zone_email').value=o.dataset.email||''; }});
qs('#zoneSearch')?.addEventListener('input',applyFilters); qs('#zoneStatusFilter')?.addEventListener('change',applyFilters); qs('#btnRefreshZones')?.addEventListener('click',()=>{ ['zoneSearch','zoneStatusFilter'].forEach(id=>{const el=qs('#'+id); if(el) el.value='';}); loadZones().catch(err=>alertBox(qs('#zoneAlert'),err.message,'error')); });
qs('#zoneForm')?.addEventListener('submit',async e=>{ e.preventDefault(); try{ const d=await post(e.currentTarget); if(!d.success) return alertBox(qs('#zoneFormAlert'),d.message||'Save failed','error'); closeModals(); alertBox(qs('#zoneAlert'),d.message||'Saved'); await loadZones(); }catch(err){ alertBox(qs('#zoneFormAlert'),err.message,'error'); }});
qs('#deleteZoneForm')?.addEventListener('submit',async e=>{ e.preventDefault(); try{ const d=await post(e.currentTarget); if(!d.success) return alertBox(qs('#deleteZoneAlert'),d.message||'Delete failed','error'); closeModals(); alertBox(qs('#zoneAlert'),d.message||'Deleted'); await loadZones(); }catch(err){ alertBox(qs('#deleteZoneAlert'),err.message,'error'); }});
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeModals(); });
loadZones().catch(err=>alertBox(qs('#zoneAlert'),err.message,'error'));
})();
</script>
<?php include '../includes/footer.php'; ?>
