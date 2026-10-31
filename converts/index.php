<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once '../includes/church-book-functions.php';

requireLogin();
cb_require_permission('converts.view');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

$hasTable = cb_table_exists($conn, 'church_converts');
$hasFollowups = cb_table_exists($conn, 'church_convert_followups');
$hasLinks = cb_table_exists($conn, 'convert_registration_links');
$hasMembers = cb_table_exists($conn, 'members');
$hasVisitors = cb_table_exists($conn, 'church_visitors');
$csrf = cb_csrf_token();
$canManage = !function_exists('current_user_can') || current_user_can('converts.manage');

$q = cb_clean($_GET['q'] ?? '');
$type = cb_clean($_GET['type'] ?? '');
$status = cb_clean($_GET['status'] ?? '');
$assigned = cb_int($_GET['assigned'] ?? 0);
$from = cb_clean($_GET['from'] ?? '');
$to = cb_clean($_GET['to'] ?? '');
$perPage = cb_int($_GET['per_page'] ?? 25, 25);
if (!in_array($perPage, [10,25,50,100], true)) $perPage = 25;
$page = max(1, cb_int($_GET['page'] ?? 1, 1));

$where = ['1=1'];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.conversion_context LIKE ? OR c.decision_type LIKE ? OR am.full_name LIKE ?)";
    $like = "%{$q}%";
    for ($i=0; $i<6; $i++) { $params[] = $like; $types .= 's'; }
}
if (in_array($type, ['Member','Visitor'], true)) {
    $where[] = 'c.person_type=?'; $params[] = $type; $types .= 's';
}
$allowedStatuses = ['Pending Assignment','In Nurture','Paused','Completed','Transferred'];
if (in_array($status, $allowedStatuses, true)) {
    $where[] = 'c.status=?'; $params[] = $status; $types .= 's';
}
if ($assigned > 0) {
    $where[] = 'c.assigned_member_id=?'; $params[] = $assigned; $types .= 'i';
}
if ($from !== '' && cb_valid_date($from)) {
    $where[] = 'c.conversion_date>=?'; $params[] = $from; $types .= 's';
}
if ($to !== '' && cb_valid_date($to)) {
    $where[] = 'c.conversion_date<=?'; $params[] = $to; $types .= 's';
}
$whereSql = implode(' AND ', $where);

$total = $hasTable ? cb_scalar($conn, 'SELECT COUNT(*) FROM church_converts') : 0;
$thisMonth = $hasTable ? cb_scalar($conn, "SELECT COUNT(*) FROM church_converts WHERE YEAR(conversion_date)=YEAR(CURDATE()) AND MONTH(conversion_date)=MONTH(CURDATE())") : 0;
$pendingAssignment = $hasTable ? cb_scalar($conn, "SELECT COUNT(*) FROM church_converts WHERE status='Pending Assignment'") : 0;
$inNurture = $hasTable ? cb_scalar($conn, "SELECT COUNT(*) FROM church_converts WHERE status='In Nurture'") : 0;
$dueFollowups = $hasTable ? cb_scalar($conn, "SELECT COUNT(*) FROM church_converts WHERE next_follow_up_date IS NOT NULL AND next_follow_up_date<=CURDATE() AND status NOT IN ('Completed','Transferred')") : 0;
$completed = $hasTable ? cb_scalar($conn, "SELECT COUNT(*) FROM church_converts WHERE status='Completed'") : 0;

$rows = [];
$filteredTotal = 0;
$pages = 1;

if ($hasTable && $hasFollowups) {
    $countSql = "SELECT COUNT(*) FROM church_converts c LEFT JOIN members am ON am.id=c.assigned_member_id WHERE {$whereSql}";
    $filteredTotal = cb_scalar($conn, $countSql, $types, $params);
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $perPage;

    $rows = cb_fetch_all(
        $conn,
        "SELECT c.*,
                am.full_name AS assigned_member_name,
                am.member_no AS assigned_member_no,
                lm.full_name AS linked_member_name,
                lm.member_no AS linked_member_no,
                v.full_name AS linked_visitor_name,
                (SELECT COUNT(*) FROM church_convert_followups f WHERE f.convert_id=c.id) AS followup_count,
                (SELECT MAX(f.follow_up_date) FROM church_convert_followups f WHERE f.convert_id=c.id) AS last_followup_date
         FROM church_converts c
         LEFT JOIN members am ON am.id=c.assigned_member_id
         LEFT JOIN members lm ON lm.id=c.member_id
         LEFT JOIN church_visitors v ON v.id=c.visitor_id
         WHERE {$whereSql}
         ORDER BY c.conversion_date DESC, c.id DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $types,
        $params
    );
}

$members = $hasMembers
    ? cb_fetch_all($conn, "SELECT id,member_no,full_name,gender,phone,email,address FROM members ORDER BY full_name LIMIT 5000")
    : [];
$visitors = $hasVisitors
    ? cb_fetch_all($conn, "SELECT id,full_name,gender,phone,email,address,visit_date FROM church_visitors ORDER BY visit_date DESC,id DESC LIMIT 3000")
    : [];

