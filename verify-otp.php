<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/login.php';

if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . 'dashboard.php');
}

$pendingLogin = $_SESSION['pending_login'] ?? null;

if (!is_array($pendingLogin) || empty($pendingLogin['user'])) {
    $_SESSION['login_error'] = 'No pending verification was found. Please sign in again.';
    redirect(BASE_URL);
}

$error = '';
$success = (string)($_SESSION['login_success'] ?? '');
unset($_SESSION['login_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!login_validate_csrf($_POST)) {
        $error = 'Your session token is invalid or expired. Please refresh the page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? 'verify');

        if ($action === 'verify') {
            $result = verify_login_code($conn, $_POST);

            if (!empty($result['success'])) {
                redirect(BASE_URL . 'dashboard.php');
            }

            if (!empty($result['restart'])) {
                $_SESSION['login_error'] = (string)($result['message'] ?? 'Verification expired. Please sign in again.');
                redirect(BASE_URL);
            }

            $error = (string)($result['message'] ?? 'Verification failed.');
        } elseif ($action === 'resend') {
            $result = resend_login_code($conn);

            if (!empty($result['success'])) {
                $success = (string)($result['message'] ?? 'A new verification code was sent.');
            } else {
                if (!empty($result['restart'])) {
                    $_SESSION['login_error'] = (string)($result['message'] ?? 'Verification expired. Please sign in again.');
                    redirect(BASE_URL);
                }

                $error = (string)($result['message'] ?? 'Unable to resend the verification code.');
            }
        } elseif ($action === 'cancel') {
            login_clear_pending_verification();
            $_SESSION['login_success'] = 'Verification cancelled. You can sign in again.';
            redirect(BASE_URL);
        }
    }
}

$pendingLogin = $_SESSION['pending_login'] ?? null;
if (!is_array($pendingLogin) || empty($pendingLogin['user'])) {
    $_SESSION['login_error'] = 'Your verification session has ended. Please sign in again.';
    redirect(BASE_URL);
}

$expiresAt = (int)($pendingLogin['expires_at'] ?? 0);
if ($expiresAt > 0 && $expiresAt < time()) {
    login_clear_pending_verification();
    $_SESSION['login_error'] = 'The verification code has expired. Please sign in again to request a new code.';
    redirect(BASE_URL);
}

$user = (array)$pendingLogin['user'];
$email = (string)($user['email'] ?? '');
$maskedEmail = login_mask_email($email);
$site = get_login_site_settings($conn);
$csrfToken = login_csrf_token();
$remainingAttempts = max(0, 5 - (int)($pendingLogin['attempts'] ?? 0));
$secondsRemaining = max(0, $expiresAt - time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Verify Login | <?= h($site['site_name']) ?></title>

<?php if ($site['favicon_url'] !== ''): ?>
<link rel="icon" href="<?= h($site['favicon_url']) ?>">
<link rel="shortcut icon" href="<?= h($site['favicon_url']) ?>">
<?php endif; ?>

<link rel="stylesheet" href="<?= h(BASE_URL . 'assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
.otp-heading {
  text-align: center;
  margin: 0 0 8px;
  font-size: 22px;
  font-weight: 700;
}
.otp-help {
  margin: 0 0 20px;
  color: #6b7280;
  font-size: 13px;
  line-height: 1.6;
  text-align: center;
}
.otp-help strong { color: #222; }
.otp-input {
  text-align: center;
  font-size: 25px;
  font-weight: 700;
  letter-spacing: 9px;
}
.otp-meta {
  display: flex;
  justify-content: center;
  gap: 14px;
  flex-wrap: wrap;
  margin: 10px 0 18px;
  color: #6b7280;
  font-size: 12px;
}
.otp-actions {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 16px;
}
.otp-actions form { margin: 0; }
.otp-link-button {
  border: 0;
  background: transparent;
  padding: 5px 7px;
  cursor: pointer;
  color: var(--primary);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
}
.otp-link-button:hover { text-decoration: underline; }
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

    <h2 class="otp-heading">Verify Your Email</h2>
    <p class="otp-help">
      We sent a 6-digit verification code to<br>
      <strong><?= h($maskedEmail) ?></strong>.<br>
      Enter the code below to continue to your dashboard.
    </p>

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
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

      <div class="form-group">
        <label><i class="fa fa-shield-halved"></i> Verification Code</label>
        <input
          type="text"
          name="verification_code"
          class="form-control otp-input"
          placeholder="000000"
          inputmode="numeric"
          pattern="[0-9]{6}"
          maxlength="6"
          required
          autofocus
          autocomplete="one-time-code"
        >
      </div>

      <div class="otp-meta">
        <span><i class="fa-regular fa-clock"></i> <span id="otpTimer"><?= h((string)$secondsRemaining) ?></span>s remaining</span>
        <span><i class="fa fa-key"></i> <?= h((string)$remainingAttempts) ?> attempt(s) remaining</span>
      </div>

      <button type="submit" class="btn-login">
        <i class="fa fa-circle-check"></i> Verify & Continue
      </button>
    </form>

    <div class="otp-actions">
      <form method="POST">
        <input type="hidden" name="action" value="resend">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="otp-link-button">
          <i class="fa fa-rotate-right"></i> Resend Code
        </button>
      </form>

      <form method="POST">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <button type="submit" class="otp-link-button">
          <i class="fa fa-arrow-left"></i> Back to Login
        </button>
      </form>
    </div>

    <div class="login-footer">
      <p style="margin-top:12px;">
        For your security, never share your verification code with anyone.
      </p>
    </div>

  </div>
</div>

<script>
(function () {
  const timer = document.getElementById('otpTimer');
  if (!timer) return;

  let seconds = parseInt(timer.textContent || '0', 10);
  const interval = setInterval(function () {
    seconds = Math.max(0, seconds - 1);
    timer.textContent = String(seconds);

    if (seconds <= 0) {
      clearInterval(interval);
      window.location.reload();
    }
  }, 1000);
})();
</script>
<script src="<?= h(BASE_URL . 'assets/js/main.js') ?>"></script>
</body>
</html>
