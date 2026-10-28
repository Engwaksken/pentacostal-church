<?php
$pageTitle = 'Youth Registry';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$conn->set_charset('utf8mb4');

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

/**
 * Absolute path (no domain) to the directory this script lives in on disk.
 * Used to build a reliable AJAX endpoint URL regardless of how the page
 * itself was requested (direct file, or via a router/query-string page=).
 */
function yr_script_dir(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return rtrim($dir, '/');
}

function yr_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function yr_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache = [];
    $key = $table.'.'.$col; if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $col); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function yr_scalar(mysqli $conn, string $sql): int {
    try { $r = $conn->query($sql); if (!$r) return 0; $v = $r->fetch_row()[0] ?? 0; $r->close(); return (int)$v; }
    catch (Throwable $e) { return 0; }
}

$hasYouth   = yr_table_exists($conn, 'youth');
$hasMembers = yr_table_exists($conn, 'members');
$csrfToken  = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

/* Endpoint used by the page's AJAX calls � always resolved relative to
   this script's real location on disk, not the browser's displayed URL. */
$processEndpoint = yr_script_dir() . '/process-youth.php';

$totalYouth    = $hasYouth ? yr_scalar($conn, "SELECT COUNT(*) FROM youth") : 0;
$activeYouth   = ($hasYouth && yr_col_exists($conn,'youth','status'))
    ? yr_scalar($conn, "SELECT COUNT(*) FROM youth WHERE COALESCE(status,'Active')='Active'") : $totalYouth;
$inactiveYouth = ($hasYouth && yr_col_exists($conn,'youth','status'))
    ? yr_scalar($conn, "SELECT COUNT(*) FROM youth WHERE COALESCE(status,'Active')<>'Active'") : 0;
$maleYouth     = ($hasYouth && $hasMembers)
    ? yr_scalar($conn, "SELECT COUNT(*) FROM youth y JOIN members m ON m.id=y.member_id WHERE LOWER(COALESCE(m.gender,'')) IN ('male','m')") : 0;
$femaleYouth   = ($hasYouth && $hasMembers)
    ? yr_scalar($conn, "SELECT COUNT(*) FROM youth y JOIN members m ON m.id=y.member_id WHERE LOWER(COALESCE(m.gender,'')) IN ('female','f')") : 0;