$followupMap = [];
if ($hasFollowups && $rows) {
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $idList = implode(',', array_map('intval', $ids));
    if ($idList !== '') {
        $allFollowups = cb_fetch_all(
            $conn,
            "SELECT f.*,u.full_name recorded_by_name
             FROM church_convert_followups f
             LEFT JOIN users u ON u.id=f.recorded_by
             WHERE f.convert_id IN ({$idList})
             ORDER BY f.follow_up_date DESC,f.id DESC"
        );
        foreach ($allFollowups as $followup) {
            $followupMap[(int)$followup['convert_id']][] = $followup;
        }
    }
}

$links = [];
if ($hasLinks && $canManage) {
    $links = cb_fetch_all(
        $conn,
        "SELECT l.*,
                am.full_name assigned_member_name,
                lm.full_name linked_member_name,
                lm.member_no linked_member_no,
                v.full_name linked_visitor_name
         FROM convert_registration_links l
         LEFT JOIN members am ON am.id=l.assigned_member_id
         LEFT JOIN members lm ON lm.id=l.linked_member_id
         LEFT JOIN church_visitors v ON v.id=l.linked_visitor_id
         ORDER BY l.id DESC LIMIT 40"
    );
}

$share = $_SESSION['cb_share_link'] ?? null;
if (($share['kind'] ?? '') !== 'convert') {
    $share = null;
} else {
    unset($_SESSION['cb_share_link']);
}

$pageTitle = 'Converts & Nurture';
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<link rel="stylesheet" href="../assets/css/church-books.css?v=<?= urlencode((string)@filemtime(__DIR__ . '/../assets/css/church-books.css')) ?>">
<style>
.cb-nurture-progress{min-width:150px}.cb-period{font-size:12px;line-height:1.45}.cb-period strong{display:block;color:#344054}.cb-due{color:#b42318;font-weight:750}.cb-ok{color:#067647;font-weight:750}.cb-followups{display:grid;gap:10px}.cb-followup-item{border:1px solid #e4e7ec;border-radius:12px;padding:12px;background:#fcfcfd}.cb-followup-top{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:7px}.cb-followup-date{font-weight:800}.cb-followup-meta{font-size:11px;color:#667085}.cb-followup-text{font-size:12px;line-height:1.55;margin-top:5px}.cb-inline-check{display:flex;align-items:center;gap:8px;min-height:40px}.cb-link-source{font-size:11px;color:#667085;margin-top:3px}.cb-field-note{font-size:11px;color:#667085;line-height:1.4}.cb-hidden{display:none!important}
</style>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area cb-page">

<?= cb_show_flash() ?>

<div class="cb-page-header">
    <div>
        <h2><i class="fas fa-seedling"></i> Converts & Nurture</h2>
        <p>Register converts, assign each person to a church member for a defined nurture period, and track follow-up progress.</p>
    </div>

    <div class="cb-actions">
        <a class="cb-btn cb-btn-outline" href="export.php?<?= cb_h(cb_query_string([], ['page'])) ?>"><i class="fas fa-file-csv"></i> </a>
        <a class="cb-btn cb-btn-danger" href="pdf.php?<?= cb_h(cb_query_string([], ['page'])) ?>"><i class="fas fa-file-pdf"></i></a>
        <a class="cb-btn cb-btn-outline" target="_blank" href="print.php?<?= cb_h(cb_query_string([], ['page'])) ?>"><i class="fas fa-print"></i> </a>
        <?php if ($canManage): ?>
            <button class="cb-btn cb-btn-info" type="button" data-open-link><i class="fas fa-qrcode"></i> Reg. Link / QR</button>
            <button class="cb-btn cb-btn-primary" type="button" data-open-add><i class="fas fa-plus"></i> Convert</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$hasTable || !$hasFollowups || !$hasLinks): ?>
    <div class="cb-alert cb-alert-error">
        <i class="fas fa-triangle-exclamation"></i>
        <span>Run the updated <code>database/church_books.sql</code> to install the Converts & Nurture tables.</span>
    </div>
<?php else: ?>

<div class="stats-grid module-stats-grid">
<?php foreach ([
    ['fa-users', $total, 'Total Converts'],
    ['fa-calendar', $thisMonth, 'This Month'],
    ['fa-user-clock', $pendingAssignment, 'Pending Assignment'],
    ['fa-seedling', $inNurture, 'In Nurture'],
    ['fa-bell', $dueFollowups, 'Follow-ups Due'],
    ['fa-circle-check', $completed, 'Completed'],
] as [$icon,$number,$label]): ?>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas <?= cb_h($icon) ?>"></i></div>
        <div><div class="stat-value"><?= number_format((int)$number) ?></div><div class="stat-label"><?= cb_h($label) ?></div></div>
    </div>
<?php endforeach; ?>
</div>

