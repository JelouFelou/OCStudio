let nextFieldId = 0;
let activeStoryFieldItem = null;
let storyQuickSaveTimer = null;

const STORY_FIELD_TYPES = {
    text: { icon: 'fa-font', label: 'Tekst', hint: 'Krotka pojedyncza wartosc.' },
    textarea: { icon: 'fa-align-left', label: 'Dlugi tekst', hint: 'Wiekszy fragment narracji.' },
    image: { icon: 'fa-image', label: 'Zdjecie', hint: 'Ilustracja albo scena.' },
    dialog: { icon: 'fa-comments', label: 'Dialog', hint: 'Wypowiedz w stylu sceny.' },
    section: { icon: 'fa-heading', label: 'Sekcja', hint: 'Naglowek rozdzialu lub aktu.' },
};

function storyDefaultCharacterImage() {
    return document.body?.dataset.theme === 'dark' ? 'default_dark.png' : 'default.png';
}

function storyUploadSrc(filename, fallback = storyDefaultCharacterImage()) {
    const clean = String(filename || '').split('/').pop();
    const resolved = !clean || ['default.png', 'default.jpg', 'default_dark.png'].includes(clean) ? fallback : clean;
    return '/public/uploads/' + resolved;
}

function storyCharacterImageVars(character = {}) {
    const focusX = Number(character.imageFocusX ?? character.character_image_focus_x ?? 50);
    const focusY = Number(character.imageFocusY ?? character.character_image_focus_y ?? 50);
    const zoom = Number(character.imageZoom ?? character.character_image_zoom ?? 1);
    const fit = character.imageFit || character.character_image_fit || 'cover';
    return `--image-focus-x:${Math.max(0, Math.min(100, focusX))}%;--image-focus-y:${Math.max(0, Math.min(100, focusY))}%;--image-zoom:${Math.max(0.2, zoom || 1)};--image-fit:${escapeHtml(fit)};`;
}

function storyPseudonymSourceSelect(characterId, selectedFieldId = '', variantId = 0) {
    const sources = (window.storyPseudonymSources || {})[characterId] || [];
    if (!sources.length) {
        return '<div class="story-pseudonym-source-empty">Brak pola pseudonimow</div>';
    }

    return `
        <select class="story-pseudonym-source-select" onchange="updateStoryCharacterPseudonymSource(${Number(characterId)}, this.value, ${Number(variantId) || 0})">
            <option value="">Nazwa postaci</option>
            ${sources.map(source => {
                const preview = Array.isArray(source.pseudonyms) && source.pseudonyms.length
                    ? ' - ' + source.pseudonyms.slice(0, 3).join(', ')
                    : '';
                return `<option value="${Number(source.id)}" ${String(selectedFieldId || '') === String(source.id) ? 'selected' : ''}>${escapeHtml(source.label || source.type)}${escapeHtml(preview)}</option>`;
            }).join('')}
        </select>`;
}

function storyFieldItems(container = document.getElementById('story-fields-container')) {
    return container ? [...container.querySelectorAll(':scope > .story-field-item')] : [];
}

function openStoryFieldModal(anchor = null, insertIndex = null) {
    openStoryFieldTypePopover(anchor, insertIndex);
}

function closeStoryFieldModal() {
    closeStoryFieldTypePopover();
}

function scrollStoryEditor(direction = 'top') {
    const scroller = document.querySelector('body[data-view="edit_story"] .main-content') || document.scrollingElement || document.documentElement;
    const top = direction === 'bottom' ? scroller.scrollHeight : 0;
    scroller.scrollTo({ top, behavior: 'smooth' });
}

function setStoryQuickSaveState(state, message = '') {
    const button = document.querySelector('.story-quick-save-btn');
    if (!button) return;
    button.classList.remove('is-saving', 'is-saved', 'is-error');
    if (state) button.classList.add(`is-${state}`);
    button.title = message || 'Szybki zapis';
    button.setAttribute('aria-label', message || 'Szybki zapis historii');
    window.clearTimeout(storyQuickSaveTimer);
    if (state === 'saved' || state === 'error') {
        storyQuickSaveTimer = window.setTimeout(() => {
            button.classList.remove('is-saved', 'is-error');
            button.title = 'Szybki zapis';
            button.setAttribute('aria-label', 'Szybki zapis historii');
        }, 1800);
    }
}

async function quickSaveStory() {
    const form = document.getElementById('story-form');
    if (!form || form.dataset.quickSaving === '1') return;

    form.dataset.quickSaving = '1';
    setStoryQuickSaveState('saving', 'Zapisywanie...');

    const data = new FormData(form);
    data.set('quick_save', '1');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.success === false) {
            throw new Error(payload.message || 'Nie udalo sie zapisac historii.');
        }
        setStoryQuickSaveState('saved', 'Zapisano');
    } catch (error) {
        setStoryQuickSaveState('error', error.message || 'Nie udalo sie zapisac');
    } finally {
        form.dataset.quickSaving = '0';
    }
}

