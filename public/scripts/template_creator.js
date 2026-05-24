console.log("Template Creator Script Loaded!");

let currentTargetLocation = 'left';

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

function moveField(btn, direction) {
    const item = btn.closest('.field-item');
    if (!item) return;

    if (direction === 'up' && item.previousElementSibling) {
        item.parentElement.insertBefore(item, item.previousElementSibling);
    }

    if (direction === 'down' && item.nextElementSibling) {
        item.parentElement.insertBefore(item.nextElementSibling, item);
    }

    updateAllFieldsLocations();
}

function moveFieldSide(btn) {
    const item = btn.closest('.field-item');
    const currentContainer = item?.closest('.fields-container');
    if (!item || !currentContainer) return;

    const targetId = currentContainer.id === 'left-fields' ? 'right-fields' : 'left-fields';
    document.getElementById(targetId)?.appendChild(item);
    updateAllFieldsLocations();
}

// -------------------------------------------------------
// Modal
// -------------------------------------------------------
function openFieldModal(location) {
    currentTargetLocation = location;
    const modal = document.getElementById('type-modal');
    if (!modal) return;
    modal.style.display = 'flex';
}

function closeModal() {
    const modal = document.getElementById('type-modal');
    if (modal) modal.style.display = 'none';
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
        <input type="text" class="table-row-name-input" placeholder="Nazwa wiersza..."
            value="${name.replace(/"/g,'&quot;')}" style="${INPUT_STYLE}">
        <button type="button" onclick="removeTableRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);" title="Usuń">
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

// -------------------------------------------------------
// SELECT
// -------------------------------------------------------
function buildSelectOptionHtml(val = '') {
    return `<div class="select-option-def" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="text" class="select-option-input" placeholder="Opcja..."
            value="${val.replace(/"/g,'&quot;')}" style="${INPUT_STYLE}">
        <button type="button" onclick="removeSelectOption(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);" title="Usuń">
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
        <input type="text" class="month-name-input" placeholder="Nazwa miesiąca..."
            value="${name.replace(/"/g,'&quot;')}" style="flex:1;${INPUT_STYLE}">
        <input type="number" class="month-days-input" min="1" max="99" value="${days}"
            style="width:64px;${INPUT_STYLE}" title="Liczba dni">
        <span style="font-size:0.75rem;color:var(--text-muted,#999);">dni</span>
        <button type="button" onclick="removeMonthRow(this)" style="${ICON_BTN}color:var(--danger,#e74c3c);">
            <i class="fa-solid fa-minus"></i></button></div>`;
}

