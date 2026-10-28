<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function users_json(array $data, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function users_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function users_post(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function users_current_id(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
}

function users_current_role(): string
{
    return strtolower(trim((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));
}

function users_role_label(string $role): string
{
    $role = trim($role);

    return match (strtolower($role)) {
        'admin' => 'Administrator',
        'super_admin' => 'Super Administrator',
        'secretary' => 'Secretary',
        'treasurer' => 'Treasurer',
        'youth_leader' => 'Youth Leader',
        'member' => 'Member',
        default => $role !== '' ? ucwords(str_replace(['_', '-'], ' ', $role)) : 'Not Assigned',
    };
}

function users_status_label(string $status): string
{
    $value = strtolower(trim($status));

    if (in_array($value, ['active', '1', 'enabled'], true)) {
        return 'Active';
    }

    if (in_array($value, ['inactive', 'deactive', 'disabled', '0'], true)) {
        return 'Inactive';
    }

    return $status !== '' ? ucfirst($status) : 'Inactive';
}

function users_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $bind = [$types];
    foreach ($params as &$value) {
        $bind[] = &$value;
    }
    unset($value);

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function users_require_csrf(): void
{
    $sent = (string)($_POST['csrf_token'] ?? '');
    $saved = (string)($_SESSION['csrf_token'] ?? '');

    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        users_json([
            'success' => false,
            'message' => 'Your session token expired. Refresh the page and try again.',
        ], 419);
    }
}

function users_valid_date(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function users_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function users_log(mysqli $conn, string $action, int $targetUserId, string $description, array $details = []): void
{
    try {
        if (!users_table_exists($conn, 'activity_logs')) {
            return;
        }

        $actorId = users_current_id();
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $json = $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $conn->prepare(
            'INSERT INTO activity_logs
                (user_id, target_user_id, action, description, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        if (!$stmt) {
            return;
        }

        $stmt->bind_param('iisssss', $actorId, $targetUserId, $action, $description, $json, $ip, $agent);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable) {
        // Logging must never stop the main user-management action.
    }
}

function users_existing_roles(mysqli $conn): array
{
    $roles = [];
    $result = $conn->query(
        "SELECT DISTINCT role
         FROM users
         WHERE role IS NOT NULL AND TRIM(role) <> ''
         ORDER BY role ASC"
    );

    while ($result && $row = $result->fetch_assoc()) {
        $role = trim((string)($row['role'] ?? ''));
        if ($role !== '') {
            $roles[$role] = users_role_label($role);
        }
    }

    if ($result) {
        $result->free();
    }

    foreach (['admin', 'secretary', 'treasurer', 'youth_leader'] as $role) {
        $roles[$role] ??= users_role_label($role);
    }

    uksort($roles, static fn(string $a, string $b): int => strcasecmp(users_role_label($a), users_role_label($b)));
    return $roles;
}

function users_role_allowed(mysqli $conn, string $role): bool
{
    if ($role === '') {
        return false;
    }

    $roles = users_existing_roles($conn);
    if (!array_key_exists($role, $roles)) {
        return false;
    }

    if (strtolower($role) === 'super_admin' && users_current_role() !== 'super_admin') {
        return false;
    }

    return true;
}

/*
 * AJAX API is intentionally handled by this page so installations that remove
 * .php extensions through .htaccess do not break user-management requests.
 */
if (isset($_GET['users_api'])) {
    try {
        $action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));

        if ($action === 'list') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['per_page'] ?? 10);
            if (!in_array($perPage, [10, 25, 50, 100], true)) {
                $perPage = 10;
            }

            $search = trim((string)($_GET['search'] ?? ''));
            $role = trim((string)($_GET['role'] ?? ''));
            $status = trim((string)($_GET['status'] ?? ''));
            $dateFrom = trim((string)($_GET['date_from'] ?? ''));
            $dateTo = trim((string)($_GET['date_to'] ?? ''));

            if (!users_valid_date($dateFrom) || !users_valid_date($dateTo)) {
                users_json(['success' => false, 'message' => 'Invalid date filter.'], 422);
            }
            if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
                users_json(['success' => false, 'message' => 'From date cannot be after To date.'], 422);
            }

            $where = [];
            $types = '';
            $params = [];

            if ($search !== '') {
                $where[] = '(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)';
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like, $like);
                $types .= 'ssss';
            }

            if ($role !== '') {
                $where[] = 'role = ?';
                $params[] = $role;
                $types .= 's';
            }

            if ($status !== '') {
                if ($status === 'Active') {
                    $where[] = "LOWER(COALESCE(status, '')) IN ('active', '1', 'enabled')";
                } elseif ($status === 'Inactive') {
                    $where[] = "LOWER(COALESCE(status, '')) IN ('inactive', 'deactive', 'disabled', '0')";
                }
            }

            if ($dateFrom !== '') {
                $where[] = 'DATE(created_at) >= ?';
                $params[] = $dateFrom;
                $types .= 's';
            }

            if ($dateTo !== '') {
                $where[] = 'DATE(created_at) <= ?';
                $params[] = $dateTo;
                $types .= 's';
            }

            $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

            $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM users{$whereSql}");
            if (!$countStmt) {
                throw new RuntimeException('Could not prepare users count: ' . $conn->error);
            }

            $countParams = $params;
            users_bind($countStmt, $types, $countParams);
            $countStmt->execute();
            $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();

            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            $listSql = "SELECT id, username, full_name, role, email, phone, status, created_at
                        FROM users{$whereSql}
                        ORDER BY id DESC
                        LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($listSql);
            if (!$stmt) {
                throw new RuntimeException('Could not prepare users list: ' . $conn->error);
            }

            $listParams = array_merge($params, [$perPage, $offset]);
            $listTypes = $types . 'ii';
            users_bind($stmt, $listTypes, $listParams);
            $stmt->execute();
            $result = $stmt->get_result();

            $items = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['role_label'] = users_role_label((string)($row['role'] ?? ''));
                $row['status_label'] = users_status_label((string)($row['status'] ?? ''));
                $items[] = $row;
            }
            $stmt->close();

            $statsResult = $conn->query(
                "SELECT
                    COUNT(*) AS total_users,
                    SUM(DATE(created_at) = CURDATE()) AS added_today,
                    SUM(YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)) AS added_week,
                    SUM(YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())) AS added_month,
                    SUM(LOWER(COALESCE(status, '')) IN ('active', '1', 'enabled')) AS active_users,
                    SUM(LOWER(COALESCE(status, '')) IN ('inactive', 'deactive', 'disabled', '0')) AS inactive_users
                 FROM users"
            );

            $statsRow = $statsResult ? ($statsResult->fetch_assoc() ?: []) : [];
            if ($statsResult) {
                $statsResult->free();
            }

            $stats = [
                'total_users' => (int)($statsRow['total_users'] ?? 0),
                'added_today' => (int)($statsRow['added_today'] ?? 0),
                'added_week' => (int)($statsRow['added_week'] ?? 0),
                'added_month' => (int)($statsRow['added_month'] ?? 0),
                'active_users' => (int)($statsRow['active_users'] ?? 0),
                'inactive_users' => (int)($statsRow['inactive_users'] ?? 0),
            ];

            $roleStats = [];
            $roleStatsResult = $conn->query(
                "SELECT TRIM(role) AS role_name, COUNT(*) AS total
                 FROM users
                 WHERE role IS NOT NULL AND TRIM(role) <> ''
                 GROUP BY TRIM(role)
                 ORDER BY total DESC, role_name ASC"
            );

            while ($roleStatsResult && $row = $roleStatsResult->fetch_assoc()) {
                $roleName = trim((string)($row['role_name'] ?? ''));
                if ($roleName === '') {
                    continue;
                }
                $roleStats[] = [
                    'role' => $roleName,
                    'label' => users_role_label($roleName),
                    'total' => (int)($row['total'] ?? 0),
                ];
            }
            if ($roleStatsResult) {
                $roleStatsResult->free();
            }

            users_json([
                'success' => true,
                'items' => $items,
                'stats' => $stats,
                'role_stats' => $roleStats,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $perPage, $total),
                ],
            ]);
        }

        if ($action === 'get') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                users_json(['success' => false, 'message' => 'Invalid user ID.'], 422);
            }

            $stmt = $conn->prepare(
                'SELECT id, username, full_name, role, email, phone, status, created_at
                 FROM users
                 WHERE id = ?
                 LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                users_json(['success' => false, 'message' => 'User not found.'], 404);
            }

            $user['id'] = (int)$user['id'];
            $user['role_label'] = users_role_label((string)($user['role'] ?? ''));
            $user['status_label'] = users_status_label((string)($user['status'] ?? ''));
            users_json(['success' => true, 'item' => $user]);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            users_json(['success' => false, 'message' => 'Invalid request method.'], 405);
        }

        users_require_csrf();

        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $fullName = users_post('full_name');
            $username = users_post('username');
            $email = users_post('email');
            $phone = users_post('phone');
            $role = users_post('role');
            $status = users_post('status');
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($fullName === '') {
                users_json(['success' => false, 'message' => 'Full name is required.'], 422);
            }
            if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
                users_json([
                    'success' => false,
                    'message' => 'Username must be 3-60 characters and use letters, numbers, dots, underscores or hyphens.',
                ], 422);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                users_json(['success' => false, 'message' => 'Enter a valid email address.'], 422);
            }
            if (!users_role_allowed($conn, $role)) {
                users_json(['success' => false, 'message' => 'Select a valid role.'], 422);
            }
            if (!in_array($status, ['Active', 'Inactive'], true)) {
                users_json(['success' => false, 'message' => 'Select a valid status.'], 422);
            }
            if ($action === 'create' && $password === '') {
                users_json(['success' => false, 'message' => 'Password is required for a new user.'], 422);
            }
            if ($password !== '' && strlen($password) < 6) {
                users_json(['success' => false, 'message' => 'Password must be at least 6 characters.'], 422);
            }
            if ($password !== '' && $password !== $confirmPassword) {
                users_json(['success' => false, 'message' => 'Passwords do not match.'], 422);
            }
            if ($action === 'update' && $id <= 0) {
                users_json(['success' => false, 'message' => 'Invalid user ID.'], 422);
            }

            $duplicateSql = 'SELECT id FROM users WHERE username = ?';
            if ($action === 'update') {
                $duplicateSql .= ' AND id <> ?';
            }
            $duplicateSql .= ' LIMIT 1';

            $stmt = $conn->prepare($duplicateSql);
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            if ($action === 'update') {
                $stmt->bind_param('si', $username, $id);
            } else {
                $stmt->bind_param('s', $username);
            }
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplicate) {
                users_json(['success' => false, 'message' => 'That username is already in use.'], 409);
            }

            if ($email !== '') {
                $emailSql = 'SELECT id FROM users WHERE email = ?';
                if ($action === 'update') {
                    $emailSql .= ' AND id <> ?';
                }
                $emailSql .= ' LIMIT 1';

                $stmt = $conn->prepare($emailSql);
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                if ($action === 'update') {
                    $stmt->bind_param('si', $email, $id);
                } else {
                    $stmt->bind_param('s', $email);
                }
                $stmt->execute();
                $duplicateEmail = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($duplicateEmail) {
                    users_json(['success' => false, 'message' => 'That email address is already in use.'], 409);
                }
            }

            $emailValue = $email !== '' ? $email : null;
            $phoneValue = $phone !== '' ? $phone : null;

            if ($action === 'create') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('Could not secure the password.');
                }

                $stmt = $conn->prepare(
                    'INSERT INTO users (username, password, full_name, role, email, phone, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                $stmt->bind_param('sssssss', $username, $hash, $fullName, $role, $emailValue, $phoneValue, $status);
                $stmt->execute();
                $newId = (int)$stmt->insert_id;
                $stmt->close();

                users_log($conn, 'user_created', $newId, 'Created system user: ' . $fullName, [
                    'username' => $username,
                    'role' => $role,
                    'status' => $status,
                ]);

                users_json(['success' => true, 'message' => 'User added successfully.', 'id' => $newId]);
            }

            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('Could not secure the password.');
                }

                $stmt = $conn->prepare(
                    'UPDATE users
                     SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, status = ?, password = ?
                     WHERE id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                $stmt->bind_param('sssssssi', $fullName, $username, $emailValue, $phoneValue, $role, $status, $hash, $id);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE users
                     SET full_name = ?, username = ?, email = ?, phone = ?, role = ?, status = ?
                     WHERE id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException($conn->error);
                }
                $stmt->bind_param('ssssssi', $fullName, $username, $emailValue, $phoneValue, $role, $status, $id);
            }

            $stmt->execute();
            $stmt->close();

            users_log($conn, 'user_updated', $id, 'Updated system user: ' . $fullName, [
                'username' => $username,
                'role' => $role,
                'status' => $status,
            ]);

            users_json(['success' => true, 'message' => 'User updated successfully.']);
        }

        if ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($id <= 0) {
                users_json(['success' => false, 'message' => 'Invalid user ID.'], 422);
            }
            if (strlen($password) < 6) {
                users_json(['success' => false, 'message' => 'Password must be at least 6 characters.'], 422);
            }
            if ($password !== $confirmPassword) {
                users_json(['success' => false, 'message' => 'Passwords do not match.'], 422);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Could not secure the password.');
            }

            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
            $stmt->close();

            users_log($conn, 'user_password_reset', $id, 'Reset a system user password.');
            users_json(['success' => true, 'message' => 'Password updated successfully.']);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                users_json(['success' => false, 'message' => 'Invalid user ID.'], 422);
            }
            if ($id === users_current_id()) {
                users_json(['success' => false, 'message' => 'You cannot deactivate your own account.'], 422);
            }

            $stmt = $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            users_log($conn, 'user_deactivated', $id, 'Deactivated a system user.');
            users_json(['success' => true, 'message' => 'User deactivated successfully.']);
        }

        if ($action === 'bulk_update') {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['ids'] ?? [])),
                static fn(int $id): bool => $id > 0
            )));
            $operation = users_post('operation');
            $role = users_post('role');

            if ($ids === []) {
                users_json(['success' => false, 'message' => 'Select at least one user.'], 422);
            }
            if (count($ids) > 500) {
                users_json(['success' => false, 'message' => 'Select at most 500 users at a time.'], 422);
            }
            if (!in_array($operation, ['activate', 'deactivate', 'set_role'], true)) {
                users_json(['success' => false, 'message' => 'Choose what to do with the selected users.'], 422);
            }
            if ($operation === 'set_role' && !users_role_allowed($conn, $role)) {
                users_json(['success' => false, 'message' => 'Select a valid role.'], 422);
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT id, full_name, username, role FROM users WHERE id IN ({$placeholders})");
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $types = str_repeat('i', count($ids));
            users_bind($stmt, $types, $ids);
            $stmt->execute();
            $targets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $currentId = users_current_id();
            $isSuperAdmin = users_current_role() === 'super_admin';
            $changed = 0;
            $skippedSelf = false;
            $skippedSuper = 0;

            if ($operation === 'set_role') {
                $update = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
            } else {
                $update = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
                $newStatus = $operation === 'activate' ? 'Active' : 'Inactive';
            }
            if (!$update) {
                throw new RuntimeException($conn->error);
            }

            foreach ($targets as $target) {
                $targetId = (int)$target['id'];
                $name = (string)($target['full_name'] ?: $target['username']);

                // Same rule as single Deactivate: nobody locks themselves out or changes their own role.
                if ($targetId === $currentId && $operation !== 'activate') {
                    $skippedSelf = true;
                    continue;
                }
                if (!$isSuperAdmin && strtolower(trim((string)$target['role'])) === 'super_admin') {
                    $skippedSuper++;
                    continue;
                }

                if ($operation === 'set_role') {
                    $update->bind_param('si', $role, $targetId);
                    $update->execute();
                    users_log($conn, 'user_updated', $targetId, 'Changed role of system user: ' . $name, [
                        'role' => $role,
                        'previous_role' => (string)$target['role'],
                        'bulk' => true,
                    ]);
                } else {
                    $update->bind_param('si', $newStatus, $targetId);
                    $update->execute();
                    users_log(
                        $conn,
                        $operation === 'activate' ? 'user_activated' : 'user_deactivated',
                        $targetId,
                        ($operation === 'activate' ? 'Activated' : 'Deactivated') . ' system user: ' . $name,
                        ['bulk' => true]
                    );
                }
                $changed++;
            }
            $update->close();

            $done = match ($operation) {
                'activate' => 'activated',
                'deactivate' => 'deactivated',
                default => 'moved to ' . users_role_label($role),
            };
            $message = $changed . ($changed === 1 ? ' user ' : ' users ') . $done . '.';
            if ($skippedSelf) {
                $message .= ' Your own account was skipped.';
            }
            if ($skippedSuper > 0) {
                $message .= ' ' . $skippedSuper . ' Super Administrator account(s) were skipped.';
            }

            users_json(['success' => true, 'message' => $message, 'changed' => $changed]);
        }

        users_json(['success' => false, 'message' => 'Unknown action.'], 400);
    } catch (mysqli_sql_exception $exception) {
        users_json(['success' => false, 'message' => 'Database error: ' . $exception->getMessage()], 500);
    } catch (Throwable $exception) {
        users_json(['success' => false, 'message' => $exception->getMessage()], 500);
    }
}

