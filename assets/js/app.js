'use strict';
/**
 * Tribal Weave Archive of the Philippines - client application.
 * Vanilla JS: hash routing (#/home, #/weave/12 ...), fetch API via api.js, no frontend "database".
 * Every dynamic value that reaches innerHTML goes through escapeHtml(); user data is never placed in
 * inline JS strings (dynamic markup uses data-* attributes + one delegated click handler).
 * Client-side guards are for UX only - the PHP API enforces all permissions.
 */

/* ======================================================================================
   STATE + SMALL HELPERS
   ====================================================================================== */
const state = { user: null };
let curRegFilter = 'All';
let curMedFilter = 'All';
let memberFilterStatus = 'ALL';
let memberRecords = [];
let lastWeaveOrigin = { view: 'weaves', tribeId: null };
let resetToken = '';
let galleryTimer = null;
let galleryRequest = 0;
let previewUrl = null;
let toastTimer = null;

const $ = (id) => document.getElementById(id);

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
const esc = escapeHtml;

function formatDate(value) {
    if (!value) return '-';
    let s = String(value);
    if (/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/.test(s)) s = s.replace(' ', 'T') + (s.length > 10 ? 'Z' : 'T00:00:00');
    const d = new Date(s);
    return isNaN(d) ? String(value) : d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function refreshIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
}

const loadingBlock = (msg = 'Loading...', cls = '') =>
    `<div class="${cls} py-12 flex items-center justify-center gap-3 text-xs text-[#6F472F]" role="status"><span class="spinner"></span><span>${esc(msg)}</span></div>`;
const emptyBlock = (msg, cls = '') =>
    `<div class="${cls} py-12 text-center text-xs text-[#6F472F] bg-[#FAF7F2] rounded-2xl border border-[#E5D5C5]">${esc(msg)}</div>`;
const errorBlock = (msg, cls = '') =>
    `<div class="${cls} py-10 px-4 text-center bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-800 space-y-3" role="alert"><p>${esc(msg)}</p><button type="button" data-action="retry" class="px-4 py-2 rounded-xl bg-[#8B5D3C] text-[#FAF7F2] font-semibold">Try again</button></div>`;

/** <img> with the SVG fallback wired through data-fallback (no inline JS). */
function imgTag(src, label, cls) {
    return `<img src="${esc(src)}" alt="${esc(label)}" data-fallback="${esc(label)}" loading="lazy" class="${cls}">`;
}

