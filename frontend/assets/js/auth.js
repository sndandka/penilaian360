/**
 * auth.js - Proteksi halaman & render layout (sidebar/topbar) sesuai role.
 * Setiap halaman admin/pegawai memanggil initLayout('menu-key') saat load.
 *
 * ROLE: super_admin, admin, pegawai
 */

// Hover persistence: jaga agar sidebar tidak mengecil saat navigasi menu (desktop only)
(function () {
    var isNav = sessionStorage.getItem('sidebar_nav_open') === '1' || sessionStorage.getItem('sidebar_hovered') === '1';
    if (isNav && localStorage.getItem('sidebar_pinned') !== '1' && window.innerWidth > 768) {
        document.documentElement.classList.add('sidebar-nav-open', 'sidebar-hover-persisted');
    }
})();

// Auto-load Searchable Select jika belum dimuat secara eksplisit
(function () {
    if (!window.SearchableSelect) {
        var script = document.createElement('script');
        var isSub = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/pegawai/');
        script.src = (isSub ? '../assets/js/' : 'assets/js/') + 'searchable-select.js?v=20261116_search';
        script.async = false;
        document.head.appendChild(script);
    }
})();

// Delegated click listener pada link menu sidebar agar persistensi selalu tercatat (desktop only)
document.addEventListener('click', function (e) {
    var link = e.target.closest('#sidebar nav a');
    if (link) {
        if (window.innerWidth <= 768) {
            closeMobileDrawer();
        } else if (localStorage.getItem('sidebar_pinned') !== '1') {
            sessionStorage.setItem('sidebar_nav_open', '1');
            sessionStorage.setItem('sidebar_hovered', '1');
        }
    }
}, true);

const ICONS = {
    dashboard: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>`,
    pegawai: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>`,
    unit: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9H9V9h10v2zm-4 4H9v-2h6v2zm4-8H9V5h10v2z"/></svg>`,
    periode: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7v-5z"/></svg>`,
    mapping: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>`,
    kriteria: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>`,
    hasil: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>`,
    monitoring: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>`,
    laporan: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>`,
    konfigurasi: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.56-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.73 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .43-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.49-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>`,
    audit: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>`,
    penilaian: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`,
};

/** Menu sidebar per role (super_admin & pegawai). */
const MENU_BY_ROLE = {
    super_admin: [
        { section: "Utama" },
        { key: "dashboard", label: "Dashboard", href: "dashboard.html", icon: ICONS.dashboard },
        { section: "Master Data Kepegawaian" },
        { key: "pegawai", label: "Data Pegawai", href: "pegawai.html", icon: ICONS.pegawai },
        { key: "master", label: "Unit Kerja & Jabatan", href: "master.html", icon: ICONS.unit },
        { section: "Penilaian" },
        { key: "periode", label: "Periode Penilaian", href: "periode.html", icon: ICONS.periode },
        { key: "mapping", label: "Mapping Penilai", href: "mapping.html", icon: ICONS.mapping },
        { key: "kriteria", label: "Rancang Penilaian", href: "kriteria.html", icon: ICONS.kriteria },
        { section: "Monitoring & Laporan" },
        { key: "hasil", label: "Hasil Penilaian", href: "hasil.html", icon: ICONS.hasil },
        { key: "monitoring", label: "Monitoring", href: "monitoring.html", icon: ICONS.monitoring },
        { key: "laporan", label: "Cetak Laporan", href: "laporan.html", icon: ICONS.laporan },
        { key: "audit", label: "Audit Log", href: "audit.html", icon: ICONS.audit },
        { section: "Sistem" },
        { key: "konfigurasi", label: "Konfigurasi", href: "konfigurasi.html", icon: ICONS.konfigurasi },
    ],

    admin: [
        { section: "Utama" },
        { key: "dashboard", label: "Dashboard", href: "dashboard.html", icon: ICONS.dashboard },
        { section: "Master Data Kepegawaian" },
        { key: "pegawai", label: "Data Pegawai", href: "pegawai.html", icon: ICONS.pegawai },
        { key: "master", label: "Unit Kerja & Jabatan", href: "master.html", icon: ICONS.unit },
        { section: "Penilaian" },
        { key: "periode", label: "Periode Penilaian", href: "periode.html", icon: ICONS.periode },
        { key: "mapping", label: "Mapping Penilai", href: "mapping.html", icon: ICONS.mapping },
        { key: "kriteria", label: "Rancang Penilaian", href: "kriteria.html", icon: ICONS.kriteria },
        { section: "Monitoring & Laporan" },
        { key: "hasil", label: "Hasil Penilaian", href: "hasil.html", icon: ICONS.hasil },
        { key: "monitoring", label: "Monitoring", href: "monitoring.html", icon: ICONS.monitoring },
        { key: "laporan", label: "Cetak Laporan", href: "laporan.html", icon: ICONS.laporan },
    ],

    pegawai: [
        { section: "Utama" },
        { key: "dashboard", label: "Dashboard", href: "dashboard.html", icon: ICONS.dashboard },
        { section: "Penilaian" },
        { key: "penilaian", label: "Kerjakan Penilaian", href: "penilaian.html", icon: ICONS.penilaian },
        { key: "hasil", label: "Hasil Penilaian", href: "hasil.html", icon: ICONS.hasil },
        { section: "Pengaturan" },
        { key: "konfigurasi", label: "Konfigurasi Akun", href: "konfigurasi.html", icon: ICONS.konfigurasi },
    ],
};

