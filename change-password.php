<?php
declare(strict_types=1);

/*
 * First sign-in password change. requireLogin() sends every page here while the signed-in user still has a
 * temporary password (users.must_change_password = 1); it can also be opened directly to change a password.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/password-reset.php';
require_once __DIR__ . '/includes/account-setup.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$forced = !empty($_SESSION['must_change_password']);
$error = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!login_validate_csrf($_POST)) {
        $error = 'Your session token is invalid or expired. Please refresh the page and try again.';
    } else {
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $errors = password_reset_errors($new, $confirm);

        $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $currentHash = (string)($stmt->get_result()->fetch_assoc()['password'] ?? '');
        $stmt->close();

        if (!$forced) {
            // A voluntary change needs the current password; a forced one follows a sign-in that just checked it.
            if (!password_verify((string)($_POST['current_password'] ?? ''), $currentHash)) {
                $errors[] = 'Your current password is incorrect.';
            }
        }
        if ($currentHash !== '' && password_verify($new, $currentHash)) {
            $errors[] = 'The new password must be different from your current password.';
        }

        if ($errors) {
            $error = 'Please fix the following:';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();
            account_set_must_change($conn, $userId, false);
            unset($_SESSION['must_change_password']);

            if (function_exists('activity_log')) {
                activity_log($conn, $userId, 'password_changed', $forced ? 'Temporary password replaced at first sign-in.' : 'Password changed.', []);
            }

            if (function_exists('setFlash')) setFlash('Your new password has been saved.');
            redirect(BASE_URL . 'dashboard.php');
        }
    }
}

$site = get_login_site_settings($conn);
$csrfToken = login_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password | <?= h($site['site_name']) ?></title>
<?php if ($site['favicon_url'] !== ''): ?>
<link rel="icon" href="<?= h($site['favicon_url']) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= h(BASE_URL . 'assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.auth-title { margin: 0 0 6px; font-size: 20px; font-weight: 800; color: var(--primary); }
.auth-intro { margin: 0 0 20px; color: var(--text-muted); font-size: 13px; line-height: 1.5; }
.auth-back { display: inline-flex; align-items: center; gap: 6px; margin-top: 16px; color: var(--primary); font-size: 13px; font-weight: 600; text-decoration: none; }
.auth-back:hover { text-decoration: underline; }
.auth-rules { margin: -6px 0 16px; padding-left: 18px; color: var(--text-muted); font-size: 12px; text-align: left; line-height: 1.6; }
.auth-errors { margin: 6px 0 0; padding-left: 18px; }
.password-field-wrap { position: relative; }
.password-field-input { padding-right: 46px !important; }
.password-toggle-btn { position: absolute; top: 50%; right: 10px; transform: translateY(-50%); width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 8px; background: transparent; color: #6b7280; cursor: pointer; }
.password-toggle-btn:hover, .password-toggle-btn:focus-visible { color: var(--primary); outline: none; }
</style>
<?= site_theme_css() ?></head>
<body>
<div class="login-page">
  <div class="login-box">
    <div class="login-logo">
      <?php if ($site['logo_url'] !== ''): ?>
        <img src="<?= h($site['logo_url']) ?>" alt="<?= h($site['site_name']) ?> Logo" class="login-logo-img">
      <?php else: ?>
        <div class="login-logo-fallback"><i class="fa fa-church"></i></div>
      <?php endif; ?>
    </div>

    <h1 class="auth-title"><?= $forced ? 'Choose your own password' : 'Change your password' ?></h1>
    <p class="auth-intro">
      <?php if ($forced): ?>
        You signed in with a temporary password. Choose a new password to continue, <strong><?= h((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')) ?></strong>.
      <?php else: ?>
        Enter your current password and the new password you want to use.
      <?php endif; ?>
    </p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;text-align:left;">
        <?= h($error) ?>
        <?php if ($errors): ?>
          <ul class="auth-errors"><?php foreach ($errors as $message): ?><li><?= h($message) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="text" name="username" value="<?= h((string)($_SESSION['username'] ?? '')) ?>" autocomplete="username" hidden>

      <?php if (!$forced): ?>
      <div class="form-group">
        <label for="currentPassword"><i class="fa fa-lock"></i> Current Password</label>
        <div class="password-field-wrap">
          <input type="password" id="currentPassword" name="current_password" class="form-control password-field-input" required autocomplete="current-password">
          <button type="button" class="password-toggle-btn" data-toggle-password="currentPassword" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
        </div>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label for="newPassword"><i class="fa fa-key"></i> New Password</label>
        <div class="password-field-wrap">
          <input type="password" id="newPassword" name="new_password" class="form-control password-field-input" minlength="8" required autofocus autocomplete="new-password">
          <button type="button" class="password-toggle-btn" data-toggle-password="newPassword" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
        </div>
      </div>

      <ul class="auth-rules">
        <li>At least 8 characters</li>
        <li>An uppercase letter, a lowercase letter and a number</li>
      </ul>

      <div class="form-group">
        <label for="confirmPassword"><i class="fa fa-key"></i> Confirm New Password</label>
        <div class="password-field-wrap">
          <input type="password" id="confirmPassword" name="confirm_password" class="form-control password-field-input" minlength="8" required autocomplete="new-password">
          <button type="button" class="password-toggle-btn" data-toggle-password="confirmPassword" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
        </div>
      </div>

      <button type="submit" class="btn-login"><i class="fa fa-save"></i> Save New Password</button>
    </form>

    <?php if ($forced): ?>
      <a class="auth-back" href="<?= h(BASE_URL . 'logout.php') ?>"><i class="fa fa-sign-out-alt"></i> Sign out instead</a>
    <?php else: ?>
      <a class="auth-back" href="<?= h(BASE_URL . 'dashboard.php') ?>"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>
    <?php endif; ?>
  </div>
</div>
<script>
document.querySelectorAll('[data-toggle-password]').forEach(function (toggle) {
  const input = document.getElementById(toggle.dataset.togglePassword);
  if (!input) return;
  toggle.addEventListener('click', function () {
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    const icon = toggle.querySelector('i');
    if (icon) { icon.classList.toggle('fa-eye', showing); icon.classList.toggle('fa-eye-slash', !showing); }
    toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
    input.focus();
  });
});
</script>
</body>
</html>
