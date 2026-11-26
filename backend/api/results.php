<?php
/**
 * USE CASE: Hasil Penilaian 360 (Pegawai melihat hasil sendiri, Admin melihat semua)
 * Skor akhir dihitung berdasarkan bobot FLEKSIBEL dari konfigurasi_sistem, lalu
 * disimpan permanen ke tabel hasil_penilaian (bukan dihitung ulang tiap saat)
 * agar tersedia untuk audit & perbandingan antar-periode.
 *
 * Endpoints:
 *  GET  /api/results.php?periode_id=1&pegawai_id=3   -> detail hasil 1 pegawai
 *  GET  /api/results.php?periode_id=1                 -> (admin) rekap semua pegawai pada periode
 *  GET  /api/results.php?action=bandingkan&pegawai_id=3 -> (self/admin) tren skor akhir pegawai antar-periode
 *  POST /api/results.php?action=hitung&periode_id=1[&pegawai_id=3]  -> (super_admin) hitung & simpan hasil_penilaian
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';
require_once __DIR__ . '/../helpers/results_calculator.php';

requireLogin();

$database = new Database();
$pdo = $database->getConnection();
$action = $_GET['action'] ?? '';

function calcWeightedRelasiAvg(array $relScores, array $bobotDigunakan) {
    $sumWeighted = 0.0;
    $sumWeight = 0.0;
    foreach (['atasan', 'rekan', 'bawahan', 'diri'] as $rel) {
        if (isset($relScores[$rel]) && $relScores[$rel] !== null) {
            $w = (float)($bobotDigunakan[$rel] ?? 0);
            if ($w > 0) {
                $sumWeighted += ((float)$relScores[$rel]) * $w;
                $sumWeight += $w;
            }
        }
    }
    if ($sumWeight > 0) {
        return round($sumWeighted / $sumWeight, 2);
    }
    $valid = array_filter($relScores, fn($v) => $v !== null);
    return count($valid) > 0 ? round(array_sum($valid) / count($valid), 2) : null;
}

/**
 * Mengambil rincian capaian indikator penilaian per kategori untuk 1 pegawai pada 1 periode.
 * Menyajikan skor per relasi (atasan, rekan, bawahan, diri), rata-rata terbobot sesuai konfigurasi bobot relasi,
 * persentase, predikat, serta rincian lengkap seluruh butir pertanyaan yang dievaluasi.
 */