const PAGE_TITLES = {
    dashboard: "Dashboard", pegawai: "Manajemen Pegawai", master: "Unit Kerja & Jabatan",
    periode: "Periode Penilaian", mapping: "Mapping Penilai",
    kriteria: "Rancang Sistem Penilaian", hasil: "Hasil Penilaian", monitoring: "Monitoring Penilaian",
    laporan: "Cetak Laporan", konfigurasi: "Konfigurasi Akun", audit: "Audit Log",
    penilaian: "Kerjakan Penilaian",
};

let CURRENT_USER = null;

async function initLayout(activeKey, allowedRoles = null) {
    const res = await apiCall("auth.php?action=me");
    if (!res.success) {
        window.location.href = "../index.html";
        return null;
    }
    CURRENT_USER = res.data;

    if (allowedRoles && !allowedRoles.includes(CURRENT_USER.role)) {
        const targetUrl = defaultDashboardFor(CURRENT_USER.role);
        // Cegah infinite loop jika targetUrl adalah halaman saat ini
        if (window.location.pathname.endsWith(targetUrl.replace('../', ''))) {
            document.body.innerHTML = `<div style="text-align:center; padding:50px;"><h3>Akses Ditolak</h3><p>Anda tidak memiliki izin (role: ${CURRENT_USER.role}) untuk mengakses halaman ini.</p><a href="../index.html">Kembali ke Login</a></div>`;
        } else {
            window.location.href = targetUrl;
        }
        return null;
    }

    renderSidebar(activeKey, CURRENT_USER.role);
    renderTopbar(activeKey);
    renderMobileBottomNav(activeKey, CURRENT_USER.role);

    // Inisialisasi dropdown pencarian jika komponen sudah siap
    if (window.SearchableSelect) {
        window.SearchableSelect.initAll();
    }

    // Tampilkan notifikasi tugas penilaian hanya di halaman Dashboard (khusus pegawai)
    if (CURRENT_USER.role === 'pegawai' && activeKey === 'dashboard') {
        checkTugasNotifikasi();
    }

    return CURRENT_USER;
}

function defaultDashboardFor(role) {
    return role === "pegawai" ? "../pegawai/dashboard.html" : "../admin/dashboard.html";
}

function getInitials(nama) {
    if (!nama) return "-";
    const parts = nama.trim().split(" ");
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
}

