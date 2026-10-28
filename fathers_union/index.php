<?php
declare(strict_types=1);

require_once '../config/config.php';
if (function_exists('requireLogin')) requireLogin();

$pageTitle = "Fathers' Union";

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

if (!function_exists('fu_h')) {
    function fu_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function fu_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function fu_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache = [];
    $key = "$table.$col"; if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    if (!$stmt) return $cache[$key] = false;
    $stmt->bind_param('ss', $table, $col); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function fu_scalar(mysqli $conn, string $sql): int {
    try { $r = $conn->query($sql); if (!$r) return 0; $v = $r->fetch_row()[0] ?? 0; $r->close(); return (int)$v; }
    catch (Throwable $e) { return 0; }
}
function fu_money($amount): string { return 'UGX ' . number_format((float)($amount ?? 0)); }

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

$hasFU      = fu_table_exists($conn, 'fathers_union');
$hasMembers = fu_table_exists($conn, 'members');

$totalMembers    = $hasFU ? fu_scalar($conn, "SELECT COUNT(*) FROM fathers_union") : 0;
$activeMembers   = $hasFU ? fu_scalar($conn, "SELECT COUNT(*) FROM fathers_union WHERE COALESCE(status,'Active')='Active'") : 0;
$inactiveMembers = $hasFU ? fu_scalar($conn, "SELECT COUNT(*) FROM fathers_union WHERE COALESCE(status,'Active')<>'Active'") : 0;

$totalSubs = 0.0;
if ($hasFU && fu_col_exists($conn, 'fathers_union', 'subscription')) {
    try {
        $res = $conn->query("SELECT COALESCE(SUM(subscription),0) FROM fathers_union");
        if ($res) { $totalSubs = (float)($res->fetch_row()[0] ?? 0); $res->close(); }
    } catch (Throwable $e) {}
}

/* Male members not yet enrolled */
$members = [];
if ($hasMembers && $hasFU) {
    try {
        $res = $conn->query("
            SELECT m.id, m.full_name, m.member_no, m.phone
            FROM members m
            WHERE LOWER(COALESCE(m.gender,'')) IN ('male','m')
              AND COALESCE(m.status,'Active') = 'Active'
              AND m.id NOT IN (SELECT member_id FROM fathers_union WHERE member_id IS NOT NULL)
            ORDER BY m.full_name
        ");
        while ($res && $row = $res->fetch_assoc()) $members[] = $row;
    } catch (Throwable $e) {}
}

$positions = ['Member','Chairman','Vice Chairman','Secretary','Treasurer','Chaplain'];
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

    <!-- -- Page header ---------------------------------------- -->
    <div class="module-page-header">
        <div class="module-title">
            <h2><i class="fas fa-male"></i> Fathers' Union</h2>
            <p>Manage Fathers' Union members, subscriptions, confirmations, and payment history.</p>
        </div>
        <div class="module-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <!-- Export group -->
            <div style="display:flex;gap:6px">
                <button type="button" class="btn btn-outline btn-sm" id="fuBtnExportCSV"
                        title="Export members list as CSV">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <button type="button" class="btn btn-outline btn-sm" id="fuBtnExportPDF"
                        title="Export members list as PDF" >
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>
            <button type="button" class="btn btn-primary" id="btnOpenFuModal">
                <i class="fas fa-user-plus"></i> Enrol Members
            </button>
        </div>
    </div>

    <!-- Export filter bar (hidden until Export CSV/PDF clicked) -->
    <div id="fuExportBar" class="fu-export-bar" style="display:none">
        <span style="font-weight:700;font-size:13px"><i class="fas fa-filter"></i> Export filters</span>
        <select id="fuExportStatus" class="form-control" style="width:auto;min-width:160px">
            <option value="">All Statuses</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Suspended">Suspended</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm" id="fuBtnExportBarGo">
            <i class="fas fa-download"></i> Download
        </button>
        <button type="button" class="btn btn-outline btn-sm" id="fuBtnExportBarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <?php if (function_exists('showFlash')) showFlash(); ?>

    <?php if (!$hasFU || !$hasMembers): ?>
        <div class="info-box danger">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <h4>Setup Required</h4>
                <p>The <code>fathers_union</code> and <code>members</code> tables must exist before using this module.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- -- Stats (4 cards) ------------------------------------ -->
    <div class="stats-grid module-stats-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">

        <div class="stat-card">
            <div class="stat-icon" style="background:color-mix(in srgb,var(--primary) 12%,transparent);color:var(--primary)">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="fuStatTotal"><?= (int)$totalMembers ?></div>
                <div class="stat-label">Total Enrolled</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(40,167,69,.12);color:var(--success)">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="fuStatActive"><?= (int)$activeMembers ?></div>
                <div class="stat-label">Active Members</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(108,117,125,.12);color:#6c757d">
                <i class="fas fa-pause-circle"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="fuStatInactive"><?= (int)$inactiveMembers ?></div>
                <div class="stat-label">Inactive / Suspended</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(212,160,23,.15);color:var(--accent)">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="fuStatSubs"><?= fu_h(fu_money($totalSubs)) ?></div>
                <div class="stat-label">Subscriptions</div>
            </div>
        </div>

    </div>

    <!-- -- Table card ----------------------------------------- -->
    <div class="card module-card">
        <div class="card-body">
            <div class="page-toolbar">
<div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="fuSearch" class="form-control" placeholder="Search name, number, phone..."></div>
<div class="page-toolbar-filters">
<select id="fuPositionFilter" class="form-control"><option value="">All Positions</option><option value="Member">Member</option><option value="Chairperson">Chairperson</option><option value="Vice Chairperson">Vice Chairperson</option><option value="Secretary">Secretary</option><option value="Treasurer">Treasurer</option><option value="Chaplain">Chaplain</option><option value="Prayer Leader">Prayer Leader</option></select>
<select id="fuStatusFilter" class="form-control"><option value="">All Statuses</option><option>Active</option><option>Inactive</option><option>Suspended</option></select>
<select id="fuPeriodFilter" class="form-control"><option value="">All Dates</option><option value="daily">Today</option><option value="weekly">This Week</option><option value="range">Custom Range</option></select>
<select id="fuPerPage" class="form-control"><option value="10">10 per page</option><option value="25" selected>25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select>
</div>
<div class="page-toolbar-actions">
<button type="button" class="btn btn-outline-secondary" id="fuClearFilters"><i class="fas fa-undo"></i> Clear</button>
<button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshFu"><i class="fas fa-rotate-right"></i> Refresh</button>
</div>
</div>
<div class="union-date-range" id="fuDateRange" hidden><label>From<input type="date" id="fuDateFrom" class="form-control"></label><label>To<input type="date" id="fuDateTo" class="form-control"></label><button type="button" class="btn btn-primary" id="fuApplyRange"><i class="fas fa-filter"></i> Apply Range</button></div>
<div id="fuAlert" class="ajax-alert"></div>

            <div class="module-table-wrap">
                <table class="table module-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>FU No.</th>
                            <th>Full Name</th>
                            <th>Phone</th>
                            <th>Position</th>
                            <th>Date Joined</th>
                            <th>Subscription</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fuTableBody">
                        <tr>
                            <td colspan="9" class="text-muted text-center" style="padding:28px">
                                <i class="fas fa-spinner fa-spin"></i> Loading records...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="union-pagination-bar"><div id="fuPaginationSummary" class="union-pagination-summary">Showing 0 records</div><div id="fuPagination" class="union-pagination"></div></div>
        </div>
    </div>


    <!-- ======================================================
         MODALS
    ====================================================== -->
    <div class="app-modal-backdrop" id="fuBackdrop"></div>

    <!-- -- Enrol / Edit modal ---------------------------------- -->
    <div class="app-modal" id="fuFormModal" aria-hidden="true">
        <div class="app-dialog app-dialog-lg">
            <form id="fuForm" autocomplete="off">
                <div class="app-modal-header">
                    <h3 id="fuFormTitle">
                        <i class="fas fa-user-plus"></i> Enrol Fathers' Union Members
                    </h3>
                    <button type="button" class="modal-close" data-fu-close>&times;</button>
                </div>

                <div class="app-modal-body">
                    <div id="fuFormAlert" class="ajax-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= fu_h($csrfToken) ?>">
                    <input type="hidden" name="action"     id="fuAction" value="create">
                    <input type="hidden" name="id"         id="fuId"     value="">

                    <!-- Member picker (create mode) -->
                    <div id="fuMemberPickerWrap">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-male"></i>
                                Select Members to Enrol
                                <span class="req">*</span>
                                <span class="badge badge-primary ml-2" id="fuPickCount">0 selected</span>
                            </label>

                            <div style="position:relative;margin-bottom:6px">
                                <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#aaa;font-size:12px"></i>
                                <input type="text" id="fuMemberSearch"
                                       class="form-control"
                                       style="padding-left:32px"
                                       placeholder="Filter by name or phone...">
                            </div>

                            <div id="fuMemberChecklist" style="
                                max-height:250px;overflow-y:auto;
                                border:2px solid #e1d9f3;border-radius:8px;
                                background:#fff;padding:4px 0">

                                <label class="fu-check-row fu-check-all" style="
                                    display:flex;align-items:center;gap:10px;
                                    padding:8px 14px;cursor:pointer;
                                    border-bottom:2px solid #f0ecfa;
                                    font-weight:700;font-size:12.5px;color:var(--primary)">
                                    <input type="checkbox" id="fuSelectAll"
                                           style="width:16px;height:16px;accent-color:var(--primary)">
                                    Select All Male Members
                                    <span id="fuPickTotal" class="text-muted"
                                          style="font-size:11px;margin-left:auto">
                                        <?= count($members) ?> available
                                    </span>
                                </label>

                                <?php if ($members): foreach ($members as $m):
                                    $fullName = fu_h(trim((string)($m['full_name'] ?? '')));
                                    $phone    = $m['phone']     ? ' . '.fu_h($m['phone'])     : '';
                                    $memNo    = $m['member_no'] ? ' ('.fu_h($m['member_no']).')' : '';
                                ?>
                                <label class="fu-check-row"
                                       data-name="<?= strtolower($fullName) ?> <?= strtolower(fu_h($m['phone'] ?? '')) ?>"
                                       style="display:flex;align-items:center;gap:10px;
                                              padding:8px 14px;cursor:pointer;
                                              border-bottom:1px solid #f5f2fb;
                                              font-size:13px;transition:background .12s">
                                    <input type="checkbox" name="member_ids[]"
                                           value="<?= (int)$m['id'] ?>"
                                           class="fu-member-cb"
                                           style="width:16px;height:16px;accent-color:var(--primary);flex-shrink:0">
                                    <div style="flex:1;min-width:0">
                                        <strong style="display:block;line-height:1.2">
                                            <?= $fullName ?><?= $memNo ?>
                                        </strong>
                                        <small class="text-muted"><?= $phone ?></small>
                                    </div>
                                </label>
                                <?php endforeach; else: ?>
                                <div class="text-muted" style="padding:18px 14px;text-align:center">
                                    <i class="fas fa-info-circle"></i>
                                    All eligible male members are already enrolled.
                                </div>
                                <?php endif; ?>

                            </div><!-- /#fuMemberChecklist -->
                        </div>

                        <div id="fuBulkNote" class="info-box" style="display:none;margin-bottom:14px">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <p>The same position, date, subscription, and status will be applied to all
                                   selected members. You can edit individual records afterwards.</p>
                            </div>
                        </div>
                    </div><!-- /#fuMemberPickerWrap -->

                    <!-- Edit mode: read-only member name -->
                    <div id="fuEditMemberInfo" style="display:none;margin-bottom:16px">
                        <div class="info-box">
                            <i class="fas fa-user-tie"></i>
                            <div>
                                <h4 id="fuEditMemberName" style="margin:0"></h4>
                                <p style="margin:0" class="text-muted">Editing Fathers' Union record</p>
                            </div>
                        </div>
                    </div>

                    <!-- Shared fields -->
                    <div class="form-section-title">
                        <i class="fas fa-info-circle"></i> Enrolment Details
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Position / Role</label>
                            <select name="position" id="fuPosition" class="form-control">
                                <?php foreach ($positions as $p): ?>
                                    <option value="<?= fu_h($p) ?>"><?= fu_h($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Date Joined</label>
                            <input type="date" name="date_joined" id="fuDateJoined"
                                   class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-group">
                            <label>Annual Subscription (UGX)</label>
                            <input type="number" name="subscription" id="fuSubscription"
                                   class="form-control" min="0" step="0.01" value="0">
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="fuStatus" class="form-control">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Suspended">Suspended</option>
                            </select>
                        </div>

                        <div class="form-group full-span">
                            <label>Notes</label>
                            <textarea name="notes" id="fuNotes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div><!-- /.app-modal-body -->

                <div class="app-modal-footer">
                    <button type="button" class="btn btn-outline" data-fu-close>Cancel</button>
                    <button type="submit" class="btn btn-primary" id="fuSaveBtn">
                        <i class="fas fa-save"></i>
                        <span id="fuSaveBtnLabel">Enrol</span>
                    </button>
                </div>
            </form>
        </div>
    </div><!-- /#fuFormModal -->


    <!-- -- View / profile modal -------------------------------- -->
    <div class="app-modal" id="fuViewModal" aria-hidden="true">
        <div class="app-dialog app-dialog-lg">
            <div class="app-modal-header">
                <h3><i class="fas fa-eye"></i> Fathers' Union Member</h3>
                <button type="button" class="modal-close" data-fu-close>&times;</button>
            </div>
            <div class="app-modal-body">
                <div id="fuViewContent" class="detail-grid"></div>

                <h4 class="mt-3">
                    <i class="fas fa-money-bill-wave"></i> Payment History
                </h4>
                <div class="module-table-wrap">
                    <table class="table module-table" style="min-width:560px">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Receipt No</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="fuPaymentRows">
                            <tr><td colspan="6" class="text-muted">No payment history.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="app-modal-footer">
                <button type="button" class="btn btn-outline" data-fu-close>Close</button>
            </div>
        </div>
    </div><!-- /#fuViewModal -->


    <!-- -- Delete modal ---------------------------------------- -->
    <div class="app-modal" id="fuDeleteModal" aria-hidden="true">
        <div class="app-dialog">
            <form id="fuDeleteForm">
                <div class="app-modal-header">
                    <h3><i class="fas fa-trash"></i> Remove Fathers' Union Member</h3>
                    <button type="button" class="modal-close" data-fu-close>&times;</button>
                </div>
                <div class="app-modal-body">
                    <div id="fuDeleteAlert" class="ajax-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= fu_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     id="fuDeleteId" value="">
                    <p>Are you sure you want to remove
                       <strong id="fuDeleteName">this member</strong>
                       from Fathers' Union?
                    </p>
                    <p class="text-muted small mt-2">
                        This removes the enrolment record only � the main church member record is unchanged.
                    </p>
                </div>
                <div class="app-modal-footer">
                    <button type="button" class="btn btn-outline" data-fu-close>Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </form>
        </div>
    </div><!-- /#fuDeleteModal -->

    <?php endif; ?>

</div><!-- /.content-area -->
</div><!-- /.main-content -->

<style>
.fu-export-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    padding: 10px 16px;
    background: #f5f1ff;
    border: 1px solid #c4b5fd;
    border-radius: 12px;
}
</style>

<style>
.union-date-range{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:12px 0}.union-date-range[hidden]{display:none!important}.union-date-range label{display:grid;gap:5px;font-size:12px;font-weight:700}.union-pagination-bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 0 2px}.union-pagination-summary{color:var(--text-muted,#64748b);font-size:12px}.union-pagination{display:flex;gap:6px;flex-wrap:wrap}.union-pagination button{min-width:36px;height:36px;border:1px solid var(--border,#dbe1ea);border-radius:8px;background:#fff;cursor:pointer}.union-pagination button.active{background:var(--primary,#4a1e8f);color:#fff;border-color:var(--primary,#4a1e8f)}.union-pagination button:disabled{opacity:.5;cursor:not-allowed}
</style>
<script>
window.FathersUnionConfig = {
    endpoint  : 'process-fathers-union.php',
    csrfToken : '<?= fu_h($csrfToken) ?>',
    today     : '<?= date('Y-m-d') ?>'
};
</script>
<script src="fathers-union.js"></script>
<?php include '../includes/footer.php'; ?>