<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
requireLogin();
enforce_current_page_permission();

if (!isset($conn) || !($conn instanceof mysqli)) {
    ms_json(false, 'Database connection not found.');
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const MS_TYPES = ['Evangelism', 'Crusade', 'Medical Outreach', 'Community Service', 'School Mission', 'Prison Visit', 'Hospital Visit', 'Church Planting', 'Other'];
const MS_STATUSES = ['Planned', 'Ongoing', 'Completed', 'Cancelled'];
const MS_INCOME = ['Offering', 'Pledge', 'Donation', 'Church Allocation', 'Fundraising', 'Other'];
const MS_EXPENSE = ['Transport', 'Accommodation', 'Meals', 'Materials & Bibles', 'Sound & Equipment', 'Medical Supplies', 'Allowances', 'Other'];

function ms_json($success, $message = '', $extra = []) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit;
}
function ms_post($key, $default = '') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }
function ms_get($key, $default = '') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }
function ms_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function ms_valid_date($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }
function ms_count($v) { return max(0, (int)$v); }
/** Next free mission number for the year, e.g. MSN-2026-0003. */
function ms_mission_no(mysqli $conn) {
    $prefix = 'MSN-' . date('Y') . '-';
    $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(mission_no, ?) AS UNSIGNED)), 0) n FROM missions WHERE mission_no LIKE CONCAT(?, '%')");
    $start = strlen($prefix) + 1;
    $stmt->bind_param('is', $start, $prefix); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $prefix . str_pad(((int)($row['n'] ?? 0)) + 1, 4, '0', STR_PAD_LEFT);
}
function ms_select_sql() {
    return "SELECT ms.*, l.full_name AS leader_name,
                (SELECT COUNT(*) FROM mission_team t WHERE t.mission_id = ms.id) AS team_count,
                (SELECT COALESCE(SUM(amount), 0) FROM mission_finance f WHERE f.mission_id = ms.id AND f.entry_type = 'Income') AS total_income,
                (SELECT COALESCE(SUM(amount), 0) FROM mission_finance f WHERE f.mission_id = ms.id AND f.entry_type = 'Expense') AS total_expense
            FROM missions ms
            LEFT JOIN members l ON l.id = ms.leader_member_id";
}
function ms_read_one(mysqli $conn, $id) {
    $stmt = $conn->prepare(ms_select_sql() . " WHERE ms.id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}
function ms_rows(mysqli $conn, $sql, $id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id); $stmt->execute(); $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}
function ms_require_mission(mysqli $conn, $id) {
    if ($id <= 0) ms_json(false, 'Invalid mission ID.');
    $stmt = $conn->prepare("SELECT id FROM missions WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute(); $found = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
    if (!$found) ms_json(false, 'Mission not found.');
}

try {
    foreach (['missions', 'mission_team', 'mission_finance'] as $t) {
        if (!ms_table_exists($conn, $t)) ms_json(false, 'The ' . $t . ' table was not found. Run database/missions.sql.');
    }
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ms_post('action') : ms_get('action', 'list');

    if ($action === 'list') {
        $res = $conn->query(ms_select_sql() . " ORDER BY ms.start_date DESC, ms.id DESC");
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        ms_json(true, '', ['items' => $items]);
    }

    if ($action === 'get') {
        $id = (int)ms_get('id', 0);
        if ($id <= 0) ms_json(false, 'Invalid mission ID.');
        $row = ms_read_one($conn, $id);
        if (!$row) ms_json(false, 'Mission not found.');
        $team = ms_rows($conn, "SELECT t.id, t.member_id, t.role, m.full_name, m.member_no, m.phone
                                FROM mission_team t LEFT JOIN members m ON m.id = t.member_id
                                WHERE t.mission_id = ? ORDER BY t.role = 'Team Member', m.full_name", $id);
        $finance = ms_rows($conn, "SELECT * FROM mission_finance WHERE mission_id = ? ORDER BY entry_date DESC, id DESC", $id);
        ms_json(true, '', ['item' => $row, 'team' => $team, 'finance' => $finance]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ms_json(false, 'Invalid request method.');

    if ($action === 'create' || $action === 'update') {
        $id = (int)ms_post('id', 0);
        $title = mb_substr(ms_post('title'), 0, 200);
        $type = ms_post('mission_type', 'Evangelism');
        $destination = mb_substr(ms_post('destination'), 0, 200);
        $start = ms_post('start_date');
        $end = ms_post('end_date');
        $leader = (int)ms_post('leader_member_id', 0) ?: null;
        $budget = (float)ms_post('budget', 0);
        $objectives = ms_post('objectives');
        $status = ms_post('status', 'Planned');
        $souls = ms_count(ms_post('souls_won', 0));
        $rededications = ms_count(ms_post('rededications', 0));
        $reached = ms_count(ms_post('people_reached', 0));
        $report = ms_post('report');

        if ($title === '') ms_json(false, 'Please enter the mission title.');
        if (!in_array($type, MS_TYPES, true)) $type = 'Other';
        if (!in_array($status, MS_STATUSES, true)) $status = 'Planned';
        if (!ms_valid_date($start)) ms_json(false, 'Please enter a valid start date.');
        if ($end !== '' && !ms_valid_date($end)) ms_json(false, 'Invalid end date.');
        if ($end !== '' && $end < $start) ms_json(false, 'The end date cannot be before the start date.');
        if ($budget < 0) ms_json(false, 'The budget cannot be negative.');
        $end = $end !== '' ? $end : null;

        if ($action === 'create') {
            $no = ms_mission_no($conn);
            $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
            $stmt = $conn->prepare("INSERT INTO missions (mission_no, title, mission_type, destination, start_date, end_date, leader_member_id, budget, objectives, status, souls_won, rededications, people_reached, report, created_by)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssssidssiiisi', $no, $title, $type, $destination, $start, $end, $leader, $budget, $objectives, $status, $souls, $rededications, $reached, $report, $by);
            $stmt->execute(); $newId = $stmt->insert_id; $stmt->close();
            ms_json(true, 'Mission ' . $no . ' created.', ['id' => $newId]);
        }

        ms_require_mission($conn, $id);
        $stmt = $conn->prepare("UPDATE missions SET title=?, mission_type=?, destination=?, start_date=?, end_date=?, leader_member_id=?, budget=?, objectives=?, status=?, souls_won=?, rededications=?, people_reached=?, report=? WHERE id=?");
        $stmt->bind_param('sssssidssiiisi', $title, $type, $destination, $start, $end, $leader, $budget, $objectives, $status, $souls, $rededications, $reached, $report, $id);
        $stmt->execute(); $stmt->close();
        ms_json(true, 'Mission updated.');
    }

    if ($action === 'delete') {
        $id = (int)ms_post('id', 0);
        ms_require_mission($conn, $id);
        // Team and finance rows go with it (ON DELETE CASCADE).
        $stmt = $conn->prepare("DELETE FROM missions WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        ms_json(true, 'Mission deleted.');
    }

    if ($action === 'team_add') {
        $missionId = (int)ms_post('mission_id', 0);
        $memberId = (int)ms_post('member_id', 0);
        $role = mb_substr(ms_post('role', 'Team Member') ?: 'Team Member', 0, 80);
        ms_require_mission($conn, $missionId);
        if ($memberId <= 0) ms_json(false, 'Please select a member.');
        $stmt = $conn->prepare("INSERT INTO mission_team (mission_id, member_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)");
        $stmt->bind_param('iis', $missionId, $memberId, $role); $stmt->execute(); $stmt->close();
        ms_json(true, 'Team member saved.');
    }

    if ($action === 'team_remove') {
        $id = (int)ms_post('id', 0);
        if ($id <= 0) ms_json(false, 'Invalid team member.');
        $stmt = $conn->prepare("DELETE FROM mission_team WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        ms_json(true, 'Removed from the team.');
    }

    if ($action === 'finance_add') {
        $missionId = (int)ms_post('mission_id', 0);
        $type = ms_post('entry_type');
        $category = ms_post('category');
        $amount = (float)ms_post('amount', 0);
        $date = ms_post('entry_date', date('Y-m-d')) ?: date('Y-m-d');
        $description = mb_substr(ms_post('description'), 0, 255);
        ms_require_mission($conn, $missionId);
        if (!in_array($type, ['Income', 'Expense'], true)) ms_json(false, 'Choose money raised or money spent.');
        if (!in_array($category, $type === 'Income' ? MS_INCOME : MS_EXPENSE, true)) ms_json(false, 'Please choose a category.');
        if ($amount <= 0) ms_json(false, 'Please enter a valid amount.');
        if (!ms_valid_date($date)) ms_json(false, 'Invalid date.');
        $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $stmt = $conn->prepare("INSERT INTO mission_finance (mission_id, entry_type, category, amount, entry_date, description, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issdssi', $missionId, $type, $category, $amount, $date, $description, $by);
        $stmt->execute(); $stmt->close();
        ms_json(true, $type === 'Income' ? 'Money raised recorded.' : 'Expense recorded.');
    }

    if ($action === 'finance_delete') {
        $id = (int)ms_post('id', 0);
        if ($id <= 0) ms_json(false, 'Invalid entry.');
        $stmt = $conn->prepare("DELETE FROM mission_finance WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        ms_json(true, 'Entry deleted.');
    }

    ms_json(false, 'Invalid request action.');
} catch (Throwable $e) {
    error_log('[process-missions] ' . $e->getMessage());
    ms_json(false, 'Something went wrong. Please try again.');
}
