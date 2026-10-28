<?php
declare(strict_types=1);
$pageTitle = 'Church Services';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';
require_once '../church_admin/install.php';
ce_require_admin();
ce_install($conn);

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException('Database connection not found.');
}

$conn->set_charset('utf8mb4');

$departments = ce_departments($conn);
$departmentNames = [];

foreach ($departments as $department) {
    $departmentId = (int)($department['id'] ?? 0);
    $departmentName = trim((string)($department['name'] ?? ''));

    if ($departmentId > 0 && $departmentName !== '') {
        $departmentNames[$departmentId] = $departmentName;
    }
}
?>
<link rel="stylesheet" href="../assets/css/church-admin.css"><link rel="stylesheet" href="../assets/css/church-manager.css">

<?php
$rows=ce_query_all($conn,"SELECT * FROM church_services ORDER BY service_date DESC,service_time DESC LIMIT 500");
$rows=ce_attach_departments($conn,$rows,'church_service_departments','service_id',$departmentNames);
$total = count($rows);
$live = count(array_filter(
    $rows,
    static fn(array $row): bool => (int)($row['is_live'] ?? 0) === 1
));
$recorded = count(array_filter(
    $rows,
    static fn(array $row): bool =>
        trim((string)($row['recording_path'] ?? '')) !== ''
        || trim((string)($row['recording_url'] ?? '')) !== ''
));
$published = count(array_filter(
    $rows,
    static fn(array $row): bool =>
        strcasecmp((string)($row['status'] ?? ''), 'Published') === 0
));
?>
<div class="main-content"><?php include '../includes/topbar.php';?><div class="content-area"><?php ce_show_flash();?><div class="church-page-head"><div><h2><i class="fas fa-video"></i> Church Services</h2><p>Manage live services, recorded services, service details and department audiences.</p></div><button class="btn btn-primary" id="addBtn"><i class="fas fa-plus"></i> Add Service</button></div>
<div class="church-stats"><div class="church-stat"><div class="church-stat-icon"><i class="fas fa-church"></i></div><div><b><?=$total?></b><span>Total Services</span></div></div><div class="church-stat"><div class="church-stat-icon"><i class="fas fa-broadcast-tower"></i></div><div><b><?=$live?></b><span>Live Now</span></div></div><div class="church-stat"><div class="church-stat-icon"><i class="fas fa-circle-play"></i></div><div><b><?=$recorded?></b><span>Recordings</span></div></div><div class="church-stat"><div class="church-stat-icon"><i class="fas fa-check-circle"></i></div><div><b><?=$published?></b><span>Published</span></div></div></div>
<div class="church-card"><div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input id="search" class="form-control" placeholder="Search services..."></div><div class="page-toolbar-filters"><select id="statusFilter" class="form-control"><option value="">All statuses</option><option>Published</option><option>Draft</option><option>Completed</option><option>Cancelled</option></select></div><div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="refreshServices"><i class="fas fa-rotate-right"></i> Refresh</button></div></div><div class="church-table-wrap"><table class="church-table"><thead><tr><th>Date</th><th>Service</th><th>Preacher</th><th>Audience</th><th>Media</th><th>Status</th><th>Actions</th></tr></thead><tbody id="rows"><?php foreach($rows as $r):?><tr data-search="<?=ce_h(strtolower(implode(' ',[$r['service_title'],$r['preacher'],$r['theme'],$r['department_names'],$r['status']])))?>" data-status="<?=ce_h($r['status'])?>"><td><?=ce_date($r['service_date'])?><br><small><?=ce_h(substr((string)$r['service_time'],0,5))?></small></td><td class="wrap"><b><?=ce_h($r['service_title'])?></b><br><small><?=ce_h($r['theme'])?></small></td><td><?=ce_h($r['preacher']?:'-')?></td><td><?=ce_h($r['audience'])?><?php if($r['department_names']):?><br><small><?=ce_h($r['department_names'])?></small><?php endif;?></td><td><?php if($r['is_live']&&$r['live_link']):?><a target="_blank" href="<?=ce_h($r['live_link'])?>">Live</a><?php endif;?> <?php if($r['recording_path']):?><a target="_blank" href="<?=ce_h(ce_media_url((string)$r['recording_path']))?>">Recording</a><?php endif;?></td><td><span class="church-badge"><?=ce_h($r['status'])?></span></td><td><div class="church-table-actions"><button class="btn btn-info btn-sm action" data-action="view" data-record='<?=ce_h(json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT))?>'><i class="fas fa-eye"></i></button><button class="btn btn-primary btn-sm action" data-action="edit" data-record='<?=ce_h(json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT))?>'><i class="fas fa-edit"></i></button><button class="btn btn-danger btn-sm action" data-action="delete" data-id="<?=$r['id']?>" data-name="<?=ce_h($r['service_title'])?>"><i class="fas fa-trash"></i></button></div></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="7" class="church-empty">No services added.</td></tr><?php endif;?></tbody></table></div></div>