function getRincianIndikator($pdo, $periodeId, $pegawaiId, $bobotDigunakan = null) {
    // 1. Cek apakah pegawai ini dinilai oleh bawahan pada periode ini (Pejabat Struktural)
    $bstmt = $pdo->prepare("SELECT COUNT(*) FROM mapping_penilai WHERE periode_id = ? AND pegawai_dinilai_id = ? AND jenis_relasi = 'bawahan'");
    $bstmt->execute([$periodeId, $pegawaiId]);
    $hasBawahan = ((int)$bstmt->fetchColumn()) > 0;

    if (!is_array($bobotDigunakan)) {
        $bobotConfig = getBobotConfig($pdo);
        $calc = hitungSkorPegawai($pdo, $periodeId, $pegawaiId, $bobotConfig);
        $bobotDigunakan = $calc['bobot_digunakan'];
    }

    // 2. Ambil struktur Kategori -> Indikator -> Pertanyaan sesuai peran pegawai yang dinilai
    // - Pelaksana / Fungsional: Paket 2 (atasan, rekan, diri) pada 5 kriteria (tanpa KEP).
    // - Pejabat Struktural: Seluruh butir yang dinilai oleh penilai (Paket 2 atasan/rekan/diri + Paket 1 bawahan termasuk KEP).
    $hasRekanExpr = "(" . dbFindInSet($pdo, "'rekan'", "q.target_relasi")
                  . " OR " . dbFindInSet($pdo, "'atasan'", "q.target_relasi")
                  . " OR " . dbFindInSet($pdo, "'diri'", "q.target_relasi") . ")";
    $hasBawahanQExpr = dbFindInSet($pdo, "'bawahan'", "q.target_relasi");

    if (!$hasBawahan) {
        $stmt = $pdo->query(
            "SELECT k.id AS kategori_id, k.kode_kategori, k.nama_kategori, k.deskripsi AS kategori_deskripsi, k.bobot_kategori,
                    i.id AS indikator_id, i.kode_indikator, i.nama_indikator, i.bobot_indikator,
                    q.id AS pertanyaan_id, q.pertanyaan, q.target_relasi, q.bobot AS bobot_pertanyaan, q.skor_min, q.skor_max
             FROM kategori_penilaian k
             JOIN indikator_penilaian i ON i.kategori_id = k.id AND i.status = 'aktif'
             JOIN pertanyaan_penilaian q ON q.indikator_id = i.id AND q.status = 'aktif'
             WHERE k.status = 'aktif' AND k.kode_kategori != 'KEP'
               AND $hasRekanExpr
             ORDER BY k.urutan ASC, i.urutan ASC, q.urutan ASC, q.id ASC"
        );
    } else {
        $stmt = $pdo->query(
            "SELECT k.id AS kategori_id, k.kode_kategori, k.nama_kategori, k.deskripsi AS kategori_deskripsi, k.bobot_kategori,
                    i.id AS indikator_id, i.kode_indikator, i.nama_indikator, i.bobot_indikator,
                    q.id AS pertanyaan_id, q.pertanyaan, q.target_relasi, q.bobot AS bobot_pertanyaan, q.skor_min, q.skor_max
             FROM kategori_penilaian k
             JOIN indikator_penilaian i ON i.kategori_id = k.id AND i.status = 'aktif'
             JOIN pertanyaan_penilaian q ON q.indikator_id = i.id AND q.status = 'aktif'
             WHERE k.status = 'aktif'
               AND ($hasRekanExpr OR $hasBawahanQExpr)
             ORDER BY k.urutan ASC, i.urutan ASC,
                      (CASE WHEN $hasBawahanQExpr THEN 2 ELSE 1 END) ASC,
                      q.urutan ASC, q.id ASC"
        );
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Ambil seluruh jawaban penilaian selesai untuk pegawai & periode ini
    $jstmt = $pdo->prepare(
        "SELECT j.pertanyaan_id, j.skor, j.komentar, m.jenis_relasi, q.indikator_id, i.kategori_id
         FROM jawaban_penilaian j
         JOIN mapping_penilai m ON j.mapping_id = m.id
         JOIN pertanyaan_penilaian q ON j.pertanyaan_id = q.id
         JOIN indikator_penilaian i ON q.indikator_id = i.id
         WHERE m.periode_id = ? AND m.pegawai_dinilai_id = ? AND m.status = 'selesai'"
    );
    $jstmt->execute([$periodeId, $pegawaiId]);
    $jawabanRows = $jstmt->fetchAll(PDO::FETCH_ASSOC);

    $jawabanByQ = [];
    $jawabanByKatRelasi = [];
    foreach ($jawabanRows as $j) {
        $qid = $j['pertanyaan_id'];
        $rel = $j['jenis_relasi'];
        $kid = $j['kategori_id'];
        $jawabanByQ[$qid][] = [
            'skor' => (float)$j['skor'],
            'relasi' => $rel,
            'komentar' => $j['komentar']
        ];
        $jawabanByKatRelasi[$kid][$rel][] = (float)$j['skor'];
    }

    $kategoriMap = [];
    foreach ($rows as $r) {
        $kid = $r['kategori_id'];
        $iid = $r['indikator_id'];
        $qid = $r['pertanyaan_id'];

        if (!isset($kategoriMap[$kid])) {
            $kategoriMap[$kid] = [
                'id' => $kid,
                'kode_kategori' => $r['kode_kategori'],
                'nama_kategori' => $r['nama_kategori'],
                'deskripsi' => $r['kategori_deskripsi'] ?? '',
                'bobot_kategori' => (float)$r['bobot_kategori'],
                'jumlah_q_bawahan' => 0,
                'jumlah_q_rekan' => 0,
                'indikator' => []
            ];
        }

        if (!isset($kategoriMap[$kid]['indikator'][$iid])) {
            $kategoriMap[$kid]['indikator'][$iid] = [
                'id' => $iid,
                'kode_indikator' => $r['kode_indikator'],
                'nama_indikator' => $r['nama_indikator'],
                'bobot_indikator' => (float)$r['bobot_indikator'],
                'pertanyaan' => []
            ];
        }

        $targetParts = array_map('trim', explode(',', (string)$r['target_relasi']));
        if (in_array('bawahan', $targetParts, true)) {
            $kategoriMap[$kid]['jumlah_q_bawahan']++;
        }
        if (in_array('rekan', $targetParts, true) || in_array('atasan', $targetParts, true) || in_array('diri', $targetParts, true)) {
            $kategoriMap[$kid]['jumlah_q_rekan']++;
        }

        $qAnswers = $jawabanByQ[$qid] ?? [];
        $qRelasiScores = ['atasan' => [], 'rekan' => [], 'bawahan' => [], 'diri' => []];
        $allScores = [];

        foreach ($qAnswers as $ans) {
            $qRelasiScores[$ans['relasi']][] = $ans['skor'];
            $allScores[] = $ans['skor'];
        }

        $avgRelasi = [];
        foreach ($qRelasiScores as $rel => $scList) {
            $avgRelasi[$rel] = count($scList) > 0 ? round(array_sum($scList) / count($scList), 2) : null;
        }

        $rataPertanyaan = calcWeightedRelasiAvg($avgRelasi, $bobotDigunakan);
        $persenPertanyaan = $rataPertanyaan !== null ? round(($rataPertanyaan / max(1, $r['skor_max'])) * 100, 1) : null;

        $kategoriMap[$kid]['indikator'][$iid]['pertanyaan'][] = [
            'id' => $qid,
            'pertanyaan' => $r['pertanyaan'],
            'target_relasi' => $r['target_relasi'],
            'bobot' => (float)$r['bobot_pertanyaan'],
            'skor_max' => (int)$r['skor_max'],
            'total_penilai' => count($allScores),
            'rata_skor' => $rataPertanyaan,
            'persentase' => $persenPertanyaan,
            'skor_relasi' => $avgRelasi
        ];
    }

    $resultKategori = [];
    foreach ($kategoriMap as $kid => $kData) {
        $indikatorList = [];

        // Skor agregat per relasi langsung dari jawaban kategori
        $katRelasiScores = [
            'atasan' => !empty($jawabanByKatRelasi[$kid]['atasan']) ? round(array_sum($jawabanByKatRelasi[$kid]['atasan']) / count($jawabanByKatRelasi[$kid]['atasan']), 2) : null,
            'rekan' => !empty($jawabanByKatRelasi[$kid]['rekan']) ? round(array_sum($jawabanByKatRelasi[$kid]['rekan']) / count($jawabanByKatRelasi[$kid]['rekan']), 2) : null,
            'bawahan' => !empty($jawabanByKatRelasi[$kid]['bawahan']) ? round(array_sum($jawabanByKatRelasi[$kid]['bawahan']) / count($jawabanByKatRelasi[$kid]['bawahan']), 2) : null,
            'diri' => !empty($jawabanByKatRelasi[$kid]['diri']) ? round(array_sum($jawabanByKatRelasi[$kid]['diri']) / count($jawabanByKatRelasi[$kid]['diri']), 2) : null,
        ];

        foreach ($kData['indikator'] as $iid => $iData) {
            $indRelasiScores = ['atasan' => [], 'rekan' => [], 'bawahan' => [], 'diri' => []];
            foreach ($iData['pertanyaan'] as $q) {
                foreach (['atasan', 'rekan', 'bawahan', 'diri'] as $rel) {
                    if ($q['skor_relasi'][$rel] !== null) {
                        $indRelasiScores[$rel][] = $q['skor_relasi'][$rel];
                    }
                }
            }

            $indAvgRelasi = [];
            foreach (['atasan', 'rekan', 'bawahan', 'diri'] as $rel) {
                $indAvgRelasi[$rel] = $katRelasiScores[$rel] ?? (count($indRelasiScores[$rel]) > 0 ? round(array_sum($indRelasiScores[$rel]) / count($indRelasiScores[$rel]), 2) : null);
            }

            $indRata = calcWeightedRelasiAvg($indAvgRelasi, $bobotDigunakan);
            $indPersen = $indRata !== null ? round(($indRata / 5) * 100, 1) : null;

            $predikat = null;
            if ($indPersen !== null) {
                if ($indPersen >= 91) $predikat = 'Sangat Baik';
                elseif ($indPersen >= 76) $predikat = 'Baik';
                elseif ($indPersen >= 61) $predikat = 'Cukup';
                elseif ($indPersen >= 51) $predikat = 'Kurang';
                else $predikat = 'Buruk';
            }

            $indikatorList[] = [
                'id' => $iData['id'],
                'kode_indikator' => $iData['kode_indikator'],
                'nama_indikator' => $iData['nama_indikator'],
                'bobot_indikator' => $iData['bobot_indikator'],
                'rata_skor' => $indRata,
                'persentase' => $indPersen,
                'predikat' => $predikat,
                'skor_relasi' => $indAvgRelasi,
                'total_pertanyaan' => count($iData['pertanyaan']),
                'daftar_pertanyaan' => $iData['pertanyaan']
            ];
        }

        $katRata = calcWeightedRelasiAvg($katRelasiScores, $bobotDigunakan);
        $katPersen = $katRata !== null ? round(($katRata / 5) * 100, 1) : null;

        $predikatKat = null;
        if ($katPersen !== null) {
            if ($katPersen >= 91) $predikatKat = 'Sangat Baik';
            elseif ($katPersen >= 76) $predikatKat = 'Baik';
            elseif ($katPersen >= 61) $predikatKat = 'Cukup';
            elseif ($katPersen >= 51) $predikatKat = 'Kurang';
            else $predikatKat = 'Buruk';
        }

        $totalPertanyaanKat = 0;
        foreach ($kData['indikator'] as $iData) {
            $totalPertanyaanKat += count($iData['pertanyaan']);
        }

        $resultKategori[] = [
            'id' => $kData['id'],
            'kode_kategori' => $kData['kode_kategori'],
            'nama_kategori' => $kData['nama_kategori'],
            'deskripsi' => $kData['deskripsi'] ?? '',
            'bobot_kategori' => $kData['bobot_kategori'],
            'bobot_paket_bawahan' => $kData['jumlah_q_bawahan'] * 5,
            'bobot_paket_rekan' => $kData['jumlah_q_rekan'] * 5,
            'jumlah_q_bawahan' => $kData['jumlah_q_bawahan'],
            'jumlah_q_rekan' => $kData['jumlah_q_rekan'],
            'total_pertanyaan' => $totalPertanyaanKat,
            'skor_relasi' => $katRelasiScores,
            'rata_skor' => $katRata,
            'persentase' => $katPersen,
            'predikat' => $predikatKat,
            'indikator' => $indikatorList
        ];
    }

    return $resultKategori;
}

// ── POST: hitung & simpan permanen ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'hitung') {
    requireAdmin();
    $periodeId = $_GET['periode_id'] ?? null;
    if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

    $bobotConfig = getBobotConfig($pdo);
    $totalBobotStruktural = ($bobotConfig['bobot_atasan'] ?? 0) + ($bobotConfig['bobot_rekan'] ?? 0)
                          + ($bobotConfig['bobot_bawahan'] ?? 0) + ($bobotConfig['bobot_diri_sendiri'] ?? 0);
    if (abs($totalBobotStruktural - 100) > 0.01) {
        sendResponse(false, "Total bobot penilaian Pejabat Struktural di Konfigurasi Sistem harus = 100% (saat ini {$totalBobotStruktural}%). Perbaiki di menu Konfigurasi terlebih dahulu.", null, 422);
    }
    $totalBobotPelaksana = ($bobotConfig['bobot_pelaksana_atasan'] ?? 50) + ($bobotConfig['bobot_pelaksana_rekan'] ?? 40) + ($bobotConfig['bobot_pelaksana_diri_sendiri'] ?? $bobotConfig['bobot_diri_sendiri'] ?? 10);
    if (abs($totalBobotPelaksana - 100) > 0.01) {
        sendResponse(false, "Total bobot penilaian Pegawai Pelaksana di Konfigurasi Sistem harus = 100% (saat ini {$totalBobotPelaksana}%). Perbaiki di menu Konfigurasi terlebih dahulu.", null, 422);
    }

    $pegawaiId = $_GET['pegawai_id'] ?? null;
    $targetIds = $pegawaiId ? [(int)$pegawaiId] : null;
    $count = simpanHasilPenilaian($pdo, (int)$periodeId, $targetIds, currentUserId());

    logAudit($pdo, 'hasil_penilaian', 'hitung_ulang', null, ['periode_id' => $periodeId, 'total_pegawai' => $count]);
    sendResponse(true, "Perhitungan hasil penilaian selesai untuk $count pegawai.", ["total" => $count]);
}

