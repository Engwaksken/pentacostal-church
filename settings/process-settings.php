<?php
declare(strict_types=1);
ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once '../config/db.php';
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($conn) || !($conn instanceof mysqli)) { throw new Exception('Database connection not found.'); }
    $conn->set_charset('utf8mb4');
    while (ob_get_level() > 1) { ob_end_clean(); }

    function st_json(array $data): void { while (ob_get_level()) { ob_end_clean(); } header('Content-Type: application/json; charset=utf-8'); echo json_encode($data); exit; }
    function st_table_exists(mysqli $conn, string $table): bool { $stmt=$conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $stmt->bind_param('s',$table); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return (int)($row['c']??0)>0; }
    function st_col_exists(mysqli $conn, string $table, string $col): bool { $stmt=$conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $stmt->bind_param('ss',$table,$col); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return (int)($row['c']??0)>0; }
    function st_ensure_site(mysqli $conn): void { $conn->query("CREATE TABLE IF NOT EXISTS site_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
    function st_ensure_email(mysqli $conn): void { $conn->query("CREATE TABLE IF NOT EXISTS email_settings (id INT AUTO_INCREMENT PRIMARY KEY, smtp_host VARCHAR(255) NOT NULL, smtp_port INT NOT NULL DEFAULT 587, smtp_user VARCHAR(255) NOT NULL, smtp_password VARCHAR(255) NOT NULL, smtp_secure VARCHAR(20) DEFAULT 'tls', from_email VARCHAR(255) NOT NULL, from_name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
    function st_ensure_sms(mysqli $conn): void { $conn->query("CREATE TABLE IF NOT EXISTS sms_settings (id INT AUTO_INCREMENT PRIMARY KEY, provider_name VARCHAR(120) NULL, api_url TEXT NOT NULL, api_key TEXT NULL, sender_id VARCHAR(80) NULL, http_method VARCHAR(10) NOT NULL DEFAULT 'POST', phone_param VARCHAR(80) NOT NULL DEFAULT 'to', message_param VARCHAR(80) NOT NULL DEFAULT 'message', sender_param VARCHAR(80) NOT NULL DEFAULT 'sender', auth_header TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); }
    function st_save_setting(mysqli $conn, string $key, string $value): void { $stmt=$conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=CURRENT_TIMESTAMP"); $stmt->bind_param('ss',$key,$value); $stmt->execute(); $stmt->close(); }
    function st_upload(string $field, array $allowedExt, int $maxBytes): ?string { if(empty($_FILES[$field]) || (int)$_FILES[$field]['error']===UPLOAD_ERR_NO_FILE) return null; if((int)$_FILES[$field]['error']!==UPLOAD_ERR_OK) throw new Exception('Upload failed for '.$field.'.'); if((int)$_FILES[$field]['size']>$maxBytes) throw new Exception($field.' exceeds allowed file size.'); $ext=strtolower(pathinfo((string)$_FILES[$field]['name'],PATHINFO_EXTENSION)); if(!in_array($ext,$allowedExt,true)) throw new Exception('Invalid file type for '.$field.'.'); $tmp=(string)$_FILES[$field]['tmp_name']; if(!is_uploaded_file($tmp)) throw new Exception('Invalid upload for '.$field.'.'); $dir=dirname(__DIR__).'/assets/uploads/settings'; if(!is_dir($dir) && !mkdir($dir,0775,true)) throw new Exception('Failed to create upload directory.'); $file=$field.'_'.date('YmdHis').'_'.bin2hex(random_bytes(4)).'.'.$ext; $dest=$dir.'/'.$file; if(!move_uploaded_file($tmp,$dest)) throw new Exception('Failed to save '.$field.'.'); return 'assets/uploads/settings/'.$file; }
    function st_color(string $value, string $default): string { $value=trim($value); return preg_match('/^#[0-9a-fA-F]{6}$/',$value)?strtolower($value):$default; }
    function current_value(mysqli $conn, string $table, string $col): string { if(!st_table_exists($conn,$table) || !st_col_exists($conn,$table,$col)) return ''; $res=$conn->query("SELECT `$col` FROM `$table` WHERE is_active=1 ORDER BY id DESC LIMIT 1"); $row=$res&&$res->num_rows?$res->fetch_assoc():[]; return (string)($row[$col]??''); }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'save_branding' || $action === 'save_settings') {
        st_ensure_site($conn);
        $siteName=trim((string)($_POST['site_name']??''));
        if($siteName==='') st_json(['success'=>false,'message'=>'Site name is required.']);
        if(mb_strlen($siteName)>150) st_json(['success'=>false,'message'=>'Site name is too long.']);
        st_save_setting($conn,'site_name',$siteName); st_save_setting($conn,'site_subtitle',trim((string)($_POST['site_subtitle']??'')));
        $logo=st_upload('site_logo',['png','jpg','jpeg','webp'],2*1024*1024); if($logo!==null) st_save_setting($conn,'site_logo',$logo);
        $favicon=st_upload('site_favicon',['ico','png','jpg','jpeg','webp'],1024*1024); if($favicon!==null) st_save_setting($conn,'site_favicon',$favicon);
        $primary=st_color((string)($_POST['primary_color']??''),'#4a1e8f');
        $secondary=st_color((string)($_POST['secondary_color']??''),'#d4a017');
        $background=st_color((string)($_POST['background_color']??''),'#f3f2f8');
        $textColor=st_color((string)($_POST['text_color']??''),'#1e1e2d');
        foreach(['primary_color'=>$primary,'secondary_color'=>$secondary,'background_color'=>$background,'text_color'=>$textColor] as $key=>$value) st_save_setting($conn,$key,$value);
        // Font settings come only from the Appearance tab; the General tab does not send them, so leave them as they are.
        if(isset($_POST['font_family'])){
            $family=trim((string)$_POST['font_family']); $custom=trim((string)($_POST['font_family_custom']??''));
            if($family==='custom'){ if(!site_valid_font_name($custom)) st_json(['success'=>false,'message'=>'Enter a custom font name using letters, numbers, spaces or hyphens (up to 60 characters).']); }
            elseif(!isset(site_font_options()[$family])) st_json(['success'=>false,'message'=>'Choose a font family from the list.']);
            $style=(string)($_POST['font_style']??'normal'); if(!in_array($style,['normal','italic'],true)) $style='normal';
            $sizeChoice=(string)($_POST['font_size']??'14');
            $size=$sizeChoice==='custom'?(int)($_POST['font_size_custom']??0):(int)$sizeChoice;
            if($size<10||$size>24) st_json(['success'=>false,'message'=>'Font size must be between 10px and 24px.']);
            st_save_setting($conn,'font_family',$family); st_save_setting($conn,'font_family_custom',$family==='custom'?$custom:'');
            st_save_setting($conn,'font_style',$style); st_save_setting($conn,'font_size',(string)$size);
            st_json(['success'=>true,'message'=>'Theme colours and fonts saved successfully.','reload'=>true]);
        }
        st_json(['success'=>true,'message'=>'Branding and theme colors saved successfully.']);
    }

    if ($action === 'save_email') {
        st_ensure_email($conn);
        $smtpHost=trim((string)($_POST['smtp_host']??'')); $smtpPort=(int)($_POST['smtp_port']??587); $smtpUser=trim((string)($_POST['smtp_user']??'')); $smtpPassword=(string)($_POST['smtp_password']??''); $smtpSecure=trim((string)($_POST['smtp_secure']??'tls')); $fromEmail=trim((string)($_POST['from_email']??'')); $fromName=trim((string)($_POST['from_name']??site_name())); $active=(int)($_POST['is_active']??1);
        if($smtpHost===''||$smtpUser===''||$fromEmail==='') st_json(['success'=>false,'message'=>'SMTP host, username and from email are required.']);
        if(!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) st_json(['success'=>false,'message'=>'Invalid from email address.']);
        if($smtpPassword==='') $smtpPassword=current_value($conn,'email_settings','smtp_password');
        $conn->query("UPDATE email_settings SET is_active=0");
        $stmt=$conn->prepare("INSERT INTO email_settings (smtp_host,smtp_port,smtp_user,smtp_password,smtp_secure,from_email,from_name,is_active) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sisssssi',$smtpHost,$smtpPort,$smtpUser,$smtpPassword,$smtpSecure,$fromEmail,$fromName,$active); $stmt->execute(); $stmt->close();
        st_json(['success'=>true,'message'=>'Email settings saved successfully.']);
    }

    if ($action === 'save_sms') {
        st_ensure_sms($conn);
        $provider=trim((string)($_POST['provider_name']??'')); $apiUrl=trim((string)($_POST['api_url']??'')); $apiKey=(string)($_POST['api_key']??''); $sender=trim((string)($_POST['sender_id']??'')); $method=strtoupper(trim((string)($_POST['http_method']??'POST'))); $phoneParam=trim((string)($_POST['phone_param']??'to')); $messageParam=trim((string)($_POST['message_param']??'message')); $senderParam=trim((string)($_POST['sender_param']??'sender')); $authHeader=trim((string)($_POST['auth_header']??'')); $active=(int)($_POST['is_active']??1);
        if($apiUrl==='') st_json(['success'=>false,'message'=>'SMS API URL is required.']);
        if(!filter_var($apiUrl,FILTER_VALIDATE_URL)) st_json(['success'=>false,'message'=>'Invalid SMS API URL.']);
        if(!in_array($method,['GET','POST'],true)) $method='POST';
        if($apiKey==='') $apiKey=current_value($conn,'sms_settings','api_key');
        $conn->query("UPDATE sms_settings SET is_active=0");
        $stmt=$conn->prepare("INSERT INTO sms_settings (provider_name,api_url,api_key,sender_id,http_method,phone_param,message_param,sender_param,auth_header,is_active) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssssi',$provider,$apiUrl,$apiKey,$sender,$method,$phoneParam,$messageParam,$senderParam,$authHeader,$active); $stmt->execute(); $stmt->close();
        st_json(['success'=>true,'message'=>'SMS API settings saved successfully.']);
    }

    st_json(['success'=>false,'message'=>'Invalid action.']);
} catch (Throwable $e) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
