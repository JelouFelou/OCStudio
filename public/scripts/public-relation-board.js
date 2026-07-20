(function () {
    const boards = document.querySelectorAll('[data-public-relation-board]');
    if (!boards.length) return;

    const NODE_WIDTH = 168;
    const NODE_HEIGHT = 188;

    function parseJson(value) {
        try {
            const parsed = JSON.parse(value || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function svgEl(name, attrs = {}) {
        const element = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, String(value)));
        return element;
    }

    function labelWidth(label) {
        return Math.max(92, Math.min(240, String(label || '').length * 8 + 34));
    }

    function showNote(popover, workspace, stage, note, x, y) {
        if (!popover || !note) return;
        popover.innerHTML = '';
        const body = document.createElement('div');
        body.className = 'relation-note-popover-body';
        body.textContent = note;
        popover.appendChild(body);
        popover.classList.remove('is-below');
        popover.hidden = false;

        const stageRect = stage.getBoundingClientRect();
        const workspaceRect = workspace.getBoundingClientRect();
        const scale = stage.offsetWidth ? stageRect.width / stage.offsetWidth : 1;
        const left = stageRect.left - workspaceRect.left + x * scale;
        const top = stageRect.top - workspaceRect.top + y * scale;
        popover.style.left = `${Math.max(18, Math.min(workspaceRect.width - 18, left))}px`;
        popover.style.top = `${top}px`;

        requestAnimationFrame(() => {
            const noteRect = popover.getBoundingClientRect();
            const nextTop = stageRect.top - workspaceRect.top + y * scale;
            popover.classList.toggle('is-below', nextTop - noteRect.height < 12);
        });
    }

    function hideNote(popover) {
        if (popover) popover.hidden = true;
    }

    function applyTransform(stage, zoomValue, state) {
        stage.style.transform = `translate(${state.panX}px, ${state.panY}px) scale(${state.scale})`;
        if (zoomValue) {
            zoomValue.textContent = `${Math.round(state.scale * 100)}%`;
        }
    }

    function screenToWorld(workspace, state, clientX, clientY) {
        const rect = workspace.getBoundingClientRect();
        return {
            x: (clientX - rect.left - state.panX) / state.scale,
            y: (clientY - rect.top - state.panY) / state.scale
        };
    }

    function fitStage(workspace, stage, zoomValue, nodes, state) {
        if (!nodes.length) return;
        const minX = Math.min(...nodes.map(node => Number(node.x || 0)));
        const minY = Math.min(...nodes.map(node => Number(node.y || 0)));
        const maxX = Math.max(...nodes.map(node => Number(node.x || 0) + NODE_WIDTH));
        const maxY = Math.max(...nodes.map(node => Number(node.y || 0) + NODE_HEIGHT));
        const width = Math.max(1, maxX - minX);
        const height = Math.max(1, maxY - minY);
        const rect = workspace.getBoundingClientRect();
        const toolbarHeight = workspace.querySelector('.relations-toolbar')?.offsetHeight || 0;
        const availableWidth = Math.max(320, rect.width - 48);
        const availableHeight = Math.max(240, rect.height - toolbarHeight - 48);
        const scale = Math.min(1.05, Math.max(0.45, Math.min(availableWidth / width, availableHeight / height)));
        const panX = Math.round((rect.width - width * scale) / 2 - minX * scale);
        const panY = Math.round(toolbarHeight + (availableHeight - height * scale) / 2 - minY * scale + 24);
        state.scale = scale;
        state.panX = panX;
        state.panY = panY;
        applyTransform(stage, zoomValue, state);
    }

    function zoomAt(workspace, stage, zoomValue, state, clientX, clientY, factor) {
        const before = screenToWorld(workspace, state, clientX, clientY);
        const rect = workspace.getBoundingClientRect();
        state.scale = Math.max(0.35, Math.min(1.9, state.scale * factor));
        state.panX = clientX - rect.left - before.x * state.scale;
        state.panY = clientY - rect.top - before.y * state.scale;
        applyTransform(stage, zoomValue, state);
    }

    function installNavigation(workspace, stage, zoomValue, popover, nodes, state, controls) {
        let panning = null;

        workspace.addEventListener('pointerdown', event => {
            if (
                event.button !== 0
                || event.target.closest('.relations-toolbar')
                || event.target.closest('.public-relation-link')
                || event.target.closest('.relation-note-popover')
            ) return;

            event.preventDefault();
            hideNote(popover);
            panning = {
                pointerId: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                panX: state.panX,
                panY: state.panY
            };
            workspace.setPointerCapture(event.pointerId);
            workspace.classList.add('is-panning');
        });

        workspace.addEventListener('pointermove', event => {
            if (!panning) return;
            event.preventDefault();
            state.panX = panning.panX + event.clientX - panning.x;
            state.panY = panning.panY + event.clientY - panning.y;
            applyTransform(stage, zoomValue, state);
        });

        workspace.addEventListener('pointerup', event => {
            if (!panning) return;
            event.preventDefault();
            panning = null;
            workspace.releasePointerCapture(event.pointerId);
            workspace.classList.remove('is-panning');
        });

        workspace.addEventListener('pointercancel', () => {
            panning = null;
            workspace.classList.remove('is-panning');
        });

        workspace.addEventListener('wheel', event => {
            event.preventDefault();
            hideNote(popover);
            zoomAt(workspace, stage, zoomValue, state, event.clientX, event.clientY, event.deltaY < 0 ? 1.08 : 0.92);
        }, { passive: false });

        controls.zoomIn?.addEventListener('click', () => {
            const rect = workspace.getBoundingClientRect();
            zoomAt(workspace, stage, zoomValue, state, rect.left + rect.width / 2, rect.top + rect.height / 2, 1.12);
        });
        controls.zoomOut?.addEventListener('click', () => {
            const rect = workspace.getBoundingClientRect();
            zoomAt(workspace, stage, zoomValue, state, rect.left + rect.width / 2, rect.top + rect.height / 2, 0.88);
        });
        controls.center?.addEventListener('click', () => {
            hideNote(popover);
            fitStage(workspace, stage, zoomValue, nodes, state);
        });
    }

    function renderBoard(board) {
        const workspace = board.querySelector('.relations-workspace');
        const stage = board.querySelector('[data-public-relation-stage]');
        const lines = board.querySelector('[data-public-relation-lines]');
        const popover = board.querySelector('[data-public-relation-note]');
        const zoomValue = board.querySelector('[data-public-relation-zoom-value]');
        if (!workspace || !stage || !lines) return;

        const nodes = parseJson(board.dataset.nodes);
        const relations = parseJson(board.dataset.relations);
        const byKey = new Map(nodes.map(node => [String(node.key || ''), node]));
        const state = { scale: 1, panX: 0, panY: 0 };
        lines.innerHTML = '';

        relations.forEach(relation => {
            const a = byKey.get(String(relation.from || ''));
            const b = byKey.get(String(relation.to || ''));
            if (!a || !b) return;

            const ax = Number(a.x || 0) + NODE_WIDTH / 2;
            const ay = Number(a.y || 0) + NODE_HEIGHT / 2;
            const bx = Number(b.x || 0) + NODE_WIDTH / 2;
            const by = Number(b.y || 0) + NODE_HEIGHT / 2;
            const mx = (ax + bx) / 2;
            const my = (ay + by) / 2;
            const label = [relation.icon, relation.label].filter(Boolean).join(' ');
            const width = labelWidth(label);
            const color = relation.color || '#7B61FF';

            const group = svgEl('g', { class: 'relation-link public-relation-link' });
            group.style.setProperty('--relation-color', color);
            const hit = svgEl('line', { x1: ax, y1: ay, x2: bx, y2: by, stroke: 'transparent', 'stroke-width': 22, class: 'relation-line-hit' });
            const line = svgEl('line', { x1: ax, y1: ay, x2: bx, y2: by, class: 'relation-line' });
            const pill = svgEl('rect', { x: mx - width / 2, y: my - 16, width, height: 32, rx: 8, class: 'relation-label-bg' });
            const text = svgEl('text', { x: mx, y: my + 5, 'text-anchor': 'middle', class: 'relation-label-text' });
            text.textContent = label || 'Relacja';
            group.append(hit, line, pill, text);

            group.addEventListener('click', event => {
                event.stopPropagation();
                const note = String(relation.note || '').trim();
                if (note) {
                    showNote(popover, workspace, stage, note, mx, my);
                }
            });
            lines.appendChild(group);
        });

        workspace.addEventListener('click', event => {
            if (!event.target.closest('.public-relation-link')) {
                hideNote(popover);
            }
        });
        fitStage(workspace, stage, zoomValue, nodes, state);
        installNavigation(workspace, stage, zoomValue, popover, nodes, state, {
            zoomIn: board.querySelector('[data-public-relation-zoom-in]'),
            zoomOut: board.querySelector('[data-public-relation-zoom-out]'),
            center: board.querySelector('[data-public-relation-center]')
        });
        window.addEventListener('resize', () => fitStage(workspace, stage, zoomValue, nodes, state));
    }

    boards.forEach(renderBoard);
})();
