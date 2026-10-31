<?php
declare(strict_types=1);

/**
 * Member notifications: per-member settings, the notification queue, and delivery by
 * push (Firebase Cloud Messaging), email and SMS. Tables: database/member_notifications.sql.
 * New items are found and delivered by cron/member_notifications.php.
 */

const MN_SERVICE_ACCOUNT = __DIR__ . '/../config/firebase-service-account.json';
const MN_CHANNELS = ['push', 'email', 'sms'];

/** Categories members can choose, with the default for each channel. */
function mn_categories(): array
{
    return [
        'announcements' => ['label' => 'Announcements', 'description' => 'New church announcements', 'push' => 1, 'email' => 1, 'sms' => 0],
        'events'        => ['label' => 'Events', 'description' => 'New church events', 'push' => 1, 'email' => 1, 'sms' => 0],
        'programs'      => ['label' => 'Upcoming programs', 'description' => 'New programs and services', 'push' => 1, 'email' => 1, 'sms' => 0],
        'tips'          => ['label' => 'Health and education tips', 'description' => 'New tips from the departments', 'push' => 1, 'email' => 1, 'sms' => 0],
        'pledges'       => ['label' => 'Pledges', 'description' => 'When a pledge is recorded for you or fully paid', 'push' => 1, 'email' => 1, 'sms' => 0],
        'payments'      => ['label' => 'Payments received', 'description' => 'Pledge payments, tithe, thanksgiving and contributions', 'push' => 1, 'email' => 1, 'sms' => 0],
        // The website already texted pledge reminders, so SMS stays on by default here.
        'reminders'     => ['label' => 'Reminders', 'description' => 'Pledge balance on Saturday morning (and a push on Sunday before service), and programs and events the day before', 'push' => 1, 'email' => 1, 'sms' => 1],
    ];
}

function mn_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function mn_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function mn_has_table(mysqli $conn, string $table): bool
{
    static $cache = [];
    return $cache[$table] ??= (bool)mn_rows($conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 's', [$table]);
}

function mn_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    return $cache["$table.$column"] ??= (bool)mn_rows($conn, 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', 'ss', [$table, $column]);
}

function mn_ready(mysqli $conn): bool
{
    foreach (['member_notification_prefs', 'member_push_devices', 'member_notifications', 'member_notification_sources', 'member_notification_state'] as $table) {
        if (!mn_has_table($conn, $table)) return false;
    }
    return true;
}

function mn_state(mysqli $conn, string $name): ?string
{
    $row = mn_rows($conn, 'SELECT value FROM member_notification_state WHERE name=?', 's', [$name])[0] ?? null;
    return $row ? (string)$row['value'] : null;
}

function mn_set_state(mysqli $conn, string $name, string $value): void
{
    mn_exec($conn, 'INSERT INTO member_notification_state (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', 'ss', [$name, $value]);
}

function mn_push_configured(): bool
{
    return is_file(MN_SERVICE_ACCOUNT);
}

function mn_sms_configured(mysqli $conn): bool
{
    return mn_has_table($conn, 'sms_settings') && (bool)mn_rows($conn, 'SELECT 1 FROM sms_settings WHERE is_active=1 LIMIT 1');
}

/** A member's settings for every category, defaults filled in: [category => [push, email, sms]]. */
function mn_prefs(mysqli $conn, int $memberId): array
{
    $prefs = [];
    foreach (mn_categories() as $key => $c) $prefs[$key] = ['push' => (bool)$c['push'], 'email' => (bool)$c['email'], 'sms' => (bool)$c['sms']];
    foreach (mn_rows($conn, 'SELECT category, push, email, sms FROM member_notification_prefs WHERE member_id=?', 'i', [$memberId]) as $row) {
        if (isset($prefs[$row['category']])) $prefs[$row['category']] = ['push' => (bool)$row['push'], 'email' => (bool)$row['email'], 'sms' => (bool)$row['sms']];
    }
    return $prefs;
}

function mn_save_prefs(mysqli $conn, int $memberId, array $settings): void
{
    $categories = mn_categories();
    foreach ($settings as $category => $channels) {
        if (!isset($categories[$category]) || !is_array($channels)) continue;
        [$push, $email, $sms] = array_map(fn($ch) => !empty($channels[$ch]) ? 1 : 0, MN_CHANNELS);
        mn_exec($conn, 'INSERT INTO member_notification_prefs (member_id, category, push, email, sms) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE push=VALUES(push), email=VALUES(email), sms=VALUES(sms)', 'isiii', [$memberId, $category, $push, $email, $sms]);
    }
}

/**
 * Members who can receive church-wide notifications: active members with an active login,
 * so they can open the app or portal and change their settings. Pass department names to
 * limit the list to members of those departments.
 */
