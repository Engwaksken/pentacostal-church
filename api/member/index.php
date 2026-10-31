<?php
declare(strict_types=1);

/**
 * Member mobile app API. Call as api/member/?action=<name>.
 * Sign in with a membership number or pledge number and the member's portal password,
 * then send "Authorization: Bearer <token>" on every other request.
 * Members can only read their own records and published church content.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/member-content-functions.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../../includes/pledge-types.php';
require_once __DIR__ . '/../../includes/member-notify.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$action = (string)($_GET['action'] ?? '');

try {
    if (!mapi_table($conn, 'member_api_tokens') || !mapi_table($conn, 'member_api_login_attempts')) {
        mapi_fail(503, 'The member app is not set up yet. Please contact the church office.');
    }

    switch ($action) {
        case 'branding':        mapi_action_branding($conn); break;
        case 'login':           mapi_action_login($conn); break;
        case 'logout':          mapi_action_logout($conn); break;
        case 'me':              mapi_action_me($conn); break;
        case 'change_password': mapi_action_change_password($conn); break;
        case 'forgot_password': mapi_action_forgot_password($conn); break;
        case 'reset_password':  mapi_action_reset_password($conn); break;
        case 'notification_settings':        mapi_action_notification_settings($conn); break;
        case 'update_notification_settings': mapi_action_update_notification_settings($conn); break;
        case 'register_device': mapi_action_register_device($conn); break;
        case 'notifications':   mapi_action_notifications($conn); break;
        case 'mark_notifications_read': mapi_action_mark_notifications_read($conn); break;
        case 'update_profile':  mapi_action_update_profile($conn); break;
        case 'update_photo':    mapi_action_update_photo($conn); break;
        case 'dashboard':       mapi_action_dashboard($conn); break;
        case 'giving':          mapi_action_giving($conn); break;
        case 'pledges':         mapi_action_pledges($conn); break;
        case 'announcements':   mapi_action_announcements($conn); break;
        case 'programs':        mapi_action_programs($conn); break;
        case 'order_of_service': mapi_action_order_of_service($conn); break;
        case 'hymns':           mapi_action_hymns($conn); break;
        case 'hymn':            mapi_action_hymn($conn); break;
        case 'tips':            mapi_action_tips($conn); break;
        default:                mapi_fail(404, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[Member API] ' . $action . ': ' . $e->getMessage());
    mapi_fail(500, 'Something went wrong. Please try again.');
}

/** Public branding used before sign-in. */
function mapi_action_branding(mysqli $conn): never
{
    $logo = mapi_setting($conn, 'site_logo', mapi_setting($conn, 'logo', ''));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo) && defined('BASE_URL')) {
        $logo = rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    }
    $colors = [];
    foreach ([
        'primary' => ['primary_color', '#4a1e8f'],
        'secondary' => ['secondary_color', '#d4a017'],
        'background' => ['background_color', '#f3f2f8'],
        'text' => ['text_color', '#1e1e2d'],
    ] as $key => [$setting, $default]) {
        $value = mapi_setting($conn, $setting, $default);
        $colors[$key] = preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $default;
    }
    mapi_ok(['church' => [
        'name' => mapi_setting($conn, 'site_name', APP_NAME),
        'subtitle' => mapi_setting($conn, 'site_subtitle', ''),
        'logo_url' => $logo,
        'colors' => $colors,
    ]]);
}

/* ------------------------------------------------------------------ account */

