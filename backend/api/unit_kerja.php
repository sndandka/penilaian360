<?php
/**
 * USE CASE: Master Data Unit Kerja (Super Admin)
 * Mendukung hierarki organisasi (Dinas > Bidang > Seksi/UPT) via parent_id.
 * Endpoints:
 *  GET    /api/unit_kerja.php               -> list semua unit kerja (+ jumlah pegawai, nama induk)
 *  GET    /api/unit_kerja.php?id=1          -> detail unit kerja
 *  GET    /api/unit_kerja.php?tree=1        -> struktur pohon (nested) untuk visualisasi
 *  POST   /api/unit_kerja.php               -> tambah unit kerja baru
 *  PUT    /api/unit_kerja.php?id=1          -> update unit kerja
 *  DELETE /api/unit_kerja.php?id=1          -> hapus (dicegah jika masih ada pegawai/anak unit)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

$baseSelect = "SELECT uk.*, parent.nama_unit AS nama_induk,
                      (SELECT COUNT(*) FROM pegawai p WHERE p.unit_kerja_id = uk.id) AS jumlah_pegawai,
                      kepala.nama_lengkap AS nama_kepala_unit
               FROM unit_kerja uk
               LEFT JOIN unit_kerja parent ON uk.parent_id = parent.id
               LEFT JOIN pegawai kepala ON uk.kepala_unit_id = kepala.id";

switch ($method) {

    case 'GET':
        requireLogin();
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare($baseSelect . " WHERE uk.id = ?");
            $stmt->execute([$_GET['id']]);
            $data = $stmt->fetch();
            if (!$data) sendResponse(false, "Unit kerja tidak ditemukan.", null, 404);
            sendResponse(true, "OK", $data);
        } else {
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

            $total = 0;
            if ($limit) {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM unit_kerja");
                $total = $countStmt->fetchColumn();
            }

            $limitClause = "";
            if ($limit) {
                $offset = ($page - 1) * $limit;
                $limitClause = " LIMIT $limit OFFSET $offset";
            }

            $stmt = $pdo->query($baseSelect . " ORDER BY CASE WHEN uk.parent_id IS NULL THEN 0 ELSE 1 END ASC, uk.nama_unit ASC" . $limitClause);
            $rows = $stmt->fetchAll();

            $meta = $limit ? [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => ceil($total / $limit)
            ] : null;

            if (isset($_GET['tree'])) {
                $byId = [];
                foreach ($rows as $r) { $r['children'] = []; $byId[$r['id']] = $r; }
                $tree = [];
                foreach ($byId as $id => $node) {
                    if ($node['parent_id'] && isset($byId[$node['parent_id']])) {
                        $byId[$node['parent_id']]['children'][] = &$byId[$id];
                    } else {
                        $tree[] = &$byId[$id];
                    }
                }
                sendResponse(true, "OK", $tree);
            }
            sendResponse(true, "OK", $rows, 200, $meta);
        }
        break;

    case 'POST':
        requireAdmin();
        $input = getJsonInput();
        requireFields($input, ['kode_unit', 'nama_unit']);
        $kode = cleanStr($input['kode_unit']);
        $nama = cleanStr($input['nama_unit']);

        $check = $pdo->prepare("SELECT id FROM unit_kerja WHERE kode_unit = ?");
        $check->execute([$kode]);
        if ($check->fetch()) sendResponse(false, "Kode unit '$kode' sudah digunakan.", null, 409);

        $jenisValid = ['dinas','sekretariat','bidang','upt','upt_lab','seksi','sub_bagian_tata_usaha','subbagian','kelompok_fungsional'];
        $jenis = in_array($input['jenis_unit'] ?? '', $jenisValid) ? $input['jenis_unit'] : 'seksi';

        $stmt = $pdo->prepare("INSERT INTO unit_kerja (kode_unit, nama_unit, jenis_unit, parent_id, alamat, status)
                                VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $kode, $nama, $jenis,
            !empty($input['parent_id']) ? $input['parent_id'] : null,
            cleanStr($input['alamat'] ?? null),
            in_array($input['status'] ?? '', ['aktif','nonaktif']) ? $input['status'] : 'aktif'
        ]);
        $newId = dbLastInsertId($pdo, 'unit_kerja');
        logAudit($pdo, 'unit_kerja', 'create', null, ['id' => $newId, 'nama_unit' => $nama]);
        sendResponse(true, "Unit kerja berhasil ditambahkan.", ["id" => $newId], 201);
        break;

    case 'PUT':
        requireAdmin();
        if (!isset($_GET['id'])) sendResponse(false, "ID unit kerja wajib disertakan.", null, 400);
        $id = $_GET['id'];
        $input = getJsonInput();

        if (!empty($input['parent_id']) && (int)$input['parent_id'] === (int)$id) {
            sendResponse(false, "Unit kerja tidak boleh menjadi induk dari dirinya sendiri.", null, 400);
        }

        $before = $pdo->prepare("SELECT * FROM unit_kerja WHERE id = ?");
        $before->execute([$id]);
        $beforeData = $before->fetch();
        if (!$beforeData) sendResponse(false, "Unit kerja tidak ditemukan.", null, 404);

        $fields = []; $params = [];
        foreach (['kode_unit','nama_unit','jenis_unit','parent_id','kepala_unit_id','alamat','status'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "$f = ?";
                $params[] = $input[$f] === '' ? null : $input[$f];
            }
        }
        if (empty($fields)) sendResponse(false, "Tidak ada data yang diubah.", null, 400);
        $params[] = $id;
        $pdo->prepare("UPDATE unit_kerja SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

        logAudit($pdo, 'unit_kerja', 'update', $beforeData, $input);
        sendResponse(true, "Unit kerja berhasil diperbarui.");
        break;

    case 'DELETE':
        requireAdmin();
        if (!isset($_GET['id'])) sendResponse(false, "ID unit kerja wajib disertakan.", null, 400);
        $id = $_GET['id'];

        $checkPeg = $pdo->prepare("SELECT COUNT(*) AS total FROM pegawai WHERE unit_kerja_id = ?");
        $checkPeg->execute([$id]);
        if ($checkPeg->fetch()['total'] > 0) {
            sendResponse(false, "Tidak dapat menghapus unit kerja yang masih memiliki pegawai. Pindahkan pegawai terlebih dahulu.", null, 409);
        }
        $checkChild = $pdo->prepare("SELECT COUNT(*) AS total FROM unit_kerja WHERE parent_id = ?");
        $checkChild->execute([$id]);
        if ($checkChild->fetch()['total'] > 0) {
            sendResponse(false, "Tidak dapat menghapus unit kerja yang masih memiliki unit kerja di bawahnya.", null, 409);
        }

        $pdo->prepare("DELETE FROM unit_kerja WHERE id = ?")->execute([$id]);
        logAudit($pdo, 'unit_kerja', 'delete', ['id' => $id], null);
        sendResponse(true, "Unit kerja berhasil dihapus.");
        break;

    default:
        sendResponse(false, "Method tidak diizinkan.", null, 405);
}