function renderSidebar(activeKey, role) {
    const menu = MENU_BY_ROLE[role] || MENU_BY_ROLE.pegawai;
    const sidebar = document.getElementById("sidebar");
    if (!sidebar) return;

    let html = `
        <div class="brand">
            <div class="brand-logo" onclick="toggleSidebar()" title="Kunci / Buka Sidebar (Pin)" style="background:transparent;"><img src="../assets/logo/OIP.webp" alt="Logo" style="width:100%; height:100%; object-fit:contain; border-radius:10px;"></div>
            <span class="brand-name">Penilaian 360<br><small style="font-weight:400;font-size:10px;opacity:.7;">Dinas PU Bina Marga Jatim</small></span>
        </div>
        <nav>
    `;

    menu.forEach(item => {
        if (item.section) {
            html += `<div class="section-label">${item.section}</div>`;
        } else {
            html += `
                <a href="${item.href}" class="${item.key === activeKey ? 'active' : ''}" id="nav-${item.key}" data-tooltip="${item.label}">
                    <span class="nav-icon">${item.icon}</span>
                    <span class="nav-label">${item.label}</span>
                </a>
            `;
        }
    });

    html += `</nav>`;

    sidebar.innerHTML = html;

    // Pastikan tidak ada tombol footer di bawah sidebar
    sidebar.querySelectorAll('.sidebar-footer, .sidebar-toggle-bottom, button.sidebar-toggle-bottom').forEach(function (el) {
        el.remove();
    });

    // Reset class lama
    sidebar.classList.remove('collapsed');

    // Cek apakah user mengunci sidebar terbuka permanen (pinned)
    if (localStorage.getItem('sidebar_pinned') === '1') {
        sidebar.classList.add('pinned');
    } else {
        sidebar.classList.remove('pinned');
    }

    // Setup interaksi hover & persistensi saat pindah menu (desktop) + backdrop drawer (mobile)
    ensureSidebarBackdrop();
    setupSidebarHover(sidebar);
}

function ensureSidebarBackdrop() {
    if (!document.getElementById('sidebar-backdrop')) {
        const bd = document.createElement('div');
        bd.id = 'sidebar-backdrop';
        bd.className = 'sidebar-backdrop';
        bd.addEventListener('click', closeMobileDrawer);
        document.body.appendChild(bd);
    }
}

function closeMobileDrawer() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (backdrop) backdrop.classList.remove('active');
    document.body.classList.remove('mobile-drawer-open');
    updateHamburgerUI(window.innerWidth <= 768 ? false : (localStorage.getItem('sidebar_pinned') === '1'));
}

function renderMobileBottomNav(activeKey, role) {
    if (role !== 'pegawai') return;
    let nav = document.getElementById('mobile-bottom-nav');
    if (!nav) {
        nav = document.createElement('nav');
        nav.id = 'mobile-bottom-nav';
        nav.className = 'mobile-bottom-nav';
        nav.setAttribute('aria-label', 'Navigasi Cepat Pegawai');
        document.body.appendChild(nav);
    }
    const items = [
        { key: 'dashboard', label: 'Beranda', href: 'dashboard.html', icon: ICONS.dashboard },
        { key: 'penilaian', label: 'Penilaian', href: 'penilaian.html', icon: ICONS.penilaian },
        { key: 'hasil', label: 'Hasil', href: 'hasil.html', icon: ICONS.hasil },
        { key: 'konfigurasi', label: 'Akun', href: 'konfigurasi.html', icon: ICONS.konfigurasi },
    ];
    nav.innerHTML = items.map(it => `
        <a href="${it.href}" class="mobile-bottom-item ${it.key === activeKey ? 'active' : ''}">
            ${it.icon}
            <span>${it.label}</span>
        </a>
    `).join('');
    document.body.classList.add('has-mobile-bottom-nav');
}

/**
 * Setup interaksi hover buka/tutup otomatis dan persistensi saat navigasi menu.
 * 1. Buka otomatis saat kursor diarahkan ke sidebar.
 * 2. Tutup otomatis saat kursor ditarik keluar dari sidebar.
 * 3. Persistensi: sidebar tetap terbuka saat klik pindah menu, baru mengecil saat kursor ditarik keluar (> 248px).
 */