<form method="get" action="index.php" class="page-toolbar">
    <div class="page-toolbar-search">
        <i class="fas fa-search"></i>
        <input type="search" name="q" value="<?= cb_h($q) ?>" placeholder="Name, phone, email, decision, assigned member" aria-label="Search converts">
    </div>
    <div class="page-toolbar-filters">
        <select name="type" aria-label="Person type">
            <option value="">All Types</option>
            <option value="Member" <?= $type === 'Member' ? 'selected' : '' ?>>Member</option>
            <option value="Visitor" <?= $type === 'Visitor' ? 'selected' : '' ?>>Visitor</option>
        </select>
        <select name="status" aria-label="Status">
            <option value="">All Statuses</option>
            <?php foreach ($allowedStatuses as $s): ?><option value="<?= cb_h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= cb_h($s) ?></option><?php endforeach; ?>
        </select>
        <select name="assigned" aria-label="Assigned to">
            <option value="0">All Members</option>
            <?php foreach ($members as $member): ?>
                <option value="<?= (int)$member['id'] ?>" <?= $assigned === (int)$member['id'] ? 'selected' : '' ?>><?= cb_h(trim((string)$member['full_name'])) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= cb_h($from) ?>" title="From date" aria-label="From date">
        <input type="date" name="to" value="<?= cb_h($to) ?>" title="To date" aria-label="To date">
    </div>
    <div class="page-toolbar-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
        <a class="btn btn-outline-primary page-toolbar-refresh" href="index.php"><i class="fas fa-rotate-right"></i> Refresh</a>
    </div>
</form>

