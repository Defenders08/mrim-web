/**
 * MRIM Web Client — ES6 Pure JavaScript Frontend
 *
 * Communicates with the local PHP WebSocket server, which bridges
 * commands and binary packets to the Mail.Ru Instant Messenger (mrim.su) server.
 */

// In-Memory state (no database required)
const state = {
    ws: null,
    wsConnected: false,
    mrimState: 'disconnected', // disconnected, connecting, connected, authenticated
    myEmail: '',
    contacts: {},       // email -> { email, nickname, status, unread }
    activeContact: null,// currently selected contact email
    messages: {},       // email -> [ { from, text, timestamp, isMe } ]
};

// DOM Elements
const el = {
    wsStatusText: document.getElementById('ws-status-text'),
    loginForm:    document.getElementById('login-form'),
    loginEmail:   document.getElementById('login-email'),
    loginPass:    document.getElementById('login-pass'),
    loginStatus:  document.getElementById('login-status'),
    btnLogin:     document.getElementById('btn-login'),
    btnLogout:    document.getElementById('btn-logout'),
    btnReconnect: document.getElementById('btn-reconnect'),
    btnPing:      document.getElementById('btn-ping'),
    authStatusBar:document.getElementById('auth-status-bar'),
    contactsCount:document.getElementById('contacts-count'),
    contactsList: document.getElementById('contacts-list'),
    directTo:     document.getElementById('direct-to'),
    btnSelectContact: document.getElementById('btn-select-contact'),
    currentChatTitle: document.getElementById('current-chat-title'),
    chatHistory:  document.getElementById('chat-history'),
    sendForm:     document.getElementById('send-form'),
    messageInput: document.getElementById('message-input'),
    btnSend:      document.getElementById('btn-send'),
    logsConsole:  document.getElementById('logs-console'),
    btnClearLogs: document.getElementById('btn-clear-logs'),
};

/**
 * Initialize WebSocket connection to local PHP server
 */
function connectWebSocket() {
    const protocol = location.protocol === 'https:' ? 'wss://' : 'ws://';
    const wsUrl = protocol + location.host + '/ws';

    logToConsole(`Подключение к WebSocket серверу (${wsUrl})...`, 'info');
    el.wsStatusText.textContent = '• WebSocket: Подключение...';
    el.wsStatusText.style.color = '#ffaa00';

    const ws = new WebSocket(wsUrl);
    state.ws = ws;

    ws.onopen = () => {
        state.wsConnected = true;
        el.wsStatusText.textContent = '• WebSocket: Подключено';
        el.wsStatusText.style.color = '#00cc00';
        logToConsole('WebSocket соединение установлено.', 'info');
    };

    ws.onmessage = (event) => {
        try {
            const msg = JSON.parse(event.data);
            handleServerEvent(msg.type, msg.data);
        } catch (e) {
            logToConsole('Ошибка разбора сообщения от сервера: ' + e.message, 'error');
        }
    };

    ws.onclose = () => {
        state.wsConnected = false;
        el.wsStatusText.textContent = '• WebSocket: Отключено (реконнект...)';
        el.wsStatusText.style.color = '#ff0000';
        logToConsole('WebSocket соединение закрыто. Повторная попытка через 3 сек...', 'warning');
        setTimeout(connectWebSocket, 3000);
    };

    ws.onerror = () => {
        logToConsole('Ошибка WebSocket соединения', 'error');
    };
}

/**
 * Send JSON command to PHP WebSocket server
 */
function sendWsCommand(action, payload = {}) {
    if (!state.ws || state.ws.readyState !== WebSocket.OPEN) {
        logToConsole('Невозможно отправить команду: WebSocket отключен', 'error');
        return;
    }
    state.ws.send(JSON.stringify({ action, ...payload }));
}

/**
 * Handle incoming events from PHP MRIM client
 */
