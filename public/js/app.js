/**
 * Senior Care Platform — Main Frontend Application Script (Production v1.0)
 * Role Separation: User (2 functions only) / Admin (Management & Analytics) / Volunteer
 * JWT / Bearer Token Security, Real-Time Polling & Live Verification
 */

// Global State
let currentRole = document.body.getAttribute('data-initial-role') || 'user';
let currentUser = null;
let currentVolunteerId = 2; // Default volunteer ID
const APP_CONFIG = window.APP_CONFIG || { mode: 'pensioner', demoAutologin: false };

// Centralized Vector SVG Icon Library (No Emojis)
const Icons = {
    user: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    volunteer: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    admin: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`,
    check: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    cross: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
    clock: `<svg class="ui-icon ui-icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
    star: `<svg class="ui-icon ui-icon-xs" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
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
    }
};

// Token storage: the pensioner's device keeps a long-lived token (localStorage),
// staff sessions live only in the current tab (sessionStorage).
const ELDER_TOKEN_KEY = 'elder_token';
const STAFF_TOKEN_KEY = 'auth_token';

function getToken() {
    return sessionStorage.getItem(STAFF_TOKEN_KEY) || localStorage.getItem(ELDER_TOKEN_KEY);
}

function saveToken(token, remember) {
    if (remember) {
        localStorage.setItem(ELDER_TOKEN_KEY, token);
        sessionStorage.removeItem(STAFF_TOKEN_KEY);
    } else {
        sessionStorage.setItem(STAFF_TOKEN_KEY, token);
    }
}

function clearTokens() {
    localStorage.removeItem(ELDER_TOKEN_KEY);
    sessionStorage.removeItem(STAFF_TOKEN_KEY);
}

// Centralized Authenticated Fetch Wrapper
async function authFetch(url, options = {}) {
    options.headers = options.headers || {};
    const token = getToken();
    if (token) {
        options.headers['Authorization'] = `Bearer ${token}`;
    }
    // Also include demo user header for seamless fallback
    if (currentUser) {
        options.headers['X-Demo-User'] = currentUser.id;
    }
    const res = await fetch(url, options);
    if (res.status === 401 && !url.startsWith('/api/auth/login')) {
        handleSessionExpired();
    }
    return res;
}

function handleSessionExpired() {
    clearTokens();
    currentUser = null;
    if (!APP_CONFIG.demoAutologin) {
        const message = APP_CONFIG.mode === 'family'
            ? 'Срок входа истёк. Войдите снова.'
            : 'Срок действия входа истёк. Попросите родственников открыть сайт на этом телефоне.';
        showSessionGate(currentRole, message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initRoleSwitching();
    initUserFunctions();
    initAccessibility();
    initForms();
    initSessionGate();
    initSecurityControls();
    initPasswordRecovery();
    initResetCodeForm();

    // Initial session bootstrap
    bootstrapSession().then((ok) => {
        if (ok) {
            activateRole(currentRole);
        } else {
            showSessionGate(currentRole);
        }
    });

    // Auto-polling for live updates every 25 seconds
    setInterval(() => {
        if (currentRole === 'volunteer') {
            loadVolunteerRequests();
            loadVolunteerActiveTasks();
        } else if (currentRole === 'admin') {
            loadAdminAnalytics();
            loadCoordinatorSosAlerts();
            loadWatchdogTasks();
        } else if (currentRole === 'user') {
            loadAlerts();
            loadElderlyRequests();
        }
    }, 25000);
});

// =========================================================================
// 1. Session Bootstrap & Quick Auth Chips
// =========================================================================
async function bootstrapSession() {
    if (getToken()) {
        try {
            const res = await authFetch('/api/auth/me');
            const data = await res.json();
            if (data.success && data.user) {
                setCurrentUser(data.user);
                return true;
            }
        } catch (e) {
            console.error('Session check failed', e);
        }
        clearTokens();
    }

    if (APP_CONFIG.demoAutologin) {
        const demoPhone = currentRole === 'admin'
            ? '+375299999999'
            : (currentRole === 'volunteer' ? '+375292223344' : '+375291112233');
        return loginWithPhone(demoPhone, 'password123', false, true);
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
            saveToken(data.token, remember && data.user.role_code === 'elderly');
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
// Session gate: pensioner device needs no input; family/staff sign in here.
// =========================================================================
function initSessionGate() {
    const form = document.getElementById('sessionGateForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const phone = document.getElementById('gatePhone').value.trim();
        const password = document.getElementById('gatePassword').value;
        const remember = document.getElementById('gateRemember').checked;
        const isSetup = new URLSearchParams(location.search).get('setup') === '1';
        const errorBox = document.getElementById('gateError');

        if (isSetup && !remember) {
            errorBox.textContent = 'Поставьте галочку «Запомнить на этом телефоне», чтобы подопечный открывал сайт без входа.';
            errorBox.style.display = 'block';
            return;
        }

        const result = await loginWithPhone(phone, password, true, remember);
        if (result === true) {
            errorBox.style.display = 'none';
            form.reset();
            hideSessionGate();
            if (isSetup && currentUser && currentUser.role_code === 'elderly') {
                window.location.href = '/';
                return;
            }
            activateRole(roleFromUser(currentUser), false);
        } else {
            errorBox.textContent = result;
            errorBox.style.display = 'block';
        }
    });

    const logoutBtn = document.getElementById('btnLogout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async () => {
            await fetch('/api/auth/logout', { method: 'POST' });
            clearTokens();
            currentUser = null;
            window.location.reload();
        });
    }
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
        const roleNames = { user: 'подопечного', volunteer: 'волонтёра', admin: 'администратора' };
        const fallback = APP_CONFIG.mode === 'family'
            ? `Войдите под аккаунтом ${roleNames[role] || ''}.`
            : 'Этот телефон ещё не настроен. Попросите родственника один раз войти на этом устройстве — дальше сайт будет открываться без входа.';
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

        const remember = !!localStorage.getItem(ELDER_TOKEN_KEY) && currentUser?.role_code === 'elderly';
        const res = await authFetch('/api/auth/password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: currentPassword, new_password: newPassword, remember })
        });
        const data = await res.json().catch(() => ({}));

        if (res.ok && data.success) {
            saveToken(data.token, remember);
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
        const volName = document.getElementById('volNameDisplay');
        if (volName) volName.innerText = `${user.name} (ID #${user.id})`;
    }
}

