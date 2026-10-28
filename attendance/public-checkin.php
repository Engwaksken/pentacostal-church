<?php
require_once '../config/config.php';
require_once __DIR__ . '/checkin_token_helper.php';
// Intentionally NOT calling requireLogin() - this page is public by design.

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function pc_esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$isAjax = isset($_GET['ajax']);
$token = $_GET['t'] ?? $_POST['t'] ?? '';
$session = checkin_verify_token($token);

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    function pc_respond(bool $success, array $data = [], string $message = ''): never {
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
        exit;
    }

    if (!$session) {
        pc_respond(false, [], 'This attendance link is invalid or has expired.');
    }

    $ajax = $_GET['ajax'];

    if ($ajax === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            pc_respond(true, ['items' => []]);
        }

        $like = '%' . $q . '%';
        $stmt = $conn->prepare("SELECT id, full_name, member_no, department, phone
                                 FROM members
                                 WHERE COALESCE(status,'Active')='Active'
                                   AND (full_name LIKE ? OR member_no LIKE ? OR phone LIKE ?)
                                 ORDER BY full_name ASC
                                 LIMIT 8");
        if (!$stmt) {
            pc_respond(false, [], 'Search failed.');
        }

        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $phone = (string)($row['phone'] ?? '');
            $maskedPhone = $phone !== '' ? str_repeat('-', max(0, strlen($phone) - 3)) . substr($phone, -3) : '';

            $items[] = [
                'id' => (int)$row['id'],
                'full_name' => $row['full_name'],
                'member_no' => $row['member_no'],
                'department' => $row['department'],
                'phone_masked' => $maskedPhone,
            ];
        }
        $stmt->close();

        pc_respond(true, ['items' => $items]);
    }

    if ($ajax === 'checkin') {
        $memberId = (int)($_GET['member_id'] ?? $_POST['member_id'] ?? 0);
        if ($memberId <= 0) {
            pc_respond(false, [], 'Please select your name from the list.');
        }

        $mStmt = $conn->prepare("SELECT id, full_name FROM members WHERE id=? AND COALESCE(status,'Active')='Active'");
        $mStmt->bind_param('i', $memberId);
        $mStmt->execute();
        $member = $mStmt->get_result()->fetch_assoc();
        $mStmt->close();

        if (!$member) {
            pc_respond(false, [], 'Member not found or inactive.');
        }

        $date = $session['date'];
        $type = $session['type'];

        $checkStmt = $conn->prepare("SELECT id, status FROM attendance WHERE member_id=? AND service_date=? AND service_type=?");
        $checkStmt->bind_param('iss', $memberId, $date, $type);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            if (($existing['status'] ?? '') === 'Present') {
                pc_respond(true, ['already' => true, 'name' => $member['full_name']], "You're already checked in for this service. See you inside!");
            }

            $upd = $conn->prepare("UPDATE attendance SET status='Present' WHERE id=?");
            $upd->bind_param('i', $existing['id']);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $conn->prepare("INSERT INTO attendance (member_id, service_date, service_type, status) VALUES (?,?,?,'Present')");
            $ins->bind_param('iss', $memberId, $date, $type);
            $ins->execute();
            $ins->close();
        }

        pc_respond(true, ['already' => false, 'name' => $member['full_name']], 'You are checked in. Thank you!');
    }

    pc_respond(false, [], 'Unknown request.');
}