function createStoryFieldTypePopover() {
    let popover = document.getElementById('story-field-type-popover');
    if (popover) return popover;

    popover = document.createElement('div');
    popover.id = 'story-field-type-popover';
    popover.className = 'story-field-type-popover';
    popover.hidden = true;
    popover.innerHTML = `
        <div class="story-field-type-popover-grid">
            ${Object.entries(STORY_FIELD_TYPES).map(([type, meta]) => `
                <button type="button" class="story-field-type-option" data-story-field-type="${type}" title="${escapeHtml(meta.hint)}">
                    <i class="fa-solid ${meta.icon}"></i>
                    <span>${meta.label}</span>
                </button>
            `).join('')}
        </div>`;
    document.body.appendChild(popover);

    popover.addEventListener('click', event => {
        const option = event.target.closest('[data-story-field-type]');
        if (!option) return;
        addStoryField(option.dataset.storyFieldType, Number(popover.dataset.insertIndex || -1));
        closeStoryFieldTypePopover();
    });

    document.addEventListener('click', event => {
        if (popover.hidden) return;
        if (event.target.closest('#story-field-type-popover, .story-field-insert-btn')) return;
        closeStoryFieldTypePopover();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeStoryFieldTypePopover();
    });

    return popover;
}

function openStoryFieldTypePopover(anchor = null, insertIndex = null) {
    const popover = createStoryFieldTypePopover();
    const container = document.getElementById('story-fields-container');
    const items = storyFieldItems(container);
    const fallbackIndex = activeStoryFieldItem && items.includes(activeStoryFieldItem)
        ? items.indexOf(activeStoryFieldItem) + 1
        : items.length;
    popover.dataset.insertIndex = String(Number.isInteger(insertIndex) && insertIndex >= 0 ? insertIndex : fallbackIndex);
    popover.hidden = false;

    const anchorRect = anchor?.getBoundingClientRect?.();
    const popoverRect = popover.getBoundingClientRect();
    const top = anchorRect
        ? anchorRect.bottom + window.scrollY + 8
        : window.scrollY + 120;
    const preferredLeft = anchorRect
        ? anchorRect.left + window.scrollX + (anchorRect.width / 2) - (popoverRect.width / 2)
        : window.scrollX + 24;
    const left = Math.max(12 + window.scrollX, Math.min(preferredLeft, window.scrollX + window.innerWidth - popoverRect.width - 12));
    popover.style.top = `${top}px`;
    popover.style.left = `${left}px`;
}

function closeStoryFieldTypePopover() {
    const popover = document.getElementById('story-field-type-popover');
    if (popover) popover.hidden = true;
}

function addStoryField(fieldType = 'text', insertIndex = null) {
    if (!STORY_FIELD_TYPES[fieldType]) fieldType = 'text';

    const container = document.getElementById('story-fields-container');
    if (!container) return;

    const currentItems = storyFieldItems(container);
    const activeIndex = activeStoryFieldItem && currentItems.includes(activeStoryFieldItem)
        ? currentItems.indexOf(activeStoryFieldItem) + 1
        : currentItems.length;
    const targetIndex = Number.isInteger(insertIndex) && insertIndex >= 0 ? insertIndex : activeIndex;

    container.querySelectorAll('.story-field-insert-point').forEach(point => point.remove());
    if (container.querySelector('p')) container.innerHTML = '';

    const fieldId = 'new_' + Date.now().toString(36) + '_' + (++nextFieldId);
    const div = document.createElement('div');
    div.innerHTML = createFieldHTML(fieldId, '', fieldType);
    const field = div.firstElementChild;
    const items = storyFieldItems(container);
    const before = items[Math.min(targetIndex, items.length)] || null;
    before ? container.insertBefore(field, before) : container.appendChild(field);
    setActiveStoryField(field);
    autosizeStoryTextareas(field);
    bindStoryImagePickers(field);
    refreshStoryFieldMoveButtons(container);
    refreshStoryInsertPoints(container);
    field.querySelector('.story-field-input:not([type="hidden"])')?.focus();
}