function setupSidebarHover(sidebar) {
    if (!sidebar) return;

    // Pastikan event click pada link menu mencatat persistensi untuk halaman tujuan (desktop) atau menutup drawer (mobile)
    sidebar.querySelectorAll('nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                closeMobileDrawer();
            } else if (localStorage.getItem('sidebar_pinned') !== '1') {
                sessionStorage.setItem('sidebar_nav_open', '1');
                sessionStorage.setItem('sidebar_hovered', '1');
            }
        });
    });

    var hoverTimer = null;

    // Fungsi tutup sidebar dan bersihkan persistensi
    function closeSidebar() {
        if (window.innerWidth <= 768) return;
        if (localStorage.getItem('sidebar_pinned') === '1') return;
        document.documentElement.classList.remove('sidebar-nav-open', 'sidebar-hover-persisted');
        sidebar.classList.remove('is-hovered');
        sessionStorage.removeItem('sidebar_nav_open');
        sessionStorage.removeItem('sidebar_hovered');
        window.removeEventListener('mousemove', handleNavMouseMove);
    }

    // Fungsi buka sidebar
    function openSidebar() {
        if (window.innerWidth <= 768) return;
        if (localStorage.getItem('sidebar_pinned') === '1') return;
        clearTimeout(hoverTimer);
        sidebar.classList.add('is-hovered');
        document.documentElement.classList.add('sidebar-hover-persisted');
    }

    // 1. Cek persistensi navigasi: jika halaman ini baru saja dibuka dari klik menu sidebar (desktop only)
    var isNavOpen = sessionStorage.getItem('sidebar_nav_open') === '1' || sessionStorage.getItem('sidebar_hovered') === '1';
    if (isNavOpen && localStorage.getItem('sidebar_pinned') !== '1' && window.innerWidth > 768) {
        document.documentElement.classList.add('sidebar-nav-open', 'sidebar-hover-persisted');
        sidebar.classList.add('is-hovered');

        // Saat navigasi, monitor mousemove window: jika kursor digeser keluar (> 248px), tutup sidebar
        window.addEventListener('mousemove', handleNavMouseMove, { passive: true });
    }

    function handleNavMouseMove(e) {
        if (e.clientX > 248) {
            closeSidebar();
        }
    }

    // 2. Event hover interaktif (mouseenter / mouseleave)
    sidebar.addEventListener('mouseenter', function () {
        openSidebar();
    });

    sidebar.addEventListener('mouseleave', function (e) {
        if (window.innerWidth <= 768) return;
        if (localStorage.getItem('sidebar_pinned') === '1') return;
        // Tunggu 120ms agar perpindahan kursor halus dan tidak flicker
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () {
            closeSidebar();
        }, 120);
    });
}

// ============================================================
// SISTEM TEMA GELAP / TERANG (DARK & LIGHT MODE)
// ============================================================
function getSavedTheme() {
    const saved = localStorage.getItem('app_theme');
    if (saved === 'dark' || saved === 'light') return saved;
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }
    return 'light';
}

function applyTheme(theme, animate = false) {
    if (animate) {
        document.documentElement.classList.add('theme-transition');
        setTimeout(() => document.documentElement.classList.remove('theme-transition'), 300);
    }
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('app_theme', theme);
    updateThemeToggleUI(theme);
    window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme } }));
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || getSavedTheme();
    const next = current === 'dark' ? 'light' : 'dark';
    applyTheme(next, true);
}

