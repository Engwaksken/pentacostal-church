<?php
declare(strict_types=1);
$pageTitle='Appointments';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/../includes/appointment-functions.php';
require_permission('member.appointments.view');
appointment_ensure_table($conn);
$memberId=current_member_id();
$userId=(int)($_SESSION['user_id']??0);
if($memberId<=0||!appointment_rows($conn,"SELECT id FROM members WHERE id=? AND user_id=? AND COALESCE(status,'Active')='Active' LIMIT 1",'ii',[$memberId,$userId])){http_response_code(403);exit('An active member profile is required to request an appointment.');}
if(empty($_SESSION['appointment_csrf']))$_SESSION['appointment_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['appointment_csrf'];
$targets=appointment_targets($conn);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new RuntimeException('Your form session expired. Refresh and try again.');
        $action=(string)($_POST['action']??'');
        if($action==='review'){
            $requestId=(int)($_POST['request_id']??0);
            $request=appointment_rows($conn,'SELECT * FROM appointment_requests WHERE id=? LIMIT 1','i',[$requestId])[0]??null;
            if(!$request||!appointment_can_review($conn,$request))throw new RuntimeException('You are not authorized to review that request.');
            if($request['status']!=='Pending')throw new RuntimeException('This request has already been reviewed.');
            $decision=(string)($_POST['decision']??'');$comment=trim((string)($_POST['review_comment']??''));
            if(!in_array($decision,['Approved','Rejected'],true))throw new RuntimeException('Choose approve or reject.');
            if($comment===''||mb_strlen($comment)>3000)throw new RuntimeException('A review comment is required (up to 3,000 characters).');
            $reviewerId=$userId;
            $stmt=$conn->prepare("UPDATE appointment_requests SET status=?,review_comment=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='Pending'");
            $stmt->bind_param('ssii',$decision,$comment,$reviewerId,$requestId);$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
            if(!$changed)throw new RuntimeException('The request has already been reviewed.');
            $member=appointment_rows($conn,'SELECT m.email,m.full_name,u.email AS user_email FROM members m LEFT JOIN users u ON u.id=m.user_id WHERE m.id=? LIMIT 1','i',[(int)$request['member_id']])[0]??[];
            $to=trim((string)($member['email']?:($member['user_email']??'')));
            $preferred=$request['preferred_at']?date('d M Y, g:i A',strtotime((string)$request['preferred_at'])):'Not specified';
            $notice='Your appointment request "'.$request['subject'].'" was '.strtolower($decision).'. Reviewer comment: '.$comment.' Preferred time: '.$preferred.'.';
            $emailSent=$to!==''&&appointment_send_email($conn,$to,trim((string)($member['full_name']??''))?:'Member','Appointment request '.$decision,$notice);
            $_SESSION['appointment_flash']=['type'=>'success','message'=>$emailSent?'Appointment request '.$decision.'. The member was emailed and can also view the response in My Appointments.':'Appointment request '.$decision.'. The member can view the response in My Appointments; email delivery was unavailable.'];
        }elseif($action==='create'){
            $targetKey=(string)($_POST['target']??'');$target=null;foreach($targets as $candidate)if($candidate['key']===$targetKey){$target=$candidate;break;}
            $subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));$preferred=trim((string)($_POST['preferred_at']??''));
            if(!$target)throw new RuntimeException('Choose a valid church leader or department leader.');
            if($subject===''||mb_strlen($subject)>180)throw new RuntimeException('Enter an appointment subject of up to 180 characters.');
            if(mb_strlen($message)<10||mb_strlen($message)>5000)throw new RuntimeException('Explain your request in 10 to 5,000 characters.');
            $preferredAt=null;
            if($preferred!==''){$date=DateTime::createFromFormat('Y-m-d\TH:i',$preferred);if(!$date)throw new RuntimeException('Enter a valid preferred date and time.');$preferredAt=$date->format('Y-m-d H:i:s');}
            $member=appointment_rows($conn,'SELECT full_name,member_no FROM members WHERE id=? LIMIT 1','i',[$memberId])[0]??[];
            $memberName=trim((string)($member['full_name']??''))?:'Member';
            $recipientUser=(int)$target['recipient_user_id'];$recipientMember=$target['recipient_member_id'];$departmentId=$target['department_id'];
            $stmt=$conn->prepare('INSERT INTO appointment_requests(member_id,member_user_id,recipient_user_id,recipient_member_id,department_id,recipient_label,subject,message,preferred_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iiiiissss',$memberId,$userId,$recipientUser,$recipientMember,$departmentId,$target['recipient_label'],$subject,$message,$preferredAt);
            $stmt->execute();$stmt->close();
            $when=$preferredAt?date('d M Y, g:i A',strtotime($preferredAt)):'Not specified';
            $notice=$memberName.' ('.($member['member_no']??'Member').') requested an appointment: '.$subject.'. Preferred time: '.$when.'. Request: '.$message;
            $emailSent=appointment_send_email($conn,(string)$target['email'],$target['label'],'New member appointment request',$notice);
            $_SESSION['appointment_flash']=['type'=>'success','message'=>$emailSent?'Your appointment request was sent and emailed to '.$target['label'].'.':'Your appointment request was recorded for '.$target['label'].', but email delivery was unavailable.'];
        }else throw new RuntimeException('Invalid appointment action.');
    }catch(Throwable $e){$_SESSION['appointment_flash']=['type'=>'danger','message'=>$e->getMessage()];}
    header('Location: appointments.php');exit;
}

