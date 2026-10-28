<?php
require_once '../config/config.php';
requireLogin();
$pageTitle = 'Church Projects';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
mysqli_set_charset($conn,'utf8mb4');
function pjh($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pj_col_page($table,$col){ global $conn; $t=mysqli_real_escape_string($conn,$table); $c=mysqli_real_escape_string($conn,$col); $r=mysqli_query($conn,"SHOW COLUMNS FROM `$t` LIKE '$c'"); return $r && mysqli_num_rows($r)>0; }
function pj_scalar($sql){ global $conn; $r=@mysqli_query($conn,$sql); if(!$r) return 0; $row=mysqli_fetch_row($r); return (float)($row[0]??0); }

$totalProjects = pj_scalar("SELECT COUNT(*) FROM projects");
$activeProjects = pj_col_page('projects','status') ? pj_scalar("SELECT COUNT(*) FROM projects WHERE COALESCE(status,'Active')='Active'") : $totalProjects;
$completedProjects = pj_col_page('projects','status') ? pj_scalar("SELECT COUNT(*) FROM projects WHERE status='Completed'") : 0;
$totalTarget = pj_col_page('projects','target_amount') ? pj_scalar("SELECT COALESCE(SUM(target_amount),0) FROM projects") : 0;
$totalRaised = (mysqli_query($conn,"SHOW TABLES LIKE 'contributions'") && pj_col_page('contributions','project_id') && pj_col_page('contributions','amount')) ? pj_scalar("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE project_id IS NOT NULL") : 0;
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo function_exists('showFlash') ? showFlash() : ''; ?>

<div class="page-header">
  <div>
    <h2><i class="fas fa-project-diagram"></i> Church Projects</h2>
    <p class="text-muted mb-0">Manage church projects, targets, status and contribution progress.</p>
  </div>
  <button type="button" class="btn btn-primary" id="btnOpenProject"><i class="fas fa-plus"></i> New Project</button>
</div>

<div class="stats-grid project-stats-grid">
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-project-diagram"></i></div><div><h3 id="statTotal"><?=number_format($totalProjects)?></h3><p>Total Projects</p></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div><h3 id="statActive"><?=number_format($activeProjects)?></h3><p>Active Projects</p></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-flag-checkered"></i></div><div><h3 id="statCompleted"><?=number_format($completedProjects)?></h3><p>Completed</p></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-bullseye"></i></div><div><h3 id="statTarget"><?=number_format($totalTarget)?></h3><p>Target UGX</p></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div><div><h3 id="statRaised"><?=number_format($totalRaised)?></h3><p>Raised UGX</p></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-percentage"></i></div><div><h3 id="statProgress"><?=$totalTarget>0?round($totalRaised/$totalTarget*100):0?>%</h3><p>Overall Progress</p></div></div>
</div>

<div class="card">
  <div class="card-body">
    <div class="page-toolbar">
      <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="text" id="projectSearch" class="form-control" placeholder="Search projects..."></div>
      <div class="page-toolbar-filters"><select id="statusFilter" class="form-control"><option value="">All Statuses</option><option>Active</option><option>Paused</option><option>Completed</option><option>Inactive</option></select></div>
      <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshProjects"><i class="fas fa-rotate-right"></i> Refresh</button></div>
    </div>
    <div id="projectAlert" class="ajax-alert"></div>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>#</th><th>Project No</th><th>Name</th><th>Type</th><th>Status</th><th>Target</th><th>Raised</th><th>Progress</th><th>Start Date</th><th>Actions</th></tr></thead>
        <tbody id="projectTableBody"><tr><td colspan="10" class="text-muted">Loading projects...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="ajax-modal-backdrop" id="projectBackdrop"></div>

<div class="ajax-modal" id="projectFormModal" aria-hidden="true">
  <div class="ajax-dialog">
    <form id="projectForm">
      <div class="ajax-modal-header"><h3 id="projectFormTitle"><i class="fas fa-plus"></i> New Project</h3><button type="button" class="modal-close" data-project-close>&times;</button></div>
      <div class="ajax-modal-body">
        <div id="projectFormAlert" class="ajax-alert"></div>
        <input type="hidden" name="csrf_token" value="<?=pjh($csrfToken)?>">
        <input type="hidden" name="action" id="projectAction" value="create">
        <input type="hidden" name="id" id="projectId">
        <div class="form-grid">
          <div class="form-group"><label>Project Name *</label><input type="text" name="name" id="projectName" class="form-control" required></div>
          <div class="form-group"><label>Project Type *</label><select name="type" id="projectType" class="form-control" required><option>Agriculture</option><option>Crafts</option><option>Construction</option><option>Welfare</option><option>Education</option><option>Other</option></select></div>
          <div class="form-group form-full"><label>Description</label><textarea name="description" id="projectDescription" class="form-control" rows="3"></textarea></div>
          <div class="form-group"><label>Target Amount (UGX)</label><input type="number" name="target_amount" id="targetAmount" class="form-control" value="0" step="0.01" min="0"></div>
          <div class="form-group"><label>Start Date *</label><input type="date" name="start_date" id="startDate" class="form-control" value="<?=date('Y-m-d')?>" required></div>
          <div class="form-group"><label>End Date</label><input type="date" name="end_date" id="endDate" class="form-control"></div>
          <div class="form-group"><label>Status</label><select name="status" id="projectStatus" class="form-control"><option>Active</option><option>Paused</option><option>Completed</option><option>Inactive</option></select></div>
        </div>
      </div>
      <div class="ajax-modal-footer"><button type="button" class="btn btn-outline" data-project-close>Cancel</button><button type="submit" class="btn btn-primary" id="projectSaveBtn"><i class="fas fa-save"></i> Save Project</button></div>
    </form>
  </div>
</div>

<div class="ajax-modal" id="projectViewModal" aria-hidden="true">
  <div class="ajax-dialog ajax-dialog-lg">
    <div class="ajax-modal-header"><h3><i class="fas fa-eye"></i> Project Details</h3><button type="button" class="modal-close" data-project-close>&times;</button></div>
    <div class="ajax-modal-body" id="projectViewContent"></div>
    <div class="ajax-modal-footer"><button type="button" class="btn btn-outline" data-project-close>Close</button></div>
  </div>
</div>

<div class="ajax-modal" id="projectDeleteModal" aria-hidden="true">
  <div class="ajax-dialog ajax-dialog-sm">
    <form id="projectDeleteForm">
      <div class="ajax-modal-header"><h3><i class="fas fa-trash"></i> Delete Project</h3><button type="button" class="modal-close" data-project-close>&times;</button></div>
      <div class="ajax-modal-body">
        <div id="projectDeleteAlert" class="ajax-alert"></div>
        <input type="hidden" name="csrf_token" value="<?=pjh($csrfToken)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteProjectId">
        <p>Are you sure you want to delete/deactivate <strong id="deleteProjectName"></strong>?</p>
      </div>
      <div class="ajax-modal-footer"><button type="button" class="btn btn-outline" data-project-close>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Confirm</button></div>
    </form>
  </div>
</div>

<style>
.project-stats-grid,.stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}.stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#eef2ff;color:#1d4ed8;font-size:20px}.stat-card h3{margin:0;font-size:24px}.ajax-alert{display:none;margin:10px 0;padding:10px 12px;border-radius:10px}.ajax-alert.success{display:block;background:#dcfce7;color:#166534}.ajax-alert.error{display:block;background:#fee2e2;color:#991b1b}.ajax-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:9998;display:none}.ajax-modal-backdrop.show{display:block}.ajax-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px}.ajax-modal.show{display:flex}.ajax-dialog{width:min(860px,100%);background:#fff;border-radius:18px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden}.ajax-dialog-sm{width:min(520px,100%)}.ajax-dialog-lg{width:min(1050px,100%)}.ajax-modal-header,.ajax-modal-footer{padding:15px 18px;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between}.ajax-modal-footer{border-top:1px solid #e5e7eb;border-bottom:0;justify-content:flex-end;gap:10px}.ajax-modal-header h3{margin:0;font-size:18px}.modal-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.ajax-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow:auto}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form-full{grid-column:1/-1}.progress-mini{height:9px;background:#e5e7eb;border-radius:99px;overflow:hidden;min-width:90px}.progress-mini span{display:block;height:100%;background:#2563eb}.detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.detail-box{border:1px solid #e5e7eb;background:#f8fafc;border-radius:10px;padding:10px}.detail-box b{display:block;color:#64748b;font-size:12px}.action-buttons{display:flex;gap:6px;flex-wrap:wrap}.btn-icon{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center}.text-muted{color:#64748b}@media(max-width:900px){.project-stats-grid,.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid,.detail-grid{grid-template-columns:1fr}}@media(max-width:560px){.project-stats-grid,.stats-grid{grid-template-columns:1fr}}
</style>

