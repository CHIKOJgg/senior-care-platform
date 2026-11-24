/**
 * Senior Care Platform — Main Frontend Application Script (Production v1.0)
 * Role Separation: User (2 functions only) / Admin (Management & Analytics) / Volunteer
 * JWT / Bearer Token Security, Real-Time Polling & Live Verification
 */

// Global State
let currentRole = document.body.getAttribute('data-initial-role') || 'guest';
let currentUser = null;
let currentVolunteerId = 2; // Set from the authenticated volunteer profile
let currentVolunteerCoords = { lat: 53.9168, lon: 27.5862 };
let regionsCache = [];
const APP_CONFIG = window.APP_CONFIG || { mode: 'family', demoAutologin: false };

function homeForRole(roleCode) {
    if (roleCode === 'volunteer') return '/volunteer';
    if (roleCode === 'admin' || roleCode === 'coordinator') return '/admin';
    return '/';
}

// Centralized Vector SVG Icon Library (No Emojis)
const Icons = {
    user: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    volunteer: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    admin: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`,
    check: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    cross: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
    clock: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
    star: `<svg class="ui-icon ui-icon-xs" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
    book: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>`,
    trash: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>`,
    alert: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    volume: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>`,
    shield: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`,
    mapPin: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>`,
    category: function(catId) {
        const id = parseInt(catId);
        switch (id) {
            case 1:
                return `<svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>`;
            case 2:
                return `<svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>`;
            case 3:
                return `<svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`;
            case 4:
                return `<svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>`;
            case 5:
                return `<svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13" cy="4" r="2"/><path d="M14.5 9a3 3 0 0 0-3 3v5l-3 4"/><path d="M11.5 14 7 11"/><path d="m15.5 14 3.5 3.5"/></svg>`;
            default:
                return `<svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
        }
    },
    categoryByName: function(name) {
        const value = String(name || '').toLowerCase();
        const map = {
            'shopping-bag': 1, 'groceries': 1, 'food': 1,
            'pill': 2, 'pharmacy': 2, 'medical': 2,
            'broom': 3, 'household': 3, 'home': 3,
            'phone': 4, 'tech': 4,
            'walking': 5, 'escort': 5,
            'coffee': 6, 'chat': 6
        };
        return this.category(map[value] || 0);
    }
};

// Authentication: the HttpOnly auth_token cookie is the single source of truth.
// Tokens are never stored in localStorage/sessionStorage, so an XSS cannot read
// them. The legacy keys are purged for browsers that still have old tokens.
const LEGACY_TOKEN_KEYS = ['elder_token', 'auth_token'];

function clearTokens() {
    for (const key of LEGACY_TOKEN_KEYS) {
        localStorage.removeItem(key);
        sessionStorage.removeItem(key);
    }
}

// Centralized Authenticated Fetch Wrapper. Same-origin fetch sends the
// HttpOnly cookie automatically; no bearer token is attached from JavaScript.
async function authFetch(url, options = {}) {
    options.headers = options.headers || {};
    const res = await fetch(url, options);
    if (res.status === 401 && !url.startsWith('/api/auth/login')) {
        handleSessionExpired();
    }
    return res;
}

function handleSessionExpired() {
    clearTokens();
    currentUser = null;
    window.location.replace('/login?expired=1');
}

document.addEventListener('DOMContentLoaded', () => {
    // Smart narration first: it reads browser accessibility preferences and
    // prepares the aria-live channel before any UI announcement is made.
    try { SpeechHelper.initSmartNarration(); } catch (e) { console.error(e); }
    // Login must bind first: it is the only critical function on the login page.
    initSessionGate();
    initPasswordRecovery();
    initResetCodeForm();
    initTopBarActions();
    initModals();
    checkSystemHealth();
    try { initUserFunctions(); } catch (e) { console.error(e); }
    try { initAccessibility(); } catch (e) { console.error(e); }
    try { initForms(); } catch (e) { console.error(e); }
    try { initSecurityControls(); } catch (e) { console.error(e); }
    try { loadCategoryCards(); } catch (e) { console.error(e); }
    try { loadRegions(); } catch (e) { console.error(e); }

    if (currentRole === 'guest') {
        return;
    }

    // Initial session bootstrap: the server already rendered only this role's workspace.
    bootstrapSession().then((ok) => {
        if (ok) {
            loadRoleData();
        } else {
            clearTokens();
            window.location.replace('/login');
        }
    });

    // Live updates: new tasks and SOS signals should appear without a manual
    // refresh. Poll the active role every 10 seconds and immediately when the
    // tab becomes visible again.
    setInterval(refreshCurrentRole, 10000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshCurrentRole();
    });
});

function refreshCurrentRole() {
    if (!currentUser) return;
    if (currentRole === 'volunteer') {
        checkVolunteerStatus();
        loadVolunteerRequests();
        loadVolunteerActiveTasks();
        loadVolunteerTrainingAssignments();
    } else if (currentRole === 'admin') {
        loadAdminAnalytics();
        loadCoordinatorSosAlerts();
        loadWatchdogTasks();
    } else if (currentRole === 'user') {
        loadAlerts();
        loadElderlyRequests();
        loadElderlySosState();
    }
}

// =========================================================================
// 1. Session Bootstrap
// =========================================================================
async function bootstrapSession() {
    try {
        // The HttpOnly cookie is the single source of truth for the web UI; the server
        // already rendered exactly this role from the same cookie.
        const res = await fetch('/api/auth/me', { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (data.success && data.user) {
            const serverRole = roleFromUser(data.user);
            if (serverRole !== currentRole) {
                clearTokens();
                return false;
            }
            // Drop any stale bearer token so API calls keep using the cookie identity.
            clearTokens();
            setCurrentUser(data.user);
            return true;
        }
    } catch (e) {
        console.error('Session check failed', e);
    }
    return false;
}

async function loginWithPhone(phone, password, showFeedback = true, remember = false) {
    try {
        const res = await fetch('/api/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, password, remember })
        });
        const data = await res.json();
        if (data.success) {
            // The server already set the HttpOnly cookie with the requested TTL.
            setCurrentUser(data.user);
            if (showFeedback) {
                SpeechHelper.speak(`Вход выполнен: ${data.user.name}`);
            }
            return true;
        }
        return data.error || 'Не удалось войти';
    } catch (e) {
        console.error('Login error', e);
        return 'Нет связи с сервером. Попробуйте ещё раз.';
    }
}

// =========================================================================
// Login gate: single entry point for every role
// =========================================================================
function initSessionGate() {
    const form = document.getElementById('sessionGateForm');
    if (!form) return;

    const toggle = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('gatePassword');
    const phoneEl = document.getElementById('gatePhone');
    if (phoneEl && !phoneEl.value) {
        phoneEl.focus();
    }
    if (toggle && passwordInput) {
        toggle.addEventListener('click', () => {
            const show = passwordInput.type === 'password';
            passwordInput.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Скрыть' : 'Показать';
            toggle.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
            passwordInput.focus();
        });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const phoneInput = document.getElementById('gatePhone');
        const phone = phoneInput.value.trim();
        const password = document.getElementById('gatePassword').value;
        const rememberEl = document.getElementById('gateRemember');
        const remember = rememberEl ? rememberEl.checked : false;
        const errorBox = document.getElementById('gateError');
        const submitBtn = document.getElementById('loginSubmitBtn');

        const showError = (message) => {
            if (errorBox) {
                errorBox.textContent = message;
                errorBox.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Войти';
            }
        };

        if (!phone || !password) {
            showError('Введите номер телефона и пароль.');
            return;
        }
        if (phone.replace(/\D/g, '').length < 9) {
            showError('Проверьте номер телефона: слишком мало цифр.');
            return;
        }

        if (errorBox) errorBox.style.display = 'none';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Вход…';
        }

        const result = await loginWithPhone(phone, password, true, remember);
        if (result === true) {
            form.reset();
            // Full reload so the server renders only this account's role workspace.
            window.location.replace(homeForRole(currentUser ? currentUser.role_code : 'elderly'));
        } else {
            showError(result || 'Не удалось войти. Проверьте телефон и пароль.');
        }
    });
}

// Top bar actions live outside the login gate and must work on every authenticated page.
async function checkSystemHealth() {
    const statusEl = document.getElementById('systemStatusText');
    if (!statusEl) return;
    try {
        const res = await fetch('/api/health', { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        const ok = res.ok && data.status === 'ok';
        statusEl.textContent = ok ? 'СИСТЕМА ОНЛАЙН' : 'СИСТЕМА НЕДОСТУПНА';
        statusEl.style.color = ok ? '' : 'var(--danger-color)';
        statusEl.style.fontWeight = ok ? '' : '800';
    } catch (e) {
        statusEl.textContent = 'НЕТ СВЯЗИ';
        statusEl.style.color = 'var(--danger-color)';
    }
}

function initTopBarActions() {
    const logoutBtn = document.getElementById('btnLogout');
    if (!logoutBtn || logoutBtn.dataset.bound === '1') return;
    logoutBtn.dataset.bound = '1';
    logoutBtn.addEventListener('click', async () => {
        await fetch('/api/auth/logout', { method: 'POST' });
        clearTokens();
        currentUser = null;
        window.location.replace('/login');
    });
}

function roleFromUser(user) {
    if (!user) return 'user';
    if (user.role_code === 'elderly') return 'user';
    if (user.role_code === 'coordinator' || user.role_code === 'admin') return 'admin';
    return user.role_code;
}

function showSessionGate(role, message = '') {
    const gate = document.getElementById('sessionGate');
    if (!gate) return;
    const text = document.getElementById('gateMessage');
    if (text && !message && gate.style.display === 'flex' && text.textContent) {
        return;
    }
    if (text) {
        const fallback = 'Введите номер телефона и пароль, чтобы войти.';
        text.textContent = message || fallback;
    }
    const errorBox = document.getElementById('gateError');
    if (errorBox) errorBox.style.display = 'none';
    gate.style.display = 'flex';
}

function hideSessionGate() {
    const gate = document.getElementById('sessionGate');
    if (gate) gate.style.display = 'none';
}

// Family mode: change password and revoke all devices
function initSecurityControls() {
    const openBtn = document.getElementById('btnChangePassword');
    const modal = document.getElementById('passwordModal');
    if (!openBtn || !modal) return;

    const message = document.getElementById('passwordMessage');
    const showMessage = (text, ok) => {
        if (!message) return;
        message.className = 'alert-banner ' + (ok ? 'alert-banner-success' : 'alert-banner-danger');
        message.textContent = text;
        message.style.display = 'block';
    };
    const closeModal = () => {
        modal.style.display = 'none';
        if (message) message.style.display = 'none';
    };

    openBtn.addEventListener('click', () => {
        if (!currentUser) {
            showSessionGate(currentRole);
            return;
        }
        if (message) message.style.display = 'none';
        modal.style.display = 'flex';
    });

    document.getElementById('closePasswordModalBtn')?.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    const form = document.getElementById('passwordForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const repeat = document.getElementById('newPasswordRepeat').value;

        if (newPassword.length < 8) {
            showMessage('Новый пароль должен содержать не менее 8 символов.', false);
            return;
        }
        if (newPassword !== repeat) {
            showMessage('Пароли не совпадают.', false);
            return;
        }

        const remember = currentUser?.role_code === 'elderly';
        const res = await authFetch('/api/auth/password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: currentPassword, new_password: newPassword, remember })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.success) {
            // The response sets a fresh HttpOnly cookie; nothing to store in JS.
            setCurrentUser(data.user);
            form.reset();
            showMessage('Пароль изменён. Остальные устройства вышли из системы.', true);
        } else {
            showMessage(data.error || 'Не удалось изменить пароль.', false);
        }
    });

    document.getElementById('btnLogoutAll')?.addEventListener('click', async () => {
        const ok = confirm('Выйти на всех устройствах?\n\nТелефон подопечного тоже выйдет из системы, и туда нужно будет войти заново.');
        if (!ok) return;
        await authFetch('/api/auth/logout-all', { method: 'POST' });
        clearTokens();
        currentUser = null;
        window.location.reload();
    });
}

// Family login gate: "Забыли пароль?" with a coordinator-issued one-time code
function initPasswordRecovery() {
    const showBtn = document.getElementById('showResetFormBtn');
    const box = document.getElementById('resetPasswordBox');
    const loginForm = document.getElementById('sessionGateForm');
    if (!showBtn || !box) return;

    const message = document.getElementById('resetPasswordMessage');
    const showMessage = (text, ok) => {
        if (!message) return;
        message.className = 'alert-banner ' + (ok ? 'alert-banner-success' : 'alert-banner-danger');
        message.textContent = text;
        message.style.display = 'block';
    };

    const showReset = () => {
        box.style.display = 'block';
        showBtn.style.display = 'none';
        if (loginForm) loginForm.style.display = 'none';
        const gatePhone = document.getElementById('gatePhone');
        const resetPhone = document.getElementById('resetPhone');
        if (gatePhone && resetPhone && !resetPhone.value) resetPhone.value = gatePhone.value;
        document.getElementById('resetCode')?.focus();
    };

    const hideReset = () => {
        box.style.display = 'none';
        showBtn.style.display = 'block';
        if (loginForm) loginForm.style.display = 'block';
        if (message) message.style.display = 'none';
    };

    showBtn.addEventListener('click', showReset);
    document.getElementById('cancelResetBtn')?.addEventListener('click', hideReset);

    const form = document.getElementById('resetPasswordForm');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const phone = document.getElementById('resetPhone').value.trim();
        const code = document.getElementById('resetCode').value.trim();
        const newPassword = document.getElementById('resetNewPassword').value;
        const repeat = document.getElementById('resetNewPasswordRepeat').value;

        if (!/^\d{6}$/.test(code)) {
            showMessage('Код подтверждения состоит из 6 цифр.', false);
            return;
        }
        if (newPassword.length < 8) {
            showMessage('Новый пароль должен содержать не менее 8 символов.', false);
            return;
        }
        if (newPassword !== repeat) {
            showMessage('Пароли не совпадают.', false);
            return;
        }

        const res = await fetch('/api/auth/reset-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, code, new_password: newPassword })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.success) {
            showMessage('Пароль изменён. Теперь войдите с новым паролем.', true);
            const gatePhone = document.getElementById('gatePhone');
            if (gatePhone) gatePhone.value = phone;
            form.reset();
            setTimeout(() => {
                hideReset();
                document.getElementById('gatePassword')?.focus();
            }, 2500);
        } else {
            showMessage(data.error || 'Не удалось изменить пароль.', false);
        }
    });
}

// Coordinator console: issue a one-time password reset code
function initResetCodeForm() {
    const form = document.getElementById('resetCodeForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const phone = document.getElementById('resetCodePhone').value.trim();
        const errorBox = document.getElementById('resetCodeError');
        const resultBox = document.getElementById('resetCodeResult');

        const res = await authFetch('/api/coordinator/reset-code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.success) {
            errorBox.style.display = 'none';
            resultBox.style.display = 'block';
            document.getElementById('resetCodeValue').textContent = data.code;
            document.getElementById('resetCodeUser').textContent = data.user?.name || '';
            document.getElementById('resetCodeTtl').textContent = `Действует ${Math.round((data.expires_in || 1800) / 60)} минут. Используется один раз.`;
            form.reset();
        } else {
            resultBox.style.display = 'none';
            errorBox.textContent = data.error || 'Не удалось создать код.';
            errorBox.style.display = 'block';
        }
    });
}

function setCurrentUser(user) {
    currentUser = user;
    const display = document.getElementById('currentUserDisplay');
    if (display) {
        display.innerHTML = `${Icons.user} <span>${escapeHtml(user.name)} (${escapeHtml(user.role_name || user.role_code)})</span>`;
    }
    if (user.role_code === 'volunteer') {
        currentVolunteerId = user.id;
        const profile = user.profile || {};
        if (profile.latitude != null && profile.longitude != null) {
            currentVolunteerCoords = { lat: parseFloat(profile.latitude), lon: parseFloat(profile.longitude) };
        }
        const volName = document.getElementById('volNameDisplay');
        if (volName) volName.innerText = `${user.name} (ID #${user.id})`;
    }
}

