<?php
declare(strict_types=1);

function access_table_exists(mysqli $conn,string $table):bool{
    $safe=$conn->real_escape_string($table);
    $r=$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r && $r->num_rows>0;
}
function is_member_user():bool{return strtolower((string)($_SESSION['role']??''))==='member';}
function current_member_id():int{
    global $conn;
    $sessionId=(int)($_SESSION['member_id']??0);
    if($sessionId>0)return $sessionId;
    $uid=(int)($_SESSION['user_id']??0);
    if($uid<=0||!isset($conn)||!($conn instanceof mysqli))return 0;
    static $cache=[]; if(isset($cache[$uid]))return $cache[$uid];
    $st=$conn->prepare('SELECT id FROM members WHERE user_id=? LIMIT 1');
    if(!$st)return 0; $st->bind_param('i',$uid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    $mid=(int)($row['id']??0);$_SESSION['member_id']=$mid;return $cache[$uid]=$mid;
}
function current_user_can(string $permission):bool{
    global $conn;
    if(empty($_SESSION['user_id']))return false;
    $role=strtolower((string)($_SESSION['role']??''));
    if($role==='admin')return true;
    /* Member portal permissions are deliberately read-only and fixed. */
    if($role==='member'){
        $allowed=['dashboard.view','member.children.view','member.church.view','member.giving.view','member.projects.view','member.attendance.view','events.view','member.profile.view','member.payments.view','member.payment_gateways.view','member.programs.view','member.services.view','member.announcements.view','member.theme.view','member.harvests.view','member.notebook.manage','member.pledges.manage','member.contact.view','member.appointments.view','member.tips.view','member.worship.view'];
        return in_array($permission,$allowed,true);
    }
    if(!isset($conn)||!($conn instanceof mysqli)||!access_table_exists($conn,'permissions'))return false;
    static $cache=[];$uid=(int)$_SESSION['user_id'];$key=$uid.':'.$permission;if(array_key_exists($key,$cache))return $cache[$key];
    $sql="SELECT 1 FROM permissions p LEFT JOIN role_permissions rp ON rp.permission_id=p.id AND rp.role=? LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=? WHERE p.permission_key=? AND COALESCE(up.allowed,rp.allowed,0)=1 LIMIT 1";
    $st=$conn->prepare($sql);if(!$st)return $cache[$key]=false;$st->bind_param('sis',$role,$uid,$permission);$st->execute();$ok=(bool)$st->get_result()->fetch_row();$st->close();return $cache[$key]=$ok;
}
function require_permission(string $permission):void{
    if(current_user_can($permission))return;
    http_response_code(403);$ajax=strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest'||str_contains(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json');
    if($ajax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'message'=>'Access denied.']);}
    else{echo '<!doctype html><html><head><meta charset="utf-8"><title>Access denied</title></head><body style="font-family:Arial;padding:40px"><h2>Access denied</h2><p>You do not have permission to access this page.</p><p><a href="'.htmlspecialchars(BASE_URL.'dashboard.php').'">Return to dashboard</a></p></body></html>';}
    exit;
}
function enforce_member_read_only():void{
    if(!is_member_user())return;
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if(in_array($method,['GET','HEAD'],true))return;
    $path=strtolower(str_replace('\\','/',parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:''));
    $allowed=['/member/profile.php','/member/notebook.php','/member/appointments'];
    foreach($allowed as $needle)if(str_contains($path,$needle))return;
    http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'message'=>'Member accounts have view-only access.']);exit;
}
function permission_for_request():string{
    $path=strtolower(str_replace('\\','/',parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:''));
    if(str_contains($path,'/member/')){
        $map=['tips'=>'member.tips.view','hymns'=>'member.worship.view','order-of-service'=>'member.worship.view','children'=>'member.children.view','church'=>'member.church.view','giving'=>'member.giving.view','projects'=>'member.projects.view','programs'=>'member.programs.view','services'=>'member.services.view','announcements'=>'member.announcements.view','annual-theme'=>'member.theme.view','harvests'=>'member.harvests.view','notebook'=>'member.profile.view','appointments'=>'member.appointments.view','contact'=>'member.profile.view','attendance'=>'member.attendance.view','event-certificates'=>'events.view','events'=>'events.view','profile'=>'member.profile.view','payment-gateways'=>'member.payment_gateways.view','payment'=>'member.payments.view'];
        foreach($map as $n=>$p)if(str_contains($path,$n))return $p;
    }
    $map=['/appointments/'=>'dashboard.view','/members/'=>'members.view','/baptism/'=>'baptism.view','/sunday_school/'=>'sunday_school.view','/youth/'=>'youth.view','/zones/'=>'zones.view','/departments/'=>'departments.view','/fathers_union/'=>'fathers_union.view','/mothers_union/'=>'mothers_union.view','/offertory/'=>'offertory.view','/tithe/'=>'tithe.view','/thanksgiving/'=>'thanksgiving.view','/pledges/'=>'pledges.view','/contributions/'=>'contributions.view','/good_samaritan/'=>'good_samaritan.view','/projects/'=>'projects.view','/fundraising/'=>'fundraising.view','/bulk_messages/'=>'bulk_messages.view','/attendance/'=>'attendance.view','/events/'=>'events.view','/missions/'=>'missions.view','/reports/'=>'reports.view','/settings/'=>'settings.manage','/users/'=>'users.manage'];
    foreach($map as $n=>$p)if(str_contains($path,$n))return $p;return 'dashboard.view';
}
function enforce_current_page_permission():void{enforce_member_read_only();require_permission(permission_for_request());}
function member_union_status(mysqli $conn,string $table,int $memberId):bool{
    if($memberId<=0||!access_table_exists($conn,$table))return false;
    $safe=preg_replace('/[^a-z0-9_]/i','',$table);$st=$conn->prepare("SELECT 1 FROM `$safe` WHERE member_id=? AND COALESCE(status,'Active')='Active' LIMIT 1");
    if(!$st)return false;$st->bind_param('i',$memberId);$st->execute();$ok=(bool)$st->get_result()->fetch_row();$st->close();return $ok;
}