// ---- Regular page load ----
$invalid = !$session;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Service Check-In</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:linear-gradient(160deg,var(--primary),var(--primary-lt));min-height:100vh;display:flex;align-items:center;justify-content:center;padding:18px}
  .pc-card{background:#fff;border-radius:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);max-width:420px;width:100%;overflow:hidden}
  .pc-header{padding:26px 24px 18px;text-align:center;background:linear-gradient(160deg,var(--primary),var(--primary-lt));color:#fff}
  .pc-header i{font-size:34px;margin-bottom:8px}
  .pc-header h1{font-size:19px;margin:6px 0 2px}
  .pc-header p{margin:0;opacity:.9;font-size:14px}
  .pc-body{padding:22px 24px 26px}
  .pc-search{position:relative;margin-bottom:12px}
  .pc-search input{width:100%;padding:13px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:15px}
  .pc-search input:focus{outline:none;border-color:var(--primary)}
  .pc-results{display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto}
  .pc-result{border:1.5px solid #e2e8f0;border-radius:12px;padding:11px 13px;cursor:pointer;transition:.15s}
  .pc-result:hover{border-color:var(--primary);background:var(--primary-fade)}
  .pc-result strong{display:block;font-size:15px;color:var(--primary-dk)}
  .pc-result small{color:#64748b}
  .pc-empty{color:#94a3b8;font-size:14px;text-align:center;padding:14px 0}
  .pc-status{display:none;text-align:center;padding:26px 6px}
  .pc-status.show{display:block}
  .pc-status i{font-size:46px}
  .pc-status.success i{color:#16a34a}
  .pc-status.info i{color:#2563eb}
  .pc-status h3{margin:12px 0 4px}
  .pc-status p{color:#64748b;margin:0}
  .pc-error{background:#fee2e2;color:#991b1b;border-radius:12px;padding:14px;text-align:center;font-size:14px}
  .pc-footer{text-align:center;font-size:12px;color:#94a3b8;padding:0 24px 20px}
  .pc-hidden{display:none}
</style>
<?= site_theme_css() ?></head>
<body>
<div class="pc-card">

<?php if ($invalid): ?>
  <div class="pc-header">
    <i class="fas fa-triangle-exclamation"></i>
    <h1>Link Invalid or Expired</h1>
  </div>
  <div class="pc-body">
    <div class="pc-error">This check-in link is no longer valid. Please ask the usher/admin for a fresh link or QR code.</div>
  </div>
<?php else: ?>
  <div class="pc-header">
    <i class="fas fa-clipboard-check"></i>
    <h1><?= pc_esc($session['type']) ?></h1>
    <p><?= pc_esc(date('l, d F Y', strtotime($session['date']))) ?></p>
  </div>

  <div class="pc-body">
    <div id="pcForm">
      <div class="pc-search">
        <input type="text" id="pcSearch" placeholder="Type your name, member no. or phone...">
      </div>
      <div id="pcResults" class="pc-results"></div>
    </div>

    <div id="pcStatus" class="pc-status">
      <i class="fas fa-circle-check"></i>
      <h3 id="pcStatusTitle"></h3>
      <p id="pcStatusMsg"></p>
    </div>
  </div>

  <div class="pc-footer">Powered by your church attendance system</div>
<?php endif; ?>

</div>

<?php if (!$invalid): ?>
<script>
(function(){
  'use strict';
  const token = <?= json_encode($token) ?>;
  const qs = s => document.querySelector(s);
  const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  async function request(params){
    const url = 'public-checkin.php?' + params + '&t=' + encodeURIComponent(token);
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return res.json();
  }

  let debounceTimer = null;
  qs('#pcSearch').addEventListener('input', function(){
    clearTimeout(debounceTimer);
    const q = this.value.trim();
    debounceTimer = setTimeout(() => runSearch(q), 250);
  });

  async function runSearch(q){
    const box = qs('#pcResults');
    if (q.length < 2) { box.innerHTML = ''; return; }

    box.innerHTML = '<div class="pc-empty">Searching...</div>';
    try {
      const data = await request('ajax=search&q=' + encodeURIComponent(q));
      if (!data.success) { box.innerHTML = '<div class="pc-empty">' + esc(data.message) + '</div>'; return; }
      if (!data.items.length) { box.innerHTML = '<div class="pc-empty">No matching member found.</div>'; return; }

      box.innerHTML = data.items.map(m => `<div class="pc-result" data-id="${m.id}">
        <strong>${esc(m.full_name)}</strong>
        <small>${esc(m.member_no || '-')} - ${esc(m.department || 'No department')}${m.phone_masked ? ' - ' + esc(m.phone_masked) : ''}</small>
      </div>`).join('');
    } catch (e) {
      box.innerHTML = '<div class="pc-empty">Something went wrong. Please try again.</div>';
    }
  }

  qs('#pcResults').addEventListener('click', function(e){
    const item = e.target.closest('.pc-result');
    if (!item) return;
    checkin(item.dataset.id);
  });

  async function checkin(memberId){
    try {
      const data = await request('ajax=checkin&member_id=' + encodeURIComponent(memberId));
      qs('#pcForm').style.display = 'none';
      const statusBox = qs('#pcStatus');
      statusBox.classList.add('show', data.already ? 'info' : 'success');
      statusBox.querySelector('i').className = 'fas ' + (data.already ? 'fa-circle-info' : 'fa-circle-check');
      qs('#pcStatusTitle').textContent = data.already ? 'Already Checked In' : 'You\'re Checked In!';
      qs('#pcStatusMsg').textContent = data.message || '';
    } catch (e) {
      alert('Something went wrong. Please try again.');
    }
  }
})();
</script>
<?php endif; ?>
</body>
</html>