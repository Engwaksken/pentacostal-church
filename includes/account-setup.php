<?php
declare(strict_types=1);

/*
 * New account set-up: a random temporary password, a welcome email with the sign-in details, and a
 * must_change_password flag that sends the user to change-password.php until they choose their own password.
 * The flag is read at sign-in (includes/login.php) and enforced by requireLogin() (config/config.php).
 */

require_once __DIR__ . '/login.php';

function account_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

/** Adds users.must_change_password once. */
function account_ensure_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    if (!account_column_exists($conn, 'users', 'must_change_password')) {
        $conn->query('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
    }
    $done = true;
}

/** A random 10-character temporary password with upper and lower case letters and digits, without look-alike characters. */
function account_temp_password(): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;
    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];
    while (count($chars) < 10) $chars[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** Sets or clears the "must change password at next sign-in" flag for a user. */
function account_set_must_change(mysqli $conn, int $userId, bool $mustChange): void
{
    if ($userId <= 0) return;
    account_ensure_columns($conn);
    $flag = $mustChange ? 1 : 0;
    $stmt = $conn->prepare('UPDATE users SET must_change_password = ? WHERE id = ?');
    $stmt->bind_param('ii', $flag, $userId);
    $stmt->execute();
    $stmt->close();
    if (!$mustChange && (int)($_SESSION['user_id'] ?? 0) === $userId) {
        unset($_SESSION['must_change_password']);
    }
}

/**
 * Emails a new user their username and temporary password. Returns ['sent' => bool, 'message' => string].
 * $how describes the account for the email, e.g. 'a member account' or 'a staff account'.
 */
function account_send_welcome_email(mysqli $conn, string $email, string $fullName, string $username, string $tempPassword, string $how = 'an account'): array
{
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'message' => 'No valid email address.'];
    }

    $siteName = login_setting($conn, 'site_name', APP_NAME);
    $displayName = trim($fullName) !== '' ? trim($fullName) : $username;
    $loginUrl = BASE_URL;

    $safeName = h($displayName);
    $safeSite = h($siteName);
    $safeUser = h($username);
    $safePass = h($tempPassword);
    $safeUrl = h($loginUrl);
    $safeHow = h($how);

    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.6;">
    <h2 style="margin-bottom:8px;">Welcome to {$safeSite}</h2>
    <p>Hello {$safeName},</p>
    <p>{$safeHow} has been created for you. Use these details to sign in:</p>
    <table style="border-collapse:collapse;margin:16px 0;width:100%;">
        <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#fafafa;width:40%;"><strong>Username</strong></td><td style="padding:10px;border:1px solid #e5e7eb;font-family:Consolas,monospace;">{$safeUser}</td></tr>
        <tr><td style="padding:10px;border:1px solid #e5e7eb;background:#fafafa;"><strong>Temporary password</strong></td><td style="padding:10px;border:1px solid #e5e7eb;font-family:Consolas,monospace;font-size:16px;">{$safePass}</td></tr>
    </table>
    <p style="text-align:center;margin:24px 0;">
        <a href="{$safeUrl}" style="display:inline-block;padding:12px 22px;background:#4a1e8f;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Sign In</a>
    </p>
    <p><strong>You will be asked to choose a new password the first time you sign in.</strong> The temporary password stops working once you change it.</p>
    <p>Do not share this email. If you were not expecting it, please contact the church office.</p>
</div>
HTML;

    $text = "Hello {$displayName},\n\n"
        . ucfirst($how) . " has been created for you at {$siteName}.\n\n"
        . "Username: {$username}\n"
        . "Temporary password: {$tempPassword}\n"
        . "Sign in: {$loginUrl}\n\n"
        . "You will be asked to choose a new password the first time you sign in.\n"
        . "Do not share this email. If you were not expecting it, please contact the church office.";

    $result = login_send_email($email, $displayName, 'Your ' . $siteName . ' account', $html, $text);
    return ['sent' => (bool)$result['success'], 'message' => (string)$result['message']];
}
