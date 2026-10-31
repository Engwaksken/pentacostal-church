<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/member-fields.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is not available.');
}
$conn->set_charset('utf8mb4');

function sr_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function sr_clean(mixed $value): string { return trim((string)($value ?? '')); }
function sr_table_exists(mysqli $conn, string $table): bool {
    $stmt=$conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s',$table); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c']??0)>0;
}
function sr_column_exists(mysqli $conn, string $table, string $column): bool {
    static $cache=[]; $key=$table.'.'.$column;
    if (array_key_exists($key,$cache)) return (bool)$cache[$key];
    $stmt=$conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss',$table,$column); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key]=((int)($row['c']??0)>0);
}
function sr_site_asset(mysqli $conn, string $column): string {
    $allowed = ['site_logo', 'site_favicon'];
    if (!in_array($column, $allowed, true)) return '';

    // Site Settings stores branding as key/value pairs in site_settings.
    if (sr_table_exists($conn, 'site_settings')) {
        $stmt = $conn->prepare('SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $column);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $value = trim((string)($row['setting_value'] ?? ''));
            if ($value !== '') return $value;
        }
    }

    // Keep compatibility with older deployments using site_system columns.
    if (!sr_table_exists($conn, 'site_system') || !sr_column_exists($conn, 'site_system', $column)) {
        return '';
    }

    $order = sr_column_exists($conn, 'site_system', 'id') ? ' ORDER BY id DESC' : '';
    $sql = "SELECT `{$column}` FROM site_system WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) <> ''{$order} LIMIT 1";
    $result = $conn->query($sql);
    if (!$result) return '';

    $row = $result->fetch_assoc();
    return trim((string)($row[$column] ?? ''));
}
function sr_site_asset_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';

    if (preg_match('~^(?:https?:)?//~i', $path) || str_starts_with($path, 'data:')) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    while (str_starts_with($path, '../')) $path = substr($path, 3);
    $path = ltrim($path, '/');

    return $path !== '' ? '../' . $path : '';
}
function sr_ensure_links_table(mysqli $conn): void {
    $sql="CREATE TABLE IF NOT EXISTS member_registration_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        label VARCHAR(120) NULL,
        expires_at DATETIME NULL,
        max_uses INT UNSIGNED NULL,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id), UNIQUE KEY uq_member_registration_token_hash(token_hash),
        KEY idx_member_registration_active(is_active,expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if(!$conn->query($sql)) throw new RuntimeException('Unable to prepare member registration.');
}
function sr_zone_table(mysqli $conn): ?string {
    if(sr_table_exists($conn,'church_zones')) return 'church_zones';
    if(sr_table_exists($conn,'zones')) return 'zones';
    return null;
}
function sr_zone_name_column(mysqli $conn,string $table): string {
    foreach(['zone_name','name','title'] as $c) if(sr_column_exists($conn,$table,$c)) return $c;
    return 'id';
}
function sr_valid_id(mysqli $conn,string $table,int $id): ?int {
    if($id<=0 || !sr_table_exists($conn,$table)) return null;
    $stmt=$conn->prepare("SELECT id FROM `$table` WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$id); $stmt->execute(); $ok=(bool)$stmt->get_result()->fetch_row(); $stmt->close();
    return $ok?$id:null;
}
function sr_generate_member_no(mysqli $conn): string {
    $prefix='MEM-'.date('Y').'-';
    $stmt=$conn->prepare("SELECT member_no FROM members WHERE member_no LIKE CONCAT(?,'%') ORDER BY member_no DESC LIMIT 1");
    $stmt->bind_param('s',$prefix); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    $next=1;
    if(!empty($row['member_no'])) { $parts=explode('-',(string)$row['member_no']); $next=max(1,((int)end($parts))+1); }
    do {
        $candidate=$prefix.str_pad((string)$next,4,'0',STR_PAD_LEFT);
        $st=$conn->prepare('SELECT id FROM members WHERE member_no=? LIMIT 1'); $st->bind_param('s',$candidate); $st->execute();
        $exists=(bool)$st->get_result()->fetch_row(); $st->close(); $next++;
    } while($exists);
    return $candidate;
}
function sr_unique_username(mysqli $conn,string $requested,string $fullName,string $memberNo): string {
    $requested=strtolower(trim($requested));
    if($requested!=='' && !preg_match('/^[a-z0-9._-]{3,50}$/',$requested)) throw new RuntimeException('Username may contain only letters, numbers, dot, underscore and hyphen.');
    $base=$requested!==''?$requested:strtolower(trim((string)preg_replace('/[^a-z0-9._-]+/i','.',trim($fullName)),'.'));
    if($base==='') $base=strtolower((string)preg_replace('/[^a-z0-9]+/i','',$memberNo));
    if($base==='') $base='member';
    $candidate=$base; $n=1;
    while(true){
        $st=$conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1'); $st->bind_param('s',$candidate); $st->execute();
        $exists=(bool)$st->get_result()->fetch_row(); $st->close();
        if(!$exists) return $candidate;
        if($requested!=='') throw new RuntimeException('That username is already in use. Please choose another one.');
        $candidate=$base.(++$n);
    }
}
function sr_lookup_invitation(mysqli $conn,string $token,bool $forUpdate=false): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/i',$token)) return null;
    $hash=hash('sha256',strtolower($token));
    $sql="SELECT id,label,expires_at,max_uses,used_count,is_active FROM member_registration_links WHERE token_hash=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$conn->prepare($sql); $stmt->bind_param('s',$hash); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$row) return null;
    if((int)$row['is_active']!==1) return null;
    if(!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) return null;
    if($row['max_uses']!==null && (int)$row['max_uses']>0 && (int)$row['used_count'] >= (int)$row['max_uses']) return null;
    return $row;
}
function sr_department_name(mysqli $conn,?int $deptId): string {
    if(!$deptId || !sr_table_exists($conn,'departments')) return '';
    $stmt=$conn->prepare('SELECT name FROM departments WHERE id=? LIMIT 1'); $stmt->bind_param('i',$deptId); $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return trim((string)($row['name']??''));
}
function sr_sync_department(mysqli $conn,int $memberId,?int $deptId,string $joinedDate): void {
    if(!$deptId || !sr_table_exists($conn,'department_members')) return;
    $role='Member';
    $stmt=$conn->prepare('INSERT INTO department_members (dept_id,member_id,role,joined_date) VALUES (?,?,?,?)');
    if($stmt){ $stmt->bind_param('iiss',$deptId,$memberId,$role,$joinedDate); $stmt->execute(); $stmt->close(); }
}

