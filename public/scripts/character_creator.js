console.log("Character Creator Script Loaded!");

const OC_IMAGE_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.png,.gif,.webp,.avif';

// -------------------------------------------------------
const ccI18n = () => window.OCI18n?.characterEditor || {};
const ccText = (key, fallback, replacements = {}) => {
    let value = ccI18n()[key] || fallback || key;
    Object.entries(replacements).forEach(([name, replacement]) => {
        value = value.replaceAll(`:${name}`, String(replacement));
    });
    return value;
};

// Ładowanie pól szablonu
// -------------------------------------------------------
async function loadTemplateFields(templateId) {
    if (!templateId) return;
    window.currentCharacterTemplateId = templateId;
    if (document.body) document.body.dataset.characterFieldsLoading = '1';

    try {
        const response = await fetch(`/getTemplateData?id=${templateId}`);
        const template = await response.json();

        if (template.error) {
            console.error(template.error);
            if (document.body) delete document.body.dataset.characterFieldsLoading;
            return;
        }
        window.currentTemplateDateSettings = template.dateSettings || {};

        const leftContainer  = document.getElementById('left-fields-container');
        const rightContainer = document.getElementById('right-fields-container');
        if (!leftContainer || !rightContainer) {
            if (document.body) delete document.body.dataset.characterFieldsLoading;
            return;
        }

        if (!template.fields || template.fields.length === 0) {
            leftContainer.innerHTML = `<p style="color:var(--text-muted);">${escapeHtml(ccText('templateEmpty', 'Ten szablon postaci nie zawiera zadnych pol.'))}</p>`;
            rightContainer.innerHTML = '';
            if (document.body) delete document.body.dataset.characterFieldsLoading;
            return;
        }

        const leftFragment = document.createDocumentFragment();
        const rightFragment = document.createDocumentFragment();
        let renderedFields = 0;

        template.fields.forEach(field => {
            try {
                const wrapper = buildFieldWidget(field);
                if (!wrapper) return;
                if (field.location === 'right') rightFragment.appendChild(wrapper);
                else                            leftFragment.appendChild(wrapper);
                renderedFields++;
            } catch (fieldError) {
                console.error('Nie udało się wyrenderować pola szablonu:', field, fieldError);
            }
        });
        if (renderedFields === 0) {
            if (document.body) delete document.body.dataset.characterFieldsLoading;
            return;
        }
        leftContainer.replaceChildren(leftFragment);
        rightContainer.replaceChildren(rightFragment);
        initCharacterFieldKeyboardNavigation();
        if (document.body) delete document.body.dataset.characterFieldsLoading;

    } catch (err) {
        console.error('Błąd pobierania danych szablonu postaci:', err);
        if (document.body) delete document.body.dataset.characterFieldsLoading;
    }
}

// -------------------------------------------------------
// Budowanie widżetu pola dla kreatora postaci
// -------------------------------------------------------
function normalizeTableRowsConfig(cfg) {
    const rows = Array.isArray(cfg?.rows) ? cfg.rows : (Array.isArray(cfg) ? cfg : []);
    return rows.map(row => {
        if (typeof row === 'string') return { key: row, label: row, type: 'text', ageFrom: '', defaultValue: '', options: [] };
        return {
            key: String(row?.key || row?.label || row?.name || ''),
            label: String(row?.label || row?.name || ''),
            type: ['text', 'date', 'image', 'list', 'age', 'select'].includes(row?.type) ? row.type : 'text',
            ageFrom: String(row?.ageFrom || ''),
            defaultValue: String(row?.defaultValue || ''),
            options: normalizeTableSelectOptions(row?.options)
        };
    }).filter(row => row.label);
}

function normalizeTableSelectOptions(options) {
    if (Array.isArray(options)) {
        return options.map(option => String(option || '').trim()).filter(Boolean);
    }
    return String(options || '')
        .split(/[\n,;]+/)
        .map(option => option.trim())
        .filter(Boolean);
}

function tableCellValue(cell) {
    return cell && typeof cell === 'object' && Object.prototype.hasOwnProperty.call(cell, 'value') ? cell.value : cell;
}

function tableSavedCell(rows, rowDef) {
    return rows[rowDef.key] ?? rows[rowDef.label];
}

function imageSizePercentFromConfig(rawConfig) {
    let cfg = {};
    try { cfg = typeof rawConfig === 'string' ? JSON.parse(rawConfig || '{}') : (rawConfig || {}); } catch(e){}
    const map = { small: '25%', medium: '50%', large: '75%', full: '100%' };
    return map[cfg.size] || map.medium;
}

function splitDeferredTags(value) {
    return window.OCImageTools?.splitTags
        ? window.OCImageTools.splitTags(value)
        : String(value || '').split(',').map(tag => tag.trim()).filter(Boolean);
}

function askDeferredImageTags(tagsInput) {
    let tags = tagsInput?.value || window.OCImageTools?.getLastUploadTags?.() || '';
    while (splitDeferredTags(tags).length < 5) {
        tags = prompt(ccText('imageTagsPrompt', 'Enter at least 5 image filters separated by commas:'), tags || ccText('imageTagsDefault', 'sfw, character, image, gallery, description')) || '';
        if (!tags) return null;
    }
    const normalized = window.OCImageTools?.setLastUploadTags?.(tags) || splitDeferredTags(tags).join(', ');
    if (tagsInput) tagsInput.value = normalized;
    return normalized;
}

function previewLocalImage(file, preview) {
    if (!file || !preview) return;
    preview.src = URL.createObjectURL(file);
    preview.hidden = false;
    preview.style.display = 'block';
}

async function chooseTemplateImageAsset(defaultTab = 'gallery') {
    const asset = await window.OCImageTools?.openImagePicker({ allowUpload: true, defaultTab });
    return asset ? {
        imageId: asset.id ?? asset.imageId ?? null,
        url: asset.url || '',
        filename: asset.filename || String(asset.url || '').split('/').pop(),
    } : null;
}

function createUnifiedImageButton(label = ccText('chooseImage', 'Wybierz zdjecie')) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'upload-label btn-secondary';
    button.style.cssText = 'border:none;flex:1;min-width:0;justify-content:center;text-align:center;line-height:1.15;';
    button.textContent = label;
    return button;
}

function currentCharacterVariantKey() {
    return String(window.characterActiveVariantKey || 'default');
}

function isEditingCharacterVariant() {
    return currentCharacterVariantKey() !== 'default';
}

function characterFieldValueMap() {
    return (window.characterFieldValues && typeof window.characterFieldValues === 'object')
        ? window.characterFieldValues
        : {};
}

function characterBaseFieldValueMap() {
    return (window.baseCharacterFieldValues && typeof window.baseCharacterFieldValues === 'object')
        ? window.baseCharacterFieldValues
        : {};
}

function characterFieldInputNames(fieldId) {
    const variantKey = currentCharacterVariantKey();
    if (variantKey === 'default') {
        return {
            value: `field_values[${fieldId}]`,
            imageFile: `field_image_files[${fieldId}]`,
            imageTags: `field_image_tags[${fieldId}]`,
            galleryFiles: `field_gallery_files[${fieldId}][]`,
            galleryTags: `field_gallery_tags[${fieldId}]`,
        };
    }

    return {
        value: `variants[${variantKey}][values][${fieldId}]`,
        imageFile: `variant_field_image_files[${variantKey}][${fieldId}]`,
        imageTags: `variant_field_image_tags[${variantKey}][${fieldId}]`,
        galleryFiles: `variant_field_gallery_files[${variantKey}][${fieldId}][]`,
        galleryTags: `variant_field_gallery_tags[${variantKey}][${fieldId}]`,
    };
}

function characterBaseFieldPlaceholder(fieldId) {
    return readableVariantBaseValue(characterBaseFieldValueMap()[fieldId] ?? '');
}

function notifyFieldValueChanged(control) {
    if (!control) return;
    control.dispatchEvent(new Event('input', { bubbles: true }));
    control.dispatchEvent(new Event('change', { bubbles: true }));
}

function variantKeyFromInputName(inputName) {
    const match = String(inputName || '').match(/^variants\[([^\]]+)\]/);
    return match ? match[1] : '';
}

function tableFindSourceCell(rows, rowDefs, sourceName) {
    const source = rowDefs.find(row => row.key === sourceName || row.label === sourceName);
    return source ? tableSavedCell(rows, source) : rows[sourceName];
}

function calculateAgeFromDate(dateValue) {
    if (!dateValue || typeof dateValue !== 'object') return '';
    const year = parseInt(dateValue.year, 10);
    if (!Number.isFinite(year)) return '';
    const today = new Date();
    const monthIndex = parseInt(dateValue.monthIndex, 10) || 0;
    const day = parseInt(dateValue.day, 10) || 1;
    let age = today.getFullYear() - year;
    if (today.getMonth() < monthIndex || (today.getMonth() === monthIndex && today.getDate() < day)) age -= 1;
    return age >= 0 ? String(age) : '';
}

