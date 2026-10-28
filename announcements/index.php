<?php
declare(strict_types=1);

$pageTitle = 'Announcements';

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';
require_once '../church_admin/install.php';

ce_require_admin();
ce_install($conn);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if (!function_exists('ce_date')) {
    function ce_date(mixed $value, string $format = 'd M Y'): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '-';
        }

        return date($format, $timestamp);
    }
}


if (!function_exists('ce_excerpt')) {
    function ce_excerpt(mixed $value, int $length = 130, string $suffix = '...'): string
    {
        $text = trim(preg_replace('/\\s+/u', ' ', strip_tags((string)$value)) ?? '');

        if ($text === '' || $length < 1) {
            return $text;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $length) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . $suffix;
        }

        if (strlen($text) <= $length) {
            return $text;
        }

        return rtrim(substr($text, 0, $length)) . $suffix;
    }
}

if (!function_exists('announcement_form_header')) {
    function announcement_form_header(string $action, string $returnTo): void
    {
        echo '<input type="hidden" name="csrf_token" value="' . ce_h(ce_csrf()) . '">';
        echo '<input type="hidden" name="action" value="' . ce_h($action) . '">';
        echo '<input type="hidden" name="return_to" value="' . ce_h($returnTo) . '">';
    }
}

if (!function_exists('announcement_datetime_local')) {
    function announcement_datetime_local(?string $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        $time = strtotime($value);

        return $time === false ? '' : date('Y-m-d\TH:i', $time);
    }
}


if (!function_exists('announcement_install_audience_tables')) {
    function announcement_install_audience_tables(mysqli $conn): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS church_announcement_departments (
            announcement_id INT NOT NULL,
            department_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (announcement_id, department_id),
            KEY idx_announcement_department (department_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$conn->query($sql)) {
            throw new RuntimeException($conn->error);
        }
    }
}

if (!function_exists('announcement_departments')) {
    function announcement_departments(mysqli $conn): array
    {
        $table = $conn->query("SHOW TABLES LIKE 'departments'");

        if (!$table || $table->num_rows === 0) {
            return [];
        }

        $columnsResult = $conn->query('SHOW COLUMNS FROM departments');
        $columns = [];

        while ($columnsResult && $column = $columnsResult->fetch_assoc()) {
            $columns[] = (string)($column['Field'] ?? '');
        }

        $idColumn = in_array('id', $columns, true)
            ? 'id'
            : (in_array('department_id', $columns, true) ? 'department_id' : '');

        $nameColumn = in_array('department_name', $columns, true)
            ? 'department_name'
            : (in_array('name', $columns, true)
                ? 'name'
                : (in_array('title', $columns, true) ? 'title' : ''));

        if ($idColumn === '' || $nameColumn === '') {
            return [];
        }

        $sql = "SELECT `$idColumn` AS id, `$nameColumn` AS name FROM departments";

        if (in_array('is_active', $columns, true)) {
            $sql .= ' WHERE is_active = 1';
        } elseif (in_array('status', $columns, true)) {
            $sql .= " WHERE status IN ('Active', 'active', '1')";
        }

        $sql .= " ORDER BY `$nameColumn` ASC";
        $result = $conn->query($sql);
        $departments = [];

        while ($result && $row = $result->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            $name = trim((string)($row['name'] ?? ''));

            if ($id > 0 && $name !== '') {
                $departments[] = ['id' => $id, 'name' => $name];
            }
        }

        return $departments;
    }
}

announcement_install_audience_tables($conn);
$departments = announcement_departments($conn);
$departmentNames = [];

foreach ($departments as $department) {
    $departmentNames[(int)$department['id']] = (string)$department['name'];
}

$announcementDepartments = [];
$mappingRows = ce_query_all(
    $conn,
    'SELECT announcement_id, department_id FROM church_announcement_departments'
);

foreach ($mappingRows as $mapping) {
    $announcementId = (int)($mapping['announcement_id'] ?? 0);
    $departmentId = (int)($mapping['department_id'] ?? 0);

    if ($announcementId > 0 && $departmentId > 0) {
        $announcementDepartments[$announcementId][] = $departmentId;
    }
}