// Graceful SVG textile fallback if an image file is missing
function handleImgError(imgEl, label) {
    const safeLabel = String(label || 'Indigenous Weave').replace(/['"<>&]/g, '');
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 300" width="100%" height="100%">
        <rect width="300" height="300" fill="#F2EAE1"/>
        <path d="M0 60 H300 M0 120 H300 M0 180 H300 M0 240 H300" stroke="#E5D5C5" stroke-width="6"/>
        <polygon points="150,45 235,150 150,255 65,150" fill="none" stroke="#8B5D3C" stroke-width="8"/>
        <polygon points="150,85 200,150 150,215 100,150" fill="#A37350" opacity="0.25"/>
        <circle cx="150" cy="150" r="18" fill="#472D1F"/>
        <text x="150" y="282" font-family="Georgia, serif" font-size="13" font-weight="bold" fill="#472D1F" text-anchor="middle">${safeLabel}</text>
    </svg>`;
    imgEl.onerror = null;
    imgEl.src = 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}
document.addEventListener('error', (e) => {
    const t = e.target;
    if (t && t.tagName === 'IMG' && t.dataset && 'fallback' in t.dataset) handleImgError(t, t.dataset.fallback);
}, true);

/* ---- toast (success + error variants) ---- */
function showToast(msg, type = 'success') {
    const banner = $('toastBanner');
    $('toastMessage').textContent = msg;
    banner.classList.remove('hidden', 'toast-success', 'toast-error');
    banner.classList.add(type === 'error' ? 'toast-error' : 'toast-success');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(hideToast, type === 'error' ? 8000 : 5000);
}
function hideToast() { $('toastBanner').classList.add('hidden'); }

/* ---- inline message boxes + field-level errors ---- */
function showBox(id, msg, kind = 'error') {
    const b = $(id);
    if (!b) return;
    b.className = 'msg-box ' + (kind === 'success' ? 'msg-success' : 'msg-error');
    b.setAttribute('role', kind === 'success' ? 'status' : 'alert');
    b.textContent = msg;
}
function hideBox(id) {
    const b = $(id);
    if (b) { b.className = 'hidden'; b.textContent = ''; }
}
function clearFieldErrors(form) {
    if (!form) return;
    form.querySelectorAll('.field-error').forEach((n) => n.remove());
    form.querySelectorAll('.input-invalid').forEach((n) => { n.classList.remove('input-invalid'); n.removeAttribute('aria-invalid'); });
}
function showFieldErrors(errors, map) {
    Object.entries(errors || {}).forEach(([key, msg]) => {
        const el = map && $(map[key]);
        if (!el) return;
        el.classList.add('input-invalid');
        el.setAttribute('aria-invalid', 'true');
        const p = document.createElement('p');
        p.className = 'field-error';
        p.textContent = msg;
        (el.parentElement && el.parentElement.classList.contains('relative') ? el.parentElement : el).insertAdjacentElement('afterend', p);
    });
}
/** Shows client-side validation errors; returns true when the form is valid. */
function checkClientErrors(errors, map, form, boxId) {
    clearFieldErrors(form);
    if (!Object.keys(errors).length) return true;
    showFieldErrors(errors, map);
    if (boxId) showBox(boxId, 'Please fix the highlighted fields.');
    return false;
}
/** Central friendly error handling for failed API calls. */
function handleApiFailure(err, opts = {}) {
    if (!err || err.name === 'AbortError' || err.handled) return;
    const { box, map, form } = opts;
    if (form) clearFieldErrors(form);
    if (map && err.errors && Object.keys(err.errors).length) showFieldErrors(err.errors, map);
    if (box) showBox(box, err.message); else showToast(err.message, 'error');
}

/* ---- busy buttons: disabled while a request runs (prevents double submit) ---- */
function setBusy(btn, busy, text) {
    if (!btn) return;
    if (busy) {
        btn.dataset.label = btn.innerHTML;
        btn.disabled = true;
        btn.classList.add('is-busy');
        if (text) btn.textContent = text;
    } else {
        btn.disabled = false;
        btn.classList.remove('is-busy');
        if (btn.dataset.label !== undefined) { btn.innerHTML = btn.dataset.label; delete btn.dataset.label; refreshIcons(); }
    }
}
async function withBusy(btn, text, fn) {
    setBusy(btn, true, text);
    try { return await fn(); } finally { setBusy(btn, false); }
}

/* ---- client-side validation (mirrors the server rules) ---- */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const USERNAME_RE = /^[A-Za-z0-9_]{3,30}$/;
function passwordError(pw) {
    if (pw.length < 8 || !/[A-Za-z]/.test(pw) || !/\d/.test(pw)) return 'Password must be at least 8 characters and contain a letter and a number.';
    if (pw.length > 72) return 'Password must be at most 72 characters.';
    return null;
}

/* ---- misc UI ---- */
function toggleMobileMenu() { $('mobileNavDrawer').classList.toggle('hidden'); }
function closeMobileMenu() { $('mobileNavDrawer').classList.add('hidden'); }
function togglePass(inputId, iconId) {
    const input = $(inputId);
    const icon = $(iconId);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    if (icon) icon.setAttribute('data-lucide', show ? 'eye-off' : 'eye');
    refreshIcons();
}
function scrollToAbout() {
    const el = $('landing-about');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ======================================================================================
   HASH ROUTER   (#/  #/login  #/register  #/reset?token=  #/home  #/tribes  #/tribe/3
                  #/weaves  #/weave/12  #/profile  #/member  #/submit  #/submit/12  #/admin)
   ====================================================================================== */
const NAV_MAP = {
    home: 'home', weaves: 'weaves', 'weave-details': 'weaves', tribes: 'tribes', 'tribe-details': 'tribes',
    profile: 'profile', 'member-dashboard': 'profile', 'submit-record': 'profile', 'admin-dashboard': 'profile'
};

function hashFor(view, id) {
    const simple = {
        landing: '#/', login: '#/login', register: '#/register', home: '#/home', tribes: '#/tribes',
        weaves: '#/weaves', profile: '#/profile', 'member-dashboard': '#/member', 'admin-dashboard': '#/admin'
    };
    if (view === 'tribe-details') return `#/tribe/${id}`;
    if (view === 'weave-details') return `#/weave/${id}`;
    if (view === 'submit-record') return id ? `#/submit/${id}` : '#/submit';
    return simple[view] || '#/';
}

/** Kept under its original name so the static inline onclick handlers keep working. */
function navigateTo(view, id) {
    const target = hashFor(view, id);
    const current = location.hash || '#/';
    if (current === target || (target === '#/' && (current === '#' || current === ''))) handleRoute();
    else location.hash = target;
}
function redirect(hash) { location.replace(hash); }

function parseRoute() {
    const raw = (location.hash || '').replace(/^#/, '');
    const qi = raw.indexOf('?');
    const path = qi === -1 ? raw : raw.slice(0, qi);
    return { seg: path.split('/').filter(Boolean), query: new URLSearchParams(qi === -1 ? '' : raw.slice(qi + 1)) };
}

function showView(name) {
    closeMobileMenu();
    document.querySelectorAll('.view-section').forEach((s) => s.classList.add('hidden'));
    const isPublicEntry = ['landing', 'login', 'register', 'reset'].includes(name);
    $('landingHeader').classList.toggle('hidden', !isPublicEntry);
    $('mainNavHeader').classList.toggle('hidden', isPublicEntry);

    const active = NAV_MAP[name] || '';
    ['home', 'weaves', 'tribes', 'profile'].forEach((key) => {
        const desk = $('nav-' + key);
        const mob = $('mnav-' + key);
        if (desk) desk.classList.toggle('active', key === active);
        if (mob) {
            mob.className = key === active
                ? 'w-full text-left px-4 py-2.5 rounded-xl text-xs font-bold bg-[#8B5D3C] text-[#FAF7F2]'
                : 'w-full text-left px-4 py-2.5 rounded-xl text-xs font-bold text-[#472D1F]';
        }
    });
    const target = $('view-' + name);
    if (target) target.classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'auto' });
    refreshIcons();
}

function showNotFound() { showView('notfound'); }

function requireLoginRoute(message) {
    if (state.user) return true;
    showToast(message || 'Please log in to continue.', 'error');
    redirect('#/login');
    return false;
}

async function handleRoute() {
    const { seg, query } = parseRoute();
    const head = seg[0] || 'landing';
    const takesId = ['tribe', 'weave', 'submit'].includes(head);
    if (seg.length > (takesId ? 2 : 1)) return showNotFound();
    const id = /^\d+$/.test(seg[1] || '') ? Number(seg[1]) : null;

    switch (head) {
        case 'landing':
            showView('landing');
            return renderLandingPage();
        case 'login':
        case 'register':
            if (state.user) return redirect('#/home');
            showView(head);
            return head === 'login' ? resetLoginView() : resetRegisterView();
        case 'reset':
            showView('reset');
            return renderResetView(query.get('token') || '');
        case 'home':
            showView('home');
            return renderHomePage();
        case 'tribes':
            showView('tribes');
            return renderTribesPage();
        case 'tribe':
            if (id === null) return showNotFound();
            showView('tribe-details');
            return renderTribeDetails(id);
        case 'weaves':
            showView('weaves');
            return renderWeavesGallery();
        case 'weave':
            if (id === null) return showNotFound();
            showView('weave-details');
            return renderWeaveDetails(id);
        case 'profile':
            showView('profile');
            return renderProfilePage();
        case 'member':
            if (!requireLoginRoute('Please log in as a Member to submit or manage weaving records.')) return;
            showView('member-dashboard');
            return renderMemberDashboard();
        case 'submit':
            if (!requireLoginRoute('Please log in as a Member to submit or manage weaving records.')) return;
            if (seg.length === 2 && id === null) return showNotFound();
            showView('submit-record');
            return renderSubmitForm(id);
        case 'admin':
            if (!requireLoginRoute('Please log in as an Administrator to open the Admin Dashboard.')) return;
            if (state.user.role !== 'ADMIN') {
                showToast('Access Denied: Only Administrator accounts can access the Admin Dashboard.', 'error');
                return redirect('#/home');
            }
            showView('admin-dashboard');
            return renderAdminDashboard();
        default:
            return showNotFound();
    }
}

/* ======================================================================================
   SESSION + AUTHENTICATION UI
   ====================================================================================== */
async function refreshSession() {
    const { data } = await API.get('api/auth.php?action=me');
    state.user = data.user;
    API.setCsrf(data.csrf_token);
}

function onAuthenticated(data) {
    state.user = data.user;
    API.setCsrf(data.csrf_token);
}

function updateLandingAuthUI() {
    const user = state.user;
    $('landingAuthGuest').classList.toggle('hidden', !!user);
    const u = $('landingAuthUser');
    u.classList.toggle('hidden', !user);
    u.classList.toggle('flex', !!user);
    $('landingCtaButtons').innerHTML = user
        ? `<button type="button" data-action="nav" data-view="home" class="px-6 py-3 rounded-2xl bg-[#8B5D3C] hover:bg-[#6F472F] text-[#FAF7F2] text-xs sm:text-sm font-semibold transition shadow-sm">Go to Home</button>
           <button type="button" data-action="nav" data-view="profile" class="px-6 py-3 rounded-2xl bg-[#F2EAE1] hover:bg-[#E5D5C5] text-[#472D1F] text-xs sm:text-sm font-semibold transition">My Profile</button>`
        : `<button type="button" data-action="nav" data-view="login" class="px-6 py-3 rounded-2xl bg-[#8B5D3C] hover:bg-[#6F472F] text-[#FAF7F2] text-xs sm:text-sm font-semibold transition shadow-sm">Log In</button>
           <button type="button" data-action="nav" data-view="register" class="px-6 py-3 rounded-2xl bg-[#F2EAE1] hover:bg-[#E5D5C5] text-[#472D1F] text-xs sm:text-sm font-semibold transition">Sign Up</button>
           <button type="button" onclick="continueAsGuest()" class="px-6 py-3 rounded-2xl bg-white hover:bg-[#FAF7F2] text-[#6F472F] border border-[#E5D5C5] text-xs sm:text-sm font-semibold transition">Continue as Guest</button>`;
}

function resetLoginView() {
    hideBox('loginErrorBox');
    clearFieldErrors($('loginForm') || document);
    $('loginPassword').value = '';
    $('loginPassword').type = 'password';
    toggleForgotForm(false);
}
function resetRegisterView() {
    hideBox('regErrorBox');
    clearFieldErrors($('view-register'));
}

async function handleLoginSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const identifier = $('loginIdentifier').value.trim();
    const password = $('loginPassword').value;
    hideBox('loginErrorBox');
    if (!identifier || !password) {
        showBox('loginErrorBox', 'Enter your username or email and your password.');
        return;
    }
    await withBusy(form.querySelector('button[type="submit"]'), 'Logging in...', async () => {
        try {
            const { data } = await API.post('api/auth.php?action=login', { identifier, password, remember: $('loginRemember').checked });
            onAuthenticated(data);
            $('loginPassword').value = '';
            const isAdmin = data.user.role === 'ADMIN';
            showToast(`Welcome back, ${data.user.full_name}!` + (isAdmin ? ' Redirected to Admin Dashboard.' : ''));
            navigateTo(isAdmin ? 'admin-dashboard' : 'home');   // Admin -> dashboard, Member -> home
        } catch (err) {
            handleApiFailure(err, { box: 'loginErrorBox' });
        }
    });
}

const REG_MAP = { full_name: 'regFullName', username: 'regUsername', email: 'regEmail', password: 'regPassword', confirm: 'regConfirmPassword' };

async function handleRegisterSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const body = {
        full_name: $('regFullName').value.trim(),
        username: $('regUsername').value.trim(),
        email: $('regEmail').value.trim(),
        password: $('regPassword').value,
        confirm: $('regConfirmPassword').value
    };
    hideBox('regErrorBox');
    const errors = {};
    if (body.full_name.length < 2 || body.full_name.length > 100) errors.full_name = 'Enter your full name (2-100 characters).';
    if (!USERNAME_RE.test(body.username)) errors.username = 'Username must be 3-30 characters: letters, numbers and underscores only.';
    if (!EMAIL_RE.test(body.email)) errors.email = 'Enter a valid email address.';
    const pwErr = passwordError(body.password);
    if (pwErr) errors.password = pwErr;
    if (body.password !== body.confirm) errors.confirm = 'Passwords do not match.';
    if (!checkClientErrors(errors, REG_MAP, form, 'regErrorBox')) return;

    await withBusy(form.querySelector('button[type="submit"]'), 'Creating account...', async () => {
        try {
            const { data } = await API.post('api/auth.php?action=register', body);
            onAuthenticated(data);
            form.reset();
            showToast(`Member account created! Welcome, ${data.user.full_name}.`);
            navigateTo('home');
        } catch (err) {
            handleApiFailure(err, { box: 'regErrorBox', map: REG_MAP, form });
        }
    });
}

/** Ends the session on the server (data + cookie) and fetches a fresh anonymous session/CSRF token. */
async function doLogout() {
    try {
        await API.post('api/auth.php?action=logout');
    } catch (err) {
        if (err.status !== 401) { handleApiFailure(err); return false; }
    }
    state.user = null;
    try { await refreshSession(); } catch (_) { API.setCsrf(''); }
    return true;
}

async function confirmLogout() {
    if (!window.confirm('Are you sure you want to log out of the Tribal Weave Archive?')) return;
    if (await doLogout()) {
        showToast('You have been safely logged out.');
        navigateTo('landing');
    }
}

