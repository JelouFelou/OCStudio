(function () {
    const TRACKED_FORMS = ['character-form', 'story-form', 'template-form'];
    const IGNORED_NAMES = new Set(['return_url']);
    const STATE_CHANGED = 'oc-change-modified';
    const STATE_REMOVED = 'oc-change-removed';
    const STATE_ADDED = 'oc-change-added';

    function shouldTrack(form) {
        if (!form || !TRACKED_FORMS.includes(form.id)) return false;
        if (form.id === 'character-form') return /\/editCharacter\//.test(form.action || window.location.pathname);
        if (form.id === 'story-form') return /^\/editStory\//.test(window.location.pathname);
        if (form.id === 'template-form') return !!form.querySelector('input[name="template_id"]');
        return false;
    }

    function isStillRendering(form) {
        if (form.id !== 'character-form') return false;
        if (document.body?.dataset.characterFieldsLoading === '1') return true;
        const templateId = document.getElementById('form-template-id')?.value || '';
        if (!templateId) return false;
        const left = document.getElementById('left-fields-container');
        const right = document.getElementById('right-fields-container');
        return !!left && !!right && left.children.length === 0 && right.children.length === 0;
    }

    function isTrackableControl(control) {
        if (!control || !control.name || control.disabled) return false;
        if (IGNORED_NAMES.has(control.name)) return false;
        if (control.name === 'story_deleted_fields[]') return false;
        const type = (control.type || '').toLowerCase();
        return !['button', 'submit', 'reset'].includes(type);
    }

    function controlsIn(root) {
        if (root instanceof HTMLFormElement) {
            return [...root.elements].filter(isTrackableControl);
        }
        return [...root.querySelectorAll('input, textarea, select')].filter(isTrackableControl);
    }

    function controlValue(control) {
        const type = (control.type || '').toLowerCase();
        if (type === 'checkbox' || type === 'radio') return control.checked ? '1' : '0';
        if (type === 'file') return control.files?.length ? [...control.files].map(file => file.name).join('|') : '';
        if (control.tagName === 'SELECT' && control.multiple) {
            return [...control.selectedOptions].map(option => option.value).join('|');
        }
        return String(control.value ?? '');
    }

    function setControlValue(control, value) {
        const type = (control.type || '').toLowerCase();
        if (type === 'checkbox' || type === 'radio') control.checked = value === '1';
        else if (type === 'file') control.value = '';
        else if (control.tagName === 'SELECT' && control.multiple) {
            const wanted = new Set(String(value).split('|'));
            [...control.options].forEach(option => { option.selected = wanted.has(option.value); });
        } else {
            control.value = value;
        }
        syncVisualPreview(control, value);
        control.dispatchEvent(new Event('input', { bubbles: true }));
        control.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function syncVisualPreview(control, value) {
        if ((control.type || '').toLowerCase() !== 'hidden') return;
        const group = groupFor(control);
        const image = group?.querySelector?.('img');
        if (!image) return;

        let src = '';
        try {
            const decoded = JSON.parse(value || '');
            src = decoded?.url || '';
        } catch (e) {
            const filename = String(value || '').split('/').pop();
            src = filename ? '/public/uploads/' + filename : '';
        }

        if (src) {
            image.src = src;
            image.hidden = false;
            image.style.display = '';
            image.closest('[hidden]')?.removeAttribute('hidden');
        } else {
            image.removeAttribute('src');
            image.style.display = 'none';
        }
    }

    function groupFor(control) {
        return control.closest(
            '.field-item, .story-field-item, .variant-card, .template-image-field, .story-cover-framing-panel, .story-create-preview-panel, .story-header-filter-row, .input-group, label'
        ) || control.parentElement;
    }

    function templateFieldId(group) {
        return group?.querySelector?.('.field-id')?.value || '';
    }

    function groupKey(group) {
        if (!group) return '';
        if (group.classList.contains('field-item')) {
            const id = templateFieldId(group);
            return id ? `template-field:${id}` : '';
        }
        if (group.classList.contains('story-field-item')) {
            const id = group.dataset.fieldId || '';
            return id && !id.startsWith('new_') ? `story-field:${id}` : '';
        }
        return '';
    }

    function controlKey(control) {
        const group = groupFor(control);
        const templateId = group?.classList.contains('field-item') ? templateFieldId(group) : '';
        if (templateId) {
            const localIndex = controlsIn(group).indexOf(control);
            return `template-field:${templateId}:${control.name}:${localIndex}`;
        }
        const storyId = group?.classList.contains('story-field-item') ? group.dataset.fieldId : '';
        if (storyId) return `story-field:${storyId}:${control.name}`;
        return control.name;
    }

    function readableLabel(group, control) {
        const directLabel = group?.querySelector?.('label')?.textContent?.trim();
        const fieldLabel = group?.querySelector?.('.story-field-label-input, input[name="field_labels[]"]')?.value?.trim();
        return fieldLabel || directLabel || control?.name || 'Pole';
    }

    function isEmptyValue(value) {
        return String(value ?? '').trim() === '';
    }

    function ensurePanel(form) {
        let panel = form._ocChangePanel;
        if (panel) return panel;
        panel = document.createElement('div');
        panel.className = 'change-tracker-panel';
        panel.hidden = true;
        panel.innerHTML = `
            <div class="change-tracker-panel-title">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span data-change-summary>Masz niezapisane zmiany</span>
            </div>
            <div class="change-tracker-panel-list" data-change-list></div>
        `;
        form._ocChangePanel = panel;
        form.parentElement?.insertBefore(panel, form);
        return panel;
    }

    function ensureRestoreButton(group, tracker) {
        if (!group || group.querySelector('.change-restore-btn')) return;
        const target = group.matches('.story-field-item, .field-item')
            ? (group.querySelector(':scope > .field-content') || group)
            : group;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'change-restore-btn';
        button.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Przywróć';
        button.addEventListener('click', event => {
            event.preventDefault();
            tracker.restoreGroup(group);
        });
        target.insertBefore(button, target.firstChild);
    }

    function clearGroupState(group) {
        if (!group) return;
        group.classList.remove(STATE_CHANGED, STATE_REMOVED, STATE_ADDED);
        group.querySelector('.change-restore-btn')?.remove();
    }

    function initTracker(form) {
        if (!shouldTrack(form) || form.dataset.changeTrackerReady) return;
        form.dataset.changeTrackerReady = '1';

        const tracker = {
            baseline: new Map(),
            groupSnapshots: new Map(),
            removedGroups: new Map(),
            markedGroups: new Set(),
            restoring: false,
            updateTimer: null,

            snapshot() {
                this.baseline.clear();
                controlsIn(form).forEach(control => {
                    this.baseline.set(controlKey(control), {
                        value: controlValue(control),
                        label: readableLabel(groupFor(control), control),
                    });
                });

                form.querySelectorAll('.field-item, .story-field-item').forEach(group => {
                    const key = groupKey(group);
                    if (!key) return;
                    this.groupSnapshots.set(key, {
                        label: readableLabel(group, controlsIn(group)[0]),
                        html: group.outerHTML,
                        parent: group.parentElement,
                        nextKey: group.nextElementSibling ? groupKey(group.nextElementSibling) : '',
                    });
                });
            },

            scheduleUpdate() {
                clearTimeout(this.updateTimer);
                this.updateTimer = setTimeout(() => this.update(), 60);
            },

            restoreGroup(group) {
                controlsIn(group).forEach(control => {
                    const entry = this.baseline.get(controlKey(control));
                    if (entry) setControlValue(control, entry.value);
                });
                clearGroupState(group);
                this.scheduleUpdate();
            },

            resetToCurrent() {
                this.removedGroups.clear();
                this.markedGroups.forEach(group => clearGroupState(group));
                this.markedGroups.clear();
                this.snapshot();
                this.update();
            },

            restoreRemoved(key) {
                const snap = this.groupSnapshots.get(key);
                if (!snap?.parent) return;
                const wrapper = document.createElement('div');
                wrapper.innerHTML = snap.html;
                const restored = wrapper.firstElementChild;
                if (!restored) return;

                this.restoring = true;
                const next = snap.nextKey
                    ? [...snap.parent.children].find(child => groupKey(child) === snap.nextKey)
                    : null;
                snap.parent.insertBefore(restored, next || null);
                this.removedGroups.delete(key);

                if (key.startsWith('story-field:')) {
                    const id = key.split(':')[1];
                    form.querySelector(`input[name="story_deleted_fields[]"][value="${CSS.escape(id)}"]`)?.remove();
                    window.autosizeStoryTextareas?.(restored);
                    window.bindStoryImagePickers?.(restored);
                    window.refreshStoryFieldMoveButtons?.();
                }
                window.initExistingTableFields?.();
                window.initExistingSelectFields?.();
                window.initExistingDateFields?.();
                window.initExistingImageFields?.();
                window.initExistingStatsFields?.();

                requestAnimationFrame(() => {
                    this.restoring = false;
                    this.update();
                });
            },

            update() {
                const changedGroups = new Map();
                let changed = 0;
                let removedValues = 0;
                let added = 0;

                controlsIn(form).forEach(control => {
                    const key = controlKey(control);
                    const current = controlValue(control);
                    const base = this.baseline.get(key);
                    const group = groupFor(control);

                    if (!base) {
                        if (!isEmptyValue(current)) {
                            added++;
                            changedGroups.set(group, STATE_ADDED);
                        }
                        return;
                    }

                    if (current !== base.value) {
                        changed++;
                        const state = !isEmptyValue(base.value) && isEmptyValue(current) ? STATE_REMOVED : STATE_CHANGED;
                        if (state === STATE_REMOVED) removedValues++;
                        changedGroups.set(group, state);
                    }
                });

                this.markedGroups.forEach(group => {
                    if (!changedGroups.has(group)) clearGroupState(group);
                });
                this.markedGroups = new Set([...this.markedGroups].filter(group => changedGroups.has(group)));

                changedGroups.forEach((state, group) => {
                    if (!group) return;
                    group.classList.remove(STATE_CHANGED, STATE_REMOVED, STATE_ADDED);
                    group.classList.add(state);
                    this.markedGroups.add(group);
                    ensureRestoreButton(group, this);
                });

                const panel = ensurePanel(form);
                const totalRemovedGroups = this.removedGroups.size;
                const total = changed + added + totalRemovedGroups;
                panel.hidden = total === 0;
                panel.querySelector('[data-change-summary]').textContent =
                    `Niezapisane zmiany: ${total}${removedValues || totalRemovedGroups ? `, usunięcia: ${removedValues + totalRemovedGroups}` : ''}`;

                const list = panel.querySelector('[data-change-list]');
                list.innerHTML = '';
                this.removedGroups.forEach((snap, key) => {
                    const row = document.createElement('div');
                    row.className = 'change-tracker-removed-row';
                    row.innerHTML = `<span><i class="fa-solid fa-trash"></i> Usunięto: ${escapeHtml(snap.label)}</span>`;
                    const restore = document.createElement('button');
                    restore.type = 'button';
                    restore.textContent = 'Przywróć';
                    restore.addEventListener('click', () => this.restoreRemoved(key));
                    row.appendChild(restore);
                    list.appendChild(row);
                });
            },
        };
        form._ocChangeTracker = tracker;

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, char => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[char]));
        }

        tracker.snapshot();
        tracker.update();

        form.addEventListener('input', () => tracker.scheduleUpdate(), true);
        form.addEventListener('change', () => tracker.scheduleUpdate(), true);
        form.addEventListener('submit', () => {
            form.querySelectorAll('.change-restore-btn').forEach(button => button.remove());
            ensurePanel(form).hidden = true;
        });

        const observer = new MutationObserver(mutations => {
            if (tracker.restoring) return;
            mutations.forEach(mutation => {
                mutation.removedNodes.forEach(node => {
                    if (!(node instanceof HTMLElement)) return;
                    const groups = node.matches?.('.field-item, .story-field-item')
                        ? [node]
                        : [...node.querySelectorAll?.('.field-item, .story-field-item') || []];
                    groups.forEach(group => {
                        const key = groupKey(group);
                        const snap = tracker.groupSnapshots.get(key);
                        if (key && snap) tracker.removedGroups.set(key, snap);
                    });
                });
                mutation.addedNodes.forEach(node => {
                    if (node instanceof HTMLElement) tracker.removedGroups.delete(groupKey(node));
                });
            });
            tracker.scheduleUpdate();
        });
        observer.observe(form, { childList: true, subtree: true });

        setInterval(() => tracker.update(), 900);
    }

    function initFormWhenReady(form, attempts = 0) {
        if (!form || form.dataset.changeTrackerReady) return;
        if (isStillRendering(form) && attempts < 40) {
            setTimeout(() => initFormWhenReady(form, attempts + 1), 250);
            return;
        }
        initTracker(form);
    }

    function initAll() {
        TRACKED_FORMS
            .map(id => document.getElementById(id))
            .filter(Boolean)
            .forEach(form => initFormWhenReady(form));
    }

    function initSaveReminder() {
        if (document.body?.dataset.saveReminderReady === '1') return;

        const reminderViews = new Set([
            'edit_story',
            'create_story',
            'edit_character',
            'create_character',
            'edit_template',
            'create_template',
        ]);
        const view = document.body?.dataset.view || '';
        if (!reminderViews.has(view)) return;

        const form = document.getElementById('story-form')
            || document.getElementById('character-form')
            || document.getElementById('template-form');
        if (!form) return;

        document.body.dataset.saveReminderReady = '1';

        let toast = null;
        let hideTimer = null;

        function ensureToast() {
            if (toast) return toast;
            toast = document.createElement('div');
            toast.className = 'save-reminder-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML = `
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Przypomnienie: zapisz zmiany na wszelki wypadek.</span>
                <button type="button" aria-label="Zamknij przypomnienie">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            `;
            toast.querySelector('button')?.addEventListener('click', hideToast);
            document.body.appendChild(toast);
            return toast;
        }

        function hideToast() {
            if (!toast) return;
            toast.classList.remove('is-visible');
            window.clearTimeout(hideTimer);
        }

        function showToast() {
            const node = ensureToast();
            node.classList.add('is-visible');
            window.clearTimeout(hideTimer);
            hideTimer = window.setTimeout(hideToast, 9000);
        }

        form.addEventListener('submit', hideToast);
        window.setInterval(showToast, 5 * 60 * 1000);
    }

    window.OCChangeTracker = {
        hasChanges(form) {
            const tracker = form?._ocChangeTracker;
            if (!tracker) return false;
            tracker.update();
            return !!form._ocChangePanel && !form._ocChangePanel.hidden;
        },
        resetToCurrent(form) {
            form?._ocChangeTracker?.resetToCurrent();
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(initAll, 700);
        initSaveReminder();
    });
})();