/* Members available for registration (not yet youth) */
$memberOptions = [];
if ($hasMembers && $hasYouth) {
    try {
        $res = $conn->query("
            SELECT m.id, m.full_name, m.gender, m.phone
            FROM members m
            WHERE COALESCE(m.status,'Active')='Active'
              AND m.id NOT IN (SELECT member_id FROM youth WHERE member_id IS NOT NULL)
            ORDER BY m.full_name
        ");
        while ($res && $row = $res->fetch_assoc()) $memberOptions[] = $row;
        if ($res) $res->close();
    } catch (Throwable $e) {}
}

?>

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area">

        <!-- -- Page header ------------------------------------ -->
        <div class="module-page-header">
            <div class="module-title">
                <h2><i class="fa fa-user-graduate"></i> Youth Registry</h2>
                <p>Registered youth members</p>
            </div>
            <div class="module-actions">
                <a href="process-youth.php?action=export_csv" class="btn btn-success" id="yrExportCsv">
                    <i class="fa fa-file-csv"></i> Export CSV
                </a>
                <a href="process-youth.php?action=export_pdf" class="btn btn-danger" id="yrExportPdf" target="_blank">
                    <i class="fa fa-file-pdf"></i> Export PDF
                </a>
                <button type="button" class="btn btn-primary" id="btnOpenAddYouth">
                    <i class="fa fa-plus"></i> Register Youth
                </button>
            </div>
        </div>

        <?php if (function_exists('showFlash')) showFlash(); ?>

        <?php if (!$hasYouth || !$hasMembers): ?>
            <div class="info-box danger">
                <i class="fa fa-exclamation-triangle"></i>
                <div>
                    <h4>Setup Required</h4>
                    <p>The <code>youth</code> and <code>members</code> tables must exist before using this module.</p>
                </div>
            </div>
        <?php else: ?>

        <!-- -- Stats ------------------------------------------ -->
        <div class="stats-grid module-stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(74,30,143,.12);color:var(--primary)"><i class="fa fa-users"></i></div>
                <div class="stat-body"><div class="stat-number" id="yrStatTotal"><?= (int)$totalYouth ?></div><div class="stat-label">Total Youth</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(40,167,69,.12);color:var(--success)"><i class="fa fa-check-circle"></i></div>
                <div class="stat-body"><div class="stat-number" id="yrStatActive"><?= (int)$activeYouth ?></div><div class="stat-label">Active Youth</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(108,117,125,.12);color:#6c757d"><i class="fa fa-pause-circle"></i></div>
                <div class="stat-body"><div class="stat-number" id="yrStatInactive"><?= (int)$inactiveYouth ?></div><div class="stat-label">Inactive Youth</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(23,162,184,.12);color:var(--info)"><i class="fa fa-mars"></i></div>
                <div class="stat-body"><div class="stat-number" id="yrStatMale"><?= (int)$maleYouth ?></div><div class="stat-label">Male Youth</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(220,53,69,.12);color:var(--danger)"><i class="fa fa-venus"></i></div>
                <div class="stat-body"><div class="stat-number" id="yrStatFemale"><?= (int)$femaleYouth ?></div><div class="stat-label">Female Youth</div></div>
            </div>
        </div>

        <!-- -- Table card ------------------------------------- -->
        <div class="page-toolbar">
                    <div class="page-toolbar-search">
                        <i class="fa fa-search"></i>
                        <input type="search" id="yrSearch" class="form-control"
                               placeholder="Search by name, phone, education...">
                    </div>

                    <div class="page-toolbar-filters">
                    <select id="yrPeriod" class="form-control" aria-label="Filter registration period">
                        <option value="">All periods</option>
                        <option value="daily">Today</option>
                        <option value="weekly">This week</option>
                        <option value="range">Custom range</option>
                    </select>

                    <div class="youth-date-range" id="yrDateRange" hidden>
                        <input type="date" id="yrStartDate" class="form-control" aria-label="Start date">
                        <span>to</span>
                        <input type="date" id="yrEndDate" class="form-control" aria-label="End date">
                    </div>

                    <select id="yrPerPage" class="form-control" aria-label="Rows per page">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                    </div>

                    <div class="page-toolbar-actions">
                        <button type="button" class="btn btn-outline-secondary" id="yrClearFilters">
                            <i class="fa fa-times"></i> Clear
                        </button>
                        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="yrRefresh">
                            <i class="fas fa-rotate-right"></i> Refresh
                        </button>
                    </div>
        </div>

        <div class="card module-card">
            <div class="card-body">

                <div id="yrAlert" class="ajax-alert"></div>

                <div class="module-table-wrap">
                    <table class="table module-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Youth No</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Phone</th>
                                <th>Education</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="yrTableBody">
                            <tr>
                                <td colspan="8" class="text-center text-muted" style="padding:28px">
                                    <i class="fa fa-spinner fa-spin mr-2"></i> Loading youth records...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="module-pagination youth-pagination">
                    <div id="yrPaginationSummary" class="text-muted">Showing 0 records</div>
                    <div id="yrPaginationButtons" class="pagination-buttons"></div>
                </div>
            </div>
        </div>


        <!-- ----------------------------------------------------
             MODALS
        ---------------------------------------------------- -->
        <div class="app-modal-backdrop" id="yrBackdrop"></div>

        <!-- -- Register Youth (multi-select) ------------------- -->
        <div class="app-modal" id="yrFormModal" aria-hidden="true">
            <div class="app-dialog app-dialog-lg">
                <form id="yrForm" autocomplete="off">
                    <div class="app-modal-header">
                        <h3 id="yrFormTitle"><i class="fa fa-user-graduate"></i> Register Youth</h3>
                        <button type="button" class="modal-close" data-close-yr-modal>&times;</button>
                    </div>

                    <div class="app-modal-body">
                        <div id="yrFormAlert" class="ajax-alert"></div>
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action"     id="yrAction" value="create">
                        <input type="hidden" name="id"         id="yrId"     value="">

                        <!-- -- Member picker (shown on create, hidden on edit) -- -->
                        <div id="yrMemberPickerWrap">

                            <!-- Search / filter the checklist -->
                            <div class="form-group">
                                <label><i class="fa fa-users"></i> Select Members to Register
                                    <span class="req">*</span>
                                    <span class="badge badge-primary ml-2" id="yrPickCount">0 selected</span>
                                </label>
                                <div style="position:relative;margin-bottom:6px">
                                    <i class="fa fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#aaa;font-size:12px"></i>
                                    <input type="text" id="yrMemberSearch"
                                           class="form-control"
                                           style="padding-left:32px"
                                           placeholder="Filter members by name or phone...">
                                </div>

                                <!-- Checklist container -->
                                <div id="yrMemberChecklist" style="
                                    max-height:260px;overflow-y:auto;
                                    border:2px solid #e1d9f3;border-radius:8px;
                                    background:#fff;padding:6px 0;">

                                    <!-- Select-all row -->
                                    <label class="yr-check-row yr-check-all" style="
                                        display:flex;align-items:center;gap:10px;
                                        padding:7px 14px;cursor:pointer;
                                        border-bottom:2px solid #f0ecfa;
                                        font-weight:700;font-size:12.5px;color:var(--primary)">
                                        <input type="checkbox" id="yrSelectAll" style="width:16px;height:16px;accent-color:var(--primary)">
                                        Select All Members
                                        <span id="yrPickTotal" class="text-muted" style="font-size:11px;margin-left:auto">
                                            <?= count($memberOptions) ?> available
                                        </span>
                                    </label>

                                    <!-- Member rows -->
                                    <?php if ($memberOptions): foreach ($memberOptions as $m):
                                        $fullName = h(trim((string)($m['full_name'] ?? '')));
                                        $gender   = $m['gender'] ? ' . '.h($m['gender']) : '';
                                        $phone    = $m['phone']  ? ' . '.h($m['phone'])  : '';
                                    ?>
                                    <label class="yr-check-row" data-name="<?= strtolower($fullName) ?> <?= strtolower(h($m['phone'] ?? '')) ?>" style="
                                        display:flex;align-items:center;gap:10px;
                                        padding:8px 14px;cursor:pointer;
                                        border-bottom:1px solid #f5f2fb;
                                        font-size:13px;transition:background .12s">
                                        <input type="checkbox" name="member_ids[]"
                                               value="<?= (int)$m['id'] ?>"
                                               class="yr-member-cb"
                                               style="width:16px;height:16px;accent-color:var(--primary);flex-shrink:0">
                                        <div style="flex:1;min-width:0">
                                            <strong style="display:block;line-height:1.2"><?= $fullName ?></strong>
                                            <small class="text-muted"><?= $gender.$phone ?></small>
                                        </div>
                                    </label>
                                    <?php endforeach; else: ?>
                                    <div class="text-muted" style="padding:18px 14px;text-align:center">
                                        <i class="fa fa-info-circle"></i>
                                        All active members are already registered as youth.
                                    </div>
                                    <?php endif; ?>
                                </div><!-- /#yrMemberChecklist -->
                            </div>

                        </div><!-- /#yrMemberPickerWrap -->

                        <!-- Edit mode: show which member is being edited (read-only) -->
                        <div id="yrEditMemberInfo" style="display:none">
                            <div class="info-box" style="margin-bottom:16px">
                                <i class="fa fa-user"></i>
                                <div>
                                    <h4 id="yrEditMemberName" style="margin:0"></h4>
                                    <p style="margin:0" class="text-muted">Editing youth record</p>
                                </div>
                            </div>
                        </div>

                        <!-- Shared fields -->
                        <div class="form-section-title"><i class="fa fa-info-circle"></i> Youth Details</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Education Level</label>
                                <select name="education_level" id="yrEducation" class="form-control">
                                    <option value="">Select Education Level</option>
                                    <option>Primary</option><option>O-Level</option>
                                    <option>A-Level</option><option>Certificate</option>
                                    <option>Diploma</option><option>Degree</option>
                                    <option>Masters</option><option>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>School / Workplace</label>
                                <input type="text" name="school_or_work" id="yrSchool" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>Next of Kin</label>
                                <input type="text" name="next_of_kin" id="yrNok" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>Next of Kin Phone</label>
                                <input type="text" name="next_of_kin_phone" id="yrNokPhone" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>Registration Date</label>
                                <input type="date" name="registered_date" id="yrRegDate"
                                       class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="yrStatus" class="form-control">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>

                            <div class="form-group full-span">
                                <label>Skills &amp; Interests</label>
                                <textarea name="skills" id="yrSkills" class="form-control"
                                          placeholder="Farming, crafts, music, sports..."></textarea>
                            </div>
                        </div>

                        <!-- Bulk note shown only when >1 member selected -->
                        <div id="yrBulkNote" class="info-box" style="display:none;margin-top:4px">
                            <i class="fa fa-info-circle"></i>
                            <div>
                                <p>The same education level, registration date, and status will be applied to
                                   all selected members. You can edit individual records afterwards.</p>
                            </div>
                        </div>

                    </div><!-- /.app-modal-body -->

                    <div class="app-modal-footer">
                        <button type="button" class="btn btn-outline" data-close-yr-modal>Cancel</button>
                        <button type="submit" class="btn btn-primary" id="yrSaveBtn">
                            <i class="fa fa-save"></i> <span id="yrSaveBtnLabel">Register Youth</span>
                        </button>
                    </div>
                </form>
            </div>
        </div><!-- /#yrFormModal -->


        <!-- -- View / Profile ---------------------------------- -->
        <div class="app-modal" id="yrViewModal" aria-hidden="true">
            <div class="app-dialog app-dialog-lg">
                <div class="app-modal-header">
                    <h3><i class="fa fa-user-graduate"></i> Youth Profile</h3>
                    <button type="button" class="modal-close" data-close-yr-modal>&times;</button>
                </div>
                <div class="app-modal-body">
                    <div id="yrViewContent" class="detail-grid"></div>
                </div>
                <div class="app-modal-footer">
                    <button type="button" class="btn btn-outline" data-close-yr-modal>Close</button>
                </div>
            </div>
        </div><!-- /#yrViewModal -->


        <!-- -- Delete ------------------------------------------ -->
        <div class="app-modal" id="yrDeleteModal" aria-hidden="true">
            <div class="app-dialog">
                <form id="yrDeleteForm">
                    <div class="app-modal-header">
                        <h3><i class="fa fa-trash"></i> Delete / Deactivate Youth</h3>
                        <button type="button" class="modal-close" data-close-yr-modal>&times;</button>
                    </div>
                    <div class="app-modal-body">
                        <div id="yrDeleteAlert" class="ajax-alert"></div>
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id"     id="yrDeleteId">
                        <p>Are you sure you want to delete / deactivate <strong id="yrDeleteName"></strong>?</p>
                    </div>
                    <div class="app-modal-footer">
                        <button type="button" class="btn btn-outline" data-close-yr-modal>Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fa fa-trash"></i> Confirm
                        </button>
                    </div>
                </form>
            </div>
        </div><!-- /#yrDeleteModal -->


        <?php endif; /* hasYouth && hasMembers */ ?>

    </div><!-- /.content-area -->
</div><!-- /.main-content -->


<style>
.page-toolbar-filters>.youth-date-range{display:flex;align-items:center;gap:7px;flex:0 1 330px}.youth-date-range[hidden]{display:none!important}.youth-date-range input{flex:1 1 0;min-width:0}.youth-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 0 2px}.pagination-buttons{display:flex;gap:6px;flex-wrap:wrap}.pagination-buttons .btn[disabled]{opacity:.5;cursor:not-allowed}@media(max-width:600px){.page-toolbar-filters>.youth-date-range{grid-column:1/-1}}@media(max-width:767px){.youth-pagination{align-items:stretch}.pagination-buttons{width:100%;justify-content:center}}
</style>
<script>
/* Resolved server-side to the real on-disk path of process-youth.php, so
   AJAX calls work even if this page is reached through a router/query
   string URL that doesn't match the file's actual folder. */
