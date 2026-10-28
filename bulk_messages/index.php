<?php
declare(strict_types=1);

$pageTitle = 'Bulk Email & SMS';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

if (!function_exists('bm_h')) {
    function bm_h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function bm_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function bm_col_exists(mysqli $conn, string $table, string $col): bool {
    static $cache = [];

    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$zones = [];

if (bm_table_exists($conn, 'church_zones')) {
    $zoneTable = 'church_zones';
    $nameCol = bm_col_exists($conn, $zoneTable, 'zone_name') ? 'zone_name' : (bm_col_exists($conn, $zoneTable, 'name') ? 'name' : 'id');

    $res = $conn->query("SELECT id, `$nameCol` AS zone_name FROM `$zoneTable` ORDER BY `$nameCol` ASC");
    while ($res && $row = $res->fetch_assoc()) {
        $zones[] = $row;
    }
} elseif (bm_table_exists($conn, 'zones')) {
    $zoneTable = 'zones';
    $nameCol = bm_col_exists($conn, $zoneTable, 'zone_name') ? 'zone_name' : (bm_col_exists($conn, $zoneTable, 'name') ? 'name' : 'id');

    $res = $conn->query("SELECT id, `$nameCol` AS zone_name FROM `$zoneTable` ORDER BY `$nameCol` ASC");
    while ($res && $row = $res->fetch_assoc()) {
        $zones[] = $row;
    }
}

$departments = [];

if (bm_table_exists($conn, 'departments') && bm_table_exists($conn, 'department_members')) {
    $where = bm_col_exists($conn, 'departments', 'status') ? "WHERE COALESCE(status,'Active')='Active'" : '';
    $res = $conn->query("SELECT id, name FROM departments $where ORDER BY name ASC");
    while ($res && $row = $res->fetch_assoc()) {
        $departments[] = $row;
    }
}
?>


<div class="main-content bulk-message-page">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area">
        <div class="page-header">
            <div>
                <h2><i class="fas fa-paper-plane text-primary"></i> Bulk Email & SMS</h2>
                <p>Send announcements to members, zones, departments, youth, teachers, and parents.</p>
            </div>
        </div>

        <?php if (function_exists('showFlash')) showFlash(); ?>

        <div id="bulkAlert" class="ajax-alert"></div>

        <div class="bulk-page-grid">
            <div class="stats-grid stats-grid-3" id="bulkStatsGrid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="stat-value" id="statRecipients">0</div>
                        <div class="stat-label">Selected Recipients</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                    <div>
                        <div class="stat-value" id="statEmails">0</div>
                        <div class="stat-label">With Email</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-sms"></i></div>
                    <div>
                        <div class="stat-value" id="statPhones">0</div>
                        <div class="stat-label">With Phone</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-bullhorn"></i> Compose Message</h3>
                </div>

                <div class="card-body">
                    <div class="tabs bulk-tabs">
                        <button type="button" class="tab-btn active" data-bulk-tab="email">
                            <i class="fas fa-envelope"></i> Email
                        </button>
                        <button type="button" class="tab-btn" data-bulk-tab="sms">
                            <i class="fas fa-comment-dots"></i> SMS
                        </button>
                    </div>

                    <form id="bulkMessageForm">
                        <input type="hidden" name="csrf_token" value="<?= bm_h($csrfToken); ?>">
                        <input type="hidden" name="action" value="send_bulk">
                        <input type="hidden" name="channel" id="bulkChannel" value="email">

                        <div class="form-grid mt-2">
                            <div class="form-group">
                                <label>Recipient Group <span class="req">*</span></label>
                                <select name="recipient_group" id="recipientGroup" class="form-control" required>
                                    <option value="all">All Active Members</option>
                                    <option value="zone">By Church Zone</option>
                                    <option value="department">By Department</option>
                                    <option value="youth">Youth</option>
                                    <option value="sunday_school_teachers">Sunday School Teachers</option>
                                    <option value="sunday_school_parents">Sunday School Children Parents</option>
                                </select>
                            </div>

                            <div class="form-group" id="zoneWrap" style="display:none;">
                                <label>Church Zone <span class="req">*</span></label>
                                <select name="zone_id" id="zone_id" class="form-control">
                                    <option value="0">-- Select Zone --</option>
                                    <?php foreach ($zones as $z): ?>
                                        <option value="<?= (int)$z['id']; ?>"><?= bm_h($z['zone_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" id="departmentWrap" style="display:none;">
                                <label>Department <span class="req">*</span></label>
                                <select name="department_id" id="department_id" class="form-control">
                                    <option value="0">-- Select Department --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['id']; ?>"><?= bm_h($d['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group email-only full-span">
                                <label>Email Subject <span class="req">*</span></label>
                                <input type="text" name="subject" id="subject" class="form-control" maxlength="180" placeholder="Enter email subject">
                            </div>

                            <div class="form-group full-span">
                                <label>Message <span class="req">*</span></label>
                                <textarea name="message" id="message" class="form-control" rows="8" required placeholder="Write your message here..."></textarea>
                                <small class="text-muted sms-only" style="display:none;">SMS supports plain text. Long messages may be charged as multiple SMS.</small>
                            </div>
                        </div>

                        <div class="bulk-actions">
                            <button type="button" class="btn btn-outline" id="btnPreviewRecipients">
                                <i class="fas fa-eye"></i> Preview Recipients
                            </button>

                            <button type="submit" class="btn btn-primary" id="btnSendBulk">
                                <i class="fas fa-paper-plane"></i> Send Email
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card" id="sendProgressCard" style="display:none;">
                <div class="card-header d-flex justify-between align-center">
                    <h3><i class="fas fa-tasks"></i> Sending Progress</h3>
                    <span class="badge badge-info" id="progressStatus">Queued</span>
                </div>

                <div class="card-body">
                    <div class="progress" style="height:12px;margin-bottom:12px;">
                        <div class="progress-bar" id="sendProgressBar" style="width:0%;"></div>
                    </div>

                    <div class="stats-grid stats-grid-3">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-check"></i></div>
                            <div>
                                <div class="stat-value" id="sentCount">0</div>
                                <div class="stat-label">Sent</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-times"></i></div>
                            <div>
                                <div class="stat-value" id="failedCount">0</div>
                                <div class="stat-label">Failed</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon"><i class="fas fa-ban"></i></div>
                            <div>
                                <div class="stat-value" id="skippedCount">0</div>
                                <div class="stat-label">Skipped</div>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted" id="progressText">Preparing messages...</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-between align-center">
                    <h3><i class="fas fa-address-book"></i> Recipient Preview</h3>
                    <div class="d-flex gap-2 align-center">
                        <span class="badge badge-primary" id="selectedCount">0 selected</span>
                        <span class="badge badge-secondary" id="previewCount">0 recipients</span>
                    </div>
                </div>

                <div class="card-body">
                    <div class="bulk-recipient-actions d-flex gap-2 align-center mb-2">
                        <label class="d-flex gap-2 align-center" style="cursor:pointer;">
                            <input type="checkbox" id="selectAllRecipients">
                            <strong>Select All</strong>
                        </label>

                        <button type="button" class="btn btn-sm btn-outline" id="btnClearRecipients">
                            <i class="fas fa-times"></i> Unselect All
                        </button>

                        <small class="text-muted">Only checked recipients will be queued for sending.</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table module-table">
                            <thead>
                                <tr>
                                    <th style="width:46px;"><input type="checkbox" id="selectAllRecipientsHead"></th>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Group</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                </tr>
                            </thead>

                            <tbody id="recipientPreviewBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Click Preview Recipients to load contacts.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.BulkMessagingConfig = {
    endpoint: 'process-bulk-messages.php',
    batchLimit: 8
};
</script>

<script>
(function(){
    'use strict';

    const cfg = window.BulkMessagingConfig || {};
    const qs = (s, r = document) => r.querySelector(s);
    const qsa = (s, r = document) => Array.from(r.querySelectorAll(s));
    const form = qs('#bulkMessageForm');

    if (!form || !cfg.endpoint) return;

    const alertBox = qs('#bulkAlert');
    const btnSend = qs('#btnSendBulk');

    let sending = false;
    let previewItems = [];

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, function(c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[c];
        });
    }

    function showAlert(msg, type = 'success') {
        if (!alertBox) return;

        alertBox.className = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        alertBox.textContent = msg || '';
        alertBox.scrollIntoView({behavior:'smooth', block:'center'});
    }

    async function request(body) {
        const res = await fetch(cfg.endpoint, {
            method: 'POST',
            body,
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });

        const text = await res.text();

        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid server response: ' + text.slice(0, 250));
        }
    }

    function setChannel(channel) {
        const bulkChannel = qs('#bulkChannel');
        if (bulkChannel) bulkChannel.value = channel;

        qsa('[data-bulk-tab]').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.bulkTab === channel);
        });

        qsa('.email-only').forEach(el => {
            el.style.display = channel === 'email' ? '' : 'none';
        });

        qsa('.sms-only').forEach(el => {
            el.style.display = channel === 'sms' ? '' : 'none';
        });

        if (btnSend) {
            btnSend.innerHTML = channel === 'email'
                ? '<i class="fas fa-paper-plane"></i> Send Email'
                : '<i class="fas fa-paper-plane"></i> Send SMS';
        }
    }

    function toggleZone() {
        const wrap = qs('#zoneWrap');
        const group = qs('#recipientGroup');

        if (wrap && group) {
            wrap.style.display = group.value === 'zone' ? '' : 'none';
        }

        const deptWrap = qs('#departmentWrap');
        if (deptWrap && group) {
            deptWrap.style.display = group.value === 'department' ? '' : 'none';
        }
    }

    function recipientPayload(r) {
        return btoa(unescape(encodeURIComponent(JSON.stringify({
            name: r.name || 'Member',
            email: r.email || '',
            phone: r.phone || '',
            group_name: r.group_name || 'Member'
        }))));
    }

    function decodeRecipientPayload(value) {
        try {
            return JSON.parse(decodeURIComponent(escape(atob(value))));
        } catch (e) {
            return null;
        }
    }

    function selectedRecipients() {
        return qsa('.recipient-check:checked')
            .map(chk => decodeRecipientPayload(chk.value))
            .filter(Boolean);
    }

    function updateSelectedCount() {
        const selected = selectedRecipients();
        const total = previewItems.length;

        const selectedCount = qs('#selectedCount');
        const statRecipients = qs('#statRecipients');
        const statEmails = qs('#statEmails');
        const statPhones = qs('#statPhones');

        if (selectedCount) selectedCount.textContent = selected.length + ' selected';
        if (statRecipients) statRecipients.textContent = selected.length;
        if (statEmails) statEmails.textContent = selected.filter(x => x.email).length;
        if (statPhones) statPhones.textContent = selected.filter(x => x.phone).length;

        const allChecked = total > 0 && selected.length === total;
        const top = qs('#selectAllRecipients');
        const head = qs('#selectAllRecipientsHead');

        if (top) top.checked = allChecked;
        if (head) head.checked = allChecked;
    }

    function setAllRecipients(checked) {
        qsa('.recipient-check').forEach(chk => {
            chk.checked = checked;
        });

        updateSelectedCount();
    }

    function renderRecipients(items) {
        previewItems = items || [];

        const previewCount = qs('#previewCount');
        const body = qs('#recipientPreviewBody');

        if (previewCount) {
            previewCount.textContent = previewItems.length + ' recipients';
        }

        if (!body) return;

        if (!previewItems.length) {
            body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No recipients found.</td></tr>';
            updateSelectedCount();
            return;
        }

        body.innerHTML = previewItems.map((r, i) => `
            <tr>
                <td><input type="checkbox" class="recipient-check" value="${recipientPayload(r)}" checked></td>
                <td>${i + 1}</td>
                <td>${esc(r.name || 'Member')}</td>
                <td>${esc(r.group_name || 'Member')}</td>
                <td>${esc(r.email || '—')}</td>
                <td>${esc(r.phone || '—')}</td>
            </tr>
        `).join('');

        updateSelectedCount();
    }

    async function previewRecipients() {
        const fd = new FormData(form);
        fd.set('action', 'preview');

        const data = await request(fd);

        if (!data.success) {
            showAlert(data.message || 'Failed to load recipients.', 'error');
            return;
        }

        renderRecipients(data.items || []);
    }

    function updateProgress(summary, remaining) {
        const total = Number(summary.total_recipients || 0);
        const sent = Number(summary.sent_count || 0);
        const failed = Number(summary.failed_count || 0);
        const skipped = Number(summary.skipped_count || 0);
        const done = sent + failed + skipped;
        const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

        const card = qs('#sendProgressCard');
        if (card) card.style.display = '';

        const sentCount = qs('#sentCount');
        const failedCount = qs('#failedCount');
        const skippedCount = qs('#skippedCount');
        const bar = qs('#sendProgressBar');
        const status = qs('#progressStatus');
        const text = qs('#progressText');

        if (sentCount) sentCount.textContent = sent;
        if (failedCount) failedCount.textContent = failed;
        if (skippedCount) skippedCount.textContent = skipped;
        if (bar) bar.style.width = pct + '%';
        if (status) status.textContent = summary.status || (remaining > 0 ? 'Processing' : 'Completed');
        if (text) text.textContent = done + '/' + total + ' processed. ' + (remaining || 0) + ' remaining.';
    }

    async function processCampaign(campaignId) {
        let remaining = 1;

        while (remaining > 0) {
            const fd = new FormData();
            fd.set('action', 'process_batch');
            fd.set('campaign_id', campaignId);
            fd.set('limit', String(cfg.batchLimit || 8));

            const data = await request(fd);

            if (!data.success) {
                throw new Error(data.message || 'Batch processing failed.');
            }

            remaining = Number(data.remaining || 0);

            if (data.summary) {
                updateProgress(data.summary, remaining);
            }

            await new Promise(resolve => setTimeout(resolve, 350));
        }
    }

    qsa('[data-bulk-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            setChannel(btn.dataset.bulkTab || 'email');
        });
    });

    const recipientGroup = qs('#recipientGroup');
    if (recipientGroup) {
        recipientGroup.addEventListener('change', () => {
            toggleZone();
            previewRecipients().catch(err => showAlert(err.message, 'error'));
        });
    }

    const zoneSelect = qs('#zone_id');
    if (zoneSelect) {
        zoneSelect.addEventListener('change', () => {
            previewRecipients().catch(err => showAlert(err.message, 'error'));
        });
    }

    const departmentSelect = qs('#department_id');
    if (departmentSelect) {
        departmentSelect.addEventListener('change', () => {
            previewRecipients().catch(err => showAlert(err.message, 'error'));
        });
    }

    const btnPreview = qs('#btnPreviewRecipients');
    if (btnPreview) {
        btnPreview.addEventListener('click', () => {
            previewRecipients().catch(err => showAlert(err.message, 'error'));
        });
    }

    const selectAll = qs('#selectAllRecipients');
    if (selectAll) {
        selectAll.addEventListener('change', e => {
            setAllRecipients(e.currentTarget.checked);
        });
    }

    const selectAllHead = qs('#selectAllRecipientsHead');
    if (selectAllHead) {
        selectAllHead.addEventListener('change', e => {
            setAllRecipients(e.currentTarget.checked);
        });
    }

    const clearBtn = qs('#btnClearRecipients');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            setAllRecipients(false);
        });
    }

    const previewBody = qs('#recipientPreviewBody');
    if (previewBody) {
        previewBody.addEventListener('change', e => {
            if (e.target.classList.contains('recipient-check')) {
                updateSelectedCount();
            }
        });
    }

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        if (sending) return;

        const channel = qs('#bulkChannel')?.value || 'email';
        const subject = qs('#subject')?.value.trim() || '';
        const message = qs('#message')?.value.trim() || '';
        const group = qs('#recipientGroup')?.value || 'all';
        const zoneId = Number(qs('#zone_id')?.value || 0);
        const departmentId = Number(qs('#department_id')?.value || 0);

        if (channel === 'email' && !subject) {
            showAlert('Email subject is required.', 'error');
            return;
        }

        if (!message) {
            showAlert('Message is required.', 'error');
            return;
        }

        if (group === 'zone' && zoneId <= 0) {
            showAlert('Please select a church zone.', 'error');
            return;
        }

        if (group === 'department' && departmentId <= 0) {
            showAlert('Please select a department.', 'error');
            return;
        }

        try {
            if (!previewItems.length) {
                await previewRecipients();
            }

            const chosen = selectedRecipients();

            if (!chosen.length) {
                showAlert('Please select at least one recipient.', 'error');
                return;
            }

            if (!confirm('Queue and send this message to ' + chosen.length + ' selected recipient(s)?')) {
                return;
            }

            sending = true;

            if (btnSend) {
                btnSend.disabled = true;
                btnSend.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
            }

            const progressCard = qs('#sendProgressCard');
            if (progressCard) progressCard.style.display = '';

            const progressText = qs('#progressText');
            if (progressText) progressText.textContent = 'Creating message queue...';

            const fd = new FormData(form);
            fd.set('action', 'create_campaign');
            fd.set('selected_recipients', JSON.stringify(chosen));

            const data = await request(fd);

            if (!data.success) {
                throw new Error(data.message || 'Failed to create message queue.');
            }

            if (data.summary) {
                updateProgress(data.summary, Number(data.summary.total_recipients || 0));
            }

            showAlert(data.message || 'Queue created. Processing batches...');

            if (btnSend) {
                btnSend.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            }

            await processCampaign(data.campaign_id);

            showAlert('Bulk message sending completed.');

        } catch (err) {
            showAlert(err.message || 'Sending failed.', 'error');
        } finally {
            sending = false;

            if (btnSend) {
                btnSend.disabled = false;
                btnSend.innerHTML = (qs('#bulkChannel')?.value || 'email') === 'email'
                    ? '<i class="fas fa-paper-plane"></i> Send Email'
                    : '<i class="fas fa-paper-plane"></i> Send SMS';
            }
        }
    });

    toggleZone();
    setChannel('email');
    previewRecipients().catch(() => {});
})();
</script>

<?php require_once '../includes/footer.php'; ?>