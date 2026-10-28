<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/login.php';

if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . 'dashboard.php');
}

$error = (string)($_SESSION['login_error'] ?? '');
$success = (string)($_SESSION['login_success'] ?? '');
unset($_SESSION['login_error'], $_SESSION['login_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!login_validate_csrf($_POST)) {
        $error = 'Your session token is invalid or expired. Please refresh the page and try again.';
    } else {
        $result = process_login($conn, $_POST);

        if (!empty($result['success'])) {
            if (!empty($result['requires_code'])) {
                $_SESSION['login_success'] = (string)($result['message'] ?? 'Verification code sent.');
                redirect(BASE_URL . 'verify-otp.php');
            }

            redirect(BASE_URL . 'dashboard.php');
        }

        $error = (string)($result['message'] ?? 'Unable to sign in.');
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

<title>Login | <?= h($site['site_name']) ?></title>

<?php if ($site['favicon_url'] !== ''): ?>
<link rel="icon" href="<?= h($site['favicon_url']) ?>">
<link rel="shortcut icon" href="<?= h($site['favicon_url']) ?>">
<?php endif; ?>

<link rel="stylesheet" href="<?= h(BASE_URL . 'assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
.password-field-wrap {
  position: relative;
}
.password-field-input {
  padding-right: 46px !important;
}
.password-toggle-btn {
  position: absolute;
  top: 50%;
  right: 10px;
  transform: translateY(-50%);
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #6b7280;
  cursor: pointer;
  z-index: 2;
}
.password-toggle-btn:hover,
.password-toggle-btn:focus-visible {
  color: var(--primary);
  background: rgba(5, 100, 91, 0.08);
  outline: none;
}
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

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;text-align:left;">
        <?= h($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="alert alert-success" style="margin-bottom:18px;text-align:left;">
        <?= h($success) ?>
      </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

      <div class="form-group">
        <label><i class="fa fa-user"></i> Username or Email</label>
        <input
          type="text"
          name="identity"
          class="form-control"
          placeholder="Enter username or email"
          value="<?= h((string)($_POST['identity'] ?? '')) ?>"
          required
          autofocus
          autocomplete="username"
        >
      </div>

      <div class="form-group">
        <label for="loginPassword"><i class="fa fa-lock"></i> Password</label>
        <div class="password-field-wrap">
          <input
            type="password"
            id="loginPassword"
            name="password"
            class="form-control password-field-input"
            placeholder="Enter password"
            required
            autocomplete="current-password"
          >
          <button
            type="button"
            class="password-toggle-btn"
            id="passwordToggle"
            aria-label="Show password"
            aria-pressed="false"
          >
            <i class="fa-regular fa-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login">
        <i class="fa fa-sign-in-alt"></i> Sign In
      </button>
    </form>

    <div class="login-footer">
      <p style="margin-top:6px;">
        "For where two or three gather in my name, there am I with them." - Matt 18:20
      </p>
    </div>

  </div>
</div>


<script>
(function () {
  const password = document.getElementById('loginPassword');
  const toggle = document.getElementById('passwordToggle');

  if (!password || !toggle) return;

  toggle.addEventListener('click', function () {
    const showing = password.type === 'text';
    password.type = showing ? 'password' : 'text';

    const icon = toggle.querySelector('i');
    if (icon) {
      icon.classList.toggle('fa-eye', showing);
      icon.classList.toggle('fa-eye-slash', !showing);
    }

    toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
    password.focus();
  });
})();
</script>
<script src="<?= h(BASE_URL . 'assets/js/main.js') ?>"></script>
</body>
</html>
