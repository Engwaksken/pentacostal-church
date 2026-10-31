<?php
declare(strict_types=1);

function ce_install(mysqli $conn): void {
    $sqls = [
        "CREATE TABLE IF NOT EXISTS church_programs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(180) NOT NULL, program_date DATE NOT NULL, start_time TIME NULL, end_time TIME NULL, venue VARCHAR(180) NULL, description TEXT NULL, audience VARCHAR(120) NULL, status ENUM('Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(program_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS church_services (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, service_title VARCHAR(180) NOT NULL, service_date DATE NOT NULL, service_time TIME NULL, preacher VARCHAR(180) NULL, theme VARCHAR(255) NULL, readings TEXT NULL, lessons_learnt TEXT NULL, recommendation_message TEXT NULL, platform VARCHAR(80) NULL, live_link VARCHAR(500) NULL, recording_link VARCHAR(500) NULL, recording_file VARCHAR(500) NULL, status ENUM('Upcoming','Live','Completed','Cancelled') NOT NULL DEFAULT 'Upcoming', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(service_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS church_harvests (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, harvest_year YEAR NOT NULL, harvest_type ENUM('Sunday School','General') NOT NULL DEFAULT 'General', target_amount DECIMAL(15,2) NOT NULL DEFAULT 0, collected_amount DECIMAL(15,2) NOT NULL DEFAULT 0, harvest_date DATE NULL, notes TEXT NULL, status ENUM('Planned','Open','Closed') NOT NULL DEFAULT 'Planned', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(harvest_year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS member_notebook (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, title VARCHAR(180) NOT NULL, note_text LONGTEXT NOT NULL, note_date DATE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(member_id), INDEX(note_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS member_pledges (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL, pledge_title VARCHAR(180) NOT NULL, pledged_amount DECIMAL(15,2) NOT NULL DEFAULT 0, paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0, due_date DATE NULL, reminder_enabled TINYINT(1) NOT NULL DEFAULT 1, reminder_day TINYINT UNSIGNED NOT NULL DEFAULT 0, reminder_time TIME NOT NULL DEFAULT '07:00:00', status ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active', notes TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(member_id), INDEX(due_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach ($sqls as $sql) { try { $conn->query($sql); } catch (Throwable $e) { error_log('[Church Engagement] '.$e->getMessage()); } }
}

function ce_csrf(): string {
    if (empty($_SESSION['ce_csrf'])) $_SESSION['ce_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['ce_csrf'];
}
function ce_check_csrf(): void {
    if (!hash_equals((string)($_SESSION['ce_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The security token is invalid. Refresh the page and try again.');
    }
}
function ce_redirect(string $url, string $message='', string $type='success'): never {
    if ($message !== '') $_SESSION['ce_flash'] = ['message'=>$message,'type'=>$type];
    header('Location: '.$url); exit;
}
function ce_flash(): void {
    $f=$_SESSION['ce_flash']??null; unset($_SESSION['ce_flash']);
    if(is_array($f)) echo '<div class="alert alert-'.mp_h($f['type']??'info').'">'.mp_h($f['message']??'').'</div>';
}
function ce_progress(float $paid,float $target): float { return $target>0?min(100,round(($paid/$target)*100,1)):0; }
function ce_link(string $url,string $label='Open'): string {
    $url=trim($url); if($url===''||!filter_var($url,FILTER_VALIDATE_URL)) return '-';
    return '<a class="btn btn-sm btn-primary" href="'.mp_h($url).'" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> '.mp_h($label).'</a>';
}
function ce_styles(): void { echo '<style>.ce-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.ce-card{border:1px solid #e5e7eb;border-radius:14px;padding:17px;background:#fff}.ce-card h4{margin:0 0 8px}.ce-meta{display:flex;gap:10px;flex-wrap:wrap;color:#6b7280;font-size:13px;margin-bottom:10px}.ce-badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:var(--primary-fade,#f1ebfa);color:var(--primary);font-size:12px}.ce-progress{height:9px;background:#eceff3;border-radius:999px;overflow:hidden}.ce-progress span{display:block;height:100%;background:var(--primary)}.ce-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.ce-form{display:grid;gap:12px}.ce-form .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ce-form textarea{min-height:150px}.ce-note{white-space:pre-wrap}.ce-alarm{padding:14px;border-radius:12px;background:#fff7e6;border:1px solid #ffd68a;margin-bottom:16px}@media(max-width:700px){.ce-form .row2{grid-template-columns:1fr}}</style>'; }