$rows = ce_query_all(
    $conn,
    "SELECT *
     FROM church_announcements
     ORDER BY publish_from DESC, id DESC
     LIMIT 1000"
);

$totalAnnouncements = count($rows);
$publishedCount = 0;
$draftCount = 0;
$urgentCount = 0;
$activeCount = 0;
$now = time();

foreach ($rows as $row) {
    $status = (string)($row['status'] ?? '');
    $priority = (string)($row['priority'] ?? '');
    $from = strtotime((string)($row['publish_from'] ?? '')) ?: 0;
    $untilValue = trim((string)($row['publish_until'] ?? ''));
    $until = $untilValue !== '' ? (strtotime($untilValue) ?: 0) : 0;

    if ($status === 'Published') {
        $publishedCount++;
    }

    if ($status === 'Draft') {
        $draftCount++;
    }

    if ($priority === 'Urgent') {
        $urgentCount++;
    }

    if (
        $status === 'Published'
        && ($from === 0 || $from <= $now)
        && ($until === 0 || $until >= $now)
    ) {
        $activeCount++;
    }
}

$announcementData = [];

foreach ($rows as $row) {
    $announcementId = (int)$row['id'];
    $departmentIds = array_values(array_unique(
        array_map('intval', $announcementDepartments[$announcementId] ?? [])
    ));
    $selectedDepartmentNames = [];

    foreach ($departmentIds as $departmentId) {
        if (isset($departmentNames[$departmentId])) {
            $selectedDepartmentNames[] = $departmentNames[$departmentId];
        }
    }

    $audienceType = $departmentIds ? 'Departments' : 'All Members';
    $audienceLabel = $audienceType === 'Departments'
        ? implode(', ', $selectedDepartmentNames)
        : 'All Members';

    $announcementData[(string)$announcementId] = [
        'id'               => $announcementId,
        'title'            => (string)($row['title'] ?? ''),
        'message'          => (string)($row['message'] ?? ''),
        'audience'         => $audienceLabel,
        'audience_type'    => $audienceType,
        'department_ids'   => $departmentIds,
        'department_names' => $selectedDepartmentNames,
        'priority'         => (string)($row['priority'] ?? 'Normal'),
        'publish_from'     => announcement_datetime_local($row['publish_from'] ?? null),
        'publish_until'    => announcement_datetime_local($row['publish_until'] ?? null),
        'status'           => (string)($row['status'] ?? 'Published'),
        'created_at'       => ce_date($row['created_at'] ?? '', 'd M Y H:i'),
        'updated_at'       => ce_date($row['updated_at'] ?? '', 'd M Y H:i'),
    ];
}
?>