function buildEraRowHtml(val='') {
    return `<div class="era-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <input type="text" class="era-input" placeholder="Nazwa ery (np. n.e.)..."
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
    const months = [...fi.querySelectorAll('.month-row')].map(row => ({
        name: row.querySelector('.month-name-input')?.value.trim() || '',
        days: parseInt(row.querySelector('.month-days-input')?.value) || 30
    }));
    const eras = [...fi.querySelectorAll('.era-input')].map(i=>i.value.trim()).filter(Boolean);
    const defaultYear = fi.querySelector('.default-year-input')?.value.trim() || '';
    const ph = fi.querySelector('.field-placeholder');
    if (ph) ph.value = JSON.stringify({type:'date', months, eras, defaultYear});
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
        if (!editor) return;
        editor.style.display = 'block';
        let cfg = {};
        try { cfg = JSON.parse(fi.querySelector('.field-placeholder')?.value||'{}'); } catch(e){}
        const months = Array.isArray(cfg.months) ? cfg.months : [];
        const eras   = Array.isArray(cfg.eras)   ? cfg.eras   : [];
        const defYear = cfg.defaultYear || '';

        const mc = editor.querySelector('.months-container');
        const mWrap = mc.querySelector('.add-month-btn-wrap');
        months.forEach((m,i) => { const d=document.createElement('div'); d.innerHTML=buildMonthRowHtml(m.name,m.days,i); mc.insertBefore(d.firstElementChild,mWrap); });

        const ec = editor.querySelector('.eras-container');
        const eWrap = ec.querySelector('.add-era-btn-wrap');
        eras.forEach(e => { const d=document.createElement('div'); d.innerHTML=buildEraRowHtml(e); ec.insertBefore(d.firstElementChild,eWrap); });

        const dyInput = editor.querySelector('.default-year-input');
        if (dyInput) dyInput.value = defYear;
        bindDateListeners(fi);
    });
}

// -------------------------------------------------------
// Metadane typów
// -------------------------------------------------------
const TYPE_META = {
    text:            { icon:'fa-font',          label:'Typ: Tekst',          color:'' },
    textarea:        { icon:'fa-align-left',     label:'Typ: Długi tekst',    color:'var(--text-muted,#888)' },
    list:            { icon:'fa-list',           label:'Typ: Lista',          color:'var(--primary,#3498db)' },
    image:           { icon:'fa-image',          label:'Typ: Zdjęcie',        color:'var(--success,#27ae60)' },
    'image-gallery': { icon:'fa-images',         label:'Typ: Galeria',        color:'var(--success,#27ae60)' },
    table:           { icon:'fa-table',          label:'Typ: Tabela',         color:'var(--secondary,#8e44ad)' },
    date:            { icon:'fa-calendar-days',  label:'Typ: Data',           color:'var(--warning,#e67e22)' },
    select:          { icon:'fa-chevron-down',   label:'Typ: Wybór z listy',  color:'var(--info,#2980b9)' },
};

// -------------------------------------------------------
// Tworzenie pola
// -------------------------------------------------------
const DEFAULT_MONTHS = [
    {name:'Styczeń',days:31},{name:'Luty',days:28},{name:'Marzec',days:31},
    {name:'Kwiecień',days:30},{name:'Maj',days:31},{name:'Czerwiec',days:30},
    {name:'Lipiec',days:31},{name:'Sierpień',days:31},{name:'Wrzesień',days:30},
    {name:'Październik',days:31},{name:'Listopad',days:30},{name:'Grudzień',days:31}
];

function createField(type) {
    const container = document.getElementById(currentTargetLocation + '-fields');
    const tmpl = document.getElementById('field-template');
    if (!container || !tmpl) return;

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
            <span style="${LABEL_SM}"><i class="fa-solid fa-table"></i> Wiersze tabeli:</span>
            <div class="table-rows-container">
                <div class="add-row-btn-wrap"><button type="button" onclick="addTableRow(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> Dodaj wiersz</button></div>
            </div></div>`);
    } else if (type === 'select') {
        fc.insertAdjacentHTML('beforeend', `<div class="select-options-editor" style="${EDITOR_WRAP}">
            <span style="${LABEL_SM}"><i class="fa-solid fa-chevron-down"></i> Opcje do wyboru:</span>
            <div class="select-options-container">
                <div class="add-opt-btn-wrap"><button type="button" onclick="addSelectOption(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> Dodaj opcję</button></div>
            </div></div>`);
    } else if (type === 'date') {
        fc.insertAdjacentHTML('beforeend', `<div class="date-editor" style="${EDITOR_WRAP}">
            <span style="${LABEL_SM}"><i class="fa-solid fa-calendar"></i> Miesiące (kolejność = kolejność wyboru):</span>
            <div class="months-container">
                <div class="add-month-btn-wrap"><button type="button" onclick="addMonthRow(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> Dodaj miesiąc</button></div>
            </div>
            <span style="${LABEL_SM}margin-top:10px;"><i class="fa-solid fa-hourglass"></i> Ery (opcjonalnie, np. "p.n.e.", "n.e."):</span>
            <div class="eras-container">
                <div class="add-era-btn-wrap"><button type="button" onclick="addEraRow(this)" style="${DASHED_BTN}">
                    <i class="fa-solid fa-plus"></i> Dodaj erę</button></div>
            </div>
            <span style="${LABEL_SM}margin-top:10px;"><i class="fa-solid fa-star"></i> Domyślny rok:</span>
            <input type="text" class="default-year-input" placeholder="np. 1200" style="${INPUT_STYLE}display:block;">
        </div>`);
    }

    container.appendChild(clone);
    const newItem = container.lastElementChild;

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
        const mc = newItem.querySelector('.months-container');
        const mWrap = mc.querySelector('.add-month-btn-wrap');
        DEFAULT_MONTHS.forEach((m,i) => { const d=document.createElement('div'); d.innerHTML=buildMonthRowHtml(m.name,m.days,i); mc.insertBefore(d.firstElementChild,mWrap); });
        bindDateListeners(newItem);
        updateDatePlaceholder(newItem);
    }

    updateAllFieldsLocations();
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
    });
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
        'opt-date':          'date',
        'opt-select':        'select',
    };
    Object.entries(btnMap).forEach(([id, type]) => {
        document.getElementById(id)?.addEventListener('click', e => { e.preventDefault(); createField(type); });
    });

    const modal = document.getElementById('type-modal');
    window.addEventListener('click', e => { if (e.target === modal) closeModal(); });

    document.getElementById('template-form')?.addEventListener('submit', syncAllPlaceholders);

    initExistingTableFields();
    initExistingSelectFields();
    initExistingDateFields();

    // Drag & drop
    document.querySelectorAll('.fields-container').forEach(container => {
        container.addEventListener('dragstart', e => { e.target.closest('.field-item')?.classList.add('dragging'); });
        container.addEventListener('dragend', e => {
            const d = e.target.closest('.field-item');
            if (d) { d.classList.remove('dragging'); updateAllFieldsLocations(); }
        });
        container.addEventListener('dragover', e => {
            e.preventDefault();
            const dragging = document.querySelector('.dragging');
            if (!dragging) return;
            const after = getDragAfterElement(container, e.clientY);
            after == null ? container.appendChild(dragging) : container.insertBefore(dragging, after);
        });
    });
});
