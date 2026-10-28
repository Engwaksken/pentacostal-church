(function () {
  'use strict';

  const deleteControlSelector = [
    'button[data-action="delete"]', 'button[data-act="delete"]', 'button[data-zone-action="delete"]',
    'button[data-project-action="delete"]', 'button[data-delete-id]',
    'button[class*="delete-"]',
    'a[href*="delete.php"]', 'form input[name="action"][value^="delete"]'
  ].join(',');
  const isVisible = (el) => !!el && el.getAttribute('aria-hidden') !== 'true' &&
    getComputedStyle(el).display !== 'none' && el.getClientRects().length > 0;
  const deleteForm = (form) => {
    const action = form.querySelector('[name="action"]')?.value || '';
    return /^delete/i.test(action) || /delete/i.test(form.action || '');
  };

  function addTableTools(table) {
    if (table.dataset.bulkDeleteReady || !table.tHead || !table.tBodies.length) return;
    const rows = Array.from(table.tBodies).flatMap((body) => Array.from(body.rows));
    if (!rows.some((row) => row.querySelector(deleteControlSelector))) return;
    table.dataset.bulkDeleteReady = '1';

    const headRow = table.tHead.rows[table.tHead.rows.length - 1];
    const headCell = document.createElement('th');
    headCell.className = 'bulk-select-cell';
    headCell.innerHTML = '<input type="checkbox" class="bulk-select-all" aria-label="Select all deletable rows">';
    headRow.insertBefore(headCell, headRow.firstChild);

    const parent = table.closest('.table-responsive') || table;
    const tools = document.createElement('div');
    tools.className = 'bulk-delete-tools';
    tools.innerHTML = '<label><input type="checkbox" class="bulk-select-all" aria-label="Select all deletable rows"> <span>Select all</span></label><span class="bulk-delete-count">0 selected</span><button type="button" class="btn btn-sm btn-danger bulk-delete-button" disabled><i class="fas fa-trash"></i> Delete selected</button>';
    parent.parentNode.insertBefore(tools, parent);
    const count = tools.querySelector('.bulk-delete-count');
    const bulkButton = tools.querySelector('.bulk-delete-button');

    function rowCheckbox(row) {
      let cell = row.querySelector(':scope > .bulk-select-cell');
      if (!cell) {
        cell = document.createElement('td');
        cell.className = 'bulk-select-cell';
        const control = document.createElement('input');
        control.type = 'checkbox';
        control.className = 'bulk-row-select';
        control.setAttribute('aria-label', 'Select row for deletion');
        cell.appendChild(control);
        row.insertBefore(cell, row.firstChild);
      }
      return cell.querySelector('input');
    }

    function syncRows() {
      Array.from(table.tBodies).forEach((body) => Array.from(body.rows).forEach((row) => {
        if (row.querySelector('td[colspan]') || !row.querySelector(deleteControlSelector)) return;
        rowCheckbox(row);
      }));
    }
    function update() {
      syncRows();
      const checks = Array.from(table.querySelectorAll('.bulk-row-select'));
      const selected = checks.filter((check) => check.checked).length;
      count.textContent = selected + (selected === 1 ? ' selected' : ' selected');
      bulkButton.disabled = selected === 0;
      tools.querySelectorAll('.bulk-select-all').forEach((box) => {
        box.checked = checks.length > 0 && selected === checks.length;
        box.indeterminate = selected > 0 && selected < checks.length;
      });
    }

    table.addEventListener('change', (event) => {
      if (event.target.matches('.bulk-row-select')) update();
    });
    tools.addEventListener('change', (event) => {
      if (!event.target.matches('.bulk-select-all')) return;
      syncRows();
      table.querySelectorAll('.bulk-row-select').forEach((box) => { box.checked = event.target.checked; });
      update();
    });
    new MutationObserver(update).observe(table.tBodies[0], { childList: true, subtree: true });
    update();

    bulkButton.addEventListener('click', async () => {
      const selectedRows = Array.from(table.querySelectorAll('tbody tr')).filter((row) => row.querySelector('.bulk-row-select:checked'));
      if (!selectedRows.length || !window.confirm('Delete ' + selectedRows.length + ' selected record(s)? This cannot be undone.')) return;
      bulkButton.disabled = true;
      const original = bulkButton.innerHTML;
      bulkButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
      const nativeConfirm = window.confirm;
      window.confirm = () => true;
      let deleted = 0;
      try {
        for (const row of selectedRows) {
          const control = row.querySelector(deleteControlSelector);
          if (!control) continue;

          // Existing inline POST forms already contain their IDs and CSRF token.
          const inlineForm = control.closest('form');
          if (inlineForm && deleteForm(inlineForm)) {
            await postForm(inlineForm);
            deleted++;
            continue;
          }

          const pending = [];
          const originalFetch = window.fetch;
          window.fetch = function () {
            const request = originalFetch.apply(this, arguments);
            pending.push(request);
            return request;
          };
          try {
            control.click();
            await new Promise((resolve) => setTimeout(resolve, 20));
            const visibleForms = Array.from(document.querySelectorAll('form')).filter((form) => deleteForm(form) && isVisible(form.closest('[role="dialog"],.modal,.app-modal,.church-modal,.contrib-modal,.attendance-modal,.tgv-modal,.bap-modal,.dept-modal,.expense-modal') || form));
            const form = visibleForms[visibleForms.length - 1];
            if (form) {
              await postForm(form);
              deleted++;
            } else if (pending.length) {
              const responses = await Promise.all(pending);
              for (const response of responses) await checkResponse(response);
              deleted++;
            } else {
              throw new Error('Could not find a delete request for one selected row. No more rows were deleted.');
            }
          } finally {
            window.fetch = originalFetch;
          }
        }
        alert(deleted + ' record(s) deleted.');
        window.location.reload();
      } catch (error) {
        alert((deleted ? deleted + ' record(s) deleted. ' : '') + (error.message || 'Bulk delete stopped.'));
        window.location.reload();
      } finally {
        window.confirm = nativeConfirm;
        bulkButton.innerHTML = original;
      }
    });
  }

  async function checkResponse(response) {
    if (!response.ok) throw new Error('Delete request failed (' + response.status + ').');
    const text = await response.clone().text();
    try {
      const data = JSON.parse(text);
      if (data.success === false || data.status === 'error') throw new Error(data.message || 'Delete failed.');
    } catch (error) {
      if (error instanceof SyntaxError) return;
      throw error;
    }
  }

  async function postForm(form) {
    const method = (form.method || 'post').toUpperCase();
    if (method !== 'POST') throw new Error('Bulk deletion only submits secure POST requests.');
    const url = new URL(form.action || window.location.href, window.location.href);
    if (url.origin !== window.location.origin) throw new Error('Refusing to send a delete request to another site.');
    const response = await fetch(url.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    await checkResponse(response);
  }

  function scan(root) {
    if (root.matches?.('table')) addTableTools(root);
    root.querySelectorAll?.('table').forEach(addTableTools);
  }
  document.addEventListener('DOMContentLoaded', () => {
    scan(document);
    new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach((node) => {
      if (node.nodeType === 1) {
        const table = node.closest?.('table');
        if (table) addTableTools(table);
        scan(node);
      }
    }))).observe(document.body, { childList: true, subtree: true });
  });
})();
