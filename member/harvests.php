<?php
declare(strict_types=1);
$pageTitle = 'Church Harvests';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.harvests.view');

$year=max(2000,min(2100,(int)($_GET['year']??date('Y'))));
$rows=mp_table($conn,'church_harvests')?mp_rows($conn,"SELECT * FROM church_harvests WHERE harvest_year=? ORDER BY FIELD(harvest_type,'General','Sunday School'),id",'i',[$year]):[];
$target=array_sum(array_map(fn($r)=>(float)($r['target_amount']??0),$rows));
$collected=array_sum(array_map(fn($r)=>(float)($r['collected_amount']??0),$rows));
$stats=[
 ['label'=>'Harvest Categories','value'=>count($rows),'icon'=>'fa-seedling'],
 ['label'=>'Annual Target','value'=>$target,'money'=>true,'icon'=>'fa-bullseye'],
 ['label'=>'Collected','value'=>$collected,'money'=>true,'icon'=>'fa-coins'],
 ['label'=>'Overall Progress','value'=>$target>0?round(($collected/$target)*100):0,'icon'=>'fa-chart-line']
];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas fa-seedling"></i> Church Harvests</h2><p>View annual general and Sunday School harvest progress.</p></div>
</div>
<div class="stats-grid module-stats-grid">
  <?php foreach ($stats as $stat): ?>
  <div class="stat-card">
    <div class="stat-icon"><i class="fas <?= mp_h($stat['icon']) ?>"></i></div>
    <div><strong class="stat-value"><?= !empty($stat['money']) ? 'UGX '.mp_money((float)$stat['value'],0) : number_format((float)$stat['value']) ?></strong><span class="stat-label"><?= mp_h($stat['label']) ?></span></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" data-member-search placeholder="Search harvests..." aria-label="Search harvests"></div><div class="page-toolbar-filters"><select data-member-filter="type" aria-label="Category"><option value="">All Categories</option><option>General</option><option>Sunday School</option></select><input type="number" name="year" value="<?=$year?>" min="2000" max="2100" form="memberHarvestYearForm" title="Year" aria-label="Year"></div><div class="page-toolbar-actions"><form method="get" id="memberHarvestYearForm" hidden></form><button type="submit" form="memberHarvestYearForm" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button><a class="btn btn-outline-primary page-toolbar-refresh" href="harvests.php"><i class="fas fa-rotate-right"></i> Refresh</a></div></div>
<div class="member-module-card">
  <div class="member-module-card-head"><h3><i class="fas fa-seedling"></i> Church Harvests</h3><span class="member-module-count"><?= count($rows) ?> records</span></div>
  <div class="member-module-body">
    <div class="member-module-table-wrap">
      <table class="member-module-table"><thead><tr><th>#</th><th>Category</th><th>Target</th><th>Collected</th><th>Progress</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php if(!$rows):?><tr><td colspan="8"><div class="member-empty"><i class="fas fa-seedling"></i>No harvest records are available for <?=$year?>.</div></td></tr><?php else:foreach($rows as $i=>$r):$p=$r['target_amount']>0?min(100,round(((float)$r['collected_amount']/(float)$r['target_amount'])*100,1)):0;$record=['category'=>$r['harvest_type']??'','target'=>'UGX '.mp_money((float)($r['target_amount']??0),0),'collected'=>'UGX '.mp_money((float)($r['collected_amount']??0),0),'progress'=>$p.'%','date'=>mp_date($r['harvest_date']??''),'status'=>$r['status']??'','notes'=>$r['notes']??''];
?><tr data-member-row data-type="<?=mp_h($r['harvest_type']??'')?>"><td><?=number_format($i+1)?></td><td><span class="member-status"><?=mp_h($r['harvest_type']??'-')?></span></td><td>UGX <?=mp_money((float)($r['target_amount']??0),0)?></td><td>UGX <?=mp_money((float)($r['collected_amount']??0),0)?></td><td><div class="member-progress"><span style="--progress:<?=$p?>%"></span></div><small><?=$p?>%</small></td><td><?=mp_date($r['harvest_date']??'')?></td><td><?=mp_h($r['status']??'-')?></td><td><button class="btn btn-info btn-sm btn-icon" data-member-view="<?=mp_h(json_encode($record))?>" data-title="Harvest Details"><i class="fas fa-eye"></i></button></td></tr><?php endforeach;endif;?>
      </tbody></table>
    </div>
  </div>
</div>
</div>
</div>

<div class="member-display-modal" id="memberViewModal" aria-hidden="true">
  <div class="member-display-dialog">
    <div class="member-display-modal-head">
      <h3 id="memberViewTitle">Details</h3>
      <button type="button" class="member-display-close" data-close-member-modal>&times;</button>
    </div>
    <div class="member-display-modal-body">
      <div class="member-detail-grid" id="memberViewContent"></div>
    </div>
    <div class="member-display-modal-foot">
      <button type="button" class="btn btn-outline" data-close-member-modal>Close</button>
    </div>
  </div>
</div>
<div class="member-modal-backdrop" id="memberDisplayBackdrop"></div>
<script src="<?= mp_h(BASE_URL . 'assets/js/member-portal.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
