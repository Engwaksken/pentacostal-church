<?php
declare(strict_types=1);
require_once __DIR__.'/../config/config.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Administrator access required.'); }
$conn->set_charset('utf8mb4');
// Same rule as members/process-member.php: the member number is the username, the name only a fallback.
function unique_username(mysqli $conn,string $fullName,string $memberNo):string{
    $base=trim((string)preg_replace('/[^A-Za-z0-9._-]+/','',$memberNo),'.-_');
    $base=$base ?: trim(strtolower((string)preg_replace('/[^a-z0-9]+/i','.',trim($fullName))),'.');
    $base=$base ?: 'member'; $candidate=$base; $n=1;
    while(true){$s=$conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$s->bind_param('s',$candidate);$s->execute();$exists=(bool)$s->get_result()->fetch_row();$s->close();if(!$exists)return $candidate;$candidate=$base.'-'.(++$n);}
}
$res=$conn->query("SELECT id,member_no,full_name,email,phone,status FROM members WHERE user_id IS NULL ORDER BY id");
$created=[];$conn->begin_transaction();
try{
 while($m=$res->fetch_assoc()){
  $username=unique_username($conn,(string)$m['full_name'],(string)$m['member_no']);
  $plain='Change@'.preg_replace('/\D+/','',(string)$m['member_no']); if(strlen($plain)<8)$plain.='1234';
  $hash=password_hash($plain,PASSWORD_DEFAULT);$full=trim((string)$m['full_name']);$role='member';$status=$m['status']==='Active'?'Active':'Inactive';
  $s=$conn->prepare('INSERT INTO users(username,password,full_name,role,email,phone,status) VALUES(?,?,?,?,?,?,?)');
  $s->bind_param('sssssss',$username,$hash,$full,$role,$m['email'],$m['phone'],$status);$s->execute();$uid=(int)$conn->insert_id;$s->close();
  $s=$conn->prepare('UPDATE members SET user_id=? WHERE id=?');$mid=(int)$m['id'];$s->bind_param('ii',$uid,$mid);$s->execute();$s->close();
  $created[]=['member_no'=>$m['member_no'],'name'=>$full,'username'=>$username,'temporary_password'=>$plain];
 }
 $conn->commit();
}catch(Throwable $e){$conn->rollback();http_response_code(500);exit('Failed: '.htmlspecialchars($e->getMessage()));}
?><!doctype html><html><head><meta charset="utf-8"><title>Member accounts created</title><style>body{font-family:Arial;padding:30px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:9px;text-align:left}th{background:#eee}</style></head><body><h2>Created <?=count($created)?> member login account(s)</h2><p>Save these temporary credentials securely, then delete this tool.</p><table><tr><th>Member No</th><th>Name</th><th>Username</th><th>Temporary Password</th></tr><?php foreach($created as $r):?><tr><td><?=htmlspecialchars((string)$r['member_no'])?></td><td><?=htmlspecialchars((string)$r['name'])?></td><td><?=htmlspecialchars((string)$r['username'])?></td><td><?=htmlspecialchars((string)$r['temporary_password'])?></td></tr><?php endforeach;?></table><p><a href="<?=htmlspecialchars(BASE_URL.'users/') ?>">Manage Users & Permissions</a></p></body></html>
