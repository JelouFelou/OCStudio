(function () {
    function numericVar(element, name, fallback) {
        const raw = getComputedStyle(element).getPropertyValue(name).trim();
        if (!raw) return fallback;
        const value = parseFloat(raw);
        return Number.isFinite(value) ? value : fallback;
    }

    function textVar(element, name, fallback) {
        const raw = getComputedStyle(element).getPropertyValue(name).trim();
        return raw || fallback;
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function mediaValue(frame, img, names, fallback) {
        for (const name of names) {
            const fromImage = getComputedStyle(img).getPropertyValue(name).trim();
            if (fromImage) return fromImage;
            const fromFrame = getComputedStyle(frame).getPropertyValue(name).trim();
            if (fromFrame) return fromFrame;
        }
        return fallback;
    }

    function percentValue(frame, img, names, fallback) {
        const raw = mediaValue(frame, img, names, `${fallback}%`);
        const value = parseFloat(raw);
        return Number.isFinite(value) ? clamp(value, 0, 100) : fallback;
    }

    function zoomValue(frame, img) {
        const raw = mediaValue(frame, img, ['--oc-media-zoom', '--image-zoom', '--story-cover-zoom'], '1');
        const value = parseFloat(raw);
        return Number.isFinite(value) ? Math.max(1, value) : 1;
    }

    function shouldCalculate(frame, img) {
        if (!frame.classList.contains('oc-media-frame--anchored-cover')) return false;
        const isCharacterCard = frame.classList.contains('oc-media-frame--character-card');
        if (!isCharacterCard && (frame.classList.contains('oc-media-frame--natural') || frame.classList.contains('image-mode-natural'))) return false;
        const fit = mediaValue(frame, img, ['--oc-media-fit', '--image-fit', '--story-cover-fit'], textVar(img, 'object-fit', 'cover'));
        return isCharacterCard || fit !== 'contain';
    }

    function applyFrame(frame) {
        const img = frame.querySelector(':scope > img');
        if (!img || !img.naturalWidth || !img.naturalHeight || !shouldCalculate(frame, img)) {
            frame.classList.remove('is-crop-calculated');
            return;
        }

        const rect = frame.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) {
            frame.classList.remove('is-crop-calculated');
            return;
        }

        const referenceAspect = numericVar(frame, '--oc-media-reference-aspect', rect.width / rect.height);
        const actualAspect = rect.width / rect.height;
        if (Math.abs(actualAspect - referenceAspect) < 0.01) {
            frame.classList.remove('is-crop-calculated');
            return;
        }

        const referenceHeight = rect.width / Math.max(referenceAspect, 0.01);
        const focusX = percentValue(frame, img, ['--oc-media-focus-x', '--image-focus-x', '--story-cover-focus-x'], 50) / 100;
        const focusY = percentValue(frame, img, ['--oc-media-focus-y', '--image-focus-y', '--story-cover-focus-y'], 50) / 100;
        const zoom = zoomValue(frame, img);

        const baseScale = Math.max(rect.width / img.naturalWidth, referenceHeight / img.naturalHeight);
        const scale = baseScale * zoom;
        const renderWidth = img.naturalWidth * scale;
        const renderHeight = img.naturalHeight * scale;

        const referenceOverflowY = Math.max(0, renderHeight - referenceHeight);
        const actualOverflowX = Math.max(0, renderWidth - rect.width);
        let top = -referenceOverflowY * focusY;
        let left = -actualOverflowX * focusX;

        if (renderHeight + top < rect.height) {
            top = Math.min(0, rect.height - renderHeight);
        }

        frame.style.setProperty('--oc-render-width', `${renderWidth}px`);
        frame.style.setProperty('--oc-render-height', `${renderHeight}px`);
        frame.style.setProperty('--oc-render-left', `${left}px`);
        frame.style.setProperty('--oc-render-top', `${top}px`);
        frame.classList.add('is-crop-calculated');
    }

    function applyAll(root) {
        if (root.matches?.('.oc-media-frame--anchored-cover')) {
            applyFrame(root);
        }
        root.querySelectorAll('.oc-media-frame--anchored-cover').forEach(applyFrame);
        fitFilterRows(root);
    }

    function fitFilterRow(container) {
        if (!container || container.clientWidth <= 0) return;

        const chips = [...container.querySelectorAll(':scope > .oc-tile-filter-chip:not(.oc-tile-filter-chip--more)')];
        const more = container.querySelector(':scope > .oc-tile-filter-chip--more');
        chips.forEach(chip => { chip.hidden = false; });
        if (more) more.hidden = true;

        if (container.scrollWidth <= container.clientWidth) return;

        let hiddenCount = 0;
        for (let index = chips.length - 1; index >= 0; index--) {
            chips[index].hidden = true;
            hiddenCount++;

            if (more) {
                more.textContent = `+${hiddenCount}`;
                more.hidden = false;
            }

            if (container.scrollWidth <= container.clientWidth) break;
        }
    }

    function fitFilterRows(root) {
        if (root.matches?.('[data-fit-filter-row]')) {
            fitFilterRow(root);
        }
        root.querySelectorAll('[data-fit-filter-row]').forEach(fitFilterRow);
    }

    window.OCMediaFrame = {
        refresh(root = document) {
            applyAll(root);
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        applyAll(document);
        document.querySelectorAll('.oc-media-frame--anchored-cover > img').forEach(img => {
            img.addEventListener('load', () => applyFrame(img.parentElement), { passive: true });
        });
        if ('ResizeObserver' in window) {
            const filterRowObserver = new ResizeObserver(entries => {
                entries.forEach(entry => fitFilterRow(entry.target));
            });
            document.querySelectorAll('[data-fit-filter-row]').forEach(row => filterRowObserver.observe(row));
        }
    });

    window.addEventListener('resize', () => applyAll(document), { passive: true });
})();
