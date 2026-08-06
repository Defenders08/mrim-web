/**
 * MRIM Web Client — ES6 Pure JavaScript Frontend
 *
 * Communicates with the local PHP WebSocket server, which bridges
 * commands and binary packets to the Mail.Ru Instant Messenger (mrim.su) server.
 */

/**
 * Safely escape HTML entities to prevent XSS vulnerabilities
 */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// In-Memory state (no database required)
const state = {
    ws: null,
    wsConnected: false,
    mrimState: 'disconnected', // disconnected, connecting, connected, authenticated
    myEmail: '',
    contacts: {},       // email -> { email, nickname, status, unread }
    activeContact: null,// currently selected contact email
    messages: {},       // email -> [ { from, text, timestamp, isMe } ]
    soundEnabled: true, // sound notifications toggle
    manualLogout: false, // track manual logout to prevent auto-login loop
};

/**
 * Saved Login & Password persistence using localStorage
 */
const CREDENTIALS_KEY = 'mrim_saved_creds_v1';

function saveSavedCredentials(email, password, status, remember) {
    if (!remember) {
        localStorage.removeItem(CREDENTIALS_KEY);
        return;
    }
    try {
        localStorage.setItem(CREDENTIALS_KEY, JSON.stringify({ email, password, status }));
    } catch (e) {
        console.warn('Ошибка сохранения учетных данных:', e);
    }
}

function loadSavedCredentials() {
    try {
        const stored = localStorage.getItem(CREDENTIALS_KEY);
        if (!stored) return null;
        return JSON.parse(stored);
    } catch (e) {
        return null;
    }
}

function isRememberMeEnabled() {
    const pref = localStorage.getItem('mrim_remember_me_pref');
    if (pref === 'false') return false;
    if (pref === 'true') return true;
    return loadSavedCredentials() !== null;
}

function restoreSavedCredentials() {
    if (!isRememberMeEnabled()) return;
    const creds = loadSavedCredentials();
    if (creds && creds.email) {
        if (el.loginEmail) el.loginEmail.value = creds.email;
        if (el.loginPass) el.loginPass.value = creds.password || '';
        if (el.loginStatus && creds.status) el.loginStatus.value = creds.status;
    }
}

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
    btnSettings:  document.getElementById('btn-settings'),
    settingsModal:      document.getElementById('settings-modal'),
    settingsRememberMe: document.getElementById('settings-remember-me'),
    settingsThemeSelect:document.getElementById('settings-theme-select'),
    customThemeUploadBox: document.getElementById('custom-theme-upload-box'),
    settingsCustomThemeFile: document.getElementById('settings-custom-theme-file'),
    customThemeStatus: document.getElementById('custom-theme-status'),
    btnSaveSettings:    document.getElementById('btn-save-settings'),
    btnCloseSettings:   document.getElementById('btn-close-settings'),
    btnCloseSettingsX:  document.getElementById('btn-close-settings-x'),
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
    btnClearHistory:   document.getElementById('btn-clear-history'),
    btnWakeupActive:   document.getElementById('btn-wakeup-active'),
    btnSoundToggle:    document.getElementById('btn-sound-toggle'),
    typingIndicator:  document.getElementById('typing-indicator'),
    chatHistory:  document.getElementById('chat-history'),
    sendForm:     document.getElementById('send-form'),
    messageInput: document.getElementById('message-input'),
    btnSend:      document.getElementById('btn-send'),
    btnSmiles:    document.getElementById('btn-smiles'),
    logsConsole:  document.getElementById('logs-console'),
    btnClearLogs: document.getElementById('btn-clear-logs'),
};

/**
 * Local chat history persistence using localStorage
 */
function saveHistoryToStorage() {
    if (!state.myEmail) return;
    try {
        const historyKey = 'mrim_history_' + state.myEmail.toLowerCase().trim();
        const contactsKey = 'mrim_contacts_' + state.myEmail.toLowerCase().trim();

        const historyToSave = {};
        Object.keys(state.messages).forEach(contactEmail => {
            const msgs = state.messages[contactEmail] || [];
            if (msgs.length > 0) {
                historyToSave[contactEmail.toLowerCase().trim()] = msgs.slice(-500);
            }
        });
        localStorage.setItem(historyKey, JSON.stringify(historyToSave));

        const contactsToSave = {};
        Object.keys(state.contacts).forEach(contactEmail => {
            const c = state.contacts[contactEmail];
            contactsToSave[contactEmail.toLowerCase().trim()] = {
                email: c.email,
                nickname: c.nickname || c.email,
                status: c.status || 0
            };
        });
        localStorage.setItem(contactsKey, JSON.stringify(contactsToSave));
    } catch (e) {
        console.warn('Ошибка сохранения истории в localStorage:', e);
    }
}

