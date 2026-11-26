<?php
/**
 * USE CASE: Monitoring Penilaian & Audit Log (Admin)
 * Endpoints:
 *  GET /api/monitoring.php?periode_id=1                        -> progress penilaian per pegawai/mapping + statistik keseluruhan
 *  GET /api/monitoring.php?action=detail_penilai&periode_id=1&penilai_id=10 -> rincian progres penilaian penilai terhadap masing-masing yang dinilai
 *  GET /api/monitoring.php?action=detail_jawaban&mapping_id=123 -> rincian butir pertanyaan & skor jawaban untuk audit kesesuaian penilaian
 *  GET /api/monitoring.php?action=log                           -> audit log terbaru (super_admin only, data sensitif)
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

requireAdmin(); // admin & super_admin bisa akses monitoring umum

$database = new Database();
$pdo = $database->getConnection();
$action = $_GET['action'] ?? '';

if ($action === 'log') {
    requireSuperAdmin(); // audit log berisi data sensitif (data_sebelum/data_sesudah), dibatasi ketat
    $limit = max(1, min((int)($_GET['limit'] ?? 100), 500));
    $modul = $_GET['modul'] ?? '';
    $where = ''; $params = [];
    if ($modul !== '') { $where = "WHERE l.modul = ?"; $params[] = $modul; }

    $stmt = $pdo->prepare(
        "SELECT l.*, u.username FROM audit_log l
         LEFT JOIN users u ON l.user_id = u.id
         $where ORDER BY l.created_at DESC LIMIT $limit"
    );
    $stmt->execute($params);
    sendResponse(true, "OK", $stmt->fetchAll());
}

if ($action === 'dashboard_stats') {
    $periodeId = isset($_GET['periode_id']) && $_GET['periode_id'] !== '' ? (int)$_GET['periode_id'] : 0;

    if (!$periodeId) {
        $pStmt = $pdo->query(
            "SELECT id, nama_periode, status, tanggal_mulai, tanggal_selesai
             FROM periode_penilaian
             WHERE status IN ('aktif', 'ditutup')
             ORDER BY CASE WHEN status = 'aktif' THEN 0 ELSE 1 END, tanggal_mulai DESC
             LIMIT 1"
        );
        $periode = $pStmt->fetch();
    } else {
        $pStmt = $pdo->prepare("SELECT id, nama_periode, status, tanggal_mulai, tanggal_selesai FROM periode_penilaian WHERE id = ?");
        $pStmt->execute([$periodeId]);
        $periode = $pStmt->fetch();
    }

    if (!$periode) {
        sendResponse(true, "OK", [
            'periode' => null,
            'predikat' => [],
            'summary' => [
                'total_dinilai' => 0,
                'total_dihitung' => 0,
                'belum_dihitung' => 0,
                'rata_skor_dinas' => null,
                'skor_tertinggi' => null,
                'skor_terendah' => null,
            ],
            'relasi' => [],
            'kriteria' => []
        ]);
    }

    $pid = (int)$periode['id'];

    // 1. Distribusi Predikat Kinerja (aman terhadap ONLY_FULL_GROUP_BY)
    $predStmt = $pdo->prepare(
        "SELECT t.predikat_label AS predikat,
                COUNT(*) AS jumlah,
                ROUND(AVG(t.nilai_akhir), 2) AS rata_skor
         FROM (
             SELECT COALESCE(NULLIF(TRIM(h.predikat), ''), 'Belum Dihitung') AS predikat_label,
                    h.nilai_akhir
             FROM hasil_penilaian h
             WHERE h.periode_id = ?
         ) t
         GROUP BY t.predikat_label"
    );
    $predStmt->execute([$pid]);
    $predRows = $predStmt->fetchAll();

    // Total pegawai yang dipetakan sebagai dinilai pada periode ini
    $dinilaiStmt = $pdo->prepare("SELECT COUNT(DISTINCT pegawai_dinilai_id) FROM mapping_penilai WHERE periode_id = ?");
    $dinilaiStmt->execute([$pid]);
    $totalDinilai = (int)$dinilaiStmt->fetchColumn();

    $sumStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total_dihitung,
                ROUND(AVG(nilai_akhir), 2) AS rata_skor_dinas,
                ROUND(MAX(nilai_akhir), 2) AS skor_tertinggi,
                ROUND(MIN(nilai_akhir), 2) AS skor_terendah
         FROM hasil_penilaian
         WHERE periode_id = ?"
    );
    $sumStmt->execute([$pid]);
    $sumRow = $sumStmt->fetch();

    $totalDihitung = (int)($sumRow['total_dihitung'] ?? 0);
    $belumDihitung = max(0, $totalDinilai - $totalDihitung);

    // Urutkan predikat secara standar
    $predMap = [];
    foreach ($predRows as $pr) {
        $predMap[$pr['predikat']] = [
            'predikat' => $pr['predikat'],
            'jumlah' => (int)$pr['jumlah'],
            'rata_skor' => $pr['rata_skor'] !== null ? (float)$pr['rata_skor'] : null
        ];
    }
    $predOrder = ['Sangat Baik', 'Baik', 'Cukup', 'Kurang', 'Sangat Kurang'];
    $predikatList = [];
    foreach ($predOrder as $lbl) {
        if (isset($predMap[$lbl])) {
            $predikatList[] = $predMap[$lbl];
            unset($predMap[$lbl]);
        }
    }
    foreach ($predMap as $extra) {
        $predikatList[] = $extra;
    }

    // 2. Progres Penilaian per Relasi Penilai
    $relStmt = $pdo->prepare(
        "SELECT jenis_relasi,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS selesai,
                SUM(CASE WHEN status = 'proses' THEN 1 ELSE 0 END) AS proses,
                SUM(CASE WHEN status = 'belum_mulai' THEN 1 ELSE 0 END) AS belum
         FROM mapping_penilai
         WHERE periode_id = ?
         GROUP BY jenis_relasi
         ORDER BY CASE jenis_relasi
             WHEN 'atasan' THEN 1
             WHEN 'rekan' THEN 2
             WHEN 'bawahan' THEN 3
             WHEN 'diri' THEN 4
             ELSE 5 END"
    );
    $relStmt->execute([$pid]);
    $relasiList = array_map(function ($r) {
        return [
            'jenis_relasi' => $r['jenis_relasi'],
            'total' => (int)$r['total'],
            'selesai' => (int)$r['selesai'],
            'proses' => (int)$r['proses'],
            'belum' => (int)$r['belum']
        ];
    }, $relStmt->fetchAll());

    // 3. Rata-rata Skor per Kriteria Kompetensi
    $katStmt = $pdo->prepare(
        "SELECT k.kode_kategori, k.nama_kategori,
                ROUND(AVG(j.skor), 2) AS rata_skor,
                COUNT(j.id) AS total_jawaban
         FROM jawaban_penilaian j
         JOIN mapping_penilai m ON j.mapping_id = m.id
         JOIN pertanyaan_penilaian q ON j.pertanyaan_id = q.id
         JOIN indikator_penilaian i ON q.indikator_id = i.id
         JOIN kategori_penilaian k ON i.kategori_id = k.id
         WHERE m.periode_id = ? AND m.status = 'selesai'
         GROUP BY k.id, k.kode_kategori, k.nama_kategori, k.urutan
         ORDER BY k.urutan ASC"
    );
    $katStmt->execute([$pid]);
    $kriteriaList = array_map(function ($k) {
        return [
            'kode_kategori' => $k['kode_kategori'],
            'nama_kategori' => $k['nama_kategori'],
            'rata_skor' => $k['rata_skor'] !== null ? (float)$k['rata_skor'] : 0,
            'total_jawaban' => (int)$k['total_jawaban']
        ];
    }, $katStmt->fetchAll());

    sendResponse(true, "OK", [
        'periode' => $periode,
        'predikat' => $predikatList,
        'summary' => [
            'total_dinilai' => $totalDinilai,
            'total_dihitung' => $totalDihitung,
            'belum_dihitung' => $belumDihitung,
            'rata_skor_dinas' => $sumRow['rata_skor_dinas'] !== null ? (float)$sumRow['rata_skor_dinas'] : null,
            'skor_tertinggi' => $sumRow['skor_tertinggi'] !== null ? (float)$sumRow['skor_tertinggi'] : null,
            'skor_terendah' => $sumRow['skor_terendah'] !== null ? (float)$sumRow['skor_terendah'] : null,
        ],
        'relasi' => $relasiList,
        'kriteria' => $kriteriaList
    ]);
}

if ($action === 'detail_penilai') {
    $periodeId = (int)($_GET['periode_id'] ?? 0);
    $penilaiId = (int)($_GET['penilai_id'] ?? 0);
    if (!$periodeId || !$penilaiId) {
        sendResponse(false, "periode_id dan penilai_id wajib disertakan.", null, 400);
    }

    $stmtPenilai = $pdo->prepare(
        "SELECT p.id, p.nip, p.nama_lengkap, p.golongan_ruang, j.nama_jabatan, uk.nama_unit
         FROM pegawai p
         LEFT JOIN jabatan j ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE p.id = ?"
    );
    $stmtPenilai->execute([$penilaiId]);
    $penilai = $stmtPenilai->fetch();
    if (!$penilai) sendResponse(false, "Pegawai penilai tidak ditemukan.", null, 404);

    $stmtPeriode = $pdo->prepare("SELECT id, nama_periode, status, tanggal_mulai, tanggal_selesai FROM periode_penilaian WHERE id = ?");
    $stmtPeriode->execute([$periodeId]);
    $periode = $stmtPeriode->fetch();

    $stmtList = $pdo->prepare(
        "SELECT m.id AS mapping_id, m.periode_id, m.pegawai_dinilai_id, m.pegawai_penilai_id,
                m.jenis_relasi, m.bobot_relasi, m.status,
                m.tanggal_mulai_isi, m.tanggal_submit, m.komentar_masukan,
                pd.nama_lengkap AS nama_dinilai, pd.nip AS nip_dinilai, pd.golongan_ruang AS golongan_dinilai,
                jd.nama_jabatan AS jabatan_dinilai, ukd.nama_unit AS unit_dinilai,
                COUNT(j.id) AS total_jawaban,
                ROUND(AVG(j.skor), 2) AS rata_skor,
                MIN(j.skor) AS min_skor,
                MAX(j.skor) AS max_skor,
                COUNT(DISTINCT j.skor) AS variasi_skor,
                ROUND(STDDEV_POP(j.skor), 2) AS stddev_skor
         FROM mapping_penilai m
         JOIN pegawai pd ON m.pegawai_dinilai_id = pd.id
         LEFT JOIN jabatan jd ON pd.jabatan_id = jd.id
         LEFT JOIN unit_kerja ukd ON pd.unit_kerja_id = ukd.id
         LEFT JOIN jawaban_penilaian j ON j.mapping_id = m.id
         WHERE m.periode_id = ? AND m.pegawai_penilai_id = ?
         GROUP BY m.id, m.periode_id, m.pegawai_dinilai_id, m.pegawai_penilai_id,
                  m.jenis_relasi, m.bobot_relasi, m.status,
                  m.tanggal_mulai_isi, m.tanggal_submit, m.komentar_masukan,
                  pd.nama_lengkap, pd.nip, pd.golongan_ruang, jd.nama_jabatan, ukd.nama_unit
         ORDER BY 
             CASE 
                 WHEN m.jenis_relasi = 'diri' THEN 1
                 WHEN m.jenis_relasi = 'bawahan' THEN 2
                 WHEN m.jenis_relasi = 'rekan' THEN 3
                 WHEN m.jenis_relasi = 'atasan' THEN 4
                 ELSE 5
             END ASC,
             pd.nama_lengkap ASC"
    );
    $stmtList->execute([$periodeId, $penilaiId]);
    $daftarDinilai = $stmtList->fetchAll();

    $flagSeragamCount = 0;
    $berkomentarCount = 0;
    $totalSelesai = 0;
    $totalProses = 0;
    $totalBelum = 0;
    $totalSkorSum = 0;
    $totalJawabanSum = 0;

    foreach ($daftarDinilai as &$row) {
        $row['total_jawaban'] = (int)$row['total_jawaban'];
        $row['variasi_skor'] = (int)$row['variasi_skor'];
        $row['rata_skor'] = $row['rata_skor'] !== null ? (float)$row['rata_skor'] : null;
        $row['min_skor'] = $row['min_skor'] !== null ? (int)$row['min_skor'] : null;
        $row['max_skor'] = $row['max_skor'] !== null ? (int)$row['max_skor'] : null;
        $hasKomentar = !empty(trim($row['komentar_masukan'] ?? ''));
        $row['has_komentar'] = $hasKomentar;
        if ($hasKomentar) $berkomentarCount++;

        if ($row['status'] === 'selesai') {
            $totalSelesai++;
            if ($row['rata_skor'] !== null && $row['total_jawaban'] > 0) {
                $totalSkorSum += ($row['rata_skor'] * $row['total_jawaban']);
                $totalJawabanSum += $row['total_jawaban'];
            }

            // Indikasi pola penilaian: straight-lining detection (nilai sama persis di seluruh butir)
            if ($row['total_jawaban'] >= 3 && $row['variasi_skor'] === 1) {
                $row['indikasi_pola'] = 'seragam';
                $row['catatan_kesesuaian'] = "Skor seragam ({$row['min_skor']}) di semua butir";
                $flagSeragamCount++;
            } else {
                $row['indikasi_pola'] = 'wajar';
                $row['catatan_kesesuaian'] = "Terdapat variasi skor (Min: {$row['min_skor']}, Max: {$row['max_skor']})";
            }
        } elseif ($row['status'] === 'proses') {
            $totalProses++;
            $row['indikasi_pola'] = 'proses';
            $row['catatan_kesesuaian'] = "Sedang proses pengisian";
        } else {
            $totalBelum++;
            $row['indikasi_pola'] = 'belum';
            $row['catatan_kesesuaian'] = "Belum mulai menilai";
        }
    }
    unset($row);

    $rataGlobal = $totalJawabanSum > 0 ? round($totalSkorSum / $totalJawabanSum, 2) : 0;
    $auditSummary = [
        'total_tugas'         => count($daftarDinilai),
        'total_selesai'       => $totalSelesai,
        'total_proses'        => $totalProses,
        'total_belum'         => $totalBelum,
        'persentase_selesai'  => count($daftarDinilai) > 0 ? round(($totalSelesai / count($daftarDinilai)) * 100, 1) : 0,
        'total_berkomentar'   => $berkomentarCount,
        'flag_skor_seragam'   => $flagSeragamCount,
        'rata_skor_diberikan' => $rataGlobal,
        'status_kualitas'     => ($flagSeragamCount > 0) ? 'perlu_perhatian' : ($totalSelesai > 0 ? 'sesuai' : 'belum_dinilai')
    ];

    sendResponse(true, "OK", [
        'penilai'        => $penilai,
        'periode'        => $periode,
        'audit_summary'  => $auditSummary,
        'daftar_dinilai' => $daftarDinilai
    ]);
}

if ($action === 'detail_jawaban') {
    $mappingId = (int)($_GET['mapping_id'] ?? 0);
    if (!$mappingId) sendResponse(false, "mapping_id wajib disertakan.", null, 400);

    $stmtMapping = $pdo->prepare(
        "SELECT m.id, m.periode_id, m.pegawai_dinilai_id, m.pegawai_penilai_id,
                m.jenis_relasi, m.status, m.tanggal_mulai_isi, m.tanggal_submit, m.komentar_masukan,
                pen.nama_lengkap AS nama_penilai, pen.nip AS nip_penilai, pen.golongan_ruang AS golongan_penilai,
                din.nama_lengkap AS nama_dinilai, din.nip AS nip_dinilai, din.golongan_ruang AS golongan_dinilai,
                jdin.nama_jabatan AS jabatan_dinilai, ukdin.nama_unit AS unit_dinilai,
                pp.nama_periode
         FROM mapping_penilai m
         JOIN pegawai pen ON m.pegawai_penilai_id = pen.id
         JOIN pegawai din ON m.pegawai_dinilai_id = din.id
         LEFT JOIN jabatan jdin ON din.jabatan_id = jdin.id
         LEFT JOIN unit_kerja ukdin ON din.unit_kerja_id = ukdin.id
         JOIN periode_penilaian pp ON m.periode_id = pp.id
         WHERE m.id = ?"
    );
    $stmtMapping->execute([$mappingId]);
    $mapping = $stmtMapping->fetch();
    if (!$mapping) sendResponse(false, "Mapping penilaian tidak ditemukan.", null, 404);

    $relasiExpr = dbFindInSet($pdo, 'm.jenis_relasi', 'q.target_relasi');
    $stmtJawaban = $pdo->prepare(
        "SELECT 
            q.id AS pertanyaan_id,
            q.pertanyaan,
            q.bobot,
            q.skor_min,
            q.skor_max,
            q.urutan AS pertanyaan_urutan,
            i.id AS indikator_id,
            i.nama_indikator,
            k.id AS kategori_id,
            k.nama_kategori,
            j.skor,
            j.komentar AS komentar_jawaban
        FROM mapping_penilai m
        JOIN pertanyaan_penilaian q ON (
            q.status = 'aktif' AND 
            (q.target_relasi IS NULL OR q.target_relasi = '' OR $relasiExpr)
        )
        JOIN indikator_penilaian i ON q.indikator_id = i.id
        JOIN kategori_penilaian k ON i.kategori_id = k.id
        LEFT JOIN jawaban_penilaian j ON j.mapping_id = m.id AND j.pertanyaan_id = q.id
        WHERE m.id = ?
        ORDER BY k.urutan ASC, i.urutan ASC, q.urutan ASC"
    );
    $stmtJawaban->execute([$mappingId]);
    $butirJawaban = $stmtJawaban->fetchAll();

    $kategoriGroups = [];
    $totalSkor = 0;
    $countTerisi = 0;
    $distinctSkor = [];

    foreach ($butirJawaban as $b) {
        $katId = $b['kategori_id'];
        if (!isset($kategoriGroups[$katId])) {
            $kategoriGroups[$katId] = [
                'kategori_id' => $katId,
                'nama_kategori' => $b['nama_kategori'],
                'items' => []
            ];
        }
        $kategoriGroups[$katId]['items'][] = $b;
        if ($b['skor'] !== null) {
            $totalSkor += (int)$b['skor'];
            $countTerisi++;
            $distinctSkor[$b['skor']] = true;
        }
    }

    $avgSkor = $countTerisi > 0 ? round($totalSkor / $countTerisi, 2) : null;
    $isSeragam = ($countTerisi >= 3 && count($distinctSkor) === 1);

    sendResponse(true, "OK", [
        'mapping' => $mapping,
        'summary' => [
            'total_pertanyaan'   => count($butirJawaban),
            'pertanyaan_terisi'  => $countTerisi,
            'rata_rata_skor'     => $avgSkor,
            'variasi_skor_count' => count($distinctSkor),
            'is_skor_seragam'    => $isSeragam
        ],
        'kategori_jawaban' => array_values($kategoriGroups)
    ]);
}

$periodeId = $_GET['periode_id'] ?? null;
if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

$unitFilter   = '';
$paramsStat   = [$periodeId];

// Filter opsional per unit kerja (gunakan id bukan nama)
if (!empty($_GET['unit_kerja_id'])) {
    $unitFilter = 'AND (p.unit_kerja_id = ? OR p.unit_kerja_id IN (SELECT id FROM unit_kerja WHERE parent_id = ?))';
    $paramsStat[] = (int)$_GET['unit_kerja_id'];
    $paramsStat[] = (int)$_GET['unit_kerja_id'];
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_mapping,
            SUM(CASE WHEN m.status = 'selesai'     THEN 1 ELSE 0 END) AS total_selesai,
            SUM(CASE WHEN m.status = 'proses'      THEN 1 ELSE 0 END) AS total_proses,
            SUM(CASE WHEN m.status = 'belum_mulai' THEN 1 ELSE 0 END) AS total_belum
     FROM mapping_penilai m
     JOIN pegawai p ON m.pegawai_penilai_id = p.id
     WHERE m.periode_id = ? $unitFilter"
);
$stmt->execute($paramsStat);
$statistik = $stmt->fetch();
$statistik['persentase_selesai'] = $statistik['total_mapping'] > 0
    ? round(($statistik['total_selesai'] / $statistik['total_mapping']) * 100, 1) : 0;

$stmt2 = $pdo->prepare(
    "SELECT p.id, p.nip, p.nama_lengkap, p.golongan_ruang, j.nama_jabatan, uk.nama_unit, uk.id AS unit_kerja_id,
            COUNT(m.id) AS total_tugas,
            SUM(CASE WHEN m.status = 'selesai'     THEN 1 ELSE 0 END) AS selesai,
            SUM(CASE WHEN m.status = 'proses'      THEN 1 ELSE 0 END) AS proses,
            SUM(CASE WHEN m.status = 'belum_mulai' THEN 1 ELSE 0 END) AS belum
     FROM mapping_penilai m
     JOIN pegawai p ON m.pegawai_penilai_id = p.id
     LEFT JOIN jabatan j ON p.jabatan_id = j.id
     LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
     WHERE m.periode_id = ? $unitFilter
     GROUP BY p.id, p.nip, p.nama_lengkap, p.golongan_ruang, j.nama_jabatan, uk.nama_unit, uk.id
     ORDER BY selesai ASC, p.nama_lengkap ASC"
);
$stmt2->execute($paramsStat);
$progressPenilai = $stmt2->fetchAll();

sendResponse(true, "OK", [
    "statistik"            => $statistik,
    "progress_per_penilai" => $progressPenilai
]);