function updateThemeToggleUI(theme) {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    const isDark = theme === 'dark';
    btn.setAttribute('aria-label', isDark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap');
    btn.setAttribute('title', isDark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap');

    if (isDark) {
        // Ikon Matahari (Sun) - klik untuk beralih ke mode terang
        btn.innerHTML = `
            <svg viewBox="0 0 24 24" style="fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;">
                <circle cx="12" cy="12" r="5"></circle>
                <line x1="12" y1="1" x2="12" y2="3"></line>
                <line x1="12" y1="21" x2="12" y2="23"></line>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                <line x1="1" y1="12" x2="3" y2="12"></line>
                <line x1="21" y1="12" x2="23" y2="12"></line>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
            </svg>
        `;
    } else {
        // Ikon Bulan (Moon) - klik untuk beralih ke mode gelap
        btn.innerHTML = `
            <svg viewBox="0 0 24 24" style="fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
            </svg>
        `;
    }
}

// Inisialisasi tema segera saat auth.js dieksekusi
(function () {
    const theme = getSavedTheme();
    document.documentElement.setAttribute('data-theme', theme);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => updateThemeToggleUI(theme));
    } else {
        updateThemeToggleUI(theme);
    }
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (!localStorage.getItem('app_theme')) {
                applyTheme(e.matches ? 'dark' : 'light', true);
            }
        });
    }
})();

function renderTopbar(activeKey) {
    const topbarUser = document.getElementById("topbar-user");
    if (!topbarUser || !CURRENT_USER) return;

    const initials = getInitials(CURRENT_USER.nama);
    const role_label = CURRENT_USER.jabatan || roleLabel(CURRENT_USER.role);
    const now = new Date();
    const dateStr = now.toLocaleDateString("id-ID", { weekday: "long", year: "numeric", month: "long", day: "numeric" });

    topbarUser.innerHTML = `
        <div class="topbar-right">
            <span class="topbar-date">${dateStr}</span>
            <button class="topbar-theme-btn" id="theme-toggle" onclick="toggleTheme()" type="button" title="Ubah Tema (Terang / Gelap)" aria-label="Ubah Tema"></button>
            <div class="topbar-user-info">
                <span class="t-name">${CURRENT_USER.nama}${CURRENT_USER.golongan_ruang ? ` <span class="badge" style="font-size:10px;padding:1px 5px;background:var(--primary-light);color:var(--primary);border-radius:10px;font-weight:600;margin-left:3px;">${CURRENT_USER.golongan_ruang}</span>` : ''}</span>
                <span class="t-role">${role_label}</span>
            </div>
            <div class="topbar-avatar">${initials}</div>
            <button class="btn-logout-top" onclick="doLogout()" title="Keluar" aria-label="Keluar">
                <svg style="width:13px;height:13px;fill:currentColor;vertical-align:middle;margin-right:4px;" viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                <span class="logout-text">Keluar</span>
            </button>
        </div>
    `;

    updateThemeToggleUI(document.documentElement.getAttribute('data-theme') || getSavedTheme());

    const titleEl = document.getElementById("page-title");
    if (titleEl) {
        titleEl.textContent = PAGE_TITLES[activeKey] || "Dashboard";
        // Sisipkan hamburger button sebelum judul di topbar-left
        const topbarLeft = document.querySelector(".topbar-left");
        if (topbarLeft && !document.getElementById("topbar-hamburger")) {
            const btn = document.createElement("button");
            btn.id = "topbar-hamburger";
            btn.className = "topbar-hamburger-btn";
            btn.type = "button";
            btn.title = "Kunci / Buka Sidebar (Pin)";
            btn.onclick = toggleSidebar;
            btn.innerHTML = `<svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>`;
            topbarLeft.insertBefore(btn, titleEl);
        }
        updateHamburgerUI(window.innerWidth <= 768 ? false : (localStorage.getItem('sidebar_pinned') === '1'));
    }

}

window.addEventListener('resize', function () {
    if (window.innerWidth > 768) {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (backdrop) backdrop.classList.remove('active');
        document.body.classList.remove('mobile-drawer-open');
        updateHamburgerUI(localStorage.getItem('sidebar_pinned') === '1');
    }
});

async function doLogout() {
    const ok = await showConfirmModal({
        title: "Konfirmasi Keluar",
        message: "Apakah Anda yakin ingin keluar dari sistem Penilaian 360 Dinas PU Bina Marga Jatim?",
        confirmText: "Ya, Keluar",
        cancelText: "Batal",
        type: "warning",
        icon: "logout"
    });
    if (!ok) return;

    showCenterFeedback("Berhasil keluar. Mengalihkan...", "info", 1500);
    await apiCall("auth.php?action=logout", "POST");
    setTimeout(() => {
        window.location.href = "../index.html";
    }, 450);
}

