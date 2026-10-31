<?php
declare(strict_types=1);

/**
 * Helpers for the member mobile app API: JSON responses, bearer-token authentication,
 * sign-in throttling and small query helpers. Tables: database/member_mobile_api.sql.
 */

const MAPI_TOKEN_DAYS = 90;
const MAPI_LOCK_MINUTES = 15;
const MAPI_MAX_FAILS_PER_IDENTIFIER = 5;
const MAPI_MAX_FAILS_PER_IP = 20;

function mapi_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function mapi_ok(array $data = []): never
{
    mapi_json(200, ['success' => true] + $data);
}

function mapi_fail(int $status, string $message): never
{
    mapi_json($status, ['success' => false, 'message' => $message]);
}

/** Request body as an array: JSON or form-encoded. */
function mapi_input(): array
{
    static $input = null;
    if ($input !== null) return $input;
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    return $input = is_array($json) ? $json : $_POST;
}

function mapi_str(array $input, string $key, int $max = 255): string
{
    $value = trim((string)($input[$key] ?? ''));
    return mb_substr($value, 0, $max);
}

function mapi_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) mapi_fail(405, 'Method not allowed.');
}

function mapi_table(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$table] = (int)$stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
    }
    return $cache[$table];
}

function mapi_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = "$table.$column";
    if (!array_key_exists($key, $cache)) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cache[$key] = (int)$stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
    }
    return $cache[$key];
}

/** Rows from a prepared query; $params are bound with $types. */
function mapi_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function mapi_row(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    return mapi_rows($conn, $sql, $types, $params)[0] ?? null;
}

function mapi_setting(mysqli $conn, string $key, string $default = ''): string
{
    $table = mapi_table($conn, 'site_settings') ? 'site_settings' : (mapi_table($conn, 'settings') ? 'settings' : '');
    if ($table === '') return $default;
    $row = mapi_row($conn, "SELECT setting_value FROM `$table` WHERE setting_key=? LIMIT 1", 's', [$key]);
    return trim((string)($row['setting_value'] ?? '')) ?: $default;
}

function mapi_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ------------------------------------------------------------------ sign-in throttling */

function mapi_is_locked(mysqli $conn, string $identifier): bool
{
    $since = date('Y-m-d H:i:s', time() - MAPI_LOCK_MINUTES * 60);
    $byIdentifier = mapi_row($conn, 'SELECT COUNT(*) c FROM member_api_login_attempts WHERE identifier=? AND attempted_at>=?', 'ss', [$identifier, $since]);
    $byIp = mapi_row($conn, 'SELECT COUNT(*) c FROM member_api_login_attempts WHERE ip_address=? AND attempted_at>=?', 'ss', [mapi_client_ip(), $since]);
    return (int)$byIdentifier['c'] >= MAPI_MAX_FAILS_PER_IDENTIFIER || (int)$byIp['c'] >= MAPI_MAX_FAILS_PER_IP;
}

function mapi_record_failure(mysqli $conn, string $identifier): void
{
    $ip = mapi_client_ip();
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare('INSERT INTO member_api_login_attempts (identifier, ip_address, attempted_at) VALUES (?,?,?)');
    $stmt->bind_param('sss', $identifier, $ip, $now);
    $stmt->execute();
    $stmt->close();
    // Keep the table small: attempts older than a day no longer matter.
    $conn->query("DELETE FROM member_api_login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
}

function mapi_clear_failures(mysqli $conn, string $identifier): void
{
    $stmt = $conn->prepare('DELETE FROM member_api_login_attempts WHERE identifier=?');
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $stmt->close();
}

/* ------------------------------------------------------------------ members and tokens */

/**
 * Active member account whose membership number or pledge number matches $identifier.
 * Returns null when nothing matches, or when the value matches two different members.
 */
function mapi_find_member_account(mysqli $conn, string $identifier): ?array
{
    $pledgeClause = mapi_column($conn, 'members', 'pledge_no') ? ' OR m.pledge_no=?' : '';
    $params = $pledgeClause ? [$identifier, $identifier] : [$identifier];
    $rows = mapi_rows(
        $conn,
        "SELECT u.id AS user_id, u.password, m.id AS member_id
         FROM members m JOIN users u ON u.id=m.user_id
         WHERE (m.member_no=?$pledgeClause)
           AND LOWER(TRIM(COALESCE(u.status,'Active')))='active'
           AND LOWER(TRIM(COALESCE(m.status,'Active')))='active'
         LIMIT 2",
        str_repeat('s', count($params)),
        $params
    );
    return count($rows) === 1 ? $rows[0] : null;
}

/** Issues a new token and returns it in plain text; only its hash is stored. */
function mapi_issue_token(mysqli $conn, int $userId, int $memberId, string $deviceName): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + MAPI_TOKEN_DAYS * 86400);
    $device = $deviceName !== '' ? $deviceName : null;
    $stmt = $conn->prepare('INSERT INTO member_api_tokens (user_id, member_id, token_hash, device_name, expires_at) VALUES (?,?,?,?,?)');
    $stmt->bind_param('iisss', $userId, $memberId, $hash, $device, $expires);
    $stmt->execute();
    $stmt->close();
    return $token;
}