try { sr_ensure_links_table($conn); } catch(Throwable $e) { http_response_code(500); exit('Registration service is unavailable.'); }

$siteName = APP_NAME;
if (sr_table_exists($conn, 'site_settings')) {
    $siteNameStmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key='site_name' LIMIT 1");
    if ($siteNameStmt) { $siteNameStmt->execute(); $siteName = trim((string)($siteNameStmt->get_result()->fetch_assoc()['setting_value'] ?? '')) ?: APP_NAME; $siteNameStmt->close(); }
}
$siteLogo = sr_site_asset($conn, 'site_logo');
$siteFavicon = sr_site_asset($conn, 'site_favicon');
$siteLogoUrl = sr_site_asset_url($siteLogo);
$siteFaviconUrl = sr_site_asset_url($siteFavicon);

$token=sr_clean($_GET['token'] ?? $_POST['token'] ?? '');
$invite=sr_lookup_invitation($conn,$token,false);
$invalidInvite=($invite===null);

if(empty($_SESSION['member_public_csrf'])) $_SESSION['member_public_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['member_public_csrf'];

$departments=[];
if(sr_table_exists($conn,'departments')) {
    $where=sr_column_exists($conn,'departments','status')?" WHERE COALESCE(status,'Active')='Active'":'';
    $r=$conn->query("SELECT id,name FROM departments $where ORDER BY name");
    if($r) while($row=$r->fetch_assoc()) $departments[]=$row;
}
$zones=[]; $zoneTable=sr_zone_table($conn);
if($zoneTable){
    $nameCol=sr_zone_name_column($conn,$zoneTable);
    $where=sr_column_exists($conn,$zoneTable,'status')?" WHERE COALESCE(status,'Active')='Active'":'';
    $r=$conn->query("SELECT id,`$nameCol` zone_name FROM `$zoneTable` $where ORDER BY `$nameCol`");
    if($r) while($row=$r->fetch_assoc()) $zones[]=$row;
}

$hasServiceCol=member_fields_ensure($conn);
$serviceOptions=$hasServiceCol?member_fields_service_options($conn):[];

$errors=[]; $success=false; $newMemberNo=''; $newUsername='';
if($_SERVER['REQUEST_METHOD']==='POST' && !$invalidInvite){
    $postedCsrf=(string)($_POST['csrf_token']??'');
    if(!hash_equals($csrf,$postedCsrf)) $errors[]='Your registration session expired. Please refresh the page and try again.';
    if(trim((string)($_POST['website']??''))!=='') $errors[]='Unable to process this registration.';

    $fullName=member_full_name($_POST);
    $gender=sr_clean($_POST['gender']??'');
    $dob=sr_clean($_POST['date_of_birth']??'');
    $phone=sr_clean($_POST['phone']??'');
    $email=strtolower(sr_clean($_POST['email']??''));
    $address=sr_clean($_POST['address']??'');
    $marital=sr_clean($_POST['marital_status']??'');
    $occupation=sr_clean($_POST['occupation']??'');
    $baptism=sr_clean($_POST['baptism_date']??'');
    $serviceAttended=mb_substr(sr_clean($_POST['service_attended']??''),0,120,'UTF-8');
    $requestedUsername=sr_clean($_POST['login_username']??'');
    $password=(string)($_POST['login_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    $deptId=sr_valid_id($conn,'departments',(int)($_POST['dept_id']??0));
    $zoneId=$zoneTable?sr_valid_id($conn,$zoneTable,(int)($_POST['church_zone_id']??0)):null;

    if($fullName==='') $errors[]='Full name is required.';
    if(!in_array($gender,['Male','Female','Other'],true)) $errors[]='Please select a valid gender.';
    if($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='A valid email address is required.';
    if(strlen($password)<8) $errors[]='Password must be at least 8 characters.';
    if($password!==$confirm) $errors[]='Passwords do not match.';
    if($dob!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dob)) $errors[]='Invalid date of birth.';
    if($baptism!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$baptism)) $errors[]='Invalid baptism date.';

    if(!$errors){
        $stmt=$conn->prepare('SELECT id FROM members WHERE LOWER(email)=? LIMIT 1'); $stmt->bind_param('s',$email); $stmt->execute();
        $exists=(bool)$stmt->get_result()->fetch_row(); $stmt->close();
        if($exists) $errors[]='A church member is already registered with this email address.';
        $stmt=$conn->prepare('SELECT id FROM users WHERE LOWER(email)=? LIMIT 1'); $stmt->bind_param('s',$email); $stmt->execute();
        $userExists=(bool)$stmt->get_result()->fetch_row(); $stmt->close();
        if($userExists) $errors[]='A user account already exists with this email address.';
    }

    if(!$errors){
        if(!sr_column_exists($conn,'members','user_id')) $errors[]='Member login linkage is not configured. Please contact the administrator.';
    }

    if(!$errors){
        $conn->begin_transaction();
        try {
            $locked=sr_lookup_invitation($conn,$token,true);
            if(!$locked) throw new RuntimeException('This registration invitation is no longer available.');

            $memberNo=sr_generate_member_no($conn);
            $username=sr_unique_username($conn,$requestedUsername,$fullName,$memberNo);
            $passwordHash=password_hash($password,PASSWORD_DEFAULT);
            $role='member'; $status='Active';

            $ust=$conn->prepare('INSERT INTO users (username,password,full_name,role,email,phone,status) VALUES (?,?,?,?,?,?,?)');
            if(!$ust) throw new RuntimeException('Unable to prepare your login account.');
            $ust->bind_param('sssssss',$username,$passwordHash,$fullName,$role,$email,$phone,$status);
            if(!$ust->execute()) throw new RuntimeException('Unable to create your login account: '.$ust->error);
            $userId=(int)$conn->insert_id; $ust->close();

            $joined=date('Y-m-d'); $department=sr_department_name($conn,$deptId); $notes='Self-registered using public member registration link.';
            $dobDb=$dob!==''?$dob:null; $baptismDb=$baptism!==''?$baptism:null;
            if(sr_column_exists($conn,'members','church_zone_id')){
                $mst=$conn->prepare("INSERT INTO members
                    (user_id,member_no,full_name,gender,date_of_birth,phone,email,address,marital_status,occupation,department,dept_id,church_zone_id,joined_date,baptism_date,status,notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                if(!$mst) throw new RuntimeException('Unable to prepare member registration.');
                $mst->bind_param('issssssssssiissss',$userId,$memberNo,$fullName,$gender,$dobDb,$phone,$email,$address,$marital,$occupation,$department,$deptId,$zoneId,$joined,$baptismDb,$status,$notes);
            } else {
                $mst=$conn->prepare("INSERT INTO members
                    (user_id,member_no,full_name,gender,date_of_birth,phone,email,address,marital_status,occupation,department,dept_id,joined_date,baptism_date,status,notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                if(!$mst) throw new RuntimeException('Unable to prepare member registration.');
                $mst->bind_param('issssssssssissss',$userId,$memberNo,$fullName,$gender,$dobDb,$phone,$email,$address,$marital,$occupation,$department,$deptId,$joined,$baptismDb,$status,$notes);
            }
            if(!$mst->execute()) throw new RuntimeException('Unable to save your member details: '.$mst->error);
            $memberId=(int)$conn->insert_id; $mst->close();
            if($hasServiceCol && $serviceAttended!==''){
                $sst=$conn->prepare('UPDATE members SET service_attended=? WHERE id=?');
                $sst->bind_param('si',$serviceAttended,$memberId); $sst->execute(); $sst->close();
            }
            sr_sync_department($conn,$memberId,$deptId,$joined);

            $newUsed=(int)$locked['used_count']+1;
            $deactivate=($locked['max_uses']!==null && (int)$locked['max_uses']>0 && $newUsed >= (int)$locked['max_uses'])?0:1;
            $lst=$conn->prepare('UPDATE member_registration_links SET used_count=?,is_active=? WHERE id=?');
            $linkId=(int)$locked['id']; $lst->bind_param('iii',$newUsed,$deactivate,$linkId); $lst->execute(); $lst->close();

            $conn->commit();
            $success=true; $newMemberNo=$memberNo; $newUsername=$username;
            unset($_SESSION['member_public_csrf']);
        } catch(Throwable $e){
            $conn->rollback();
            $errors[]=$e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Member Self-Registration | <?= sr_h($siteName) ?></title>
<?php if($siteFaviconUrl !== ''): ?>
<link rel="icon" href="<?= sr_h($siteFaviconUrl) ?>">
<link rel="shortcut icon" href="<?= sr_h($siteFaviconUrl) ?>">
<link rel="apple-touch-icon" href="<?= sr_h($siteFaviconUrl) ?>">
<?php endif; ?>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/church-admin.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--reg-primary:var(--primary);--reg-bg:#f5f7fb;--reg-border:#e5e7eb;--reg-text:#172033;--reg-muted:#64748b}
*{box-sizing:border-box}.reg-page{min-height:100vh;background:linear-gradient(135deg,#f7f8ff,#eef7fb);padding:28px 16px;color:var(--reg-text)}
.reg-wrap{max-width:900px;margin:0 auto}.reg-brand{text-align:center;margin:6px 0 22px}.reg-brand .brand-logo{display:flex;align-items:center;justify-content:center;min-height:76px}.reg-brand .site-logo{display:block;max-width:220px;max-height:90px;width:auto;height:auto;object-fit:contain}.reg-brand .icon{width:64px;height:64px;margin:auto;border-radius:18px;background:var(--reg-primary);color:#fff;display:grid;place-items:center;font-size:27px;box-shadow:0 10px 28px rgba(46,49,146,.22)}
.reg-brand h1{font-size:28px;margin:12px 0 5px}.reg-brand p{margin:0;color:var(--reg-muted)}
.reg-card{background:#fff;border:1px solid var(--reg-border);border-radius:20px;box-shadow:0 16px 50px rgba(15,23,42,.08);overflow:hidden}.reg-head{padding:20px 24px;border-bottom:1px solid var(--reg-border);background:#fbfcff}.reg-head h2{margin:0 0 4px;font-size:20px}.reg-head p{margin:0;color:var(--reg-muted);font-size:14px}.reg-body{padding:24px}.reg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.reg-group label{display:block;font-weight:600;font-size:13px;margin:0 0 7px}.reg-group input,.reg-group select,.reg-group textarea{width:100%;border:1px solid #d9deea;border-radius:10px;padding:11px 12px;background:#fff;font:inherit;color:inherit}.reg-group input:focus,.reg-group select:focus,.reg-group textarea:focus{outline:none;border-color:var(--reg-primary);box-shadow:0 0 0 3px rgba(46,49,146,.09)}.reg-span-2{grid-column:span 2}.pwd-wrap{position:relative}.pwd-wrap input{padding-right:44px}.pwd-toggle{position:absolute;right:7px;top:50%;transform:translateY(-50%);border:0;background:transparent;width:34px;height:34px;cursor:pointer;color:#64748b}.reg-actions{display:flex;justify-content:flex-end;margin-top:22px}.reg-btn{border:0;border-radius:10px;padding:12px 18px;background:var(--reg-primary);color:#fff;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none}.reg-alert{padding:13px 15px;border-radius:11px;margin:0 0 18px}.reg-alert.error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.reg-alert.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}.reg-invalid{text-align:center;padding:44px 24px}.reg-invalid i{font-size:44px;color:#dc2626;margin-bottom:14px}.hp{position:absolute!important;left:-9999px!important;opacity:0!important}
@media(max-width:680px){.reg-grid{grid-template-columns:1fr}.reg-span-2{grid-column:span 1}.reg-body{padding:18px}.reg-head{padding:18px}.reg-brand h1{font-size:23px}}
</style>
<link rel="stylesheet" href="../assets/css/form-tabs.css">
<?= site_theme_css() ?></head>
<body>
<div class="reg-page"><div class="reg-wrap">
    <div class="reg-brand">
        <div class="brand-logo">
            <?php if($siteLogoUrl !== ''): ?>
                <img
                    src="<?= sr_h($siteLogoUrl) ?>"
                    alt="<?= sr_h($siteName) ?> logo"
                    class="site-logo"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"
                >
                <div class="icon" style="display:none"><i class="fas fa-church"></i></div>
            <?php else: ?>
                <div class="icon"><i class="fas fa-church"></i></div>
            <?php endif; ?>
        </div>
        <h1><?= sr_h($siteName) ?></h1>
        <p>Church Member Registration · Complete this form to create your account.</p>
    </div>
    <div class="reg-card">
    <?php if($invalidInvite): ?>
        <div class="reg-invalid">
            <i class="fas fa-link-slash"></i>
            <h2>Registration link unavailable</h2>
            <p>This invitation is invalid, expired, has reached its usage limit, or has been replaced. Please request a new registration link from the church administrator.</p>
        </div>
    <?php elseif($success): ?>
        <div class="reg-invalid">
            <i class="fas fa-circle-check" style="color:#059669"></i>
            <h2>Registration completed</h2>
            <p>Your member number is <strong><?= sr_h($newMemberNo) ?></strong> and your username is <strong><?= sr_h($newUsername) ?></strong>.</p>
            <p>You can now sign in using your email or username and the password you created.</p>
            <p style="margin-top:20px"><a class="reg-btn" href="../index.php"><i class="fas fa-right-to-bracket"></i> Go to Login</a></p>
        </div>
    <?php else: ?>
        <div class="reg-head"><h2><i class="fas fa-user-plus"></i> Register Yourself</h2><p><?= sr_h($invite['label'] ?? 'Member self-registration') ?></p></div>
        <div class="reg-body">
            <?php if($errors): ?><div class="reg-alert error"><strong>Please correct the following:</strong><br><?= implode('<br>',array_map('sr_h',$errors)) ?></div><?php endif; ?>
            <form method="post" autocomplete="off" data-form-tabs>
                <input type="hidden" name="token" value="<?= sr_h($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= sr_h($csrf) ?>">
                <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <div class="reg-grid">
                    <div class="reg-group" data-tab-start="Your Details"><label>Full Name *</label><input type="text" name="full_name" required maxlength="200" value="<?= sr_h($_POST['full_name']??'') ?>"></div>
                    <div class="reg-group"><label>Gender *</label><select name="gender" required><option value="">Select gender</option><?php foreach(['Male','Female','Other'] as $g): ?><option value="<?= $g ?>" <?= (($_POST['gender']??'')===$g)?'selected':'' ?>><?= $g ?></option><?php endforeach; ?></select></div>
                    <div class="reg-group"><label>Date of Birth</label><input type="date" name="date_of_birth" value="<?= sr_h($_POST['date_of_birth']??'') ?>"></div>
                    <div class="reg-group"><label>Phone</label><input type="tel" name="phone" maxlength="40" placeholder="+256..." value="<?= sr_h($_POST['phone']??'') ?>"></div>
                    <div class="reg-group"><label>Email Address *</label><input type="email" name="email" required maxlength="190" value="<?= sr_h($_POST['email']??'') ?>"></div>
                    <div class="reg-group"><label>Marital Status</label><select name="marital_status"><option value="">Select</option><?php foreach(['Single','Married','Widowed','Divorced','Separated'] as $m): ?><option value="<?= $m ?>" <?= (($_POST['marital_status']??'')===$m)?'selected':'' ?>><?= $m ?></option><?php endforeach; ?></select></div>
                    <div class="reg-group"><label>Occupation</label><input type="text" name="occupation" maxlength="150" value="<?= sr_h($_POST['occupation']??'') ?>"></div>
                    <?php if($departments): ?><div class="reg-group" data-tab="Church"><label>Department</label><select name="dept_id"><option value="">- None -</option><?php foreach($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= ((int)($_POST['dept_id']??0)===(int)$d['id'])?'selected':'' ?>><?= sr_h($d['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <?php if($zones): ?><div class="reg-group" data-tab="Church"><label>Church Zone</label><select name="church_zone_id"><option value="">- None -</option><?php foreach($zones as $z): ?><option value="<?= (int)$z['id'] ?>" <?= ((int)($_POST['church_zone_id']??0)===(int)$z['id'])?'selected':'' ?>><?= sr_h($z['zone_name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <?php if($hasServiceCol): ?><div class="reg-group" data-tab="Church"><label>Service You Attend</label><input type="text" name="service_attended" list="regServiceOptions" maxlength="120" placeholder="e.g. First Service" value="<?= sr_h($_POST['service_attended']??'') ?>"><datalist id="regServiceOptions"><?php foreach($serviceOptions as $so): ?><option value="<?= sr_h($so) ?>"><?php endforeach; ?></datalist></div><?php endif; ?>
                    <div class="reg-group" data-tab="Church"><label>Baptism Date</label><input type="date" name="baptism_date" value="<?= sr_h($_POST['baptism_date']??'') ?>"></div>
                    <div class="reg-group reg-span-2" data-tab="Your Details"><label>Address</label><textarea name="address" rows="2" maxlength="500"><?= sr_h($_POST['address']??'') ?></textarea></div>
                    <div class="reg-group" data-tab-start="Login Account"><label>Username <small style="font-weight:400;color:#64748b">(optional)</small></label><input type="text" name="login_username" maxlength="50" placeholder="Leave blank to generate one" value="<?= sr_h($_POST['login_username']??'') ?>"></div>
                    <div></div>
                    <div class="reg-group"><label>Password *</label><div class="pwd-wrap"><input id="regPassword" type="password" name="login_password" required minlength="8"><button class="pwd-toggle" type="button" data-target="regPassword" aria-label="Show password"><i class="fas fa-eye"></i></button></div></div>
                    <div class="reg-group"><label>Confirm Password *</label><div class="pwd-wrap"><input id="regConfirm" type="password" name="confirm_password" required minlength="8"><button class="pwd-toggle" type="button" data-target="regConfirm" aria-label="Show password"><i class="fas fa-eye"></i></button></div></div>
                </div>
                <div class="reg-actions"><button class="reg-btn" type="submit"><i class="fas fa-user-check"></i> Complete Registration</button></div>
            </form>
        </div>
    <?php endif; ?>
    </div>
</div></div>
<script>
document.addEventListener('click',function(e){const b=e.target.closest('.pwd-toggle');if(!b)return;const i=document.getElementById(b.dataset.target);if(!i)return;const show=i.type==='password';i.type=show?'text':'password';const icon=b.querySelector('i');if(icon)icon.className=show?'fas fa-eye-slash':'fas fa-eye';b.setAttribute('aria-label',show?'Hide password':'Show password');});
</script>
<script src="../assets/js/form-tabs.js"></script>
</body>
</html>