$flash=$_SESSION['appointment_flash']??null;unset($_SESSION['appointment_flash']);
$outgoing=appointment_rows($conn,'SELECT * FROM appointment_requests WHERE member_id=? ORDER BY created_at DESC','i',[$memberId]);
$incoming=appointment_rows($conn,'SELECT ar.*,m.full_name,m.member_no FROM appointment_requests ar JOIN members m ON m.id=ar.member_id WHERE ar.recipient_user_id=? OR ar.recipient_member_id=? ORDER BY ar.status="Pending" DESC,ar.created_at DESC','ii',[$userId,$memberId]);
include __DIR__.'/../includes/sidebar.php';
?>
<div class="main-content"><?php include __DIR__.'/../includes/topbar.php';?><div class="content-area appointment-page">
<?php if(is_array($flash)):?><div class="alert alert-<?=mp_h($flash['type'])?>"><?=mp_h($flash['message'])?></div><?php endif;?>
<div class="appointment-heading"><div><h2><i class="fas fa-calendar-check"></i> Appointments</h2><p>Request a private meeting with a church leader and track the response.</p></div></div>
<div class="appointment-columns">
  <section class="card"><div class="card-header"><h3>Request an Appointment</h3></div><div class="card-body">
    <?php if(!$targets):?><div class="alert alert-info">No church leaders with an active email address are currently available. Please contact the church office.</div><?php else:?>
    <form method="post" class="appointment-form"><input type="hidden" name="csrf_token" value="<?=mp_h($csrf)?>"><input type="hidden" name="action" value="create">
      <label>Send request to<select class="form-control" name="target" required><option value="">Select a church leader</option><?php foreach($targets as $target):?><option value="<?=mp_h($target['key'])?>"><?=mp_h($target['label'])?></option><?php endforeach;?></select></label>
      <label>Subject<input class="form-control" name="subject" maxlength="180" required placeholder="Brief reason for meeting"></label>
      <label>Preferred date and time <span class="appointment-optional">(optional)</span><input class="form-control" type="datetime-local" name="preferred_at"></label>
      <label>Message<textarea class="form-control" name="message" rows="5" minlength="10" maxlength="5000" required placeholder="Please share a short explanation for your request."></textarea></label>
      <button class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Appointment Request</button>
    </form><?php endif;?>
  </div></section>
  <section class="card"><div class="card-header"><h3>My Requests</h3><span class="appointment-count"><?=count($outgoing)?></span></div><div class="card-body"><div class="table-responsive"><table class="table appointment-table"><thead><tr><th>Date</th><th>Subject</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php if(!$outgoing):?><tr><td colspan="4" class="appointment-empty">Your appointment requests will appear here.</td></tr><?php else:foreach($outgoing as $request):$viewData=['title'=>(string)$request['subject'],'date'=>mp_date($request['created_at'],'d M Y'),'recipient'=>appointment_official_label((string)$request['recipient_label']),'message'=>(string)$request['message'],'preferred'=>!empty($request['preferred_at'])?date('d M Y, g:i A',strtotime((string)$request['preferred_at'])):'Not specified','status'=>(string)$request['status'],'comment'=>(string)($request['review_comment']??'')];?><tr><td><?=mp_h(mp_date($request['created_at'],'d M Y'))?></td><td><?=mp_h($request['subject'])?></td><td><span class="appointment-status <?=strtolower((string)$request['status'])?>"><?=mp_h($request['status'])?></span></td><td><button type="button" class="btn btn-outline-primary btn-sm appointment-view-details" aria-label="View appointment details" title="View details" data-details="<?=mp_h(json_encode($viewData,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>"><i class="fas fa-eye" aria-hidden="true"></i></button></td></tr><?php endforeach;endif;?>
  </tbody></table></div></div></section>
