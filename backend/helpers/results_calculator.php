<?php
/**
 * Helper Perhitungan Skor Penilaian 360 Derajat.
 * Digunakan oleh API results.php dan skrip seeding periode penilaian.
 */

function getBobotConfig($pdo) {
    $stmt = $pdo->query("SELECT config_key, config_value FROM konfigurasi_sistem WHERE config_key IN
                          ('bobot_atasan','bobot_rekan','bobot_bawahan','bobot_diri_sendiri',
                           'bobot_pelaksana_atasan','bobot_pelaksana_rekan','bobot_pelaksana_diri_sendiri',
                           'ambang_predikat_sangat_baik','ambang_predikat_baik','ambang_predikat_cukup','ambang_predikat_kurang')");
    $rows = $stmt->fetchAll();
    $bobot = [];
    foreach ($rows as $r) { $bobot[$r['config_key']] = (float)$r['config_value']; }
    if (!isset($bobot['bobot_atasan'])) $bobot['bobot_atasan'] = 50.0;
    if (!isset($bobot['bobot_rekan'])) $bobot['bobot_rekan'] = 15.0;
    if (!isset($bobot['bobot_bawahan'])) $bobot['bobot_bawahan'] = 25.0;
    if (!isset($bobot['bobot_diri_sendiri'])) $bobot['bobot_diri_sendiri'] = 10.0;
    if (!isset($bobot['bobot_pelaksana_atasan'])) $bobot['bobot_pelaksana_atasan'] = 50.0;
    if (!isset($bobot['bobot_pelaksana_rekan'])) $bobot['bobot_pelaksana_rekan'] = 40.0;
    if (!isset($bobot['bobot_pelaksana_diri_sendiri'])) $bobot['bobot_pelaksana_diri_sendiri'] = $bobot['bobot_diri_sendiri'] ?? 10.0;
    return $bobot;
}

function hitungPredikat($nilai, $bobotConfig) {
    if ($nilai === null) return null;
    if ($nilai >= ($bobotConfig['ambang_predikat_sangat_baik'] ?? 91)) return 'Sangat Baik';
    if ($nilai >= ($bobotConfig['ambang_predikat_baik'] ?? 76)) return 'Baik';
    if ($nilai >= ($bobotConfig['ambang_predikat_cukup'] ?? 61)) return 'Cukup';
    if ($nilai >= ($bobotConfig['ambang_predikat_kurang'] ?? 51)) return 'Kurang';
    return 'Sangat Kurang';
}

/**
 * Menghitung dan menyimpan (upsert) hasil penilaian ke tabel hasil_penilaian
 * untuk seluruh pegawai atau daftar pegawai tertentu pada satu periode.
 */
function simpanHasilPenilaian($pdo, $periodeId, $pegawaiIds = null, $dihitungOleh = null) {
    $bobotConfig = getBobotConfig($pdo);

    if ($pegawaiIds === null) {
        $stmt = $pdo->prepare("SELECT DISTINCT pegawai_dinilai_id FROM mapping_penilai WHERE periode_id = ?");
        $stmt->execute([$periodeId]);
        $targetIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $targetIds = array_values(array_unique(array_filter(array_map('intval', (array)$pegawaiIds))));
    }

    if (empty($targetIds)) {
        return 0;
    }

    $upsertSql = dbIsPgsql($pdo)
        ? "INSERT INTO hasil_penilaian (periode_id, pegawai_id, nilai_diri, nilai_atasan, nilai_rekan, nilai_bawahan, nilai_akhir, predikat, tanggal_hitung, dihitung_oleh)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
           ON CONFLICT (periode_id, pegawai_id) DO UPDATE SET nilai_diri = EXCLUDED.nilai_diri, nilai_atasan = EXCLUDED.nilai_atasan,
                                                            nilai_rekan = EXCLUDED.nilai_rekan, nilai_bawahan = EXCLUDED.nilai_bawahan,
                                                            nilai_akhir = EXCLUDED.nilai_akhir, predikat = EXCLUDED.predikat,
                                                            tanggal_hitung = NOW(), dihitung_oleh = EXCLUDED.dihitung_oleh"
        : "INSERT INTO hasil_penilaian (periode_id, pegawai_id, nilai_diri, nilai_atasan, nilai_rekan, nilai_bawahan, nilai_akhir, predikat, tanggal_hitung, dihitung_oleh)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
           ON DUPLICATE KEY UPDATE nilai_diri=VALUES(nilai_diri), nilai_atasan=VALUES(nilai_atasan), nilai_rekan=VALUES(nilai_rekan),
                                    nilai_bawahan=VALUES(nilai_bawahan), nilai_akhir=VALUES(nilai_akhir), predikat=VALUES(predikat),
                                    tanggal_hitung=NOW(), dihitung_oleh=VALUES(dihitung_oleh)";
    $upsert = $pdo->prepare($upsertSql);

    $count = 0;
    foreach ($targetIds as $pegId) {
        $hasil = hitungSkorPegawai($pdo, $periodeId, $pegId, $bobotConfig);
        $predikat = hitungPredikat($hasil['nilai_akhir'], $bobotConfig);
        $upsert->execute([
            $periodeId,
            $pegId,
            $hasil['nilai_diri'],
            $hasil['nilai_atasan'],
            $hasil['nilai_rekan'],
            $hasil['nilai_bawahan'],
            $hasil['nilai_akhir'],
            $predikat,
            $dihitungOleh
        ]);
        $count++;
    }

    return $count;
}

/**
 * Menghitung skor akhir 360 derajat untuk 1 pegawai pada 1 periode.
 */
function hitungSkorPegawai($pdo, $periodeId, $pegawaiId, $bobotConfig) {
    $mstmt = $pdo->prepare("SELECT id, jenis_relasi FROM mapping_penilai WHERE periode_id = ? AND pegawai_dinilai_id = ? AND status = 'selesai'");
    $mstmt->execute([$periodeId, $pegawaiId]);
    $mappings = $mstmt->fetchAll();

    $relasiScores = [];
    foreach ($mappings as $m) {
        $jstmt = $pdo->prepare(
            "SELECT j.skor, q.bobot, q.skor_max FROM jawaban_penilaian j
             JOIN pertanyaan_penilaian q ON j.pertanyaan_id = q.id WHERE j.mapping_id = ?"
        );
        $jstmt->execute([$m['id']]);
        $jawaban = $jstmt->fetchAll();
        $totalBobot = 0; $totalSkorBobot = 0;
        foreach ($jawaban as $j) {
            $skorPersen = ($j['skor'] / max(1, $j['skor_max'])) * 100;
            $bobot = ($j['bobot'] > 0) ? (float)$j['bobot'] : 1.0;
            $totalSkorBobot += $skorPersen * $bobot;
            $totalBobot += $bobot;
        }
        if ($totalBobot > 0) $relasiScores[$m['jenis_relasi']][] = $totalSkorBobot / $totalBobot;
    }

    $rata = ['diri' => null, 'atasan' => null, 'rekan' => null, 'bawahan' => null];
    foreach (['diri', 'atasan', 'rekan', 'bawahan'] as $rel) {
        if (!empty($relasiScores[$rel])) {
            $rata[$rel] = round(array_sum($relasiScores[$rel]) / count($relasiScores[$rel]), 2);
        }
    }

    // Cek apakah pegawai memiliki fungsi supervisi / bawahan
    $bstmt = $pdo->prepare("SELECT COUNT(*) FROM mapping_penilai WHERE periode_id = ? AND pegawai_dinilai_id = ? AND jenis_relasi = 'bawahan'");
    $bstmt->execute([$periodeId, $pegawaiId]);
    $hasBawahanMapping = ((int)$bstmt->fetchColumn()) > 0;

    $pCheck = $pdo->prepare("SELECT COUNT(*) FROM pegawai WHERE atasan_langsung_id = ? AND status_aktif = 'aktif'");
    $pCheck->execute([$pegawaiId]);
    $hasBawahanPegawai = ((int)$pCheck->fetchColumn()) > 0;

    $hasBawahan = $hasBawahanMapping || $hasBawahanPegawai;

    $hasNilaiAtasan = ($rata['atasan'] !== null);
    $hasNilaiRekan  = ($rata['rekan'] !== null);
    $hasNilaiBawahan = ($rata['bawahan'] !== null);
    $hasNilaiDiri   = ($rata['diri'] !== null);

    $bobotDipakai = ['atasan' => 0.0, 'rekan' => 0.0, 'bawahan' => 0.0, 'diri' => 0.0];
    $bobotDiriConfig = (float)($bobotConfig['bobot_diri_sendiri'] ?? 10.0);
    $hasDiri = ($hasNilaiDiri && $bobotDiriConfig > 0);
    $diriWeight = $hasDiri ? $bobotDiriConfig : 0.0;
    $sisaBobot = max(0, 100.0 - $diriWeight);

    if ($hasBawahan) {
        if ($hasNilaiAtasan && $hasNilaiRekan && $hasNilaiBawahan) {
            $bobotDipakai['atasan'] = (float)($bobotConfig['bobot_atasan'] ?? 50.0);
            $bobotDipakai['rekan']  = (float)($bobotConfig['bobot_rekan'] ?? 15.0);
            $bobotDipakai['bawahan'] = (float)($bobotConfig['bobot_bawahan'] ?? 25.0);
        } elseif (!$hasNilaiAtasan && $hasNilaiRekan && $hasNilaiBawahan) {
            $bobotDipakai['rekan']  = round($sisaBobot * 0.4, 1);
            $bobotDipakai['bawahan'] = round($sisaBobot * 0.6, 1);
        } elseif ($hasNilaiAtasan && !$hasNilaiRekan && $hasNilaiBawahan) {
            $bobotDipakai['atasan'] = round($sisaBobot * 0.7, 1);
            $bobotDipakai['bawahan'] = round($sisaBobot * 0.3, 1);
        } elseif ($hasNilaiAtasan && $hasNilaiRekan && !$hasNilaiBawahan) {
            $bobotDipakai['atasan'] = round($sisaBobot * 0.8, 1);
            $bobotDipakai['rekan']  = round($sisaBobot * 0.2, 1);
        } elseif (!$hasNilaiAtasan && !$hasNilaiRekan && $hasNilaiBawahan) {
            $bobotDipakai['bawahan'] = $sisaBobot;
        } elseif (!$hasNilaiAtasan && $hasNilaiRekan && !$hasNilaiBawahan) {
            $bobotDipakai['rekan']  = $sisaBobot;
        } elseif ($hasNilaiAtasan && !$hasNilaiRekan && !$hasNilaiBawahan) {
            $bobotDipakai['atasan'] = $sisaBobot;
        }
    } else {
        $diriPelaksanaWeight = (float)($bobotConfig['bobot_pelaksana_diri_sendiri'] ?? $bobotConfig['bobot_diri_sendiri'] ?? 10.0);
        $hasDiriPel = ($hasNilaiDiri && $diriPelaksanaWeight > 0);
        $diriWeightPel = $hasDiriPel ? $diriPelaksanaWeight : 0.0;
        $sisaBobotPel = max(0, 100.0 - $diriWeightPel);

        if ($hasNilaiAtasan && $hasNilaiRekan) {
            $bobotDipakai['atasan'] = (float)($bobotConfig['bobot_pelaksana_atasan'] ?? 50.0);
            $bobotDipakai['rekan']  = (float)($bobotConfig['bobot_pelaksana_rekan'] ?? 40.0);
        } elseif (!$hasNilaiAtasan && $hasNilaiRekan) {
            $bobotDipakai['rekan']  = $sisaBobotPel;
        } elseif ($hasNilaiAtasan && !$hasNilaiRekan) {
            $bobotDipakai['atasan'] = $sisaBobotPel;
        }

        if ($hasDiriPel) {
            $bobotDipakai['diri'] = $diriWeightPel;
        }
    }

    if ($hasBawahan && $hasDiri) {
        $bobotDipakai['diri'] = $diriWeight;
    }

    $totalBobotDipakai = array_sum($bobotDipakai);
    $skorAkhirFinal = null;
    if ($totalBobotDipakai > 0) {
        $skorAkhir = 0;
        foreach (['atasan', 'rekan', 'bawahan', 'diri'] as $rel) {
            if ($bobotDipakai[$rel] > 0 && $rata[$rel] !== null) {
                $skorAkhir += $rata[$rel] * ($bobotDipakai[$rel] / $totalBobotDipakai);
            }
        }
        $skorAkhirFinal = round($skorAkhir, 2);
    }

    return [
        'nilai_diri' => $rata['diri'],
        'nilai_atasan' => $rata['atasan'],
        'nilai_rekan' => $rata['rekan'],
        'nilai_bawahan' => $rata['bawahan'],
        'nilai_akhir' => $skorAkhirFinal,
        'has_bawahan' => $hasBawahan,
        'is_struktural' => $hasBawahan,
        'bobot_digunakan' => $bobotDipakai
    ];
}

