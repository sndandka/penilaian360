/**
 * api.js - Helper terpusat untuk memanggil backend PHP.
 * Untuk deployment terpisah (mis. Vercel + Backend PHP), atur BACKEND_API_URL di assets/js/config.js.
 */
function resolveBackendBase(defaultRelative = "../../backend/api") {
    const custom = (typeof window !== "undefined" && window.APP_CONFIG && window.APP_CONFIG.BACKEND_API_URL)
        ? String(window.APP_CONFIG.BACKEND_API_URL).trim().replace(/\/+$/, "")
        : "";
    return custom || defaultRelative;
}

const API_BASE_URL = resolveBackendBase("../../backend/api"); // relatif dari frontend/admin/*.html atau frontend/pegawai/*.html
// Jika file HTML ada langsung di /frontend (seperti index.html), gunakan "../backend/api"

function getApiUrl(endpoint, baseOverride = null) {
    const base = resolveBackendBase(baseOverride || "../../backend/api");
    return `${base}/${String(endpoint).replace(/^\/+/, "")}`;
}

// Token CSRF disimpan di memori (bukan localStorage) - didapat dari response login/me,
// lalu disertakan di header setiap request POST/PUT/DELETE agar backend bisa memvalidasi.
let CSRF_TOKEN = null;
function setCsrfToken(token) {
    if (token) CSRF_TOKEN = token;
}

async function apiCall(endpoint, method = "GET", body = null, baseOverride = null) {
    if (window.location.protocol === "file:") {
        return {
            success: false,
            message: "Aplikasi dibuka via file:// protocol (langsung klik file HTML). Mohon jalankan Web Server (seperti XAMPP / Laragon / PHP CLI) dan buka melalui HTTP (contoh: http://localhost/penilaian360/frontend/index.html).",
            data: null
        };
    }

    const base = resolveBackendBase(baseOverride || API_BASE_URL);
    const options = {
        method,
        credentials: "include", // supaya session cookie PHP terkirim
        headers: {}
    };
    if (method !== "GET" && CSRF_TOKEN) {
        options.headers["X-CSRF-Token"] = CSRF_TOKEN;
    }
    if (body) {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch(`${base}/${endpoint}`, options);
    } catch (err) {
        return {
            success: false,
            message: "Tidak dapat terhubung ke server backend. Pastikan Web Server (Apache/PHP) sedang berjalan dan URL backend dapat diakses.",
            data: null
        };
    }

    let json;
    try {
        json = await res.json();
    } catch (err) {
        return {
            success: false,
            message: `Response dari server bukan format JSON valid (${res.status} ${res.statusText}). Pastikan path backend/api '${base}/${endpoint}' sudah benar.`,
            data: null
        };
    }

    // Simpan/refresh token CSRF setiap kali server mengirimkannya (login, me, dsb)
    if (json && json.data && json.data.csrf_token) {
        setCsrfToken(json.data.csrf_token);
    }

    if (!res.ok && json && json.message) {
        return json;
    }
    if (!res.ok && !json) {
        return { success: false, message: `Terjadi kesalahan (${res.status})`, data: null };
    }
    return json;
}