</div>

<?php if($incoming):?><section class="card appointment-inbox"><div class="card-header"><h3>Requests for My Review</h3><span class="appointment-count"><?=count($incoming)?></span></div><div class="card-body"><div class="table-responsive"><table class="table appointment-table"><thead><tr><th>Date</th><th>Member</th><th>Subject</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($incoming as $request):$viewData=['title'=>(string)$request['subject'],'date'=>mp_date($request['created_at'],'d M Y'),'member'=>trim((string)($request['full_name']??'')).' ('.(string)($request['member_no']??'').')','recipient'=>appointment_official_label((string)$request['recipient_label']),'message'=>(string)$request['message'],'preferred'=>!empty($request['preferred_at'])?date('d M Y, g:i A',strtotime((string)$request['preferred_at'])):'Not specified','status'=>(string)$request['status'],'comment'=>(string)($request['review_comment']??'')];?><tr><td><?=mp_h(mp_date($request['created_at'],'d M Y'))?></td><td><?=mp_h(trim((string)($request['full_name']??'')))?></td><td><?=mp_h($request['subject'])?></td><td><span class="appointment-status <?=strtolower((string)$request['status'])?>"><?=mp_h($request['status'])?></span></td><td><button type="button" class="btn btn-outline-primary btn-sm appointment-view-details" aria-label="View appointment details" title="View details" data-details="<?=mp_h(json_encode($viewData,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>"><i class="fas fa-eye" aria-hidden="true"></i></button> <?php if($request['status']==='Pending'):?><button type="button" class="btn btn-primary btn-sm appointment-open-review" data-request-id="<?=(int)$request['id']?>" data-subject="<?=mp_h($request['subject'])?>">Review</button><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div></section>
<dialog class="appointment-review-modal" id="appointmentReviewModal"><form method="post" class="appointment-review-modal-form"><input type="hidden" name="csrf_token" value="<?=mp_h($csrf)?>"><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" id="appointmentReviewRequestId"><header><h3 id="appointmentReviewTitle">Review appointment</h3><button type="button" class="appointment-modal-close" aria-label="Close">&times;</button></header><label>Comment to member<textarea class="form-control" name="review_comment" rows="4" maxlength="3000" required placeholder="Explain your decision or suggest another time."></textarea></label><footer><button class="btn btn-success" name="decision" value="Approved">Approve</button><button class="btn btn-danger" name="decision" value="Rejected">Reject</button></footer></form></dialog>
<script>(function(){const modal=document.getElementById('appointmentReviewModal');if(!modal)return;const form=modal.querySelector('form');document.querySelectorAll('.appointment-open-review').forEach(button=>button.addEventListener('click',()=>{document.getElementById('appointmentReviewRequestId').value=button.dataset.requestId;document.getElementById('appointmentReviewTitle').textContent='Review: '+button.dataset.subject;form.querySelector('textarea').value='';modal.showModal();form.querySelector('textarea').focus()}));modal.querySelector('.appointment-modal-close').addEventListener('click',()=>modal.close());modal.addEventListener('click',event=>{if(event.target===modal)modal.close()})})();</script>
<?php endif;?>
<dialog class="appointment-details-modal" id="appointmentDetailsModal"><div class="appointment-details-content"><header><h3 id="appointmentDetailsTitle">Appointment details</h3><button type="button" class="appointment-modal-close" aria-label="Close">&times;</button></header><dl><dt>Date</dt><dd data-detail="date"></dd><dt>Member</dt><dd data-detail="member"></dd><dt>Recipient</dt><dd data-detail="recipient"></dd><dt>Preferred time</dt><dd data-detail="preferred"></dd><dt>Status</dt><dd data-detail="status"></dd><dt>Request</dt><dd data-detail="message"></dd><dt>Review comment</dt><dd data-detail="comment"></dd></dl></div></dialog>
</div></div>
<script>(function(){const modal=document.getElementById('appointmentDetailsModal');if(!modal)return;document.querySelectorAll('.appointment-view-details').forEach(button=>button.addEventListener('click',()=>{let d={};try{d=JSON.parse(button.dataset.details||'{}')}catch(e){};document.getElementById('appointmentDetailsTitle').textContent=d.title||'Appointment details';modal.querySelectorAll('[data-detail]').forEach(el=>{el.textContent=d[el.dataset.detail]||'Not available';el.hidden=el.dataset.detail==='member'&&!d.member});modal.showModal()}));modal.querySelector('.appointment-modal-close').addEventListener('click',()=>modal.close());modal.addEventListener('click',event=>{if(event.target===modal)modal.close()})})();</script>
<style>
.appointment-heading{margin-bottom:18px}.appointment-heading h2{margin:0 0 5px}.appointment-heading p{margin:0;color:#667085}.appointment-columns{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.15fr);gap:16px;align-items:start}.appointment-form{display:grid;gap:13px}.appointment-form label{display:grid;gap:6px;color:#344054;font-size:13px;font-weight:700}.appointment-form textarea{min-height:125px;resize:vertical}.appointment-optional{color:#667085;font-weight:400}.appointment-count{display:inline-grid;min-width:26px;height:26px;place-items:center;border-radius:50%;background:var(--primary-fade,#f1ebfa);color:var(--primary);font-weight:800;font-size:12px}.appointment-table{min-width:650px}.appointment-table thead th{text-align:left}.appointment-table td{vertical-align:top;overflow-wrap:anywhere}.appointment-status{display:inline-flex;padding:4px 9px;border-radius:999px;background:#fff7e6;color:#9a6700;font-size:10px;font-weight:800;text-transform:uppercase;white-space:nowrap}.appointment-status.approved{background:#e8f7ee;color:#167044}.appointment-status.rejected{background:#feeceb;color:#b42318}.appointment-empty{padding:22px;color:#667085;text-align:center}.appointment-inbox{margin-top:18px}.appointment-review-modal,.appointment-details-modal{position:fixed;inset:0;margin:auto;width:min(540px,calc(100% - 28px));max-height:calc(100% - 28px);overflow:auto;padding:0;border:0;border-radius:14px;box-shadow:0 20px 70px #10182844}.appointment-review-modal::backdrop,.appointment-details-modal::backdrop{background:#10182899}.appointment-review-modal-form{display:grid;gap:16px;padding:22px}.appointment-review-modal header,.appointment-review-modal footer,.appointment-details-content header{display:flex;align-items:center;justify-content:space-between;gap:10px}.appointment-review-modal header h3,.appointment-details-content h3{margin:0}.appointment-review-modal label{display:grid;gap:7px;font-weight:700}.appointment-review-modal footer{justify-content:flex-end}.appointment-details-content{padding:22px}.appointment-details-content dl{display:grid;grid-template-columns:130px 1fr;gap:10px;margin:20px 0 0}.appointment-details-content dt{font-weight:700;color:#475467}.appointment-details-content dd{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}.appointment-modal-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}@media(max-width:900px){.appointment-columns{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__.'/../includes/footer.php';?>
