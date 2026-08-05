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
    directContactForm: document.getElementById('direct-contact-form'),
    btnSelectContact: document.getElementById('btn-select-contact'),
    btnAddContact:    document.getElementById('btn-add-contact'),
    currentChatTitle: document.getElementById('current-chat-title'),
    chatHeaderActions:document.getElementById('chat-header-actions'),
    btnAuthorizeActive:document.getElementById('btn-authorize-active'),
    typingIndicator:  document.getElementById('typing-indicator'),
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
    if (el.wsStatusText) {
        el.wsStatusText.textContent = '• WebSocket: Подключение...';
        el.wsStatusText.style.color = '#ffaa00';
    }

    const ws = new WebSocket(wsUrl);
    state.ws = ws;

    ws.onopen = () => {
        state.wsConnected = true;
        if (el.wsStatusText) {
            el.wsStatusText.textContent = '• WebSocket: Подключено';
            el.wsStatusText.style.color = '#00cc00';
        }
        logToConsole('WebSocket соединение установлено.', 'info');
    };

    ws.onmessage = (event) => {
        try {
            console.log("RECEIVED WS EVENT:", event.data);
            const msg = JSON.parse(event.data);
            handleServerEvent(msg.type, msg.data);
        } catch (e) {
            logToConsole('Ошибка разбора сообщения от сервера: ' + e.message, 'error');
        }
    };

    ws.onclose = () => {
        state.wsConnected = false;
        if (el.wsStatusText) {
            el.wsStatusText.textContent = '• WebSocket: Отключено (реконнект...)';
            el.wsStatusText.style.color = '#ff0000';
        }
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
            if (data.mrim_state === 'authenticated' && data.my_email) {
                state.myEmail = data.my_email.toLowerCase().trim();
                el.authStatusBar.textContent = `В сети как: ${state.myEmail}`;
                el.authStatusBar.style.color = '#006600';
                if (data.contacts && Array.isArray(data.contacts)) {
                    state.contacts = {};
                    data.contacts.forEach(c => {
                        if (c && c.email) {
                            const emailKey = c.email.toLowerCase().trim();
                            state.contacts[emailKey] = { ...c, email: emailKey };
                        }
                    });
                    renderContacts();
                }
            } else {
                // Wipe local state completely on new or unauthenticated connection
                state.myEmail = '';
                state.contacts = {};
                state.messages = {};
                state.activeContact = null;
                renderContacts();
                renderChatHistory();
            }
            updateAuthUI('Не в сети');
            break;

        case 'status_log':
            logToConsole(data.message, 'info');
            break;

        case 'log':
            logToConsole(data.message, data.level || 'info');
            break;

        case 'login_success':
            const newEmail = (data.email || '').toLowerCase().trim();
            if (state.myEmail !== newEmail) {
                state.contacts = {};
                state.messages = {};
                state.activeContact = null;
            }
            state.mrimState = 'authenticated';
            state.myEmail = newEmail;
            renderContacts();
            renderChatHistory();
            updateAuthUI();

            // Auto collapse login panel on successful login
            const loginPanelEl = document.getElementById('login-panel');
            const toggleBtnEl = document.getElementById('btn-toggle-login');
            if (loginPanelEl && toggleBtnEl) {
                loginPanelEl.classList.remove('expanded');
                loginPanelEl.classList.add('collapsed');
                toggleBtnEl.setAttribute('aria-expanded', 'false');
            }
            logToConsole(`Успешная авторизация на сервере mrim.su как ${state.myEmail}!`, 'info');
            break;

        case 'login_error':
            state.mrimState = 'disconnected';
            state.myEmail = '';
            state.contacts = {};
            state.messages = {};
            state.activeContact = null;
            renderContacts();
            renderChatHistory();
            updateAuthUI(`Ошибка входа: ${data.reason}`);
            logToConsole(`Ошибка авторизации MRIM: ${data.reason}`, 'error');
            break;

        case 'logout':
        case 'disconnected':
            state.mrimState = 'disconnected';
            state.myEmail = '';
            state.contacts = {};
            state.messages = {};
            state.activeContact = null;
            renderContacts();
            renderChatHistory();
            updateAuthUI('Отключено от сервера MRIM.');
            logToConsole(`Отключение MRIM: ${data.reason || 'Завершение сессии'}`, 'warning');
            break;

        case 'contact_list':
            if (Array.isArray(data.contacts)) {
                state.contacts = {};
                data.contacts.forEach(c => {
                    if (c && c.email) {
                        const emailKey = c.email.toLowerCase().trim();
                        state.contacts[emailKey] = {
                            ...c,
                            email: emailKey,
                            unread: state.contacts[emailKey]?.unread || 0
                        };
                    }
                });
                renderContacts();
                logToConsole(`Загружен список контактов (${data.contacts.length} шт.)`, 'info');
            }
            break;

        case 'user_status':
            if (data && data.email) {
                const emailKey = data.email.toLowerCase().trim();
                const existing = state.contacts[emailKey] || {
                    email: emailKey,
                    nickname: emailKey,
                    unread: 0
                };
                state.contacts[emailKey] = {
                    ...existing,
                    status: data.status,
                    status_title: data.status_title,
                    status_desc: data.status_desc
                };
                renderContacts();
            }
            break;

        case 'message':
            if (data && data.from && data.text !== undefined) {
                console.log("HANDLE MESSAGE", data.from, data.text);
                logToConsole(`Входящее сообщение от ${data.from}: ${data.text}`, 'info');
                handleIncomingMessage(data.from, data.text, data.timestamp, data.is_auth_request, data.sender_nick);
            }
            break;

        case 'typing_notification':
            if (data && data.from) {
                const typingFrom = data.from.toLowerCase().trim();
                if (state.activeContact === typingFrom) {
                    showTypingIndicator();
                }
            }
            break;

        case 'message_sent':
            if (data && data.to && data.text) {
                handleOutgoingMessage(data.to, data.text, data.timestamp);
            }
            break;

        case 'message_delivery_status':
            if (data) {
                if (data.success) {
                    logToConsole(`Сообщение успешно доставлено: ${data.text || data.code}`, 'info');
                } else {
                    logToConsole(`Ошибка доставки сообщения: ${data.text || data.code}`, 'error');
                    if (data.need_authorize && state.activeContact) {
                        logToConsole(`Совет: Нажмите кнопку '🔓 Авторизовать контакт' в шапке чата, чтобы отправить запрос авторизации контакту ${state.activeContact}`, 'warning');
                    }
                }
            }
            break;

        case 'authorize_request':
            if (data && data.from) {
                const reqFrom = data.from.toLowerCase().trim();
                const now = Date.now();
                logToConsole(`Запрос авторизации от ${reqFrom}: ${data.text || ''}`, 'warning');
                if (!state.contacts[reqFrom]) {
                    state.contacts[reqFrom] = {
                        email: reqFrom,
                        nickname: data.nick || reqFrom,
                        status: 1,
                        unread: 1,
                        hasAuthReq: true,
                        lastActivity: now
                    };
                } else {
                    state.contacts[reqFrom].hasAuthReq = true;
                    state.contacts[reqFrom].lastActivity = now;
                }
                renderContacts();
            }
            break;

        case 'error':
            logToConsole('Ошибка сервера: ' + (data.message || JSON.stringify(data)), 'error');
            break;

        default:
            console.log('Неизвестное событие от сервера:', type, data);
            break;
    }
}