// ============================================================
// STYLES INJECTION: Memastikan feedback & modal selalu ter-render
// sempurna di tengah layar tanpa terpengaruh cache file CSS eksternal.
// ============================================================
(function injectFeedbackStyles() {
    if (typeof document === 'undefined') return;
    if (document.getElementById('app-feedback-center-styles')) return;

    const css = `
        /* Overlay Backdrop di Tengah Layar */
        .app-center-overlay {
            position: fixed !important;
            inset: 0 !important;
            background: rgba(15, 23, 42, 0.55) !important;
            backdrop-filter: blur(5px) !important;
            -webkit-backdrop-filter: blur(5px) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            z-index: 9999999 !important;
            padding: 16px !important;
            box-sizing: border-box !important;
            animation: appCenterFadeIn 0.2s ease-out !important;
        }

        @keyframes appCenterFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Modal Box di Tengah Layar */
        .app-center-card {
            background: #ffffff !important;
            border-radius: 18px !important;
            width: 100% !important;
            max-width: 420px !important;
            padding: 28px 24px 24px !important;
            text-align: center !important;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.9) !important;
            position: relative !important;
            box-sizing: border-box !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            animation: appCenterScaleUp 0.22s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
        }

        @keyframes appCenterScaleUp {
            from { opacity: 0; transform: scale(0.9) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .app-center-card.closing {
            animation: appCenterScaleDown 0.18s ease-in forwards !important;
        }

        @keyframes appCenterScaleDown {
            from { opacity: 1; transform: scale(1); }
            to { opacity: 0; transform: scale(0.92); }
        }

        /* Ikon Lingkaran di Tengah Atas */
        .app-center-icon-wrap {
            width: 62px !important;
            height: 62px !important;
            border-radius: 50% !important;
            margin: 0 auto 16px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-sizing: border-box !important;
        }

        .app-center-icon-wrap svg {
            width: 30px !important;
            height: 30px !important;
            display: block !important;
        }

        .app-center-icon-wrap.danger {
            background: #FEE2E2 !important;
            color: #DC2626 !important;
            border: 4px solid #FEF2F2 !important;
        }

        .app-center-icon-wrap.warning {
            background: #FEF3C7 !important;
            color: #D97706 !important;
            border: 4px solid #FFFBEB !important;
        }

        .app-center-icon-wrap.primary {
            background: #E8F0FA !important;
            color: #1B4F8C !important;
            border: 4px solid #F4F8FD !important;
        }

        .app-center-icon-wrap.success {
            background: #D1FAE5 !important;
            color: #059669 !important;
            border: 4px solid #ECFDF5 !important;
        }

        .app-center-icon-wrap.info {
            background: #E0E7FF !important;
            color: #4338CA !important;
            border: 4px solid #EEF2FF !important;
        }

        /* Teks Judul & Pesan */
        .app-center-title {
            font-size: 18px !important;
            font-weight: 700 !important;
            color: #0F172A !important;
            margin: 0 0 8px !important;
            line-height: 1.35 !important;
        }

        .app-center-message {
            font-size: 13.5px !important;
            color: #475569 !important;
            line-height: 1.55 !important;
            margin: 0 0 22px !important;
            word-break: break-word !important;
        }

        /* Tombol Aksi */
        .app-center-actions {
            display: flex !important;
            gap: 10px !important;
            justify-content: center !important;
        }

        .app-center-btn {
            flex: 1 !important;
            padding: 11px 18px !important;
            border-radius: 10px !important;
            font-size: 13.5px !important;
            font-weight: 600 !important;
            font-family: inherit !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            transition: all 0.15s ease !important;
            border: none !important;
            text-decoration: none !important;
        }

        .app-center-btn-cancel {
            background: #F1F5F9 !important;
            color: #475569 !important;
            border: 1px solid #CBD5E1 !important;
        }

        .app-center-btn-cancel:hover {
            background: #E2E8F0 !important;
            color: #0F172A !important;
        }

        .app-center-btn-action {
            color: #ffffff !important;
            box-shadow: 0 2px 5px rgba(0,0,0,0.12) !important;
        }

        .app-center-btn-action.danger {
            background: #DC2626 !important;
        }
        .app-center-btn-action.danger:hover {
            background: #B91C1C !important;
        }

        .app-center-btn-action.warning {
            background: #D97706 !important;
        }
        .app-center-btn-action.warning:hover {
            background: #B45309 !important;
        }

        .app-center-btn-action.primary {
            background: #1B4F8C !important;
        }
        .app-center-btn-action.primary:hover {
            background: #123A68 !important;
        }

        .app-center-btn-action.success {
            background: #059669 !important;
        }
        .app-center-btn-action.success:hover {
            background: #047857 !important;
        }

        /* Auto-dismiss progress bar untuk feedback */
        .app-center-progress-wrap {
            width: 100% !important;
            height: 3px !important;
            background: #F1F5F9 !important;
            border-radius: 2px !important;
            margin-top: 14px !important;
            overflow: hidden !important;
        }
        .app-center-progress-bar {
            height: 100% !important;
            width: 100% !important;
            transform-origin: left !important;
            animation: appCenterProgress 2.2s linear forwards !important;
        }
        .app-center-progress-bar.success { background: #10B981 !important; }
        .app-center-progress-bar.error, .app-center-progress-bar.danger { background: #EF4444 !important; }
        .app-center-progress-bar.warning { background: #F59E0B !important; }
        .app-center-progress-bar.primary { background: #1B4F8C !important; }

        @keyframes appCenterProgress {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }
    `;

    const style = document.createElement('style');
    style.id = 'app-feedback-center-styles';
    style.textContent = css;
    if (document.head) {
        document.head.appendChild(style);
    } else {
        document.addEventListener('DOMContentLoaded', () => document.head.appendChild(style));
    }
})();

/**
 * Tampilkan dialog konfirmasi modern tepat di tengah halaman (Center Modal).
 * @param {Object} options
 * @param {string} options.title - Judul dialog konfirmasi
 * @param {string} options.message - Pertanyaan/penjelasan konfirmasi
 * @param {string} [options.confirmText="Ya, Lanjutkan"] - Teks tombol setuju
 * @param {string} [options.cancelText="Batal"] - Teks tombol batal
 * @param {'danger'|'warning'|'primary'|'success'} [options.type="primary"] - Jenis aksi/warna
 * @param {string} [options.icon] - Ikon ('delete', 'logout', 'add', 'edit', 'check', 'warning')
 * @returns {Promise<boolean>}
 */