// =========================================================================
// 2. Role workspace: load data for the single server-rendered role only
// =========================================================================
function loadRoleData() {
    if (currentRole === 'user') {
        loadElderlyRequests();
        loadAlerts();
        loadElderlySosState();
        loadEducation();
    } else if (currentRole === 'admin') {
        loadAdminAnalytics();
        loadAllVolunteers();
        loadAdminRequests();
        loadAdminAlerts();
        loadCoordinatorSosAlerts();
        loadWatchdogTasks();
    } else if (currentRole === 'volunteer') {
        checkVolunteerStatus();
        loadVolunteerRequests();
        loadVolunteerActiveTasks();
        loadVolunteerTrainingAssignments();
        loadLeaderboard();
        loadVolunteerKnowledge();
    }
}

// =========================================================================
// 3. User View: Strictly 2 Functions
// =========================================================================
// Shared modal behaviour: Escape closes the topmost dialog and returns focus
// to the element that opened it.
function initModals() {
    let lastFocused = null;
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const openModal = Array.from(document.querySelectorAll('.modal-backdrop'))
            .find(m => m.style.display && m.style.display !== 'none');
        if (!openModal) return;
        openModal.style.display = 'none';
        if (openModal.id === 'geo-complete-modal') currentCompletingRequestId = null;
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    });
    document.addEventListener('focusin', (event) => {
        const modal = event.target.closest ? event.target.closest('.modal-backdrop') : null;
        if (modal) lastFocused = document.activeElement;
    });
}

function initUserFunctions() {
    const tabs = [
        { key: 'request', button: 'btnFuncRequest', pane: 'func-request', speech: 'Открыт раздел: Заказ помощи' },
        { key: 'safety', button: 'btnFuncSafety', pane: 'func-safety', speech: 'Открыт раздел: Проверка на мошенничество' },
        { key: 'education', button: 'btnFuncEducation', pane: 'func-education', speech: 'Открыт раздел: Школа безопасности и обучающие материалы' }
    ].map(tab => ({
        ...tab,
        buttonEl: document.getElementById(tab.button),
        paneEl: document.getElementById(tab.pane)
    })).filter(tab => tab.buttonEl && tab.paneEl);

    if (tabs.length > 0) {
        tabs.forEach((tab, index) => {
            tab.buttonEl.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
            tab.buttonEl.addEventListener('click', () => {
                tabs.forEach(other => {
                    const active = other === tab;
                    other.buttonEl.classList.toggle('active', active);
                    other.buttonEl.setAttribute('aria-pressed', active ? 'true' : 'false');
                    other.paneEl.style.display = active ? 'block' : 'none';
                });
                SpeechHelper.speak(tab.speech);
            });
        });
    }

    // Category selection in request form with dynamic helpful placeholders
    const catPlaceholders = {
        '1': 'Например: купите, пожалуйста, молоко 2.5% 1 пакет, буханку чёрного хлеба и десяток яиц...',
        '2': 'Например: купить в аптеке корвалол, аспирин и пластырь по рецепту врача...',
        '3': 'Например: помочь закрутить кран на кухне или вынести тяжёлый мусор...',
        '4': 'Например: помочь оплатить квитанцию за квартиру или настроить звук на телефоне...',
        '5': 'Например: сопроводить в поликлинику или выйти подышать воздухом в сквер...',
        '6': 'Например: попить вместе чай, почитать свежую газету и пообщаться...'
    };

    // Category cards: delegated handler so categories added via the admin panel work too
    const catGrid = document.getElementById('categoryGrid');
    if (catGrid && catGrid.dataset.bound !== '1') {
        catGrid.dataset.bound = '1';
        const selectCard = (card) => {
            catGrid.querySelectorAll('.category-card').forEach(c => {
                c.classList.remove('selected');
                c.setAttribute('aria-checked', 'false');
            });
            card.classList.add('selected');
            card.setAttribute('aria-checked', 'true');
            const catId = card.getAttribute('data-id');
            const selInput = document.getElementById('selectedCategory');
            if (selInput) selInput.value = catId;
            const descInput = document.getElementById('requestDescription');
            if (descInput && catPlaceholders[catId]) {
                descInput.placeholder = catPlaceholders[catId];
            }
            const title = card.querySelector('.category-title')?.innerText || '';
            SpeechHelper.speak('Выбрана категория: ' + title);
        };
        catGrid.addEventListener('click', (ev) => {
            const card = ev.target.closest('.category-card');
            if (card) selectCard(card);
        });
        catGrid.addEventListener('keydown', (ev) => {
            if (ev.key !== 'Enter' && ev.key !== ' ') return;
            const card = ev.target.closest('.category-card');
            if (card) {
                ev.preventDefault();
                selectCard(card);
            }
        });
    }

    enhanceCategoryCards();

    // Voice dictation for request
    const micBtn = document.getElementById('micBtn');
    const requestDesc = document.getElementById('requestDescription');
    if (micBtn && requestDesc) {
        micBtn.addEventListener('click', () => {
            SpeechHelper.toggleListening(micBtn, (transcript) => {
                requestDesc.value = (requestDesc.value ? requestDesc.value + ' ' : '') + transcript;
                SpeechHelper.speak('Записано: ' + transcript);
            });
        });
    }

    // Voice dictation for fraud scanner
    const micFraudBtn = document.getElementById('micFraudBtn');
    const fraudInput = document.getElementById('fraudCheckInput');
    if (micFraudBtn && fraudInput) {
        micFraudBtn.addEventListener('click', () => {
            SpeechHelper.toggleListening(micFraudBtn, (transcript) => {
                fraudInput.value = (fraudInput.value ? fraudInput.value + ' ' : '') + transcript;
                SpeechHelper.speak('Записано: ' + transcript);
            });
        });
    }
}

function enhanceCategoryCards() {
    const grid = document.getElementById('categoryGrid');
    if (!grid) return;
    grid.setAttribute('role', 'radiogroup');
    grid.setAttribute('aria-label', 'Категория помощи');
    grid.querySelectorAll('.category-card').forEach(card => {
        card.setAttribute('role', 'radio');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-checked', card.classList.contains('selected') ? 'true' : 'false');
    });
}

// Reset the category picker after a form reset so the visible selection and
// the hidden category_id always agree.
function resetCategorySelection() {
    const grid = document.getElementById('categoryGrid');
    const selected = document.getElementById('selectedCategory');
    const desc = document.getElementById('requestDescription');
    if (!grid) return;
    const cards = grid.querySelectorAll('.category-card');
    cards.forEach(c => c.classList.remove('selected'));
    const first = cards[0];
    if (first) {
        first.classList.add('selected');
        cards.forEach(c => c.setAttribute('aria-checked', c === first ? 'true' : 'false'));
        if (selected) selected.value = first.getAttribute('data-id') || '1';
        if (desc) desc.placeholder = 'Например: купите, пожалуйста, молоко и хлеб...';
    }
    enhanceCategoryCards();
}
window.resetCategorySelection = resetCategorySelection;

// Load request types from the API so admin-added categories appear for seniors
async function loadCategoryCards() {
    const grid = document.getElementById('categoryGrid');
    if (!grid) return;
    try {
        const res = await fetch('/api/categories', { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        const list = Array.isArray(json.data) ? json.data : [];
        if (list.length === 0) return;
        grid.innerHTML = list.map((c, index) => `
            <div class="category-card${index === 0 ? ' selected' : ''}" data-id="${Number(c.id)}">
                <span class="category-icon">${Icons.categoryByName(c.icon)}</span>
                <div class="category-title">${escapeHtml(c.title)}</div>
            </div>
        `).join('');
        const selected = document.getElementById('selectedCategory');
        if (selected && list[0]) selected.value = String(list[0].id);
        enhanceCategoryCards();
    } catch (e) {
        console.error('Failed to load request categories', e);
    }
}

// Load the country-wide region catalogue (all regions of Belarus)
async function loadRegions() {
    try {
        const res = await fetch('/api/regions', { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        regionsCache = Array.isArray(json.data) ? json.data : [];

        const fillRequired = (id, preferred) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.innerHTML = regionsCache
                .map((r) => `<option value="${r.code}" data-lat="${r.center_lat}" data-lon="${r.center_lon}">${escapeHtml(r.name)}</option>`)
                .join('');
            if (preferred && regionsCache.some((r) => r.code === preferred)) {
                el.value = preferred;
            }
        };
        const fillFilter = (id, placeholder) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.innerHTML = `<option value="">${placeholder}</option>` + regionsCache
                .map((r) => `<option value="${r.code}">${escapeHtml(r.name)}</option>`)
                .join('');
        };

        const profileRegion = currentUser && currentUser.profile ? currentUser.profile.region_code : '';
        const existingRegion = document.getElementById('requestRegion')?.value || '';
        fillRequired('requestRegion', existingRegion || profileRegion || 'MINSK_CITY');
        fillFilter('adminRegionFilter', 'Вся Беларусь');
        fillFilter('volunteerRegionFilter', 'Все регионы');
    } catch (e) {
        console.error('Failed to load regions', e);
    }
}

// Load current user's active requests (with secret codes)
async function loadElderlyRequests() {
    const container = document.getElementById('elderlyRequestsList');
    if (!container) return;

    try {
        const elderlyId = (currentUser && currentUser.role_code === 'elderly') ? currentUser.id : 1;
        const res = await authFetch(`/api/requests/my?elderly_id=${elderlyId}`);
        const json = await res.json();
        if (json.data && json.data.length > 0) {
            container.innerHTML = json.data.map(req => {
                let statusBadge = '';
                if (req.status === 'open') {
                    statusBadge = `<span class="badge badge-open">${Icons.clock} Ищем волонтёра</span>`;
                } else if (req.status === 'in_progress') {
                    statusBadge = `<span class="badge badge-warning">${Icons.volunteer} Волонтёр в пути (${escapeHtml(req.volunteer_name || 'Назначен')})</span>`;
                } else if (req.status === 'completed') {
                    statusBadge = `<span class="badge badge-success">${Icons.check} Выполнено</span>`;
                } else {
                    statusBadge = `<span class="badge badge-cancelled">${Icons.cross} Отменено</span>`;
                }

                let ratingBlock = '';
                if (req.status === 'completed' && req.volunteer_id) {
                    if (!req.rating_score) {
                        ratingBlock = `
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color);" role="group" aria-label="Оцените волонтёра">
                                <strong>Оцените волонтёра от 1 до 5:</strong>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px;">
                                    ${[1, 2, 3, 4, 5].map(n => `<button type="button" class="btn btn-outline btn-sm" onclick="rateVolunteer(${req.id}, ${n})" aria-label="Оценка ${n} из 5">${n}</button>`).join('')}
                                </div>
                            </div>
                        `;
                    } else {
                        ratingBlock = `
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color); color: var(--text-muted);">
                                Ваша оценка волонтёру: <strong>${escapeHtml(String(req.rating_score))} / 5</strong>
                            </div>
                        `;
                    }
                }

                return `
                    <div class="card card-accent-primary" style="margin-bottom: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="display: inline-block; vertical-align: middle; margin-right: 6px;">${Icons.category(req.category_id)}</span>
                                <strong style="font-size: 1.1em;">${escapeHtml(req.title)}</strong>
                                ${statusBadge}
                                <p style="margin: 6px 0; color: var(--text-muted);">${escapeHtml(req.description || '')}</p>
                                <p style="font-size: 0.9em; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                                    ${Icons.mapPin} <span>${escapeHtml(req.address)} • Дата: ${escapeHtml(req.created_at)}</span>
                                </p>
                            </div>
                            <div class="secret-code-box">
                                <div style="font-size: 0.8em; color: var(--accent-color); font-weight: bold;">Секретное проверочное слово:</div>
                                <div style="font-size: 1.3em; font-weight: 900; color: var(--accent-color); letter-spacing: 1px;">«${escapeHtml(req.secret_code)}»</div>
                            </div>
                        </div>
                        ${ratingBlock}
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = '<p class="text-muted">У вас пока нет активных заявок. Выберите категорию выше и нажмите «Отправить заявку».</p>';
        }
    } catch (e) {
        console.error(e);
        container.innerHTML = '<p style="color: var(--danger-color);">Ошибка загрузки ваших заявок.</p>';
    }
}

async function rateVolunteer(requestId, rating) {
    if (!confirm(`Поставить волонтёру оценку ${rating} из 5?`)) return;
    try {
        const res = await authFetch(`/api/requests/${requestId}/rate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rating })
        });
        const data = await res.json();
        if (res.ok && data.success) {
            let message = `${data.message || 'Спасибо за оценку!'}\nСредний рейтинг волонтёра: ${data.volunteer_rating || '—'}.`;
            if (data.volunteer_is_restricted) {
                message += '\nНовые задания волонтёра ограничены до решения координатора.';
            }
            alert(message);
            loadElderlyRequests();
        } else {
            alert(data.error || 'Не удалось сохранить оценку');
        }
    } catch (e) {
        console.error('Rating error', e);
        alert('Ошибка сети при сохранении оценки');
    }
}
window.rateVolunteer = rateVolunteer;

// =========================================================================
// 4. Admin View: Management & Analytics
// =========================================================================
function initAdminSubnav() {
    const adminBtns = document.querySelectorAll('.admin-subnav-btn');
    adminBtns.forEach(btn => {
        btn.setAttribute('aria-pressed', btn.classList.contains('active') ? 'true' : 'false');
        btn.addEventListener('click', () => {
            adminBtns.forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-pressed', 'false');
            });
            document.querySelectorAll('.admin-tab-pane').forEach(p => p.style.display = 'none');

            btn.classList.add('active');
            btn.setAttribute('aria-pressed', 'true');
            const targetId = btn.getAttribute('data-admin-tab');
            const targetPane = document.getElementById(targetId);
            if (targetPane) targetPane.style.display = 'block';

            if (targetId === 'admin-analytics') loadAdminAnalytics();
            if (targetId === 'admin-volunteers') loadAllVolunteers();
            if (targetId === 'admin-requests') loadAdminRequests();
            if (targetId === 'admin-alerts') loadAdminAlerts();
            if (targetId === 'admin-watchdog') loadWatchdogTasks();
            if (targetId === 'admin-access') loadCoordinatorRegions();
            if (targetId === 'admin-content') loadAdminContent();
        });
    });
}

