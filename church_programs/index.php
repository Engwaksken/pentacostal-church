<?php
declare(strict_types=1);

$pageTitle = 'Church Programs';

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';
require_once '../church_admin/install.php';

ce_require_admin();
ce_install($conn);

if (!function_exists('program_form_header')) {
    function program_form_header(string $action, string $returnTo): void
    {
        echo '<input type="hidden" name="csrf_token" value="' . ce_h(ce_csrf()) . '">';
        echo '<input type="hidden" name="action" value="' . ce_h($action) . '">';
        echo '<input type="hidden" name="return_to" value="' . ce_h($returnTo) . '">';
    }
}


if (!function_exists('program_departments')) {
    function program_departments(mysqli $conn): array
    {
        $table = $conn->query("SHOW TABLES LIKE 'departments'");
        if (!$table || $table->num_rows === 0) return [];
        $columnsResult = $conn->query('SHOW COLUMNS FROM departments');
        $columns = [];
        while ($columnsResult && $column = $columnsResult->fetch_assoc()) $columns[] = (string)$column['Field'];
        $idColumn = in_array('id', $columns, true) ? 'id' : (in_array('department_id', $columns, true) ? 'department_id' : '');
        $nameColumn = in_array('department_name', $columns, true) ? 'department_name' : (in_array('name', $columns, true) ? 'name' : (in_array('title', $columns, true) ? 'title' : ''));
        if ($idColumn === '' || $nameColumn === '') return [];
        $rows = [];
        $result = $conn->query("SELECT `$idColumn` AS id, `$nameColumn` AS name FROM departments ORDER BY `$nameColumn`");
        while ($result && $row = $result->fetch_assoc()) {
            $id=(int)($row['id']??0); $name=trim((string)($row['name']??''));
            if($id>0 && $name!=='') $rows[]=['id'=>$id,'name'=>$name];
        }
        return $rows;
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS church_program_departments (
    program_id INT NOT NULL,
    department_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (program_id, department_id),
    KEY idx_program_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$departments = program_departments($conn);
$departmentNames = [];
foreach ($departments as $department) $departmentNames[(int)$department['id']] = (string)$department['name'];
$programDepartments = [];
$mapResult = $conn->query('SELECT program_id, department_id FROM church_program_departments');
while ($mapResult && $mapping = $mapResult->fetch_assoc()) {
    $programId=(int)($mapping['program_id']??0); $departmentId=(int)($mapping['department_id']??0);
    if($programId>0 && $departmentId>0) $programDepartments[$programId][]=$departmentId;
}

$rows = ce_query_all(
    $conn,
    "SELECT *
     FROM church_programs
     ORDER BY program_date DESC, start_time DESC, id DESC
     LIMIT 500"
);

foreach ($rows as &$programRow) {
    $programId = (int)($programRow['id'] ?? 0);
    $departmentIds = array_values(array_unique(array_map('intval', $programDepartments[$programId] ?? [])));
    $names = [];
    foreach ($departmentIds as $departmentId) if (isset($departmentNames[$departmentId])) $names[] = $departmentNames[$departmentId];
    $programRow['audience_type'] = $departmentIds ? 'Departments' : 'All Members';
    $programRow['department_ids'] = $departmentIds;
    $programRow['department_names'] = $names;
    $programRow['audience_label'] = $departmentIds ? implode(', ', $names) : 'All Members';
}
unset($programRow);

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$currentMonth = date('Y-m');

$totalPrograms = count($rows);
$todayPrograms = 0;
$thisWeekPrograms = 0;
$thisMonthPrograms = 0;
$onlinePrograms = 0;
$publishedPrograms = 0;

foreach ($rows as $row) {
    $programDate = (string)($row['program_date'] ?? '');

    if ($programDate === $today) {
        $todayPrograms++;
    }

    if ($programDate >= $weekStart && $programDate <= $weekEnd) {
        $thisWeekPrograms++;
    }

    if (str_starts_with($programDate, $currentMonth)) {
        $thisMonthPrograms++;
    }

    if (trim((string)($row['meeting_link'] ?? '')) !== '') {
        $onlinePrograms++;
    }

    if ((string)($row['status'] ?? '') === 'Published') {
        $publishedPrograms++;
    }
}

$programTypes = [
    'Daily Program',
    'Sunday Service',
    'Bible Study',
    'Prayer Meeting',
    'Youth Program',
    'Choir Practice',
    'Community Outreach',
    'Sunday School',
    'Fellowship Meeting',
    'Special Service',
    'Other',
];

$programRowsJson = json_encode(
    $rows,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
?>

<link rel="stylesheet" href="../assets/css/church-admin.css">
<link rel="stylesheet" href="../assets/css/church-programs.css">

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area church-programs-page">
        <?php ce_show_flash(); ?>

        <div class="church-page-head">
            <div>
                <h2>
                    <i class="fas fa-calendar-days"></i>
                    Church Programs
                </h2>
                <p>Manage daily programs, church services, meetings and online attendance links.</p>
            </div>

            <div class="church-page-actions">
                <a href="calendar.php" class="btn btn-outline">
                    <i class="fas fa-calendar"></i>
                    Monthly Calendar
                </a>

                <button type="button" class="btn btn-primary" id="openAddProgramModal">
                    <i class="fas fa-plus"></i>
                    Add Program
                </button>
            </div>
        </div>

        <div class="church-stats church-program-stats">
            <div class="church-stat">
                <div class="church-stat-icon"><i class="fas fa-calendar"></i></div>
                <div class="church-stat-content">
                    <b><?= $totalPrograms ?></b>
                    <span>Total Programs</span>
                </div>
            </div>

            <div class="church-stat">
                <div class="church-stat-icon"><i class="fas fa-clock"></i></div>
                <div class="church-stat-content">
                    <b><?= $todayPrograms ?></b>
                    <span>Today</span>
                </div>
            </div>

            <div class="church-stat">
                <div class="church-stat-icon"><i class="fas fa-calendar-week"></i></div>
                <div class="church-stat-content">
                    <b><?= $thisWeekPrograms ?></b>
                    <span>This Week</span>
                </div>
            </div>

            <div class="church-stat">
                <div class="church-stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="church-stat-content">
                    <b><?= $thisMonthPrograms ?></b>
                    <span>This Month</span>
                </div>
            </div>

            <div class="church-stat">
                <div class="church-stat-icon"><i class="fas fa-video"></i></div>
                <div class="church-stat-content">
                    <b><?= $onlinePrograms ?></b>
                    <span>Online Programs</span>
                </div>
            </div>

            <div class="church-stat">
                <div class="church-stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="church-stat-content">
                    <b><?= $publishedPrograms ?></b>
                    <span>Published</span>
                </div>
            </div>
        </div>

        <section class="church-card church-program-list-card">
            <div class="church-card-header church-program-list-head">
                <div>
                    <h3>
                        <i class="fas fa-list"></i>
                        Programs Register
                    </h3>
                    <p class="church-card-subtitle">
                        Search, filter, edit or remove church programs.
                    </p>
                </div>

                <span class="church-badge" id="programResultCount">
                    <?= $totalPrograms ?> records
                </span>
            </div>

            <div class="page-toolbar">
                <div class="page-toolbar-search">
                    <i class="fas fa-search"></i>
                    <input
                        type="search"
                        id="programSearch"
                        class="form-control"
                        placeholder="Search title, venue, audience or type..."
                        autocomplete="off"
                    >
                </div>

                <div class="page-toolbar-filters">
                <select id="programTypeFilter" class="form-control">
                    <option value="">All program types</option>
                    <?php foreach ($programTypes as $type): ?>
                        <option value="<?= ce_h($type) ?>"><?= ce_h($type) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="programStatusFilter" class="form-control">
                    <option value="">All statuses</option>
                    <option value="Published">Published</option>
                    <option value="Draft">Draft</option>
                    <option value="Cancelled">Cancelled</option>
                </select>

                <input
                    type="month"
                    id="programMonthFilter"
                    class="form-control"
                    aria-label="Filter by month"
                >
                </div>

                <div class="page-toolbar-actions">
                    <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="clearProgramFilters">
                        <i class="fas fa-rotate-right"></i>
                        Refresh
                    </button>
                </div>
            </div>

            <div class="church-table-wrap">
                <table class="church-table church-program-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Program</th>
                            <th>Audience</th>
                            <th>Venue / Online</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="programTableBody">
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $id = (int)$row['id'];
                            $meetingLink = trim((string)($row['meeting_link'] ?? ''));
                            $attachmentPath = trim((string)($row['attachment_path'] ?? ''));
                            $status = (string)($row['status'] ?? 'Draft');
                            $statusClass = match ($status) {
                                'Published' => 'church-badge-success',
                                'Cancelled' => 'church-badge-danger',
                                default => 'church-badge-warning',
                            };
                            ?>
                            <tr data-program-id="<?= $id ?>">
                                <td>
                                    <strong><?= ce_h(ce_date((string)$row['program_date'])) ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?= ce_h(substr((string)($row['start_time'] ?? ''), 0, 5) ?: '-') ?>
                                        <?php if (!empty($row['end_time'])): ?>
                                            – <?= ce_h(substr((string)$row['end_time'], 0, 5)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>

                                <td>
                                    <strong><?= ce_h($row['title']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= ce_h($row['program_type']) ?></small>

                                    <?php if ($attachmentPath !== ''): ?>
                                        <br>
                                        <a
                                            href="<?= ce_h(ce_media_url($attachmentPath)) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="church-service-link"
                                        >
                                            <i class="fas fa-paperclip"></i>
                                            Attachment
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <td><span class="church-badge <?= !empty($row['department_ids']) ? 'church-badge-info' : '' ?>"><?= ce_h($row['audience_label'] ?? 'All Members') ?></span></td>

                                <td>
                                    <?= ce_h($row['venue'] ?: '-') ?>

                                    <?php if ($meetingLink !== ''): ?>
                                        <br>
                                        <a
                                            href="<?= ce_h($meetingLink) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="church-service-link"
                                        >
                                            <i class="fas fa-video"></i>
                                            Join online
                                        </a>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="church-badge <?= ce_h($statusClass) ?>">
                                        <?= ce_h($status) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="church-table-actions">
                                        <button
                                            type="button"
                                            class="btn btn-info btn-sm btn-icon view-program-btn"
                                            data-id="<?= $id ?>"
                                            title="View program"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-primary btn-sm btn-icon edit-program-btn"
                                            data-id="<?= $id ?>"
                                            title="Edit program"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm btn-icon delete-program-btn"
                                            data-id="<?= $id ?>"
                                            data-title="<?= ce_h($row['title']) ?>"
                                            title="Delete program"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="6" class="church-empty">
                                    <i class="fas fa-calendar-xmark"></i>
                                    No church programs have been created.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="church-empty church-program-filter-empty" id="programFilterEmpty" hidden>
                <i class="fas fa-search"></i>
                No programs match the selected filters.
            </div>
        </section>
    </div>
</div>

<div class="church-modal-backdrop" id="programModalBackdrop"></div>

<!-- Add / Edit Program Modal -->
<div class="church-modal" id="programFormModal" aria-hidden="true">
    <div class="church-modal-dialog church-modal-dialog-lg">
        <form
            method="post"
            action="../church_admin/process.php"
            enctype="multipart/form-data"
            id="programForm"
            autocomplete="off"
        >
            <?php program_form_header('save_program', '../church_programs/'); ?>
            <input type="hidden" name="id" id="programId" value="0">

            <div class="church-modal-header">
                <h3 id="programFormModalTitle">
                    <i class="fas fa-plus"></i>
                    Add Church Program
                </h3>

                <button type="button" class="church-modal-close" data-close-program-modal>
                    &times;
                </button>
            </div>

            <div class="church-modal-body">
                <div class="church-form church-program-modal-form">
                    <div class="church-form-row">
                        <div class="church-form-group">
                            <label for="programTitle">Program Title <span class="req">*</span></label>
                            <input
                                type="text"
                                id="programTitle"
                                name="title"
                                maxlength="180"
                                required
                            >
                        </div>

                        <div class="church-form-group">
                            <label for="programType">Program Type <span class="req">*</span></label>
                            <select id="programType" name="program_type" required>
                                <?php foreach ($programTypes as $type): ?>
                                    <option value="<?= ce_h($type) ?>"><?= ce_h($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="church-form-row church-form-row-3">
                        <div class="church-form-group">
                            <label for="programDate">Program Date <span class="req">*</span></label>
                            <input
                                type="date"
                                id="programDate"
                                name="program_date"
                                required
                                value="<?= ce_h(date('Y-m-d')) ?>"
                            >
                        </div>

                        <div class="church-form-group">
                            <label for="programStartTime">Start Time</label>
                            <input type="time" id="programStartTime" name="start_time">
                        </div>

                        <div class="church-form-group">
                            <label for="programEndTime">End Time</label>
                            <input type="time" id="programEndTime" name="end_time">
                        </div>
                    </div>

                    <div class="church-form-row">
                        <div class="church-form-group">
                            <label for="programAudienceType">Audience <span class="req">*</span></label>
                            <select id="programAudienceType" name="audience_type" required>
                                <option value="All Members">All Members</option>
                                <option value="Departments">Selected Departments</option>
                            </select>
                        </div>

                        <div class="church-form-group">
                            <label for="programVenue">Venue</label>
                            <input
                                type="text"
                                id="programVenue"
                                name="venue"
                                maxlength="180"
                                placeholder="Church, hall or another venue"
                            >
                        </div>
                    </div>

                    <div class="church-form-group program-departments-field" id="programDepartmentsField" hidden>
                        <label>Select Departments <span class="req">*</span></label>
                        <?php if ($departments): ?>
                            <div class="program-department-tools">
                                <button type="button" class="btn btn-outline btn-sm" id="selectAllProgramDepartments"><i class="fas fa-check-double"></i> Select All</button>
                                <button type="button" class="btn btn-outline btn-sm" id="clearProgramDepartments"><i class="fas fa-xmark"></i> Clear</button>
                            </div>
                            <div class="program-department-list">
                                <?php foreach ($departments as $department): ?>
                                    <label class="program-department-option">
                                        <input type="checkbox" name="department_ids[]" value="<?= (int)$department['id'] ?>">
                                        <span><?= ce_h($department['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small>Only members assigned to the selected departments will see this program.</small>
                        <?php else: ?>
                            <div class="info-box warning"><i class="fas fa-triangle-exclamation"></i><div>No departments were found. Add departments before using department targeting.</div></div>
                        <?php endif; ?>
                    </div>

                    <div class="church-form-group">
                        <label for="programMeetingLink">Online Meeting Link</label>
                        <input
                            type="url"
                            id="programMeetingLink"
                            name="meeting_link"
                            maxlength="500"
                            placeholder="Zoom, Google Meet, Microsoft Teams or another link"
                        >
                    </div>

                    <div class="church-form-group">
                        <label for="programDescription">Description</label>
                        <textarea
                            id="programDescription"
                            name="description"
                            rows="4"
                            placeholder="Program details, instructions or notes"
                        ></textarea>
                    </div>

                    <div class="church-form-row">
                        <div class="church-form-group">
                            <label for="programAttachment">Attachment</label>
                            <input
                                type="file"
                                id="programAttachment"
                                name="attachment"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                            >
                            <small>PDF, Word or image. Maximum 8 MB.</small>
                            <div id="currentProgramAttachment" class="church-current-attachment"></div>
                        </div>

                        <div class="church-form-group">
                            <label for="programStatus">Status</label>
                            <select id="programStatus" name="status">
                                <option value="Published">Published</option>
                                <option value="Draft">Draft</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="church-modal-footer">
                <button type="button" class="btn btn-outline" data-close-program-modal>
                    Cancel
                </button>

                <button type="submit" class="btn btn-primary" id="saveProgramBtn">
                    <i class="fas fa-save"></i>
                    <span id="saveProgramBtnText">Save Program</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Program Modal -->
<div class="church-modal" id="viewProgramModal" aria-hidden="true">
    <div class="church-modal-dialog">
        <div class="church-modal-header">
            <h3>
                <i class="fas fa-calendar-day"></i>
                Program Details
            </h3>

            <button type="button" class="church-modal-close" data-close-program-modal>
                &times;
            </button>
        </div>

        <div class="church-modal-body">
            <div class="detail-grid" id="programDetailsContent"></div>
        </div>

        <div class="church-modal-footer">
            <a
                href="#"
                id="programJoinLink"
                class="btn btn-success"
                target="_blank"
                rel="noopener noreferrer"
                hidden
            >
                <i class="fas fa-video"></i>
                Join Online
            </a>

            <a
                href="#"
                id="programAttachmentLink"
                class="btn btn-info"
                target="_blank"
                rel="noopener"
                hidden
            >
                <i class="fas fa-paperclip"></i>
                Open Attachment
            </a>

            <button type="button" class="btn btn-outline" data-close-program-modal>
                Close
            </button>
        </div>
    </div>
</div>

<!-- Delete Program Modal -->
<div class="church-modal" id="deleteProgramModal" aria-hidden="true">
    <div class="church-modal-dialog church-modal-dialog-sm">
        <form method="post" action="../church_admin/process.php" id="deleteProgramForm">
            <?php program_form_header('delete_program', '../church_programs/'); ?>
            <input type="hidden" name="id" id="deleteProgramId">

            <div class="church-modal-header">
                <h3>
                    <i class="fas fa-trash"></i>
                    Delete Program
                </h3>

                <button type="button" class="church-modal-close" data-close-program-modal>
                    &times;
                </button>
            </div>

            <div class="church-modal-body">
                <div class="info-box danger church-program-delete-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <h4>Confirm deletion</h4>
                        <p>
                            Delete <strong id="deleteProgramTitle"></strong>?
                            This action cannot be undone.
                        </p>
                    </div>
                </div>
            </div>

            <div class="church-modal-footer">
                <button type="button" class="btn btn-outline" data-close-program-modal>
                    Cancel
                </button>

                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i>
                    Delete Program
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const programs = <?= $programRowsJson ?: '[]' ?>;
    const programMap = new Map(
        programs.map(function (program) {
            return [String(program.id), program];
        })
    );

    const backdrop = document.getElementById('programModalBackdrop');
    const formModal = document.getElementById('programFormModal');
    const viewModal = document.getElementById('viewProgramModal');
    const deleteModal = document.getElementById('deleteProgramModal');
    const programForm = document.getElementById('programForm');

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function displayDate(value) {
        if (!value) {
            return '-';
        }

        const parts = String(value).split('-');

        if (parts.length !== 3) {
            return value;
        }

        const date = new Date(
            Number(parts[0]),
            Number(parts[1]) - 1,
            Number(parts[2])
        );

        return new Intl.DateTimeFormat(undefined, {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        }).format(date);
    }

    function displayTime(value) {
        return value ? String(value).slice(0, 5) : '-';
    }

    function openModal(modal) {
        if (!modal || !backdrop) {
            return;
        }

        backdrop.classList.add('show');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cms-modal-open');
    }

    function closeModals() {
        document.querySelectorAll('.church-modal.show').forEach(function (modal) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        });

        backdrop?.classList.remove('show');
        document.body.classList.remove('cms-modal-open');
    }

    function setInput(id, value) {
        const input = document.getElementById(id);

        if (input) {
            input.value = value ?? '';
        }
    }

    const programAudienceType = document.getElementById('programAudienceType');
    const programDepartmentsField = document.getElementById('programDepartmentsField');
    const programDepartmentCheckboxes = Array.from(document.querySelectorAll('input[name="department_ids[]"]'));

    function toggleProgramDepartmentField() {
        const byDepartment = programAudienceType?.value === 'Departments';
        if (programDepartmentsField) programDepartmentsField.hidden = !byDepartment;
        programDepartmentCheckboxes.forEach(function (checkbox) { checkbox.required = byDepartment && !programDepartmentCheckboxes.some(function (item) { return item.checked; }); });
    }

    function setSelectedProgramDepartments(ids) {
        const selected = new Set((ids || []).map(String));
        programDepartmentCheckboxes.forEach(function (checkbox) { checkbox.checked = selected.has(String(checkbox.value)); });
    }

    programAudienceType?.addEventListener('change', toggleProgramDepartmentField);
    programDepartmentCheckboxes.forEach(function (checkbox) { checkbox.addEventListener('change', toggleProgramDepartmentField); });
    document.getElementById('selectAllProgramDepartments')?.addEventListener('click', function () { programDepartmentCheckboxes.forEach(function (checkbox) { checkbox.checked = true; }); toggleProgramDepartmentField(); });
    document.getElementById('clearProgramDepartments')?.addEventListener('click', function () { programDepartmentCheckboxes.forEach(function (checkbox) { checkbox.checked = false; }); toggleProgramDepartmentField(); });

    function resetProgramForm() {
        programForm?.reset();
        setInput('programId', '0');
        setInput('programDate', <?= json_encode(date('Y-m-d')) ?>);
        setInput('programType', 'Daily Program');
        setInput('programAudienceType', 'All Members');
        setSelectedProgramDepartments([]);
        toggleProgramDepartmentField();
        setInput('programStatus', 'Published');

        document.getElementById('programFormModalTitle').innerHTML =
            '<i class="fas fa-plus"></i> Add Church Program';
        document.getElementById('saveProgramBtnText').textContent = 'Save Program';
        document.getElementById('currentProgramAttachment').innerHTML = '';
    }

    function fillProgramForm(program) {
        resetProgramForm();

        setInput('programId', program.id);
        setInput('programTitle', program.title);
        setInput('programType', program.program_type || 'Daily Program');
        setInput('programDate', program.program_date);
        setInput('programStartTime', String(program.start_time || '').slice(0, 5));
        setInput('programEndTime', String(program.end_time || '').slice(0, 5));
        setInput('programAudienceType', program.audience_type || 'All Members');
        setSelectedProgramDepartments(program.department_ids || []);
        toggleProgramDepartmentField();
        setInput('programVenue', program.venue || '');
        setInput('programMeetingLink', program.meeting_link || '');
        setInput('programDescription', program.description || '');
        setInput('programStatus', program.status || 'Published');

        document.getElementById('programFormModalTitle').innerHTML =
            '<i class="fas fa-edit"></i> Edit Church Program';
        document.getElementById('saveProgramBtnText').textContent = 'Update Program';

        const attachmentBox = document.getElementById('currentProgramAttachment');

        if (program.attachment_path) {
            let attachmentUrl = String(program.attachment_path);

            if (attachmentUrl.startsWith('assets/')) {
                attachmentUrl = '../' + attachmentUrl;
            }

            attachmentBox.innerHTML =
                '<a href="' + escapeHtml(attachmentUrl) + '" target="_blank" rel="noopener">' +
                '<i class="fas fa-paperclip"></i> View current attachment</a>';
        }
    }

    if (new URLSearchParams(window.location.search).get('open') === 'add') { resetProgramForm(); openModal(formModal); }

    document.getElementById('openAddProgramModal')?.addEventListener('click', function () {
        resetProgramForm();
        openModal(formModal);
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-close-program-modal]')) {
            closeModals();
            return;
        }

        const editButton = event.target.closest('.edit-program-btn');

        if (editButton) {
            const program = programMap.get(String(editButton.dataset.id || ''));

            if (program) {
                fillProgramForm(program);
                openModal(formModal);
            }

            return;
        }

        const viewButton = event.target.closest('.view-program-btn');

        if (viewButton) {
            const program = programMap.get(String(viewButton.dataset.id || ''));

            if (!program) {
                return;
            }

            const fields = [
                ['Program', program.title],
                ['Type', program.program_type],
                ['Date', displayDate(program.program_date)],
                ['Start Time', displayTime(program.start_time)],
                ['End Time', displayTime(program.end_time)],
                ['Audience', program.audience_label || 'All Members'],
                ['Venue', program.venue],
                ['Status', program.status],
                ['Description', program.description]
            ];

            document.getElementById('programDetailsContent').innerHTML = fields.map(
                function (field) {
                    return '<div class="detail-item">' +
                        '<b>' + escapeHtml(field[0]) + '</b>' +
                        '<span>' + escapeHtml(field[1] || '-') + '</span>' +
                        '</div>';
                }
            ).join('');

            const joinLink = document.getElementById('programJoinLink');
            const attachmentLink = document.getElementById('programAttachmentLink');

            joinLink.hidden = !program.meeting_link;
            joinLink.href = program.meeting_link || '#';

            let attachmentUrl = String(program.attachment_path || '');

            if (attachmentUrl.startsWith('assets/')) {
                attachmentUrl = '../' + attachmentUrl;
            }

            attachmentLink.hidden = !attachmentUrl;
            attachmentLink.href = attachmentUrl || '#';

            openModal(viewModal);
            return;
        }

        const deleteButton = event.target.closest('.delete-program-btn');

        if (deleteButton) {
            setInput('deleteProgramId', deleteButton.dataset.id || '');
            document.getElementById('deleteProgramTitle').textContent =
                deleteButton.dataset.title || 'this program';
            openModal(deleteModal);
        }
    });

    backdrop?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals();
        }
    });

    const searchInput = document.getElementById('programSearch');
    const typeFilter = document.getElementById('programTypeFilter');
    const statusFilter = document.getElementById('programStatusFilter');
    const monthFilter = document.getElementById('programMonthFilter');
    const resultCount = document.getElementById('programResultCount');
    const emptyState = document.getElementById('programFilterEmpty');

    function applyFilters() {
        const term = String(searchInput?.value || '').trim().toLowerCase();
        const type = String(typeFilter?.value || '');
        const status = String(statusFilter?.value || '');
        const month = String(monthFilter?.value || '');
        let visible = 0;

        document.querySelectorAll('#programTableBody tr[data-program-id]').forEach(
            function (row) {
                const program = programMap.get(String(row.dataset.programId || ''));

                if (!program) {
                    return;
                }

                const searchable = [
                    program.title,
                    program.description,
                    program.program_type,
                    program.audience_label || program.audience,
                    program.venue,
                    program.status
                ].join(' ').toLowerCase();

                const matchesTerm = !term || searchable.includes(term);
                const matchesType = !type || program.program_type === type;
                const matchesStatus = !status || program.status === status;
                const matchesMonth = !month || String(program.program_date || '').startsWith(month);
                const show = matchesTerm && matchesType && matchesStatus && matchesMonth;

                row.hidden = !show;

                if (show) {
                    visible++;
                }
            }
        );

        if (resultCount) {
            resultCount.textContent = visible + (visible === 1 ? ' record' : ' records');
        }

        if (emptyState) {
            emptyState.hidden = visible !== 0 || programs.length === 0;
        }
    }

    [searchInput, typeFilter, statusFilter, monthFilter].forEach(function (field) {
        field?.addEventListener(field === searchInput ? 'input' : 'change', applyFilters);
    });

    document.getElementById('clearProgramFilters')?.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        if (typeFilter) typeFilter.value = '';
        if (statusFilter) statusFilter.value = '';
        if (monthFilter) monthFilter.value = '';
        applyFilters();
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