// ── GET: tren skor akhir pegawai antar-periode ──────────────────────
if ($action === 'bandingkan') {
    $pegawaiId = $_GET['pegawai_id'] ?? currentPegawaiId();
    if (!isAdminOrSuperAdmin() && $pegawaiId != currentPegawaiId()) {
        sendResponse(false, "Anda hanya boleh melihat perbandingan hasil Anda sendiri.", null, 403);
    }
    $stmt = $pdo->prepare(
        "SELECT h.*, pp.nama_periode, pp.tahun FROM hasil_penilaian h
         JOIN periode_penilaian pp ON h.periode_id = pp.id
         WHERE h.pegawai_id = ? ORDER BY pp.tanggal_mulai ASC"
    );
    $stmt->execute([$pegawaiId]);
    sendResponse(true, "OK", $stmt->fetchAll());
}

// ── GET: hasil (dari tabel hasil_penilaian jika sudah dihitung) ─────
$periodeId = $_GET['periode_id'] ?? null;
$pegawaiId = $_GET['pegawai_id'] ?? null;
if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

// Pegawai biasa hanya boleh melihat hasil dirinya sendiri
if (!isAdminOrSuperAdmin()) {
    $pegawaiId = currentPegawaiId();
}

if ($pegawaiId) {
    $ustmt = $pdo->prepare(
        "SELECT p.id, p.nip, p.nama_lengkap, p.gelar_depan, p.gelar_belakang, p.golongan_ruang,
                j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja
         FROM pegawai p LEFT JOIN jabatan j ON p.jabatan_id = j.id LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE p.id = ?"
    );
    $ustmt->execute([$pegawaiId]);
    $pegawaiInfo = $ustmt->fetch();
    if (!$pegawaiInfo) sendResponse(false, "Pegawai tidak ditemukan.", null, 404);

    $pstmt = $pdo->prepare("SELECT * FROM periode_penilaian WHERE id = ?");
    $pstmt->execute([$periodeId]);
    $periodeInfo = $pstmt->fetch();

    $bobotConfig = getBobotConfig($pdo);

    $hstmt = $pdo->prepare("SELECT * FROM hasil_penilaian WHERE periode_id = ? AND pegawai_id = ?");
    $hstmt->execute([$periodeId, $pegawaiId]);
    $hasil = $hstmt->fetch();

    // Detail per penilai (nama penilai hanya untuk admin & super_admin, demi kerahasiaan penilai)
    $mstmt = $pdo->prepare(
        "SELECT m.id AS mapping_id, m.jenis_relasi, m.status, m.tanggal_submit, m.komentar_masukan, p.nama_lengkap AS nama_penilai, p.golongan_ruang AS golongan_penilai
         FROM mapping_penilai m JOIN pegawai p ON m.pegawai_penilai_id = p.id
         WHERE m.periode_id = ? AND m.pegawai_dinilai_id = ? AND m.status = 'selesai'"
    );
    $mstmt->execute([$periodeId, $pegawaiId]);
    $mappings = $mstmt->fetchAll();

    $detail = [];
    foreach ($mappings as $m) {
        $jstmt = $pdo->prepare(
            "SELECT j.skor, j.komentar, q.pertanyaan, q.bobot, q.skor_max, i.nama_indikator, k.nama_kategori
             FROM jawaban_penilaian j
             JOIN pertanyaan_penilaian q ON j.pertanyaan_id = q.id
             JOIN indikator_penilaian i ON q.indikator_id = i.id
             JOIN kategori_penilaian k ON i.kategori_id = k.id
             WHERE j.mapping_id = ?"
        );
        $jstmt->execute([$m['mapping_id']]);
        $entry = [
            "mapping_id" => $m['mapping_id'], "jenis_relasi" => $m['jenis_relasi'],
            "tanggal_submit" => $m['tanggal_submit'], "komentar_masukan" => $m['komentar_masukan'], 
            "jawaban" => $jstmt->fetchAll()
        ];
        if (isAdminOrSuperAdmin()) {
            $entry['penilai'] = $m['nama_penilai'];
            $entry['golongan_penilai'] = $m['golongan_penilai'] ?? null;
        }
        $detail[] = $entry;
    }

    // Cek apakah pegawai memiliki bawahan pada periode ini
    $bstmt = $pdo->prepare("SELECT COUNT(*) FROM mapping_penilai WHERE periode_id = ? AND pegawai_dinilai_id = ? AND jenis_relasi = 'bawahan'");
    $bstmt->execute([$periodeId, $pegawaiId]);
    $hasBawahan = ((int)$bstmt->fetchColumn()) > 0;

    // Ambil data Kepala Dinas untuk pengesahan kedinasan
    $kdStmt = $pdo->query("SELECT p.nip, p.nama_lengkap, p.gelar_depan, p.gelar_belakang, j.nama_jabatan
                           FROM pegawai p JOIN jabatan j ON p.jabatan_id = j.id
                           WHERE LOWER(j.nama_jabatan) LIKE '%kepala dinas%' LIMIT 1");
    $kepalaDinas = $kdStmt->fetch();

    $skorCalc = hitungSkorPegawai($pdo, $periodeId, $pegawaiId, $bobotConfig);

    sendResponse(true, "OK", [
        "pegawai" => $pegawaiInfo,
        "periode" => $periodeInfo,
        "bobot_config" => $bobotConfig,
        "bobot_digunakan" => $skorCalc['bobot_digunakan'],
        "hasil" => $hasil,
        "has_bawahan" => $hasBawahan,
        "is_struktural" => $hasBawahan,
        "kepala_dinas" => $kepalaDinas,
        "sudah_dihitung" => (bool)$hasil,
        "detail_penilai" => $detail,
        "rincian_indikator" => getRincianIndikator($pdo, $periodeId, $pegawaiId, $skorCalc['bobot_digunakan'])
    ]);
} else {
    requireAdmin();

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : null;
    $total = 0;

    if ($limit) {
        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM pegawai p WHERE p.id IN (SELECT DISTINCT pegawai_dinilai_id FROM mapping_penilai WHERE periode_id = ?)");
        $countStmt->execute([$periodeId]);
        $total = $countStmt->fetchColumn();
    }

    $limitClause = "";
    if ($limit) {
        $offset = ($page - 1) * $limit;
        $limitClause = " LIMIT $limit OFFSET $offset";
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.nama_lengkap, p.golongan_ruang, j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja, h.nilai_akhir, h.predikat, h.tanggal_hitung
         FROM pegawai p
         LEFT JOIN jabatan j ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         LEFT JOIN hasil_penilaian h ON h.pegawai_id = p.id AND h.periode_id = ?
         WHERE p.id IN (SELECT DISTINCT pegawai_dinilai_id FROM mapping_penilai WHERE periode_id = ?)
         ORDER BY p.nama_lengkap ASC" . $limitClause
    );
    $stmt->execute([$periodeId, $periodeId]);

    $meta = $limit ? [
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'totalPages' => ceil($total / $limit)
    ] : null;

    sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
}