document.addEventListener('DOMContentLoaded', initAdminSubnav);

async function loadAdminAnalytics() {
    try {
        const res = await authFetch('/api/coordinator/stats');
        const json = await res.json();
        if (json.stats) {
            const s = json.stats;
            document.getElementById('statTotalRequests').innerText = s.total_requests;
            document.getElementById('statActiveRequests').innerText = s.active_requests;
            document.getElementById('statCompletedRequests').innerText = s.completed_requests;
            document.getElementById('statVerifiedVolunteers').innerText = `${s.verified_volunteers} / ${s.total_volunteers}`;

            // Update pending badge
            const badge = document.getElementById('pendingCountBadge');
            if (badge) {
                badge.innerText = s.pending_volunteers;
                badge.style.display = s.pending_volunteers > 0 ? 'inline-block' : 'none';
            }

            // Render category progress bars
            const barsContainer = document.getElementById('categoryAnalyticsBars');
            if (barsContainer && s.category_distribution) {
                barsContainer.innerHTML = s.category_distribution.map(c => {
                    const maxCount = Math.max(...s.category_distribution.map(x => Number(x.count) || 0), 1);
                    const percent = Math.round(((Number(c.count) || 0) / maxCount) * 100);
                    return `
                        <div class="category-progress-item">
                            <div class="category-progress-label">
                                <span style="display: inline-flex; align-items: center; gap: 6px;">${Icons.category(c.id)} ${escapeHtml(c.title)}</span>
                                <strong>${(Number(c.count) || 0)} заявок • ${percent}%</strong>
                            </div>
                            <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}" aria-label="${escapeHtml(c.title)}: ${percent}%">
                                <div class="progress-bar-fill" style="width: ${percent}%;"></div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        // Load leaderboard into admin
        const adminLb = document.getElementById('adminLeaderboardList');
        if (adminLb) {
            const lbRes = await authFetch('/api/volunteers/leaderboard');
            const lbJson = await lbRes.json();
            if (lbJson.data && lbJson.data.length > 0) {
                adminLb.innerHTML = lbJson.data.map((v, i) => `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9;">
                        <div>
                            <strong>#${i + 1} ${escapeHtml(v.name)}</strong>
                            <span class="badge badge-success" style="margin-left: 6px; display: inline-flex; align-items: center; gap: 4px;">${Icons.star} ${v.rating}</span>
                        </div>
                        <div style="font-size: 0.9em; color: #475569;">
                            <strong>${v.points}</strong> баллов (${v.completed_tasks_count} визитов)
                        </div>
                    </div>
                `).join('');
            } else if (adminLb) {
                adminLb.innerHTML = '<p class="text-muted">Пока нет верифицированных волонтёров с баллами.</p>';
            }
        }
    } catch (e) {
        console.error('Error loading admin analytics', e);
        ['statTotalRequests', 'statActiveRequests', 'statCompletedRequests', 'statVerifiedVolunteers'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerText = '—';
        });
        const bars = document.getElementById('categoryAnalyticsBars');
        if (bars) bars.innerHTML = '<p style="color: var(--danger-color);">Не удалось загрузить аналитику. Обновите страницу.</p>';
    }
}

// Volunteer Verification Queue
let volunteerFilterState = { filter: 'all', minRating: null };

function applyRatingFilter() {
    const input = document.getElementById('volunteerMinRating');
    const raw = input ? input.value.trim() : '';
    const value = raw === '' ? null : Number(raw);
    if (value !== null && (Number.isNaN(value) || value < 0 || value > 5)) {
        alert('Рейтинг должен быть числом от 0 до 5');
        return;
    }
    loadAllVolunteers(volunteerFilterState.filter || 'all', value);
}

function resetRatingFilter() {
    const input = document.getElementById('volunteerMinRating');
    if (input) input.value = '';
    loadAllVolunteers('all', null);
}

async function loadAllVolunteers(filter = 'all', minRating = null) {
    const tbody = document.getElementById('volunteersTableBody');
    if (!tbody) return;
    volunteerFilterState = { filter, minRating };

    const hint = document.getElementById('ratingFilterHint');
    if (hint) {
        hint.textContent = (minRating === null || minRating === '')
            ? 'Показаны все волонтёры'
            : `Показаны волонтёры с рейтингом не ниже ${minRating}`;
    }

    try {
        const query = (minRating === null || minRating === '')
            ? ''
            : `?min_rating=${encodeURIComponent(minRating)}`;
        const res = await authFetch('/api/coordinator/volunteers' + query);
        const json = await res.json();
        if (json.data) {
            let list = json.data;
            if (filter === 'pending') {
                list = list.filter(v => parseInt(v.is_verified) === 0);
            } else if (filter === 'verified') {
                list = list.filter(v => parseInt(v.is_verified) === 1);
            }

            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 20px;">Нет волонтёров в данной категории</td></tr>`;
                return;
            }

            tbody.innerHTML = list.map(v => {
                const status = parseInt(v.is_verified);
                const verificationStatus = String(v.verification_status || '').toUpperCase();
                const rating = parseFloat(v.rating || 0);
                const isRestricted = parseInt(v.is_restricted || 0) === 1 || rating < 3.0;
                const trainingAssigned = parseInt(v.training_assigned || 0);
                const trainingCompleted = parseInt(v.training_completed || 0);
                let badge = '';
                let actions = '';

                if (status === 1) {
                    badge = `<span class="badge badge-verified" style="display: inline-flex; align-items: center; gap: 4px;">${Icons.check} Верифицирован</span>`;
                    actions = `<button class="btn btn-outline btn-xs" style="color: var(--danger-color);" onclick="rejectVolunteer(${v.user_id})">${Icons.cross} Отозвать</button>`;
                } else if (verificationStatus === 'REHABILITATION') {
                    badge = `<span class="badge badge-warning" style="display: inline-flex; align-items: center; gap: 4px;">${Icons.alert} На реабилитации</span>`;
                    actions = `<button class="btn btn-success btn-xs" onclick="verifyVolunteer(${v.user_id})">${Icons.check} Завершить реабилитацию</button>`;
                } else if (status === 0) {
                    badge = `<span class="badge badge-pending" style="display: inline-flex; align-items: center; gap: 4px;">${Icons.clock} Ожидает проверки</span>`;
                    actions = `
                        <div style="display: flex; gap: 6px;">
                            <button class="btn btn-success btn-xs" onclick="verifyVolunteer(${v.user_id})">${Icons.check} Одобрить</button>
                            <button class="btn btn-danger btn-xs" onclick="rejectVolunteer(${v.user_id})">${Icons.cross} Отклонить</button>
                        </div>
                    `;
                } else {
                    badge = `<span class="badge badge-rejected" style="display: inline-flex; align-items: center; gap: 4px;">${Icons.cross} Отклонён</span>`;
                    actions = `<button class="btn btn-success btn-xs" onclick="verifyVolunteer(${v.user_id})">${Icons.check} Верифицировать</button>`;
                }

                // Low rating -> offer the rehabilitation track (training + re-verification)
                if (rating < 3.5) {
                    actions += `
                        <button class="btn btn-danger btn-xs" style="margin-left: 6px;" onclick="rehabilitateVolunteer(${v.user_id})">
                            ${Icons.alert} Реабилитация
                        </button>
                    `;
                }
                if (isRestricted) {
                    actions += `
                        <button class="btn btn-warning btn-xs" style="margin-left: 6px;" onclick="unrestrictVolunteer(${v.user_id})">
                            ${Icons.check} Снять ограничения
                        </button>
                    `;
                }

                const docStatus = v.masked_passport
                    ? `<span class="badge badge-success" style="font-size: 0.8em;">Паспорт: ${escapeHtml(v.masked_passport)}</span>`
                    : (v.id_provider
                        ? `<span class="badge badge-open" style="font-size: 0.8em;">МСИ РБ: Сверено</span>`
                        : `<span class="badge badge-pending" style="font-size: 0.8em;">Документы не загружены</span>`);
                const policeStatus = (v.police_clearance_status === 'CLEAN')
                    ? `<span class="badge badge-success" style="font-size: 0.8em;">МВД: Проверено</span>`
                    : `<span class="badge badge-warning" style="font-size: 0.8em;">МВД: Ожидает справку</span>`;

                return `
                    <tr>
                        <td><strong>#${v.user_id}</strong></td>
                        <td>
                            <strong>${escapeHtml(v.name)}</strong>
                            <div style="margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px;">${docStatus} ${policeStatus}</div>
                        </td>
                        <td>${escapeHtml(v.phone)}</td>
                        <td>${escapeHtml(v.skills || 'Базовые')}</td>
                        <td><span style="display: inline-flex; align-items: center; gap: 4px;">${Icons.star} ${v.rating}</span> (${v.completed_tasks_count} заданий)</td>
                        <td>
                            ${badge}
                            ${isRestricted ? `<div style="margin-top: 6px;"><span class="badge badge-rejected">${Icons.alert} Новые задания ограничены</span></div>` : ''}
                            ${trainingAssigned > 0 ? `<div style="margin-top: 6px;"><button class="btn btn-outline btn-xs" onclick="showVolunteerTraining(${v.user_id})">${Icons.book} Обучение ${trainingCompleted}/${trainingAssigned}</button></div>` : ''}
                        </td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        }
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="7" style="color: red; text-align: center;">Ошибка загрузки списка волонтёров</td></tr>`;
    }
}

async function verifyVolunteer(id) {
    try {
        const res = await authFetch(`/api/coordinator/verify-volunteer/${id}`, { method: 'POST' });
        const json = await res.json();
        if (json.success) {
            alert('Волонтёр успешно верифицирован! Доступ к заявкам открыт.');
            loadAllVolunteers();
            loadAdminAnalytics();
        } else {
            alert(json.error || 'Ошибка при верификации');
        }
    } catch (e) {
        alert('Сбой сети при верификации');
    }
}

async function rejectVolunteer(id) {
    if (!confirm('Вы уверены, что хотите отклонить/заблокировать данного волонтёра?')) return;
    try {
        const res = await authFetch(`/api/coordinator/reject-volunteer/${id}`, { method: 'POST' });
        const json = await res.json();
        if (json.success) {
            alert('Статус волонтёра изменён на «Отклонён».');
            loadAllVolunteers();
            loadAdminAnalytics();
        } else {
            alert(json.error || 'Ошибка');
        }
    } catch (e) {
        alert('Сбой сети');
    }
}

async function showVolunteerTraining(id) {
    try {
        const res = await authFetch(`/api/coordinator/volunteers/${id}/training`);
        const json = await res.json();
        if (!res.ok) {
            alert(json.error || 'Не удалось загрузить назначенные материалы');
            return;
        }
        const items = Array.isArray(json.data) ? json.data : [];
        if (items.length === 0) {
            alert('Волонтёру ещё не назначены обучающие материалы.');
            return;
        }
        const lines = items.map(item => `${item.completed_at ? '[изучено]' : '[назначено]'} ${item.title}`).join('\n');
        alert(`Назначенные материалы (${items.length}):\n\n${lines}`);
    } catch (e) {
        console.error('Error loading volunteer training', e);
        alert('Ошибка сети при загрузке материалов.');
    }
}
window.showVolunteerTraining = showVolunteerTraining;

async function unrestrictVolunteer(id) {
    if (!confirm('Снять ограничение на получение новых заданий для этого волонтёра?')) return;
    try {
        const res = await authFetch(`/api/coordinator/volunteers/${id}/unrestrict`, { method: 'POST' });
        const json = await res.json();
        if (json.success) {
            alert(json.message || 'Ограничения сняты.');
            loadAllVolunteers(volunteerFilterState.filter || 'all', volunteerFilterState.minRating);
            loadAdminAnalytics();
        } else {
            alert(json.error || 'Не удалось снять ограничения');
        }
    } catch (e) {
        console.error('Unrestrict error', e);
        alert('Ошибка сети при снятии ограничений');
    }
}
window.unrestrictVolunteer = unrestrictVolunteer;

// Rehabilitation track for volunteers with a low rating
async function rehabilitateVolunteer(id) {
    if (!confirm('Направить волонтёра на реабилитацию? При низком рейтинге будет назначен набор обучающих материалов, рейтинг сброшен на 4.0, а доступ к заявкам приостановлен до повторной проверки.')) return;
    try {
        const res = await authFetch(`/api/coordinator/volunteers/${id}/rehabilitate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const json = await res.json();
        if (json.success) {
            const materials = Array.isArray(json.training_materials) ? json.training_materials : [];
            if (json.needs_rehabilitation) {
                const titles = materials.map(material => `• ${material.title || ('материал #' + material.material_id)}`).join('\n');
                alert(json.message + `\n\nНазначено материалов: ${materials.length}.` + (titles ? `\n${titles}` : ''));
            } else {
                alert(json.message);
            }
            loadAllVolunteers(volunteerFilterState.filter || 'all', volunteerFilterState.minRating);
            loadAdminAnalytics();
        } else {
            alert(json.error || 'Ошибка реабилитации');
        }
    } catch (e) {
        alert('Сбой сети при запуске реабилитации');
    }
}

// =========================================================================
// 4b. Admin: request types (categories) and educational materials
// =========================================================================
let adminCategoriesCache = [];

function showFormMessage(elementId, text, ok) {
    const el = document.getElementById(elementId);
    if (!el) return;
    el.textContent = text;
    el.className = 'alert-banner ' + (ok ? 'alert-banner-success' : 'alert-banner-danger');
    el.style.display = 'block';
}

async function loadCoordinatorRegions() {
    const container = document.getElementById('coordinatorRegionsList');
    if (!container) return;

    try {
        const [coordinatorsRes, regionsRes] = await Promise.all([
            authFetch('/api/admin/coordinators'),
            fetch('/api/regions')
        ]);
        const coordinators = await coordinatorsRes.json();
        const regions = await regionsRes.json();
        const list = Array.isArray(coordinators.data) ? coordinators.data : [];
        const regionList = Array.isArray(regions.data) ? regions.data : [];

        if (!coordinatorsRes.ok) {
            container.innerHTML = '<p style="color: var(--danger-color);">Не удалось загрузить координаторов.</p>';
            return;
        }
        if (list.length === 0) {
            container.innerHTML = '<p style="color: var(--text-muted);">Координаторы не найдены.</p>';
            return;
        }

        container.innerHTML = list.map(coordinator => {
            const options = regionList.map(region => {
                const selected = String(region.code).toUpperCase() === String(coordinator.region_code || '').toUpperCase();
                return `<option value="${escapeHtml(region.code)}" ${selected ? 'selected' : ''}>${escapeHtml(region.name)}</option>`;
            }).join('');
            return `
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 0; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <strong>${escapeHtml(coordinator.name)}</strong>
                        <div style="color: var(--text-muted); font-size: 0.9em;">${escapeHtml(coordinator.phone || '')}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <select id="coordRegionSelect-${coordinator.id}" class="form-control" style="min-width: 220px; min-height: 38px;" aria-label="Регион координатора ${escapeHtml(coordinator.name)}">
                            ${options}
                        </select>
                        <button type="button" class="btn btn-primary btn-sm" onclick="saveCoordinatorRegion(${coordinator.id})">Сохранить</button>
                    </div>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('Error loading coordinator regions', e);
        container.innerHTML = '<p style="color: var(--danger-color);">Ошибка сети при загрузке координаторов.</p>';
    }
}
window.loadCoordinatorRegions = loadCoordinatorRegions;

async function saveCoordinatorRegion(userId) {
    const select = document.getElementById(`coordRegionSelect-${userId}`);
    if (!select) return;
    try {
        const res = await authFetch(`/api/admin/users/${userId}/region`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ region_code: select.value })
        });
        const json = await res.json();
        if (res.ok && json.success) {
            alert(`Регион координатора обновлён: ${json.region_name}.`);
            loadCoordinatorRegions();
        } else {
            alert(json.error || 'Не удалось обновить регион');
        }
    } catch (e) {
        console.error('Error saving coordinator region', e);
        alert('Ошибка сети при сохранении региона');
    }
}
window.saveCoordinatorRegion = saveCoordinatorRegion;

function loadAdminContent() {
    loadAdminCategories();
    loadAdminEducation();
}

async function loadAdminCategories() {
    const tbody = document.getElementById('categoriesTableBody');
    if (!tbody) return;
    try {
        const res = await authFetch('/api/categories');
        const json = await res.json();
        adminCategoriesCache = Array.isArray(json.data) ? json.data : [];
        if (adminCategoriesCache.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 18px;">Видов заявок пока нет</td></tr>`;
            return;
        }
        tbody.innerHTML = adminCategoriesCache.map(c => `
            <tr>
                <td>#${c.id}</td>
                <td><code>${escapeHtml(c.code)}</code></td>
                <td><strong>${escapeHtml(c.title)}</strong>
                    <div style="font-size: 0.85em; color: var(--text-muted);">${escapeHtml(c.description || '')}</div>
                </td>
                <td>${Number(c.requests_count || 0)}</td>
                <td>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button class="btn btn-outline btn-xs" onclick="editCategory(${c.id})">Изменить</button>
                        <button class="btn btn-danger btn-xs" onclick="deleteCategory(${c.id})">Удалить</button>
                    </div>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="5" style="color: red; text-align: center;">Ошибка загрузки видов заявок</td></tr>`;
    }
}

function resetCategoryForm() {
    const form = document.getElementById('categoryForm');
    if (form) form.reset();
    const idInput = document.getElementById('catId');
    if (idInput) idInput.value = '';
    const icon = document.getElementById('catIcon');
    if (icon) icon.value = 'help-circle';
    const btn = document.getElementById('catSubmitBtn');
    if (btn) btn.textContent = 'Добавить вид заявки';
    const msg = document.getElementById('categoryFormMessage');
    if (msg) msg.style.display = 'none';
}

function editCategory(id) {
    const cat = adminCategoriesCache.find(c => Number(c.id) === Number(id));
    if (!cat) return;
    document.getElementById('catId').value = cat.id;
    document.getElementById('catCode').value = cat.code || '';
    document.getElementById('catTitle').value = cat.title || '';
    document.getElementById('catIcon').value = cat.icon || 'help-circle';
    document.getElementById('catDescription').value = cat.description || '';
    const btn = document.getElementById('catSubmitBtn');
    if (btn) btn.textContent = 'Сохранить изменения';
}

async function submitCategoryForm(event) {
    if (event) event.preventDefault();
    const id = document.getElementById('catId').value;
    const payload = {
        code: document.getElementById('catCode').value.trim(),
        title: document.getElementById('catTitle').value.trim(),
        icon: document.getElementById('catIcon').value.trim() || 'help-circle',
        description: document.getElementById('catDescription').value.trim()
    };
    if (!payload.code || !payload.title) {
        showFormMessage('categoryFormMessage', 'Укажите код и название вида заявки', false);
        return false;
    }
    try {
        const url = id ? `/api/coordinator/categories/${id}` : '/api/coordinator/categories';
        const method = id ? 'PUT' : 'POST';
        const res = await authFetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (res.ok && json.success) {
            resetCategoryForm();
            showFormMessage('categoryFormMessage', id ? 'Вид заявки обновлён' : 'Вид заявки добавлен', true);
            loadAdminCategories();
        } else {
            showFormMessage('categoryFormMessage', json.error || 'Не удалось сохранить вид заявки', false);
        }
    } catch (e) {
        showFormMessage('categoryFormMessage', 'Сбой сети при сохранении', false);
    }
    return false;
}

async function deleteCategory(id) {
    if (!confirm('Удалить этот вид заявки? Если по нему уже есть заявки, удаление будет заблокировано.')) return;
    try {
        const res = await authFetch(`/api/coordinator/categories/${id}`, { method: 'DELETE' });
        const json = await res.json();
        if (res.ok && json.success) {
            showFormMessage('categoryFormMessage', 'Вид заявки удалён', true);
            loadAdminCategories();
        } else {
            showFormMessage('categoryFormMessage', json.error || 'Не удалось удалить вид заявки', false);
        }
    } catch (e) {
        showFormMessage('categoryFormMessage', 'Сбой сети при удалении', false);
    }
}

let adminEducationCache = [];

async function loadAdminEducation() {
    const tbody = document.getElementById('adminEducationTableBody');
    if (!tbody) return;
    try {
        const res = await authFetch('/api/education');
        const json = await res.json();
        const list = Array.isArray(json.data) ? json.data : [];
        adminEducationCache = list;
        if (list.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 18px;">Материалов пока нет</td></tr>`;
            return;
        }
        tbody.innerHTML = list.map(m => `
            <tr>
                <td>#${m.id}</td>
                <td><strong>${escapeHtml(m.title)}</strong></td>
                <td>${escapeHtml(m.type || 'memo')}</td>
                <td>${escapeHtml(m.category || 'Общее')}</td>
                <td>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button class="btn btn-primary btn-xs" onclick="editEducationMaterial(${m.id})" title="Заполнить форму для изменения материала">Изменить</button>
                        <button class="btn btn-danger btn-xs" onclick="deleteEducationMaterial(${m.id})" title="Удалить материал">Удалить</button>
                    </div>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="5" style="color: red; text-align: center;">Ошибка загрузки материалов</td></tr>`;
    }
}

function resetEducationForm() {
    const form = document.getElementById('educationForm');
    if (form) form.reset();
    const idInput = document.getElementById('eduId');
    if (idInput) idInput.value = '';
    const btn = document.getElementById('eduSubmitBtn');
    if (btn) btn.textContent = 'Добавить материал';
    const msg = document.getElementById('educationFormMessage');
    if (msg) msg.style.display = 'none';
}

function editEducationMaterial(id) {
    const m = adminEducationCache.find(item => Number(item.id) === Number(id));
    if (!m) return;
    const idInput = document.getElementById('eduId');
    if (idInput) idInput.value = m.id;
    const titleInput = document.getElementById('eduTitle');
    if (titleInput) titleInput.value = m.title || '';
    const typeInput = document.getElementById('eduType');
    if (typeInput) typeInput.value = m.type || 'memo';
    const catInput = document.getElementById('eduCategory');
    if (catInput) catInput.value = m.category || 'Безопасность';
    const audioInput = document.getElementById('eduAudioUrl');
    if (audioInput) audioInput.value = m.audio_url || '';
    const contentInput = document.getElementById('eduContent');
    if (contentInput) contentInput.value = m.content || '';
    const btn = document.getElementById('eduSubmitBtn');
    if (btn) btn.textContent = 'Сохранить изменения';
    const form = document.getElementById('educationForm');
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
window.resetEducationForm = resetEducationForm;
window.editEducationMaterial = editEducationMaterial;
window.submitEducationForm = submitEducationForm;
window.deleteEducationMaterial = deleteEducationMaterial;

function fillDemoLogin(phone, pass) {
    const p = document.getElementById('gatePhone');
    const pwd = document.getElementById('gatePassword');
    if (p) p.value = phone;
    if (pwd) pwd.value = pass;
    const btn = document.getElementById('loginSubmitBtn');
    if (btn) btn.focus();
}
window.fillDemoLogin = fillDemoLogin;

async function submitEducationForm(event) {
    if (event) event.preventDefault();
    const id = document.getElementById('eduId')?.value;
    const payload = {
        title: document.getElementById('eduTitle').value.trim(),
        type: document.getElementById('eduType').value,
        category: document.getElementById('eduCategory').value.trim() || 'Общее',
        content: document.getElementById('eduContent').value.trim(),
        audio_url: document.getElementById('eduAudioUrl')?.value.trim() || null
    };
    if (!payload.title || !payload.content) {
        showFormMessage('educationFormMessage', 'Укажите заголовок и текст материала', false);
        return false;
    }
    try {
        const url = id ? `/api/education/${id}` : '/api/education';
        const method = id ? 'PUT' : 'POST';
        const res = await authFetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (res.ok && json.success) {
            showFormMessage('educationFormMessage', id ? 'Обучающий материал обновлён' : 'Обучающий материал добавлен', true);
            resetEducationForm();
            loadAdminEducation();
        } else {
            showFormMessage('educationFormMessage', json.error || 'Не удалось сохранить материал', false);
        }
    } catch (e) {
        showFormMessage('educationFormMessage', 'Сбой сети при сохранении', false);
    }
    return false;
}

async function deleteEducationMaterial(id) {
    if (!confirm('Удалить этот обучающий материал?')) return;
    try {
        const res = await authFetch(`/api/education/${id}`, { method: 'DELETE' });
        const json = await res.json();
        if (res.ok && json.success) {
            showFormMessage('educationFormMessage', 'Материал удалён', true);
            loadAdminEducation();
        } else {
            showFormMessage('educationFormMessage', json.error || 'Не удалось удалить материал', false);
        }
    } catch (e) {
        showFormMessage('educationFormMessage', 'Сбой сети при удалении', false);
    }
}

async function loadAdminRequests(status = 'all', region = null) {
    const tbody = document.getElementById('adminRequestsTableBody');
    if (!tbody) return;

    try {
        const regionEl = document.getElementById('adminRegionFilter');
        if (region === null && regionEl) region = regionEl.value;
        const params = new URLSearchParams();
        if (status && status !== 'all') params.set('status', status);
        if (region) params.set('region', region);
        const url = '/api/coordinator/requests' + (params.toString() ? ('?' + params.toString()) : '');
        const res = await authFetch(url);
        const json = await res.json();
        if (json.data) {
            if (json.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 20px;">Заявок с данным статусом не найдено</td></tr>`;
                return;
            }

            tbody.innerHTML = json.data.map(req => {
                let badgeClass = 'badge-open';
                let statusName = 'Открыта';
                if (req.status === 'in_progress') { badgeClass = 'badge-in_progress'; statusName = 'В работе'; }
                else if (req.status === 'completed') { badgeClass = 'badge-completed'; statusName = 'Выполнена'; }
                else if (req.status === 'cancelled') { badgeClass = 'badge-cancelled'; statusName = 'Отменена'; }

                let actions = '-';
                if (req.status === 'open' || req.status === 'in_progress') {
                    actions = `
                        <div style="display: flex; gap: 4px;">
                            <button class="btn btn-outline btn-xs" onclick="adminChangeRequestStatus(${req.id}, 'completed')">${Icons.check} Завершить</button>
                            <button class="btn btn-outline btn-xs" style="color: var(--danger-color);" onclick="adminChangeRequestStatus(${req.id}, 'cancelled')">${Icons.cross} Отменить</button>
                        </div>
                    `;
                }

                return `
                    <tr>
                        <td>#${req.id}</td>
                        <td><span style="display: inline-flex; align-items: center; gap: 6px;">${Icons.category(req.category_id)} ${escapeHtml(req.category_title)}</span></td>
                        <td>
                            <strong>${escapeHtml(req.elderly_name)}</strong><br>
                            <span style="font-size: 0.85em; color: var(--text-muted);">${escapeHtml(req.address)}${req.region_name ? ' • ' + escapeHtml(req.region_name) : ''} (${escapeHtml(req.elderly_phone)})</span>
                        </td>
                        <td>${escapeHtml(req.description || req.title)}</td>
                        <td><span class="secret-code-pill">${escapeHtml(req.secret_code)}</span></td>
                        <td>${req.volunteer_name ? `<strong>${escapeHtml(req.volunteer_name)}</strong>` : '<span style="color: var(--text-muted);">Не назначен</span>'}</td>
                        <td><span class="badge ${badgeClass}">${statusName}</span></td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        }
    } catch (e) {
        console.error(e);
        tbody.innerHTML = `<tr><td colspan="8" style="color: red; text-align: center;">Ошибка загрузки реестра заявок</td></tr>`;
    }
}

async function adminChangeRequestStatus(id, newStatus) {
    if (newStatus === 'completed' && !confirm(`Завершить заявку #${id} вручную? Это обходит проверку секретного слова и GPS; действие будет записано в журнал.`)) {
        return;
    }
    try {
        const res = await authFetch(`/api/coordinator/requests/${id}/status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: newStatus, force: newStatus === 'completed' })
        });
        const data = await res.json();
        if (data.success) {
            loadAdminRequests();
            loadAdminAnalytics();
        } else {
            alert(data.error || 'Ошибка смены статуса');
        }
    } catch (e) {
        alert('Сбой сети');
    }
}

async function loadAdminAlerts() {
    const list = document.getElementById('activeAlertsAdminList');
    if (!list) return;

    try {
        const res = await authFetch('/api/antifraud/alerts');
        const json = await res.json();
        if (json.data && json.data.length > 0) {
            list.innerHTML = json.data.map(a => `
                <div class="card card-accent-danger" style="margin-bottom: 12px; padding: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <strong style="display: inline-flex; align-items: center; gap: 6px;">${Icons.alert} ${escapeHtml(a.title)}</strong>
                            <p style="margin: 6px 0; font-size: 0.9em; color: var(--text-muted);">${escapeHtml(a.description)}</p>
                            <span style="font-size: 0.8em; color: var(--text-muted);">Опубликовано: ${escapeHtml(a.created_at)}</span>
                        </div>
                        <button class="btn btn-outline btn-xs" style="color: var(--danger-color);" onclick="deleteAdminAlert(${a.id})">${Icons.trash} Снять</button>
                    </div>
                </div>
            `).join('');
        } else {
            list.innerHTML = '<p class="text-muted">Нет активных экстренных предупреждений в районе.</p>';
        }
    } catch (e) {
        console.error(e);
        list.innerHTML = '<p style="color: var(--danger-color);">Не удалось загрузить оповещения. Обновите страницу.</p>';
    }
}

async function deleteAdminAlert(id) {
    if (!confirm('Снять это предупреждение с экранов жителей?')) return;
    try {
        const res = await authFetch(`/api/coordinator/alerts/${id}`, { method: 'DELETE' });
        const json = await res.json();
        if (json.success) {
            loadAdminAlerts();
            loadAlerts();
        }
    } catch (e) {
        alert('Не удалось снять предупреждение');
    }
}

// =========================================================================
// 5. Volunteer View & Strict Verification Enforcement
// =========================================================================
async function checkVolunteerStatus() {
    const notice = document.getElementById('volunteerVerificationNotice');
    const title = document.getElementById('volVerifyTitle');
    const desc = document.getElementById('volVerifyDesc');
    if (!notice || !title || !desc) return;

    try {
        const res = await authFetch('/api/auth/me');
        const json = await res.json();
        if (json.success && json.user) {
            const user = json.user;
            const isVerified = user.profile ? parseInt(user.profile.is_verified) : 0;
            const profileRating = user.profile ? parseFloat(user.profile.rating || 5) : 5;
            const isRestricted = user.profile
                ? (parseInt(user.profile.is_restricted) === 1 || profileRating < 3.0)
                : false;
            const badgeBtn = document.getElementById('btnOpenVolunteerBadge');
            const feed = document.getElementById('volunteerRequestList');
            const feedCard = feed ? feed.closest('.card') : null;
            if (badgeBtn) badgeBtn.disabled = isVerified !== 1;
            if (feedCard) feedCard.style.display = isVerified === 1 ? '' : 'none';

            const restrictionNotice = document.getElementById('volunteerRestrictionNotice');
            if (restrictionNotice) {
                if (isRestricted) {
                    const reason = user.profile && user.profile.restriction_reason
                        ? escapeHtml(user.profile.restriction_reason)
                        : 'Низкий рейтинг (ниже 3.0).';
                    restrictionNotice.style.display = 'block';
                    restrictionNotice.innerHTML = `
                        <strong>Ограничение на получение новых заданий.</strong>
                        <div style="margin-top: 4px;">${reason}</div>
                        <div style="margin-top: 4px;">Пройдите назначенные обучающие материалы и попросите координатора ТЦСОН снять ограничение.</div>
                        <button type="button" class="btn btn-outline btn-sm" style="margin-top: 8px;" onclick="document.getElementById('volunteerTrainingCard')?.scrollIntoView({behavior: 'smooth', block: 'start'})">
                            Перейти к материалам
                        </button>
                    `;
                } else {
                    restrictionNotice.style.display = 'none';
                    restrictionNotice.innerHTML = '';
                }
            }
            if (isVerified === 1) {
                notice.style.borderLeftColor = 'var(--accent-color)';
                notice.style.background = '#f0fdf4';
                title.innerHTML = `${Icons.check} <span>Статус волонтёра: Верифицирован</span>`;
                title.style.color = 'var(--accent-color)';
                desc.innerText = 'Ваша личность проверена ТЦСОН. Вы имеете полный доступ к приёму заявок.';
            } else if (isVerified === 0) {
                notice.style.borderLeftColor = 'var(--warning-color)';
                notice.style.background = '#fffbeb';
                title.innerHTML = `${Icons.clock} <span>Статус волонтёра: Ожидает верификации</span>`;
                title.style.color = 'var(--warning-color)';
                desc.innerText = 'Ваш профиль проходит проверку координатором ТЦСОН. До окончания верификации вы НЕ можете брать заявки.';
            } else {
                notice.style.borderLeftColor = 'var(--danger-color)';
                notice.style.background = '#fef2f2';
                title.innerHTML = `${Icons.cross} <span>Статус волонтёра: Отклонён / Заблокирован</span>`;
                title.style.color = 'var(--danger-color)';
                desc.innerText = 'Ваш допуск к заявкам заблокирован администрацией.';
            }
        }
    } catch (e) {
        console.error(e);
        title.innerHTML = `${Icons.alert} <span>Статус волонтёра недоступен</span>`;
        title.style.color = 'var(--danger-color)';
        desc.innerText = 'Не удалось проверить статус. Обновите страницу или обратитесь к координатору.';
    }
}

async function loadVolunteerActiveTasks() {
    const list = document.getElementById('volunteerActiveTasksList');
    if (!list) return;

    try {
        const volId = (currentUser && currentUser.role_code === 'volunteer') ? currentUser.id : currentVolunteerId;
        const res = await authFetch(`/api/requests/volunteer?volunteer_id=${volId}&status=in_progress`);
        const json = await res.json();
        if (json.data && json.data.length > 0) {
            list.innerHTML = json.data.map(req => `
                <div class="card card-accent-warning" style="margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <span style="display: inline-block; vertical-align: middle; margin-right: 6px;">${Icons.category(req.category_id)}</span>
                            <strong style="font-size: 1.1em;">${escapeHtml(req.title)}</strong>
                            <span class="badge badge-warning" style="margin-left: 8px;">${Icons.clock} В работе</span>
                            <p style="margin: 6px 0; color: var(--text-muted);">${escapeHtml(req.description || '')}</p>
                            <p style="font-size: 0.9em; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                                ${Icons.mapPin} <span>${escapeHtml(req.address)} • Подопечный: <strong>${escapeHtml(req.elderly_name)}</strong> (${escapeHtml(req.elderly_phone)})</span>
                            </p>
                        </div>
                        <div>
                            <button class="btn btn-success" style="font-size: 1.05em; padding: 0 18px;" onclick="completeRequestPrompt(${req.id})">
                                ${Icons.check} Завершить (ввести код)
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            list.innerHTML = '<p class="text-muted">У вас пока нет активных заданий в работе. Выберите подходящую заявку из ленты ниже и нажмите «Взять заявку».</p>';
        }
    } catch (e) {
        console.error(e);
        list.innerHTML = '<p style="color: var(--danger-color);">Ошибка загрузки активных заданий.</p>';
    }
}

let lastVolunteerFeedSignature = null;

async function loadVolunteerRequests() {
    const list = document.getElementById('volunteerRequestList');
    if (!list) return;

    try {
        const region = document.getElementById('volunteerRegionFilter')?.value || '';
        const coords = currentVolunteerCoords || { lat: 53.9168, lon: 27.5862 };
        const params = new URLSearchParams({ lat: String(coords.lat), lon: String(coords.lon) });
        if (region) params.set('region', region);
        const res = await authFetch('/api/requests?' + params.toString());
        const json = await res.json();
        if (!res.ok) {
            list.innerHTML = '<p style="color: var(--danger-color);">Не удалось загрузить ленту заявок. Повторите попытку.</p>';
            return;
        }

        const items = Array.isArray(json.data) ? json.data : [];
        const updatedEl = document.getElementById('volunteerFeedUpdatedAt');
        if (updatedEl) {
            updatedEl.textContent = 'Обновлено: ' + new Date().toLocaleTimeString('ru-RU') + ' · автообновление каждые 10 секунд';
        }

        // Avoid rebuilding the DOM (and stealing focus) when nothing changed.
        const signature = items.map(req => `${req.id}:${req.status}`).join('|');
        if (signature === lastVolunteerFeedSignature) return;
        lastVolunteerFeedSignature = signature;

        if (items.length === 0) {
            list.innerHTML = '<p class="text-muted">Сейчас нет открытых заявок в вашем районе.</p>';
            return;
        }

        list.innerHTML = items.map(req => {
            let actionBtn = `<button class="btn btn-primary btn-sm" onclick="acceptRequest(${req.id})">${Icons.volunteer} Взять заявку</button>`;
            if (req.status === 'in_progress') {
                actionBtn = `<button class="btn btn-success btn-sm" onclick="completeRequestPrompt(${req.id})">${Icons.check} Завершить (Код)</button>`;
            }

            return `
                <div class="card card-accent-primary" style="margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <span style="display: inline-block; vertical-align: middle; margin-right: 6px;">${Icons.category(req.category_id)}</span>
                            <strong style="font-size: 20px;">${escapeHtml(req.title)}</strong>
                            <p style="margin: 8px 0; color: var(--text-muted);">${escapeHtml(req.description || '')}</p>
                            <p style="display: flex; align-items: center; gap: 6px;">
                                ${Icons.mapPin} <span>Адрес: <strong>${escapeHtml(req.address)}</strong>${req.region_name ? ' • ' + escapeHtml(req.region_name) : ''}${req.distance_meters != null ? ' (расстояние: ~' + escapeHtml(String(req.distance_meters)) + ' м)' : ' (расстояние неизвестно)'}</span>
                            </p>
                            <p style="display: flex; align-items: center; gap: 6px;">
                                ${Icons.user} <span>Подопечный: ${escapeHtml(req.elderly_name)} (${escapeHtml(req.elderly_phone)})</span>
                            </p>
                        </div>
                        <div>
                            ${actionBtn}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error(e);
        list.innerHTML = '<p style="color: var(--danger-color);">Ошибка сети при загрузке заявок.</p>';
    }
}

let lastTrainingSignature = null;

async function loadVolunteerTrainingAssignments() {
    const card = document.getElementById('volunteerTrainingCard');
    const list = document.getElementById('volunteerTrainingList');
    if (!card || !list) return;

    try {
        const res = await authFetch('/api/volunteers/training');
        const json = await res.json();
        if (!res.ok) {
            card.style.display = 'none';
            return;
        }
        const items = Array.isArray(json.data) ? json.data : [];
        const signature = items.map(item => `${item.material_id}:${item.completed_at || ''}`).join('|');
        if (signature === lastTrainingSignature) return;
        lastTrainingSignature = signature;

        if (items.length === 0) {
            card.style.display = 'none';
            list.innerHTML = '';
            return;
        }

        card.style.display = 'block';
        list.innerHTML = items.map(item => {
            const completed = !!item.completed_at;
            const preview = String(item.content || '').slice(0, 280);
            return `
                <div class="card" style="margin-bottom: 10px; border-left: 4px solid var(--warning-color, #d97706);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
                        <div>
                            <strong style="font-size: 1.05em;">${escapeHtml(item.title)}</strong>
                            <div style="font-size: 0.85em; color: var(--text-muted); margin-top: 2px;">
                                ${escapeHtml(item.category || '')}${item.type ? ' · ' + escapeHtml(item.type) : ''}
                            </div>
                        </div>
                        ${completed
                            ? '<span class="badge badge-verified">Изучено</span>'
                            : `<button class="btn btn-outline btn-xs" onclick="completeTrainingMaterial(${item.material_id})">Отметить изученным</button>`}
                    </div>
                    <p style="margin: 8px 0 0; color: var(--text-muted);">${escapeHtml(preview)}${String(item.content || '').length > 280 ? '…' : ''}</p>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('Error loading training assignments', e);
        card.style.display = 'none';
    }
}
window.loadVolunteerTrainingAssignments = loadVolunteerTrainingAssignments;

async function completeTrainingMaterial(materialId) {
    try {
        const res = await authFetch(`/api/volunteers/training/${materialId}/complete`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            lastTrainingSignature = null;
            loadVolunteerTrainingAssignments();
        } else {
            alert(data.error || 'Не удалось отметить материал изученным');
        }
    } catch (e) {
        console.error('Error completing training material', e);
        alert('Ошибка сети. Попробуйте ещё раз.');
    }
}
window.completeTrainingMaterial = completeTrainingMaterial;

async function acceptRequest(id) {
    try {
        const res = await authFetch(`/api/requests/${id}/accept`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ volunteer_id: currentVolunteerId })
        });
        const data = await res.json();
        if (data.success) {
            alert(`Заявка принята!\n\nОБЯЗАТЕЛЬНО назовите подопечному проверочное кодовое слово у двери:\n«${data.secret_code}»\n\nЗаявка добавлена в блок «Мои задания в работе» выше. Когда выполните визит, нажмите там «Завершить (Код)».`);
            loadVolunteerRequests();
            loadVolunteerActiveTasks();
            document.getElementById('volunteerActiveTasksCard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            loadElderlyRequests();
        } else {
            alert(data.error || 'Не удалось принять заявку');
        }
    } catch (e) {
        alert('Ошибка связи с сервером при взятии заявки');
    }
}

// =========================================================================
// M4: Geofenced Completion with Navigator Geolocation (Proof-of-Presence)
// =========================================================================
let currentCompletingRequestId = null;

function openCompleteModal(id, addressHint = '') {
    currentCompletingRequestId = id;
    const modal = document.getElementById('geo-complete-modal');
    const reqIdInput = document.getElementById('geoCompleteRequestId');
    const secretInput = document.getElementById('geoSecretWordInput');
    const coordsDisplay = document.getElementById('geoCoordinatesDisplay');
    const errorBox = document.getElementById('geoDistanceErrorBox');
    const errorText = document.getElementById('geoDistanceErrorText');
    const confirmBtn = document.getElementById('confirmGeoCompleteBtn');

    if (reqIdInput) reqIdInput.value = id;
    if (secretInput) {
        secretInput.value = '';
        setTimeout(() => secretInput.focus(), 100);
    }
    if (errorBox) {
        errorBox.style.display = 'none';
        if (errorText) errorText.innerText = '';
    }
    if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = `${Icons.check} Подтвердить выполнение`;
    }

    if (coordsDisplay) {
        coordsDisplay.innerText = 'Определение GPS-координат...';
        coordsDisplay.removeAttribute('data-lat');
        coordsDisplay.removeAttribute('data-lon');
    }

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                if (coordsDisplay) {
                    coordsDisplay.innerText = `Широта: ${pos.coords.latitude.toFixed(6)}, Долгота: ${pos.coords.longitude.toFixed(6)} (точность ~${Math.round(pos.coords.accuracy)}м)`;
                    coordsDisplay.setAttribute('data-lat', pos.coords.latitude);
                    coordsDisplay.setAttribute('data-lon', pos.coords.longitude);
                }
            },
            (err) => {
                console.warn('Geolocation query warning:', err);
                if (coordsDisplay) {
                    coordsDisplay.innerText = 'GPS недоступен. Разрешите геолокацию в браузере — без координат подтвердить визит нельзя.';
                    coordsDisplay.removeAttribute('data-lat');
                    coordsDisplay.removeAttribute('data-lon');
                }
            },
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
        );
    } else {
        if (coordsDisplay) coordsDisplay.innerText = 'Геолокация не поддерживается браузером';
    }

    if (modal) modal.style.display = 'flex';
}
window.openCompleteModal = openCompleteModal;
window.completeRequestPrompt = openCompleteModal; // Backward-compatible upgrade

function closeCompleteModal() {
    const modal = document.getElementById('geo-complete-modal');
    if (modal) modal.style.display = 'none';
    currentCompletingRequestId = null;
}
window.closeCompleteModal = closeCompleteModal;

async function submitGeoComplete(overrideNoGps = false) {
    const reqIdInput = document.getElementById('geoCompleteRequestId');
    const id = reqIdInput ? reqIdInput.value : currentCompletingRequestId;
    const secretInput = document.getElementById('geoSecretWordInput');
    const secretCode = secretInput ? secretInput.value.trim() : '';
    const errorBox = document.getElementById('geoDistanceErrorBox');
    const errorText = document.getElementById('geoDistanceErrorText');
    const coordsDisplay = document.getElementById('geoCoordinatesDisplay');
    const confirmBtn = document.getElementById('confirmGeoCompleteBtn');

    if (!secretCode) {
        if (errorBox) {
            errorBox.style.display = 'block';
            if (errorText) errorText.innerText = 'Введите проверочное секретное слово от подопечного';
        }
        return;
    }

    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerText = overrideNoGps ? 'Завершение по коду...' : 'Проверка геолокации...';
    }

    let lat = null;
    let lon = null;
    if (coordsDisplay && coordsDisplay.getAttribute('data-lat')) {
        lat = parseFloat(coordsDisplay.getAttribute('data-lat'));
        lon = parseFloat(coordsDisplay.getAttribute('data-lon'));
    } else if (navigator.geolocation) {
        try {
            const pos = await new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true,
                    timeout: 4000,
                    maximumAge: 0
                });
            });
            lat = pos.coords.latitude;
            lon = pos.coords.longitude;
        } catch (e) {
            console.warn('Fallback GPS retrieval failed', e);
        }
    }

    if ((lat === null || lon === null || isNaN(lat) || isNaN(lon)) && overrideNoGps) {
        lat = 53.9168;
        lon = 27.5862;
    }

    try {
        if (lat === null || lon === null || isNaN(lat) || isNaN(lon)) {
            if (errorBox) {
                errorBox.style.display = 'block';
                if (errorText) {
                    errorText.innerHTML = `
                        <div style="margin-bottom: 8px;">Не удалось определить GPS-координаты волонтёра.</div>
                        <div style="font-size: 0.9em; margin-bottom: 10px;">Разрешите геолокацию в браузере или подтвердите визит только по кодовому слову подопечного:</div>
                        <button type="button" class="btn btn-warning btn-sm" onclick="submitGeoComplete(true)" style="width: 100%; min-height: 40px; font-weight: 600;">
                            ${Icons.check} Завершить по проверочному слову подопечного
                        </button>
                    `;
                }
            }
            return;
        }

        const payload = { secret_code: secretCode, latitude: lat, longitude: lon };
        if (overrideNoGps) {
            payload.no_gps_override = true;
        }

        const res = await authFetch(`/api/requests/${id}/complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (res.ok && data.success) {
            closeCompleteModal();
            SpeechHelper.speak('Визит успешно подтверждён! Вам начислено 50 очков опыта.');
            const extraNote = data.verified_by === 'secret_word_override'
                ? '\n(Подтверждено проверочным кодом подопечного без привязки к GPS)'
                : `\nРасстояние: ${data.distance_meters || 0} м.`;
            alert(`Заявка #${id} успешно выполнена и подтверждена!${extraNote}\nНачислено: +50 очков кармы.`);
            loadVolunteerRequests();
            loadVolunteerActiveTasks();
            loadLeaderboard();
        } else {
            const errMsg = data.error || 'Ошибка при завершении заявки';
            if (errorBox) {
                errorBox.style.display = 'block';
                if (errMsg.includes('вне допустимой зоны') || errMsg.includes('расстояние') || data.can_override_with_secret) {
                    if (errorText) {
                        errorText.innerHTML = `
                            <div style="margin-bottom: 8px;">
                                <strong>${Icons.alert} Превышение радиуса геозоны (до 150 м):</strong>
                            </div>
                            <div style="font-size: 0.9em; margin-bottom: 12px; line-height: 1.4;">
                                ${escapeHtml(errMsg)}.<br>
                                Если подопечный подавал заявку с ПК (без точного GPS) или на устройстве высокая погрешность — вы можете завершить визит по кодовому слову:
                            </div>
                            <button type="button" class="btn btn-warning btn-sm" id="btnOverrideGeofence" onclick="submitGeoComplete(true)" style="width: 100%; min-height: 40px; font-weight: 600;">
                                ${Icons.check} Завершить по проверочному слову подопечного
                            </button>
                        `;
                    }
                } else {
                    if (errorText) errorText.innerText = errMsg;
                }
            } else {
                alert(errMsg);
            }
        }
    } catch (e) {
        console.error('Completion request error', e);
        if (errorBox) {
            errorBox.style.display = 'block';
            if (errorText) errorText.innerText = 'Сбой сети при отправке подтверждения';
        }
    } finally {
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = `${Icons.check} Подтвердить выполнение`;
        }
    }
}
window.submitGeoComplete = submitGeoComplete;

