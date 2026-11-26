<?php
/**
 * USE CASE: Melakukan Penilaian (Pegawai & Admin)
 * Endpoints:
 *  GET    /api/evaluations.php?action=tugas                 -> daftar tugas penilaian milik user login
 *  GET    /api/evaluations.php?action=form&mapping_id=1     -> ambil form pertanyaan + jawaban (jika ada)
 *  POST   /api/evaluations.php?action=submit                -> submit/simpan jawaban penilaian
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';
require_once __DIR__ . '/../helpers/results_calculator.php';

requireLogin();

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function currentPegawaiIdOrFail() {
    $pegawaiId = currentPegawaiId();
    if (!$pegawaiId) {
        sendResponse(false, "Akun Anda tidak terhubung dengan data kepegawaian. Hubungi Super Admin.", null, 403);
    }
    return $pegawaiId;
}

if ($method === 'GET' && $action === 'tugas') {
    $pegawaiId = currentPegawaiIdOrFail();
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
    $total = 0;
    
    if ($limit) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mapping_penilai m JOIN periode_penilaian pp ON m.periode_id = pp.id WHERE m.pegawai_penilai_id = ? AND pp.status = 'aktif'");
        $countStmt->execute([$pegawaiId]);
        $total = $countStmt->fetchColumn();
    }
    
    $limitClause = $limit ? " LIMIT $limit OFFSET " . (($page - 1) * $limit) : "";

    $stmt = $pdo->prepare(
        "SELECT m.id AS mapping_id, m.jenis_relasi, m.status, m.periode_id,
                p.id AS dinilai_id, p.nama_lengkap AS nama_dinilai, p.golongan_ruang AS golongan_dinilai,
                j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja,
                pp.nama_periode, pp.tanggal_mulai, pp.tanggal_selesai
         FROM mapping_penilai m
         JOIN pegawai p ON m.pegawai_dinilai_id = p.id
         LEFT JOIN jabatan j     ON p.jabatan_id    = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         JOIN periode_penilaian pp ON m.periode_id = pp.id
         WHERE m.pegawai_penilai_id = ? AND pp.status = 'aktif'
         ORDER BY pp.tanggal_mulai DESC,
                  CASE 
                      WHEN m.jenis_relasi = 'diri' THEN 1
                      WHEN m.jenis_relasi = 'bawahan' THEN 2
                      WHEN m.jenis_relasi = 'rekan' THEN 3
                      WHEN m.jenis_relasi = 'atasan' THEN 4
                      ELSE 5
                  END ASC,
                  p.nama_lengkap ASC" . $limitClause
    );
    $stmt->execute([$pegawaiId]);
    
    $meta = $limit ? ['total' => (int)$total, 'page' => $page, 'limit' => $limit, 'totalPages' => ceil($total / $limit)] : null;
    sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
}

if ($method === 'GET' && $action === 'form') {
    $pegawaiId = currentPegawaiIdOrFail();
    $mappingId = $_GET['mapping_id'] ?? null;
    if (!$mappingId) sendResponse(false, "mapping_id wajib disertakan.", null, 400);

    $mstmt = $pdo->prepare("SELECT m.*, p.nama_lengkap AS nama_dinilai, p.golongan_ruang AS golongan_dinilai,
                                   j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja
                            FROM mapping_penilai m 
                            JOIN pegawai p ON m.pegawai_dinilai_id = p.id 
                            LEFT JOIN jabatan j ON p.jabatan_id = j.id
                            LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
                            WHERE m.id = ?");
    $mstmt->execute([$mappingId]);
    $mapping = $mstmt->fetch();
    if (!$mapping) sendResponse(false, "Mapping tidak ditemukan.", null, 404);
    if (!isAdminOrSuperAdmin() && $mapping['pegawai_penilai_id'] != $pegawaiId) {
        sendResponse(false, "Anda tidak berhak mengakses penilaian ini.", null, 403);
    }

    $relasi = $mapping['jenis_relasi'] ?? '';
    $relasiExpr = dbFindInSet($pdo, '?', 'q.target_relasi');
    $qstmt = $pdo->prepare(
        "SELECT q.*, i.nama_indikator, k.nama_kategori, k.id AS kategori_id
         FROM pertanyaan_penilaian q
         JOIN indikator_penilaian i ON q.indikator_id = i.id
         JOIN kategori_penilaian k ON i.kategori_id = k.id
         WHERE q.status = 'aktif'
           AND (q.target_relasi IS NULL OR q.target_relasi = '' OR $relasiExpr)
         ORDER BY k.urutan ASC, i.urutan ASC, q.urutan ASC"
    );
    $qstmt->execute([$relasi]);
    $pertanyaan = $qstmt->fetchAll();

    $jstmt = $pdo->prepare("SELECT pertanyaan_id, skor, komentar FROM jawaban_penilaian WHERE mapping_id = ?");
    $jstmt->execute([$mappingId]);
    $jawaban = [];
    foreach ($jstmt->fetchAll() as $j) { $jawaban[$j['pertanyaan_id']] = $j; }

    sendResponse(true, "OK", [
        "mapping" => $mapping,
        "pertanyaan" => $pertanyaan,
        "jawaban" => $jawaban
    ]);
}

if ($method === 'GET' && $action === 'matriks_data') {
    $pegawaiId = currentPegawaiIdOrFail();

    // 1. Info Pegawai Penilai
    $evaluatorStmt = $pdo->prepare(
        "SELECT p.id, p.nip, p.nama_lengkap, p.golongan_ruang, j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja
         FROM pegawai p
         LEFT JOIN jabatan j ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE p.id = ?"
    );
    $evaluatorStmt->execute([$pegawaiId]);
    $evaluator = $evaluatorStmt->fetch();

    // 2. Periode Aktif
    $periodeStmt = $pdo->prepare(
        "SELECT id, nama_periode, tahun, semester, tanggal_mulai, tanggal_selesai
         FROM periode_penilaian
         WHERE status = 'aktif'
         ORDER BY tanggal_mulai DESC LIMIT 1"
    );
    $periodeStmt->execute();
    $periode = $periodeStmt->fetch();

    // 3. Mapping Penilai untuk Pegawai ini pada Periode Aktif
    $mappingStmt = $pdo->prepare(
        "SELECT m.id AS mapping_id, m.jenis_relasi, m.status, m.komentar_masukan,
                p.id AS dinilai_id, p.nip AS nip_dinilai, p.nama_lengkap AS nama_dinilai,
                p.golongan_ruang AS golongan_dinilai,
                j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja
         FROM mapping_penilai m
         JOIN pegawai p ON m.pegawai_dinilai_id = p.id
         LEFT JOIN jabatan j ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         JOIN periode_penilaian pp ON m.periode_id = pp.id
         WHERE m.pegawai_penilai_id = ? AND pp.status = 'aktif'
         ORDER BY CASE 
                     WHEN m.jenis_relasi = 'diri' THEN 1
                     WHEN m.jenis_relasi = 'bawahan' THEN 2
                     WHEN m.jenis_relasi = 'rekan' THEN 3
                     WHEN m.jenis_relasi = 'atasan' THEN 4
                     ELSE 5
                  END ASC,
                  p.nama_lengkap ASC"
    );
    $mappingStmt->execute([$pegawaiId]);
    $mappings = $mappingStmt->fetchAll();

    // 4. Seluruh Pertanyaan Aktif
    $qstmt = $pdo->prepare(
        "SELECT q.id, q.pertanyaan, q.indikator_id, q.skor_min, q.skor_max, q.urutan, q.target_relasi,
                i.nama_indikator, i.kode_indikator,
                k.id AS kategori_id, k.nama_kategori, k.urutan AS kategori_urutan
         FROM pertanyaan_penilaian q
         JOIN indikator_penilaian i ON q.indikator_id = i.id
         JOIN kategori_penilaian k ON i.kategori_id = k.id
         WHERE q.status = 'aktif'
         ORDER BY k.urutan ASC, i.urutan ASC, q.urutan ASC"
    );
    $qstmt->execute();
    $pertanyaan = $qstmt->fetchAll();

    // 5. Seluruh Jawaban Tersimpan untuk Mapping Milik Pegawai ini
    $mappingIds = array_column($mappings, 'mapping_id');
    $jawabanMap = [];
    if (!empty($mappingIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($mappingIds), '?'));
        $jstmt = $pdo->prepare("SELECT mapping_id, pertanyaan_id, skor, komentar FROM jawaban_penilaian WHERE mapping_id IN ($inPlaceholders)");
        $jstmt->execute($mappingIds);
        $rawJawaban = $jstmt->fetchAll();
        foreach ($rawJawaban as $row) {
            $jawabanMap[$row['mapping_id']][$row['pertanyaan_id']] = [
                'skor' => (int)$row['skor'],
                'komentar' => $row['komentar']
            ];
        }
    }

    sendResponse(true, "OK", [
        'evaluator' => $evaluator,
        'periode' => $periode,
        'mappings' => $mappings,
        'pertanyaan' => $pertanyaan,
        'jawaban' => $jawabanMap
    ]);
}

function upsertJawabanSql(PDO $pdo): string {
    if (dbIsPgsql($pdo)) {
        return "INSERT INTO jawaban_penilaian (mapping_id, pertanyaan_id, skor, komentar)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (mapping_id, pertanyaan_id) DO UPDATE SET skor = EXCLUDED.skor, komentar = EXCLUDED.komentar";
    }
    return "INSERT INTO jawaban_penilaian (mapping_id, pertanyaan_id, skor, komentar)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE skor = VALUES(skor), komentar = VALUES(komentar)";
}

if ($method === 'POST' && $action === 'batch_submit') {
    $pegawaiId = currentPegawaiIdOrFail();
    $input = getJsonInput();
    $submissions = $input['submissions'] ?? []; // [{mapping_id, jawaban: [{pertanyaan_id, skor}], final, komentar_masukan}]

    if (empty($submissions) || !is_array($submissions)) {
        sendResponse(false, "Tidak ada data penilaian yang dikirim.", null, 400);
    }

    $mappingIds = array_column($submissions, 'mapping_id');
    $inPlaceholders = implode(',', array_fill(0, count($mappingIds), '?'));
    $checkStmt = $pdo->prepare("SELECT id, periode_id, pegawai_dinilai_id, status, pegawai_penilai_id, tanggal_mulai_isi FROM mapping_penilai WHERE id IN ($inPlaceholders)");
    $checkStmt->execute($mappingIds);
    $existingMappings = [];
    foreach ($checkStmt->fetchAll() as $em) {
        $existingMappings[$em['id']] = $em;
    }

    foreach ($submissions as $sub) {
        $mId = $sub['mapping_id'] ?? null;
        if (!isset($existingMappings[$mId])) {
            sendResponse(false, "Mapping #$mId tidak ditemukan.", null, 404);
        }
        if (!isAdminOrSuperAdmin() && $existingMappings[$mId]['pegawai_penilai_id'] != $pegawaiId) {
            sendResponse(false, "Anda tidak berhak mengisi penilaian #$mId.", null, 403);
        }
    }

    $pdo->beginTransaction();
    try {
        $insertOrUpdate = $pdo->prepare(upsertJawabanSql($pdo));

        $updMapping = $pdo->prepare(
            "UPDATE mapping_penilai 
             SET status = ?, 
                 tanggal_mulai_isi = COALESCE(tanggal_mulai_isi, ?), 
                 tanggal_submit = COALESCE(?, tanggal_submit), 
                 komentar_masukan = ? 
             WHERE id = ?"
        );

        $savedCount = 0;
        $finalCount = 0;
        $targetsByPeriode = [];
        $now = date('Y-m-d H:i:s');

        foreach ($submissions as $sub) {
            $mId = $sub['mapping_id'];
            $mRecord = $existingMappings[$mId];

            // Jika mapping sudah final, lewati agar tidak menimpa data terkunci
            if ($mRecord['status'] === 'selesai') {
                continue;
            }

            $jawabanList = $sub['jawaban'] ?? [];
            if (!empty($jawabanList)) {
                foreach ($jawabanList as $j) {
                    if (isset($j['pertanyaan_id']) && isset($j['skor']) && $j['skor'] !== null && $j['skor'] !== '') {
                        $insertOrUpdate->execute([
                            $mId,
                            $j['pertanyaan_id'],
                            (int)$j['skor'],
                            cleanStr($j['komentar'] ?? null)
                        ]);
                    }
                }
            }

            $isFinal = !empty($sub['final']);
            $komentarMasukan = cleanStr($sub['komentar_masukan'] ?? null);
            $statusBaru = $isFinal ? 'selesai' : (count($jawabanList) > 0 ? 'proses' : $mRecord['status']);
            $tglMulai = $mRecord['tanggal_mulai_isi'] ?? $now;
            $tglSubmit = $isFinal ? $now : null;

            $updMapping->execute([$statusBaru, $tglMulai, $tglSubmit, $komentarMasukan, $mId]);
            $savedCount++;
            if ($isFinal) {
                $finalCount++;
                $targetsByPeriode[(int)$mRecord['periode_id']][] = (int)$mRecord['pegawai_dinilai_id'];
            }

            logAudit($pdo, 'jawaban_penilaian', $isFinal ? 'submit_final' : 'simpan_draft', null, ['mapping_id' => $mId]);
        }

        // Hitung dan simpan hasil_penilaian otomatis bagi pegawai yang baru disubmit final
        foreach ($targetsByPeriode as $pid => $targetPegawaiIds) {
            simpanHasilPenilaian($pdo, (int)$pid, $targetPegawaiIds, currentUserId());
        }

        $pdo->commit();
        $msg = $finalCount > 0 
            ? "Berhasil mengirim $finalCount penilaian final!" 
            : "Berhasil menyimpan draft $savedCount penilaian.";
        sendResponse(true, $msg, ['saved' => $savedCount, 'final' => $finalCount]);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(false, "Gagal menyimpan batch penilaian: " . $e->getMessage(), null, 500);
    }
}

if ($method === 'POST' && $action === 'submit') {
    $pegawaiId = currentPegawaiIdOrFail();
    $input = getJsonInput();
    $mappingId = $input['mapping_id'] ?? null;
    $jawaban = $input['jawaban'] ?? []; // [{pertanyaan_id, skor, komentar}]

    if (!$mappingId || empty($jawaban)) sendResponse(false, "Data penilaian tidak lengkap.", null, 400);

    $mstmt = $pdo->prepare("SELECT * FROM mapping_penilai WHERE id = ?");
    $mstmt->execute([$mappingId]);
    $mapping = $mstmt->fetch();
    if (!$mapping) sendResponse(false, "Mapping tidak ditemukan.", null, 404);
    if (!isAdminOrSuperAdmin() && $mapping['pegawai_penilai_id'] != $pegawaiId) {
        sendResponse(false, "Anda tidak berhak mengisi penilaian ini.", null, 403);
    }
    if ($mapping['status'] === 'selesai') {
        sendResponse(false, "Penilaian ini sudah final dan tidak dapat diubah lagi.", null, 409);
    }

    // Validasi skor terhadap batas pertanyaan
    $qStmt = $pdo->prepare("SELECT skor_min, skor_max FROM pertanyaan_penilaian WHERE id = ?");
    foreach ($jawaban as $j) {
        if (!isset($j['pertanyaan_id']) || !isset($j['skor'])) {
            sendResponse(false, "Format jawaban tidak valid.", null, 400);
        }
        $qStmt->execute([$j['pertanyaan_id']]);
        $q = $qStmt->fetch();
        if (!$q) sendResponse(false, "Pertanyaan ID {$j['pertanyaan_id']} tidak ditemukan.", null, 400);
        validateNumberRange($j['skor'], $q['skor_min'], $q['skor_max'], "Skor pertanyaan #{$j['pertanyaan_id']}");
    }

    $pdo->beginTransaction();
    try {
        $insertOrUpdate = $pdo->prepare(upsertJawabanSql($pdo));
        foreach ($jawaban as $j) {
            $insertOrUpdate->execute([
                $mappingId,
                $j['pertanyaan_id'],
                $j['skor'],
                cleanStr($j['komentar'] ?? null)
            ]);
        }

        $isFinal = $input['final'] ?? false;
        $komentarMasukan = cleanStr($input['komentar_masukan'] ?? null);
        $statusBaru = $isFinal ? 'selesai' : 'proses';
        $tglMulai = $mapping['tanggal_mulai_isi'] ?? date('Y-m-d H:i:s');
        $tglSubmit = $isFinal ? date('Y-m-d H:i:s') : null;

        $upd = $pdo->prepare(
            "UPDATE mapping_penilai SET status = ?, tanggal_mulai_isi = COALESCE(tanggal_mulai_isi, ?), tanggal_submit = COALESCE(?, tanggal_submit), komentar_masukan = ? WHERE id = ?"
        );
        $upd->execute([$statusBaru, $tglMulai, $tglSubmit, $komentarMasukan, $mappingId]);

        if ($isFinal) {
            simpanHasilPenilaian($pdo, (int)$mapping['periode_id'], [(int)$mapping['pegawai_dinilai_id']], currentUserId());
        }

        $pdo->commit();
        logAudit($pdo, 'jawaban_penilaian', $isFinal ? 'submit_final' : 'simpan_draft', null, ['mapping_id' => $mappingId]);
        sendResponse(true, $isFinal ? "Penilaian berhasil disubmit." : "Penilaian berhasil disimpan sebagai draft.");
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(false, "Gagal menyimpan penilaian: " . $e->getMessage(), null, 500);
    }
}

sendResponse(false, "Aksi tidak dikenali.", null, 404);
