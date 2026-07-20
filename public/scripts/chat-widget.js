(function () {
    const root = document.querySelector('[data-chat-widget]');
    if (!root || typeof fetch !== 'function') return;

    const storageKey = 'oc_chat_widget_state';
    const launcher = root.querySelector('[data-chat-toggle]');
    const panel = root.querySelector('[data-chat-panel]');
    const closeBtn = root.querySelector('[data-chat-close]');
    const title = root.querySelector('[data-chat-title]');
    const unreadBadge = root.querySelector('[data-chat-unread]');
    const status = root.querySelector('[data-chat-status]');
    const searchInput = root.querySelector('[data-chat-search]');
    const searchResults = root.querySelector('[data-chat-search-results]');
    const conversationList = root.querySelector('[data-chat-conversation-list]');
    const emptyState = root.querySelector('[data-chat-empty]');
    const threadHead = root.querySelector('[data-chat-thread-head]');
    const threadTitle = root.querySelector('[data-chat-thread-title]');
    const messagesList = root.querySelector('[data-chat-messages]');
    const compose = root.querySelector('[data-chat-compose]');
    const input = root.querySelector('[data-chat-input]');

    let conversations = [];
    let activeConversationId = '';
    let lastMessageId = 0;
    let threadTimer = null;
    let listTimer = null;
    let searchTimer = null;
    let loadingThread = false;
    let sendingMessage = false;
    const i18n = window.OCI18n?.chat || {};
    const tr = (key, fallback) => i18n[key] || fallback;
    if (title) title.textContent = tr('messages', 'Messages');
    if (status) status.textContent = tr('ready', 'Ready');

    const readState = () => {
        try {
            return JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
        } catch (error) {
            return {};
        }
    };

    const writeState = (patch) => {
        const next = Object.assign(readState(), patch);
        localStorage.setItem(storageKey, JSON.stringify(next));
    };

    const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[char]);

    const initials = (name) => String(name || 'U').trim().slice(0, 1).toUpperCase() || 'U';

    const setStatus = (text) => {
        if (status) status.textContent = text || tr('ready', 'Ready');
    };

    const setUnread = (count) => {
        const unread = Number(count || 0);
        if (!unreadBadge) return;
        unreadBadge.hidden = unread <= 0;
        unreadBadge.textContent = unread > 99 ? '99+' : String(unread);
    };

    const api = async (url, options = {}) => {
        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.error) {
            const error = new Error(data.error || tr('genericError', 'Nie udalo sie wykonac operacji.'));
            error.status = response.status;
            throw error;
        }
        return data;
    };

    const renderConversations = () => {
        if (!conversationList) return;
        if (!conversations.length) {
            conversationList.innerHTML = `<p class="chat-empty">${escapeHtml(tr('noConversations', 'No conversations.'))}</p>`;
            return;
        }

        conversationList.innerHTML = conversations.map((conversation) => {
            const username = conversation.otherUser?.username || tr('user', 'User');
            const latest = conversation.latestMessage?.body || tr('noMessages', 'No messages.');
            const unread = Number(conversation.unreadCount || 0);
            return `
                <button type="button" class="chat-conversation-item ${conversation.uuid === activeConversationId ? 'active' : ''}" data-chat-conversation="${escapeHtml(conversation.uuid)}">
                    <span class="chat-avatar">${escapeHtml(initials(username))}</span>
                    <span>
                        <strong>${escapeHtml(username)}</strong>
                        <small>${escapeHtml(latest)}</small>
                    </span>
                    ${unread > 0 ? `<span class="chat-unread">${unread > 99 ? '99+' : unread}</span>` : ''}
                </button>
            `;
        }).join('');
    };

    const loadConversations = async () => {
        try {
            const data = await api('/api/messages/conversations');
            conversations = Array.isArray(data.conversations) ? data.conversations : [];
            setUnread(data.unreadCount);
            renderConversations();
        } catch (error) {
            if (error.status === 403 || error.status === 401) {
                root.hidden = true;
                stopPolling();
                return;
            }
            setStatus(error.message);
        }
    };

    const renderMessages = (messages, append) => {
        if (!messagesList) return;
        if (!append) messagesList.innerHTML = '';

        messages.forEach((message) => {
            const messageId = Number(message.id || 0);
            if (messageId > 0 && messagesList.querySelector(`[data-message-id="${messageId}"]`)) {
                lastMessageId = Math.max(lastMessageId, messageId);
                return;
            }
            const item = document.createElement('article');
            item.className = `chat-message ${message.mine ? 'mine' : ''}`;
            item.dataset.messageId = String(messageId || message.id || '');
            item.innerHTML = `
                <p>${escapeHtml(message.body)}</p>
                <small>${escapeHtml(message.mine ? tr('you', 'You') : message.senderUsername || tr('user', 'User'))}</small>
            `;
            messagesList.appendChild(item);
            lastMessageId = Math.max(lastMessageId, messageId);
        });

        messagesList.scrollTop = messagesList.scrollHeight;
    };

    const showThread = (conversation) => {
        const username = conversation?.otherUser?.username || tr('conversation', 'Conversation');
        if (emptyState) emptyState.hidden = true;
        if (threadHead) threadHead.hidden = false;
        if (messagesList) messagesList.hidden = false;
        if (compose) compose.hidden = false;
        if (threadTitle) threadTitle.textContent = username;
    };

    const loadThread = async (conversationId, append = false) => {
        if (!conversationId || loadingThread) return;
        loadingThread = true;

        try {
            const after = append ? lastMessageId : 0;
            const data = await api(`/api/messages/thread?conversation=${encodeURIComponent(conversationId)}&after=${after}`);
            activeConversationId = conversationId;
            writeState({ activeConversationId, open: !panel.hidden });
            showThread(data.conversation);
            renderMessages(Array.isArray(data.messages) ? data.messages : [], append);
            setUnread(data.unreadCount);
            renderConversations();
            setStatus(tr('ready', 'Ready'));
        } catch (error) {
            setStatus(error.message);
            if (!append) {
                activeConversationId = '';
                writeState({ activeConversationId: '' });
            }
        } finally {
            loadingThread = false;
        }
    };

    const selectConversation = (conversationId) => {
        activeConversationId = conversationId;
        lastMessageId = 0;
        writeState({ activeConversationId, open: true });
        renderConversations();
        loadThread(conversationId, false);
    };

    const startConversation = async (userId) => {
        try {
            setStatus(tr('opening', 'Opening conversation...'));
            const data = await api('/api/messages/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId }),
            });
            if (searchInput) searchInput.value = '';
            if (searchResults) {
                searchResults.hidden = true;
                searchResults.innerHTML = '';
            }
            await loadConversations();
            selectConversation(data.conversation.uuid);
        } catch (error) {
            setStatus(error.message);
        }
    };

    const searchUsers = async () => {
        const query = String(searchInput?.value || '').trim();
        if (!searchResults) return;
        if (query.length < 2) {
            searchResults.hidden = true;
            searchResults.innerHTML = '';
            return;
        }

        try {
            const data = await api(`/api/messages/search?q=${encodeURIComponent(query)}`);
            const users = Array.isArray(data.users) ? data.users : [];
            searchResults.hidden = false;
            searchResults.innerHTML = users.length ? users.map((user) => `
                <button type="button" class="chat-user-result" data-chat-user="${Number(user.id || 0)}">
                    <span class="chat-avatar">${escapeHtml(initials(user.username))}</span>
                    <span>
                        <strong>${escapeHtml(user.username || tr('user', 'User'))}</strong>
                    </span>
                </button>
            `).join('') : `<p class="chat-empty">${escapeHtml(tr('noUsers', 'No users.'))}</p>`;
        } catch (error) {
            setStatus(error.message);
        }
    };

    const sendMessage = async () => {
        const body = String(input?.value || '').trim();
        if (!body || !activeConversationId || sendingMessage) return;

        try {
            sendingMessage = true;
            if (input) input.disabled = true;
            setStatus(tr('sending', 'Sending...'));
            const data = await api('/api/messages/send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conversationId: activeConversationId, body }),
            });
            input.value = '';
            renderMessages([data.message], true);
            setUnread(data.unreadCount);
            await loadConversations();
            setStatus(tr('ready', 'Ready'));
        } catch (error) {
            setStatus(error.message);
        } finally {
            sendingMessage = false;
            if (input) {
                input.disabled = false;
                input.focus();
            }
        }
    };

    const openPanel = () => {
        panel.hidden = false;
        launcher?.setAttribute('aria-expanded', 'true');
        writeState({ open: true });
        loadConversations().then(() => {
            if (activeConversationId) loadThread(activeConversationId, false);
        });
        startPolling();
    };

    const closePanel = () => {
        panel.hidden = true;
        launcher?.setAttribute('aria-expanded', 'false');
        writeState({ open: false });
        stopThreadPolling();
    };

    const startPolling = () => {
        if (!listTimer) {
            listTimer = window.setInterval(loadConversations, 8000);
        }
        if (!threadTimer) {
            threadTimer = window.setInterval(() => {
                if (!panel.hidden && activeConversationId) loadThread(activeConversationId, true);
            }, 3500);
        }
    };

    const stopThreadPolling = () => {
        if (threadTimer) {
            window.clearInterval(threadTimer);
            threadTimer = null;
        }
    };

    const stopPolling = () => {
        stopThreadPolling();
        if (listTimer) {
            window.clearInterval(listTimer);
            listTimer = null;
        }
    };

    launcher?.addEventListener('click', () => {
        if (panel.hidden) openPanel();
        else closePanel();
    });

    closeBtn?.addEventListener('click', closePanel);

    conversationList?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-chat-conversation]');
        if (button) selectConversation(button.dataset.chatConversation || '');
    });

    searchResults?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-chat-user]');
        if (button) startConversation(Number(button.dataset.chatUser || 0));
    });

    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(searchUsers, 250);
    });

    compose?.addEventListener('submit', (event) => {
        event.preventDefault();
        sendMessage();
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    const state = readState();
    activeConversationId = String(state.activeConversationId || '');
    loadConversations();
    if (state.open) openPanel();
})();