async function loadLeaderboard() {
    const box = document.getElementById('leaderboardList');
    if (!box) return;

    try {
        const res = await authFetch('/api/volunteers/leaderboard');
        const json = await res.json();
        if (!res.ok) {
            box.innerHTML = '<p style="color: var(--danger-color);">Не удалось загрузить таблицу лидеров.</p>';
            return;
        }
        if (json.data && json.data.length > 0) {
            box.innerHTML = json.data.map((v, idx) => `
                <div style="display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #e2e8f0;">
                    <div>
                        <strong>#${idx + 1} ${escapeHtml(v.name)}</strong>
                        <span class="badge badge-success" style="margin-left: 8px; display: inline-flex; align-items: center; gap: 4px;">${Icons.star} Рейтинг ${v.rating}</span>
                    </div>
                    <div>
                        <strong>${v.points} баллов</strong> (${v.completed_tasks_count} заданий)
                    </div>
                </div>
            `).join('');
        } else {
            box.innerHTML = '<p class="text-muted">Пока нет выполненных заданий — таблица лидеров появится позже.</p>';
        }
    } catch (e) {
        console.error(e);
        box.innerHTML = '<p style="color: var(--danger-color);">Ошибка загрузки таблицы лидеров.</p>';
    }
}

// =========================================================================
// 6. Shared Forms (Create Request, Anti-Fraud, Alerts)
// =========================================================================
function beginFormSubmit(form) {
    if (!form || form.dataset.busy === '1') return false;
    form.dataset.busy = '1';
    const button = form.querySelector('button[type="submit"]') || form.querySelector('button:not([type])');
    if (button) {
        button.dataset.previousHtml = button.innerHTML;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    }
    return true;
}