function loadHistoryFromStorage() {
    if (!state.myEmail) return;
    try {
        const historyKey = 'mrim_history_' + state.myEmail.toLowerCase().trim();
        const contactsKey = 'mrim_contacts_' + state.myEmail.toLowerCase().trim();

        // 1. Restore saved contacts list
        const savedContacts = localStorage.getItem(contactsKey);
        if (savedContacts) {
            const parsedContacts = JSON.parse(savedContacts);
            Object.keys(parsedContacts).forEach(cEmail => {
                const norm = cEmail.toLowerCase().trim();
                if (!state.contacts[norm]) {
                    state.contacts[norm] = {
                        email: norm,
                        nickname: parsedContacts[cEmail].nickname || norm,
                        status: parsedContacts[cEmail].status || 0,
                        unread: 0
                    };
                }
            });
        }

        // 2. Restore saved chat messages
        const savedHistory = localStorage.getItem(historyKey);
        if (savedHistory) {
            const parsedHistory = JSON.parse(savedHistory);
            let totalLoaded = 0;

            Object.keys(parsedHistory).forEach(cEmail => {
                const norm = cEmail.toLowerCase().trim();
                if (!state.messages[norm]) {
                    state.messages[norm] = [];
                }
                const savedMsgs = parsedHistory[cEmail] || [];
                const currentMsgs = state.messages[norm];

                savedMsgs.forEach(m => {
                    const mTs = m.timestamp > 1e11 ? Math.floor(m.timestamp / 1000) : (m.timestamp || 0);
                    const exists = currentMsgs.some(cur => {
                        const curTs = cur.timestamp > 1e11 ? Math.floor(cur.timestamp / 1000) : (cur.timestamp || 0);
                        return cur.text === m.text && cur.isMe === m.isMe && (!mTs || !curTs || Math.abs(curTs - mTs) <= 300);
                    });
                    if (!exists) {
                        currentMsgs.push({
                            ...m,
                            timestamp: mTs || Math.floor(Date.now() / 1000)
                        });
                        totalLoaded++;
                    }
                });

                currentMsgs.sort((a, b) => (a.timestamp || 0) - (b.timestamp || 0));

                if (!state.contacts[norm]) {
                    state.contacts[norm] = {
                        email: norm,
                        nickname: norm,
                        status: 0,
                        unread: 0
                    };
                }
            });

            if (totalLoaded > 0) {
                logToConsole(`📥 Загружена история диалогов из localStorage (${totalLoaded} сообщ.)`, 'info');
            }
        }
    } catch (e) {
        console.warn('Ошибка загрузки истории из localStorage:', e);
    }
}

function clearHistoryForActiveContact() {
    if (!state.activeContact) return;
    const contact = state.activeContact.toLowerCase().trim();
    if (!state.messages[contact] || state.messages[contact].length === 0) {
        logToConsole(`История сообщений с ${contact} уже пуста.`, 'info');
        return;
    }

    if (confirm(`Вы действительно хотите очистить сохранённую историю сообщений с ${contact}?`)) {
        state.messages[contact] = [];
        saveHistoryToStorage();
        renderChatHistory();
        logToConsole(`🗑️ Локальная история сообщений с ${contact} очищена.`, 'warning');
    }
}

/**
 * Initialize WebSocket connection to local PHP server
 */
