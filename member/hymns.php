<?php
declare(strict_types=1);
$pageTitle = 'Hymns';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.worship.view');

$hasHymns = mp_table($conn, 'church_hymns');
$hymnId = (int)($_GET['id'] ?? 0);
// Members only ever see published hymns.
$hymn = ($hasHymns && $hymnId > 0) ? mp_row($conn, "SELECT * FROM church_hymns WHERE id=? AND status='Published'", 'i', [$hymnId]) : null;
$rows = ($hasHymns && !$hymn)
 ? mp_rows($conn, "SELECT id, hymn_number, title, hymn_book, language, author, theme FROM church_hymns WHERE status='Published' ORDER BY hymn_book, CAST(hymn_number AS UNSIGNED), hymn_number, title")
 : [];
$books = array_values(array_unique(array_filter(array_column($rows, 'hymn_book'))));
$languages = array_values(array_unique(array_filter(array_column($rows, 'language'))));

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<style>.hymn-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px}.hymn-meta div{padding:10px 12px;border:1px solid var(--border,#e2e6eb);border-radius:10px;background:#fafafa}.hymn-meta b{display:block;font-size:11px;text-transform:uppercase;color:var(--text-muted,#6b7280)}.hymn-section{margin-top:18px}.hymn-section h4{margin:0 0 8px;color:var(--primary,#4a1e8f)}.hymn-lyrics{white-space:pre-wrap;line-height:1.7;font-size:15px}.hymn-copyright{margin-top:10px;color:var(--text-muted,#6b7280);font-size:12px}</style>
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<?php if ($hymn): ?>
<div class="member-module-header">
  <div><h2><i class="fas fa-music"></i> <?= $hymn['hymn_number'] ? mp_h('#' . $hymn['hymn_number']) . ' ' : '' ?><?= mp_h($hymn['title']) ?></h2><p><?= mp_h($hymn['hymn_book'] ?: 'Hymns') ?></p></div>
  <div class="member-module-actions"><a class="btn btn-outline-primary" href="hymns.php"><i class="fas fa-arrow-left"></i> All Hymns</a></div>
</div>
<div class="member-module-card"><div class="member-module-body">
  <div class="hymn-meta">
    <?php foreach (['language' => 'Language', 'author' => 'Author / Translator', 'tune' => 'Tune / Metre', 'theme' => 'Theme / Season', 'scripture_reference' => 'Scripture'] as $key => $label): if (trim((string)$hymn[$key]) === '') continue; ?>
      <div><b><?= mp_h($label) ?></b><?= mp_h($hymn[$key]) ?></div>
    <?php endforeach; ?>
  </div>
  <?php if (trim((string)$hymn['study_notes']) !== ''): ?>
    <div class="hymn-section"><h4><i class="fas fa-book-open"></i> Study Notes</h4><div class="member-note-text"><?= mp_h($hymn['study_notes']) ?></div></div>
  <?php endif; ?>
  <?php if (trim((string)$hymn['lyrics']) !== '' && $hymn['copyright_status'] !== 'Not Entered'): ?>
    <div class="hymn-section"><h4><i class="fas fa-align-left"></i> Lyrics</h4><div class="hymn-lyrics"><?= mp_h($hymn['lyrics']) ?></div>
      <div class="hymn-copyright"><?= mp_h($hymn['copyright_status']) ?><?= $hymn['copyright_notice'] ? ' &middot; ' . mp_h($hymn['copyright_notice']) : '' ?></div></div>
  <?php endif; ?>
</div></div>
<?php else: ?>
<div class="member-module-header">
  <div><h2><i class="fas fa-music"></i> Hymns</h2><p>Read and study hymns in your free time.</p></div>
</div>
<?php if ($hymnId > 0): ?><div class="member-info-banner"><i class="fas fa-circle-info"></i> That hymn is not available.</div><?php endif; ?>
<div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" data-member-search placeholder="Search hymns by number, title or author..." aria-label="Search hymns"></div><div class="page-toolbar-filters">
  <select data-member-filter="book" aria-label="Hymn book"><option value="">All Hymn Books</option><?php foreach ($books as $b): ?><option><?= mp_h($b) ?></option><?php endforeach; ?></select>
  <select data-member-filter="language" aria-label="Language"><option value="">All Languages</option><?php foreach ($languages as $l): ?><option><?= mp_h($l) ?></option><?php endforeach; ?></select>
</div><div class="page-toolbar-actions"><a class="btn btn-outline-primary page-toolbar-refresh" href="hymns.php"><i class="fas fa-rotate-right"></i> Refresh</a></div></div>
<div class="member-module-card">
  <div class="member-module-card-head"><h3><i class="fas fa-music"></i> Hymns</h3><span class="member-module-count"><?= count($rows) ?> hymns</span></div>
  <div class="member-module-body"><div class="member-module-table-wrap">
    <table class="member-module-table"><thead><tr><th>No.</th><th>Title</th><th>Hymn Book</th><th>Language</th><th>Theme</th><th>Action</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="member-empty"><i class="fas fa-music"></i>No hymns have been published yet.</div></td></tr><?php else: foreach ($rows as $r): ?>
      <tr data-member-row data-book="<?= mp_h($r['hymn_book']) ?>" data-language="<?= mp_h($r['language']) ?>">
        <td><strong><?= mp_h($r['hymn_number'] ?: '-') ?></strong></td>
        <td><strong><?= mp_h($r['title']) ?></strong><?php if ($r['author']): ?><br><small><?= mp_h($r['author']) ?></small><?php endif; ?></td>
        <td><?= mp_h($r['hymn_book'] ?: '-') ?></td>
        <td><?= mp_h($r['language'] ?: '-') ?></td>
        <td><?= mp_h($r['theme'] ?: '-') ?></td>
        <td><a class="btn btn-info btn-sm btn-icon" href="hymns.php?id=<?= (int)$r['id'] ?>" title="Read"><i class="fas fa-book-open"></i></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody></table>
  </div></div>
</div>
<?php endif; ?>
</div>
</div>
<script src="<?= mp_h(BASE_URL . 'assets/js/member-portal.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