/** Explicit "Continue as Guest": signs out a signed-in user, then opens the public archive. */
async function continueAsGuest() {
    if (state.user && !(await doLogout())) return;
    navigateTo('home');
}

/* ---- forgot password (inline on the login page) ---- */
function toggleForgotForm(force) {
    const panel = $('forgotPanel');
    if (!panel) return;
    const show = typeof force === 'boolean' ? force : panel.classList.contains('hidden');
    panel.classList.toggle('hidden', !show);
    if (show) {
        const typed = $('loginIdentifier').value.trim();
        if (EMAIL_RE.test(typed)) $('forgotEmail').value = typed;
        hideBox('forgotMsgBox');
        $('forgotEmail').focus();
    }
}

async function handleForgotSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const email = $('forgotEmail').value.trim();
    clearFieldErrors(form);
    if (!EMAIL_RE.test(email)) {
        showBox('forgotMsgBox', 'Enter a valid email address.');
        return;
    }
    await withBusy(form.querySelector('button[type="submit"]'), 'Sending...', async () => {
        try {
            const { message } = await API.post('api/auth.php?action=forgot', { email });
            showBox('forgotMsgBox', message, 'success');
        } catch (err) {
            handleApiFailure(err, { box: 'forgotMsgBox' });
        }
    });
}

/* ---- set a new password (link from the simulated e-mail) ---- */
async function renderResetView(token) {
    resetToken = token;
    const form = $('resetForm');
    form.reset();
    clearFieldErrors(form);
    form.classList.add('hidden');
    if (!token) {
        showBox('resetStatusBox', 'This reset link is incomplete. Please request a new link from the login page.');
        return;
    }
    showBox('resetStatusBox', 'Checking your reset link...', 'success');
    try {
        await API.get('api/auth.php?action=reset_check&token=' + encodeURIComponent(token));
        hideBox('resetStatusBox');
        form.classList.remove('hidden');
    } catch (err) {
        handleApiFailure(err, { box: 'resetStatusBox' });
    }
}

const RESET_MAP = { password: 'resetPassword', confirm: 'resetConfirm' };

async function handleResetSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const password = $('resetPassword').value;
    const confirm = $('resetConfirm').value;
    const errors = {};
    const pwErr = passwordError(password);
    if (pwErr) errors.password = pwErr;
    if (password !== confirm) errors.confirm = 'Passwords do not match.';
    if (!checkClientErrors(errors, RESET_MAP, form)) return;

    await withBusy(form.querySelector('button[type="submit"]'), 'Updating...', async () => {
        try {
            const { data, message } = await API.post('api/auth.php?action=reset', { token: resetToken, password, confirm });
            API.setCsrf(data.csrf_token);
            resetToken = '';
            form.reset();
            showToast(message);
            navigateTo('login');
        } catch (err) {
            handleApiFailure(err, { box: 'resetStatusBox', map: RESET_MAP, form });
        }
    });
}

/* ---- edit profile + change password (Profile page) ---- */
const PROFILE_MAP = { full_name: 'editFullName', email: 'editEmail' };
const PASSWORD_MAP = { current_password: 'curPassword', new_password: 'newPassword', confirm_password: 'newPassword2' };

async function handleProfileUpdate(e) {
    e.preventDefault();
    const form = e.target;
    const body = { full_name: $('editFullName').value.trim(), email: $('editEmail').value.trim() };
    hideBox('profileEditBox');
    const errors = {};
    if (body.full_name.length < 2 || body.full_name.length > 100) errors.full_name = 'Enter your full name (2-100 characters).';
    if (!EMAIL_RE.test(body.email)) errors.email = 'Enter a valid email address.';
    if (!checkClientErrors(errors, PROFILE_MAP, form, 'profileEditBox')) return;

    await withBusy(form.querySelector('button[type="submit"]'), 'Saving...', async () => {
        try {
            const { data, message } = await API.post('api/auth.php?action=update_profile', body);
            state.user = data.user;
            showToast(message);
            renderProfileInfo();
        } catch (err) {
            handleApiFailure(err, { box: 'profileEditBox', map: PROFILE_MAP, form });
        }
    });
}

async function handleChangePassword(e) {
    e.preventDefault();
    const form = e.target;
    const body = { current_password: $('curPassword').value, new_password: $('newPassword').value, confirm_password: $('newPassword2').value };
    hideBox('passwordBox');
    const errors = {};
    if (!body.current_password) errors.current_password = 'Enter your current password.';
    const pwErr = passwordError(body.new_password);
    if (pwErr) errors.new_password = pwErr;
    if (body.new_password !== body.confirm_password) errors.confirm_password = 'Passwords do not match.';
    if (!checkClientErrors(errors, PASSWORD_MAP, form, 'passwordBox')) return;

    await withBusy(form.querySelector('button[type="submit"]'), 'Updating...', async () => {
        try {
            const { data, message } = await API.post('api/auth.php?action=change_password', body);
            API.setCsrf(data.csrf_token);
            form.reset();
            showBox('passwordBox', message, 'success');
            showToast(message);
        } catch (err) {
            handleApiFailure(err, { box: 'passwordBox', map: PASSWORD_MAP, form });
        }
    });
}

/* ======================================================================================
   SHARED MARKUP PIECES
   ====================================================================================== */
const statusBadge = (s) => `<span class="status-badge status-${esc(s)}">${esc(s)}</span>`;

function openAttrs(w, from, tribeId) {
    return `data-action="open-weave" data-id="${Number(w.weave_id)}" data-from="${esc(from)}" data-tribe="${tribeId ? Number(tribeId) : ''}" role="button" tabindex="0"`;
}
const tile = (label, value, extra = '') =>
    `<div class="p-4 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] ${extra}"><span class="block text-[11px] font-bold uppercase tracking-wider text-[#A37350]">${esc(label)}</span><span class="text-xs sm:text-sm font-semibold text-[#472D1F] mt-1 block">${esc(value)}</span></div>`;
const textPanel = (title, text) =>
    `<div class="p-5 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] space-y-1.5"><h4 class="text-xs font-bold uppercase tracking-wider text-[#472D1F]">${esc(title)}</h4><p class="text-xs sm:text-sm text-[#6F472F] leading-relaxed">${esc(text)}</p></div>`;
const canModify = (w) => !!state.user && w.submitted_by === state.user.user_id && w.status !== 'APPROVED';

function weaveCardsForTribe(list, tribeId) {
    return list.map((w) => `
        <div ${openAttrs(w, 'tribe', tribeId)} class="heritage-card heritage-card-hover overflow-hidden flex flex-col justify-between cursor-pointer group">
            <div>
                <div class="h-52 bg-[#F2EAE1] p-5 flex items-center justify-center border-b border-[#E5D5C5]">
                    ${imgTag(w.image, w.name, 'w-40 h-40 object-cover rounded-2xl shadow-sm group-hover:scale-105 transition')}
                </div>
                <div class="p-6 space-y-2">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-[#8B5D3C]">${esc(w.medium)} &bull; ${esc(w.region)}</span>
                    <h4 class="text-lg font-bold text-[#472D1F] font-serif">${esc(w.name)}</h4>
                    <p class="text-xs text-[#6F472F] leading-relaxed">${esc(w.culture)}</p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-[#E5D5C5]/60 flex items-center justify-between text-xs font-semibold text-[#8B5D3C]">
                <span>Inspect Weave Details</span><i data-lucide="chevron-right" class="w-4 h-4"></i>
            </div>
        </div>`).join('');
}

/* ======================================================================================
   PUBLIC VIEWS: LANDING, HOME, TRIBES, TRIBE DETAILS, WEAVES, WEAVE DETAILS
   ====================================================================================== */
async function renderLandingPage() {
    updateLandingAuthUI();
    const grid = $('landingFeaturedGrid');
    grid.innerHTML = loadingBlock('Loading featured specimens...', 'col-span-full');
    try {
        const { data } = await API.get('api/weaves.php?action=list&limit=4');
        $('landingStatWeaves').textContent = data.total;
        grid.innerHTML = data.weaves.length ? data.weaves.map((w) => `
            <div ${openAttrs(w, 'weaves')} class="heritage-card heritage-card-hover overflow-hidden flex flex-col justify-between cursor-pointer group">
                <div>
                    <div class="h-48 bg-[#F2EAE1] p-4 flex items-center justify-center border-b border-[#E5D5C5] relative">
                        <span class="absolute top-3 left-3 px-2.5 py-1 rounded-lg text-[10px] font-semibold bg-white/95 text-[#472D1F]">${esc(w.region)}</span>
                        ${imgTag(w.image, w.name, 'w-36 h-36 object-cover rounded-2xl shadow-sm group-hover:scale-105 transition')}
                    </div>
                    <div class="p-5 space-y-1.5">
                        <p class="text-xs font-bold text-[#8B5D3C]">${esc(w.tribe)}</p>
                        <h4 class="text-base font-bold text-[#472D1F] font-serif">${esc(w.name)}</h4>
                        <p class="text-xs text-[#6F472F] line-clamp-2">${esc(w.culture)}</p>
                    </div>
                </div>
                <div class="px-5 py-3 border-t border-[#E5D5C5]/60 flex items-center justify-between text-xs text-[#8B5D3C] font-semibold">
                    <span>${esc(w.medium)}</span><span>Inspect &rarr;</span>
                </div>
            </div>`).join('') : emptyBlock('No approved weaves have been published yet.', 'col-span-full');
    } catch (err) {
        grid.innerHTML = errorBlock(err.message, 'col-span-full');
    }
    refreshIcons();
}