function endFormSubmit(form) {
    if (!form) return;
    form.dataset.busy = '0';
    const button = form.querySelector('button[type="submit"]') || form.querySelector('button:not([type])');
    if (button) {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        if (button.dataset.previousHtml) button.innerHTML = button.dataset.previousHtml;
    }
}

function initForms() {
    // Submit Help Request
    const helpForm = document.getElementById('helpForm');
    const selectedCatInput = document.getElementById('selectedCategory');
    const requestDesc = document.getElementById('requestDescription');

    if (helpForm) {
        helpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!beginFormSubmit(helpForm)) return;
            try {
            const catId = selectedCatInput ? selectedCatInput.value : 1;
            const desc = requestDesc ? requestDesc.value.trim() : '';
            const address = document.getElementById('requestAddress')?.value
                || document.getElementById('requestRegion')?.selectedOptions?.[0]?.textContent?.trim()
                || 'Республика Беларусь';

            if (!desc) {
                alert('Пожалуйста, укажите или надиктуйте, какая помощь вам нужна.');
                return;
            }

            const consentBox = document.getElementById('requestConsentCheckbox');
            if (consentBox && !consentBox.checked) {
                alert('Для создания заявки необходимо подтвердить согласие на обработку персональных данных в соответствии с Законом РБ № 99-З');
                return;
            }

            if (consentBox && consentBox.checked) {
                try {
                    const consentRes = await authFetch('/api/consent', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            consent_type: 'DATA_PROCESSING_99Z',
                            policy_version: '1.0.0'
                        })
                    });
                    if (!consentRes.ok) {
                        throw new Error('consent rejected');
                    }
                } catch (consentErr) {
                    alert('Не удалось зафиксировать согласие на обработку данных. Попробуйте ещё раз.');
                    return;
                }
            }

            const regionSelect = document.getElementById('requestRegion');
            const regionCode = regionSelect?.value || '';
            const selectedRegion = regionSelect?.selectedOptions?.[0];
            let reqLat = selectedRegion?.dataset?.lat ? parseFloat(selectedRegion.dataset.lat) : 53.9168;
            let reqLon = selectedRegion?.dataset?.lon ? parseFloat(selectedRegion.dataset.lon) : 27.5862;
            if (navigator.geolocation) {
                try {
                    const pos = await new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: true,
                            timeout: 2500,
                            maximumAge: 60000
                        });
                    });
                    reqLat = pos.coords.latitude;
                    reqLon = pos.coords.longitude;
                } catch (geoErr) {
                    // Keep the regional centre as a fallback.
                }
            }

            try {
                const res = await authFetch('/api/requests', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        elderly_id: (currentUser && currentUser.role_code === 'elderly') ? currentUser.id : 1,
                        category_id: parseInt(catId),
                        region_code: regionCode,
                        title: desc.substring(0, 60),
                        description: desc,
                        address: address,
                        latitude: reqLat,
                        longitude: reqLon
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showCreatedModal(data.secret_code);
                    helpForm.reset();
                    resetCategorySelection();
                    loadElderlyRequests();
                    loadVolunteerRequests();
                } else {
                    alert(data.error || 'Ошибка при создании заявки');
                }
            } catch (err) {
                console.error(err);
                alert('Ошибка сети при создании заявки');
            }
            } finally {
                endFormSubmit(helpForm);
            }
        });
    }

    // Anti-Fraud Express Scanner
    const runFraudBtn = document.getElementById('runFraudCheckBtn');
    const fraudInput = document.getElementById('fraudCheckInput');
    const fraudResultBox = document.getElementById('fraudResultBox');

    if (runFraudBtn && fraudInput && fraudResultBox) {
        runFraudBtn.addEventListener('click', async () => {
            const text = fraudInput.value.trim();
            if (!text) {
                alert('Введите фразу или описание звонка');
                return;
            }

            try {
                const res = await authFetch('/api/antifraud/check', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text })
                });
                const data = await res.json();
                if (data.success) {
                    const r = data.result;
                    fraudResultBox.style.display = 'block';
                    if (r.risk_level === 'danger') {
                        fraudResultBox.className = 'alert-banner alert-banner-danger';
                    } else if (r.risk_level === 'warning') {
                        fraudResultBox.className = 'alert-banner alert-banner-warning';
                    } else {
                        fraudResultBox.className = 'alert-banner alert-banner-success';
                    }

                    fraudResultBox.innerHTML = `
                        <h4>Вердикт ИИ-детектора:</h4>
                        <p style="margin: 8px 0; font-weight: bold;">${escapeHtml(r.summary)}</p>
                        ${(r.danger_matches || []).map(m => `<p style="display: flex; align-items: center; gap: 6px;">${Icons.cross} <span><strong>Паттерн:</strong> «${escapeHtml(m.pattern)}» — ${escapeHtml(m.advice)}</span></p>`).join('')}
                        ${(r.warning_matches || []).map(m => `<p style="display: flex; align-items: center; gap: 6px;">${Icons.alert} <span><strong>Внимание:</strong> «${escapeHtml(m.pattern)}» — ${escapeHtml(m.advice)}</span></p>`).join('')}
                    `;
                    SpeechHelper.speak(r.summary);
                }
            } catch (e) {
                alert('Ошибка связи с сервисом антифрода');
            }
        });
    }

    // Anti-Fraud Quick Sample Buttons
    document.querySelectorAll('.fraud-sample-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const sampleText = btn.getAttribute('data-text');
            if (fraudInput && sampleText) {
                fraudInput.value = sampleText;
                if (runFraudBtn) runFraudBtn.click();
            }
        });
    });

    // Alert Form for Admin
    const alertForm = document.getElementById('alertForm');
    if (alertForm) {
        alertForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!beginFormSubmit(alertForm)) return;
            try {
            const title = document.getElementById('alertInputTitle')?.value;
            const desc = document.getElementById('alertInputDesc')?.value;
            const severity = document.getElementById('alertInputSeverity')?.value || 'warning';

            try {
                const res = await authFetch('/api/coordinator/alerts', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, description: desc, severity })
                });
                const data = await res.json();
                if (data.success) {
                    alert('Экстренное оповещение успешно опубликовано!');
                    alertForm.reset();
                    loadAdminAlerts();
                    loadAlerts();
                } else {
                    alert(data.error || 'Ошибка публикации');
                }
            } catch (err) {
                alert('Ошибка при публикации');
            }
            } finally {
                endFormSubmit(alertForm);
            }
        });
    }
}

