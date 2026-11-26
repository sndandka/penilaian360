<?php
/**
 * USE CASE: Pengaturan Profil & Password Pengguna (Pegawai & Admin)
 * Endpoints:
 *  GET    /api/profile.php?action=get     -> Ambil data profil saat ini
 *  POST   /api/profile.php?action=update  -> Update username & email
 *  POST   /api/profile.php?action=password -> Ganti password
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ──────────────────────────────────────────────────────────────
// GET  /api/profile.php?action=get
// Mengembalikan data profil lengkap pengguna yang sedang login
// ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'get') {
    requireLogin();

    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.role, u.last_login,
                p.nip, p.nama_lengkap, p.golongan_ruang, p.tmt_golongan, p.status_kepegawaian, p.email, p.foto,
                j.nama_jabatan AS jabatan, j.eselon,
                uk.nama_unit   AS unit_kerja
         FROM users u
         LEFT JOIN pegawai    p  ON u.pegawai_id    = p.id
         LEFT JOIN jabatan    j  ON p.jabatan_id    = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE u.id = ? LIMIT 1"
    );
    $stmt->execute([currentUserId()]);
    $user = $stmt->fetch();

    if (!$user) sendResponse(false, "Pengguna tidak ditemukan.", null, 404);

    unset($user['password']);
    sendResponse(true, "OK", $user);
}

// ──────────────────────────────────────────────────────────────
// POST /api/profile.php?action=update
// Update username dan/atau email pengguna yang sedang login
// ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'update') {
    requireLogin();

    $input    = getJsonInput();
    $userId   = currentUserId();
    $username = cleanStr($input['username'] ?? '');
    $email    = cleanStr($input['email'] ?? '');

    // ── Validasi username ──────────────────────────────────────
    if (empty($username)) sendResponse(false, "Username tidak boleh kosong.", null, 400);
    if (strlen($username) < 4) sendResponse(false, "Username minimal 4 karakter.", null, 400);
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        sendResponse(false, "Username hanya boleh huruf, angka, titik, dan underscore.", null, 400);
    }

    // ── Cek duplikat username (kecuali milik sendiri) ──────────
    $dup = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
    $dup->execute([$username, $userId]);
    if ($dup->fetch()) {
        sendResponse(false, "Username '$username' sudah digunakan oleh pengguna lain.", null, 409);
    }

    // ── Validasi email opsional ────────────────────────────────
    if (!empty($email)) {
        validateEmailIfPresent($email, 'Email');
    }

    // ── Ambil data lama untuk audit log ───────────────────────
    $old = $pdo->prepare("SELECT u.username, p.email FROM users u LEFT JOIN pegawai p ON u.pegawai_id = p.id WHERE u.id = ?");
    $old->execute([$userId]);
    $oldData = $old->fetch();

    // ── Update username di tabel users ────────────────────────
    $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$username, $userId]);

    // ── Update email di tabel pegawai (jika ada pegawai_id) ───
    $pegawaiId = $_SESSION['pegawai_id'] ?? null;
    if ($pegawaiId && !empty($email)) {
        $pdo->prepare("UPDATE pegawai SET email = ? WHERE id = ?")->execute([$email, $pegawaiId]);
    }

    // ── Update session username ────────────────────────────────
    $_SESSION['username'] = $username;

    logAudit($pdo, 'profile', 'update_profil',
        ['username' => $oldData['username'], 'email' => $oldData['email']],
        ['username' => $username, 'email' => $email]
    );

    sendResponse(true, "Profil berhasil diperbarui.");
}

// ──────────────────────────────────────────────────────────────
// POST /api/profile.php?action=password
// Ganti password; wajib verifikasi password lama
// ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'password') {
    requireLogin();

    $input       = getJsonInput();
    $userId      = currentUserId();
    $oldPassword = (string)($input['old_password'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');
    $confirmPwd  = (string)($input['confirm_password'] ?? '');

    if (empty($oldPassword)) sendResponse(false, "Password lama wajib diisi.", null, 400);
    if (empty($newPassword))  sendResponse(false, "Password baru wajib diisi.", null, 400);

    // ── Ambil hash password saat ini ──────────────────────────
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) sendResponse(false, "Pengguna tidak ditemukan.", null, 404);

    if (!password_verify($oldPassword, $row['password'])) {
        sendResponse(false, "Password lama tidak sesuai. Silakan periksa kembali.", null, 401);
    }

    // ── Validasi password baru ─────────────────────────────────
    if (strlen($newPassword) < 8) {
        sendResponse(false, "Password baru minimal 8 karakter.", null, 400);
    }
    if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        sendResponse(false, "Password baru harus mengandung kombinasi huruf dan angka.", null, 400);
    }
    if ($newPassword !== $confirmPwd) {
        sendResponse(false, "Konfirmasi password baru tidak cocok.", null, 400);
    }
    if (password_verify($newPassword, $row['password'])) {
        sendResponse(false, "Password baru tidak boleh sama dengan password lama.", null, 400);
    }

    // ── Simpan hash baru ───────────────────────────────────────
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);

    logAudit($pdo, 'profile', 'ganti_password', null, ['user_id' => $userId]);

    sendResponse(true, "Password berhasil diperbarui. Silakan login kembali jika diperlukan.");
}

sendResponse(false, "Aksi tidak dikenali.", null, 404);