let typingTimeout = null;

function showTypingIndicator() {
    if (!el.typingIndicator) return;
    el.typingIndicator.classList.remove('hidden');
    if (typingTimeout) clearTimeout(typingTimeout);
    typingTimeout = setTimeout(() => {
        if (el.typingIndicator) el.typingIndicator.classList.add('hidden');
    }, 3500);
}

/**
 * Helper to get contact's last activity timestamp
 */
function getLastActivity(email) {
    const c = state.contacts[email];
    if (c && c.lastActivity) {
        return c.lastActivity;
    }
    const msgs = state.messages[email];
    if (msgs && msgs.length > 0) {
        const lastMsg = msgs[msgs.length - 1];
        if (lastMsg && lastMsg.timestamp) {
            return lastMsg.timestamp > 1e11 ? lastMsg.timestamp : lastMsg.timestamp * 1000;
        }
    }
    return 0;
}

/**
 * Handle incoming message from a contact
 */
function handleIncomingMessage(rawFrom, text, timestamp, isAuthReq = false, senderNick = '') {
    if (!rawFrom) return;
    const fromEmail = rawFrom.toLowerCase().trim();
    const now = timestamp ? (timestamp > 1e11 ? timestamp : timestamp * 1000) : Date.now();

    if (fromEmail.includes('admin@mrim.su')) {
        console.log("TEST MESSAGE FROM ADMIN RECEIVED");
        logToConsole("TEST MESSAGE FROM ADMIN RECEIVED", 'info');
    }

    if (!state.messages[fromEmail]) {
        state.messages[fromEmail] = [];
    }

    state.messages[fromEmail].push({
        from: fromEmail,
        text: text,
        timestamp: timestamp || Math.floor(Date.now() / 1000),
        isMe: false,
        isAuthReq: isAuthReq || false,
        senderNick: senderNick || ''
    });

    if (!state.contacts[fromEmail]) {
        state.contacts[fromEmail] = {
            email: fromEmail,
            nickname: senderNick || fromEmail,
            status: 1,
            unread: 0,
            hasAuthReq: isAuthReq,
            lastActivity: now
        };
    } else {
        state.contacts[fromEmail].lastActivity = now;
        if (isAuthReq) {
            state.contacts[fromEmail].hasAuthReq = true;
        }
        if (senderNick && state.contacts[fromEmail].nickname === fromEmail) {
            state.contacts[fromEmail].nickname = senderNick;
        }
    }

    if (state.activeContact === fromEmail) {
        renderChatHistory();
    } else {
        state.contacts[fromEmail].unread = (state.contacts[fromEmail].unread || 0) + 1;
    }
    renderContacts();
}