async function renderHomePage() {
    const role = state.user ? state.user.role : 'GUEST';
    const name = state.user ? state.user.full_name : 'Guest Visitor';
    $('homeWelcomeBadge').textContent = `Welcome, ${name} (${role})`;
    const actions = $('homeRoleActions');
    if (role === 'ADMIN') {
        actions.innerHTML = `<button type="button" data-action="nav" data-view="admin-dashboard" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#472D1F] text-[#FAF7F2] text-xs font-semibold hover:bg-[#6F472F] transition"><i data-lucide="shield" class="w-3.5 h-3.5"></i><span>Open Admin Dashboard</span></button>`;
    } else if (role === 'MEMBER') {
        actions.innerHTML = `<button type="button" data-action="nav" data-view="member-dashboard" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-[#F2EAE1] text-[#472D1F] text-xs font-semibold hover:bg-[#E5D5C5] transition"><span>Member Dashboard</span></button>
            <button type="button" data-action="new-record" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-[#8B5D3C] text-[#FAF7F2] text-xs font-semibold hover:bg-[#6F472F] transition"><i data-lucide="plus-circle" class="w-3.5 h-3.5"></i><span>Submit Record</span></button>`;
    } else {
        actions.innerHTML = `<button type="button" data-action="nav" data-view="login" class="px-3.5 py-1.5 rounded-xl bg-[#8B5D3C] text-[#FAF7F2] text-xs font-semibold">Log In / Contribute</button>`;
    }

    const grid = $('homeFeaturedTribesGrid');
    grid.innerHTML = loadingBlock('Loading featured tribes...', 'col-span-full');
    try {
        const { data } = await API.get('api/tribes.php?action=list');
        grid.innerHTML = data.tribes.map((t) => `
            <div data-action="open-tribe" data-id="${Number(t.tribe_id)}" role="button" tabindex="0" class="heritage-card heritage-card-hover overflow-hidden flex flex-col justify-between cursor-pointer group">
                <div>
                    <div class="h-52 bg-[#F2EAE1] p-5 flex items-center justify-center border-b border-[#E5D5C5]">
                        ${imgTag(t.image, t.tribe_name, 'w-40 h-40 object-cover rounded-2xl shadow-sm group-hover:scale-105 transition duration-300')}
                    </div>
                    <div class="p-6 space-y-2.5">
                        <h4 class="text-xl font-bold text-[#472D1F] font-serif">${esc(t.tribe_name)}</h4>
                        <p class="text-xs font-medium text-[#8B5D3C] flex items-center gap-1"><i data-lucide="map-pin" class="w-3.5 h-3.5 shrink-0"></i><span>${esc(t.location)}</span></p>
                        <p class="text-xs text-[#6F472F] leading-relaxed">${esc(t.description)}</p>
                    </div>
                </div>
                <div class="p-6 pt-0">
                    <span class="w-full inline-flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-[#8B5D3C] group-hover:bg-[#6F472F] text-[#FAF7F2] text-xs font-semibold transition"><span>Explore Tribe</span><i data-lucide="chevron-right" class="w-4 h-4"></i></span>
                </div>
            </div>`).join('') || emptyBlock('No featured tribes found.', 'col-span-full');
    } catch (err) {
        grid.innerHTML = errorBlock(err.message, 'col-span-full');
    }
    refreshIcons();
}

async function renderTribesPage() {
    const grid = $('tribesPageGrid');
    grid.innerHTML = loadingBlock('Loading tribes...', 'col-span-full');
    try {
        const { data } = await API.get('api/tribes.php?action=list');
        grid.innerHTML = data.tribes.map((t) => `
            <div data-action="open-tribe" data-id="${Number(t.tribe_id)}" role="button" tabindex="0" class="heritage-card heritage-card-hover p-6 sm:p-7 flex flex-col sm:flex-row gap-6 items-center cursor-pointer group">
                <div class="w-36 h-36 sm:w-44 sm:h-44 rounded-2xl bg-[#F2EAE1] p-3 border border-[#E5D5C5] shrink-0 flex items-center justify-center overflow-hidden">
                    ${imgTag(t.image, t.tribe_name, 'w-full h-full object-cover rounded-xl group-hover:scale-105 transition duration-300')}
                </div>
                <div class="space-y-2.5 flex-1">
                    <span class="px-2.5 py-0.5 rounded-lg bg-[#F2EAE1] text-[#8B5D3C] text-[11px] font-semibold">${t.weave_count} Associated Weave${t.weave_count === 1 ? '' : 's'}</span>
                    <h3 class="text-2xl font-bold text-[#472D1F] font-serif">${esc(t.tribe_name)}</h3>
                    <p class="text-xs font-medium text-[#8B5D3C] flex items-center gap-1"><i data-lucide="map-pin" class="w-3.5 h-3.5"></i><span>${esc(t.location)}</span></p>
                    <p class="text-xs text-[#6F472F] leading-relaxed">${esc(t.description)}</p>
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-[#8B5D3C] group-hover:text-[#472D1F] pt-1"><span>Explore Tribe Details</span><i data-lucide="arrow-right" class="w-3.5 h-3.5"></i></span>
                </div>
            </div>`).join('') || emptyBlock('No tribes found.', 'col-span-full');
    } catch (err) {
        grid.innerHTML = errorBlock(err.message, 'col-span-full');
    }
    refreshIcons();
}

function openTribeDetails(tribeId) { navigateTo('tribe-details', tribeId); }

async function renderTribeDetails(tribeId) {
    const secA = $('tribeDetailSectionA');
    const grid = $('tribeDetailWeavesGrid');
    secA.innerHTML = loadingBlock('Loading tribe...');
    grid.innerHTML = loadingBlock('Loading weaves...', 'col-span-full');
    $('tribeWeavesCountBadge').textContent = '';
    try {
        const { data } = await API.get('api/tribes.php?action=get&id=' + tribeId);
        const t = data.tribe;
        const info = (title, text) => `<div class="p-5 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] space-y-2"><h4 class="text-sm font-bold text-[#472D1F] font-serif uppercase tracking-wider">${esc(title)}</h4><p class="text-xs text-[#6F472F] leading-relaxed">${esc(text || 'Not yet documented.')}</p></div>`;
        secA.innerHTML = `
            <div class="border-b border-[#E5D5C5] pb-4 flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs font-bold uppercase tracking-widest text-[#8B5D3C]">Section A &bull; About the Tribe</span>
                <span class="text-xs font-medium text-[#6F472F] flex items-center gap-1"><i data-lucide="map-pin" class="w-3.5 h-3.5 text-[#8B5D3C]"></i>${esc(t.location)}</span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                <div class="lg:col-span-4 bg-[#F2EAE1] rounded-3xl p-6 border border-[#E5D5C5] flex flex-col items-center text-center space-y-3">
                    ${imgTag(t.image, t.tribe_name, 'w-56 h-56 object-cover rounded-2xl shadow-md')}
                    <div><h2 class="text-2xl font-bold text-[#472D1F] font-serif">${esc(t.tribe_name)}</h2><p class="text-xs text-[#6F472F]">${esc(t.location)}</p></div>
                </div>
                <div class="lg:col-span-8 grid grid-cols-1 sm:grid-cols-2 gap-5">
                    ${info('Brief History', t.history)}${info('Cultural Background', t.cultural_background)}
                    ${info('Traditional Practices', t.traditional_practices)}${info('Cultural Significance', t.cultural_significance)}
                </div>
            </div>`;
        $('tribeWeavesHeading').textContent = `${t.tribe_name} Traditional Weaves`;
        $('tribeWeavesCountBadge').textContent = `${data.weaves.length} Weave${data.weaves.length === 1 ? '' : 's'}`;
        grid.innerHTML = data.weaves.length
            ? weaveCardsForTribe(data.weaves, t.tribe_id)
            : emptyBlock('No approved weaves currently linked to this tribe.', 'col-span-full');
    } catch (err) {
        secA.innerHTML = errorBlock(err.status === 404 ? 'That tribe could not be found.' : err.message);
        grid.innerHTML = '';
    }
    refreshIcons();
}