function connectWebSocket() {
    const protocol = location.protocol === 'https:' ? 'wss://' : 'ws://';
    const wsUrl = protocol + location.host + '/ws';

    logToConsole(`Подключение к WebSocket серверу (${wsUrl})...`, 'info');
    if (el.wsStatusText) {
        el.wsStatusText.textContent = '';
        el.wsStatusText.title = 'WebSocket: Подключение...';
        el.wsStatusText.style.backgroundColor = '#ffaa00';
    }

    const ws = new WebSocket(wsUrl);
    state.ws = ws;

    ws.onopen = () => {
        state.wsConnected = true;
        if (el.wsStatusText) {
            el.wsStatusText.textContent = '';
            el.wsStatusText.title = 'WebSocket: Подключено';
            el.wsStatusText.style.backgroundColor = '#00cc00';
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
            el.wsStatusText.textContent = '';
            el.wsStatusText.title = 'WebSocket: Отключено (реконнект...)';
            el.wsStatusText.style.backgroundColor = '#ff0000';
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
                }
                loadHistoryFromStorage();
                renderContacts();
                renderChatHistory();
            } else {
                // Wipe local state completely on new or unauthenticated connection
                state.myEmail = '';
                state.contacts = {};
                state.messages = {};
                state.activeContact = null;
                renderContacts();
                renderChatHistory();

                // Auto-login if saved credentials exist, remember me is enabled, and user hasn't manually logged out
                const savedCreds = loadSavedCredentials();
                if (savedCreds && savedCreds.email && savedCreds.password && !state.manualLogout && isRememberMeEnabled()) {
                    logToConsole(`Автоматический вход под сохраненным аккаунтом (${savedCreds.email})...`, 'info');
                    sendWsCommand('login', {
                        email: savedCreds.email,
                        password: savedCreds.password,
                        status: savedCreds.status || 1
                    });
                }
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
            loadHistoryFromStorage();
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

        case 'wakeup':
            if (data && data.from) {
                logToConsole(`🔔 БУДИЛЬНИК (WakeUp) от ${data.from}: ${data.text}`, 'warning');
                triggerWakeUpEffect(data.from, data.text, data.timestamp);
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
function handleIncomingMessage(rawFrom, text, timestamp, isAuthReq = false, senderNick = '', isWakeUp = false) {
    if (!rawFrom) return;
    const fromEmail = rawFrom.toLowerCase().trim();
    const now = timestamp ? (timestamp > 1e11 ? timestamp : timestamp * 1000) : Date.now();
    const msgTs = timestamp ? (timestamp > 1e11 ? Math.floor(timestamp / 1000) : timestamp) : Math.floor(Date.now() / 1000);

    if (fromEmail.includes('admin@mrim.su')) {
        console.log("TEST MESSAGE FROM ADMIN RECEIVED");
        logToConsole("TEST MESSAGE FROM ADMIN RECEIVED", 'info');
    }

    if (!state.messages[fromEmail]) {
        state.messages[fromEmail] = [];
    }

    // Deduplicate incoming message if identical message already exists in history
    const isDuplicate = state.messages[fromEmail].some(m => {
        if (m.isMe) return false;
        if (m.text !== text) return false;
        const mTs = m.timestamp > 1e11 ? Math.floor(m.timestamp / 1000) : (m.timestamp || 0);
        return !msgTs || !mTs || Math.abs(mTs - msgTs) <= 300;
    });

    if (isDuplicate) {
        logToConsole(`Пропущено дубликатное сообщение от ${fromEmail}: "${text.substring(0, 30)}..."`, 'debug');
        return;
    }

    state.messages[fromEmail].push({
        from: fromEmail,
        text: text,
        timestamp: msgTs,
        isMe: false,
        isAuthReq: isAuthReq || false,
        senderNick: senderNick || '',
        isWakeUp: isWakeUp || false
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
    saveHistoryToStorage();

    if (!isWakeUp) {
        playIncomingSound();
    }
}

/**
 * Pre-instantiated audio objects for zero-latency sound effects
 */
const incomingAudio = new Audio('/res/vk1.wav');
const outgoingAudio = new Audio('/res/vk1.wav');
const alarmAudio = new Audio('/res/alarm.wav');
incomingAudio.preload = 'auto';
outgoingAudio.preload = 'auto';
alarmAudio.preload = 'auto';

/**
 * Play sound for incoming message
 */
function playIncomingSound() {
    if (!state.soundEnabled) return;
    try {
        incomingAudio.currentTime = 0;
        const playPromise = incomingAudio.play();
        if (playPromise !== undefined) {
            playPromise.catch(() => {
                playWebAudioTone([587.33, 880], [0.12, 0.18], 'sine');
            });
        }
    } catch (e) {
        playWebAudioTone([587.33, 880], [0.12, 0.18], 'sine');
    }
}

/**
 * Play sound for outgoing message
 */
function playOutgoingSound() {
    if (!state.soundEnabled) return;
    try {
        outgoingAudio.currentTime = 0;
        const playPromise = outgoingAudio.play();
        if (playPromise !== undefined) {
            playPromise.catch(() => {
                playWebAudioTone([660, 990], [0.08, 0.12], 'triangle');
            });
        }
    } catch (e) {
        playWebAudioTone([660, 990], [0.08, 0.12], 'triangle');
    }
}

/**
 * Play sound for alarm/wakeup
 */
function playAlarmSound() {
    if (!state.soundEnabled) return;
    try {
        alarmAudio.currentTime = 0;
        const playPromise = alarmAudio.play();
        if (playPromise !== undefined) {
            playPromise.catch(() => {
                playWebAudioAlarm();
            });
        }
    } catch (e) {
        playWebAudioAlarm();
    }
}

/**
 * Fallback Web Audio API synthesizer for notification sounds
 */
function playWebAudioTone(frequencies = [587.33, 880], durations = [0.1, 0.15], type = 'sine') {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        let now = ctx.currentTime;

        frequencies.forEach((freq, index) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            const dur = durations[index] || 0.1;

            osc.type = type;
            osc.frequency.setValueAtTime(freq, now);
            gain.gain.setValueAtTime(0.15, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + dur);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(now);
            osc.stop(now + dur);
            now += dur * 0.8;
        });
    } catch (e) {
        console.warn('AudioContext tone error:', e);
    }
}

/**
 * Trigger WakeUp visual shake animation and play audio alarm
 */
function triggerWakeUpEffect(from, text, timestamp) {
    if (!from) return;
    const fromEmail = from.toLowerCase().trim();

    // 1. Play audio alarm sound instantly from preloaded audio
    playAlarmSound();

    // 2. Insert alarm message into chat (with shake effect target on the message bubble)
    const alarmText = '🔔 Собеседник отправил будильник!';
    handleIncomingMessage(fromEmail, alarmText, timestamp, false, null, true);
}

/**
 * Fallback Web Audio API synthesizer for alarm tone
 */
function playWebAudioAlarm() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) {
            const ctx = new AudioCtx();
            const now = ctx.currentTime;

            [0, 0.15, 0.3, 0.45].forEach((offset) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(880, now + offset);
                osc.frequency.exponentialRampToValueAtTime(1760, now + offset + 0.1);
                gain.gain.setValueAtTime(0.3, now + offset);
                gain.gain.exponentialRampToValueAtTime(0.01, now + offset + 0.1);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now + offset);
                osc.stop(now + offset + 0.1);
            });
        }
    } catch (e) {
        console.warn('AudioContext alarm error:', e);
    }
}

/**
 * Handle outgoing message echo or optimistic update
 */