function showCreatedModal(secretCode) {
    const banner = document.getElementById('secretCodeBanner');
    const wordEl = document.getElementById('displaySecretWord');
    if (banner && wordEl) {
        wordEl.innerText = secretCode;
        banner.style.display = 'block';
        banner.scrollIntoView({ behavior: 'smooth' });
        SpeechHelper.speak('Заявка создана! Ваше защитное проверочное слово: ' + secretCode + '. Волонтёр обязан назвать его у двери.');
    }
}

let allEducationMaterialsCache = [];
let selectedEducationCategory = 'all';
let educationSearchQuery = '';

function handleEducationSearch(query) {
    educationSearchQuery = query || '';
    renderEducationCards(selectedEducationCategory);
}

function clearEducationSearch() {
    educationSearchQuery = '';
    const input = document.getElementById('educationSearchInput');
    if (input) input.value = '';
    renderEducationCards(selectedEducationCategory);
}

function renderEducationMedia(item) {
    if (!item || !item.audio_url) return '';
    const url = escapeHtml(item.audio_url);
    const type = String(item.type || '').toLowerCase();
    if (type === 'video') {
        const isYouTube = /youtube\.com|youtu\.be/i.test(String(item.audio_url));
        if (isYouTube) {
            return `<p style="margin-bottom: 12px;"><a class="btn btn-outline btn-sm" href="${url}" target="_blank" rel="noopener noreferrer">Смотреть видео</a></p>`;
        }
        return `<video controls preload="none" src="${url}" style="max-width: 100%; margin-bottom: 12px; border-radius: 8px;"></video>`;
    }
    return `<audio controls preload="none" src="${url}" style="width: 100%; margin-bottom: 12px;"></audio>`;
}

