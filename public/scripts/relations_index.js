(function () {
    const modal = document.getElementById('relation-board-modal');
    if (!modal) return;

    const fields = {
        id: document.getElementById('relation-board-id'),
        title: document.getElementById('relation-board-modal-title'),
        name: document.getElementById('relation-board-name'),
        description: document.getElementById('relation-board-description'),
        search: document.getElementById('relation-board-character-search'),
        save: document.getElementById('relation-board-save'),
        cancel: document.getElementById('relation-board-cancel')
    };

    function openModal(board) {
        fields.id.value = board ? board.id : '';
        fields.title.textContent = board ? 'Edytuj relacje' : 'Nowa relacja';
        fields.name.value = board ? board.name : '';
        fields.description.value = board ? (board.description || '') : '';
        setSelected('.relation-board-world', board ? board.worldIds : []);
        setSelected('.relation-board-character', board ? board.characterIds : []);
        modal.classList.add('is-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
    }

    function setSelected(selector, ids) {
        const selected = new Set((ids || []).map(Number));
        document.querySelectorAll(selector).forEach(button => {
            button.classList.toggle('is-selected', selected.has(parseInt(button.dataset.id, 10)));
        });
        if (selector === '.relation-board-world') {
            markCharactersFromWorlds();
        }
    }

    function selectedIds(selector) {
        return Array.from(document.querySelectorAll(selector + '.is-selected')).map(button => parseInt(button.dataset.id, 10));
    }

    function markCharactersFromWorlds() {
        const worldIds = new Set(selectedIds('.relation-board-world').map(String));
        document.querySelectorAll('.relation-board-character').forEach(button => {
            const fromWorld = button.dataset.worldId && worldIds.has(button.dataset.worldId);
            button.classList.toggle('is-from-selected-world', Boolean(fromWorld));
            if (fromWorld) {
                button.classList.add('is-selected');
            }
        });
    }

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.error || 'Nie udalo sie zapisac relacji.');
        }
        return data;
    }

    document.getElementById('open-relation-board-modal')?.addEventListener('click', () => openModal(null));
    fields.cancel.addEventListener('click', closeModal);
    modal.addEventListener('click', event => {
        if (event.target === modal) closeModal();
    });

    document.querySelectorAll('.relation-board-choice').forEach(button => {
        button.addEventListener('click', () => {
            button.classList.toggle('is-selected');
            if (button.classList.contains('relation-board-world')) {
                markCharactersFromWorlds();
            }
        });
    });

    fields.search.addEventListener('input', () => {
        const term = fields.search.value.trim().toLowerCase();
        document.querySelectorAll('.relation-board-character').forEach(button => {
            button.style.display = !term || button.dataset.name.includes(term) ? '' : 'none';
        });
    });

    fields.save.addEventListener('click', async () => {
        try {
            const data = await postJson('/api/relation-boards', {
                boardId: fields.id.value ? parseInt(fields.id.value, 10) : null,
                name: fields.name.value,
                description: fields.description.value,
                worldIds: selectedIds('.relation-board-world'),
                characterIds: selectedIds('.relation-board-character')
            });
            window.location.href = '/relations/' + encodeURIComponent(data.publicId || data.id);
        } catch (error) {
            alert(error.message);
        }
    });

    document.querySelectorAll('.edit-board-btn').forEach(button => {
        button.addEventListener('click', event => {
            const card = event.currentTarget.closest('.relations-index-card');
            openModal(JSON.parse(card.dataset.board));
        });
    });

    document.querySelectorAll('.duplicate-board-btn').forEach(button => {
        button.addEventListener('click', async event => {
            const card = event.currentTarget.closest('.relations-index-card');
            try {
                await postJson('/api/relation-boards/duplicate', { boardId: parseInt(card.dataset.boardId, 10) });
                location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });

    document.querySelectorAll('.toggle-board-hidden-btn').forEach(button => {
        button.addEventListener('click', async event => {
            const card = event.currentTarget.closest('.relations-index-card');
            try {
                await postJson('/api/relation-boards/hidden', {
                    boardId: parseInt(card.dataset.boardId, 10),
                    hidden: button.dataset.hidden === '1'
                });
                location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });

    document.querySelectorAll('.delete-board-btn').forEach(button => {
        button.addEventListener('click', async event => {
            const card = event.currentTarget.closest('.relations-index-card');
            if (!confirm('Usunac to pole relacji? Same relacje miedzy postaciami zostana.')) return;
            try {
                await postJson('/api/relation-boards/delete', { boardId: parseInt(card.dataset.boardId, 10) });
                location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });
})();
