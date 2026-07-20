(function () {
    const uploadBase = '/media/';
    const imageAccept = 'image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.png,.gif,.webp,.avif';
    const transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    let activeGalleryController = null;
    let galleryImageObserver = null;
    let galleryCardObserver = null;
    let pageFlowObserver = null;
    let pageFlowMutationObserver = null;
    const i18n = window.OCI18n || {};
    const commonText = i18n.common || {};
    const galleryText = i18n.gallery || {};

    function tr(source, key, fallback) {
        return source?.[key] || fallback;
    }

    function splitTags(value) {
        return String(value || '')
            .split(',')
            .map(tag => tag.trim())
            .filter(Boolean);
    }

    function tagsToText(tags) {
        return (tags || []).map(tag => tag.name || tag.label || tag.slug || tag).join(', ');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function filenameFromSrc(src) {
        const value = String(src || '').trim();
        if (!value) return '';
        try {
            return decodeURIComponent(new URL(value, window.location.origin).pathname.split('/').pop() || '');
        } catch (e) {
            return decodeURIComponent(value.split('?')[0].split('#')[0].split('/').pop() || '');
        }
    }

    function adultImageSet() {
        return new Set((window.OCAdultImages || []).map(filename => String(filename || '').trim()).filter(Boolean));
    }

    function markAdultImages(root = document) {
        const adultImages = adultImageSet();
        const images = root instanceof HTMLImageElement
            ? [root]
            : Array.from(root.querySelectorAll?.('img') || []);

        images.forEach(image => {
            const isAdult = adultImages.has(filenameFromSrc(image.dataset.fullSrc || image.currentSrc || image.src));
            image.classList.toggle('oc-adult-image-blurred', isAdult);
            if (isAdult) {
                image.dataset.adultImage = '1';
            } else {
                delete image.dataset.adultImage;
            }
        });
    }

    function refreshAdultImageList(images) {
        window.OCAdultImages = (images || [])
            .filter(image => normalizeVisibility(image.visibility) === 'adult')
            .map(image => image.filename)
            .filter(Boolean);
        markAdultImages(document);
    }

    function updateAdultImageRegistry(asset) {
        if (!asset?.filename) return;
        const filenames = new Set(window.OCAdultImages || []);
        if (normalizeVisibility(asset.visibility) === 'adult') {
            filenames.add(asset.filename);
        } else {
            filenames.delete(asset.filename);
        }
        window.OCAdultImages = Array.from(filenames);
        markAdultImages(document);
    }

    function initAdultImageBlur() {
        markAdultImages(document);
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'attributes' && mutation.target instanceof HTMLImageElement) {
                    markAdultImages(mutation.target);
                    return;
                }
                mutation.addedNodes.forEach(node => {
                    if (node instanceof HTMLElement) {
                        markAdultImages(node);
                    }
                });
            });
        });
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['src', 'srcset']
        });
    }

    function bindGalleryImageVirtualization(root = document) {
        if (!('IntersectionObserver' in window)) return;
        if (!galleryImageObserver) {
            galleryImageObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    const image = entry.target;
                    if (!(image instanceof HTMLImageElement)) return;
                    const thumbSrc = image.dataset.thumbSrc || image.dataset.fullSrc || image.src;
                    if (entry.isIntersecting) {
                        clearTimeout(Number(image.dataset.unloadTimer || 0));
                        if (image.dataset.unloaded === '1' && thumbSrc) {
                            image.src = thumbSrc;
                            delete image.dataset.unloaded;
                            markAdultImages(image);
                        }
                    } else if (image.dataset.unloaded !== '1' && image.src !== transparentPixel && !image.dataset.unloadTimer) {
                        image.dataset.unloadTimer = String(window.setTimeout(() => {
                            if (image.dataset.unloaded !== '1') {
                                image.dataset.unloaded = '1';
                                image.src = transparentPixel;
                            }
                            delete image.dataset.unloadTimer;
                        }, 220));
                    }
                });
            }, { rootMargin: '1200px 0px' });
        }

        root.querySelectorAll?.('img[data-gallery-thumb="1"]').forEach(image => {
            if (image.dataset.virtualizedGalleryThumb) return;
            image.dataset.virtualizedGalleryThumb = '1';
            galleryImageObserver.observe(image);
        });
    }

    function bindGalleryCardFlow(root = document) {
        if (!('IntersectionObserver' in window)) return;
        if (!galleryCardObserver) {
            galleryCardObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    const card = entry.target;
                    if (!(card instanceof HTMLElement)) return;
                    card.classList.toggle('is-gallery-card-visible', entry.isIntersecting);
                    card.classList.toggle('is-gallery-card-away', !entry.isIntersecting);
                });
            }, { rootMargin: '260px 0px', threshold: 0.02 });
        }

        root.querySelectorAll?.('.gallery-card').forEach((card, index) => {
            if (card.dataset.boundGalleryFlow) return;
            card.dataset.boundGalleryFlow = '1';
            card.style.setProperty('--gallery-card-delay', `${Math.min(index % 18, 10) * 18}ms`);
            galleryCardObserver.observe(card);
        });
    }

    function bindPageFlow(root = document) {
        if (!('IntersectionObserver' in window)) return;
        if (!pageFlowObserver) {
            pageFlowObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    const item = entry.target;
                    if (!(item instanceof HTMLElement)) return;
                    item.classList.toggle('oc-flow-visible', entry.isIntersecting);
                    item.classList.toggle('oc-flow-away', !entry.isIntersecting);
                });
            }, { rootMargin: '220px 0px', threshold: 0.03 });
        }

        const selectors = [
            '.character-card',
            '.drag-drop-folder',
            '.story-list-card',
            '.template-card',
            '.search-char-row',
            '.search-folder-header'
        ].join(',');

        root.querySelectorAll?.(selectors).forEach((item, index) => {
            if (item.classList.contains('gallery-card') || item.dataset.boundPageFlow) return;
            item.dataset.boundPageFlow = '1';
            item.classList.add('oc-flow-item');
            item.style.setProperty('--oc-flow-delay', `${Math.min(index % 16, 8) * 16}ms`);
            pageFlowObserver.observe(item);
        });
    }

    function initPageFlow() {
        document.body?.classList.add('oc-flow-enabled');
        bindPageFlow(document);
        if (pageFlowMutationObserver) return;
        pageFlowMutationObserver = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node instanceof HTMLElement) {
                        bindPageFlow(node);
                    }
                });
            });
        });
        pageFlowMutationObserver.observe(document.body, { childList: true, subtree: true });
    }

    const lastUploadTagsCookie = 'oc_last_image_tags';

    function getCookie(name) {
        return document.cookie
            .split('; ')
            .find(row => row.startsWith(name + '='))
            ?.split('=')
            .slice(1)
            .join('=') || '';
    }

    function setCookie(name, value, days = 180) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
    }

    function getLastUploadTags() {
        return decodeURIComponent(getCookie(lastUploadTagsCookie) || '');
    }

    function setLastUploadTags(tags) {
        const normalized = splitTags(tags).join(', ');
        if (normalized) setCookie(lastUploadTagsCookie, normalized);
        return normalized;
    }

    function ensureSuggestions(input) {
        let box = document.querySelector('.tag-suggestions');
        if (!box) {
            box = document.createElement('div');
            box.className = 'tag-suggestions';
            box.hidden = true;
            document.body.appendChild(box);
        }

        let debounce;
        input.addEventListener('input', () => {
            clearTimeout(debounce);
            const parts = input.value.split(',');
            const query = parts[parts.length - 1].trim();
            if (query.length < 2) {
                box.hidden = true;
                return;
            }

            debounce = setTimeout(async () => {
                const rect = input.getBoundingClientRect();
                box.style.left = rect.left + 'px';
                box.style.top = (rect.bottom + 4) + 'px';
                box.style.width = rect.width + 'px';
                const params = new URLSearchParams({ q: query });
                if (input.dataset.contextWorldId) {
                    params.set('worldId', input.dataset.contextWorldId);
                }
                const res = await fetch('/api/filters/search?' + params.toString());
                const data = await res.json();
                box.innerHTML = '';
                (data.filters || []).forEach(filter => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = filter.name;
                    btn.addEventListener('click', () => {
                        parts[parts.length - 1] = ' ' + filter.name;
                        input.value = parts.map(part => part.trim()).filter(Boolean).join(', ') + ', ';
                        box.hidden = true;
                        input.focus();
                    });
                    box.appendChild(btn);
                });
                box.hidden = !(data.filters || []).length;
            }, 180);
        });

        document.addEventListener('click', event => {
            if (event.target !== input && !box.contains(event.target)) {
                box.hidden = true;
            }
        });
    }

    function bindTagInputs(root = document) {
        root.querySelectorAll('[data-tag-input], .tag-input, [data-gallery-tags-input]').forEach(input => ensureSuggestions(input));
    }

    async function fetchImages(query = '') {
        const res = await fetch('/api/images?q=' + encodeURIComponent(query));
        if (!res.ok) throw new Error(tr(galleryText, 'errorFetch', 'Nie udalo sie pobrac galerii.'));
        return (await res.json()).images || [];
    }

    function normalizeVisibility(value) {
        return ['normal', 'hidden', 'adult'].includes(value) ? value : 'normal';
    }

    async function uploadImage(file, tags, visibility = 'normal') {
        const form = new FormData();
        form.append('file', file);
        form.append('tags', setLastUploadTags(tags));
        form.append('visibility', normalizeVisibility(visibility));
        const res = await fetch('/api/images/upload', { method: 'POST', body: form });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || tr(galleryText, 'errorUpload', 'Nie udalo sie wgrac zdjecia.'));
        return data.imageAsset;
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || tr(galleryText, 'errorOperation', 'Nie udalo sie wykonac operacji.'));
        return data;
    }

    function createPicker() {
        const modal = document.createElement('div');
        modal.className = 'image-picker-modal';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="image-picker-card">
                <h2>${escapeHtml(tr(galleryText, 'chooseImage', 'Wybierz zdjecie'))}</h2>
                <div class="image-picker-tabs">
                    <button type="button" data-tab="gallery" class="active">${escapeHtml(tr(galleryText, 'fromGallery', 'Z galerii'))}</button>
                    <button type="button" data-tab="upload">${escapeHtml(tr(galleryText, 'fromComputer', 'Z komputera'))}</button>
                </div>
                <div data-panel="gallery">
                    <div class="gallery-search" style="margin-bottom:12px;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" data-image-search placeholder="${escapeHtml(tr(galleryText, 'searchImages', 'Szukaj zdjec...'))}">
                    </div>
                    <div class="image-picker-grid" data-image-grid></div>
                </div>
                <div data-panel="upload" hidden>
                    <div class="image-picker-upload-preview" data-upload-preview hidden>
                        <img src="" alt="">
                        <div>
                            <strong data-upload-preview-name></strong>
                            <span data-upload-preview-size></span>
                        </div>
                    </div>
                    <label class="gallery-field">
                        <span>${escapeHtml(tr(galleryText, 'file', 'Plik'))}</span>
                        <input type="file" accept="${imageAccept}" data-upload-file>
                    </label>
                    <label class="gallery-field">
                        <span>${escapeHtml(tr(galleryText, 'filtersMin', 'Filtry zdjecia, minimum 5'))}</span>
                        <input type="text" class="tag-input" data-upload-tags placeholder="${escapeHtml(tr(galleryText, 'tagsPlaceholder', 'e.g. sfw, male, young, beach, sea'))}">
                    </label>
                    <label class="gallery-field">
                        <span>${escapeHtml(tr(galleryText, 'visibility', 'Widocznosc'))}</span>
                        <select data-upload-visibility>
                            <option value="normal">${escapeHtml(tr(commonText, 'normal', 'Zwykle'))}</option>
                            <option value="hidden">${escapeHtml(tr(commonText, 'hidden', 'Ukryte'))}</option>
                            <option value="adult">+18</option>
                        </select>
                    </label>
                </div>
                <div class="image-picker-actions">
                    <button type="button" data-cancel>${escapeHtml(tr(commonText, 'cancel', 'Anuluj'))}</button>
                    <button type="button" class="primary" data-confirm>${escapeHtml(tr(commonText, 'choose', 'Wybierz'))}</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        bindTagInputs(modal);
        return modal;
    }

    async function openImagePicker(options = {}) {
        const modal = createPicker();
        const grid = modal.querySelector('[data-image-grid]');
        const search = modal.querySelector('[data-image-search]');
        const uploadFileInput = modal.querySelector('[data-upload-file]');
        const uploadTagsInput = modal.querySelector('[data-upload-tags]');
        const uploadVisibilityInput = modal.querySelector('[data-upload-visibility]');
        const uploadPreview = modal.querySelector('[data-upload-preview]');
        const uploadPreviewImage = uploadPreview?.querySelector('img');
        const uploadPreviewName = modal.querySelector('[data-upload-preview-name]');
        const uploadPreviewSize = modal.querySelector('[data-upload-preview-size]');
        if (uploadTagsInput) uploadTagsInput.value = getLastUploadTags();
        let selected = null;
        let uploadPreviewUrl = '';
        let activeTab = options.defaultTab === 'upload' ? 'upload' : 'gallery';
        if (options.allowUpload === false) {
            modal.querySelector('[data-tab="upload"]')?.remove();
            modal.querySelector('[data-panel="upload"]')?.remove();
            activeTab = 'gallery';
        }
        if (options.allowGallery === false) {
            modal.querySelector('[data-tab="gallery"]')?.remove();
            modal.querySelector('[data-panel="gallery"]')?.remove();
            activeTab = 'upload';
        }

        async function renderImages(query = '') {
            if (!grid) return;
            grid.innerHTML = `<span>${escapeHtml(tr(commonText, 'loading', 'Ladowanie...'))}</span>`;
            const images = await fetchImages(query);
            grid.innerHTML = '';
            images.forEach(image => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'image-picker-item';
                btn.innerHTML = `<img src="${image.thumbnailUrl || image.url}" data-full-src="${image.url}" alt="" loading="lazy" decoding="async">`;
                btn.addEventListener('click', () => {
                    selected = image;
                    grid.querySelectorAll('.active').forEach(el => el.classList.remove('active'));
                    btn.classList.add('active');
                });
                grid.appendChild(btn);
            });
            if (!images.length) grid.innerHTML = `<span>${escapeHtml(tr(galleryText, 'noImages', 'Brak zdjec.'))}</span>`;
        }

        modal.querySelectorAll('[data-tab]').forEach(tab => {
            tab.addEventListener('click', () => {
                activeTab = tab.dataset.tab;
                modal.querySelectorAll('[data-tab]').forEach(btn => btn.classList.toggle('active', btn === tab));
                modal.querySelectorAll('[data-panel]').forEach(panel => {
                    panel.hidden = panel.dataset.panel !== activeTab;
                });
            });
        });
        modal.querySelectorAll('[data-tab]').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === activeTab));
        modal.querySelectorAll('[data-panel]').forEach(panel => {
            panel.hidden = panel.dataset.panel !== activeTab;
        });

        let debounce;
        search?.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(() => renderImages(search.value), 180);
        });

        function clearUploadPreview() {
            if (uploadPreviewUrl) URL.revokeObjectURL(uploadPreviewUrl);
            uploadPreviewUrl = '';
            if (uploadPreviewImage) {
                uploadPreviewImage.removeAttribute('src');
            }
            if (uploadPreviewName) uploadPreviewName.textContent = '';
            if (uploadPreviewSize) uploadPreviewSize.textContent = '';
            if (uploadPreview) uploadPreview.hidden = true;
        }

        function renderUploadPreview(file) {
            clearUploadPreview();
            if (!file || !uploadPreview || !uploadPreviewImage) return;
            uploadPreviewUrl = URL.createObjectURL(file);
            uploadPreviewImage.src = uploadPreviewUrl;
            if (uploadPreviewName) uploadPreviewName.textContent = file.name;
            if (uploadPreviewSize) {
                const mb = file.size / 1024 / 1024;
                uploadPreviewSize.textContent = `${mb >= 0.1 ? mb.toFixed(1) : '<0.1'} MB`;
            }
            uploadPreview.hidden = false;
        }

        uploadFileInput?.addEventListener('change', () => {
            selected = null;
            renderUploadPreview(uploadFileInput.files?.[0]);
        });

        await renderImages();
        modal.hidden = false;

        return new Promise((resolve, reject) => {
            modal.querySelector('[data-cancel]').addEventListener('click', () => {
                clearUploadPreview();
                modal.remove();
                resolve(null);
            });
            modal.querySelector('[data-confirm]').addEventListener('click', async () => {
                try {
                    if (activeTab === 'upload') {
                        const file = uploadFileInput.files[0];
                        if (!file) throw new Error(tr(galleryText, 'selectFile', 'Wybierz plik.'));
                        if (splitTags(uploadTagsInput.value).length < 5) {
                            throw new Error(tr(galleryText, 'minFilters', 'Podaj minimum 5 filtrow dla zdjecia.'));
                        }
                        selected = await uploadImage(file, uploadTagsInput.value, uploadVisibilityInput?.value || 'normal');
                        updateAdultImageRegistry(selected);
                    }
                    if (!selected) throw new Error(tr(galleryText, 'selectImage', 'Wybierz zdjecie.'));
                    clearUploadPreview();
                    modal.remove();
                    resolve(selected);
                } catch (error) {
                    alert(error.message);
                }
            });
        });
    }

    function setImageTarget(target, asset) {
        if (!asset) return;
        let preview = document.querySelector(target.dataset.previewSelector);
        if (!preview && target.dataset.previewSelector?.endsWith(' img')) {
            const containerSelector = target.dataset.previewSelector.slice(0, -4);
            const container = document.querySelector(containerSelector);
            const placeholder = container?.querySelector('.variant-image-placeholder');
            if (placeholder) {
                preview = document.createElement('img');
                preview.alt = '';
                preview.style.cssText = 'width:100%;max-width:220px;border-radius:8px;object-fit:cover;';
                placeholder.replaceWith(preview);
            }
        }
        const filenameInput = document.querySelector(target.dataset.filenameSelector);
        const idInput = document.querySelector(target.dataset.imageIdSelector);
        if (preview) {
            const previewImage = preview.matches('img') ? preview : preview.querySelector('img');
            if (previewImage) {
                previewImage.removeAttribute('hidden');
                previewImage.src = asset.url;
                previewImage.style.display = '';
            }
            if (preview.matches('img')) {
                preview.removeAttribute('hidden');
                preview.style.display = '';
            } else {
                preview.removeAttribute('hidden');
                preview.hidden = false;
                preview.style.display = '';
            }
        }
        if (filenameInput) filenameInput.value = asset.filename || '';
        if (idInput) idInput.value = asset.id ?? asset.imageId ?? '';
        if (idInput?.id === 'new-folder-image-id') {
            const removeBtn = document.getElementById('remove-folder-image-btn');
            if (removeBtn) removeBtn.style.display = '';
        }
        if (idInput?.id === 'character-image-id') {
            const removeFlag = document.getElementById('remove-image-flag');
            const fileInput = document.getElementById('profile-image-upload');
            const removeBtn = document.getElementById('remove-image-btn');
            if (removeFlag) removeFlag.value = '';
            if (fileInput) fileInput.value = '';
            if (removeBtn) removeBtn.style.display = '';
        }
        if (target.tagName === 'INPUT' && target.type === 'hidden') target.value = asset.filename;
    }

    function bindImagePickerButtons(root = document) {
        root.querySelectorAll('[data-open-image-picker]').forEach(btn => {
            if (window.OCFeatures?.gallery === false) {
                btn.remove();
                return;
            }
            if (btn.dataset.boundPicker) return;
            btn.dataset.boundPicker = '1';
            btn.addEventListener('click', async () => {
                const asset = await openImagePicker({
                    allowUpload: btn.dataset.galleryOnly !== '1',
                    allowGallery: btn.dataset.uploadOnly !== '1',
                    defaultTab: btn.dataset.pickerDefaultTab || 'gallery',
                });
                setImageTarget(btn, asset);
                if (asset) {
                    btn.dispatchEvent(new CustomEvent('oc:image-selected', {
                        bubbles: true,
                        detail: { asset },
                    }));
                }
            });
        });
    }

    function renderGalleryCards(images) {
        const grid = document.getElementById('gallery-grid');
        if (!grid) return;
        grid.innerHTML = '';
        images.forEach(image => {
            const card = document.createElement('article');
            card.className = galleryCardClass(image);
            card.dataset.imageId = image.id;
            card.dataset.galleryImage = JSON.stringify(image);
            card.innerHTML = `
                <button type="button" class="gallery-image-button" data-open-lightbox data-src="${image.url}">
                    <img src="${image.thumbnailUrl || image.url}" data-thumb-src="${image.thumbnailUrl || image.url}" data-full-src="${image.url}" data-gallery-thumb="1" alt="" loading="lazy" decoding="async">
                </button>
                ${galleryHiddenBadge(image)}
                <div class="gallery-card-actions">
                    ${galleryPublishButton(image, true)}
                    <button type="button" class="gallery-delete-image" data-delete-image title="${escapeHtml(tr(galleryText, 'deleteImage', 'Usun zdjecie'))}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                <div class="gallery-card-details">
                    <label>
                        <span>${escapeHtml(tr(i18n, 'filters', 'Filtry'))}</span>
                        <textarea data-gallery-tags-input rows="3">${tagsToText(image.tags || [])}</textarea>
                    </label>
                    <label>
                        <span>${escapeHtml(tr(galleryText, 'visibility', 'Widocznosc'))}</span>
                        <select data-gallery-visibility>
                            <option value="normal" ${normalizeVisibility(image.visibility) === 'normal' ? 'selected' : ''}>${escapeHtml(tr(commonText, 'normal', 'Zwykle'))}</option>
                            <option value="hidden" ${normalizeVisibility(image.visibility) === 'hidden' ? 'selected' : ''}>${escapeHtml(tr(commonText, 'hidden', 'Ukryte'))}</option>
                            <option value="adult" ${normalizeVisibility(image.visibility) === 'adult' ? 'selected' : ''}>+18</option>
                        </select>
                    </label>
                    <button type="button" class="gallery-save-tags" data-save-card-tags>${escapeHtml(tr(commonText, 'save', 'Zapisz'))}</button>
                    ${galleryPublishButton(image, false)}
                    <button type="button" class="gallery-delete-image" data-delete-image>${escapeHtml(tr(galleryText, 'deleteImage', 'Usun zdjecie'))}</button>
                </div>`;
            grid.appendChild(card);
        });
        bindTagInputs(grid);
        bindGalleryActions();
        markAdultImages(grid);
        bindGalleryImageVirtualization(grid);
        bindGalleryCardFlow(grid);
    }

    function galleryCardClass(image) {
        const classes = ['gallery-card'];
        if (image?.hasHiddenReferences) classes.push('is-hidden-gallery-image');
        if (normalizeVisibility(image?.visibility) === 'adult') classes.push('is-adult-gallery-image');
        if (image?.hiddenWithoutOwnFilters) classes.push('is-hidden-gallery-image-unfiltered');
        return classes.join(' ');
    }

    function galleryHiddenBadge(image) {
        if (!image?.hasHiddenReferences) return '';
        if (normalizeVisibility(image.visibility) === 'adult') return '<span class="gallery-hidden-badge">+18</span>';
        return `<span class="gallery-hidden-badge">${escapeHtml(image.hiddenWithoutOwnFilters ? tr(galleryText, 'hiddenWithoutFilters', 'Ukryte bez filtrow') : tr(commonText, 'hidden', 'Ukryte'))}</span>`;
    }

    function galleryPublishButton(image, iconOnly) {
        if (window.OCFeatures?.offlineMode || window.OCFeatures?.community === false || window.OCFeatures?.publications === false) {
            return '';
        }
        const disabled = normalizeVisibility(image?.visibility) === 'hidden';
        const title = disabled ? tr(galleryText, 'hiddenPublishDisabled', 'Ukrytego zdjecia nie mozna opublikowac') : tr(galleryText, 'publishImage', 'Udostepnij zdjecie');
        const label = iconOnly ? '<i class="fa-solid fa-share-nodes"></i>' : escapeHtml(tr(commonText, 'publish', 'Udostepnij'));
        return `<button type="button" class="gallery-publish-image" data-publish-image title="${escapeHtml(title)}" ${disabled ? 'disabled' : ''}>${label}</button>`;
    }

    function imageFromCard(card) {
        try {
            return JSON.parse(card?.dataset.galleryImage || '{}');
        } catch (e) {
            return {};
        }
    }

    function initGalleryPage() {
        bindTagInputs();
        const search = document.getElementById('gallery-search-input');
        const uploadButton = document.getElementById('gallery-upload-button');
        const toggleFilters = document.getElementById('gallery-toggle-filters');
        const dialog = document.getElementById('gallery-dialog');
        const title = document.getElementById('gallery-dialog-title');
        const tagsInput = document.getElementById('gallery-tags-input');
        const save = document.getElementById('gallery-dialog-save');
        const inspector = {
            root: document.getElementById('gallery-inspector'),
            content: document.querySelector('.gallery-inspector-content'),
            usage: document.getElementById('gallery-inspector-usage'),
            usageList: document.getElementById('gallery-inspector-usage-list'),
            tags: document.getElementById('gallery-inspector-tags'),
            warning: document.getElementById('gallery-inspector-warning'),
            visibilityOptions: document.getElementById('gallery-inspector-visibility-radios'),
            replace: document.getElementById('gallery-inspector-replace-btn'),
            delete: document.getElementById('gallery-inspector-delete-btn')
        };
        let mode = null;
        let currentImageId = null;
        let pendingUploadFile = null;
        let selectedImage = null;

        async function refresh() {
            renderGalleryCards(await fetchImages(search?.value || ''));
            if (selectedImage) {
                const nextCard = document.querySelector(`.gallery-card[data-image-id="${selectedImage.id}"]`);
                if (nextCard) {
                    selectGalleryCard(nextCard);
                } else {
                    clearGalleryInspector();
                }
            }
        }

        function renderInspector(image) {
            if (!inspector.root || !image?.id) return;
            selectedImage = image;
            if (inspector.content) inspector.content.hidden = false;
            if (inspector.usage) {
                const count = Number(image.usageCount || 0);
                inspector.usage.textContent = `${count} ${count === 1 ? tr(galleryText, 'usageOne', 'uzycie') : tr(galleryText, 'usageMany', 'uzyc')}`;
            }
            renderUsageList(image).catch(console.error);
            if (inspector.tags) {
                const tags = image.tags || [];
                inspector.tags.innerHTML = tags.length
                    ? tags.map(tag => `<span>${escapeHtml(tag.name || tag.label || tag.slug || tag)}</span>`).join('')
                    : `<em>${escapeHtml(tr(galleryText, 'noFilters', 'Brak filtrow'))}</em>`;
            }
            if (inspector.visibilityOptions) {
                const currentVisibility = normalizeVisibility(image.visibility);
                inspector.visibilityOptions.querySelectorAll('[data-gallery-visibility-option]').forEach(option => {
                    option.checked = normalizeVisibility(option.value) === currentVisibility;
                });
            }
            if (inspector.warning) {
                const ownVisibility = normalizeVisibility(image.visibility);
                let text = '';
                if (ownVisibility === 'adult') {
                    text = tr(galleryText, 'markedAdult', 'Oznaczone jako +18');
                } else if (ownVisibility === 'hidden') {
                    text = tr(galleryText, 'markedHidden', 'Oznaczone jako ukryte');
                } else if (image.hiddenWithoutOwnFilters) {
                    text = tr(galleryText, 'hiddenWithoutOwnFilters', 'Ukryte bez wlasnych filtrow');
                } else if (image.hasHiddenReferences) {
                    text = tr(galleryText, 'relatedHidden', 'Powiazane z ukryta trescia');
                }
                inspector.warning.textContent = text;
                inspector.warning.hidden = text === '';
                inspector.warning.classList.toggle('is-danger', ownVisibility === 'adult' || Boolean(image.hiddenWithoutOwnFilters));
            }
        }

        async function renderUsageList(image) {
            if (!inspector.usageList) return;
            inspector.usageList.innerHTML = `<em>${escapeHtml(tr(commonText, 'loading', 'Ladowanie...'))}</em>`;
            if (!image?.id) {
                inspector.usageList.innerHTML = `<em>${escapeHtml(tr(galleryText, 'noUsageData', 'Brak danych'))}</em>`;
                return;
            }

            const res = await fetch('/api/images/usage?imageId=' + encodeURIComponent(image.id));
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.error || tr(galleryText, 'errorUsage', 'Nie udalo sie pobrac uzyc zdjecia.'));
            }
            const usage = Array.isArray(data.usage) ? data.usage : [];
            if (!usage.length) {
                inspector.usageList.innerHTML = `<em>${escapeHtml(tr(galleryText, 'noUsage', 'Nigdzie nie jest uzywane'))}</em>`;
                return;
            }

            inspector.usageList.innerHTML = usage.map(item => `
                <a href="${escapeHtml(item.href || '#')}">
                    <strong>${escapeHtml(item.title || tr(galleryText, 'untitled', 'Bez nazwy'))}</strong>
                    <span>${escapeHtml([item.type, item.context].filter(Boolean).join(' - '))}</span>
                </a>
            `).join('');
        }

        function clearGalleryInspector() {
            selectedImage = null;
            document.querySelectorAll('.gallery-card.is-selected').forEach(el => el.classList.remove('is-selected'));
            if (inspector.content) inspector.content.hidden = true;
        }

        function selectGalleryCard(card) {
            if (!card) return;
            document.querySelectorAll('.gallery-card.is-selected').forEach(el => el.classList.remove('is-selected'));
            card.classList.add('is-selected');
            const image = imageFromCard(card);
            renderInspector(image);
            if (image.url) openGalleryLightbox(image.url);
        }

        async function deleteGalleryImage(imageId, card = null) {
            const confirmation = prompt(tr(galleryText, 'deletePrompt', 'Aby usunac zdjecie, wpisz kod 123456:'), '');
            if (confirmation === null) return;
            if (confirmation !== '123456') {
                alert(tr(galleryText, 'deleteMismatch', 'Kod potwierdzenia nie zgadza sie.'));
                return;
            }

            const data = await postJson('/api/images/delete', { imageId, forceMissing: true, confirmation });
            const targetCard = card || document.querySelector(`.gallery-card[data-image-id="${imageId}"]`);
            targetCard?.remove();
            if (selectedImage && Number(selectedImage.id) === Number(imageId)) {
                clearGalleryInspector();
                closeGalleryLightbox();
            }
            updateStorageWidget(data.storage);
        }

        uploadButton?.addEventListener('oc:image-selected', () => refresh().catch(console.error));

        search?.addEventListener('input', () => refresh().catch(console.error));
        toggleFilters?.addEventListener('click', () => {
            const enabled = !document.body.classList.contains('gallery-filters-open');
            document.body.classList.toggle('gallery-filters-open', enabled);
            toggleFilters.classList.toggle('active', enabled);
            toggleFilters.innerHTML = enabled
                ? `<i class="fa-solid fa-tags"></i> ${escapeHtml(tr(galleryText, 'hideFilters', 'Ukryj filtry'))}`
                : `<i class="fa-solid fa-tags"></i> ${escapeHtml(tr(galleryText, 'editFilters', 'Edytuj filtry'))}`;
        });
        document.getElementById('gallery-dialog-cancel')?.addEventListener('click', () => {
            dialog.hidden = true;
        });

        save?.addEventListener('click', async () => {
            try {
                if (mode === 'upload') {
                    await uploadImage(pendingUploadFile, tagsInput.value, 'normal');
                } else if (mode === 'tags') {
                    const res = await fetch('/api/images/tags', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ imageId: currentImageId, tags: splitTags(tagsInput.value), visibility: 'normal' })
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error || tr(galleryText, 'errorTags', 'Nie udalo sie zapisac filtrow.'));
                }
                dialog.hidden = true;
                await refresh();
            } catch (error) {
                alert(error.message);
            }
        });

        window.openGalleryDialog = (nextMode, imageId, tags) => {
            mode = nextMode;
            currentImageId = imageId;
            tagsInput.value = tags || '';
            title.textContent = tr(galleryText, 'filtersImage', 'Filtry zdjecia');
            dialog.hidden = false;
        };

        inspector.replace?.addEventListener('click', async () => {
            if (!selectedImage?.id) return;
            const replacement = await openImagePicker({ allowUpload: true, defaultTab: 'gallery' });
            if (!replacement || Number(replacement.id) === Number(selectedImage.id)) return;
            try {
                await postJson('/api/images/merge', {
                    sourceImageId: Number(selectedImage.id),
                    targetImageId: Number(replacement.id)
                });
                clearGalleryInspector();
                closeGalleryLightbox();
                await refresh();
            } catch (error) {
                alert(error.message);
            }
        });

        async function applyInspectorVisibility(nextVisibility) {
            if (!selectedImage?.id) return;
            const previousImage = selectedImage;
            const previousVisibility = normalizeVisibility(previousImage.visibility);
            nextVisibility = normalizeVisibility(nextVisibility);
            if (nextVisibility === previousVisibility) {
                return;
            }

            selectedImage = { ...selectedImage, visibility: nextVisibility };
            renderInspector(selectedImage);

            try {
                const data = await postJson('/api/images/visibility', {
                    imageId: Number(selectedImage.id),
                    visibility: nextVisibility
                });
                selectedImage = data.imageAsset;
                updateAdultImageRegistry(data.imageAsset);
                await refresh();
            } catch (error) {
                selectedImage = previousImage;
                renderInspector(previousImage);
                alert(error.message);
            }
        }

        inspector.visibilityOptions?.querySelectorAll('[data-gallery-visibility-option]').forEach(option => {
            option.addEventListener('change', () => {
                if (option.checked) applyInspectorVisibility(option.value);
            });
        });

        inspector.delete?.addEventListener('click', async () => {
            if (!selectedImage?.id) return;
            try {
                await deleteGalleryImage(Number(selectedImage.id));
            } catch (error) {
                alert(error.message);
            }
        });

        activeGalleryController = {
            selectCard: selectGalleryCard,
            deleteImage: deleteGalleryImage
        };

        initGalleryLightbox();
        bindGalleryActions();
    }

    function updateStorageWidget(storage) {
        if (!storage) return;
        const box = document.querySelector('.storage-info');
        if (!box) return;

        const percent = box.querySelector('.storage-text span:last-child');
        const fill = box.querySelector('.progress-fill');
        const detail = box.querySelector('.storage-detail');
        if (percent) percent.textContent = `${storage.percent}%`;
        if (fill) {
            fill.style.width = `${storage.barPercent}%`;
            fill.style.background = storage.color;
        }
        if (detail) {
            const template = window.OCI18n?.storageUsed || 'Uzyto :used z :limit MB';
            detail.textContent = template.replace(':used', storage.usedMb).replace(':limit', storage.limitMb);
        }
    }

    function bindGalleryActions() {
        document.querySelectorAll('.gallery-card').forEach(card => {
            if (card.dataset.boundGalleryCard) return;
            card.dataset.boundGalleryCard = '1';
            const imageId = Number(card.dataset.imageId);
            card.querySelector('[data-open-lightbox]')?.addEventListener('click', event => {
                event.preventDefault();
                activeGalleryController?.selectCard(card);
            });
            card.querySelector('[data-save-card-tags]')?.addEventListener('click', async () => {
                const input = card.querySelector('[data-gallery-tags-input]');
                const visibility = card.querySelector('[data-gallery-visibility]');
                try {
                    const res = await fetch('/api/images/tags', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            imageId,
                            tags: splitTags(input?.value || ''),
                            visibility: normalizeVisibility(visibility?.value)
                        })
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error || tr(galleryText, 'errorTags', 'Nie udalo sie zapisac filtrow.'));
                    if (input) input.value = tagsToText(data.imageAsset.tags || []);
                    updateAdultImageRegistry(data.imageAsset);
                    renderGalleryCards(await fetchImages(document.getElementById('gallery-search-input')?.value || ''));
                } catch (error) {
                    alert(error.message);
                }
            });
            card.querySelectorAll('[data-publish-image]').forEach(publishButton => {
                publishButton.addEventListener('click', async event => {
                    event.preventDefault();
                    event.stopPropagation();
                    if (publishButton.disabled) return;

                    publishButton.disabled = true;
                    try {
                        const res = await fetch('/api/publications/image/publish', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ imageAssetId: imageId, changeReason: 'refresh' })
                        });
                        const data = await res.json();
                        if (!res.ok || !data.success) throw new Error(data.error || tr(galleryText, 'errorPublish', 'Nie udalo sie opublikowac zdjecia.'));
                        const publicId = data.publication?.publicId || '';
                        if (publicId) {
                            const publicationUrl = '/p/' + encodeURIComponent(publicId);
                            if (window.OCPublicationPreview?.open?.(publicationUrl)) {
                                publishButton.disabled = false;
                                return;
                            }
                            window.location.href = publicationUrl;
                            return;
                        }
                        alert(tr(galleryText, 'published', 'Zdjecie zostalo opublikowane.'));
                    } catch (error) {
                        publishButton.disabled = false;
                        alert(error.message);
                    }
                });
            });
            card.querySelectorAll('[data-delete-image]').forEach(deleteButton => {
                deleteButton.addEventListener('click', async () => {
                if (activeGalleryController?.deleteImage) {
                    try {
                        await activeGalleryController.deleteImage(imageId, card);
                    } catch (error) {
                        alert(error.message);
                    }
                    return;
                }
                const confirmation = prompt(tr(galleryText, 'deletePrompt', 'Aby usunac zdjecie, wpisz kod 123456:'), '');
                if (confirmation === null) return;
                if (confirmation !== '123456') {
                    alert(tr(galleryText, 'deleteMismatch', 'Kod potwierdzenia nie zgadza sie.'));
                    return;
                }
                try {
                    const res = await fetch('/api/images/delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ imageId, forceMissing: true, confirmation })
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.error || tr(galleryText, 'errorDelete', 'Nie udalo sie usunac zdjecia.'));
                    card.remove();
                    updateStorageWidget(data.storage);
                } catch (error) {
                    alert(error.message);
                }
                });
            });
        });
    }

    let galleryZoom = 1;

    function initGalleryLightbox() {
        const lightbox = document.getElementById('gallery-lightbox');
        if (!lightbox || lightbox.dataset.boundLightbox) return;
        lightbox.dataset.boundLightbox = '1';

        lightbox.querySelector('[data-gallery-lightbox-close]')?.addEventListener('click', closeGalleryLightbox);
        lightbox.querySelector('[data-gallery-zoom-in]')?.addEventListener('click', () => setGalleryZoom(galleryZoom + 0.25));
        lightbox.querySelector('[data-gallery-zoom-out]')?.addEventListener('click', () => setGalleryZoom(galleryZoom - 0.25));
        lightbox.querySelector('[data-gallery-zoom-reset]')?.addEventListener('click', () => setGalleryZoom(1));
        lightbox.addEventListener('click', event => {
            if (event.target === lightbox) closeGalleryLightbox();
        });
        window.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !lightbox.hidden) closeGalleryLightbox();
        });
    }

    function openGalleryLightbox(src) {
        const lightbox = document.getElementById('gallery-lightbox');
        const image = document.getElementById('gallery-lightbox-img');
        if (!lightbox || !image || !src) return;
        image.src = src;
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        setGalleryZoom(1);
    }

    function closeGalleryLightbox() {
        const lightbox = document.getElementById('gallery-lightbox');
        const image = document.getElementById('gallery-lightbox-img');
        if (!lightbox || !image) return;
        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        image.src = '';
    }

    function setGalleryZoom(value) {
        const image = document.getElementById('gallery-lightbox-img');
        if (!image) return;
        galleryZoom = Math.min(4, Math.max(0.5, value));
        image.style.transform = `scale(${galleryZoom})`;
    }

    window.OCImageTools = {
        bindTagInputs,
        bindImagePickerButtons,
        openImagePicker,
        initGalleryPage,
        markAdultImages,
        bindPageFlow,
        splitTags,
        tagsToText,
        getLastUploadTags,
        setLastUploadTags,
        uploadBase,
    };

    document.addEventListener('DOMContentLoaded', () => {
        initAdultImageBlur();
        initPageFlow();
        bindTagInputs();
        bindImagePickerButtons();
        bindGalleryImageVirtualization(document);
        bindGalleryCardFlow(document);
    });
})();
