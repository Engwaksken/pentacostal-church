<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/password-reset.php';

if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . 'dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!login_validate_csrf($_POST)) {
        $error = 'Your session token is invalid or expired. Please refresh the page and try again.';
    } else {
        $result = password_reset_request($conn, (string)($_POST['identity'] ?? ''));
        if (!empty($result['success'])) {
            $success = (string)$result['message'];
        } else {
            $error = (string)$result['message'];
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

<title>Forgot Password | <?= h($site['site_name']) ?></title>

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

    <h1 class="auth-title">Forgot your password?</h1>
    <p class="auth-intro">Enter your username or email address and we will email you a link to set a new password.</p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;text-align:left;">
        <?= h($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="alert alert-success" style="margin-bottom:18px;text-align:left;">
        <?= h($success) ?>
      </div>
    <?php else: ?>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

        <div class="form-group">
          <label for="resetIdentity"><i class="fa fa-user"></i> Username or Email</label>
          <input
            type="text"
            id="resetIdentity"
            name="identity"
            class="form-control"
            placeholder="Enter username or email"
            value="<?= h((string)($_POST['identity'] ?? '')) ?>"
            maxlength="190"
            required
            autofocus
            autocomplete="username"
          >
        </div>

        <button type="submit" class="btn-login">
          <i class="fa fa-paper-plane"></i> Send Reset Link
        </button>
      </form>
    <?php endif; ?>

    <a class="auth-back" href="<?= h(BASE_URL) ?>"><i class="fa fa-arrow-left"></i> Back to Sign In</a>

  </div>
</div>
<script src="<?= h(BASE_URL . 'assets/js/main.js') ?>"></script>
</body>
</html>
