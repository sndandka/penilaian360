<?php
/**
 * Helper untuk validasi login, Role Based Access Control (RBAC), dan audit log.
 *
 * HIERARKI ROLE:
 *   - super_admin : akses penuh ke seluruh modul termasuk konfigurasi sistem
 *   - admin       : akses operasional (kelola pegawai, periode, mapping, laporan)
 *                   TIDAK bisa ubah konfigurasi sistem & bobot penilaian
 *   - pegawai     : mengisi penilaian & melihat hasil diri sendiri
 *
 * Catatan penting:
 *   - super_admin & admin TIDAK memiliki pegawai_id (bukan pegawai)
 *   - pegawai SELALU memiliki pegawai_id (terhubung ke tabel pegawai)
 */
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../config/csrf.php';

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        sendResponse(false, "Anda belum login. Silakan login terlebih dahulu.", null, 401);
    }
    // CSRF hanya wajib untuk request yang mengubah state (POST/PUT/DELETE)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'OPTIONS', 'HEAD'])) {
        requireCsrf();
    }
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole() {
    return $_SESSION['role'] ?? null;
}

function currentPegawaiId() {
    return $_SESSION['pegawai_id'] ?? null;
}

/**
 * Memastikan role user login termasuk salah satu dari daftar $roles.
 * Contoh: requireRoles(['super_admin', 'admin']);
 */
function requireRoles(array $roles) {
    requireLogin();
    if (!in_array(currentUserRole(), $roles, true)) {
        sendResponse(false, "Akses ditolak. Anda tidak memiliki hak akses ke modul ini.", null, 403);
    }
}

/** Hanya Super Admin (akses penuh termasuk konfigurasi sistem). */
function requireSuperAdmin() {
    requireRoles(['super_admin']);
}

/**
 * Akses untuk Super Admin DAN Admin.
 * Gunakan ini untuk endpoint yang bisa diakses keduanya
 * (mis. kelola pegawai, periode, mapping, laporan).
 */
function requireAdmin() {
    requireRoles(['super_admin', 'admin']);
}

/** Cek role tanpa memblokir */
function isSuperAdmin()  { return currentUserRole() === 'super_admin'; }
function isAdmin()       { return currentUserRole() === 'admin'; }
function isAdminOrSuperAdmin() { return in_array(currentUserRole(), ['super_admin', 'admin'], true); }
function isPegawaiRole() { return currentUserRole() === 'pegawai'; }

/**
 * AUDIT LOG
 * Mencatat aktivitas ke tabel audit_log, termasuk data sebelum/sesudah
 * perubahan (untuk akuntabilitas sesuai standar pemerintahan).
 *
 * @param PDO    $pdo
 * @param string $modul       nama modul, contoh: 'pegawai', 'periode_penilaian'
 * @param string $aktivitas   contoh: 'create', 'update', 'delete', 'login', 'submit_penilaian'
 * @param array|null $dataSebelum
 * @param array|null $dataSesudah
 */
function logAudit($pdo, $modul, $aktivitas, $dataSebelum = null, $dataSesudah = null) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, modul, aktivitas, data_sebelum, data_sesudah, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            currentUserId(),
            $modul,
            $aktivitas,
            $dataSebelum !== null ? json_encode($dataSebelum, JSON_UNESCAPED_UNICODE) : null,
            $dataSesudah !== null ? json_encode($dataSesudah, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Exception $e) {
        // Jangan sampai kegagalan logging menghentikan proses utama.
    }
}