function parseVariantJsonObject(value) {
    try {
        const parsed = JSON.parse(value || '{}');
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch(e) {
        return {};
    }
}

function readableVariantBaseValue(value) {
    if (value === undefined || value === null || value === '') return '';
    if (typeof value === 'object') {
        if (value.value !== undefined) return readableVariantBaseValue(value.value);
        if (value.year !== undefined || value.monthName !== undefined) {
            return [value.day, value.monthName, value.year, value.era].filter(Boolean).join(' ');
        }
        if (value.filename) return value.filename;
        return Object.values(value).map(readableVariantBaseValue).filter(Boolean).join(', ');
    }
    const raw = String(value);
    try {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return parsed.map(readableVariantBaseValue).filter(Boolean).join(', ');
        if (parsed && typeof parsed === 'object') return readableVariantBaseValue(parsed);
    } catch(e) {}
    return raw;
}

function createDateTableWidget(cfg, saved, onChange) {
    const months = Array.isArray(cfg.months) && cfg.months.length ? cfg.months : [
        {name:'Styczeń',days:31},{name:'Luty',days:28},{name:'Marzec',days:31},{name:'Kwiecień',days:30},
        {name:'Maj',days:31},{name:'Czerwiec',days:30},{name:'Lipiec',days:31},{name:'Sierpień',days:31},
        {name:'Wrzesień',days:30},{name:'Październik',days:31},{name:'Listopad',days:30},{name:'Grudzień',days:31}
    ];
    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:6px 8px;width:100%;';
    const daySel = document.createElement('select');
    const monthSel = document.createElement('select');
    const yearInp = document.createElement('input');
    daySel.style.cssText = 'flex:1;min-width:68px;';
    monthSel.style.cssText = 'flex:2;min-width:110px;';
    yearInp.type = 'text';
    yearInp.placeholder = 'Rok';
    yearInp.value = saved?.year ?? '';
    yearInp.style.cssText = 'flex:1;min-width:72px;';
    months.forEach((month, index) => {
        const opt = document.createElement('option');
        opt.value = index;
        opt.textContent = month.name;
        if ((saved?.monthIndex ?? 0) === index) opt.selected = true;
        monthSel.appendChild(opt);
    });
    const populateDays = () => {
        const monthIndex = parseInt(monthSel.value, 10) || 0;
        const maxDays = months[monthIndex]?.days || 31;
        const previous = parseInt(daySel.value, 10) || saved?.day || 1;
        daySel.innerHTML = '';
        for (let day = 1; day <= maxDays; day++) {
            const opt = document.createElement('option');
            opt.value = day;
            opt.textContent = day;
            if (day === Math.min(previous, maxDays)) opt.selected = true;
            daySel.appendChild(opt);
        }
    };
    const sync = () => {
        const monthIndex = parseInt(monthSel.value, 10) || 0;
        onChange({
            day: parseInt(daySel.value, 10) || 1,
            monthIndex,
            monthName: months[monthIndex]?.name || '',
            year: yearInp.value.trim()
        });
    };
    populateDays();
    daySel.addEventListener('change', sync);
    monthSel.addEventListener('change', () => { populateDays(); sync(); });
    yearInp.addEventListener('input', sync);
    wrap.append(daySel, monthSel, yearInp);
    sync();
    return wrap;
}

function templateDateConfigFromField(fieldOrConfig = {}) {
    let local = {};
    try {
        local = typeof fieldOrConfig.placeholder === 'string'
            ? JSON.parse(fieldOrConfig.placeholder || '{}')
            : (fieldOrConfig || {});
    } catch(e) {}
    const global = window.currentTemplateDateSettings?.settings || {};
    const source = Array.isArray(global.months) && global.months.length ? global : local;
    return {
        months: Array.isArray(source.months) && source.months.length ? source.months : [
            {name:'Styczen',days:31},{name:'Luty',days:28},{name:'Marzec',days:31},{name:'Kwiecien',days:30},
            {name:'Maj',days:31},{name:'Czerwiec',days:30},{name:'Lipiec',days:31},{name:'Sierpien',days:31},
            {name:'Wrzesien',days:30},{name:'Pazdziernik',days:31},{name:'Listopad',days:30},{name:'Grudzien',days:31}
        ],
        eras: Array.isArray(source.eras) ? source.eras : [],
        defaultYear: source.defaultYear || ''
    };
}

function createImageTableWidget(saved, onChange, fieldId = '', rowKey = '') {
    const wrap = document.createElement('div');
    wrap.className = 'table-image-widget';

    const preview = document.createElement('img');
    preview.alt = '';
    preview.className = 'table-image-preview';
    if (saved?.url) {
        preview.src = saved.url;
    }

    const actions = document.createElement('div');
    actions.className = 'table-image-actions';

    const chooseBtn = createUnifiedImageButton('Z galerii');
    chooseBtn.classList.add('table-image-action');
    chooseBtn.title = ccText('chooseFromGallery', 'Wybierz z galerii');
    chooseBtn.innerHTML = '<i class="fa-solid fa-images"></i><span>Z galerii</span>';
    chooseBtn.addEventListener('click', async () => {
        const value = await chooseTemplateImageAsset();
        if (!value) return;
        preview.src = value.url;
        onChange(value);
    });

    const encodedRowKey = encodeURIComponent(rowKey || '');
    if (!isEditingCharacterVariant() && fieldId && rowKey) {
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = OC_IMAGE_ACCEPT;
        fileInput.id = `table-image-file-${fieldId}-${encodedRowKey}`;
        fileInput.name = `field_table_image_files[${fieldId}][${encodedRowKey}]`;
        fileInput.hidden = true;

        const tagsInput = document.createElement('input');
        tagsInput.type = 'hidden';
        tagsInput.name = `field_table_image_tags[${fieldId}][${encodedRowKey}]`;

        const uploadBtn = document.createElement('label');
        uploadBtn.htmlFor = fileInput.id;
        uploadBtn.className = 'upload-label btn-secondary table-image-action';
        uploadBtn.title = ccText('uploadImage', 'Wgraj zdjecie');
        uploadBtn.innerHTML = `<i class="fa-solid fa-upload"></i><span>${escapeHtml(ccText('uploadImage', 'Wgraj'))}</span>`;

        fileInput.addEventListener('change', () => {
            const file = fileInput.files[0];
            if (!file) return;
            if (askDeferredImageTags(tagsInput) === null) {
                fileInput.value = '';
                return;
            }
            previewLocalImage(file, preview);
            onChange({ pendingUpload: true, filename: file.name });
        });

        actions.append(uploadBtn);
        wrap.append(fileInput, tagsInput);
    }

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn-secondary table-image-action table-image-remove';
    removeBtn.title = ccText('removeImage', 'Usun zdjecie');
    removeBtn.innerHTML = `<i class="fa-solid fa-trash"></i><span>${escapeHtml(window.OCI18n?.common?.delete || 'Usun')}</span>`;
    removeBtn.addEventListener('click', () => {
        preview.removeAttribute('src');
        onChange('');
    });

    actions.append(chooseBtn, removeBtn);
    wrap.append(preview, actions);
    return wrap;
}

function createListTableWidget(saved, onChange) {
    let items = normalizeListItems(saved);
    const wrap = document.createElement('div');
    wrap.className = 'character-list-widget table-list-widget';
    const list = document.createElement('div');
    list.className = 'character-list-rows';

    const sync = () => {
        items = [...list.querySelectorAll('input')].map(input => input.value.trim()).filter(Boolean);
        onChange(items);
    };

    const addRow = (value = '') => {
        const row = document.createElement('div');
        row.className = 'character-list-row';
        const bullet = document.createElement('span');
        bullet.className = 'character-list-bullet';
        bullet.textContent = '•';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'character-list-input';
        input.value = value;
        input.placeholder = 'Element listy...';
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                const next = addRow('');
                next.querySelector('input')?.focus();
                sync();
            }
        });
        input.addEventListener('input', sync);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'character-list-remove';
        remove.title = ccText('removeItem', 'Usun element');
        remove.innerHTML = '<i class="fa-solid fa-minus"></i>';
        remove.addEventListener('click', () => {
            row.remove();
            if (!list.children.length) addRow('');
            sync();
        });
        row.append(bullet, input, remove);
        list.appendChild(row);
        return row;
    };

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn-secondary character-list-add';
    addBtn.innerHTML = `<i class="fa-solid fa-plus"></i><span>${escapeHtml(ccText('addItem', 'Dodaj element'))}</span>`;
    addBtn.addEventListener('click', () => { addRow(''); sync(); });

    if (items.length) items.forEach(addRow);
    else addRow('');
    wrap.append(list, addBtn);
    sync();
    return wrap;
}

function normalizeListItems(value) {
    if (value && typeof value === 'object' && !Array.isArray(value) && Object.prototype.hasOwnProperty.call(value, 'value')) {
        value = value.value;
    }
    if (typeof value === 'string') {
        try {
            value = JSON.parse(value);
        } catch(e) {
            value = value.split(/\r\n|\r|\n/);
        }
    }
    if (!Array.isArray(value)) return [];
    return value.map(item => {
        if (item && typeof item === 'object' && Object.prototype.hasOwnProperty.call(item, 'value')) {
            return item.value;
        }
        return item;
    }).map(item => String(item ?? '').trim()).filter(Boolean);
}

function isSerializedListValue(value) {
    value = tableCellValue(value);
    if (value && typeof value === 'object' && !Array.isArray(value) && Object.prototype.hasOwnProperty.call(value, 'value')) {
        value = value.value;
    }
    if (Array.isArray(value)) {
        return true;
    }
    if (typeof value !== 'string' || value.trim() === '') {
        return false;
    }
    try {
        const parsed = JSON.parse(value);
        if (Array.isArray(parsed)) return true;
        return Boolean(parsed && typeof parsed === 'object' && Array.isArray(parsed.value));
    } catch(e) {
        return false;
    }
}