function showConfirmModal({
    title = "Konfirmasi Aksi",
    message = "Apakah Anda yakin ingin melanjutkan tindakan ini?",
    confirmText = "Ya, Lanjutkan",
    cancelText = "Batal",
    type = "primary",
    icon = null
} = {}) {
    return new Promise((resolve) => {
        const existing = document.getElementById("app-confirm-overlay");
        if (existing) existing.remove();

        const overlay = document.createElement("div");
        overlay.id = "app-confirm-overlay";
        overlay.className = "app-center-overlay";

        const iconType = icon || (type === "danger" ? "delete" : type === "warning" ? "logout" : type === "success" ? "check" : "edit");

        let iconSvg = "";
        if (iconType === "delete") {
            iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>`;
        } else if (iconType === "logout") {
            iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>`;
        } else if (iconType === "add") {
            iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>`;
        } else if (iconType === "check") {
            iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`;
        } else if (iconType === "warning") {
            iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>`;
        } else {
            iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>`;
        }

        overlay.innerHTML = `
            <div class="app-center-card" role="dialog" aria-modal="true" aria-labelledby="app-confirm-title">
                <div class="app-center-icon-wrap ${type}">
                    ${iconSvg}
                </div>
                <h3 class="app-center-title" id="app-confirm-title">${title}</h3>
                <p class="app-center-message">${(message || "").toString().split("\n").join("<br>")}</p>
                <div class="app-center-actions">
                    <button type="button" class="app-center-btn app-center-btn-cancel" id="btn-app-confirm-cancel">
                        ${cancelText}
                    </button>
                    <button type="button" class="app-center-btn app-center-btn-action ${type}" id="btn-app-confirm-ok">
                        ${confirmText}
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);

        const card = overlay.querySelector(".app-center-card");
        const okBtn = overlay.querySelector("#btn-app-confirm-ok");
        const cancelBtn = overlay.querySelector("#btn-app-confirm-cancel");

        if (okBtn) okBtn.focus();

        function closeDialog(result) {
            document.removeEventListener("keydown", onKeyDown);
            if (card) card.classList.add("closing");
            setTimeout(() => {
                overlay.remove();
                resolve(result);
            }, 180);
        }

        function onKeyDown(e) {
            if (e.key === "Escape") {
                closeDialog(false);
            }
        }

        document.addEventListener("keydown", onKeyDown);
        if (cancelBtn) cancelBtn.onclick = () => closeDialog(false);
        if (okBtn) okBtn.onclick = () => closeDialog(true);
        overlay.onclick = (e) => {
            if (e.target === overlay) closeDialog(false);
        };
    });
}
window.showConfirmModal = showConfirmModal;

/**
 * Tampilkan notifikasi feedback tepat di TENGAH HALAMAN (Center Feedback Modal).
 * Memberikan feedback instan setelah suatu aksi selesai diproses.
 * @param {string} message - Pesan notifikasi
 * @param {'success'|'error'|'warning'|'info'} [type='success'] - Jenis status
 * @param {number} [duration=2200] - Lama tampil otomatis dalam ms
 */
function showCenterFeedback(message, type = "success", duration = 2200) {
    if (!message) return;

    const existing = document.getElementById("app-feedback-overlay");
    if (existing) existing.remove();

    const overlay = document.createElement("div");
    overlay.id = "app-feedback-overlay";
    overlay.className = "app-center-overlay";

    let iconSvg = "";
    let defaultTitle = "Pemberitahuan";
    if (type === "success") {
        defaultTitle = "Berhasil!";
        iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`;
    } else if (type === "error" || type === "danger") {
        defaultTitle = "Terjadi Kesalahan";
        iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>`;
    } else if (type === "warning") {
        defaultTitle = "Peringatan";
        iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>`;
    } else {
        defaultTitle = "Informasi";
        iconSvg = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>`;
    }

    const typeClass = (type === "error" || type === "danger") ? "danger" : (type === "warning" ? "warning" : (type === "info" ? "primary" : "success"));

    overlay.innerHTML = `
        <div class="app-center-card" role="alertdialog" aria-modal="true">
            <div class="app-center-icon-wrap ${typeClass}">
                ${iconSvg}
            </div>
            <h3 class="app-center-title">${defaultTitle}</h3>
            <p class="app-center-message">${(message || "").toString().split("\n").join("<br>")}</p>
            <div class="app-center-actions">
                <button type="button" class="app-center-btn app-center-btn-action ${typeClass}" id="btn-app-feedback-ok" style="max-width:130px; margin:0 auto;">
                    OK
                </button>
            </div>
            <div class="app-center-progress-wrap">
                <div class="app-center-progress-bar ${typeClass}"></div>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const card = overlay.querySelector(".app-center-card");
    const okBtn = overlay.querySelector("#btn-app-feedback-ok");
    if (okBtn) okBtn.focus();

    let timer = null;
    function closeFeedback() {
        if (timer) clearTimeout(timer);
        document.removeEventListener("keydown", onKeyDown);
        if (card) card.classList.add("closing");
        setTimeout(() => {
            overlay.remove();
        }, 180);
    }

    function onKeyDown(e) {
        if (e.key === "Escape" || e.key === "Enter") {
            closeFeedback();
        }
    }

    document.addEventListener("keydown", onKeyDown);
    if (okBtn) okBtn.onclick = closeFeedback;
    overlay.onclick = (e) => {
        if (e.target === overlay) closeFeedback();
    };

    if (duration > 0) {
        timer = setTimeout(closeFeedback, duration);
    }
}
window.showCenterFeedback = showCenterFeedback;

function showAlert(container, message, type = "error") {
    if (container) {
        container.innerHTML = `<div class="alert alert-${type === 'error' ? 'error' : 'success'}">${message}</div>`;
        if (type !== 'error') {
            setTimeout(() => { container.innerHTML = ""; }, 3000);
        }
    }
    // Munculkan feedback visual di TENGAH HALAMAN secara konsisten
    if (message) {
        showCenterFeedback(message, type === 'error' ? 'error' : 'success');
    }
}
window.showAlert = showAlert;

function formatDate(dateStr) {
    if (!dateStr) return "-";
    const d = new Date(dateStr);
    if (isNaN(d)) return dateStr;
    return d.toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" });
}

function formatDateTime(dateStr) {
    if (!dateStr) return "-";
    const d = new Date(dateStr);
    if (isNaN(d)) return dateStr;
    return d.toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" }) +
        " " + d.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}

function relasiLabel(relasi) {
    const map = {
        diri: "Diri Sendiri",
        atasan: "Atasan",
        bawahan: "Bawahan",
        rekan: "Rekan Kerja"
    };
    return map[relasi] || relasi;
}

function relasiTargetLabel(relasi) {
    const map = {
        diri: "Diri Sendiri",
        atasan: "Bawahan", // Jika role evaluator adalah atasan, maka yang dinilai adalah bawahannya
        bawahan: "Atasan", // Jika role evaluator adalah bawahan, maka yang dinilai adalah atasannya
        rekan: "Rekan Kerja"
    };
    return map[relasi] || relasi;
}

function getRelasiSortOrder(relasi) {
    // Urutan prioritas relasi: 1. Diri Sendiri, 2. Atasan, 3. Rekan Kerja, 4. Bawahan
    const orderMap = {
        diri: 1,    // Target: Diri Sendiri
        bawahan: 2, // Evaluator adalah bawahan -> Target: Atasan
        rekan: 3,   // Evaluator adalah rekan -> Target: Rekan Kerja
        atasan: 4   // Evaluator adalah atasan -> Target: Bawahan
    };
    return orderMap[relasi] || 5;
}

function sortTugasPenilaian(tugasList) {
    if (!Array.isArray(tugasList)) return tugasList;
    return tugasList.sort((a, b) => {
        const orderA = getRelasiSortOrder(a.jenis_relasi);
        const orderB = getRelasiSortOrder(b.jenis_relasi);
        if (orderA !== orderB) return orderA - orderB;
        return (a.nama_dinilai || '').localeCompare(b.nama_dinilai || '');
    });
}

function roleLabel(role) {
    const map = {
        super_admin: "Super Admin",
        admin: "Admin",
        pegawai: "Pegawai"
    };
    return map[role] || role;
}

function statusBadge(status) {
    const map = {
        /* Mapping status */
        selesai:     '<span class="badge badge-green">Selesai</span>',
        proses:      '<span class="badge badge-yellow">Proses</span>',
        belum_mulai: '<span class="badge badge-gray">Belum Mulai</span>',
        draft:       '<span class="badge badge-gray">Draft</span>',
        /* Periode */
        aktif:       '<span class="badge badge-green">Aktif</span>',
        ditutup:     '<span class="badge badge-red">Ditutup</span>',
        diarsipkan:  '<span class="badge badge-gray">Diarsipkan</span>',
        /* Akun */
        terkunci:    '<span class="badge badge-red">Terkunci</span>',
        /* Status aktif pegawai (v3) */
        nonaktif:    '<span class="badge badge-red">Nonaktif</span>',
        pensiun:     '<span class="badge badge-yellow">Pensiun</span>',
        mutasi:      '<span class="badge badge-blue">Mutasi</span>',
        meninggal:   '<span class="badge badge-gray">Meninggal</span>',
    };
    return map[status] || `<span class="badge badge-gray">${status || '-'}</span>`;
}


function predikatBadge(predikat) {
    const map = {
        'Sangat Baik': '<span class="badge badge-green">Sangat Baik</span>',
        'Baik': '<span class="badge badge-blue">Baik</span>',
        'Cukup': '<span class="badge badge-yellow">Cukup</span>',
        'Kurang': '<span class="badge badge-orange">Kurang</span>',
        'Buruk': '<span class="badge badge-red">Buruk</span>',
        'Sangat Kurang': '<span class="badge badge-red">Buruk</span>'
    };
    return map[predikat] || '<span class="badge badge-gray">Belum Dihitung</span>';
}

/**
 * Render pagination controls inside a container element.
 * @param {Object} meta     - { total, page, limit, totalPages } from API response
 * @param {string} containerId - id of the <div> that will hold pagination buttons
 * @param {Function} onPageChange - callback(page) called when user clicks a page button
 */
function renderPagination(meta, containerId, onPageChange) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (!meta || meta.totalPages <= 1) { container.innerHTML = ''; return; }

    const { page, totalPages, total, limit } = meta;
    const start = (page - 1) * limit + 1;
    const end = Math.min(page * limit, total);

    // Build page number buttons (show max 5 around current page)
    let pages = [];
    const delta = 2;
    for (let i = Math.max(1, page - delta); i <= Math.min(totalPages, page + delta); i++) {
        pages.push(i);
    }
    const hasLeftGap  = pages[0] > 2;
    const hasRightGap = pages[pages.length - 1] < totalPages - 1;

    let numbersHTML = '';
    if (pages[0] > 1) {
        numbersHTML += `<button class="pg-btn" data-page="1">1</button>`;
        if (hasLeftGap) numbersHTML += `<span class="pg-ellipsis">…</span>`;
    }
    pages.forEach(p => {
        numbersHTML += `<button class="pg-btn ${p === page ? 'pg-active' : ''}" data-page="${p}">${p}</button>`;
    });
    if (pages[pages.length - 1] < totalPages) {
        if (hasRightGap) numbersHTML += `<span class="pg-ellipsis">…</span>`;
        numbersHTML += `<button class="pg-btn" data-page="${totalPages}">${totalPages}</button>`;
    }

    container.innerHTML = `
        <div class="pagination-wrap">
            <span class="pg-info">Menampilkan ${start}–${end} dari ${total} data</span>
            <div class="pg-controls">
                <button class="pg-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>‹ Prev</button>
                ${numbersHTML}
                <button class="pg-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Next ›</button>
            </div>
        </div>`;

    container.querySelectorAll('.pg-btn:not([disabled])').forEach(btn => {
        btn.addEventListener('click', () => onPageChange(parseInt(btn.dataset.page)));
    });
}

/** Utility escape HTML untuk mencegah XSS injection */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Render Detail Capaian Penilaian per Kriteria, Indikator, dan Butir Pertanyaan.
 * Digunakan bersama oleh halaman Admin (admin/hasil.html) dan Pegawai (pegawai/hasil.html).
 *
 * @param {Array}  rincian - data.rincian_indikator dari results.php
 * @param {string} prefix  - prefix ID unik ('admin' / 'pegawai')
 * @param {Object} opts    - { hasBawahan, bobotDigunakan }
 */
function buildRincianIndikatorHtml(rincian, prefix = "rincian", opts = {}) {
    if (!rincian || rincian.length === 0) {
        return `<div style="border:1px dashed var(--border); border-radius:8px; padding:20px; text-align:center; color:var(--text-muted); font-size:13px; margin-top:20px;">Belum ada data indikator penilaian.</div>`;
    }

    const hasBawahan = Boolean(opts.hasBawahan);
    const bobotDigunakan = opts.bobotDigunakan || {};
    const fmtBobot = (rel) => {
        const val = bobotDigunakan[rel];
        return (val !== undefined && val !== null && Number(val) > 0) ? ` (${Number(val)}%)` : '';
    };

    const relasiBadgeStyle = {
        atasan: 'background:var(--primary-light); color:var(--primary); border:1px solid rgba(27,79,140,0.25);',
        rekan: 'background:#FEF3C7; color:#92400E; border:1px solid rgba(245,158,11,0.25);',
        bawahan: 'background:#D1FAE5; color:#065F46; border:1px solid rgba(16,185,129,0.25);',
        diri: 'background:#EDE9FE; color:#5B21B6; border:1px solid rgba(139,92,246,0.25);'
    };

    const getPredBadge = (pred) => {
        if (!pred) return `<span class="badge" style="background:#E2E8F0; color:#475569;">Belum dinilai</span>`;
        if (pred === 'Sangat Baik') return `<span class="badge badge-green">Sangat Baik</span>`;
        if (pred === 'Baik') return `<span class="badge badge-blue">Baik</span>`;
        if (pred === 'Cukup') return `<span class="badge badge-yellow">Cukup</span>`;
        if (pred === 'Kurang') return `<span class="badge badge-orange">Kurang</span>`;
        return `<span class="badge badge-red">${pred === 'Sangat Kurang' ? 'Buruk' : (pred || 'Buruk')}</span>`;
    };

    const getProgressBarColor = (persen) => {
        if (!persen) return '#94A3B8';
        if (persen >= 91) return 'var(--success)';
        if (persen >= 76) return 'var(--primary)';
        if (persen >= 61) return 'var(--warning)';
        return 'var(--danger)';
    };

    let html = `
        <div style="margin-top:24px; margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
                <div>
                    <h4 style="margin:0; font-size:15px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:8px;">
                        <svg style="width:18px; height:18px; fill:var(--primary);" viewBox="0 0 24 24">
                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                        </svg>
                        Detail Capaian Penilaian per Kriteria &amp; Indikator
                    </h4>
                    <p style="margin:3px 0 0 0; font-size:12px; color:var(--text-muted);">
                        Skor dihitung terbobot sesuai komposisi relasi penilai 360&deg; (${hasBawahan ? 'Pejabat Struktural' : 'Pegawai Pelaksana / Fungsional'}).
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:10px; font-size:11px; flex-wrap:wrap;">
                    <span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px; height:10px; border-radius:50%; background:var(--primary); display:inline-block;"></span> Atasan${fmtBobot('atasan')}</span>
                    <span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px; height:10px; border-radius:50%; background:var(--warning); display:inline-block;"></span> Rekan Kerja${fmtBobot('rekan')}</span>
                    ${hasBawahan ? `<span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px; height:10px; border-radius:50%; background:var(--success); display:inline-block;"></span> Bawahan${fmtBobot('bawahan')}</span>` : ''}
                    <span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px; height:10px; border-radius:50%; background:var(--purple); display:inline-block;"></span> Diri Sendiri${fmtBobot('diri')}</span>
                </div>
            </div>
        </div>
    `;

    html += `<div style="display:flex; flex-direction:column; gap:16px;">`;

    rincian.forEach((kat, kIdx) => {
        const katRataText = kat.rata_skor !== null ? `${Number(kat.rata_skor).toFixed(2)} / 5.00 (${kat.persentase}%)` : `Belum dinilai`;
        const qCountBawahan = kat.jumlah_q_bawahan ?? 0;
        const qCountRekan = kat.jumlah_q_rekan ?? 0;
        const bPaketBawahan = kat.bobot_paket_bawahan ?? (qCountBawahan * 5);
        const bPaketRekan = kat.bobot_paket_rekan ?? (qCountRekan * 5);
        const totalButirKat = kat.total_pertanyaan ?? (kat.indikator ? kat.indikator.reduce((acc, i) => acc + (i.daftar_pertanyaan ? i.daftar_pertanyaan.length : 0), 0) : 0);

        let bobotKatBadges = '';
        if (hasBawahan && qCountBawahan > 0 && qCountRekan > 0) {
            bobotKatBadges = `
                <span class="badge badge-blue" style="font-size:11px; padding:2px 8px;" title="Porsi butir pada Paket Penilaian Atasan/Rekan/Diri">Paket Rekan/Atasan/Diri: ${qCountRekan} Butir (${bPaketRekan}%)</span>
                <span class="badge badge-green" style="font-size:11px; padding:2px 8px;" title="Porsi butir pada Paket Penilaian Bawahan">Paket Bawahan: ${qCountBawahan} Butir (${bPaketBawahan}%)</span>
            `;
        } else if (qCountBawahan > 0) {
            bobotKatBadges = `<span class="badge badge-green" style="font-size:11px; padding:2px 8px;">${qCountBawahan} Butir &bull; Bobot Paket Bawahan ${bPaketBawahan}%</span>`;
        } else {
            bobotKatBadges = `<span class="badge badge-blue" style="font-size:11px; padding:2px 8px;">${totalButirKat} Butir &bull; Bobot Kriteria ${bPaketRekan}%</span>`;
        }

        html += `
            <div style="border:1px solid var(--border); border-radius:8px; overflow:hidden; background:var(--card-bg);">
                <div class="rincian-kat-header" style="background:var(--bg); border-bottom:1px solid var(--border); padding:12px 16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span style="background:var(--primary); color:white; font-size:11px; font-weight:700; padding:2px 8px; border-radius:4px;">${escapeHtml(kat.kode_kategori || 'KAT')}</span>
                        <strong style="font-size:14px; color:var(--text);">${escapeHtml(kat.nama_kategori)}</strong>
                        ${bobotKatBadges}
                    </div>
                    <div style="font-size:12px; color:var(--text-secondary);">
                        Capaian Terbobot: <strong style="color:var(--text);">${katRataText}</strong>
                    </div>
                </div>
                <div>
        `;

        kat.indikator.forEach((ind, iIdx) => {
            const toggleId = `${prefix}-qlist-${kIdx}-${iIdx}`;
            const btnId = `${prefix}-btn-${kIdx}-${iIdx}`;
            const indRataText = ind.rata_skor !== null ? Number(ind.rata_skor).toFixed(2) : '-';
            const indPersenText = ind.persentase !== null ? `${ind.persentase}%` : '-';
            const progressColor = getProgressBarColor(ind.persentase);
            const isLast = iIdx === kat.indikator.length - 1;
            const isKep = (kat.kode_kategori === 'KEP');

            const visibleRelations = isKep
                ? ['bawahan']
                : (hasBawahan ? ['atasan', 'rekan', 'bawahan', 'diri'] : ['atasan', 'rekan', 'diri']);

            const relasiPills = visibleRelations.map(rel => {
                const val = ind.skor_relasi ? ind.skor_relasi[rel] : null;
                const text = val !== null && val !== undefined ? Number(val).toFixed(2) : '-';
                const wTag = (bobotDigunakan[rel] > 0 && !isKep) ? ` (${Number(bobotDigunakan[rel])}%)` : '';
                return `<span style="display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600; ${relasiBadgeStyle[rel]}">
                    <span>${relasiLabel(rel)}${wTag}:</span>
                    <span>${text}</span>
                </span>`;
            }).join(" ");

            let qRowsHtml = "";
            (ind.daftar_pertanyaan || []).forEach((q, qIdx) => {
                const qRataText = q.rata_skor !== null ? Number(q.rata_skor).toFixed(2) : '-';
                const qPersenText = q.persentase !== null ? `${q.persentase}%` : '-';
                const targetParts = String(q.target_relasi || '').split(',').map(s => s.trim()).filter(Boolean);
                const isPaketBawahan = targetParts.includes('bawahan') && !targetParts.includes('rekan');

                let paketBadge = '';
                if (hasBawahan && !isKep) {
                    paketBadge = isPaketBawahan
                        ? `<span class="badge badge-green" style="font-size:10px; padding:1px 6px; margin-top:4px;">Paket Bawahan</span>`
                        : `<span class="badge badge-blue" style="font-size:10px; padding:1px 6px; margin-top:4px;">Paket Atasan / Rekan / Diri</span>`;
                }

                const qRelasiCells = ['atasan', 'rekan', 'bawahan', 'diri'].map(rel => {
                    const applies = targetParts.length === 0 || targetParts.includes(rel);
                    if (!applies || (rel === 'bawahan' && !hasBawahan)) {
                        return `<td style="text-align:center; padding:6px 8px; font-size:11px;"><span class="text-muted" title="Tidak termasuk paket penilai ini">&mdash;</span></td>`;
                    }
                    const val = q.skor_relasi ? q.skor_relasi[rel] : null;
                    return `<td style="text-align:center; padding:6px 8px; font-size:11px; font-weight:600;">${val !== null && val !== undefined ? Number(val).toFixed(2) : '<span class="text-muted">-</span>'}</td>`;
                }).join("");

                qRowsHtml += `
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:8px 10px; font-size:12px; color:var(--text-secondary); width:30px; text-align:center;">${qIdx + 1}</td>
                        <td style="padding:8px 10px; font-size:12px; color:var(--text); min-width:220px;">
                            <div>${escapeHtml(q.pertanyaan)}</div>
                            ${paketBadge ? `<div>${paketBadge}</div>` : ''}
                        </td>
                        <td style="padding:8px 10px; font-size:11px; font-weight:600; color:var(--text-secondary); text-align:center; white-space:nowrap;">${Number(q.bobot)}%</td>
                        ${qRelasiCells}
                        <td style="padding:8px 10px; font-size:12px; font-weight:700; color:var(--text); text-align:center; white-space:nowrap;">${qRataText} <span style="font-weight:400; font-size:11px; color:var(--text-muted);">(${qPersenText})</span></td>
                    </tr>
                `;
            });

            const qListCount = ind.daftar_pertanyaan ? ind.daftar_pertanyaan.length : 0;

            html += `
                <div style="padding:14px 16px; ${isLast ? '' : 'border-bottom:1px solid var(--border);'}">
                    <div class="rincian-ind-wrap" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
                        <div class="rincian-ind-left" style="flex:1; min-width:240px;">
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:6px;">
                                <span style="font-size:11px; font-weight:700; color:var(--primary); background:var(--primary-light); padding:2px 7px; border-radius:3px;">${escapeHtml(ind.kode_indikator || 'IND')}</span>
                                <strong style="font-size:13px; color:var(--text);">${escapeHtml(ind.nama_indikator)}</strong>
                                ${isKep ? `<span style="font-size:11px; color:var(--text-muted);">(Khusus Penilaian Bawahan)</span>` : ''}
                            </div>
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:6px;">
                                ${relasiPills}
                            </div>
                        </div>
                        <div class="rincian-ind-right" style="text-align:right; min-width:180px;">
                            <div class="rincian-ind-score-row" style="display:flex; align-items:center; justify-content:flex-end; gap:8px;">
                                <div style="font-size:14px; font-weight:800; color:var(--text);">${indRataText} <span style="font-size:11px; font-weight:500; color:var(--text-muted);">/ 5.00</span></div>
                                <div style="font-size:12px; font-weight:600; color:var(--text-secondary);">(${indPersenText})</div>
                                ${getPredBadge(ind.predikat)}
                            </div>
                            <div class="rincian-ind-bar-bg" style="width:100%; max-width:180px; height:6px; background:var(--border); border-radius:3px; overflow:hidden; margin-top:6px; margin-left:auto;">
                                <div style="width:${ind.persentase || 0}%; height:100%; background:${progressColor}; border-radius:3px; transition:width 0.3s;"></div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:10px;">
                        <button id="${btnId}" class="btn btn-secondary btn-sm" style="font-size:11px; padding:5px 10px; display:inline-flex; align-items:center; gap:4px;"
                            onclick="(function(){
                                var el = document.getElementById('${toggleId}');
                                var btn = document.getElementById('${btnId}');
                                var isHidden = el.style.display === 'none' || getComputedStyle(el).display === 'none';
                                el.style.display = isHidden ? 'block' : 'none';
                                btn.innerHTML = isHidden
                                    ? '<svg style=\\'width:12px;height:12px;fill:currentColor;transform:rotate(180deg);\\' viewBox=\\'0 0 24 24\\'><path d=\\'M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z\\'/></svg> Sembunyikan Pertanyaan (${qListCount})'
                                    : '<svg style=\\'width:12px;height:12px;fill:currentColor;\\' viewBox=\\'0 0 24 24\\'><path d=\\'M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z\\'/></svg> Lihat Butir Pertanyaan (${qListCount})';
                            })()">
                            <svg style="width:12px;height:12px;fill:currentColor;" viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
                            Lihat Butir Pertanyaan (${qListCount})
                        </button>

                        <div id="${toggleId}" class="rincian-q-wrap" style="display:none; margin-top:8px; border:1px solid var(--border); border-radius:6px; overflow-x:auto;">
                            <table class="rincian-q-table" style="width:100%; border-collapse:collapse; background:var(--card-bg);">
                                <thead>
                                    <tr style="background:var(--bg); border-bottom:1px solid var(--border); font-size:11px; color:var(--text-secondary);">
                                        <th style="padding:6px 10px; text-align:center; width:30px;">#</th>
                                        <th style="padding:6px 10px; text-align:left;">Butir Pertanyaan</th>
                                        <th style="padding:6px 10px; text-align:center; width:55px;" title="Bobot per butir dalam paket 20 pertanyaan (20 x 5% = 100%)">Bobot</th>
                                        <th style="padding:6px 8px; text-align:center; width:55px; color:var(--primary);">Atasan</th>
                                        <th style="padding:6px 8px; text-align:center; width:55px; color:var(--warning);">Rekan</th>
                                        <th style="padding:6px 8px; text-align:center; width:55px; color:var(--success);">Bawahan</th>
                                        <th style="padding:6px 8px; text-align:center; width:55px; color:var(--purple);">Diri</th>
                                        <th style="padding:6px 10px; text-align:center; width:90px;">Skor Terbobot</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${qRowsHtml || '<tr><td colspan="8" style="padding:10px; text-align:center; color:var(--text-muted); font-size:11px;">Tidak ada pertanyaan aktif</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    });

    html += `</div>`;
    return html;
}
