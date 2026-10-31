<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/password-reset.php';

if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . 'dashboard.php');
}

// Keep the reset link out of the Referer header sent to fonts and other third-party resources.
header('Referrer-Policy: no-referrer');

$token = strtolower(trim((string)($_POST['token'] ?? $_GET['token'] ?? '')));
$error = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!login_validate_csrf($_POST)) {
        $error = 'Your session token is invalid or expired. Please refresh the page and try again.';
    } else {
        $result = password_reset_complete(
            $conn,
            $token,
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? '')
        );

        if (!empty($result['success'])) {
            $_SESSION['login_success'] = (string)$result['message'];
            redirect(BASE_URL);
        }

        $error = (string)$result['message'];
        $errors = (array)($result['errors'] ?? []);
    }
}

$reset = password_reset_find($conn, $token);
$site = get_login_site_settings($conn);
$csrfToken = login_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">

<title>Reset Password | <?= h($site['site_name']) ?></title>

<?php if ($site['favicon_url'] !== ''): ?>
<link rel="icon" href="<?= h($site['favicon_url']) ?>">
<link rel="shortcut icon" href="<?= h($site['favicon_url']) ?>">
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
        <div class="login-logo-fallback">
          <i class="fa fa-church"></i>
        </div>
      <?php endif; ?>
    </div>

    <h1 class="auth-title">Set a new password</h1>

    <?php if ($error !== '' && $reset): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;text-align:left;">
        <?= h($error) ?>
        <?php if ($errors): ?>
          <ul class="auth-errors">
            <?php foreach ($errors as $message): ?><li><?= h($message) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$reset): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;text-align:left;">
        This password reset link is invalid, has already been used or has expired. Please request a new one.
      </div>
      <a class="btn-login" style="display:block;text-decoration:none;text-align:center;" href="<?= h(BASE_URL . 'forgot-password.php') ?>">
        <i class="fa fa-paper-plane"></i> Request a New Link
      </a>
    <?php else: ?>
      <p class="auth-intro">Choose a new password for <strong><?= h((string)$reset['username']) ?></strong>.</p>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="text" name="username" value="<?= h((string)$reset['username']) ?>" autocomplete="username" hidden>

        <div class="form-group">
          <label for="newPassword"><i class="fa fa-lock"></i> New Password</label>
          <div class="password-field-wrap">
            <input type="password" id="newPassword" name="new_password" class="form-control password-field-input" placeholder="Enter new password" minlength="8" required autofocus autocomplete="new-password">
            <button type="button" class="password-toggle-btn" data-toggle-password="newPassword" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
          </div>
        </div>

        <ul class="auth-rules">
          <li>At least 8 characters</li>
          <li>An uppercase letter, a lowercase letter and a number</li>
        </ul>

        <div class="form-group">
          <label for="confirmPassword"><i class="fa fa-lock"></i> Confirm New Password</label>
          <div class="password-field-wrap">
            <input type="password" id="confirmPassword" name="confirm_password" class="form-control password-field-input" placeholder="Re-enter new password" minlength="8" required autocomplete="new-password">
            <button type="button" class="password-toggle-btn" data-toggle-password="confirmPassword" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
          </div>
        </div>

        <button type="submit" class="btn-login">
          <i class="fa fa-key"></i> Reset Password
        </button>
      </form>
    <?php endif; ?>

    <a class="auth-back" href="<?= h(BASE_URL) ?>"><i class="fa fa-arrow-left"></i> Back to Sign In</a>

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
    if (icon) {
      icon.classList.toggle('fa-eye', showing);
      icon.classList.toggle('fa-eye-slash', !showing);
    }
    toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
    input.focus();
  });
});
</script>
<script src="<?= h(BASE_URL . 'assets/js/main.js') ?>"></script>
</body>
</html>