function handleServerEvent(type, data) {
    switch (type) {
        case 'init_state':
            state.mrimState = data.mrim_state || 'disconnected';
            if (data.contacts && Array.isArray(data.contacts)) {
                data.contacts.forEach(c => {
                    state.contacts[c.email] = c;
                });
                renderContacts();
            }
            updateAuthUI();
            break;

        case 'status_log':
            logToConsole(data.message, 'info');
            break;

        case 'log':
            logToConsole(data.message, data.level || 'info');
            break;

        case 'login_success':
            state.mrimState = 'authenticated';
            state.myEmail = data.email;
            el.authStatusBar.textContent = `В сети как: ${state.myEmail}`;
            el.authStatusBar.style.color = '#006600';
            updateAuthUI();
            logToConsole(`Успешная авторизация на сервере mrim.su как ${data.email}!`, 'info');
            break;

        case 'login_error':
            state.mrimState = 'disconnected';
            el.authStatusBar.textContent = `Ошибка входа: ${data.reason}`;
            el.authStatusBar.style.color = '#cc0000';
            updateAuthUI();
            logToConsole(`Ошибка авторизации MRIM: ${data.reason}`, 'error');
            break;

        case 'logout':
        case 'disconnected':
            state.mrimState = 'disconnected';
            el.authStatusBar.textContent = 'Отключено от сервера MRIM.';
            el.authStatusBar.style.color = '#666666';
            updateAuthUI();
            logToConsole(`Отключение MRIM: ${data.reason || 'Завершение сессии'}`, 'warning');
            break;

        case 'contact_list':
            if (Array.isArray(data.contacts)) {
                data.contacts.forEach(c => {
                    const existing = state.contacts[c.email] || {};
                    state.contacts[c.email] = {
                        ...existing,
                        ...c,
                    };
                });
                renderContacts();
                logToConsole(`Загружен список контактов (${data.contacts.length} шт.)`, 'info');
            }
            break;

        case 'user_status':
            if (data && data.email) {
                const existing = state.contacts[data.email] || {
                    email: data.email,
                    nickname: data.email,
                    unread: 0
                };
                state.contacts[data.email] = {
                    ...existing,
                    status: data.status,
                    status_title: data.status_title,
                    status_desc: data.status_desc
                };
                renderContacts();
            }
            break;

        case 'message':
            handleIncomingMessage(data.from, data.text, data.timestamp);
            break;

        case 'message_sent':
            handleOutgoingMessage(data.to, data.text, data.timestamp);
            break;

        case 'error':
            logToConsole('Ошибка сервера: ' + (data.message || JSON.stringify(data)), 'error');
            break;

        default:
            console.log('Неизвестное событие от сервера:', type, data);
            break;
    }
}

/**
 * Handle incoming message from a contact
 */
function handleIncomingMessage(fromEmail, text, timestamp) {
    if (!state.messages[fromEmail]) {
        state.messages[fromEmail] = [];
    }

    state.messages[fromEmail].push({
        from: fromEmail,
        text: text,
        timestamp: timestamp || Math.floor(Date.now() / 1000),
        isMe: false
    });

    if (state.activeContact === fromEmail) {
        renderChatHistory();
    } else {
        // Increment unread counter if chat is not open
        if (!state.contacts[fromEmail]) {
            state.contacts[fromEmail] = {
                email: fromEmail,
                nickname: fromEmail,
                status: 1,
                unread: 0
            };
        }
        state.contacts[fromEmail].unread = (state.contacts[fromEmail].unread || 0) + 1;
        renderContacts();
    }
}

/**
 * Handle outgoing message echo
 */
function handleOutgoingMessage(toEmail, text, timestamp) {
    if (!state.messages[toEmail]) {
        state.messages[toEmail] = [];
    }

    state.messages[toEmail].push({
        from: state.myEmail || 'Я',
        text: text,
        timestamp: timestamp || Math.floor(Date.now() / 1000),
        isMe: true
    });

    if (state.activeContact === toEmail) {
        renderChatHistory();
    }
}

/**
 * Update UI controls based on authentication state
 */
function updateAuthUI() {
    const isAuth = (state.mrimState === 'authenticated');
    el.btnLogin.classList.toggle('hidden', isAuth);
    el.btnLogout.classList.toggle('hidden', !isAuth);
    el.loginEmail.disabled = isAuth;
    el.loginPass.disabled = isAuth;
    el.loginStatus.disabled = isAuth;

    el.messageInput.disabled = !isAuth || !state.activeContact;
    el.btnSend.disabled = !isAuth || !state.activeContact;
}

/**
 * Render Contacts list in left panel
 */
function renderContacts() {
    const keys = Object.keys(state.contacts);
    el.contactsCount.textContent = `(${keys.length})`;

    if (keys.length === 0) {
        el.contactsList.innerHTML = '<div class="empty-list">Нет контактов (войдите в сеть)</div>';
        return;
    }

    el.contactsList.innerHTML = '';
    keys.sort().forEach(email => {
        const c = state.contacts[email];
        const item = document.createElement('div');
        item.className = 'contact-item' + (state.activeContact === email ? ' active' : '');
        item.onclick = () => selectContact(email);

        const left = document.createElement('div');
        left.className = 'contact-left';

        const dot = document.createElement('span');
        dot.className = 'contact-status-dot ' + getStatusClass(c.status);
        dot.title = c.status_title || 'Статус: ' + c.status;

        const nameSpan = document.createElement('span');
        nameSpan.className = 'contact-name';
        nameSpan.textContent = c.nickname || email;
        nameSpan.title = email;

        left.appendChild(dot);
        left.appendChild(nameSpan);
        item.appendChild(left);

        if (c.unread && c.unread > 0) {
            const badge = document.createElement('span');
            badge.className = 'unread-badge';
            badge.textContent = c.unread;
            item.appendChild(badge);
        }

        el.contactsList.appendChild(item);
    });
}