function mapi_action_login(mysqli $conn): never
{
    mapi_require_method('POST');
    $input = mapi_input();
    $identifier = mapi_str($input, 'identifier', 80);
    $password = (string)($input['password'] ?? '');
    $device = mapi_str($input, 'device_name', 120);
    if ($identifier === '' || $password === '') mapi_fail(422, 'Enter your membership or pledge number and your password.');

    $key = mb_strtolower($identifier);
    if (mapi_is_locked($conn, $key)) mapi_fail(429, 'Too many failed attempts. Please wait ' . MAPI_LOCK_MINUTES . ' minutes and try again.');

    $account = mapi_find_member_account($conn, $identifier);
    // Verify against a dummy hash when no account matches so response time does not reveal valid numbers.
    $hash = $account ? (string)$account['password'] : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$account) {
        mapi_record_failure($conn, $key);
        mapi_fail(401, 'The membership/pledge number or password is incorrect.');
    }
    mapi_clear_failures($conn, $key);

    // The old guessable member password (Change@ + member number digits) no longer signs anyone in.
    if (str_starts_with($password, 'Change@')) {
        $memberNo = (string)(mapi_row($conn, 'SELECT member_no FROM members WHERE id=? LIMIT 1', 'i', [(int)$account['member_id']])['member_no'] ?? '');
        $base = 'Change@' . preg_replace('/\D+/', '', $memberNo);
        if (in_array($password, [$base, $base . '@1234', $base . '1234'], true)) {
            mapi_fail(403, 'For your security, your starting password has been retired. Use "Forgot password" on the church website to choose a new one, or contact the church office.');
        }
    }

    $token = mapi_issue_token($conn, (int)$account['user_id'], (int)$account['member_id'], $device);
    $mustChange = false;
    try {
        $flag = mapi_row($conn, 'SELECT must_change_password FROM users WHERE id=? LIMIT 1', 'i', [(int)$account['user_id']]);
        $mustChange = (int)($flag['must_change_password'] ?? 0) === 1;
    } catch (Throwable $e) {
        // Older database without the column: nothing to change.
    }
    mapi_ok([
        'token' => $token,
        'must_change_password' => $mustChange,
        'expires_in_days' => MAPI_TOKEN_DAYS,
        'member' => mapi_member_profile($conn, (int)$account['member_id']),
    ]);
}

function mapi_action_logout(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $auth['token_id']);
    $stmt->execute();
    $stmt->close();
    // This phone stops getting push notifications.
    if (mapi_table($conn, 'member_push_devices')) mn_exec($conn, 'DELETE FROM member_push_devices WHERE api_token_id=?', 'i', [$auth['token_id']]);
    mapi_ok(['message' => 'Signed out.']);
}

function mapi_action_me(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $logo = mapi_setting($conn, 'site_logo', mapi_setting($conn, 'logo', ''));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo) && defined('BASE_URL')) $logo = rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    mapi_ok([
        'member' => mapi_member_profile($conn, $auth['member_id']),
        'church' => [
            'name' => mapi_setting($conn, 'site_name', APP_NAME),
            'subtitle' => mapi_setting($conn, 'site_subtitle', ''),
            'logo_url' => $logo,
        ],
    ]);
}

function mapi_action_change_password(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $input = mapi_input();
    $current = (string)($input['current_password'] ?? '');
    $new = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    $user = mapi_row($conn, 'SELECT password FROM users WHERE id=? LIMIT 1', 'i', [$auth['user_id']]);
    $errors = mapi_password_errors($current, $new, $confirm, (string)($user['password'] ?? ''));
    if ($errors) mapi_fail(422, implode(' ', $errors));

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $stmt->bind_param('si', $hash, $auth['user_id']);
    $stmt->execute();
    $stmt->close();
    try {
        $stmt = $conn->prepare('UPDATE users SET must_change_password=0 WHERE id=?');
        $stmt->bind_param('i', $auth['user_id']);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Older database without the column.
    }
    // Sign out the member's other devices; this one stays signed in.
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE user_id=? AND id<>? AND revoked_at IS NULL');
    $stmt->bind_param('ii', $auth['user_id'], $auth['token_id']);
    $stmt->execute();
    $stmt->close();
    mapi_ok(['message' => 'Your password was changed. Use it for the app and the website.']);
}

/**
 * Sends a 6-digit reset code to the phone and email on file for a membership or pledge number.
 * The reply is the same whether or not the number matched, so it cannot be used to find valid numbers.
 */