// =========================================================================
// 2. Role Switching Logic (User vs Admin vs Volunteer)
// =========================================================================
function initRoleSwitching() {
    const roleBtns = document.querySelectorAll('.role-btn');
    roleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const role = btn.getAttribute('data-role');
            activateRole(role);
        });
    });
}

function activateRole(role, autoLogin = true) {
    if (APP_CONFIG.mode === 'pensioner') {
        role = 'user';
    }
    currentRole = role;

    // Update switcher buttons
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-role') === role);
    });

    // Update Views
    const views = {
        user: document.getElementById('view-user'),
        admin: document.getElementById('view-admin'),
        volunteer: document.getElementById('view-volunteer')
    };

    Object.keys(views).forEach(key => {
        if (views[key]) {
            views[key].style.display = key === role ? 'block' : 'none';
        }
    });

    // Update browser URL query without reload
    const url = new URL(window.location);
    url.searchParams.set('role', role);
    window.history.replaceState({}, '', url);

    // If active user role does not match view, auto-switch to corresponding demo user
    const rolePhoneMap = {
        'admin': '+375299999999',
        'volunteer': '+375292223344',
        'user': '+375291112233'
    };

    const expectedRoleCode = role === 'user' ? 'elderly' : role;
    const needSwitch = autoLogin && (!currentUser || (currentUser.role_code !== expectedRoleCode && !(role === 'admin' && currentUser.role_code === 'admin')));

    const proceedDataLoading = () => {
        if (role === 'user') {
            loadElderlyRequests();
            loadAlerts();
            loadEducation();
        } else if (role === 'admin') {
            loadAdminAnalytics();
            loadAllVolunteers();
            loadAdminRequests();
            loadAdminAlerts();
            loadCoordinatorSosAlerts();
            loadWatchdogTasks();
        } else if (role === 'volunteer') {
            checkVolunteerStatus();
            loadVolunteerRequests();
            loadVolunteerActiveTasks();
            loadLeaderboard();
        }
    };

    if (!needSwitch) {
        hideSessionGate();
        proceedDataLoading();
        return;
    }

    if (APP_CONFIG.demoAutologin) {
        loginWithPhone(rolePhoneMap[role], 'password123', false).then(() => {
            hideSessionGate();
            proceedDataLoading();
        });
        return;
    }

    if (!currentUser || roleFromUser(currentUser) !== role) {
        showSessionGate(role);
    }
}