function createSelectTableWidget(rowDef, saved, onChange) {
    const select = document.createElement('select');
    select.dataset.rowKey = rowDef.key || rowDef.label;
    select.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = ccText('chooseEmpty', '-- Wybierz --');
    select.appendChild(empty);

    (rowDef.options || []).forEach(optionValue => {
        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionValue;
        if (saved === optionValue) option.selected = true;
        select.appendChild(option);
    });

    select.addEventListener('change', () => onChange(select.value));
    return select;
}

function normalizeStatsConfig(field) {
    let cfg = {};
    try { cfg = JSON.parse(field.placeholder || '{}'); } catch(e){}
    return {
        maxPoints: Math.max(0, parseInt(cfg.maxPoints, 10) || 0),
        rows: Array.isArray(cfg.rows) ? cfg.rows.map(row => {
            if (typeof row === 'string') return { key: row, label: row, defaultValue: '' };
            return {
                key: String(row?.key || row?.label || row?.name || ''),
                label: String(row?.label || row?.name || ''),
                defaultValue: String(row?.defaultValue ?? '')
            };
        }).filter(row => row.label) : []
    };
}

function renderStatsWidget(field, savedRaw, hiddenInput) {
    const cfg = normalizeStatsConfig(field);
    const variantMode = isEditingCharacterVariant();
    const hasOwnValue = savedRaw !== null && savedRaw !== undefined && String(savedRaw).trim() !== '';
    let values = {};
    try { values = hasOwnValue ? JSON.parse(savedRaw) : {}; } catch(e){}
    if (variantMode && !hasOwnValue) {
        values = parseVariantJsonObject(characterBaseFieldValueMap()[field.id] ?? '{}');
    } else if (window.characterIsNew && !hasOwnValue) {
        cfg.rows.forEach(rowDef => {
            const defaultValue = Math.max(0, parseInt(rowDef.defaultValue, 10) || 0);
            if (defaultValue > 0) values[rowDef.key || rowDef.label] = defaultValue;
        });
    }
    let inherited = variantMode && !hasOwnValue;

    const wrap = document.createElement('div');
    wrap.className = 'stats-field-widget';
    wrap.dataset.maxPoints = String(cfg.maxPoints);
    if (inherited) wrap.dataset.inherited = 'true';
    wrap.style.cssText = 'display:flex;flex-direction:column;gap:10px;';

    const counter = document.createElement('div');
    counter.className = 'stats-points-counter';
    counter.style.cssText = 'font-size:0.85rem;font-weight:700;color:var(--text-muted,#888);';

    const rowsWrap = document.createElement('div');
    rowsWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;overflow:hidden;';

    const sync = (persist = true) => {
        const next = {};
        let sum = 0;
        wrap.querySelectorAll('input[data-stat-key]').forEach(input => {
            const value = Math.max(0, parseInt(input.value, 10) || 0);
            input.value = String(value);
            next[input.dataset.statKey] = value;
            sum += value;
        });
        if (persist) {
            inherited = false;
            delete wrap.dataset.inherited;
            hiddenInput.value = JSON.stringify(next);
            notifyFieldValueChanged(hiddenInput);
        } else if (inherited) {
            hiddenInput.value = '';
        }
        counter.textContent = inherited
            ? `Dziedziczone: ${sum}/${cfg.maxPoints} pkt`
            : `Wydano ${sum}/${cfg.maxPoints} pkt`;
        counter.style.color = sum === cfg.maxPoints ? 'var(--success,#27ae60)' : 'var(--danger,#e74c3c)';
        wrap.dataset.pointsValid = sum === cfg.maxPoints ? 'true' : 'false';
    };

    cfg.rows.forEach((rowDef, index) => {
        const row = document.createElement('div');
        row.style.cssText = `display:grid;grid-template-columns:minmax(0, 1fr) minmax(64px, 22%);align-items:stretch;${index < cfg.rows.length - 1 ? 'border-bottom:1px solid var(--border,#ddd);' : ''}`;
        const label = document.createElement('div');
        label.textContent = rowDef.label;
        label.style.cssText = 'min-width:0;padding:8px 12px;background:var(--surface-alt,#f5f5f5);font-weight:700;border-right:1px solid var(--border,#ddd);word-break:break-word;';
        const input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.step = '1';
        input.dataset.statKey = rowDef.key || rowDef.label;
        input.value = String(Math.max(0, parseInt(values[rowDef.key] ?? values[rowDef.label], 10) || 0));
        input.style.cssText = 'width:100%;min-width:0;border:none;padding:8px 10px;background:transparent;text-align:right;box-sizing:border-box;';
        input.addEventListener('input', () => sync(true));
        row.append(label, input);
        rowsWrap.appendChild(row);
    });

    wrap.append(counter, rowsWrap);
    sync(!inherited);
    return wrap;
}

