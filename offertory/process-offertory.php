<?php
require_once '../config/config.php';
requireLogin();
require_once __DIR__ . '/../includes/service-options.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(E_ALL);
function oj($ok,$msg='',$extra=[]){ echo json_encode(array_merge(['success'=>$ok,'message'=>$msg],$extra)); exit; }
function s($v){ global $conn; return mysqli_real_escape_string($conn, trim((string)$v)); }
function one($sql){ global $conn; $r=mysqli_query($conn,$sql); return $r?mysqli_fetch_assoc($r):null; }
function many($sql){ global $conn; $out=[]; $r=mysqli_query($conn,$sql); if($r){ while($x=mysqli_fetch_assoc($r))$out[]=$x; } return $out; }
try{
 $action=$_POST['action']??$_GET['action']??'list';
 if($action==='list'){
   $month=s($_GET['month']??date('Y-m'));
   $rows=many("SELECT * FROM offertory WHERE DATE_FORMAT(service_date,'%Y-%m')='$month' ORDER BY service_date DESC,id DESC");
   $stats=[
    'month_total'=>(float)(one("SELECT COALESCE(SUM(total_amount),0) t FROM offertory WHERE DATE_FORMAT(service_date,'%Y-%m')='$month'")['t']??0),
    'year_total'=>(float)(one("SELECT COALESCE(SUM(total_amount),0) t FROM offertory WHERE YEAR(service_date)=YEAR(CURDATE())")['t']??0),
    'all_total'=>(float)(one("SELECT COALESCE(SUM(total_amount),0) t FROM offertory")['t']??0),
   ];
   oj(true,'',['items'=>$rows,'stats'=>$stats]);
 }
 if($action==='get'){
   $id=(int)($_GET['id']??0); if(!$id) oj(false,'Invalid offertory ID.');
   $row=one("SELECT * FROM offertory WHERE id=$id"); if(!$row) oj(false,'Offertory record not found.');
   oj(true,'',['item'=>$row]);
 }
 if($action==='save'){
   $id=(int)($_POST['id']??0); $date=s($_POST['service_date']??date('Y-m-d')); $type=s($_POST['service_type']??'Sunday Service');
   $env=(float)($_POST['envelope_total']??0); $loose=(float)($_POST['loose_total']??0); $extra=(float)($_POST['extra']??0); $total=$env+$loose+$extra;
   $counted=s($_POST['counted_by']??''); $witness=s($_POST['witnessed_by']??''); $notes=s($_POST['notes']??'');
   $recordedBy=(int)($_SESSION['user_id']??0); $recordedBySql=$recordedBy>0?(string)$recordedBy:'NULL'; $hasRecordedBy=service_column_exists($conn,'offertory','recorded_by');
   // First, second, ... service; saved once database/service_session.sql has added the column.
   $session=trim((string)($_POST['service_session']??'')); $session=in_array($session,service_session_options(),true)?$session:'';
   $hasSession=service_column_exists($conn,'offertory','service_session'); $sessionSql=$session!==''?"'".s($session)."'":'NULL';
   if($total<=0) oj(false,'Total offertory amount must be greater than zero.');
   if($id>0){
    $ok=mysqli_query($conn,"UPDATE offertory SET service_date='$date',service_type='$type',".($hasSession?"service_session=$sessionSql,":'').($hasRecordedBy?"recorded_by=$recordedBySql,":'')."envelope_total=$env,loose_total=$loose,total_amount=$total,counted_by='$counted',witnessed_by='$witness',notes='$notes' WHERE id=$id");
    if(!$ok) oj(false,'Update failed: '.mysqli_error($conn)); oj(true,'Offertory updated successfully.');
   } else {
    $ok=mysqli_query($conn,"INSERT INTO offertory (service_date,service_type,".($hasSession?'service_session,':'').($hasRecordedBy?'recorded_by,':'')."envelope_total,loose_total,total_amount,counted_by,witnessed_by,notes) VALUES ('$date','$type',".($hasSession?"$sessionSql,":'').($hasRecordedBy?"$recordedBySql,":'')."$env,$loose,$total,'$counted','$witness','$notes')");
    if(!$ok) oj(false,'Insert failed: '.mysqli_error($conn)); oj(true,'Offertory recorded successfully.',['id'=>mysqli_insert_id($conn)]);
   }
 }
 if($action==='delete'){
   $id=(int)($_POST['id']??0); if(!$id) oj(false,'Invalid offertory ID.');
   $ok=mysqli_query($conn,"DELETE FROM offertory WHERE id=$id"); if(!$ok) oj(false,'Delete failed: '.mysqli_error($conn)); oj(true,'Offertory deleted successfully.');
 }
 oj(false,'Invalid request action.');
}catch(Throwable $e){ oj(false,'Server error: '.$e->getMessage()); }
