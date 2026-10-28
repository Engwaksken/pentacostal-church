
(function () {
    'use strict';

    /* -------------------------------------------------------------------------
     * SHARED UTILITIES
     * ---------------------------------------------------------------------- */

    /** @param {string} sel @param {Document|Element} [root] */
    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function escHtml(v) {
        return String(v ?? '').replace(/[&<>'"]/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
        }[c]));
    }

    function badge(text, cls = 'badge-secondary') {
        return `<span class="badge ${cls}">${escHtml(text || '—')}</span>`;
    }

    function showAlert(el, msg, type = 'success') {
        if (!el) return;
        el.className = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        el.textContent = msg || '';
    }

    function clearAlert(el) {
        if (!el) return;
        el.className = 'ajax-alert';
        el.textContent = '';
    }

    function lockBody(lock) {
        document.body.style.overflow = lock ? 'hidden' : '';
    }

    /**
     * Open any .app-modal by id (with or without leading #).
     * Finds the nearest .app-modal-backdrop automatically.
     */
    function openModal(idOrSelector) {
        const sel    = idOrSelector.startsWith('#') ? idOrSelector : '#' + idOrSelector;
        const modal  = $(sel);
        if (!modal) return;
        const bdId   = modal.dataset.backdrop || modal.dataset.modalBackdrop;
        const bd     = bdId ? $('#' + bdId) : $('.app-modal-backdrop');
        if (bd)  bd.classList.add('show');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        lockBody(true);
    }

    /** Close every open modal / backdrop on the page. */
    function closeModals() {
        $$('.app-modal, .modal-overlay, .app-modal-backdrop, .modal-backdrop').forEach(el => {
            el.classList.remove('show');
            if (el.hasAttribute('aria-hidden')) el.setAttribute('aria-hidden', 'true');
        });
        lockBody(false);
    }

    /**
     * Fetch a URL, parse JSON.  Throws on network or parse error.
     * @param {string} url
     * @param {RequestInit} [opts]
     */
    async function request(url, opts = {}) {
        const res  = await fetch(url, {
            ...opts,
            headers: { 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers || {}) }
        });
        const text = await res.text();
        try { return JSON.parse(text); }
        catch { throw new Error('Invalid server response: ' + text.slice(0, 300)); }
    }

    /** POST a <form> element to endpoint, return parsed JSON. */
    async function postForm(endpoint, form) {
        return request(endpoint, { method: 'POST', body: new FormData(form) });
    }

    /* Expose to other scripts / inline handlers */
    window.AppUI = { $, $$, escHtml, badge, showAlert, clearAlert, openModal, closeModals, request, postForm };

    /* Legacy globals used by older pages */
    window.confirmDelete = (url) => {
        if (confirm('Are you sure you want to delete this record? This cannot be undone.'))
            window.location.href = url;
    };
    window.printPage = () => window.print();


    /* -------------------------------------------------------------------------
     * SHARED LAYOUT  — sidebar toggle, flash auto-dismiss, topbar date, etc.
     * ---------------------------------------------------------------------- */
    function initMobileSidebar() {
        const sidebar = document.getElementById('sidebar') || document.querySelector('.sidebar');
        const toggle = document.getElementById('sidebarToggle');

        if (!sidebar || !toggle || document.documentElement.dataset.sidebarReady === '1') {
            return;
        }

        document.documentElement.dataset.sidebarReady = '1';

        let overlay = document.getElementById('sidebarOverlay')
            || document.querySelector('.sidebar-overlay');

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'sidebarOverlay';
            overlay.className = 'sidebar-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            document.body.appendChild(overlay);
        }

        function setOpen(open) {
            sidebar.classList.toggle('open', open);
            overlay.classList.toggle('show', open);
            document.body.classList.toggle('sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        /* Capture phase blocks duplicate handlers from topbar.php or older scripts. */
        document.addEventListener('click', function (event) {
            const clickedToggle = event.target.closest('#sidebarToggle');

            if (clickedToggle) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                setOpen(!sidebar.classList.contains('open'));
                return;
            }

            if (event.target === overlay) {
                event.preventDefault();
                setOpen(false);
                return;
            }

            if (
                window.innerWidth <= 767
                && sidebar.classList.contains('open')
                && event.target.closest('.sidebar-nav a')
            ) {
                setOpen(false);
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 767) {
                setOpen(false);
            }
        });

        setOpen(false);
    }

    function initSharedUI() {
        /* Sidebar is controlled once by initMobileSidebar() below. */

        /* Auto-dismiss flash alerts after 5 s. Only flash messages (showFlash() adds .alert-dismissible):
           other .alert boxes, such as notes or a form's running total, must stay on the page. */
        $$('.alert.alert-dismissible').forEach(el => {
            if (el.dataset.autoDismiss || el.closest('.modal, .ajax-modal, .app-modal, [role="dialog"]')) return;
            el.dataset.autoDismiss = '1';
            setTimeout(() => {
                el.style.cssText += 'opacity:0;transform:translateY(-8px);transition:all .5s ease';
                setTimeout(() => el.remove(), 500);
            }, 5000);
        });

        /* data-confirm buttons */
        $$('[data-confirm]').forEach(btn => {
            if (btn.dataset.confirmBound) return;
            btn.dataset.confirmBound = '1';
            btn.addEventListener('click', function (e) {
                if (!confirm(this.dataset.confirm || 'Are you sure?')) e.preventDefault();
            });
        });

        /* Live date in topbar */
        const dateEl = $('.topbar-date');
        if (dateEl) {
            dateEl.textContent = new Date().toLocaleDateString('en-UG', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });
        }

        /* data-modal="someModalId" open triggers */
        $$('[data-modal]').forEach(btn => {
            if (btn.dataset.modalBound) return;
            btn.dataset.modalBound = '1';
            btn.addEventListener('click', function () { openModal(this.dataset.modal); });
        });

        /* Single delegated close-modal handler */
        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-close-modal]')) closeModals();
        });

        /* Click-outside-modal to close */
        $$('.modal-overlay, .app-modal').forEach(modal => {
            if (modal.dataset.overlayBound) return;
            modal.dataset.overlayBound = '1';
            modal.addEventListener('click', function (e) {
                if (e.target === this) closeModals();
            });
        });

        /* Escape key */
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

        /* Generic table search (pages that don't have their own AJAX table) */
        const tableSearch = $('#tableSearch');
        if (tableSearch && !tableSearch.dataset.bound && !$('#yrTableBody') && !$('#titheTableBody')) {
            tableSearch.dataset.bound = '1';
            tableSearch.addEventListener('input', function () {
                const val = this.value.toLowerCase();
                $$('tbody tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
                });
            });
        }
    }


    /* -------------------------------------------------------------------------
     * YOUTH REGISTRY
     * Requires: window.YouthRegistryConfig = { endpoint, today }
     *           tbody#yrTableBody in the DOM
     * ---------------------------------------------------------------------- */
    function initYouthRegistry() {
        const cfg       = window.YouthRegistryConfig;
        const tableBody = $('#yrTableBody');
        if (!cfg?.endpoint || !tableBody) return;

        let rows = [];

        /* -- helpers -------------------------------------------------------- */

        function setText(sel, val) {
            const el = $(sel); if (el) el.textContent = val;
        }

        function renderStats(items) {
            setText('#yrStatTotal',    items.length);
            setText('#yrStatActive',   items.filter(x => (x.status || 'Active') === 'Active').length);
            setText('#yrStatInactive', items.filter(x => (x.status || 'Active') !== 'Active').length);
            setText('#yrStatMale',     items.filter(x => ['male','m'].includes(String(x.gender || '').toLowerCase())).length);
            setText('#yrStatFemale',   items.filter(x => ['female','f'].includes(String(x.gender || '').toLowerCase())).length);
        }

        function renderTable(items) {
            if (!items.length) {
                tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:24px">No youth records found.</td></tr>';
                return;
            }
            tableBody.innerHTML = items.map((y, i) => {
                const name      = (y.full_name || `${y.first_name || ''} ${y.last_name || ''}`.trim()) || '—';
                const status    = y.status || 'Active';
                return `<tr>
                    <td>${i + 1}</td>
                    <td>${badge(y.youth_no, 'badge-gold')}</td>
                    <td><strong>${escHtml(name)}</strong></td>
                    <td>${escHtml(y.gender || '—')}</td>
                    <td>${escHtml(y.phone  || '—')}</td>
                    <td>${escHtml(y.education_level || '—')}</td>
                    <td>${badge(status, status === 'Active' ? 'badge-success' : 'badge-secondary')}</td>
                    <td>
                        <div class="table-action">
                            <button type="button" class="btn btn-sm btn-info    btn-icon" data-yr-action="view"     data-id="${+y.id}" title="View"><i class="fa fa-eye"></i></button>
                            <button type="button" class="btn btn-sm btn-primary btn-icon" data-yr-action="edit"     data-id="${+y.id}" title="Edit"><i class="fa fa-edit"></i></button>
                            <button type="button" class="btn btn-sm btn-danger  btn-icon" data-yr-action="delete"   data-id="${+y.id}" data-name="${escHtml(name)}" title="Delete"><i class="fa fa-trash"></i></button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        function applySearch() {
            const term = ($('#yrSearch')?.value || '').toLowerCase();
            renderTable(term ? rows.filter(r => Object.values(r).join(' ').toLowerCase().includes(term)) : rows);
        }

        async function loadYouth() {
            clearAlert($('#yrAlert'));
            const data = await request(cfg.endpoint + '?action=list');
            if (!data.success) throw new Error(data.message || 'Failed to load youth records.');
            rows = data.items || [];
            renderStats(rows);
            applySearch();
        }

        /* -- form helpers --------------------------------------------------- */

        function resetYouthForm() {
            const form = $('#yrForm');
            if (!form) return;
            form.reset();
            $('#yrAction').value       = 'create';
            $('#yrId').value           = '';
            $('#yrFormTitle').textContent = 'Register Youth';
            $('#yrSaveBtn').innerHTML  = '<i class="fa fa-save"></i> Save Youth';
            const wrap = $('#yrMemberWrap'), mem = $('#yrMemberId');
            if (wrap) wrap.style.display = '';
            if (mem)  mem.required = true;
            clearAlert($('#yrFormAlert'));
        }

        async function openYouthEdit(id) {
            resetYouthForm();
            const data = await request(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
            if (!data.success) { showAlert($('#yrAlert'), data.message || 'Record not found.', 'error'); return; }

            const y = data.item || {};
            $('#yrAction').value      = 'update';
            $('#yrId').value          = y.id || '';
            $('#yrFormTitle').textContent = 'Edit Youth';
            $('#yrSaveBtn').innerHTML = '<i class="fa fa-save"></i> Update Youth';

            const wrap = $('#yrMemberWrap'), mem = $('#yrMemberId');
            if (wrap) wrap.style.display = 'none';
            if (mem)  mem.required = false;

            const set = (sel, val) => { const el = $(sel); if (el) el.value = val || ''; };
            set('#yrEducation', y.education_level);
            set('#yrSchool',    y.school_or_work);
            set('#yrNok',       y.next_of_kin);
            set('#yrNokPhone',  y.next_of_kin_phone);
            set('#yrRegDate',   y.registered_date || cfg.today);
            set('#yrStatus',    y.status || 'Active');
            set('#yrSkills',    y.skills);

            openModal('yrFormModal');
        }

        async function openYouthView(id) {
            const data = await request(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
            if (!data.success) { showAlert($('#yrAlert'), data.message || 'Record not found.', 'error'); return; }

            const y    = data.item || {};
            const name = y.full_name || `${y.first_name || ''} ${y.last_name || ''}`.trim();
            const fields = [
                ['Youth No', y.youth_no], ['Name', name], ['Gender', y.gender],
                ['Phone', y.phone], ['Email', y.email], ['Date of Birth', y.date_of_birth],
                ['Education', y.education_level], ['School / Workplace', y.school_or_work],
                ['Next of Kin', y.next_of_kin], ['Next of Kin Phone', y.next_of_kin_phone],
                ['Registered', y.registered_date], ['Status', y.status], ['Skills', y.skills]
            ];

            const box = $('#yrViewContent');
            if (box) box.innerHTML = fields.map(([l, v]) =>
                `<div class="detail-item"><b>${escHtml(l)}</b><span>${escHtml(v || '—')}</span></div>`
            ).join('');

            openModal('yrViewModal');
        }

        /* -- event listeners ------------------------------------------------ */

        /* Delegated clicks for all youth actions */
        document.addEventListener('click', function (e) {
            /* "Register Youth" button */
            if (e.target.closest('[data-open-youth-modal], #btnOpenAddYouth')) {
                resetYouthForm();
                openModal('yrFormModal');
                return;
            }

            const btn = e.target.closest('[data-yr-action]');
            if (!btn) return;

            const action = btn.dataset.yrAction;
            const id     = btn.dataset.id;

            switch (action) {
                case 'view':
                    openYouthView(id).catch(err => showAlert($('#yrAlert'), err.message, 'error'));
                    break;
                case 'edit':
                    openYouthEdit(id).catch(err => showAlert($('#yrAlert'), err.message, 'error'));
                    break;
                case 'delete':
                    const delId   = $('#yrDeleteId');
                    const delName = $('#yrDeleteName');
                    if (delId)   delId.value       = id;
                    if (delName) delName.textContent = btn.dataset.name || 'this youth';
                    clearAlert($('#yrDeleteAlert'));
                    openModal('yrDeleteModal');
                    break;
            }
        });

        $('#yrSearch')?.addEventListener('input', applySearch);
        $('#yrRefresh')?.addEventListener('click', () =>
            loadYouth().catch(err => showAlert($('#yrAlert'), err.message, 'error'))
        );

        /* Save / update youth */
        $('#yrForm')?.addEventListener('submit', async function (e) {
            e.preventDefault();
            clearAlert($('#yrFormAlert'));
            try {
                const data = await postForm(cfg.endpoint, this);
                if (!data.success) { showAlert($('#yrFormAlert'), data.message || 'Save failed.', 'error'); return; }
                closeModals();
                showAlert($('#yrAlert'), data.message || 'Saved successfully.');
                await loadYouth();
            } catch (err) { showAlert($('#yrFormAlert'), err.message, 'error'); }
        });

        /* Delete youth */
        $('#yrDeleteForm')?.addEventListener('submit', async function (e) {
            e.preventDefault();
            clearAlert($('#yrDeleteAlert'));
            try {
                const data = await postForm(cfg.endpoint, this);
                if (!data.success) { showAlert($('#yrDeleteAlert'), data.message || 'Delete failed.', 'error'); return; }
                closeModals();
                showAlert($('#yrAlert'), data.message || 'Deleted successfully.');
                await loadYouth();
            } catch (err) { showAlert($('#yrDeleteAlert'), err.message, 'error'); }
        });

        /* Initial load */
        loadYouth().catch(err => showAlert($('#yrAlert'), err.message, 'error'));
    }


    /* -------------------------------------------------------------------------
     * TITHE REGISTRY
     * Requires: window.TitheConfig = { endpoint, receiptUrl }
     *           tbody#titheTableBody in the DOM
     * ---------------------------------------------------------------------- */
    function initTitheRegistry() {
        const cfg = window.TitheConfig;
        if (!cfg || !$('#titheTableBody')) return;

        const endpoint   = cfg.endpoint   || 'process-tithe.php';
        const receiptUrl = cfg.receiptUrl || 'download_receipt.php?tithe_id=';
        const MONTHS     = ['','January','February','March','April','May','June',
                            'July','August','September','October','November','December'];
        let rows = [];

        /* -- formatters ----------------------------------------------------- */

        function money(v) { return 'UGX ' + Number(v || 0).toLocaleString(); }

        function period(r) {
            const m = Number(r.period_month || 0);
            return (m >= 1 && m <= 12 ? MONTHS[m] + ' ' : '') + (r.period_year || '') || '—';
        }

        function memberName(r) {
            const n = String(r.full_name || '').trim() || 'Anonymous / Visitor';
            return n + (r.member_no ? ` (${r.member_no})` : '');
        }

        /* -- render --------------------------------------------------------- */

        function updateStats(items) {
            const total = items.reduce((s, r) => s + Number(r.amount || 0), 0);
            const sa = $('#statAll'),   sc = $('#statCount');
            if (sa) sa.textContent = money(total);
            if (sc) sc.textContent = items.length.toLocaleString();
        }

        function renderTable(items) {
            const body = $('#titheTableBody');
            if (!body) return;
            if (!items.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:24px">No tithe records found.</td></tr>';
                return;
            }
            body.innerHTML = items.map((r, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td><strong>${escHtml(r.receipt_no || '')}</strong></td>
                    <td>${escHtml(memberName(r))}</td>
                    <td><strong>${money(r.amount)}</strong></td>
                    <td>${escHtml(period(r))}</td>
                    <td>${escHtml(r.tithe_date || '')}</td>
                    <td>${badge(r.payment_method || '—', 'badge-info')}</td>
                    <td>
                        <div class="table-action">
                            <button type="button" class="btn btn-sm btn-info    btn-icon" data-tithe-action="view"   data-id="${+r.id}" title="View"><i class="fas fa-eye"></i></button>
                            <button type="button" class="btn btn-sm btn-warning btn-icon" data-tithe-action="edit"   data-id="${+r.id}" title="Edit"><i class="fas fa-edit"></i></button>
                            <a class="btn btn-sm btn-success btn-icon" href="${receiptUrl}${+r.id}" target="_blank"   title="Receipt"><i class="fas fa-file-pdf"></i></a>
                            <button type="button" class="btn btn-sm btn-danger  btn-icon" data-tithe-action="delete" data-id="${+r.id}" data-receipt="${escHtml(r.receipt_no || '')}" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>
                    </td>
                </tr>`).join('');
        }

        function applySearch() {
            const term = ($('#titheSearch')?.value || '').toLowerCase();
            renderTable(term ? rows.filter(r => Object.values(r).join(' ').toLowerCase().includes(term)) : rows);
        }

        function loadTithe() {
            clearAlert($('#titheAlert'));
            const params = new URLSearchParams({
                action:    'list',
                member_id: $('#filterMember')?.value || 0,
                month:     $('#filterMonth')?.value  || 0,
                year:      $('#filterYear')?.value   || new Date().getFullYear()
            });
            return request(endpoint + '?' + params).then(data => {
                if (!data.success) throw new Error(data.message || 'Failed to load tithe records.');
                rows = data.items || [];
                updateStats(rows);
                applySearch();
            });
        }

        /* -- form helpers --------------------------------------------------- */

        function resetTitheForm() {
            const form = $('#titheForm');
            if (form) form.reset();
            const act = $('#titheAction'), tid = $('#titheId');
            const ttl = $('#titheFormTitle'), sav = $('#saveTitheBtn');
            if (act) act.value = 'create';
            if (tid) tid.value = '';
            if (ttl) ttl.innerHTML = '<i class="fas fa-receipt"></i> Record Tithe';
            if (sav) sav.innerHTML = '<i class="fas fa-save"></i> Save Tithe';
            clearAlert($('#formAlert'));
        }

        function openTitheEdit(id) {
            resetTitheForm();
            request(endpoint + '?action=get&id=' + encodeURIComponent(id))
                .then(data => {
                    if (!data.success) { showAlert($('#titheAlert'), data.message || 'Record not found.', 'error'); return; }
                    const r   = data.item || {};
                    const set = (sel, val) => { const el = $(sel); if (el) el.value = val ?? ''; };
                    set('#titheAction',   'update');
                    set('#titheId',       r.id);
                    set('#member_id',     r.member_id || 0);
                    set('#amount',        r.amount);
                    set('#period_month',  r.period_month || 0);
                    set('#period_year',   r.period_year  || new Date().getFullYear());
                    set('#tithe_date',    r.tithe_date);
                    set('#payment_method', r.payment_method || 'Cash');
                    set('#notes',         r.notes);
                    const ttl = $('#titheFormTitle'), sav = $('#saveTitheBtn');
                    if (ttl) ttl.innerHTML = '<i class="fas fa-edit"></i> Edit Tithe';
                    if (sav) sav.innerHTML = '<i class="fas fa-save"></i> Update Tithe';
                    openModal('#titheFormModal');
                })
                .catch(err => showAlert($('#titheAlert'), err.message, 'error'));
        }

        function openTitheView(id) {
            request(endpoint + '?action=get&id=' + encodeURIComponent(id))
                .then(data => {
                    if (!data.success) { showAlert($('#titheAlert'), data.message || 'Record not found.', 'error'); return; }
                    const r      = data.item || {};
                    const fields = [
                        ['Receipt No', r.receipt_no], ['Member', memberName(r)],
                        ['Amount', money(r.amount)],  ['Period', period(r)],
                        ['Date Paid', r.tithe_date],  ['Payment Method', r.payment_method],
                        ['Notes', r.notes]
                    ];
                    const box = $('#viewTitheContent');
                    if (box) box.innerHTML = fields.map(([l, v]) =>
                        `<div class="detail-item"><b>${escHtml(l)}</b><span>${escHtml(v || '—')}</span></div>`
                    ).join('');
                    openModal('#viewTitheModal');
                })
                .catch(err => showAlert($('#titheAlert'), err.message, 'error'));
        }

        /* -- event listeners ------------------------------------------------ */

        /* Open "Record Tithe" modal */
        const openBtn = $('#btnOpenTithe') || $('[data-open-tithe-modal]');
        if (openBtn) openBtn.addEventListener('click', e => {
            e.preventDefault(); resetTitheForm(); openModal('#titheFormModal');
        });

        /* Refresh + filter changes */
        $('#btnRefreshTithe')?.addEventListener('click', () =>
            loadTithe().catch(err => showAlert($('#titheAlert'), err.message, 'error'))
        );
        ['#filterMember','#filterMonth','#filterYear'].forEach(sel =>
            $(sel)?.addEventListener('change', () =>
                loadTithe().catch(err => showAlert($('#titheAlert'), err.message, 'error'))
            )
        );

        /* Search */
        $('#titheSearch')?.addEventListener('input', applySearch);

        /* Delegated row-action clicks */
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-tithe-action]');
            if (!btn) return;
            const action = btn.dataset.titheAction;
            const id     = btn.dataset.id;
            if (action === 'view')   { openTitheView(id); return; }
            if (action === 'edit')   { openTitheEdit(id); return; }
            if (action === 'delete') {
                const delId  = $('#deleteTitheId');
                const delRec = $('#deleteReceiptNo');
                if (delId)  delId.value        = id;
                if (delRec) delRec.textContent  = btn.dataset.receipt || 'this receipt';
                clearAlert($('#deleteAlert'));
                openModal('#deleteTitheModal');
            }
        });

        /* Save tithe */
        $('#titheForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            postForm(endpoint, this)
                .then(data => {
                    if (!data.success) { showAlert($('#formAlert'), data.message || 'Save failed.', 'error'); return; }
                    closeModals();
                    showAlert($('#titheAlert'), data.message || 'Saved successfully.');
                    return loadTithe();
                })
                .catch(err => showAlert($('#formAlert'), err.message, 'error'));
        });

        /* Delete tithe */
        $('#deleteTitheForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            postForm(endpoint, this)
                .then(data => {
                    if (!data.success) { showAlert($('#deleteAlert'), data.message || 'Delete failed.', 'error'); return; }
                    closeModals();
                    showAlert($('#titheAlert'), data.message || 'Deleted successfully.');
                    return loadTithe();
                })
                .catch(err => showAlert($('#deleteAlert'), err.message, 'error'));
        });

        /* Initial load */
        loadTithe().catch(err => showAlert($('#titheAlert'), err.message, 'error'));
    }


    /* -------------------------------------------------------------------------
     * SETTINGS MODULE
     * Requires: window.SettingsPageConfig = { endpoint }
     *           #settingsForm, #settingsModal, #settingsBackdrop in the DOM
     * ---------------------------------------------------------------------- */
    function initSettingsModule() {
        const cfg = window.SettingsPageConfig;
        if (!cfg) return;

        const endpoint = cfg.endpoint || 'process-settings.php';

        /* -- modal open / close -------------------------------------------- */

        function openSettingsModal() {
            const bd = $('#settingsBackdrop'), m = $('#settingsModal');
            if (bd) bd.classList.add('show');
            if (m)  m.classList.add('show');
            lockBody(true);
        }

        function closeSettingsModal() {
            const bd = $('#settingsBackdrop'), m = $('#settingsModal');
            if (bd) bd.classList.remove('show');
            if (m)  m.classList.remove('show');
            lockBody(false);
        }

        /* -- delegated triggers -------------------------------------------- */

        document.addEventListener('click', function (e) {
            if (e.target.closest('#btnOpenSettingsModal'))              openSettingsModal();
            if (e.target.closest('[data-close-settings]'))             closeSettingsModal();
            if (e.target.id === 'settingsBackdrop')                    closeSettingsModal();
        });

        /* Escape key is already handled globally by initSharedUI, but
           settings has its own backdrop/modal so we also catch it here
           in case the page uses settings without the shared modal set. */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSettingsModal();
        });

        /* -- form submit ---------------------------------------------------- */

        const form = $('#settingsForm');
        if (!form) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const alertBox  = $('#settingsFormAlert');
            const pageAlert = $('#settingsAlert');
            const btn       = $('#saveSettingsBtn');

            clearAlert(alertBox);
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…'; }

            try {
                const data = await request(endpoint, { method: 'POST', body: new FormData(form) });
                if (!data.success) {
                    showAlert(alertBox, data.message || 'Failed to save settings.', 'error');
                    return;
                }
                closeSettingsModal();
                showAlert(pageAlert, data.message || 'Settings saved successfully.', 'success');
                setTimeout(() => window.location.reload(), 700);
            } catch (err) {
                showAlert(alertBox, err.message, 'error');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Settings'; }
            }
        });
    }


    /* -------------------------------------------------------------------------
     * MONEY INPUTS — show thousands separators while typing (50,000)
     * The field's .value and any submitted/FormData value stay plain (50000),
     * so page scripts and the server keep working unchanged.
     * ---------------------------------------------------------------------- */
    const MONEY_FIELDS = /^(amount|target_amount|pledged_amount|amount_pledged|amount_collected|paid_amount|loose_total|envelope_total|budget|extra)$/;
    const nativeValue  = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

    const plainMoney = v => String(v ?? '').replace(/,/g, '').trim();

    function formatMoney(v) {
        const raw = plainMoney(v).replace(/[^\d.\-]/g, '');
        if (raw === '' || raw === '-') return raw;
        const [whole, ...rest] = raw.split('.');
        const sign = whole.startsWith('-') ? '-' : '';
        const digits = whole.replace(/-/g, '').replace(/^0+(?=\d)/, '');
        const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return sign + grouped + (rest.length ? '.' + rest.join('').slice(0, 2) : '');
    }

    function enhanceMoneyInput(input) {
        if (input.dataset.money || input.type !== 'number' || !MONEY_FIELDS.test(input.name)) return;
        input.dataset.money = '1';
        const initial = nativeValue.get.call(input).replace(/\.0+$/, '');
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';

        Object.defineProperty(input, 'value', {
            configurable: true,
            get() { return plainMoney(nativeValue.get.call(this)); },
            set(v) { nativeValue.set.call(this, formatMoney(String(v ?? '').replace(/\.0+$/, ''))); }
        });
        input.value = initial;

        input.addEventListener('input', function () {
            const before = nativeValue.get.call(this);
            const caret = this.selectionStart ?? before.length;
            const digitsLeft = before.slice(0, caret).replace(/[^\d.]/g, '').length;
            const after = formatMoney(before);
            nativeValue.set.call(this, after);
            let pos = 0, seen = 0;
            while (pos < after.length && seen < digitsLeft) { if (/[\d.]/.test(after[pos])) seen++; pos++; }
            try { this.setSelectionRange(pos, pos); } catch (e) { /* not focusable */ }
        });
    }

    function initMoneyInputs() {
        $$('input').forEach(enhanceMoneyInput);
        new MutationObserver(records => records.forEach(r => r.addedNodes.forEach(node => {
            if (node.nodeType !== 1) return;
            if (node.matches('input')) enhanceMoneyInput(node);
            $$('input', node).forEach(enhanceMoneyInput);
        }))).observe(document.body, { childList: true, subtree: true });

        // Strip separators from native submits and from `new FormData(form)`.
        document.addEventListener('formdata', e => {
            $$('input[data-money]', e.target).forEach(input => {
                if (input.name && e.formData.has(input.name)) e.formData.set(input.name, input.value);
            });
        });
    }


    /* -------------------------------------------------------------------------
     * BOOT
     * ---------------------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initMobileSidebar();
        initSharedUI();
        initMoneyInputs();
        initYouthRegistry();
        initTitheRegistry();
        initSettingsModule();
    });

})();