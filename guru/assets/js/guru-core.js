/**
 * ExamLAN Pro - Teacher Dashboard Core Module (DRY Shared JS)
 */
const GURU_BASE = (function() {
    const p = window.location.pathname;
    const idx = p.indexOf('/guru');
    if (idx !== -1) {
        return p.substring(0, idx + 5) + '/'; // '/kuis/guru/'
    }
    return '/kuis/guru/';
})();
const API_URL = GURU_BASE + '../api.php';

let dbData = {
    questions: [],
    results: [],
    students: [],
    settings: { require_student_password: true },
    audit_logs: []
};
let isServerAuthenticated = false;
let pendingExtensionRequests = [];
let adminExtensionPollInterval = null;

// ================= MODAL & DIALOG HELPERS =================
function toggleModal(modalId, show) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    if (show) {
        modal.classList.remove('hidden');
    } else {
        modal.classList.add('hidden');
    }
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
window.escapeHtml = escapeHtml;

function appAlert(message, title = 'Informasi') {
    return new Promise((resolve) => {
        let alertModal = document.getElementById('core-app-alert-modal');
        if (!alertModal) {
            alertModal = document.createElement('div');
            alertModal.id = 'core-app-alert-modal';
            alertModal.className = 'fixed inset-0 bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4';
            alertModal.style.zIndex = '99999';
            alertModal.innerHTML = `
                <div class="bg-white rounded-2xl max-w-sm w-full p-5 shadow-2xl space-y-4 border border-slate-200">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center shrink-0 text-lg">
                            <i class="fa-solid fa-circle-info"></i>
                        </div>
                        <div>
                            <h4 id="core-alert-title" class="font-bold text-slate-800 text-sm"></h4>
                            <p id="core-alert-message" class="text-xs text-slate-600 mt-1 whitespace-pre-line leading-relaxed"></p>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2 border-t border-slate-100">
                        <button id="core-alert-ok-btn" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-bold transition">OK</button>
                    </div>
                </div>
            `;
            document.body.appendChild(alertModal);
        }
        document.getElementById('core-alert-title').innerText = title;
        document.getElementById('core-alert-message').innerText = message;
        alertModal.style.zIndex = '99999';
        alertModal.classList.remove('hidden');

        const okBtn = document.getElementById('core-alert-ok-btn');
        const clickHandler = () => {
            alertModal.classList.add('hidden');
            okBtn.removeEventListener('click', clickHandler);
            resolve(true);
        };
        okBtn.addEventListener('click', clickHandler);
    });
}

function appConfirm(message, title = 'Konfirmasi', cancelText = 'Batal', okText = 'Ya, Lanjutkan') {
    return new Promise((resolve) => {
        let confirmModal = document.getElementById('core-app-confirm-modal');
        if (!confirmModal) {
            confirmModal = document.createElement('div');
            confirmModal.id = 'core-app-confirm-modal';
            confirmModal.className = 'fixed inset-0 bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4';
            confirmModal.style.zIndex = '99999';
            confirmModal.innerHTML = `
                <div class="bg-white rounded-2xl max-w-sm w-full p-5 shadow-2xl space-y-4 border border-slate-200">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 text-lg">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <h4 id="core-confirm-title" class="font-bold text-slate-800 text-sm"></h4>
                            <p id="core-confirm-message" class="text-xs text-slate-600 mt-1 whitespace-pre-line leading-relaxed"></p>
                        </div>
                    </div>
                    <div class="flex justify-end items-center gap-2 pt-2 border-t border-slate-100">
                        <button id="core-confirm-cancel-btn" class="px-3.5 py-1.5 border border-slate-300 text-slate-600 hover:bg-slate-50 rounded-xl text-xs font-semibold transition">Batal</button>
                        <button id="core-confirm-ok-btn" class="px-4 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold transition">OK</button>
                    </div>
                </div>
            `;
            document.body.appendChild(confirmModal);
        }
        document.getElementById('core-confirm-title').innerText = title;
        document.getElementById('core-confirm-message').innerText = message;
        const cancelBtn = document.getElementById('core-confirm-cancel-btn');
        const okBtn = document.getElementById('core-confirm-ok-btn');
        cancelBtn.innerText = cancelText;
        okBtn.innerText = okText;
        confirmModal.style.zIndex = '99999';
        confirmModal.classList.remove('hidden');

        const onCancel = () => {
            confirmModal.classList.add('hidden');
            cleanup();
            resolve(false);
        };
        const onOk = () => {
            confirmModal.classList.add('hidden');
            cleanup();
            resolve(true);
        };
        function cleanup() {
            cancelBtn.removeEventListener('click', onCancel);
            okBtn.removeEventListener('click', onOk);
        }
        cancelBtn.addEventListener('click', onCancel);
        okBtn.addEventListener('click', onOk);
    });
}

