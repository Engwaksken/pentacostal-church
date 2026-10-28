<?php
$pageTitle = 'Dashboard';
require_once 'config/config.php';
requireLogin();

$isMember = function_exists('is_member_user') && is_member_user();

if ($isMember) {
    $memberId = current_member_id();
    $member = null;
    $activeGateways = [];
    $activeDepartmentCount = 0;
    $upcomingProgramCount = 0;
    $attendanceCount = 0;
    $eventCertificateCount = 0;

    if ($memberId > 0) {
        $stmt = $conn->prepare("SELECT m.* FROM members m WHERE m.id=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }

    if (function_exists('access_table_exists') && access_table_exists($conn, 'payment_gateways')) {
        $result = $conn->query("SELECT gateway_type,gateway_name,provider_name,account_number,phone_number,merchant_code,currency FROM payment_gateways WHERE is_active=1 ORDER BY sort_order,gateway_name LIMIT 3");
        if ($result) while ($row = $result->fetch_assoc()) $activeGateways[] = $row;
    }

    if ($memberId > 0 && access_table_exists($conn, 'department_members') && access_table_exists($conn, 'departments')) {
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT d.id) total FROM department_members dm JOIN departments d ON d.id=dm.dept_id JOIN members m ON m.id=dm.member_id WHERE dm.member_id=? AND COALESCE(m.status,'Active')='Active' AND COALESCE(d.status,'Active')='Active'");
        if ($stmt) { $stmt->bind_param('i', $memberId); $stmt->execute(); $activeDepartmentCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close(); }
    }
    if (access_table_exists($conn, 'church_programs')) {
        $result = $conn->query("SELECT COUNT(*) total FROM church_programs WHERE program_date>=CURDATE() AND status='Scheduled'");
        if ($result) $upcomingProgramCount = (int)($result->fetch_assoc()['total'] ?? 0);
    }
    if ($memberId > 0 && access_table_exists($conn, 'attendance')) {
        $stmt = $conn->prepare('SELECT COUNT(*) total FROM attendance WHERE member_id=?');
        if ($stmt) { $stmt->bind_param('i', $memberId); $stmt->execute(); $attendanceCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close(); }
    }
    if ($memberId > 0 && access_table_exists($conn, 'event_certificates') && access_table_exists($conn, 'event_attendance')) {
        $stmt = $conn->prepare('SELECT COUNT(*) total FROM event_certificates ec JOIN event_attendance ea ON ea.event_id=ec.event_id AND ea.member_id=ec.member_id AND ea.attended=1 WHERE ec.member_id=?');
        if ($stmt) { $stmt->bind_param('i', $memberId); $stmt->execute(); $eventCertificateCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close(); }
    }

    require_once 'includes/member-content-functions.php';
    $dashboardVerses = mc_dashboard_verses($conn);

    include 'includes/header.php';
    include 'includes/sidebar.php';
    ?>
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        <div class="content-area member-dashboard">
            <?= showFlash(); ?>
            <div class="member-welcome">
                <div>
                    <h3>Welcome, <?= h(explode(' ', trim((string) ($member['full_name'] ?? ($_SESSION['full_name'] ?? 'Member'))))[0]) ?></h3>
                    <p>Member No: <strong><?= h((string) ($member['member_no'] ?? '-')) ?></strong></p>
                </div>
                <i class="fas fa-church"></i>
            </div>

            <?php if ($dashboardVerses): ?>
                <div class="member-verses">
                    <?php foreach ($dashboardVerses as $i => $verse): ?>
                        <figure class="member-verse<?= $i === 0 ? ' featured' : '' ?>">
                            <?php if ($i === 0): ?><small><i class="fas fa-book-bible"></i> <?= $verse['display_date'] === date('Y-m-d') ? 'Verse of the Day' : 'Bible Verse' ?></small><?php endif; ?>
                            <blockquote><?= h((string)$verse['verse_text']) ?></blockquote>
                            <figcaption><?= h((string)$verse['reference']) ?> <span>(<?= h((string)$verse['translation']) ?>)</span></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid module-stats-grid">
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-sitemap"></i></span><div><strong class="stat-value"><?= number_format($activeDepartmentCount) ?></strong><span class="stat-label">Active Departments</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-calendar-days"></i></span><div><strong class="stat-value"><?= number_format($upcomingProgramCount) ?></strong><span class="stat-label">Upcoming Programs</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-clipboard-check"></i></span><div><strong class="stat-value"><?= number_format($attendanceCount) ?></strong><span class="stat-label">Attendance Records</span></div></div>
            </div>

            <div class="member-quick-grid">
                <a href="<?= h(BASE_URL.'member/giving.php') ?>"><i class="fas fa-hand-holding-heart"></i><span>My Giving</span></a>
                <a href="<?= h(BASE_URL.'member/children.php') ?>"><i class="fas fa-child"></i><span>My Children</span></a>
                <a href="<?= h(BASE_URL.'member/attendance.php') ?>"><i class="fas fa-calendar-check"></i><span>My Attendance</span></a>
                <a href="<?= h(BASE_URL.'member/events.php') ?>"><i class="fas fa-calendar-alt"></i><span>Church Events</span></a>
                <a href="<?= h(BASE_URL.'member/event-certificates.php') ?>"><i class="fas fa-certificate"></i><span>Event Certificates (<?= number_format($eventCertificateCount) ?>)</span></a>
                <a href="<?= h(BASE_URL.'member/projects.php') ?>"><i class="fas fa-project-diagram"></i><span>Projects</span></a>
                <a href="<?= h(BASE_URL.'member/order-of-service.php') ?>"><i class="fas fa-list-ol"></i><span>Order of Service</span></a>
                <a href="<?= h(BASE_URL.'member/hymns.php') ?>"><i class="fas fa-music"></i><span>Church Hymns</span></a>
                <a href="<?= h(BASE_URL.'member/tips.php?dept=health') ?>"><i class="fas fa-heart-pulse"></i><span>Health Tips</span></a>
                <a href="<?= h(BASE_URL.'member/tips.php?dept=education') ?>"><i class="fas fa-graduation-cap"></i><span>Education Tips</span></a>
                <a href="<?= h(BASE_URL.'member/profile.php') ?>"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
            </div>

            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-credit-card"></i> Payment Gateways</span>
                    <a class="btn btn-sm btn-primary" href="<?= h(BASE_URL.'member/payment-gateways.php') ?>">View All</a>
                </div>
                <div class="card-body">
                    <?php if (!$activeGateways): ?>
                        <div class="alert alert-info mb-0">Payment gateways have not yet been published.</div>
                    <?php else: ?>
                        <div class="dashboard-gateway-grid">
                            <?php foreach ($activeGateways as $g):
                                $value = $g['gateway_type']==='bank' ? $g['account_number'] : ($g['gateway_type']==='mobile_money' ? $g['phone_number'] : $g['merchant_code']);
                                $label = $g['gateway_type']==='bank' ? 'Account' : ($g['gateway_type']==='mobile_money' ? 'Mobile Money' : 'Merchant Code');
                            ?>
                                <div class="dashboard-gateway">
                                    <small><?= h((string)$g['provider_name']) ?></small>
                                    <strong><?= h((string)$g['gateway_name']) ?></strong>
                                    <span><?= h($label) ?>: <b><?= h((string)$value) ?></b></span>
                                    <em><?= h((string)$g['currency']) ?></em>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <style>
    .member-welcome{display:flex;justify-content:space-between;align-items:center;padding:22px 24px;border-radius:14px;background:linear-gradient(135deg,var(--primary),var(--primary-lt));color:#fff;margin-bottom:20px}.member-welcome h3{margin:0 0 5px}.member-welcome p{margin:0}.member-welcome i{font-size:48px;opacity:.28}.member-quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.member-quick-grid a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;min-height:105px;border:1px solid #e1e4e8;border-radius:12px;background:#fff;text-decoration:none;color:#222;box-shadow:0 4px 12px rgba(20,30,40,.04)}.member-quick-grid a:hover{border-color:var(--primary);color:var(--primary)}.member-quick-grid i{font-size:24px}.dashboard-gateway-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.dashboard-gateway{position:relative;padding:15px;border:1px solid #e1e4e8;border-radius:10px;background:#fafafa}.dashboard-gateway small,.dashboard-gateway span{display:block;color:#68707d}.dashboard-gateway strong{display:block;margin:4px 0 8px}.dashboard-gateway em{position:absolute;right:12px;top:12px;font-style:normal;font-weight:700;color:var(--primary)}.member-verses{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:20px}.member-verse{margin:0;padding:16px 18px;border:1px solid #e1e4e8;border-left:4px solid var(--primary);border-radius:12px;background:#fff}.member-verse.featured{grid-column:1/-1;background:#f7f3fd}.member-verse small{display:block;margin-bottom:6px;color:var(--primary);font-weight:700;text-transform:uppercase;font-size:11px}.member-verse blockquote{margin:0 0 8px;white-space:pre-wrap;font-size:15px;line-height:1.6;font-style:italic}.member-verse.featured blockquote{font-size:17px}.member-verse figcaption{font-weight:700;color:#333}.member-verse figcaption span{font-weight:400;color:#68707d}
    </style>
    <?php require_once 'includes/footer.php';
    exit;
}

$total_members   = numRows("SELECT id FROM members WHERE status='Active'");
$total_youth     = numRows("SELECT id FROM youth WHERE status='Active'");
$total_baptisms  = numRows("SELECT id FROM baptisms");
$total_ss        = numRows("SELECT id FROM sunday_school_pupils WHERE status='Active'");
$recent_members = mysqli_query($conn,"SELECT * FROM members ORDER BY created_at DESC LIMIT 6");
require_once 'includes/member-content-functions.php';
$dailyVerse = mc_dashboard_verses($conn, 1)[0] ?? null;
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="main-content"><?php include 'includes/topbar.php'; ?><div class="content-area"><?= showFlash(); ?>
<div style="background:linear-gradient(135deg,var(--primary),var(--primary-lt));border-radius:12px;color:#fff;padding:18px 24px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;"><div><h4 style="margin:0;font-weight:700;">Welcome back, <?= h(explode(' ', $_SESSION['full_name'] ?? 'Admin')[0]) ?></h4><small><?= date('l, d F Y') ?></small><?php if ($dailyVerse): ?><blockquote style="margin:10px 0 0;padding-left:12px;border-left:3px solid rgba(255,255,255,.5);font-style:italic;white-space:pre-wrap;max-width:720px;"><?= h((string)$dailyVerse['verse_text']) ?><footer style="margin-top:4px;font-style:normal;font-weight:700;font-size:13px;"><?= h((string)$dailyVerse['reference']) ?> <span style="font-weight:400;opacity:.8;">(<?= h((string)$dailyVerse['translation']) ?>)</span></footer></blockquote><?php endif; ?></div><i class="fas fa-church" style="font-size:42px;opacity:.25;"></i></div>
<div class="dashboard-stat-grid">
<?php foreach ([[$total_members,'Members','fa-users','var(--primary)'],[$total_youth,'Youth','fa-user-friends','#6f42c1'],[$total_baptisms,'Baptisms','fa-water','#007bff'],[$total_ss,'Pupils','fa-child','#17a2b8']] as $stat): ?>
<div class="stat-card"><div class="stat-icon"><i class="fas <?= h($stat[2]) ?>"></i></div><div><div class="stat-number"><?= (int)$stat[0] ?></div><div class="stat-label"><?= h($stat[1]) ?></div></div></div>
<?php endforeach; ?></div>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="fas fa-users"></i> Recent Members</span><a href="<?= BASE_URL ?>members/index.php" class="btn btn-sm btn-primary"><i class="fas fa-user-plus"></i> Add</a></div><div class="card-body p-0"><table class="table table-sm mb-0"><tbody><?php while($m=mysqli_fetch_assoc($recent_members)): ?><tr><td style="width:50px"><span class="avatar-sm"><?= h(strtoupper(substr(trim((string)$m['full_name']),0,1))) ?></span></td><td><strong><?= h((string)$m['full_name']) ?></strong><br><small class="text-muted"><?= h((string)$m['member_no']) ?></small></td><td><small class="text-muted"><?= h((string)($m['department']??'')) ?></small></td></tr><?php endwhile; ?></tbody></table></div><div class="card-footer"><a href="<?= BASE_URL ?>members/">View all members <i class="fas fa-arrow-right"></i></a></div></div>
<div class="card mt-3"><div class="card-header"><i class="fas fa-bolt"></i> Quick Actions</div><div class="card-body"><div class="row"><?php foreach ([['members/index.php','fa-user-plus','Add Member','var(--primary)'],['baptism/index.php','fa-water','Baptism','#007bff'],['sunday_school/index.php','fa-child','Enrol Pupil','#17a2b8'],['attendance/index.php','fa-clipboard-check','Attendance','var(--accent)']] as $q): ?><div class="col-md-3 col-6 mb-2"><a href="<?= BASE_URL.h($q[0]) ?>" class="btn btn-block text-white" style="background:<?= h($q[3]) ?>"><i class="fas <?= h($q[1]) ?>"></i> <?= h($q[2]) ?></a></div><?php endforeach; ?></div></div></div>
</div></div><?php require_once 'includes/footer.php'; ?>