/* ---- weaves gallery: server-side search + filters ---- */
function setRegionFilter(reg) {
    curRegFilter = reg;
    document.querySelectorAll('.reg-btn').forEach((b) => {
        b.className = b.dataset.region === reg
            ? 'reg-btn px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-[#8B5D3C] text-[#FAF7F2] shadow-sm'
            : 'reg-btn px-3.5 py-1.5 rounded-xl text-xs font-medium transition bg-white border border-[#E5D5C5] text-[#472D1F] hover:bg-[#F2EAE1]';
    });
    renderWeavesGallery();
}
function setMediumFilter(med) {
    curMedFilter = med;
    document.querySelectorAll('.med-btn').forEach((b) => {
        b.className = b.dataset.med === med
            ? 'med-btn px-3.5 py-1.5 rounded-xl text-xs font-semibold transition bg-[#472D1F] text-[#FAF7F2] shadow-sm'
            : 'med-btn px-3.5 py-1.5 rounded-xl text-xs font-medium transition bg-white border border-[#E5D5C5] text-[#472D1F] hover:bg-[#F2EAE1]';
    });
    renderWeavesGallery();
}
function debouncedGallery() {
    clearTimeout(galleryTimer);
    galleryTimer = setTimeout(renderWeavesGallery, 300);
}

async function renderWeavesGallery() {
    const seq = ++galleryRequest;   // ignore answers that arrive after a newer request
    const grid = $('weaveGrid');
    const noRes = $('noResults');
    const params = new URLSearchParams({ action: 'list' });
    const q = $('searchInput').value.trim();
    if (q) params.set('q', q);
    if (curRegFilter !== 'All') params.set('region', curRegFilter);
    if (curMedFilter !== 'All') params.set('medium', curMedFilter);
    if (!grid.querySelector('.masonry-item')) grid.innerHTML = loadingBlock('Loading weaves...');
    try {
        const { data } = await API.get('api/weaves.php?' + params.toString());
        if (seq !== galleryRequest) return;
        $('entryCount').textContent = `${data.total} ${data.total === 1 ? 'Entry' : 'Entries'} Shown`;
        if (!data.weaves.length) {
            grid.innerHTML = '';
            noRes.classList.remove('hidden');
            return;
        }
        noRes.classList.add('hidden');
        grid.innerHTML = data.weaves.map((item) => {
            let badge = 'bg-amber-50 text-amber-800 border-amber-200';
            if (item.vuln.includes('Critical')) badge = 'bg-rose-50 text-rose-800 border-rose-200';
            else if (item.vuln.includes('Sustained')) badge = 'bg-emerald-50 text-emerald-800 border-emerald-200';
            return `
            <div ${openAttrs(item, 'weaves')} class="masonry-item heritage-card heritage-card-hover overflow-hidden flex flex-col justify-between cursor-pointer group">
                <div>
                    <div class="bg-[#F2EAE1] relative p-6 flex items-center justify-center border-b border-[#E5D5C5] overflow-hidden">
                        <span class="absolute top-3 left-3 px-2.5 py-1 rounded-lg text-[10px] font-semibold bg-white/95 text-[#472D1F] uppercase tracking-wider">${esc(item.region)}</span>
                        <span class="absolute top-3 right-3 px-2.5 py-1 rounded-full text-[10px] font-semibold border ${badge}">${esc(item.vuln)}</span>
                        <div class="w-36 h-36 my-3 rounded-2xl overflow-hidden transition-transform duration-300 group-hover:scale-105 flex items-center justify-center">
                            ${imgTag(item.image, item.name, 'w-full h-full object-cover')}
                        </div>
                    </div>
                    <div class="p-5 space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-[#8B5D3C] tracking-wide">${esc(item.tribe)}</p>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-[#FAF7F2] border border-[#E5D5C5] text-[#6F472F]">${esc(item.medium)}</span>
                        </div>
                        <h4 class="text-base font-bold text-[#472D1F] font-serif leading-snug">${esc(item.name)}</h4>
                        <p class="text-xs text-[#6F472F] leading-relaxed">${esc(item.culture)}</p>
                    </div>
                </div>
                <div class="px-5 py-3.5 flex items-center justify-between border-t border-[#E5D5C5]/60 bg-[#FAF7F2]/50">
                    <span class="text-[11px] text-[#6F472F] truncate max-w-[145px]"><i data-lucide="map-pin" class="w-3 h-3 inline mr-1 text-[#8B5D3C]"></i>${esc(item.loc)}</span>
                    <span class="px-3 py-1 rounded-xl bg-[#F2EAE1] group-hover:bg-[#8B5D3C] group-hover:text-[#FAF7F2] text-[#472D1F] text-xs font-semibold transition inline-flex items-center gap-1"><span>Inspect</span><i data-lucide="chevron-right" class="w-3.5 h-3.5"></i></span>
                </div>
            </div>`;
        }).join('');
    } catch (err) {
        if (seq !== galleryRequest) return;
        noRes.classList.add('hidden');
        grid.innerHTML = errorBlock(err.message);
    }
    refreshIcons();
}

/* ---- individual weave details ---- */
function openWeaveDetails(weaveId, fromView = 'weaves', tribeId = null) {
    lastWeaveOrigin = { view: fromView, tribeId };
    navigateTo('weave-details', weaveId);
}

function goBackFromWeave() {
    const o = lastWeaveOrigin;
    if (o.view === 'tribe' && o.tribeId) openTribeDetails(o.tribeId);
    else if (o.view === 'member-dashboard') navigateTo('member-dashboard');
    else if (o.view === 'admin-dashboard') navigateTo('admin-dashboard');
    else if (o.view === 'profile') navigateTo('profile');
    else navigateTo('weaves');
}

async function renderWeaveDetails(weaveId) {
    const box = $('weaveDetailsContainer');
    const labels = { member: 'Back to Member Dashboard', admin: 'Back to Admin Dashboard', profile: 'Back to Profile', tribe: 'Back to Tribe' };
    const key = { 'member-dashboard': 'member', 'admin-dashboard': 'admin', profile: 'profile', tribe: 'tribe' }[lastWeaveOrigin.view];
    $('weaveDetailsBackLabel').textContent = labels[key] || 'Back to Weaves';
    $('weaveDetailsBackBtn').onclick = goBackFromWeave;
    $('weaveDetailsCodeBadge').textContent = '';
    box.innerHTML = loadingBlock('Loading weave details...');
    try {
        const { data } = await API.get('api/weaves.php?action=get&id=' + weaveId);
        const w = data.weave;
        if (key === 'tribe') $('weaveDetailsBackLabel').textContent = `Back to Tribe (${w.tribe})`;
        $('weaveDetailsCodeBadge').textContent = `Catalog ID: ${w.id}`;
        box.innerHTML = `
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-10 items-start">
                <div class="lg:col-span-5 space-y-4">
                    <div class="bg-[#F2EAE1] rounded-3xl p-6 sm:p-8 border border-[#E5D5C5] flex items-center justify-center">
                        ${imgTag(w.image, w.name, 'w-full max-h-[420px] object-cover rounded-2xl shadow-md')}
                    </div>
                    <div class="p-4 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] flex items-center justify-between text-xs">
                        <span class="font-semibold text-[#6F472F]">Vulnerability Status:</span><span class="font-bold text-[#8B5D3C]">${esc(w.vuln)}</span>
                    </div>
                </div>
                <div class="lg:col-span-7 space-y-6">
                    <div class="space-y-2 border-b border-[#E5D5C5] pb-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-3 py-1 rounded-lg bg-[#8B5D3C] text-[#FAF7F2] text-xs font-semibold">${esc(w.region)}</span>
                            <span class="px-3 py-1 rounded-lg bg-[#F2EAE1] text-[#472D1F] border border-[#E5D5C5] text-xs font-semibold">${esc(w.medium)}</span>
                            ${statusBadge(w.status)}
                        </div>
                        <h2 class="text-2xl sm:text-4xl font-bold text-[#472D1F] font-serif pt-1">${esc(w.name)}</h2>
                        <p class="text-sm font-semibold text-[#8B5D3C]">Associated Tribe: ${esc(w.tribe)}</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        ${tile('Geographic Location', w.loc)}${tile('Craft Medium', w.medium)}
                        ${tile('Materials and Fibers', w.fiber)}${tile('Natural Dyes', w.dyes)}
                        ${tile('Loom or Weaving Technique', w.loom, 'sm:col-span-2')}
                    </div>
                    <div class="space-y-4">
                        ${textPanel('Cultural Significance', w.culture)}
                        ${textPanel('Motifs and Patterns', w.motifs || 'Not yet documented.')}
                    </div>
                    <div class="p-5 rounded-2xl bg-emerald-50/90 border border-emerald-200 space-y-2 text-xs text-emerald-950">
                        <div class="flex items-center gap-2 font-bold text-emerald-900"><i data-lucide="file-check-2" class="w-4 h-4"></i><span>Available Documentation &amp; Community FPIC Verification</span></div>
                        <p><strong>Documentation Source:</strong> ${esc(w.docSource || 'NCIP Ethnographic Field Archive')}</p>
                        <p><strong>Community Consent / FPIC:</strong> ${esc(w.fpic)}</p>
                    </div>
                </div>
            </div>`;
    } catch (err) {
        box.innerHTML = errorBlock(err.status === 404 ? 'This weaving record could not be found, or it is not published yet.' : err.message);
    }
    refreshIcons();
}