function autosizeStoryTextarea(textarea) {
    if (!textarea || textarea.tagName !== 'TEXTAREA') return;
    const styles = window.getComputedStyle(textarea);
    const borderSize = parseFloat(styles.borderTopWidth || '0') + parseFloat(styles.borderBottomWidth || '0');
    const minHeight = parseFloat(styles.minHeight || '0') || 56;
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.max(Math.ceil(textarea.scrollHeight + borderSize), minHeight)}px`;
}

function scheduleAutosizeStoryTextarea(textarea) {
    window.requestAnimationFrame(() => autosizeStoryTextarea(textarea));
}

function autosizeStoryTextareas(root = document) {
    root.querySelectorAll('textarea.story-field-input').forEach(textarea => {
        scheduleAutosizeStoryTextarea(textarea);
        if (textarea.dataset.storyAutosizeBound) return;
        textarea.dataset.storyAutosizeBound = '1';
        textarea.addEventListener('input', () => scheduleAutosizeStoryTextarea(textarea));
        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(() => scheduleAutosizeStoryTextarea(textarea));
            observer.observe(textarea.parentElement || textarea);
            textarea._storyAutosizeObserver = observer;
        }
    });
}

function bindStoryImagePickers(root = document) {
    root.querySelectorAll('.story-field-image-row button, [data-story-image-picker]').forEach(button => {
        if (button.dataset.boundStoryImagePicker) return;
        const row = button.closest('.story-field-image-row');
        const input = row?.querySelector('input.story-field-input[type="hidden"]');
        if (!input) return;
        button.dataset.boundStoryImagePicker = '1';
        button.addEventListener('click', async () => {
            const asset = await window.OCImageTools?.openImagePicker({ allowUpload: true });
            if (!asset) return;
            input.value = asset.filename || '';
            const preview = row.querySelector('.story-field-image-preview');
            const previewImage = preview?.querySelector('img');
            if (preview && previewImage) {
                previewImage.src = asset.url;
                preview.hidden = false;
            }
            button.textContent = asset.filename ? `Zdjecie: ${asset.filename}` : 'Wybierz zdjecie';
        });
    });
}

function syncStoryImagePreviewFromInput(input) {
    const row = input?.closest('.story-field-image-row');
    if (!row) return;
    const value = String(input.value || '').split('/').pop();
    const preview = row.querySelector('.story-field-image-preview');
    const previewImage = preview?.querySelector('img');
    const button = row.querySelector('[data-story-image-picker]');
    if (preview && previewImage && value) {
        previewImage.src = `/public/uploads/${value}`;
        preview.hidden = false;
    }
    if (button) button.textContent = value ? `Zdjecie: ${value}` : 'Wybierz zdjecie';
}

function storyImageEditorHTML(fieldId, value = '') {
    const label = value ? `Zdjecie: ${escapeHtml(String(value).split('/').pop())}` : 'Wybierz zdjecie';
    const src = value ? `/public/uploads/${escapeHtml(String(value).split('/').pop())}` : '';
    return `<div class="story-field-image-row">
            <div class="story-field-image-preview"${src ? '' : ' hidden'}>
                <img src="${src}" alt="">
            </div>
            <button type="button" class="btn-secondary" data-story-image-picker style="flex:1;">${label}</button>
            <input type="hidden" name="story_fields[${fieldId}]" class="story-field-input" value="${escapeHtml(value)}">
        </div>`;
}

function createFieldHTML(fieldId, label, fieldType) {
    const meta = STORY_FIELD_TYPES[fieldType] || STORY_FIELD_TYPES.text;
    let editorHTML = '';

    if (fieldType === 'text') {
        editorHTML = `<input type="text" name="story_fields[${fieldId}]" class="story-field-input" placeholder="Tekst...">`;
    } else if (fieldType === 'textarea') {
        editorHTML = `<textarea name="story_fields[${fieldId}]" class="story-field-input" placeholder="Dlugi tekst..." rows="5"></textarea>`;
    } else if (fieldType === 'image') {
        editorHTML = storyImageEditorHTML(fieldId);
    } else if (fieldType === 'dialog') {
        editorHTML = `<div class="story-dialog-editor">
            <textarea name="story_fields[${fieldId}]" class="story-field-input" placeholder="Dialog postaci..." rows="5"></textarea>
        </div>`;
    } else if (fieldType === 'section') {
        editorHTML = `<input type="text" name="story_fields[${fieldId}]" class="story-field-input story-section-input" placeholder="Nazwa sekcji...">`;
    }

    return `
        <div class="story-field-item story-schema-field draggable" data-field-id="${fieldId}" tabindex="0">
            <i class="fa-solid fa-grip-vertical drag-handle" aria-hidden="true" draggable="true"></i>
            <div class="field-content">
                <input type="text" class="story-field-label-input" name="story_field_labels[${fieldId}]" value="${escapeHtml(label)}" placeholder="Naglowek/etykieta pola">
                <input type="hidden" name="story_field_types[${fieldId}]" value="${escapeHtml(fieldType)}">
                <div class="type-preview-tag story-type-preview-tag">
                    <small><i class="fa-solid ${meta.icon}"></i> Typ: ${meta.label}</small>
                </div>
                <div class="story-field-editor">${editorHTML}</div>
            </div>
            <div class="story-field-actions">
                <button type="button" class="story-field-move-btn" onclick="moveStoryField(this, -1)" title="Przesun wyzej">
                    <i class="fa-solid fa-chevron-up"></i>
                </button>
                <button type="button" class="story-field-move-btn" onclick="moveStoryField(this, 1)" title="Przesun nizej">
                    <i class="fa-solid fa-chevron-down"></i>
                </button>
                <button type="button" class="story-field-tool-btn" onclick="duplicateStoryField(this)" title="Duplikuj pole">
                    <i class="fa-solid fa-copy"></i>
                </button>
                <button type="button" class="story-field-tool-btn" onclick="toggleStoryFieldCollapse(this)" title="Zwin pole">
                    <i class="fa-solid fa-minimize"></i>
                </button>
                <button type="button" class="delete-field-btn" onclick="removeStoryField(this)" title="Usun pole">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>`;
}

function storyFieldValue(item) {
    const input = item?.querySelector('.story-field-input');
    return input ? String(input.value || '') : '';
}

function storyFieldSummary(item) {
    const value = storyFieldValue(item).replace(/\s+/g, ' ').trim();
    const type = detectExistingFieldType(item);
    if (type === 'image') {
        return value ? `Zdjecie: ${value.split('/').pop()}` : 'Puste zdjecie';
    }
    return value ? value.slice(0, 140) : 'Puste pole';
}

function refreshStoryFieldCollapseSummary(item) {
    const summary = item?.querySelector('.story-field-collapse-summary');
    if (summary) summary.textContent = storyFieldSummary(item);
}

function duplicateStoryField(btn) {
    const item = btn.closest('.story-field-item');
    const container = item?.parentElement;
    if (!item || !container) return;

    const items = storyFieldItems(container);
    const index = items.indexOf(item);
    const fieldType = detectExistingFieldType(item);
    const label = item.querySelector('.story-field-label-input')?.value || '';
    const value = storyFieldValue(item);
    const fieldId = 'new_' + Date.now().toString(36) + '_' + (++nextFieldId);
    const wrapper = document.createElement('div');
    wrapper.innerHTML = createFieldHTML(fieldId, label, fieldType);
    const clone = wrapper.firstElementChild;
    const input = clone.querySelector('.story-field-input');
    if (input) {
        input.value = value;
        syncStoryImagePreviewFromInput(input);
    }

    container.querySelectorAll('.story-field-insert-point').forEach(point => point.remove());
    const before = items[index + 1] || null;
    before ? container.insertBefore(clone, before) : container.appendChild(clone);
    setActiveStoryField(clone);
    autosizeStoryTextareas(clone);
    bindStoryImagePickers(clone);
    refreshStoryFieldMoveButtons(container);
    refreshStoryInsertPoints(container);
    clone.querySelector('.story-field-input:not([type="hidden"])')?.focus();
    clone.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function toggleStoryFieldCollapse(btn) {
    const item = btn.closest('.story-field-item');
    if (!item) return;
    let summary = item.querySelector('.story-field-collapse-summary');
    if (!summary) {
        summary = document.createElement('button');
        summary.type = 'button';
        summary.className = 'story-field-collapse-summary';
        summary.addEventListener('click', () => toggleStoryFieldCollapse(btn));
        item.querySelector('.field-content')?.appendChild(summary);
    }

    const collapsed = !item.classList.contains('is-story-field-collapsed');
    if (collapsed) refreshStoryFieldCollapseSummary(item);
    item.classList.toggle('is-story-field-collapsed', collapsed);
    btn.title = collapsed ? 'Rozwin pole' : 'Zwin pole';
    btn.innerHTML = collapsed
        ? '<i class="fa-solid fa-expand"></i>'
        : '<i class="fa-solid fa-minimize"></i>';
}

function moveStoryField(btn, direction) {
    const item = btn.closest('.story-field-item');
    const container = item?.parentElement;
    if (!item || !container) return;

    container.querySelectorAll('.story-field-insert-point').forEach(point => point.remove());
    const items = storyFieldItems(container);
    const index = items.indexOf(item);

    if (direction < 0 && index > 0) {
        container.insertBefore(item, items[index - 1]);
    } else if (direction > 0 && index >= 0 && index < items.length - 1) {
        container.insertBefore(items[index + 1], item);
    }

    refreshStoryFieldMoveButtons(container);
    refreshStoryInsertPoints(container);
    autosizeStoryTextareas(item);
    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function refreshStoryFieldMoveButtons(container = document.getElementById('story-fields-container')) {
    if (!container) return;
    const items = [...container.querySelectorAll('.story-field-item')];
    items.forEach((item, index) => {
        const moveButtons = item.querySelectorAll('.story-field-move-btn');
        const up = moveButtons[0];
        const down = moveButtons[1];
        if (up) up.disabled = index === 0;
        if (down) down.disabled = index === items.length - 1;
    });
}

function setActiveStoryField(item) {
    if (!item || !item.classList?.contains('story-field-item')) return;
    activeStoryFieldItem?.classList.remove('is-active-story-field');
    activeStoryFieldItem = item;
    activeStoryFieldItem.classList.add('is-active-story-field');
}

function createStoryInsertPoint(index) {
    const point = document.createElement('div');
    point.className = 'story-field-insert-point';
    point.innerHTML = `
        <button type="button" class="story-field-insert-btn" data-story-insert-index="${index}" title="Dodaj pole w tym miejscu">
            <i class="fa-solid fa-plus"></i>
        </button>`;
    return point;
}

function refreshStoryInsertPoints(container = document.getElementById('story-fields-container')) {
    if (!container) return;
    container.querySelectorAll('.story-field-insert-point').forEach(point => point.remove());
    const items = storyFieldItems(container);
    if (!items.length) {
        container.appendChild(createStoryInsertPoint(0));
        return;
    }
    items.forEach((item, index) => {
        item.parentElement.insertBefore(createStoryInsertPoint(index), item);
    });
    container.appendChild(createStoryInsertPoint(items.length));
}

function storyFieldHasContent(item) {
    if (!item) return false;
    return [...item.querySelectorAll('.story-field-input')].some(input => {
        if (input.type === 'file') return input.files && input.files.length > 0;
        return String(input.value || '').trim() !== '';
    });
}

function removeStoryField(btn) {
    const item = btn.closest('.story-field-item');
    if (storyFieldHasContent(item) && !confirm('Czy na pewno chcesz usunac to pole?')) return;
    const fieldId = item?.dataset.fieldId || '';
    if (fieldId && !fieldId.startsWith('new_')) {
        const form = document.getElementById('story-form');
        if (form && !form.querySelector(`input[name="story_deleted_fields[]"][value="${CSS.escape(fieldId)}"]`)) {
            const deletedInput = document.createElement('input');
            deletedInput.type = 'hidden';
            deletedInput.name = 'story_deleted_fields[]';
            deletedInput.value = fieldId;
            form.appendChild(deletedInput);
        }
    }
    item?.remove();

    const container = document.getElementById('story-fields-container');
    container?.querySelectorAll('.story-field-insert-point').forEach(point => point.remove());
    if (container && storyFieldItems(container).length === 0) {
        container.innerHTML = '<p class="story-empty-note">Brak pol. Dodaj pierwsze pole aby zaczac pisanie historii.</p>';
    }
    refreshStoryFieldMoveButtons(container);
    refreshStoryInsertPoints(container);
}

function fieldTypeMeta(type) {
    return STORY_FIELD_TYPES[type] || STORY_FIELD_TYPES.text;
}

function detectExistingFieldType(item) {
    if (item.dataset.fieldType && STORY_FIELD_TYPES[item.dataset.fieldType]) return item.dataset.fieldType;
    const tag = item.querySelector('[data-story-field-type]');
    if (tag?.dataset.storyFieldType) return tag.dataset.storyFieldType;
    const typeText = item.querySelector('strong')?.textContent?.trim();
    return STORY_FIELD_TYPES[typeText] ? typeText : 'text';
}

function enhanceExistingStoryFields() {
    document.querySelectorAll('#story-fields-container .story-field-item').forEach(item => {
        if (item.classList.contains('story-schema-field')) return;

        const fieldId = item.dataset.fieldId;
        const label = item.dataset.fieldLabel || item.querySelector('[data-field-label-text]')?.textContent?.trim() || '';
        const type = detectExistingFieldType(item);
        const meta = fieldTypeMeta(type);
        const valueInput = item.querySelector('.story-field-input');
        const editor = valueInput?.closest('div[style*="margin-top"], .story-field-editor')
            || valueInput?.parentElement;
        const editorHtml = type === 'image'
            ? storyImageEditorHTML(fieldId, valueInput?.value || '')
            : (editor ? editor.innerHTML : '');

        item.className = 'story-field-item story-schema-field draggable';
        item.removeAttribute('style');
        item.tabIndex = 0;
        item.draggable = false;
        item.innerHTML = `
            <i class="fa-solid fa-grip-vertical drag-handle" aria-hidden="true" draggable="true"></i>
            <div class="field-content">
                <input type="text" class="story-field-label-input" name="story_field_labels[${fieldId}]" value="${escapeHtml(label)}" placeholder="Naglowek/etykieta pola">
                <input type="hidden" name="story_field_types[${fieldId}]" value="${escapeHtml(type)}">
                <div class="type-preview-tag story-type-preview-tag">
                    <small><i class="fa-solid ${meta.icon}"></i> Typ: ${meta.label}</small>
                </div>
                <div class="story-field-editor">${editorHtml}</div>
            </div>
            <div class="story-field-actions">
                <button type="button" class="story-field-move-btn" onclick="moveStoryField(this, -1)" title="Przesun wyzej">
                    <i class="fa-solid fa-chevron-up"></i>
                </button>
                <button type="button" class="story-field-move-btn" onclick="moveStoryField(this, 1)" title="Przesun nizej">
                    <i class="fa-solid fa-chevron-down"></i>
                </button>
                <button type="button" class="story-field-tool-btn" onclick="duplicateStoryField(this)" title="Duplikuj pole">
                    <i class="fa-solid fa-copy"></i>
                </button>
                <button type="button" class="story-field-tool-btn" onclick="toggleStoryFieldCollapse(this)" title="Zwin pole">
                    <i class="fa-solid fa-minimize"></i>
                </button>
                <button type="button" class="delete-field-btn" onclick="removeStoryField(this)" title="Usun pole">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>`;
    });
    autosizeStoryTextareas(document.getElementById('story-fields-container') || document);
    bindStoryImagePickers(document.getElementById('story-fields-container') || document);
    refreshStoryFieldMoveButtons();
    refreshStoryInsertPoints();
}

function getDragAfterStoryField(container, y) {
    const items = [...container.querySelectorAll('.story-field-item:not(.dragging)')];
    return items.reduce((closest, item) => {
        const box = item.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        return offset < 0 && offset > closest.offset ? { offset, element: item } : closest;
    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
}

function initStoryFieldDragDrop() {
    const container = document.getElementById('story-fields-container');
    if (!container) return;

    container.querySelectorAll('.story-field-item').forEach(item => {
        item.draggable = false;
        item.querySelector('.drag-handle')?.setAttribute('draggable', 'true');
    });

    container.addEventListener('dragstart', event => {
        const handle = event.target.closest('.drag-handle');
        if (!handle) {
            event.preventDefault();
            return;
        }
        const item = handle.closest('.story-field-item');
        if (!item) return;
        event.dataTransfer.effectAllowed = 'move';
        item.classList.add('dragging');
    });

    container.addEventListener('dragend', event => {
        event.target.closest('.story-field-item')?.classList.remove('dragging');
        refreshStoryFieldMoveButtons(container);
        refreshStoryInsertPoints(container);
    });

    container.addEventListener('dragover', event => {
        event.preventDefault();
        const dragging = container.querySelector('.story-field-item.dragging');
        if (!dragging) return;
        const after = getDragAfterStoryField(container, event.clientY);
        after ? container.insertBefore(dragging, after) : container.appendChild(dragging);
    });

    refreshStoryFieldMoveButtons(container);
    refreshStoryInsertPoints(container);
}

function initStoryFieldFlowControls() {
    const container = document.getElementById('story-fields-container');
    if (!container) return;

    container.addEventListener('focusin', event => {
        const item = event.target.closest('.story-field-item');
        if (item) setActiveStoryField(item);
    });

    container.addEventListener('click', event => {
        const insertButton = event.target.closest('.story-field-insert-btn');
        if (insertButton) {
            openStoryFieldTypePopover(insertButton, Number(insertButton.dataset.storyInsertIndex || 0));
            return;
        }
        const item = event.target.closest('.story-field-item');
        if (item) setActiveStoryField(item);
    });

    function focusStoryFieldByOffset(offset) {
        const items = storyFieldItems(container);
        const index = activeStoryFieldItem && items.includes(activeStoryFieldItem)
            ? items.indexOf(activeStoryFieldItem)
            : -1;
        if (index < 0) {
            const first = items[0];
            if (!first) return false;
            setActiveStoryField(first);
            const focusTarget = first.querySelector('.story-field-input:not([type="hidden"])') || first;
            focusTarget.focus();
            first.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return true;
        }
        const next = items[index + offset];
        if (!next) return false;
        setActiveStoryField(next);
        const focusTarget = next.querySelector('.story-field-input:not([type="hidden"])') || next;
        focusTarget.focus();
        next.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return true;
    }

    function addStoryFieldAfterActive(fieldType) {
        const items = storyFieldItems(container);
        const insertIndex = activeStoryFieldItem && items.includes(activeStoryFieldItem)
            ? items.indexOf(activeStoryFieldItem) + 1
            : items.length;
        addStoryField(fieldType, insertIndex);
    }

    document.addEventListener('keydown', event => {
        const target = event.target;
        const isTextControl = target?.matches?.('input, textarea, select, [contenteditable="true"]');
        const isButtonControl = target?.matches?.('button, a[href]');
        const activeInsideStory = activeStoryFieldItem && container.contains(activeStoryFieldItem);

        if (activeInsideStory && event.shiftKey && !event.ctrlKey && !event.metaKey && !event.altKey && !isButtonControl) {
            const shortcutTypes = {
                F: 'textarea',
                D: 'dialog',
                Z: 'image',
            };
            const fieldType = shortcutTypes[event.key.toUpperCase()];
            if (fieldType) {
                event.preventDefault();
                addStoryFieldAfterActive(fieldType);
            }
            return;
        }

        if ((event.key === 'ArrowUp' || event.key === 'ArrowDown') && event.ctrlKey && !isButtonControl) {
            const moved = focusStoryFieldByOffset(event.key === 'ArrowUp' ? -1 : 1);
            if (moved) event.preventDefault();
            return;
        }

        if (event.key === 'Enter' && event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && !isButtonControl) {
            event.preventDefault();
            addStoryFieldAfterActive('textarea');
            return;
        }

        if (!activeInsideStory) return;

        if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
        if (isButtonControl) return;
        if (target?.matches?.('textarea, select, [contenteditable="true"]')) return;

        if (isTextControl) {
            event.preventDefault();
            return;
        }
    });
}

function openCharacterSelector() {
    const modal = document.getElementById('character-selector-modal');
    const list = document.getElementById('character-list');
    if (!modal || !list) return;

    const selectedIds = new Set([...document.querySelectorAll('#story-characters-container [data-character-id]')]
        .map(item => `${item.dataset.characterId}:${item.dataset.variantId || ''}`));
    const characters = Array.isArray(window.availableCharacters) ? window.availableCharacters : [];

    list.innerHTML = characters.length
        ? characters.map(character => {
            const id = String(character.id);
            const choices = [{ id: '', name: character.name || 'Postac', image: character.image, meta: character }]
                .concat((character.variants || []).map(variant => ({
                    id: String(variant.id),
                    name: variant.name || 'Wariant',
                    image: variant.image || character.image,
                    meta: { ...character, ...variant, image: variant.image || character.image }
                })));
            return choices.map(choice => {
                const key = `${id}:${choice.id}`;
                const disabled = selectedIds.has(key);
                const label = choice.id ? `${character.name || 'Postac'} - ${choice.name}` : (character.name || 'Postac');
                return `
                <button type="button" class="story-character-choice ${disabled ? 'is-selected' : ''}"
                        ${disabled ? 'disabled' : ''}
                        style="${storyCharacterImageVars(choice.meta)}"
                        onclick="selectCharacter(${Number(character.id)}, '${escapeJs(label)}', ${choice.id ? Number(choice.id) : 0})">
                    <img src="${storyUploadSrc(choice.image)}" alt="${escapeHtml(label)}">
                    <span>${escapeHtml(label)}</span>
                    ${disabled ? '<small>Juz dodana</small>' : ''}
                </button>`;
            }).join('');
        }).join('')
        : '<p class="story-empty-note">Brak dostepnych postaci.</p>';

    modal.style.display = 'flex';
}

function closeCharacterSelector() {
    const modal = document.getElementById('character-selector-modal');
    if (modal) modal.style.display = 'none';
}

function selectCharacter(characterId, characterName, variantId = 0) {
    const storyId = window.storyId;
    const container = document.getElementById('story-characters-container');
    if (!container) return;
    const variantKey = Number(variantId) || '';

    if (container.querySelector(`[data-character-id="${characterId}"][data-variant-id="${variantKey}"]`)) {
        alert('Ta wersja postaci jest juz w historii!');
        return;
    }

    if (!storyId) {
        if (container.querySelector('p')) container.innerHTML = '';
        container.appendChild(buildStoryCharacterChip(characterId, characterName, variantId));
        closeCharacterSelector();
        return;
    }

    fetch('/addCharacterToStory', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `story_id=${encodeURIComponent(storyId)}&character_id=${encodeURIComponent(characterId)}&variant_id=${encodeURIComponent(variantId || '')}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('Blad: ' + (data.message || 'Nie udalo sie dodac postaci'));
            return;
        }

        if (container.querySelector('p')) container.innerHTML = '';
        container.appendChild(buildStoryCharacterChip(characterId, characterName, variantId));
        closeCharacterSelector();
    })
    .catch(e => alert('Blad: ' + e.message));
}