function buildFieldWidget(field) {
    const fieldId  = field.id;
    const ftype    = field.field_type;
    const savedRaw = characterFieldValueMap()[fieldId] != null ? characterFieldValueMap()[fieldId] : null;
    const basePlaceholder = characterBaseFieldPlaceholder(fieldId);
    const fieldNames = characterFieldInputNames(fieldId);
    const variantMode = isEditingCharacterVariant();

    const wrapper = document.createElement('div');
    wrapper.className = 'input-group';
    wrapper.classList.add(`field-type-${ftype}`);
    wrapper.dataset.fieldType = ftype;
    wrapper.tabIndex = 0;

    if ((field.label || '').trim() !== '') {
        const label = document.createElement('label');
        label.innerText = field.label;
        wrapper.appendChild(label);
    }

    switch(ftype) {

        // ---- TEKST ----
        case 'text': {
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.placeholder = variantMode && basePlaceholder ? basePlaceholder : ccText('valuePlaceholder', 'Wpisz wartosc...');
            inp.name = fieldNames.value;
            if (savedRaw !== null) inp.value = savedRaw;
            wrapper.appendChild(inp);
            break;
        }

        // ---- DŁUGI TEKST ----
        case 'textarea': {
            const ta = document.createElement('textarea');
            ta.placeholder = variantMode && basePlaceholder ? basePlaceholder : ccText('valuePlaceholder', 'Wpisz tekst...');
            ta.name = fieldNames.value;
            ta.rows = 5;
            ta.style.resize = 'vertical';
            if (savedRaw !== null) ta.value = savedRaw;
            wrapper.appendChild(ta);
            break;
        }

        // ---- LISTA (bullet points) ----
        case 'list': {
            const listWrap = document.createElement('div');
            listWrap.className = 'character-list-widget';

            const bulletContainer = document.createElement('div');
            bulletContainer.className = 'bullet-list-container character-list-rows';

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = fieldNames.value;
            hiddenInput.id   = `list-hidden-${fieldId}`;

            let lines = [];
            lines = normalizeListItems(savedRaw);
            if (lines.length === 0) lines = [''];

            const syncListHidden = () => {
                const vals = [...bulletContainer.querySelectorAll('.bullet-input')]
                    .map(i => i.value).filter(v => v.trim() !== '');
                hiddenInput.value = vals.length ? JSON.stringify(vals) : '';
                notifyFieldValueChanged(hiddenInput);
            };

            const addBulletRow = (val = '') => {
                const row = document.createElement('div');
                row.className = 'character-list-row';

                const dot = document.createElement('span');
                dot.className = 'character-list-bullet';
                dot.textContent = '•';

                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'bullet-input character-list-input';
                inp.value = val;
                inp.placeholder = variantMode && basePlaceholder ? basePlaceholder : ccText('valuePlaceholder', 'Wpisz element...');

                inp.addEventListener('keydown', e => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const next = addBulletRow('');
                        next.querySelector('.bullet-input')?.focus();
                        syncListHidden();
                    } else if (e.key === 'Backspace' && inp.value === '') {
                        e.preventDefault();
                        const prev = row.previousElementSibling;
                        row.remove();
                        if (!bulletContainer.children.length) addBulletRow('');
                        prev?.querySelector('.bullet-input')?.focus();
                        syncListHidden();
                    }
                });
                inp.addEventListener('input', syncListHidden);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'character-list-remove';
                removeBtn.title = ccText('removeItem', 'Usun element');
                removeBtn.innerHTML = '<i class="fa-solid fa-minus"></i>';
                removeBtn.addEventListener('click', () => {
                    const prev = row.previousElementSibling;
                    row.remove();
                    if (!bulletContainer.children.length) addBulletRow('');
                    (prev || bulletContainer.lastElementChild)?.querySelector('.bullet-input')?.focus();
                    syncListHidden();
                });

                row.append(dot, inp, removeBtn);
                bulletContainer.appendChild(row);
                syncListHidden();
                return row;
            };

            lines.forEach(l => addBulletRow(l));

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn-secondary character-list-add';
            addBtn.innerHTML = `<i class="fa-solid fa-plus"></i><span>${escapeHtml(ccText('addItem', 'Dodaj element'))}</span>`;
            addBtn.addEventListener('click', () => {
                const row = addBulletRow('');
                row.querySelector('.bullet-input')?.focus();
                syncListHidden();
            });

            listWrap.append(bulletContainer, addBtn);
            wrapper.appendChild(listWrap);
            wrapper.appendChild(hiddenInput);
            if (lines.some(line => String(line || '').trim() !== '')) syncListHidden();
            else hiddenInput.value = '';
            break;
        }

        // ---- ZDJĘCIE ----
        case 'image': {
            const imgWrap = document.createElement('div');
            imgWrap.className = 'template-image-field';
            imgWrap.style.cssText = 'display:flex;flex-direction:column;gap:8px;';
            const previewWidth = imageSizePercentFromConfig(field.placeholder);

            let savedData = null;
            try { savedData = savedRaw ? JSON.parse(savedRaw) : null; } catch(e){}

            const preview = document.createElement('img');
            preview.style.cssText = `width:${previewWidth};max-width:100%;height:auto;border-radius:8px;object-fit:contain;border:1px solid var(--border,#ddd);display:none;`;
            if (savedData?.url) {
                preview.src = savedData.url;
                preview.style.display = 'block';
            }

            const fileInput = document.createElement('input');
            fileInput.type   = 'file';
            fileInput.accept = OC_IMAGE_ACCEPT;
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `file-${fieldId}`;
            fileInput.name = fieldNames.imageFile;
            fileInput.dataset.legacyUpload = '1';

            const uploadBtn = document.createElement('label');
            uploadBtn.htmlFor = fileInput.id;
            uploadBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;padding:9px 12px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;font-weight:700;line-height:1.15;flex:1;min-width:0;text-align:center;';
            uploadBtn.innerHTML = `<i class="fa-solid fa-upload"></i> ${escapeHtml(ccText('chooseImage', 'Wybierz zdjecie'))}`;

            const galleryBtn = document.createElement('button');
            galleryBtn.type = 'button';
            galleryBtn.className = 'upload-label btn-secondary';
            galleryBtn.textContent = ccText('chooseFromGallery', 'Wybierz z galerii');
            galleryBtn.style.cssText = 'flex:1;min-width:0;justify-content:center;text-align:center;line-height:1.15;';

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = fieldNames.value;
            hiddenInput.id   = `img-hidden-${fieldId}`;
            if (savedRaw !== null) hiddenInput.value = savedRaw;

            const tagsInput = document.createElement('input');
            tagsInput.type = 'hidden';
            tagsInput.name = fieldNames.imageTags;

            fileInput.addEventListener('change', () => {
                const file = fileInput.files[0];
                if (!file) return;
                if (askDeferredImageTags(tagsInput) === null) {
                    fileInput.value = '';
                    return;
                }
                setCollapsed(false);
                previewLocalImage(file, preview);
                hiddenInput.value = JSON.stringify({ pendingUpload: true, filename: file.name });
            });
            galleryBtn.addEventListener('click', async () => {
                const asset = await chooseTemplateImageAsset();
                if (!asset) return;
                setCollapsed(false);
                preview.src = asset.url;
                preview.style.display = 'block';
                hiddenInput.value = JSON.stringify(asset);
            });

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-secondary image-remove-btn';
            removeBtn.textContent = ccText('removeImage', 'Usun zdjecie');
            removeBtn.style.cssText = 'flex:1;min-width:0;justify-content:center;text-align:center;line-height:1.15;';
            removeBtn.addEventListener('click', () => {
                fileInput.value = '';
                tagsInput.value = '';
                preview.removeAttribute('src');
                preview.style.display = 'none';
                hiddenInput.value = '';
            });

            const hideBtn = document.createElement('button');
            hideBtn.type = 'button';
            hideBtn.className = 'image-hide-toggle';
            hideBtn.style.cssText = 'flex:1;min-width:0;justify-content:center;text-align:center;line-height:1.15;';
            const storageKey = () => `oc-character-template-image-field-collapsed:${window.currentCharacterTemplateId || document.getElementById('form-template-id')?.value || 'without-template'}:${fieldId}`;
            const setCollapsed = (collapsed, persist = true) => {
                imgWrap.classList.toggle('is-image-field-collapsed', collapsed);
                preview.hidden = collapsed;
                hideBtn.textContent = collapsed ? ccText('revealImage', 'Odslon zdjecie') : ccText('hideImageTemp', 'Tymczasowo ukryj');
                hideBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                hideBtn.title = collapsed ? ccText('revealImageTitle', 'Odslon zdjecie w edytorze') : ccText('hideImageTitle', 'Tymczasowo ukryj zdjecie w edytorze');
                if (persist) localStorage.setItem(storageKey(), collapsed ? '1' : '0');
            };
            hideBtn.addEventListener('click', () => {
                setCollapsed(hideBtn.getAttribute('aria-expanded') === 'true');
            });
            setCollapsed(localStorage.getItem(storageKey()) === '1', false);

            const actionsWrap = document.createElement('div');
            actionsWrap.className = 'image-actions-row template-image-actions-row';
            actionsWrap.style.cssText = 'display:flex;gap:8px;width:100%;align-items:stretch;';
            actionsWrap.append(uploadBtn, galleryBtn, removeBtn, hideBtn);

            imgWrap.appendChild(preview);
            imgWrap.appendChild(actionsWrap);
            imgWrap.appendChild(fileInput);
            imgWrap.appendChild(tagsInput);
            wrapper.appendChild(imgWrap);
            wrapper.appendChild(hiddenInput);
            break;
        }

        // ---- GALERIA ----
        case 'image-gallery': {
            const gallWrap = document.createElement('div');
            gallWrap.style.cssText = 'display:flex;flex-direction:column;gap:10px;';

            const thumbsContainer = document.createElement('div');
            thumbsContainer.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;';

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = fieldNames.value;
            hiddenInput.id   = `gallery-hidden-${fieldId}`;

            let images = [];
            try { images = savedRaw ? JSON.parse(savedRaw) : []; } catch(e){}

            const syncGallery = () => { hiddenInput.value = images.length ? JSON.stringify(images) : ''; };

            const addThumb = (img) => {
                const thumb = document.createElement('div');
                thumb.style.cssText = 'position:relative;width:80px;height:80px;';

                const imgEl = document.createElement('img');
                imgEl.src = img.url;
                imgEl.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--border,#ddd);';

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:0.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;';
                removeBtn.addEventListener('click', () => {
                    images = images.filter(i => i.filename !== img.filename);
                    thumb.remove();
                    syncGallery();
                });

                thumb.appendChild(imgEl);
                thumb.appendChild(removeBtn);
                thumbsContainer.appendChild(thumb);
            };

            images.forEach(img => addThumb(img));
            syncGallery();

            const fileInput = document.createElement('input');
            fileInput.type     = 'file';
            fileInput.accept   = OC_IMAGE_ACCEPT;
            fileInput.multiple = true;
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `gallery-file-${fieldId}`;
            fileInput.name = fieldNames.galleryFiles;

            const tagsInput = document.createElement('input');
            tagsInput.type = 'hidden';
            tagsInput.name = fieldNames.galleryTags;

            const uploadBtn = document.createElement('label');
            uploadBtn.htmlFor = fileInput.id;
            uploadBtn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadBtn.innerHTML = `<i class="fa-solid fa-upload"></i> ${escapeHtml(ccText('uploadImages', 'Wgraj zdjecia'))}`;

            const galleryBtn = document.createElement('button');
            galleryBtn.type = 'button';
            galleryBtn.className = 'btn-secondary';
            galleryBtn.textContent = ccText('addFromGallery', 'Dodaj z galerii');

            fileInput.addEventListener('change', () => {
                if (!fileInput.files.length) return;
                if (askDeferredImageTags(tagsInput) === null) {
                    fileInput.value = '';
                    return;
                }
                for (const file of fileInput.files) {
                    const img = { pendingUpload: true, url: URL.createObjectURL(file), filename: file.name };
                    images.push(img);
                    addThumb(img);
                    syncGallery();
                }
            });
            galleryBtn.className = 'upload-label btn-secondary';
            galleryBtn.textContent = ccText('addImage', 'Dodaj zdjecie');
            galleryBtn.addEventListener('click', async () => {
                const asset = await chooseTemplateImageAsset();
                if (!asset) return;
                const img = asset;
                images.push(img);
                addThumb(img);
                syncGallery();
            });

            gallWrap.appendChild(thumbsContainer);
            gallWrap.appendChild(uploadBtn);
            gallWrap.appendChild(galleryBtn);
            gallWrap.appendChild(fileInput);
            gallWrap.appendChild(tagsInput);
            wrapper.appendChild(gallWrap);
            wrapper.appendChild(hiddenInput);
            break;
        }

        // ---- TABELA ----
        case 'table': {
            let cfg = {};
            try { cfg = JSON.parse(field.placeholder || '{}'); } catch(e){}
            const rowDefs = normalizeTableRowsConfig(cfg);
            const baseRows = parseVariantJsonObject(characterBaseFieldValueMap()[fieldId] ?? '{}');

            let savedRows = {};
            try { savedRows = savedRaw ? JSON.parse(savedRaw) : {}; } catch(e){}

            if (window.characterIsNew && !savedRaw) {
                rowDefs.forEach(rowDef => {
                    if (rowDef.type !== 'text' || !rowDef.defaultValue) return;
                    savedRows[rowDef.key || rowDef.label] = {
                        type: rowDef.type,
                        value: rowDef.defaultValue
                    };
                });
            }

            if (rowDefs.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:var(--text-muted,#999);font-size:0.85rem;';
                empty.innerText = 'Ta tabela nie ma zdefiniowanych wierszy.';
                wrapper.appendChild(empty);
                break;
            }

            const tableWrap = document.createElement('div');
            tableWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;overflow:hidden;';

            const hiddenInput = document.createElement('input');
            hiddenInput.type  = 'hidden';
            hiddenInput.name  = fieldNames.value;
            hiddenInput.id    = `table-hidden-${fieldId}`;
            const hasSavedTableRows = savedRaw !== null && savedRaw !== undefined && String(savedRaw).trim() !== '';
            hiddenInput.value = hasSavedTableRows ? JSON.stringify(savedRows) : '';
            let initializingInheritedTable = variantMode && !hasSavedTableRows;

            const setCell = (rowDef, value) => {
                if (initializingInheritedTable) return;
                savedRows[rowDef.key || rowDef.label] = { type: rowDef.type, value };
                syncAgeRows();
                hiddenInput.value = JSON.stringify(savedRows);
                notifyFieldValueChanged(hiddenInput);
            };

            const ageOutputs = new Map();
            const syncAgeRows = () => {
                rowDefs.filter(row => row.type === 'age').forEach(row => {
                    const source = row.ageFrom || row.label;
                    const age = calculateAgeFromDate(tableCellValue(tableFindSourceCell(savedRows, rowDefs, source)));
                    savedRows[row.key || row.label] = { type: 'age', value: age, ageFrom: source };
                    const output = ageOutputs.get(row.key || row.label);
                    if (output) output.value = age;
                });
            };

            rowDefs.forEach((rowDef, idx) => {
                const row = document.createElement('div');
                row.style.cssText = `display:flex;align-items:stretch;${idx < rowDefs.length-1 ? 'border-bottom:1px solid var(--border,#ddd);' : ''}`;

                const nameCell = document.createElement('div');
                nameCell.style.cssText = 'flex:0 0 38%;padding:8px 12px;background:var(--surface-alt,#f5f5f5);font-size:0.88rem;font-weight:600;color:var(--text,#333);display:flex;align-items:center;border-right:1px solid var(--border,#ddd);word-break:break-word;';
                nameCell.innerText = rowDef.label;

                const valueCell = document.createElement('div');
                valueCell.style.cssText = 'flex:1;display:flex;align-items:stretch;';
                const savedCell = tableSavedCell(savedRows, rowDef);
                const effectiveRowDef = rowDef.type === 'text' && isSerializedListValue(savedCell)
                    ? { ...rowDef, type: 'list' }
                    : rowDef;
                const savedValue = tableCellValue(savedCell);
                if (effectiveRowDef.type === 'date') {
                    valueCell.appendChild(createDateTableWidget(templateDateConfigFromField(cfg), savedValue, value => setCell(effectiveRowDef, value)));
                } else if (effectiveRowDef.type === 'image') {
                    valueCell.appendChild(createImageTableWidget(savedValue, value => setCell(effectiveRowDef, value), fieldId, effectiveRowDef.key || effectiveRowDef.label));
                } else if (effectiveRowDef.type === 'list') {
                    valueCell.appendChild(createListTableWidget(savedValue, value => setCell(effectiveRowDef, value)));
                } else if (effectiveRowDef.type === 'select') {
                    valueCell.appendChild(createSelectTableWidget(effectiveRowDef, savedValue, value => setCell(effectiveRowDef, value)));
                } else if (effectiveRowDef.type === 'age') {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    inp.readOnly = true;
                    inp.dataset.rowKey = effectiveRowDef.key || effectiveRowDef.label;
                    inp.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';
                    ageOutputs.set(effectiveRowDef.key || effectiveRowDef.label, inp);
                    valueCell.appendChild(inp);
                } else {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    const rowBase = readableVariantBaseValue(tableFindSourceCell(baseRows, rowDefs, effectiveRowDef.key || effectiveRowDef.label));
                    inp.placeholder = variantMode && rowBase ? rowBase : ccText('valuePlaceholder', 'Wpisz wartosc...');
                    inp.dataset.rowKey = effectiveRowDef.key || effectiveRowDef.label;
                    inp.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';
                    if (savedValue !== undefined && savedValue !== null) inp.value = savedValue;
                    inp.addEventListener('input', () => setCell(effectiveRowDef, inp.value));
                    valueCell.appendChild(inp);
                }
                row.appendChild(nameCell);
                row.appendChild(valueCell);
                tableWrap.appendChild(row);
            });
            initializingInheritedTable = false;

            if (hasSavedTableRows || !variantMode) {
                syncAgeRows();
                hiddenInput.value = JSON.stringify(savedRows);
            }
            wrapper.appendChild(tableWrap);
            wrapper.appendChild(hiddenInput);
            break;
        }

        case 'stats': {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = fieldNames.value;
            wrapper.appendChild(renderStatsWidget(field, savedRaw, hiddenInput));
            wrapper.appendChild(hiddenInput);
            break;
        }

        // ---- DATA ----
        case 'date': {
            const cfg = templateDateConfigFromField(field);
            const months    = cfg.months;
            const eras      = cfg.eras;
            const defYear   = cfg.defaultYear || '';

            let saved = {};
            try { saved = savedRaw ? JSON.parse(savedRaw) : {}; } catch(e){}

            const hiddenInput = document.createElement('input');
            hiddenInput.type  = 'hidden';
            hiddenInput.name  = fieldNames.value;
            hiddenInput.id    = `date-hidden-${fieldId}`;

            const dateRow = document.createElement('div');
            dateRow.className = 'character-template-date-row';
            dateRow.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;';

            // Miesiąc
            const monthSel = document.createElement('select');
            monthSel.style.cssText = 'flex:2;min-width:120px;';
            if (months.length === 0) {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = ccText('emptyMonths', '-- Brak miesiecy --');
                monthSel.appendChild(opt);
            } else {
                months.forEach((m, i) => {
                    const opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = m.name;
                    if (saved.monthIndex !== undefined && saved.monthIndex === i) opt.selected = true;
                    monthSel.appendChild(opt);
                });
            }

            // Dzień
            const daySel = document.createElement('select');
            daySel.style.cssText = 'flex:1;min-width:70px;';

            const populateDays = () => {
                const mIdx = parseInt(monthSel.value);
                const maxDays = (months[mIdx]?.days) || 31;
                const prevDay = parseInt(daySel.value) || (saved.day || 1);
                daySel.innerHTML = '';
                for (let d = 1; d <= maxDays; d++) {
                    const opt = document.createElement('option');
                    opt.value = d; opt.textContent = d;
                    if (d === prevDay) opt.selected = true;
                    daySel.appendChild(opt);
                }
            };
            populateDays();
            monthSel.addEventListener('change', () => { populateDays(); syncDate(); });

            // Rok
            const yearInp = document.createElement('input');
            yearInp.type  = 'text';
            yearInp.placeholder = basePlaceholder || 'Rok';
            yearInp.value = saved.year !== undefined ? saved.year : defYear;
            yearInp.style.cssText = 'flex:1;min-width:70px;';

            const syncDate = () => {
                const obj = {
                    day:        parseInt(daySel.value)  || 1,
                    monthIndex: parseInt(monthSel.value)|| 0,
                    monthName:  months[parseInt(monthSel.value)]?.name || '',
                    year:       yearInp.value.trim(),
                };
                if (eraSel) obj.era = eraSel.value;
                hiddenInput.value = JSON.stringify(obj);
            };

            daySel.addEventListener('change', syncDate);
            yearInp.addEventListener('input', syncDate);

            dateRow.appendChild(daySel);
            dateRow.appendChild(monthSel);
            dateRow.appendChild(yearInp);

            // Era (opcjonalna)
            let eraSel = null;
            if (eras.length > 0) {
                eraSel = document.createElement('select');
                eraSel.style.cssText = 'flex:1;min-width:80px;';
                eras.forEach(e => {
                    const opt = document.createElement('option');
                    opt.value = e; opt.textContent = e;
                    if (saved.era === e) opt.selected = true;
                    eraSel.appendChild(opt);
                });
                eraSel.addEventListener('change', syncDate);
                dateRow.appendChild(eraSel);
            }

            wrapper.appendChild(dateRow);
            wrapper.appendChild(hiddenInput);
            syncDate();
            break;
        }

        // ---- WYBĂ“R Z LISTY ----
        case 'select': {
            let cfg = {};
            try { cfg = JSON.parse(field.placeholder || '{}'); } catch(e){}
            const options = Array.isArray(cfg.options) ? cfg.options : [];

            const sel = document.createElement('select');
            sel.name = fieldNames.value;

            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = variantMode && basePlaceholder ? ccText('defaultValue', 'Domyslne: :value', { value: basePlaceholder }) : ccText('chooseEmpty', '-- Wybierz --');
            if (savedRaw === null || savedRaw === '') defOpt.selected = true;
            sel.appendChild(defOpt);

            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o; opt.textContent = o;
                if (savedRaw === o) opt.selected = true;
                sel.appendChild(opt);
            });

            wrapper.appendChild(sel);
            break;
        }

        default:
            return null;
    }

    return wrapper;
}