$pageTitle = 'Manage Users';
$csrfToken = (string)$_SESSION['csrf_token'];
$roleOptions = users_existing_roles($conn);

// Do not expose the Super Administrator role to non-super-admin users.
if (users_current_role() !== 'super_admin') {
    foreach (array_keys($roleOptions) as $roleKey) {
        if (strtolower($roleKey) === 'super_admin') {
            unset($roleOptions[$roleKey]);
        }
    }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<style>
.users-page{--users-border:#e5e7eb;--users-muted:#64748b;--users-bg:#f8fafc}
.users-page .users-summary-grid{display:grid;grid-template-columns:repeat(6,minmax(150px,1fr));gap:14px;margin-bottom:18px}
.users-page .users-stat-card{background:#fff;border:1px solid var(--users-border);border-radius:14px;padding:16px;display:flex;align-items:center;gap:12px;min-height:96px;box-shadow:0 4px 18px rgba(15,23,42,.05)}
.users-page .users-stat-icon{width:44px;height:44px;min-width:44px;border-radius:12px;display:grid;place-items:center;background:#f1f5f9;font-size:18px}
.users-page .users-stat-card h3{margin:0;font-size:24px;line-height:1.1}.users-page .users-stat-card p{margin:4px 0 0;color:var(--users-muted);font-size:13px}
.users-page .role-stats-wrap{margin-bottom:18px}.users-page .role-stats-title{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.users-page .role-stats-title h4{margin:0;font-size:15px}
.users-page .role-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:10px}.users-page .role-stat-card{background:#fff;border:1px solid var(--users-border);border-radius:12px;padding:13px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.users-page .role-stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(15,23,42,.08)}.users-page .role-stat-card.active{border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.1)}.users-page .role-stat-label{font-size:13px;color:#475569;font-weight:600}.users-page .role-stat-number{font-size:20px;font-weight:700;color:#0f172a}

.users-page .table-card{overflow:hidden}.users-page .users-table th{white-space:nowrap;text-align:left}.users-page .users-table td{vertical-align:middle}.users-page .action-buttons{display:flex;gap:6px;flex-wrap:nowrap}.users-page .badge-role{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#ede9fe;color:#5b21b6;font-size:12px;font-weight:600;white-space:nowrap}.users-page .status-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}.users-page .status-active{background:#dcfce7;color:#166534}.users-page .status-inactive{background:#fee2e2;color:#991b1b}
.users-page .table-footer{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px 16px;border-top:1px solid var(--users-border);background:#fff}.users-page .pagination-info{color:var(--users-muted);font-size:13px}.users-page .pagination-controls{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.users-page .page-btn{min-width:36px;height:36px;padding:0 10px;border:1px solid #dbe3ec;background:#fff;border-radius:8px;cursor:pointer;display:inline-grid;place-items:center;font-size:13px}.users-page .page-btn:hover:not(:disabled){background:#f8fafc}.users-page .page-btn.active{background:#2563eb;border-color:#2563eb;color:#fff}.users-page .page-btn:disabled{opacity:.45;cursor:not-allowed}
.users-page .user-alert{display:none;padding:12px 14px;margin:0 0 15px;border-radius:9px}.users-page .user-alert.show{display:block}.users-page .user-alert.error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b}.users-page .user-alert.success{background:#dcfce7;border:1px solid #86efac;color:#166534}
.users-page .modal-layer{position:fixed;inset:0;background:rgba(15,23,42,.58);display:none;z-index:9998}.users-page .user-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;z-index:9999}.users-page .modal-layer.open,.users-page .user-modal.open{display:flex}.users-page .modal-card{width:min(760px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(0,0,0,.25)}.users-page .modal-head,.users-page .modal-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--users-border)}.users-page .modal-foot{border-top:1px solid var(--users-border);border-bottom:0;justify-content:flex-end}.users-page .modal-body{padding:20px}.users-page .modal-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.users-page .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.users-page .form-group{margin-bottom:0}.users-page .form-group.full{grid-column:1/-1}.users-page .password-wrap{position:relative}.users-page .password-wrap input{padding-right:44px}.users-page .password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;cursor:pointer;padding:6px}.users-page .empty-state{text-align:center;padding:34px 15px;color:#64748b}.users-page .empty-state i{font-size:30px;margin-bottom:10px;opacity:.45}.users-page .loading-cell{text-align:center;padding:28px!important;color:#64748b}
.users-page .select-cell{width:38px;min-width:38px;text-align:center!important}.users-page .select-cell input,.users-page .bulk-bar input[type=checkbox]{width:16px;height:16px;accent-color:var(--primary,#4a1e8f);cursor:pointer}.users-page tr.is-selected td{background:var(--primary-fade,#f5f3ff)}
.users-page .bulk-bar{display:none;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid var(--users-border);background:var(--users-bg);font-size:13px}.users-page .bulk-bar.show{display:flex}.users-page .bulk-count{font-weight:700;margin-right:auto}.users-page .bulk-bar select{width:auto;min-width:170px;height:32px;padding:4px 8px;font-size:13px}.users-page .bulk-role{display:flex;gap:6px;align-items:center}
@media(max-width:1350px){.users-page .users-summary-grid{grid-template-columns:repeat(3,minmax(150px,1fr))}}
@media(max-width:850px){.users-page .users-summary-grid{grid-template-columns:repeat(2,minmax(140px,1fr))}.users-page .table-footer{align-items:flex-start;flex-direction:column}.users-page .form-grid{grid-template-columns:1fr}}
@media(max-width:520px){.users-page .users-summary-grid{grid-template-columns:1fr 1fr;gap:9px}.users-page .users-stat-card{padding:12px;min-height:82px}.users-page .users-stat-icon{width:38px;height:38px;min-width:38px}.users-page .users-stat-card h3{font-size:20px}.users-page .role-stats-grid{grid-template-columns:1fr 1fr}}
</style>

<div class="main-content users-page">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="content-area">
        <div class="page-header module-header">
            <div>
                <h2><i class="fas fa-users-cog"></i> Manage Users</h2>
                <p class="text-muted">Manage system users, monitor account activity and filter records by period.</p>
            </div>
            <button type="button" class="btn btn-primary" id="addUserBtn">
                <i class="fas fa-user-plus"></i> Add User
            </button>
        </div>

        <div id="pageAlert" class="user-alert" role="alert"></div>

        <section class="users-summary-grid" aria-label="User statistics">
            <article class="users-stat-card">
                <div class="users-stat-icon"><i class="fas fa-users"></i></div>
                <div><h3 id="statTotal">0</h3><p>Total Users</p></div>
            </article>
            <article class="users-stat-card">
                <div class="users-stat-icon"><i class="fas fa-user-plus"></i></div>
                <div><h3 id="statToday">0</h3><p>Added Today</p></div>
            </article>
            <article class="users-stat-card">
                <div class="users-stat-icon"><i class="fas fa-calendar-week"></i></div>
                <div><h3 id="statWeek">0</h3><p>Added This Week</p></div>
            </article>
            <article class="users-stat-card">
                <div class="users-stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div><h3 id="statMonth">0</h3><p>Added This Month</p></div>
            </article>
            <article class="users-stat-card">
                <div class="users-stat-icon"><i class="fas fa-user-check"></i></div>
                <div><h3 id="statActive">0</h3><p>Active Users</p></div>
            </article>
            <article class="users-stat-card">
                <div class="users-stat-icon"><i class="fas fa-user-slash"></i></div>
                <div><h3 id="statInactive">0</h3><p>Deactive Users</p></div>
            </article>
        </section>

        <section class="role-stats-wrap">
            <div class="role-stats-title">
                <h4><i class="fas fa-user-tag"></i> Users per Role</h4>
                <small class="text-muted">Click a role card to filter the table</small>
            </div>
            <div class="role-stats-grid" id="roleStatsGrid">
                <div class="role-stat-card"><span class="role-stat-label">Loading roles...</span><span class="role-stat-number">—</span></div>
            </div>
        </section>

        <div class="page-toolbar">
                <div class="page-toolbar-search">
                    <i class="fas fa-search"></i>
                    <input type="search" id="searchUsers" class="form-control" placeholder="Name, username, email or phone" aria-label="Search users">
                </div>
                <div class="page-toolbar-filters">
                    <select id="filterRole" class="form-control" aria-label="Role">
                        <option value="">All roles</option>
                        <?php foreach ($roleOptions as $roleValue => $roleLabel): ?>
                            <option value="<?= users_h($roleValue) ?>"><?= users_h($roleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="filterStatus" class="form-control" aria-label="Status">
                        <option value="">All statuses</option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Deactive / Inactive</option>
                    </select>
                    <input type="date" id="dateFrom" class="form-control" title="From date" aria-label="From date">
                    <input type="date" id="dateTo" class="form-control" title="To date" aria-label="To date">
                    <select id="perPage" class="form-control" aria-label="Records per page">
                        <option value="10">10 / page</option>
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
                <div class="page-toolbar-actions">
                    <button type="button" class="btn btn-primary" id="applyFilters"><i class="fas fa-filter"></i> Filter</button>
                    <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="clearFilters"><i class="fas fa-rotate-right"></i> Refresh</button>
                </div>
        </div>

        <div class="card table-card">
            <div class="bulk-bar" id="bulkBar" role="region" aria-label="Selected users">
                <span class="bulk-count" id="bulkCount">0 selected</span>
                <button type="button" class="btn btn-sm btn-success" data-bulk="activate"><i class="fas fa-user-check"></i> Activate</button>
                <button type="button" class="btn btn-sm btn-danger" data-bulk="deactivate"><i class="fas fa-user-slash"></i> Deactivate</button>
                <div class="bulk-role">
                    <select id="bulkRole" class="form-control" aria-label="New role for selected users">
                        <option value="">Change role to...</option>
                        <?php foreach ($roleOptions as $roleValue => $roleLabel): ?>
                            <option value="<?= users_h($roleValue) ?>"><?= users_h($roleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-primary" data-bulk="set_role"><i class="fas fa-user-tag"></i> Apply</button>
                </div>
                <button type="button" class="btn btn-sm btn-light" id="bulkClear">Clear selection</button>
            </div>
            <div class="table-responsive">
                <table class="table users-table">
                    <thead>
                        <tr>
                            <th class="select-cell"><input type="checkbox" id="selectAll" aria-label="Select all users on this page"></th>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersBody">
                        <tr><td colspan="10" class="loading-cell"><i class="fas fa-spinner fa-spin"></i> Loading users...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <div class="pagination-info" id="paginationInfo">Showing 0 records</div>
                <div class="pagination-controls" id="paginationControls"></div>
            </div>
        </div>
    </div>

    <div class="modal-layer" id="modalLayer"></div>

    <div class="user-modal" id="userModal" aria-hidden="true">
        <div class="modal-card">
            <form id="userForm" autocomplete="off">
                <div class="modal-head">
                    <h4 id="userModalTitle">Add User</h4>
                    <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="formAlert" class="user-alert" role="alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= users_h($csrfToken) ?>">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="id" id="userId" value="">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input class="form-control" type="text" name="full_name" id="full_name" maxlength="120" required>
                        </div>
                        <div class="form-group">
                            <label for="username">Username *</label>
                            <input class="form-control" type="text" name="username" id="username" maxlength="60" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input class="form-control" type="email" name="email" id="email" maxlength="120">
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input class="form-control" type="tel" name="phone" id="phone" maxlength="30">
                        </div>
                        <div class="form-group">
                            <label for="role">Role *</label>
                            <select class="form-control" name="role" id="role" required>
                                <option value="">Select role</option>
                                <?php foreach ($roleOptions as $roleValue => $roleLabel): ?>
                                    <option value="<?= users_h($roleValue) ?>"><?= users_h($roleLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Status *</label>
                            <select class="form-control" name="status" id="status" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Deactive / Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="password">Password <span id="passwordRequired">*</span></label>
                            <div class="password-wrap">
                                <input class="form-control" type="password" name="password" id="password" minlength="6">
                                <button type="button" class="password-toggle" data-password="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password <span id="confirmRequired">*</span></label>
                            <div class="password-wrap">
                                <input class="form-control" type="password" name="confirm_password" id="confirm_password" minlength="6">
                                <button type="button" class="password-toggle" data-password="confirm_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="form-group full">
                            <small class="text-muted" id="passwordHint">Password is required for a new user.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-light" data-close>Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveUserBtn"><i class="fas fa-save"></i> Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="user-modal" id="passwordModal" aria-hidden="true">
        <div class="modal-card" style="max-width:520px">
            <form id="passwordForm" autocomplete="off">
                <div class="modal-head">
                    <h4>Reset Password</h4>
                    <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="passwordAlert" class="user-alert" role="alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= users_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" id="passwordUserId">
                    <p>Reset password for <strong id="passwordUserName"></strong>.</p>
                    <div class="form-group mb-3">
                        <label>New Password *</label>
                        <input class="form-control" type="password" name="password" minlength="6" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input class="form-control" type="password" name="confirm_password" minlength="6" required>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-light" data-close>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    'use strict';

    const API = new URL(window.location.href);
    API.search = '';
    API.hash = '';
    API.searchParams.set('users_api', '1');

    const $ = (selector, root = document) => root.querySelector(selector);
    const body = $('#usersBody');
    const modalLayer = $('#modalLayer');
    const paginationControls = $('#paginationControls');
    const paginationInfo = $('#paginationInfo');

    let users = [];
    let currentPage = 1;
    let totalPages = 1;
    let searchTimer = null;
    // Ticked users on the current page; cleared whenever the table reloads.
    const selected = new Set();

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
        }[char]));
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return escapeHtml(value);
        return date.toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: '2-digit'});
    }

    function alertBox(el, message, type = 'error') {
        el.textContent = message;
        el.className = `user-alert show ${type}`;
        if (el === $('#pageAlert')) {
            window.setTimeout(() => clearAlert(el), 6000);
        }
    }

    function clearAlert(el) {
        el.textContent = '';
        el.className = 'user-alert';
    }

    function openModal(id) {
        modalLayer.classList.add('open');
        const modal = $(id);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModals() {
        modalLayer.classList.remove('open');
        document.querySelectorAll('.user-modal.open').forEach(modal => {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        });
    }

    async function api(action, options = {}) {
        const url = new URL(API.toString());
        url.searchParams.set('action', action);

        Object.entries(options.params || {}).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                url.searchParams.set(key, String(value));
            }
        });

        if (options.id) {
            url.searchParams.set('id', String(options.id));
        }

        const response = await fetch(url.toString(), {
            method: options.method || 'GET',
            body: options.body || null,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });

        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch {
            throw new Error(`The users backend returned an invalid response (HTTP ${response.status}).`);
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || `Request failed (${response.status}).`);
        }

        return data;
    }

    function queryParams() {
        return {
            page: currentPage,
            per_page: $('#perPage').value,
            search: $('#searchUsers').value.trim(),
            role: $('#filterRole').value,
            status: $('#filterStatus').value,
            date_from: $('#dateFrom').value,
            date_to: $('#dateTo').value,
        };
    }

    function renderStats(stats = {}, roleStats = []) {
        $('#statTotal').textContent = Number(stats.total_users || 0).toLocaleString();
        $('#statToday').textContent = Number(stats.added_today || 0).toLocaleString();
        $('#statWeek').textContent = Number(stats.added_week || 0).toLocaleString();
        $('#statMonth').textContent = Number(stats.added_month || 0).toLocaleString();
        $('#statActive').textContent = Number(stats.active_users || 0).toLocaleString();
        $('#statInactive').textContent = Number(stats.inactive_users || 0).toLocaleString();

        const grid = $('#roleStatsGrid');
        if (!roleStats.length) {
            grid.innerHTML = '<div class="role-stat-card"><span class="role-stat-label">No role statistics</span><span class="role-stat-number">0</span></div>';
            return;
        }

        const selectedRole = $('#filterRole').value;
        grid.innerHTML = roleStats.map(item => `
            <button type="button" class="role-stat-card ${selectedRole === item.role ? 'active' : ''}" data-role-filter="${escapeHtml(item.role)}">
                <span class="role-stat-label"><i class="fas fa-user-tag"></i> ${escapeHtml(item.label)}</span>
                <span class="role-stat-number">${Number(item.total || 0).toLocaleString()}</span>
            </button>
        `).join('');
    }

    function updateSelection() {
        body.querySelectorAll('[data-select]').forEach(box => {
            box.checked = selected.has(Number(box.dataset.select));
            box.closest('tr').classList.toggle('is-selected', box.checked);
        });
        const count = selected.size;
        const all = $('#selectAll');
        all.checked = users.length > 0 && count === users.length;
        all.indeterminate = count > 0 && count < users.length;
        all.disabled = users.length === 0;
        $('#bulkCount').textContent = `${count} user${count === 1 ? '' : 's'} selected`;
        $('#bulkBar').classList.toggle('show', count > 0);
    }

    function renderUsers(items, pagination) {
        users = items || [];
        selected.clear();

        if (!users.length) {
            body.innerHTML = `
                <tr><td colspan="10">
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <div>No users found for the selected filters.</div>
                    </div>
                </td></tr>`;
        } else {
            const start = Number(pagination.from || 1);
            body.innerHTML = users.map((user, index) => {
                const status = user.status_label || user.status || 'Inactive';
                return `<tr>
                    <td class="select-cell"><input type="checkbox" data-select="${user.id}" aria-label="Select ${escapeHtml(user.full_name || user.username || 'user')}"></td>
                    <td>${start + index}</td>
                    <td><strong>${escapeHtml(user.full_name || '-')}</strong></td>
                    <td>${escapeHtml(user.username || '-')}</td>
                    <td>${escapeHtml(user.email || '-')}</td>
                    <td>${escapeHtml(user.phone || '-')}</td>
                    <td><span class="badge-role">${escapeHtml(user.role_label || user.role || 'Not Assigned')}</span></td>
                    <td><span class="status-badge ${status === 'Active' ? 'status-active' : 'status-inactive'}"><i class="fas fa-circle" style="font-size:7px"></i>${escapeHtml(status)}</span></td>
                    <td>${formatDate(user.created_at)}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-sm btn-primary" type="button" data-edit="${user.id}" title="Edit user"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-warning" type="button" data-reset="${user.id}" title="Reset password"><i class="fas fa-key"></i></button>
                            ${status === 'Active' ? `<button class="btn btn-sm btn-danger" type="button" data-delete="${user.id}" title="Deactivate user"><i class="fas fa-user-slash"></i></button>` : ''}
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        const total = Number(pagination.total || 0);
        const from = Number(pagination.from || 0);
        const to = Number(pagination.to || 0);
        paginationInfo.textContent = total > 0
            ? `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()} users`
            : 'Showing 0 users';

        renderPagination(pagination);
        updateSelection();
    }

    function renderPagination(pagination) {
        currentPage = Number(pagination.page || 1);
        totalPages = Number(pagination.total_pages || 1);

        const buttons = [];
        buttons.push(`<button class="page-btn" type="button" data-page="${currentPage - 1}" ${currentPage <= 1 ? 'disabled' : ''} title="Previous"><i class="fas fa-chevron-left"></i></button>`);

        let start = Math.max(1, currentPage - 2);
        let end = Math.min(totalPages, currentPage + 2);
        if (currentPage <= 3) end = Math.min(totalPages, 5);
        if (currentPage >= totalPages - 2) start = Math.max(1, totalPages - 4);

        if (start > 1) {
            buttons.push('<button class="page-btn" type="button" data-page="1">1</button>');
            if (start > 2) buttons.push('<span>…</span>');
        }

        for (let page = start; page <= end; page += 1) {
            buttons.push(`<button class="page-btn ${page === currentPage ? 'active' : ''}" type="button" data-page="${page}">${page}</button>`);
        }

        if (end < totalPages) {
            if (end < totalPages - 1) buttons.push('<span>…</span>');
            buttons.push(`<button class="page-btn" type="button" data-page="${totalPages}">${totalPages}</button>`);
        }

        buttons.push(`<button class="page-btn" type="button" data-page="${currentPage + 1}" ${currentPage >= totalPages ? 'disabled' : ''} title="Next"><i class="fas fa-chevron-right"></i></button>`);
        paginationControls.innerHTML = buttons.join('');
    }

    async function loadUsers({resetPage = false} = {}) {
        if (resetPage) currentPage = 1;

        body.innerHTML = '<tr><td colspan="10" class="loading-cell"><i class="fas fa-spinner fa-spin"></i> Loading users...</td></tr>';

        const data = await api('list', {params: queryParams()});
        renderStats(data.stats || {}, data.role_stats || []);
        renderUsers(data.items || [], data.pagination || {});
    }

    function prepareCreate() {
        $('#userForm').reset();
        $('#formAction').value = 'create';
        $('#userId').value = '';
        $('#userModalTitle').textContent = 'Add User';
        $('#password').required = true;
        $('#confirm_password').required = true;
        $('#passwordRequired').style.display = '';
        $('#confirmRequired').style.display = '';
        $('#passwordHint').textContent = 'Password is required for a new user.';
        $('#status').value = 'Active';
        clearAlert($('#formAlert'));
        openModal('#userModal');
    }

    async function prepareEdit(id) {
        clearAlert($('#formAlert'));
        const data = await api('get', {id});
        const user = data.item;

        $('#userForm').reset();
        $('#formAction').value = 'update';
        $('#userId').value = user.id;
        $('#full_name').value = user.full_name || '';
        $('#username').value = user.username || '';
        $('#email').value = user.email || '';
        $('#phone').value = user.phone || '';
        $('#role').value = user.role || '';
        $('#status').value = user.status_label || 'Inactive';
        $('#userModalTitle').textContent = 'Edit User';
        $('#password').required = false;
        $('#confirm_password').required = false;
        $('#passwordRequired').style.display = 'none';
        $('#confirmRequired').style.display = 'none';
        $('#passwordHint').textContent = 'Leave password blank to keep the current password.';
        openModal('#userModal');
    }

    $('#addUserBtn').addEventListener('click', prepareCreate);
    $('#applyFilters').addEventListener('click', () => loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message)));
    $('#perPage').addEventListener('change', () => loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message)));
    $('#filterRole').addEventListener('change', () => loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message)));
    $('#filterStatus').addEventListener('change', () => loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message)));

    $('#searchUsers').addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message));
        }, 350);
    });

    $('#dateFrom').addEventListener('change', () => {
        if ($('#dateTo').value && $('#dateFrom').value > $('#dateTo').value) {
            $('#dateTo').value = $('#dateFrom').value;
        }
    });

    $('#clearFilters').addEventListener('click', () => {
        $('#searchUsers').value = '';
        $('#filterRole').value = '';
        $('#filterStatus').value = '';
        $('#dateFrom').value = '';
        $('#dateTo').value = '';
        $('#perPage').value = '10';
        loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message));
    });

    $('#roleStatsGrid').addEventListener('click', event => {
        const card = event.target.closest('[data-role-filter]');
        if (!card) return;
        const role = card.dataset.roleFilter || '';
        $('#filterRole').value = $('#filterRole').value === role ? '' : role;
        loadUsers({resetPage: true}).catch(error => alertBox($('#pageAlert'), error.message));
    });

    paginationControls.addEventListener('click', event => {
        const button = event.target.closest('[data-page]');
        if (!button || button.disabled) return;
        const page = Number(button.dataset.page || 1);
        if (page < 1 || page > totalPages || page === currentPage) return;
        currentPage = page;
        loadUsers().catch(error => alertBox($('#pageAlert'), error.message));
    });

    modalLayer.addEventListener('click', closeModals);
    document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', closeModals));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeModals();
    });

    document.querySelectorAll('[data-password]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.password);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        const icon = button.querySelector('i');
        if (icon) icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    }));

    body.addEventListener('click', async event => {
        const edit = event.target.closest('[data-edit]');
        const reset = event.target.closest('[data-reset]');
        const deactivate = event.target.closest('[data-delete]');

        try {
            if (edit) {
                await prepareEdit(Number(edit.dataset.edit));
                return;
            }

            if (reset) {
                const id = Number(reset.dataset.reset);
                const user = users.find(item => item.id === id);
                if (!user) return;
                $('#passwordForm').reset();
                $('#passwordUserId').value = String(user.id);
                $('#passwordUserName').textContent = user.full_name || user.username || 'this user';
                clearAlert($('#passwordAlert'));
                openModal('#passwordModal');
                return;
            }

            if (deactivate) {
                const id = Number(deactivate.dataset.delete);
                const user = users.find(item => item.id === id);
                if (!user) return;
                if (!window.confirm(`Deactivate ${user.full_name || user.username}?`)) return;

                const formData = new FormData();
                formData.set('csrf_token', <?= json_encode($csrfToken) ?>);
                formData.set('id', String(id));

                const data = await api('delete', {method: 'POST', body: formData});
                alertBox($('#pageAlert'), data.message, 'success');
                await loadUsers();
            }
        } catch (error) {
            alertBox($('#pageAlert'), error.message);
        }
    });

    body.addEventListener('change', event => {
        const box = event.target.closest('[data-select]');
        if (!box) return;
        const id = Number(box.dataset.select);
        if (box.checked) selected.add(id); else selected.delete(id);
        updateSelection();
    });

    $('#selectAll').addEventListener('change', event => {
        selected.clear();
        if (event.target.checked) users.forEach(user => selected.add(Number(user.id)));
        updateSelection();
    });

    $('#bulkClear').addEventListener('click', () => {
        selected.clear();
        updateSelection();
    });

    $('#bulkBar').addEventListener('click', async event => {
        const button = event.target.closest('[data-bulk]');
        if (!button || selected.size === 0) return;

        const operation = button.dataset.bulk;
        const role = $('#bulkRole').value;
        const count = selected.size;
        const who = `${count} selected user${count === 1 ? '' : 's'}`;

        if (operation === 'set_role' && !role) {
            alertBox($('#pageAlert'), 'Choose the new role first.');
            $('#bulkRole').focus();
            return;
        }
        const question = {
            activate: `Activate ${who}?`,
            deactivate: `Deactivate ${who}? They will not be able to sign in.`,
            set_role: `Change the role of ${who} to ${$('#bulkRole').selectedOptions[0].textContent.trim()}?`,
        }[operation];
        if (!window.confirm(question)) return;

        const formData = new FormData();
        formData.set('csrf_token', <?= json_encode($csrfToken) ?>);
        formData.set('operation', operation);
        if (operation === 'set_role') formData.set('role', role);
        selected.forEach(id => formData.append('ids[]', String(id)));

        const buttons = $('#bulkBar').querySelectorAll('button, select');
        buttons.forEach(control => { control.disabled = true; });
        try {
            const data = await api('bulk_update', {method: 'POST', body: formData});
            $('#bulkRole').value = '';
            alertBox($('#pageAlert'), data.message, 'success');
            await loadUsers();
        } catch (error) {
            alertBox($('#pageAlert'), error.message);
        } finally {
            buttons.forEach(control => { control.disabled = false; });
        }
    });

    $('#userForm').addEventListener('submit', async event => {
        event.preventDefault();
        clearAlert($('#formAlert'));

        const form = event.currentTarget;
        const formData = new FormData(form);
        const button = $('#saveUserBtn');
        button.disabled = true;

        try {
            const data = await api(String(formData.get('action') || 'create'), {method: 'POST', body: formData});
            closeModals();
            alertBox($('#pageAlert'), data.message, 'success');
            await loadUsers({resetPage: formData.get('action') === 'create'});
        } catch (error) {
            alertBox($('#formAlert'), error.message);
        } finally {
            button.disabled = false;
        }
    });

    $('#passwordForm').addEventListener('submit', async event => {
        event.preventDefault();
        clearAlert($('#passwordAlert'));

        const formData = new FormData(event.currentTarget);
        try {
            const data = await api('reset_password', {method: 'POST', body: formData});
            closeModals();
            alertBox($('#pageAlert'), data.message, 'success');
        } catch (error) {
            alertBox($('#passwordAlert'), error.message);
        }
    });

    loadUsers().catch(error => alertBox($('#pageAlert'), error.message));
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