function buildStoryCharacterChip(characterId, characterName, variantId = 0) {
    const character = (window.availableCharacters || []).find(item => Number(item.id) === Number(characterId)) || {};
    const variant = (character.variants || []).find(item => Number(item.id) === Number(variantId)) || null;
    const display = variant ? { ...character, ...variant, image: variant.image || character.image } : character;
    const variantKey = variant ? Number(variant.id) : 0;
    const entryKey = `${Number(characterId)}:${variantKey}`;
    const chip = document.createElement('div');
    chip.className = 'story-character-item story-character-chip';
    chip.dataset.characterId = characterId;
    chip.dataset.variantId = variant ? String(variant.id) : '';
    chip.dataset.characterPublicId = character.publicId || '';
    chip.title = 'Podglad postaci';
    chip.style.cssText = storyCharacterImageVars(display);
    chip.innerHTML = `
        ${window.storyId ? '' : `<input type="hidden" name="story_character_ids[]" value="${entryKey}">
        <input type="hidden" name="story_character_pseudonym_fields[${entryKey}]" value="">`}
        <img src="${storyUploadSrc(display.image)}" alt="${escapeHtml(characterName)}">
        <span>${escapeHtml(characterName)}</span>
        <button type="button" class="story-character-remove-btn" onclick="removeStoryCharacter(${Number(characterId)}, ${variant ? Number(variant.id) : 0})" title="Usun postac">
            <i class="fa-solid fa-trash"></i>
        </button>
        <div class="story-pseudonym-source-panel">${storyPseudonymSourceSelect(characterId, character.pseudonymFieldId || '', variant ? Number(variant.id) : 0)}</div>`;
    return chip;
}

