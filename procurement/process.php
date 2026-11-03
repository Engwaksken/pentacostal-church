<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
requireLogin();
enforce_current_page_permission();
require_once '../includes/procurement-functions.php';

function pr_json(bool $success, string $message = '', array $extra = []): void {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) pr_json(false, 'Database connection not found.');
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!pr_installed($conn)) pr_json(false, 'Procurement tables not found. Run database/procurement.sql.');
if (!pr_can_access($conn)) pr_json(false, 'Access denied. Only the procurement team and department leaders can use Procurement.');

function pr_post(string $k, string $d = ''): string { return isset($_POST[$k]) && !is_array($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function pr_get(string $k, string $d = ''): string { return isset($_GET[$k]) && !is_array($_GET[$k]) ? trim((string)$_GET[$k]) : $d; }
function pr_date_or_null(string $d, string $error): ?string {
    if ($d === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) pr_json(false, $error);
    return $d;
}

$memberId = pr_member_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$memberIdOrNull = $memberId > 0 ? $memberId : null;
$isAdmin = pr_is_admin();
$canManage = pr_can_manage($conn);
$ledDepts = pr_led_departments($conn);

function pr_log(mysqli $conn, int $requestId, string $action, string $comment): void {
    global $memberIdOrNull, $userId;
    $c = $comment !== '' ? $comment : null;
    $st = $conn->prepare('INSERT INTO procurement_request_log (request_id, action, comment, member_id, user_id) VALUES (?, ?, ?, ?, ?)');
    $st->bind_param('issii', $requestId, $action, $c, $memberIdOrNull, $userId);
    $st->execute(); $st->close();
}

function pr_select_sql(): string {
    return "SELECT r.*, d.name AS department_name,
                COALESCE(rm.full_name, NULLIF(ru.full_name,''), ru.username) AS requested_by_name,
                COALESCE(dm.full_name, NULLIF(du.full_name,''), du.username) AS decided_by_name,
                (SELECT COUNT(*) FROM procurement_request_items i WHERE i.request_id = r.id) AS item_count
            FROM procurement_requests r
            LEFT JOIN departments d ON d.id = r.department_id
            LEFT JOIN members rm ON rm.id = r.requested_by_member_id
            LEFT JOIN users ru ON ru.id = r.requested_by_user_id
            LEFT JOIN members dm ON dm.id = r.decided_by_member_id
            LEFT JOIN users du ON du.id = r.decided_by_user_id";
}

/** Loads a request the current user may see, or stops. */
function pr_load(mysqli $conn, int $id): array {
    global $canManage, $ledDepts;
    if ($id <= 0) pr_json(false, 'Invalid request.');
    $st = $conn->prepare(pr_select_sql() . ' WHERE r.id = ? LIMIT 1');
    $st->bind_param('i', $id); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    if (!$row) pr_json(false, 'Request not found.');
    if (!$canManage && !isset($ledDepts[(int)$row['department_id']])) pr_json(false, 'You can only see requests from departments you lead.');
    return $row;
}

/** Department leaders (and admins) own a request's content. */
function pr_owns(array $r): bool {
    global $isAdmin, $ledDepts;
    return $isAdmin || isset($ledDepts[(int)$r['department_id']]);
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? pr_post('action') : pr_get('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = pr_post('csrf_token');
    if ($token === '' || !hash_equals(pr_csrf_token(), $token)) pr_json(false, 'Your session expired. Reload the page and try again.');
}

try {
    /* ---------------- Requests ---------------- */
    if ($action === 'list') {
        $sql = pr_select_sql();
        if (!$canManage) {
            if (!$ledDepts) pr_json(true, '', ['items' => []]);
            $sql .= ' WHERE r.department_id IN (' . implode(',', array_map('intval', array_keys($ledDepts))) . ')';
        }
        $sql .= " ORDER BY FIELD(r.status,'Pending','Approved','Ordered','Delivered','Rejected','Cancelled'), r.created_at DESC";
        $res = $conn->query($sql); $items = [];
        while ($r = $res->fetch_assoc()) $items[] = $r;
        pr_json(true, '', ['items' => $items]);
    }

    if ($action === 'get') {
        $r = pr_load($conn, (int)pr_get('id'));
        $id = (int)$r['id'];
        $st = $conn->prepare('SELECT * FROM procurement_request_items WHERE request_id = ? ORDER BY id');
        $st->bind_param('i', $id); $st->execute(); $items = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        $st = $conn->prepare("SELECT l.*, COALESCE(m.full_name, NULLIF(u.full_name,''), u.username) AS by_name FROM procurement_request_log l
                              LEFT JOIN members m ON m.id = l.member_id LEFT JOIN users u ON u.id = l.user_id
                              WHERE l.request_id = ? ORDER BY l.created_at, l.id");
        $st->bind_param('i', $id); $st->execute(); $log = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
        $mine = $memberId > 0 && (int)$r['requested_by_member_id'] === $memberId;
        pr_json(true, '', ['item' => $r, 'items' => $items, 'log' => $log, 'can' => [
            'edit' => $r['status'] === 'Pending' && pr_owns($r),
            'cancel' => $r['status'] === 'Pending' && pr_owns($r),
            'decide' => $r['status'] === 'Pending' && $canManage && ($isAdmin || !$mine),
            'order' => $r['status'] === 'Approved' && $canManage,
            'deliver' => in_array($r['status'], ['Approved', 'Ordered'], true) && $canManage,
            'delete' => $isAdmin,
        ]]);
    }

    if ($action === 'save') {
        $id = (int)pr_post('id');
        $deptId = (int)pr_post('department_id');
        $title = pr_post('title');
        $justification = pr_post('justification');
        $priority = pr_post('priority', 'Normal');
        $neededBy = pr_date_or_null(pr_post('needed_by'), 'Enter a valid "needed by" date.');
        if ($title === '') pr_json(false, 'Enter what the request is for.');
        if (!in_array($priority, PR_PRIORITIES, true)) $priority = 'Normal';
        if ($deptId <= 0) pr_json(false, 'Choose the department.');
        if (!$isAdmin && !isset($ledDepts[$deptId])) pr_json(false, 'You can only raise requests for departments you lead.');
        if ($isAdmin && !isset($ledDepts[$deptId])) {
            $st = $conn->prepare('SELECT 1 FROM departments WHERE id = ?'); $st->bind_param('i', $deptId); $st->execute();
            $ok = (bool)$st->get_result()->fetch_row(); $st->close();
            if (!$ok) pr_json(false, 'Department not found.');
        }

        $names = (array)($_POST['item_name'] ?? []);
        $items = []; $total = 0.0;
        foreach ($names as $i => $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $qty = (float)($_POST['quantity'][$i] ?? 1);
            $cost = (float)($_POST['unit_cost'][$i] ?? 0);
            if ($qty <= 0) pr_json(false, "Quantity for \"$name\" must be more than zero.");
            if ($cost < 0) pr_json(false, "Unit cost for \"$name\" cannot be negative.");
            $items[] = [mb_substr($name, 0, 200), mb_substr(trim((string)($_POST['specification'][$i] ?? '')), 0, 255), $qty, mb_substr(trim((string)($_POST['unit'][$i] ?? '')), 0, 30), $cost];
            $total += $qty * $cost;
        }
        if (!$items) pr_json(false, 'Add at least one item.');

        $conn->begin_transaction();
        if ($id > 0) {
            $r = pr_load($conn, $id);
            if ($r['status'] !== 'Pending' || !pr_owns($r)) { $conn->rollback(); pr_json(false, 'Only pending requests from your department can be edited.'); }
            $st = $conn->prepare('UPDATE procurement_requests SET department_id=?, title=?, justification=?, priority=?, needed_by=?, estimated_total=? WHERE id=?');
            $st->bind_param('issssdi', $deptId, $title, $justification, $priority, $neededBy, $total, $id);
            $st->execute(); $st->close();
            $st = $conn->prepare('DELETE FROM procurement_request_items WHERE request_id = ?'); $st->bind_param('i', $id); $st->execute(); $st->close();
            $logAction = 'Edited';
        } else {
            $prefix = 'PRQ-' . date('Y') . '-'; $start = strlen($prefix) + 1;
            $st = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(request_no, ?) AS UNSIGNED)), 0) FROM procurement_requests WHERE request_no LIKE CONCAT(?, '%') FOR UPDATE");
            $st->bind_param('is', $start, $prefix); $st->execute();
            $no = $prefix . str_pad((string)(((int)$st->get_result()->fetch_row()[0]) + 1), 4, '0', STR_PAD_LEFT); $st->close();
            $st = $conn->prepare('INSERT INTO procurement_requests (request_no, department_id, requested_by_member_id, requested_by_user_id, title, justification, priority, needed_by, estimated_total) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->bind_param('siiissssd', $no, $deptId, $memberIdOrNull, $userId, $title, $justification, $priority, $neededBy, $total);
            $st->execute(); $id = (int)$st->insert_id; $st->close();
            $logAction = 'Submitted';
        }
        $st = $conn->prepare('INSERT INTO procurement_request_items (request_id, item_name, specification, quantity, unit, unit_cost) VALUES (?,?,?,?,?,?)');
        foreach ($items as [$n, $spec, $q, $u, $c]) { $st->bind_param('issdsd', $id, $n, $spec, $q, $u, $c); $st->execute(); }
        $st->close();
        pr_log($conn, $id, $logAction, '');
        $conn->commit();
        pr_json(true, $logAction === 'Submitted' ? 'Request sent to procurement.' : 'Request updated.', ['id' => $id]);
    }

    if ($action === 'decide') {
        if (!$canManage) pr_json(false, 'Only the procurement officer or assistant can approve or reject requests.');
        $r = pr_load($conn, (int)pr_post('id'));
        $decision = pr_post('decision');
        $comment = pr_post('comment');
        if (!in_array($decision, ['Approved', 'Rejected'], true)) pr_json(false, 'Choose approve or reject.');
        if ($r['status'] !== 'Pending') pr_json(false, 'This request has already been ' . strtolower($r['status']) . '.');
        $isOwnRequest = ($memberId > 0 && (int)$r['requested_by_member_id'] === $memberId)
            || ($userId > 0 && (int)$r['requested_by_user_id'] === $userId);
        if (!$isAdmin && $isOwnRequest) pr_json(false, 'You cannot approve or reject your own request. Another member of the procurement team must decide it.');
        if ($comment === '') pr_json(false, 'Add a comment explaining this decision.');
        $id = (int)$r['id'];
        $st = $conn->prepare("UPDATE procurement_requests SET status=?, decision_comment=?, decided_by_member_id=?, decided_by_user_id=?, decided_at=NOW() WHERE id=? AND status='Pending'");
        $st->bind_param('ssiii', $decision, $comment, $memberIdOrNull, $userId, $id);
        $st->execute(); $changed = $st->affected_rows; $st->close();
        if ($changed < 1) pr_json(false, 'This request was just decided by someone else. Refresh to see it.');
        pr_log($conn, $id, $decision, $comment);
        pr_json(true, 'Request ' . strtolower($decision) . '.');
    }

    if ($action === 'progress') {
        if (!$canManage) pr_json(false, 'Only the procurement team can update purchasing.');
        $r = pr_load($conn, (int)pr_post('id'));
        $to = pr_post('status');
        $id = (int)$r['id'];
        $date = pr_date_or_null(pr_post('date'), 'Enter a valid date.') ?? date('Y-m-d');
        $supplier = pr_post('supplier');
        $costRaw = pr_post('actual_cost');
        $cost = $costRaw === '' ? null : (float)$costRaw;
        if ($cost !== null && $cost < 0) pr_json(false, 'Cost cannot be negative.');
        $comment = pr_post('comment');
        if ($to === 'Ordered') {
            if ($r['status'] !== 'Approved') pr_json(false, 'Only approved requests can be marked as ordered.');
            $st = $conn->prepare("UPDATE procurement_requests SET status='Ordered', ordered_at=?, supplier=NULLIF(?,''), actual_cost=COALESCE(?, actual_cost) WHERE id=?");
        } elseif ($to === 'Delivered') {
            if (!in_array($r['status'], ['Approved', 'Ordered'], true)) pr_json(false, 'Only approved or ordered requests can be marked as delivered.');
            $st = $conn->prepare("UPDATE procurement_requests SET status='Delivered', delivered_at=?, supplier=COALESCE(NULLIF(?,''), supplier), actual_cost=COALESCE(?, actual_cost) WHERE id=?");
        } else {
            pr_json(false, 'Unknown step.');
        }
        $st->bind_param('ssdi', $date, $supplier, $cost, $id);
        $st->execute(); $st->close();
        $details = trim(($supplier !== '' ? "Supplier: $supplier. " : '') . ($cost !== null ? 'Cost: UGX ' . number_format($cost) . '. ' : '') . $comment);
        pr_log($conn, $id, $to, $details);
        pr_json(true, 'Request marked as ' . strtolower($to) . '.');
    }

    if ($action === 'cancel') {
        $r = pr_load($conn, (int)pr_post('id'));
        if ($r['status'] !== 'Pending' || !pr_owns($r)) pr_json(false, 'Only pending requests from your department can be cancelled.');
        $id = (int)$r['id'];
        $st = $conn->prepare("UPDATE procurement_requests SET status='Cancelled' WHERE id=? AND status='Pending'");
        $st->bind_param('i', $id); $st->execute(); $st->close();
        pr_log($conn, $id, 'Cancelled', pr_post('comment'));
        pr_json(true, 'Request cancelled.');
    }

    if ($action === 'comment') {
        $r = pr_load($conn, (int)pr_post('id'));
        $comment = pr_post('comment');
        if ($comment === '') pr_json(false, 'Write a comment first.');
        pr_log($conn, (int)$r['id'], 'Comment', $comment);
        pr_json(true, 'Comment added.');
    }

    if ($action === 'delete') {
        if (!$isAdmin) pr_json(false, 'Only an administrator can delete requests.');
        $r = pr_load($conn, (int)pr_post('id'));
        $id = (int)$r['id'];
        $st = $conn->prepare('DELETE FROM procurement_requests WHERE id = ?'); $st->bind_param('i', $id); $st->execute(); $st->close();
        pr_json(true, 'Request deleted.');
    }

    /* ---------------- Procurement team (admins only) ---------------- */
    if ($action === 'team_list') {
        if (!$isAdmin) pr_json(false, 'Only an administrator can manage the procurement team.');
        $res = $conn->query("SELECT t.*, m.full_name, m.member_no, m.phone, (m.user_id IS NOT NULL AND m.user_id > 0) AS has_login
                             FROM procurement_team t JOIN members m ON m.id = t.member_id
                             ORDER BY t.status, FIELD(t.role,'Officer','Assistant'), m.full_name");
        pr_json(true, '', ['team' => $res->fetch_all(MYSQLI_ASSOC)]);
    }

    if ($action === 'team_save') {
        if (!$isAdmin) pr_json(false, 'Only an administrator can manage the procurement team.');
        $mid = (int)pr_post('member_id');
        $role = pr_post('role');
        $status = pr_post('status', 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if ($mid <= 0) pr_json(false, 'Choose a member.');
        if (!in_array($role, PR_TEAM_ROLES, true)) pr_json(false, 'Choose Officer or Assistant.');
        $st = $conn->prepare('SELECT 1 FROM members WHERE id = ?'); $st->bind_param('i', $mid); $st->execute();
        $ok = (bool)$st->get_result()->fetch_row(); $st->close();
        if (!$ok) pr_json(false, 'Member not found.');
        $st = $conn->prepare('INSERT INTO procurement_team (member_id, role, status, assigned_by) VALUES (?,?,?,?)
                              ON DUPLICATE KEY UPDATE role=VALUES(role), status=VALUES(status), assigned_by=VALUES(assigned_by)');
        $st->bind_param('issi', $mid, $role, $status, $userId); $st->execute(); $st->close();
        pr_json(true, 'Procurement team updated.');
    }

    if ($action === 'team_remove') {
        if (!$isAdmin) pr_json(false, 'Only an administrator can manage the procurement team.');
        $id = (int)pr_post('id');
        $st = $conn->prepare('DELETE FROM procurement_team WHERE id = ?'); $st->bind_param('i', $id); $st->execute(); $st->close();
        pr_json(true, 'Removed from the procurement team.');
    }

    pr_json(false, 'Invalid request action.');
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('[procurement] ' . $e->getMessage());
    pr_json(false, 'Server error: ' . $e->getMessage());
}