/**
 * Handle outgoing message echo
 */
function handleOutgoingMessage(rawTo, text, timestamp) {
    if (!rawTo) return;
    const toEmail = rawTo.toLowerCase().trim();
    const now = timestamp ? (timestamp > 1e11 ? timestamp : timestamp * 1000) : Date.now();

    if (!state.messages[toEmail]) {
        state.messages[toEmail] = [];
    }

    state.messages[toEmail].push({
        from: state.myEmail || 'Я',
        text: text,
        timestamp: timestamp || Math.floor(Date.now() / 1000),
        isMe: true
    });

    if (!state.contacts[toEmail]) {
        state.contacts[toEmail] = {
            email: toEmail,
            nickname: toEmail,
            status: 1,
            unread: 0,
            lastActivity: now
        };
    } else {
        state.contacts[toEmail].lastActivity = now;
    }

    if (state.activeContact === toEmail) {
        renderChatHistory();
    }
    renderContacts();
}

/**
 * Update UI controls based on authentication state
 */
function updateAuthUI(statusMessage) {
    const isAuth = (state.mrimState === 'authenticated');
    if (el.btnLogin) el.btnLogin.classList.toggle('hidden', isAuth);
    if (el.btnLogout) el.btnLogout.classList.toggle('hidden', !isAuth);
    if (el.loginEmail) el.loginEmail.disabled = isAuth;
    if (el.loginPass) el.loginPass.disabled = isAuth;
    if (el.loginStatus) el.loginStatus.disabled = isAuth;

    if (el.messageInput) el.messageInput.disabled = !isAuth || !state.activeContact;
    if (el.btnSend) el.btnSend.disabled = !isAuth || !state.activeContact;

    const emailPreview = document.getElementById('user-email-preview');
    const statusIndicator = document.getElementById('user-status-indicator');
    const avatarImg = document.getElementById('user-avatar-img');
    const defaultSvg = document.getElementById('user-avatar-default');

    if (emailPreview) {
        if (isAuth && state.myEmail) {
            emailPreview.innerHTML = `В сети как: <strong class="user-email-accent">${state.myEmail}</strong>`;
            emailPreview.className = 'user-email-preview authenticated';
            if (statusIndicator) {
                statusIndicator.className = 'user-status-indicator status-online';
                statusIndicator.title = `Статус: В сети (${state.myEmail})`;
            }
            if (avatarImg) applyAvatarWithFallbacks(avatarImg, state.myEmail, defaultSvg);
        } else if (statusMessage) {
            emailPreview.textContent = statusMessage;
            emailPreview.className = 'user-email-preview' + (statusMessage.includes('Ошибка') ? ' error' : '');
            if (statusIndicator) {
                statusIndicator.className = 'user-status-indicator ' + (statusMessage.includes('Подключение') ? 'status-away' : 'status-offline');
                statusIndicator.title = statusMessage;
            }
            const currentEmail = state.myEmail || (el.loginEmail ? el.loginEmail.value.trim() : '');
            if (avatarImg && currentEmail) {
                applyAvatarWithFallbacks(avatarImg, currentEmail, defaultSvg);
            } else if (avatarImg && defaultSvg) {
                avatarImg.classList.add('hidden');
                defaultSvg.classList.remove('hidden');
            }
        } else {
            const loginInputVal = el.loginEmail ? el.loginEmail.value.trim() : '';
            if (loginInputVal) {
                emailPreview.textContent = `Логин: ${loginInputVal}`;
                if (avatarImg) applyAvatarWithFallbacks(avatarImg, loginInputVal, defaultSvg);
            } else {
                emailPreview.textContent = 'Не авторизован';
                if (avatarImg && defaultSvg) {
                    avatarImg.classList.add('hidden');
                    defaultSvg.classList.remove('hidden');
                }
            }
            emailPreview.className = 'user-email-preview';
            if (statusIndicator) {
                statusIndicator.className = 'user-status-indicator status-offline';
                statusIndicator.title = 'Статус: Не в сети';
            }
        }
    }
}

