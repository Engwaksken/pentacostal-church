<?php
require_once '../config/config.php'; requireLogin(); require_once __DIR__ . '/../includes/service-options.php';
$users=[];$userResult=$conn->query("SELECT id,full_name FROM users ORDER BY full_name");while($userResult && $u=$userResult->fetch_assoc())$users[]=$u;
$id = intval($_GET['id']??0);
$rec = $id ? fetchOne("SELECT * FROM offertory WHERE id=$id") : null;
$userNames = array_column($users, 'full_name');
$legacyName = fn(string $field): string => ($v = (string)($rec[$field] ?? '')) !== '' && !in_array($v, $userNames, true) ? '<option value="'.san($v).'" selected>'.san($v).'</option>' : '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $date    = san($_POST['service_date']);
    $type    = san($_POST['service_type']);
    $env     = floatval($_POST['envelope_total']);
    $loose   = floatval($_POST['loose_total']);
    $total   = $env + $loose + floatval($_POST['extra']);
    $counted = san($_POST['counted_by']);
    $witness = san($_POST['witnessed_by']);
    $notes   = san($_POST['notes']);
    $recordedBy = (int)($_SESSION['user_id'] ?? 0);
    $recordedBySql = $recordedBy > 0 ? (string)$recordedBy : 'NULL';
    $hasRecordedBy = service_column_exists($conn, 'offertory', 'recorded_by');
    if($id){
        mysqli_query($conn,"UPDATE offertory SET service_date='$date',service_type='$type',".($hasRecordedBy?"recorded_by=$recordedBySql,":'')."envelope_total=$env,loose_total=$loose,total_amount=$total,counted_by='$counted',witnessed_by='$witness',notes='$notes' WHERE id=$id");
        setFlash('Offertory updated.','success');
    } else {
        $r = mysqli_query($conn,"INSERT INTO offertory (service_date,service_type,".($hasRecordedBy?'recorded_by,':'')."envelope_total,loose_total,total_amount,counted_by,witnessed_by,notes) VALUES ('$date','$type',".($hasRecordedBy?"$recordedBySql,":'')."$env,$loose,$total,'$counted','$witness','$notes')");
        if(!$r) setFlash('Error: '.mysqli_error($conn),'danger');
        else setFlash('Offertory recorded.','success');
    }
    redirect('index.php');
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>
<div class="page-header"><h2><i class="fas fa-church"></i> <?=$id?'Edit':'Record'?> Offertory</h2><a href="index.php" class="btn btn-secondary">Back</a></div>
<div class="card"><div class="card-body">
<form method="POST" id="offForm">
<div class="form-row">
  <div class="form-group col-md-4"><label>Service Date *</label><input type="date" name="service_date" class="form-control" value="<?=san($rec['service_date']??date('Y-m-d'))?>" required></div>
  <div class="form-group col-md-4"><label>Service Type</label>
    <select name="service_type" class="form-control">
      <?php foreach(['Sunday Service','Wednesday Bible Study','Friday Prayer','Special Service','Revival','Other'] as $t): ?>
      <option <?=($rec['service_type']??'Sunday Service')==$t?'selected':''?>><?=$t?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="form-row">
  <div class="form-group col-md-4"><label>Envelope Collection (UGX)</label><input type="number" name="envelope_total" id="env" class="form-control" value="<?=$rec['envelope_total']??0?>" step="0.01" oninput="calcTotal()"></div>
  <div class="form-group col-md-4"><label>Loose Offering (UGX)</label><input type="number" name="loose_total" id="loose" class="form-control" value="<?=$rec['loose_total']??0?>" step="0.01" oninput="calcTotal()"></div>
  <div class="form-group col-md-4"><label>Other/Additional (UGX)</label><input type="number" name="extra" id="extra" class="form-control" value="0" step="0.01" oninput="calcTotal()"></div>
</div>
<div class="alert alert-success"><strong>Total Offertory: UGX <span id="totalDisplay"><?=number_format($rec['total_amount']??0)?></span></strong></div>
<div class="form-row">
  <div class="form-group col-md-6"><label for="countedBySearch">Counted By</label><input type="search" id="countedBySearch" class="form-control" placeholder="Search users..."><select name="counted_by" id="counted_by" class="form-control"><option value="">Select a user</option><?=$legacyName('counted_by')?><?php foreach($users as $u):?><option value="<?=san($u['full_name'])?>" <?=($rec['counted_by']??'')===$u['full_name']?'selected':''?>><?=san($u['full_name'])?></option><?php endforeach;?></select></div>
  <div class="form-group col-md-6"><label for="witnessedBySearch">Witnessed By</label><input type="search" id="witnessedBySearch" class="form-control" placeholder="Search users..."><select name="witnessed_by" id="witnessed_by" class="form-control"><option value="">Select a user</option><?=$legacyName('witnessed_by')?><?php foreach($users as $u):?><option value="<?=san($u['full_name'])?>" <?=($rec['witnessed_by']??'')===$u['full_name']?'selected':''?>><?=san($u['full_name'])?></option><?php endforeach;?></select></div>
</div>
<div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?=san($rec['notes']??'')?></textarea></div>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
</form>
</div></div>
</div>
</div>
<script>
function calcTotal(){
  var e=parseFloat(document.getElementById('env').value)||0;
  var l=parseFloat(document.getElementById('loose').value)||0;
  var x=parseFloat(document.getElementById('extra').value)||0;
  document.getElementById('totalDisplay').innerText=(e+l+x).toLocaleString();
}
function filterUserOptions(searchId, selectId){const search=document.getElementById(searchId),select=document.getElementById(selectId),options=Array.from(select.options).slice(1);search.addEventListener('input',()=>{const value=search.value.toLowerCase(),selected=select.value;select.replaceChildren(new Option('Select a user',''));options.filter(o=>o.text.toLowerCase().includes(value)).forEach(o=>select.add(o.cloneNode(true)));if(Array.from(select.options).some(o=>o.value===selected))select.value=selected;});select.addEventListener('change',()=>search.value=select.selectedOptions[0]?.text||'');search.value=select.selectedOptions[0]?.text||'';}
filterUserOptions('countedBySearch','counted_by');filterUserOptions('witnessedBySearch','witnessed_by');
</script>
<?php include '../includes/footer.php'; ?>
