/*
 * Form tabs: splits a long form into tabs so it fits on screen.
 *
 * Markup (on any element inside a form, usually the form or its field grid):
 *   data-form-tabs                 turn this container into tabs
 *   data-form-tabs-first="Label"   optional: fields before the first tab start become a tab with this label
 *
 * Tab starts, inside the container:
 *   - section titles (.cb-section-title, .ca-section-label, .ms-section-label, .pr-section, .form-section-title)
 *   - any element with data-tab-start="Label"
 * A tab holds its start element and the sibling elements after it, up to the next start or an element with
 * data-tab-stop. An element anywhere in the container with data-tab="Label" joins the tab with that label
 * instead (useful for fields printed conditionally or outside the grid). Tabs keep the order of their fields.
 * Elements in no tab (hidden inputs, alerts, buttons) always stay visible.
 *
 * Nothing is moved in the page, so grids and page scripts keep working; fields in other tabs are only hidden.
 * When the browser finds an invalid field, its tab opens and is marked; a form reset, or the form being
 * displayed again (a modal reopening), returns to the first tab.
 */
(function () {
    'use strict';

    const TITLE_SELECTOR = '.cb-section-title, .ca-section-label, .ms-section-label, .pr-section, .form-section-title';
    let uid = 0;

    function titleText(el) {
        if (el.dataset.tabStart) return el.dataset.tabStart.trim();
        const copy = el.cloneNode(true);
        copy.querySelectorAll('small, .text-muted').forEach(n => n.remove());
        return copy.textContent.replace(/\s+/g, ' ').trim() || 'Details';
    }

    function build(container) {
        if (container.dataset.formTabsReady) return;

        const starts = Array.from(container.querySelectorAll('[data-tab-start], ' + TITLE_SELECTOR))
            .filter(el => !el.closest('[data-form-tabs-skip]'));
        if (!starts.length) return;

        const groups = new Map(); // label -> Set of elements
        const add = (label, el) => {
            if (!groups.has(label)) groups.set(label, new Set());
            groups.get(label).add(el);
        };
        const isStart = el => starts.includes(el);
        const firstLabel = (container.dataset.formTabsFirst || '').trim();

        // Fields before the first start, in the same parent, form the optional first tab.
        if (firstLabel) {
            add(firstLabel, null);
            let el = starts[0].parentElement.firstElementChild;
            while (el && el !== starts[0]) {
                if (!el.matches('input[type="hidden"], [data-tab-stop], [data-tab]')) add(firstLabel, el);
                el = el.nextElementSibling;
            }
        }

        starts.forEach(start => {
            const label = titleText(start);
            add(label, start);
            let el = start.nextElementSibling;
            while (el && !isStart(el) && !el.hasAttribute('data-tab-stop')) {
                if (!el.hasAttribute('data-tab')) add(label, el);
                el = el.nextElementSibling;
            }
        });

        container.querySelectorAll('[data-tab]').forEach(el => add(el.dataset.tab.trim(), el));
        groups.forEach(set => set.delete(null));

        // Tabs follow the order their first fields appear in the form.
        const firstOf = label => Array.from(groups.get(label)).sort((a, b) =>
            a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1)[0];
        const labels = Array.from(groups.keys()).filter(label => groups.get(label).size);
        labels.sort((a, b) => firstOf(a).compareDocumentPosition(firstOf(b)) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1);
        if (labels.length < 2) return;

        container.dataset.formTabsReady = '1';
        // A section title repeats its tab's name, so it is hidden; a field marked data-tab-start stays.
        starts.forEach(start => { if (start.matches(TITLE_SELECTOR)) start.classList.add('form-tabs-title'); });
        const id = 'formtabs' + (++uid);
        const bar = document.createElement('div');
        bar.className = 'form-tabs-bar';
        bar.setAttribute('role', 'tablist');

        const tabs = labels.map((label, index) => {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'form-tabs-tab';
            tab.id = id + '-tab' + index;
            tab.setAttribute('role', 'tab');
            tab.innerHTML = '<span class="form-tabs-num">' + (index + 1) + '</span><span class="form-tabs-label"></span>';
            tab.querySelector('.form-tabs-label').textContent = label;
            tab.addEventListener('click', () => show(index, true));
            bar.appendChild(tab);
            return tab;
        });

        // The bar goes before the first tab's first element; in a grid it spans every column.
        const firstEl = firstOf(labels[0]);
        firstEl.parentElement.insertBefore(bar, firstEl);

        let current = 0;
        function show(index, focus) {
            current = index;
            labels.forEach((label, i) => {
                const active = i === index;
                groups.get(label).forEach(el => el.classList.toggle('form-tabs-hidden', !active));
                tabs[i].classList.toggle('active', active);
                tabs[i].setAttribute('aria-selected', active ? 'true' : 'false');
                tabs[i].tabIndex = active ? 0 : -1;
            });
            if (focus) tabs[index].focus();
            // Keep the active tab in view when the bar scrolls sideways on small screens, without scrolling the page.
            const tab = tabs[index];
            if (tab.offsetLeft < bar.scrollLeft) bar.scrollLeft = tab.offsetLeft;
            else if (tab.offsetLeft + tab.offsetWidth > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = tab.offsetLeft + tab.offsetWidth - bar.clientWidth;
        }

        bar.addEventListener('keydown', event => {
            const keys = { ArrowRight: 1, ArrowLeft: -1 };
            if (event.key in keys) {
                event.preventDefault();
                show((current + keys[event.key] + tabs.length) % tabs.length, true);
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                show(event.key === 'Home' ? 0 : tabs.length - 1, true);
            }
        });

        function tabIndexOf(field) {
            return labels.findIndex(label => Array.from(groups.get(label)).some(el => el === field || el.contains(field)));
        }

        const form = container.closest('form') || container.querySelector('form') || container;

        // A required field in a hidden tab cannot be focused, so open its tab first and mark it.
        form.addEventListener('invalid', event => {
            const index = tabIndexOf(event.target);
            if (index < 0) return;
            tabs[index].classList.add('has-error');
            if (!form.dataset.formTabsJumped) {
                form.dataset.formTabsJumped = '1';
                show(index, false);
                setTimeout(() => { delete form.dataset.formTabsJumped; }, 0);
            }
        }, true);

        form.addEventListener('input', event => {
            const index = tabIndexOf(event.target);
            if (index >= 0 && event.target.validity && event.target.validity.valid) {
                const anyInvalid = Array.from(groups.get(labels[index]))
                    .some(el => (el.matches && el.matches(':invalid')) || (el.querySelector && el.querySelector(':invalid')));
                if (!anyInvalid) tabs[index].classList.remove('has-error');
            }
        });

        form.addEventListener('reset', () => {
            tabs.forEach(tab => tab.classList.remove('has-error'));
            show(0, false);
        });

        // A modal form reopening (the bar going from not displayed to displayed) starts again on the first tab.
        // Scrolling does not change the bar's size, so it never triggers this.
        if ('ResizeObserver' in window) {
            let displayed = bar.offsetWidth > 0;
            new ResizeObserver(() => {
                const nowDisplayed = bar.offsetWidth > 0;
                if (nowDisplayed && !displayed) {
                    tabs.forEach(tab => tab.classList.remove('has-error'));
                    show(0, false);
                }
                displayed = nowDisplayed;
            }).observe(bar);
        }

        show(0, false);
    }

    function init(root) {
        (root || document).querySelectorAll('[data-form-tabs]').forEach(build);
    }

    window.FormTabs = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();
})();
