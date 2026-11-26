<?php
/**
 * USE CASE: Manajemen Akun Administrator & Super Admin (Super Admin Only)
 *
 * Mengizinkan Super Admin untuk:
 *  - GET    /api/users.php?role=admin    -> Daftar semua akun admin & super_admin
 *  - POST   /api/users.php               -> Membuat akun admin atau super_admin baru
 *  - PUT    /api/users.php?id={id}       -> Ubah status (aktif/nonaktif) atau reset password admin & super_admin
 *  - DELETE /api/users.php?id={id}       -> Hapus akun admin atau super_admin (dengan proteksi akun utama)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Hanya Super Admin yang memiliki hak akses untuk mengelola akun administrator & super admin
requireSuperAdmin();

switch ($method) {

    case 'GET':
        // Ambil daftar user administrator (admin dan super_admin)
        $roleFilter = cleanStr($_GET['role'] ?? '');
        if ($roleFilter === 'admin') {
            $stmt = $pdo->prepare(
                "SELECT id, username, role, status, is_registered, last_login, created_at, updated_at 
                 FROM users 
                 WHERE role = 'admin' 
                 ORDER BY created_at DESC"
            );
            $stmt->execute();
        } elseif ($roleFilter === 'super_admin') {
            $stmt = $pdo->prepare(
                "SELECT id, username, role, status, is_registered, last_login, created_at, updated_at 
                 FROM users 
                 WHERE role = 'super_admin' 
                 ORDER BY created_at ASC"
            );
            $stmt->execute();
        } else {
            $stmt = $pdo->query(
                "SELECT id, username, role, status, is_registered, last_login, created_at, updated_at 
                 FROM users 
                 WHERE role IN ('super_admin', 'admin') 
                 ORDER BY CASE WHEN role = 'super_admin' THEN 1 WHEN role = 'admin' THEN 2 ELSE 3 END, created_at ASC"
            );
        }
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse(true, "Data akun administrator & super admin berhasil dimuat.", $admins);
        break;

    case 'POST':
        $input = getJsonInput();
        requireFields($input, ['username', 'password']);

        $username = cleanStr($input['username']);
        $password = (string)($input['password'] ?? '');
        $role = cleanStr($input['role'] ?? 'admin');

        // Validasi role: hanya boleh 'admin' atau 'super_admin'
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            $role = 'admin';
        }

        // Validasi format username
        if (strlen($username) < 3 || strlen($username) > 50) {
            sendResponse(false, "Username harus terdiri dari 3 hingga 50 karakter.", null, 400);
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            sendResponse(false, "Username hanya boleh mengandung huruf, angka, garis bawah (_), titik (.), atau tanda hubung (-).", null, 400);
        }

        // Validasi password
        if (strlen($password) < 6) {
            sendResponse(false, "Password minimal 6 karakter.", null, 400);
        }

        // Cek apakah username sudah ada
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $checkStmt->execute([$username]);
        if ($checkStmt->fetch()) {
            sendResponse(false, "Username '{$username}' sudah digunakan. Silakan gunakan username lain.", null, 409);
        }

        // Hash password menggunakan BCRYPT
        $hashPassword = password_hash($password, PASSWORD_BCRYPT);

        // Akun admin / super admin adalah akun mandiri tanpa pegawai_id
        $insertStmt = $pdo->prepare(
            "INSERT INTO users (pegawai_id, username, password, role, is_registered, status, created_at, updated_at)
             VALUES (NULL, ?, ?, ?, 1, 'aktif', NOW(), NOW())"
        );
        $insertStmt->execute([$username, $hashPassword, $role]);
        $newId = (int)dbLastInsertId($pdo, 'users');

        $logData = [
            'id' => $newId,
            'username' => $username,
            'role' => $role,
            'status' => 'aktif'
        ];
        logAudit($pdo, 'users', 'tambah_' . $role, null, $logData);

        $roleLabel = ($role === 'super_admin') ? 'Super Admin' : 'Administrator';
        sendResponse(true, "Akun {$roleLabel} '{$username}' berhasil dibuat.", $logData, 201);
        break;

    case 'PUT':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            sendResponse(false, "ID user administrator tidak valid.", null, 400);
        }

        // Ambil data user yang akan diubah
        $stmtUser = $pdo->prepare("SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1");
        $stmtUser->execute([$id]);
        $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            sendResponse(false, "Akun pengguna tidak ditemukan.", null, 404);
        }

        // Keamanan: endpoint ini hanya untuk akun admin atau super_admin
        if (!in_array($targetUser['role'], ['admin', 'super_admin'], true)) {
            sendResponse(false, "Aksi ini hanya dapat dilakukan pada akun administrator atau super admin.", null, 403);
        }

        $input = getJsonInput();
        $fieldsToUpdate = [];
        $params = [];
        $dataSesudah = $targetUser;

        // 1. Update Status (aktif / nonaktif)
        if (isset($input['status'])) {
            $newStatus = cleanStr($input['status']);
            if (!in_array($newStatus, ['aktif', 'nonaktif'], true)) {
                sendResponse(false, "Status harus 'aktif' atau 'nonaktif'.", null, 400);
            }

            // Proteksi: Tidak boleh menonaktifkan akun sendiri yang sedang aktif
            if ($id === (int)currentUserId() && $newStatus !== 'aktif') {
                sendResponse(false, "Anda tidak dapat menonaktifkan akun Anda sendiri yang sedang aktif.", null, 400);
            }

            // Proteksi: Tidak boleh menonaktifkan jika merupakan satu-satunya Super Admin aktif
            if ($targetUser['role'] === 'super_admin' && $newStatus !== 'aktif') {
                $activeSuper = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'aktif'")->fetchColumn();
                if ($activeSuper <= 1) {
                    sendResponse(false, "Tidak dapat menonaktifkan satu-satunya akun Super Admin yang aktif pada sistem.", null, 403);
                }
            }

            $fieldsToUpdate[] = "status = ?";
            $params[] = $newStatus;
            $dataSesudah['status'] = $newStatus;
        }

        // 2. Reset / Ubah Password (berlaku untuk admin dan super_admin)
        if (!empty($input['password'])) {
            $newPassword = (string)$input['password'];
            if (strlen($newPassword) < 6) {
                sendResponse(false, "Password baru minimal 6 karakter.", null, 400);
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $fieldsToUpdate[] = "password = ?";
            $params[] = $newHash;
            $dataSesudah['password_updated'] = true;
        }

        if (empty($fieldsToUpdate)) {
            sendResponse(false, "Tidak ada data perubahan yang dikirimkan.", null, 400);
        }

        $fieldsToUpdate[] = "updated_at = NOW()";
        $params[] = $id;

        $sqlUpdate = "UPDATE users SET " . implode(", ", $fieldsToUpdate) . " WHERE id = ?";
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute($params);

        logAudit($pdo, 'users', 'update_' . $targetUser['role'], $targetUser, $dataSesudah);

        $roleLabel = ($targetUser['role'] === 'super_admin') ? 'Super Admin' : 'Administrator';
        sendResponse(true, "Data akun {$roleLabel} '{$targetUser['username']}' berhasil diperbarui.", [
            'id' => $id,
            'status' => $dataSesudah['status'] ?? $targetUser['status'],
            'role' => $targetUser['role']
        ]);
        break;

    case 'DELETE':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            sendResponse(false, "ID user administrator tidak valid.", null, 400);
        }

        $stmtUser = $pdo->prepare("SELECT id, username, role, status FROM users WHERE id = ? LIMIT 1");
        $stmtUser->execute([$id]);
        $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$targetUser) {
            sendResponse(false, "Akun administrator tidak ditemukan.", null, 404);
        }

        // Keamanan: Hanya akun role 'admin' atau 'super_admin' yang boleh dihapus
        if (!in_array($targetUser['role'], ['admin', 'super_admin'], true)) {
            sendResponse(false, "Akun dengan role '{$targetUser['role']}' tidak dapat dihapus melalui fitur ini.", null, 403);
        }

        // Keamanan: Tidak dapat menghapus akun yang sedang digunakan login
        if ($id === (int)currentUserId()) {
            sendResponse(false, "Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif digunakan.", null, 400);
        }

        // Proteksi: Tidak dapat menghapus jika merupakan satu-satunya akun Super Admin di sistem
        if ($targetUser['role'] === 'super_admin') {
            $totalSuper = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
            if ($totalSuper <= 1) {
                sendResponse(false, "Tidak dapat menghapus satu-satunya akun Super Admin pada sistem.", null, 403);
            }
        }

        $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $deleteStmt->execute([$id]);

        logAudit($pdo, 'users', 'hapus_' . $targetUser['role'], $targetUser, null);

        $roleLabel = ($targetUser['role'] === 'super_admin') ? 'Super Admin' : 'Administrator';
        sendResponse(true, "Akun {$roleLabel} '{$targetUser['username']}' berhasil dihapus.");
        break;

    default:
        sendResponse(false, "Metode HTTP tidak diizinkan.", null, 405);
        break;
}
