<?php
/**
 * USE CASE: Import Data Pegawai dari Excel (Super Admin)
 * Endpoint:
 *  POST /api/pegawai_import.php  -> bulk insert / update pegawai dari array JSON
 *
 * Mendukung Sheet 1 (Import Format Sistem) maupun Sheet 2 (Data Lengkap Pegawai).
 * Jika NIP sudah ada, data pegawai akan diperbarui (termasuk golongan_ruang).
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, "Method tidak diizinkan.", null, 405);

$input = getJsonInput();
if (empty($input['rows']) || !is_array($input['rows'])) {
    sendResponse(false, "Data rows tidak ditemukan atau tidak valid.", null, 400);
}

$database = new Database();
$pdo = $database->getConnection();

$jabatanMap = [];
$unitMap = [];
foreach ($pdo->query("SELECT id, LOWER(nama_jabatan) AS nama, LOWER(kode_jabatan) AS kode FROM jabatan")->fetchAll() as $r) {
    if (!empty($r['nama'])) $jabatanMap[$r['nama']] = $r['id'];
    if (!empty($r['kode'])) $jabatanMap[$r['kode']] = $r['id'];
}
foreach ($pdo->query("SELECT id, LOWER(nama_unit) AS nama, LOWER(kode_unit) AS kode FROM unit_kerja")->fetchAll() as $r) {
    if (!empty($r['nama'])) $unitMap[$r['nama']] = $r['id'];
    if (!empty($r['kode'])) $unitMap[$r['kode']] = $r['id'];
}

function cleanGolongan(?string $val): ?string {
    if (!$val) return null;
    $val = trim($val);
    if (preg_match('/^(I|II|III|IV)\s*[\/\-]\s*([a-e])/i', $val, $m)) {
        return strtoupper($m[1]) . '/' . strtolower($m[2]);
    }
    return null;
}

function cleanDate(?string $val): ?string {
    if (!$val) return null;
    $val = trim($val);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (is_numeric($val) && (int)$val > 10000 && (int)$val < 60000) {
        $unix = ((int)$val - 25569) * 86400;
        return gmdate('Y-m-d', $unix);
    }
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}

function cleanPendidikan(?string $val): ?string {
    if (!$val) return null;
    $norm = strtoupper(trim($val));
    $map = [
        'SD' => 'SD', 'SLTP' => 'SLTP', 'SMP' => 'SLTP',
        'SLTA' => 'SLTA', 'SMA' => 'SLTA', 'SMK' => 'SLTA',
        'D-III' => 'D-III', 'D3' => 'D-III', 'D-IV' => 'D-IV', 'D4' => 'D-IV',
        'S-1' => 'S-1', 'S1' => 'S-1', 'S-2' => 'S-2', 'S2' => 'S-2',
        'S-3' => 'S-3', 'S3' => 'S-3'
    ];
    return $map[$norm] ?? null;
}

$checkNip = $pdo->prepare("SELECT id FROM pegawai WHERE nip = ?");
$checkUsername = $pdo->prepare("SELECT id FROM users WHERE username = ?");

$insertPegawai = $pdo->prepare(
    "INSERT INTO pegawai (
        nip, nama_lengkap, jenis_kelamin, golongan_ruang, jabatan_id, unit_kerja_id,
        status_kepegawaian, email, no_hp, tempat_lahir, tanggal_lahir,
        agama, status_pernikahan, pendidikan_terakhir, prodi, tahun_lulus,
        gelar_depan, gelar_belakang, alamat, status_aktif
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')"
);

$updatePegawai = $pdo->prepare(
    "UPDATE pegawai SET
        nama_lengkap = COALESCE(NULLIF(?, ''), nama_lengkap),
        jenis_kelamin = COALESCE(NULLIF(?, ''), jenis_kelamin),
        golongan_ruang = COALESCE(?, golongan_ruang),
        jabatan_id = COALESCE(?, jabatan_id),
        unit_kerja_id = COALESCE(?, unit_kerja_id),
        status_kepegawaian = COALESCE(NULLIF(?, ''), status_kepegawaian),
        email = COALESCE(NULLIF(?, ''), email),
        no_hp = COALESCE(NULLIF(?, ''), no_hp),
        tempat_lahir = COALESCE(NULLIF(?, ''), tempat_lahir),
        tanggal_lahir = COALESCE(?, tanggal_lahir),
        agama = COALESCE(NULLIF(?, ''), agama),
        status_pernikahan = COALESCE(NULLIF(?, ''), status_pernikahan),
        pendidikan_terakhir = COALESCE(?, pendidikan_terakhir),
        prodi = COALESCE(NULLIF(?, ''), prodi),
        tahun_lulus = COALESCE(?, tahun_lulus),
        gelar_depan = COALESCE(NULLIF(?, ''), gelar_depan),
        gelar_belakang = COALESCE(NULLIF(?, ''), gelar_belakang),
        alamat = COALESCE(NULLIF(?, ''), alamat)
    WHERE id = ?"
);

$insertUser = $pdo->prepare(
    "INSERT INTO users (pegawai_id, username, password, role, is_registered, status) VALUES (?, ?, ?, ?, ?, 'aktif')"
);

$imported = 0; $updated = 0; $skipped = 0; $errors = [];

// PASS 1: Simpan pegawai baru atau perbarui data pegawai lama
foreach ($input['rows'] as $i => $row) {
    $rowNum = $i + 2;
    $nip = preg_replace('/\s+/', '', trim($row['nip'] ?? ''));
    $nama = trim($row['nama_lengkap'] ?? $row['nama'] ?? $row['nama_&_gelar'] ?? '');

    if ($nip === '' || $nama === '') {
        $errors[] = "Baris $rowNum: NIP dan Nama Lengkap wajib diisi."; $skipped++; continue;
    }

    $rawJk = strtoupper(trim($row['jenis_kelamin'] ?? $row['l_p'] ?? 'L'));
    $jk = in_array($rawJk, ['P', 'PEREMPUAN', 'WANITA']) ? 'P' : 'L';

    $rawGol = trim($row['golongan_ruang'] ?? $row['gol_ruang'] ?? $row['golongan'] ?? $row['pangkat_golongan'] ?? $row['gol'] ?? '');
    $golongan = cleanGolongan($rawGol);

    $rawStatusKepeg = trim($row['status_kepegawaian'] ?? '');
    if (preg_match('/paruh/i', $rawStatusKepeg)) {
        $statusKepeg = 'PPPK_Paruh_Waktu';
    } elseif (preg_match('/pppk/i', $rawStatusKepeg)) {
        $statusKepeg = 'PPPK';
    } elseif (preg_match('/cpns/i', $rawStatusKepeg)) {
        $statusKepeg = 'CPNS';
    } elseif (preg_match('/honorer|ptt/i', $rawStatusKepeg)) {
        $statusKepeg = 'Honorer';
    } else {
        $statusKepeg = 'PNS';
    }

    $email = trim($row['email'] ?? $row['email_resmi'] ?? '') ?: null;
    $noHp = trim($row['no_hp'] ?? $row['no._handphone'] ?? $row['no_handphone'] ?? $row['telepon'] ?? $row['hp'] ?? '') ?: null;

    $jabatanKey = strtolower(trim($row['jabatan'] ?? $row['nama_jabatan'] ?? $row['kode_jabatan'] ?? ''));
    $jabatanId = $jabatanKey !== '' ? ($jabatanMap[$jabatanKey] ?? null) : null;

    $unitKey = strtolower(trim($row['unit_kerja'] ?? $row['nama_unit_kerja'] ?? $row['kode_unit'] ?? ''));
    $unitId = $unitKey !== '' ? ($unitMap[$unitKey] ?? null) : null;

    $tempatLahir = trim($row['tempat_lahir'] ?? '') ?: null;
    $tglLahir = cleanDate($row['tanggal_lahir'] ?? null);
    $agama = trim($row['agama'] ?? '') ?: null;
    $statusNikah = trim($row['status_pernikahan'] ?? $row['status_nikah'] ?? '') ?: null;
    $pendidikan = cleanPendidikan($row['pendidikan_terakhir'] ?? $row['pendidikan'] ?? null);
    $prodi = trim($row['prodi'] ?? $row['program_studi'] ?? '') ?: null;
    $thnLulus = (!empty($row['tahun_lulus']) && is_numeric($row['tahun_lulus'])) ? (int)$row['tahun_lulus'] : null;
    $gelarDepan = trim($row['gelar_depan'] ?? '') ?: null;
    if ($gelarDepan === '-') $gelarDepan = null;
    $gelarBelakang = trim($row['gelar_belakang'] ?? '') ?: null;
    if ($gelarBelakang === '-') $gelarBelakang = null;
    $alamat = trim($row['alamat'] ?? $row['alamat_domisili'] ?? '') ?: null;

    $checkNip->execute([$nip]);
    $existing = $checkNip->fetch(PDO::FETCH_ASSOC);

    try {
        $pdo->beginTransaction();
        if ($existing) {
            $pegawaiId = $existing['id'];
            $updatePegawai->execute([
                $nama, $jk, $golongan, $jabatanId, $unitId,
                $statusKepeg, $email, $noHp, $tempatLahir, $tglLahir,
                $agama, $statusNikah, $pendidikan, $prodi, $thnLulus,
                $gelarDepan, $gelarBelakang, $alamat,
                $pegawaiId
            ]);
            $updated++;
        } else {
            $insertPegawai->execute([
                $nip, $nama, $jk, $golongan, $jabatanId, $unitId,
                $statusKepeg, $email, $noHp, $tempatLahir, $tglLahir,
                $agama, $statusNikah, $pendidikan, $prodi, $thnLulus,
                $gelarDepan, $gelarBelakang, $alamat
            ]);
            $pegawaiId = dbLastInsertId($pdo, 'pegawai');

            $username = trim($row['username'] ?? '') ?: $nip;
            $checkUsername->execute([$username]);
            if (!$checkUsername->fetch()) {
                $password = trim($row['password'] ?? '') ?: 'password';
                $role = in_array(strtolower(trim($row['role'] ?? '')), ['admin', 'super_admin']) ? strtolower(trim($row['role'])) : 'pegawai';
                $insertUser->execute([$pegawaiId, $username, password_hash($password, PASSWORD_BCRYPT), $role, 0]);
            }
            $imported++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = "Baris $rowNum: Gagal menyimpan ($nip) - " . $e->getMessage();
        $skipped++;
    }
}

// PASS 2: Hubungkan Relasi Atasan Langsung (atasan_langsung_id)
if (dbIsPgsql($pdo)) {
    $updateAtasanByNip = $pdo->prepare(
        "UPDATE pegawai AS p
         SET atasan_langsung_id = a.id
         FROM pegawai AS a
         WHERE a.nip = ? AND p.nip = ? AND p.id != a.id"
    );
    $updateAtasanByName = $pdo->prepare(
        "UPDATE pegawai AS p
         SET atasan_langsung_id = a.id
         FROM pegawai AS a
         WHERE LOWER(TRIM(a.nama_lengkap)) = LOWER(TRIM(?)) AND p.nip = ? AND p.id != a.id"
    );
} else {
    $updateAtasanByNip = $pdo->prepare(
        "UPDATE pegawai p
         JOIN pegawai a ON a.nip = ?
         SET p.atasan_langsung_id = a.id
         WHERE p.nip = ? AND p.id != a.id"
    );
    $updateAtasanByName = $pdo->prepare(
        "UPDATE pegawai p
         JOIN pegawai a ON LOWER(TRIM(a.nama_lengkap)) = LOWER(TRIM(?))
         SET p.atasan_langsung_id = a.id
         WHERE p.nip = ? AND p.id != a.id"
    );
}

$atasanLinked = 0;
foreach ($input['rows'] as $row) {
    $nip = preg_replace('/\s+/', '', trim($row['nip'] ?? ''));
    $nipAtasan = preg_replace('/\s+/', '', trim($row['nip_atasan'] ?? $row['atasan_nip'] ?? $row['nip_atasan_langsung'] ?? ''));
    $namaAtasan = trim($row['atasan_langsung'] ?? $row['nama_atasan'] ?? $row['nama_atasan_langsung'] ?? '');

    if ($nip === '') continue;

    if ($nipAtasan !== '' && $nipAtasan !== '-' && $nipAtasan !== $nip) {
        $updateAtasanByNip->execute([$nipAtasan, $nip]);
        if ($updateAtasanByNip->rowCount() > 0) $atasanLinked++;
    } elseif ($namaAtasan !== '' && !preg_match('/gubernur/i', $namaAtasan)) {
        $updateAtasanByName->execute([$namaAtasan, $nip]);
        if ($updateAtasanByName->rowCount() > 0) $atasanLinked++;
    }
}

logAudit($pdo, 'pegawai', 'import_excel', null, [
    'imported' => $imported,
    'updated' => $updated,
    'skipped' => $skipped,
    'atasan_linked' => $atasanLinked
]);

$summaryMsg = [];
if ($imported > 0) $summaryMsg[] = "$imported pegawai baru berhasil ditambahkan";
if ($updated > 0) $summaryMsg[] = "$updated data pegawai berhasil diperbarui";
if ($skipped > 0) $summaryMsg[] = "$skipped baris dilewati";
$finalMessage = "Import selesai: " . (implode(', ', $summaryMsg) ?: "Tidak ada perubahan data") . ". $atasanLinked relasi atasan langsung berhasil disinkronkan.";

sendResponse(true, $finalMessage, [
    "imported" => $imported,
    "updated" => $updated,
    "skipped" => $skipped,
    "atasan_linked" => $atasanLinked,
    "errors" => $errors
]);