<script>
(function(){
'use strict';
const endpoint='process-projects.php'; let rows=[];
const qs=(s,r=document)=>r.querySelector(s); const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const money=v=>Number(v||0).toLocaleString();
function alertBox(el,msg,type='success'){if(!el)return;el.className='ajax-alert '+(type==='error'?'error':'success');el.textContent=msg;}
function clearAlert(el){if(el){el.className='ajax-alert';el.textContent='';}}
function openModal(id){qs('#projectBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
function closeModal(){qsa('.ajax-modal,.ajax-modal-backdrop').forEach(x=>x.classList.remove('show'));document.body.style.overflow='';}
async function req(url,opt={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opt));const text=await res.text();try{return JSON.parse(text)}catch(e){throw new Error('Invalid server response: '+text.slice(0,220));}}
async function post(form){return req(endpoint,{method:'POST',body:new FormData(form)});}
function badge(status){let cls=status==='Active'?'success':(status==='Completed'?'secondary':(status==='Paused'?'warning':'secondary'));return `<span class="badge badge-${cls}">${esc(status||'Active')}</span>`;}
function typeBadge(t){return `<span class="badge badge-info">${esc(t||'Other')}</span>`;}
function progress(p){const target=Number(p.target_amount||0), raised=Number(p.total_raised||0); const pct=target>0?Math.min(100,Math.round(raised/target*100)):0; return `<div class="progress-mini" title="${pct}%"><span style="width:${pct}%"></span></div><small>${pct}%</small>`;}
function stats(items){let total=items.length, active=items.filter(x=>(x.status||'Active')==='Active').length, comp=items.filter(x=>x.status==='Completed').length, target=items.reduce((a,x)=>a+Number(x.target_amount||0),0), raised=items.reduce((a,x)=>a+Number(x.total_raised||0),0); qs('#statTotal').textContent=money(total); qs('#statActive').textContent=money(active); qs('#statCompleted').textContent=money(comp); qs('#statTarget').textContent=money(target); qs('#statRaised').textContent=money(raised); qs('#statProgress').textContent=(target>0?Math.round(raised/target*100):0)+'%';}
function render(items){const tb=qs('#projectTableBody'); if(!items.length){tb.innerHTML='<tr><td colspan="10" class="text-muted">No projects found.</td></tr>';return;} tb.innerHTML=items.map((p,i)=>`<tr><td>${i+1}</td><td>${esc(p.project_no||'-')}</td><td><strong>${esc(p.name||'')}</strong></td><td>${typeBadge(p.type)}</td><td>${badge(p.status)}</td><td>${money(p.target_amount)}</td><td>${money(p.total_raised)}</td><td>${progress(p)}</td><td>${esc(p.start_date||'')}</td><td><div class="action-buttons"><button class="btn btn-sm btn-info btn-icon" data-act="view" data-id="${Number(p.id)}"><i class="fas fa-eye"></i></button><button class="btn btn-sm btn-warning btn-icon" data-act="edit" data-id="${Number(p.id)}"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-danger btn-icon" data-act="delete" data-id="${Number(p.id)}" data-name="${esc(p.name||'')}"><i class="fas fa-trash"></i></button></div></td></tr>`).join('');}
function filter(){const term=(qs('#projectSearch').value||'').toLowerCase(), stat=qs('#statusFilter').value; const f=rows.filter(p=>(!stat||(p.status||'Active')===stat)&&Object.values(p).join(' ').toLowerCase().includes(term)); render(f);}
async function load(){clearAlert(qs('#projectAlert')); const data=await req(endpoint+'?action=list'); if(!data.success) throw new Error(data.message||'Failed to load.'); rows=data.items||[]; stats(rows); filter();}
function reset(){qs('#projectForm').reset(); qs('#projectAction').value='create'; qs('#projectId').value=''; qs('#projectFormTitle').innerHTML='<i class="fas fa-plus"></i> New Project'; qs('#projectSaveBtn').innerHTML='<i class="fas fa-save"></i> Save Project'; qs('#startDate').value=new Date().toISOString().slice(0,10); clearAlert(qs('#projectFormAlert'));}
async function edit(id){reset(); const data=await req(endpoint+'?action=get&id='+encodeURIComponent(id)); if(!data.success) return alertBox(qs('#projectAlert'),data.message||'Not found','error'); const p=data.item; qs('#projectAction').value='update'; qs('#projectId').value=p.id||''; qs('#projectFormTitle').innerHTML='<i class="fas fa-edit"></i> Edit Project'; qs('#projectSaveBtn').innerHTML='<i class="fas fa-save"></i> Update Project'; qs('#projectName').value=p.name||''; qs('#projectType').value=p.type||'Other'; qs('#projectDescription').value=p.description||''; qs('#targetAmount').value=p.target_amount||0; qs('#startDate').value=p.start_date||''; qs('#endDate').value=p.end_date||''; qs('#projectStatus').value=p.status||'Active'; openModal('#projectFormModal');}
async function view(id){const data=await req(endpoint+'?action=get&id='+encodeURIComponent(id)); if(!data.success) return alertBox(qs('#projectAlert'),data.message||'Not found','error'); const p=data.item, contribs=data.contributions||[]; const pct=Number(p.target_amount)>0?Math.min(100,Math.round(Number(p.total_raised||0)/Number(p.target_amount)*100)):0; qs('#projectViewContent').innerHTML=`<div class="detail-grid"><div class="detail-box"><b>Project No</b>${esc(p.project_no||'-')}</div><div class="detail-box"><b>Name</b>${esc(p.name||'')}</div><div class="detail-box"><b>Type</b>${esc(p.type||'')}</div><div class="detail-box"><b>Status</b>${esc(p.status||'Active')}</div><div class="detail-box"><b>Target</b>UGX ${money(p.target_amount)}</div><div class="detail-box"><b>Raised</b>UGX ${money(p.total_raised)}</div><div class="detail-box"><b>Start Date</b>${esc(p.start_date||'')}</div><div class="detail-box"><b>End Date</b>${esc(p.end_date||'Ongoing')}</div><div class="detail-box form-full"><b>Description</b>${esc(p.description||'—')}</div><div class="detail-box form-full"><b>Progress</b><div class="progress-mini"><span style="width:${pct}%"></span></div>${pct}%</div></div><h4 style="margin-top:18px">Recent Contributions</h4><div class="table-responsive"><table class="table"><thead><tr><th>Member</th><th>Amount</th><th>Method</th><th>Date</th></tr></thead><tbody>${contribs.length?contribs.map(c=>`<tr><td>${esc(c.member_name||'Member')}</td><td>${money(c.amount)}</td><td>${esc(c.payment_method||'')}</td><td>${esc(c.contributed_on||c.created_at||'')}</td></tr>`).join(''):'<tr><td colspan="4" class="text-muted">No recent contributions.</td></tr>'}</tbody></table></div>`; openModal('#projectViewModal');}
qs('#btnOpenProject').addEventListener('click',()=>{reset();openModal('#projectFormModal');}); qs('#btnRefreshProjects').addEventListener('click',load); qs('#projectSearch').addEventListener('input',filter); qs('#statusFilter').addEventListener('change',filter); qs('#projectBackdrop').addEventListener('click',closeModal); qsa('[data-project-close]').forEach(b=>b.addEventListener('click',closeModal)); document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
document.addEventListener('click',e=>{const b=e.target.closest('[data-act]'); if(!b)return; const id=b.dataset.id; if(b.dataset.act==='view')view(id); if(b.dataset.act==='edit')edit(id); if(b.dataset.act==='delete'){qs('#deleteProjectId').value=id; qs('#deleteProjectName').textContent=b.dataset.name||'this project'; clearAlert(qs('#projectDeleteAlert')); openModal('#projectDeleteModal');}});
qs('#projectForm').addEventListener('submit',async e=>{e.preventDefault(); try{const d=await post(e.currentTarget); if(!d.success)return alertBox(qs('#projectFormAlert'),d.message||'Save failed','error'); closeModal(); alertBox(qs('#projectAlert'),d.message||'Saved.'); await load();}catch(err){alertBox(qs('#projectFormAlert'),err.message,'error');}});
qs('#projectDeleteForm').addEventListener('submit',async e=>{e.preventDefault(); try{const d=await post(e.currentTarget); if(!d.success)return alertBox(qs('#projectDeleteAlert'),d.message||'Delete failed','error'); closeModal(); alertBox(qs('#projectAlert'),d.message||'Deleted.'); await load();}catch(err){alertBox(qs('#projectDeleteAlert'),err.message,'error');}});
load().catch(err=>alertBox(qs('#projectAlert'),err.message,'error'));
})();
</script>

</div>
</div>
<?php include '../includes/footer.php'; ?>
