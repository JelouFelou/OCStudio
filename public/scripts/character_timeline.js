(function () {
    const board = document.querySelector('[data-character-timeline-board]');
    if (!board) return;

    const stage = board.querySelector('[data-timeline-stage]');
    const lines = board.querySelector('[data-timeline-lines]');
    const zoomValue = board.querySelector('[data-timeline-zoom-value]');
    const saveUrl = window.OCCharacterTimeline?.saveUrl || '/api/stories/timeline-position';

    let state = { scale: 1, panX: 40, panY: 40 };
    let panning = null;
    let suppressClick = false;

    function applyTransform() {
        stage.style.transform = `translate(${state.panX}px, ${state.panY}px) scale(${state.scale})`;
        if (zoomValue) zoomValue.textContent = `${Math.round(state.scale * 100)}%`;
        renderLines();
    }

    function screenToWorld(clientX, clientY) {
        const rect = board.getBoundingClientRect();
        return {
            x: (clientX - rect.left - state.panX) / state.scale,
            y: (clientY - rect.top - state.panY) / state.scale
        };
    }

    function nodeCenter(node) {
        const dot = node.querySelector('.character-timeline-node-dot');
        if (dot) {
            return {
                x: parseFloat(node.dataset.nodeX || '0') + node.offsetWidth / 2,
                y: parseFloat(node.dataset.nodeY || '0') + dot.offsetTop + dot.offsetHeight / 2
            };
        }

        return {
            x: parseFloat(node.dataset.nodeX || '0') + node.offsetWidth / 2,
            y: parseFloat(node.dataset.nodeY || '0') + node.offsetHeight / 2
        };
    }

    function nodeTopForCenterY(node, centerY) {
        return Math.max(0, centerY - node.offsetHeight / 2);
    }

    function firstMainNode() {
        const mainNodes = [...stage.querySelectorAll('.character-timeline-node')]
            .filter(node => !node.dataset.branch)
            .sort((a, b) => {
                const aSort = parseFloat(a.dataset.dateSort || '');
                const bSort = parseFloat(b.dataset.dateSort || '');
                if (Number.isFinite(aSort) && Number.isFinite(bSort)) return aSort - bSort;
                if (Number.isFinite(aSort)) return -1;
                if (Number.isFinite(bSort)) return 1;
                const aX = parseFloat(a.dataset.nodeX || '0');
                const bX = parseFloat(b.dataset.nodeX || '0');
                return (Number.isFinite(aX) ? aX : 0) - (Number.isFinite(bX) ? bX : 0);
            });

        return mainNodes.find(node => node.dataset.nodeType === 'start') || mainNodes[0] || null;
    }

    function mainLineCenterY() {
        const first = firstMainNode();
        return first ? nodeCenter(first).y : 0;
    }

    function branchLineCenterY(branchName, fallbackNode) {
        const branchNodes = [...stage.querySelectorAll('.character-timeline-node')]
            .filter(node => node.dataset.branch === branchName && node !== fallbackNode)
            .sort((a, b) => parseFloat(a.dataset.nodeX || '0') - parseFloat(b.dataset.nodeX || '0'));

        if (branchNodes.length) {
            return nodeCenter(branchNodes[0]).y;
        }

        return fallbackNode ? nodeCenter(fallbackNode).y : mainLineCenterY();
    }

    function renderLines() {
        if (!lines) return;
        lines.innerHTML = '';
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        defs.innerHTML = `
            <linearGradient id="timeline-main-fade" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="var(--primary)" stop-opacity="1"></stop>
                <stop offset="100%" stop-color="var(--primary)" stop-opacity="0"></stop>
            </linearGradient>
            <linearGradient id="timeline-branch-fade" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="var(--secondary, #8E44AD)" stop-opacity="1"></stop>
                <stop offset="100%" stop-color="var(--secondary, #8E44AD)" stop-opacity="0"></stop>
            </linearGradient>
        `;
        lines.appendChild(defs);
        const nodes = [...stage.querySelectorAll('.character-timeline-node')];
        const main = nodes.filter(node => !node.dataset.branch).sort((a, b) => parseFloat(a.dataset.nodeX) - parseFloat(b.dataset.nodeX));
        const branches = new Map();
        nodes.filter(node => node.dataset.branch).forEach(node => {
            if (!branches.has(node.dataset.branch)) branches.set(node.dataset.branch, []);
            branches.get(node.dataset.branch).push(node);
        });

        drawPath(main, 'character-timeline-line-main');
        drawFade(main.at(-1), 'character-timeline-line-main-fade');
        branches.forEach(group => {
            const sorted = group.sort((a, b) => parseFloat(a.dataset.nodeX) - parseFloat(b.dataset.nodeX));
            drawBranchAxis(main, sorted);
            if (branchSplitPoint(main, sorted[0])) {
                drawBranchConnector(main, sorted[0]);
            }
            drawPath(sorted, 'character-timeline-line-branch', branchColor(sorted[0]));
            drawBranchReturnConnector(main, sorted);
        });
    }

    function branchColor(node) {
        return node?.dataset.branchColor || '#8E44AD';
    }

    function applyBranchColor(element, color) {
        if (color) {
            element.style.setProperty('--timeline-branch-color', color);
        }
    }

    function mainAxisBounds(mainNodes) {
        if (!mainNodes.length) {
            return { startX: 40, endX: 480 };
        }

        const points = mainNodes.map(nodeCenter);
        const startX = Math.min(...points.map(point => point.x));
        const endX = Math.max(...points.map(point => point.x)) + 440;
        return { startX, endX };
    }

    function drawBranchAxis(mainNodes, branchNodes) {
        if (!branchNodes.length) return;
        const first = branchNodes[0];
        const { startX, endX } = mainAxisBounds(mainNodes);
        const splitPoint = branchSplitPoint(mainNodes, first);
        const mergePoint = branchMergePoint(mainNodes, first);
        const branchCenters = branchNodes.map(nodeCenter);
        const firstBranchX = Math.min(...branchCenters.map(point => point.x));
        const lastBranchX = Math.max(...branchCenters.map(point => point.x));
        const branchStartX = splitPoint ? firstBranchX : startX;
        const branchEndX = mergePoint ? lastBranchX : Math.max(branchStartX + 120, endX);
        const y = branchLineCenterY(first.dataset.branch, first);
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', branchStartX);
        line.setAttribute('y1', y);
        line.setAttribute('x2', branchEndX);
        line.setAttribute('y2', y);
        line.setAttribute('class', 'character-timeline-line-branch character-timeline-line-branch-axis');
        applyBranchColor(line, branchColor(first));
        lines.appendChild(line);
    }

    function drawPath(nodes, className, color = '') {
        if (nodes.length < 2) return;
        for (let i = 0; i < nodes.length - 1; i++) {
            const a = nodeCenter(nodes[i]);
            const b = nodeCenter(nodes[i + 1]);
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', a.x);
            line.setAttribute('y1', a.y);
            line.setAttribute('x2', b.x);
            line.setAttribute('y2', b.y);
            line.setAttribute('class', className);
            applyBranchColor(line, color);
            lines.appendChild(line);
        }
    }

    function drawBranchConnector(mainNodes, branchNode) {
        if (!branchNode || !mainNodes.length) return;
        const branch = nodeCenter(branchNode);
        const source = branchSplitPoint(mainNodes, branchNode);
        if (!source) return;
        const sourceNode = branchSourceNode(mainNodes, branchNode);
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', branchConnectorPath(source, branch, sourceNode));
        path.setAttribute('class', 'character-timeline-line-branch character-timeline-line-connector');
        applyBranchColor(path, branchColor(branchNode));
        lines.appendChild(path);
        if (!sourceNode) drawBranchSourceDot(source, branchColor(branchNode));
    }

    function drawBranchReturnConnector(mainNodes, branchNodes) {
        if (!branchNodes.length) return;
        const last = branchNodes.at(-1);
        const target = branchMergePoint(mainNodes, last);
        if (!target) return;
        const branch = nodeCenter(last);
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', branchReturnConnectorPath(branch, target));
        path.setAttribute('class', 'character-timeline-line-branch character-timeline-line-connector');
        applyBranchColor(path, branchColor(last));
        lines.appendChild(path);
        drawBranchSourceDot(target, branchColor(last));
    }

    function branchConnectorPath(source, branch, sourceNode) {
        if (!sourceNode) {
            const midX = source.x + Math.max(80, (branch.x - source.x) * 0.45);
            return `M ${source.x} ${source.y} C ${midX} ${source.y}, ${midX} ${branch.y}, ${branch.x} ${branch.y}`;
        }

        const xDirection = branch.x >= source.x ? 1 : -1;
        const diagonalAngle = 16 * Math.PI / 180;
        const verticalDistance = Math.abs(branch.y - source.y);
        const horizontalDistance = Math.abs(branch.x - source.x);
        const minimumBendDistance = 70;
        const naturalBendDistance = verticalDistance * Math.tan(diagonalAngle);
        const bendDistance = horizontalDistance > minimumBendDistance * 2
            ? Math.min(Math.max(naturalBendDistance, minimumBendDistance), horizontalDistance - minimumBendDistance)
            : horizontalDistance / 2;
        const bendX = source.x + (xDirection * bendDistance);

        return `M ${source.x} ${source.y} L ${bendX} ${branch.y} L ${branch.x} ${branch.y}`;
    }

    function branchReturnConnectorPath(branch, target) {
        const xDirection = target.x >= branch.x ? 1 : -1;
        const horizontalDistance = Math.abs(target.x - branch.x);
        const bendDistance = Math.max(70, horizontalDistance * 0.45);
        const bendX = branch.x + (xDirection * Math.min(bendDistance, Math.max(40, horizontalDistance - 40)));

        return `M ${branch.x} ${branch.y} C ${bendX} ${branch.y}, ${bendX} ${target.y}, ${target.x} ${target.y}`;
    }

    function drawBranchSourceDot(point, color = '') {
        const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        dot.setAttribute('cx', point.x);
        dot.setAttribute('cy', point.y);
        dot.setAttribute('r', '7');
        dot.setAttribute('class', 'character-timeline-line-branch-dot');
        applyBranchColor(dot, color);
        lines.appendChild(dot);
    }

    function sortedMainNodesByDate(mainNodes) {
        return mainNodes
            .map(node => ({ node, sort: parseFloat(node.dataset.dateSort || '') }))
            .filter(item => Number.isFinite(item.sort))
            .sort((a, b) => a.sort - b.sort);
    }

    function pointOnMainAxisForSort(mainNodes, sort) {
        const sortedByDate = mainNodes
            ? sortedMainNodesByDate(mainNodes)
            : [];
        if (!Number.isFinite(sort) || !sortedByDate.length) {
            return null;
        }

        if (sort <= sortedByDate[0].sort) {
            return nodeCenter(sortedByDate[0].node);
        }
        const last = sortedByDate.at(-1);
        if (sort >= last.sort) {
            return nodeCenter(last.node);
        }

        for (let i = 0; i < sortedByDate.length - 1; i++) {
            const left = sortedByDate[i];
            const right = sortedByDate[i + 1];
            if (sort >= left.sort && sort <= right.sort) {
                const a = nodeCenter(left.node);
                const b = nodeCenter(right.node);
                const span = right.sort - left.sort;
                const ratio = span === 0 ? 0.5 : (sort - left.sort) / span;
                return {
                    x: a.x + (b.x - a.x) * ratio,
                    y: a.y + (b.y - a.y) * ratio
                };
            }
        }

        return null;
    }

    function branchSourceNode(mainNodes, branchNode) {
        return mainNodes.find(node => node.dataset.branchSource === branchNode?.dataset.branch) || null;
    }

    function branchSplitPoint(mainNodes, branchNode) {
        const sourceNode = branchSourceNode(mainNodes, branchNode);
        if (sourceNode) {
            return nodeCenter(sourceNode);
        }
        return pointOnMainAxisForSort(mainNodes, parseFloat(branchNode?.dataset.splitSort || ''));
    }

    function branchMergePoint(mainNodes, branchNode) {
        return pointOnMainAxisForSort(mainNodes, parseFloat(branchNode?.dataset.mergeSort || ''));
    }

    function drawFade(node, className) {
        if (!node) return;
        const point = nodeCenter(node);
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', point.x);
        line.setAttribute('y1', point.y);
        line.setAttribute('x2', point.x + 440);
        line.setAttribute('y2', point.y);
        line.setAttribute('class', className);
        lines.appendChild(line);
    }

    function drawFadeTo(node, targetNode, className) {
        if (!node) return;
        const point = nodeCenter(node);
        const target = targetNode ? nodeCenter(targetNode) : { x: point.x + 440, y: point.y };
        const x2 = target.x > point.x + 90 ? target.x : point.x + 440;
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', point.x);
        line.setAttribute('y1', point.y);
        line.setAttribute('x2', x2);
        line.setAttribute('y2', point.y);
        line.setAttribute('class', className);
        lines.appendChild(line);
    }

    function disableNativeDrag(node) {
        node.draggable = false;
        node.querySelectorAll('a, img').forEach(element => {
            element.draggable = false;
        });
    }

    board.querySelectorAll('.character-timeline-node').forEach(disableNativeDrag);

    board.addEventListener('dragstart', event => {
        event.preventDefault();
    });

    board.addEventListener('pointerdown', event => {
        if (event.button !== 0 && event.button !== 1) return;
        if (event.button === 1) event.preventDefault();
        if (event.target.closest('.character-timeline-toolbar')) return;
        panning = {
            pointerId: event.pointerId,
            buttonMask: event.button === 1 ? 4 : 1,
            button: event.button,
            startX: event.clientX,
            startY: event.clientY,
            panX: state.panX,
            panY: state.panY,
            moved: false,
            link: event.button === 0 ? event.target.closest('a.character-timeline-node[href]') : null,
            openInNewTab: event.ctrlKey || event.metaKey
        };
        board.setPointerCapture(event.pointerId);
        board.classList.add('is-panning');
    });

    board.addEventListener('mousedown', event => {
        if (event.button === 1) event.preventDefault();
    });

    board.addEventListener('auxclick', event => {
        if (event.button === 1) event.preventDefault();
    });

    function finishPanning() {
        if (!panning) return false;
        panning = null;
        board.classList.remove('is-panning');
        return true;
    }

    board.addEventListener('pointermove', event => {
        if (!panning) return;
        if (panning.pointerId !== event.pointerId || (event.buttons & panning.buttonMask) === 0) {
            finishPanning();
            return;
        }
        if (Math.abs(event.clientX - panning.startX) > 3 || Math.abs(event.clientY - panning.startY) > 3) {
            panning.moved = true;
            suppressClick = true;
        }
        state.panX = panning.panX + event.clientX - panning.startX;
        state.panY = panning.panY + event.clientY - panning.startY;
        applyTransform();
    });

    board.addEventListener('pointerup', event => {
        if (panning && panning.pointerId === event.pointerId) {
            const link = panning.link;
            const shouldOpenLink = panning.button === 0 && link && !panning.moved;
            const openInNewTab = panning.openInNewTab;
            finishPanning();
            if (shouldOpenLink) {
                if (openInNewTab) {
                    window.open(link.href, '_blank', 'noopener');
                } else {
                    window.location.href = link.href;
                }
            }
        }
    });

    board.addEventListener('pointercancel', event => {
        if (panning && panning.pointerId === event.pointerId) finishPanning();
    });

    board.addEventListener('lostpointercapture', event => {
        if (panning && panning.pointerId === event.pointerId) finishPanning();
    });

    window.addEventListener('blur', () => {
        finishPanning();
    });

    board.addEventListener('click', event => {
        if (!suppressClick) return;
        event.preventDefault();
        event.stopPropagation();
        suppressClick = false;
    }, true);

    board.querySelector('[data-timeline-zoom-in]')?.addEventListener('click', () => {
        state.scale = Math.min(1.8, state.scale + 0.1);
        applyTransform();
    });
    board.querySelector('[data-timeline-zoom-out]')?.addEventListener('click', () => {
        state.scale = Math.max(0.45, state.scale - 0.1);
        applyTransform();
    });
    board.querySelector('[data-timeline-center]')?.addEventListener('click', () => {
        state = { scale: 1, panX: 40, panY: 40 };
        applyTransform();
    });

    applyTransform();
})();