/**
 * Helper to construct primary avatar URL according to MRIM server rules:
 * http://obraz.mrim.su/{domain}/{username}/_mrimavatar (proxied via /avatar/)
 */
function getAvatarUrl(email) {
    if (!email || typeof email !== 'string') return '';
    const cleanEmail = email.trim().toLowerCase();
    let username = cleanEmail;
    let domain = 'mail.ru';

    if (cleanEmail.includes('@')) {
        const parts = cleanEmail.split('@');
        username = parts[0].trim();
        domain = parts[1].trim();
    }

    if (!username || !domain) return '';
    return `/avatar/${encodeURIComponent(domain)}/${encodeURIComponent(username)}`;
}

/**
 * Safely set avatar image on an HTML <img> element with fallback to default silhouette SVG
 */
function applyAvatarWithFallbacks(imgElement, email, defaultSvgElement = null) {
    if (!imgElement) return;

    const cleanEmail = (email || '').trim().toLowerCase();
    if (!cleanEmail) {
        imgElement.dataset.currentEmail = '';
        imgElement.classList.add('hidden');
        if (defaultSvgElement) defaultSvgElement.classList.remove('hidden');
        return;
    }

    const avatarUrl = getAvatarUrl(cleanEmail);
    if (!avatarUrl) {
        imgElement.dataset.currentEmail = '';
        imgElement.classList.add('hidden');
        if (defaultSvgElement) defaultSvgElement.classList.remove('hidden');
        return;
    }

    function showImage() {
        imgElement.classList.remove('hidden');
        if (defaultSvgElement) defaultSvgElement.classList.add('hidden');
    }

    function getInitialSvgUri(str) {
        const username = str.split('@')[0] || str;
        const initial = (username.charAt(0) || '?').toUpperCase();
        const colors = ['#3b5998', '#0066cc', '#0088cc', '#2b579a', '#1e88e5', '#3949ab', '#5e35b1', '#00897b', '#43a047'];
        let hash = 0;
        for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        const color = colors[Math.abs(hash) % colors.length];

        const svgStr = `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" rx="50" fill="${color}"/><text x="50" y="50" font-family="Arial, sans-serif" font-size="48" font-weight="bold" fill="#ffffff" text-anchor="middle" dominant-baseline="central">${initial}</text></svg>`;
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(svgStr);
    }

    // If dataset email matches and src is set, ensure image is unhidden
    if (imgElement.dataset.currentEmail === cleanEmail && imgElement.src) {
        if (imgElement.complete) {
            if (imgElement.naturalWidth > 0) {
                showImage();
            } else {
                imgElement.src = getInitialSvgUri(cleanEmail);
                showImage();
            }
        } else {
            showImage();
        }
        return;
    }

    imgElement.dataset.currentEmail = cleanEmail;

    imgElement.onload = function() {
        showImage();
    };

    imgElement.onerror = function() {
        imgElement.onload = null;
        imgElement.onerror = null;
        imgElement.src = getInitialSvgUri(cleanEmail);
        showImage();
    };

    imgElement.src = avatarUrl;

    if (imgElement.complete) {
        if (imgElement.naturalWidth > 0) {
            showImage();
        } else {
            imgElement.src = getInitialSvgUri(cleanEmail);
            showImage();
        }
    }
}