<link rel="stylesheet" href="../assets/css/church-admin.css">
<link rel="stylesheet" href="../assets/css/announcements.css">

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area announcements-page">
        <?php ce_show_flash(); ?>

        <div class="church-page-head">
            <div>
                <h2>
                    <i class="fas fa-bullhorn"></i>
                    Announcements
                </h2>
                <p>Create, schedule and publish announcements for church members.</p>
            </div>

            <div class="church-page-actions">
                <button type="button" class="btn btn-primary" id="openAddAnnouncement">
                    <i class="fas fa-plus"></i>
                    Add Announcement
                </button>
            </div>
        </div>

        <div class="church-stats announcements-stats">
            <button type="button" class="church-stat announcement-stat-filter" data-filter-status="">
                <span class="church-stat-icon"><i class="fas fa-list"></i></span>
                <span class="church-stat-content">
                    <b><?= number_format($totalAnnouncements) ?></b>
                    <span>Total Announcements</span>
                </span>
            </button>

            <button type="button" class="church-stat announcement-stat-filter" data-filter-status="Published">
                <span class="church-stat-icon"><i class="fas fa-circle-check"></i></span>
                <span class="church-stat-content">
                    <b><?= number_format($publishedCount) ?></b>
                    <span>Published</span>
                </span>
            </button>

            <button type="button" class="church-stat announcement-stat-filter" data-filter-status="Draft">
                <span class="church-stat-icon"><i class="fas fa-file-pen"></i></span>
                <span class="church-stat-content">
                    <b><?= number_format($draftCount) ?></b>
                    <span>Drafts</span>
                </span>
            </button>

            <button type="button" class="church-stat announcement-stat-filter" data-filter-active="1">
                <span class="church-stat-icon"><i class="fas fa-bell"></i></span>
                <span class="church-stat-content">
                    <b><?= number_format($activeCount) ?></b>
                    <span>Currently Active</span>
                </span>
            </button>

            <button type="button" class="church-stat announcement-stat-filter" data-filter-priority="Urgent">
                <span class="church-stat-icon"><i class="fas fa-triangle-exclamation"></i></span>
                <span class="church-stat-content">
                    <b><?= number_format($urgentCount) ?></b>
                    <span>Urgent</span>
                </span>
            </button>
        </div>

        <section class="church-card announcements-register-card">
            <div class="page-toolbar">
                <div class="page-toolbar-search">
                    <i class="fas fa-search"></i>
                    <input
                        type="search"
                        id="announcementSearch"
                        class="form-control"
                        placeholder="Search title, message or audience..."
                    >
                </div>

                <div class="page-toolbar-filters">
                <select id="announcementStatusFilter" class="form-control">
                    <option value="">All statuses</option>
                    <option value="Published">Published</option>
                    <option value="Draft">Draft</option>
                    <option value="Archived">Archived</option>
                </select>

                <select id="announcementPriorityFilter" class="form-control">
                    <option value="">All priorities</option>
                    <option value="Normal">Normal</option>
                    <option value="Important">Important</option>
                    <option value="Urgent">Urgent</option>
                </select>
                </div>

                <div class="page-toolbar-actions">
                    <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="clearAnnouncementFilters">
                        <i class="fas fa-rotate-right"></i>
                        Refresh
                    </button>
                </div>
            </div>

            <div class="church-table-wrap">
                <table class="church-table announcements-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Announcement</th>
                            <th>Audience</th>
                            <th>Priority</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="announcementTableBody">
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $id = (int)$row['id'];
                            $rowDepartmentIds = array_values(array_unique(array_map('intval', $announcementDepartments[$id] ?? [])));
                            $rowDepartmentNames = [];
                            foreach ($rowDepartmentIds as $rowDepartmentId) {
                                if (isset($departmentNames[$rowDepartmentId])) {
                                    $rowDepartmentNames[] = $departmentNames[$rowDepartmentId];
                                }
                            }
                            $rowAudience = $rowDepartmentIds ? implode(', ', $rowDepartmentNames) : 'All Members';
                            $status = (string)($row['status'] ?? 'Draft');
                            $priority = (string)($row['priority'] ?? 'Normal');
                            $message = trim((string)($row['message'] ?? ''));
                            $fromTimestamp = strtotime((string)($row['publish_from'] ?? '')) ?: 0;
                            $untilValue = trim((string)($row['publish_until'] ?? ''));
                            $untilTimestamp = $untilValue !== '' ? (strtotime($untilValue) ?: 0) : 0;
                            $isActive = $status === 'Published'
                                && ($fromTimestamp === 0 || $fromTimestamp <= $now)
                                && ($untilTimestamp === 0 || $untilTimestamp >= $now);

                            $priorityClass = match ($priority) {
                                'Urgent' => 'church-badge-danger',
                                'Important' => 'church-badge-warning',
                                default => '',
                            };

                            $statusClass = match ($status) {
                                'Published' => 'church-badge-success',
                                'Archived' => 'church-badge-info',
                                default => 'church-badge-warning',
                            };
                            ?>
                            <tr
                                data-announcement-row
                                data-id="<?= $id ?>"
                                data-status="<?= ce_h($status) ?>"
                                data-priority="<?= ce_h($priority) ?>"
                                data-active="<?= $isActive ? '1' : '0' ?>"
                                data-search="<?= ce_h(strtolower(trim(
                                    (string)($row['title'] ?? '') . ' ' .
                                    $message . ' ' .
                                    $rowAudience
                                ))) ?>"
                            >
                                <td class="text-muted"><?= $index + 1 ?></td>
                                <td>
                                    <strong class="announcement-title">
                                        <?= ce_h($row['title'] ?? '') ?>
                                    </strong>
                                    <p class="announcement-excerpt">
                                        <?= ce_h(ce_excerpt($message, 130)) ?>
                                    </p>
                                </td>
                                <td>
                                    <?php if ($rowDepartmentIds): ?>
                                        <span class="church-badge church-badge-info">Departments</span>
                                        <div class="announcement-audience-list"><?= ce_h($rowAudience) ?></div>
                                    <?php else: ?>
                                        <span class="church-badge">All Members</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="church-badge <?= ce_h($priorityClass) ?>">
                                        <?= ce_h($priority) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="announcement-schedule">
                                        <span>
                                            <i class="fas fa-calendar-day"></i>
                                            <?= ce_date($row['publish_from'] ?? '', 'd M Y H:i') ?>
                                        </span>
                                        <?php if ($untilValue !== ''): ?>
                                            <small>
                                                Until <?= ce_date($untilValue, 'd M Y H:i') ?>
                                            </small>
                                        <?php else: ?>
                                            <small>No expiry date</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="church-badge <?= ce_h($statusClass) ?>">
                                        <?= ce_h($status) ?>
                                    </span>
                                    <?php if ($isActive): ?>
                                        <span class="announcement-live-indicator">Active now</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="church-table-actions">
                                        <button
                                            type="button"
                                            class="btn btn-info btn-icon btn-sm announcement-action"
                                            data-action="view"
                                            data-id="<?= $id ?>"
                                            title="View announcement"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-primary btn-icon btn-sm announcement-action"
                                            data-action="edit"
                                            data-id="<?= $id ?>"
                                            title="Edit announcement"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-warning btn-icon btn-sm announcement-action"
                                            data-action="status"
                                            data-id="<?= $id ?>"
                                            title="Change publication status"
                                        >
                                            <i class="fas fa-toggle-on"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-danger btn-icon btn-sm announcement-action"
                                            data-action="delete"
                                            data-id="<?= $id ?>"
                                            title="Delete announcement"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr id="announcementEmptyRow" <?= $rows ? 'hidden' : '' ?>>
                            <td colspan="7">
                                <div class="church-empty">
                                    <i class="fas fa-bullhorn"></i>
                                    <p>No announcements found.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="announcements-result-count">
                Showing <strong id="visibleAnnouncementCount"><?= count($rows) ?></strong>
                of <?= count($rows) ?> announcements
            </div>
        </section>
    </div>