/**
 * Toggle sidebar antara pinned (terbuka permanen) dan auto (buka/tutup otomatis saat kursor diarahkan).
 * State disimpan di localStorage agar persist lintas halaman.
 */
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    // Mode Mobile: buka/tutup off-canvas drawer
    if (window.innerWidth <= 768) {
        ensureSidebarBackdrop();
        const backdrop = document.getElementById('sidebar-backdrop');
        const isOpen = sidebar.classList.toggle('mobile-open');
        if (backdrop) backdrop.classList.toggle('active', isOpen);
        document.body.classList.toggle('mobile-drawer-open', isOpen);
        updateHamburgerUI(isOpen);
        return;
    }

    const isPinned = sidebar.classList.toggle('pinned');
    sidebar.classList.remove('is-hovered', 'collapsed');
    document.documentElement.classList.remove('sidebar-nav-open', 'sidebar-hover-persisted');
    localStorage.setItem('sidebar_pinned', isPinned ? '1' : '0');
    sessionStorage.removeItem('sidebar_nav_open');
    sessionStorage.removeItem('sidebar_hovered');
    updateHamburgerUI(isPinned);
}

function updateHamburgerUI(isPinned) {
    const btn = document.getElementById("topbar-hamburger");
    if (!btn) return;
    const isMobile = window.innerWidth <= 768;
    btn.classList.toggle("is-pinned", !!isPinned);
    if (isMobile) {
        btn.setAttribute("title", isPinned ? "Tutup Menu Navigasi" : "Buka Menu Navigasi");
        btn.setAttribute("aria-label", isPinned ? "Tutup Menu Navigasi" : "Buka Menu Navigasi");
    } else {
        btn.setAttribute("title", isPinned ? "Sidebar Terkunci (Klik untuk melepas kunci)" : "Kunci Sidebar (Pin)");
        btn.setAttribute("aria-label", isPinned ? "Sidebar Terkunci" : "Kunci Sidebar");
    }
}

/**
 * Cek apakah ada tugas penilaian yang belum dikerjakan.
 * Popup ditampilkan setiap kali halaman Dashboard dibuka/di-reload.
 */
async function checkTugasNotifikasi() {
    // Ambil daftar tugas penilaian
    const res = await apiCall('evaluations.php?action=tugas');
    if (!res.success || !res.data) return;

    // Filter yang belum selesai (status != 'selesai')
    let belumSelesai = res.data.filter(t => t.status !== 'selesai');
    if (belumSelesai.length === 0) return;

    if (typeof sortTugasPenilaian === 'function') {
        sortTugasPenilaian(belumSelesai);
    }

    // Ambil info periode aktif untuk deadline
    let deadlineText = 'Segera selesaikan sebelum batas waktu periode berakhir.';
    try {
        const resPeriod = await apiCall('periode.php');
        if (resPeriod.success && resPeriod.data) {
            const aktif = resPeriod.data
                .filter(p => p.status === 'aktif')
                .sort((a, b) => new Date(b.tanggal_mulai) - new Date(a.tanggal_mulai));
            if (aktif.length > 0) {
                const tgl = new Date(aktif[0].tanggal_selesai).toLocaleDateString('id-ID', {
                    day: '2-digit', month: 'long', year: 'numeric'
                });
                deadlineText = `Batas waktu pengerjaan: <strong>${tgl}</strong>. Jangan sampai terlewat!`;
            }
        }
    } catch (_) { }

    showTugasPopup(belumSelesai, deadlineText);
}

/**
 * Render & tampilkan popup notifikasi tugas penilaian.
 * @param {Array}  tugas        - Array item tugas yang belum selesai
 * @param {string} deadlineText - Teks deadline HTML
 */