function enhanceExistingStoryCharacters() {
    const storyCharacters = Array.isArray(window.storyCharacters) ? window.storyCharacters : [];
    document.querySelectorAll('#story-characters-container .story-character-item').forEach(item => {
        if (item.querySelector('img')) return;
        const characterId = Number(item.dataset.characterId || 0);
        const itemVariantId = Number(item.dataset.variantId || 0);
        const storyCharacter = storyCharacters.find(char => Number(char.id_character) === characterId && String(char.id_variant || '') === String(item.dataset.variantId || char.id_variant || '')) || {};
        const available = (window.availableCharacters || []).find(char => Number(char.id) === characterId) || {};
        const variantKey = Number(storyCharacter.id_variant || itemVariantId || 0);
        const entryKey = `${characterId}:${variantKey}`;
        const variant = (available.variants || []).find(item => Number(item.id) === variantKey) || null;
        const name = storyCharacter.character_name
            || (variant ? `${available.name || 'Postac'} - ${variant.name || 'Wariant'}` : '')
            || available.name
            || item.querySelector('div div')?.textContent?.trim()
            || item.querySelector('span')?.textContent?.trim()
            || 'Postac';
        const image = storyCharacter.character_image || variant?.image || available.image || '';
        const preservedPseudonymField = item.querySelector(`input[name="story_character_pseudonym_fields[${entryKey}]"]`)?.value
            || item.querySelector(`input[name="story_character_pseudonym_fields[${characterId}]"]`)?.value
            || '';
        const display = {
            imageFocusX: storyCharacter.character_image_focus_x ?? variant?.imageFocusX ?? available.imageFocusX,
            imageFocusY: storyCharacter.character_image_focus_y ?? variant?.imageFocusY ?? available.imageFocusY,
            imageZoom: storyCharacter.character_image_zoom ?? variant?.imageZoom ?? available.imageZoom,
            imageFit: storyCharacter.character_image_fit ?? variant?.imageFit ?? available.imageFit,
        };

        item.className = 'story-character-item story-character-chip';
        item.dataset.variantId = variantKey ? String(variantKey) : '';
        item.dataset.characterPublicId = storyCharacter.character_public_id || available.publicId || item.dataset.characterPublicId || '';
        item.title = 'Podglad postaci';
        item.style.cssText = storyCharacterImageVars(display);
        item.innerHTML = `
            ${window.storyId ? '' : `<input type="hidden" name="story_character_ids[]" value="${entryKey}">
            <input type="hidden" name="story_character_pseudonym_fields[${entryKey}]" value="${escapeHtml(preservedPseudonymField)}">`}
            <img src="${storyUploadSrc(image)}" alt="${escapeHtml(name)}">
            <span>${escapeHtml(name)}</span>
            <button type="button" class="story-character-remove-btn" onclick="removeStoryCharacter(${characterId}, ${variantKey})" title="Usun postac">
                <i class="fa-solid fa-trash"></i>
            </button>
            <div class="story-pseudonym-source-panel">${storyPseudonymSourceSelect(characterId, storyCharacter.pseudonym_field_id || preservedPseudonymField || '', variantKey)}</div>`;
    });
}

