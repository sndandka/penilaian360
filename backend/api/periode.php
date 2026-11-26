<?php
/**
 * USE CASE: Manajemen Periode Penilaian (Super Admin)
 * Endpoints:
 *  GET    /api/periode.php            -> list periode
 *  GET    /api/periode.php?id=1       -> detail periode
 *  POST   /api/periode.php            -> tambah periode baru
 *  PUT    /api/periode.php?id=1       -> update periode / ubah status
 *  DELETE /api/periode.php?id=1       -> hapus periode
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';
require_once __DIR__ . '/../helpers/results_calculator.php';

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];


// Nilai valid untuk semester berdasarkan jenis_periode
$VALID_SEMESTER = [
    'tahunan'  => ['1'],
    'semester' => ['1','2'],
    'triwulan' => ['1','2','3','4'],
    'bulanan'  => ['1','2','3','4','5','6','7','8','9','10','11','12'],
    'lainnya'  => null, // bebas
];

if ($method === 'GET') {
    requireLogin(); // pegawai boleh melihat daftar periode
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM periode_penilaian WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $data = $stmt->fetch();
        if (!$data) sendResponse(false, "Periode tidak ditemukan.", null, 404);
        sendResponse(true, "OK", $data);
    } else {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

        $total = 0;
        if ($limit) {
            $countStmt = $pdo->query("SELECT COUNT(*) FROM periode_penilaian");
            $total = $countStmt->fetchColumn();
        }

        $limitClause = "";
        if ($limit) {
            $offset = ($page - 1) * $limit;
            $limitClause = " LIMIT $limit OFFSET $offset";
        }

        $stmt = $pdo->query(
            "SELECT pp.*, u.username AS dibuat_oleh,
                    (SELECT COUNT(*) FROM mapping_penilai m WHERE m.periode_id = pp.id) AS total_mapping,
                    (SELECT COUNT(*) FROM mapping_penilai m WHERE m.periode_id = pp.id AND m.status = 'selesai') AS total_selesai
             FROM periode_penilaian pp
             LEFT JOIN users u ON pp.created_by = u.id
             ORDER BY pp.tahun DESC, pp.tanggal_mulai ASC" . $limitClause
        );
        
        $meta = $limit ? [
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => ceil($total / $limit)
        ] : null;

        sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
    }
    exit();
}

requireAdmin();

if ($method === 'POST') {
    $input = getJsonInput();
    requireFields($input, ['nama_periode', 'tahun', 'tanggal_mulai', 'tanggal_selesai']);

    // Validasi tanggal
    if ($input['tanggal_selesai'] < $input['tanggal_mulai']) {
        sendResponse(false, "Tanggal selesai tidak boleh sebelum tanggal mulai.", null, 400);
    }

    // Jenis periode
    $jenisValid = ['tahunan','semester','triwulan','bulanan','lainnya'];
    $jenis = in_array($input['jenis_periode'] ?? '', $jenisValid)
        ? $input['jenis_periode']
        : 'tahunan';

    // Semester / urutan sub-periode
    $semester = cleanStr($input['semester'] ?? '1');
    // Validasi semester sesuai jenis (untuk jenis lainnya bebaskan)
    if ($jenis !== 'lainnya' && isset($VALID_SEMESTER[$jenis])) {
        if (!in_array($semester, $VALID_SEMESTER[$jenis])) {
            $semester = '1'; // fallback
        }
    }

    // Simpan semester sesuai jenis: tahunan -> 'tahunan', sisanya angka/string
    $semesterDB = ($jenis === 'tahunan') ? 'tahunan' : $semester;

    // Target penilaian (semua vs golongan tertentu, e.g. Golongan III & IV)
    $targetPenilaian = in_array($input['target_penilaian'] ?? '', ['semua', 'golongan_tertentu'])
        ? $input['target_penilaian']
        : 'semua';

    $targetGolongan = null;
    if ($targetPenilaian === 'golongan_tertentu') {
        if (!empty($input['target_golongan'])) {
            if (is_array($input['target_golongan'])) {
                $targetGolongan = implode(',', array_filter(array_map('trim', $input['target_golongan'])));
            } else {
                $targetGolongan = cleanStr($input['target_golongan']);
            }
        }
        if (empty($targetGolongan)) {
            $targetGolongan = 'III,IV';
        }
    }

    // Buat kode unik
    $kode = cleanStr($input['kode_periode'] ?? '') ?:
        ('PRD-' . $input['tahun'] . '-' . strtoupper(substr(md5(uniqid()), 0, 6)));

    $stmt = $pdo->prepare(
        "INSERT INTO periode_penilaian
            (kode_periode, nama_periode, tahun, jenis_periode, semester,
             target_penilaian, target_golongan,
             tanggal_mulai, tanggal_selesai,
             tanggal_mulai_pengisian, tanggal_akhir_pengisian,
             status, keterangan, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $kode,
        cleanStr($input['nama_periode']),
        (int)$input['tahun'],
        $jenis,
        $semesterDB,
        $targetPenilaian,
        $targetGolongan,
        $input['tanggal_mulai'],
        $input['tanggal_selesai'],
        !empty($input['tanggal_mulai_pengisian']) ? $input['tanggal_mulai_pengisian'] : null,
        !empty($input['tanggal_akhir_pengisian']) ? $input['tanggal_akhir_pengisian'] : null,
        in_array($input['status'] ?? '', ['draft','aktif','ditutup','diarsipkan'])
            ? $input['status'] : 'draft',
        cleanStr($input['keterangan'] ?? null),
        currentUserId()
    ]);
    $newId = dbLastInsertId($pdo, 'periode_penilaian');
    logAudit($pdo, 'periode_penilaian', 'create', null,
        ['id' => $newId, 'nama_periode' => $input['nama_periode']]);
    sendResponse(true, "Periode penilaian berhasil dibuat.", ["id" => $newId], 201);
}

if ($method === 'PUT') {
    if (!isset($_GET['id'])) sendResponse(false, "ID periode wajib disertakan.", null, 400);
    $id = $_GET['id'];
    $input = getJsonInput();

    $before = $pdo->prepare("SELECT * FROM periode_penilaian WHERE id = ?");
    $before->execute([$id]);
    $beforeData = $before->fetch();
    if (!$beforeData) sendResponse(false, "Periode tidak ditemukan.", null, 404);

    // Validasi tanggal jika keduanya dikirim
    $mulai   = $input['tanggal_mulai']   ?? $beforeData['tanggal_mulai'];
    $selesai = $input['tanggal_selesai'] ?? $beforeData['tanggal_selesai'];
    if ($selesai < $mulai) {
        sendResponse(false, "Tanggal selesai tidak boleh sebelum tanggal mulai.", null, 400);
    }

    if (isset($input['target_golongan']) && is_array($input['target_golongan'])) {
        $input['target_golongan'] = implode(',', array_filter(array_map('trim', $input['target_golongan'])));
    }
    if (isset($input['target_penilaian']) && $input['target_penilaian'] === 'semua') {
        $input['target_golongan'] = null;
    }

    $fields = []; $params = [];
    $allowedFields = [
        'nama_periode','tahun','jenis_periode','semester',
        'target_penilaian','target_golongan',
        'tanggal_mulai','tanggal_selesai',
        'tanggal_mulai_pengisian','tanggal_akhir_pengisian',
        'status','keterangan'
    ];
    foreach ($allowedFields as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "$f = ?";
            $params[] = ($input[$f] === '') ? null : $input[$f];
        }
    }
    if (empty($fields)) sendResponse(false, "Tidak ada data yang diubah.", null, 400);
    $params[] = $id;
    $pdo->prepare("UPDATE periode_penilaian SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    logAudit($pdo, 'periode_penilaian', 'update', $beforeData, $input);

    // Hitung dan finalisasi skor otomatis ketika status periode ditutup
    $isClosing = isset($input['status']) && $input['status'] === 'ditutup' && $beforeData['status'] !== 'ditutup';
    if ($isClosing) {
        $autoCount = simpanHasilPenilaian($pdo, (int)$id, null, currentUserId());
        logAudit($pdo, 'hasil_penilaian', 'hitung_otomatis', null, ['periode_id' => (int)$id, 'total_pegawai' => $autoCount, 'trigger' => 'tutup_periode']);
        sendResponse(true, "Periode berhasil ditutup dan hasil penilaian untuk $autoCount pegawai telah dihitung otomatis.");
    }

    sendResponse(true, "Periode berhasil diperbarui.");
}

if ($method === 'DELETE') {
    if (!isset($_GET['id'])) sendResponse(false, "ID periode wajib disertakan.", null, 400);
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM periode_penilaian WHERE id = ?");
    $stmt->execute([$id]);
    $beforeData = $stmt->fetch();
    if (!$beforeData) sendResponse(false, "Periode tidak ditemukan.", null, 404);

    $pdo->prepare("DELETE FROM periode_penilaian WHERE id = ?")->execute([$id]);
    logAudit($pdo, 'periode_penilaian', 'delete', $beforeData, null);
    sendResponse(true, "Periode berhasil dihapus.");
}

sendResponse(false, "Method tidak diizinkan.", null, 405);
