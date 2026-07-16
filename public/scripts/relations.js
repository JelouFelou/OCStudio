(function () {
    const app = document.getElementById('relations-app');
    if (!app) return;

    const boardId = parseInt(app.dataset.boardId, 10);
    const focusCharacterId = app.dataset.focusCharacterId ? parseInt(app.dataset.focusCharacterId, 10) : null;
    const returnUrl = app.dataset.returnUrl || '/relations';
    const uploadBase = '/public/uploads/';

    const els = {
        workspace: document.getElementById('relations-workspace'),
        stage: document.getElementById('relations-stage'),
        lines: document.getElementById('relations-lines'),
        nodes: document.getElementById('relations-nodes'),
        notePopover: document.getElementById('relation-note-popover'),
        empty: document.getElementById('relations-empty-state'),
        toolbar: document.querySelector('.relations-toolbar'),
        panel: document.querySelector('.relations-panel'),
        list: document.getElementById('relations-character-list'),
        search: document.getElementById('relations-character-search'),
        zoomValue: document.getElementById('relations-zoom-value'),
        zoomIn: document.getElementById('relations-zoom-in'),
        zoomOut: document.getElementById('relations-zoom-out'),
        center: document.getElementById('relations-center'),
        saveAndExit: document.getElementById('relations-save-and-exit'),
        modal: document.getElementById('relations-modal'),
        modalTitle: document.getElementById('relations-modal-title'),
        typeGrid: document.getElementById('relations-type-grid'),
        customName: document.getElementById('relations-custom-name'),
        customIcon: document.getElementById('relations-custom-icon'),
        customColor: document.getElementById('relations-custom-color'),
        customPresets: document.getElementById('relations-custom-presets'),
        note: document.getElementById('relations-note'),
        deleteRelation: document.getElementById('relations-delete-relation'),
        cancelModal: document.getElementById('relations-cancel-modal'),
        saveRelation: document.getElementById('relations-save-relation')
    };

    let state = {
        types: [],
        nodes: [],
        relations: [],
        availableCharacters: [],
        customRelationPresets: [],
        ruleCharacters: [],
        worlds: [],
        rules: { excludedWorldIds: [], exceptionCharacterIds: [] },
        scale: 1,
        panX: 0,
        panY: 0,
        modal: null
    };

    function api(url, options) {
        return fetch(url, options).then(async res => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || 'Blad operacji.');
            return data;
        });
    }

    function jsonPost(url, body) {
        return api(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
    }

    function imageSrc(image) {
        if (window.OCDefaults?.characterImageSrc) {
            return window.OCDefaults.characterImageSrc(image);
        }

        const defaultImage = document.body.dataset.defaultCharacterImage || 'default.png';
        const filename = String(image || '').split('/').pop();
        return uploadBase + (filename && !['default.png', 'default.jpg', 'default_dark.png'].includes(filename) ? filename : defaultImage);
    }

    function imageCropStyle(character) {
        const fit = ['cover', 'contain'].includes(character?.image_fit) ? character.image_fit : 'cover';
        const focusX = Number.isFinite(parseFloat(character?.image_focus_x)) ? parseFloat(character.image_focus_x) : 50;
        const focusY = Number.isFinite(parseFloat(character?.image_focus_y)) ? parseFloat(character.image_focus_y) : 50;
        const zoom = Number.isFinite(parseFloat(character?.image_zoom)) ? parseFloat(character.image_zoom) : 1;

        return `--image-focus-x:${Math.max(0, Math.min(100, focusX))}%;--image-focus-y:${Math.max(0, Math.min(100, focusY))}%;--image-zoom:${Math.max(1, zoom)};object-fit:${fit};`;
    }

    function entityKey(characterId, variantId = 0) {
        return `${parseInt(characterId, 10)}:${parseInt(variantId || 0, 10) || 0}`;
    }

    function entityKeyOf(item) {
        return item?.entity_key || entityKey(item?.character_id || item?.id, item?.variant_id);
    }

    function parseEntityKey(key) {
        const [characterId, variantId] = String(key || '0:0').split(':').map(value => parseInt(value, 10) || 0);
        return { characterId, variantId };
    }

    function relationAKey(relation) {
        return relation.character_a_key || entityKey(relation.character_a_id, relation.character_a_variant_id);
    }

    function relationBKey(relation) {
        return relation.character_b_key || entityKey(relation.character_b_id, relation.character_b_variant_id);
    }

    function normalizeRelationEntity(relation, side) {
        const key = side === 'a' ? relationAKey(relation) : relationBKey(relation);
        const entity = parseEntityKey(key);
        return {
            key: entityKey(entity.characterId, entity.variantId),
            characterId: entity.characterId,
            variantId: entity.variantId || 0
        };
    }

    function entityName(key) {
        const node = state.nodes.find(item => entityKeyOf(item) === key);
        const available = state.availableCharacters.find(item => entityKeyOf(item) === key);
        return node?.name || available?.name || 'Postac';
    }

    function applyTransform() {
        els.stage.style.transform = `translate(${state.panX}px, ${state.panY}px) scale(${state.scale})`;
        els.zoomValue.textContent = `${Math.round(state.scale * 100)}%`;
    }

    function screenToWorld(clientX, clientY) {
        const rect = els.workspace.getBoundingClientRect();
        return {
            x: (clientX - rect.left - state.panX) / state.scale,
            y: (clientY - rect.top - state.panY) / state.scale
        };
    }

    async function loadTree() {
        const query = '?boardId=' + encodeURIComponent(boardId);
        const data = await api('/api/relations/tree' + query);
        state = Object.assign(state, data);
        renderAll();
        if (focusCharacterId) focusNode(focusCharacterId);
    }

    function renderAll() {
        renderAvailableCharacters();
        renderNodes();
        renderRelations();
        els.empty.style.display = state.nodes.length ? 'none' : 'flex';
    }

    function renderAvailableCharacters() {
        const term = (els.search.value || '').trim().toLowerCase();
        els.list.innerHTML = '';
        state.availableCharacters
            .filter(c => !term || c.name.toLowerCase().includes(term))
            .forEach(character => {
                const key = entityKeyOf(character);
                const row = document.createElement('div');
                row.className = 'relations-character' + (character.on_tree ? ' is-on-tree' : '');
                row.draggable = true;
                row.dataset.characterId = character.character_id || character.id;
                row.dataset.variantId = character.variant_id || '';
                row.dataset.entityKey = key;
                row.innerHTML = `
                    <div class="relations-character-image">
                        <img src="${imageSrc(character.image)}" alt="" style="${imageCropStyle(character)}">
                    </div>
                    <div>
                        <strong>${escapeHtml(character.name)}</strong>
                        <span>${escapeHtml(character.world_name || 'Folder glowny')}</span>
                    </div>
                `;
                const image = row.querySelector('img');
                image.draggable = false;
                image.onerror = event => { event.currentTarget.src = imageSrc(); };
                row.addEventListener('dragstart', event => {
                    event.dataTransfer.setData('application/x-character-entity', key);
                    event.dataTransfer.setData('application/x-character-id', String(character.character_id || character.id));
                    event.dataTransfer.setData('application/x-character-variant-id', String(character.variant_id || 0));
                    event.dataTransfer.effectAllowed = 'copyMove';
                    row.classList.add('is-dragging');
                    els.workspace.classList.add('is-character-dragging');
                });
                row.addEventListener('dragend', () => {
                    row.classList.remove('is-dragging');
                    els.workspace.classList.remove('is-character-dragging', 'is-character-drag-over');
                });
                els.list.appendChild(row);
            });
    }

    function renderNodes() {
        els.nodes.innerHTML = '';
        state.nodes.forEach(node => {
            const key = entityKeyOf(node);
            const el = document.createElement('div');
            el.className = 'relation-node' + (focusCharacterId === parseInt(node.character_id, 10) ? ' is-focus' : '');
            el.dataset.characterId = node.character_id;
            el.dataset.variantId = node.variant_id || '';
            el.dataset.entityKey = key;
            el.style.left = `${parseFloat(node.position_x)}px`;
            el.style.top = `${parseFloat(node.position_y)}px`;
            el.innerHTML = `
                <div class="relation-node-image">
                    <img src="${imageSrc(node.image)}" alt="" style="${imageCropStyle(node)}">
                </div>
                <strong>${escapeHtml(node.name)}</strong>
            `;
            el.querySelector('img').onerror = event => { event.currentTarget.src = imageSrc(); };
            installNodeDrag(el, node);
            els.nodes.appendChild(el);
        });
    }

    function renderRelations() {
        els.lines.innerHTML = '';
        const nodeMap = new Map(state.nodes.map(node => [entityKeyOf(node), node]));
        state.visibleRelations = [];
        state.relations.forEach(relation => {
            const a = nodeMap.get(relationAKey(relation));
            const b = nodeMap.get(relationBKey(relation));
            if (!a || !b) return;
            state.visibleRelations.push(relation);

            const nodeWidth = 168;
            const nodeHeight = 188;
            const ax = parseFloat(a.position_x) + nodeWidth / 2;
            const ay = parseFloat(a.position_y) + nodeHeight / 2;
            const bx = parseFloat(b.position_x) + nodeWidth / 2;
            const by = parseFloat(b.position_y) + nodeHeight / 2;
            const mx = (ax + bx) / 2;
            const my = (ay + by) / 2;
            const customRelation = boolFlag(relation.is_custom);
            const label = customRelation && relation.custom_name ? relation.custom_name : relation.type_name;
            const icon = customRelation && relation.custom_icon ? relation.custom_icon : emojiForRelation(relation.code);
            const visibleLabel = icon ? `${icon} ${label}` : label;
            const labelWidth = relationLabelWidth(visibleLabel);

            const group = svgEl('g', { class: 'relation-link' });
            group.style.setProperty('--relation-color', customRelation && relation.custom_color_hex ? relation.custom_color_hex : relation.color_hex);
            const hit = svgEl('line', { x1: ax, y1: ay, x2: bx, y2: by, stroke: 'transparent', 'stroke-width': 22, class: 'relation-line-hit' });
            const line = svgEl('line', { x1: ax, y1: ay, x2: bx, y2: by, class: 'relation-line' });
            const pill = svgEl('rect', { x: mx - labelWidth / 2, y: my - 16, width: labelWidth, height: 32, rx: 8, class: 'relation-label-bg' });
            const text = svgEl('text', { x: mx, y: my + 5, 'text-anchor': 'middle', class: 'relation-label-text' });
            text.textContent = visibleLabel;
            group.append(hit, line, pill, text);

            const handleRelationClick = event => {
                event.stopPropagation();
                if (relation.note) {
                    showNotePopover(relation.note, mx, my);
                    return;
                }
                openRelationModal(relation);
            };
            const handleRelationDoubleClick = event => {
                event.stopPropagation();
                hideNotePopover();
                openRelationModal(relation);
            };

            [group, hit, pill, text].forEach(element => {
                element.addEventListener('click', handleRelationClick);
                element.addEventListener('dblclick', handleRelationDoubleClick);
            });
            els.lines.appendChild(group);
        });
    }

    function showNotePopover(note, x, y) {
        if (!els.notePopover) return;
        const margin = 14;
        const pointerGap = 18;
        const screenX = state.panX + x * state.scale;
        const screenY = state.panY + y * state.scale;
        const workspaceRect = els.workspace.getBoundingClientRect();
        const toolbarRect = els.toolbar ? els.toolbar.getBoundingClientRect() : null;
        const safeTop = toolbarRect ? Math.max(margin, toolbarRect.bottom - workspaceRect.top + margin) : margin;

        els.notePopover.innerHTML = '';
        const body = document.createElement('div');
        body.className = 'relation-note-popover-body';
        body.textContent = note;
        els.notePopover.appendChild(body);
        els.notePopover.classList.remove('is-below');
        els.notePopover.style.left = `${screenX}px`;
        els.notePopover.style.top = `${screenY}px`;
        body.style.maxHeight = `${Math.max(160, workspaceRect.height - safeTop - margin - pointerGap)}px`;
        els.notePopover.hidden = false;

        const popoverRect = els.notePopover.getBoundingClientRect();
        const minX = margin + popoverRect.width / 2;
        const maxX = workspaceRect.width - margin - popoverRect.width / 2;
        const clampedX = Math.min(Math.max(screenX, minX), Math.max(minX, maxX));
        let clampedY = screenY;

        if (screenY - popoverRect.height - pointerGap < safeTop) {
            els.notePopover.classList.add('is-below');
            clampedY = Math.min(screenY, workspaceRect.height - margin - popoverRect.height - pointerGap);
            clampedY = Math.max(safeTop, clampedY);
        } else {
            clampedY = Math.max(screenY, safeTop + popoverRect.height + pointerGap);
        }

        els.notePopover.style.left = `${clampedX}px`;
        els.notePopover.style.top = `${clampedY}px`;
    }

    function hideNotePopover() {
        if (els.notePopover) {
            els.notePopover.hidden = true;
        }
    }

    function installNodeDrag(el, node) {
        let dragging = null;
        let dropTargetEl = null;

        el.addEventListener('dragover', event => event.preventDefault());
        el.addEventListener('drop', async event => {
            event.preventDefault();
            event.stopPropagation();
            const sourceKey = event.dataTransfer.getData('application/x-character-entity')
                || entityKey(event.dataTransfer.getData('application/x-character-id'), event.dataTransfer.getData('application/x-character-variant-id'));
            const targetKey = entityKeyOf(node);
            const sourceEntity = parseEntityKey(sourceKey);
            const targetEntity = parseEntityKey(targetKey);
            if (!sourceEntity.characterId || sourceKey === targetKey) return;
            const sourceOnTree = state.nodes.some(n => entityKeyOf(n) === sourceKey);
            if (!sourceOnTree) {
                const pos = {
                    x: parseFloat(node.position_x) + 170,
                    y: parseFloat(node.position_y)
                };
                await addNode(sourceEntity.characterId, sourceEntity.variantId, pos.x, pos.y);
            }
            openRelationModal(findExistingRelation(sourceKey, targetKey) || {
                character_a_id: sourceEntity.characterId,
                character_a_variant_id: sourceEntity.variantId || null,
                character_a_key: sourceKey,
                character_b_id: targetEntity.characterId,
                character_b_variant_id: targetEntity.variantId || null,
                character_b_key: targetKey
            });
        });

        el.addEventListener('pointerdown', event => {
            if (event.button !== 0) return;
            event.preventDefault();
            dragging = {
                startX: event.clientX,
                startY: event.clientY,
                nodeX: parseFloat(node.position_x),
                nodeY: parseFloat(node.position_y),
                moved: false
            };
            el.setPointerCapture(event.pointerId);
            el.style.cursor = 'grabbing';
            el.classList.add('is-dragging');
            els.workspace.classList.add('is-node-dragging');
        });

        el.addEventListener('pointermove', event => {
            if (!dragging) return;
            const dx = (event.clientX - dragging.startX) / state.scale;
            const dy = (event.clientY - dragging.startY) / state.scale;
            dragging.moved = dragging.moved || Math.abs(event.clientX - dragging.startX) > 4 || Math.abs(event.clientY - dragging.startY) > 4;
            node.position_x = dragging.nodeX + dx;
            node.position_y = dragging.nodeY + dy;
            el.style.left = `${node.position_x}px`;
            el.style.top = `${node.position_y}px`;
            dropTargetEl?.classList.remove('is-drop-target');
            dropTargetEl = findNodeUnderPointer(el, event.clientX, event.clientY);
            dropTargetEl?.classList.add('is-drop-target');
            els.panel?.classList.toggle('is-drop-target', isPointerOverPalette(event.clientX, event.clientY));
            renderRelations();
        });

        el.addEventListener('pointerup', async event => {
            if (!dragging) return;
            const finishedDrag = dragging;
            dragging = null;
            el.releasePointerCapture(event.pointerId);
            el.style.cursor = 'grab';
            el.classList.remove('is-dragging');
            els.workspace.classList.remove('is-node-dragging');
            dropTargetEl?.classList.remove('is-drop-target');
            els.panel?.classList.remove('is-drop-target');
            const targetEl = finishedDrag.moved ? findNodeUnderPointer(el, event.clientX, event.clientY) : null;

            if (targetEl) {
                node.position_x = finishedDrag.nodeX;
                node.position_y = finishedDrag.nodeY;
                el.style.left = `${node.position_x}px`;
                el.style.top = `${node.position_y}px`;
                renderRelations();
                const sourceKey = entityKeyOf(node);
                const targetKey = targetEl.dataset.entityKey || entityKey(targetEl.dataset.characterId, targetEl.dataset.variantId);
                const sourceEntity = parseEntityKey(sourceKey);
                const targetEntity = parseEntityKey(targetKey);
                openRelationModal(findExistingRelation(sourceKey, targetKey) || {
                    character_a_id: sourceEntity.characterId,
                    character_a_variant_id: sourceEntity.variantId || null,
                    character_a_key: sourceKey,
                    character_b_id: targetEntity.characterId,
                    character_b_variant_id: targetEntity.variantId || null,
                    character_b_key: targetKey
                });
                return;
            }

            if (finishedDrag.moved && isPointerOverPalette(event.clientX, event.clientY)) {
                node.position_x = finishedDrag.nodeX;
                node.position_y = finishedDrag.nodeY;
                el.style.left = `${node.position_x}px`;
                el.style.top = `${node.position_y}px`;
                renderRelations();
                await removeNode(parseInt(node.character_id, 10), parseInt(node.variant_id || 0, 10)).catch(alertError);
                return;
            }

            await jsonPost('/api/relations/node/position', {
                boardId,
                characterId: parseInt(node.character_id, 10),
                variantId: parseInt(node.variant_id || 0, 10),
                x: parseFloat(node.position_x),
                y: parseFloat(node.position_y)
            }).catch(alertError);
        });

        el.addEventListener('pointercancel', () => {
            dragging = null;
            el.classList.remove('is-dragging');
            els.workspace.classList.remove('is-node-dragging');
            dropTargetEl?.classList.remove('is-drop-target');
            els.panel?.classList.remove('is-drop-target');
        });
    }

    function findNodeUnderPointer(sourceEl, clientX, clientY) {
        sourceEl.style.pointerEvents = 'none';
        const target = document.elementFromPoint(clientX, clientY);
        sourceEl.style.pointerEvents = '';
        const nodeEl = target ? target.closest('.relation-node') : null;
        if (!nodeEl || nodeEl === sourceEl) {
            return null;
        }

        return nodeEl;
    }

    function isPointerOverPalette(clientX, clientY) {
        if (!els.panel) return false;
        const rect = els.panel.getBoundingClientRect();
        return clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom;
    }

    function findExistingRelation(entityAKey, entityBKey) {
        const [a, b] = [String(entityAKey), String(entityBKey)].sort();
        return state.relations.find(relation => {
            const [relA, relB] = [relationAKey(relation), relationBKey(relation)].sort();
            return relA === a && relB === b;
        }) || null;
    }

    async function addNode(characterId, variantId, x, y) {
        await jsonPost('/api/relations/node', { boardId, characterId, variantId: variantId || 0, x, y });
        await loadTree();
    }

    async function removeNode(characterId, variantId = 0) {
        await jsonPost('/api/relations/node/remove', { boardId, characterId, variantId: variantId || 0 });
        await loadTree();
    }

    function openRelationModal(relation) {
        const a = normalizeRelationEntity(relation, 'a');
        const b = normalizeRelationEntity(relation, 'b');
        state.modal = {
            ...relation,
            character_a_id: a.characterId,
            character_a_variant_id: a.variantId || null,
            character_a_key: a.key,
            character_b_id: b.characterId,
            character_b_variant_id: b.variantId || null,
            character_b_key: b.key
        };
        hideNotePopover();
        const existing = Boolean(relation.id);
        els.modalTitle.textContent = `${existing ? 'Edytuj relacje' : 'Dodaj relacje'}: ${entityName(a.key)} <-> ${entityName(b.key)}`;
        els.customName.value = relation.custom_name || '';
        els.customIcon.value = relation.custom_icon || '';
        els.customColor.value = relation.custom_color_hex || '#8E44AD';
        els.note.value = relation.note || '';
        els.deleteRelation.style.display = existing ? 'inline-block' : 'none';
        renderRelationTypes(relation.relation_type_id || (state.types[0] && state.types[0].id));
        syncCustomNameVisibility();
        els.modal.classList.add('is-open');
    }

    function renderRelationTypes(selectedTypeId) {
        els.typeGrid.innerHTML = '';
        state.types.forEach(type => {
            const selected = parseInt(type.id, 10) === parseInt(selectedTypeId, 10);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'relations-type-button' + (selected ? ' is-selected' : '');
            btn.dataset.typeId = type.id;
            btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
            btn.style.setProperty('--relation-color', type.color_hex);
            btn.classList.toggle('is-custom-type', isCustomType(type));
            btn.innerHTML = `<i class="${escapeHtml(type.icon)}" style="color:${escapeHtml(type.color_hex)}"></i><span>${escapeHtml(type.name)}</span><i class="fa-solid fa-check relations-type-check"></i>`;
            btn.addEventListener('click', () => {
                renderRelationTypes(type.id);
                syncCustomNameVisibility();
            });
            els.typeGrid.appendChild(btn);
        });
    }

    function syncCustomNameVisibility() {
        const selected = els.typeGrid.querySelector('.is-selected');
        const type = state.types.find(item => parseInt(item.id, 10) === parseInt(selected?.dataset.typeId || 0, 10));
        const isCustom = type && isCustomType(type);
        const field = els.customName.closest('.relations-form-field');
        if (field) {
            field.style.display = isCustom ? 'grid' : 'none';
        }
        document.querySelector('.relations-custom-options')?.classList.toggle('is-visible', Boolean(isCustom));
        renderCustomPresets(isCustom);
        if (!isCustom) {
            els.customName.value = '';
            els.customIcon.value = '';
            els.customColor.value = '#8E44AD';
        }
    }

    function renderCustomPresets(isCustom) {
        if (!els.customPresets) return;
        els.customPresets.innerHTML = '';
        els.customPresets.hidden = !isCustom;
        if (!isCustom || !state.modal) return;
        const pairNsfw = relationPairIsNsfw(relationAKey(state.modal), relationBKey(state.modal));
        const presets = (state.customRelationPresets || []).filter(preset => boolFlag(preset.is_nsfw) === pairNsfw);
        if (!presets.length) return;
        const label = document.createElement('span');
        label.className = 'relations-custom-presets-label';
        label.textContent = 'Poprzednie custom relacje';
        els.customPresets.appendChild(label);
        presets.forEach(preset => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.style.setProperty('--relation-color', preset.custom_color_hex || '#8E44AD');
            btn.innerHTML = `<span>${escapeHtml(preset.custom_icon || '')}</span>${escapeHtml(preset.custom_name)}`;
            btn.addEventListener('click', () => {
                els.customName.value = preset.custom_name || '';
                els.customIcon.value = preset.custom_icon || '';
                els.customColor.value = preset.custom_color_hex || '#8E44AD';
            });
            els.customPresets.appendChild(btn);
        });
    }

    function relationPairIsNsfw(aKey, bKey) {
        const keys = [aKey, bKey];
        return keys.every(key => {
            const node = state.nodes.find(item => entityKeyOf(item) === key);
            const available = state.availableCharacters.find(item => entityKeyOf(item) === key);
            return boolFlag(node?.is_nsfw || available?.is_nsfw);
        });
    }

    function isCustomType(type) {
        return Boolean(type && (boolFlag(type.is_custom) || type.code === 'custom'));
    }

    function closeModal() {
        state.modal = null;
        els.modal.classList.remove('is-open');
    }

    async function saveModalRelation() {
        const selected = els.typeGrid.querySelector('.is-selected');
        if (!state.modal || !selected) return;
        const a = normalizeRelationEntity(state.modal, 'a');
        const b = normalizeRelationEntity(state.modal, 'b');
        try {
            await jsonPost('/api/relations', {
                characterAId: a.characterId,
                characterAVariantId: a.variantId,
                characterBId: b.characterId,
                characterBVariantId: b.variantId,
                relationTypeId: parseInt(selected.dataset.typeId, 10),
                customName: els.customName.value,
                customIcon: els.customIcon.value,
                customColorHex: els.customColor.value,
                note: els.note.value
            });
        } catch (error) {
            alertError(error);
            return;
        }
        closeModal();
        await loadTree();
    }

    async function deleteModalRelation() {
        if (!state.modal || !state.modal.id) return;
        try {
            await jsonPost('/api/relations/delete', { relationId: parseInt(state.modal.id, 10) });
        } catch (error) {
            alertError(error);
            return;
        }
        closeModal();
        await loadTree();
    }

    function focusNode(characterId) {
        const node = state.nodes.find(n => parseInt(n.character_id, 10) === characterId);
        if (!node) return;
        const rect = els.workspace.getBoundingClientRect();
        state.panX = rect.width / 2 - (parseFloat(node.position_x) + 66) * state.scale;
        state.panY = rect.height / 2 - (parseFloat(node.position_y) + 53) * state.scale;
        applyTransform();
    }

    function centerTree() {
        if (!state.nodes.length) {
            state.panX = 40;
            state.panY = 80;
            applyTransform();
            return;
        }
        const avgX = state.nodes.reduce((sum, n) => sum + parseFloat(n.position_x), 0) / state.nodes.length;
        const avgY = state.nodes.reduce((sum, n) => sum + parseFloat(n.position_y), 0) / state.nodes.length;
        const rect = els.workspace.getBoundingClientRect();
        state.panX = rect.width / 2 - (avgX + 66) * state.scale;
        state.panY = rect.height / 2 - (avgY + 53) * state.scale;
        applyTransform();
    }

    function installWorkspaceEvents() {
        els.workspace.addEventListener('dragover', event => event.preventDefault());
        els.workspace.addEventListener('dragenter', event => {
            const types = Array.from(event.dataTransfer.types);
            if (!types.includes('application/x-character-entity') && !types.includes('application/x-character-id')) return;
            els.workspace.classList.add('is-character-drag-over');
        });
        els.workspace.addEventListener('dragleave', event => {
            if (els.workspace.contains(event.relatedTarget)) return;
            els.workspace.classList.remove('is-character-drag-over');
        });
        els.workspace.addEventListener('drop', async event => {
            event.preventDefault();
            hideNotePopover();
            els.workspace.classList.remove('is-character-dragging', 'is-character-drag-over');
            const droppedKey = event.dataTransfer.getData('application/x-character-entity')
                || entityKey(event.dataTransfer.getData('application/x-character-id'), event.dataTransfer.getData('application/x-character-variant-id'));
            const droppedEntity = parseEntityKey(droppedKey);
            if (!droppedEntity.characterId) return;
            const overNode = event.target.closest('.relation-node');
            if (overNode) return;
            const pos = screenToWorld(event.clientX, event.clientY);
            await addNode(droppedEntity.characterId, droppedEntity.variantId, pos.x - 66, pos.y - 53).catch(alertError);
        });

        let panning = null;
        els.workspace.addEventListener('pointerdown', event => {
            if (
                event.button !== 0
                || event.target.closest('.relation-node')
                || event.target.closest('.relations-toolbar')
                || event.target.closest('.relation-link')
            ) return;
            event.preventDefault();
            hideNotePopover();
            panning = { x: event.clientX, y: event.clientY, panX: state.panX, panY: state.panY };
            els.workspace.setPointerCapture(event.pointerId);
            els.workspace.classList.add('is-panning');
        });
        els.workspace.addEventListener('pointermove', event => {
            if (!panning) return;
            event.preventDefault();
            state.panX = panning.panX + event.clientX - panning.x;
            state.panY = panning.panY + event.clientY - panning.y;
            applyTransform();
        });
        els.workspace.addEventListener('pointerup', event => {
            if (!panning) return;
            event.preventDefault();
            panning = null;
            els.workspace.releasePointerCapture(event.pointerId);
            els.workspace.classList.remove('is-panning');
        });
        els.workspace.addEventListener('pointercancel', () => {
            panning = null;
            els.workspace.classList.remove('is-panning');
        });

        els.workspace.addEventListener('wheel', event => {
            event.preventDefault();
            hideNotePopover();
            const before = screenToWorld(event.clientX, event.clientY);
            const factor = event.deltaY < 0 ? 1.08 : 0.92;
            state.scale = Math.max(0.35, Math.min(1.9, state.scale * factor));
            const rect = els.workspace.getBoundingClientRect();
            state.panX = event.clientX - rect.left - before.x * state.scale;
            state.panY = event.clientY - rect.top - before.y * state.scale;
            applyTransform();
        }, { passive: false });
    }

    function installControls() {
        els.search.addEventListener('input', renderAvailableCharacters);
        els.zoomIn.addEventListener('click', () => { state.scale = Math.min(1.9, state.scale + 0.1); applyTransform(); });
        els.zoomOut.addEventListener('click', () => { state.scale = Math.max(0.35, state.scale - 0.1); applyTransform(); });
        els.center.addEventListener('click', centerTree);
        els.cancelModal.addEventListener('click', closeModal);
        els.modal.addEventListener('click', event => { if (event.target === els.modal) closeModal(); });
        els.saveRelation.addEventListener('click', saveModalRelation);
        els.deleteRelation.addEventListener('click', deleteModalRelation);

        els.saveAndExit.addEventListener('click', () => {
            window.location.href = returnUrl;
        });

        document.addEventListener('click', event => {
            if (event.target.closest('.relation-note-popover')) return;
            hideNotePopover();
        });
        window.addEventListener('keydown', event => {
            if (event.key === 'Escape') hideNotePopover();
        });
    }

    function svgEl(name, attrs) {
        const el = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.entries(attrs || {}).forEach(([key, value]) => el.setAttribute(key, value));
        return el;
    }

    function relationLabelWidth(label) {
        const text = String(label || '');
        let weight = 0;
        for (const char of text) {
            weight += char.charCodeAt(0) > 255 ? 15 : 7.4;
        }
        return Math.max(72, Math.min(520, Math.ceil(weight + 30)));
    }

    function emojiForRelation(code) {
        return ({
            family: '🏠',
            partners: '💕',
            friends: '🤝',
            allies: '🛡️',
            rivals: '⚡',
            enemies: '💀'
        })[String(code || '')] || '';
    }

    function boolFlag(value) {
        return value === true || value === 1 || value === '1' || value === 't' || value === 'true';
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

    function alertError(error) {
        alert(error.message || 'Wystapil blad.');
    }

    installWorkspaceEvents();
    installControls();
    state.panX = 60;
    state.panY = 90;
    applyTransform();
    loadTree().catch(alertError);
})();