/**
 * Render Contacts list in left panel
 */
function renderContacts() {
    let keys = Object.keys(state.contacts);
    if (el.contactsCount) {
        el.contactsCount.textContent = `(${keys.length})`;
    }

    const filterText = (el.directTo ? el.directTo.value : '').trim().toLowerCase();
    if (filterText) {
        keys = keys.filter(email => {
            const nick = (state.contacts[email]?.nickname || '').toLowerCase();
            return email.toLowerCase().includes(filterText) || nick.includes(filterText);
        });
    }

    if (keys.length === 0) {
        if (filterText) {
            el.contactsList.innerHTML = '<div class="empty-list">Контакты не найдены<br><small style="color:#778899">Используйте 💬 для чата или 👤+ для добавления</small></div>';
        } else {
            el.contactsList.innerHTML = '<div class="empty-list">Нет контактов (войдите в сеть)</div>';
        }
        return;
    }

    el.contactsList.innerHTML = '';

    keys.sort((a, b) => {
        const actA = getLastActivity(a);
        const actB = getLastActivity(b);
        if (actA !== actB) {
            return actB - actA;
        }
        const unreadA = state.contacts[a]?.unread || 0;
        const unreadB = state.contacts[b]?.unread || 0;
        if (unreadA !== unreadB) {
            return unreadB - unreadA;
        }
        const nameA = (state.contacts[a]?.nickname || a).toLowerCase();
        const nameB = (state.contacts[b]?.nickname || b).toLowerCase();
        return nameA.localeCompare(nameB);
    }).forEach(email => {
        const c = state.contacts[email];
        const item = document.createElement('div');
        item.className = 'contact-item' + (state.activeContact === email ? ' active' : '');
        item.onclick = () => selectContact(email);

        const left = document.createElement('div');
        left.className = 'contact-left';

        const avatarBadge = document.createElement('div');
        avatarBadge.className = 'contact-avatar-badge';

        const avatarImg = document.createElement('img');
        avatarImg.className = 'chat-avatar';
        avatarImg.alt = '';
        applyAvatarWithFallbacks(avatarImg, email);

        const dot = document.createElement('span');
        dot.className = 'contact-status-dot ' + getStatusClass(c.status);
        dot.title = c.status_title || 'Статус: ' + c.status;

        avatarBadge.appendChild(avatarImg);
        avatarBadge.appendChild(dot);

        const nameSpan = document.createElement('span');
        nameSpan.className = 'contact-name';
        nameSpan.textContent = c.nickname || email;
        nameSpan.title = email;

        left.appendChild(avatarBadge);
        left.appendChild(nameSpan);
        item.appendChild(left);

        const right = document.createElement('div');
        right.className = 'contact-right';

        if (c.hasAuthReq) {
            const authBadge = document.createElement('span');
            authBadge.className = 'unread-badge';
            authBadge.style.backgroundColor = '#d29922';
            authBadge.textContent = '🔑';
            authBadge.title = 'Пользователь просит авторизацию';
            right.appendChild(authBadge);
        }

        if (c.unread && c.unread > 0) {
            const badge = document.createElement('span');
            badge.className = 'unread-badge';
            badge.textContent = c.unread;
            right.appendChild(badge);
        }

        item.appendChild(right);

        el.contactsList.appendChild(item);
    });
}

/**
 * Select active contact for chatting
 */
