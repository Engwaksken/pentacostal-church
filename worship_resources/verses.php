<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/member-content-functions.php';
requireLogin();
require_permission('bible_verses.manage');

$pageTitle = 'Bible Verses';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';

$ready = mc_tables_ready($conn, ['bible_verses']);
$rows = $ready ? ce_query_all($conn, 'SELECT * FROM bible_verses ORDER BY display_date IS NULL, display_date DESC, id DESC') : [];
$publishedCount = count(array_filter($rows, fn($r) => $r['status'] === 'Published'));
$today = date('Y-m-d');
$recordFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<link rel="stylesheet" href="../assets/css/church-admin.css"><link rel="stylesheet" href="../assets/css/church-manager.css">
<div class="main-content"><?php include '../includes/topbar.php'; ?><div class="content-area">
<?php ce_show_flash(); ?>
<div class="church-page-head">
    <div><h2><i class="fas fa-book-bible"></i> Bible Verses</h2><p>Verses shown on the member welcome dashboard. A verse with a display date appears from that day; undated verses fill in when none is scheduled.</p></div>
    <?php if ($ready): ?><button type="button" class="btn btn-outline-primary" data-content-bulk="verses"><i class="fas fa-layer-group"></i> Add Multiple</button><button type="button" class="btn btn-primary" id="addBtn"><i class="fas fa-plus"></i> Add Verse</button><?php endif; ?>
</div>
<?php mc_worship_tabs('verses'); ?>

<?php if (!$ready): ?>
<div class="alert alert-warning"><strong>Setup required.</strong> Run <code>database/member_content.sql</code> to create the Bible verses table.</div>
<?php else: ?>
<div class="church-stats">
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-list"></i></div><div><b><?= count($rows) ?></b><span>Total Verses</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-eye"></i></div><div><b><?= $publishedCount ?></b><span>Published</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-calendar-day"></i></div><div><b><?= count(array_filter($rows, fn($r) => $r['display_date'] === $today && $r['status'] === 'Published')) ?></b><span>Scheduled Today</span></div></div>
</div>
<div class="church-card">
    <div class="page-toolbar">
        <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="recordSearch" class="form-control" placeholder="Search verses..."></div>
        <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="refreshRecords"><i class="fas fa-rotate-right"></i> Refresh</button></div>
    </div>
    <div class="church-table-wrap"><table class="church-table">
        <thead><tr><th>Reference</th><th>Verse</th><th>Translation</th><th>Display Date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $published = $r['status'] === 'Published';
            $record = ['id' => (int)$r['id'], 'reference' => $r['reference'], 'verse_text' => $r['verse_text'], 'translation' => $r['translation'], 'display_date' => $r['display_date'] ?? '', 'status' => $r['status']];
        ?>
            <tr data-search="<?= ce_h(mb_strtolower($r['reference'] . ' ' . $r['verse_text'] . ' ' . $r['translation'])) ?>">
                <td><b><?= ce_h($r['reference']) ?></b></td>
                <td class="wrap"><?= ce_h(mb_strimwidth($r['verse_text'], 0, 120, '…')) ?></td>
                <td><?= ce_h($r['translation']) ?></td>
                <td><?= ce_h($r['display_date'] ? mc_format_date($r['display_date']) : 'Any day') ?></td>
                <td><span class="church-badge <?= $published ? 'church-badge-success' : 'church-badge-warning' ?>"><?= ce_h($r['status']) ?></span></td>
                <td><div class="church-table-actions">
                    <button type="button" class="btn btn-info btn-sm js-view" title="View" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-eye"></i></button>
                    <button type="button" class="btn btn-primary btn-sm js-edit" title="Edit" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-edit"></i></button>
                    <form method="post" action="process.php" class="mc-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="resource" value="verses"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="status" value="<?= $published ? 'Draft' : 'Published' ?>">
                        <button type="submit" class="btn btn-sm <?= $published ? 'btn-warning' : 'btn-success' ?>" title="<?= $published ? 'Unpublish' : 'Publish' ?>"><i class="fas <?= $published ? 'fa-eye-slash' : 'fa-upload' ?>"></i></button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm js-delete" title="Delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= ce_h($r['reference']) ?>"><i class="fas fa-trash"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="church-empty">No Bible verses have been added yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="church-modal-backdrop" id="backdrop"></div>
<div class="church-modal" id="formModal"><div class="church-modal-dialog"><form method="post" action="process.php" id="recordForm">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="resource" value="verses"><input type="hidden" name="id" id="recordId">
    <div class="church-modal-header"><h3 id="formTitle">Add Verse</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body"><div class="church-form">
        <div class="church-form-row">
            <label>Reference<input name="reference" id="reference" maxlength="120" placeholder="e.g. Psalm 23:1" required></label>
            <label>Translation<input name="translation" id="translation" maxlength="80" required></label>
        </div>
        <label>Verse Text<textarea name="verse_text" id="verse_text" rows="5" maxlength="2000" required></textarea>
            <small class="church-form-help">Only enter text from a translation the church may reproduce, such as a public-domain translation or one whose publisher permits this use, and name it in Translation.</small></label>
        <div class="church-form-row">
            <label>Display Date<input type="date" name="display_date" id="display_date"><small class="church-form-help">Leave empty to show on any day.</small></label>
            <label>Status<select name="status" id="status"><option value="Draft">Draft</option><option value="Published">Published</option></select></label>
        </div>
    </div></div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Verse</button></div>
</form></div></div>
<div class="church-modal" id="viewModal"><div class="church-modal-dialog"><div class="church-modal-header"><h3>Verse Details</h3><button type="button" class="church-modal-close js-close">&times;</button></div><div class="church-modal-body"><div class="modal-detail-grid" id="details"></div></div><div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Close</button></div></div></div>
<div class="church-modal" id="deleteModal"><div class="church-modal-dialog church-modal-dialog-sm"><form method="post" action="process.php">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="resource" value="verses"><input type="hidden" name="id" id="deleteId">
    <div class="church-modal-header"><h3>Delete Verse</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body">Are you sure you want to delete <strong id="deleteName"></strong>?</div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
</form></div></div>
<?php include '../includes/member-content-staff-assets.php'; ?>
<script>
mcBindTable({
    addTitle: 'Add Verse', editTitle: 'Edit Verse', fields: ['reference', 'verse_text', 'translation', 'display_date', 'status'],
    view: r => ({'Reference': r.reference, 'Translation': r.translation, 'Display Date': r.display_date || 'Any day', 'Status': r.status, 'Verse': r.verse_text})
});
</script>
</div></div><?php require_once '../includes/footer.php'; ?>
