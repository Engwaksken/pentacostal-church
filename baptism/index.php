<?php
require_once '../config/config.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

function bh($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function b_scalar(mysqli $conn, string $sql): int {
    try {
        $res = $conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row();
        $res->close();
        return (int)($row[0] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function b_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    if (!$stmt) return false;

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

$hasBaptisms = b_table_exists($conn, 'baptisms');
$hasMembers  = b_table_exists($conn, 'members');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$total        = $hasBaptisms ? b_scalar($conn, "SELECT COUNT(*) FROM baptisms") : 0;
$infant       = $hasBaptisms ? b_scalar($conn, "SELECT COUNT(*) FROM baptisms WHERE baptism_type='Infant'") : 0;
$adult        = $hasBaptisms ? b_scalar($conn, "SELECT COUNT(*) FROM baptisms WHERE baptism_type='Adult'") : 0;
$confirmation = $hasBaptisms ? b_scalar($conn, "SELECT COUNT(*) FROM baptisms WHERE baptism_type='Confirmation'") : 0;
$reaffirm     = $hasBaptisms ? b_scalar($conn, "SELECT COUNT(*) FROM baptisms WHERE baptism_type='Re-affirmation'") : 0;
$thisYear     = $hasBaptisms ? b_scalar($conn, "SELECT COUNT(*) FROM baptisms WHERE YEAR(baptism_date)=YEAR(CURDATE())") : 0;

$members = [];
if ($hasMembers) {
    $res = $conn->query("
        SELECT id, member_no, full_name
        FROM members
        ORDER BY full_name
        LIMIT 2000
    ");
    while ($res && $m = $res->fetch_assoc()) {
        $members[] = $m;
    }
}

$memberOptionsHtml = '<option value="">- Not a registered member -</option>';
foreach ($members as $m) {
    $id   = (int)$m['id'];
    $name = bh((string)($m['full_name'] ?? ''));
    $no   = !empty($m['member_no']) ? ' (' . bh($m['member_no']) . ')' : '';
    $memberOptionsHtml .= '<option value="' . $id . '">' . $name . $no . '</option>';
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<link rel="stylesheet" href="../assets/css/baptism.css?v=<?= urlencode((string)@filemtime(__DIR__ . '/../assets/css/baptism.css')) ?>">
<style>
.page-toolbar-filters>.baptism-range-fields{display:flex;align-items:center;gap:8px;flex:0 1 300px;min-width:280px}
.baptism-range-fields[hidden]{display:none!important}
.baptism-range-separator{color:var(--text-muted);font-size:12px;font-weight:700}
.baptism-pagination-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 0 0;border-top:1px solid var(--border);margin-top:14px}
.baptism-pagination-summary{color:var(--text-muted);font-size:12px}
.baptism-pagination{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end}
.baptism-page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 11px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.baptism-page-btn:hover:not(:disabled){border-color:var(--primary);color:var(--primary);background:var(--primary-fade)}
.baptism-page-btn.active{border-color:var(--primary);background:var(--primary);color:#fff}
.baptism-page-btn:disabled{opacity:.45;cursor:not-allowed}
.baptism-page-ellipsis{display:inline-grid;place-items:center;min-width:30px;height:36px;color:var(--text-muted)}
@media(max-width:767px){.page-toolbar-filters>.baptism-range-fields{grid-column:1/-1;min-width:0}.baptism-range-fields input{min-width:0}.baptism-pagination-bar{align-items:flex-start;flex-direction:column}.baptism-pagination{justify-content:flex-start}}
@media(max-width:480px){.page-toolbar-filters>.baptism-range-fields{display:grid;grid-template-columns:1fr}.baptism-range-separator{text-align:center}}
</style>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

<?= showFlash(); ?>

<div class="module-page-header">
    <div class="module-title">
        <h2><i class="fas fa-water baptism-title-icon"></i> Baptism Register</h2>
        <p>Record, view, edit and manage baptism records.</p>
    </div>
    <div class="module-actions">
        <button type="button" class="btn btn-outline" id="btnOpenSignatories">
            <i class="fas fa-signature"></i> Certificate Signatories
        </button>
        <button type="button" class="btn btn-primary" id="btnOpenAddBaptism">
            <i class="fas fa-plus"></i> Record Baptism
        </button>
    </div>
</div>

<?php if (!$hasBaptisms): ?>

<div class="info-box danger">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <h4>Setup Required</h4>
        <p>The <code>baptisms</code> table does not exist. Please run the database setup script.</p>
    </div>
</div>

<?php else: ?>

<div class="stats-grid module-stats-grid baptism-stats-grid">
    <div class="stat-card baptism-stat-card" data-filter="">
        <div class="stat-icon baptism-stat-icon-primary">
            <i class="fas fa-list"></i>
        </div>
        <div class="stat-body">
            <div class="stat-number" id="statTotal"><?= (int)$total ?></div>
            <div class="stat-label">Total Records</div>
        </div>
    </div>

    <div class="stat-card baptism-stat-card" data-filter="Infant">
        <div class="stat-icon baptism-stat-icon-primary">
            <i class="fas fa-baby"></i>
        </div>
        <div class="stat-body">
            <div class="stat-number" id="statInfant"><?= (int)$infant ?></div>
            <div class="stat-label">Infant Baptisms</div>
        </div>
    </div>

    <div class="stat-card baptism-stat-card" data-filter="Adult">
        <div class="stat-icon baptism-stat-icon-accent">
            <i class="fas fa-user"></i>
        </div>
        <div class="stat-body">
            <div class="stat-number" id="statAdult"><?= (int)$adult ?></div>
            <div class="stat-label">Adult Baptisms</div>
        </div>
    </div>

    <div class="stat-card baptism-stat-card" data-filter="Confirmation">
        <div class="stat-icon baptism-stat-icon-success">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-body">
            <div class="stat-number" id="statConfirmation"><?= (int)$confirmation ?></div>
            <div class="stat-label">Confirmations</div>
        </div>
    </div>

    <div class="stat-card baptism-stat-card" data-filter="Re-affirmation">
        <div class="stat-icon baptism-stat-icon-info">
            <i class="fas fa-hand-holding-heart"></i>
        </div>
        <div class="stat-body">
            <div class="stat-number" id="statReaffirm"><?= (int)$reaffirm ?></div>
            <div class="stat-label">Re-affirmations</div>
        </div>
    </div>

    <div class="stat-card baptism-stat-card">
        <div class="stat-icon baptism-stat-icon-danger">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-body">
            <div class="stat-number" id="statYear"><?= (int)$thisYear ?></div>
            <div class="stat-label">This Year</div>
        </div>
    </div>
</div>

<div class="card module-card">
    <div class="card-body">
        <div class="page-toolbar">
            <div class="page-toolbar-search">
                <i class="fas fa-search"></i>
                <input type="search" id="baptismSearch" class="form-control" placeholder="Search candidate, number, officiant, venue...">
            </div>

            <div class="page-toolbar-filters">
            <select id="typeFilter" class="form-control baptism-type-filter" aria-label="Filter by baptism type">
                <option value="">All Types</option>
                <option value="Infant">Infant</option>
                <option value="Adult">Adult</option>
                <option value="Confirmation">Confirmation</option>
                <option value="Re-affirmation">Re-affirmation</option>
            </select>

            <select id="periodFilter" class="form-control baptism-period-filter" aria-label="Filter by date period">
                <option value="all">All Dates</option>
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="range">Custom Range</option>
            </select>

            <div class="baptism-range-fields" id="baptismRangeFields" hidden>
                <input type="date" id="baptismStartDate" class="form-control" aria-label="Start date">
                <span class="baptism-range-separator">to</span>
                <input type="date" id="baptismEndDate" class="form-control" aria-label="End date">
            </div>

            <select id="baptismPerPage" class="form-control baptism-per-page" aria-label="Records per page">
                <option value="10">10 per page</option>
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>

            </div>

            <div class="page-toolbar-actions">
                <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshBaptism">
                    <i class="fas fa-rotate-right"></i> Refresh
                </button>
            </div>
        </div>

        <div id="baptismAlert" class="ajax-alert"></div>

        <div class="module-table-wrap">
            <table class="table module-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Baptism No</th>
                        <th>Candidate</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Officiant</th>
                        <th>Venue</th>
                        <th>Cert. No</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="baptismTableBody">
                    <tr>
                        <td colspan="9" class="text-muted text-center baptism-loading-cell">
                            <i class="fas fa-spinner fa-spin"></i> Loading baptism records...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="baptism-pagination-bar" id="baptismPaginationBar" hidden>
            <div class="baptism-pagination-summary" id="baptismPaginationSummary">Showing 0 records</div>
            <nav class="baptism-pagination" id="baptismPagination" aria-label="Baptism pagination"></nav>
        </div>
    </div>
</div>

<div class="bap-modal" id="signatoriesModal" aria-hidden="true">
    <div class="bap-dialog" style="width:min(760px,100%)">
        <form id="signatoriesForm" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="action" value="save_signatories">
            <input type="hidden" name="csrf_token" value="<?= bh($csrfToken) ?>">
            <div class="bap-modal-header">
                <div class="bap-modal-title"><i class="fas fa-signature"></i> Certificate Signatories</div>
                <button type="button" class="bap-close" data-close-baptism>&times;</button>
            </div>
            <div class="bap-modal-body">
                <div id="signatoriesAlert" class="ajax-alert"></div>
                <p class="text-muted" style="margin-top:0">Set the title, name and signature of the two people who sign certificates, for example <em>Senior Pastor</em> and <em>Church Secretary</em>. They appear on baptism certificates, and the first signatory also signs event certificates for the church. Upload transparent PNG or JPG signatures.</p>
                <div class="signatory-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px">
                    <fieldset style="border:1px solid var(--border,#e2e6eb);border-radius:10px;padding:14px 16px;margin:0">
                        <legend style="padding:0 6px;font-weight:700">First Signatory</legend>
                        <div class="form-group">
                            <label for="vicarTitle">Title</label>
                            <input type="text" class="form-control" name="vicar_title" id="vicarTitle" maxlength="100" placeholder="e.g. Senior Pastor" list="signatoryTitleOptions" required>
                        </div>
                        <div class="form-group">
                            <label for="vicarName">Name</label>
                            <input type="text" class="form-control" name="vicar_name" id="vicarName" maxlength="150" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label>Signature</label>
                            <input type="file" class="form-control" name="vicar_signature" accept="image/png,image/jpeg,image/webp">
                            <div id="vicarSignaturePreview" style="margin-top:10px"></div>
                        </div>
                        <label style="display:flex;gap:8px;align-items:center;margin-top:8px">
                            <input type="checkbox" name="remove_vicar_signature" value="1"> Remove current signature
                        </label>
                    </fieldset>
                    <fieldset style="border:1px solid var(--border,#e2e6eb);border-radius:10px;padding:14px 16px;margin:0">
                        <legend style="padding:0 6px;font-weight:700">Second Signatory</legend>
                        <div class="form-group">
                            <label for="secretaryTitle">Title</label>
                            <input type="text" class="form-control" name="secretary_title" id="secretaryTitle" maxlength="100" placeholder="e.g. Church Secretary" list="signatoryTitleOptions" required>
                        </div>
                        <div class="form-group">
                            <label for="secretaryName">Name</label>
                            <input type="text" class="form-control" name="secretary_name" id="secretaryName" maxlength="150" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label>Signature</label>
                            <input type="file" class="form-control" name="secretary_signature" accept="image/png,image/jpeg,image/webp">
                            <div id="secretarySignaturePreview" style="margin-top:10px"></div>
                        </div>
                        <label style="display:flex;gap:8px;align-items:center;margin-top:8px">
                            <input type="checkbox" name="remove_secretary_signature" value="1"> Remove current signature
                        </label>
                    </fieldset>
                </div>
                <datalist id="signatoryTitleOptions">
                    <option value="Senior Pastor"><option value="Pastor"><option value="Assistant Pastor"><option value="Bishop">
                    <option value="Overseer"><option value="Church Secretary"><option value="Church Administrator"><option value="Elder">
                    <option value="The Vicar / Officiating Clergy"><option value="Officiating Minister">
                </datalist>
            </div>
            <div class="bap-modal-footer">
                <button type="button" class="btn btn-outline" data-close-baptism>Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveSignatoriesBtn"><i class="fas fa-save"></i> Save Signatories</button>
            </div>
        </form>
    </div>
</div>

<div class="bap-modal-backdrop" id="baptismBackdrop"></div>

<div class="bap-modal" id="baptismAddModal" aria-hidden="true">
    <div class="bap-dialog" style="width:min(1100px,100%)">
        <form id="baptismBulkForm" autocomplete="off">
            <div class="bap-modal-header">
                <div class="bap-modal-title">
                    <i class="fas fa-water"></i> Record Baptisms
                    <span class="badge badge-primary ml-2" id="bapRowCountBadge">1 candidate</span>
                </div>
                <button type="button" class="bap-close" data-close-baptism>&times;</button>
            </div>

            <div class="bap-modal-body" style="padding:0">
                <div style="padding:16px 20px 14px;border-bottom:1px solid var(--border);background:#faf9fd">
                    <div class="form-section-title" style="margin:0 0 12px">
                        <i class="fas fa-church"></i> Ceremony Details
                    </div>

                    <div class="form-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:12px">
                        <div class="form-group" style="margin:0">
                            <label>Baptism Date <span class="req">*</span></label>
                            <input type="date" id="sharedBapDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group" style="margin:0">
                            <label>Baptism Type <span class="req">*</span></label>
                            <select id="sharedBapType" class="form-control">
                                <option value="Infant">Infant</option>
                                <option value="Adult">Adult</option>
                                <option value="Confirmation">Confirmation</option>
                                <option value="Re-affirmation">Re-affirmation</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin:0">
                            <label>Officiant</label>
                            <input type="text" id="sharedOfficiant" class="form-control" placeholder="Officiating minister">
                        </div>

                        <div class="form-group" style="margin:0">
                            <label>Venue</label>
                            <input type="text" id="sharedVenue" class="form-control" value="<?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                </div>

                <div id="bapRowsHeader">
                    <div></div>
                    <div><i class="fas fa-user"></i> Full Name *</div>
                    <div><i class="fas fa-link"></i> Linked Member</div>
                    <div><i class="fas fa-certificate"></i> Cert. No</div>
                    <div><i class="fas fa-user-tie"></i> Godfather</div>
                    <div><i class="fas fa-user-friends"></i> Godmother</div>
                    <div></div>
                </div>

                <div id="bapRowsContainer" style="max-height:380px;overflow-y:auto;padding:8px 20px"></div>

                <div style="padding:10px 20px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center">
                    <button type="button" class="btn btn-outline btn-sm" id="btnAddBapRow">
                        <i class="fas fa-plus"></i> Add Another Candidate
                    </button>
                    <span class="text-muted small" id="bapRowsHint"></span>
                </div>

                <div style="padding:0 20px">
                    <div id="bulkBapAlert" class="ajax-alert"></div>
                </div>
            </div>

            <div class="bap-modal-actions">
                <button type="button" class="btn btn-outline" data-close-baptism>
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" id="bapBulkSubmitBtn">
                    <i class="fas fa-save"></i>
                    <span id="bapBulkSubmitLabel">Save 1 Record</span>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="bap-modal" id="baptismEditModal" aria-hidden="true">
    <div class="bap-dialog">
        <form id="baptismEditForm">
            <div class="bap-modal-header">
                <div class="bap-modal-title">
                    <i class="fas fa-edit"></i> Edit Baptism Record
                </div>
                <button type="button" class="bap-close" data-close-baptism>&times;</button>
            </div>

            <div class="bap-modal-body">
                <div id="editFormAlert" class="ajax-alert"></div>

                <input type="hidden" name="csrf_token" value="<?= bh($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editBaptismId">

                <div class="form-grid">
                    <div class="form-group full-span">
                        <label>Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" id="editFullName" class="form-control" required>
                    </div>

                    <div class="form-group full-span">
                        <label>Linked Church Member</label>
                        <select name="member_id" id="editMemberId" class="form-control">
                            <?= $memberOptionsHtml ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Baptism Type <span class="req">*</span></label>
                        <select name="baptism_type" id="editBapType" class="form-control" required>
                            <option value="Infant">Infant</option>
                            <option value="Adult">Adult</option>
                            <option value="Confirmation">Confirmation</option>
                            <option value="Re-affirmation">Re-affirmation</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Baptism Date <span class="req">*</span></label>
                        <input type="date" name="baptism_date" id="editBapDate" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Certificate No.</label>
                        <input type="text" name="certificate_no" id="editCertNo" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Officiant</label>
                        <input type="text" name="officiant" id="editOfficiant" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Venue</label>
                        <input type="text" name="venue" id="editVenue" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Godfather</label>
                        <input type="text" name="godfather" id="editGodfather" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Godmother</label>
                        <input type="text" name="godmother" id="editGodmother" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Father / Parent</label>
                        <input type="text" name="parent_father" id="editParentFather" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Mother / Parent</label>
                        <input type="text" name="parent_mother" id="editParentMother" class="form-control">
                    </div>

                    <div class="form-group full-span">
                        <label>Notes</label>
                        <textarea name="notes" id="editNotes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>

            <div class="bap-modal-actions">
                <button type="button" class="btn btn-outline" data-close-baptism>Cancel</button>
                <button type="submit" class="btn btn-primary" id="editBapSubmitBtn">
                    <i class="fas fa-save"></i> Update Baptism
                </button>
            </div>
        </form>
    </div>
</div>

<div class="bap-modal" id="viewBaptismModal" aria-hidden="true">
    <div class="bap-dialog">
        <div class="bap-modal-header">
            <div class="bap-modal-title"><i class="fas fa-water"></i> Baptism Details</div>
            <button type="button" class="bap-close" data-close-baptism>&times;</button>
        </div>

        <div class="bap-modal-body">
            <div id="viewBaptismContent" class="detail-grid"></div>
        </div>

        <div class="bap-modal-actions">
            <a href="#" class="btn btn-success" id="viewCertificateBtn">
                <i class="fas fa-certificate"></i> Certificate
            </a>
            <button type="button" class="btn btn-outline" data-close-baptism>Close</button>
        </div>
    </div>
</div>

<div class="bap-modal" id="deleteBaptismModal" aria-hidden="true">
    <div class="bap-dialog" style="width:min(520px,100%)">
        <form id="deleteBaptismForm">
            <div class="bap-modal-header">
                <div class="bap-modal-title"><i class="fas fa-trash"></i> Delete Baptism Record</div>
                <button type="button" class="bap-close" data-close-baptism>&times;</button>
            </div>

            <div class="bap-modal-body">
                <div id="deleteAlert" class="ajax-alert"></div>

                <input type="hidden" name="csrf_token" value="<?= bh($csrfToken) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteBaptismId">

                <div class="info-box danger" style="margin:0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        Are you sure you want to delete the record for
                        <strong id="deleteBaptismName"></strong>?
                        This cannot be undone.
                    </div>
                </div>
            </div>

            <div class="bap-modal-actions">
                <button type="button" class="btn btn-outline" data-close-baptism>Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

</div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const ENDPOINT = 'process-baptism.php';
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    const MEMBER_OPTIONS_HTML = <?= json_encode($memberOptionsHtml) ?>;

    let rows = [];
    let bapRowCounter = 0;
    let currentPage = 1;
    let pagination = { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 };
    let searchTimer = null;

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function showAlert(el, message, type = 'success') {
        if (!el) return;
        el.className = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        el.textContent = message;
    }

    function clearAlert(el) {
        if (!el) return;
        el.className = 'ajax-alert';
        el.textContent = '';
    }

    function badge(text, cls = 'badge-info') {
        return `<span class="badge ${cls}">${esc(text || '-')}</span>`;
    }

    function openModal(selector) {
        const backdrop = $('#baptismBackdrop');
        const modal = $(selector);

        if (backdrop) backdrop.classList.add('show');
        if (modal) {
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        }

        document.body.classList.add('cms-modal-open');
    }

    function closeModals() {
        $$('.bap-modal, .bap-modal-backdrop').forEach(el => el.classList.remove('show'));
        $$('.bap-modal').forEach(el => el.setAttribute('aria-hidden', 'true'));
        document.body.classList.remove('cms-modal-open');
    }

    async function request(url, options = {}) {
        const finalOptions = Object.assign({
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }, options);

        const response = await fetch(url, finalOptions);
        const text = await response.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error(text ? 'Invalid server response: ' + text.substring(0, 250) : 'Empty server response.');
        }

        if (!response.ok) {
            throw new Error(data.message || 'Request failed.');
        }

        return data;
    }

    function postForm(form) {
        return request(ENDPOINT, {
            method: 'POST',
            body: new FormData(form)
        });
    }

    function clsForType(type) {
        if (type === 'Infant') return 'badge-primary';
        if (type === 'Adult') return 'badge-warning';
        if (type === 'Confirmation') return 'badge-success';
        if (type === 'Re-affirmation') return 'badge-info';
        return 'badge-secondary';
    }

    function setText(selector, value) {
        const el = $(selector);
        if (el) el.textContent = value ?? 0;
    }

    function renderStats(counts = {}) {
        setText('#statTotal', counts.Total ?? rows.length);
        setText('#statInfant', counts.Infant ?? 0);
        setText('#statAdult', counts.Adult ?? 0);
        setText('#statConfirmation', counts.Confirmation ?? 0);
        setText('#statReaffirm', counts['Re-affirmation'] ?? 0);
        setText('#statYear', counts.ThisYear ?? counts.thisYear ?? 0);
    }

    function renderTable(items) {
        const tbody = $('#baptismTableBody');
        if (!tbody) return;

        if (!items.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center baptism-loading-cell">
                        <div class="empty-state">
                            <div class="icon"><i class="fas fa-water"></i></div>
                            <p>No baptism records found.</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = items.map((r, i) => {
            const id = Number(r.id || 0);

            return `
                <tr>
                    <td class="text-muted">${(pagination.from || 1) + i}</td>
                    <td><strong>${esc(r.baptism_no || '-')}</strong></td>
                    <td>
                        <strong>${esc(r.candidate_name || '-')}</strong>
                        ${r.member_name ? `<br><small class="text-muted"><i class="fas fa-link"></i> ${esc(r.member_name)}${r.member_no ? ' (' + esc(r.member_no) + ')' : ''}</small>` : ''}
                    </td>
                    <td>${badge(r.baptism_type, clsForType(r.baptism_type))}</td>
                    <td>${esc(r.baptism_date || '-')}</td>
                    <td>${esc(r.officiant || '-')}</td>
                    <td>${esc(r.venue || '-')}</td>
                    <td>${esc(r.certificate_no || '-')}</td>
                    <td>
                        <div class="action-buttons">
                            <button type="button" class="btn btn-sm btn-info btn-icon" data-action="view" data-id="${id}" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-primary btn-icon" data-action="edit" data-id="${id}" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a class="btn btn-sm btn-success btn-icon" href="certificate.php?id=${id}" title="Certificate">
                                <i class="fas fa-certificate"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-danger btn-icon" data-action="delete" data-id="${id}" data-name="${esc(r.candidate_name || 'this record')}" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function toggleRangeFields() {
        const period = $('#periodFilter')?.value || 'all';
        const rangeFields = $('#baptismRangeFields');

        if (rangeFields) {
            rangeFields.hidden = period !== 'range';
        }
    }

    function buildListUrl() {
        const params = new URLSearchParams({
            action: 'list',
            page: String(currentPage),
            per_page: String($('#baptismPerPage')?.value || 10),
            search: String($('#baptismSearch')?.value || '').trim(),
            type: String($('#typeFilter')?.value || ''),
            period: String($('#periodFilter')?.value || 'all')
        });

        if ($('#periodFilter')?.value === 'range') {
            params.set('start_date', String($('#baptismStartDate')?.value || ''));
            params.set('end_date', String($('#baptismEndDate')?.value || ''));
        }

        return ENDPOINT + '?' + params.toString();
    }

    function paginationPages(page, totalPages) {
        const pages = [];

        if (totalPages <= 7) {
            for (let i = 1; i <= totalPages; i++) pages.push(i);
            return pages;
        }

        pages.push(1);
        if (page > 4) pages.push('ellipsis-left');

        const start = Math.max(2, page - 1);
        const end = Math.min(totalPages - 1, page + 1);
        for (let i = start; i <= end; i++) pages.push(i);

        if (page < totalPages - 3) pages.push('ellipsis-right');
        pages.push(totalPages);

        return pages;
    }

    function renderPagination(meta = {}) {
        pagination = Object.assign({
            page: 1,
            per_page: 10,
            total: 0,
            total_pages: 1,
            from: 0,
            to: 0
        }, meta || {});

        currentPage = Number(pagination.page || 1);

        const bar = $('#baptismPaginationBar');
        const summary = $('#baptismPaginationSummary');
        const nav = $('#baptismPagination');

        if (bar) bar.hidden = false;
        if (summary) {
            summary.textContent = pagination.total > 0
                ? `Showing ${pagination.from}–${pagination.to} of ${pagination.total} records`
                : 'Showing 0 records';
        }

        if (!nav) return;

        const totalPages = Math.max(1, Number(pagination.total_pages || 1));
        const page = Math.max(1, Number(pagination.page || 1));
        const controls = [];

        controls.push(`
            <button type="button" class="baptism-page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i><span class="sr-only">Previous</span>
            </button>
        `);

        paginationPages(page, totalPages).forEach(function (item) {
            if (typeof item === 'string') {
                controls.push('<span class="baptism-page-ellipsis">…</span>');
                return;
            }

            controls.push(`
                <button type="button" class="baptism-page-btn ${item === page ? 'active' : ''}" data-page="${item}" ${item === page ? 'aria-current="page"' : ''}>
                    ${item}
                </button>
            `);
        });

        controls.push(`
            <button type="button" class="baptism-page-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i><span class="sr-only">Next</span>
            </button>
        `);

        nav.innerHTML = controls.join('');
    }

    async function loadBaptisms(resetPage = false) {
        const alertBox = $('#baptismAlert');
        const tbody = $('#baptismTableBody');

        if (resetPage) currentPage = 1;
        clearAlert(alertBox);

        if ($('#periodFilter')?.value === 'range') {
            const startDate = $('#baptismStartDate')?.value || '';
            const endDate = $('#baptismEndDate')?.value || '';

            if (!startDate || !endDate) {
                rows = [];
                renderTable([]);
                renderPagination({ page: 1, total: 0, total_pages: 1, from: 0, to: 0 });
                showAlert(alertBox, 'Select both the start date and end date.', 'error');
                return;
            }

            if (startDate > endDate) {
                rows = [];
                renderTable([]);
                renderPagination({ page: 1, total: 0, total_pages: 1, from: 0, to: 0 });
                showAlert(alertBox, 'The start date cannot be later than the end date.', 'error');
                return;
            }
        }

        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-muted text-center baptism-loading-cell">
                        <i class="fas fa-spinner fa-spin"></i> Loading baptism records...
                    </td>
                </tr>
            `;
        }

        try {
            const data = await request(buildListUrl());

            if (!data.success) {
                throw new Error(data.message || 'Failed to load records.');
            }

            rows = Array.isArray(data.items) ? data.items : [];
            renderStats(data.counts || {});
            renderPagination(data.pagination || {});
            renderTable(rows);
        } catch (error) {
            rows = [];
            renderTable([]);
            renderPagination({ page: 1, total: 0, total_pages: 1, from: 0, to: 0 });

            showAlert(
                alertBox,
                error.message + ' Check that process-baptism.php exists and returns valid JSON.',
                'error'
            );
        }
    }

    function createBapRow(number = 1) {
        const idx = ++bapRowCounter;
        const row = document.createElement('div');

        row.className = 'bap-candidate-row';
        row.dataset.idx = idx;

        row.innerHTML = `
            <div style="padding-top:8px;color:var(--text-muted);font-size:11px;font-weight:700;text-align:center">${number}</div>

            <div class="form-group" style="margin:0">
                <input type="text" name="candidates[${idx}][full_name]" class="form-control form-control-sm" placeholder="Full name" required>
            </div>

            <div class="form-group" style="margin:0">
                <select name="candidates[${idx}][member_id]" class="form-control form-control-sm">
                    ${MEMBER_OPTIONS_HTML}
                </select>
            </div>

            <div class="form-group" style="margin:0">
                <input type="text" name="candidates[${idx}][certificate_no]" class="form-control form-control-sm" placeholder="Cert. No">
            </div>

            <div class="form-group" style="margin:0">
                <input type="text" name="candidates[${idx}][godfather]" class="form-control form-control-sm" placeholder="Godfather">
            </div>

            <div class="form-group" style="margin:0">
                <input type="text" name="candidates[${idx}][godmother]" class="form-control form-control-sm" placeholder="Godmother">
            </div>

            <div style="padding-top:4px">
                <button type="button" class="btn btn-danger btn-icon btn-sm remove-bap-row" title="Remove" style="display:none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        return row;
    }

    function getAllBapRows() {
        const container = $('#bapRowsContainer');
        return container ? Array.from(container.querySelectorAll('.bap-candidate-row')) : [];
    }

    function refreshBapUI() {
        const candidateRows = getAllBapRows();
        const count = candidateRows.length;

        candidateRows.forEach((row, index) => {
            const numEl = row.children[0];
            if (numEl) numEl.textContent = index + 1;

            const removeBtn = row.querySelector('.remove-bap-row');
            if (removeBtn) removeBtn.style.display = count > 1 ? '' : 'none';
        });

        const badgeEl = $('#bapRowCountBadge');
        if (badgeEl) badgeEl.textContent = count === 1 ? '1 candidate' : count + ' candidates';

        const labelEl = $('#bapBulkSubmitLabel');
        if (labelEl) labelEl.textContent = count === 1 ? 'Save 1 Record' : 'Save ' + count + ' Records';

        const hintEl = $('#bapRowsHint');
        if (hintEl) hintEl.textContent = count > 1 ? count + ' baptisms will be recorded' : '';
    }

    function initBulkBapForm() {
        const container = $('#bapRowsContainer');
        if (!container) return;

        container.innerHTML = '';
        bapRowCounter = 0;
        container.appendChild(createBapRow(1));

        clearAlert($('#bulkBapAlert'));
        refreshBapUI();
    }

    async function openEdit(id) {
        clearAlert($('#editFormAlert'));

        const submitBtn = $('#editBapSubmitBtn');
        const oldHtml = submitBtn ? submitBtn.innerHTML : '';

        openModal('#baptismEditModal');

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        }

        try {
            const data = await request(ENDPOINT + '?action=get&id=' + encodeURIComponent(id));

            if (!data.success) {
                throw new Error(data.message || 'Record not found.');
            }

            const r = data.item || {};

            $('#editBaptismId').value = r.id || '';
            $('#editFullName').value = r.candidate_name || '';
            $('#editMemberId').value = r.member_id || '';
            $('#editBapType').value = r.baptism_type || 'Infant';
            $('#editBapDate').value = r.baptism_date || '';
            $('#editCertNo').value = r.certificate_no || '';
            $('#editOfficiant').value = r.officiant || '';
            $('#editVenue').value = r.venue || '';
            $('#editGodfather').value = r.godfather || '';
            $('#editGodmother').value = r.godmother || '';
            $('#editParentFather').value = r.parent_father || '';
            $('#editParentMother').value = r.parent_mother || '';
            $('#editNotes').value = r.notes || '';
        } catch (error) {
            closeModals();
            showAlert($('#baptismAlert'), error.message, 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = oldHtml || '<i class="fas fa-save"></i> Update Baptism';
            }
        }
    }

    async function openView(id) {
        const content = $('#viewBaptismContent');
        const certBtn = $('#viewCertificateBtn');

        if (content) {
            content.innerHTML = '<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
        }

        openModal('#viewBaptismModal');

        try {
            const data = await request(ENDPOINT + '?action=get&id=' + encodeURIComponent(id));

            if (!data.success) {
                throw new Error(data.message || 'Record not found.');
            }

            const r = data.item || {};

            const candidate = r.candidate_name || '';

            const fields = [
                ['Baptism No', r.baptism_no],
                ['Candidate', candidate],
                ['Linked Member', r.member_name],
                ['Member No', r.member_no],
                ['Type', r.baptism_type],
                ['Date', r.baptism_date],
                ['Certificate No', r.certificate_no],
                ['Officiant', r.officiant],
                ['Venue', r.venue],
                ['Godfather', r.godfather],
                ['Godmother', r.godmother],
                ['Father / Parent', r.parent_father],
                ['Mother / Parent', r.parent_mother],
                ['Notes', r.notes]
            ];

            if (content) {
                content.innerHTML = fields.map(([label, value]) => `
                    <div class="detail-item">
                        <b>${esc(label)}</b>
                        <span>${esc(value || '-')}</span>
                    </div>
                `).join('');
            }

            if (certBtn) {
                certBtn.href = 'certificate.php?id=' + encodeURIComponent(id);
            }
        } catch (error) {
            if (content) {
                content.innerHTML = `<div class="ajax-alert error">${esc(error.message)}</div>`;
            }
        }
    }

    async function openSignatories() {
        clearAlert($('#signatoriesAlert'));
        openModal('#signatoriesModal');
        try {
            const data = await request(ENDPOINT + '?action=get_signatories');
            if (!data.success) throw new Error(data.message || 'Could not load signatories.');
            const item = data.item || {};
            $('#vicarTitle').value = item.vicar_title || '';
            $('#vicarName').value = item.vicar_name || '';
            $('#secretaryTitle').value = item.secretary_title || '';
            $('#secretaryName').value = item.secretary_name || '';
            $('#vicarSignaturePreview').innerHTML = item.vicar_signature_url
                ? `<img src="${esc(item.vicar_signature_url)}" alt="First signatory signature" style="max-width:220px;max-height:80px;object-fit:contain;border:1px solid #ddd;padding:6px;background:#fff">`
                : '<span class="text-muted">No signature uploaded.</span>';
            $('#secretarySignaturePreview').innerHTML = item.secretary_signature_url
                ? `<img src="${esc(item.secretary_signature_url)}" alt="Second signatory signature" style="max-width:220px;max-height:80px;object-fit:contain;border:1px solid #ddd;padding:6px;background:#fff">`
                : '<span class="text-muted">No signature uploaded.</span>';
        } catch (error) {
            showAlert($('#signatoriesAlert'), error.message, 'error');
        }
    }

    $('#btnOpenSignatories')?.addEventListener('click', openSignatories);

    $('#signatoriesForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearAlert($('#signatoriesAlert'));
        const btn = $('#saveSignatoriesBtn');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        try {
            const data = await postForm(this);
            if (!data.success) throw new Error(data.message || 'Save failed.');
            showAlert($('#signatoriesAlert'), data.message || 'Signatories saved successfully.');
            await openSignatories();
        } catch (error) {
            showAlert($('#signatoriesAlert'), error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    });

    $('#btnOpenAddBaptism')?.addEventListener('click', function () {
        initBulkBapForm();
        openModal('#baptismAddModal');
    });

    $('#btnAddBapRow')?.addEventListener('click', function () {
        const container = $('#bapRowsContainer');
        if (!container) return;

        container.appendChild(createBapRow(getAllBapRows().length + 1));
        refreshBapUI();
        container.scrollTop = container.scrollHeight;
    });

    document.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.remove-bap-row');
        if (removeBtn) {
            removeBtn.closest('.bap-candidate-row')?.remove();
            refreshBapUI();
            return;
        }

        if (e.target.closest('[data-close-baptism]')) {
            closeModals();
            return;
        }

        const actionBtn = e.target.closest('[data-action]');
        if (!actionBtn) return;

        const id = actionBtn.dataset.id;
        const action = actionBtn.dataset.action;

        if (!id) return;

        if (action === 'view') openView(id);
        if (action === 'edit') openEdit(id);

        if (action === 'delete') {
            $('#deleteBaptismId').value = id;
            $('#deleteBaptismName').textContent = actionBtn.dataset.name || 'this record';
            clearAlert($('#deleteAlert'));
            openModal('#deleteBaptismModal');
        }
    });

    $('#baptismBackdrop')?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModals();
    });

    $('#baptismBulkForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();

        clearAlert($('#bulkBapAlert'));

        const candidateRows = getAllBapRows();

        if (!candidateRows.length) {
            showAlert($('#bulkBapAlert'), 'Add at least one candidate.', 'error');
            return;
        }

        let valid = true;

        candidateRows.forEach(row => {
            const fullName = row.querySelector('[name$="[full_name]"]')?.value.trim();

            if (!fullName) {
                row.style.outline = '2px solid var(--danger)';
                valid = false;
            } else {
                row.style.outline = '';
            }
        });

        if (!valid) {
            showAlert($('#bulkBapAlert'), 'Please fill in the Full Name for every candidate.', 'error');
            return;
        }

        const baptismDate = $('#sharedBapDate')?.value || '';
        const baptismType = $('#sharedBapType')?.value || 'Infant';

        if (!baptismDate) {
            showAlert($('#bulkBapAlert'), 'Baptism date is required.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'bulk_create');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('baptism_date', baptismDate);
        fd.append('baptism_type', baptismType);
        fd.append('officiant', $('#sharedOfficiant')?.value || '');
        fd.append('venue', $('#sharedVenue')?.value || '');

        candidateRows.forEach((row, index) => {
            const getVal = name => row.querySelector(`[name$="[${name}]"]`)?.value.trim() || '';

            fd.append(`candidates[${index}][full_name]`, getVal('full_name'));
            fd.append(`candidates[${index}][member_id]`, getVal('member_id'));
            fd.append(`candidates[${index}][certificate_no]`, getVal('certificate_no'));
            fd.append(`candidates[${index}][godfather]`, getVal('godfather'));
            fd.append(`candidates[${index}][godmother]`, getVal('godmother'));
        });

        const btn = $('#bapBulkSubmitBtn');
        const oldHtml = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        try {
            const data = await request(ENDPOINT, {
                method: 'POST',
                body: fd
            });

            if (!data.success) {
                throw new Error(data.message || 'Save failed.');
            }

            closeModals();
            showAlert($('#baptismAlert'), data.message || 'Records saved successfully.');
            await loadBaptisms();
        } catch (error) {
            showAlert($('#bulkBapAlert'), error.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        }
    });

    $('#baptismEditForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();

        clearAlert($('#editFormAlert'));

        const btn = $('#editBapSubmitBtn');
        const oldHtml = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        try {
            const data = await postForm(this);

            if (!data.success) {
                throw new Error(data.message || 'Save failed.');
            }

            closeModals();
            showAlert($('#baptismAlert'), data.message || 'Updated successfully.');
            await loadBaptisms();
        } catch (error) {
            showAlert($('#editFormAlert'), error.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        }
    });

    $('#deleteBaptismForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();

        clearAlert($('#deleteAlert'));

        try {
            const data = await postForm(this);

            if (!data.success) {
                throw new Error(data.message || 'Delete failed.');
            }

            closeModals();
            showAlert($('#baptismAlert'), data.message || 'Deleted successfully.');
            await loadBaptisms();
        } catch (error) {
            showAlert($('#deleteAlert'), error.message, 'error');
        }
    });

    $$('.stat-card[data-filter]').forEach(card => {
        card.addEventListener('click', function () {
            const typeFilter = $('#typeFilter');
            if (typeFilter) typeFilter.value = this.dataset.filter || '';
            applyFilters();
        });
    });

    $('#baptismSearch')?.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            loadBaptisms(true);
        }, 350);
    });

    $('#typeFilter')?.addEventListener('change', function () {
        loadBaptisms(true);
    });

    $('#periodFilter')?.addEventListener('change', function () {
        toggleRangeFields();

        if (this.value !== 'range') {
            loadBaptisms(true);
        }
    });

    $('#baptismStartDate')?.addEventListener('change', function () {
        if ($('#baptismEndDate')?.value) loadBaptisms(true);
    });

    $('#baptismEndDate')?.addEventListener('change', function () {
        if ($('#baptismStartDate')?.value) loadBaptisms(true);
    });

    $('#baptismPerPage')?.addEventListener('change', function () {
        loadBaptisms(true);
    });

    $('#btnRefreshBaptism')?.addEventListener('click', function () {
        if ($('#baptismSearch')) $('#baptismSearch').value = '';
        if ($('#typeFilter')) $('#typeFilter').value = '';
        if ($('#periodFilter')) $('#periodFilter').value = 'all';
        if ($('#baptismStartDate')) $('#baptismStartDate').value = '';
        if ($('#baptismEndDate')) $('#baptismEndDate').value = '';
        if ($('#baptismPerPage')) $('#baptismPerPage').value = '10';
        toggleRangeFields();
        loadBaptisms(true);
    });

    $('#baptismPagination')?.addEventListener('click', function (event) {
        const button = event.target.closest('[data-page]');
        if (!button || button.disabled) return;

        const requestedPage = Number(button.dataset.page || 1);
        if (requestedPage < 1 || requestedPage === currentPage) return;

        currentPage = requestedPage;
        loadBaptisms(false);
        document.querySelector('.module-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    $$('.baptism-stat-card[data-filter]').forEach(card => {
        card.addEventListener('click', function () {
            if ($('#typeFilter')) $('#typeFilter').value = this.dataset.filter || '';
            loadBaptisms(true);
        });
    });

    toggleRangeFields();
    loadBaptisms(true);
});
</script>

<?php include '../includes/footer.php'; ?>