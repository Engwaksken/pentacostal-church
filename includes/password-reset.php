<?php
declare(strict_types=1);

/*
 * Forgot-password helpers.
 *
 * Flow:
 * 1. forgot-password.php: the user enters a username or email. If it matches an active account with an email
 *    address, a one-time reset link is emailed. The page shows the same message either way, so it never
 *    reveals which accounts exist.
 * 2. reset-password.php: the link opens a form for a new password. The link works once and expires after
 *    PASSWORD_RESET_TTL seconds. Only a SHA-256 hash of the token is stored.
 */

require_once __DIR__ . '/login.php';

const PASSWORD_RESET_TTL = 3600;          // Link lifetime: 1 hour.
const PASSWORD_RESET_MAX_PER_WINDOW = 3;  // Emails per account per window.
const PASSWORD_RESET_WINDOW = 900;        // Window: 15 minutes.

function password_reset_ensure_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            request_ip VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_password_resets_token (token_hash),
            INDEX idx_password_resets_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** The same rules as the member portal's change-password form. */
function password_reset_errors(string $new, string $confirm): array
{
    $errors = [];
    if (strlen($new) < 8) $errors[] = 'The new password must contain at least 8 characters.';
    if (!preg_match('/[A-Z]/', $new)) $errors[] = 'The new password must contain an uppercase letter.';
    if (!preg_match('/[a-z]/', $new)) $errors[] = 'The new password must contain a lowercase letter.';
    if (!preg_match('/\d/', $new)) $errors[] = 'The new password must contain a number.';
    if ($new !== $confirm) $errors[] = 'The password confirmation does not match.';
    return $errors;
}

/**
 * Emails a reset link when $identity matches an active account with a valid email.
 * Returns ['success' => bool, 'message' => string]; on success the message never says whether an account matched.
 */
function password_reset_request(mysqli $conn, string $identity): array
{
    $generic = 'If an account matches the details you entered, a password reset link has been sent to its email address. The link expires in 1 hour.';
    $identity = trim($identity);

    if ($identity === '') {
        return ['success' => false, 'message' => 'Please enter your username or email.'];
    }
    if (strlen($identity) > 190) {
        return ['success' => false, 'message' => 'Username or email is too long.'];
    }

    /* Per-browser throttle, so the form cannot be used to flood an inbox. */
    $lastRequest = (int)($_SESSION['password_reset_last_request'] ?? 0);
    if ($lastRequest > 0 && time() - $lastRequest < 60) {
        return ['success' => false, 'message' => 'Please wait ' . (60 - (time() - $lastRequest)) . ' seconds before requesting another link.'];
    }
    $_SESSION['password_reset_last_request'] = time();

    $user = login_find_user($conn, $identity);
    $email = trim((string)($user['email'] ?? ''));
    if (!$user || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => true, 'message' => $generic];
    }

    password_reset_ensure_table($conn);
    $userId = (int)$user['id'];

    /* Per-account limit across all browsers. */
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)');
    $window = PASSWORD_RESET_WINDOW;
    $stmt->bind_param('ii', $userId, $window);
    $stmt->execute();
    $recent = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($recent >= PASSWORD_RESET_MAX_PER_WINDOW) {
        return ['success' => true, 'message' => $generic];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $ttl = PASSWORD_RESET_TTL;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    /* Only the newest link works: earlier unused links for this account are cancelled. */
    $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip) VALUES (?, ?, NOW() + INTERVAL ? SECOND, ?)');
    $stmt->bind_param('isis', $userId, $tokenHash, $ttl, $ip);
    $stmt->execute();
    $stmt->close();

    $siteName = login_setting($conn, 'site_name', APP_NAME);
    $fullName = trim((string)($user['full_name'] ?? ''));
    $displayName = $fullName !== '' ? $fullName : (string)$user['username'];
    $link = BASE_URL . 'reset-password.php?token=' . $token;

    $safeName = h($displayName);
    $safeSite = h($siteName);
    $safeLink = h($link);
    $safeUser = h((string)$user['username']);

    $htmlBody = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.6;">
    <h2 style="margin-bottom:8px;">Reset your password</h2>
    <p>Hello {$safeName},</p>
    <p>We received a request to reset the password for your {$safeSite} account (username: <strong>{$safeUser}</strong>).</p>
    <p style="text-align:center;margin:24px 0;">
        <a href="{$safeLink}" style="display:inline-block;padding:12px 22px;background:#4a1e8f;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Reset Password</a>
    </p>
    <p>Or copy this link into your browser:<br><a href="{$safeLink}">{$safeLink}</a></p>
    <p>This link expires in <strong>1 hour</strong> and can only be used once.</p>
    <p>If you did not ask to reset your password, you can ignore this email; your password will not change.</p>
</div>
HTML;

    $textBody = "Hello {$displayName},\n\n"
        . "We received a request to reset the password for your {$siteName} account (username: {$user['username']}).\n\n"
        . "Reset your password here: {$link}\n\n"
        . "This link expires in 1 hour and can only be used once.\n"
        . "If you did not ask to reset your password, you can ignore this email.";

    $mail = login_send_email($email, $displayName, $siteName . ' password reset', $htmlBody, $textBody);

    if (function_exists('activity_log')) {
        activity_log($conn, $userId, 'password_reset_requested', 'Password reset link requested.', ['email_sent' => (bool)$mail['success']]);
    }

    if (!$mail['success']) {
        error_log('[Password reset email] ' . $mail['message']);
        return ['success' => false, 'message' => 'We could not send the reset email right now. Please try again later or contact the church office.'];
    }

    return ['success' => true, 'message' => $generic];
}

/** The reset row for a token from a link, when it is unused, unexpired and its account is active; otherwise null. */
function password_reset_find(mysqli $conn, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    password_reset_ensure_table($conn);
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT pr.id, pr.user_id, u.username
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ?
           AND pr.used_at IS NULL
           AND pr.expires_at > NOW()
           AND LOWER(TRIM(u.status)) = 'active'
         LIMIT 1"
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Sets the new password for a valid token. Returns ['success' => bool, 'message' => string, 'errors' => string[]]. */
function password_reset_complete(mysqli $conn, string $token, string $new, string $confirm): array
{
    $reset = password_reset_find($conn, $token);
    if (!$reset) {
        return ['success' => false, 'expired' => true, 'message' => 'This password reset link is invalid or has expired. Please request a new one.', 'errors' => []];
    }

    $errors = password_reset_errors($new, $confirm);
    if ($errors) {
        return ['success' => false, 'message' => 'Please fix the following:', 'errors' => $errors];
    }

    $userId = (int)$reset['user_id'];
    $resetId = (int)$reset['id'];
    $hash = password_hash($new, PASSWORD_DEFAULT);

    $conn->begin_transaction();
    try {
        /* Claim the token first so the same link cannot be used twice at once. */
        $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $stmt->bind_param('i', $resetId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) {
            $conn->rollback();
            return ['success' => false, 'expired' => true, 'message' => 'This password reset link has already been used. Please request a new one.', 'errors' => []];
        }

        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[Password reset] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Your password could not be changed. Please try again.', 'errors' => []];
    }

    if (function_exists('activity_log')) {
        activity_log($conn, $userId, 'password_reset_completed', 'Password was reset using an emailed link.', ['username' => (string)$reset['username']]);
    }

    return ['success' => true, 'message' => 'Your password has been reset. You can now sign in with your new password.', 'errors' => []];
}