/* ======================================================================================
   PROFILE + MEMBER DASHBOARD
   ====================================================================================== */
function renderProfileInfo() {
    const role = state.user ? state.user.role : 'GUEST';
    const btns = $('profileRoleActionBtns');
    if (role === 'ADMIN') {
        btns.innerHTML = `<button type="button" data-action="nav" data-view="admin-dashboard" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#472D1F] text-[#FAF7F2] text-xs font-semibold"><i data-lucide="layout-dashboard" class="w-4 h-4"></i><span>Admin Dashboard</span></button>`;
    } else if (role === 'MEMBER') {
        btns.innerHTML = `<button type="button" data-action="nav" data-view="member-dashboard" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#F2EAE1] text-[#472D1F] text-xs font-semibold"><span>Member Dashboard</span></button>
            <button type="button" data-action="new-record" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#8B5D3C] text-[#FAF7F2] text-xs font-semibold"><i data-lucide="plus-circle" class="w-4 h-4"></i><span>Submit New Record</span></button>`;
    } else {
        btns.innerHTML = `<button type="button" data-action="nav" data-view="login" class="px-4 py-2.5 rounded-xl bg-[#8B5D3C] text-[#FAF7F2] text-xs font-semibold">Log In</button>`;
    }
    // LOGOUT only makes sense for signed-in users
    document.querySelector('#view-profile button[onclick="confirmLogout()"]').classList.toggle('hidden', !state.user);

    const info = $('profileUserInfoContent');
    const u = state.user;
    if (u) {
        const field = (label, value) => `<div class="p-4 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5]"><span class="block text-[11px] font-bold uppercase tracking-wider text-[#A37350]">${esc(label)}</span><span class="text-sm font-semibold text-[#472D1F] mt-1 block break-words">${esc(value)}</span></div>`;
        info.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                <div class="md:col-span-3 flex flex-col items-center text-center p-5 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5]">
                    <div class="w-20 h-20 rounded-full bg-[#8B5D3C] text-[#FAF7F2] flex items-center justify-center text-3xl font-bold font-serif shadow-sm">${esc(u.full_name.charAt(0).toUpperCase())}</div>
                    <h3 class="text-base font-bold text-[#472D1F] font-serif mt-3">${esc(u.full_name)}</h3>
                    <span class="mt-1 px-3 py-0.5 rounded-full bg-[#F2EAE1] text-[#8B5D3C] text-[11px] font-bold">${esc(u.role)}</span>
                </div>
                <div class="md:col-span-9 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    ${field('Full Name', u.full_name)}${field('Username', '@' + u.username)}
                    ${field('Email Address', u.email)}${field('Date Joined', formatDate(u.created_at))}
                </div>
            </div>`;
    } else {
        info.innerHTML = `
            <div class="p-6 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-[#E5D5C5] text-[#472D1F] flex items-center justify-center text-2xl font-serif font-bold">G</div>
                <div><h3 class="text-lg font-bold text-[#472D1F] font-serif">Guest Explorer</h3><p class="text-xs text-[#6F472F]">Account Role: GUEST &bull; Public Read-Only Access</p></div>
            </div>`;
    }
    $('profileAccountForms').classList.toggle('hidden', !u);
    if (u) {
        $('editFullName').value = u.full_name;
        $('editEmail').value = u.email;
    }
    refreshIcons();
}

async function renderProfilePage() {
    renderProfileInfo();
    hideBox('profileEditBox');
    hideBox('passwordBox');
    const list = $('profileContributionsList');
    if (!state.user) {
        $('profileContributionCount').textContent = '';
        list.innerHTML = emptyBlock('Log in to see and manage your contributions.');
        return;
    }
    list.innerHTML = loadingBlock('Loading your contributions...');
    try {
        const { data } = await API.get('api/weaves.php?action=list&mine=1&sort=newest');
        const mine = data.weaves;
        $('profileContributionCount').textContent = `${mine.length} Record${mine.length === 1 ? '' : 's'}`;
        list.innerHTML = mine.length ? `<div class="grid grid-cols-1 md:grid-cols-2 gap-5">${mine.map((c) => `
            <div class="p-4 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] flex gap-4 items-start">
                ${imgTag(c.image, c.name, 'w-20 h-20 object-cover rounded-xl border border-[#E5D5C5] shrink-0 bg-[#F2EAE1]')}
                <div class="flex-1 space-y-1.5 min-w-0">
                    <div class="flex items-center justify-between">${statusBadge(c.status)}<span class="text-[11px] text-[#6F472F]">${esc(formatDate(c.created_at))}</span></div>
                    <h4 class="text-base font-bold text-[#472D1F] font-serif">${esc(c.name)}</h4>
                    <p class="text-xs font-semibold text-[#8B5D3C]">Tribe: ${esc(c.tribe)}</p>
                    ${c.admin_feedback ? `<div class="p-2 rounded-lg bg-amber-50 border border-amber-200 text-[11px] text-amber-900"><strong>Admin Feedback:</strong> ${esc(c.admin_feedback)}</div>` : ''}
                    <div class="pt-1 flex flex-wrap gap-3 text-xs font-semibold">
                        <button type="button" data-action="open-weave" data-id="${Number(c.weave_id)}" data-from="profile" class="text-[#8B5D3C] hover:underline">View</button>
                        ${canModify(c) ? `<button type="button" data-action="edit-weave" data-id="${Number(c.weave_id)}" class="text-[#472D1F] hover:underline">${c.status === 'REJECTED' ? 'Revise &amp; Resubmit' : 'Edit'}</button>
                        <button type="button" data-action="delete-weave" data-id="${Number(c.weave_id)}" class="text-rose-700 hover:underline">Delete</button>` : ''}
                    </div>
                </div>
            </div>`).join('')}</div>`
            : emptyBlock("You haven't contributed any weaving records yet.");
    } catch (err) {
        list.innerHTML = errorBlock(err.message);
    }
    refreshIcons();
}

function setMemberStatusFilter(status) {
    memberFilterStatus = status;
    paintMemberDashboard();
}

async function renderMemberDashboard() {
    const tbody = $('memberSubmissionsTableBody');
    $('memberStatCards').innerHTML = '';
    tbody.innerHTML = `<tr><td colspan="6">${loadingBlock('Loading your submissions...')}</td></tr>`;
    try {
        const { data } = await API.get('api/weaves.php?action=list&mine=1&sort=newest');
        memberRecords = data.weaves;
        paintMemberDashboard();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6">${errorBlock(err.message)}</td></tr>`;
    }
}

function paintMemberDashboard() {
    const recs = memberRecords;
    const count = (s) => recs.filter((w) => w.status === s).length;
    const cards = [
        ['ALL', 'My Submissions', 'text-[#6F472F]', 'text-[#472D1F]', recs.length],
        ['APPROVED', 'Approved Records', 'text-emerald-700', 'text-emerald-800', count('APPROVED')],
        ['PENDING', 'Pending Approval', 'text-amber-700', 'text-amber-800', count('PENDING')],
        ['REJECTED', 'Rejected Submissions', 'text-rose-700', 'text-rose-800', count('REJECTED')]
    ];
    $('memberStatCards').innerHTML = cards.map(([key, label, c1, c2, n]) => `
        <button type="button" data-action="member-filter" data-status="${key}" class="heritage-card p-5 text-left ${memberFilterStatus === key ? 'ring-2 ring-[#8B5D3C]' : ''}">
            <span class="text-xs font-semibold ${c1}">${label}</span>
            <div class="text-2xl font-bold ${c2} font-serif mt-1">${n}</div>
        </button>`).join('');

    const shown = memberFilterStatus === 'ALL' ? recs : recs.filter((w) => w.status === memberFilterStatus);
    $('memberSubmissionsTableBody').innerHTML = shown.length ? shown.map((r) => `
        <tr class="hover:bg-[#FAF7F2]">
            <td class="py-4 px-5"><div class="flex items-center gap-3">
                ${imgTag(r.image, r.name, 'w-12 h-12 rounded-xl object-cover border border-[#E5D5C5]')}
                <div><div class="font-bold text-[#472D1F] text-sm font-serif">${esc(r.name)}</div><div class="text-[11px] text-[#6F472F] font-mono">${esc(r.id)}</div></div>
            </div></td>
            <td class="py-4 px-5"><div class="font-semibold text-[#8B5D3C]">${esc(r.tribe)}</div><div class="text-[#6F472F]">${esc(r.region)}</div></td>
            <td class="py-4 px-5 font-medium">${esc(r.medium)}</td>
            <td class="py-4 px-5">${statusBadge(r.status)}</td>
            <td class="py-4 px-5 max-w-xs text-[#6F472F]">${r.admin_feedback ? esc(r.admin_feedback) : '<span class="italic text-stone-400">Awaiting review / None</span>'}</td>
            <td class="py-4 px-5 text-right whitespace-nowrap space-x-2">
                <button type="button" data-action="open-weave" data-id="${Number(r.weave_id)}" data-from="member-dashboard" class="px-3 py-1.5 rounded-lg bg-[#F2EAE1] text-[#472D1F] font-semibold">View</button>
                ${canModify(r) ? `<button type="button" data-action="edit-weave" data-id="${Number(r.weave_id)}" class="px-3 py-1.5 rounded-lg bg-[#8B5D3C] text-[#FAF7F2] font-semibold">${r.status === 'REJECTED' ? 'Revise &amp; Resubmit' : 'Edit'}</button>
                <button type="button" data-action="delete-weave" data-id="${Number(r.weave_id)}" class="px-3 py-1.5 rounded-lg bg-rose-100 text-rose-800 font-semibold hover:bg-rose-200">Delete</button>` : ''}
            </td>
        </tr>`).join('') : `<tr><td colspan="6" class="py-10 text-center text-[#6F472F]">No submissions in this category yet.</td></tr>`;
}