function storyCharacterViewUrl(chip) {
    const publicId = chip?.dataset.characterPublicId || '';
    if (!publicId) return '';

    const params = new URLSearchParams();
    const returnUrl = window.location.pathname + window.location.search;
    params.set('return_url', returnUrl);
    if (chip.dataset.variantId) {
        params.set('variant', chip.dataset.variantId);
    }

    return `/character/${encodeURIComponent(publicId)}?${params.toString()}`;
}

function initStoryCharacterNavigation() {
    const container = document.getElementById('story-characters-container');
    if (!container || !window.storyId) return;

    container.addEventListener('click', event => {
        if (event.target.closest('button, select, input, textarea, a, label')) return;
        const chip = event.target.closest('.story-character-chip');
        if (!chip || !container.contains(chip)) return;
        const url = storyCharacterViewUrl(chip);
        if (url) {
            window.location.href = url;
        }
    });
}

function updateStoryCharacterPseudonymSource(characterId, fieldId, variantId = 0) {
    const variantKey = Number(variantId) || '';
    const entryKey = `${Number(characterId)}:${Number(variantId) || 0}`;
    if (!window.storyId) {
        const input = document.querySelector(`#story-characters-container [data-character-id="${Number(characterId)}"][data-variant-id="${variantKey}"] input[name="story_character_pseudonym_fields[${entryKey}]"]`);
        if (input) input.value = fieldId || '';
        return;
    }

    fetch('/updateStoryCharacterPseudonyms', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `story_id=${encodeURIComponent(window.storyId)}&character_id=${encodeURIComponent(characterId)}&variant_id=${encodeURIComponent(variantId || '')}&pseudonym_field_id=${encodeURIComponent(fieldId || '')}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('Blad: ' + (data.message || 'Nie udalo sie zapisac zrodla pseudonimow'));
        }
    })
    .catch(e => alert('Blad: ' + e.message));
}

function removeStoryCharacter(characterId, variantId = 0) {
    const variantKey = Number(variantId) || '';
    const selector = `[data-character-id="${characterId}"][data-variant-id="${variantKey}"]`;
    if (!window.storyId) {
        document.querySelector(selector)?.remove();
        const container = document.getElementById('story-characters-container');
        if (container && !container.querySelector('[data-character-id]')) {
            container.innerHTML = '<p class="story-empty-note">Brak postaci. Dodaj postacie, ktore maja pojawic sie w historii.</p>';
        }
        return;
    }

    fetch('/removeCharacterFromStory', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `story_id=${encodeURIComponent(window.storyId)}&character_id=${encodeURIComponent(characterId)}&variant_id=${encodeURIComponent(variantId || '')}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert('Blad: ' + (data.message || 'Nie udalo sie usunac postaci'));
            return;
        }

        document.querySelector(selector)?.remove();
        const container = document.getElementById('story-characters-container');
        if (container && container.children.length === 0) {
            container.innerHTML = '<p class="story-empty-note">Brak postaci. Dodaj postacie aby pojawily sie w historii.</p>';
        }
    })
    .catch(e => alert('Blad: ' + e.message));
}

function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[m]));
}

function escapeJs(text) {
    return String(text || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, ' ');
}

document.addEventListener('DOMContentLoaded', () => {
    enhanceExistingStoryFields();
    autosizeStoryTextareas();
    bindStoryImagePickers();
    initStoryFieldDragDrop();
    initStoryFieldFlowControls();
    enhanceExistingStoryCharacters();
    initStoryCharacterNavigation();

    const modal = document.getElementById('character-selector-modal');
    if (modal) {
        modal.addEventListener('click', event => {
            if (event.target === modal) closeCharacterSelector();
        });
    }

    document.querySelectorAll('[data-story-field-type]').forEach(tag => {
        const type = tag.dataset.storyFieldType;
        const meta = STORY_FIELD_TYPES[type];
        if (meta) {
            tag.innerHTML = `<small><i class="fa-solid ${meta.icon}"></i> Typ: ${meta.label}</small>`;
        }
    });
});
