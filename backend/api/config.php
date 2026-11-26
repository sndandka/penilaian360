<?php
/**
 * USE CASE: Konfigurasi Sistem (Super Admin)
 * Endpoints:
 *  GET /api/config.php        -> ambil semua konfigurasi (public, dipakai render header dsb)
 *  PUT /api/config.php        -> update banyak konfigurasi sekaligus {key: value, ...}
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT config_key, config_value, tipe_data, kategori, keterangan FROM konfigurasi_sistem");
    $rows = $stmt->fetchAll();
    $config = [];
    foreach ($rows as $r) { $config[$r['config_key']] = $r['config_value']; }
    sendResponse(true, "OK", $config);
}

if ($method === 'PUT') {
    // Bobot penilaian termasuk hal sensitif -> hanya Super Admin
    requireSuperAdmin();
    $input = getJsonInput();
    if (empty($input)) sendResponse(false, "Tidak ada data konfigurasi yang dikirim.", null, 400);

    // 1. VALIDASI: Bobot Pejabat Struktural (Atasan + Rekan + Bawahan + Diri Sendiri = 100%)
    $bobotKeys = ['bobot_atasan', 'bobot_rekan', 'bobot_bawahan', 'bobot_diri_sendiri'];
    $touchesBobot = count(array_intersect($bobotKeys, array_keys($input))) > 0;
    if ($touchesBobot) {
        $current = $pdo->query("SELECT config_key, config_value FROM konfigurasi_sistem WHERE config_key IN ('" . implode("','", $bobotKeys) . "')")->fetchAll();
        $bobotSekarang = [];
        foreach ($current as $r) { $bobotSekarang[$r['config_key']] = (float)$r['config_value']; }
        foreach ($bobotKeys as $k) { if (isset($input[$k])) $bobotSekarang[$k] = (float)$input[$k]; }

        foreach ($bobotKeys as $k) {
            if (!is_numeric($bobotSekarang[$k] ?? null) || $bobotSekarang[$k] < 0 || $bobotSekarang[$k] > 100) {
                sendResponse(false, "Nilai bobot '$k' harus berupa angka 0-100.", null, 400);
            }
        }
        $total = array_sum($bobotSekarang);
        if (abs($total - 100) > 0.01) {
            sendResponse(false, "Total bobot penilaian Pejabat Struktural (Atasan+Rekan+Bawahan+Diri Sendiri) harus = 100%. Saat ini total = {$total}%.", null, 422);
        }
    }

    // 2. VALIDASI: Bobot Pegawai Pelaksana / Fungsional (Atasan + Rekan + Diri Sendiri = 100%)
    $bobotPelaksanaKeys = ['bobot_pelaksana_atasan', 'bobot_pelaksana_rekan', 'bobot_pelaksana_diri_sendiri'];
    $touchesPelaksana = count(array_intersect($bobotPelaksanaKeys, array_keys($input))) > 0;
    if ($touchesPelaksana) {
        $currentP = $pdo->query("SELECT config_key, config_value FROM konfigurasi_sistem WHERE config_key IN ('" . implode("','", $bobotPelaksanaKeys) . "')")->fetchAll();
        $bobotPelaksanaSekarang = [];
        foreach ($currentP as $r) { $bobotPelaksanaSekarang[$r['config_key']] = (float)$r['config_value']; }
        foreach ($bobotPelaksanaKeys as $k) { if (isset($input[$k])) $bobotPelaksanaSekarang[$k] = (float)$input[$k]; }

        foreach ($bobotPelaksanaKeys as $k) {
            if (!is_numeric($bobotPelaksanaSekarang[$k] ?? null) || $bobotPelaksanaSekarang[$k] < 0 || $bobotPelaksanaSekarang[$k] > 100) {
                sendResponse(false, "Nilai bobot pelaksana '$k' harus berupa angka 0-100.", null, 400);
            }
        }
        $totalP = array_sum($bobotPelaksanaSekarang);
        if (abs($totalP - 100) > 0.01) {
            sendResponse(false, "Total bobot penilaian Pegawai Pelaksana (Atasan+Rekan+Diri Sendiri) harus = 100%. Saat ini total = {$totalP}%.", null, 422);
        }
    }

    $upsertSql = dbIsPgsql($pdo)
        ? "INSERT INTO konfigurasi_sistem (config_key, config_value) VALUES (?, ?) ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value"
        : "INSERT INTO konfigurasi_sistem (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)";
    $stmt = $pdo->prepare($upsertSql);
    foreach ($input as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    logAudit($pdo, 'konfigurasi_sistem', 'update', null, $input);
    sendResponse(true, "Konfigurasi sistem berhasil diperbarui.");
}

sendResponse(false, "Method tidak diizinkan.", null, 405);