/* ======================================================================================
   SUBMIT / EDIT RECORD FORM  (multipart upload through the fetch API)
   ====================================================================================== */
const SUBMIT_FIELDS = {
    tribe: 'subTribe', name: 'subWeaveName', region: 'subRegion', medium: 'subMedium', loc: 'subLocation', vuln: 'subStatusVuln',
    fiber: 'subFiber', dyes: 'subDyes', loom: 'subLoom', culture: 'subCulture', motifs: 'subMotifs',
    docSource: 'subDocSource', fpic: 'subFpic', image: 'subImageFile'
};

function openSubmitRecordForm(editWeaveId = null) { navigateTo('submit-record', editWeaveId); }

function returnFromSubmitForm() {
    navigateTo(state.user && state.user.role === 'ADMIN' ? 'admin-dashboard' : 'member-dashboard');
}

function clearImagePreview() {
    if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; }
    $('subImagePreviewWrap').classList.add('hidden');
    $('subImagePreview').removeAttribute('src');
}
function showImagePreview(src, note) {
    $('subImagePreview').src = src;
    $('subImageNote').textContent = note;
    $('subImagePreviewWrap').classList.remove('hidden');
}
function onImageChosen() {
    const file = $('subImageFile').files[0];
    const form = $('recordForm');
    form.querySelectorAll('[data-for="image"]').forEach((n) => n.remove());
    if (!file) { clearImagePreview(); return; }
    const okType = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type);
    if (!okType || file.size > 3 * 1024 * 1024) {
        $('subImageFile').value = '';
        clearImagePreview();
        showToast(!okType ? 'Only JPG, PNG, WEBP or GIF images are allowed.' : 'The image is larger than 3 MB.', 'error');
        return;
    }
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = URL.createObjectURL(file);
    showImagePreview(previewUrl, 'New image selected: ' + file.name);
}

async function renderSubmitForm(editId) {
    const form = $('recordForm');
    form.reset();
    clearFieldErrors(form);
    hideBox('submitErrorBox');
    clearImagePreview();
    $('subEditId').value = '';
    const isAdmin = state.user && state.user.role === 'ADMIN';
    $('submitRoleNote').innerHTML = isAdmin
        ? 'Records added or edited by an Admin are published to the archive immediately (status <strong>APPROVED</strong>).'
        : 'Member submissions are saved with <strong>PENDING</strong> status and must be reviewed and approved by an Admin before appearing in the public archive.';
    $('submitFormHeading').textContent = editId ? 'Edit Weaving Record' : (isAdmin ? 'Add Archival Record' : 'Submit New Weaving Record');

    // Tribe suggestions come from the database
    API.get('api/tribes.php?action=list&all=1').then(({ data }) => {
        $('tribeOptionsList').innerHTML = data.tribes.map((t) => `<option value="${esc(t.tribe_name)}">`).join('');
    }).catch(() => { /* suggestions are optional */ });

    if (!editId) return;
    form.classList.add('opacity-60');
    try {
        const { data } = await API.get('api/weaves.php?action=get&id=' + editId);
        const w = data.weave;
        if (!isAdmin && (w.submitted_by !== state.user.user_id || w.status === 'APPROVED')) {
            showToast('You can only edit your own pending or rejected submissions.', 'error');
            return redirect('#/member');
        }
        $('submitFormHeading').textContent = w.status === 'REJECTED' && !isAdmin ? 'Revise & Resubmit Weaving Record' : 'Edit Weaving Record';
        $('subEditId').value = w.weave_id;
        Object.entries(SUBMIT_FIELDS).forEach(([key, id]) => { if (key !== 'image') $(id).value = w[key] ?? ''; });
        showImagePreview(w.image, 'Current image (choose a file to replace it)');
    } catch (err) {
        showToast(err.message, 'error');
        redirect(isAdmin ? '#/admin' : '#/member');
    } finally {
        form.classList.remove('opacity-60');
    }
}

