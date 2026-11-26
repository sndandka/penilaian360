<?php
/**
 * USE CASE: Cetak Laporan Penilaian (Admin)
 * Endpoints:
 *  GET /api/reports.php?periode_id=1&format=json  -> data rekap laporan (JSON)
 *  GET /api/reports.php?periode_id=1&format=csv    -> download CSV
 *  GET /api/reports.php?periode_id=1&format=pdf    -> download PDF dengan kop surat resmi
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

requireAdmin();

$database = new Database();
$pdo = $database->getConnection();

$periodeId = $_GET['periode_id'] ?? null;
$format = $_GET['format'] ?? 'json';
if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

$pstmt = $pdo->prepare("SELECT * FROM periode_penilaian WHERE id = ?");
$pstmt->execute([$periodeId]);
$periode = $pstmt->fetch();
if (!$periode) sendResponse(false, "Periode tidak ditemukan.", null, 404);

$unitFilter = '';
$paramsPeg = [$periodeId];

if (!empty($_GET['unit_kerja_id'])) {
    $unitFilter = ' AND (p.unit_kerja_id = ? OR p.unit_kerja_id IN (SELECT id FROM unit_kerja WHERE parent_id = ?))';
    $paramsPeg[] = (int)$_GET['unit_kerja_id'];
    $paramsPeg[] = (int)$_GET['unit_kerja_id'];
}

$stmt = $pdo->prepare(
    "SELECT DISTINCT p.id, p.nip, p.nama_lengkap, p.golongan_ruang, j.nama_jabatan AS jabatan, uk.nama_unit AS unit_kerja
     FROM mapping_penilai m
     JOIN pegawai p ON m.pegawai_dinilai_id = p.id
     LEFT JOIN jabatan j ON p.jabatan_id = j.id
     LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
     WHERE m.periode_id = ? $unitFilter
     ORDER BY uk.nama_unit, p.nama_lengkap ASC"
);
$stmt->execute($paramsPeg);
$pegawaiList = $stmt->fetchAll();

$hstmt = $pdo->prepare("SELECT * FROM hasil_penilaian WHERE periode_id = ? AND pegawai_id = ?");

$laporan = [];
foreach ($pegawaiList as $peg) {
    $hstmt->execute([$periodeId, $peg['id']]);
    $h = $hstmt->fetch();
    $laporan[] = [
        "id" => $peg['id'], "nip" => $peg['nip'],
        "nama" => $peg['nama_lengkap'], "golongan" => $peg['golongan_ruang'] ?? '-', "jabatan" => $peg['jabatan'], "unit_kerja" => $peg['unit_kerja'],
        "skor_diri" => $h['nilai_diri'] ?? '-', "skor_atasan" => $h['nilai_atasan'] ?? '-',
        "skor_rekan" => $h['nilai_rekan'] ?? '-', "skor_bawahan" => $h['nilai_bawahan'] ?? '-',
        "skor_akhir" => $h['nilai_akhir'] ?? '-', "predikat" => $h['predikat'] ?? 'Belum dihitung'
    ];
}

logAudit($pdo, 'reports', 'cetak_laporan', null, ['periode_id' => $periodeId, 'format' => $format]);

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_penilaian_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $periode['nama_periode']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nama', 'Golongan/Ruang', 'Jabatan', 'Unit Kerja', 'Skor Diri', 'Skor Atasan', 'Skor Rekan', 'Skor Bawahan', 'Skor Akhir', 'Predikat']);
    foreach ($laporan as $row) {
        fputcsv($out, [$row['nama'], $row['golongan'], $row['jabatan'], $row['unit_kerja'], $row['skor_diri'], $row['skor_atasan'], $row['skor_rekan'], $row['skor_bawahan'], $row['skor_akhir'], $row['predikat']]);
    }
    fclose($out);
    exit();
}

if ($format === 'pdf') {
    require_once __DIR__ . '/../lib/fpdf/fpdf.php';
    require_once __DIR__ . '/../config/kop_surat.php';

    function fpdfText($str) {
        if ($str === null || $str === '') return '';
        $str = (string)$str;

        // Normalisasi karakter UTF-8 khusus kedinasan ke karakter UTF-8 yang didukung penuh oleh FPDF / CP1252
        // Peluru (•) diubah menjadi middle dot (·) resmi, kutip ganda dan dash dinormalisasi
        $str = str_replace(
            ['•', '“', '”', '‘', '’', '–', '—'],
            ['·', '"', '"', "'", "'", '-', '-'],
            $str
        );

        // Konversi encoding UTF-8 asli ke Windows-1252 untuk FPDF tanpa menghasilkan tanda tanya (?)
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $str);
            if ($conv !== false) return $conv;
        }
        if (function_exists('mb_convert_encoding')) {
            return @mb_convert_encoding($str, 'Windows-1252', 'UTF-8');
        }
        return @utf8_decode($str);
    }

    function formatTglIndo($dateStr, $short = false) {
        if (!$dateStr) return "-";
        $time = strtotime($dateStr);
        if (!$time) return $dateStr;
        $d = date('d', $time);
        $m = (int)date('m', $time);
        $y = date('Y', $time);
        $bulanFull = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $bulanShort = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $b = $short ? ($bulanShort[$m] ?? '') : ($bulanFull[$m] ?? '');
        return "$d $b $y";
    }

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();

    if (file_exists(KOP_PATH_LOGO)) {
        $pdf->Image(KOP_PATH_LOGO, 14, 9, 22);
    }

    $pdf->SetFont('Arial', 'B', 12.5);
    $pdf->SetTextColor(17, 24, 39); // #111827
    $pdf->Cell(0, 6, fpdfText(KOP_NAMA_PEMDA), 0, 1, 'C');

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetTextColor(17, 24, 39); // #111827 (Hitam, sama dengan nama pemda & cetak HTML)
    $pdf->Cell(0, 7, fpdfText(KOP_NAMA_DINAS), 0, 1, 'C');
    $pdf->SetTextColor(55, 65, 81); // #374151

    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(0, 4.2, fpdfText(KOP_ALAMAT), 0, 1, 'C');
    $pdf->Cell(0, 4.2, fpdfText(KOP_TELEPON), 0, 1, 'C');
    $pdf->Cell(0, 4.2, fpdfText(KOP_EMAIL), 0, 1, 'C');

    // Garis ganda pembatas kop surat (tebal 0.8mm & tipis 0.25mm)
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(10, 38, 287, 38);
    $pdf->SetLineWidth(0.25);
    $pdf->Line(10, 39.3, 287, 39.3);
    $pdf->Ln(6);

    // Judul Dokumen Cetak (Format Resmi: Bold, Garis Bawah, Simbol Derajat, Huruf Kapital)
    $pdf->SetFont('Arial', 'BU', 12.5);
    $pdf->SetTextColor(17, 24, 39); // #111827
    $pdf->Cell(0, 6, fpdfText("LAPORAN HASIL PENILAIAN KINERJA 360° PEGAWAI"), 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 9.5);
    $pdf->SetTextColor(75, 85, 99); // #4B5563
    $cleanPeriode = preg_replace('/^Penilaian\s+Penilaian/i', 'Penilaian', $periode['nama_periode']);
    $cleanPeriode = preg_replace('/^Periode:\s*/i', '', $cleanPeriode);
    $tglMulai = formatTglIndo($periode['tanggal_mulai'], true);
    $tglSelesai = formatTglIndo($periode['tanggal_selesai'], true);
    $periodeTeks = "Periode: " . $cleanPeriode . " (" . $tglMulai . " s/d " . $tglSelesai . ")";
    $pdf->Cell(0, 5, fpdfText($periodeTeks), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Header Tabel (Warna lembut #F1F5F9 dengan teks #1F2937 dan garis border #D1D5DB)
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->SetDrawColor(209, 213, 219);
    $w = [8, 48, 38, 38, 20, 20, 20, 20, 22, 43];
    $headers = ['No', 'Nama Pegawai', 'Jabatan', 'Unit Kerja', 'Diri', 'Atasan', 'Rekan', 'Bawahan', 'Akhir', 'Predikat'];
    foreach ($headers as $i => $h) { 
        $pdf->Cell($w[$i], 8, fpdfText($h), 1, 0, 'C', true); 
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(17, 24, 39);
    $no = 1;
    foreach ($laporan as $row) {
        if ($pdf->GetY() > 165) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 8.5);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->SetDrawColor(209, 213, 219);
            foreach ($headers as $i => $h) { 
                $pdf->Cell($w[$i], 8, fpdfText($h), 1, 0, 'C', true); 
            }
            $pdf->Ln();
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(17, 24, 39);
        }

        $pdf->Cell($w[0], 6.5, $no++, 1, 0, 'C');
        $displayName = $row['nama'] . (!empty($row['golongan']) && $row['golongan'] !== '-' ? ' (' . $row['golongan'] . ')' : '');
        $pdf->Cell($w[1], 6.5, fpdfText(substr($displayName, 0, 32)), 1, 0, 'L');
        $pdf->Cell($w[2], 6.5, fpdfText(substr($row['jabatan'] ?? '-', 0, 22)), 1, 0, 'L');
        $pdf->Cell($w[3], 6.5, fpdfText(substr($row['unit_kerja'] ?? '-', 0, 22)), 1, 0, 'L');
        $pdf->Cell($w[4], 6.5, $row['skor_diri'], 1, 0, 'C');
        $pdf->Cell($w[5], 6.5, $row['skor_atasan'], 1, 0, 'C');
        $pdf->Cell($w[6], 6.5, $row['skor_rekan'], 1, 0, 'C');
        $pdf->Cell($w[7], 6.5, $row['skor_bawahan'], 1, 0, 'C');

        // Nilai Akhir dicetak tebal dengan warna #1B4F8C
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetTextColor(27, 79, 140);
        $skorStr = is_numeric($row['skor_akhir']) ? number_format((float)$row['skor_akhir'], 2, '.', '') : (string)$row['skor_akhir'];
        $pdf->Cell($w[8], 6.5, $skorStr, 1, 0, 'C');
        $pdf->SetFont('Arial', '', 8);

        // Predikat dengan warna latar badge sesuai cetak hasil pegawai
        $pred = trim($row['predikat'] ?? '');
        if (stripos($pred, 'Sangat Baik') !== false) {
            $pdf->SetFillColor(236, 253, 245); // #ECFDF5
            $pdf->SetTextColor(6, 95, 70);     // #065F46
        } elseif (stripos($pred, 'Baik') !== false) {
            $pdf->SetFillColor(239, 246, 255); // #EFF6FF
            $pdf->SetTextColor(30, 64, 175);   // #1E40AF
        } elseif (stripos($pred, 'Cukup') !== false) {
            $pdf->SetFillColor(255, 251, 235); // #FFFBEB
            $pdf->SetTextColor(146, 64, 14);   // #92400E
        } elseif (stripos($pred, 'Kurang') !== false) {
            $pdf->SetFillColor(254, 242, 242); // #FEF2F2
            $pdf->SetTextColor(153, 27, 27);   // #991B1B
        } elseif (stripos($pred, 'Sangat Kurang') !== false) {
            $pdf->SetFillColor(69, 10, 10);    // #450A0A
            $pdf->SetTextColor(255, 255, 255);
        } else {
            $pdf->SetFillColor(243, 244, 246);
            $pdf->SetTextColor(107, 114, 128);
        }
        $pdf->Cell($w[9], 6.5, fpdfText($pred ?: '-'), 1, 1, 'C', true);
        $pdf->SetTextColor(17, 24, 39);
    }

    $bulanIndo = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $tglCetak = date('d') . ' ' . ($bulanIndo[(int)date('m')] ?? date('F')) . ' ' . date('Y');

    $kdStmt = $pdo->query("SELECT p.nip, p.nama_lengkap, p.gelar_depan, p.gelar_belakang, j.nama_jabatan
                           FROM pegawai p JOIN jabatan j ON p.jabatan_id = j.id
                           WHERE LOWER(j.nama_jabatan) LIKE '%kepala dinas%' LIMIT 1");
    $kd = $kdStmt ? $kdStmt->fetch() : null;
    $kdNama = '( .......................................... )';
    $kdNip = 'NIP. .................................';
    if ($kd && !empty($kd['nama_lengkap'])) {
        $namaLengkap = trim($kd['nama_lengkap']);
        $depan = trim($kd['gelar_depan'] ?? '');
        $belakang = trim($kd['gelar_belakang'] ?? '');
        if ($depan && !str_starts_with(strtolower($namaLengkap), strtolower($depan))) {
            $namaLengkap = $depan . ' ' . $namaLengkap;
        }
        if ($belakang && !str_contains(strtolower($namaLengkap), strtolower($belakang))) {
            $namaLengkap = $namaLengkap . ', ' . $belakang;
        }
        $kdNama = $namaLengkap;
        $kdNip = !empty($kd['nip']) ? 'NIP. ' . $kd['nip'] : 'NIP. -';
    }

    if ($pdf->GetY() > 145) {
        $pdf->AddPage();
    } else {
        $pdf->Ln(8);
    }
    $pdf->Cell(200);
    $pdf->Cell(77, 6, fpdfText("Surabaya, " . $tglCetak), 0, 1, 'C');
    $pdf->Cell(200);
    $pdf->Cell(77, 6, fpdfText("Kepala Dinas Pekerjaan Umum Bina Marga"), 0, 1, 'C');
    $pdf->Cell(200);
    $pdf->Cell(77, 6, fpdfText("Provinsi Jawa Timur"), 0, 1, 'C');
    $pdf->Ln(25);
    $pdf->Cell(200);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(77, 6, fpdfText($kdNama), 0, 1, 'C');
    $pdf->Cell(200);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(77, 6, fpdfText($kdNip), 0, 1, 'C');

    if (ob_get_length()) {
        ob_clean();
    }
    $pdf->Output('I', 'laporan_penilaian_360.pdf');
    exit();
}

sendResponse(true, "OK", ["periode" => $periode, "laporan" => $laporan]);
