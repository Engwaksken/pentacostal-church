<?php
declare(strict_types=1);

/*
 * Login helpers.
 *
 * Flow:
 * 1. A user may sign in with either username OR email.
 * 2. Members (users linked to members.user_id) go straight to the dashboard.
 * 3. Non-members must enter a verification code sent to their email.
 * 4. The normal authenticated session is only created after verification.
 */

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

/* Load the existing project mail helper when available. */
$mailHelper = __DIR__ . '/mail-function.php';
if (is_file($mailHelper)) {
    require_once $mailHelper;
}

$activityLogger = __DIR__ . '/activity-logger.php';
if (is_file($activityLogger)) {
    require_once $activityLogger;
}

function login_table_exists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function login_setting(mysqli $conn, string $key, string $default = ''): string
{
    static $settingsTable = null;

    if ($settingsTable === null) {
        if (login_table_exists($conn, 'site_settings')) {
            $settingsTable = 'site_settings';
        } elseif (login_table_exists($conn, 'settings')) {
            $settingsTable = 'settings';
        } else {
            $settingsTable = '';
        }
    }

    if ($settingsTable === '') {
        return $default;
    }

    $stmt = $conn->prepare(
        "SELECT setting_value
         FROM `$settingsTable`
         WHERE setting_key = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return !empty($row['setting_value'])
        ? (string)$row['setting_value']
        : $default;
}

function login_asset_url(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function get_login_site_settings(mysqli $conn): array
{
    $siteName = login_setting($conn, 'site_name', APP_NAME);

    $logoPath = login_setting($conn, 'site_logo', '');
    if ($logoPath === '') {
        $logoPath = login_setting($conn, 'logo', 'assets/img/logo.png');
    }

    $faviconPath = login_setting($conn, 'site_favicon', '');
    if ($faviconPath === '') {
        $faviconPath = login_setting($conn, 'favicon', '');
    }

    return [
        'site_name'   => $siteName,
        'logo_url'    => login_asset_url($logoPath),
        'favicon_url' => login_asset_url($faviconPath),
    ];
}

function login_csrf_token(): string
{
    if (empty($_SESSION['login_csrf_token'])) {
        $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['login_csrf_token'];
}

function login_validate_csrf(array $post): bool
{
    $submitted = (string)($post['csrf_token'] ?? '');
    $stored = (string)($_SESSION['login_csrf_token'] ?? '');

    return $submitted !== ''
        && $stored !== ''
        && hash_equals($stored, $submitted);
}

function login_clear_pending_verification(): void
{
    unset($_SESSION['pending_login']);
}

function login_start_authenticated_session(
    mysqli $conn,
    array $user,
    string $loginMethod = 'password'
): void {
    session_regenerate_id(true);

    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['username']  = (string)$user['username'];
    $_SESSION['full_name'] = (string)$user['full_name'];
    $_SESSION['role']      = (string)$user['role'];
    $_SESSION['member_id'] = (int)($user['member_id'] ?? 0);
    $_SESSION['email']     = (string)($user['email'] ?? '');

    /* Accounts created with a temporary password must change it before using the system. */
    unset($_SESSION['must_change_password']);
    try {
        $flagCol = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'");
        if ($flagCol && $flagCol->num_rows > 0) {
            $flagStmt = $conn->prepare('SELECT must_change_password FROM users WHERE id = ? LIMIT 1');
            $flagUserId = (int)$user['id'];
            $flagStmt->bind_param('i', $flagUserId);
            $flagStmt->execute();
            if ((int)($flagStmt->get_result()->fetch_assoc()['must_change_password'] ?? 0) === 1) {
                $_SESSION['must_change_password'] = 1;
            }
            $flagStmt->close();
        }
    } catch (Throwable $e) {
        error_log('[Login] must_change_password check failed: ' . $e->getMessage());
    }

    /*
     * Record the successful login before clearing pending OTP data.
     * A logging failure must never block a valid login.
     */
    if (function_exists('activity_log')) {
        activity_log(
            $conn,
            (int)$user['id'],
            'login_success',
            'User logged in successfully.',
            [
                'login_method' => $loginMethod,
                'username'     => (string)($user['username'] ?? ''),
                'role'         => (string)($user['role'] ?? ''),
                'member_id'    => (int)($user['member_id'] ?? 0),
            ]
        );
    }

    login_clear_pending_verification();

    /* Rotate the form token after a successful authentication. */
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

function login_find_user(mysqli $conn, string $identity): ?array
{
    $stmt = $conn->prepare(
        "SELECT
            u.id,
            u.username,
            u.email,
            u.full_name,
            u.role,
            u.password,
            u.status,
            m.id AS member_id
         FROM users u
         LEFT JOIN members m ON m.user_id = u.id
         WHERE (u.username = ? OR u.email = ?)
           AND LOWER(TRIM(u.status)) = 'active'
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();

    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $user ?: null;
}

/**
 * Sends through the project's existing sendEmail() helper.
 * Supports both of the common signatures used by this project:
 *   sendEmail($to, $subject, $body, $altBody = null, ...)
 *   sendEmail($to, $toName, $subject, $body, ...)
 */
function login_send_email(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    if (!function_exists('sendEmail')) {
        return [
            'success' => false,
            'message' => 'The email service is not available. Please check includes/mail-function.php.',
        ];
    }

    try {
        $reflection = new ReflectionFunction('sendEmail');
        $params = $reflection->getParameters();
        $secondName = isset($params[1])
            ? strtolower($params[1]->getName())
            : '';

        /* Newer helper: sendEmail($to, $subject, $body, $altBody, ...) */
        if (str_contains($secondName, 'subject')) {
            $result = sendEmail($toEmail, $subject, $htmlBody, $textBody);
        } else {
            /* Older helper: sendEmail($to, $toName, $subject, $body) */
            $result = sendEmail($toEmail, $toName, $subject, $htmlBody);
        }

        if ($result === true) {
            return ['success' => true, 'message' => 'Verification code sent.'];
        }

        if (is_array($result)) {
            $ok = (bool)($result['success'] ?? false);
            return [
                'success' => $ok,
                'message' => $ok
                    ? (string)($result['message'] ?? 'Verification code sent.')
                    : (string)($result['message'] ?? $result['error'] ?? 'Failed to send verification email.'),
            ];
        }

        if (is_string($result) && trim($result) !== '') {
            return ['success' => false, 'message' => trim($result)];
        }

        return ['success' => false, 'message' => 'Failed to send verification email.'];
    } catch (Throwable $e) {
        error_log('[Login verification email] ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Failed to send verification email. Please check the SMTP settings.',
        ];
    }
}

function login_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    [$name, $domain] = $parts;
    $nameLength = strlen($name);

    if ($nameLength <= 2) {
        $maskedName = substr($name, 0, 1) . '*';
    } else {
        $maskedName = substr($name, 0, 2)
            . str_repeat('*', max(2, $nameLength - 2));
    }

    return $maskedName . '@' . $domain;
}

function login_send_verification_code(mysqli $conn, array $user, bool $isResend = false): array
{
    $email = trim((string)($user['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'This account has no valid email address. Please contact the administrator to add an email before signing in.',
        ];
    }

    if ($isResend && isset($_SESSION['pending_login']['last_sent_at'])) {
        $lastSent = (int)$_SESSION['pending_login']['last_sent_at'];
        $wait = 60 - (time() - $lastSent);

        if ($wait > 0) {
            return [
                'success' => false,
                'message' => 'Please wait ' . $wait . ' seconds before requesting another code.',
            ];
        }
    }

    $code = (string)random_int(100000, 999999);
    $siteName = login_setting($conn, 'site_name', APP_NAME);
    $fullName = trim((string)($user['full_name'] ?? ''));
    $displayName = $fullName !== '' ? $fullName : (string)$user['username'];

    $subject = $siteName . ' login verification code';
    $safeName = h($displayName);
    $safeSite = h($siteName);
    $safeCode = h($code);

    $htmlBody = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.6;">
    <h2 style="margin-bottom:8px;">Login verification</h2>
    <p>Hello {$safeName},</p>
    <p>A login was requested for your {$safeSite} account.</p>
    <p>Your verification code is:</p>
    <div style="font-size:30px;font-weight:700;letter-spacing:8px;padding:16px 20px;background:#f4f4f4;border-radius:8px;text-align:center;">{$safeCode}</div>
    <p>This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
    <p>If you did not try to sign in, you can ignore this email.</p>
</div>
HTML;

    $textBody = "Hello {$displayName},\n\n"
        . "Your {$siteName} login verification code is: {$code}\n"
        . "The code expires in 10 minutes. Do not share it with anyone.\n\n"
        . "If you did not try to sign in, you can ignore this email.";

    $mailResult = login_send_email(
        $email,
        $displayName,
        $subject,
        $htmlBody,
        $textBody
    );

    if (!$mailResult['success']) {
        return $mailResult;
    }

    /* Store only a hash of the code in the session. */
    $_SESSION['pending_login'] = [
        'user' => [
            'id'        => (int)$user['id'],
            'username'  => (string)$user['username'],
            'email'     => $email,
            'full_name' => (string)$user['full_name'],
            'role'      => (string)$user['role'],
            'member_id' => (int)($user['member_id'] ?? 0),
        ],
        'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
        'expires_at'   => time() + 600,
        'attempts'     => 0,
        'last_sent_at' => time(),
    ];

    return [
        'success'        => true,
        'requires_code'  => true,
        'message'        => 'A 6-digit verification code was sent to ' . login_mask_email($email) . '.',
        'masked_email'   => login_mask_email($email),
    ];
}

/**
 * True when $password is one of the old automatic member passwords, "Change@" followed by the digits of the
 * member number (with "@1234" or "1234" added when it was short). Anyone who knows a member number could guess
 * these, so they are no longer accepted for signing in.
 */
function login_is_legacy_member_password(mysqli $conn, int $memberId, string $password): bool
{
    if ($memberId <= 0 || !str_starts_with($password, 'Change@')) return false;
    $stmt = $conn->prepare('SELECT member_no FROM members WHERE id = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $memberNo = (string)($stmt->get_result()->fetch_assoc()['member_no'] ?? '');
    $stmt->close();
    $base = 'Change@' . preg_replace('/\D+/', '', $memberNo);
    return in_array($password, [$base, $base . '@1234', $base . '1234'], true);
}

function process_login(mysqli $conn, array $post): array
{
    $identity = trim((string)($post['identity'] ?? $post['username'] ?? ''));
    $password = (string)($post['password'] ?? '');

    if ($identity === '' || $password === '') {
        return [
            'success' => false,
            'message' => 'Please enter your username or email and password.',
        ];
    }

    if (strlen($identity) > 190) {
        return [
            'success' => false,
            'message' => 'Username or email is too long.',
        ];
    }

    $user = login_find_user($conn, $identity);

    if (!$user) {
        return [
            'success' => false,
            'message' => 'User not found or account inactive.',
        ];
    }

    if (!password_verify($password, (string)$user['password'])) {
        if (function_exists('activity_log')) {
            activity_log(
                $conn,
                (int)$user['id'],
                'login_failed',
                'Login failed because the password was incorrect.',
                [
                    'identity' => $identity,
                    'reason'   => 'incorrect_password',
                ]
            );
        }

        return [
            'success' => false,
            'message' => 'Incorrect password.',
        ];
    }

    $memberId = (int)($user['member_id'] ?? 0);

    /*
     * The old guessable member password no longer signs anyone in. Instead the account's owner is emailed a
     * reset link, so only someone with access to that inbox can set the new password.
     */
    if (login_is_legacy_member_password($conn, $memberId, $password)) {
        require_once __DIR__ . '/password-reset.php';
        $hasEmail = filter_var(trim((string)($user['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
        $reset = $hasEmail ? password_reset_request($conn, (string)$user['username']) : ['success' => false];

        if (function_exists('activity_log')) {
            activity_log($conn, (int)$user['id'], 'login_blocked_legacy_password',
                'Sign-in refused: the account still used the old automatic password.', ['reset_email' => $hasEmail]);
        }

        return [
            'success' => false,
            'message' => $hasEmail && !empty($reset['success'])
                ? 'For your security, the starting password for your account has been retired. We have emailed you a link to choose a new password (it expires in 1 hour).'
                : 'For your security, the starting password for your account has been retired. Please contact the church office to have your password reset.',
        ];
    }

    /* Members keep the existing direct-login behavior. */
    if ($memberId > 0) {
        login_start_authenticated_session($conn, $user, 'password');

        return [
            'success' => true,
            'requires_code' => false,
            'message' => 'Login successful.',
        ];
    }

    /* Non-members must verify by email before a dashboard session is created. */
    login_clear_pending_verification();
    return login_send_verification_code($conn, $user);
}

function verify_login_code(mysqli $conn, array $post): array
{
    $code = preg_replace('/\D+/', '', (string)($post['verification_code'] ?? ''));
    $pending = $_SESSION['pending_login'] ?? null;

    if (!is_array($pending) || empty($pending['user'])) {
        return [
            'success' => false,
            'message' => 'Your verification session has expired. Please sign in again.',
            'restart' => true,
        ];
    }

    if ((int)($pending['expires_at'] ?? 0) < time()) {
        login_clear_pending_verification();

        return [
            'success' => false,
            'message' => 'The verification code has expired. Please sign in again to request a new code.',
            'restart' => true,
        ];
    }

    $attempts = (int)($pending['attempts'] ?? 0);
    if ($attempts >= 5) {
        login_clear_pending_verification();

        return [
            'success' => false,
            'message' => 'Too many incorrect verification attempts. Please sign in again.',
            'restart' => true,
        ];
    }

    if (strlen($code) !== 6) {
        return [
            'success' => false,
            'message' => 'Enter the 6-digit verification code.',
        ];
    }

    if (!password_verify($code, (string)($pending['code_hash'] ?? ''))) {
        $_SESSION['pending_login']['attempts'] = $attempts + 1;
        $remaining = max(0, 5 - ($attempts + 1));

        return [
            'success' => false,
            'message' => 'Incorrect verification code.'
                . ($remaining > 0 ? ' ' . $remaining . ' attempt(s) remaining.' : ''),
        ];
    }

    login_start_authenticated_session($conn, (array)$pending['user'], 'email_otp');

    return [
        'success' => true,
        'message' => 'Email verified successfully.',
    ];
}

function resend_login_code(mysqli $conn): array
{
    $pending = $_SESSION['pending_login'] ?? null;

    if (!is_array($pending) || empty($pending['user'])) {
        return [
            'success' => false,
            'message' => 'Your verification session has expired. Please sign in again.',
            'restart' => true,
        ];
    }

    return login_send_verification_code($conn, (array)$pending['user'], true);
}
