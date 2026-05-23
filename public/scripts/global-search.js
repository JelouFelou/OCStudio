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

        const { characters = [], worlds = [] } = data;

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
            folderRow.dataset.href = '/characters?world=' + world.id;
            folderRow.innerHTML =
                '<span class="search-folder-icon"><i class="fa-solid fa-folder"></i></span>' +
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
        if (characters.length === 0 && worlds.length === 0) {
            const empty = makeEl('p', 'search-empty');
            empty.textContent = 'Brak wyników dla „' + input.value.trim() + '"';
            dropdown.appendChild(empty);
        }

        showDropdown();
    }

    // ── Pomocnicze – tworzenie wierszy ────────────────────────────────────────
    function charRow(c) {
        const row = makeEl('div', 'search-char-row');
        row.dataset.href = '/viewCharacter?id=' + c.id;
        row.setAttribute('tabindex', '0');

        const img  = makeEl('img', 'search-char-img');
        img.src    = 'public/uploads/' + c.image;
        img.alt    = '';
        img.onerror = () => { img.src = 'public/uploads/default.png'; };

        const info = makeEl('div', 'search-char-info');
        info.innerHTML = '<span class="search-char-name">' + esc(c.name) + '</span>';

        // Odznaka statusu
        if (c.statusName) {
            const badge = makeEl('span', 'search-status-badge');
            badge.textContent = c.statusName;
            badge.style.background = c.statusColor || '#aaa';
            info.appendChild(badge);
        }

        row.appendChild(img);
        row.appendChild(info);

        row.addEventListener('click',   () => navigate(row.dataset.href));
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter') navigate(row.dataset.href); });

        return row;
    }

    function sectionLabel(text) {
        const el = makeEl('p', 'search-section-label');
        el.textContent = text;
        return el;
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