<?php
declare(strict_types=1);

$pageTitle = 'My Notebook';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_church_engagement.php';
require_permission('member.profile.view');
ce_install($conn);
$memberId = current_member_id();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ce_check_csrf();
        $action = (string)($_POST['action'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'delete') {
            $stmt = $conn->prepare('DELETE FROM member_notebook WHERE id=? AND member_id=?');
            $stmt->bind_param('ii', $id, $memberId);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            ce_redirect('notebook.php', $deleted ? 'Note deleted.' : 'Note was not found.');
        }

        if ($action !== 'save') throw new RuntimeException('Invalid notebook action.');
        $title = trim((string)($_POST['title'] ?? ''));
        $note = trim((string)($_POST['note_text'] ?? ''));
        $date = trim((string)($_POST['note_date'] ?? date('Y-m-d')));
        $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
        if ($title === '' || mb_strlen($title) > 180 || $note === '') throw new RuntimeException('Enter a title (up to 180 characters) and note.');
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new RuntimeException('Enter a valid note date.');

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE member_notebook SET title=?,note_text=?,note_date=? WHERE id=? AND member_id=?');
            $stmt->bind_param('sssii', $title, $note, $date, $id, $memberId);
            $message = 'Note updated.';
        } else {
            $stmt = $conn->prepare('INSERT INTO member_notebook(member_id,title,note_text,note_date) VALUES(?,?,?,?)');
            $stmt->bind_param('isss', $memberId, $title, $note, $date);
            $message = 'Note saved.';
        }
        $stmt->execute();
        $stmt->close();
        ce_redirect('notebook.php', $message);
    }
} catch (Throwable $e) {
    $_SESSION['ce_flash'] = ['message' => $e->getMessage(), 'type' => 'danger'];
}

