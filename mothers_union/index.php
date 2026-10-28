<?php
require_once '../config/config.php';
requireLogin();

$pageTitle = "Mothers' Union";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function mu_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mu_scalar(mysqli $conn, string $sql): int {
    try { $res = $conn->query($sql); if (!$res) return 0; $row = $res->fetch_row(); return (int)($row[0] ?? 0); }
    catch (Throwable $e) { return 0; }
}
function mu_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

$hasMU      = mu_table_exists($conn, 'mothers_union');
$hasMembers = mu_table_exists($conn, 'members');
$csrf       = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

$total    = $hasMU ? mu_scalar($conn, "SELECT COUNT(*) FROM mothers_union") : 0;
$active   = $hasMU ? mu_scalar($conn, "SELECT COUNT(*) FROM mothers_union WHERE COALESCE(status,'Active')='Active'") : 0;
$inactive = max(0, $total - $active);
$subs     = $hasMU ? mu_scalar($conn, "SELECT COALESCE(SUM(subscription),0) FROM mothers_union") : 0;

/* Female members not yet enrolled */
$members = [];
if ($hasMembers && $hasMU) {
    $sql = "SELECT m.id, m.member_no, m.full_name, m.phone
            FROM members m
            WHERE LOWER(COALESCE(m.gender,'')) IN ('female','f')
              AND COALESCE(m.status,'Active')='Active'
              AND m.id NOT IN (SELECT member_id FROM mothers_union WHERE member_id IS NOT NULL)
            ORDER BY m.full_name";
    $res = $conn->query($sql);
    while ($res && $row = $res->fetch_assoc()) $members[] = $row;
}

$positions = ['Member','Chairperson','Vice Chairperson','Secretary','Treasurer','Chaplain','Prayer Leader'];
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>

<div class="content-area">

    <!-- -- Page header ---------------------------------------- -->
    <div class="module-page-header">
        <div class="module-title">
            <h2><i class="fas fa-female"></i> Mothers' Union</h2>
            <p>Manage Mothers' Union enrollment, subscriptions and receipts.</p>
        </div>
        <div class="module-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <!-- Export group -->
            <div style="display:flex;gap:6px">
                <button type="button" class="btn btn-outline btn-sm" id="muBtnExportCSV"
                        title="Export members list as CSV">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <button type="button" class="btn btn-outline btn-sm" id="muBtnExportPDF"
                        title="Export members list as PDF">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>
            <button type="button" class="btn btn-primary" id="btnOpenMU">
                <i class="fas fa-user-plus"></i> Enrol Members
            </button>
        </div>
    </div>

    <!-- Export filter bar (hidden until Export CSV/PDF clicked) -->
    <div id="muExportBar" class="mu-export-bar" style="display:none">
        <span style="font-weight:700;font-size:13px"><i class="fas fa-filter"></i> Export filters</span>
        <select id="muExportStatus" class="form-control" style="width:auto;min-width:160px">
            <option value="">All Statuses</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Suspended">Suspended</option>
        </select>
        <button type="button" class="btn btn-primary btn-sm" id="muBtnExportBarGo">
            <i class="fas fa-download"></i> Download
        </button>
        <button type="button" class="btn btn-outline btn-sm" id="muBtnExportBarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <?php echo function_exists('showFlash') ? showFlash() : ''; ?>

    <?php if (!$hasMU || !$hasMembers): ?>
        <div class="info-box danger">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <h4>Setup Required</h4>
                <p>The <code>mothers_union</code> and <code>members</code> tables must exist before using this module.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- -- Stats ---------------------------------------------- -->
    <div class="stats-grid module-stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:color-mix(in srgb,var(--primary) 12%,transparent);color:var(--primary)">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="muTotal"><?= (int)$total ?></div>
                <div class="stat-label">Total Enrolled</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(40,167,69,.12);color:var(--success)">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="muActive"><?= (int)$active ?></div>
                <div class="stat-label">Active Members</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(212,160,23,.15);color:var(--accent)">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-body">
                <div class="stat-number" id="muSubs">UGX <?= number_format($subs) ?></div>
                <div class="stat-label">Total Subscriptions</div>
            </div>
        </div>
    </div>

    <!-- -- Table card ----------------------------------------- -->
    <div class="card module-card">
        <div class="card-body">
            <div class="page-toolbar">