<div class="church-modal-backdrop" id="backdrop"></div><div class="church-modal" id="formModal" aria-hidden="true"><div class="church-modal-dialog church-modal-dialog-lg"><form method="post" action="../church_admin/process.php" enctype="multipart/form-data" id="recordForm"><?php ce_form_header('save_service','../church_services/');?><input type="hidden" name="id" id="recordId"><div class="church-modal-header"><h3 id="formTitle">Add Service</h3><button type="button" class="church-modal-close close">&times;</button></div><div class="church-modal-body"><div class="church-form"><label>Service title<input name="service_title" id="service_title" required></label><div class="church-form-row"><label>Date<input type="date" name="service_date" id="service_date" required></label><label>Time<input type="time" name="service_time" id="service_time"></label></div><div class="church-form-row"><label>Service type<input name="service_type" id="service_type" value="Sunday Service"></label><label>Preacher<input name="preacher" id="preacher"></label></div><label>Theme<input name="theme" id="theme"></label><label>Readings<textarea name="readings" id="readings"></textarea></label><label>Lessons learnt<textarea name="lessons_learnt" id="lessons_learnt"></textarea></label><label>Recommendation tips / message<textarea name="recommendations" id="recommendations"></textarea></label><label>Audience<select name="audience" id="audience"><option>All Members</option><option>Selected Departments</option></select></label><div class="audience-departments" id="deptBox"><div class="department-actions"><button type="button" class="btn btn-sm btn-outline selectAll">Select All</button><button type="button" class="btn btn-sm btn-outline clearAll">Clear</button></div><div class="department-options"><?php foreach($departments as $d):?><label class="department-option"><input type="checkbox" name="department_ids[]" value="<?= (int)$d['id']?>"><span><?=ce_h($d['name'])?></span></label><?php endforeach;?><?php if(!$departments):?><div class="church-empty">No departments found.</div><?php endif;?></div></div><div class="church-form-row"><label>Live platform<select name="live_platform" id="live_platform"><option value="">None</option><option>Zoom</option><option>Google Meet</option><option>Microsoft Teams</option><option>YouTube Live</option><option>Facebook Live</option><option>Other</option></select></label><label>Live link<input type="url" name="live_link" id="live_link"></label></div><label class="church-check"><input type="checkbox" name="is_live" id="is_live" value="1"> Mark as currently live</label><label>Recorded service link<input type="url" name="recording_url" id="recording_url"></label><div class="church-form-row"><label>Upload recording<input type="file" name="recording_file" accept="video/*,audio/*"></label><label>Thumbnail<input type="file" name="thumbnail" accept="image/*"></label></div><label>Status<select name="status" id="status"><option>Published</option><option>Completed</option><option>Draft</option><option>Cancelled</option></select></label></div></div><div class="church-modal-footer"><button type="button" class="btn btn-outline close">Cancel</button><button class="btn btn-primary"><i class="fas fa-save"></i> Save Service</button></div></form></div></div>
<div class="church-modal" id="viewModal" aria-hidden="true"><div class="church-modal-dialog"><div class="church-modal-header"><h3>Service Details</h3><button class="church-modal-close close">&times;</button></div><div class="church-modal-body"><div class="detail-grid" id="details"></div></div><div class="church-modal-footer"><button class="btn btn-outline close">Close</button></div></div></div>
<div class="church-modal" id="deleteModal" aria-hidden="true"><div class="church-modal-dialog church-modal-dialog-sm"><form method="post" action="../church_admin/process.php"><?php ce_form_header('delete_service','../church_services/');?><input type="hidden" name="id" id="deleteId"><div class="church-modal-header"><h3>Delete Service</h3><button type="button" class="church-modal-close close">&times;</button></div><div class="church-modal-body">Delete <strong id="deleteName"></strong>? This cannot be undone.</div><div class="church-modal-footer"><button type="button" class="btn btn-outline close">Cancel</button><button class="btn btn-danger">Delete</button></div></form></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const backdrop = $('#backdrop');
    const modals = $$('.church-modal');
    const formModal = $('#formModal');
    const viewModal = $('#viewModal');
    const deleteModal = $('#deleteModal');
    const form = $('#recordForm');
    const audience = $('#audience');
    const departmentBox = $('#deptBox');
    const departmentCheckboxes = $$('#deptBox input[type="checkbox"]');

    function openModal(modal) {
        if (!modal) return;

        modals.forEach(function (item) {
            item.classList.remove('show', 'is-open');
            item.setAttribute('aria-hidden', 'true');
        });

        backdrop?.classList.add('show', 'is-open');
        modal.classList.add('show', 'is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cms-modal-open');
    }

    function closeModals() {
        backdrop?.classList.remove('show', 'is-open');

        modals.forEach(function (modal) {
            modal.classList.remove('show', 'is-open');
            modal.setAttribute('aria-hidden', 'true');
        });

        document.body.classList.remove('cms-modal-open');
    }

    function toggleDepartments() {
        const visible = audience?.value === 'Selected Departments';

        if (departmentBox) {
            departmentBox.hidden = !visible;
            departmentBox.classList.toggle('show', visible);
        }
    }

    function selectDepartments(ids) {
        const selected = new Set((ids || []).map(String));

        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.checked = selected.has(String(checkbox.value));
        });
    }

    function resetServiceForm() {
        form?.reset();

        if ($('#recordId')) $('#recordId').value = '0';
        if ($('#formTitle')) $('#formTitle').textContent = 'Add Service';
        if ($('#service_type')) $('#service_type').value = 'Sunday Service';
        if ($('#audience')) $('#audience').value = 'All Members';
        if ($('#status')) $('#status').value = 'Published';

        selectDepartments([]);
        toggleDepartments();
    }

    function fillServiceForm(record) {
        resetServiceForm();

        const fieldIds = [
            'service_title',
            'service_date',
            'service_time',
            'service_type',
            'preacher',
            'theme',
            'readings',
            'lessons_learnt',
            'recommendations',
            'audience',
            'live_platform',
            'live_link',
            'status'
        ];

        if ($('#recordId')) $('#recordId').value = record.id || 0;
        if ($('#formTitle')) $('#formTitle').textContent = 'Edit Service';

        fieldIds.forEach(function (fieldId) {
            const field = $('#' + fieldId);

            if (field) {
                field.value = record[fieldId] || '';
            }
        });

        const recordingUrl = $('#recording_url');
        if (recordingUrl) {
            recordingUrl.value =
                record.recording_url
                || (record.recording_type === 'external'
                    ? record.recording_path || ''
                    : '');
        }

        if ($('#is_live')) {
            $('#is_live').checked = Number(record.is_live) === 1;
        }

        selectDepartments(
            String(record.department_ids || '')
                .split(',')
                .map(function (id) { return id.trim(); })
                .filter(Boolean)
        );

        toggleDepartments();
    }

    function createDetail(label, value) {
        const item = document.createElement('div');
        const title = document.createElement('b');
        const content = document.createElement('span');

        item.className = 'detail-item';
        title.textContent = label;
        content.textContent = value || '-';

        item.appendChild(title);
        item.appendChild(content);

        return item;
    }

    function showServiceDetails(record) {
        const details = $('#details');

        if (!details) return;

        details.replaceChildren();

        const audienceLabel = (record.audience || 'All Members')
            + (record.department_names ? ' — ' + record.department_names : '');

        const fields = {
            'Service': record.service_title,
            'Date': record.service_date,
            'Time': record.service_time,
            'Service type': record.service_type,
            'Preacher': record.preacher,
            'Theme': record.theme,
            'Readings': record.readings,
            'Lessons learnt': record.lessons_learnt,
            'Recommendations': record.recommendations,
            'Audience': audienceLabel,
            'Platform': record.live_platform,
            'Live link': record.live_link,
            'Status': record.status
        };

        Object.entries(fields).forEach(function ([label, value]) {
            details.appendChild(createDetail(label, value));
        });

        openModal(viewModal);
    }

    $$('.close').forEach(function (button) {
        button.addEventListener('click', closeModals);
    });

    backdrop?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModals();
        }
    });

    audience?.addEventListener('change', toggleDepartments);

    $('.selectAll')?.addEventListener('click', function () {
        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.checked = true;
        });
    });

    $('.clearAll')?.addEventListener('click', function () {
        departmentCheckboxes.forEach(function (checkbox) {
            checkbox.checked = false;
        });
    });

    $('#addBtn')?.addEventListener('click', function () {
        resetServiceForm();
        openModal(formModal);
    });

    form?.addEventListener('submit', function (event) {
        if (audience?.value !== 'Selected Departments') return;

        const selected = departmentCheckboxes.some(function (checkbox) {
            return checkbox.checked;
        });

        if (!selected) {
            event.preventDefault();
            alert('Select at least one department or choose All Members.');
        }
    });

    $$('.action').forEach(function (button) {
        button.addEventListener('click', function () {
            const action = button.dataset.action || '';

            if (action === 'delete') {
                if ($('#deleteId')) $('#deleteId').value = button.dataset.id || '0';
                if ($('#deleteName')) {
                    $('#deleteName').textContent =
                        button.dataset.name || 'this service';
                }

                openModal(deleteModal);
                return;
            }

            let record;

            try {
                record = JSON.parse(button.dataset.record || '{}');
            } catch (error) {
                console.error('Invalid church service record.', error);
                return;
            }

            if (action === 'view') {
                showServiceDetails(record);
                return;
            }

            if (action === 'edit') {
                fillServiceForm(record);
                openModal(formModal);
            }
        });
    });

    const searchInput = $('#search');
    const statusFilter = $('#statusFilter');
    const serviceRows = $$('#rows tr[data-search]');
    const emptyRow = $('#rows tr:not([data-search])');

    function applyFilters() {
        const query = String(searchInput?.value || '').trim().toLowerCase();
        const status = String(statusFilter?.value || '');
        let visible = 0;

        serviceRows.forEach(function (row) {
            const matchesSearch =
                query === ''
                || String(row.dataset.search || '').includes(query);
            const matchesStatus =
                status === ''
                || String(row.dataset.status || '') === status;
            const show = matchesSearch && matchesStatus;

            row.hidden = !show;

            if (show) visible++;
        });

        if (emptyRow) {
            emptyRow.hidden = visible !== 0;
        }
    }

    searchInput?.addEventListener('input', applyFilters);
    statusFilter?.addEventListener('change', applyFilters);
    $('#refreshServices')?.addEventListener('click', function () {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = '';
        applyFilters();
    });

    toggleDepartments();
    applyFilters();
});
</script></div></div><?php require_once '../includes/footer.php';?>