function mn_recipients(mysqli $conn, ?array $departments = null): array
{
    $sql = "SELECT m.id FROM members m JOIN users u ON u.id=m.user_id
        WHERE LOWER(TRIM(COALESCE(u.status,'Active')))='active' AND LOWER(TRIM(COALESCE(m.status,'Active')))='active'";
    $types = '';
    if ($departments !== null) {
        if (!$departments || !mn_has_column($conn, 'members', 'department')) return [];
        $sql .= ' AND LOWER(TRIM(m.department)) IN (' . implode(',', array_fill(0, count($departments), '?')) . ')';
        $types = str_repeat('s', count($departments));
        $departments = array_map(fn($d) => mb_strtolower(trim((string)$d)), $departments);
    }
    return array_map(fn($r) => (int)$r['id'], mn_rows($conn, $sql, $types, $departments ?? []));
}

/**
 * Adds a notification for one member (shown in the app), using their settings to decide the channels.
 * $only limits the channels (e.g. ['push'] when email is already sent elsewhere).
 * Does nothing if the member already has a notification with this $refKey.
 */
function mn_enqueue(mysqli $conn, int $memberId, string $category, string $refKey, string $title, string $body, ?array $only = null): void
{
    $prefs = mn_prefs($conn, $memberId)[$category] ?? null;
    // A category with every channel off is not shown in the app either.
    if ($prefs === null || !in_array(true, $prefs, true)) return;
    $status = [];
    foreach (MN_CHANNELS as $ch) $status[$ch] = $prefs[$ch] && ($only === null || in_array($ch, $only, true)) ? 'pending' : 'off';
    $title = mb_substr($title, 0, 200);
    $now = date('Y-m-d H:i:s');
    mn_exec($conn, 'INSERT IGNORE INTO member_notifications (member_id, category, ref_key, title, body, push_status, email_status, sms_status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?)', 'issssssss', [$memberId, $category, $refKey, $title, $body, $status['push'], $status['email'], $status['sms'], $now]);
}

/** Sends a church-wide item once; true when it was new. */
function mn_broadcast(mysqli $conn, string $category, string $refKey, string $title, string $body, ?array $departments = null): bool
{
    if (mn_exec($conn, 'INSERT IGNORE INTO member_notification_sources (ref_key) VALUES (?)', 's', [$refKey]) === 0) return false;
    foreach (mn_recipients($conn, $departments) as $memberId) mn_enqueue($conn, $memberId, $category, $refKey, $title, $body);
    return true;
}

/** Marks a church-wide item as already sent, without sending it. */
function mn_mark_sent(mysqli $conn, string $refKey): void
{
    mn_exec($conn, 'INSERT IGNORE INTO member_notification_sources (ref_key) VALUES (?)', 's', [$refKey]);
}

/* ------------------------------------------------------------------ delivery */

/** Sends up to $limit queued notifications; returns counts per channel. */
function mn_deliver(mysqli $conn, int $limit = 200): array
{
    foreach (['/../config/mail-function.php', '/../config/sms-function.php'] as $helper) {
        if (is_file(__DIR__ . $helper)) require_once __DIR__ . $helper;
    }
    $counts = ['push' => 0, 'email' => 0, 'sms' => 0];
    $rows = mn_rows($conn, "SELECT * FROM member_notifications WHERE delivered_at IS NULL ORDER BY id LIMIT ?", 'i', [$limit]);
    $site = mn_site_name($conn);
    foreach ($rows as $n) {
        $memberId = (int)$n['member_id'];
        $contact = mn_contact($conn, $memberId);
        $status = ['push' => $n['push_status'], 'email' => $n['email_status'], 'sms' => $n['sms_status']];

        if ($status['push'] === 'pending') {
            $status['push'] = mn_send_push($conn, $memberId, (string)$n['title'], (string)$n['body'], ['category' => (string)$n['category'], 'notification_id' => (string)$n['id']]);
        }
        if ($status['email'] === 'pending') {
            if ($contact['email'] === '' || !function_exists('sendSystemEmail')) {
                $status['email'] = 'skipped';
            } else {
                $html = '<p>Hello ' . htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') . ',</p><h3>' . htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8') . '</h3>'
                    . '<p>' . nl2br(htmlspecialchars((string)$n['body'], ENT_QUOTES, 'UTF-8')) . '</p>'
                    . '<p style="color:#666;font-size:12px">You can change which messages you receive in the member app under Notification settings.</p>';
                $text = "Hello {$contact['name']},\n\n{$n['title']}\n\n{$n['body']}\n\nYou can change which messages you receive in the member app under Notification settings.";
                $status['email'] = sendSystemEmail($conn, $contact['email'], $contact['name'], "$site: {$n['title']}", $html, $text) ? 'sent' : 'failed';
            }
        }
        if ($status['sms'] === 'pending') {
            if ($contact['phone'] === '' || !function_exists('sendSms')) {
                $status['sms'] = 'skipped';
            } else {
                $status['sms'] = sendSms($conn, $contact['phone'], mb_substr("{$n['title']}: {$n['body']}", 0, 300)) ? 'sent' : 'failed';
            }
        }
        foreach ($counts as $ch => $c) if ($status[$ch] === 'sent') $counts[$ch]++;
        mn_exec($conn, 'UPDATE member_notifications SET push_status=?, email_status=?, sms_status=?, delivered_at=NOW() WHERE id=?',
            'sssi', [$status['push'], $status['email'], $status['sms'], (int)$n['id']]);
    }
    return $counts;
}