// -------------------------------------------------------
// Upload pliku do serwera
// -------------------------------------------------------
async function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    let tags = window.OCImageTools?.getLastUploadTags?.() || '';
    while (tags.split(',').map(t => t.trim()).filter(Boolean).length < 5) {
        tags = prompt(ccText('imageTagsPrompt', 'Enter at least 5 image filters separated by commas:'), tags || ccText('imageTagsDefault', 'sfw, character, image, gallery, description')) || '';
        if (!tags) return null;
    }
    tags = window.OCImageTools?.setLastUploadTags?.(tags) || tags.split(',').map(t => t.trim()).filter(Boolean).join(', ');
    formData.append('tags', tags);

    try {
        const res = await fetch('/uploadFile', { method: 'POST', body: formData });
        if (!res.ok) throw new Error('Upload failed');
        return await res.json(); // { url, filename }
    } catch(e) {
        console.error('Upload error:', e);
        alert(ccText('uploadError', 'Nie udalo sie przeslac pliku: :name', { name: file.name }));
        return null;
    }
}

// -------------------------------------------------------
// Helper tabeli
// -------------------------------------------------------
function updateTableHiddenInput(fieldId, tableWrap) {
    const hiddenInput = document.getElementById(`table-hidden-${fieldId}`);
    if (!hiddenInput) return;
    const result = {};
    tableWrap.querySelectorAll('input[data-row-key]').forEach(inp => {
        result[inp.dataset.rowKey] = inp.value;
    });
    hiddenInput.value = JSON.stringify(result);
}