// ================= AUTHENTICATION & SESSION =================
async function checkServerSession(onSuccessCallback = null) {
    try {
        const res = await fetch(`${API_URL}?action=check_session`, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (res.ok) {
            const data = await res.json();
            if (data.authenticated) {
                isServerAuthenticated = true;
                const authModal = document.getElementById('server-auth-modal');
                if (authModal) authModal.classList.add('hidden');
                startAdminExtensionPolling();
                await fetchDataFromAPI(onSuccessCallback);
                return true;
            }
        }
    } catch (e) {
        console.warn('Cek sesi guru gagal:', e);
    }
    lockTeacherServer();
    return false;
}

function lockTeacherServer() {
    isServerAuthenticated = false;
    clearInterval(adminExtensionPollInterval);
    const authModal = document.getElementById('server-auth-modal');
    if (authModal) {
        authModal.classList.remove('hidden');
        const input = document.getElementById('server-password-input');
        if (input) {
            input.value = '';
            input.focus();
        }
    }
}

async function verifyServerPassword(e, onSuccessCallback = null) {
    if (e) e.preventDefault();
    const input = document.getElementById('server-password-input');
    if (!input) return;
    const pwd = input.value;
    try {
        const res = await fetch(`${API_URL}?action=teacher_login`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pwd })
        });
        const data = await res.json();
        if (!res.ok || data.status !== 'success') {
            appAlert(data.message || 'Password salah!', 'Login Gagal');
            input.value = '';
            input.focus();
            return;
        }
        isServerAuthenticated = true;
        const authModal = document.getElementById('server-auth-modal');
        if (authModal) authModal.classList.add('hidden');
        startAdminExtensionPolling();
        await fetchDataFromAPI(onSuccessCallback);
        if (typeof onSuccessCallback === 'function') {
            onSuccessCallback(dbData);
        } else if (typeof window.onDataLoaded === 'function') {
            window.onDataLoaded(dbData);
        } else {
            window.location.reload();
        }
    } catch (err) {
        appAlert('Gagal menghubungi server.', 'Koneksi Terputus');
    }
}

async function handleLogout() {
    if (await appConfirm('Yakin ingin keluar dari dashboard guru?', 'Logout Guru', 'Batal', 'Keluar')) {
        try {
            await fetch(`${API_URL}?action=teacher_logout`, { method: 'POST', credentials: 'same-origin' });
        } catch (e) {}
        lockTeacherServer();
    }
}

// ================= FETCH SERVER DATA =================
async function fetchDataFromAPI(onSuccessCallback = null) {
    if (!isServerAuthenticated) return;
    try {
        const res = await fetch(`${API_URL}?action=get_data`, { credentials: 'same-origin', cache: 'no-store' });
        if (res.status === 403) {
            lockTeacherServer();
            return;
        }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        dbData = data;
        if (!dbData.questions) dbData.questions = [];
        if (!dbData.results) dbData.results = [];
        if (!dbData.students) dbData.students = [];
        if (!dbData.settings) dbData.settings = { require_student_password: true };

        if (typeof onSuccessCallback === 'function') {
            onSuccessCallback(dbData);
        }
    } catch (err) {
        console.error('Gagal memuat data dari server:', err);
    }
}

// ================= PERPANJANGAN WAKTU (NOTIFIKASI BELL) =================
function startAdminExtensionPolling() {
    clearInterval(adminExtensionPollInterval);
    fetchExtensionRequests();
    adminExtensionPollInterval = setInterval(fetchExtensionRequests, 8000);
}

