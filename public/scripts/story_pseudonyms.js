class StoryPseudonymHighlighter {
    constructor(storyPublicId) {
        this.storyPublicId = storyPublicId;
        this.characters = [];
        this.pseudonyms = {};
        this.init();
    }

    async init() {
        try {
            await this.fetchStoryData();
            this.highlightPseudonyms();
            this.setupEventListeners();
        } catch (e) {
            console.error('Blad inicjalizacji pseudonimow:', e);
        }
    }

    async fetchStoryData() {
        const response = await fetch(`/getStoryData?id=${encodeURIComponent(this.storyPublicId)}`);
        if (!response.ok) throw new Error('Failed to fetch story data');

        const data = await response.json();
        this.characters = data.characters || [];
        this.pseudonyms = {};

        for (const character of this.characters) {
            const names = [];
            if (character.character_name) {
                names.push(character.character_name);
            }

            if (Array.isArray(character.pseudonyms)) {
                for (const item of character.pseudonyms) {
                    if (!item.is_excluded && item.pseudonym) {
                        names.push(item.pseudonym);
                    }
                }
            }

            this.pseudonyms[character.id_character] = this.uniqueNames(names);
        }
    }

    highlightPseudonyms() {
        const contentContainer = document.querySelector('.story-content');
        if (!contentContainer) return;
        this.processNode(contentContainer);
    }

    processNode(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent || '';
            const highlighted = this.highlightText(text);

            if (highlighted !== text) {
                const wrapper = document.createElement('span');
                wrapper.innerHTML = highlighted;
                node.parentNode.replaceChild(wrapper, node);
            }
            return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) return;
        if (['PRE', 'CODE', 'SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT', 'BUTTON', 'A'].includes(node.tagName)) {
            return;
        }
        if (node.classList.contains('pseudonym-highlight')) return;

        [...node.childNodes].forEach(child => this.processNode(child));
    }

    highlightText(text) {
        if (!text || text.trim().length === 0) return text;

        const aliases = this.getAliases();
        if (!aliases.length) return text;

        const lookup = new Map(aliases.map(item => [item.text.toLocaleLowerCase(), item]));
        const alternatives = aliases.map(item => this.escapeRegex(item.text)).join('|');
        const regex = new RegExp(`(^|[^\\p{L}\\p{N}_])(${alternatives})(?=$|[^\\p{L}\\p{N}_])`, 'giu');

        let html = '';
        let lastIndex = 0;
        let matched = false;
        let result;

        while ((result = regex.exec(text)) !== null) {
            const prefix = result[1] || '';
            const match = result[2] || '';
            const item = lookup.get(match.toLocaleLowerCase());
            if (!item) continue;

            const matchStart = result.index + prefix.length;
            const matchEnd = matchStart + match.length;
            html += this.escapeHtml(text.slice(lastIndex, matchStart));
            html += `<span class="pseudonym-highlight" data-character-id="${item.charId}" data-pseudonym="${this.escapeHtml(item.text)}">${this.escapeHtml(match)}</span>`;
            lastIndex = matchEnd;
            matched = true;
        }

        if (!matched) return text;
        return html + this.escapeHtml(text.slice(lastIndex));
    }

    getAliases() {
        const aliases = [];
        const seen = new Set();

        for (const charId in this.pseudonyms) {
            for (const name of this.pseudonyms[charId]) {
                const clean = String(name || '').trim();
                const key = clean.toLocaleLowerCase();
                if (!clean || seen.has(key)) continue;

                seen.add(key);
                aliases.push({ text: clean, charId: Number(charId) });
            }
        }

        aliases.sort((a, b) => b.text.length - a.text.length || a.text.localeCompare(b.text));
        return aliases;
    }

    uniqueNames(names) {
        const result = [];
        const seen = new Set();

        for (const name of names) {
            const clean = String(name || '').trim();
            const key = clean.toLocaleLowerCase();
            if (!clean || seen.has(key)) continue;

            seen.add(key);
            result.push(clean);
        }

        return result;
    }

    setupEventListeners() {
        document.addEventListener('mouseenter', (e) => {
            if (e.target.classList.contains('pseudonym-highlight')) {
                this.showCharacterPreview(e.target);
            }
        }, true);

        document.addEventListener('mouseleave', (e) => {
            if (e.target.classList.contains('pseudonym-highlight')) {
                this.hideCharacterPreview();
            }
        }, true);

        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('pseudonym-highlight')) {
                e.preventDefault();
                this.navigateToCharacter(e.target.getAttribute('data-character-id'));
            }
        }, true);
    }

    showCharacterPreview(element) {
        const charId = Number(element.getAttribute('data-character-id'));
        const character = this.characters.find(item => Number(item.id_character) === charId);
        if (!character) return;

        this.hideCharacterPreview();

        const popup = document.createElement('div');
        popup.id = 'pseudonym-preview-popup';
        popup.className = 'pseudonym-preview-popup';
        popup.style.cssText = this.characterImageVars(character);
        popup.innerHTML = `
            <img src="${this.uploadSrc(character.character_image)}" alt="${this.escapeHtml(character.character_name || 'Postac')}">
            <div class="pseudonym-preview-overlay">
                <strong>${this.escapeHtml(character.character_name || 'Postac')}</strong>
                <small>${this.escapeHtml(element.getAttribute('data-pseudonym') || character.character_name || '')}</small>
            </div>
        `;

        document.body.appendChild(popup);
        this.positionPopup(popup, element);
    }

    positionPopup(popup, element) {
        const rect = element.getBoundingClientRect();
        const gap = 10;
        let left = rect.left + rect.width / 2 - popup.offsetWidth / 2;
        let top = rect.top - popup.offsetHeight - gap;

        left = Math.max(10, Math.min(left, window.innerWidth - popup.offsetWidth - 10));
        if (top < 10) {
            top = rect.bottom + gap;
        }

        popup.style.left = `${left}px`;
        popup.style.top = `${top}px`;
    }

    hideCharacterPreview() {
        document.getElementById('pseudonym-preview-popup')?.remove();
    }

    navigateToCharacter(charId) {
        const character = this.characters.find(item => Number(item.id_character) === Number(charId));
        if (character) {
            window.location.href = `/character/${character.character_public_id}`;
        }
    }

    uploadSrc(filename) {
        const clean = String(filename || '').split('/').pop();
        const fallback = document.body?.dataset.theme === 'dark' ? 'default_dark.png' : 'default.png';
        const resolved = !clean || ['default.png', 'default.jpg', 'default_dark.png'].includes(clean) ? fallback : clean;
        return '/media/' + resolved;
    }

    characterImageVars(character) {
        const focusX = Number(character.character_image_focus_x ?? 50);
        const focusY = Number(character.character_image_focus_y ?? 50);
        const zoom = Number(character.character_image_zoom ?? 1);
        const fit = character.character_image_fit || 'cover';

        return `--image-focus-x:${Math.max(0, Math.min(100, focusX))}%;--image-focus-y:${Math.max(0, Math.min(100, focusY))}%;--image-zoom:${Math.max(0.2, zoom || 1)};--image-fit:${this.escapeHtml(fit)};`;
    }

    escapeRegex(text) {
        return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.storyPublicId) {
        new StoryPseudonymHighlighter(window.storyPublicId);
    }
});
