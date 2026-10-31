<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/member-content-functions.php';
requireLogin();
require_permission('order_of_service.manage');

$pageTitle = 'Service Program';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';

$ready = mc_tables_ready($conn, ['service_orders', 'service_order_items', 'church_hymns']);
$rows = $items = $hymns = [];
if ($ready) {
    $rows = ce_query_all($conn, 'SELECT * FROM service_orders ORDER BY service_date DESC, service_time DESC, id DESC');
    foreach (ce_query_all($conn, 'SELECT * FROM service_order_items ORDER BY service_order_id, sort_order, id') as $item) {
        $items[(int)$item['service_order_id']][] = ['title' => $item['item_title'], 'led_by' => (string)$item['led_by'], 'details' => (string)$item['details'], 'hymn_id' => (string)($item['hymn_id'] ?? '')];
    }
    $hymns = ce_query_all($conn, 'SELECT id, hymn_number, title, hymn_book FROM church_hymns ORDER BY hymn_book, CAST(hymn_number AS UNSIGNED), hymn_number, title');
}
$hymnLabels = [];
foreach ($hymns as $h) $hymnLabels[(int)$h['id']] = trim(($h['hymn_number'] ? '#' . $h['hymn_number'] . ' ' : '') . $h['title'] . ($h['hymn_book'] ? ' (' . $h['hymn_book'] . ')' : ''));
$publishedCount = count(array_filter($rows, fn($r) => $r['status'] === 'Published'));
$upcomingCount = count(array_filter($rows, fn($r) => $r['service_date'] >= date('Y-m-d')));
$recordFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<link rel="stylesheet" href="../assets/css/church-admin.css"><link rel="stylesheet" href="../assets/css/church-manager.css">
<style>
.sos-items{display:grid;gap:10px}.sos-item{display:grid;grid-template-columns:28px 1fr auto;gap:8px;align-items:start;padding:10px;border:1px solid var(--border,#ddd);border-radius:10px;background:#faf9fd}
.sos-item-no{font-weight:700;color:var(--primary,#4a1e8f);padding-top:9px}.sos-item-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.sos-item-fields .full{grid-column:1/-1}
.sos-item-tools{display:flex;flex-direction:column;gap:4px}@media(max-width:767px){.sos-item-fields{grid-template-columns:1fr}}
</style>
<div class="main-content"><?php include '../includes/topbar.php'; ?><div class="content-area">
<?php ce_show_flash(); ?>
<div class="church-page-head">
    <div><h2><i class="fas fa-list-ol"></i> Service Program</h2><p>Publish the service program so members can follow along. Members see published programs only.</p></div>
    <?php if ($ready): ?><button type="button" class="btn btn-primary" id="addBtn"><i class="fas fa-plus"></i> Add Service Program</button><?php endif; ?>
</div>
<?php mc_worship_tabs('order_of_service'); ?>

<?php if (!$ready): ?>
<div class="alert alert-warning"><strong>Setup required.</strong> Run <code>database/member_content.sql</code> to create the Service Program and song tables.</div>
<?php else: ?>
<div class="church-stats">
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-list"></i></div><div><b><?= count($rows) ?></b><span>Total Programs</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-eye"></i></div><div><b><?= $publishedCount ?></b><span>Published</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-calendar"></i></div><div><b><?= $upcomingCount ?></b><span>Today &amp; Upcoming</span></div></div>
</div>
<div class="church-card">
    <div class="page-toolbar">
        <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="recordSearch" class="form-control" placeholder="Search service programs..."></div>
        <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="refreshRecords"><i class="fas fa-rotate-right"></i> Refresh</button></div>
    </div>
    <div class="church-table-wrap"><table class="church-table">
        <thead><tr><th>Date</th><th>Service</th><th>Items</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $published = $r['status'] === 'Published';
            $orderItems = $items[(int)$r['id']] ?? [];
            $record = ['id' => (int)$r['id'], 'title' => $r['title'], 'service_type' => (string)$r['service_type'], 'service_date' => $r['service_date'], 'service_time' => $r['service_time'] ? substr($r['service_time'], 0, 5) : '', 'notes' => (string)$r['notes'], 'status' => $r['status'], 'items' => $orderItems];
        ?>
            <tr data-search="<?= ce_h(mb_strtolower($r['title'] . ' ' . $r['service_type'] . ' ' . $r['service_date'] . ' ' . implode(' ', array_column($orderItems, 'title')))) ?>">
                <td><b><?= ce_h(mc_format_date($r['service_date'])) ?></b><?php if ($r['service_time']): ?><br><small><?= ce_h(substr($r['service_time'], 0, 5)) ?></small><?php endif; ?></td>
                <td class="wrap"><b><?= ce_h($r['title']) ?></b><?php if ($r['service_type']): ?><br><small><?= ce_h($r['service_type']) ?></small><?php endif; ?></td>
                <td><?= count($orderItems) ?></td>
                <td><span class="church-badge <?= $published ? 'church-badge-success' : 'church-badge-warning' ?>"><?= ce_h($r['status']) ?></span></td>
                <td><div class="church-table-actions">
                    <button type="button" class="btn btn-info btn-sm js-view" title="View" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-eye"></i></button>
                    <button type="button" class="btn btn-primary btn-sm js-edit" title="Edit" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-edit"></i></button>
                    <form method="post" action="process.php" class="mc-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="resource" value="order_of_service"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="status" value="<?= $published ? 'Draft' : 'Published' ?>">
                        <button type="submit" class="btn btn-sm <?= $published ? 'btn-warning' : 'btn-success' ?>" title="<?= $published ? 'Unpublish' : 'Publish' ?>"><i class="fas <?= $published ? 'fa-eye-slash' : 'fa-upload' ?>"></i></button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm js-delete" title="Delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= ce_h($r['title'] . ' (' . mc_format_date($r['service_date']) . ')') ?>"><i class="fas fa-trash"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="church-empty">No service programs have been added yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="church-modal-backdrop" id="backdrop"></div>
<div class="church-modal" id="formModal"><div class="church-modal-dialog church-modal-dialog-lg"><form method="post" action="process.php" id="recordForm">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="resource" value="order_of_service"><input type="hidden" name="id" id="recordId">
    <div class="church-modal-header"><h3 id="formTitle">Add Service Program</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body"><div class="church-form">
        <div class="church-form-row">
            <label>Title<input name="title" id="title" maxlength="200" placeholder="e.g. Sunday Worship Service" required></label>
            <label>Service Type<input name="service_type" id="service_type" maxlength="120" placeholder="e.g. Praise &amp; Worship, Revival, Communion"></label>
        </div>
        <div class="church-form-row">
            <label>Service Date<input type="date" name="service_date" id="service_date" required></label>
            <label>Start Time<input type="time" name="service_time" id="service_time"></label>
        </div>
        <label>Notes for Members<textarea name="notes" id="notes" rows="3" maxlength="5000"></textarea></label>
        <div>
            <b>Service Items</b><small class="church-form-help">List each part of the service in order. Give readings by reference; link a song so members can open its study page.</small>
            <div class="sos-items" id="itemsBox"></div>
            <button type="button" class="btn btn-sm btn-outline mt-2" id="addItem"><i class="fas fa-plus"></i> Add Item</button>
        </div>
        <label>Status<select name="status" id="status"><option value="Draft">Draft</option><option value="Published">Published</option></select></label>
    </div></div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Service Program</button></div>
</form></div></div>
<template id="itemTemplate">
    <div class="sos-item">
        <span class="sos-item-no"></span>
        <div class="sos-item-fields">
            <input name="item_title[]" maxlength="200" placeholder="Item, e.g. Praise &amp; Worship" required>
            <input name="item_led_by[]" maxlength="180" placeholder="Led by (optional)">
            <select name="item_hymn_id[]" class="full"><option value="">No linked song</option><?php foreach ($hymnLabels as $id => $label): ?><option value="<?= (int)$id ?>"><?= ce_h($label) ?></option><?php endforeach; ?></select>
            <textarea name="item_details[]" rows="2" maxlength="2000" class="full" placeholder="Details, e.g. reading reference or response (optional)"></textarea>
        </div>
        <div class="sos-item-tools">
            <button type="button" class="btn btn-sm btn-outline js-up" title="Move up"><i class="fas fa-arrow-up"></i></button>
            <button type="button" class="btn btn-sm btn-outline js-down" title="Move down"><i class="fas fa-arrow-down"></i></button>
            <button type="button" class="btn btn-sm btn-danger js-remove" title="Remove"><i class="fas fa-times"></i></button>
        </div>
    </div>
</template>
<div class="church-modal" id="viewModal"><div class="church-modal-dialog church-modal-dialog-lg"><div class="church-modal-header"><h3>Service Program</h3><button type="button" class="church-modal-close js-close">&times;</button></div><div class="church-modal-body"><div class="modal-detail-grid" id="details"></div></div><div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Close</button></div></div></div>
<div class="church-modal" id="deleteModal"><div class="church-modal-dialog church-modal-dialog-sm"><form method="post" action="process.php">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="resource" value="order_of_service"><input type="hidden" name="id" id="deleteId">
    <div class="church-modal-header"><h3>Delete Service Program</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body">Are you sure you want to delete <strong id="deleteName"></strong>?</div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
</form></div></div>
<?php include '../includes/member-content-staff-assets.php'; ?>
<script>
const hymnLabels = <?= json_encode((object)$hymnLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
function renumberItems() { $$('#itemsBox .sos-item-no').forEach((el, i) => el.textContent = (i + 1) + '.'); }
function addItem(item = {}) {
    const node = $('#itemTemplate').content.firstElementChild.cloneNode(true);
    node.querySelector('[name="item_title[]"]').value = item.title || '';
    node.querySelector('[name="item_led_by[]"]').value = item.led_by || '';
    node.querySelector('[name="item_details[]"]').value = item.details || '';
    node.querySelector('[name="item_hymn_id[]"]').value = item.hymn_id || '';
    node.querySelector('.js-remove').addEventListener('click', () => { node.remove(); renumberItems(); });
    node.querySelector('.js-up').addEventListener('click', () => { if (node.previousElementSibling) node.parentNode.insertBefore(node, node.previousElementSibling); renumberItems(); });
    node.querySelector('.js-down').addEventListener('click', () => { if (node.nextElementSibling) node.parentNode.insertBefore(node.nextElementSibling, node); renumberItems(); });
    $('#itemsBox').appendChild(node);
    renumberItems();
}
$('#addItem')?.addEventListener('click', () => addItem());
mcBindTable({
    addTitle: 'Add Service Program', editTitle: 'Edit Service Program', fields: ['title', 'service_type', 'service_date', 'service_time', 'notes', 'status'],
    onReset: () => { $('#itemsBox').replaceChildren(); },
    onEdit: r => (r.items || []).forEach(addItem),
    view: r => {
        const fields = {'Title': r.title, 'Service Type': r.service_type, 'Date': r.service_date, 'Time': r.service_time, 'Status': r.status, 'Notes': r.notes};
        (r.items || []).forEach((it, i) => {
            const parts = [it.led_by ? 'Led by ' + it.led_by : '', it.hymn_id && hymnLabels[it.hymn_id] ? 'Song: ' + hymnLabels[it.hymn_id] : '', it.details].filter(Boolean);
            fields[(i + 1) + '. ' + it.title] = parts.join('\n');
        });
        return fields;
    }
});
</script>
</div></div><?php require_once '../includes/footer.php'; ?>
