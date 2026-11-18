/**
 * ExamLAN Pro - Shared Header & Sidebar Navigation Component (DRY)
 */
(function() {
    function initNavigation() {
        const headerPlaceholder = document.getElementById('guru-header-placeholder');
        const sidebarPlaceholder = document.getElementById('guru-sidebar-placeholder');
        const path = window.location.pathname.toLowerCase();

        // Tentukan menu aktif berdasarkan URL
        let activeKey = 'hasil-ujian';
        if (path.includes('/bank-soal')) activeKey = 'bank-soal';
        else if (path.includes('/data-siswa')) activeKey = 'data-siswa';
        else if (path.includes('/audit-log')) activeKey = 'audit-log';
        else if (path.includes('/monitoring')) activeKey = 'monitoring';
        else if (path.includes('/notifikasi') || path.includes('/permintaan-waktu')) activeKey = 'notifikasi';
        else if (path.includes('/pengaturan')) activeKey = 'pengaturan';
        else if (path.includes('/hasil-ujian')) activeKey = 'hasil-ujian';

        // 1. Render Header
        if (headerPlaceholder) {
            const host = window.location.hostname || 'localhost';
            headerPlaceholder.innerHTML = `
                <header class="bg-slate-900 text-white shadow-sm sticky top-0 z-30">
                    <div class="w-full px-4 sm:px-6 py-2.5 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="bg-brand-600 p-1.5 rounded-lg text-white flex items-center justify-center">
                                <i class="fa-solid fa-network-wired text-base"></i>
                            </div>
                            <div>
                                <h1 class="font-bold text-sm sm:text-base leading-tight flex items-center gap-2">
                                    ExamLAN Pro <span class="bg-amber-500/20 text-amber-300 text-[10px] px-2 py-0.5 rounded-full border border-amber-500/30">v2.0</span>
                                </h1>
                                <p class="text-[11px] text-slate-400">Server Evaluasi Lab Komputer</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 sm:gap-3">
                            <span class="hidden md:inline-flex items-center gap-1.5 bg-slate-800 text-slate-300 text-xs px-2.5 py-1 rounded-lg border border-slate-700">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span>Host: ${host}</span>
                            </span>

                            <!-- Admin Extension Bell (Link ke Halaman Permintaan Waktu) -->
                            <a id="admin-bell-btn" href="${GURU_BASE}notifikasi/" class="relative p-2 rounded-xl text-slate-300 hover:text-white hover:bg-slate-800 transition focus:outline-none" title="Permintaan Waktu Siswa">
                                <i class="fa-solid fa-bell text-base"></i>
                                <span id="admin-bell-dot" class="hidden absolute top-1 right-1 w-2.5 h-2.5 bg-rose-500 rounded-full ring-2 ring-slate-900 animate-pulse"></span>
                            </a>

                            <button type="button" onclick="toggleModal('lan-guide-modal', true)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 border border-slate-700">
                                <i class="fa-solid fa-circle-question text-xs"></i>
                                <span class="hidden sm:inline">Info LAN</span>
                            </button>

                            <button type="button" onclick="handleLogout()" class="px-2.5 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
                                <i class="fa-solid fa-right-from-bracket text-xs"></i>
                                <span class="hidden sm:inline">Keluar</span>
                            </button>
                        </div>
                    </div>
                </header>
            `;
        }

        // 2. Render Sidebar Navigation
        if (sidebarPlaceholder) {
            const menus = [
                { key: 'hasil-ujian', title: 'Hasil Ujian', icon: 'fa-chart-line', url: `${GURU_BASE}hasil-ujian/` },
                { key: 'bank-soal', title: 'Bank Soal', icon: 'fa-folder-plus', url: `${GURU_BASE}bank-soal/` },
                { key: 'data-siswa', title: 'Data Siswa', icon: 'fa-users', url: `${GURU_BASE}data-siswa/` },
                { key: 'monitoring', title: 'Monitoring Ujian', icon: 'fa-tower-broadcast', url: `${GURU_BASE}monitoring/` },
                { key: 'notifikasi', title: 'Permintaan Waktu', icon: 'fa-clock-rotate-left', url: `${GURU_BASE}notifikasi/`, badgeId: 'sidebar-notif-badge' },
                { key: 'audit-log', title: 'Audit Log', icon: 'fa-shield-halved', url: `${GURU_BASE}audit-log/` },
                { key: 'pengaturan', title: 'Pengaturan', icon: 'fa-gear', url: `${GURU_BASE}pengaturan/` }
            ];

            sidebarPlaceholder.innerHTML = `
                <nav class="bg-white p-2.5 rounded-2xl border border-slate-200 shadow-xs flex flex-row md:flex-col gap-1 w-full overflow-x-auto md:overflow-visible">
                    ${menus.map(m => {
                        const isActive = m.key === activeKey;
                        const activeClass = isActive
                            ? 'bg-brand-50 text-brand-700 font-bold border border-brand-200 shadow-2xs'
                            : 'text-slate-600 hover:bg-slate-50 font-medium hover:text-slate-900';
                        return `
                            <a href="${m.url}" class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs transition shrink-0 md:shrink ${activeClass}">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <i class="fa-solid ${m.icon} w-4 text-center text-sm ${isActive ? 'text-brand-600' : 'text-slate-400'}"></i>
                                    <span class="whitespace-nowrap">${m.title}</span>
                                </div>
                                ${m.badgeId ? `<span id="${m.badgeId}" class="hidden ml-auto bg-rose-600 text-white text-[10px] font-black px-1.5 py-0.2 rounded-full animate-pulse shadow-sm">0</span>` : ''}
                            </a>
                        `;
                    }).join('')}
                    <hr class="hidden md:block my-1.5 border-slate-100">
                    <button type="button" onclick="handleLogout()" class="hidden md:flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs text-rose-600 hover:bg-rose-50 font-medium transition w-full text-left">
                        <i class="fa-solid fa-right-from-bracket w-4 text-center text-sm text-rose-500"></i>
                        <span>Logout</span>
                    </button>
                </nav>
            `;
        }

        // 3. Inject Teacher Auth Modal & LAN Guide Modal jika belum ada
        if (!document.getElementById('server-auth-modal')) {
            const authDiv = document.createElement('div');
            authDiv.id = 'server-auth-modal';
            authDiv.className = 'hidden fixed inset-0 bg-slate-950/80 backdrop-blur-md z-[100] flex items-center justify-center p-4';
            authDiv.innerHTML = `
                <div class="bg-white rounded-2xl max-w-sm w-full p-5 shadow-2xl border border-slate-200 space-y-4">
                    <div class="text-center space-y-1.5">
                        <div class="w-12 h-12 bg-brand-50 text-brand-600 rounded-full flex items-center justify-center mx-auto text-xl">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <h3 class="text-base font-bold text-slate-800">Login Guru / Server</h3>
                        <p class="text-xs text-slate-500">Masukkan password pengawas ujian untuk membuka dashboard.</p>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1 uppercase tracking-wider">Password Guru</label>
                            <input type="text" id="server-password-input" name="server_auth_key" required autocomplete="one-time-code" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" onkeydown="if(event.key === 'Enter') verifyServerPassword(event)" style="-webkit-text-security: disc; text-security: disc;" placeholder="Masukkan password..." class="w-full px-3.5 py-2 rounded-xl border border-slate-300 focus:ring-2 focus:ring-brand-500 outline-none text-xs">
                        </div>
                        <button type="button" onclick="verifyServerPassword(event)" class="w-full py-2.5 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-xs font-bold shadow-sm transition">
                            Masuk Dashboard
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(authDiv);
        }

        if (!document.getElementById('lan-guide-modal')) {
            const lanDiv = document.createElement('div');
            lanDiv.id = 'lan-guide-modal';
            lanDiv.className = 'hidden fixed inset-0 bg-slate-950/70 backdrop-blur-xs z-[100] flex items-center justify-center p-4';
            lanDiv.innerHTML = `
                <div class="bg-white rounded-2xl max-w-md w-full p-5 shadow-2xl border border-slate-200 space-y-3">
                    <div class="flex items-center justify-between border-b pb-2.5">
                        <h3 class="font-bold text-slate-800 text-sm flex items-center gap-2">
                            <i class="fa-solid fa-network-wired text-brand-600"></i> Informasi Jaringan LAN
                        </h3>
                        <button type="button" onclick="toggleModal('lan-guide-modal', false)" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div class="space-y-2 text-xs text-slate-600">
                        <p>Agar siswa di lab dapat mengakses ujian melalui jaringan LAN, bagikan alamat berikut kepada siswa:</p>
                        <div class="p-2.5 bg-slate-100 rounded-xl font-mono text-xs font-bold text-brand-700 text-center select-all">
                            http://${window.location.hostname || 'IP_SERVER'}/kuis/
                        </div>
                        <p class="text-[11px] text-slate-500">Pastikan perangkat server dan komputer siswa terhubung ke Switch/Access Point yang sama.</p>
                    </div>
                    <div class="flex justify-end pt-2 border-t">
                        <button type="button" onclick="toggleModal('lan-guide-modal', false)" class="px-4 py-1.5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold">Tutup</button>
                    </div>
                </div>
            `;
            document.body.appendChild(lanDiv);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavigation);
    } else {
        initNavigation();
    }
})();