/**
 * Select active contact for chatting
 */
function selectContact(email) {
    if (!email) return;
    state.activeContact = email;

    if (!state.contacts[email]) {
        state.contacts[email] = {
            email: email,
            nickname: email,
            status: 1,
            unread: 0
        };
    }
    state.contacts[email].unread = 0;

    el.currentChatTitle.textContent = `${state.contacts[email].nickname} (${email})`;
    el.messageInput.disabled = (state.mrimState !== 'authenticated');
    el.btnSend.disabled = (state.mrimState !== 'authenticated');

    renderContacts();
    renderChatHistory();
    el.messageInput.focus();
}

/**
 * Render chat messages from in-memory array
 */
function renderChatHistory() {
    if (!state.activeContact) {
        return;
    }

    const messages = state.messages[state.activeContact] || [];
    el.chatHistory.innerHTML = '';

    if (messages.length === 0) {
        el.chatHistory.innerHTML = '<div class="chat-welcome">История диалога пуста. Напишите сообщение!</div>';
        return;
    }

    messages.forEach(m => {
        const item = document.createElement('div');
        item.className = 'message-item';

        const header = document.createElement('div');
        header.className = 'msg-header';

        const author = document.createElement('span');
        author.className = 'msg-author' + (m.isMe ? ' me' : '');
        author.textContent = m.isMe ? (state.myEmail || 'Я') : m.from;

        const timeStr = formatTime(m.timestamp);
        header.appendChild(author);
        header.appendChild(document.createTextNode(` [${timeStr}]:`));

        const body = document.createElement('div');
        body.className = 'msg-text';
        body.textContent = m.text;

        item.appendChild(header);
        item.appendChild(body);
        el.chatHistory.appendChild(item);
    });

    el.chatHistory.scrollTop = el.chatHistory.scrollHeight;
}

/**
 * Convert Unix timestamp to readable HH:MM:SS
 */
function formatTime(ts) {
    const d = new Date(ts * 1000);
    return d.toLocaleTimeString('ru-RU', { hour12: false });
}

/**
 * Convert numeric MRIM status to CSS class
 */
function getStatusClass(status) {
    if (status === 1) return 'status-online';
    if (status === 2) return 'status-away';
    return 'status-offline';
}

/**
 * Append line to system log panel
 */
function logToConsole(message, level = 'info') {
    const line = document.createElement('div');
    line.className = `log-line log-${level}`;
    const time = new Date().toLocaleTimeString('ru-RU', { hour12: false });
    line.textContent = `[${time}] ${message}`;
    el.logsConsole.appendChild(line);
    el.logsConsole.scrollTop = el.logsConsole.scrollHeight;
}

// ==========================================
// Event Listeners
// ==========================================

el.loginForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const email = el.loginEmail.value.trim();
    const password = el.loginPass.value;
    const status = parseInt(el.loginStatus.value, 10) || 1;

    el.authStatusBar.textContent = `Подключение к mrim.su...`;
    sendWsCommand('login', { email, password, status });
});

el.btnLogout.addEventListener('click', () => {
    sendWsCommand('logout');
});

el.btnReconnect.addEventListener('click', () => {
    logToConsole('Запрос на ручное переподключение (Reconnect)...', 'warning');
    sendWsCommand('reconnect');
});

el.btnPing.addEventListener('click', () => {
    logToConsole('Отправка проверочного пакета MRIM_CS_PING...', 'info');
    sendWsCommand('ping');
});

el.btnSelectContact.addEventListener('click', () => {
    const email = el.directTo.value.trim();
    if (email) {
        selectContact(email);
        el.directTo.value = '';
    }
});

el.sendForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = el.messageInput.value;
    if (!text || !state.activeContact) return;

    sendWsCommand('send_message', {
        to: state.activeContact,
        text: text
    });

    el.messageInput.value = '';
});

el.messageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        el.sendForm.dispatchEvent(new Event('submit'));
    }
});

el.btnClearLogs.addEventListener('click', () => {
    el.logsConsole.innerHTML = '';
});

// Start WebSocket connection when DOM is ready
window.addEventListener('DOMContentLoaded', () => {
    connectWebSocket();
});
