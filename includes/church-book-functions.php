<?php
declare(strict_types=1);

if (!function_exists('cb_h')) {
    function cb_h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('cb_table_exists')) {
    function cb_table_exists(mysqli $conn, string $table): bool {
        $stmt=$conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        if(!$stmt) return false; $stmt->bind_param('s',$table); $stmt->execute(); $row=$stmt->get_result()->fetch_row(); $stmt->close();
        return (int)($row[0]??0)>0;
    }
}

if (!function_exists('cb_column_exists')) {
    function cb_column_exists(mysqli $conn,string $table,string $column): bool {
        $stmt=$conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        if(!$stmt) return false; $stmt->bind_param('ss',$table,$column); $stmt->execute(); $row=$stmt->get_result()->fetch_row(); $stmt->close();
        return (int)($row[0]??0)>0;
    }
}

if (!function_exists('cb_bind')) {
    function cb_bind(mysqli_stmt $stmt,string $types,array &$params): void {
        if($types===''||!$params) return;
        $refs=[]; foreach($params as $k=>&$v){$refs[$k]=&$v;} unset($v);
        $stmt->bind_param($types,...$refs);
    }
}

if (!function_exists('cb_fetch_all')) {
    function cb_fetch_all(mysqli $conn,string $sql,string $types='',array $params=[]): array {
        $stmt=$conn->prepare($sql); if(!$stmt) throw new RuntimeException('Database query could not be prepared: '.$conn->error);
        cb_bind($stmt,$types,$params); $stmt->execute(); $res=$stmt->get_result(); $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[]; $stmt->close(); return $rows;
    }
}

if (!function_exists('cb_fetch_one')) {
    function cb_fetch_one(mysqli $conn,string $sql,string $types='',array $params=[]): ?array { $rows=cb_fetch_all($conn,$sql,$types,$params); return $rows[0]??null; }
}

if (!function_exists('cb_scalar')) {
    function cb_scalar(mysqli $conn,string $sql,string $types='',array $params=[]): int { $row=cb_fetch_one($conn,$sql,$types,$params); return $row?(int)array_values($row)[0]:0; }
}

if (!function_exists('cb_csrf_token')) {
    function cb_csrf_token(): string { if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return (string)$_SESSION['csrf_token']; }
}

if (!function_exists('cb_verify_csrf')) {
    function cb_verify_csrf(): void {
        $sent=(string)($_POST['csrf_token']??''); $saved=(string)($_SESSION['csrf_token']??'');
        if($saved===''||$sent===''||!hash_equals($saved,$sent)){http_response_code(419);exit('Invalid or expired security token. Refresh the page and try again.');}
    }
}

if (!function_exists('cb_flash')) {
    function cb_flash(string $message,string $type='success'): void { $_SESSION['church_book_flash']=['message'=>$message,'type'=>$type]; }
}
if (!function_exists('cb_show_flash')) {
    function cb_show_flash(): string {
        $f=$_SESSION['church_book_flash']??null; unset($_SESSION['church_book_flash']); if(!$f)return '';
        $type=($f['type']??'')==='error'?'error':'success'; $icon=$type==='error'?'fa-circle-exclamation':'fa-circle-check';
        return '<div class="cb-alert cb-alert-'.$type.'"><i class="fas '.$icon.'"></i><span>'.cb_h($f['message']??'').'</span></div>';
    }
}

if (!function_exists('cb_require_permission')) {
    function cb_require_permission(string $permission): void {
        if(function_exists('current_user_can')&&!current_user_can($permission)){http_response_code(403);exit('You do not have permission to access this module.');}
    }
}

if (!function_exists('cb_int')) { function cb_int(mixed $value,int $default=0): int { return is_numeric($value)?(int)$value:$default; } }
if (!function_exists('cb_clean')) { function cb_clean(mixed $value): string { return trim((string)($value??'')); } }
if (!function_exists('cb_nullable_int')) { function cb_nullable_int(mixed $value): ?int { $v=trim((string)($value??'')); return $v===''?null:(int)$v; } }
if (!function_exists('cb_valid_date')) { function cb_valid_date(string $v): bool { if($v==='')return true; $d=DateTime::createFromFormat('Y-m-d',$v); return $d&&$d->format('Y-m-d')===$v; } }
if (!function_exists('cb_current_user_id')) { function cb_current_user_id(): ?int { $v=$_SESSION['user_id']??$_SESSION['id']??null; return is_numeric($v)?(int)$v:null; } }

if (!function_exists('cb_query_string')) {
    function cb_query_string(array $overrides=[],array $remove=[]): string {
        $d=$_GET; foreach($remove as $k)unset($d[$k]); foreach($overrides as $k=>$v){if($v===null||$v==='')unset($d[$k]);else$d[$k]=$v;} return http_build_query($d);
    }
}