function renderEducationCards(category = 'all') {
    const container = document.getElementById('educationList');
    if (!container) return;
    selectedEducationCategory = category;

    // Render category filter tabs
    const tabsContainer = document.getElementById('educationCategoryTabs');
    if (tabsContainer) {
        const categories = ['all', ...Array.from(new Set(allEducationMaterialsCache.map(m => m.category || 'Общее')))];
        tabsContainer.innerHTML = categories.map(cat => {
            const isSelected = cat === selectedEducationCategory;
            const count = cat === 'all'
                ? allEducationMaterialsCache.length
                : allEducationMaterialsCache.filter(m => (m.category || 'Общее') === cat).length;
            const label = cat === 'all' ? `Все (${count})` : `${escapeHtml(cat)} (${count})`;
            return `<button type="button" class="btn ${isSelected ? 'btn-primary' : 'btn-outline'} btn-xs" style="min-height: 38px; padding: 6px 12px;" onclick="renderEducationCards(${jsString(cat)})">${label}</button>`;
        }).join('');
    }

    let filtered = (category === 'all')
        ? allEducationMaterialsCache
        : allEducationMaterialsCache.filter(m => (m.category || 'Общее') === category);

    if (educationSearchQuery.trim() !== '') {
        const q = educationSearchQuery.trim().toLowerCase();
        filtered = filtered.filter(m =>
            (m.title && m.title.toLowerCase().includes(q)) ||
            (m.content && m.content.toLowerCase().includes(q)) ||
            (m.category && m.category.toLowerCase().includes(q))
        );
    }

    if (filtered.length === 0) {
        container.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--text-muted);">Материалов по вашему запросу не найдено. Попробуйте изменить поисковый запрос или выбрать категорию «Все».</div>';
        return;
    }

    container.innerHTML = filtered.map(item => `
        <div class="card" style="margin-bottom: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                <h3 style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">${Icons.shield} <span>${escapeHtml(item.title)}</span></h3>
                <span class="badge badge-open" style="font-size: 0.8em;">${escapeHtml(item.category || 'Общее')}</span>
            </div>
            <p style="margin: 8px 0 12px 0; line-height: 1.5;">${escapeHtml(item.content)}</p>
            ${renderEducationMedia(item)}
            <button class="btn btn-outline btn-sm" onclick="SpeechHelper.speak(${jsString(item.title + '. ' + item.content)})">
                ${Icons.volume} Прослушать памятку
            </button>
        </div>
    `).join('');
}

async function loadEducation() {
    const container = document.getElementById('educationList');
    if (!container) return;

    try {
        const res = await authFetch('/api/education');
        const json = await res.json();
        if (json.data && Array.isArray(json.data)) {
            allEducationMaterialsCache = json.data;
            renderEducationCards(selectedEducationCategory);
        }
    } catch (e) {
        console.error('Error loading education materials', e);
        container.innerHTML = '<p style="color: var(--danger-color);">Не удалось загрузить памятки. Повторите попытку.</p>';
    }
}

let selectedVolunteerKnowledgeCategory = 'all';

function renderVolunteerKnowledgeCards(category = 'all') {
    const container = document.getElementById('volunteerKnowledgeList');
    if (!container) return;
    selectedVolunteerKnowledgeCategory = category;

    // Filter relevant categories for volunteers
    const volunteerCategories = ['Волонтёрство', 'Реабилитация', 'Безопасность', 'Здоровье', 'Бытовая безопасность', 'Социальная помощь'];
    const relevantMaterials = allEducationMaterialsCache.filter(m => volunteerCategories.includes(m.category));

    const tabsContainer = document.getElementById('volunteerKnowledgeCategoryTabs');
    if (tabsContainer) {
        const categories = ['all', ...Array.from(new Set(relevantMaterials.map(m => m.category || 'Общее')))];
        tabsContainer.innerHTML = categories.map(cat => {
            const isSelected = cat === selectedVolunteerKnowledgeCategory;
            const count = cat === 'all'
                ? relevantMaterials.length
                : relevantMaterials.filter(m => (m.category || 'Общее') === cat).length;
            const label = cat === 'all' ? `Все стандарты (${count})` : `${escapeHtml(cat)} (${count})`;
            return `<button type="button" class="btn ${isSelected ? 'btn-primary' : 'btn-outline'} btn-xs" style="min-height: 36px; padding: 4px 10px;" onclick="renderVolunteerKnowledgeCards(${jsString(cat)})">${label}</button>`;
        }).join('');
    }

    const filtered = (category === 'all')
        ? relevantMaterials
        : relevantMaterials.filter(m => (m.category || 'Общее') === category);

    if (filtered.length === 0) {
        container.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--text-muted);">Материалов в данной категории пока нет</div>';
        return;
    }

    container.innerHTML = filtered.map(item => `
        <div class="card" style="margin-bottom: 12px; background: var(--card-subtle); border-left: 4px solid var(--primary-color);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                <h4 style="display: flex; align-items: center; gap: 8px; margin: 0 0 6px 0; font-size: 1.05em;">${Icons.shield} <span>${escapeHtml(item.title)}</span></h4>
                <span class="badge badge-open" style="font-size: 0.75em;">${escapeHtml(item.category || 'Регламент')}</span>
            </div>
            <p style="margin: 6px 0 0 0; line-height: 1.45; font-size: 0.95em;">${escapeHtml(item.content)}</p>
        </div>
    `).join('');
}

async function loadVolunteerKnowledge() {
    const container = document.getElementById('volunteerKnowledgeList');
    if (!container) return;

    if (allEducationMaterialsCache.length === 0) {
        try {
            const res = await authFetch('/api/education');
            const json = await res.json();
            if (json.data && Array.isArray(json.data)) {
                allEducationMaterialsCache = json.data;
            }
        } catch (e) {
            console.error('Error loading education materials for volunteer', e);
        }
    }
    renderVolunteerKnowledgeCards(selectedVolunteerKnowledgeCategory);
}

async function loadAlerts() {
    const alertBox = document.getElementById('urgentAlertBox');
    const titleEl = document.getElementById('alertTitle');
    const descEl = document.getElementById('alertDesc');
    if (!alertBox) return;

    // Administrators create fraud alerts; showing their own alerts back to them
    // as an unread citizen banner is noise. The management list stays available.
    if (currentRole === 'admin') {
        alertBox.style.display = 'none';
        return;
    }

    try {
        const res = await authFetch('/api/antifraud/alerts');
        const json = await res.json();
        if (json.data && json.data.length > 0) {
            const first = json.data[0];
            titleEl.innerHTML = `${Icons.alert} <span>${escapeHtml(first.title)}</span>`;
            descEl.innerHTML = json.data.map(a => `<p style="margin: 4px 0;"><strong>${escapeHtml(a.title)}:</strong> ${escapeHtml(a.description || '')}</p>`).join('');
            alertBox.classList.remove('alert-banner-danger', 'alert-banner-warning', 'alert-banner-info', 'alert-banner-success');
            const severityClass = first.severity === 'danger' ? 'alert-banner-danger'
                : first.severity === 'warning' ? 'alert-banner-warning'
                : first.severity === 'info' ? 'alert-banner-info' : 'alert-banner-warning';
            alertBox.classList.add(severityClass);
            alertBox.style.display = 'block';
        } else {
            alertBox.style.display = 'none';
        }
    } catch (e) {
        console.error(e);
    }
}

async function loadElderlySosState() {
    const banner = document.getElementById('activeSosBanner');
    const ticketSpan = document.getElementById('sosTicketId');
    if (!banner) return;
    try {
        const res = await authFetch('/api/sos/my-active');
        if (!res.ok) return;
        const data = await res.json();
        const alerts = data.data || data.alerts || [];
        if (alerts.length > 0) {
            if (ticketSpan) ticketSpan.innerText = alerts[0].emercom_ticket_id || ('SOS-' + alerts[0].id);
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }
    } catch (e) {
        // The banner is optional on the senior home screen; keep the page usable.
    }
}

// =========================================================================
// 7. Accessibility Helpers & Escaping
// =========================================================================
function initAccessibility() {
    const toggleSeniorBtn = document.getElementById('toggleSeniorMode');
    if (toggleSeniorBtn) {
        const isSenior = localStorage.getItem('seniorMode') === 'true';
        toggleSeniorBtn.setAttribute('aria-pressed', isSenior ? 'true' : 'false');
        if (isSenior) {
            document.body.classList.add('senior-mode');
            toggleSeniorBtn.classList.add('btn-primary');
            toggleSeniorBtn.classList.remove('btn-outline');
        } else {
            document.body.classList.remove('senior-mode');
            toggleSeniorBtn.classList.remove('btn-primary');
            toggleSeniorBtn.classList.add('btn-outline');
        }

        toggleSeniorBtn.addEventListener('click', () => {
            const active = document.body.classList.toggle('senior-mode');
            toggleSeniorBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
            localStorage.setItem('seniorMode', active ? 'true' : 'false');
            if (active) {
                toggleSeniorBtn.classList.add('btn-primary');
                toggleSeniorBtn.classList.remove('btn-outline');
            } else {
                toggleSeniorBtn.classList.remove('btn-primary');
                toggleSeniorBtn.classList.add('btn-outline');
            }
            SpeechHelper.refreshProfile({ announce: true });
            SpeechHelper.speak(active ? 'Включён крупный режим для слабовидящих' : 'Включён стандартный режим');
        });
    }

    const speakPageBtn = document.getElementById('speakPageBtn');
    if (speakPageBtn) {
        speakPageBtn.addEventListener('click', () => {
            SpeechHelper.speakPage();
        });
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Safely embed a JavaScript string literal inside a double-quoted HTML
// attribute. JSON.stringify handles quotes/newlines, escapeHtml protects the
// attribute boundary, and the browser decodes entities before JS parses it.
function jsString(value) {
    return escapeHtml(JSON.stringify(String(value ?? '')));
}

// =========================================================================
// M4: Senior Emergency SOS Implementation
// =========================================================================
async function triggerSos(customReason = 'Экстренная тревога подопечного: требуется срочная помощь') {
    const sosBtn = document.getElementById('sosBtn');
    if (sosBtn) {
        sosBtn.disabled = true;
        sosBtn.classList.add('sos-active');
    }

    SpeechHelper.speak('Сигнал бедствия активирован. Передаём сигнал координатору ТЦСОН.', { priority: 0 });

    // Do not invent a location: if GPS is unavailable the server falls back to
    // the elderly profile stored in the account (the actual home address).
    let latitude = null;
    let longitude = null;

    // Fast Geolocation attempt with 3.5s timeout
    if (navigator.geolocation) {
        try {
            const pos = await new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true,
                    timeout: 3500,
                    maximumAge: 10000
                });
            });
            latitude = pos.coords.latitude;
            longitude = pos.coords.longitude;
        } catch (geoErr) {
            console.warn('Geolocation unavailable, server will use the profile address:', geoErr);
        }
    }

    try {
        const payload = { reason: customReason };
        if (latitude !== null && longitude !== null) {
            payload.latitude = latitude;
            payload.longitude = longitude;
        }
        const res = await authFetch('/api/sos', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (data.success) {
            const ticketId = data.emercom_ticket_id || ('SOS-' + (data.sos_id || '1'));
            const banner = document.getElementById('activeSosBanner');
            const ticketSpan = document.getElementById('sosTicketId');
            if (ticketSpan) ticketSpan.innerText = ticketId;
            if (banner) banner.style.display = 'block';

            if (data.emercom_notified === true) {
                SpeechHelper.speak('Сигнал принят! Служба 112 и координатор района оповещены. Помощь направлена.', { priority: 0 });
            } else {
                SpeechHelper.speak('Сигнал принят координатором ТЦСОН. Если опасность непосредственная, срочно позвоните по номеру 112.', { priority: 0 });
            }
            showSosConfirmationModal(data);
        } else {
            alert('Ошибка вызова экстренной помощи: ' + (data.error || 'Попробуйте позвонить 112 напрямую'));
        }
    } catch (e) {
        console.error('SOS dispatch error', e);
        alert('Критическая ошибка связи при отправке SOS. Пожалуйста, срочно позвоните по номеру 112!');
    } finally {
        if (sosBtn) {
            sosBtn.disabled = false;
            sosBtn.classList.remove('sos-active');
        }
    }
}
window.triggerSos = triggerSos;

function showSosConfirmationModal(data) {
    let modal = document.getElementById('sosConfirmationModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sosConfirmationModal';
        modal.className = 'modal-backdrop';
        document.body.appendChild(modal);
    }

    const notified = data.emercom_notified === true;
    const ticketId = data.emercom_ticket_id || ('SOS-' + (data.sos_id || '1'));
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'sosConfirmationTitle');
    modal.innerHTML = `
        <div class="modal-dialog card-accent-danger" style="border: 4px solid var(--danger-color); max-width: 500px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <div style="display: inline-flex; align-items: center; justify-content: center; width: 72px; height: 72px; border-radius: 50%; background: #fee2e2; color: var(--danger-color); margin-bottom: 12px;">
                    <svg class="ui-icon ui-icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h2 id="sosConfirmationTitle" style="color: var(--danger-color); font-size: 1.5em; margin-bottom: 8px;">СИГНАЛ SOS ПРИНЯТ!</h2>
                <p style="font-weight: bold; font-size: 1.05em;">${notified ? 'Координатор ТЦСОН и служба МЧС (112) оповещены.' : 'Координатор ТЦСОН оповещён. Интеграция с МЧС работает в тестовом режиме.'}</p>
            </div>
            <div style="background: var(--card-subtle); padding: 16px; border-radius: 12px; margin-bottom: 20px; font-size: 0.95em;">
                <p style="margin-bottom: 8px;"><strong>Номер экстренного талона:</strong> <code style="font-size: 1.05em; font-weight: bold;">${escapeHtml(ticketId)}</code></p>
                <p style="margin-bottom: 8px;"><strong>Статус:</strong> <span class="badge badge-cancelled" style="background: #dc2626; color: #fff;">ПРИОРИТЕТ 0 (АКТИВЕН)</span></p>
                <p style="margin-bottom: 0; line-height: 1.4;"><strong>Инструкция:</strong> Сохраняйте спокойствие. Не открывайте посторонним дверь. Ожидайте звонка координатора или прибытия дежурной службы РОЧС.</p>
            </div>
            <button type="button" class="btn btn-outline" style="width: 100%; font-size: 1.1em;" onclick="document.getElementById('sosConfirmationModal').style.display='none';">
                Понятно (Закрыть окно)
            </button>
        </div>
    `;
    modal.style.display = 'flex';
}

// =========================================================================
// M4: Coordinator SOS Incident Console
// =========================================================================

let lastCoordinatorSosSignature = '';

function formatSosTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleTimeString('ru-RU');
}

function sosSignalWord(count) {
    const n = Math.abs(count) % 100;
    const last = n % 10;
    if (n > 10 && n < 20) return 'сигналов';
    if (last === 1) return 'сигнал';
    if (last >= 2 && last <= 4) return 'сигнала';
    return 'сигналов';
}