function mapi_bearer_token(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $m)) return strtolower($m[1]);
    // Fallback for hosts that strip the Authorization header.
    $alt = trim((string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
    return preg_match('/^[a-f0-9]{64}$/i', $alt) ? strtolower($alt) : '';
}

/**
 * The signed-in member for this request, or a 401 response. The member and their
 * login must still be active, so deactivating either ends app access immediately.
 */
function mapi_auth(mysqli $conn): array
{
    $token = mapi_bearer_token();
    if ($token === '') mapi_fail(401, 'Please sign in.');
    $hash = hash('sha256', $token);
    $session = mapi_row(
        $conn,
        "SELECT t.id AS token_id, t.user_id, t.member_id
         FROM member_api_tokens t
         JOIN users u ON u.id=t.user_id
         JOIN members m ON m.id=t.member_id AND m.user_id=t.user_id
         WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW()
           AND LOWER(TRIM(COALESCE(u.status,'Active')))='active'
           AND LOWER(TRIM(COALESCE(m.status,'Active')))='active'
         LIMIT 1",
        's',
        [$hash]
    );
    if (!$session) mapi_fail(401, 'Your session has expired. Please sign in again.');
    $id = (int)$session['token_id'];
    $stmt = $conn->prepare('UPDATE member_api_tokens SET last_used_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    return ['token_id' => $id, 'user_id' => (int)$session['user_id'], 'member_id' => (int)$session['member_id']];
}

/** Member profile returned by the API. */
function mapi_member_profile(mysqli $conn, int $memberId): array
{
    // first_name/last_name are legacy columns that may already be dropped (database/members_full_name.sql).
    $legacy = mapi_column($conn, 'members', 'first_name') && mapi_column($conn, 'members', 'last_name')
        ? "TRIM(CONCAT_WS(' ', m.first_name, m.last_name))"
        : "''";
    $name = mapi_column($conn, 'members', 'full_name')
        ? "COALESCE(NULLIF(TRIM(m.full_name),''), $legacy)"
        : $legacy;
    $optional = [];
    foreach (['pledge_no', 'service_attended', 'gender', 'phone', 'email', 'address', 'joined_date', 'department', 'photo'] as $col) {
        $optional[] = mapi_column($conn, 'members', $col) ? "m.`$col`" : "NULL AS `$col`";
    }
    $row = mapi_row($conn, "SELECT m.id, m.member_no, $name AS full_name, " . implode(', ', $optional) . " FROM members m WHERE m.id=? LIMIT 1", 'i', [$memberId]) ?? [];
    $row['id'] = (int)($row['id'] ?? 0);

    $photo = trim((string)($row['photo'] ?? ''));
    unset($row['photo']);
    $row['photo_url'] = $photo !== '' && defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' . ltrim($photo, '/') : null;
    return $row;
}

/** Same password rules as the web member portal (member/profile.php). */
function mapi_password_errors(string $current, string $new, string $confirm, string $currentHash): array
{
    $errors = [];
    if ($currentHash === '' || !password_verify($current, $currentHash)) $errors[] = 'Your current password is incorrect.';
    if (strlen($new) < 8) $errors[] = 'The new password must contain at least 8 characters.';
    if (!preg_match('/[A-Z]/', $new)) $errors[] = 'The new password must contain an uppercase letter.';
    if (!preg_match('/[a-z]/', $new)) $errors[] = 'The new password must contain a lowercase letter.';
    if (!preg_match('/\d/', $new)) $errors[] = 'The new password must contain a number.';
    if ($new !== $confirm) $errors[] = 'The new password confirmation does not match.';
    if ($currentHash !== '' && password_verify($new, $currentHash)) $errors[] = 'The new password must be different from your current password.';
    return $errors;
}