function mn_site_name(mysqli $conn): string
{
    if (mn_has_table($conn, 'site_settings')) {
        $row = mn_rows($conn, "SELECT setting_value FROM site_settings WHERE setting_key='site_name' LIMIT 1")[0] ?? null;
        if ($row && trim((string)$row['setting_value']) !== '') return trim((string)$row['setting_value']);
    }
    return defined('APP_NAME') ? APP_NAME : 'Pentecostal Church';
}

/** Name, phone and email for a member; the login's email when the member record has none. */
function mn_contact(mysqli $conn, int $memberId): array
{
    $cols = [];
    foreach (['full_name', 'phone', 'email'] as $col) $cols[] = mn_has_column($conn, 'members', $col) ? "m.`$col`" : "NULL AS `$col`";
    $userEmail = mn_has_column($conn, 'users', 'email') ? 'u.email' : 'NULL';
    $row = mn_rows($conn, 'SELECT ' . implode(', ', $cols) . ", $userEmail AS user_email FROM members m LEFT JOIN users u ON u.id=m.user_id WHERE m.id=? LIMIT 1", 'i', [$memberId])[0] ?? [];
    $email = trim((string)($row['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = trim((string)($row['user_email'] ?? ''));
    return [
        'name' => trim((string)($row['full_name'] ?? '')) ?: 'Member',
        'phone' => trim((string)($row['phone'] ?? '')),
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
    ];
}

/* ------------------------------------------------------------------ push (Firebase Cloud Messaging HTTP v1) */

/** Sends to every signed-in app on the member's phones; returns the channel status. */
function mn_send_push(mysqli $conn, int $memberId, string $title, string $body, array $data): string
{
    // Only phones that are still signed in to the app.
    $devices = mn_rows($conn, 'SELECT d.id, d.fcm_token FROM member_push_devices d
        JOIN member_api_tokens t ON t.id=d.api_token_id AND t.revoked_at IS NULL AND t.expires_at>NOW()
        WHERE d.member_id=?', 'i', [$memberId]);
    if (!$devices) return 'skipped';
    $auth = mn_fcm_auth($conn);
    if (!$auth) return 'failed';
    $sent = false;
    foreach ($devices as $device) {
        $result = mn_fcm_send($auth, (string)$device['fcm_token'], $title, $body, $data);
        if ($result === 'sent') $sent = true;
        if ($result === 'invalid') mn_exec($conn, 'DELETE FROM member_push_devices WHERE id=?', 'i', [(int)$device['id']]);
    }
    return $sent ? 'sent' : 'failed';
}

/** ['token' => access token, 'project' => Firebase project id], cached until shortly before it expires. */
function mn_fcm_auth(mysqli $conn): ?array
{
    static $auth = null;
    if ($auth && $auth['expires'] > time() + 60) return $auth;
    $cached = json_decode((string)mn_state($conn, 'fcm_access_token'), true);
    if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) return $auth = $cached;

    $account = is_file(MN_SERVICE_ACCOUNT) ? json_decode((string)file_get_contents(MN_SERVICE_ACCOUNT), true) : null;
    if (!is_array($account) || empty($account['private_key']) || empty($account['client_email']) || empty($account['project_id'])) {
        error_log('[Member notifications] Firebase service account file is missing or invalid.');
        return null;
    }
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    if (!openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
        error_log('[Member notifications] Could not sign the Firebase token request.');
        return null;
    }
    [$code, $response] = mn_http_post($tokenUri, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $unsigned . '.' . $b64($signature),
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $json = json_decode($response, true);
    if ($code !== 200 || empty($json['access_token'])) {
        error_log('[Member notifications] Firebase token request failed: HTTP ' . $code . ' ' . mb_substr($response, 0, 300));
        return null;
    }
    $auth = ['token' => $json['access_token'], 'project' => $account['project_id'], 'expires' => $now + (int)($json['expires_in'] ?? 3600)];
    mn_set_state($conn, 'fcm_access_token', json_encode($auth));
    return $auth;
}

/** Returns 'sent', 'invalid' (the phone's token no longer works) or 'failed'. */
function mn_fcm_send(array $auth, string $deviceToken, string $title, string $body, array $data): string
{
    $payload = ['message' => [
        'token' => $deviceToken,
        'notification' => ['title' => $title, 'body' => mb_substr($body, 0, 500)],
        'data' => array_map('strval', $data),
        'android' => ['priority' => 'high'],
    ]];
    [$code, $response] = mn_http_post(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($auth['project']) . '/messages:send',
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $auth['token']]
    );
    if ($code === 200) return 'sent';
    $error = json_decode($response, true)['error'] ?? [];
    $details = json_encode($error['details'] ?? []);
    if ($code === 404 || str_contains($details, 'UNREGISTERED') || ($code === 400 && str_contains((string)($error['message'] ?? ''), 'registration token'))) return 'invalid';
    error_log('[Member notifications] Push failed: HTTP ' . $code . ' ' . mb_substr($response, 0, 300));
    return 'failed';
}

function mn_http_post(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($response) ? $response : ''];
}