async function fetchExtensionRequests() {
    if (!isServerAuthenticated) return;
    try {
        const res = await fetch(`${API_URL}?action=check_extension_requests`, { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) return;
        const data = await res.json();
        pendingExtensionRequests = data.requests || [];
        const count = data.pending_count ?? pendingExtensionRequests.length;

        // 1. Update Red Badge di Sidebar Menu "Permintaan Waktu"
        const sidebarBadge = document.getElementById('sidebar-notif-badge');
        if (sidebarBadge) {
            if (count > 0) {
                sidebarBadge.innerText = count;
                sidebarBadge.classList.remove('hidden');
            } else {
                sidebarBadge.classList.add('hidden');
            }
        }

        // 2. Update Red Dot di Tombol Bell Header
        const dot = document.getElementById('admin-bell-dot');
        const badge = document.getElementById('extension-count-badge');
        if (dot) {
            if (count > 0) dot.classList.remove('hidden');
            else dot.classList.add('hidden');
        }
        if (badge) badge.innerText = count;

        renderExtensionDropdownItems();

        // 3. Callback jika berada di halaman Notifikasi / Permintaan Waktu
        if (typeof window.onExtensionRequestsUpdated === 'function') {
            window.onExtensionRequestsUpdated(data);
        }
    } catch (e) {}
}

function toggleExtensionDropdown() {
    // Arahkan langsung ke halaman notifikasi jika dibuka
    window.location.href = `${GURU_BASE}notifikasi/`;
}

function renderExtensionDropdownItems() {
    const list = document.getElementById('extension-request-list');
    if (!list) return;
    if (pendingExtensionRequests.length === 0) {
        list.innerHTML = `<p class="text-xs text-slate-400 text-center py-4">Tidak ada permintaan perpanjangan waktu.</p>`;
        return;
    }
    list.innerHTML = pendingExtensionRequests.map(req => {
        const targetToken = req.token_hash || req.id || req.nis;
        return `
            <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1.5 text-xs">
                <div class="flex items-center justify-between font-bold text-slate-800">
                    <span>${escapeHtml(req.name)} (${escapeHtml(req.class)})</span>
                    <span class="text-[10px] text-slate-400 font-mono">NIS: ${escapeHtml(req.nis)}</span>
                </div>
                <p class="text-[11px] text-slate-500">Kuis: ${escapeHtml(req.quiz_title)}</p>
                <div class="flex items-center justify-end gap-1.5 pt-1">
                    <button type="button" onclick="handleExtensionDecision('${escapeHtml(targetToken)}', false)" class="px-2.5 py-1 bg-white hover:bg-slate-100 border border-slate-300 text-slate-600 rounded-lg text-[11px] font-semibold">Tolak</button>
                    <button type="button" onclick="handleExtensionDecision('${escapeHtml(targetToken)}', true, 15)" class="px-2.5 py-1 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-[11px] font-bold">+15 Menit</button>
                </div>
            </div>
        `;
    }).join('');
}

async function handleExtensionDecision(tokenHash, approve, minutes = 15) {
    try {
        const action = approve ? 'approve_extension' : 'reject_extension';
        const res = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token_hash: tokenHash, approve: approve, minutes: parseInt(minutes) || 15 })
        });
        const data = await res.json();
        if (res.ok && data.status === 'success') {
            appAlert(data.message || (approve ? `Perpanjangan waktu ${minutes} menit berhasil diberikan.` : 'Permintaan waktu ditolak.'), 'Permintaan Waktu');
            await fetchExtensionRequests();
        } else {
            appAlert(data.message || 'Gagal memproses permintaan waktu.', 'Perhatian');
        }
    } catch (e) {
        appAlert('Gagal memproses perpanjangan waktu. Periksa koneksi server.', 'Error');
    }
}