// -------------------------------------------------------
// Aktualizacja template_id w ukrytym inpucie formularza
// -------------------------------------------------------
function updateTemplateId(value) {
    const hiddenInput = document.getElementById('form-template-id');
    if (hiddenInput) hiddenInput.value = value;
    loadTemplateFields(value);
}

let activeCharacterFieldItem = null;

function characterFieldItems(container) {
    return container ? [...container.querySelectorAll(':scope > .input-group')] : [];
}

function setActiveCharacterField(item) {
    if (!item || !item.matches?.('.input-group')) return;
    activeCharacterFieldItem?.classList.remove('is-active-character-field');
    activeCharacterFieldItem = item;
    activeCharacterFieldItem.classList.add('is-active-character-field');
}

function focusCharacterField(item) {
    if (!item) return false;
    setActiveCharacterField(item);
    const focusTarget = item.querySelector('input:not([type="hidden"]), textarea, select, button:not([disabled])') || item;
    focusTarget.focus();
    item.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return true;
}

function characterCompositeControls(item) {
    if (!item?.matches?.('.field-type-table, .field-type-stats')) return [];
    return [...item.querySelectorAll('input, textarea, select, button')]
        .filter(control => {
            if (control.disabled || control.hidden) return false;
            if (control.type === 'hidden') return false;
            if (control.readOnly) return false;
            if (control.offsetParent === null) return false;
            return !control.matches('[tabindex="-1"]');
        });
}

function moveWithinCharacterCompositeField(item, direction, target) {
    if (direction !== 'up' && direction !== 'down') return false;
    const controls = characterCompositeControls(item);
    if (!controls.length) return false;

    const index = controls.indexOf(target);
    const nextIndex = index < 0
        ? (direction === 'down' ? 0 : controls.length - 1)
        : index + (direction === 'down' ? 1 : -1);

    if (nextIndex < 0 || nextIndex >= controls.length) return false;
    const next = controls[nextIndex];
    next.focus();
    if (typeof next.select === 'function' && next.matches('input[type="text"], input[type="number"], input:not([type]), textarea')) {
        next.select();
    }
    next.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return true;
}

function focusInitialCharacterField(direction) {
    const leftItems = characterFieldItems(document.getElementById('left-fields-container'));
    const rightItems = characterFieldItems(document.getElementById('right-fields-container'));

    if (direction === 'right' && rightItems.length) {
        return focusCharacterField(rightItems[0]);
    }
    if (direction === 'left' && leftItems.length) {
        return focusCharacterField(leftItems[0]);
    }

    return focusCharacterField(leftItems[0] || rightItems[0]);
}

function moveCharacterFieldFocus(direction, target = document.activeElement) {
    const left = document.getElementById('left-fields-container');
    const right = document.getElementById('right-fields-container');
    const current = activeCharacterFieldItem;
    const currentContainer = current?.closest?.('#left-fields-container, #right-fields-container');
    if (!current || !currentContainer) {
        return focusInitialCharacterField(direction);
    }

    if (moveWithinCharacterCompositeField(current, direction, target)) {
        return true;
    }

    const currentItems = characterFieldItems(currentContainer);
    const index = currentItems.indexOf(current);
    if (index < 0) return false;

    if (direction === 'up' || direction === 'down') {
        const next = currentItems[index + (direction === 'up' ? -1 : 1)];
        return focusCharacterField(next);
    }

    const targetContainer = direction === 'left'
        ? (currentContainer.id === 'right-fields-container' ? left : null)
        : (currentContainer.id === 'left-fields-container' ? right : null);
    const targetItems = characterFieldItems(targetContainer);
    if (!targetItems.length) return false;
    return focusCharacterField(targetItems[Math.min(index, targetItems.length - 1)]);
}

function initCharacterFieldKeyboardNavigation() {
    const containers = [
        document.getElementById('left-fields-container'),
        document.getElementById('right-fields-container')
    ].filter(Boolean);
    if (!containers.length) return;

    containers.forEach(container => {
        if (container.dataset.characterKeyboardBound) return;
        container.dataset.characterKeyboardBound = '1';
        container.addEventListener('focusin', event => {
            const item = event.target.closest('#left-fields-container > .input-group, #right-fields-container > .input-group');
            if (item) setActiveCharacterField(item);
        });
        container.addEventListener('click', event => {
            const item = event.target.closest('#left-fields-container > .input-group, #right-fields-container > .input-group');
            if (item) setActiveCharacterField(item);
        });
    });
}