function handleOutgoingMessage(rawTo, text, timestamp) {
    if (!rawTo) return;
    const toEmail = rawTo.toLowerCase().trim();
    const now = timestamp ? (timestamp > 1e11 ? timestamp : timestamp * 1000) : Date.now();
    const msgTs = timestamp ? (timestamp > 1e11 ? Math.floor(timestamp / 1000) : timestamp) : Math.floor(Date.now() / 1000);

    if (!state.messages[toEmail]) {
        state.messages[toEmail] = [];
    }

    // Deduplicate if identical outgoing message was already added optimistically
    const isDuplicate = state.messages[toEmail].some(m => {
        if (!m.isMe || m.text !== text) return false;
        const mTs = m.timestamp > 1e11 ? Math.floor(m.timestamp / 1000) : m.timestamp;
        return Math.abs(mTs - msgTs) <= 5;
    });

    if (isDuplicate) {
        return;
    }

    state.messages[toEmail].push({
        from: state.myEmail || 'Я',
        text: text,
        timestamp: msgTs,
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
    saveHistoryToStorage();
}

/**
 * Update UI controls based on authentication state
 */
function updateAuthUI(statusMessage) {
    const isAuth = (state.mrimState === 'authenticated');
    if (el.btnLogin) el.btnLogin.classList.toggle('hidden', isAuth);
    if (el.btnLogout) el.btnLogout.classList.toggle('hidden', !isAuth);
    if (el.btnReconnect) el.btnReconnect.classList.toggle('hidden', !isAuth);
    if (el.loginEmail) el.loginEmail.disabled = isAuth;
    if (el.loginPass) el.loginPass.disabled = isAuth;
    if (el.loginStatus) el.loginStatus.disabled = isAuth;

    if (el.messageInput) el.messageInput.disabled = !isAuth || !state.activeContact;
    if (el.btnSend) el.btnSend.disabled = !isAuth || !state.activeContact;
    if (el.btnSmiles) el.btnSmiles.disabled = !isAuth || !state.activeContact;
    if (el.btnWakeupActive) el.btnWakeupActive.disabled = !isAuth || !state.activeContact;

    if (!isAuth || !state.activeContact) {
        const smilePopup = document.getElementById('smile-picker-popup');
        if (smilePopup) smilePopup.classList.add('hidden');
    }

    const emailPreview = document.getElementById('user-email-preview');
    const statusIndicator = document.getElementById('user-status-indicator');
    const avatarImg = document.getElementById('user-avatar-img');
    const defaultSvg = document.getElementById('user-avatar-default');

    if (emailPreview) {
        if (isAuth && state.myEmail) {
            emailPreview.innerHTML = `В сети как: <strong class="user-email-accent">${escapeHtml(state.myEmail)}</strong>`;
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
 * Avatar Cache Manager using localStorage and Data URLs for instant loading
 */
const avatarCache = (function() {
    let cache = {};
    const CACHE_KEY = 'mrim_avatars_v1';
    const MAX_ENTRIES = 60;

    try {
        const stored = localStorage.getItem(CACHE_KEY);
        if (stored) cache = JSON.parse(stored);
    } catch (e) {
        cache = {};
    }

    function save() {
        try {
            const keys = Object.keys(cache);
            if (keys.length > MAX_ENTRIES) {
                const toDelete = keys.slice(0, keys.length - MAX_ENTRIES);
                toDelete.forEach(k => delete cache[k]);
            }
            localStorage.setItem(CACHE_KEY, JSON.stringify(cache));
        } catch (e) {
            console.warn('Avatar cache storage limit reached or disabled:', e);
        }
    }

    return {
        get(email) {
            if (!email) return null;
            return cache[email.toLowerCase().trim()] || null;
        },
        set(email, dataUrl) {
            if (!email || !dataUrl) return;
            cache[email.toLowerCase().trim()] = dataUrl;
            save();
        },
        cacheImage(email, imgElement) {
            if (!email || !imgElement) return;
            const normEmail = email.toLowerCase().trim();
            if (cache[normEmail]) return;

            try {
                if (!imgElement.naturalWidth || !imgElement.naturalHeight) return;
                const canvas = document.createElement('canvas');
                canvas.width = Math.min(imgElement.naturalWidth, 128);
                canvas.height = Math.min(imgElement.naturalHeight, 128);
                const ctx = canvas.getContext('2d');
                ctx.drawImage(imgElement, 0, 0, canvas.width, canvas.height);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                this.set(normEmail, dataUrl);
            } catch (e) {
                // Ignore canvas export errors if any
            }
        }
    };
})();

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

    function showImage() {
        imgElement.classList.remove('hidden');
        if (defaultSvgElement) defaultSvgElement.classList.add('hidden');
    }

    function getInitialSvgUri(str) {
        const username = str.split('@')[0] || str;
        const initial = escapeHtml((username.charAt(0) || '?').toUpperCase());
        const colors = ['#3b5998', '#0066cc', '#0088cc', '#2b579a', '#1e88e5', '#3949ab', '#5e35b1', '#00897b', '#43a047'];
        let hash = 0;
        for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        const color = colors[Math.abs(hash) % colors.length];

        const svgStr = `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" rx="50" fill="${color}"/><text x="50" y="50" font-family="Arial, sans-serif" font-size="48" font-weight="bold" fill="#ffffff" text-anchor="middle" dominant-baseline="central">${initial}</text></svg>`;
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(svgStr);
    }

    // Check avatar cache first
    const cachedDataUrl = avatarCache.get(cleanEmail);
    if (cachedDataUrl) {
        imgElement.dataset.currentEmail = cleanEmail;
        imgElement.src = cachedDataUrl;
        showImage();
        return;
    }

    const avatarUrl = getAvatarUrl(cleanEmail);
    if (!avatarUrl) {
        imgElement.dataset.currentEmail = '';
        imgElement.classList.add('hidden');
        if (defaultSvgElement) defaultSvgElement.classList.remove('hidden');
        return;
    }

    // If dataset email matches and src is set, ensure image is unhidden
    if (imgElement.dataset.currentEmail === cleanEmail && imgElement.src) {
        if (imgElement.complete) {
            if (imgElement.naturalWidth > 0) {
                showImage();
                avatarCache.cacheImage(cleanEmail, imgElement);
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
        avatarCache.cacheImage(cleanEmail, imgElement);
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
            avatarCache.cacheImage(cleanEmail, imgElement);
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
    if (el.btnSmiles) el.btnSmiles.disabled = (state.mrimState !== 'authenticated');
    if (el.btnWakeupActive) el.btnWakeupActive.disabled = (state.mrimState !== 'authenticated');

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
        item.className = 'message-item' + (m.isWakeUp ? ' wakeup-message shake-effect' : '');

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
            title.innerHTML = `📬 <b>Запрос авторизации</b> от ${escapeHtml(m.from)}`;

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
    state.manualLogout = false;
    const email = el.loginEmail.value.trim();
    const password = el.loginPass.value;
    const status = parseInt(el.loginStatus.value, 10) || 1;
    const remember = isRememberMeEnabled();

    saveSavedCredentials(email, password, status, remember);

    updateAuthUI('Подключение к mrim.su...');
    sendWsCommand('login', { email, password, status });
});

el.btnLogout.addEventListener('click', () => {
    state.manualLogout = true;
    sendWsCommand('logout');
});

el.btnReconnect.addEventListener('click', () => {
    logToConsole('Запрос на ручное переподключение (Reconnect)...', 'warning');
    sendWsCommand('reconnect');
});

if (el.btnSettings) {
    el.btnSettings.addEventListener('click', () => {
        openSettingsModal();
    });
}

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

if (el.btnClearHistory) {
    el.btnClearHistory.addEventListener('click', () => {
        clearHistoryForActiveContact();
    });
}

if (el.btnWakeupActive) {
    el.btnWakeupActive.addEventListener('click', () => {
        if (!state.activeContact) return;
        if (state.mrimState !== 'authenticated') {
            logToConsole('Ошибка: Вы должны войти в систему, чтобы отправлять Будильник.', 'error');
            return;
        }
        playAlarmSound();
        sendWsCommand('send_wakeup', { to: state.activeContact });
        handleOutgoingMessage(state.activeContact, '🔔 Вы отправили БУДИЛЬНИК!', Math.floor(Date.now() / 1000));
        logToConsole(`🔔 Отправлен БУДИЛЬНИК (WakeUp) контакту ${state.activeContact}`, 'info');
    });
}

if (el.btnSoundToggle) {
    el.btnSoundToggle.addEventListener('click', () => {
        state.soundEnabled = !state.soundEnabled;
        el.btnSoundToggle.textContent = state.soundEnabled ? 'Звук: Вкл' : 'Звук: Выкл';
        logToConsole(`Звуковые уведомления ${state.soundEnabled ? 'включены' : 'выключены'}`, 'info');
        if (state.soundEnabled) {
            playOutgoingSound();
        }
    });
}

// Setup contenteditable messageInput behavior
function setupMessageInput() {
    if (!el.messageInput) return;

    Object.defineProperty(el.messageInput, 'value', {
        get() {
            let result = '';
            function walk(node) {
                for (let child = node.firstChild; child; child = child.nextSibling) {
                    if (child.nodeType === Node.TEXT_NODE) {
                        result += child.nodeValue;
                    } else if (child.nodeType === Node.ELEMENT_NODE) {
                        if (child.tagName === 'IMG' && child.dataset.smileId) {
                            const id = child.dataset.smileId;
                            const alt = child.dataset.smileAlt || '';
                            result += `<SMILE> id=${id} alt='${alt}'</SMILE>`;
                        } else if (child.tagName === 'BR') {
                            result += '\n';
                        } else if (child.tagName === 'DIV' || child.tagName === 'P') {
                            if (result.length > 0 && !result.endsWith('\n')) {
                                result += '\n';
                            }
                            walk(child);
                        } else {
                            walk(child);
                        }
                    }
                }
            }
            walk(el.messageInput);
            return result;
        },
        set(val) {
            if (!val) {
                el.messageInput.innerHTML = '';
                return;
            }
            const map = window.smileMap || {};
            const path = window.SMILE_PATH || '/res/';
            const smileRegex = /<SMILE>\s*id=(\d+)\s+alt='([^']*)'\s*<\/SMILE>/gi;

            let html = '';
            let lastIndex = 0;
            let match;
            smileRegex.lastIndex = 0;

            while ((match = smileRegex.exec(val)) !== null) {
                if (match.index > lastIndex) {
                    html += escapeHtml(val.substring(lastIndex, match.index));
                }
                const id = match[1];
                const alt = match[2] || '';
                const fileName = map[id] || alt || 'smile.gif';
                const safeAlt = escapeHtml(alt);
                const safeFileName = escapeHtml(fileName);
                const smileSrc = (window.getSmileSrc && window.getSmileSrc(id)) || `${path}${safeFileName}`;
                html += `<img src="${smileSrc}" data-smile-id="${id}" data-smile-alt="${safeAlt}" class="mrim-smile-input" draggable="false" alt="${safeAlt}">`;
                lastIndex = smileRegex.lastIndex;
            }
            if (lastIndex < val.length) {
                html += escapeHtml(val.substring(lastIndex));
            }

            html = html.replace(/\n/g, '<br>');
            el.messageInput.innerHTML = html;
        },
        configurable: true
    });

    Object.defineProperty(el.messageInput, 'disabled', {
        get() {
            return el.messageInput.getAttribute('contenteditable') === 'false';
        },
        set(val) {
            el.messageInput.setAttribute('contenteditable', val ? 'false' : 'true');
            if (val) {
                el.messageInput.classList.add('disabled');
            } else {
                el.messageInput.classList.remove('disabled');
            }
        },
        configurable: true
    });

    el.messageInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
    });

    el.messageInput.addEventListener('input', () => {
        if (el.messageInput.innerHTML === '<br>' || el.messageInput.textContent.trim() === '') {
            if (!el.messageInput.querySelector('img')) {
                el.messageInput.innerHTML = '';
            }
        }
    });

    el.messageInput.addEventListener('focus', () => {
        setTimeout(() => {
            if (el.chatHistory) {
                el.chatHistory.scrollTop = el.chatHistory.scrollHeight;
            }
        }, 300);
    });
}

setupMessageInput();

el.sendForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = el.messageInput.value;
    if (!text || !state.activeContact) return;

    // Play sound immediately when sending
    playOutgoingSound();

    // Optimistically render message in UI immediately
    handleOutgoingMessage(state.activeContact, text, Math.floor(Date.now() / 1000));

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

/**
 * Theme Manager logic (loads custom theme CSS from /styles/ directory or user uploaded CSS)
 */
const THEME_KEY = 'mrim_theme_preference_v1';
const CUSTOM_THEME_CSS_KEY = 'mrim_custom_theme_css_v1';

function getCurrentTheme() {
    return localStorage.getItem(THEME_KEY) || 'default';
}

function applyTheme(themeName) {
    const validThemes = [
        'default',
        'agent6',
        'agent6_red',
        'agent6_green',
        'agent6_cyan',
        'agent6_blue',
        'agent6_purple',
        'agent6_white',
        'agent6_black',
        'win95',
        'matrix',
        'custom'
    ];
    if (!validThemes.includes(themeName)) {
        themeName = 'default';
    }

    const themeLink = document.getElementById('theme-stylesheet');
    let customStyleTag = document.getElementById('custom-theme-style');

    if (themeName === 'custom') {
        const customCss = localStorage.getItem(CUSTOM_THEME_CSS_KEY) || '';
        if (!customStyleTag) {
            customStyleTag = document.createElement('style');
            customStyleTag.id = 'custom-theme-style';
            document.head.appendChild(customStyleTag);
        }
        customStyleTag.textContent = customCss;
        if (themeLink) {
            themeLink.href = 'styles/default.css'; // Keep default as base structural style
        }
    } else {
        if (customStyleTag) {
            customStyleTag.textContent = '';
        }
        if (themeLink) {
            themeLink.href = `styles/${themeName}.css`;
        }
    }

    localStorage.setItem(THEME_KEY, themeName);
    updateCustomThemeUI(themeName);
}

function updateCustomThemeUI(currentTheme) {
    if (!el.customThemeUploadBox) return;
    if (currentTheme === 'custom') {
        el.customThemeUploadBox.classList.remove('hidden');
        if (el.customThemeStatus) {
            const hasCustomCss = !!localStorage.getItem(CUSTOM_THEME_CSS_KEY);
            el.customThemeStatus.textContent = hasCustomCss ? 'Пользовательская тема загружена и активна' : 'Выберите .css файл с вашего компьютера';
        }
    } else {
        el.customThemeUploadBox.classList.add('hidden');
    }
}

function restoreSavedTheme() {
    const theme = getCurrentTheme();
    applyTheme(theme);
    if (el.settingsThemeSelect) {
        el.settingsThemeSelect.value = theme;
    }
}

function openSettingsModal() {
    if (!el.settingsModal) return;
    if (el.settingsRememberMe) {
        el.settingsRememberMe.checked = isRememberMeEnabled();
    }
    const theme = getCurrentTheme();
    if (el.settingsThemeSelect) {
        el.settingsThemeSelect.value = theme;
    }
    updateCustomThemeUI(theme);
    updatePWAStatus();
    el.settingsModal.classList.remove('hidden');
}

function closeSettingsModal() {
    if (!el.settingsModal) return;
    el.settingsModal.classList.add('hidden');
}

function updatePWAStatus() {
    const pwaStatusText = document.getElementById('pwa-status-text');
    if (!pwaStatusText) return;
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isStandalone) {
        pwaStatusText.innerHTML = 'Приложение запущенно как PWA (автономный режим)';
    } else {
        pwaStatusText.innerHTML = 'В зависимости от устройства вы можете установить клиент на экран iPhone или ПК.';
    }
}

function saveSettings() {
    if (el.settingsRememberMe) {
        const remember = el.settingsRememberMe.checked;
        localStorage.setItem('mrim_remember_me_pref', remember ? 'true' : 'false');

        if (!remember) {
            localStorage.removeItem(CREDENTIALS_KEY);
        } else {
            const email = el.loginEmail ? el.loginEmail.value.trim() : '';
            const password = el.loginPass ? el.loginPass.value : '';
            const status = el.loginStatus ? (parseInt(el.loginStatus.value, 10) || 1) : 1;
            if (email && password) {
                saveSavedCredentials(email, password, status, true);
            }
        }
    }

    if (el.settingsThemeSelect) {
        const selectedTheme = el.settingsThemeSelect.value;
        applyTheme(selectedTheme);
        logToConsole(`Тема оформления изменена и сохранена: ${selectedTheme}`, 'info');
    }

    logToConsole('Настройки успешно сохранены.', 'info');
    closeSettingsModal();
}

function initSettingsModal() {
    if (el.settingsThemeSelect) {
        el.settingsThemeSelect.addEventListener('change', (e) => {
            const newTheme = e.target.value;
            applyTheme(newTheme);
            logToConsole(`Предпросмотр темы: ${newTheme}`, 'info');
        });
    }

    if (el.settingsCustomThemeFile) {
        el.settingsCustomThemeFile.addEventListener('change', (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (event) => {
                const cssContent = event.target.result;
                localStorage.setItem(CUSTOM_THEME_CSS_KEY, cssContent);
                if (el.customThemeStatus) {
                    el.customThemeStatus.textContent = `Файл "${file.name}" успешно загружен!`;
                }
                applyTheme('custom');
                if (el.settingsThemeSelect) {
                    el.settingsThemeSelect.value = 'custom';
                }
                logToConsole(`Загружена пользовательская тема из файла: ${file.name}`, 'info');
            };
            reader.readAsText(file);
        });
    }

    if (el.btnSettings) {
        el.btnSettings.addEventListener('click', () => {
            openSettingsModal();
        });
    }

    if (el.btnSaveSettings) {
        el.btnSaveSettings.addEventListener('click', () => {
            saveSettings();
        });
    }

    if (el.btnCloseSettings) {
        el.btnCloseSettings.addEventListener('click', () => {
            closeSettingsModal();
        });
    }

    if (el.btnCloseSettingsX) {
        el.btnCloseSettingsX.addEventListener('click', () => {
            closeSettingsModal();
        });
    }

    if (el.settingsModal) {
        el.settingsModal.addEventListener('click', (e) => {
            if (e.target === el.settingsModal) {
                closeSettingsModal();
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && el.settingsModal && !el.settingsModal.classList.contains('hidden')) {
            closeSettingsModal();
        }
    });
}

// Start WebSocket connection when DOM is ready
window.addEventListener('DOMContentLoaded', () => {
    restoreSavedTheme();
    restoreSavedCredentials();
    connectWebSocket();
    initLoginPanelToggle();
    initLogsPanelToggle();
    initMobileNavigation();
    initSmilePicker();
    initSettingsModal();
    initPWAManager();
});

// ==========================================
// PWA (Progressive Web App) Manager
// ==========================================
let deferredPwaPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPwaPrompt = e;
    const nativeBox = document.getElementById('pwa-native-install-box');
    if (nativeBox) {
        nativeBox.classList.remove('hidden');
    }
});

