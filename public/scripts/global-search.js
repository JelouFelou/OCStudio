/**
 * global-search.js
 *
 * Obsługuje wyszukiwarkę w headerze aplikacji OCStudio.
 *
 * Oczekiwana struktura HTML w layoucie:
 *
 *   <div class="header-search" id="header-search-wrapper">
 *     <input  type="text"
 *             id="header-search-input"
 *             placeholder="Szukaj postaci lub folderu…"
 *             autocomplete="off">
 *     <div id="header-search-dropdown" class="search-dropdown" hidden></div>
 *   </div>
 *
 * Wstaw ten skrypt na końcu <body> lub ładuj z defer.
 */

(function () {
    'use strict';

    const input    = document.getElementById('header-search-input');
    const dropdown = document.getElementById('header-search-dropdown');

    if (!input || !dropdown) return;   // brak elementów – nic nie rób

    let debounceTimer = null;
    let lastQuery     = '';

    // ── Obsługa inputa ──────────────────────────────────────────────────────
    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const q = input.value.trim();

        if (q.length < 2) {
            hideDropdown();
            return;
        }

        debounceTimer = setTimeout(() => search(q), 280);
    });

    // Zamknij po kliknięciu poza elementem
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#header-search-wrapper')) {
            hideDropdown();
        }
    });

    // Nawigacja klawiaturą (strzałki + Enter + Esc)
    input.addEventListener('keydown', (e) => {
        const items = [...dropdown.querySelectorAll('[data-href]')];
        if (!items.length) return;

        const active = dropdown.querySelector('[data-active="true"]');
        const idx    = active ? items.indexOf(active) : -1;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(items, idx < items.length - 1 ? idx + 1 : 0);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(items, idx > 0 ? idx - 1 : items.length - 1);
        } else if (e.key === 'Enter') {
            if (active) { e.preventDefault(); window.location.href = active.dataset.href; }
        } else if (e.key === 'Escape') {
            hideDropdown();
            input.blur();
        }
    });

    // ── Zapytanie do API ─────────────────────────────────────────────────────
    async function search(q) {
        if (q === lastQuery) return;
        lastQuery = q;

        showLoading();

        try {
            const res  = await fetch('/api/search?q=' + encodeURIComponent(q));
            const data = await res.json();
            render(data);
        } catch {
            dropdown.innerHTML = '<p class="search-empty">Błąd połączenia</p>';
            showDropdown();
        }
    }

    // ── Renderowanie wyników ─────────────────────────────────────────────────
    function render(data) {
        dropdown.innerHTML = '';

        const { characters = [], worlds = [], stories = [], templates = [], filters = [], publications = [] } = data;

        // Zbieramy ID postaci już pokazanych w sekcji "Postacie"
        // żeby nie powielać ich pod folderami
        const shownIds = new Set();

        // Sekcja: bezpośrednie wyniki postaci
        if (characters.length > 0) {
            dropdown.appendChild(sectionLabel('Postacie'));
            characters.forEach(c => {
                shownIds.add(c.id);
                dropdown.appendChild(charRow(c));
            });
        }

        // Sekcja: foldery z postaciami
        worlds.forEach(world => {
            // Filtruj postacie już pokazane wyżej
            const unique = world.characters.filter(c => !shownIds.has(c.id));

            // Nagłówek folderu – klikalny (otwiera folder)
            const folderRow = makeEl('div', 'search-folder-header');
            folderRow.dataset.href = '/characters/' + encodeURIComponent(world.publicId || world.id);
            folderRow.innerHTML =
                folderVisual(world) +
                '<span class="search-folder-name">' + esc(world.name) + '</span>' +
                '<span class="search-folder-count">' + world.characters.length + ' postaci</span>';
            folderRow.setAttribute('tabindex', '0');
            folderRow.addEventListener('click', () => navigate(folderRow.dataset.href));
            folderRow.addEventListener('keydown', (e) => { if (e.key === 'Enter') navigate(folderRow.dataset.href); });
            dropdown.appendChild(folderRow);

            // Postacie w tym folderze (unikalne)
            if (unique.length > 0) {
                const group = makeEl('div', 'search-folder-group');
                unique.forEach(c => {
                    shownIds.add(c.id);
                    group.appendChild(charRow(c));
                });
                dropdown.appendChild(group);
            }
        });

        // Brak wyników
        if (stories.length > 0) {
            dropdown.appendChild(sectionLabel('Historie'));
            stories.forEach(story => dropdown.appendChild(storyRow(story)));
        }

        if (templates.length > 0) {
            dropdown.appendChild(sectionLabel('Szablony'));
            templates.forEach(template => dropdown.appendChild(templateRow(template)));
        }

        if (publications.length > 0) {
            dropdown.appendChild(sectionLabel('Publikacje publiczne'));
            publications.forEach(publication => dropdown.appendChild(publicationRow(publication)));
        }

        if (filters.length > 0) {
            dropdown.appendChild(sectionLabel('Filtry'));
            filters.slice(0, 8).forEach(filter => dropdown.appendChild(filterRow(filter)));
        }

        if (characters.length === 0 && worlds.length === 0 && stories.length === 0 && templates.length === 0 && filters.length === 0 && publications.length === 0) {
            const empty = makeEl('p', 'search-empty');
            empty.textContent = 'Brak wyników dla „' + input.value.trim() + '"';
            dropdown.appendChild(empty);
        }

        showDropdown();
    }

    // ── Pomocnicze – tworzenie wierszy ────────────────────────────────────────
    function numericValue(primary, fallback, defaultValue) {
        const value = Number.parseFloat(primary ?? fallback);
        return Number.isFinite(value) ? value : defaultValue;
    }

    function characterCropStyle(character) {
        const fitValue = character?.image_fit ?? character?.imageFit;
        const fit = ['cover', 'contain'].includes(fitValue) ? fitValue : 'cover';
        const focusX = Math.max(0, Math.min(100, numericValue(character?.image_focus_x, character?.imageFocusX, 50)));
        const focusY = Math.max(0, Math.min(100, numericValue(character?.image_focus_y, character?.imageFocusY, 50)));
        const zoom = Math.max(1, numericValue(character?.image_zoom, character?.imageZoom, 1));

        return {
            fit,
            focusX: `${focusX}%`,
            focusY: `${focusY}%`,
            zoom: String(zoom),
            mode: (character?.image_display_mode ?? character?.imageDisplayMode) === 'natural' ? 'natural' : 'square'
        };
    }

    function charRow(c) {
        const row = makeEl('div', 'search-char-row');
        row.dataset.href = '/character/' + encodeURIComponent(c.publicId || c.id);
        row.setAttribute('tabindex', '0');

        const avatar = makeEl('span', 'search-char-img-wrap oc-media-frame oc-media-frame--portrait oc-media-frame--anchored-cover');
        const img  = makeEl('img', 'search-char-img');
        const crop = characterCropStyle(c);
        avatar.classList.add(crop.mode === 'natural' ? 'oc-media-frame--natural' : 'image-mode-square');
        avatar.style.setProperty('--image-fit', crop.fit);
        avatar.style.setProperty('--image-focus-x', crop.focusX);
        avatar.style.setProperty('--image-focus-y', crop.focusY);
        avatar.style.setProperty('--image-zoom', crop.zoom);
        img.src    = window.OCDefaults?.characterImageSrc
            ? window.OCDefaults.characterImageSrc(c.image)
            : '/media/' + (c.image || 'default.png');
        img.alt    = '';
        img.draggable = false;
        img.style.objectFit = crop.fit;
        img.addEventListener('load', () => window.OCMediaFrame?.refresh?.(avatar), { passive: true });
        img.onerror = () => {
            img.src = window.OCDefaults?.characterImageSrc
                ? window.OCDefaults.characterImageSrc()
                : '/media/' + (document.body?.dataset.theme === 'dark' ? 'default_dark.png' : 'default.png');
        };

        const info = makeEl('div', 'search-char-info');
        info.innerHTML = '<span class="search-char-name">' + esc(c.name) + '</span>';

        // Odznaka statusu
        if (c.statusName) {
            const badge = makeEl('span', 'search-status-badge');
            badge.textContent = c.statusName;
            badge.style.background = c.statusColor || '#aaa';
            info.appendChild(badge);
        }

        avatar.appendChild(img);
        row.appendChild(avatar);
        row.appendChild(info);
        requestAnimationFrame(() => window.OCMediaFrame?.refresh?.(avatar));

        row.addEventListener('click',   () => navigate(row.dataset.href));
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter') navigate(row.dataset.href); });

        return row;
    }

    function storyRow(story) {
        return mediaRow({
            href: '/story/' + encodeURIComponent(story.publicId || story.id),
            image: storyImageSrc(story.image),
            icon: 'fa-book-open',
            title: story.date ? story.date + ' - ' + story.title : story.title,
            description: story.description || 'Historia',
            badge: story.status || ''
        });
    }

    function templateRow(template) {
        return mediaRow({
            href: '/templates/' + encodeURIComponent(template.publicId || template.id) + '/edit',
            icon: 'fa-file-code',
            title: template.name,
            description: template.description || 'Szablon'
        });
    }

    function filterRow(filter) {
        return mediaRow({
            href: '/characters?q=' + encodeURIComponent(filter.name || filter.slug || ''),
            icon: 'fa-filter',
            title: filter.name || filter.slug,
            description: filter.slug ? '#' + filter.slug : 'Filtr'
        });
    }

    function publicationRow(publication) {
        return mediaRow({
            href: '/p/' + encodeURIComponent(publication.publicId || publication.id),
            image: window.OCDefaults?.characterImageSrc
                ? window.OCDefaults.characterImageSrc(publication.image)
                : '/media/' + (publication.image || 'default.png'),
            icon: 'fa-share-nodes',
            title: publication.title || 'Publikacja',
            description: [
                publication.isOwn ? 'Twoja publikacja' : 'Autor: ' + (publication.authorName || 'Uzytkownik'),
                publication.typeLabel || 'Publikacja'
            ].filter(Boolean).join(' - '),
            badge: publication.ageRating === 'adult' ? '+18' : ''
        });
    }

    function mediaRow(item) {
        const row = makeEl('div', 'search-media-row');
        row.dataset.href = item.href;
        row.setAttribute('tabindex', '0');

        const visual = makeEl('span', item.image ? 'search-media-thumb' : 'search-media-icon');
        if (item.image) {
            const img = makeEl('img', '');
            img.src = item.image;
            img.alt = '';
            img.onerror = () => { visual.innerHTML = '<i class="fa-solid ' + esc(item.icon || 'fa-circle') + '"></i>'; visual.className = 'search-media-icon'; };
            visual.appendChild(img);
        } else {
            visual.innerHTML = '<i class="fa-solid ' + esc(item.icon || 'fa-circle') + '"></i>';
        }

        const info = makeEl('span', 'search-media-info');
        info.innerHTML = '<span class="search-media-title">' + esc(item.title || '') + '</span>'
            + '<span class="search-media-desc">' + esc(trimText(item.description || '', 80)) + '</span>';

        row.appendChild(visual);
        row.appendChild(info);

        if (item.badge) {
            const badge = makeEl('span', 'search-media-badge');
            badge.textContent = item.badge;
            row.appendChild(badge);
        }

        row.addEventListener('click', () => navigate(row.dataset.href));
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter') navigate(row.dataset.href); });
        return row;
    }

    function sectionLabel(text) {
        const el = makeEl('p', 'search-section-label');
        el.textContent = text;
        return el;
    }

    function folderVisual(world) {
        if (world.image && !['default.jpg', 'default.png', ''].includes(world.image)) {
            return '<span class="search-folder-thumb"><img src="/media/' + esc(world.image) + '" alt=""></span>';
        }
        return '<span class="search-folder-icon" style="background:' + esc(world.iconColor || '#7B61FF') + '"><i class="fa-solid fa-folder"></i></span>';
    }

    function storyImageSrc(image) {
        const filename = String(image || '').split('/').pop();
        const isDefault = !filename || ['default_story.png', 'default_story.jpg', 'default_story_dark.png'].includes(filename);
        return '/media/' + (isDefault && document.body?.dataset.theme === 'dark' ? 'default_story_dark.png' : (filename || 'default_story.png'));
    }

    function trimText(text, limit) {
        const value = String(text || '').trim();
        return value.length > limit ? value.slice(0, limit - 1) + '…' : value;
    }

    // ── Pomocnicze – stan dropdownu ──────────────────────────────────────────
    function showDropdown() { dropdown.hidden = false; }
    function hideDropdown() { dropdown.hidden = true;  lastQuery = ''; }

    function showLoading() {
        dropdown.innerHTML = '<p class="search-empty search-loading">Szukam…</p>';
        showDropdown();
    }

    // ── Pomocnicze – fokus klawiaturowy ──────────────────────────────────────
    function setActive(items, idx) {
        items.forEach((el, i) => {
            const active = i === idx;
            el.dataset.active = active;
            el.classList.toggle('is-active', active);
        });
        items[idx]?.scrollIntoView({ block: 'nearest' });
    }

    // ── Util ─────────────────────────────────────────────────────────────────
    function makeEl(tag, cls) {
        const el = document.createElement(tag);
        if (cls) el.className = cls;
        return el;
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function navigate(href) {
        window.location.href = href;
    }
})();
