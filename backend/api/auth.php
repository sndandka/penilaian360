<?php
/**
 * USE CASE: Login (3 Role: super_admin, admin & pegawai) & Sesi
 *
 *  - super_admin & admin : login menggunakan USERNAME (mis. "superadmin", "admin")
 *                          pegawai_id = NULL (tidak terhubung ke data pegawai)
 *  - pegawai             : login menggunakan NIP (tersimpan di kolom username)
 *                          pegawai_id terhubung ke tabel pegawai
 *
 * Endpoints:
 *  POST   /api/auth.php?action=login
 *  POST   /api/auth.php?action=logout
 *  GET    /api/auth.php?action=me
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && $action === 'login') {
    // Catatan: CSRF tidak divalidasi di sini karena session belum ada sebelum login.
    // CSRF hanya berlaku untuk request yang SUDAH memiliki sesi aktif.
    $input = getJsonInput();
    $username = cleanStr($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');

    if (empty($username) || empty($password)) {
        sendResponse(false, "Username/NIP dan password wajib diisi.", null, 400);
    }

    /**
     * Login UNIFIED — semua role dicari via kolom `username`:
     *   - Admin/SuperAdmin : username berupa kata (mis. "admin", "superadmin"), pegawai_id IS NULL
     *   - Pegawai          : username berupa NIP 18 digit, pegawai_id IS NOT NULL
     * Tidak ada perbedaan endpoint; perbedaan hanya pada data yang dimiliki.
     */
    $stmt = $pdo->prepare(
        "SELECT u.*, p.nama_lengkap, p.nip, p.golongan_ruang, p.email AS email_pegawai, p.jabatan_id, p.unit_kerja_id,
                j.nama_jabatan, uk.nama_unit
         FROM users u
         LEFT JOIN pegawai p  ON u.pegawai_id = p.id
         LEFT JOIN jabatan j  ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE u.username = ? OR (p.nip = ? AND p.nip IS NOT NULL AND p.nip != '')
         ORDER BY CASE WHEN u.username = ? THEN 1 ELSE 2 END
         LIMIT 1"
    );
    $stmt->execute([$username, $username, $username]);
    $user = $stmt->fetch();

    $validPassword = false;
    if ($user) {
        if (password_verify($password, $user['password'])) {
            $validPassword = true;
        } elseif (password_verify(trim($password), $user['password'])) {
            $validPassword = true;
        } elseif ($user['password'] === $password || $user['password'] === trim($password)) {
            // Legacy plaintext fallback: upgrade ke hash bcrypt
            $validPassword = true;
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
        }
    }

    if (!$user || !$validPassword) {
        // Catat percobaan gagal tanpa membocorkan apakah username ada
        logAudit($pdo, 'auth', 'login_gagal', null, ['username_or_nip' => $username]);
        sendResponse(false, "Username/NIP atau password salah.", null, 401);
    }

    if ($user['status'] !== 'aktif') {
        sendResponse(false, "Akun Anda tidak aktif/terkunci. Hubungi Super Admin.", null, 403);
    }

    // Integritas keamanan: akun yang memiliki pegawai_id/NIP tidak boleh ber-role admin atau super_admin.
    // Akun admin dan super_admin adalah akun sistem mandiri tanpa pegawai_id.
    if (!empty($user['pegawai_id']) && $user['role'] !== 'pegawai') {
        $user['role'] = 'pegawai';
        $pdo->prepare("UPDATE users SET role = 'pegawai' WHERE id = ?")->execute([$user['id']]);
    }
    if (in_array($user['role'], ['super_admin', 'admin'], true) && !empty($user['pegawai_id'])) {
        $user['pegawai_id'] = null;
        $pdo->prepare("UPDATE users SET pegawai_id = NULL WHERE id = ?")->execute([$user['id']]);
    }

    // Cegah session fixation: regenerasi ID sesi setiap kali login berhasil.
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['nama']       = $user['nama_lengkap'] ?? $user['username'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['pegawai_id'] = $user['pegawai_id'];
    $_SESSION['golongan_ruang'] = $user['golongan_ruang'] ?? null;
    unset($_SESSION['csrf_token']); // token lama (anonim) dibuang
    $csrfToken = generateCsrfToken();

    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    logAudit($pdo, 'auth', 'login', null, ['username' => $user['username'], 'role' => $user['role']]);

    unset($user['password']);
    $user['csrf_token'] = $csrfToken;
    sendResponse(true, "Login berhasil.", $user);
}

if ($method === 'POST' && $action === 'logout') {
    requireCsrf();
    if (isset($_SESSION['user_id'])) {
        logAudit($pdo, 'auth', 'logout', null, ['username' => $_SESSION['username'] ?? null]);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    sendResponse(true, "Logout berhasil.");
}

if ($method === 'GET' && $action === 'me') {
    // Selalu sediakan csrf_token yang berlaku untuk sesi ini (dibuat jika belum ada)
    $csrfToken = generateCsrfToken();

    if (!isset($_SESSION['user_id'])) {
        sendResponse(false, "Anda belum login. Silakan login terlebih dahulu.", ["csrf_token" => $csrfToken], 401);
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.role, u.pegawai_id, u.status, u.last_login,
                p.nip, p.nama_lengkap, p.golongan_ruang, p.email, p.foto,
                p.jabatan_id, j.nama_jabatan AS jabatan,
                p.unit_kerja_id, uk.nama_unit AS unit_kerja
         FROM users u
         LEFT JOIN pegawai p  ON u.pegawai_id = p.id
         LEFT JOIN jabatan j  ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE u.id = ?"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) sendResponse(false, "User tidak ditemukan.", null, 404);
    $user['nama'] = $user['nama_lengkap'] ?? $user['username'];
    $user['csrf_token'] = $csrfToken;
    sendResponse(true, "OK", $user);
}

sendResponse(false, "Aksi tidak dikenali.", null, 404);
