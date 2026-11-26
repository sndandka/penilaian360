<?php
/**
 * USE CASE: Master Data Jabatan (Super Admin)
 * Endpoints:
 *  GET    /api/jabatan.php            -> list semua jabatan (+ jumlah pegawai)
 *  GET    /api/jabatan.php?id=1       -> detail jabatan
 *  POST   /api/jabatan.php            -> tambah jabatan baru
 *  PUT    /api/jabatan.php?id=1       -> update jabatan
 *  DELETE /api/jabatan.php?id=1       -> hapus jabatan (dicegah jika masih dipakai)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        requireLogin();
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT j.*, (SELECT COUNT(*) FROM pegawai p WHERE p.jabatan_id = j.id) AS jumlah_pegawai
                                    FROM jabatan j
                                    WHERE j.id = ?");
            $stmt->execute([$_GET['id']]);
            $data = $stmt->fetch();
            if (!$data) sendResponse(false, "Jabatan tidak ditemukan.", null, 404);
            sendResponse(true, "OK", $data);
        } else {
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

            $total = 0;
            if ($limit) {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM jabatan");
                $total = $countStmt->fetchColumn();
            }

            $limitClause = "";
            if ($limit) {
                $offset = ($page - 1) * $limit;
                $limitClause = " LIMIT $limit OFFSET $offset";
            }

            $stmt = $pdo->query("SELECT j.*, (SELECT COUNT(*) FROM pegawai p WHERE p.jabatan_id = j.id) AS jumlah_pegawai
                                  FROM jabatan j
                                  ORDER BY j.level_jabatan ASC, j.id ASC" . $limitClause);
                                  
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
        requireAdmin();
        $input = getJsonInput();
        requireFields($input, ['kode_jabatan', 'nama_jabatan']);
        $kode = cleanStr($input['kode_jabatan']);
        $nama = cleanStr($input['nama_jabatan']);

        $check = $pdo->prepare("SELECT id FROM jabatan WHERE kode_jabatan = ?");
        $check->execute([$kode]);
        if ($check->fetch()) sendResponse(false, "Kode jabatan '$kode' sudah digunakan.", null, 409);

        $jenisValid = ['struktural','fungsional','pelaksana'];
        $jenis = in_array($input['jenis_jabatan'] ?? '', $jenisValid) ? $input['jenis_jabatan'] : 'pelaksana';
        // Non-struktural selalu level 4
        $level = ($jenis === 'struktural') ? (int)($input['level_jabatan'] ?? 4) : 4;

        $stmt = $pdo->prepare("INSERT INTO jabatan (kode_jabatan, nama_jabatan, jenis_jabatan, eselon, level_jabatan, keterangan)
                                VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $kode, $nama, $jenis,
            cleanStr($input['eselon'] ?? null),
            $level,
            cleanStr($input['keterangan'] ?? null)
        ]);
        $newId = dbLastInsertId($pdo, 'jabatan');
        logAudit($pdo, 'jabatan', 'create', null, ['id' => $newId, 'nama_jabatan' => $nama]);
        sendResponse(true, "Jabatan berhasil ditambahkan.", ["id" => $newId], 201);
        break;

    case 'PUT':
        requireAdmin();
        if (!isset($_GET['id'])) sendResponse(false, "ID jabatan wajib disertakan.", null, 400);
        $id = $_GET['id'];
        $input = getJsonInput();

        $before = $pdo->prepare("SELECT * FROM jabatan WHERE id = ?");
        $before->execute([$id]);
        $beforeData = $before->fetch();
        if (!$beforeData) sendResponse(false, "Jabatan tidak ditemukan.", null, 404);

        $fields = []; $params = [];
        foreach (['kode_jabatan','nama_jabatan','jenis_jabatan','eselon','level_jabatan','keterangan'] as $f) {
            if (array_key_exists($f, $input)) { $fields[] = "$f = ?"; $params[] = $input[$f] === '' ? null : $input[$f]; }
        }
        if (empty($fields)) sendResponse(false, "Tidak ada data yang diubah.", null, 400);
        $params[] = $id;
        $pdo->prepare("UPDATE jabatan SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        logAudit($pdo, 'jabatan', 'update', $beforeData, $input);
        sendResponse(true, "Jabatan berhasil diperbarui.");
        break;

    case 'DELETE':
        requireAdmin();
        if (!isset($_GET['id'])) sendResponse(false, "ID jabatan wajib disertakan.", null, 400);
        $id = $_GET['id'];
        $check = $pdo->prepare("SELECT COUNT(*) AS total FROM pegawai WHERE jabatan_id = ?");
        $check->execute([$id]);
        $row = $check->fetch();
        if ($row['total'] > 0) {
            sendResponse(false, "Tidak dapat menghapus jabatan yang masih digunakan oleh {$row['total']} pegawai.", null, 409);
        }
        $pdo->prepare("DELETE FROM jabatan WHERE id = ?")->execute([$id]);
        logAudit($pdo, 'jabatan', 'delete', ['id' => $id], null);
        sendResponse(true, "Jabatan berhasil dihapus.");
        break;

    default:
        sendResponse(false, "Method tidak diizinkan.", null, 405);
}