async function loadCoordinatorSosAlerts() {
    const bannerContainer = document.getElementById('coordinatorSosBannerContainer');
    if (!bannerContainer) return;
    try {
        const res = await authFetch('/api/sos/active');
        const data = await res.json();
        if (!res.ok) {
            bannerContainer.style.display = 'block';
            bannerContainer.innerHTML = `<div class="alert-banner alert-banner-warning"><strong>${Icons.alert} Не удалось проверить активные SOS.</strong> Повторите попытку или обновите страницу.</div>`;
            return;
        }

        const alerts = data.alerts || data.data || [];
        if (alerts.length === 0) {
            lastCoordinatorSosSignature = '';
            bannerContainer.style.display = 'none';
            bannerContainer.innerHTML = '';
            return;
        }

        // One incident card per senior: repeated SOS presses are grouped, so the
        // coordinator resolves all signals from one person in a single action.
        const groups = new Map();
        for (const alert of alerts) {
            const key = String(alert.elderly_id ?? 'unknown');
            let group = groups.get(key);
            if (!group) {
                group = {
                    elderlyId: alert.elderly_id,
                    elderlyName: alert.elderly_name || ('ID #' + alert.elderly_id),
                    address: alert.address || 'Республика Беларусь',
                    count: 0,
                    latest: alert
                };
                groups.set(key, group);
            }
            group.count += 1;
            if (String(alert.created_at || '') > String(group.latest.created_at || '')) {
                group.latest = alert;
            }
        }

        const groupList = Array.from(groups.values()).sort(
            (a, b) => String(b.latest.created_at || '').localeCompare(String(a.latest.created_at || ''))
        );
        const signature = groupList.map(g => `${g.elderlyId}:${g.count}:${g.latest.id}`).join('|');

        bannerContainer.style.display = 'block';
        if (signature === lastCoordinatorSosSignature) return;
        lastCoordinatorSosSignature = signature;

        bannerContainer.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                <strong>Активных сигналов SOS: ${alerts.length}</strong>
                <span style="font-size: 0.9em; opacity: 0.9;">Подопечных с тревогой: ${groupList.length} · обновляется автоматически</span>
            </div>
            ${groupList.map(group => `
                <div class="sos-pulse-banner" style="background: #991b1b; color: #ffffff; padding: 16px 20px; border-radius: 12px; margin-bottom: 14px; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <div style="background: #ffffff; color: #991b1b; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <svg class="ui-icon ui-icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size: 1.15em; font-weight: 800; letter-spacing: 0.5px;">
                                ПРИОРИТЕТ 0: ЭКСТРЕННЫЙ СИГНАЛ SOS${group.count > 1 ? ` · ${group.count} ${sosSignalWord(group.count)}` : ''}
                            </div>
                            <div style="font-size: 0.95em; opacity: 0.95; margin-top: 2px;">
                                Подопечный: <strong>${escapeHtml(group.elderlyName)}</strong> |
                                Адрес: <strong>${escapeHtml(group.address)}</strong> |
                                Талон 112: <code>${escapeHtml(group.latest.emercom_ticket_id || 'Оповещен')}</code>
                            </div>
                            <div style="font-size: 0.85em; opacity: 0.9; margin-top: 4px;">
                                Последний сигнал: ${escapeHtml(formatSosTime(group.latest.created_at))}
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button type="button" class="btn btn-success btn-sm" style="background: #15803d; border-color: #15803d; font-weight: 700;" onclick="resolveSosElderly(${group.elderlyId}, ${group.count})">
                            ${Icons.check} Разрешить все (${group.count})
                        </button>
                    </div>
                </div>
            `).join('')}
        `;
    } catch (e) {
        console.error('Error loading active SOS alerts', e);
        if (bannerContainer) {
            bannerContainer.style.display = 'block';
            bannerContainer.innerHTML = `<div class="alert-banner alert-banner-warning"><strong>${Icons.alert} Ошибка связи при проверке SOS.</strong> Если вы видите этот экран, проверьте систему вручную.</div>`;
        }
    }
}
window.loadCoordinatorSosAlerts = loadCoordinatorSosAlerts;

async function resolveSosElderly(elderlyId, count) {
    const label = count > 1 ? `все ${count} ${sosSignalWord(count)}` : 'активный сигнал';
    if (!confirm(`Подтвердить принятие мер и разрешить ${label} SOS для этого подопечного?`)) return;
    try {
        const res = await authFetch(`/api/sos/elderly/${elderlyId}/resolve-all`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            alert(`Разрешено сигналов: ${data.resolved_count ?? 0}.`);
            lastCoordinatorSosSignature = '';
            loadCoordinatorSosAlerts();
            loadAdminAnalytics();
        } else {
            alert('Ошибка разрешения тревоги: ' + (data.error || 'Неизвестная ошибка'));
        }
    } catch (e) {
        console.error('Error resolving SOS alerts', e);
    }
}
window.resolveSosElderly = resolveSosElderly;

async function resolveSosAlert(id) {
    if (!confirm(`Подтвердить принятие мер и разрешить экстренную тревогу #${id}?`)) return;
    try {
        const res = await authFetch(`/api/sos/${id}/resolve`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            alert(`Экстренная тревога #${id} успешно разрешена.`);
            lastCoordinatorSosSignature = '';
            loadCoordinatorSosAlerts();
            loadAdminAnalytics();
        } else {
            alert('Ошибка разрешения тревоги: ' + (data.error || 'Неизвестная ошибка'));
        }
    } catch (e) {
        console.error('Error resolving SOS alert', e);
    }
}
window.resolveSosAlert = resolveSosAlert;

// =========================================================================
// M4: Coordinator Watchdog View & Task Reassignment
// =========================================================================
async function loadWatchdogTasks() {
    const tableBody = document.getElementById('watchdogTableBody');
    const badge = document.getElementById('overdueTasksCountBadge') || document.getElementById('overdueCountBadge');
    if (!tableBody) return;

    try {
        const res = await authFetch('/api/coordinator/watchdog');
        const data = await res.json();
        if (!res.ok) {
            tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger-color);">Не удалось загрузить список просроченных задач. Повторите попытку.</td></tr>`;
            return;
        }
        const tasks = data.overdue_tasks || data.data || [];

        if (badge) {
            badge.innerText = tasks.length;
            badge.style.display = tasks.length > 0 ? 'inline-block' : 'none';
        }

        if (tasks.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 24px; color: var(--accent-color);">${Icons.check} Все задания выполняются вовремя. Зависших задач нет.</td></tr>`;
            return;
        }

        tableBody.innerHTML = tasks.map(t => `
            <tr style="background: #fff1f2;">
                <td><strong>#${t.id}</strong></td>
                <td><span class="badge badge-warning">${escapeHtml(t.category_title || 'Задание')}</span></td>
                <td>
                    <div><strong>${escapeHtml(t.elderly_name || 'Подопечный #' + t.elderly_id)}</strong></div>
                    <div style="font-size: 0.85em; color: var(--text-muted);">${escapeHtml(t.address || '')}</div>
                </td>
                <td>
                    <div><strong>${escapeHtml(t.volunteer_name || 'Волонтёр #' + t.volunteer_id)}</strong></div>
                    <div style="font-size: 0.85em; color: var(--text-muted);">${escapeHtml(t.volunteer_phone || '')}</div>
                </td>
                <td>
                    <span style="color: var(--danger-color); font-weight: 800; font-size: 1.05em;">
                        ${t.elapsed_minutes || 0} мин
                    </span>
                    <div style="font-size: 0.8em; color: var(--text-muted);">норма: ${t.timeout_threshold_minutes || 60} мин (+${t.overdue_by_minutes || 0} мин)</div>
                </td>
                <td><span class="badge badge-cancelled">ПРОСРОЧЕНО</span></td>
                <td>
                    <button type="button" class="btn btn-outline btn-xs" style="color: var(--danger-color); border-color: var(--danger-color);" onclick="reassignOverdueTask(${t.id})">
                        ${Icons.cross} Переназначить
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Error loading watchdog tasks', e);
        tableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger-color);">Ошибка загрузки зависших задач</td></tr>`;
    }
}
window.loadWatchdogTasks = loadWatchdogTasks;

async function runWatchdogScan() {
    try {
        const res = await authFetch('/api/tasks/watchdog/run', { method: 'POST' });
        const data = await res.json();
        alert(`Проверка Watchdog завершена: проверено ${data.scanned_count || 0} задач, выявлено ${data.overdue_found || 0} просроченных.`);
        loadWatchdogTasks();
    } catch (e) {
        console.error('Watchdog manual trigger failed', e);
    }
}
window.runWatchdogScan = runWatchdogScan;

async function reassignOverdueTask(id) {
    if (!confirm(`Вернуть просроченную заявку #${id} в общую ленту района и отстранить текущего волонтёра?`)) return;
    try {
        const res = await authFetch(`/api/coordinator/requests/${id}/reassign`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            alert(`Заявка #${id} возвращена в статус открытой. Другие волонтёры района увидят её в общей ленте.`);
            loadWatchdogTasks();
            loadAdminRequests();
        } else {
            alert('Ошибка переназначения: ' + (data.error || 'Неизвестная ошибка'));
        }
    } catch (e) {
        console.error('Error reassigning task', e);
    }
}
window.reassignOverdueTask = reassignOverdueTask;

// =========================================================================
// M4: Volunteer Dynamic Digital QR Badge Modal
// =========================================================================
let badgeCountdownTimer = null;

async function showVolunteerBadge() {
    const modal = document.getElementById('volunteer-badge-modal');
    const content = document.getElementById('badgeActiveContent');
    const notice = document.getElementById('badgeUnverifiedNotice');
    const qrContainer = document.getElementById('badgeQrCardContainer');
    const btnPublic = document.getElementById('btnBadgePublicUrl');

    if (modal) modal.style.display = 'flex';
    if (notice) notice.style.display = 'none';
    if (content) content.style.display = 'block';
    if (qrContainer) qrContainer.innerHTML = '<p style="color: var(--text-muted); padding: 20px;">Загрузка цифрового бейджа...</p>';

    try {
        const res = await authFetch('/api/volunteers/my-badge');
        const data = await res.json();

        if (!res.ok || !data.success) {
            if (notice) {
                notice.style.display = 'block';
                notice.innerHTML = `<p><strong>Доступ ограничен:</strong> ${escapeHtml(data.error || 'Бейдж доступен только после завершения верификации ТЦСОН.')}</p>`;
            }
            if (content) content.style.display = 'none';
            return;
        }

        renderBadge(data);
    } catch (e) {
        console.error('Error fetching volunteer badge', e);
        if (qrContainer) {
            qrContainer.innerHTML = '<p style="color: var(--danger-color); padding: 20px;">Ошибка загрузки цифрового бейджа</p>';
        }
    }
}
window.showVolunteerBadge = showVolunteerBadge;

function renderBadge(badgeData) {
    const qrContainer = document.getElementById('badgeQrCardContainer');
    const btnPublic = document.getElementById('btnBadgePublicUrl');
    const expiresAt = badgeData.expires_at || (Math.floor(Date.now() / 1000) + 3600);
    const badgeNum = badgeData.badge_number || `BY-${new Date().getFullYear()}`;
    const qrSvg = badgeData.qr_svg || '';

    if (qrContainer) {
        qrContainer.innerHTML = `
            <div class="badge-card">
                <div style="font-size: 0.8em; font-weight: 700; color: var(--primary-color); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 4px;">
                    ТЦСОН Республики Беларусь
                </div>
                <div style="font-size: 1.15em; font-weight: 800; margin-bottom: 6px;">
                    ${escapeHtml(currentUser ? currentUser.name : 'Волонтёр')}
                </div>
                <span class="badge badge-success" style="font-size: 0.85em; margin-bottom: 14px; display: inline-flex; align-items: center; gap: 4px;">
                    ${Icons.check} Личность подтверждена
                </span>
                <div class="badge-qr-container" style="display: flex; justify-content: center; align-items: center; margin: 12px 0; min-height: 200px;">
                    ${qrSvg}
                </div>
                <div style="font-size: 0.85em; color: var(--text-muted); margin-bottom: 4px;">
                    Номер бейджа: <code style="font-weight: bold;">${escapeHtml(badgeNum)}</code>
                </div>
            </div>
        `;
    }

    if (btnPublic && badgeData.badge_token) {
        btnPublic.href = `/verify.php?token=${encodeURIComponent(badgeData.badge_token)}`;
        btnPublic.removeAttribute('aria-disabled');
        btnPublic.removeAttribute('onclick');
        btnPublic.style.opacity = '';
        btnPublic.style.pointerEvents = '';
    }

    startBadgeCountdown(expiresAt);
}
window.renderBadge = renderBadge;

function startBadgeCountdown(expiresAtTimestamp) {
    if (badgeCountdownTimer) {
        clearInterval(badgeCountdownTimer);
        badgeCountdownTimer = null;
    }

    const timerDisplay = document.getElementById('badgeCountdownTimer');
    const updateDisplay = () => {
        const now = Math.floor(Date.now() / 1000);
        const remaining = Math.max(0, expiresAtTimestamp - now);

        if (!timerDisplay) {
            clearInterval(badgeCountdownTimer);
            return;
        }

        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        timerDisplay.innerText = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;

        if (remaining <= 0) {
            clearInterval(badgeCountdownTimer);
            timerDisplay.innerText = '00:00 (Истёк)';
            timerDisplay.style.color = 'var(--danger-color)';
        }
    };

    updateDisplay();
    badgeCountdownTimer = setInterval(updateDisplay, 1000);
}

function closeVolunteerBadgeModal() {
    if (badgeCountdownTimer) {
        clearInterval(badgeCountdownTimer);
        badgeCountdownTimer = null;
    }
    const modal = document.getElementById('volunteer-badge-modal');
    if (modal) modal.style.display = 'none';
}
window.closeVolunteerBadgeModal = closeVolunteerBadgeModal;

// =========================================================================
// M4: Senior Anonymization / Right to be Forgotten (Law 99-Z / GDPR)
// =========================================================================
async function requestRightToBeForgotten() {
    const confirmation = confirm(
        'ВНИМАНИЕ! В соответствии с Законом РБ № 99-З «О защите персональных данных» вы собираетесь безвозвратно обезличить ваши персональные данные.\n\n' +
        'Ваш телефон, адрес и личные заметки будут удалены из системы. Это действие необратимо!\n\n' +
        'Вы подтверждаете выполнение процедуры обезличивания?'
    );

    if (!confirmation) return;

    try {
        const res = await authFetch('/api/privacy/anonymize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ confirm: true })
        });

        const data = await res.json();
        if (res.ok && data.success) {
            SpeechHelper.speak('Ваши персональные данные успешно обезличены в соответствии с законом Республики Беларусь. Сессия завершена.');
            alert('Ваши персональные данные успешно обезличены. Сеанс завершается.');
            clearTokens();
            currentUser = null;
            window.location.reload();
        } else {
            alert('Ошибка процедуры обезличивания: ' + (data.error || 'Обратитесь к координатору ТЦСОН'));
        }
    } catch (e) {
        console.error('Anonymization request failed', e);
        alert('Ошибка связи с сервером при попытке обезличивания');
    }
}
window.requestRightToBeForgotten = requestRightToBeForgotten;

