(function () {
    'use strict';

    const endpoint = 'process-sunday-school.php';
    let pupils = [];
    let classes = [];
    let bulkRowCounter = 0;
    let pupilPage = 1;
    let pupilPagination = {page:1,per_page:25,total:0,total_pages:1,from:0,to:0};
    let pupilSearchTimer = null;

    const qs  = (s, r = document) => r.querySelector(s);
    const qsa = (s, r = document) => Array.from(r.querySelectorAll(s));

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[c]));

    /* =========================================================
       ALERTS
    ========================================================= */
    function showAlert(el, msg, type = 'success') {
        if (!el) return;
        el.className   = 'ss-alert ' + (type === 'error' ? 'error' : 'success');
        el.textContent = msg;
    }
    function clearAlert(el) {
        if (!el) return;
        el.className   = 'ss-alert';
        el.textContent = '';
    }

    /* =========================================================
       MODALS
    ========================================================= */
    function openModal(id) {
        const backdrop = qs('#ssBackdrop');
        const modal    = qs(id);
        if (backdrop) backdrop.classList.add('show');
        if (modal)    modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeModals() {
        qsa('.ss-modal, .ss-modal-backdrop').forEach(x => x.classList.remove('show'));
        document.body.style.overflow = '';
    }

    /* =========================================================
       HTTP
    ========================================================= */
    async function request(url, opts = {}) {
        const res  = await fetch(url, Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' } }, opts));
        const text = await res.text();
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Invalid server response: ' + text.slice(0, 250)); }
    }
    async function postForm(form) {
        return request(endpoint, { method: 'POST', body: new FormData(form) });
    }

    /* =========================================================
       EXPORT HELPERS
    ========================================================= */

    /**
     * Build the export URL with optional class_id / status filter
     * values read from the export bar selects.
     */
    function exportParams(action) {
        const classId = qs('#exportClassFilter')?.value  || '';
        const status  = qs('#exportStatusFilter')?.value || '';
        let url = endpoint + '?action=' + action;
        if (classId) url += '&class_id=' + encodeURIComponent(classId);
        if (status)  url += '&status='   + encodeURIComponent(status);
        return url;
    }

    /** Force a file download via a temporary <a> element. */
    function triggerDownload(url) {
        const a = document.createElement('a');
        a.href  = url;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    /** Open a URL in a new browser tab (used for PDFs). */
    function openTab(url) {
        window.open(url, '_blank');
    }

    function toggleExportBar(show) {
        const bar = qs('#ssExportBar');
        if (bar) bar.style.display = show ? 'flex' : 'none';
    }

    /* =========================================================
       MISC HELPERS
    ========================================================= */
    function badge(txt, cls) {
        return `<span class="badge ${cls || 'badge-secondary'}">${esc(txt)}</span>`;
    }

    function showTab(tab) {
        qsa('.ss-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        ['classes', 'pupils', 'attendance'].forEach(t => {
            const el = qs('#tab' + t.charAt(0).toUpperCase() + t.slice(1));
            if (el) el.classList.toggle('d-none', t !== tab);
        });
    }

    /* =========================================================
       STATS
    ========================================================= */
    async function loadStats() {
        const d = await request(endpoint + '?action=stats');
        if (!d.success) return;
        const s = d.stats || {};
        const map = {
            statTotalPupils:     s.totalPupils     || 0,
            statActivePupils:    s.activePupils    || 0,
            statClasses:         s.classes         || 0,
            statBaptised:        s.baptised        || 0,
            statAwaiting:        Math.max(0, (s.totalPupils || 0) - (s.baptised || 0)),
            statTodayAttendance: s.todayAttendance || 0,
        };
        Object.keys(map).forEach(id => {
            const el = qs('#' + id);
            if (el) el.textContent = map[id];
        });
    }

    /* =========================================================
       CLASSES
    ========================================================= */
    async function loadClasses() {
        const d = await request(endpoint + '?action=list&type=classes');
        if (!d.success) throw new Error(d.message || 'Failed to load classes.');
        classes = d.items || [];
        renderClasses();
    }

    function renderClasses() {
        const grid = qs('#classesGrid');
        if (!grid) return;
        if (!classes.length) {
            grid.innerHTML = '<div class="text-muted">No classes found.</div>';
            return;
        }
        grid.innerHTML = classes.map(c => `
            <div class="ss-class-card">
                <div class="ss-class-head">
                    <h5 class="mb-0">${esc(c.class_name)}</h5>
                    <small>${esc(c.age_range || 'Age range not set')}</small>
                </div>
                <div class="ss-class-body">
                    <p><i class="fas fa-chalkboard-teacher"></i> ${esc((c.teacher_name || '').trim() || 'No teacher assigned')}</p>
                    <div class="ss-class-count">${Number(c.pupil_count || 0)}</div>
                    <p class="text-center text-muted">pupils enrolled</p>
                    <p class="text-muted small">Capacity: ${esc(c.capacity || 0)}</p>
                    <div class="ss-table-actions">
                        <button type="button" class="btn btn-sm btn-primary" data-view-class-pupils="${Number(c.id)}" title="View pupils">
                            <i class="fas fa-users"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-warning" data-edit-class="${Number(c.id)}" title="Edit class">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-danger"
                                data-delete-class="${Number(c.id)}" data-name="${esc(c.class_name)}" title="Delete class">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    /* =========================================================
       PUPILS
    ========================================================= */
    async function loadPupils(page = pupilPage) {
        pupilPage=Math.max(1,Number(page)||1);
        const params=new URLSearchParams({action:'list',type:'pupils',page:String(pupilPage),per_page:qs('#pupilPerPage')?.value||'25',class_id:qs('#classFilter')?.value||'',search:qs('#pupilSearch')?.value.trim()||'',period:qs('#pupilPeriodFilter')?.value||'',date_from:qs('#pupilDateFrom')?.value||'',date_to:qs('#pupilDateTo')?.value||''});
        const d=await request(endpoint+'?'+params.toString());if(!d.success)throw new Error(d.message||'Failed to load pupils.');pupils=d.items||[];pupilPagination=d.pagination||pupilPagination;pupilPage=Number(pupilPagination.page||1);renderPupils();renderPupilPagination();
    }
    function renderPupils(){const body=qs('#pupilsBody');if(!body)return;if(!pupils.length){body.innerHTML='<tr><td colspan="11" class="text-muted">No pupils found.</td></tr>';return;}const start=Number(pupilPagination.from||1);body.innerHTML=pupils.map((p,i)=>`<tr><td>${start+i}</td><td>${esc(p.pupil_no||'')}</td><td><strong>${esc(p.full_name||'')}</strong><br><small class="text-muted">Enrolled: ${esc(p.date_enrolled||'-')}</small></td><td>${esc(p.gender||'')}</td><td>${esc(p.class_name||'')}</td><td>${esc(p.age||'')}</td><td>${esc(p.parent_guardian||'')}</td><td>${esc(p.parent_phone||'')}</td><td>${Number(p.baptised||0)===1?badge('Yes','badge-success'):badge('No','badge-secondary')}</td><td>${badge(p.status||'Active',(p.status||'Active')==='Active'?'badge-success':'badge-secondary')}</td><td><div class="ss-table-actions"><button type="button" class="btn btn-sm btn-info" data-view-pupil="${Number(p.id)}"><i class="fas fa-eye"></i></button><button type="button" class="btn btn-sm btn-primary" data-edit-pupil="${Number(p.id)}"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-sm btn-danger" data-delete-pupil="${Number(p.id)}" data-name="${esc(p.full_name)}"><i class="fas fa-trash"></i></button></div></td></tr>`).join('');}
    function renderPupilPagination(){const nav=qs('#pupilPagination'),summary=qs('#pupilPaginationSummary');if(!nav||!summary)return;const page=Number(pupilPagination.page||1),pages=Number(pupilPagination.total_pages||1),total=Number(pupilPagination.total||0),from=Number(pupilPagination.from||0),to=Number(pupilPagination.to||0);summary.textContent=total?`Showing ${from}-${to} of ${total} pupils`:'Showing 0 pupils';let b=[`<button class="ss-page-btn" data-pupil-page="${page-1}" ${page<=1?'disabled':''}>Previous</button>`];let first=Math.max(1,page-2),last=Math.min(pages,page+2);for(let i=first;i<=last;i++)b.push(`<button class="ss-page-btn ${i===page?'active':''}" data-pupil-page="${i}">${i}</button>`);b.push(`<button class="ss-page-btn" data-pupil-page="${page+1}" ${page>=pages?'disabled':''}>Next</button>`);nav.innerHTML=b.join('');}
    function togglePupilRangeFields(){const show=qs('#pupilPeriodFilter')?.value==='range';if(qs('#pupilDateFrom'))qs('#pupilDateFrom').hidden=!show;if(qs('#pupilDateTo'))qs('#pupilDateTo').hidden=!show;if(!show){if(qs('#pupilDateFrom'))qs('#pupilDateFrom').value='';if(qs('#pupilDateTo'))qs('#pupilDateTo').value='';}}

    /* =========================================================
       BULK ADD — ROW FACTORY
    ========================================================= */
    function classOptions(selected = '') {
        return `<option value="">-- Select Class --</option>` +
            classes.map(c => {
                const id = String(c.id || '');
                return `<option value="${esc(id)}" ${id === String(selected) ? 'selected' : ''}>${esc(c.class_name || '')}</option>`;
            }).join('');
    }

    function createBulkPupilRow() {
        const idx = ++bulkRowCounter;
        const tr  = document.createElement('tr');
        tr.className   = 'bulk-pupil-row';
        tr.dataset.idx = idx;
        tr.innerHTML   = `
            <td class="ss-row-num"></td>
            <td><input type="text"  name="pupils[${idx}][full_name]"       placeholder="Full name" required></td>
            <td><select             name="pupils[${idx}][gender]">
                    <option value="">Select</option><option>Male</option><option>Female</option>
                </select></td>
            <td><input type="date"  name="pupils[${idx}][date_of_birth]"></td>
            <td><select class="bulk-class"  name="pupils[${idx}][class_id]">${classOptions(qs('#bulkDefaultClass')?.value || '')}</select></td>
            <td><input type="text"  name="pupils[${idx}][parent_guardian]"  placeholder="Parent / guardian"></td>
            <td><input type="text"  name="pupils[${idx}][parent_phone]"     placeholder="Phone"></td>
            <td><select             name="pupils[${idx}][baptised]"><option value="0">No</option><option value="1">Yes</option></select></td>
            <td><select class="bulk-status" name="pupils[${idx}][status]"><option>Active</option><option>Inactive</option></select></td>
            <td><input class="bulk-date" type="date" name="pupils[${idx}][date_enrolled]"
                       value="${esc(qs('#bulkDefaultDate')?.value || new Date().toISOString().slice(0, 10))}"></td>
            <td><input type="text"  name="pupils[${idx}][notes]"            placeholder="Notes"></td>
            <td><button type="button" class="btn btn-sm btn-danger ss-remove-row" data-remove-bulk-row>
                    <i class="fas fa-times"></i>
                </button></td>
        `;
        return tr;
    }

    function refreshBulkRows() {
        const rows = qsa('.bulk-pupil-row');
        rows.forEach((row, i) => {
            const num = row.querySelector('.ss-row-num');
            if (num) num.textContent = i + 1;
            const rm  = row.querySelector('[data-remove-bulk-row]');
            if (rm)  rm.style.display = rows.length > 1 ? '' : 'none';
        });
        const cnt = qs('#bulkPupilCount');
        if (cnt) cnt.textContent = rows.length === 1 ? '1 pupil' : rows.length + ' pupils';
    }

    function resetBulkPupils() {
        const body = qs('#bulkPupilRows');
        if (!body) return;
        body.innerHTML = '';
        bulkRowCounter = 0;
        body.appendChild(createBulkPupilRow());
        clearAlert(qs('#bulkPupilAlert'));
        refreshBulkRows();
    }

    function applyBulkDefaults() {
        const cls = qs('#bulkDefaultClass')?.value  || '';
        const dt  = qs('#bulkDefaultDate')?.value   || '';
        const st  = qs('#bulkDefaultStatus')?.value || 'Active';
        qsa('.bulk-class').forEach(el  => el.value = cls);
        qsa('.bulk-date').forEach(el   => el.value = dt);
        qsa('.bulk-status').forEach(el => el.value = st);
        showAlert(qs('#bulkPupilAlert'), 'Defaults applied to all rows.');
    }

    /* =========================================================
       CSV TEMPLATE  (client-side blob download)
    ========================================================= */
    function csvEscape(value) {
        const text = String(value ?? '');
        if (/[",\n\r]/.test(text)) return '"' + text.replace(/"/g, '""') + '"';
        return text;
    }

    function downloadPupilCsvTemplate() {
        const headers = ['full_name','gender','date_of_birth','class_name','class_id',
                         'parent_guardian','parent_phone','baptised','status','date_enrolled','notes'];
        const sample  = ['Jane Nakasujja','Female','2016-05-20','','',
                         'Mary Nakasujja','0700000000','No','Active',
                         new Date().toISOString().slice(0, 10),'Optional note'];
        const csv  = headers.map(csvEscape).join(',') + '\n' + sample.map(csvEscape).join(',') + '\n';
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = 'sunday_school_pupils_template.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    /* =========================================================
       CSV IMPORT INTO BULK TABLE
    ========================================================= */
    function parseCsv(text) {
        const rows = [];
        let row = [], cell = '', quote = false;
        for (let i = 0; i < text.length; i++) {
            const ch = text[i], next = text[i + 1];
            if (quote) {
                if (ch === '"' && next === '"') { cell += '"'; i++; }
                else if (ch === '"') quote = false;
                else cell += ch;
            } else {
                if      (ch === '"')  quote = true;
                else if (ch === ',')  { row.push(cell); cell = ''; }
                else if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
                else if (ch !== '\r') cell += ch;
            }
        }
        if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
        return rows.filter(r => r.some(v => String(v).trim() !== ''));
    }

    function classIdFromCsv(row) {
        const id   = String(row.class_id   || '').trim();
        if (id) return id;
        const name = String(row.class_name || '').trim().toLowerCase();
        if (!name) return qs('#bulkDefaultClass')?.value || '';
        const found = classes.find(c => String(c.class_name || '').trim().toLowerCase() === name);
        return found ? String(found.id) : (qs('#bulkDefaultClass')?.value || '');
    }

    function setBulkRowValues(tr, data) {
        const set = (name, value) => {
            const el = tr.querySelector(`[name$="[${name}]"]`);
            if (el) el.value = value ?? '';
        };
        set('full_name',       data.full_name       || '');
        set('gender',          data.gender          || '');
        set('date_of_birth',   data.date_of_birth   || '');
        set('class_id',        classIdFromCsv(data));
        set('parent_guardian', data.parent_guardian || '');
        set('parent_phone',    data.parent_phone    || '');
        set('baptised',        /^(1|yes|true)$/i.test(String(data.baptised || '')) ? '1' : '0');
        set('status',          data.status || qs('#bulkDefaultStatus')?.value || 'Active');
        set('date_enrolled',   data.date_enrolled || qs('#bulkDefaultDate')?.value || new Date().toISOString().slice(0, 10));
        set('notes',           data.notes || '');
    }

    async function importPupilsCsv(file) {
        if (!file) return;
        if (!/\.csv$/i.test(file.name) && file.type && !file.type.includes('csv')) {
            showAlert(qs('#bulkPupilAlert'), 'Please upload a CSV file.', 'error');
            return;
        }
        const text   = await file.text();
        const parsed = parseCsv(text);
        if (parsed.length < 2) {
            showAlert(qs('#bulkPupilAlert'), 'CSV is empty or missing data rows.', 'error');
            return;
        }
        const headers = parsed[0].map(h => String(h || '').trim().toLowerCase().replace(/\s+/g, '_'));
        if (!headers.includes('full_name')) {
            showAlert(qs('#bulkPupilAlert'), 'CSV must include a full_name column.', 'error');
            return;
        }
        const body = qs('#bulkPupilRows');
        if (!body) return;
        body.innerHTML = '';
        bulkRowCounter = 0;
        let imported = 0;
        parsed.slice(1).forEach(values => {
            const obj = {};
            headers.forEach((h, i) => obj[h] = String(values[i] ?? '').trim());
            if (!obj.full_name) return;
            const tr = createBulkPupilRow();
            body.appendChild(tr);
            setBulkRowValues(tr, obj);
            imported++;
        });
        if (imported < 1) {
            body.appendChild(createBulkPupilRow());
            refreshBulkRows();
            showAlert(qs('#bulkPupilAlert'), 'No valid pupil rows found in the CSV.', 'error');
            return;
        }
        refreshBulkRows();
        showAlert(qs('#bulkPupilAlert'), imported + (imported === 1 ? ' pupil loaded from CSV.' : ' pupils loaded from CSV.'));
    }

    /* =========================================================
       PUPIL / CLASS MODAL HELPERS
    ========================================================= */
    function resetPupil() {
        qs('#pupilForm')?.reset();
        if (qs('#pupilAction')) qs('#pupilAction').value      = 'create_pupil';
        if (qs('#pupilId'))     qs('#pupilId').value          = '';
        if (qs('#pupilTitle'))  qs('#pupilTitle').textContent = 'Add Pupil';
        clearAlert(qs('#pupilAlert'));
    }

    function resetClass() {
        qs('#classForm')?.reset();
        if (qs('#classAction')) qs('#classAction').value      = 'create_class';
        if (qs('#classId'))     qs('#classId').value          = '';
        if (qs('#classTitle'))  qs('#classTitle').textContent = 'Add Class';
        clearAlert(qs('#classAlert'));
    }

    async function openEditPupil(id) {
        resetPupil();
        const d = await request(endpoint + '?action=get_pupil&id=' + encodeURIComponent(id));
        if (!d.success) return showAlert(qs('#ssAlert'), d.message || 'Not found', 'error');
        const p = d.item || {};
        qs('#pupilAction').value       = 'update_pupil';
        qs('#pupilId').value           = p.id || '';
        qs('#pupilTitle').textContent  = 'Edit Pupil';
        ['pupil_no','full_name','gender','date_of_birth','class_id','parent_guardian',
         'parent_phone','parent_member_id','baptised','status','date_enrolled','notes'].forEach(k => {
            const el = qs('#' + k);
            if (el) el.value = p[k] ?? '';
        });
        openModal('#pupilModal');
    }

    async function openViewPupil(id) {
        const d = await request(endpoint + '?action=get_pupil&id=' + encodeURIComponent(id));
        if (!d.success) return showAlert(qs('#ssAlert'), d.message || 'Not found', 'error');
        const p = d.item || {};
        const fields = [
            ['Pupil No',        p.pupil_no],
            ['Full Name',       p.full_name],
            ['Gender',          p.gender],
            ['Date of Birth',   p.date_of_birth],
            ['Age',             p.age],
            ['Class',           p.class_name],
            ['Parent/Guardian', p.parent_guardian],
            ['Parent Phone',    p.parent_phone],
            ['Baptised',        Number(p.baptised || 0) === 1 ? 'Yes' : 'No'],
            ['Status',          p.status],
            ['Date Enrolled',   p.date_enrolled],
            ['Notes',           p.notes],
        ];
        qs('#viewContent').innerHTML = fields
            .map(([k, v]) => `<div class="ss-detail"><b>${esc(k)}</b><span>${esc(v || '—')}</span></div>`)
            .join('');
        openModal('#viewModal');
    }

    async function openEditClass(id) {
        resetClass();
        const d = await request(endpoint + '?action=get_class&id=' + encodeURIComponent(id));
        if (!d.success) return showAlert(qs('#ssAlert'), d.message || 'Not found', 'error');
        const c = d.item || {};
        qs('#classAction').value      = 'update_class';
        qs('#classId').value          = c.id || '';
        qs('#classTitle').textContent = 'Edit Class';
        ['class_name','teacher_id','capacity'].forEach(k => {
            const el = qs('#' + k);
            if (el) el.value = c[k] ?? '';
        });
        // Split the stored range ("3-5 years", "6 to 9", "13+", "4") into the From / To boxes.
        const rangeText = String(c.age_range || '').toLowerCase();
        let m = rangeText.match(/(\d{1,2})\s*(?:-|–|—|to)\s*(\d{1,2})/);
        let from = '', to = '';
        if (m) { from = Math.min(+m[1], +m[2]); to = Math.max(+m[1], +m[2]); }
        else if ((m = rangeText.match(/(\d{1,2})\s*\+/))) { from = +m[1]; to = 99; }
        else if ((m = rangeText.match(/^\D*(\d{1,2})\D*$/))) { from = to = +m[1]; }
        if (qs('#age_from')) qs('#age_from').value = from;
        if (qs('#age_to'))   qs('#age_to').value   = to;
        if (rangeText.trim() && from === '') {
            showAlert(qs('#classAlert'), 'The saved age range "' + c.age_range + '" could not be read. Enter the From and To ages.', 'error');
        }
        if (qs('#class_notes')) qs('#class_notes').value = c.notes || '';
        openModal('#classModal');
    }

    /* =========================================================
       ATTENDANCE
    ========================================================= */
    async function loadAttendance() {
        const classId    = qs('#attendanceClass')?.value || '';
        const lessonDate = qs('#attendanceDate')?.value  || new Date().toISOString().slice(0, 10);
        if (qs('#attClassId'))    qs('#attClassId').value    = classId;
        if (qs('#attLessonDate')) qs('#attLessonDate').value = lessonDate;
        if (!classId) {
            qs('#attendanceList').innerHTML = '<div class="text-muted">Choose a class to load pupils.</div>';
            return;
        }
        const d = await request(
            endpoint + '?action=attendance_pupils'
                     + '&class_id='    + encodeURIComponent(classId)
                     + '&lesson_date=' + encodeURIComponent(lessonDate)
        );
        if (!d.success) throw new Error(d.message || 'Failed to load attendance.');
        const items = d.items || [];
        if (!items.length) {
            qs('#attendanceList').innerHTML = '<div class="text-muted">No active pupils in this class.</div>';
            return;
        }
        qs('#attendanceList').innerHTML = items.map(p => {
            const st = p.attendance_status || 'Present';
            return `
                <div class="ss-att-pupil">
                    <input type="hidden" name="pupil_id[]" value="${Number(p.id)}">
                    <strong>${esc(p.full_name)}</strong>
                    <small class="text-muted">${esc(p.pupil_no || '')}</small>
                    <select name="attendance_status[${Number(p.id)}]" class="att-status">
                        <option ${st === 'Present' ? 'selected' : ''}>Present</option>
                        <option ${st === 'Absent'  ? 'selected' : ''}>Absent</option>
                        <option ${st === 'Late'    ? 'selected' : ''}>Late</option>
                        <option ${st === 'Excused' ? 'selected' : ''}>Excused</option>
                    </select>
                    <input type="text" name="attendance_notes[${Number(p.id)}]"
                           placeholder="Notes" value="${esc(p.attendance_notes || '')}">
                </div>`;
        }).join('');
    }

    /* =========================================================
       REFRESH ALL
    ========================================================= */
    async function refreshAll() {
        await Promise.all([loadStats(), loadClasses(), loadPupils()]);
    }

    /* =========================================================
       EXPORT — state tracker for the filter bar
    ========================================================= */
    let _exportBarTarget = null; // 'csv' | 'pdf'

    function activateExportBar(type) {
        _exportBarTarget = type;
        toggleExportBar(true);
        // Update the bar's inline download button label
        const dlBtn = qs('#btnExportBarDownload');
        if (dlBtn) {
            dlBtn.innerHTML = type === 'pdf'
                ? '<i class="fas fa-file-pdf"></i> Open PDF'
                : '<i class="fas fa-file-csv"></i> Download CSV';
        }
    }

    function executeExport() {
        const url = exportParams(_exportBarTarget === 'pdf' ? 'export_pdf' : 'export_csv');
        if (_exportBarTarget === 'pdf') openTab(url);
        else triggerDownload(url);
    }

    /* =========================================================
       GLOBAL CLICK HANDLER
    ========================================================= */
    document.addEventListener('click', async e => {
        const t = e.target.closest('button, [data-close]');
        if (!t) return;

        // Modals
        if (t.matches('[data-close]')) closeModals();

        // Pupil CRUD
        if (t.id === 'btnAddPupil')      { resetPupil(); openModal('#pupilModal'); }
        if (t.dataset.viewPupil)         openViewPupil(t.dataset.viewPupil);
        if (t.dataset.editPupil)         openEditPupil(t.dataset.editPupil);
        if (t.dataset.deletePupil) {
            qs('#deleteAction').value     = 'delete_pupil';
            qs('#deleteId').value         = t.dataset.deletePupil;
            qs('#deleteName').textContent = t.dataset.name || 'this pupil';
            clearAlert(qs('#deleteAlert'));
            openModal('#deleteModal');
        }

        // Bulk add
        if (t.id === 'btnBulkAddPupils')   { resetBulkPupils(); openModal('#bulkPupilModal'); }
        if (t.id === 'btnAddBulkPupilRow') {
            qs('#bulkPupilRows')?.appendChild(createBulkPupilRow());
            refreshBulkRows();
        }
        if (t.id === 'btnApplyBulkDefaults')          applyBulkDefaults();
        if (t.dataset.removeBulkRow !== undefined)    { t.closest('.bulk-pupil-row')?.remove(); refreshBulkRows(); }

        // CSV template
        if (t.id === 'btnDownloadPupilTemplate' || t.id === 'btnDownloadPupilTemplateModal') {
            downloadPupilCsvTemplate();
        }

        // Class CRUD
        if (t.id === 'btnAddClass')     { resetClass(); openModal('#classModal'); }
        if (t.id === 'btnRefreshClasses') loadClasses().catch(err => showAlert(qs('#ssAlert'), err.message, 'error'));
        if (t.dataset.editClass)          openEditClass(t.dataset.editClass);
        if (t.dataset.deleteClass) {
            qs('#deleteAction').value     = 'delete_class';
            qs('#deleteId').value         = t.dataset.deleteClass;
            qs('#deleteName').textContent = t.dataset.name || 'this class';
            clearAlert(qs('#deleteAlert'));
            openModal('#deleteModal');
        }
        if (t.dataset.viewClassPupils) {
            qs('#classFilter').value = t.dataset.viewClassPupils;
            showTab('pupils');
            loadPupils().catch(err => showAlert(qs('#ssAlert'), err.message, 'error'));
        }

        // Tabs & pupils refresh
        if (t.dataset.tab)          showTab(t.dataset.tab);
        if (t.id === 'btnRefreshPupils') loadPupils().catch(err => showAlert(qs('#ssAlert'), err.message, 'error'));

        // Attendance helpers
        if (t.id === 'btnAllPresent')      qsa('.att-status').forEach(s => s.value = 'Present');
        if (t.id === 'btnClearAttendance') qsa('.att-status').forEach(s => s.value = 'Absent');

        // ── EXPORT: pupils CSV ─────────────────────────────────
        if (t.id === 'btnExportCSV') {
            const barOpen = qs('#ssExportBar')?.style.display !== 'none';
            if (!barOpen) activateExportBar('csv');
            else          executeExport();
        }

        // ── EXPORT: pupils PDF ─────────────────────────────────
        if (t.id === 'btnExportPDF') {
            const barOpen = qs('#ssExportBar')?.style.display !== 'none';
            if (!barOpen) activateExportBar('pdf');
            else          executeExport();
        }

        // ── EXPORT: bar download button ────────────────────────
        if (t.id === 'btnExportBarDownload') executeExport();

        // ── EXPORT: close the filter bar ──────────────────────
        if (t.id === 'btnCloseExportBar') {
            toggleExportBar(false);
            _exportBarTarget = null;
        }

        // ── EXPORT: attendance session PDF ────────────────────
        if (t.id === 'btnExportAttendancePDF') {
            const classId    = qs('#attendanceClass')?.value || '';
            const lessonDate = qs('#attendanceDate')?.value  || '';
            if (!classId) {
                showAlert(qs('#ssAlert'), 'Please select a class before exporting attendance.', 'error');
                return;
            }
            openTab(
                endpoint
                + '?action=export_attendance_pdf'
                + '&class_id='    + encodeURIComponent(classId)
                + '&lesson_date=' + encodeURIComponent(lessonDate)
            );
        }
    });

    /* =========================================================
       PASSIVE LISTENERS
    ========================================================= */
    qs('#ssBackdrop')?.addEventListener('click', closeModals);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

    qs('#pupilSearch')?.addEventListener('input',()=>{clearTimeout(pupilSearchTimer);pupilSearchTimer=setTimeout(()=>loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error')),300);});
    qs('#classFilter')?.addEventListener('change',()=>loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error')));
    qs('#pupilPeriodFilter')?.addEventListener('change',()=>{togglePupilRangeFields();loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error'));});
    qs('#pupilDateFrom')?.addEventListener('change',()=>loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error')));
    qs('#pupilDateTo')?.addEventListener('change',()=>loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error')));
    qs('#pupilPerPage')?.addEventListener('change',()=>loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error')));
    qs('#pupilPagination')?.addEventListener('click',e=>{const b=e.target.closest('[data-pupil-page]');if(!b||b.disabled)return;loadPupils(Number(b.dataset.pupilPage||1)).catch(err=>showAlert(qs('#ssAlert'),err.message,'error'));});
    qs('#btnClearPupilFilters')?.addEventListener('click',()=>{['pupilSearch','classFilter','pupilPeriodFilter','pupilDateFrom','pupilDateTo'].forEach(id=>{const el=qs('#'+id);if(el)el.value='';});togglePupilRangeFields();loadPupils(1).catch(err=>showAlert(qs('#ssAlert'),err.message,'error'));});
    qs('#attendanceClass')?.addEventListener('change', () =>
        loadAttendance().catch(err => showAlert(qs('#ssAlert'), err.message, 'error'))
    );
    qs('#attendanceDate')?.addEventListener('change', () =>
        loadAttendance().catch(err => showAlert(qs('#ssAlert'), err.message, 'error'))
    );
    qs('#pupilCsvUpload')?.addEventListener('change', e =>
        importPupilsCsv(e.target.files?.[0]).catch(err =>
            showAlert(qs('#bulkPupilAlert'), err.message, 'error')
        )
    );

    togglePupilRangeFields();

    /* =========================================================
       FORM SUBMISSIONS
    ========================================================= */
    qs('#pupilForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert(qs('#pupilAlert'));
        try {
            const d = await postForm(e.currentTarget);
            if (!d.success) return showAlert(qs('#pupilAlert'), d.message || 'Save failed', 'error');
            closeModals();
            showAlert(qs('#ssAlert'), d.message || 'Saved');
            await refreshAll();
        } catch (err) {
            showAlert(qs('#pupilAlert'), err.message, 'error');
        }
    });

    qs('#bulkPupilForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert(qs('#bulkPupilAlert'));
        const rows  = qsa('.bulk-pupil-row');
        let   valid = true;
        rows.forEach(row => {
            const nameEl = row.querySelector('[name$="[full_name]"]');
            if (!nameEl || !nameEl.value.trim()) {
                valid = false;
                row.style.outline = '2px solid #dc2626';
            } else {
                row.style.outline = '';
            }
        });
        if (!valid) return showAlert(qs('#bulkPupilAlert'), 'Please enter full name for every pupil row.', 'error');

        const btn = qs('#bulkPupilSubmit');
        const old = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }
        try {
            const d = await postForm(e.currentTarget);
            if (!d.success) return showAlert(qs('#bulkPupilAlert'), d.message || 'Save failed', 'error');
            closeModals();
            showAlert(qs('#ssAlert'), d.message || 'Pupils saved');
            await refreshAll();
            showTab('pupils');
        } catch (err) {
            showAlert(qs('#bulkPupilAlert'), err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = old; }
        }
    });

    qs('#classForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert(qs('#classAlert'));
        try {
            const d = await postForm(e.currentTarget);
            if (!d.success) return showAlert(qs('#classAlert'), d.message || 'Save failed', 'error');
            closeModals();
            showAlert(qs('#ssAlert'), d.message || 'Saved');
            await refreshAll();
        } catch (err) {
            showAlert(qs('#classAlert'), err.message, 'error');
        }
    });

    qs('#deleteForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert(qs('#deleteAlert'));
        try {
            const d = await postForm(e.currentTarget);
            if (!d.success) return showAlert(qs('#deleteAlert'), d.message || 'Delete failed', 'error');
            closeModals();
            showAlert(qs('#ssAlert'), d.message || 'Deleted');
            await refreshAll();
        } catch (err) {
            showAlert(qs('#deleteAlert'), err.message, 'error');
        }
    });

    qs('#attendanceForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        if (qs('#attClassId'))    qs('#attClassId').value    = qs('#attendanceClass')?.value || '';
        if (qs('#attLessonDate')) qs('#attLessonDate').value = qs('#attendanceDate')?.value  || '';
        try {
            const d = await postForm(e.currentTarget);
            if (!d.success) return showAlert(qs('#ssAlert'), d.message || 'Save failed', 'error');
            showAlert(qs('#ssAlert'), d.message || 'Attendance saved');
            await loadStats();
        } catch (err) {
            showAlert(qs('#ssAlert'), err.message, 'error');
        }
    });

    /* =========================================================
       BOOT
    ========================================================= */
    refreshAll().catch(err => showAlert(qs('#ssAlert'), err.message, 'error'));

})();