// ================= UNIVERSAL CUSTOM TOOLTIP SYSTEM =================
// Menghilangkan tooltip default browser (alt/title bawaan) saat elemen disorot,
// dan menggantikannya dengan floating custom tooltip yang modern, cepat, dan rapi.
(function initGlobalTooltipSystem() {
    let tooltipEl = null;
    let currentTarget = null;
    let hideTimer = null;

    function getOrCreateTooltip() {
        if (!tooltipEl) {
            tooltipEl = document.getElementById('app-global-tooltip');
            if (!tooltipEl) {
                tooltipEl = document.createElement('div');
                tooltipEl.id = 'app-global-tooltip';
                document.body.appendChild(tooltipEl);
            }
        }
        return tooltipEl;
    }

    function sanitizeElement(el) {
        if (!el || !(el instanceof Element)) return;
        if (el.hasAttribute('title')) {
            const val = el.getAttribute('title');
            if (val && val.trim()) {
                el.setAttribute('data-tooltip', val.trim());
            }
            el.removeAttribute('title');
        }
        if (el.hasAttribute('alt')) {
            const altVal = el.getAttribute('alt');
            if (altVal && altVal.trim() && !el.getAttribute('data-tooltip')) {
                el.setAttribute('data-tooltip', altVal.trim());
            }
        }
    }

    function showTooltipFor(target) {
        const text = target.getAttribute('data-tooltip');
        if (!text || !text.trim()) return;

        const tip = getOrCreateTooltip();
        tip.textContent = text.trim();
        tip.style.display = 'block';

        const rect = target.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();

        let top = rect.top - tipRect.height - 7;
        let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
        let placement = 'top';

        if (top < 8) {
            top = rect.bottom + 7;
            placement = 'bottom';
        }

        const minLeft = 8;
        const maxLeft = window.innerWidth - tipRect.width - 8;
        left = Math.max(minLeft, Math.min(left, maxLeft));

        const arrowOffset = (rect.left + (rect.width / 2)) - left;
        tip.style.setProperty('--arrow-left', `${arrowOffset}px`);

        tip.style.top = `${Math.round(top)}px`;
        tip.style.left = `${Math.round(left)}px`;
        tip.className = `active ${placement === 'top' ? 'arrow-bottom' : 'arrow-top'}`;
    }

    function hideTooltip() {
        if (!tooltipEl) return;
        tooltipEl.classList.remove('active');
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            if (tooltipEl && !tooltipEl.classList.contains('active')) {
                tooltipEl.style.display = 'none';
            }
        }, 120);
        currentTarget = null;
    }

    document.addEventListener('mouseover', (e) => {
        let el = e.target;
        while (el && el !== document.body && el !== document.documentElement) {
            if (el.hasAttribute('title') || el.hasAttribute('alt')) {
                sanitizeElement(el);
            }
            if (el.hasAttribute('data-tooltip')) {
                currentTarget = el;
                clearTimeout(hideTimer);
                showTooltipFor(el);
                return;
            }
            el = el.parentElement;
        }
    }, true);

    document.addEventListener('mouseout', (e) => {
        if (!currentTarget) return;
        if (e.relatedTarget && currentTarget.contains(e.relatedTarget)) return;
        hideTooltip();
    }, true);

    document.addEventListener('click', () => {
        hideTooltip();
    }, true);

    window.addEventListener('scroll', () => {
        hideTooltip();
    }, true);

    const observer = new MutationObserver((mutations) => {
        for (const m of mutations) {
            if (m.type === 'attributes' && m.attributeName === 'title') {
                sanitizeElement(m.target);
            } else if (m.type === 'childList') {
                m.addedNodes.forEach(node => {
                    if (node instanceof Element) {
                        if (node.hasAttribute('title') || node.hasAttribute('alt')) sanitizeElement(node);
                        if (node.querySelectorAll) {
                            node.querySelectorAll('[title], [alt]').forEach(sanitizeElement);
                        }
                    }
                });
            }
        }
    });

    function startObserving() {
        observer.observe(document.documentElement, {
            subtree: true,
            attributes: true,
            childList: true,
            attributeFilter: ['title']
        });
        document.querySelectorAll('[title], [alt]').forEach(sanitizeElement);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startObserving);
    } else {
        startObserving();
    }
})();
