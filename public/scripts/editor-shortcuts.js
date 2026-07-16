(function () {
    const EDITOR_VIEWS = new Set([
        'create_character',
        'create_template',
        'edit_story',
        'relations_editor',
    ]);

    const view = document.body?.dataset.view || '';
    if (!EDITOR_VIEWS.has(view)) return;

    const shortcuts = [
        ['Ctrl + S', 'Zapisuje aktywny formularz edycji.'],
    ];

    if (view === 'edit_story') {
        shortcuts.push(
            ['Shift + F', 'Dodaje Długi tekst pod aktywnym polem historii.'],
            ['Shift + D', 'Dodaje Dialog pod aktywnym polem historii.'],
            ['Shift + Z', 'Dodaje Zdjęcie pod aktywnym polem historii.'],
            ['Ctrl + ↑ / ↓', 'Przechodzi do poprzedniego lub następnego bloku historii.']
        );
    }

    if (view === 'create_character') {
        shortcuts.push(
            ['Ctrl + ↑ / ↓', 'Przechodzi po polach oraz wierszach tabel/statystyk.'],
            ['Ctrl + ← / →', 'Przechodzi między lewą i prawą kolumną pól.']
        );
    }

    shortcuts.push(['Shift + /', 'Pokazuje lub ukrywa tę legendę.']);

    function isTextEditingTarget(target) {
        return target?.matches?.('textarea, [contenteditable="true"]');
    }

    function isInteractiveButton(target) {
        return target?.matches?.('button, a[href], input[type="button"], input[type="submit"], input[type="reset"]');
    }

    function editorForm() {
        return document.getElementById('story-form')
            || document.getElementById('template-form')
            || document.getElementById('character-form')
            || document.querySelector('form');
    }

    function submitEditorForm() {
        const form = editorForm();
        if (!form) return;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function ensureModal() {
        let modal = document.getElementById('editor-shortcuts-popover');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'editor-shortcuts-popover';
        modal.className = 'editor-shortcuts-popover';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="editor-shortcuts-panel" role="dialog" aria-modal="false" aria-label="Skróty klawiszowe">
                <div class="editor-shortcuts-header">
                    <strong>Skróty klawiszowe</strong>
                    <button type="button" data-editor-shortcuts-close aria-label="Zamknij">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="editor-shortcuts-list">
                    ${shortcuts.map(([keys, label]) => `
                        <div class="editor-shortcuts-row">
                            <kbd>${keys}</kbd>
                            <span>${label}</span>
                        </div>
                    `).join('')}
                </div>
            </div>`;
        document.body.appendChild(modal);

        modal.addEventListener('click', event => {
            if (event.target === modal || event.target.closest('[data-editor-shortcuts-close]')) {
                hideShortcuts();
            }
        });

        return modal;
    }

    function positionModal() {
        const modal = ensureModal();
        const button = document.querySelector('[data-editor-shortcuts-toggle]');
        const rect = button?.getBoundingClientRect?.();
        modal.hidden = false;
        const modalRect = modal.getBoundingClientRect();
        const top = rect ? rect.bottom + window.scrollY + 10 : window.scrollY + 76;
        const preferredLeft = rect ? rect.right + window.scrollX - modalRect.width : window.scrollX + window.innerWidth - modalRect.width - 18;
        const left = Math.max(12 + window.scrollX, Math.min(preferredLeft, window.scrollX + window.innerWidth - modalRect.width - 12));
        modal.style.top = `${top}px`;
        modal.style.left = `${left}px`;
    }

    function showShortcuts() {
        positionModal();
        document.querySelector('[data-editor-shortcuts-toggle]')?.classList.add('is-active');
    }

    function hideShortcuts() {
        const modal = document.getElementById('editor-shortcuts-popover');
        if (modal) modal.hidden = true;
        document.querySelector('[data-editor-shortcuts-toggle]')?.classList.remove('is-active');
    }

    function toggleShortcuts() {
        const modal = ensureModal();
        modal.hidden ? showShortcuts() : hideShortcuts();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const button = document.querySelector('[data-editor-shortcuts-toggle]');
        if (button) {
            button.hidden = false;
            button.addEventListener('click', toggleShortcuts);
        }
    });

    document.addEventListener('keydown', event => {
        const target = event.target;

        if (event.key === 'Escape') {
            hideShortcuts();
            return;
        }

        if (event.shiftKey && (event.key === '/' || event.key === '?' || event.code === 'Slash')) {
            event.preventDefault();
            toggleShortcuts();
            return;
        }

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            submitEditorForm();
            return;
        }

        if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
        if (isTextEditingTarget(target) || isInteractiveButton(target)) return;

        const form = target?.closest?.('form');
        if (form && form === editorForm()) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('click', event => {
        const modal = document.getElementById('editor-shortcuts-popover');
        if (!modal || modal.hidden) return;
        if (event.target.closest('#editor-shortcuts-popover, [data-editor-shortcuts-toggle]')) return;
        hideShortcuts();
    });
})();