</div>

<div class="church-modal-backdrop" id="announcementBackdrop"></div>

<!-- Add/Edit Modal -->
<div class="church-modal" id="announcementFormModal" aria-hidden="true">
    <div class="church-modal-dialog church-modal-dialog-lg">
        <form method="post" action="../church_admin/process.php" id="announcementForm" autocomplete="off">
            <?php announcement_form_header('save_announcement', '../announcements/'); ?>
            <input type="hidden" name="id" id="announcementId" value="0">

            <div class="church-modal-header">
                <h3 id="announcementFormTitle">
                    <i class="fas fa-bullhorn"></i>
                    Add Announcement
                </h3>
                <button type="button" class="church-modal-close" data-close-announcement>&times;</button>
            </div>

            <div class="church-modal-body">
                <div class="church-form">
                    <div class="church-form-group">
                        <label for="announcementTitle">Title <span class="required">*</span></label>
                        <input
                            type="text"
                            id="announcementTitle"
                            name="title"
                            maxlength="180"
                            required
                        >
                    </div>

                    <div class="church-form-group">
                        <label for="announcementMessage">Message <span class="required">*</span></label>
                        <textarea
                            id="announcementMessage"
                            name="message"
                            rows="7"
                            required
                        ></textarea>
                    </div>

                    <div class="church-form-row">
                        <div class="church-form-group">
                            <label for="announcementAudienceType">Audience</label>
                            <select id="announcementAudienceType" name="audience_type" required>
                                <option value="All Members">All Members</option>
                                <option value="Departments">Selected Departments</option>
                            </select>
                        </div>

                        <div class="church-form-group">
                            <label for="announcementPriority">Priority</label>
                            <select id="announcementPriority" name="priority">
                                <option value="Normal">Normal</option>
                                <option value="Important">Important</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="church-form-group announcement-departments-field" id="announcementDepartmentsField" hidden>
                        <label>Select Departments <span class="required">*</span></label>

                        <?php if ($departments): ?>
                            <div class="announcement-department-tools">
                                <button type="button" class="btn btn-outline btn-sm" id="selectAllDepartments">
                                    <i class="fas fa-check-double"></i> Select All
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" id="clearAllDepartments">
                                    <i class="fas fa-xmark"></i> Clear
                                </button>
                            </div>

                            <div class="announcement-department-list">
                                <?php foreach ($departments as $department): ?>
                                    <label class="announcement-department-option">
                                        <input
                                            type="checkbox"
                                            name="department_ids[]"
                                            value="<?= (int)$department['id'] ?>"
                                        >
                                        <span><?= ce_h($department['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small>Only members assigned to the selected departments will see this announcement.</small>
                        <?php else: ?>
                            <div class="info-box warning announcement-no-departments">
                                <i class="fas fa-triangle-exclamation"></i>
                                <div>No departments were found. Add departments before using department targeting.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="church-form-row">
                        <div class="church-form-group">
                            <label for="announcementPublishFrom">Publish From</label>
                            <input
                                type="datetime-local"
                                id="announcementPublishFrom"
                                name="publish_from"
                                value="<?= date('Y-m-d\TH:i') ?>"
                            >
                        </div>

                        <div class="church-form-group">
                            <label for="announcementPublishUntil">Publish Until</label>
                            <input
                                type="datetime-local"
                                id="announcementPublishUntil"
                                name="publish_until"
                            >
                            <small>Leave blank to keep the announcement visible indefinitely.</small>
                        </div>
                    </div>

                    <div class="church-form-group">
                        <label for="announcementStatus">Status</label>
                        <select id="announcementStatus" name="status">
                            <option value="Published">Published</option>
                            <option value="Draft">Draft</option>
                            <option value="Archived">Archived</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="church-modal-footer">
                <button type="button" class="btn btn-outline" data-close-announcement>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    <span id="announcementSubmitLabel">Save Announcement</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div class="church-modal" id="announcementViewModal" aria-hidden="true">
    <div class="church-modal-dialog">
        <div class="church-modal-header">
            <h3><i class="fas fa-eye"></i> Announcement Details</h3>
            <button type="button" class="church-modal-close" data-close-announcement>&times;</button>
        </div>

        <div class="church-modal-body">
            <div class="announcement-view-head">
                <div>
                    <span class="church-badge" id="viewAnnouncementPriority"></span>
                    <span class="church-badge" id="viewAnnouncementStatus"></span>
                </div>
                <h3 id="viewAnnouncementTitle"></h3>
                <p id="viewAnnouncementMessage"></p>
            </div>

            <div class="detail-grid announcement-detail-grid">
                <div class="detail-item">
                    <b>Audience</b>
                    <span id="viewAnnouncementAudience"></span>
                </div>
                <div class="detail-item">
                    <b>Publish From</b>
                    <span id="viewAnnouncementFrom"></span>
                </div>
                <div class="detail-item">
                    <b>Publish Until</b>
                    <span id="viewAnnouncementUntil"></span>
                </div>
                <div class="detail-item">
                    <b>Last Updated</b>
                    <span id="viewAnnouncementUpdated"></span>
                </div>
            </div>
        </div>

        <div class="church-modal-footer">
            <button type="button" class="btn btn-outline" data-close-announcement>Close</button>
            <button type="button" class="btn btn-primary" id="editFromViewButton">
                <i class="fas fa-edit"></i> Edit
            </button>
        </div>
    </div>
</div>

<!-- Status Modal -->
<div class="church-modal" id="announcementStatusModal" aria-hidden="true">
    <div class="church-modal-dialog church-modal-dialog-sm">
        <form method="post" action="../church_admin/process.php" id="announcementStatusForm">
            <?php announcement_form_header('update_announcement_status', '../announcements/'); ?>
            <input type="hidden" name="id" id="statusAnnouncementId">

            <div class="church-modal-header">
                <h3><i class="fas fa-toggle-on"></i> Change Status</h3>
                <button type="button" class="church-modal-close" data-close-announcement>&times;</button>
            </div>

            <div class="church-modal-body">
                <p>
                    Update the publication status for
                    <strong id="statusAnnouncementName"></strong>.
                </p>

                <div class="church-form-group">
                    <label for="statusAnnouncementStatus">Status</label>
                    <select id="statusAnnouncementStatus" name="status">
                        <option value="Published">Published</option>
                        <option value="Draft">Draft</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
            </div>

            <div class="church-modal-footer">
                <button type="button" class="btn btn-outline" data-close-announcement>Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div class="church-modal" id="announcementDeleteModal" aria-hidden="true">
    <div class="church-modal-dialog church-modal-dialog-sm">
        <form method="post" action="../church_admin/process.php">
            <?php announcement_form_header('delete_announcement', '../announcements/'); ?>
            <input type="hidden" name="id" id="deleteAnnouncementId">

            <div class="church-modal-header">
                <h3><i class="fas fa-trash"></i> Delete Announcement</h3>
                <button type="button" class="church-modal-close" data-close-announcement>&times;</button>
            </div>

            <div class="church-modal-body">
                <div class="info-box danger announcement-delete-warning">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div>
                        <h4>Delete announcement?</h4>
                        <p>
                            You are about to delete
                            <strong id="deleteAnnouncementName"></strong>.
                            This action cannot be undone.
                        </p>
                    </div>
                </div>
            </div>

            <div class="church-modal-footer">
                <button type="button" class="btn btn-outline" data-close-announcement>Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="announcementData">
<?= json_encode($announcementData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const dataElement = document.getElementById('announcementData');
    let announcements = {};

    try {
        announcements = dataElement
            ? JSON.parse(dataElement.textContent || '{}')
            : {};
    } catch (error) {
        console.error('Unable to load announcement data.', error);
        announcements = {};
    }

    const backdrop = document.getElementById('announcementBackdrop');
    const modals = Array.from(document.querySelectorAll('.church-modal'));
    const formModal = document.getElementById('announcementFormModal');
    const viewModal = document.getElementById('announcementViewModal');
    const statusModal = document.getElementById('announcementStatusModal');
    const deleteModal = document.getElementById('announcementDeleteModal');

    // Prevent modals from flashing on page load and normalize conflicting styles.
    if (backdrop) {
        backdrop.style.display = 'none';
    }
    modals.forEach(function (modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    });

    let currentViewId = null;
    let activeOnly = false;

    function openModal(modal) {
        if (!modal) return;

        // Close any modal that may already be open.
        modals.forEach(function (item) {
            item.classList.remove('show', 'is-open');
            item.setAttribute('aria-hidden', 'true');
        });

        if (backdrop) {
            backdrop.classList.add('show', 'is-open');
            backdrop.removeAttribute('hidden');
            backdrop.style.display = 'block';
        }

        modal.classList.add('show', 'is-open');
        modal.setAttribute('aria-hidden', 'false');
        modal.removeAttribute('hidden');
        modal.style.display = 'flex';

        document.body.classList.add('cms-modal-open');
        document.body.style.overflow = 'hidden';

        window.requestAnimationFrame(function () {
            const focusTarget = modal.querySelector(
                'input:not([type="hidden"]), textarea, select, button:not([data-close-announcement])'
            );
            focusTarget?.focus();
        });
    }

    function closeModals() {
        if (backdrop) {
            backdrop.classList.remove('show', 'is-open');
            backdrop.style.display = 'none';
        }

        modals.forEach(function (modal) {
            modal.classList.remove('show', 'is-open');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.display = 'none';
        });

        document.body.classList.remove('cms-modal-open');
        document.body.style.overflow = '';
    }

    const audienceType = document.getElementById('announcementAudienceType');
    const departmentsField = document.getElementById('announcementDepartmentsField');
    const departmentCheckboxes = Array.from(
        document.querySelectorAll('input[name="department_ids[]"]')
    );

    function toggleDepartmentField() {
        const byDepartment = audienceType?.value === 'Departments';

        if (departmentsField) {
            departmentsField.hidden = !byDepartment;
        }

        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.required = false;
        });
    }

    function setSelectedDepartments(ids) {
        const selected = new Set((ids || []).map(String));

        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.checked = selected.has(String(checkbox.value));
        });
    }

    audienceType?.addEventListener('change', toggleDepartmentField);

    document.getElementById('selectAllDepartments')?.addEventListener('click', function () {
        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.checked = true;
        });
    });

    document.getElementById('clearAllDepartments')?.addEventListener('click', function () {
        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.checked = false;
        });
    });

    document.getElementById('announcementForm')?.addEventListener('submit', function (event) {
        if (audienceType?.value !== 'Departments') {
            return;
        }

        const selected = departmentCheckboxes.some(function (checkbox) {
            return checkbox.checked;
        });

        if (!selected) {
            event.preventDefault();
            alert('Select at least one department or choose All Members.');
        }
    });

    function resetForm() {
        const form = document.getElementById('announcementForm');
        form?.reset();

        document.getElementById('announcementId').value = '0';
        document.getElementById('announcementAudienceType').value = 'All Members';
        setSelectedDepartments([]);
        toggleDepartmentField();
        document.getElementById('announcementPriority').value = 'Normal';
        document.getElementById('announcementStatus').value = 'Published';

        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        document.getElementById('announcementPublishFrom').value = now
            .toISOString()
            .slice(0, 16);

        document.getElementById('announcementFormTitle').innerHTML =
            '<i class="fas fa-bullhorn"></i> Add Announcement';
        document.getElementById('announcementSubmitLabel').textContent =
            'Save Announcement';
    }

    function fillForm(item) {
        document.getElementById('announcementId').value = item.id || 0;
        document.getElementById('announcementTitle').value = item.title || '';
        document.getElementById('announcementMessage').value = item.message || '';
        document.getElementById('announcementAudienceType').value = item.audience_type || 'All Members';
        setSelectedDepartments(item.department_ids || []);
        toggleDepartmentField();
        document.getElementById('announcementPriority').value = item.priority || 'Normal';
        document.getElementById('announcementPublishFrom').value = item.publish_from || '';
        document.getElementById('announcementPublishUntil').value = item.publish_until || '';
        document.getElementById('announcementStatus').value = item.status || 'Published';

        document.getElementById('announcementFormTitle').innerHTML =
            '<i class="fas fa-edit"></i> Edit Announcement';
        document.getElementById('announcementSubmitLabel').textContent =
            'Update Announcement';
    }

    function openView(item) {
        currentViewId = String(item.id);

        document.getElementById('viewAnnouncementTitle').textContent = item.title || '-';
        document.getElementById('viewAnnouncementMessage').textContent = item.message || '-';
        document.getElementById('viewAnnouncementAudience').textContent = item.audience || '-';
        document.getElementById('viewAnnouncementFrom').textContent =
            item.publish_from ? item.publish_from.replace('T', ' ') : '-';
        document.getElementById('viewAnnouncementUntil').textContent =
            item.publish_until ? item.publish_until.replace('T', ' ') : 'No expiry date';
        document.getElementById('viewAnnouncementUpdated').textContent = item.updated_at || '-';

        const priority = document.getElementById('viewAnnouncementPriority');
        const status = document.getElementById('viewAnnouncementStatus');

        priority.textContent = item.priority || 'Normal';
        status.textContent = item.status || 'Draft';

        priority.className = 'church-badge ' + (
            item.priority === 'Urgent'
                ? 'church-badge-danger'
                : item.priority === 'Important'
                    ? 'church-badge-warning'
                    : ''
        );

        status.className = 'church-badge ' + (
            item.status === 'Published'
                ? 'church-badge-success'
                : item.status === 'Archived'
                    ? 'church-badge-info'
                    : 'church-badge-warning'
        );

        openModal(viewModal);
    }

    function openStatus(item) {
        document.getElementById('statusAnnouncementId').value = item.id || 0;
        document.getElementById('statusAnnouncementStatus').value = item.status || 'Draft';
        document.getElementById('statusAnnouncementName').textContent = item.title || 'this announcement';

        openModal(statusModal);
    }

    document.getElementById('openAddAnnouncement')?.addEventListener('click', function () {
        resetForm();
        openModal(formModal);
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-close-announcement]')) {
            closeModals();
            return;
        }

        const button = event.target.closest('.announcement-action');
        if (!button) return;

        const id = String(button.dataset.id || '');
        const action = button.dataset.action || '';
        const item = announcements[id];

        if (!item) return;

        if (action === 'view') {
            openView(item);
        } else if (action === 'edit') {
            fillForm(item);
            openModal(formModal);
        } else if (action === 'status') {
            openStatus(item);
        } else if (action === 'delete') {
            document.getElementById('deleteAnnouncementId').value = item.id || 0;
            document.getElementById('deleteAnnouncementName').textContent =
                item.title || 'this announcement';
            openModal(deleteModal);
        }
    });

    document.getElementById('editFromViewButton')?.addEventListener('click', function () {
        const item = currentViewId ? announcements[currentViewId] : null;
        if (!item) return;

        closeModals();
        fillForm(item);
        openModal(formModal);
    });

    backdrop?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModals();
    });

    const searchInput = document.getElementById('announcementSearch');
    const statusFilter = document.getElementById('announcementStatusFilter');
    const priorityFilter = document.getElementById('announcementPriorityFilter');
    const rows = Array.from(document.querySelectorAll('[data-announcement-row]'));
    const emptyRow = document.getElementById('announcementEmptyRow');
    const countElement = document.getElementById('visibleAnnouncementCount');

    function applyFilters() {
        const search = String(searchInput?.value || '').trim().toLowerCase();
        const status = String(statusFilter?.value || '');
        const priority = String(priorityFilter?.value || '');
        let visible = 0;

        rows.forEach(function (row) {
            const matchesSearch = !search || String(row.dataset.search || '').includes(search);
            const matchesStatus = !status || row.dataset.status === status;
            const matchesPriority = !priority || row.dataset.priority === priority;
            const matchesActive = !activeOnly || row.dataset.active === '1';
            const show = matchesSearch && matchesStatus && matchesPriority && matchesActive;

            row.hidden = !show;
            if (show) visible++;
        });

        if (emptyRow) emptyRow.hidden = visible !== 0;
        if (countElement) countElement.textContent = String(visible);
    }

    searchInput?.addEventListener('input', applyFilters);
    statusFilter?.addEventListener('change', function () {
        activeOnly = false;
        applyFilters();
    });
    priorityFilter?.addEventListener('change', function () {
        activeOnly = false;
        applyFilters();
    });

    document.getElementById('clearAnnouncementFilters')?.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        if (priorityFilter) priorityFilter.value = '';
        activeOnly = false;
        applyFilters();
    });

    document.querySelectorAll('.announcement-stat-filter').forEach(function (card) {
        card.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = card.dataset.filterStatus || '';
            if (priorityFilter) priorityFilter.value = card.dataset.filterPriority || '';
            activeOnly = card.dataset.filterActive === '1';
            applyFilters();
        });
    });

    applyFilters();
});
</script>

<?php require_once '../includes/footer.php'; ?>
