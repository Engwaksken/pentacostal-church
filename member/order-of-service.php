<?php
declare(strict_types=1);
$pageTitle = 'Order of Service';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.worship.view');

// Members only ever see published orders of service.
$orders = mp_table($conn, 'service_orders')
 ? mp_rows($conn, "SELECT id, title, service_type, service_date, service_time, notes FROM service_orders WHERE status='Published' ORDER BY service_date DESC, service_time DESC, id DESC")
 : [];
$selectedId = (int)($_GET['id'] ?? 0);
$order = null;
foreach ($orders as $o) if ((int)$o['id'] === $selectedId) $order = $o;
if (!$order && $orders) {
    // Default to the next service (today or later); otherwise the most recent one.
    $today = date('Y-m-d');
    $upcoming = array_filter($orders, fn($o) => $o['service_date'] >= $today);
    $order = $upcoming ? end($upcoming) : $orders[0];
}
$items = $order
 ? mp_rows($conn, "SELECT i.item_title, i.led_by, i.details, h.id AS hymn_id, h.hymn_number, h.title AS hymn_title
                   FROM service_order_items i LEFT JOIN church_hymns h ON h.id=i.hymn_id AND h.status='Published'
                   WHERE i.service_order_id=? ORDER BY i.sort_order, i.id", 'i', [(int)$order['id']])
 : [];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<style>.sos-list{list-style:none;margin:0;padding:0;counter-reset:sos}.sos-list li{counter-increment:sos;display:grid;grid-template-columns:38px 1fr;gap:10px;padding:14px 0;border-bottom:1px solid var(--border,#e2e6eb)}.sos-list li:last-child{border-bottom:0}.sos-list li::before{content:counter(sos);display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:var(--primary-fade,var(--primary-fade,#f1ebfa));color:var(--primary,#4a1e8f);font-weight:700}.sos-list h4{margin:4px 0;font-size:16px}.sos-led{color:var(--text-muted,#6b7280);font-size:12.5px}.sos-details{white-space:pre-wrap;margin-top:4px}.sos-hymn{display:inline-block;margin-top:6px}</style>
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas fa-list-ol"></i> Order of Service</h2><p>Follow the service as it happens.</p></div>
</div>
<?php if (!$order): ?>
  <div class="member-module-card"><div class="member-module-body"><div class="member-empty"><i class="fas fa-list-ol"></i>No order of service has been published yet.</div></div></div>
<?php else: ?>
<form method="get" class="page-toolbar">
  <div class="page-toolbar-filters"><select name="id" aria-label="Service" onchange="this.form.submit()">
    <?php foreach ($orders as $o): ?><option value="<?= (int)$o['id'] ?>"<?= (int)$o['id'] === (int)$order['id'] ? ' selected' : '' ?>><?= mp_h(mp_date($o['service_date']) . ' — ' . $o['title']) ?></option><?php endforeach; ?>
  </select></div>
  <div class="page-toolbar-actions"><noscript><button type="submit" class="btn btn-primary">Show</button></noscript><a class="btn btn-outline-primary page-toolbar-refresh" href="order-of-service.php"><i class="fas fa-rotate-right"></i> Next Service</a></div>
</form>
<div class="member-module-card">
  <div class="member-module-card-head">
    <h3><i class="fas fa-church"></i> <?= mp_h($order['title']) ?></h3>
    <span class="member-module-count"><?= mp_h(mp_date($order['service_date'], 'l, d M Y')) ?><?= $order['service_time'] ? ' &middot; ' . mp_h(substr($order['service_time'], 0, 5)) : '' ?></span>
  </div>
  <div class="member-module-body">
    <?php if ($order['service_type']): ?><p><strong><?= mp_h($order['service_type']) ?></strong></p><?php endif; ?>
    <?php if (trim((string)$order['notes']) !== ''): ?><div class="member-info-banner"><i class="fas fa-circle-info"></i><div class="member-note-text"><?= mp_h($order['notes']) ?></div></div><?php endif; ?>
    <?php if (!$items): ?>
      <div class="member-empty"><i class="fas fa-list"></i>The items for this service have not been added yet.</div>
    <?php else: ?>
    <ol class="sos-list">
      <?php foreach ($items as $it): ?>
      <li><div>
        <h4><?= mp_h($it['item_title']) ?></h4>
        <?php if ($it['led_by']): ?><div class="sos-led"><i class="fas fa-user"></i> <?= mp_h($it['led_by']) ?></div><?php endif; ?>
        <?php if ($it['details']): ?><div class="sos-details"><?= mp_h($it['details']) ?></div><?php endif; ?>
        <?php if ($it['hymn_id']): ?><a class="sos-hymn btn btn-sm btn-outline-primary" href="hymns.php?id=<?= (int)$it['hymn_id'] ?>"><i class="fas fa-music"></i> <?= mp_h(($it['hymn_number'] ? '#' . $it['hymn_number'] . ' ' : '') . $it['hymn_title']) ?></a><?php endif; ?>
      </div></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