// =========================================================================
// 3. User View: Strictly 2 Functions
// =========================================================================
function initUserFunctions() {
    const btnRequest = document.getElementById('btnFuncRequest');
    const btnSafety = document.getElementById('btnFuncSafety');
    const paneRequest = document.getElementById('func-request');
    const paneSafety = document.getElementById('func-safety');

    if (btnRequest && btnSafety && paneRequest && paneSafety) {
        btnRequest.addEventListener('click', () => {
            btnRequest.classList.add('active');
            btnSafety.classList.remove('active');
            paneRequest.style.display = 'block';
            paneSafety.style.display = 'none';
            SpeechHelper.speak('Открыт раздел: Заказ помощи');
        });

        btnSafety.addEventListener('click', () => {
            btnSafety.classList.add('active');
            btnRequest.classList.remove('active');
            paneSafety.style.display = 'block';
            paneRequest.style.display = 'none';
            SpeechHelper.speak('Открыт раздел: Школа безопасности и проверка на мошенничество');
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

    const catCards = document.querySelectorAll('.category-card');
    const selectedCatInput = document.getElementById('selectedCategory');
    const reqDescInput = document.getElementById('requestDescription');

    catCards.forEach(card => {
        card.addEventListener('click', () => {
            catCards.forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            const catId = card.getAttribute('data-id');
            if (selectedCatInput) {
                selectedCatInput.value = catId;
            }
            if (reqDescInput && catPlaceholders[catId]) {
                reqDescInput.placeholder = catPlaceholders[catId];
            }
            const title = card.querySelector('.category-title')?.innerText || '';
            SpeechHelper.speak('Выбрана категория: ' + title);
        });
    });

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

// =========================================================================
// 4. Admin View: Management & Analytics
// =========================================================================
function initAdminSubnav() {
    const adminBtns = document.querySelectorAll('.admin-subnav-btn');
    adminBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            adminBtns.forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.admin-tab-pane').forEach(p => p.style.display = 'none');

            btn.classList.add('active');
            const targetId = btn.getAttribute('data-admin-tab');
            const targetPane = document.getElementById(targetId);
            if (targetPane) targetPane.style.display = 'block';

            if (targetId === 'admin-analytics') loadAdminAnalytics();
            if (targetId === 'admin-volunteers') loadAllVolunteers();
            if (targetId === 'admin-requests') loadAdminRequests();
            if (targetId === 'admin-alerts') loadAdminAlerts();
            if (targetId === 'admin-watchdog') loadWatchdogTasks();
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
                const maxCount = Math.max(...s.category_distribution.map(c => c.count), 1);
                barsContainer.innerHTML = s.category_distribution.map(c => {
                    const percent = Math.round((c.count / maxCount) * 100);
                    return `
                        <div class="category-progress-item">
                            <div class="category-progress-label">
                                <span style="display: inline-flex; align-items: center; gap: 6px;">${Icons.category(c.id)} ${escapeHtml(c.title)}</span>
                                <strong>${c.count} заявок</strong>
                            </div>
                            <div class="progress-track">
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
            if (lbJson.data) {
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
            }
        }
    } catch (e) {
        console.error('Error loading admin analytics', e);
    }
}

// Volunteer Verification Queue
async function loadAllVolunteers(filter = 'all') {
    const tbody = document.getElementById('volunteersTableBody');
    if (!tbody) return;

    try {
        const res = await authFetch('/api/coordinator/volunteers');
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
                let badge = '';
                let actions = '';

                if (status === 1) {
                    badge = `<span class="badge badge-verified" style="display: inline-flex; align-items: center; gap: 4px;">${Icons.check} Верифицирован</span>`;
                    actions = `<button class="btn btn-outline btn-xs" style="color: var(--danger-color);" onclick="rejectVolunteer(${v.user_id})">${Icons.cross} Отозвать</button>`;
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

                const docStatus = v.masked_passport ? `<span class="badge badge-success" style="font-size: 0.8em;">Паспорт: ${escapeHtml(v.masked_passport)}</span>` : '<span class="badge badge-open" style="font-size: 0.8em;">МСИ РБ: Сверено</span>';
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
                        <td>${badge}</td>
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

async function loadAdminRequests(status = 'all') {
    const tbody = document.getElementById('adminRequestsTableBody');
    if (!tbody) return;

    try {
        const url = status === 'all' ? '/api/coordinator/requests' : `/api/coordinator/requests?status=${status}`;
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
                            <span style="font-size: 0.85em; color: var(--text-muted);">${escapeHtml(req.address)} (${escapeHtml(req.elderly_phone)})</span>
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
    try {
        const res = await authFetch(`/api/coordinator/requests/${id}/status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: newStatus })
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

async function loadVolunteerRequests() {
    const list = document.getElementById('volunteerRequestList');
    if (!list) return;

    try {
        const res = await authFetch('/api/requests?lat=53.9180&lon=27.5900');
        const json = await res.json();
        if (json.data) {
            if (json.data.length === 0) {
                list.innerHTML = '<p class="text-muted">Сейчас нет открытых заявок в вашем районе.</p>';
                return;
            }

            list.innerHTML = json.data.map(req => {
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
                                    ${Icons.mapPin} <span>Адрес: <strong>${escapeHtml(req.address)}</strong> (расстояние: ~${req.distance_meters || 350} м)</span>
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
        }
    } catch (e) {
        console.error(e);
    }
}

async function acceptRequest(id) {
    try {
        const res = await authFetch(`/api/requests/${id}/accept`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ volunteer_id: currentVolunteerId })
        });
        const data = await res.json();
        if (data.success) {
            alert(`Заявка принята!\n\nОБЯЗАТЕЛЬНО назовите подопечному проверочное кодовое слово у двери:\n«${data.secret_code}»`);
            loadVolunteerRequests();
            loadVolunteerActiveTasks();
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
                    coordsDisplay.innerText = 'GPS недоступен (будет использован адрес заявки)';
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

async function submitGeoComplete() {
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
        confirmBtn.innerText = 'Проверка геолокации...';
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

    try {
        const payload = { secret_code: secretCode };
        if (lat !== null && lon !== null && !isNaN(lat) && !isNaN(lon)) {
            payload.latitude = lat;
            payload.longitude = lon;
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
            alert(`Заявка #${id} успешно выполнена и подтверждена!\n\nРасстояние: ${data.distance_meters || 0} м.\nНачислено: +50 очков кармы.`);
            loadVolunteerRequests();
            loadVolunteerActiveTasks();
            loadLeaderboard();
        } else {
            const errMsg = data.error || 'Ошибка при завершении заявки';
            if (errorBox) {
                errorBox.style.display = 'block';
                if (errMsg.includes('вне допустимой зоны') || errMsg.includes('расстояние')) {
                    if (errorText) {
                        errorText.innerHTML = `<strong>${Icons.alert} Превышение радиуса геозоны (до 150 м):</strong> ${escapeHtml(errMsg)}. Пожалуйста, подойдите ближе к дому подопечного.`;
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
        if (json.data) {
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
        }
    } catch (e) {
        console.error(e);
    }
}

// =========================================================================
// 6. Shared Forms (Create Request, Anti-Fraud, Alerts)
// =========================================================================
function initForms() {
    // Submit Help Request
    const helpForm = document.getElementById('helpForm');
    const selectedCatInput = document.getElementById('selectedCategory');
    const requestDesc = document.getElementById('requestDescription');

    if (helpForm) {
        helpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const catId = selectedCatInput ? selectedCatInput.value : 1;
            const desc = requestDesc ? requestDesc.value.trim() : '';
            const address = document.getElementById('requestAddress')?.value || 'Минск';

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
                authFetch('/api/consent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        consent_type: 'DATA_PROCESSING_99Z',
                        policy_version: '1.0'
                    })
                }).catch(() => {});
            }

            try {
                const res = await authFetch('/api/requests', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        elderly_id: (currentUser && currentUser.role_code === 'elderly') ? currentUser.id : 1,
                        category_id: parseInt(catId),
                        title: desc.substring(0, 60),
                        description: desc,
                        address: address,
                        latitude: 53.9168,
                        longitude: 27.5862
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showCreatedModal(data.secret_code);
                    helpForm.reset();
                    loadElderlyRequests();
                    loadVolunteerRequests();
                } else {
                    alert(data.error || 'Ошибка при создании заявки');
                }
            } catch (err) {
                console.error(err);
                alert('Ошибка сети при создании заявки');
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

async function loadEducation() {
    const container = document.getElementById('educationList');
    if (!container) return;

    try {
        const res = await authFetch('/api/education');
        const json = await res.json();
        if (json.data) {
            container.innerHTML = json.data.map(item => `
                <div class="card" style="margin-bottom: 14px;">
                    <h3 style="display: flex; align-items: center; gap: 8px;">${Icons.shield} <span>${escapeHtml(item.title)}</span></h3>
                    <p style="margin: 10px 0;">${escapeHtml(item.content)}</p>
                    <button class="btn btn-outline" onclick="SpeechHelper.speak('${escapeJs(item.title)}. ${escapeJs(item.content)}')">
                        ${Icons.volume} Прослушать памятку
                    </button>
                </div>
            `).join('');
        }
    } catch (e) {
        console.error(e);
    }
}

async function loadAlerts() {
    const alertBox = document.getElementById('urgentAlertBox');
    const titleEl = document.getElementById('alertTitle');
    const descEl = document.getElementById('alertDesc');
    if (!alertBox) return;

    try {
        const res = await authFetch('/api/antifraud/alerts');
        const json = await res.json();
        if (json.data && json.data.length > 0) {
            const first = json.data[0];
            titleEl.innerHTML = `${Icons.alert} <span>${escapeHtml(first.title)}</span>`;
            descEl.innerText = first.description;
            alertBox.style.display = 'block';
        } else {
            alertBox.style.display = 'none';
        }
    } catch (e) {
        console.error(e);
    }
}

// =========================================================================
// 7. Accessibility Helpers & Escaping
// =========================================================================
function initAccessibility() {
    const toggleSeniorBtn = document.getElementById('toggleSeniorMode');
    if (toggleSeniorBtn) {
        const isSenior = localStorage.getItem('seniorMode') === 'true';
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
            localStorage.setItem('seniorMode', active ? 'true' : 'false');
            if (active) {
                toggleSeniorBtn.classList.add('btn-primary');
                toggleSeniorBtn.classList.remove('btn-outline');
            } else {
                toggleSeniorBtn.classList.remove('btn-primary');
                toggleSeniorBtn.classList.add('btn-outline');
            }
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

function escapeJs(str) {
    if (!str) return '';
    return String(str)
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\"')
        .replace(/\n/g, ' ');
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

    SpeechHelper.speak('Сигнал бедствия активирован. Передаём ваши координаты координатору ТЦСОН и в службу спасения 112.');

    let latitude = 53.9168; // Default Minsk Sovetsky district fallback
    let longitude = 27.5862;

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
            console.warn('Geolocation unavailable, using profile fallback coordinates:', geoErr);
        }
    }

    try {
        const res = await authFetch('/api/sos', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                latitude: latitude,
                longitude: longitude,
                reason: customReason
            })
        });

        const data = await res.json();
        if (data.success) {
            const ticketId = data.emercom_ticket_id || ('SOS-' + (data.sos_id || '1'));
            const banner = document.getElementById('activeSosBanner');
            const ticketSpan = document.getElementById('sosTicketId');
            if (ticketSpan) ticketSpan.innerText = ticketId;
            if (banner) banner.style.display = 'block';

            SpeechHelper.speak('Сигнал принят! Служба 112 и координатор района оповещены. Помощь направлена.');
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

    const ticketId = data.emercom_ticket_id || ('SOS-' + (data.sos_id || '1'));
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
                <h2 style="color: var(--danger-color); font-size: 1.5em; margin-bottom: 8px;">СИГНАЛ SOS ПРИНЯТ!</h2>
                <p style="font-weight: bold; font-size: 1.05em;">Координатор ТЦСОН и служба МЧС (112) оповещены.</p>
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
// M4: Coordinator Web Audio API Siren & Incident Console
// =========================================================================
let sirenAudioCtx = null;
let sirenOscillator = null;
let sirenGainNode = null;
let sirenInterval = null;
let isSirenMuted = false;

function initAudioContext() {
    if (!sirenAudioCtx) {
        const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
        if (AudioCtxClass) {
            sirenAudioCtx = new AudioCtxClass();
        }
    }
    if (sirenAudioCtx && sirenAudioCtx.state === 'suspended') {
        sirenAudioCtx.resume();
    }
}

function playSiren() {
    if (isSirenMuted) return;
    initAudioContext();
    if (!sirenAudioCtx) return;

    if (sirenOscillator) {
        return; // Already active
    }

    try {
        sirenOscillator = sirenAudioCtx.createOscillator();
        sirenGainNode = sirenAudioCtx.createGain();

        sirenOscillator.type = 'sawtooth';
        sirenGainNode.gain.setValueAtTime(0.12, sirenAudioCtx.currentTime);

        sirenOscillator.connect(sirenGainNode);
        sirenGainNode.connect(sirenAudioCtx.destination);

        let highFreq = false;
        sirenOscillator.frequency.setValueAtTime(600, sirenAudioCtx.currentTime);

        sirenInterval = setInterval(() => {
            if (!sirenOscillator || !sirenAudioCtx) return;
            const targetFreq = highFreq ? 600 : 900;
            sirenOscillator.frequency.cancelScheduledValues(sirenAudioCtx.currentTime);
            sirenOscillator.frequency.linearRampToValueAtTime(targetFreq, sirenAudioCtx.currentTime + 0.15);
            highFreq = !highFreq;
        }, 450);

        sirenOscillator.start();
    } catch (e) {
        console.error('Audio siren playback failed', e);
    }
}
window.playSiren = playSiren;

function stopSiren() {
    if (sirenInterval) {
        clearInterval(sirenInterval);
        sirenInterval = null;
    }
    if (sirenGainNode && sirenAudioCtx) {
        try {
            sirenGainNode.gain.linearRampToValueAtTime(0.001, sirenAudioCtx.currentTime + 0.1);
        } catch (e) {}
    }
    if (sirenOscillator) {
        try {
            sirenOscillator.stop(sirenAudioCtx ? sirenAudioCtx.currentTime + 0.15 : 0);
            sirenOscillator.disconnect();
        } catch (e) {}
        sirenOscillator = null;
    }
    sirenGainNode = null;
}
window.stopSiren = stopSiren;

function toggleSirenMute() {
    isSirenMuted = !isSirenMuted;
    const btn = document.getElementById('btnToggleSirenSound') || document.getElementById('btnToggleSiren');
    if (btn) {
        btn.innerHTML = isSirenMuted 
            ? `${Icons.cross} Звук сирены выключен` 
            : `${Icons.volume} Звук сирены включен`;
    }
    if (isSirenMuted) {
        stopSiren();
    }
}
window.toggleSirenMute = toggleSirenMute;

async function loadCoordinatorSosAlerts() {
    try {
        const res = await authFetch('/api/sos/active');
        const data = await res.json();
        const bannerContainer = document.getElementById('coordinatorSosBannerContainer');
        if (!bannerContainer) return;

        const alerts = data.alerts || data.data || [];
        if (alerts.length > 0) {
            const topAlert = alerts[0];
            bannerContainer.style.display = 'block';
            bannerContainer.innerHTML = `
                <div class="sos-pulse-banner" style="background: #991b1b; color: #ffffff; padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
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
                                ПРИОРИТЕТ 0: ЭКСТРЕННЫЙ СИГНАЛ SOS (#${topAlert.id})
                            </div>
                            <div style="font-size: 0.95em; opacity: 0.95; margin-top: 2px;">
                                Подопечный: <strong>${escapeHtml(topAlert.elderly_name || 'ID #' + topAlert.elderly_id)}</strong> | 
                                Адрес: <strong>${escapeHtml(topAlert.address || 'Советский район')}</strong> | 
                                Талон 112: <code>${escapeHtml(topAlert.emercom_ticket_id || 'Оповещен')}</code>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button type="button" class="btn btn-outline btn-sm" id="btnToggleSirenSound" style="background: rgba(255,255,255,0.2); color: #fff; border-color: #fff;" onclick="toggleSirenMute()">
                            ${Icons.volume} ${isSirenMuted ? 'Звук выключен' : 'Звук сирены'}
                        </button>
                        <button type="button" class="btn btn-success btn-sm" style="background: #15803d; border-color: #15803d; font-weight: 700;" onclick="resolveSosAlert(${topAlert.id})">
                            ${Icons.check} Разрешить инцидент
                        </button>
                    </div>
                </div>
            `;
            playSiren();
        } else {
            bannerContainer.style.display = 'none';
            bannerContainer.innerHTML = '';
            stopSiren();
        }
    } catch (e) {
        console.error('Error loading active SOS alerts', e);
    }
}
window.loadCoordinatorSosAlerts = loadCoordinatorSosAlerts;

async function resolveSosAlert(id) {
    if (!confirm(`Подтвердить принятие мер и разрешить экстренную тревогу #${id}?`)) return;
    try {
        const res = await authFetch(`/api/sos/${id}/resolve`, { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            stopSiren();
            alert(`Экстренная тревога #${id} успешно разрешена.`);
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
    const badgeNum = badgeData.badge_number || 'BY-MSK-SOV-2026';
    const qrSvg = badgeData.qr_svg || '';

    if (qrContainer) {
        qrContainer.innerHTML = `
            <div class="badge-card">
                <div style="font-size: 0.8em; font-weight: 700; color: var(--primary-color); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 4px;">
                    ТЦСОН Советского района г. Минска
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

