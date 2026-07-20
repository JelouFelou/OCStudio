console.log("Template Creator Script Loaded!");

let currentTargetLocation = 'left';
let currentInsertIndex = null;

const templateI18n = () => window.OCI18n?.templateEditor || {};
const templateText = (key, fallback, replacements = {}) => {
    let value = templateI18n()[key] || fallback || key;
    Object.entries(replacements).forEach(([name, replacement]) => {
        value = value.replaceAll(`:${name}`, String(replacement));
    });
    return value;
};

const FIELD_TYPE_OPTIONS = [
    ['text', 'fa-font', templateText('text', 'Tekst')],
    ['textarea', 'fa-align-left', templateText('textarea', 'Dlugi tekst')],
    ['list', 'fa-list-ul', templateText('list', 'Lista')],
    ['select', 'fa-chevron-down', templateText('select', 'Wybor z listy')],
    ['image', 'fa-image', templateText('image', 'Zdjecie')],
    ['image-gallery', 'fa-images', templateText('imageGallery', 'Galeria')],
    ['table', 'fa-table', templateText('table', 'Tabela')],
    ['stats', 'fa-chart-simple', templateText('stats', 'Statystyki')],
    ['date', 'fa-calendar-days', templateText('date', 'Data')],
];

// -------------------------------------------------------
// Helpers – lokalizacja i drag & drop
// -------------------------------------------------------
function updateAllFieldsLocations() {
    document.querySelectorAll('#left-fields .field-location').forEach(i => i.value = 'left');
    document.querySelectorAll('#right-fields .field-location').forEach(i => i.value = 'right');
}