async function handleRecordSubmission(e) {
    e.preventDefault();
    const form = e.target;
    clearFieldErrors(form);
    hideBox('submitErrorBox');
    const editId = $('subEditId').value;
    const fd = new FormData();
    Object.entries(SUBMIT_FIELDS).forEach(([key, id]) => { if (key !== 'image') fd.append(key, $(id).value.trim()); });
    const file = $('subImageFile').files[0];
    if (file) fd.append('image', file);

    await withBusy(form.querySelector('button[type="submit"]'), 'Saving...', async () => {
        try {
            const url = editId ? `api/weaves.php?action=update&id=${encodeURIComponent(editId)}` : 'api/weaves.php?action=create';
            const { message } = await API.postForm(url, fd);
            showToast(message);
            returnFromSubmitForm();
        } catch (err) {
            handleApiFailure(err, { box: 'submitErrorBox', map: SUBMIT_FIELDS, form });
            const box = $('submitErrorBox');
            if (box && box.scrollIntoView) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
}

/* ---- delete (member: own pending/rejected, admin: any) ---- */
async function deleteWeave(weaveId, btn) {
    if (!window.confirm('Are you sure you want to permanently delete this weaving record?')) return;
    await withBusy(btn, 'Deleting...', async () => {
        try {
            const { message } = await API.post('api/weaves.php?action=delete&id=' + weaveId);
            showToast(message);
            await handleRoute();   // refresh whichever list the user is looking at
        } catch (err) {
            handleApiFailure(err);
        }
    });
}

/* ======================================================================================
   ADMIN DASHBOARD
   ====================================================================================== */
async function renderAdminDashboard() {
    $('adminOverviewStats').innerHTML = loadingBlock('Loading overview...', 'col-span-full');
    $('adminPendingList').innerHTML = loadingBlock('Loading pending submissions...');
    $('adminPublishedTableBody').innerHTML = `<tr><td colspan="4">${loadingBlock('Loading published records...')}</td></tr>`;
    $('adminMembersTableBody').innerHTML = `<tr><td colspan="5">${loadingBlock('Loading members...')}</td></tr>`;
    try {
        const [stats, pending, published, members] = await Promise.all([
            API.get('api/admin.php?action=stats'),
            API.get('api/weaves.php?action=list&status=PENDING&sort=newest'),
            API.get('api/weaves.php?action=list&status=APPROVED&sort=newest'),
            API.get('api/admin.php?action=members')
        ]);
        const s = stats.data;
        $('adminOverviewStats').innerHTML = `
            <div class="heritage-card p-5"><span class="text-[11px] font-bold uppercase tracking-wider text-[#6F472F]">Total Weaving Records</span><div class="text-2xl font-bold text-[#472D1F] font-serif mt-1">${s.total}</div></div>
            <div class="heritage-card p-5 border-amber-300 bg-amber-50/40"><span class="text-[11px] font-bold uppercase tracking-wider text-amber-800">Pending Submissions</span><div class="text-2xl font-bold text-amber-900 font-serif mt-1">${s.pending}</div></div>
            <div class="heritage-card p-5 border-emerald-300 bg-emerald-50/40"><span class="text-[11px] font-bold uppercase tracking-wider text-emerald-800">Approved Records</span><div class="text-2xl font-bold text-emerald-900 font-serif mt-1">${s.approved}</div></div>
            <div class="heritage-card p-5 border-rose-300 bg-rose-50/40"><span class="text-[11px] font-bold uppercase tracking-wider text-rose-800">Rejected Submissions</span><div class="text-2xl font-bold text-rose-900 font-serif mt-1">${s.rejected}</div></div>
            <div class="heritage-card p-5"><span class="text-[11px] font-bold uppercase tracking-wider text-[#8B5D3C]">Registered Members</span><div class="text-2xl font-bold text-[#8B5D3C] font-serif mt-1">${s.members}</div></div>`;

        $('adminPendingBadge').textContent = `${s.pending} Pending`;
        const pend = pending.data.weaves;
        $('adminPendingList').innerHTML = pend.length ? pend.map((p) => `
            <div class="p-6 rounded-2xl bg-[#FAF7F2] border border-[#E5D5C5] grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <div class="lg:col-span-3 flex flex-col items-center text-center space-y-2">
                    ${imgTag(p.image, p.name, 'w-36 h-36 object-cover rounded-2xl border border-[#E5D5C5] bg-[#F2EAE1]')}
                    <span class="text-[11px] font-mono text-[#6F472F]">${esc(p.id)}</span>
                    <span class="text-[11px] font-semibold text-[#8B5D3C]">Submitted by: ${esc(p.submitter_name || 'Member')}</span>
                </div>
                <div class="lg:col-span-5 space-y-2 text-xs">
                    <div class="flex gap-2"><span class="px-2.5 py-0.5 rounded bg-[#8B5D3C] text-white font-semibold">${esc(p.region)}</span><span class="px-2.5 py-0.5 rounded bg-[#F2EAE1] text-[#472D1F] font-semibold">${esc(p.medium)}</span></div>
                    <h4 class="text-lg font-bold text-[#472D1F] font-serif">${esc(p.name)}</h4>
                    <p><strong>Tribe:</strong> ${esc(p.tribe)} &bull; <strong>Location:</strong> ${esc(p.loc)}</p>
                    <p><strong>Fibers &amp; Dyes:</strong> ${esc(p.fiber)} | ${esc(p.dyes)}</p>
                    <p><strong>Cultural Significance:</strong> ${esc(p.culture)}</p>
                    ${p.motifs ? `<p><strong>Motifs:</strong> ${esc(p.motifs)}</p>` : ''}
                    <div class="p-3 rounded-xl bg-white border border-[#E5D5C5] space-y-1">
                        <p><strong>Documentation Source:</strong> ${esc(p.docSource || 'Field Submission')}</p>
                        <p><strong>FPIC Consent:</strong> ${esc(p.fpic)}</p>
                    </div>
                </div>
                <div class="lg:col-span-4 space-y-3 bg-white p-4 rounded-2xl border border-[#E5D5C5]">
                    <button type="button" data-action="approve-weave" data-id="${Number(p.weave_id)}" class="w-full py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold transition">Approve &amp; Publish to Archive</button>
                    <div class="space-y-2 pt-2 border-t border-[#E5D5C5]">
                        <label for="rejectFeedback_${Number(p.weave_id)}" class="block text-[11px] font-bold text-[#472D1F]">Rejection Feedback (required):</label>
                        <textarea id="rejectFeedback_${Number(p.weave_id)}" rows="2" maxlength="1000" placeholder="Explain required revisions or FPIC verification needed..." class="w-full p-2.5 rounded-xl bg-[#FAF7F2] border border-[#E5D5C5] text-xs"></textarea>
                        <button type="button" data-action="reject-weave" data-id="${Number(p.weave_id)}" class="w-full py-2 rounded-xl bg-rose-700 hover:bg-rose-800 text-white text-xs font-semibold transition">Reject Submission</button>
                    </div>
                </div>
            </div>`).join('') : emptyBlock('No pending member submissions require review right now.');

        $('adminPublishedTableBody').innerHTML = published.data.weaves.map((pub) => `
            <tr class="hover:bg-[#FAF7F2]">
                <td class="py-3.5 px-5"><div class="flex items-center gap-3">
                    ${imgTag(pub.image, pub.name, 'w-10 h-10 rounded-lg object-cover border border-[#E5D5C5]')}
                    <div><div class="font-bold text-[#472D1F] font-serif">${esc(pub.name)}</div><div class="text-[11px] text-[#6F472F] font-mono">${esc(pub.id)}</div></div>
                </div></td>
                <td class="py-3.5 px-5 font-semibold text-[#8B5D3C]">${esc(pub.tribe)}</td>
                <td class="py-3.5 px-5 text-[#6F472F]">${esc(pub.region)} &bull; ${esc(pub.medium)}</td>
                <td class="py-3.5 px-5 text-right whitespace-nowrap space-x-2">
                    <button type="button" data-action="open-weave" data-id="${Number(pub.weave_id)}" data-from="admin-dashboard" class="px-3 py-1.5 rounded-lg bg-[#F2EAE1] text-[#472D1F] font-semibold">View</button>
                    <button type="button" data-action="edit-weave" data-id="${Number(pub.weave_id)}" class="px-3 py-1.5 rounded-lg bg-[#8B5D3C] text-[#FAF7F2] font-semibold">Edit</button>
                    <button type="button" data-action="delete-weave" data-id="${Number(pub.weave_id)}" class="px-3 py-1.5 rounded-lg bg-rose-100 text-rose-800 font-semibold hover:bg-rose-200">Delete</button>
                </td>
            </tr>`).join('') || `<tr><td colspan="4" class="py-8 text-center text-[#6F472F]">No published records.</td></tr>`;

        $('adminMembersTableBody').innerHTML = members.data.members.map((m) => `
            <tr class="hover:bg-[#FAF7F2]">
                <td class="py-3.5 px-5 font-bold text-[#472D1F]">${esc(m.full_name)}</td>
                <td class="py-3.5 px-5 text-[#8B5D3C]">@${esc(m.username)}</td>
                <td class="py-3.5 px-5 text-[#6F472F]">${esc(m.email)}</td>
                <td class="py-3.5 px-5 text-[#6F472F]">${esc(formatDate(m.created_at))}</td>
                <td class="py-3.5 px-5 font-semibold text-[#472D1F]">${m.total_contributions} Total (${m.approved_contributions} Approved)</td>
            </tr>`).join('') || `<tr><td colspan="5" class="py-8 text-center text-[#6F472F]">No registered members yet.</td></tr>`;
    } catch (err) {
        $('adminOverviewStats').innerHTML = errorBlock(err.message, 'col-span-full');
        $('adminPendingList').innerHTML = '';
        $('adminPublishedTableBody').innerHTML = '';
        $('adminMembersTableBody').innerHTML = '';
    }
    refreshIcons();
}

async function adminApproveRecord(weaveId, btn) {
    await withBusy(btn, 'Approving...', async () => {
        try {
            const { data } = await API.post('api/weaves.php?action=approve&id=' + weaveId);
            showToast(`"${data.weave.name}" has been APPROVED and published to the public archive.`);
            await renderAdminDashboard();
        } catch (err) {
            handleApiFailure(err);
            if (err.status === 409) renderAdminDashboard();
        }
    });
}

async function adminRejectRecord(weaveId, btn) {
    const ta = $('rejectFeedback_' + weaveId);
    const feedback = ta ? ta.value.trim() : '';
    const holder = ta ? ta.parentElement : null;
    if (holder) clearFieldErrors(holder);
    if (!feedback) {
        showFieldErrors({ feedback: 'Please provide rejection feedback so the Member can revise their submission.' }, { feedback: 'rejectFeedback_' + weaveId });
        if (ta) ta.focus();
        return;
    }
    await withBusy(btn, 'Rejecting...', async () => {
        try {
            const { data } = await API.post('api/weaves.php?action=reject&id=' + weaveId, { feedback });
            showToast(`"${data.weave.name}" was REJECTED and feedback was sent to the Member.`);
            await renderAdminDashboard();
        } catch (err) {
            handleApiFailure(err, { map: { feedback: 'rejectFeedback_' + weaveId }, form: holder });
        }
    });
}

function adminDeleteRecord(weaveId, btn) { return deleteWeave(weaveId, btn); }

/* ======================================================================================
   EVENT DELEGATION (dynamic markup uses data-action; no user data in inline JS)
   ====================================================================================== */
const ACTIONS = {
    'open-weave': (el) => openWeaveDetails(Number(el.dataset.id), el.dataset.from || 'weaves', el.dataset.tribe ? Number(el.dataset.tribe) : null),
    'open-tribe': (el) => openTribeDetails(Number(el.dataset.id)),
    'edit-weave': (el) => openSubmitRecordForm(Number(el.dataset.id)),
    'delete-weave': (el) => deleteWeave(Number(el.dataset.id), el),
    'approve-weave': (el) => adminApproveRecord(Number(el.dataset.id), el),
    'reject-weave': (el) => adminRejectRecord(Number(el.dataset.id), el),
    'member-filter': (el) => setMemberStatusFilter(el.dataset.status),
    'new-record': () => openSubmitRecordForm(),
    nav: (el) => navigateTo(el.dataset.view),
    retry: () => handleRoute()
};

document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-action]');
    if (!el || el.disabled) return;
    const fn = ACTIONS[el.dataset.action];
    if (fn) { e.preventDefault(); fn(el); }
});
document.addEventListener('keydown', (e) => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-action][role="button"]')) {
        e.preventDefault();
        e.target.click();
    }
});

/* ======================================================================================
   START-UP: restore the session, then open the route from the URL
   ====================================================================================== */
API.onUnauthorized = (err) => {
    if (!state.user) return;                 // e.g. a wrong password while logged out
    err.handled = true;
    state.user = null;
    showToast('Your session has expired. Please log in again.', 'error');
    refreshSession().catch(() => {}).finally(() => handleRoute());
};

async function init() {
    window.addEventListener('hashchange', handleRoute);
    $('subImageFile').addEventListener('change', onImageChosen);
    try {
        await refreshSession();
    } catch (err) {
        showToast(err.message, 'error');
    }
    await handleRoute();
}
document.addEventListener('DOMContentLoaded', init);