if (!function_exists('cb_site_setting')) {
    function cb_site_setting(mysqli $conn,string $key,string $default=''): string {
        if(!cb_table_exists($conn,'site_settings')||!cb_column_exists($conn,'site_settings','setting_key')||!cb_column_exists($conn,'site_settings','setting_value')) return $default;
        $row=cb_fetch_one($conn,"SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1",'s',[$key]); $v=trim((string)($row['setting_value']??'')); return $v!==''?$v:$default;
    }
}

if (!function_exists('cb_absolute_base_url')) {
    function cb_absolute_base_url(): string {
        $base=defined('BASE_URL')?(string)BASE_URL:'/';
        if(preg_match('~^https?://~i',$base)) return rtrim($base,'/').'/';
        $scheme=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')?'https':'http';
        $host=trim((string)($_SERVER['HTTP_HOST']??''));
        if($host==='') return rtrim($base,'/').'/';
        return $scheme.'://'.$host.'/'.ltrim(rtrim($base,'/').'/','/');
    }
}

if (!function_exists('cb_public_url')) {
    function cb_public_url(string $path,array $query=[]): string {
        $url=cb_absolute_base_url().ltrim($path,'/'); if($query)$url.='?'.http_build_query($query); return $url;
    }
}

if (!function_exists('cb_registration_link_lookup')) {
    function cb_registration_link_lookup(mysqli $conn,string $table,string $token,bool $forUpdate=false): ?array {
        if($token===''||!preg_match('/^[a-f0-9]{64}$/',$token)||!cb_table_exists($conn,$table)) return null;
        $suffix=$forUpdate?' FOR UPDATE':'';
        $row=cb_fetch_one($conn,"SELECT * FROM `{$table}` WHERE token=? LIMIT 1{$suffix}",'s',[$token]);
        if(!$row||(int)($row['is_active']??0)!==1) return null;
        if(!empty($row['expires_at'])&&strtotime((string)$row['expires_at'])<=time()) return null;
        $max=(int)($row['max_uses']??0); $used=(int)($row['used_count']??0); if($max>0&&$used>=$max) return null;
        return $row;
    }
}

if (!function_exists('cb_consume_registration_link')) {
    function cb_consume_registration_link(mysqli $conn,string $table,array $row): void {
        $used=(int)($row['used_count']??0)+1; $max=(int)($row['max_uses']??0); $active=($max>0&&$used>=$max)?0:1; $id=(int)$row['id'];
        $stmt=$conn->prepare("UPDATE `{$table}` SET used_count=?,is_active=?,last_used_at=NOW() WHERE id=?");
        if(!$stmt) throw new RuntimeException('Unable to update registration link usage.');
        $stmt->bind_param('iii',$used,$active,$id); if(!$stmt->execute())throw new RuntimeException('Unable to update registration link usage.'); $stmt->close();
    }
}

if (!function_exists('cb_datetime_local_to_sql')) {
    function cb_datetime_local_to_sql(string $value): ?string {
        $value=trim($value); if($value==='')return null; $ts=strtotime($value); return $ts?date('Y-m-d H:i:s',$ts):null;
    }
}

if (!function_exists('cb_load_dompdf')) {
    function cb_load_dompdf(): void {
        if (class_exists('Dompdf\\Dompdf')) return;
        $candidates = [
            __DIR__ . '/../vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        foreach ($candidates as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists('Dompdf\\Dompdf')) return;
            }
        }
        http_response_code(500);
        exit('DOMPDF is not installed. Run: composer require dompdf/dompdf');
    }
}

/** The labels printed under the signature lines of a wedding record, in order (two per row). */
if (!defined('CB_WEDDING_SIGNATURE_DEFAULTS')) {
    define('CB_WEDDING_SIGNATURE_DEFAULTS', ['Groom Signature', 'Bride Signature', 'Witness 1 Signature', 'Witness 2 Signature', 'Officiating Minister', 'Date / Church Stamp']);
}

if (!function_exists('cb_wedding_signature_lines')) {

    /** The six wedding record signature labels set by an admin (Wedding Vows Book > Signature Lines); a blank one uses its default. */
    function cb_wedding_signature_lines(mysqli $conn): array {
        $saved = json_decode(cb_site_setting($conn, 'wedding_signature_lines', '[]'), true);
        $lines = [];
        foreach (CB_WEDDING_SIGNATURE_DEFAULTS as $i => $default) {
            $label = is_array($saved) ? trim((string)($saved[$i] ?? '')) : '';
            $lines[] = $label !== '' ? $label : $default;
        }
        return $lines;
    }

    /** Saves the six labels; blank ones are stored empty so they fall back to their defaults. */
    function cb_save_wedding_signature_lines(mysqli $conn, array $labels): void {
        $clean = [];
        foreach (array_keys(CB_WEDDING_SIGNATURE_DEFAULTS) as $i) {
            $clean[] = mb_substr(trim(strip_tags((string)($labels[$i] ?? ''))), 0, 80);
        }
        $conn->query("CREATE TABLE IF NOT EXISTS site_settings (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(100) NOT NULL UNIQUE, setting_value TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $key = 'wedding_signature_lines';
        $value = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}