<div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="muSearch" class="form-control" placeholder="Search name, number, phone..."></div>
<div class="page-toolbar-filters">
<select id="muPositionFilter" class="form-control"><option value="">All Positions</option><option value="Member">Member</option><option value="Chairperson">Chairperson</option><option value="Vice Chairperson">Vice Chairperson</option><option value="Secretary">Secretary</option><option value="Treasurer">Treasurer</option><option value="Chaplain">Chaplain</option><option value="Prayer Leader">Prayer Leader</option></select>
<select id="muStatusFilter" class="form-control"><option value="">All Statuses</option><option>Active</option><option>Inactive</option><option>Suspended</option></select>
<select id="muPeriodFilter" class="form-control"><option value="">All Dates</option><option value="daily">Today</option><option value="weekly">This Week</option><option value="range">Custom Range</option></select>
<select id="muPerPage" class="form-control"><option value="10">10 per page</option><option value="25" selected>25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select>
</div>
<div class="page-toolbar-actions">
<button type="button" class="btn btn-outline-secondary" id="muClearFilters"><i class="fas fa-undo"></i> Clear</button>
<button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefreshMU"><i class="fas fa-rotate-right"></i> Refresh</button>
</div>
</div>
<div class="union-date-range" id="muDateRange" hidden><label>From<input type="date" id="muDateFrom" class="form-control"></label><label>To<input type="date" id="muDateTo" class="form-control"></label><button type="button" class="btn btn-primary" id="muApplyRange"><i class="fas fa-filter"></i> Apply Range</button></div>
<div id="muAlert" class="ajax-alert"></div>

            <div class="module-table-wrap">
                <table class="table module-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>MU No.</th>
                            <th>Full Name</th>
                            <th>Phone</th>
                            <th>Position</th>
                            <th>Date Joined</th>
                            <th>Subscription</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="muTableBody">
                        <tr>
                            <td colspan="9" class="text-muted text-center" style="padding:28px">
                                <i class="fas fa-spinner fa-spin"></i> Loading records...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="union-pagination-bar"><div id="muPaginationSummary" class="union-pagination-summary">Showing 0 records</div><div id="muPagination" class="union-pagination"></div></div>
        </div>
    </div>


    <!-- ======================================================
         MODALS
    ====================================================== -->
    <div class="app-modal-backdrop" id="muBackdrop"></div>

    <!-- -- Enrol / Edit modal ---------------------------------- -->
    <div class="app-modal" id="muFormModal" aria-hidden="true">
        <div class="app-dialog app-dialog-lg">
            <form id="muForm" autocomplete="off">
                <div class="app-modal-header">
                    <h3 id="muFormTitle">
                        <i class="fas fa-user-plus"></i> Enrol Members
                    </h3>
                    <button type="button" class="modal-close" data-mu-close>&times;</button>
                </div>

                <div class="app-modal-body">
                    <div id="muFormAlert" class="ajax-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= mu_h($csrf) ?>">
                    <input type="hidden" name="action"     id="muAction" value="create">
                    <input type="hidden" name="id"         id="muId"     value="">

                    <!-- Member picker (create mode) -->
                    <div id="muMemberPickerWrap">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-female"></i>
                                Select Members to Enrol
                                <span class="req">*</span>
                                <span class="badge badge-primary ml-2" id="muPickCount">0 selected</span>
                            </label>

                            <div style="position:relative;margin-bottom:6px">
                                <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#aaa;font-size:12px"></i>
                                <input type="text" id="muMemberSearch"
                                       class="form-control"
                                       style="padding-left:32px"
                                       placeholder="Filter by name or phone...">
                            </div>

                            <div id="muMemberChecklist" style="
                                max-height:250px;overflow-y:auto;
                                border:2px solid #e1d9f3;border-radius:8px;
                                background:#fff;padding:4px 0">

                                <label class="mu-check-row mu-check-all" style="
                                    display:flex;align-items:center;gap:10px;
                                    padding:8px 14px;cursor:pointer;
                                    border-bottom:2px solid #f0ecfa;
                                    font-weight:700;font-size:12.5px;color:var(--primary)">
                                    <input type="checkbox" id="muSelectAll"
                                           style="width:16px;height:16px;accent-color:var(--primary)">
                                    Select All Female Members
                                    <span id="muPickTotal" class="text-muted" style="font-size:11px;margin-left:auto">
                                        <?= count($members) ?> available
                                    </span>
                                </label>

                                <?php if ($members): foreach ($members as $m):
                                    $fullName = mu_h(trim((string)($m['full_name'] ?? '')));
                                    $phone    = $m['phone']     ? ' . '.mu_h($m['phone'])     : '';
                                    $memNo    = $m['member_no'] ? ' ('.mu_h($m['member_no']).')' : '';
                                ?>
                                <label class="mu-check-row"
                                       data-name="<?= strtolower($fullName) ?> <?= strtolower(mu_h($m['phone'] ?? '')) ?>"
                                       style="display:flex;align-items:center;gap:10px;
                                              padding:8px 14px;cursor:pointer;
                                              border-bottom:1px solid #f5f2fb;
                                              font-size:13px;transition:background .12s">
                                    <input type="checkbox" name="member_ids[]"
                                           value="<?= (int)$m['id'] ?>"
                                           class="mu-member-cb"
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
                                    All eligible female members are already enrolled.
                                </div>
                                <?php endif; ?>
                            </div><!-- /#muMemberChecklist -->
                        </div>

                        <div id="muBulkNote" class="info-box" style="display:none;margin-bottom:14px">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <p>The same position, date, subscription, and status will be applied to all
                                   selected members. You can edit individual records afterwards.</p>
                            </div>
                        </div>
                    </div><!-- /#muMemberPickerWrap -->

                    <!-- Edit mode: show member name (read-only) -->
                    <div id="muEditMemberInfo" style="display:none;margin-bottom:16px">
                        <div class="info-box">
                            <i class="fas fa-user"></i>
                            <div>
                                <h4 id="muEditMemberName" style="margin:0"></h4>
                                <p style="margin:0" class="text-muted">Editing Mothers' Union record</p>
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
                            <select name="position" id="muPosition" class="form-control">
                                <?php foreach ($positions as $p): ?>
                                    <option value="<?= mu_h($p) ?>"><?= mu_h($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Date Joined</label>
                            <input type="date" name="date_joined" id="muDateJoined"
                                   class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-group">
                            <label>Annual Subscription (UGX)</label>
                            <input type="number" min="0" step="0.01" name="subscription"
                                   id="muSubscription" class="form-control" value="0">
                        </div>

                        <div class="form-group">
                            <label>Payment Method</label>
                            <select name="payment_method" id="muPaymentMethod" class="form-control">
                                <option>Cash</option>
                                <option>Mobile Money</option>
                                <option>Bank Transfer</option>
                                <option>Cheque</option>
                                <option>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="muStatus" class="form-control">
                                <option>Active</option>
                                <option>Inactive</option>
                                <option>Suspended</option>
                            </select>
                        </div>

                        <div class="form-group full-span">
                            <label>Notes</label>
                            <textarea name="notes" id="muNotes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div><!-- /.app-modal-body -->

                <div class="app-modal-footer">
                    <button type="button" class="btn btn-outline" data-mu-close>Cancel</button>
                    <button type="submit" class="btn btn-primary" id="muSaveBtn">
                        <i class="fas fa-save"></i>
                        <span id="muSaveBtnLabel">Enrol</span>
                    </button>
                </div>
            </form>
        </div>
    </div><!-- /#muFormModal -->


    <!-- -- View / profile modal -------------------------------- -->
    <div class="app-modal" id="muViewModal" aria-hidden="true">
        <div class="app-dialog app-dialog-lg">
            <div class="app-modal-header">
                <h3><i class="fas fa-eye"></i> Mothers' Union Details</h3>
                <button type="button" class="modal-close" data-mu-close>&times;</button>
            </div>
            <div class="app-modal-body">
                <div id="muViewContent" class="detail-grid"></div>

                <h4 class="mt-3">
                    <i class="fas fa-receipt"></i> Payment History
                </h4>
                <div class="module-table-wrap">
                    <table class="table module-table" style="min-width:680px">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Receipt No</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Email</th>
                                <th>SMS</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="muPaymentHistory">
                            <tr><td colspan="8" class="text-muted">No payment history loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="app-modal-footer">
                <button type="button" class="btn btn-outline" data-mu-close>Close</button>
            </div>
        </div>
    </div><!-- /#muViewModal -->


    <!-- -- Delete modal ---------------------------------------- -->
    <div class="app-modal" id="muDeleteModal" aria-hidden="true">
        <div class="app-dialog">
            <form id="muDeleteForm">
                <div class="app-modal-header">
                    <h3><i class="fas fa-trash"></i> Delete Record</h3>
                    <button type="button" class="modal-close" data-mu-close>&times;</button>
                </div>
                <div class="app-modal-body">
                    <div id="muDeleteAlert" class="ajax-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= mu_h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     id="muDeleteId">
                    <p>Remove <strong id="muDeleteName"></strong> from Mothers' Union?</p>
                    <p class="text-muted small mt-2">
                        This will permanently delete the member's enrolment record and payment history.
                    </p>
                </div>
                <div class="app-modal-footer">
                    <button type="button" class="btn btn-outline" data-mu-close>Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div><!-- /#muDeleteModal -->

    <?php endif; ?>

</div><!-- /.content-area -->
</div><!-- /.main-content -->

<style>
.mu-export-bar {
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
window.MothersUnionConfig = {
    endpoint : 'process-mothers-union.php',
    today    : '<?= date('Y-m-d') ?>'
};
</script>
<script src="mothers-union.js"></script>
<?php include '../includes/footer.php'; ?>