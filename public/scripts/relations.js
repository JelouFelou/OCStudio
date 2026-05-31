(function () {
    const app = document.getElementById('relations-app');
    if (!app) return;

    const boardId = parseInt(app.dataset.boardId, 10);
    const focusCharacterId = app.dataset.focusCharacterId ? parseInt(app.dataset.focusCharacterId, 10) : null;
    const uploadBase = '/public/uploads/';
    const defaultImage = document.body.dataset.theme === 'dark' ? 'default_dark.png' : 'default.png';

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
        return uploadBase + (image || defaultImage);
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
                const row = document.createElement('div');
                row.className = 'relations-character' + (character.on_tree ? ' is-on-tree' : '');
                row.draggable = true;
                row.dataset.characterId = character.id;
                row.innerHTML = `
                    <img src="${imageSrc(character.image)}" alt="">
                    <div>
                        <strong>${escapeHtml(character.name)}</strong>
                        <span>${escapeHtml(character.world_name || 'Folder glowny')}</span>
                    </div>
                `;
                const image = row.querySelector('img');
                image.draggable = false;
                image.onerror = event => { event.currentTarget.src = uploadBase + defaultImage; };
                row.addEventListener('dragstart', event => {
                    event.dataTransfer.setData('application/x-character-id', String(character.id));
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
            const el = document.createElement('div');
            el.className = 'relation-node' + (focusCharacterId === parseInt(node.character_id, 10) ? ' is-focus' : '');
            el.dataset.characterId = node.character_id;
            el.style.left = `${parseFloat(node.position_x)}px`;
            el.style.top = `${parseFloat(node.position_y)}px`;
            el.innerHTML = `
                <img src="${imageSrc(node.image)}" alt="">
                <strong>${escapeHtml(node.name)}</strong>
                <small>Przeciagnij na postac albo do panelu</small>
            `;
            el.querySelector('img').onerror = event => { event.currentTarget.src = uploadBase + defaultImage; };
            installNodeDrag(el, node);
            els.nodes.appendChild(el);
        });
    }

    function renderRelations() {
        els.lines.innerHTML = '';
        const nodeMap = new Map(state.nodes.map(node => [parseInt(node.character_id, 10), node]));
        state.visibleRelations = [];
        state.relations.forEach(relation => {
            const a = nodeMap.get(parseInt(relation.character_a_id, 10));
            const b = nodeMap.get(parseInt(relation.character_b_id, 10));
            if (!a || !b) return;
            state.visibleRelations.push(relation);

            const ax = parseFloat(a.position_x) + 66;
            const ay = parseFloat(a.position_y) + 53;
            const bx = parseFloat(b.position_x) + 66;
            const by = parseFloat(b.position_y) + 53;
            const mx = (ax + bx) / 2;
            const my = (ay + by) / 2;
            const label = relation.is_custom && relation.custom_name ? relation.custom_name : relation.type_name;

            const group = svgEl('g', { class: 'relation-link' });
            group.style.setProperty('--relation-color', relation.color_hex);
            const hit = svgEl('line', { x1: ax, y1: ay, x2: bx, y2: by, stroke: 'transparent', 'stroke-width': 22, class: 'relation-line-hit' });
            const line = svgEl('line', { x1: ax, y1: ay, x2: bx, y2: by, class: 'relation-line' });
            const pill = svgEl('rect', { x: mx - 54, y: my - 15, width: 108, height: 30, rx: 8, class: 'relation-label-bg' });
            const text = svgEl('text', { x: mx, y: my + 5, 'text-anchor': 'middle', class: 'relation-label-text' });
            text.textContent = label.length > 13 ? label.slice(0, 12) + '...' : label;
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
            const sourceId = parseInt(event.dataTransfer.getData('application/x-character-id'), 10);
            const targetId = parseInt(node.character_id, 10);
            if (!sourceId || sourceId === targetId) return;
            const sourceOnTree = state.nodes.some(n => parseInt(n.character_id, 10) === sourceId);
            if (!sourceOnTree) {
                const pos = {
                    x: parseFloat(node.position_x) + 170,
                    y: parseFloat(node.position_y)
                };
                await addNode(sourceId, pos.x, pos.y);
            }
            openRelationModal(findExistingRelation(sourceId, targetId) || { character_a_id: sourceId, character_b_id: targetId });
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
                const sourceId = parseInt(node.character_id, 10);
                const targetId = parseInt(targetEl.dataset.characterId, 10);
                openRelationModal(findExistingRelation(sourceId, targetId) || {
                    character_a_id: parseInt(node.character_id, 10),
                    character_b_id: parseInt(targetEl.dataset.characterId, 10)
                });
                return;
            }

            if (finishedDrag.moved && isPointerOverPalette(event.clientX, event.clientY)) {
                node.position_x = finishedDrag.nodeX;
                node.position_y = finishedDrag.nodeY;
                el.style.left = `${node.position_x}px`;
                el.style.top = `${node.position_y}px`;
                renderRelations();
                await removeNode(parseInt(node.character_id, 10)).catch(alertError);
                return;
            }

            await jsonPost('/api/relations/node/position', {
                boardId,
                characterId: parseInt(node.character_id, 10),
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

    function findExistingRelation(characterAId, characterBId) {
        const a = Math.min(parseInt(characterAId, 10), parseInt(characterBId, 10));
        const b = Math.max(parseInt(characterAId, 10), parseInt(characterBId, 10));
        return state.relations.find(relation => {
            const relA = Math.min(parseInt(relation.character_a_id, 10), parseInt(relation.character_b_id, 10));
            const relB = Math.max(parseInt(relation.character_a_id, 10), parseInt(relation.character_b_id, 10));
            return relA === a && relB === b;
        }) || null;
    }

    async function addNode(characterId, x, y) {
        await jsonPost('/api/relations/node', { boardId, characterId, x, y });
        await loadTree();
    }

    async function removeNode(characterId) {
        await jsonPost('/api/relations/node/remove', { boardId, characterId });
        await loadTree();
    }

    function openRelationModal(relation) {
        state.modal = relation;
        hideNotePopover();
        const existing = Boolean(relation.id);
        els.modalTitle.textContent = existing ? 'Edytuj relacje' : 'Dodaj relacje';
        els.customName.value = relation.custom_name || '';
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
        if (!isCustom) {
            els.customName.value = '';
        }
    }

    function isCustomType(type) {
        return Boolean(type && (type.is_custom === true || type.is_custom === 't' || type.is_custom === 1 || type.is_custom === '1' || type.code === 'custom'));
    }

    function closeModal() {
        state.modal = null;
        els.modal.classList.remove('is-open');
    }

    async function saveModalRelation() {
        const selected = els.typeGrid.querySelector('.is-selected');
        if (!state.modal || !selected) return;
        try {
            await jsonPost('/api/relations', {
                characterAId: parseInt(state.modal.character_a_id, 10),
                characterBId: parseInt(state.modal.character_b_id, 10),
                relationTypeId: parseInt(selected.dataset.typeId, 10),
                customName: els.customName.value,
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
            if (!Array.from(event.dataTransfer.types).includes('application/x-character-id')) return;
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
            const characterId = parseInt(event.dataTransfer.getData('application/x-character-id'), 10);
            if (!characterId) return;
            const overNode = event.target.closest('.relation-node');
            if (overNode) return;
            const pos = screenToWorld(event.clientX, event.clientY);
            await addNode(characterId, pos.x - 66, pos.y - 53).catch(alertError);
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
            window.location.href = '/relations';
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
