<?php
declare(strict_types=1);

/*
 * Who can use Procurement:
 *  - Admins and staff with the procurement.manage permission: everything, including assigning the team.
 *  - Members on the procurement team (Officer / Assistant): see every request, approve or reject with a comment,
 *    record ordering and delivery.
 *  - Department leaders (departments.leader, or a Leader / Assistant Leader in department_members): raise requests
 *    for their departments, edit or cancel them while pending, and follow their progress.
 */

const PR_TEAM_ROLES = ['Officer', 'Assistant'];
const PR_PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];
const PR_STATUSES = ['Pending', 'Approved', 'Rejected', 'Ordered', 'Delivered', 'Cancelled'];
const PR_LEAD_ROLES = ['Leader', 'Assistant Leader'];

function pr_table_exists(mysqli $conn, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $st = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$st) return false;
    $st->bind_param('s', $table); $st->execute();
    $n = (int)($st->get_result()->fetch_row()[0] ?? 0); $st->close();
    return $cache[$table] = $n > 0;
}

function pr_installed(mysqli $conn): bool {
    foreach (['procurement_team', 'procurement_requests', 'procurement_request_items', 'procurement_request_log'] as $t) {
        if (!pr_table_exists($conn, $t)) return false;
    }
    return true;
}

function pr_member_id(): int {
    return function_exists('current_member_id') ? current_member_id() : (int)($_SESSION['member_id'] ?? 0);
}

/** Admin or staff with procurement.manage. */
function pr_is_admin(): bool {
    if (strtolower((string)($_SESSION['role'] ?? '')) === 'admin') return true;
    return !is_member_user() && current_user_can('procurement.manage');
}

/** 'Officer', 'Assistant' or '' for the signed-in member. */
function pr_team_role(mysqli $conn): string {
    static $role = null;
    if ($role !== null) return $role;
    $mid = pr_member_id();
    if ($mid <= 0 || !pr_table_exists($conn, 'procurement_team')) return $role = '';
    $st = $conn->prepare("SELECT role FROM procurement_team WHERE member_id = ? AND status = 'Active' LIMIT 1");
    $st->bind_param('i', $mid); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return $role = (string)($row['role'] ?? '');
}

/** Departments the signed-in member leads, as [id => name]. */
function pr_led_departments(mysqli $conn): array {
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    $mid = pr_member_id();
    if ($mid <= 0 || !pr_table_exists($conn, 'departments')) return $out;
    $sql = "SELECT d.id, d.name FROM departments d WHERE COALESCE(d.status,'Active') = 'Active' AND (d.leader = ?";
    if (pr_table_exists($conn, 'department_members')) {
        $sql .= " OR EXISTS (SELECT 1 FROM department_members dm WHERE dm.dept_id = d.id AND dm.member_id = ? AND dm.role IN ('" . implode("','", PR_LEAD_ROLES) . "'))";
        $st = $conn->prepare($sql . ') ORDER BY d.name');
        $st->bind_param('ii', $mid, $mid);
    } else {
        $st = $conn->prepare($sql . ') ORDER BY d.name');
        $st->bind_param('i', $mid);
    }
    $st->execute(); $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $out[(int)$r['id']] = (string)$r['name'];
    $st->close();
    return $out;
}

/** Can approve / reject / order / deliver any request. */
function pr_can_manage(mysqli $conn): bool { return pr_is_admin() || pr_team_role($conn) !== ''; }

/** Can raise requests (for led departments; admins for any department). */
function pr_can_request(mysqli $conn): bool { return pr_is_admin() || pr_led_departments($conn) !== []; }

function pr_can_access(mysqli $conn): bool {
    if (!pr_installed($conn)) return pr_is_admin();
    return pr_can_manage($conn) || pr_can_request($conn);
}

function pr_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}