function selectContact(rawEmail) {
    if (!rawEmail) return;
    const email = rawEmail.toLowerCase().trim();
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

    if (el.currentChatTitle) {
        el.currentChatTitle.textContent = `${state.contacts[email].nickname} (${email})`;
    }
    
    const activeChatAvatar = document.getElementById('active-chat-avatar');
    if (activeChatAvatar) {
        applyAvatarWithFallbacks(activeChatAvatar, email);
    }
    const activeChatStatusDot = document.getElementById('active-chat-status-dot');
    if (activeChatStatusDot && state.contacts[email]) {
        activeChatStatusDot.className = 'contact-status-dot ' + getStatusClass(state.contacts[email].status);
        activeChatStatusDot.classList.remove('hidden');
    }

    el.messageInput.disabled = (state.mrimState !== 'authenticated');
    el.btnSend.disabled = (state.mrimState !== 'authenticated');

    if (el.chatHeaderActions) {
        el.chatHeaderActions.classList.toggle('hidden', state.mrimState !== 'authenticated');
    }

    renderContacts();
    renderChatHistory();
    el.messageInput.focus();

    if (window.innerWidth <= 768) {
        switchMobileTab('chat');
    }
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

        const avatar = document.createElement('img');
        avatar.className = 'msg-avatar';
        avatar.alt = '';
        applyAvatarWithFallbacks(avatar, m.isMe ? state.myEmail : m.from);

        const author = document.createElement('span');
        author.className = 'msg-author' + (m.isMe ? ' me' : '');
        author.textContent = m.isMe ? (state.myEmail || 'Я') : m.from;

        const timeStr = formatTime(m.timestamp);
        header.appendChild(avatar);
        header.appendChild(author);
        header.appendChild(document.createTextNode(` [${timeStr}]:`));

        const body = document.createElement('div');
        body.className = 'msg-text';
        body.textContent = m.text;

        item.appendChild(header);
        item.appendChild(body);

        if (m.isAuthReq || (!m.isMe && (m.text.includes('добавьте меня') || m.text.includes('Запрос авторизации')))) {
            const authCard = document.createElement('div');
            authCard.className = 'msg-auth-card';

            const inner = document.createElement('div');
            inner.className = 'auth-card-inner';

            const title = document.createElement('div');
            title.className = 'auth-card-title';
            title.innerHTML = `📬 <b>Запрос авторизации</b> от ${m.from}`;

            const btnApprove = document.createElement('button');
            btnApprove.type = 'button';
            btnApprove.className = 'btn btn-auth btn-approve-card';
            btnApprove.textContent = '✅ Одобрить авторизацию';
            btnApprove.onclick = () => {
                logToConsole(`Одобрение авторизации для ${m.from}...`, 'info');
                sendWsCommand('authorize_contact', { email: m.from });
                sendWsCommand('add_contact', { email: m.from, nickname: m.from });
                btnApprove.disabled = true;
                btnApprove.textContent = '✅ Авторизован!';
                if (state.contacts[m.from]) {
                    state.contacts[m.from].hasAuthReq = false;
                    renderContacts();
                }
            };

            inner.appendChild(title);
            inner.appendChild(btnApprove);
            authCard.appendChild(inner);
            item.appendChild(authCard);
        }

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
    const st = Number(status) || 0;
    if (st === 1 || (st & 0x00000001)) return 'status-online';
    if (st === 2 || (st & 0x00000002)) return 'status-away';
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

    updateAuthUI('Подключение к mrim.su...');
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

if (el.directTo) {
    el.directTo.addEventListener('input', () => {
        renderContacts();
    });
}

if (el.directContactForm) {
    el.directContactForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const email = el.directTo.value.trim();
        if (email) {
            selectContact(email);
            el.directTo.value = '';
            renderContacts();
        }
    });
} else if (el.btnSelectContact) {
    el.btnSelectContact.addEventListener('click', () => {
        const email = el.directTo.value.trim();
        if (email) {
            selectContact(email);
            el.directTo.value = '';
            renderContacts();
        }
    });
}

if (el.btnAddContact) {
    el.btnAddContact.addEventListener('click', () => {
        const email = el.directTo.value.trim();
        if (email) {
            logToConsole(`Отправка запроса на добавление и авторизацию контакта: ${email}...`, 'info');
            sendWsCommand('add_contact', { email, nickname: email });
            selectContact(email);
            el.directTo.value = '';
            renderContacts();
        }
    });
}

if (el.btnAuthorizeActive) {
    el.btnAuthorizeActive.addEventListener('click', () => {
        if (state.activeContact) {
            logToConsole(`Отправка пакета авторизации MRIM_CS_AUTHORIZE для ${state.activeContact}...`, 'info');
            sendWsCommand('authorize_contact', { email: state.activeContact });
        }
    });
}

el.sendForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = el.messageInput.value;
    if (!text || !state.activeContact) return;

    sendWsCommand('send_message', {
        to: state.activeContact,
        text: text
    });

    el.messageInput.value = '';
    el.messageInput.style.height = '32px';
});