<div class="cb-panel">
    <div class="cb-table-wrap">
        <table class="cb-table" style="min-width:1250px">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Convert</th>
                    <th>Type</th>
                    <th>Decision</th>
                    <th>Conversion Date</th>
                    <th>Assigned Nurture Member</th>
                    <th>Nurture Period</th>
                    <th>Next Follow-up</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="10" class="cb-empty">No convert records found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $i => $row):
                $nextDue = !empty($row['next_follow_up_date']) && strtotime((string)$row['next_follow_up_date']) <= strtotime(date('Y-m-d')) && !in_array($row['status'], ['Completed','Transferred'], true);
                $recordJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $followJson = json_encode($followupMap[(int)$row['id']] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $statusClass = match ($row['status']) {
                    'Completed' => 'cb-badge-green',
                    'In Nurture' => 'cb-badge-blue',
                    'Pending Assignment' => 'cb-badge-yellow',
                    'Paused' => 'cb-badge-purple',
                    'Transferred' => '',
                    default => '',
                };
            ?>
                <tr>
                    <td><?= (($page - 1) * $perPage) + $i + 1 ?></td>
                    <td>
                        <span class="cb-person"><?= cb_h($row['full_name']) ?></span>
                        <span class="cb-sub"><?= cb_h($row['phone'] ?: $row['email'] ?: '-') ?></span>
                        <?php if ((int)($row['self_registered'] ?? 0) === 1): ?><span class="cb-public-pill"><i class="fas fa-qrcode"></i> QR self-registered</span><?php endif; ?>
                    </td>
                    <td><span class="cb-badge <?= $row['person_type'] === 'Member' ? 'cb-badge-purple' : 'cb-badge-blue' ?>"><?= cb_h($row['person_type']) ?></span></td>
                    <td><?= cb_h($row['decision_type']) ?><span class="cb-sub"><?= cb_h($row['conversion_context'] ?: '-') ?></span></td>
                    <td><?= cb_h(date('d M Y', strtotime($row['conversion_date']))) ?></td>
                    <td>
                        <?php if (!empty($row['assigned_member_name'])): ?>
                            <span class="cb-person"><?= cb_h($row['assigned_member_name']) ?></span>
                            <span class="cb-sub"><?= cb_h($row['assigned_member_no'] ?: '') ?></span>
                        <?php else: ?><span class="cb-badge cb-badge-yellow">Not Assigned</span><?php endif; ?>
                    </td>
                    <td class="cb-period">
                        <strong><?= (int)$row['nurture_period_weeks'] ?> weeks</strong>
                        <?= $row['nurture_start_date'] ? cb_h(date('d M Y', strtotime($row['nurture_start_date']))) : '-' ?>
                        – <?= $row['nurture_end_date'] ? cb_h(date('d M Y', strtotime($row['nurture_end_date']))) : '-' ?>
                        <span class="cb-sub"><?= (int)$row['followup_count'] ?> follow-up<?= (int)$row['followup_count'] === 1 ? '' : 's' ?></span>
                    </td>
                    <td>
                        <?php if ($row['next_follow_up_date']): ?>
                            <span class="<?= $nextDue ? 'cb-due' : 'cb-ok' ?>"><?= cb_h(date('d M Y', strtotime($row['next_follow_up_date']))) ?></span>
                            <?php if ($nextDue): ?><span class="cb-sub">Due / overdue</span><?php endif; ?>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><span class="cb-badge <?= cb_h($statusClass) ?>"><?= cb_h($row['status']) ?></span></td>
                    <td>
                        <div class="cb-row-actions">
                            <button class="cb-btn cb-btn-info cb-btn-sm js-view" type="button" data-record="<?= cb_h($recordJson) ?>"><i class="fas fa-eye"></i></button>
                            <a class="cb-btn cb-btn-danger cb-btn-sm" href="record-pdf.php?id=<?= (int)$row['id'] ?>" title="PDF"><i class="fas fa-file-pdf"></i></a>
                            <?php if ($canManage): ?>
                                <button class="cb-btn cb-btn-success cb-btn-sm js-followup" type="button" data-record="<?= cb_h($recordJson) ?>" data-followups="<?= cb_h($followJson) ?>" title="Nurture Log"><i class="fas fa-clipboard-list"></i></button>
                                <button class="cb-btn cb-btn-primary cb-btn-sm js-edit" type="button" data-record="<?= cb_h($recordJson) ?>"><i class="fas fa-edit"></i></button>
                                <form method="post" action="process-convert.php" onsubmit="return confirm('Delete this convert and all nurture follow-up entries?');">
                                    <input type="hidden" name="csrf_token" value="<?= cb_h($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="cb-btn cb-btn-danger cb-btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="cb-pagination">
        <div>Showing <?= $filteredTotal ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $filteredTotal) ?> of <?= number_format($filteredTotal) ?></div>
        <div class="cb-pages">
            <?php if ($page > 1): ?><a class="cb-page-link" href="?<?= cb_h(cb_query_string(['page'=>$page-1])) ?>">&laquo;</a><?php endif; ?>
            <?php for ($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?><a class="cb-page-link <?= $p === $page ? 'active' : '' ?>" href="?<?= cb_h(cb_query_string(['page'=>$p])) ?>"><?= $p ?></a><?php endfor; ?>
            <?php if ($page < $pages): ?><a class="cb-page-link" href="?<?= cb_h(cb_query_string(['page'=>$page+1])) ?>">&raquo;</a><?php endif; ?>
        </div>
        <form method="get">
            <?php foreach ($_GET as $k=>$v) { if (!in_array($k,['per_page','page'],true) && !is_array($v)) echo '<input type="hidden" name="'.cb_h($k).'" value="'.cb_h($v).'">'; } ?>
            <select class="cb-select" name="per_page" onchange="this.form.submit()">
                <?php foreach ([10,25,50,100] as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> / page</option><?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Add/Edit Convert -->
<div class="cb-modal" id="convertFormModal" aria-hidden="true">
    <div class="cb-modal-card">
        <form method="post" action="process-convert.php" id="convertForm" data-form-tabs>
            <div class="cb-modal-head"><h3><i class="fas fa-seedling"></i> <span id="convertModalTitle">Add Convert</span></h3><button class="cb-modal-close" type="button" data-cb-close>&times;</button></div>
            <div class="cb-modal-body">
                <input type="hidden" name="csrf_token" value="<?= cb_h($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="">

                <div class="cb-form-grid">
                    <div class="cb-section-title">Convert Details</div>
                    <div class="cb-field"><label>Conversion / Decision Date *</label><input class="cb-input" type="date" name="conversion_date" required></div>
                    <div class="cb-field"><label>Person Type *</label><select class="cb-select" name="person_type" id="convertType"><option>Visitor</option><option>Member</option></select></div>

                    <div class="cb-field span-2" id="memberSourceField"><label>Linked Registered Member</label><select class="cb-select" name="member_id" id="convertMember"><option value="">Select member (optional)</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>" data-name="<?= cb_h(trim((string)$m['full_name'])) ?>" data-gender="<?= cb_h($m['gender'] ?? '') ?>" data-phone="<?= cb_h($m['phone'] ?? '') ?>" data-email="<?= cb_h($m['email'] ?? '') ?>" data-address="<?= cb_h($m['address'] ?? '') ?>"><?= cb_h(($m['member_no'] ? $m['member_no'].' · ' : '').trim((string)$m['full_name'])) ?></option><?php endforeach; ?></select><span class="cb-field-note">Selecting a member can prefill their contact details.</span></div>

                    <div class="cb-field span-2" id="visitorSourceField"><label>Linked Visitor</label><select class="cb-select" name="visitor_id" id="convertVisitor"><option value="">Select visitor (optional)</option><?php foreach ($visitors as $v): ?><option value="<?= (int)$v['id'] ?>" data-name="<?= cb_h($v['full_name']) ?>" data-gender="<?= cb_h($v['gender'] ?? '') ?>" data-phone="<?= cb_h($v['phone'] ?? '') ?>" data-email="<?= cb_h($v['email'] ?? '') ?>" data-address="<?= cb_h($v['address'] ?? '') ?>"><?= cb_h($v['full_name'].' · '.date('d M Y',strtotime($v['visit_date']))) ?></option><?php endforeach; ?></select><span class="cb-field-note">Use this when the convert first entered the church through the Visitors Book.</span></div>

                    <div class="cb-field"><label>Full Name *</label><input class="cb-input" name="full_name" required maxlength="180"></div>
                    <div class="cb-field"><label>Gender</label><select class="cb-select" name="gender"><option value="">Select</option><?php foreach (['Male','Female','Other','Prefer not to say'] as $g): ?><option><?= cb_h($g) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Phone</label><input class="cb-input" name="phone" maxlength="50"></div>
                    <div class="cb-field"><label>Email</label><input class="cb-input" type="email" name="email" maxlength="180"></div>
                    <div class="cb-field span-2"><label>Address / Residence</label><input class="cb-input" name="address" maxlength="255"></div>

                    <div class="cb-section-title">Decision & Spiritual Follow-up</div>
                    <div class="cb-field"><label>Decision Type *</label><select class="cb-select" name="decision_type"><?php foreach (['New Convert','Recommitment','Transferred In','Other'] as $d): ?><option><?= cb_h($d) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Service / Event / Place</label><input class="cb-input" name="conversion_context" maxlength="180"></div>
                    <div class="cb-field span-2"><label>Testimony / Decision Notes</label><textarea class="cb-textarea" name="testimony"></textarea></div>
                    <div class="cb-field span-2"><label>Prayer Request / Support Needed</label><textarea class="cb-textarea" name="prayer_request"></textarea></div>

                    <div class="cb-section-title">Nurture Assignment</div>
                    <div class="cb-field span-2"><label>Assigned Nurture Member</label><select class="cb-select" name="assigned_member_id"><option value="">Pending Assignment</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= cb_h(($m['member_no'] ? $m['member_no'].' · ' : '').trim((string)$m['full_name'])) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Nurture Start</label><input class="cb-input" type="date" name="nurture_start_date" id="nurtureStart"></div>
                    <div class="cb-field"><label>Nurture Period (weeks)</label><input class="cb-input" type="number" name="nurture_period_weeks" id="nurtureWeeks" min="1" max="104" value="12"></div>
                    <div class="cb-field"><label>Nurture End</label><input class="cb-input" type="date" name="nurture_end_date" id="nurtureEnd"></div>
                    <div class="cb-field"><label>Status</label><select class="cb-select" name="status"><?php foreach ($allowedStatuses as $s): ?><option><?= cb_h($s) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Next Follow-up</label><input class="cb-input" type="date" name="next_follow_up_date"></div>
                    <div class="cb-field"><label>Discipleship Class / Group</label><input class="cb-input" name="discipleship_class" maxlength="180"></div>
                    <div class="cb-field span-2"><label class="cb-inline-check"><input type="checkbox" name="baptism_interest" value="1"> Interested in baptism / baptism preparation</label></div>
                    <div class="cb-field span-2"><label>Internal Notes</label><textarea class="cb-textarea" name="notes"></textarea></div>
                </div>
            </div>
            <div class="cb-modal-foot"><button class="cb-btn cb-btn-outline" type="button" data-cb-close>Cancel</button><button class="cb-btn cb-btn-primary"><i class="fas fa-save"></i> Save Convert</button></div>
        </form>
    </div>
</div>

<!-- Nurture Follow-up Modal -->
<div class="cb-modal" id="followupModal" aria-hidden="true">
    <div class="cb-modal-card">
        <form method="post" action="process-convert.php" id="followupForm">
            <div class="cb-modal-head"><h3><i class="fas fa-clipboard-list"></i> Nurture Log – <span id="followupPerson"></span></h3><button class="cb-modal-close" type="button" data-cb-close>&times;</button></div>
            <div class="cb-modal-body">
                <input type="hidden" name="csrf_token" value="<?= cb_h($csrf) ?>">
                <input type="hidden" name="action" value="followup">
                <input type="hidden" name="convert_id" value="">

                <div class="cb-form-grid">
                    <div class="cb-section-title">Record New Follow-up</div>
                    <div class="cb-field"><label>Follow-up Date *</label><input class="cb-input" type="date" name="follow_up_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="cb-field"><label>Contact Method</label><select class="cb-select" name="contact_method"><?php foreach (['In Person','Phone Call','WhatsApp','SMS','Home Visit','Church Meeting','Other'] as $m): ?><option><?= cb_h($m) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Progress</label><select class="cb-select" name="progress_status"><?php foreach (['Not Started','Growing','Needs Support','Ready to Complete','Completed'] as $p): ?><option <?= $p==='Growing'?'selected':'' ?>><?= cb_h($p) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Next Follow-up</label><input class="cb-input" type="date" name="next_follow_up_date"></div>
                    <div class="cb-field span-2"><label>Topics Discussed / Activities</label><textarea class="cb-textarea" name="topics_discussed" placeholder="Bible study, prayer, church integration, family, work, baptism preparation..."></textarea></div>
                    <div class="cb-field span-2"><label>Prayer Notes / Support Required</label><textarea class="cb-textarea" name="prayer_notes"></textarea></div>
                </div>

                <div class="cb-actions" style="margin:14px 0 18px"><button class="cb-btn cb-btn-success"><i class="fas fa-plus"></i> Add Follow-up</button></div>

                <div class="cb-section-title" style="margin-bottom:10px">Previous Nurture Follow-ups</div>
                <div class="cb-followups" id="followupHistory"></div>
            </div>
            <div class="cb-modal-foot"><button class="cb-btn cb-btn-outline" type="button" data-cb-close>Close</button></div>
        </form>
    </div>
</div>

<!-- Registration Link / QR Modal -->
<div class="cb-modal" id="convertLinksModal" aria-hidden="true">
    <div class="cb-modal-card">
        <div class="cb-modal-head"><h3><i class="fas fa-qrcode"></i> Convert Registration Link / QR</h3><button class="cb-modal-close" type="button" data-cb-close>&times;</button></div>
        <div class="cb-modal-body">
            <p class="cb-help">Generate a QR/link for one person or a general conversion-registration point. You can pre-assign the nurture member and period before the convert scans the QR.</p>

            <form method="post" action="registration-links.php" id="convertLinkForm">
                <input type="hidden" name="csrf_token" value="<?= cb_h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="cb-form-grid">
                    <div class="cb-field"><label>Link Label</label><input class="cb-input" name="label" placeholder="e.g. Sunday Service Converts"></div>
                    <div class="cb-field"><label>Person Type</label><select class="cb-select" name="person_type" id="linkPersonType"><option value="">Let registrant choose</option><option>Visitor</option><option>Member</option></select></div>
                    <div class="cb-field"><label>Recipient Name (optional)</label><input class="cb-input" name="recipient_name"></div>
                    <div class="cb-field"><label>Recipient Phone (optional)</label><input class="cb-input" name="recipient_phone"></div>
                    <div class="cb-field span-2 cb-hidden" id="linkMemberField"><label>Link to Existing Member</label><select class="cb-select" name="linked_member_id"><option value="">No specific member</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= cb_h(($m['member_no'] ? $m['member_no'].' · ' : '').trim((string)$m['full_name'])) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field span-2 cb-hidden" id="linkVisitorField"><label>Link to Existing Visitor</label><select class="cb-select" name="linked_visitor_id"><option value="">No specific visitor</option><?php foreach ($visitors as $v): ?><option value="<?= (int)$v['id'] ?>"><?= cb_h($v['full_name'].' · '.date('d M Y',strtotime($v['visit_date']))) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-section-title">Nurture Assignment</div>
                    <div class="cb-field span-2"><label>Assign to Church Member</label><select class="cb-select" name="assigned_member_id"><option value="">Assign after registration</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= cb_h(($m['member_no'] ? $m['member_no'].' · ' : '').trim((string)$m['full_name'])) ?></option><?php endforeach; ?></select></div>
                    <div class="cb-field"><label>Nurture Period (weeks)</label><input class="cb-input" type="number" name="nurture_period_weeks" min="1" max="104" value="12" required></div>
                    <div class="cb-field"><label>Maximum Registrations</label><input class="cb-input" type="number" name="max_uses" min="1" max="500" value="1" required></div>
                    <div class="cb-field span-2"><label>Expires At (optional)</label><input class="cb-input" type="datetime-local" name="expires_at"></div>
                </div>
                <div class="cb-actions" style="margin-top:14px"><button class="cb-btn cb-btn-primary"><i class="fas fa-link"></i> Generate Link & QR</button></div>
            </form>

            <div class="cb-link-list">
            <?php foreach ($links as $link):
                $url = cb_public_url('public_register/convert.php', ['token'=>$link['token']]);
                $expired = !empty($link['expires_at']) && strtotime((string)$link['expires_at']) <= time();
                $activeLink = (int)$link['is_active'] === 1 && !$expired && ((int)$link['max_uses'] <= 0 || (int)$link['used_count'] < (int)$link['max_uses']);
                $source = $link['linked_member_name'] ? 'Member: '.$link['linked_member_name'] : ($link['linked_visitor_name'] ? 'Visitor: '.$link['linked_visitor_name'] : ($link['person_type'] ?: 'Registrant chooses type'));
            ?>
                <div class="cb-link-row">
                    <div class="cb-link-row-main">
                        <div class="cb-link-label"><?= cb_h($link['label'] ?: $link['recipient_name'] ?: 'Convert Registration') ?></div>
                        <div class="cb-link-source"><?= cb_h($source) ?> · Nurture: <?= (int)$link['nurture_period_weeks'] ?> weeks<?= $link['assigned_member_name'] ? ' · Assigned to '.$link['assigned_member_name'] : ' · Assignment pending' ?></div>
                        <div class="cb-link-meta">Used <?= (int)$link['used_count'] ?>/<?= (int)$link['max_uses'] ?><?= $link['expires_at'] ? ' · Expires '.cb_h(date('d M Y H:i',strtotime($link['expires_at']))) : ' · No expiry' ?> · <?= $activeLink ? 'Active' : 'Inactive' ?></div>
                    </div>
                    <div class="cb-link-row-actions">
                        <button class="cb-btn cb-btn-info cb-btn-sm" type="button" onclick='CBShare(<?= json_encode($url) ?>,<?= json_encode($link['label'] ?: $link['recipient_name'] ?: 'Convert Registration') ?>)'><i class="fas fa-qrcode"></i> Share</button>
                        <?php if ($activeLink): ?><form method="post" action="registration-links.php"><input type="hidden" name="csrf_token" value="<?= cb_h($csrf) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$link['id'] ?>"><button class="cb-btn cb-btn-danger cb-btn-sm"><i class="fas fa-ban"></i></button></form><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="cb-modal-foot"><button class="cb-btn cb-btn-outline" type="button" data-cb-close>Close</button></div>
    </div>
</div>
<?php endif; ?>

<!-- View Modal -->
<div class="cb-modal" id="convertViewModal" aria-hidden="true">
    <div class="cb-modal-card cb-modal-md">
        <div class="cb-modal-head"><h3><i class="fas fa-eye"></i> Convert & Nurture Details</h3><button class="cb-modal-close" type="button" data-cb-close>&times;</button></div>
        <div class="cb-modal-body"><div class="cb-view-grid" id="convertView"></div></div>
        <div class="cb-modal-foot"><button class="cb-btn cb-btn-outline" type="button" data-cb-close>Close</button></div>
    </div>
</div>

<!-- Share QR Modal -->
<div class="cb-modal" id="cbShareModal" aria-hidden="true">
    <div class="cb-modal-card cb-modal-md">
        <div class="cb-modal-head"><h3><i class="fas fa-share-nodes"></i> <span id="cbShareTitle">Convert Registration</span></h3><button class="cb-modal-close" type="button" data-cb-close>&times;</button></div>
        <div class="cb-modal-body">
            <div class="cb-share-grid">
                <div class="cb-qr-box" id="cbQrBox"></div>
                <div>
                    <p class="cb-help">The convert can scan this QR code or open the link on a phone and register without logging in. The assigned nurture member and period are applied automatically.</p>
                    <div class="cb-link-copy"><input class="cb-input" id="cbShareUrl" readonly><button class="cb-btn cb-btn-outline" id="cbCopyLink" type="button"><i class="fas fa-copy"></i> Copy</button></div>
                    <div class="cb-actions" style="margin-top:12px"><button class="cb-btn cb-btn-primary" id="cbNativeShare" type="button"><i class="fas fa-share-nodes"></i> Share Link</button><button class="cb-btn cb-btn-success" id="cbDownloadQr" type="button"><i class="fas fa-download"></i> Download QR</button></div>
                </div>
            </div>
        </div>
        <div class="cb-modal-foot"><button class="cb-btn cb-btn-outline" type="button" data-cb-close>Close</button></div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
    'use strict';
    const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
    const esc=v=>String(v??'-').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function openModal(target){const m=typeof target==='string'?$(target):target;if(!m)return;m.classList.add('show');m.setAttribute('aria-hidden','false');document.body.classList.add('cms-modal-open');}
    function closeModal(m){if(!m)return;m.classList.remove('show');m.setAttribute('aria-hidden','true');if(!$('.cb-modal.show'))document.body.classList.remove('cms-modal-open');}
    $$('[data-cb-close]').forEach(b=>b.addEventListener('click',()=>closeModal(b.closest('.cb-modal'))));
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){const m=$('.cb-modal.show');if(m)closeModal(m);}});
    // Backdrop clicks intentionally do not close modals, matching Add Member behaviour.
    window.CBModal={open:openModal,close:closeModal};

    function setSourceVisibility(){
        const type=$('#convertType')?.value||'Visitor';
        $('#memberSourceField')?.classList.toggle('cb-hidden',type!=='Member');
        $('#visitorSourceField')?.classList.toggle('cb-hidden',type!=='Visitor');
    }
    $('#convertType')?.addEventListener('change',setSourceVisibility);

    function fillFromOption(select){
        const f=$('#convertForm'),o=select?.selectedOptions?.[0];if(!f||!o||!o.value)return;
        const map={full_name:'name',gender:'gender',phone:'phone',email:'email',address:'address'};
        Object.entries(map).forEach(([field,data])=>{if(f.elements[field] && !f.elements[field].value)f.elements[field].value=o.dataset[data]||'';});
    }
    $('#convertMember')?.addEventListener('change',function(){fillFromOption(this)});
    $('#convertVisitor')?.addEventListener('change',function(){fillFromOption(this)});

    function calculateEnd(){
        const start=$('#nurtureStart')?.value,weeks=parseInt($('#nurtureWeeks')?.value||'0',10),end=$('#nurtureEnd');
        if(!start||!weeks||!end)return;const d=new Date(start+'T00:00:00');d.setDate(d.getDate()+(weeks*7));end.value=d.toISOString().slice(0,10);
    }
    $('#nurtureStart')?.addEventListener('change',calculateEnd);$('#nurtureWeeks')?.addEventListener('input',calculateEnd);

    $('[data-open-add]')?.addEventListener('click',()=>{
        const f=$('#convertForm');f.reset();f.elements.id.value='';f.elements.conversion_date.value='<?= date('Y-m-d') ?>';f.elements.person_type.value='Visitor';f.elements.nurture_start_date.value='<?= date('Y-m-d') ?>';f.elements.nurture_period_weeks.value='12';f.elements.status.value='Pending Assignment';$('#convertModalTitle').textContent='Add Convert';setSourceVisibility();calculateEnd();openModal('#convertFormModal');
    });

    $$('.js-edit').forEach(b=>b.addEventListener('click',()=>{
        const r=JSON.parse(b.dataset.record||'{}'),f=$('#convertForm');f.reset();Object.keys(r).forEach(k=>{if(!f.elements[k])return;if(f.elements[k].type==='checkbox')f.elements[k].checked=String(r[k])==='1';else f.elements[k].value=r[k]??'';});$('#convertModalTitle').textContent='Edit Convert';setSourceVisibility();openModal('#convertFormModal');
    }));

    const viewLabels={full_name:'Full Name',person_type:'Person Type',linked_member_name:'Linked Member',linked_visitor_name:'Linked Visitor',conversion_date:'Conversion Date',decision_type:'Decision Type',conversion_context:'Service / Event / Place',gender:'Gender',phone:'Phone',email:'Email',address:'Address',testimony:'Testimony / Decision Notes',prayer_request:'Prayer Request',assigned_member_name:'Assigned Nurture Member',nurture_start_date:'Nurture Start',nurture_end_date:'Nurture End',nurture_period_weeks:'Nurture Period (weeks)',status:'Status',next_follow_up_date:'Next Follow-up',discipleship_class:'Discipleship Class',notes:'Internal Notes'};
    $$('.js-view').forEach(b=>b.addEventListener('click',()=>{const r=JSON.parse(b.dataset.record||'{}');$('#convertView').innerHTML=Object.entries(viewLabels).map(([k,l])=>`<div class="cb-view-item ${['address','testimony','prayer_request','notes'].includes(k)?'span-2':''}"><strong>${esc(l)}</strong><span>${esc(r[k]||'-')}</span></div>`).join('')+`<div class="cb-view-item"><strong>Baptism Interest</strong><span>${String(r.baptism_interest)==='1'?'Yes':'No'}</span></div><div class="cb-view-item"><strong>Self-registered</strong><span>${String(r.self_registered)==='1'?'Yes – QR/link':'No'}</span></div>`;openModal('#convertViewModal');}));

    $$('.js-followup').forEach(b=>b.addEventListener('click',()=>{
        const r=JSON.parse(b.dataset.record||'{}'),items=JSON.parse(b.dataset.followups||'[]'),f=$('#followupForm');f.reset();f.elements.convert_id.value=r.id||'';f.elements.follow_up_date.value='<?= date('Y-m-d') ?>';$('#followupPerson').textContent=r.full_name||'';
        const box=$('#followupHistory');box.innerHTML=items.length?items.map(x=>`<div class="cb-followup-item"><div class="cb-followup-top"><span class="cb-followup-date">${esc(x.follow_up_date||'-')}</span><span class="cb-badge">${esc(x.progress_status||'-')}</span></div><div class="cb-followup-meta">${esc(x.contact_method||'-')}${x.recorded_by_name?' · Recorded by '+esc(x.recorded_by_name):''}${x.next_follow_up_date?' · Next: '+esc(x.next_follow_up_date):''}</div>${x.topics_discussed?`<div class="cb-followup-text"><strong>Topics:</strong> ${esc(x.topics_discussed)}</div>`:''}${x.prayer_notes?`<div class="cb-followup-text"><strong>Prayer / support:</strong> ${esc(x.prayer_notes)}</div>`:''}</div>`).join(''):'<div class="cb-empty">No nurture follow-up has been recorded yet.</div>';
        openModal('#followupModal');
    }));

    function syncLinkType(){const type=$('#linkPersonType')?.value||'';$('#linkMemberField')?.classList.toggle('cb-hidden',type!=='Member');$('#linkVisitorField')?.classList.toggle('cb-hidden',type!=='Visitor');}
    $('#linkPersonType')?.addEventListener('change',syncLinkType);
    $('[data-open-link]')?.addEventListener('click',()=>{syncLinkType();openModal('#convertLinksModal');});

    window.CBShare=function(url,title){const input=$('#cbShareUrl'),qr=$('#cbQrBox'),heading=$('#cbShareTitle');if(input)input.value=url;if(heading)heading.textContent=title||'Convert Registration';if(qr){qr.innerHTML='';if(window.QRCode)new QRCode(qr,{text:url,width:220,height:220,correctLevel:QRCode.CorrectLevel.H});else qr.innerHTML='<small>QR library unavailable. Use the link below.</small>';}openModal('#cbShareModal');};
    $('#cbCopyLink')?.addEventListener('click',async function(){const i=$('#cbShareUrl');if(!i)return;try{await navigator.clipboard.writeText(i.value);this.innerHTML='<i class="fas fa-check"></i> Copied';setTimeout(()=>this.innerHTML='<i class="fas fa-copy"></i> Copy',1600);}catch(e){i.select();document.execCommand('copy');}});
    $('#cbNativeShare')?.addEventListener('click',async function(){const url=$('#cbShareUrl')?.value||'';if(navigator.share){try{await navigator.share({title:$('#cbShareTitle')?.textContent||'Convert Registration',url});}catch(e){}}else{$('#cbCopyLink')?.click();}});
    $('#cbDownloadQr')?.addEventListener('click',function(){const box=$('#cbQrBox');if(!box)return;const canvas=box.querySelector('canvas'),img=box.querySelector('img');const src=canvas?canvas.toDataURL('image/png'):(img?img.src:'');if(!src)return;const a=document.createElement('a');a.href=src;a.download='convert-registration-qr.png';document.body.appendChild(a);a.click();a.remove();});
})();
</script>

<?php if ($share): ?><script>document.addEventListener('DOMContentLoaded',()=>CBShare(<?= json_encode($share['url']) ?>,<?= json_encode($share['title']) ?>));</script><?php endif; ?>

<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