function initPWAManager() {
    // Register Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
            console.log('[PWA] Service Worker успешно зарегистрирован:', reg.scope);
        }).catch((err) => {
            console.warn('[PWA] Ошибка регистрации Service Worker:', err);
        });
    }

    const btnTriggerInstall = document.getElementById('btn-pwa-trigger-install');

    if (btnTriggerInstall) {
        btnTriggerInstall.addEventListener('click', async () => {
            if (deferredPwaPrompt) {
                deferredPwaPrompt.prompt();
                const { outcome } = await deferredPwaPrompt.userChoice;
                console.log(`[PWA] Результат установки: ${outcome}`);
                deferredPwaPrompt = null;
                const nativeBox = document.getElementById('pwa-native-install-box');
                if (nativeBox) nativeBox.classList.add('hidden');
                closeSettingsModal();
            }
        });
    }
}

// ==========================================
// Smile Picker Controls
// ==========================================
function initSmilePicker() {
    const btnSmiles = document.getElementById('btn-smiles');
    const popup = document.getElementById('smile-picker-popup');
    const smileGrid = document.getElementById('smile-picker-grid');
    const kaomojiGrid = document.getElementById('kaomoji-picker-grid');
    const btnClose = document.getElementById('btn-close-smiles');
    const tabSmiles = document.getElementById('tab-btn-smiles');
    const tabKaomoji = document.getElementById('tab-btn-kaomoji');
    const input = el.messageInput;

    if (!btnSmiles || !popup || !smileGrid || !kaomojiGrid) return;

    let activeTab = 'smiles';

    const kaomojiList = [
        "(⁠◕⁠ᴗ⁠◕⁠✿⁠)", "(⁠*⁠^⁠-⁠^⁠*⁠)", "⊂(￣▽￣)⊃", "(⁠≧⁠◡⁠≦⁠)", "＼(￣▽￣)／", "ヽ(♡‿♡)ノ",
        "(⁠｡⁠♥⁠‿⁠♥⁠｡⁠)", "(⁠◠⁠‿⁠◕⁠)", "ԅ(¯ㅂ¯ԅ)", "(⁠/⁠¯⁠◡⁠o⁠)⁠/⁠*⁠:⁠･ﾟ", "(⁠~⁠￣⁠³⁠￣⁠)⁠~", "(b_d)",
        "(⁠Φ⁠ω⁠Φ⁠)", "(⁠=⁠^⁠･⁠ω⁠･⁠^⁠=⁠)", "(⁠^⁠=⁠◕⁠ᴥ⁠◕⁠=⁠^⁠)", "ʕ⁠·⁠ᴥ⁠·⁠ʔ", "(⁠`⁠･⁠ω⁠･⁠´⁠)", "(⁠;⁠Φ⁠ω⁠Φ⁠)",
        "¯\\_(ツ)_/¯", "(⁠ಠ⁠_⁠ಠ⁠)", "(¬_¬)", "(⁠•⁠a⁠•⁠)", "ヽ(°□°)ﾉ", "└(°o°)┘",
        "(⁠⊙⁠_⁠⊙⁠)", "(⁠~⁠_⁠~⁠;⁠)", "(⁠╯⁠°⁠□⁠°⁠)⁠╯⁠︵⁠ ⁠┻⁠━⁠┻", "┬─┬ノ( º _ ºノ)", "(⁠ง⁠•⁠_⁠•⁠)⁠ง", "(⁠ノ⁠_⁠<⁠)",
        "(⁠;⁠_⁠;⁠)", "(⁠╥⁠﹏⁠╥⁠)", "(⁠ﾉ⁠◕⁠ヮ⁠◕⁠)⁠ﾉ⁠*⁠:⁠･ﾟ⁠✧", "(⁠-⁠_⁠-⁠)", "(⁠★⁠ω⁠★⁠)", "└(™_™)┐"
    ];

    function switchTab(tab) {
        activeTab = tab;
        const titleEl = popup.querySelector('.smile-picker-title');
        if (tab === 'kaomoji') {
            if (tabSmiles) tabSmiles.classList.remove('active');
            if (tabKaomoji) tabKaomoji.classList.add('active');
            if (titleEl) titleEl.textContent = 'Каомодзи';
            smileGrid.classList.add('hidden');
            kaomojiGrid.classList.remove('hidden');
            if (kaomojiGrid.children.length === 0) {
                renderKaomojiGrid();
            }
        } else {
            if (tabKaomoji) tabKaomoji.classList.remove('active');
            if (tabSmiles) tabSmiles.classList.add('active');
            if (titleEl) titleEl.textContent = 'Смайлики';
            kaomojiGrid.classList.add('hidden');
            smileGrid.classList.remove('hidden');
            if (smileGrid.children.length === 0) {
                renderSmileGrid();
            }
        }
    }

    if (tabSmiles) {
        tabSmiles.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            switchTab('smiles');
        });
    }

    if (tabKaomoji) {
        tabKaomoji.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            switchTab('kaomoji');
        });
    }

    function renderSmileGrid() {
        smileGrid.innerHTML = '';
        const map = window.smileMap || {};
        const path = window.SMILE_PATH || '/res/';

        Object.keys(map).forEach(id => {
            const fileName = map[id];
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'smile-picker-item';
            item.title = fileName;
            item.setAttribute('data-id', id);

            const img = document.createElement('img');
            img.src = (window.getSmileSrc && window.getSmileSrc(id)) || `${path}${fileName}`;
            img.alt = fileName;
            img.loading = 'lazy';

            item.appendChild(img);

            item.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                insertSmile(id);
                closeSmilePicker();
            });

            smileGrid.appendChild(item);
        });
    }

    function renderKaomojiGrid() {
        kaomojiGrid.innerHTML = '';
        kaomojiList.forEach(km => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'kaomoji-picker-item';
            item.title = km;
            item.textContent = km;

            item.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                insertKaomoji(km);
                closeSmilePicker();
            });

            kaomojiGrid.appendChild(item);
        });
    }

    function insertKaomoji(km) {
        if (!input || input.disabled || input.getAttribute('contenteditable') === 'false') return;
        input.focus();

        const sel = window.getSelection();
        let range;

        if (sel && sel.rangeCount > 0 && input.contains(sel.anchorNode)) {
            range = sel.getRangeAt(0);
        } else {
            range = document.createRange();
            range.selectNodeContents(input);
            range.collapse(false);
        }

        const textNode = document.createTextNode(km + ' ');
        range.deleteContents();
        range.insertNode(textNode);

        // Position selection right after the inserted text
        range.setStartAfter(textNode);
        range.setEndAfter(textNode);
        sel.removeAllRanges();
        sel.addRange(range);

        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function insertSmile(id) {
        if (!input || input.disabled || input.getAttribute('contenteditable') === 'false') return;
        input.focus();

        const map = window.smileMap || {};
        const path = window.SMILE_PATH || '/res/';
        const fileName = map[id] || '';

        const sel = window.getSelection();
        let range;

        if (sel && sel.rangeCount > 0 && input.contains(sel.anchorNode)) {
            range = sel.getRangeAt(0);
        } else {
            range = document.createRange();
            range.selectNodeContents(input);
            range.collapse(false);
        }

        const img = document.createElement('img');
        img.src = (window.getSmileSrc && window.getSmileSrc(id)) || `${path}${fileName}`;
        img.className = 'mrim-smile-input';
        img.alt = fileName;
        img.dataset.smileId = id;
        img.dataset.smileAlt = fileName;
        img.draggable = false;

        range.deleteContents();
        range.insertNode(img);

        // Position selection right after the inserted img element
        range.setStartAfter(img);
        range.setEndAfter(img);
        sel.removeAllRanges();
        sel.addRange(range);

        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function openSmilePicker() {
        if (input && input.disabled) return;
        if (activeTab === 'smiles' && smileGrid.children.length === 0) {
            renderSmileGrid();
        } else if (activeTab === 'kaomoji' && kaomojiGrid.children.length === 0) {
            renderKaomojiGrid();
        }
        popup.classList.remove('hidden');
    }

    function closeSmilePicker() {
        popup.classList.add('hidden');
    }

    function toggleSmilePicker(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        if (popup.classList.contains('hidden')) {
            openSmilePicker();
        } else {
            closeSmilePicker();
        }
    }

    btnSmiles.addEventListener('click', toggleSmilePicker);

    if (btnClose) {
        btnClose.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            closeSmilePicker();
        });
    }

    // Close on click outside
    document.addEventListener('click', (e) => {
        if (!popup.classList.contains('hidden')) {
            if (!popup.contains(e.target) && !btnSmiles.contains(e.target)) {
                closeSmilePicker();
            }
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !popup.classList.contains('hidden')) {
            closeSmilePicker();
        }
    });
}

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
        if (el.chatHistory) {
            setTimeout(() => {
                el.chatHistory.scrollTop = el.chatHistory.scrollHeight;
            }, 50);
        }
    } else {
        workspace.classList.add('mobile-view-contacts');
        workspace.classList.remove('mobile-view-chat');
        if (btnContacts) btnContacts.classList.add('active');
        if (btnChat) btnChat.classList.remove('active');
    }
}
