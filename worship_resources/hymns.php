<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/member-content-functions.php';
requireLogin();
require_permission('hymns.manage');

$pageTitle = 'Church Hymns';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';

$ready = mc_tables_ready($conn, ['church_hymns']);
$rows = $ready ? ce_query_all($conn, 'SELECT * FROM church_hymns ORDER BY hymn_book, CAST(hymn_number AS UNSIGNED), hymn_number, title') : [];
$publishedCount = count(array_filter($rows, fn($r) => $r['status'] === 'Published'));
$withLyrics = count(array_filter($rows, fn($r) => trim((string)$r['lyrics']) !== ''));
$recordFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
$fields = ['hymn_number', 'title', 'hymn_book', 'language', 'author', 'tune', 'theme', 'scripture_reference', 'lyrics', 'copyright_status', 'copyright_notice', 'study_notes', 'status'];
?>
<link rel="stylesheet" href="../assets/css/church-admin.css"><link rel="stylesheet" href="../assets/css/church-manager.css">
<div class="main-content"><?php include '../includes/topbar.php'; ?><div class="content-area">
<?php ce_show_flash(); ?>
<div class="church-page-head">
    <div><h2><i class="fas fa-music"></i> Hymns</h2><p>Hymn information members can read and study. Lyrics are optional and may only be entered when the church may reproduce them.</p></div>
    <?php if ($ready): ?><button type="button" class="btn btn-primary" id="addBtn"><i class="fas fa-plus"></i> Add Hymn</button><?php endif; ?>
</div>
<?php mc_worship_tabs('hymns'); ?>

<?php if (!$ready): ?>
<div class="alert alert-warning"><strong>Setup required.</strong> Run <code>database/member_content.sql</code> to create the hymns table.</div>
<?php else: ?>
<div class="church-stats">
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-music"></i></div><div><b><?= count($rows) ?></b><span>Total Hymns</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-eye"></i></div><div><b><?= $publishedCount ?></b><span>Published</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-align-left"></i></div><div><b><?= $withLyrics ?></b><span>With Lyrics</span></div></div>
</div>
<div class="church-card">
    <div class="page-toolbar">
        <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="recordSearch" class="form-control" placeholder="Search hymns..."></div>
        <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="refreshRecords"><i class="fas fa-rotate-right"></i> Refresh</button></div>
    </div>
    <div class="church-table-wrap"><table class="church-table">
        <thead><tr><th>No.</th><th>Title</th><th>Hymn Book</th><th>Language</th><th>Lyrics</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $published = $r['status'] === 'Published';
            $record = ['id' => (int)$r['id']];
            foreach ($fields as $f) $record[$f] = (string)($r[$f] ?? '');
        ?>
            <tr data-search="<?= ce_h(mb_strtolower(implode(' ', [$r['hymn_number'], $r['title'], $r['hymn_book'], $r['language'], $r['author'], $r['theme']]))) ?>">
                <td><b><?= ce_h($r['hymn_number'] ?: '-') ?></b></td>
                <td class="wrap"><b><?= ce_h($r['title']) ?></b><?php if ($r['author']): ?><br><small><?= ce_h($r['author']) ?></small><?php endif; ?></td>
                <td><?= ce_h($r['hymn_book'] ?: '-') ?></td>
                <td><?= ce_h($r['language'] ?: '-') ?></td>
                <td><?= trim((string)$r['lyrics']) !== '' ? ce_h($r['copyright_status']) : 'None' ?></td>
                <td><span class="church-badge <?= $published ? 'church-badge-success' : 'church-badge-warning' ?>"><?= ce_h($r['status']) ?></span></td>
                <td><div class="church-table-actions">
                    <button type="button" class="btn btn-info btn-sm js-view" title="View" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-eye"></i></button>
                    <button type="button" class="btn btn-primary btn-sm js-edit" title="Edit" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-edit"></i></button>
                    <form method="post" action="process.php" class="mc-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="resource" value="hymns"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="status" value="<?= $published ? 'Draft' : 'Published' ?>">
                        <button type="submit" class="btn btn-sm <?= $published ? 'btn-warning' : 'btn-success' ?>" title="<?= $published ? 'Unpublish' : 'Publish' ?>"><i class="fas <?= $published ? 'fa-eye-slash' : 'fa-upload' ?>"></i></button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm js-delete" title="Delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= ce_h($r['title']) ?>"><i class="fas fa-trash"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="church-empty">No hymns have been added yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="church-modal-backdrop" id="backdrop"></div>