function getDragAfterElement(container, y) {
    const els = [...container.querySelectorAll('.field-item:not(.dragging)')];
    return els.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function templateFieldItems(container) {
    return container ? [...container.querySelectorAll(':scope > .field-item')] : [];
}

function moveField(btn, direction) {
    const item = btn.closest('.field-item');
    if (!item) return;

    const container = item.parentElement;
    container.querySelectorAll('.template-field-insert-point').forEach(point => point.remove());
    const items = templateFieldItems(container);
    const index = items.indexOf(item);

    if (direction === 'up' && index > 0) {
        container.insertBefore(item, items[index - 1]);
    }

    if (direction === 'down' && index >= 0 && index < items.length - 1) {
        container.insertBefore(items[index + 1], item);
    }

    updateAllFieldsLocations();
    refreshTemplateInsertPoints(container);
}

function moveFieldSide(btn) {
    const item = btn.closest('.field-item');
    const currentContainer = item?.closest('.fields-container');
    if (!item || !currentContainer) return;

    const targetId = currentContainer.id === 'left-fields' ? 'right-fields' : 'left-fields';
    currentContainer.querySelectorAll('.template-field-insert-point').forEach(point => point.remove());
    document.getElementById(targetId)?.appendChild(item);
    updateAllFieldsLocations();
    refreshTemplateInsertPoints(currentContainer);
    refreshTemplateInsertPoints(document.getElementById(targetId));
}

// -------------------------------------------------------
// Modal
// -------------------------------------------------------
function openFieldModal(location, anchor = null, insertIndex = null) {
    currentTargetLocation = location;
    currentInsertIndex = Number.isInteger(insertIndex) && insertIndex >= 0 ? insertIndex : null;
    openTemplateFieldTypePopover(anchor, location, currentInsertIndex);
}

function closeModal() {
    const modal = document.getElementById('type-modal');
    if (modal) modal.style.display = 'none';
    const popover = document.getElementById('template-field-type-popover');
    if (popover) popover.hidden = true;
}

function createTemplateFieldTypePopover() {
    let popover = document.getElementById('template-field-type-popover');
    if (popover) return popover;

    popover = document.createElement('div');
    popover.id = 'template-field-type-popover';
    popover.className = 'template-field-type-popover';
    popover.hidden = true;
    popover.innerHTML = `
        <div class="template-field-type-popover-grid">
            ${FIELD_TYPE_OPTIONS.map(([type, icon, label]) => `
                <button type="button" class="template-field-type-option" data-template-field-type="${type}">
                    <i class="fa-solid ${icon}"></i>
                    <span>${label}</span>
                </button>
            `).join('')}
        </div>`;
    document.body.appendChild(popover);

    popover.addEventListener('click', event => {
        const option = event.target.closest('[data-template-field-type]');
        if (!option) return;
        createField(option.dataset.templateFieldType);
    });

    document.addEventListener('click', event => {
        if (popover.hidden) return;
        if (event.target.closest('#template-field-type-popover, .template-field-insert-btn')) return;
        closeModal();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeModal();
    });

    return popover;
}

function openTemplateFieldTypePopover(anchor = null, location = currentTargetLocation, insertIndex = null) {
    const popover = createTemplateFieldTypePopover();
    const container = document.getElementById(`${location}-fields`);
    const items = templateFieldItems(container);
    currentTargetLocation = location;
    currentInsertIndex = Number.isInteger(insertIndex) && insertIndex >= 0 ? insertIndex : items.length;
    popover.hidden = false;

    const anchorRect = anchor?.getBoundingClientRect?.();
    const popoverRect = popover.getBoundingClientRect();
    const top = anchorRect ? anchorRect.bottom + window.scrollY + 8 : window.scrollY + 120;
    const preferredLeft = anchorRect
        ? anchorRect.left + window.scrollX + (anchorRect.width / 2) - (popoverRect.width / 2)
        : window.scrollX + 24;
    const left = Math.max(12 + window.scrollX, Math.min(preferredLeft, window.scrollX + window.innerWidth - popoverRect.width - 12));
    popover.style.top = `${top}px`;
    popover.style.left = `${left}px`;
}

function createTemplateInsertPoint(location, index) {
    const point = document.createElement('div');
    point.className = 'template-field-insert-point';
    point.innerHTML = `
        <button type="button" class="template-field-insert-btn" data-template-location="${location}" data-template-insert-index="${index}" title="${escapeHtml(templateText('addFieldHere', 'Dodaj pole'))}">
            <i class="fa-solid fa-plus"></i>
        </button>`;
    return point;
}

function refreshTemplateInsertPoints(container) {
    if (!container) return;
    container.querySelectorAll('.template-field-insert-point').forEach(point => point.remove());
    const location = container.id === 'right-fields' ? 'right' : 'left';
    const items = templateFieldItems(container);
    if (!items.length) {
        container.appendChild(createTemplateInsertPoint(location, 0));
        return;
    }
    items.forEach((item, index) => {
        container.insertBefore(createTemplateInsertPoint(location, index), item);
    });
    container.appendChild(createTemplateInsertPoint(location, items.length));
}

function removeTemplateField(btn) {
    const item = btn.closest('.field-item');
    const container = item?.parentElement;
    item?.remove();
    updateAllFieldsLocations();
    refreshTemplateInsertPoints(container);
}

// -------------------------------------------------------
// Style stałe
// -------------------------------------------------------
const INPUT_STYLE = 'flex:1;padding:6px 10px;border-radius:6px;border:1px solid var(--border,#ccc);background:var(--input-bg,#fff);color:var(--text,#333);font-size:0.9rem;';
const DASHED_BTN  = 'background:none;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);border-radius:6px;padding:4px 12px;cursor:pointer;font-size:0.85rem;width:100%;margin-top:4px;';
const ICON_BTN    = 'background:none;border:none;cursor:pointer;padding:4px 6px;font-size:1rem;line-height:1;';
const LABEL_SM    = 'font-size:0.8rem;color:var(--text-muted,#888);margin-bottom:6px;display:block;';
const EDITOR_WRAP = 'margin-top:10px;padding:10px;background:var(--surface-alt,#f5f5f5);border-radius:8px;border:1px solid var(--border,#ddd);';

// -------------------------------------------------------
// TABELA
// -------------------------------------------------------
function buildTableRowHtml(name = '') {
    return `<div class="table-row-definition" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="text" class="table-row-name-input" placeholder="${escapeHtml(templateText('rowName', 'Nazwa wiersza...'))}"
            value="${name.replace(/"/g,'&quot;')}" style="${INPUT_STYLE}">
        <button type="button" onclick="removeTableRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);" title="${escapeHtml(window.OCI18n?.common?.delete || 'Usun')}">
            <i class="fa-solid fa-minus"></i></button></div>`;
}

function addTableRow(btn) {
    const rc = btn.closest('.table-rows-container');
    const d = document.createElement('div');
    d.innerHTML = buildTableRowHtml('');
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    updateTablePlaceholder(btn.closest('.field-item'));
}

function removeTableRow(btn) {
    const fi = btn.closest('.field-item');
    btn.closest('.table-row-definition').remove();
    updateTablePlaceholder(fi);
}

function updateTablePlaceholder(fi) {
    const rows = [...fi.querySelectorAll('.table-row-name-input')].map(i=>i.value.trim()).filter(Boolean);
    const ph = fi.querySelector('.field-placeholder');
    if (ph) ph.value = JSON.stringify({type:'table', rows});
}

function bindTableRowListeners(fi) {
    fi.addEventListener('input', e => {
        if (e.target.classList.contains('table-row-name-input')) updateTablePlaceholder(fi);
    });
}

function initExistingTableFields() {
    document.querySelectorAll('.field-item').forEach(fi => {
        if (fi.querySelector('.field-type')?.value !== 'table') return;
        const editor = fi.querySelector('.table-rows-editor');
        if (!editor) return;
        editor.style.display = 'block';
        let cfg = {};
        try { cfg = JSON.parse(fi.querySelector('.field-placeholder')?.value||'{}'); } catch(e){}
        const rows = Array.isArray(cfg.rows) ? cfg.rows : (Array.isArray(cfg) ? cfg : []);
        const rc = editor.querySelector('.table-rows-container');
        const wrap = rc.querySelector('.add-row-btn-wrap');
        rows.forEach(r => { const d=document.createElement('div'); d.innerHTML=buildTableRowHtml(r); rc.insertBefore(d.firstElementChild,wrap); });
        bindTableRowListeners(fi);
    });
}

function normalizeTableRowConfig(row) {
    if (typeof row === 'string') return { key: row, label: row, type: 'text', ageFrom: '', defaultValue: '', options: [] };
    return {
        key: String(row?.key || row?.label || row?.name || crypto.randomUUID?.() || Date.now()),
        label: String(row?.label || row?.name || ''),
        type: ['text', 'date', 'image', 'list', 'age', 'select'].includes(row?.type) ? row.type : 'text',
        ageFrom: String(row?.ageFrom || ''),
        defaultValue: String(row?.defaultValue || ''),
        options: Array.isArray(row?.options) ? row.options.map(option => String(option || '').trim()).filter(Boolean) : []
    };
}

function splitTableSelectOptions(value) {
    return String(value || '')
        .split(/[\n,;]+/)
        .map(option => option.trim())
        .filter(Boolean);
}

function buildTableRowHtml(row = '') {
    const cfg = normalizeTableRowConfig(row);
    const esc = value => String(value || '').replace(/"/g, '&quot;');
    return `<div class="table-row-definition" style="display:grid;grid-template-columns:minmax(130px,1fr) 110px minmax(130px,0.9fr) minmax(120px,0.9fr) minmax(150px,1fr) auto;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="hidden" class="table-row-key-input" value="${esc(cfg.key)}">
        <input type="text" class="table-row-name-input" placeholder="${escapeHtml(templateText('rowName', 'Nazwa wiersza...'))}"
            value="${esc(cfg.label)}" style="${INPUT_STYLE}">
        <select class="table-row-type-input" style="${INPUT_STYLE}">
            <option value="text" ${cfg.type === 'text' ? 'selected' : ''}>${escapeHtml(templateText('text', 'Tekst'))}</option>
            <option value="date" ${cfg.type === 'date' ? 'selected' : ''}>${escapeHtml(templateText('date', 'Data'))}</option>
            <option value="image" ${cfg.type === 'image' ? 'selected' : ''}>${escapeHtml(templateText('image', 'Zdjecie'))}</option>
            <option value="list" ${cfg.type === 'list' ? 'selected' : ''}>${escapeHtml(templateText('list', 'Lista'))}</option>
            <option value="select" ${cfg.type === 'select' ? 'selected' : ''}>${escapeHtml(templateText('choice', 'Wybor'))}</option>
            <option value="age" ${cfg.type === 'age' ? 'selected' : ''}>${escapeHtml(templateText('ageFromDate', 'Wiek z daty'))}</option>
        </select>
        <input type="text" class="table-row-age-from-input" placeholder="${escapeHtml(templateText('dateRowName', 'Nazwa wiersza daty'))}"
            value="${esc(cfg.ageFrom)}" style="${INPUT_STYLE}${cfg.type === 'age' ? '' : 'display:none;'}">
        <input type="text" class="table-row-default-input" placeholder="${escapeHtml(templateText('defaultValue', 'Domyslna wartosc'))}"
            value="${esc(cfg.defaultValue)}" style="${INPUT_STYLE}${cfg.type === 'text' ? '' : 'display:none;'}">
        <input type="text" class="table-row-options-input" placeholder="${escapeHtml(templateText('optionsComma', 'Opcje po przecinku'))}"
            value="${esc((cfg.options || []).join(', '))}" style="${INPUT_STYLE}${cfg.type === 'select' ? '' : 'display:none;'}">
        <div class="row-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:8px;">
            <button type="button" onclick="moveTableRow(this, -1)" style="${ICON_BTN}color:var(--text-muted,#888);" title="${escapeHtml(templateText('moveUp', 'Przesun w gore'))}">
                <i class="fa-solid fa-arrow-up"></i></button>
            <button type="button" onclick="moveTableRow(this, 1)" style="${ICON_BTN}color:var(--text-muted,#888);" title="${escapeHtml(templateText('moveDown', 'Przesun w dol'))}">
                <i class="fa-solid fa-arrow-down"></i></button>
            <button type="button" onclick="removeTableRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);" title="${escapeHtml(window.OCI18n?.common?.delete || 'Usun')}">
                <i class="fa-solid fa-minus"></i></button>
        </div></div>`;
}

function moveTableRow(btn, direction) {
    const row = btn.closest('.table-row-definition');
    const container = btn.closest('.table-rows-container');
    if (!row || !container) return;
    if (direction < 0 && row.previousElementSibling?.classList.contains('table-row-definition')) {
        container.insertBefore(row, row.previousElementSibling);
    } else if (direction > 0 && row.nextElementSibling?.classList.contains('table-row-definition')) {
        container.insertBefore(row.nextElementSibling, row);
    }
    updateTablePlaceholder(btn.closest('.field-item'));
}

function updateTablePlaceholder(fi) {
    const rows = [...fi.querySelectorAll('.table-row-definition')].map(row => {
        const key = row.querySelector('.table-row-key-input')?.value || crypto.randomUUID?.() || Date.now().toString();
        const label = row.querySelector('.table-row-name-input')?.value.trim() || '';
        const type = row.querySelector('.table-row-type-input')?.value || 'text';
        const ageFrom = row.querySelector('.table-row-age-from-input')?.value.trim() || '';
        const defaultValue = type === 'text' ? (row.querySelector('.table-row-default-input')?.value.trim() || '') : '';
        const options = type === 'select' ? splitTableSelectOptions(row.querySelector('.table-row-options-input')?.value || '') : [];
        return { key, label, type, ageFrom, defaultValue, options };
    }).filter(row => row.label);
    const ph = fi.querySelector('.field-placeholder');
    if (ph) ph.value = JSON.stringify({ type: 'table', rows });
    updateGlobalDateSettings();
}

function bindTableRowListeners(fi) {
    fi.addEventListener('input', e => {
        if (e.target.classList.contains('table-row-name-input') || e.target.classList.contains('table-row-age-from-input') || e.target.classList.contains('table-row-default-input') || e.target.classList.contains('table-row-options-input')) {
            updateTablePlaceholder(fi);
        }
    });
    fi.addEventListener('change', e => {
        if (!e.target.classList.contains('table-row-type-input')) return;
        const row = e.target.closest('.table-row-definition');
        const ageFrom = row?.querySelector('.table-row-age-from-input');
        const defaultInput = row?.querySelector('.table-row-default-input');
        const optionsInput = row?.querySelector('.table-row-options-input');
        if (ageFrom) ageFrom.style.display = e.target.value === 'age' ? '' : 'none';
        if (defaultInput) defaultInput.style.display = e.target.value === 'text' ? '' : 'none';
        if (optionsInput) optionsInput.style.display = e.target.value === 'select' ? '' : 'none';
        updateTablePlaceholder(fi);
    });
}

// -------------------------------------------------------
// SELECT
// -------------------------------------------------------
function buildSelectOptionHtml(val = '') {
    return `<div class="select-option-def" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="text" class="select-option-input" placeholder="Opcja..."
            value="${val.replace(/"/g,'&quot;')}" style="${INPUT_STYLE}">
        <button type="button" onclick="removeSelectOption(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);" title="${escapeHtml(window.OCI18n?.common?.delete || 'Usun')}">
            <i class="fa-solid fa-minus"></i></button></div>`;
}

function addSelectOption(btn) {
    const rc = btn.closest('.select-options-container');
    const d = document.createElement('div');
    d.innerHTML = buildSelectOptionHtml('');
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    updateSelectPlaceholder(btn.closest('.field-item'));
}

function removeSelectOption(btn) {
    const fi = btn.closest('.field-item');
    btn.closest('.select-option-def').remove();
    updateSelectPlaceholder(fi);
}

function updateSelectPlaceholder(fi) {
    const opts = [...fi.querySelectorAll('.select-option-input')].map(i=>i.value.trim()).filter(Boolean);
    const ph = fi.querySelector('.field-placeholder');
    if (ph) ph.value = JSON.stringify({type:'select', options:opts});
}

function bindSelectListeners(fi) {
    fi.addEventListener('input', e => {
        if (e.target.classList.contains('select-option-input')) updateSelectPlaceholder(fi);
    });
}

function initExistingSelectFields() {
    document.querySelectorAll('.field-item').forEach(fi => {
        if (fi.querySelector('.field-type')?.value !== 'select') return;
        const editor = fi.querySelector('.select-options-editor');
        if (!editor) return;
        editor.style.display = 'block';
        let cfg = {};
        try { cfg = JSON.parse(fi.querySelector('.field-placeholder')?.value||'{}'); } catch(e){}
        const opts = Array.isArray(cfg.options) ? cfg.options : [];
        const rc = editor.querySelector('.select-options-container');
        const wrap = rc.querySelector('.add-opt-btn-wrap');
        opts.forEach(o => { const d=document.createElement('div'); d.innerHTML=buildSelectOptionHtml(o); rc.insertBefore(d.firstElementChild,wrap); });
        bindSelectListeners(fi);
    });
}

// -------------------------------------------------------
// DATA
// -------------------------------------------------------
function buildMonthRowHtml(name='', days=30, idx=0) {
    return `<div class="month-row" style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
        <span class="month-num" style="font-size:0.75rem;color:var(--text-muted,#999);min-width:18px;">${idx+1}.</span>
        <input type="text" class="month-name-input" placeholder="${escapeHtml(templateText('monthName', 'Nazwa miesiaca...'))}"
            value="${name.replace(/"/g,'&quot;')}" style="flex:1;${INPUT_STYLE}">
        <input type="number" class="month-days-input" min="1" max="99" value="${days}"
            style="width:64px;${INPUT_STYLE}" title="Liczba dni">
        <span style="font-size:0.75rem;color:var(--text-muted,#999);">dni</span>
        <button type="button" onclick="removeMonthRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);">
            <i class="fa-solid fa-minus"></i></button></div>`;
}

function buildEraRowHtml(val='') {
    return `<div class="era-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="text" class="era-input" placeholder="${escapeHtml(templateText('eraName', 'Nazwa ery (np. n.e.)...'))}"
            value="${val.replace(/"/g,'&quot;')}" style="${INPUT_STYLE}">
        <button type="button" onclick="removeEraRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);">
            <i class="fa-solid fa-minus"></i></button></div>`;
}

function addMonthRow(btn) {
    const rc = btn.closest('.months-container');
    const idx = rc.querySelectorAll('.month-row').length;
    const d = document.createElement('div');
    d.innerHTML = buildMonthRowHtml('', 30, idx);
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    renumberMonths(rc);
    updateDatePlaceholder(btn.closest('.field-item'));
}

function removeMonthRow(btn) {
    const fi = btn.closest('.field-item');
    const rc = btn.closest('.months-container');
    btn.closest('.month-row').remove();
    renumberMonths(rc);
    updateDatePlaceholder(fi);
}

function renumberMonths(rc) {
    rc.querySelectorAll('.month-row').forEach((row,i) => {
        const s = row.querySelector('.month-num');
        if (s) s.textContent = (i+1)+'.';
    });
}

function addEraRow(btn) {
    const rc = btn.closest('.eras-container');
    const d = document.createElement('div');
    d.innerHTML = buildEraRowHtml('');
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    updateDatePlaceholder(btn.closest('.field-item'));
}

function removeEraRow(btn) {
    const fi = btn.closest('.field-item');
    btn.closest('.era-row').remove();
    updateDatePlaceholder(fi);
}

function updateDatePlaceholder(fi) {
    const ph = fi?.querySelector('.field-placeholder');
    if (ph) ph.value = '';
}

function updateGlobalDateSettings() {
    const hidden = document.getElementById('template-date-settings');
    if (!hidden) return;
    const months = [...document.querySelectorAll('#global-months-container .month-row')].map(row => ({
        name: row.querySelector('.month-name-input')?.value.trim() || '',
        days: parseInt(row.querySelector('.month-days-input')?.value, 10) || 30
    })).filter(month => month.name);
    const eras = [...document.querySelectorAll('#global-eras-container .era-input')]
        .map(input => input.value.trim())
        .filter(Boolean);
    const defaultYear = document.getElementById('global-default-year')?.value.trim() || String(new Date().getFullYear());
    const currentDateMode = document.getElementById('template-current-date-mode')?.value || 'fixed';
    const currentDateAnchor = document.getElementById('template-current-date-anchor')?.value.trim() || '';
    hidden.value = JSON.stringify({ type: 'date', months, eras, defaultYear, currentDateMode, currentDateAnchor });
}

function addGlobalMonthRow(btn) {
    const rc = document.getElementById('global-months-container');
    const idx = rc.querySelectorAll('.month-row').length;
    const d = document.createElement('div');
    d.innerHTML = buildMonthRowHtml('', 30, idx);
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    renumberMonths(rc);
    updateGlobalDateSettings();
}

function addGlobalEraRow(btn) {
    const rc = document.getElementById('global-eras-container');
    const d = document.createElement('div');
    d.innerHTML = buildEraRowHtml('');
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    updateGlobalDateSettings();
}

function initGlobalDateSettings() {
    const hidden = document.getElementById('template-date-settings');
    if (!hidden) return;
    let cfg = {};
    try { cfg = JSON.parse(hidden.value || '{}'); } catch(e){}
    const months = Array.isArray(cfg.months) && cfg.months.length ? cfg.months : DEFAULT_MONTHS;
    const eras = Array.isArray(cfg.eras) ? cfg.eras : [];
    const mc = document.getElementById('global-months-container');
    const ec = document.getElementById('global-eras-container');
    const mWrap = mc?.querySelector('.add-month-btn-wrap');
    const eWrap = ec?.querySelector('.add-era-btn-wrap');
    months.forEach((month, index) => {
        const d = document.createElement('div');
        d.innerHTML = buildMonthRowHtml(month.name, month.days, index);
        mc.insertBefore(d.firstElementChild, mWrap);
    });
    eras.forEach(era => {
        const d = document.createElement('div');
        d.innerHTML = buildEraRowHtml(era);
        ec.insertBefore(d.firstElementChild, eWrap);
    });
    const defaultYearInput = document.getElementById('global-default-year');
    if (defaultYearInput) defaultYearInput.value = cfg.defaultYear || String(new Date().getFullYear());
    const currentDateModeInput = document.getElementById('template-current-date-mode');
    if (currentDateModeInput) currentDateModeInput.value = cfg.currentDateMode || 'fixed';
    const currentDateAnchorInput = document.getElementById('template-current-date-anchor');
    if (currentDateAnchorInput) currentDateAnchorInput.value = cfg.currentDateAnchor || '';
    document.querySelector('.template-date-settings-panel')?.addEventListener('input', updateGlobalDateSettings);
    document.querySelector('.template-date-settings-panel')?.addEventListener('change', updateGlobalDateSettings);
    updateGlobalDateSettings();
}

function bindDateListeners(fi) {
    fi.addEventListener('input', e => {
        const cl = e.target.classList;
        if (cl.contains('month-name-input')||cl.contains('month-days-input')||
            cl.contains('era-input')||cl.contains('default-year-input')) updateDatePlaceholder(fi);
    });
}

function initExistingDateFields() {
    document.querySelectorAll('.field-item').forEach(fi => {
        if (fi.querySelector('.field-type')?.value !== 'date') return;
        const editor = fi.querySelector('.date-editor');
        editor?.remove();
        updateDatePlaceholder(fi);
    });
}

// -------------------------------------------------------
// ZDJECIE
// -------------------------------------------------------
function updateImagePlaceholder(fi) {
    const size = fi.querySelector('.image-size-input')?.value || 'medium';
    const ph = fi.querySelector('.field-placeholder');
    if (ph) ph.value = JSON.stringify({type:'image', size});
}

function bindImageListeners(fi) {
    fi.addEventListener('change', e => {
        if (e.target.classList.contains('image-size-input')) updateImagePlaceholder(fi);
    });
}

function initExistingImageFields() {
    document.querySelectorAll('.field-item').forEach(fi => {
        if (fi.querySelector('.field-type')?.value !== 'image') return;
        const editor = fi.querySelector('.image-size-editor');
        if (!editor) return;
        editor.style.display = 'none';
        let cfg = {};
        try { cfg = JSON.parse(fi.querySelector('.field-placeholder')?.value||'{}'); } catch(e){}
        const size = ['small', 'medium', 'large', 'full'].includes(cfg.size) ? cfg.size : 'medium';
        const input = editor.querySelector('.image-size-input');
        if (input) input.value = size;
        bindImageListeners(fi);
        updateImagePlaceholder(fi);
    });
}

function normalizeStatsRowConfig(row) {
    if (typeof row === 'string') return { key: row, label: row, defaultValue: '' };
    return {
        key: String(row?.key || row?.label || row?.name || crypto.randomUUID?.() || Date.now()),
        label: String(row?.label || row?.name || ''),
        defaultValue: String(row?.defaultValue ?? '')
    };
}

function buildStatsRowHtml(row = '') {
    const cfg = normalizeStatsRowConfig(row);
    const esc = value => String(value || '').replace(/"/g, '&quot;');
    return `<div class="stats-row-definition" style="display:grid;grid-template-columns:minmax(130px,1fr) minmax(90px,0.45fr) auto;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="hidden" class="stats-row-key-input" value="${esc(cfg.key)}">
        <input type="text" class="stats-row-name-input" placeholder="${escapeHtml(templateText('statName', 'Nazwa statystyki...'))}"
            value="${esc(cfg.label)}" style="${INPUT_STYLE}">
        <input type="number" min="0" step="1" class="stats-row-default-input" placeholder="${escapeHtml(templateText('defaultValue', 'Domyslna'))}"
            value="${esc(cfg.defaultValue)}" style="${INPUT_STYLE}">
        <div class="row-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:8px;">
            <button type="button" onclick="moveStatsRow(this, -1)" style="${ICON_BTN}color:var(--text-muted,#888);" title="${escapeHtml(templateText('moveUp', 'Przesun w gore'))}"><i class="fa-solid fa-arrow-up"></i></button>
            <button type="button" onclick="moveStatsRow(this, 1)" style="${ICON_BTN}color:var(--text-muted,#888);" title="${escapeHtml(templateText('moveDown', 'Przesun w dol'))}"><i class="fa-solid fa-arrow-down"></i></button>
            <button type="button" onclick="removeStatsRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);" title="${escapeHtml(window.OCI18n?.common?.delete || 'Usun')}"><i class="fa-solid fa-minus"></i></button>
        </div>
    </div>`;
}

function ensureStatsEditor(fi) {
    const fc = fi.querySelector('.field-content');
    let editor = fi.querySelector('.stats-editor');
    if (!fc || editor) return editor;
    fc.insertAdjacentHTML('beforeend', `<div class="stats-editor" style="${EDITOR_WRAP}">
        <span style="${LABEL_SM}"><i class="fa-solid fa-chart-simple"></i> ${escapeHtml(templateText('pointsPool', 'Pula punktow:'))}</span>
        <input type="number" min="0" step="1" class="stats-max-input" value="33" style="${INPUT_STYLE}width:140px;display:block;margin-bottom:10px;">
        <span style="${LABEL_SM}"><i class="fa-solid fa-list"></i> ${escapeHtml(templateText('stats', 'Statystyki'))}:</span>
        <div class="stats-rows-container">
            <div class="add-stat-btn-wrap"><button type="button" onclick="addStatsRow(this)" style="${DASHED_BTN}">
                <i class="fa-solid fa-plus"></i> ${escapeHtml(templateText('addStat', 'Dodaj statystyke'))}</button></div>
        </div>
    </div>`);
    return fi.querySelector('.stats-editor');
}

function addStatsRow(btn) {
    const rc = btn.closest('.stats-rows-container');
    const d = document.createElement('div');
    d.innerHTML = buildStatsRowHtml('');
    rc.insertBefore(d.firstElementChild, btn.parentElement);
    updateStatsPlaceholder(btn.closest('.field-item'));
}

function removeStatsRow(btn) {
    const fi = btn.closest('.field-item');
    btn.closest('.stats-row-definition')?.remove();
    updateStatsPlaceholder(fi);
}

function moveStatsRow(btn, direction) {
    const row = btn.closest('.stats-row-definition');
    const container = btn.closest('.stats-rows-container');
    if (!row || !container) return;
    if (direction < 0 && row.previousElementSibling?.classList.contains('stats-row-definition')) {
        container.insertBefore(row, row.previousElementSibling);
    } else if (direction > 0 && row.nextElementSibling?.classList.contains('stats-row-definition')) {
        container.insertBefore(row.nextElementSibling, row);
    }
    updateStatsPlaceholder(btn.closest('.field-item'));
}

function updateStatsPlaceholder(fi) {
    const maxPoints = Math.max(0, parseInt(fi.querySelector('.stats-max-input')?.value, 10) || 0);
    const rows = [...fi.querySelectorAll('.stats-row-definition')].map(row => {
        const key = row.querySelector('.stats-row-key-input')?.value || crypto.randomUUID?.() || Date.now().toString();
        const label = row.querySelector('.stats-row-name-input')?.value.trim() || '';
        const defaultValue = row.querySelector('.stats-row-default-input')?.value.trim() || '';
        return { key, label, defaultValue };
    }).filter(row => row.label);
    const ph = fi.querySelector('.field-placeholder');
    if (ph) ph.value = JSON.stringify({ type: 'stats', maxPoints, rows });
}

function bindStatsListeners(fi) {
    fi.addEventListener('input', e => {
        if (e.target.classList.contains('stats-row-name-input') || e.target.classList.contains('stats-row-default-input') || e.target.classList.contains('stats-max-input')) {
            updateStatsPlaceholder(fi);
        }
    });
}

function initExistingStatsFields() {
    document.querySelectorAll('.field-item').forEach(fi => {
        if (fi.querySelector('.field-type')?.value !== 'stats') return;
        const editor = ensureStatsEditor(fi);
        if (!editor) return;
        editor.style.display = 'block';
        let cfg = {};
        try { cfg = JSON.parse(fi.querySelector('.field-placeholder')?.value || '{}'); } catch(e){}
        const maxInput = editor.querySelector('.stats-max-input');
        if (maxInput) maxInput.value = Number.isFinite(parseInt(cfg.maxPoints, 10)) ? parseInt(cfg.maxPoints, 10) : 33;
        const rows = Array.isArray(cfg.rows) ? cfg.rows : [];
        const rc = editor.querySelector('.stats-rows-container');
        const wrap = rc.querySelector('.add-stat-btn-wrap');
        rows.forEach(row => {
            const d = document.createElement('div');
            d.innerHTML = buildStatsRowHtml(row);
            rc.insertBefore(d.firstElementChild, wrap);
        });
        bindStatsListeners(fi);
        updateStatsPlaceholder(fi);
    });
}

// -------------------------------------------------------
// Metadane typów
// -------------------------------------------------------
const TYPE_META = {
    text:            { icon:'fa-font',          label: templateText('typePrefix', 'Typ: :type', { type: templateText('text', 'Tekst') }),          color:'' },
    textarea:        { icon:'fa-align-left',     label: templateText('typePrefix', 'Typ: :type', { type: templateText('textarea', 'Dlugi tekst') }),    color:'var(--text-muted,#888)' },
    list:            { icon:'fa-list',           label: templateText('typePrefix', 'Typ: :type', { type: templateText('list', 'Lista') }),          color:'var(--primary,#3498db)' },
    image:           { icon:'fa-image',          label: templateText('typePrefix', 'Typ: :type', { type: templateText('image', 'Zdjecie') }),        color:'var(--success,#27ae60)' },
    'image-gallery': { icon:'fa-images',         label: templateText('typePrefix', 'Typ: :type', { type: templateText('imageGallery', 'Galeria') }),        color:'var(--success,#27ae60)' },
    table:           { icon:'fa-table',          label: templateText('typePrefix', 'Typ: :type', { type: templateText('table', 'Tabela') }),         color:'var(--secondary,#8e44ad)' },
    stats:           { icon:'fa-chart-simple',   label: templateText('typePrefix', 'Typ: :type', { type: templateText('stats', 'Statystyki') }),     color:'var(--secondary,#8e44ad)' },
    date:            { icon:'fa-calendar-days',  label: templateText('typePrefix', 'Typ: :type', { type: templateText('date', 'Data') }),           color:'var(--warning,#e67e22)' },
    select:          { icon:'fa-chevron-down',   label: templateText('typePrefix', 'Typ: :type', { type: templateText('select', 'Wybor z listy') }),  color:'var(--info,#2980b9)' },
};

// -------------------------------------------------------
// Tworzenie pola
// -------------------------------------------------------
const DEFAULT_MONTHS = [
    {name:'Styczen',days:31},{name:'Luty',days:28},{name:'Marzec',days:31},
    {name:'Kwiecien',days:30},{name:'Maj',days:31},{name:'Czerwiec',days:30},
    {name:'Lipiec',days:31},{name:'Sierpien',days:31},{name:'Wrzesien',days:30},
    {name:'Pazdziernik',days:31},{name:'Listopad',days:30},{name:'Grudzien',days:31}
];

function createField(type) {
    const container = document.getElementById(currentTargetLocation + '-fields');
    const tmpl = document.getElementById('field-template');
    if (!container || !tmpl) return;
    container.querySelectorAll('.template-field-insert-point').forEach(point => point.remove());
    const targetIndex = Number.isInteger(currentInsertIndex) && currentInsertIndex >= 0
        ? currentInsertIndex
        : templateFieldItems(container).length;

    const clone = tmpl.content.cloneNode(true);
    const fi = clone.querySelector('.field-item');

    fi.querySelector('.field-location').value = currentTargetLocation;
    fi.querySelector('.field-type').value = type;

    const meta = TYPE_META[type] || TYPE_META.text;
    const tag = fi.querySelector('.type-preview-tag small');
    tag.innerHTML = `<i class="fa-solid ${meta.icon}"></i> ${meta.label}`;
    if (meta.color) tag.parentElement.style.color = meta.color;

    // Edytory konfiguracyjne dla złożonych typów
    const fc = fi.querySelector('.field-content');
    if (type === 'table') {
        fc.insertAdjacentHTML('beforeend', `<div class="table-rows-editor" style="${EDITOR_WRAP}">
            <span style="${LABEL_SM}"><i class="fa-solid fa-table"></i> ${escapeHtml(templateText('tableRows', 'Wiersze tabeli:'))}</span>
            <div class="table-rows-container">
                <div class="add-row-btn-wrap"><button type="button" onclick="addTableRow(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> ${escapeHtml(templateText('addRow', 'Dodaj wiersz'))}</button></div>
            </div></div>`);
    } else if (type === 'select') {
        fc.insertAdjacentHTML('beforeend', `<div class="select-options-editor" style="${EDITOR_WRAP}">
            <span style="${LABEL_SM}"><i class="fa-solid fa-chevron-down"></i> ${escapeHtml(templateText('selectOptions', 'Opcje do wyboru:'))}</span>
            <div class="select-options-container">
                <div class="add-opt-btn-wrap"><button type="button" onclick="addSelectOption(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> ${escapeHtml(templateText('addOption', 'Dodaj opcje'))}</button></div>
            </div></div>`);
    } else if (type === 'date') {
        fc.insertAdjacentHTML('beforeend', `<div class="date-editor" style="${EDITOR_WRAP}">
            <span style="${LABEL_SM}"><i class="fa-solid fa-calendar"></i> ${escapeHtml(templateText('months', 'Miesiace'))}:</span>
            <div class="months-container">
                <div class="add-month-btn-wrap"><button type="button" onclick="addMonthRow(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> ${escapeHtml(templateText('addMonth', 'Dodaj miesiac'))}</button></div>
            </div>
            <span style="${LABEL_SM}margin-top:10px;"><i class="fa-solid fa-hourglass"></i> ${escapeHtml(templateText('erasOptional', 'Ery (opcjonalnie):'))}</span>
            <div class="eras-container">
                <div class="add-era-btn-wrap"><button type="button" onclick="addEraRow(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> ${escapeHtml(templateText('addEra', 'Dodaj ere'))}</button></div>
            </div>
            <span style="${LABEL_SM}margin-top:10px;"><i class="fa-solid fa-star"></i> ${escapeHtml(templateText('defaultYear', 'Domyslny rok'))}:</span>
            <input type="text" class="default-year-input" placeholder="np. 1200" style="${INPUT_STYLE}display:block;">
        </div>`);
    } else if (type === 'image') {
        fc.insertAdjacentHTML('beforeend', `<div class="image-size-editor" style="${EDITOR_WRAP}">
            <span style="${LABEL_SM}"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> ${escapeHtml(templateText('imageSizePreview', 'Rozmiar zdjecia w podgladzie:'))}</span>
            <select class="image-size-input" style="${INPUT_STYLE}width:100%;">
                <option value="small">${escapeHtml(templateText('sizeSmall', 'Male'))}</option>
                <option value="medium" selected>${escapeHtml(templateText('sizeMedium', 'Srednie'))}</option>
                <option value="large">${escapeHtml(templateText('sizeLarge', 'Duze'))}</option>
                <option value="full">${escapeHtml(templateText('sizeFull', 'Pelna szerokosc'))}</option>
            </select>
        </div>`);
    }

    const before = templateFieldItems(container)[Math.min(targetIndex, templateFieldItems(container).length)] || null;
    before ? container.insertBefore(clone, before) : container.appendChild(clone);
    const newItem = before ? before.previousElementSibling : container.lastElementChild;

    // Seed danych dla nowych pól
    if (type === 'table') {
        const rc = newItem.querySelector('.table-rows-container');
        const wrap = rc.querySelector('.add-row-btn-wrap');
        const d = document.createElement('div'); d.innerHTML = buildTableRowHtml(''); rc.insertBefore(d.firstElementChild, wrap);
        bindTableRowListeners(newItem);
    } else if (type === 'select') {
        const rc = newItem.querySelector('.select-options-container');
        const wrap = rc.querySelector('.add-opt-btn-wrap');
        const d = document.createElement('div'); d.innerHTML = buildSelectOptionHtml(''); rc.insertBefore(d.firstElementChild, wrap);
        bindSelectListeners(newItem);
    } else if (type === 'date') {
        newItem.querySelector('.date-editor')?.remove();
        newItem.querySelector('.field-placeholder').value = '';
        updateDatePlaceholder(newItem);
    } else if (type === 'date-legacy-unused') {
        const mc = newItem.querySelector('.months-container');
        const mWrap = mc.querySelector('.add-month-btn-wrap');
        DEFAULT_MONTHS.forEach((m,i) => { const d=document.createElement('div'); d.innerHTML=buildMonthRowHtml(m.name,m.days,i); mc.insertBefore(d.firstElementChild,mWrap); });
        bindDateListeners(newItem);
        updateDatePlaceholder(newItem);
    } else if (type === 'image') {
        bindImageListeners(newItem);
        updateImagePlaceholder(newItem);
    } else if (type === 'stats') {
        const editor = ensureStatsEditor(newItem);
        const rc = editor.querySelector('.stats-rows-container');
        const wrap = rc.querySelector('.add-stat-btn-wrap');
        ['Strength', 'Stamina', 'Agility'].forEach(name => {
            const d = document.createElement('div');
            d.innerHTML = buildStatsRowHtml(name);
            rc.insertBefore(d.firstElementChild, wrap);
        });
        bindStatsListeners(newItem);
        updateStatsPlaceholder(newItem);
    }

    updateAllFieldsLocations();
    refreshTemplateInsertPoints(container);
    updateGlobalDateSettings();
    closeModal();
}

// -------------------------------------------------------
// Sync przed zapisem
// -------------------------------------------------------
function syncAllPlaceholders() {
    document.querySelectorAll('.field-item').forEach(fi => {
        const type = fi.querySelector('.field-type')?.value;
        if (type === 'table')  updateTablePlaceholder(fi);
        if (type === 'select') updateSelectPlaceholder(fi);
        if (type === 'date')   updateDatePlaceholder(fi);
        if (type === 'image')  updateImagePlaceholder(fi);
        if (type === 'stats')  updateStatsPlaceholder(fi);
    });
    updateGlobalDateSettings();
}

// -------------------------------------------------------
// DOMContentLoaded
// -------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {

    const btnMap = {
        'opt-text':          'text',
        'opt-textarea':      'textarea',
        'opt-list':          'list',
        'opt-image':         'image',
        'opt-image-gallery': 'image-gallery',
        'opt-table':         'table',
        'opt-stats':         'stats',
        'opt-date':          'date',
        'opt-select':        'select',
    };
    Object.entries(btnMap).forEach(([id, type]) => {
        document.getElementById(id)?.addEventListener('click', e => { e.preventDefault(); createField(type); });
    });

    const modal = document.getElementById('type-modal');
    window.addEventListener('click', e => { if (e.target === modal) closeModal(); });

    document.getElementById('template-form')?.addEventListener('submit', syncAllPlaceholders);
    document.getElementById('template-form')?.addEventListener('input', e => {
        if (e.target.classList.contains('field-label')) {
            updateGlobalDateSettings();
        }
    });
    document.getElementById('template-form')?.addEventListener('change', e => {
        if (e.target.classList.contains('field-type') || e.target.classList.contains('table-row-type-input')) {
            updateGlobalDateSettings();
        }
    });

    initExistingTableFields();
    initExistingSelectFields();
    initExistingDateFields();
    initGlobalDateSettings();
    initExistingImageFields();
    initExistingStatsFields();

    // Drag & drop
    document.querySelectorAll('.fields-container').forEach(container => {
        container.addEventListener('dragstart', e => { e.target.closest('.field-item')?.classList.add('dragging'); });
        container.addEventListener('dragend', e => {
            const d = e.target.closest('.field-item');
            if (d) { d.classList.remove('dragging'); updateAllFieldsLocations(); refreshTemplateInsertPoints(container); }
        });
        container.addEventListener('dragover', e => {
            e.preventDefault();
            const dragging = document.querySelector('.dragging');
            if (!dragging) return;
            container.querySelectorAll('.template-field-insert-point').forEach(point => point.remove());
            const after = getDragAfterElement(container, e.clientY);
            after == null ? container.appendChild(dragging) : container.insertBefore(dragging, after);
        });
        container.addEventListener('click', e => {
            const button = e.target.closest('.template-field-insert-btn');
            if (!button) return;
            openFieldModal(
                button.dataset.templateLocation || (container.id === 'right-fields' ? 'right' : 'left'),
                button,
                Number(button.dataset.templateInsertIndex || 0)
            );
        });
        refreshTemplateInsertPoints(container);
    });
});