function mapi_action_forgot_password(mysqli $conn): never
{
    mapi_require_method('POST');
    if (!mapi_table($conn, 'member_api_password_resets')) mapi_fail(503, 'Password reset is not set up yet. Please contact the church office.');
    $identifier = mapi_str(mapi_input(), 'identifier', 80);
    if ($identifier === '') mapi_fail(422, 'Enter your membership or pledge number.');

    $key = mb_strtolower($identifier);
    $ip = mapi_client_ip();
    $since = date('Y-m-d H:i:s', time() - 3600);
    $byIdentifier = mapi_row($conn, 'SELECT COUNT(*) c FROM member_api_password_resets WHERE identifier=? AND created_at>=?', 'ss', [$key, $since]);
    $byIp = mapi_row($conn, 'SELECT COUNT(*) c FROM member_api_password_resets WHERE ip_address=? AND created_at>=?', 'ss', [$ip, $since]);
    if ((int)$byIdentifier['c'] >= MAPI_RESET_MAX_REQUESTS || (int)$byIp['c'] >= MAPI_RESET_MAX_REQUESTS_PER_IP) {
        mapi_fail(429, 'Too many reset requests. Please wait an hour and try again, or contact the church office.');
    }

    $account = mapi_find_member_account($conn, $identifier);
    $userId = $account ? (int)$account['user_id'] : null;
    $code = (string)random_int(100000, 999999);
    $codeHash = $account ? password_hash($code, PASSWORD_DEFAULT) : null;
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + MAPI_RESET_CODE_MINUTES * 60);
    if ($account) {
        // Only the newest code works.
        $stmt = $conn->prepare('UPDATE member_api_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare('INSERT INTO member_api_password_resets (identifier, ip_address, user_id, code_hash, created_at, expires_at) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('ssisss', $key, $ip, $userId, $codeHash, $now, $expires);
    $stmt->execute();
    $stmt->close();
    // Keep the table small: requests older than a day no longer matter.
    $conn->query('DELETE FROM member_api_password_resets WHERE created_at < NOW() - INTERVAL 1 DAY');

    if ($account) {
        $contacts = mapi_reset_contacts($conn, (int)$account['member_id'], $userId);
        if (!mapi_send_reset_code($conn, $contacts, $code)) {
            error_log('[Member API] forgot_password: no reset code could be sent for user ' . $userId);
        }
    }
    mapi_ok([
        'message' => 'If this number belongs to an active member with a phone number or email on file, a 6-digit code has been sent. '
            . 'It expires in ' . MAPI_RESET_CODE_MINUTES . ' minutes. If nothing arrives, contact the church office.',
        'expires_in_minutes' => MAPI_RESET_CODE_MINUTES,
    ]);
}

/** Sets a new password with a code from forgot_password, and signs the member out of the app everywhere. */
function mapi_action_reset_password(mysqli $conn): never
{
    mapi_require_method('POST');
    if (!mapi_table($conn, 'member_api_password_resets')) mapi_fail(503, 'Password reset is not set up yet. Please contact the church office.');
    $input = mapi_input();
    $identifier = mapi_str($input, 'identifier', 80);
    $code = mapi_str($input, 'code', 10);
    $new = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    if ($identifier === '' || $code === '') mapi_fail(422, 'Enter your membership or pledge number and the code you received.');

    $key = mb_strtolower($identifier);
    if (mapi_is_locked($conn, $key)) mapi_fail(429, 'Too many failed attempts. Please wait ' . MAPI_LOCK_MINUTES . ' minutes and try again.');

    $account = mapi_find_member_account($conn, $identifier);
    $reset = $account ? mapi_row(
        $conn,
        'SELECT id, code_hash FROM member_api_password_resets
         WHERE user_id=? AND used_at IS NULL AND expires_at>NOW() AND attempts<? AND code_hash IS NOT NULL
         ORDER BY id DESC LIMIT 1',
        'ii',
        [(int)$account['user_id'], MAPI_RESET_MAX_TRIES]
    ) : null;
    if (!$reset || !preg_match('/^\d{6}$/', $code) || !password_verify($code, (string)$reset['code_hash'])) {
        if ($reset) {
            $stmt = $conn->prepare('UPDATE member_api_password_resets SET attempts=attempts+1 WHERE id=?');
            $stmt->bind_param('i', $reset['id']);
            $stmt->execute();
            $stmt->close();
        }
        mapi_record_failure($conn, $key);
        mapi_fail(422, 'The code is incorrect or has expired. Check the code or request a new one.');
    }

    $userId = (int)$account['user_id'];
    $errors = mapi_new_password_errors($new, $confirm, (string)$account['password']);
    if ($errors) mapi_fail(422, implode(' ', $errors));

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('UPDATE member_api_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    // Anyone who knew the old password is signed out of the app.
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    mapi_clear_failures($conn, $key);
    mapi_ok(['message' => 'Your password was reset. Use it for the app and the website.']);
}

/* ------------------------------------------------------------------ notifications */

function mapi_require_notifications(mysqli $conn): void
{
    if (!mn_ready($conn)) mapi_fail(503, 'Notifications are not set up yet. Please contact the church office.');
}

/** The member's choices per category, and which channels can reach them. */
function mapi_action_notification_settings(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    mapi_require_notifications($conn);
    $prefs = mn_prefs($conn, $auth['member_id']);
    $categories = [];
    foreach (mn_categories() as $key => $c) {
        $categories[] = ['key' => $key, 'label' => $c['label'], 'description' => $c['description']] + $prefs[$key];
    }
    $contact = mn_contact($conn, $auth['member_id']);
    mapi_ok([
        'categories' => $categories,
        'channels' => [
            'push' => ['available' => mn_push_configured(), 'note' => mn_push_configured() ? null : 'Push notifications are not switched on by the church yet.'],
            'email' => ['available' => $contact['email'] !== '', 'note' => $contact['email'] !== '' ? $contact['email'] : 'Add an email address to your profile to get emails.'],
            'sms' => ['available' => $contact['phone'] !== '' && mn_sms_configured($conn), 'note' => $contact['phone'] === '' ? 'Add a phone number to your profile to get SMS.' : (mn_sms_configured($conn) ? $contact['phone'] : 'SMS is not switched on by the church yet.')],
        ],
    ]);
}

function mapi_action_update_notification_settings(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    mapi_require_notifications($conn);
    $settings = mapi_input()['settings'] ?? null;
    if (!is_array($settings)) mapi_fail(422, 'No settings were sent.');
    mn_save_prefs($conn, $auth['member_id'], $settings);
    mapi_ok(['message' => 'Your notification settings were saved.']);
}

/** Saves this phone's push token so it can receive notifications while signed in. */
function mapi_action_register_device(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    mapi_require_notifications($conn);
    $input = mapi_input();
    $token = mapi_str($input, 'fcm_token', 255);
    $platform = mapi_str($input, 'platform', 20);
    if ($token === '' || !preg_match('/^[A-Za-z0-9_:\-]+$/', $token)) mapi_fail(422, 'Invalid push token.');
    mn_exec($conn, 'INSERT INTO member_push_devices (member_id, user_id, api_token_id, fcm_token, platform, last_seen_at) VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE member_id=VALUES(member_id), user_id=VALUES(user_id), api_token_id=VALUES(api_token_id), platform=VALUES(platform), last_seen_at=NOW()',
        'iiiss', [$auth['member_id'], $auth['user_id'], $auth['token_id'], $token, $platform]);
    mapi_ok(['message' => 'This phone will get push notifications.']);
}

/** The member's latest notifications, newest first. */
function mapi_action_notifications(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    mapi_require_notifications($conn);
    $items = mapi_rows($conn, 'SELECT id, category, title, body, created_at, read_at FROM member_notifications
        WHERE member_id=? ORDER BY id DESC LIMIT 100', 'i', [$auth['member_id']]);
    $unread = 0;
    foreach ($items as &$n) {
        $n['id'] = (int)$n['id'];
        if ($n['read_at'] === null) $unread++;
    }
    unset($n);
    mapi_ok(['items' => $items, 'unread' => $unread]);
}

function mapi_action_mark_notifications_read(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    mapi_require_notifications($conn);
    mn_exec($conn, 'UPDATE member_notifications SET read_at=NOW() WHERE member_id=? AND read_at IS NULL', 'i', [$auth['member_id']]);
    mapi_ok(['message' => 'Marked as read.']);
}

/** Contact details and service attendance, with the same rules as the web portal (member/profile.php). */
function mapi_action_update_profile(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $input = mapi_input();
    $email = mb_strtolower(mapi_str($input, 'email', 120));
    $phone = mapi_str($input, 'phone', 25);
    $service = mapi_str($input, 'service_attended', 120);
    $errors = [];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($phone === '') $errors[] = 'Phone number is required.';
    elseif (!preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) $errors[] = 'Enter a valid phone number.';
    if ($email !== '' && mapi_row($conn, 'SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', 'si', [$email, $auth['user_id']])) {
        $errors[] = 'That email address is already being used by another account.';
    }
    if ($errors) mapi_fail(422, implode(' ', $errors));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE users SET email=?, phone=? WHERE id=?');
        $stmt->bind_param('ssi', $email, $phone, $auth['user_id']);
        $stmt->execute();
        $stmt->close();

        $sets = [];
        $types = '';
        $values = [];
        foreach (['email' => ['s', $email], 'phone' => ['s', $phone], 'service_attended' => ['s', $service !== '' ? $service : null]] as $col => [$type, $value]) {
            if (!mapi_column($conn, 'members', $col)) continue;
            $sets[] = "`$col`=?";
            $types .= $type;
            $values[] = $value;
        }
        if ($sets) {
            $types .= 'i';
            $values[] = $auth['member_id'];
            $stmt = $conn->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id=?');
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    mapi_ok(['message' => 'Your profile was updated.', 'member' => mapi_member_profile($conn, $auth['member_id'])]);
}

/** Profile photo upload (multipart field "photo"), with the same rules as the web portal. */
function mapi_action_update_photo(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    if (!mapi_column($conn, 'members', 'photo')) mapi_fail(422, 'Profile photos are not available.');
    $photo = $_FILES['photo'] ?? null;
    if (!is_array($photo) || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooLarge = in_array($photo['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        mapi_fail(422, $tooLarge ? 'The selected photo is too large.' : 'Select a profile photo to upload.');
    }
    if ((int)($photo['size'] ?? 0) > 5 * 1024 * 1024) mapi_fail(422, 'The profile photo must not exceed 5 MB.');
    $tmp = (string)($photo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) mapi_fail(422, 'The uploaded photo is invalid.');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset($allowed[$mime])) mapi_fail(422, 'Upload a JPG, PNG, or WEBP profile photo.');
    if (@getimagesize($tmp) === false) mapi_fail(422, 'The selected file is not a valid image.');

    $dir = __DIR__ . '/../../assets/uploads/members';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create ' . $dir);
    $file = sprintf('member_%d_%s.%s', $auth['member_id'], bin2hex(random_bytes(12)), $allowed[$mime]);
    $relative = 'assets/uploads/members/' . $file;
    if (!move_uploaded_file($tmp, $dir . '/' . $file)) throw new RuntimeException('Cannot save the uploaded photo.');

    $old = trim((string)(mapi_row($conn, 'SELECT photo FROM members WHERE id=?', 'i', [$auth['member_id']])['photo'] ?? ''));
    $stmt = $conn->prepare('UPDATE members SET photo=? WHERE id=?');
    $stmt->bind_param('si', $relative, $auth['member_id']);
    $stmt->execute();
    $stmt->close();

    // Remove the previous member-uploaded photo, never anything outside the members upload folder.
    if ($old !== '' && str_starts_with($old, 'assets/uploads/members/')) {
        $oldPath = realpath(__DIR__ . '/../../' . $old);
        $root = realpath($dir);
        if ($oldPath !== false && $root !== false && str_starts_with($oldPath, $root) && is_file($oldPath)) @unlink($oldPath);
    }
    mapi_ok(['message' => 'Your profile photo was updated.', 'member' => mapi_member_profile($conn, $auth['member_id'])]);
}

/* ------------------------------------------------------------------ home */

function mapi_action_dashboard(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $memberId = $auth['member_id'];
    $pledges = ['total_pledged' => 0.0, 'total_paid' => 0.0, 'balance' => 0.0, 'open' => 0];
    if (mapi_table($conn, 'pledges')) {
        $row = mapi_row($conn, "SELECT COALESCE(SUM(amount_pledged),0) pledged, COALESCE(SUM(amount_paid),0) paid,
                SUM(status IN ('Pending','Partial')) open_count
            FROM pledges WHERE member_id=? AND status<>'Cancelled'", 'i', [$memberId]);
        $pledges = ['total_pledged' => (float)$row['pledged'], 'total_paid' => (float)$row['paid'],
            'balance' => max(0, (float)$row['pledged'] - (float)$row['paid']), 'open' => (int)$row['open_count']];
    }
    mapi_ok([
        'member' => mapi_member_profile($conn, $memberId),
        'verses' => mc_dashboard_verses($conn, 3),
        'pledges' => $pledges,
        'announcements' => array_slice(mapi_published_announcements($conn), 0, 3),
        'programs' => array_slice(mapi_upcoming_programs($conn), 0, 3),
    ]);
}

/* ------------------------------------------------------------------ giving */

function mapi_action_giving(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    // Each giving type mapped to: table, date column, amount column, reference and description columns.
    $types = [
        'tithe'         => ['tithe', 'tithe_date', 'amount', 'receipt_no', "CONCAT_WS(' ', 'Tithe', period_month, period_year)"],
        'thanksgiving'  => ['thanksgiving', 'thanksgiving_date', 'amount', 'receipt_no', 'COALESCE(NULLIF(reason,\'\'), item_description)'],
        'contributions' => ['contributions', 'contributed_on', 'amount', 'receipt_no', 'notes'],
    ];
    $type = (string)($_GET['type'] ?? 'tithe');
    if (!isset($types[$type])) mapi_fail(422, 'Unknown giving type.');
    [$table, $date, $amount, $reference, $description] = $types[$type];
    if (!mapi_table($conn, $table)) mapi_ok(['type' => $type, 'total' => 0, 'items' => []]);

    $items = mapi_rows($conn, "SELECT id, `$date` AS date, `$amount` AS amount, $reference AS reference,
            $description AS description, payment_method
        FROM `$table` WHERE member_id=? ORDER BY `$date` DESC, id DESC LIMIT 500", 'i', [$auth['member_id']]);
    $total = 0.0;
    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['amount'] = (float)$item['amount'];
        $total += $item['amount'];
    }
    unset($item);
    mapi_ok(['type' => $type, 'total' => $total, 'items' => $items]);
}

function mapi_action_pledges(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    if (!mapi_table($conn, 'pledges')) mapi_ok(['items' => []]);
    $projectJoin = mapi_table($conn, 'projects') ? 'LEFT JOIN projects pr ON pr.id=p.project_id' : '';
    $projectName = $projectJoin ? 'pr.name' : 'NULL';
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
    $items = mapi_rows($conn, "SELECT p.id, p.pledge_no, p.pledge_reason, $projectName AS project_name $typeSelect,
            p.amount_pledged, p.amount_paid, p.pledge_date, p.due_date, p.status
        FROM pledges p $projectJoin $typeJoin WHERE p.member_id=? ORDER BY p.pledge_date DESC, p.id DESC", 'i', [$auth['member_id']]);

    $payments = [];
    if ($items && mapi_table($conn, 'pledge_payments')) {
        $ids = array_map(fn($p) => (int)$p['id'], $items);
        $rows = mapi_rows($conn, 'SELECT pledge_id, amount, paid_on, method, receipt_no FROM pledge_payments WHERE pledge_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY paid_on DESC, id DESC', str_repeat('i', count($ids)), $ids);
        foreach ($rows as $r) $payments[(int)$r['pledge_id']][] = ['amount' => (float)$r['amount'], 'paid_on' => $r['paid_on'], 'method' => $r['method'], 'receipt_no' => $r['receipt_no']];
    }
    foreach ($items as &$p) {
        $p['purpose'] = pledge_purpose($p);
        $p['id'] = (int)$p['id'];
        $p['amount_pledged'] = (float)$p['amount_pledged'];
        $p['amount_paid'] = (float)$p['amount_paid'];
        $p['balance'] = max(0, $p['amount_pledged'] - $p['amount_paid']);
        $p['payments'] = $payments[$p['id']] ?? [];
    }
    unset($p);
    mapi_ok(['items' => $items]);
}

/* ------------------------------------------------------------------ church news */

function mapi_published_announcements(mysqli $conn): array
{
    if (!mapi_table($conn, 'church_announcements')) return [];
    return mapi_rows($conn, "SELECT id, title, message, priority, COALESCE(publish_from, created_at) AS published_at
        FROM church_announcements
        WHERE status='Published' AND (publish_from IS NULL OR publish_from<=NOW()) AND (publish_until IS NULL OR publish_until>=NOW())
        ORDER BY FIELD(priority,'Urgent','Important','Normal'), publish_from DESC, id DESC LIMIT 100");
}

function mapi_upcoming_programs(mysqli $conn): array
{
    if (!mapi_table($conn, 'church_programs')) return [];
    return mapi_rows($conn, "SELECT id, title, description, program_date, start_time, end_time, venue, program_type, meeting_link, status
        FROM church_programs
        WHERE program_date>=CURDATE() AND COALESCE(status,'Scheduled')<>'Cancelled'
        ORDER BY program_date, start_time, id LIMIT 100");
}

function mapi_action_announcements(mysqli $conn): never
{
    mapi_auth($conn);
    mapi_ok(['items' => mapi_published_announcements($conn)]);
}

function mapi_action_programs(mysqli $conn): never
{
    mapi_auth($conn);
    mapi_ok(['items' => mapi_upcoming_programs($conn)]);
}

/* ------------------------------------------------------------------ worship */

function mapi_action_order_of_service(mysqli $conn): never
{
    mapi_auth($conn);
    if (!mapi_table($conn, 'service_orders')) mapi_ok(['orders' => [], 'order' => null]);
    $orders = mapi_rows($conn, "SELECT id, title, service_type, service_date, service_time, notes FROM service_orders
        WHERE status='Published' ORDER BY service_date DESC, service_time DESC, id DESC");
    $selectedId = (int)($_GET['id'] ?? 0);
    $order = null;
    foreach ($orders as $o) if ((int)$o['id'] === $selectedId) $order = $o;
    if (!$order && $orders) {
        // Default to the next service (today or later); otherwise the most recent one.
        $upcoming = array_filter($orders, fn($o) => $o['service_date'] >= date('Y-m-d'));
        $order = $upcoming ? end($upcoming) : $orders[0];
    }
    if ($order) {
        $order['id'] = (int)$order['id'];
        $order['items'] = mapi_rows($conn, "SELECT i.item_title, i.led_by, i.details, h.id AS hymn_id, h.hymn_number, h.title AS hymn_title
            FROM service_order_items i LEFT JOIN church_hymns h ON h.id=i.hymn_id AND h.status='Published'
            WHERE i.service_order_id=? ORDER BY i.sort_order, i.id", 'i', [$order['id']]);
    }
    $list = array_map(fn($o) => ['id' => (int)$o['id'], 'title' => $o['title'], 'service_date' => $o['service_date']], $orders);
    mapi_ok(['orders' => $list, 'order' => $order]);
}

function mapi_action_hymns(mysqli $conn): never
{
    mapi_auth($conn);
    if (!mapi_table($conn, 'church_hymns')) mapi_ok(['items' => []]);
    $items = mapi_rows($conn, "SELECT id, hymn_number, title, hymn_book, language, author, theme FROM church_hymns
        WHERE status='Published' ORDER BY hymn_book, CAST(hymn_number AS UNSIGNED), hymn_number, title");
    foreach ($items as &$h) $h['id'] = (int)$h['id'];
    unset($h);
    mapi_ok(['items' => $items]);
}

function mapi_action_hymn(mysqli $conn): never
{
    mapi_auth($conn);
    $id = (int)($_GET['id'] ?? 0);
    $hymn = mapi_table($conn, 'church_hymns')
        ? mapi_row($conn, "SELECT id, hymn_number, title, hymn_book, language, author, tune, theme, scripture_reference, study_notes,
                lyrics, copyright_status, copyright_notice
            FROM church_hymns WHERE id=? AND status='Published' LIMIT 1", 'i', [$id])
        : null;
    if (!$hymn) mapi_fail(404, 'That song is not available.');
    $hymn['id'] = (int)$hymn['id'];
    // Lyrics are only shown when a right to reproduce them was recorded.
    if ($hymn['copyright_status'] === 'Not Entered' || trim((string)$hymn['lyrics']) === '') {
        $hymn['lyrics'] = null;
        $hymn['copyright_notice'] = null;
    }
    mapi_ok(['hymn' => $hymn]);
}

function mapi_action_tips(mysqli $conn): never
{
    mapi_auth($conn);
    $dept = (string)($_GET['dept'] ?? 'health');
    if (!mc_valid_department($dept)) mapi_fail(422, 'Unknown department.');
    $items = mapi_table($conn, 'department_tips')
        ? mapi_rows($conn, "SELECT t.id, t.title, t.content, t.published_at, COALESCE(NULLIF(u.full_name,''), u.username, 'Church Leader') AS author
            FROM department_tips t LEFT JOIN users u ON u.id=t.created_by
            WHERE t.department=? AND t.status='Published' ORDER BY t.published_at DESC, t.id DESC", 's', [$dept])
        : [];
    foreach ($items as &$t) $t['id'] = (int)$t['id'];
    unset($t);
    mapi_ok(['department' => mc_tip_departments()[$dept]['label'], 'items' => $items]);
}
