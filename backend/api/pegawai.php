<?php
/**
 * USE CASE: Manajemen Data Pegawai (Admin & Super Admin)
 * Pegawai (data kepegawaian) kini terpisah dari users (kredensial login).
 * Endpoints:
 *  GET    /api/pegawai.php                    -> list semua pegawai (?search=&unit_kerja_id=)
 *  GET    /api/pegawai.php?id=1                -> detail pegawai (+ info akun jika ada)
 *  POST   /api/pegawai.php                     -> tambah data pegawai baru (opsional langsung buat akun)
 *  PUT    /api/pegawai.php?id=1                -> update data pegawai
 *  PUT    /api/pegawai.php?id=1&action=akun    -> kelola akun login pegawai (buat/reset password/ubah role)
 *  DELETE /api/pegawai.php?id=1                -> hapus data pegawai (+ akun terkait, cascade)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

requireAdmin();

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$baseSelect = "SELECT p.*, j.nama_jabatan, j.jenis_jabatan, j.level_jabatan,
                      uk.nama_unit, uk.parent_id AS unit_parent_id, uk.jenis_unit,
                      a.nama_lengkap AS nama_atasan, a.golongan_ruang AS golongan_atasan,
                      u.id AS user_id, u.username, u.role AS user_role, u.is_registered, u.status AS status_akun
               FROM pegawai p
               LEFT JOIN jabatan j ON p.jabatan_id = j.id
               LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
               LEFT JOIN pegawai a ON p.atasan_langsung_id = a.id
               LEFT JOIN users u ON u.pegawai_id = p.id";

switch ($method) {

    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare($baseSelect . " WHERE p.id = ?");
            $stmt->execute([$_GET['id']]);
            $data = $stmt->fetch();
            if (!$data) sendResponse(false, "Pegawai tidak ditemukan.", null, 404);
            unset($data['password']);
            sendResponse(true, "OK", $data);
        } else {
            $search = $_GET['search'] ?? '';
            $unitFilter = $_GET['unit_kerja_id'] ?? '';
            $golFilter = $_GET['golongan_ruang'] ?? ($_GET['golongan'] ?? '');
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

            $conditions = []; $params = [];

            if ($search !== '') {
                $searchCols = ['p.nama_lengkap', 'p.nip', 'p.golongan_ruang', 'u.username', 'j.nama_jabatan', 'uk.nama_unit'];
                $conditions[] = "(" . implode(' OR ', array_map(fn($c) => dbILike($pdo, $c), $searchCols)) . ")";
                $like = "%$search%";
                $params = array_merge($params, array_fill(0, count($searchCols), $like));
            }
            if ($unitFilter !== '') {
                $conditions[] = "(p.unit_kerja_id = ? OR p.unit_kerja_id IN (SELECT id FROM unit_kerja WHERE parent_id = ?))";
                $params[] = $unitFilter;
                $params[] = $unitFilter;
            }
            if ($golFilter !== '') {
                if (in_array($golFilter, ['I', 'II', 'III', 'IV'])) {
                    $conditions[] = "p.golongan_ruang LIKE ?";
                    $params[] = $golFilter . '/%';
                } else {
                    $conditions[] = "p.golongan_ruang = ?";
                    $params[] = $golFilter;
                }
            }

            $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

            // Total count for pagination
            $total = 0;
            if ($limit) {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pegawai p LEFT JOIN users u ON u.pegawai_id = p.id LEFT JOIN jabatan j ON p.jabatan_id = j.id LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id $where");
                $countStmt->execute($params);
                $total = $countStmt->fetchColumn();
            }

            $limitClause = "";
            if ($limit) {
                $offset = ($page - 1) * $limit;
                $limitClause = " LIMIT $limit OFFSET $offset";
            }

            $stmt = $pdo->prepare($baseSelect . " $where ORDER BY p.nama_lengkap ASC $limitClause");
            $stmt->execute($params);
            
            $meta = $limit ? [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => ceil($total / $limit)
            ] : null;

            sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
        }
        break;

    case 'POST':
        $input = getJsonInput();
        requireFields($input, ['nip', 'nama_lengkap']);
        $nip = cleanStr($input['nip']);
        $nama = cleanStr($input['nama_lengkap']);
        validateEmailIfPresent($input['email'] ?? null);

        $check = $pdo->prepare("SELECT id FROM pegawai WHERE nip = ?");
        $check->execute([$nip]);
        if ($check->fetch()) sendResponse(false, "NIP '$nip' sudah digunakan oleh pegawai lain.", null, 409);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO pegawai (nip, nip_lama, nama_lengkap, gelar_depan, gelar_belakang, jenis_kelamin,
                                       tempat_lahir, tanggal_lahir, golongan_ruang, tmt_golongan,
                                       jabatan_id, unit_kerja_id, atasan_langsung_id, tmt_jabatan,
                                       status_kepegawaian, no_hp, email, alamat, status_aktif)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $nip,
                ($tmp = cleanStr($input['nip_lama'] ?? null)) === '' ? null : $tmp,
                $nama,
                ($tmp = cleanStr($input['gelar_depan'] ?? null)) === '' ? null : $tmp,
                ($tmp = cleanStr($input['gelar_belakang'] ?? null)) === '' ? null : $tmp,
                in_array($input['jenis_kelamin'] ?? '', ['L','P']) ? $input['jenis_kelamin'] : 'L',
                ($tmp = cleanStr($input['tempat_lahir'] ?? null)) === '' ? null : $tmp,
                (!empty($input['tanggal_lahir'])) ? $input['tanggal_lahir'] : null,
                ($tmp = cleanStr($input['golongan_ruang'] ?? null)) === '' ? null : $tmp,
                (!empty($input['tmt_golongan'])) ? $input['tmt_golongan'] : null,
                (isset($input['jabatan_id']) && $input['jabatan_id'] !== '') ? (int)$input['jabatan_id'] : null,
                (isset($input['unit_kerja_id']) && $input['unit_kerja_id'] !== '') ? (int)$input['unit_kerja_id'] : null,
                (isset($input['atasan_langsung_id']) && $input['atasan_langsung_id'] !== '') ? (int)$input['atasan_langsung_id'] : null,
                (!empty($input['tmt_jabatan'])) ? $input['tmt_jabatan'] : null,
                in_array($input['status_kepegawaian'] ?? '', ['PNS','CPNS','PPPK','PPPK_Paruh_Waktu','Honorer']) ? $input['status_kepegawaian'] : 'PNS',
                ($tmp = cleanStr($input['no_hp'] ?? null)) === '' ? null : $tmp,
                ($tmp = cleanStr($input['email'] ?? null)) === '' ? null : $tmp,
                ($tmp = cleanStr($input['alamat'] ?? null)) === '' ? null : $tmp,
                in_array($input['status_aktif'] ?? '', ['aktif','nonaktif','pensiun','mutasi','meninggal']) ? $input['status_aktif'] : 'aktif'
            ]);
            $pegawaiId = dbLastInsertId($pdo, 'pegawai');

            // Selalu buatkan akun login untuk pegawai baru (username default = NIP, password default = 'password')
            $username = cleanStr($input['username'] ?? '') ?: $nip;
            $password = $input['password'] ?? 'password';
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $role = in_array($input['role'] ?? '', ['pegawai']) ? $input['role'] : 'pegawai';

            $checkUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $checkUser->execute([$username]);
            if (!$checkUser->fetch()) {
                $pdo->prepare("INSERT INTO users (pegawai_id, username, password, role, is_registered, status) VALUES (?, ?, ?, ?, ?, 'aktif')")
                    ->execute([$pegawaiId, $username, $hash, $role, !empty($input['password']) ? 1 : 0]);
            }

            $pdo->commit();
            logAudit($pdo, 'pegawai', 'create', null, ['id' => $pegawaiId, 'nip' => $nip, 'nama' => $nama]);
            sendResponse(true, "Pegawai berhasil ditambahkan.", ["id" => $pegawaiId], 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            sendResponse(false, "Gagal menyimpan pegawai: " . $e->getMessage(), null, 409);
        }
        break;

    case 'PUT':
        if (!isset($_GET['id'])) sendResponse(false, "ID pegawai wajib disertakan.", null, 400);
        $id = $_GET['id'];
        $input = getJsonInput();

        if ($action === 'akun') {
            // Kelola akun login pegawai secara terpisah
            $pstmt = $pdo->prepare("SELECT id, nama_lengkap FROM pegawai WHERE id = ?");
            $pstmt->execute([$id]);
            $peg = $pstmt->fetch();
            if (!$peg) sendResponse(false, "Pegawai tidak ditemukan.", null, 404);

            $ustmt = $pdo->prepare("SELECT * FROM users WHERE pegawai_id = ?");
            $ustmt->execute([$id]);
            $existing = $ustmt->fetch();

            $role = 'pegawai'; // Akun pegawai selalu memiliki role 'pegawai'
            $status = in_array($input['status'] ?? '', ['aktif','nonaktif','terkunci']) ? $input['status'] : 'aktif';

            if ($existing) {
                $fields = ["role = ?", "status = ?"]; $params = [$role, $status];
                if (!empty($input['username'])) { $fields[] = "username = ?"; $params[] = cleanStr($input['username']); }
                if (!empty($input['password'])) {
                    $fields[] = "password = ?"; $params[] = password_hash($input['password'], PASSWORD_BCRYPT);
                    $fields[] = "is_registered = 1";
                }
                $params[] = $existing['id'];
                $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
                logAudit($pdo, 'users', 'update_akun', null, ['pegawai_id' => $id, 'role' => $role]);
                sendResponse(true, "Akun pegawai berhasil diperbarui.");
            } else {
                requireFields($input, ['username']);
                $username = cleanStr($input['username']);
                $checkUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $checkUser->execute([$username]);
                if ($checkUser->fetch()) sendResponse(false, "Username '$username' sudah digunakan.", null, 409);

                $password = $input['password'] ?? '';
                $hash = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                $pdo->prepare("INSERT INTO users (pegawai_id, username, password, role, is_registered, status) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$id, $username, $hash, $role, !empty($password) ? 1 : 0, $status]);
                logAudit($pdo, 'users', 'create_akun', null, ['pegawai_id' => $id, 'username' => $username, 'role' => $role]);
                sendResponse(true, "Akun pegawai berhasil dibuat.");
            }
        }

        // Update data kepegawaian biasa
        $before = $pdo->prepare("SELECT * FROM pegawai WHERE id = ?");
        $before->execute([$id]);
        $beforeData = $before->fetch();
        if (!$beforeData) sendResponse(false, "Pegawai tidak ditemukan.", null, 404);

        validateEmailIfPresent($input['email'] ?? null);
        $allowedFields = ['nip','nip_lama','nama_lengkap','gelar_depan','gelar_belakang','jenis_kelamin',
                           'tempat_lahir','tanggal_lahir','golongan_ruang','tmt_golongan','jabatan_id',
                           'unit_kerja_id','atasan_langsung_id','tmt_jabatan','status_kepegawaian',
                           'no_hp','email','alamat','status_aktif'];
        $fields = []; $params = [];
        foreach ($allowedFields as $f) {
            if (array_key_exists($f, $input)) { $fields[] = "$f = ?"; $params[] = $input[$f] === '' ? null : $input[$f]; }
        }
        if (empty($fields)) sendResponse(false, "Tidak ada data yang diubah.", null, 400);
        if (isset($input['atasan_langsung_id']) && (int)$input['atasan_langsung_id'] === (int)$id) {
            sendResponse(false, "Pegawai tidak boleh menjadi atasan langsungnya sendiri.", null, 400);
        }
        $params[] = $id;
        $pdo->prepare("UPDATE pegawai SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

        // Jika NIP diubah, sinkronkan juga username akun pegawainya
        if (isset($input['nip']) && !empty($input['nip'])) {
            $newNip = cleanStr($input['nip']);
            $pdo->prepare("UPDATE users SET username = ? WHERE pegawai_id = ? AND role = 'pegawai'")->execute([$newNip, $id]);
        }

        logAudit($pdo, 'pegawai', 'update', $beforeData, $input);
        sendResponse(true, "Data pegawai berhasil diperbarui.");
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) sendResponse(false, "ID pegawai wajib disertakan.", null, 400);
        $id = $_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM pegawai WHERE id = ?");
        $stmt->execute([$id]);
        $beforeData = $stmt->fetch();
        if (!$beforeData) sendResponse(false, "Pegawai tidak ditemukan.", null, 404);

        $pdo->prepare("DELETE FROM pegawai WHERE id = ?")->execute([$id]); // cascade ke users, hasil_penilaian, mapping_penilai
        logAudit($pdo, 'pegawai', 'delete', $beforeData, null);
        sendResponse(true, "Pegawai berhasil dihapus.");
        break;

    default:
        sendResponse(false, "Method tidak diizinkan.", null, 405);
}