$rows = mp_rows($conn, 'SELECT id,title,note_text,note_date,created_at,updated_at FROM member_notebook WHERE member_id=? ORDER BY note_date DESC,id DESC', 'i', [$memberId]);
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="main-content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>
    <div class="content-area notebook-page">
        <?php ce_flash(); ?>
        <div class="notebook-heading">
            <div><span class="notebook-eyebrow">Private space</span><h2><i class="fas fa-book"></i> My Notes</h2><p>Keep personal notes for your own reference.</p></div>
            <button type="button" class="btn btn-primary" id="addNotebookNote"><i class="fas fa-plus"></i> Add Note</button>
        </div>

        <?php if (!$rows): ?>
            <div class="notebook-empty"><i class="fas fa-note-sticky"></i><h3>No notes yet</h3><p>Add a note to start your private notebook.</p><button type="button" class="btn btn-primary" data-open-note><i class="fas fa-plus"></i> Create your first note</button></div>
        <?php else: ?>
            <div class="notebook-grid">
                <?php foreach ($rows as $row):
                    $noteData = json_encode(['id'=>(int)$row['id'],'title'=>(string)$row['title'],'note_text'=>(string)$row['note_text'],'note_date'=>(string)$row['note_date']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                ?>
                    <article class="notebook-card">
                        <div class="notebook-card-meta"><span><i class="fas fa-calendar-day"></i> <?= mp_h(mp_date($row['note_date'])) ?></span><span>Updated <?= mp_h(mp_date($row['updated_at'], 'd M Y')) ?></span></div>
                        <h3><?= mp_h($row['title']) ?></h3>
                        <div class="notebook-note-preview"><?= nl2br(mp_h($row['note_text'])) ?></div>
                        <div class="notebook-card-actions">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-edit-note="<?= mp_h($noteData ?: '{}') ?>"><i class="fas fa-pen"></i> Edit</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-note="<?= (int)$row['id'] ?>" data-delete-title="<?= mp_h($row['title']) ?>"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="notebook-backdrop" id="notebookBackdrop"></div>
<section class="notebook-modal" id="noteEditor" role="dialog" aria-modal="true" aria-labelledby="noteEditorTitle" aria-hidden="true">
    <div class="notebook-dialog">
        <form method="post" id="noteForm">
            <input type="hidden" name="csrf_token" value="<?= mp_h(ce_csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="noteId" value="0">
            <div class="notebook-modal-head"><div><h2 id="noteEditorTitle">Add a note</h2><p>Your notes are private to your member account.</p></div><button type="button" class="notebook-close" data-close-note aria-label="Close">&times;</button></div>
            <div class="notebook-modal-body">
                <label>Title<input class="form-control" name="title" id="noteTitle" maxlength="180" required></label>
                <label>Date<input class="form-control" type="date" name="note_date" id="noteDate" value="<?= date('Y-m-d') ?>" required></label>
                <label>Note<textarea class="form-control" name="note_text" id="noteText" rows="9" required></textarea></label>
            </div>
            <div class="notebook-modal-foot"><button type="button" class="btn btn-light" data-close-note>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Note</button></div>
        </form>
    </div>
</section>
<section class="notebook-modal" id="noteDeleteModal" role="dialog" aria-modal="true" aria-labelledby="noteDeleteTitle" aria-hidden="true">
    <div class="notebook-dialog notebook-dialog-small">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= mp_h(ce_csrf()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteNoteId">
            <div class="notebook-modal-head"><div><h2 id="noteDeleteTitle">Delete note?</h2><p>This note will be permanently removed.</p></div><button type="button" class="notebook-close" data-close-note aria-label="Close">&times;</button></div>
            <div class="notebook-modal-body"><p>Delete <strong id="deleteNoteName"></strong>?</p></div>
            <div class="notebook-modal-foot"><button type="button" class="btn btn-light" data-close-note>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete Note</button></div>
        </form>
    </div>
</section>

<style>
.notebook-page{--nb-border:#e4e8ef;--nb-muted:#667085}.notebook-heading{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:22px}.notebook-heading h2{margin:2px 0 5px;color:#172033}.notebook-heading p{margin:0;color:var(--nb-muted)}.notebook-eyebrow{color:var(--primary);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.notebook-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:15px}.notebook-card{display:flex;flex-direction:column;min-width:0;padding:18px;border:1px solid var(--nb-border);border-radius:14px;background:#fff;box-shadow:0 4px 16px #1018280a}.notebook-card-meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;color:var(--nb-muted);font-size:11px}.notebook-card-meta span:first-child{color:var(--primary);font-weight:700}.notebook-card h3{margin:13px 0 9px;color:#172033;font-size:17px;overflow-wrap:anywhere}.notebook-note-preview{flex:1;max-height:190px;overflow:auto;color:#475467;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}.notebook-card-actions{display:flex;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid #eef0f4}.notebook-empty{padding:50px 18px;border:1px dashed #cbd2de;border-radius:14px;background:#fff;text-align:center}.notebook-empty>i{color:var(--primary);font-size:32px}.notebook-empty h3{margin:12px 0 5px}.notebook-empty p{color:var(--nb-muted)}.notebook-backdrop{position:fixed;inset:0;z-index:10020;display:none;background:#10182899}.notebook-backdrop.open{display:block}.notebook-modal{position:fixed;inset:0;z-index:10021;display:none;align-items:center;justify-content:center;padding:16px;overflow:auto}.notebook-modal.open{display:flex}.notebook-dialog{width:min(650px,100%);max-height:92vh;overflow:auto;border-radius:16px;background:#fff;box-shadow:0 24px 70px #0004}.notebook-dialog-small{width:min(450px,100%)}.notebook-modal-head,.notebook-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;background:#f8fafc;border-bottom:1px solid var(--nb-border)}.notebook-modal-head h2{margin:0;color:#172033;font-size:18px}.notebook-modal-head p{margin:4px 0 0;color:var(--nb-muted);font-size:12px}.notebook-close{border:0;background:transparent;color:#667085;font-size:28px;line-height:1;cursor:pointer}.notebook-modal-body{display:grid;gap:14px;padding:20px}.notebook-modal-body label{display:grid;gap:6px;color:#344054;font-size:13px;font-weight:700}.notebook-modal-body textarea{min-height:180px;resize:vertical}.notebook-modal-foot{justify-content:flex-end;border-top:1px solid var(--nb-border);border-bottom:0}.notebook-modal-open{overflow:hidden}@media(max-width:600px){.notebook-heading{align-items:flex-start;flex-direction:column}.notebook-heading>.btn{width:100%;justify-content:center}.notebook-modal-head,.notebook-modal-foot{padding:14px}.notebook-modal-body{padding:14px}}
</style>
<script>
(function(){
  const editor=document.getElementById('noteEditor'), deleter=document.getElementById('noteDeleteModal'), backdrop=document.getElementById('notebookBackdrop'), form=document.getElementById('noteForm');
  const title=document.getElementById('noteTitle'), text=document.getElementById('noteText'), date=document.getElementById('noteDate'), id=document.getElementById('noteId');
  function close(){editor.classList.remove('open');deleter.classList.remove('open');backdrop.classList.remove('open');editor.setAttribute('aria-hidden','true');deleter.setAttribute('aria-hidden','true');document.body.classList.remove('notebook-modal-open');}
  function open(modal){backdrop.classList.add('open');modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.classList.add('notebook-modal-open');modal.querySelector('input:not([type=hidden]),textarea,button')?.focus();}
  function addNote(){form.reset();id.value='0';date.value='<?= date('Y-m-d') ?>';document.getElementById('noteEditorTitle').textContent='Add a note';open(editor);}
  document.getElementById('addNotebookNote')?.addEventListener('click',addNote);
  document.querySelector('[data-open-note]')?.addEventListener('click',addNote);
  document.querySelectorAll('[data-edit-note]').forEach(button=>button.addEventListener('click',()=>{let note={};try{note=JSON.parse(button.dataset.editNote||'{}')}catch(e){}form.reset();id.value=note.id||'0';title.value=note.title||'';text.value=note.note_text||'';date.value=note.note_date||'<?= date('Y-m-d') ?>';document.getElementById('noteEditorTitle').textContent='Edit note';open(editor);}));
  document.querySelectorAll('[data-delete-note]').forEach(button=>button.addEventListener('click',()=>{document.getElementById('deleteNoteId').value=button.dataset.deleteNote||'';document.getElementById('deleteNoteName').textContent=button.dataset.deleteTitle||'this note';open(deleter);}));
  backdrop.addEventListener('click',close);document.querySelectorAll('[data-close-note]').forEach(button=>button.addEventListener('click',close));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&(editor.classList.contains('open')||deleter.classList.contains('open')))close();});
})();
</script>
<?php mp_standard_styles();ce_styles();require_once __DIR__ . '/../includes/footer.php'; ?>