<div class="church-modal" id="formModal"><div class="church-modal-dialog church-modal-dialog-lg"><form method="post" action="process.php" id="recordForm">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="resource" value="hymns"><input type="hidden" name="id" id="recordId">
    <div class="church-modal-header"><h3 id="formTitle">Add Hymn</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body"><div class="church-form">
        <div class="church-form-row">
            <label>Hymn Number<input name="hymn_number" id="hymn_number" maxlength="20"></label>
            <label>Title / First Line<input name="title" id="title" maxlength="200" required></label>
        </div>
        <div class="church-form-row">
            <label>Hymn Book<input name="hymn_book" id="hymn_book" maxlength="150"></label>
            <label>Language<input name="language" id="language" maxlength="80"></label>
        </div>
        <div class="church-form-row">
            <label>Author / Translator<input name="author" id="author" maxlength="180"></label>
            <label>Tune / Metre<input name="tune" id="tune" maxlength="180"></label>
        </div>
        <div class="church-form-row">
            <label>Theme / Season<input name="theme" id="theme" maxlength="120" placeholder="e.g. Advent, Easter, Communion"></label>
            <label>Scripture Reference<input name="scripture_reference" id="scripture_reference" maxlength="180"></label>
        </div>
        <label>Study Notes<textarea name="study_notes" id="study_notes" rows="5" maxlength="20000"></textarea><small class="church-form-help">Background, meaning and how the hymn is used in worship.</small></label>
        <label>Lyrics (optional)<textarea name="lyrics" id="lyrics" rows="8" maxlength="30000"></textarea>
            <small class="church-form-help">Enter lyrics only if they are in the public domain, covered by the church's licence, or used with the copyright holder's permission.</small></label>
        <div class="church-form-row">
            <label>Copyright Basis for Lyrics<select name="copyright_status" id="copyright_status"><?php foreach (MC_COPYRIGHT_STATUSES as $s): ?><option value="<?= ce_h($s) ?>"><?= ce_h($s === 'Not Entered' ? 'No lyrics entered' : $s) ?></option><?php endforeach; ?></select></label>
            <label>Copyright / Licence Notice<input name="copyright_notice" id="copyright_notice" maxlength="255" placeholder="e.g. Licence no. or 'Used by permission of …'"><small class="church-form-help">Required for Licensed or Permission Granted lyrics; shown to members.</small></label>
        </div>
        <label>Status<select name="status" id="status"><option value="Draft">Draft</option><option value="Published">Published</option></select></label>
    </div></div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Hymn</button></div>
</form></div></div>
<div class="church-modal" id="viewModal"><div class="church-modal-dialog church-modal-dialog-lg"><div class="church-modal-header"><h3>Hymn Details</h3><button type="button" class="church-modal-close js-close">&times;</button></div><div class="church-modal-body"><div class="modal-detail-grid" id="details"></div></div><div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Close</button></div></div></div>
<div class="church-modal" id="deleteModal"><div class="church-modal-dialog church-modal-dialog-sm"><form method="post" action="process.php">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="resource" value="hymns"><input type="hidden" name="id" id="deleteId">
    <div class="church-modal-header"><h3>Delete Hymn</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body">Are you sure you want to delete <strong id="deleteName"></strong>? It will also be unlinked from any Order of Service.</div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
</form></div></div>
<?php include '../includes/member-content-staff-assets.php'; ?>
<script>
mcBindTable({
    addTitle: 'Add Hymn', editTitle: 'Edit Hymn', fields: <?= json_encode($fields) ?>,
    view: r => ({'Number': r.hymn_number, 'Title': r.title, 'Hymn Book': r.hymn_book, 'Language': r.language, 'Author / Translator': r.author, 'Tune / Metre': r.tune,
        'Theme / Season': r.theme, 'Scripture': r.scripture_reference, 'Status': r.status, 'Copyright Basis': r.lyrics ? r.copyright_status : 'No lyrics entered',
        'Copyright Notice': r.copyright_notice, 'Study Notes': r.study_notes, 'Lyrics': r.lyrics})
});
</script>
</div></div><?php require_once '../includes/footer.php'; ?>