function showTugasPopup(tugas, deadlineText) {
    const MAX_PREVIEW = 4;
    const sisanya = tugas.length - MAX_PREVIEW;

    // Buat item preview tugas
    const tugasHtml = tugas.slice(0, MAX_PREVIEW).map(t => `
        <div class="notif-task-item">
            <div class="notif-task-item-dot"></div>
            <div class="notif-task-item-name">${t.nama_dinilai || '–'}${t.golongan_dinilai && t.golongan_dinilai !== '-' ? ` [${t.golongan_dinilai}]` : ''}</div>
            <div class="notif-task-item-relasi rel-${t.jenis_relasi || ''}">${relasiTargetLabel(t.jenis_relasi || '')}</div>
        </div>
    `).join('');

    const moreHtml = sisanya > 0
        ? `<div class="notif-more-label">+ ${sisanya} tugas lainnya</div>`
        : '';

    // Tentukan path penilaian.html relatif terhadap halaman saat ini
    const path = window.location.pathname;
    const isPegawaiDir = path.includes('/pegawai/');
    const penilaianUrl = isPegawaiDir ? 'penilaian.html' : '../pegawai/penilaian.html';

    const html = `
        <div class="notif-overlay" id="notif-overlay" role="dialog" aria-modal="true" aria-labelledby="notif-title">
            <div class="notif-popup">

                <!-- Header -->
                <div class="notif-header">
                    <div class="notif-header-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                    </div>
                    <div class="notif-pulse" style="margin-bottom:10px;">
                        <div class="notif-pulse-dot"></div>
                        Periode Penilaian Aktif
                    </div>
                    <div class="notif-header-title" id="notif-title">Ada ${tugas.length} Tugas Penilaian Belum Dikerjakan!</div>
                    <div class="notif-header-sub">Halo, ${CURRENT_USER?.nama || 'Pegawai'}! Anda memiliki tugas penilaian yang menunggu.</div>
                </div>

                <!-- Body -->
                <div class="notif-body">
                    <div class="notif-count-badge">
                        <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        <div>
                            <div class="notif-count-number">${tugas.length}</div>
                            <div style="font-size:12px; font-weight:500; margin-top:1px;">orang perlu Anda nilai</div>
                        </div>
                    </div>

                    <div class="notif-task-list">
                        ${tugasHtml}
                        ${moreHtml}
                    </div>

                    <div class="notif-deadline">
                        <svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
                        <span>${deadlineText}</span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="notif-footer">
                    <button class="notif-btn-dismiss" onclick="closeTugasPopup()" id="notif-dismiss">Nanti Saja</button>
                    <button class="notif-btn-action" onclick="goToPenilaian('${penilaianUrl}')" id="notif-action">
                        <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                        Kerjakan Sekarang
                    </button>
                </div>

            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', html);

    // Tutup popup jika klik di luar (backdrop)
    const overlay = document.getElementById('notif-overlay');
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeTugasPopup();
    });

    // Tutup dengan Escape
    const escHandler = (e) => {
        if (e.key === 'Escape') { closeTugasPopup(); document.removeEventListener('keydown', escHandler); }
    };
    document.addEventListener('keydown', escHandler);
}

/** Menutup popup dengan animasi, lalu hapus dari DOM */
function closeTugasPopup() {
    const overlay = document.getElementById('notif-overlay');
    if (!overlay) return;
    overlay.classList.add('closing');
    setTimeout(() => overlay.remove(), 280);
}

/** Navigasi ke halaman penilaian & tutup popup */
function goToPenilaian(url) {
    closeTugasPopup();
    // Delay sedikit agar animasi close terlihat
    setTimeout(() => { window.location.href = url; }, 120);
}

// Pembersihan otomatis elemen toggle bawah sidebar
(function () {
    function purgeSidebarFooter() {
        document.querySelectorAll('.sidebar-footer, .sidebar-toggle-bottom, button.sidebar-toggle-bottom').forEach(function (el) {
            el.remove();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', purgeSidebarFooter);
    } else {
        purgeSidebarFooter();
    }
    setTimeout(purgeSidebarFooter, 50);
    setTimeout(purgeSidebarFooter, 300);
})();