document.addEventListener('keydown', event => {
    if (document.body?.dataset.view !== 'create_character') return;
    if (!event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
    const directions = {
        ArrowUp: 'up',
        ArrowDown: 'down',
        ArrowLeft: 'left',
        ArrowRight: 'right',
    };
    const direction = directions[event.key];
    if (!direction) return;
    const target = event.target;
    const item = target?.closest?.('#left-fields-container > .input-group, #right-fields-container > .input-group');
    if (item) setActiveCharacterField(item);
    if (moveCharacterFieldFocus(direction, target)) {
        event.preventDefault();
    }
}, true);

document.addEventListener('submit', event => {
    if (event.target?.id !== 'character-form') return;
    const invalidStats = [...document.querySelectorAll('.stats-field-widget')]
        .filter(widget => !widget.closest('[data-variant-card]'))
        .find(widget => widget.dataset.pointsValid !== 'true');
    if (!invalidStats) return;
    event.preventDefault();
    const counter = invalidStats.querySelector('.stats-points-counter');
    alert(counter?.textContent ? `${ccText('statsError', 'Statystyki musza wykorzystac dokladnie pule punktow.')} ${counter.textContent}` : ccText('statsError', 'Statystyki musza wykorzystac dokladnie pule punktow.'));
    invalidStats.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

// -------------------------------------------------------
// Renderowanie widgetĂłw pĂłl dla wariantĂłw
// -------------------------------------------------------
function buildVariantFieldWidget(fieldWrapper) {
    // fieldWrapper to element [data-variant-field] LUB rodzic z [data-variant-field]
    let fieldContainer = fieldWrapper;
    if (!fieldWrapper.hasAttribute('data-variant-field')) {
        fieldContainer = fieldWrapper.querySelector('[data-variant-field]');
        if (!fieldContainer) return;
    }
    
    const fieldId = fieldContainer.dataset.fieldId;
    const fieldType = fieldContainer.dataset.fieldType;
    const fieldConfig = fieldContainer.dataset.fieldConfig;
    const fieldLabel = fieldContainer.dataset.fieldLabel;
    const baseValue = fieldContainer.dataset.baseValue || '';
    const renderer = fieldContainer.querySelector('.variant-field-renderer');
    const hiddenInput = fieldContainer.querySelector('.variant-field-input');
    
    if (!renderer || !hiddenInput) return;
    
    renderer.innerHTML = '';
    const basePlaceholder = readableVariantBaseValue(baseValue);

    if (typeof buildFieldWidget === 'function') {
        const variantKey = variantKeyFromInputName(hiddenInput.name);
        const previousValues = window.characterFieldValues;
        window.characterFieldValues = { ...(previousValues || {}), [fieldId]: hiddenInput.value };
        const widget = buildFieldWidget({
            id: fieldId,
            field_type: fieldType,
            placeholder: fieldConfig,
            label: '',
            location: 'left'
        });
        window.characterFieldValues = previousValues;

        if (widget) {
            const rewriteName = name => {
                if (!name) return name;
                if (name === `field_values[${fieldId}]`) return hiddenInput.name;
                if (name === `field_image_files[${fieldId}]`) return `variant_field_image_files[${variantKey}][${fieldId}]`;
                if (name === `field_image_tags[${fieldId}]`) return `variant_field_image_tags[${variantKey}][${fieldId}]`;
                if (name === `field_gallery_files[${fieldId}][]`) return `variant_field_gallery_files[${variantKey}][${fieldId}][]`;
                if (name === `field_gallery_tags[${fieldId}]`) return `variant_field_gallery_tags[${variantKey}][${fieldId}]`;
                return name;
            };

            widget.querySelectorAll('[name]').forEach(control => {
                control.name = rewriteName(control.name);
            });
            widget.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(control => {
                if (basePlaceholder && !control.value && control.name === hiddenInput.name) {
                    control.placeholder = basePlaceholder;
                }
            });
            const baseRows = parseVariantJsonObject(baseValue);
            const tableRows = normalizeTableRowsConfig(parseVariantJsonObject(fieldConfig));
            widget.querySelectorAll('[data-row-key]').forEach(control => {
                const rowBase = readableVariantBaseValue(tableFindSourceCell(baseRows, tableRows, control.dataset.rowKey));
                if (rowBase && !control.value) control.placeholder = rowBase;
            });
            widget.querySelectorAll('.bullet-input, .bullet-input-variant').forEach(control => {
                if (!control.value && basePlaceholder) {
                    control.placeholder = basePlaceholder;
                }
            });

            hiddenInput.removeAttribute('name');
            renderer.append(...Array.from(widget.childNodes));
            return;
        }
    }
    
    switch(fieldType) {
        case 'date': {
            const cfg = templateDateConfigFromField({ placeholder: fieldConfig });
            const months = cfg.months;
            const eras = cfg.eras;
            const defYear = cfg.defaultYear || '';
            
            let saved = {};
            try { saved = JSON.parse(hiddenInput.value || '{}'); } catch(e){}
            
            const dateRow = document.createElement('div');
            dateRow.className = 'character-template-date-row';
            dateRow.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;';
            
            // Dzień
            const daySel = document.createElement('select');
            daySel.style.cssText = 'flex:1;min-width:70px;';
            
            // Miesiąc
            const monthSel = document.createElement('select');
            monthSel.style.cssText = 'flex:2;min-width:120px;';
            if (months.length === 0) {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = ccText('emptyMonths', '-- Brak miesiecy --');
                monthSel.appendChild(opt);
            } else {
                months.forEach((m, i) => {
                    const opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = m.name;
                    if (saved.monthIndex !== undefined && saved.monthIndex === i) opt.selected = true;
                    monthSel.appendChild(opt);
                });
            }
            
            const populateDays = () => {
                const mIdx = parseInt(monthSel.value);
                const maxDays = (months[mIdx]?.days) || 31;
                const prevDay = parseInt(daySel.value) || (saved.day || 1);
                daySel.innerHTML = '';
                for (let d = 1; d <= maxDays; d++) {
                    const opt = document.createElement('option');
                    opt.value = d; opt.textContent = d;
                    if (d === prevDay) opt.selected = true;
                    daySel.appendChild(opt);
                }
            };
            populateDays();
            monthSel.addEventListener('change', () => { populateDays(); syncDate(); });
            
            // Rok
            const yearInp = document.createElement('input');
            yearInp.type = 'text';
            yearInp.placeholder = basePlaceholder || 'Rok';
            yearInp.value = saved.year !== undefined ? saved.year : defYear;
            yearInp.style.cssText = 'flex:1;min-width:70px;';
            
            const syncDate = () => {
                const obj = {
                    day: parseInt(daySel.value) || 1,
                    monthIndex: parseInt(monthSel.value) || 0,
                    monthName: months[parseInt(monthSel.value)]?.name || '',
                    year: yearInp.value.trim(),
                };
                if (eraSel) obj.era = eraSel.value;
                hiddenInput.value = JSON.stringify(obj);
            };
            
            daySel.addEventListener('change', syncDate);
            yearInp.addEventListener('input', syncDate);
            
            dateRow.appendChild(daySel);
            dateRow.appendChild(monthSel);
            dateRow.appendChild(yearInp);
            
            // Era (opcjonalna)
            let eraSel = null;
            if (eras.length > 0) {
                eraSel = document.createElement('select');
                eraSel.style.cssText = 'flex:1;min-width:80px;';
                eras.forEach(e => {
                    const opt = document.createElement('option');
                    opt.value = e; opt.textContent = e;
                    if (saved.era === e) opt.selected = true;
                    eraSel.appendChild(opt);
                });
                eraSel.addEventListener('change', syncDate);
                dateRow.appendChild(eraSel);
            }
            
            renderer.appendChild(dateRow);
            if (hiddenInput.value.trim() !== '') {
                syncDate();
            }
            break;
        }
        
        case 'image-gallery': {
            let images = [];
            try { images = hiddenInput.value ? JSON.parse(hiddenInput.value) : []; } catch(e){}
            const variantKey = variantKeyFromInputName(hiddenInput.name);
            
            const gallWrap = document.createElement('div');
            gallWrap.style.cssText = 'display:flex;flex-direction:column;gap:10px;';
            
            const thumbsContainer = document.createElement('div');
            thumbsContainer.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;';
            
            const syncGallery = () => { hiddenInput.value = images.length ? JSON.stringify(images) : ''; };
            
            const addThumb = (img) => {
                const thumb = document.createElement('div');
                thumb.style.cssText = 'position:relative;width:80px;height:80px;';
                
                const imgEl = document.createElement('img');
                imgEl.src = img.url;
                imgEl.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid var(--border,#ddd);';
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.style.cssText = 'position:absolute;top:2px;right:2px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:0.7rem;cursor:pointer;display:flex;align-items:center;justify-content:center;';
                removeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    images = images.filter(i => i.filename !== img.filename);
                    thumb.remove();
                    syncGallery();
                });
                
                thumb.appendChild(imgEl);
                thumb.appendChild(removeBtn);
                thumbsContainer.appendChild(thumb);
            };
            
            images.forEach(img => addThumb(img));
            syncGallery();
            
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = OC_IMAGE_ACCEPT;
            fileInput.multiple = true;
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `gallery-input-variant-${fieldId}`;
            if (variantKey) fileInput.name = `variant_field_gallery_files[${variantKey}][${fieldId}][]`;
            const tagsInput = document.createElement('input');
            tagsInput.type = 'hidden';
            if (variantKey) tagsInput.name = `variant_field_gallery_tags[${variantKey}][${fieldId}]`;
            
            const uploadBtn = document.createElement('label');
            uploadBtn.htmlFor = fileInput.id;
            uploadBtn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadBtn.innerHTML = `<i class="fa-solid fa-upload"></i> ${escapeHtml(ccText('uploadImages', 'Wgraj zdjecia'))}`;

            const galleryBtn = document.createElement('button');
            galleryBtn.type = 'button';
            galleryBtn.className = 'upload-label btn-secondary';
            galleryBtn.textContent = ccText('addFromGallery', 'Dodaj z galerii');
            galleryBtn.addEventListener('click', async () => {
                const asset = await chooseTemplateImageAsset();
                if (!asset) return;
                images.push(asset);
                addThumb(asset);
                syncGallery();
            });
            
            fileInput.addEventListener('change', () => {
                if (!fileInput.files.length) return;
                if (askDeferredImageTags(tagsInput) === null) {
                    fileInput.value = '';
                    return;
                }
                for (const file of fileInput.files) {
                    const img = { pendingUpload: true, url: URL.createObjectURL(file), filename: file.name };
                    images.push(img);
                    addThumb(img);
                    syncGallery();
                }
            });
            
            gallWrap.appendChild(thumbsContainer);
            gallWrap.appendChild(uploadBtn);
            gallWrap.appendChild(galleryBtn);
            gallWrap.appendChild(fileInput);
            gallWrap.appendChild(tagsInput);
            renderer.appendChild(gallWrap);
            break;
        }
        
        case 'image': {
            let savedImg = null;
            try { savedImg = hiddenInput.value ? JSON.parse(hiddenInput.value) : null; } catch(e){}
            const previewWidth = imageSizePercentFromConfig(fieldConfig);
            const variantKey = variantKeyFromInputName(hiddenInput.name);
            
            const imgWrap = document.createElement('div');
            imgWrap.className = 'template-image-field';
            imgWrap.style.cssText = 'display:flex;gap:10px;flex-direction:column;';
            
            const preview = document.createElement('img');
            preview.style.cssText = `width:${previewWidth};max-width:100%;height:auto;border-radius:6px;border:1px solid var(--border,#ddd);`;
            if (savedImg?.url) preview.src = savedImg.url;
            else preview.style.display = 'none';
            
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = OC_IMAGE_ACCEPT;
            fileInput.style.cssText = 'display:none;';
            fileInput.id = `image-input-variant-${fieldId}`;
            if (variantKey) fileInput.name = `variant_field_image_files[${variantKey}][${fieldId}]`;
            const tagsInput = document.createElement('input');
            tagsInput.type = 'hidden';
            if (variantKey) tagsInput.name = `variant_field_image_tags[${variantKey}][${fieldId}]`;
            
            const uploadLabel = document.createElement('label');
            uploadLabel.htmlFor = fileInput.id;
            uploadLabel.style.cssText = 'display:inline-flex;align-items:center;gap:6px;cursor:pointer;padding:7px 14px;border-radius:8px;border:1px dashed var(--primary,#3498db);color:var(--primary,#3498db);font-size:0.9rem;width:fit-content;';
            uploadLabel.innerHTML = `<i class="fa-solid fa-upload"></i> ${escapeHtml(ccText('uploadImage', 'Wgraj zdjecie'))}`;

            const galleryBtn = document.createElement('button');
            galleryBtn.type = 'button';
            galleryBtn.className = 'upload-label btn-secondary';
            galleryBtn.textContent = ccText('chooseFromGallery', 'Wybierz z galerii');
            galleryBtn.addEventListener('click', async () => {
                const asset = await chooseTemplateImageAsset();
                if (!asset) return;
                savedImg = asset;
                setCollapsed(false);
                preview.src = asset.url;
                preview.style.display = 'block';
                hiddenInput.value = JSON.stringify(asset);
            });
            
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    if (askDeferredImageTags(tagsInput) === null) {
                        fileInput.value = '';
                        return;
                    }
                    savedImg = { pendingUpload: true, filename: fileInput.files[0].name };
                    setCollapsed(false);
                    previewLocalImage(fileInput.files[0], preview);
                    hiddenInput.value = JSON.stringify(savedImg);
                }
            });

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-secondary image-remove-btn';
            removeBtn.textContent = ccText('removeImage', 'Usun zdjecie');
            removeBtn.addEventListener('click', () => {
                fileInput.value = '';
                tagsInput.value = '';
                preview.removeAttribute('src');
                preview.style.display = 'none';
                hiddenInput.value = '';
            });

            const hideBtn = document.createElement('button');
            hideBtn.type = 'button';
            hideBtn.className = 'image-hide-toggle';
            const storageKey = () => `oc-character-template-image-field-collapsed:${document.getElementById('form-template-id')?.value || window.currentCharacterTemplateId || 'without-template'}:${fieldId}`;
            const setCollapsed = (collapsed, persist = true) => {
                imgWrap.classList.toggle('is-image-field-collapsed', collapsed);
                preview.hidden = collapsed;
                hideBtn.textContent = collapsed ? ccText('revealImage', 'Odslon zdjecie') : ccText('hideImageTemp', 'Tymczasowo ukryj');
                hideBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                hideBtn.title = collapsed ? ccText('revealImageTitle', 'Odslon zdjecie w edytorze') : ccText('hideImageTitle', 'Tymczasowo ukryj zdjecie w edytorze');
                if (persist) localStorage.setItem(storageKey(), collapsed ? '1' : '0');
            };
            hideBtn.addEventListener('click', () => {
                setCollapsed(hideBtn.getAttribute('aria-expanded') === 'true');
            });
            setCollapsed(localStorage.getItem(storageKey()) === '1', false);

            const actionsWrap = document.createElement('div');
            actionsWrap.className = 'image-actions-row template-image-actions-row';
            actionsWrap.append(uploadLabel, galleryBtn, removeBtn, hideBtn);
            
            imgWrap.appendChild(preview);
            imgWrap.appendChild(actionsWrap);
            imgWrap.appendChild(fileInput);
            imgWrap.appendChild(tagsInput);
            renderer.appendChild(imgWrap);
            break;
        }
        
        case 'select': {
            let cfg = {};
            try { cfg = JSON.parse(fieldConfig || '{}'); } catch(e){}
            const options = Array.isArray(cfg.options) ? cfg.options : [];
            
            const sel = document.createElement('select');
            
            const defOpt = document.createElement('option');
            defOpt.value = '';
            defOpt.textContent = basePlaceholder ? ccText('baseValue', 'Bazowa: :value', { value: basePlaceholder }) : ccText('leaveEmptyForBase', 'Zostaw puste, aby uzyc wartosci bazowej');
            if (!hiddenInput.value) defOpt.selected = true;
            sel.appendChild(defOpt);
            
            options.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o;
                opt.textContent = o;
                if (hiddenInput.value === o) opt.selected = true;
                sel.appendChild(opt);
            });
            
            sel.addEventListener('change', () => {
                hiddenInput.value = sel.value;
            });
            
            renderer.appendChild(sel);
            break;
        }
        
        case 'list': {
            let lines = normalizeListItems(hiddenInput.value);
            if (lines.length === 0) lines = [''];
            
            const listWrap = document.createElement('div');
            listWrap.className = 'character-list-widget';
            
            const bulletContainer = document.createElement('div');
            bulletContainer.className = 'bullet-list-container-variant character-list-rows';
            
            const syncList = () => {
                const vals = [...bulletContainer.querySelectorAll('.bullet-input-variant')]
                    .map(i => i.value)
                    .filter(v => v.trim() !== '');
                hiddenInput.value = vals.length ? JSON.stringify(vals) : '';
                notifyFieldValueChanged(hiddenInput);
            };
            
            const addBulletInput = (value = '') => {
                const row = document.createElement('div');
                row.className = 'character-list-row';

                const bullet = document.createElement('span');
                bullet.className = 'character-list-bullet';
                bullet.textContent = '•';
                
                const inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'bullet-input-variant character-list-input';
                inp.value = value;
                inp.placeholder = basePlaceholder || 'Element listy...';
                inp.addEventListener('input', syncList);
                inp.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addBulletInput('');
                    }
                });
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'character-list-remove';
                removeBtn.title = ccText('removeItem', 'Usun element');
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    row.remove();
                    if (!bulletContainer.children.length) addBulletInput('');
                    syncList();
                });
                
                row.append(bullet, inp, removeBtn);
                bulletContainer.appendChild(row);
                return row;
            };
            
            lines.forEach(line => addBulletInput(line));
            if (lines.length === 0 || !lines[lines.length - 1]) {
                addBulletInput('');
            }
            
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn-secondary character-list-add';
            addBtn.innerHTML = `<i class="fa-solid fa-plus"></i><span>${escapeHtml(ccText('addItem', 'Dodaj element'))}</span>`;
            addBtn.addEventListener('click', () => {
                const row = addBulletInput('');
                row.querySelector('input')?.focus();
                syncList();
            });
            
            listWrap.append(bulletContainer, addBtn);
            renderer.appendChild(listWrap);
            if (lines.some(line => String(line || '').trim() !== '')) syncList();
            else hiddenInput.value = '';
            break;
        }
        
        case 'table': {
            let cfg = {};
            try { cfg = JSON.parse(fieldConfig || '{}'); } catch(e){}
            const rowDefs = normalizeTableRowsConfig(cfg);
            
            let savedRows = {};
            try { savedRows = hiddenInput.value ? JSON.parse(hiddenInput.value) : {}; } catch(e){}
            
            if (rowDefs.length === 0) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:var(--text-muted,#999);font-size:0.85rem;';
                empty.innerText = 'Ta tabela nie ma zdefiniowanych wierszy.';
                renderer.appendChild(empty);
                break;
            }
            
            const tableWrap = document.createElement('div');
            tableWrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;overflow:hidden;';
            let initializingInheritedTable = !hiddenInput.value;
            const setVariantCell = (rowDef, value) => {
                if (initializingInheritedTable) return;
                savedRows[rowDef.key || rowDef.label] = { type: rowDef.type, value };
                hiddenInput.value = JSON.stringify(savedRows);
                notifyFieldValueChanged(hiddenInput);
            };
            
            rowDefs.forEach((rowDef, idx) => {
                const row = document.createElement('div');
                row.style.cssText = `display:flex;align-items:stretch;${idx < rowDefs.length-1 ? 'border-bottom:1px solid var(--border,#ddd);' : ''}`;
                
                const nameCell = document.createElement('div');
                nameCell.style.cssText = 'flex:0 0 38%;padding:8px 12px;background:var(--surface-alt,#f5f5f5);font-size:0.88rem;font-weight:600;color:var(--text,#333);display:flex;align-items:center;border-right:1px solid var(--border,#ddd);word-break:break-word;';
                nameCell.innerText = rowDef.label;
                
                const valueCell = document.createElement('div');
                valueCell.style.cssText = 'flex:1;display:flex;align-items:stretch;';

                const savedCell = tableSavedCell(savedRows, rowDef);
                const effectiveRowDef = rowDef.type === 'text' && isSerializedListValue(savedCell)
                    ? { ...rowDef, type: 'list' }
                    : rowDef;
                const savedValue = tableCellValue(savedCell);

                if (effectiveRowDef.type === 'date') {
                    valueCell.appendChild(createDateTableWidget(templateDateConfigFromField({ placeholder: fieldConfig }), savedValue, value => setVariantCell(effectiveRowDef, value)));
                } else if (effectiveRowDef.type === 'image') {
                    valueCell.appendChild(createImageTableWidget(savedValue, value => setVariantCell(effectiveRowDef, value), fieldId, effectiveRowDef.key || effectiveRowDef.label));
                } else if (effectiveRowDef.type === 'list') {
                    valueCell.appendChild(createListTableWidget(savedValue, value => setVariantCell(effectiveRowDef, value)));
                } else if (effectiveRowDef.type === 'select') {
                    valueCell.appendChild(createSelectTableWidget(effectiveRowDef, savedValue, value => setVariantCell(effectiveRowDef, value)));
                } else if (effectiveRowDef.type === 'age') {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    inp.readOnly = true;
                    inp.dataset.rowKey = effectiveRowDef.key || effectiveRowDef.label;
                    inp.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';
                    inp.value = readableVariantBaseValue(savedValue);
                    valueCell.appendChild(inp);
                } else {
                    const inp = document.createElement('input');
                    inp.type = 'text';
                    inp.placeholder = readableVariantBaseValue(tableFindSourceCell(parseVariantJsonObject(baseValue), rowDefs, effectiveRowDef.key || effectiveRowDef.label)) || ccText('valuePlaceholder', 'Wpisz wartosc...');
                    inp.dataset.rowKey = effectiveRowDef.key || effectiveRowDef.label;
                    inp.style.cssText = 'width:100%;border:none;outline:none;padding:8px 12px;background:transparent;font-size:0.9rem;color:var(--text,#333);';
                    if (savedValue !== undefined && savedValue !== null) inp.value = savedValue;
                    inp.addEventListener('input', () => setVariantCell(effectiveRowDef, inp.value));
                    valueCell.appendChild(inp);
                }

                row.appendChild(nameCell);
                row.appendChild(valueCell);
                tableWrap.appendChild(row);
            });
            initializingInheritedTable = false;
            
            renderer.appendChild(tableWrap);
            break;
        }
        
        case 'textarea':
        case 'text':
        default: {
            const ta = document.createElement('textarea');
            ta.rows = 2;
            ta.style.cssText = 'width:100%;border:1px solid var(--border,#ddd);border-radius:6px;padding:8px;font-family:inherit;';
            ta.placeholder = basePlaceholder ? ccText('baseValue', 'Bazowa: :value', { value: basePlaceholder }) : ccText('leaveEmptyForBase', 'Zostaw puste, aby uzyc wartosci bazowej');
            ta.value = hiddenInput.value;
            ta.addEventListener('input', () => {
                hiddenInput.value = ta.value;
            });
            renderer.appendChild(ta);
        }
    }
}

// -------------------------------------------------------
// Inicjalizacja wariantów po załadowaniu DOM
// -------------------------------------------------------
function initializeVariantFields() {
    document.querySelectorAll('[data-variant-field]').forEach(fieldWrapper => {
        buildVariantFieldWidget(fieldWrapper);
    });
}