el.messageInput.addEventListener('input', () => {
    el.messageInput.style.height = 'auto';
    const newH = Math.min(Math.max(32, el.messageInput.scrollHeight), 96);
    el.messageInput.style.height = newH + 'px';
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
    initLoginPanelToggle();
    initLogsPanelToggle();
    initMobileNavigation();
});

// ==========================================
// Collapsible Logs Panel Controls
// ==========================================
function initLogsPanelToggle() {
    const logsPanel = document.getElementById('logs-panel');
    const logsHeader = document.getElementById('logs-header');
    const toggleBtn = document.getElementById('btn-toggle-logs');

    if (!logsPanel || !toggleBtn) return;

    function toggleLogs(e) {
        if (e && e.target && e.target.id === 'btn-clear-logs') return; // Don't toggle if clearing logs
        const isCollapsed = logsPanel.classList.contains('collapsed');
        if (isCollapsed) {
            logsPanel.classList.remove('collapsed');
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.title = 'Свернуть лог';
        } else {
            logsPanel.classList.add('collapsed');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.title = 'Развернуть лог';
        }
    }

    if (logsHeader) {
        logsHeader.addEventListener('click', (e) => {
            if (e.target.closest('#btn-clear-logs')) return;
            toggleLogs(e);
        });
    }
}

// ==========================================
// Compact / Expandable Login Panel Controls
// ==========================================
function initLoginPanelToggle() {
    const loginPanel = document.getElementById('login-panel');
    const toggleBtn = document.getElementById('btn-toggle-login');
    const emailInput = document.getElementById('login-email');
    const btnLogin = document.getElementById('btn-login');

    if (!loginPanel || !toggleBtn) return;

    function handleEmailInput() {
        if (state.mrimState !== 'authenticated') {
            updateAuthUI();
        }
    }

    // Toggle expand/collapse state
    toggleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        const isExpanded = loginPanel.classList.contains('expanded');
        if (isExpanded) {
            loginPanel.classList.remove('expanded');
            loginPanel.classList.add('collapsed');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.title = 'Развернуть панель';
        } else {
            loginPanel.classList.remove('collapsed');
            loginPanel.classList.add('expanded');
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.title = 'Свернуть панель';
        }
    });

    // Auto-expand if clicking 'Войти в Агент' while panel is collapsed and form fields are missing
    if (btnLogin) {
        btnLogin.addEventListener('click', () => {
            if (loginPanel.classList.contains('collapsed') && (!emailInput.value.trim() || !document.getElementById('login-pass').value)) {
                loginPanel.classList.remove('collapsed');
                loginPanel.classList.add('expanded');
                toggleBtn.setAttribute('aria-expanded', 'true');
                if (!emailInput.value.trim()) {
                    emailInput.focus();
                } else {
                    document.getElementById('login-pass').focus();
                }
            }
        });
    }

    if (emailInput) {
        emailInput.addEventListener('input', handleEmailInput);
    }

    updateAuthUI();
}

// ==========================================
// Responsive Mobile Navigation Controls
// ==========================================
function initMobileNavigation() {
    const btnContacts = document.getElementById('tab-btn-contacts');
    const btnChat = document.getElementById('tab-btn-chat');
    const btnBack = document.getElementById('btn-back-contacts');

    if (btnContacts) {
        btnContacts.addEventListener('click', () => switchMobileTab('contacts'));
    }
    if (btnChat) {
        btnChat.addEventListener('click', () => switchMobileTab('chat'));
    }
    if (btnBack) {
        btnBack.addEventListener('click', () => switchMobileTab('contacts'));
    }
}

function switchMobileTab(tab) {
    const workspace = document.getElementById('workspace');
    const btnContacts = document.getElementById('tab-btn-contacts');
    const btnChat = document.getElementById('tab-btn-chat');

    if (!workspace) return;

    if (tab === 'chat') {
        workspace.classList.add('mobile-view-chat');
        workspace.classList.remove('mobile-view-contacts');
        if (btnContacts) btnContacts.classList.remove('active');
        if (btnChat) btnChat.classList.add('active');
    } else {
        workspace.classList.add('mobile-view-contacts');
        workspace.classList.remove('mobile-view-chat');
        if (btnContacts) btnContacts.classList.add('active');
        if (btnChat) btnChat.classList.remove('active');
    }
}