window.YouthRegistryConfig = { endpoint: <?= json_encode($processEndpoint) ?> };
</script>
<script>
(function () {
    'use strict';

    const ENDPOINT = window.YouthRegistryConfig?.endpoint ?? 'process-youth.php';

    /* -- helpers ------------------------------------------- */
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function currentFilterParams(includePage = false) {
        const params = new URLSearchParams();
        const search = ($('#yrSearch')?.value || '').trim();
        const period = $('#yrPeriod')?.value || '';
        const startDate = $('#yrStartDate')?.value || '';
        const endDate = $('#yrEndDate')?.value || '';
        const perPage = $('#yrPerPage')?.value || '10';

        if (search) params.set('q', search);
        if (period) params.set('period', period);
        if (period === 'range' && startDate) params.set('start_date', startDate);
        if (period === 'range' && endDate) params.set('end_date', endDate);
        params.set('per_page', perPage);
        if (includePage) params.set('page', String(currentPage));

        return params;
    }

    function updateExportLinks() {
        const params = currentFilterParams(false);
        const query = params.toString();
        const csv = $('#yrExportCsv');
        const pdf = $('#yrExportPdf');
        if (csv) csv.href = ENDPOINT + '?action=export_csv' + (query ? '&' + query : '');
        if (pdf) pdf.href = ENDPOINT + '?action=export_pdf' + (query ? '&' + query : '');
    }

    function esc(v) {
        return String(v ?? '').replace(/[&<>'"]/g,
            c => ({ '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;' }[c]));
    }

    function showAlert(el, msg, type = 'success') {
        if (!el) return;
        el.className   = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        el.textContent = msg;
    }
    function clearAlert(el) {
        if (el) { el.className = 'ajax-alert'; el.textContent = ''; }
    }

    function badge(txt, cls = 'badge-secondary') {
        return `<span class="badge ${cls}">${esc(txt)}</span>`;
    }

    /* -- modal open / close -------------------------------- */
    function openModal(id) {
        $('#yrBackdrop').classList.add('show');
        $(id).classList.add('show');
        $(id).setAttribute('aria-hidden', 'false');
        document.body.classList.add('cms-modal-open');
    }
    function closeModals() {
        $$('.app-modal.show').forEach(m => {
            m.classList.remove('show');
            m.setAttribute('aria-hidden', 'true');
        });
        $('#yrBackdrop')?.classList.remove('show');
        document.body.classList.remove('cms-modal-open');
    }

    /* -- fetch helpers ------------------------------------- */
    async function request(url, opts = {}) {
        const res  = await fetch(url, Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' } }, opts));
        const text = await res.text();
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Invalid server response: ' + text.slice(0, 200)); }
    }
    const postForm = form => request(ENDPOINT, { method: 'POST', body: new FormData(form) });

    /* -------------------------------------------------------
       MULTI-SELECT MEMBER PICKER
    ------------------------------------------------------- */
    const pickerWrap   = $('#yrMemberPickerWrap');
    const searchInput  = $('#yrMemberSearch');
    const selectAllCb  = $('#yrSelectAll');
    const pickCountBadge = $('#yrPickCount');
    const bulkNote     = $('#yrBulkNote');
    const saveBtnLabel = $('#yrSaveBtnLabel');

    function getMemberRows() {
        return $$('.yr-check-row:not(.yr-check-all)', $('#yrMemberChecklist'));
    }

    function countChecked() {
        return $$('.yr-member-cb:checked', $('#yrMemberChecklist')).length;
    }

    function updatePickerUI() {
        const n = countChecked();
        if (pickCountBadge) {
            pickCountBadge.textContent = n === 0 ? '0 selected'
                                       : n === 1 ? '1 selected'
                                       : n + ' selected';
            pickCountBadge.className = 'badge ml-2 ' + (n > 0 ? 'badge-primary' : 'badge-secondary');
        }
        if (bulkNote)      bulkNote.style.display       = n > 1 ? 'flex' : 'none';
        if (saveBtnLabel)  saveBtnLabel.textContent      = n > 1 ? `Register ${n} Members` : 'Register Youth';
    }

    /* search / filter checklist rows */
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.toLowerCase().trim();
            getMemberRows().forEach(row => {
                const match = !term || (row.dataset.name ?? '').includes(term);
                row.style.display = match ? '' : 'none';
            });
            /* update select-all state to reflect visible rows */
            syncSelectAll();
        });
    }

    /* select-all toggle */
    if (selectAllCb) {
        selectAllCb.addEventListener('change', () => {
            getMemberRows().forEach(row => {
                if (row.style.display === 'none') return;   /* skip hidden (filtered) */
                const cb = row.querySelector('.yr-member-cb');
                if (cb) cb.checked = selectAllCb.checked;
            });
            updatePickerUI();
        });
    }

    /* individual checkbox ? update select-all state + counter */
    document.addEventListener('change', e => {
        if (e.target.classList.contains('yr-member-cb')) {
            syncSelectAll();
            updatePickerUI();
        }
    });

    function syncSelectAll() {
        if (!selectAllCb) return;
        const visible   = getMemberRows().filter(r => r.style.display !== 'none');
        const checkedVis = visible.filter(r => r.querySelector('.yr-member-cb')?.checked);
        selectAllCb.indeterminate = checkedVis.length > 0 && checkedVis.length < visible.length;
        selectAllCb.checked       = visible.length > 0 && checkedVis.length === visible.length;
    }

    /* hover highlight */
    document.addEventListener('mouseover', e => {
        const row = e.target.closest('.yr-check-row:not(.yr-check-all)');
        if (row) row.style.background = '#f5f1ff';
    });
    document.addEventListener('mouseout', e => {
        const row = e.target.closest('.yr-check-row:not(.yr-check-all)');
        if (row) row.style.background = '';
    });

    /* -------------------------------------------------------
       LOAD & RENDER TABLE
    ------------------------------------------------------- */
    let allRows = [];
    let currentPage = 1;
    let pagination = {
        page: 1,
        per_page: 10,
        total: 0,
        total_pages: 1,
        from: 0,
        to: 0
    };
    let searchTimer = null;

    async function loadYouth(page = currentPage) {
        clearAlert($('#yrAlert'));
        currentPage = Math.max(1, Number(page) || 1);

        try {
            const params = currentFilterParams(true);
            params.set('action', 'list');
            const data = await request(ENDPOINT + '?' + params.toString());
            if (!data.success) throw new Error(data.message || 'Failed to load youth.');

            allRows = data.items || [];
            pagination = data.pagination || pagination;
            currentPage = Number(pagination.page || 1);
            renderTable(allRows, Number(pagination.from || 0));
            renderPagination();
            updateExportLinks();
        } catch (err) {
            showAlert($('#yrAlert'), err.message, 'error');
        }
    }

    function renderTable(items, firstNumber = 0) {
        const tbody = $('#yrTableBody');
        if (!items.length) {
            tbody.innerHTML = `<tr>
                <td colspan="8">
                    <div class="empty-state">
                        <div class="icon"><i class="fas fa-user-graduate"></i></div>
                        <p>No youth records found for the selected filters.</p>
                    </div>
                </td>
            </tr>`;
            return;
        }

        tbody.innerHTML = items.map((r, i) => {
            const name = esc(r.full_name || '-');
            const youthNo = r.youth_no ? badge(r.youth_no, 'badge-primary') : '-';
            const gender = r.gender
                ? badge(r.gender, r.gender?.toLowerCase() === 'female' ? 'badge-info' : 'badge-secondary')
                : '-';
            const statusBadge = badge(
                r.status || 'Active',
                (r.status || 'Active') === 'Active' ? 'badge-success' : 'badge-secondary'
            );

            return `<tr>
                <td class="text-muted">${firstNumber + i}</td>
                <td>${youthNo}</td>
                <td>
                    <div class="record-info">
                        <div class="record-avatar"><i class="fas fa-user-graduate"></i></div>
                        <div>
                            <div class="record-name">${name}</div>
                            <div class="record-sub">${esc(r.phone || '')}</div>
                        </div>
                    </div>
                </td>
                <td>${gender}</td>
                <td>${esc(r.phone || '-')}</td>
                <td>${esc(r.education_level || '-')}</td>
                <td>${statusBadge}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-info btn-icon" data-action="view" data-id="${r.id}" title="View"><i class="fa fa-eye"></i></button>
                        <button class="btn btn-sm btn-primary btn-icon" data-action="edit" data-id="${r.id}" title="Edit"><i class="fa fa-edit"></i></button>
                        <button class="btn btn-sm btn-danger btn-icon" data-action="delete" data-id="${r.id}" data-name="${name}" title="Delete"><i class="fa fa-trash"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    function renderPagination() {
        const summary = $('#yrPaginationSummary');
        const buttons = $('#yrPaginationButtons');

        if (summary) {
            summary.textContent = pagination.total > 0
                ? `Showing ${pagination.from}-${pagination.to} of ${pagination.total} youth records`
                : 'Showing 0 youth records';
        }

        if (!buttons) return;
        buttons.innerHTML = '';

        const totalPages = Math.max(1, Number(pagination.total_pages || 1));
        const page = Math.max(1, Number(pagination.page || 1));

        function addButton(label, targetPage, disabled = false, active = false) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline');
            button.textContent = label;
            button.disabled = disabled;
            if (!disabled && !active) {
                button.addEventListener('click', () => loadYouth(targetPage));
            }
            buttons.appendChild(button);
        }

        addButton('Previous', page - 1, page <= 1);

        const start = Math.max(1, page - 2);
        const end = Math.min(totalPages, page + 2);
        for (let p = start; p <= end; p++) {
            addButton(String(p), p, false, p === page);
        }

        addButton('Next', page + 1, page >= totalPages);
    }

    function toggleDateRange() {
        const show = $('#yrPeriod')?.value === 'range';
        if ($('#yrDateRange')) $('#yrDateRange').hidden = !show;
        if (!show) {
            if ($('#yrStartDate')) $('#yrStartDate').value = '';
            if ($('#yrEndDate')) $('#yrEndDate').value = '';
        }
    }

    $('#yrSearch')?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => loadYouth(1), 350);
    });

    $('#yrPeriod')?.addEventListener('change', () => {
        toggleDateRange();
        loadYouth(1);
    });

    $('#yrStartDate')?.addEventListener('change', () => loadYouth(1));
    $('#yrEndDate')?.addEventListener('change', () => loadYouth(1));
    $('#yrPerPage')?.addEventListener('change', () => loadYouth(1));

    $('#yrClearFilters')?.addEventListener('click', () => {
        if ($('#yrSearch')) $('#yrSearch').value = '';
        if ($('#yrPeriod')) $('#yrPeriod').value = '';
        if ($('#yrStartDate')) $('#yrStartDate').value = '';
        if ($('#yrEndDate')) $('#yrEndDate').value = '';
        if ($('#yrPerPage')) $('#yrPerPage').value = '10';
        toggleDateRange();
        loadYouth(1);
    });

    /* -------------------------------------------------------
       FORM HELPERS
    ------------------------------------------------------- */
    function resetForm() {
        $('#yrForm').reset();
        $('#yrAction').value = 'create';
        $('#yrId').value     = '';
        $('#yrFormTitle').innerHTML = '<i class="fa fa-user-graduate"></i> Register Youth';
        $('#yrSaveBtnLabel').textContent = 'Register Youth';
        clearAlert($('#yrFormAlert'));

        /* show picker, hide edit-member info */
        if (pickerWrap)         pickerWrap.style.display         = '';
        if (searchInput)        searchInput.value                = '';
        if (selectAllCb)        { selectAllCb.checked = false; selectAllCb.indeterminate = false; }
        if (bulkNote)           bulkNote.style.display           = 'none';
        $('#yrEditMemberInfo').style.display = 'none';

        /* uncheck all checkboxes & restore visibility */
        $$('.yr-member-cb').forEach(cb => cb.checked = false);
        getMemberRows().forEach(row => row.style.display = '');
        updatePickerUI();
    }

    async function openEdit(id) {
        resetForm();
        try {
            const data = await request(`${ENDPOINT}?action=get&id=${encodeURIComponent(id)}`);
            if (!data.success) return showAlert($('#yrAlert'), data.message || 'Record not found.', 'error');
            const d = data.item;

            /* switch to edit mode UI */
            $('#yrAction').value = 'update';
            $('#yrId').value     = d.id || '';
            $('#yrFormTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Youth Record';
            $('#yrSaveBtnLabel').textContent = 'Update Youth';

            /* hide member picker, show member name */
            if (pickerWrap) pickerWrap.style.display = 'none';
            const editInfo = $('#yrEditMemberInfo');
            editInfo.style.display = '';
            $('#yrEditMemberName').textContent = d.full_name || 'Youth Member';

            /* populate fields */
            $('#yrEducation').value = d.education_level || '';
            $('#yrSchool').value    = d.school_or_work  || '';
            $('#yrNok').value       = d.next_of_kin     || '';
            $('#yrNokPhone').value  = d.next_of_kin_phone || '';
            $('#yrRegDate').value   = d.registered_date || '';
            $('#yrStatus').value    = d.status          || 'Active';
            $('#yrSkills').value    = d.skills          || '';

            openModal('#yrFormModal');
        } catch (err) {
            showAlert($('#yrAlert'), err.message, 'error');
        }
    }

    async function openView(id) {
        clearAlert($('#yrAlert'));
        $('#yrViewContent').innerHTML = '<p class="text-muted"><i class="fa fa-spinner fa-spin"></i> Loading...</p>';
        openModal('#yrViewModal');

        try {
            const data = await request(`${ENDPOINT}?action=get&id=${encodeURIComponent(id)}`);
            if (!data.success) return showAlert($('#yrAlert'), data.message || 'Not found.', 'error');
            const d = data.item;

            const fields = [
                ['Youth No',      d.youth_no         || '-'],
                ['Full Name',     d.full_name        || '-'],
                ['Gender',        d.gender           || '-'],
                ['Phone',         d.phone            || '-'],
                ['Email',         d.email            || '-'],
                ['Education',     d.education_level  || '-'],
                ['School/Work',   d.school_or_work   || '-'],
                ['Next of Kin',   d.next_of_kin      || '-'],
                ['NoK Phone',     d.next_of_kin_phone|| '-'],
                ['Skills',        d.skills           || '-'],
                ['Registered',    d.registered_date  || '-'],
                ['Status',        d.status           || '-'],
            ];
            $('#yrViewContent').innerHTML = fields.map(([label, val]) =>
                `<div class="detail-item"><b>${esc(label)}</b>${esc(val)}</div>`
            ).join('');
        } catch (err) {
            showAlert($('#yrAlert'), err.message, 'error');
        }
    }

    /* -------------------------------------------------------
       EVENT BINDINGS
    ------------------------------------------------------- */
    /* open add modal */
    $('#btnOpenAddYouth')?.addEventListener('click', () => { resetForm(); openModal('#yrFormModal'); });

    /* refresh */
    $('#yrRefresh')?.addEventListener('click', () => loadYouth(currentPage));

    /* close modals */
    $('#yrBackdrop')?.addEventListener('click', closeModals);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });
    document.addEventListener('click', e => {
        if (e.target.closest('[data-close-yr-modal]')) closeModals();
    });

    /* delegated table action buttons */
    document.addEventListener('click', async e => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const { action, id, name } = btn.dataset;

        if (action === 'view')   { openView(id); return; }
        if (action === 'edit')   { openEdit(id); return; }

        if (action === 'delete') {
            clearAlert($('#yrDeleteAlert'));
            $('#yrDeleteId').value = id;
            $('#yrDeleteName').textContent = name || 'this youth member';
            openModal('#yrDeleteModal');
            return;
        }
    });

    /* -- Main form submit (create / update) --------------- */
    $('#yrForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert($('#yrFormAlert'));

        const isCreate = $('#yrAction').value === 'create';

        /* validate: at least one member must be selected on create */
        if (isCreate) {
            const checked = $$('.yr-member-cb:checked');
            if (!checked.length) {
                showAlert($('#yrFormAlert'), 'Please select at least one member to register.', 'error');
                return;
            }
        }

        const btn     = $('#yrSaveBtn');
        const oldHTML = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        try {
            const data = await postForm($('#yrForm'));
            if (!data.success) {
                showAlert($('#yrFormAlert'), data.message || 'Save failed.', 'error');
                return;
            }
            closeModals();
            showAlert($('#yrAlert'), data.message || 'Saved successfully.');
            await loadYouth();
        } catch (err) {
            showAlert($('#yrFormAlert'), err.message, 'error');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = oldHTML;
        }
    });

    /* -- Delete form --------------------------------------- */
    $('#yrDeleteForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        try {
            const data = await postForm($('#yrDeleteForm'));
            if (!data.success) return showAlert($('#yrDeleteAlert'), data.message || 'Delete failed.', 'error');
            closeModals();
            showAlert($('#yrAlert'), data.message || 'Deleted successfully.');
            await loadYouth();
        } catch (err) { showAlert($('#yrDeleteAlert'), err.message, 'error'); }
    });

    /* -- init ---------------------------------------------- */
    toggleDateRange();
    updateExportLinks();
    loadYouth(1);

})();
</script>

<?php require_once '../includes/footer.php'; ?>