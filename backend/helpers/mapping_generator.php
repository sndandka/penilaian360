<?php
/**
 * Generator Mapping Penilaian 360 Derajat yang Terdistribusi Secara Sama Rata.
 * 
 * Mengacu pada Aturan Resmi (Rules/Aturan Mapping penilaian untuk generate otomatis):
 *  1. Eselon 2 / Level 1:
 *     - Menilai bawahan: Eselon 3 dan Eselon 4 (serta staf langsung pimpinan jika ada).
 *     - Menilai diri sendiri.
 *  2. Eselon 3 / Level 2:
 *     - Menilai bawahan: Eselon 4 pada UPT/BIDANG yang sama.
 *     - Menilai bawahan: Jabatan fungsional pada UPT/BIDANG yang sama.
 *     - Menilai atasan langsung: Eselon 2.
 *     - Menilai rekan kerja sesama Eselon 3 (maksimal 10 orang).
 *     - Menilai diri sendiri.
 *  3. Eselon 4 / Level 3:
 *     - Menilai semua bawahan: Jabatan pelaksana pada sub-unit/seksinya.
 *     - Menilai atasan langsung: Eselon 3.
 *     - Menilai atasan banding: Eselon 2.
 *     - Menilai rekan kerja sesama Eselon 4 (total 10 orang): diambil dari UPT/BIDANG
 *       yang sama, sisanya dari beda UPT/BIDANG.
 *     - Menilai diri sendiri.
 *  4. Pegawai Jabatan Fungsional / Level 4 (Atasan langsung Eselon 3):
 *     - Menilai atasan langsung: Eselon 3 pada UPT/BIDANG yang sama.
 *     - Menilai atasan banding: Eselon 2.
 *     - Menilai rekan kerja: sesama seksi/sub.bidang (5 orang).
 *     - Menilai rekan kerja: beda seksi/sub.bidang di UPT yang sama (5 orang: jika terdapat 3 seksi di UPT, dibagi secara merata dari 2 seksi berbeda [3 dan 2]).
 *     - Menilai diri sendiri.
 *  5. Pegawai Jabatan Pelaksana / Level 4 (Atasan langsung Eselon 4):
 *     - Menilai atasan langsung: Eselon 4 pada UPT/BIDANG yang sama.
 *     - Menilai atasan banding: Eselon 3.
 *     - Menilai rekan kerja: sesama seksi/sub.bidang (5 orang).
 *     - Menilai rekan kerja: beda seksi/sub.bidang di UPT yang sama (5 orang: jika terdapat 3 seksi di UPT, dibagi secara merata dari 2 seksi berbeda [3 dan 2]).
 *     - Menilai diri sendiri.
 */

/**
 * Memetakan pasangan rekan kerja (directed graph) agar derajat keluar (penilai)
 * dan derajat masuk (dinilai) terdistribusi sama rata sesuai kuota.
 * Dilengkapi dengan 2-opt swap repair dan node edge-insertion untuk menjamin konvergensi sempurna.
 */
function balanceDirectedPeers(
    array $nodes,
    array &$remOut,
    array &$remIn,
    array &$mappings,
    callable $addMapping,
    callable $removeMapping,
    bool $isSameUnitForbidden = false,
    array $pegawaiMap = [],
    bool $preferSameInduk = false,
    ?callable $getIndukFn = null,
    array &$addedEdges = []
): array {
    $blockedU = [];
    while (true) {
        $u = null;
        $bestOut = 0;
        foreach ($nodes as $cand) {
            if (!empty($blockedU[$cand])) continue;
            if (($remOut[$cand] ?? 0) > $bestOut) {
                $u = $cand;
                $bestOut = $remOut[$cand];
            }
        }
        if ($u === null) break;

        $uUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$u]['unit_kerja_id'] ?? 0) : null;
        $uInduk = ($preferSameInduk && $getIndukFn) ? $getIndukFn($u) : null;

        $cands = [];
        foreach ($nodes as $v) {
            if ($v == $u) continue;
            if ($isSameUnitForbidden && (int)($pegawaiMap[$v]['unit_kerja_id'] ?? 0) === $uUnit) continue;
            if (($remIn[$v] ?? 0) <= 0) continue;
            if (isset($mappings["{$u}_{$v}"])) continue;
            $cands[] = $v;
        }
        if (empty($cands)) {
            $blockedU[$u] = true;
            continue;
        }

        usort($cands, function ($a, $b) use ($remIn, $remOut, $preferSameInduk, $getIndukFn, $uInduk) {
            $cmpIn = ($remIn[$b] ?? 0) <=> ($remIn[$a] ?? 0);
            if ($cmpIn !== 0) return $cmpIn;
            if ($preferSameInduk && $getIndukFn) {
                $sameA = ($getIndukFn($a) === $uInduk) ? 0 : 1;
                $sameB = ($getIndukFn($b) === $uInduk) ? 0 : 1;
                if ($sameA !== $sameB) return $sameA <=> $sameB;
            }
            $cmpOut = ($remOut[$b] ?? 0) <=> ($remOut[$a] ?? 0);
            return $cmpOut !== 0 ? $cmpOut : ($a <=> $b);
        });

        $k = min($remOut[$u], count($cands));
        for ($i = 0; $i < $k; $i++) {
            $v = $cands[$i];
            if ($addMapping($u, $v, 'rekan')) {
                $remOut[$u]--;
                $remIn[$v]--;
                $addedEdges[] = [$u, $v];
            }
        }
    }

    // 2-Opt Swap Repair & Node Insertion jika masih terdapat sisa remOut/remIn
    if (array_sum($remOut) > 0 && !empty($addedEdges)) {
        foreach ($nodes as $u) {
            $uUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$u]['unit_kerja_id'] ?? 0) : null;
            $guard = 0;
            while (($remOut[$u] ?? 0) > 0 && $guard < 120) {
                $guard++;
                $vTarget = null;
                foreach ($nodes as $v) {
                    if ($v != $u && ($remIn[$v] ?? 0) > 0) {
                        $vTarget = $v;
                        break;
                    }
                }

                if ($vTarget !== null) {
                    // Kasus 1: Swap repair antara u dan vTarget melalui sisi a -> b
                    $vUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$vTarget]['unit_kerja_id'] ?? 0) : null;
                    $swapped = false;
                    foreach ($addedEdges as $idx => $edge) {
                        [$a, $b] = $edge;
                        $aUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$a]['unit_kerja_id'] ?? 0) : null;
                        $bUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$b]['unit_kerja_id'] ?? 0) : null;
                        if ($a != $vTarget && $u != $b
                            && (!$isSameUnitForbidden || ($aUnit !== $vUnit && $uUnit !== $bUnit))
                            && !isset($mappings["{$a}_{$vTarget}"]) && !isset($mappings["{$u}_{$b}"])
                        ) {
                            $removeMapping($a, $b);
                            $addMapping($a, $vTarget, 'rekan');
                            $addMapping($u, $b, 'rekan');
                            $addedEdges[$idx] = [$a, $vTarget];
                            $addedEdges[] = [$u, $b];
                            $remOut[$u]--;
                            $remIn[$vTarget]--;
                            $swapped = true;
                            break;
                        }
                    }
                    if (!$swapped) break;
                } elseif (($remIn[$u] ?? 0) > 0) {
                    // Kasus 2: Node insertion ke dalam sisi yang sudah ada (a -> b menjadi a -> u -> b)
                    $swapped = false;
                    foreach ($addedEdges as $idx => $edge) {
                        [$a, $b] = $edge;
                        $aUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$a]['unit_kerja_id'] ?? 0) : null;
                        $bUnit = $isSameUnitForbidden ? (int)($pegawaiMap[$b]['unit_kerja_id'] ?? 0) : null;
                        if ($a != $u && $b != $u
                            && (!$isSameUnitForbidden || ($aUnit !== $uUnit && $uUnit !== $bUnit))
                            && !isset($mappings["{$a}_{$u}"]) && !isset($mappings["{$u}_{$b}"])
                        ) {
                            $removeMapping($a, $b);
                            $addMapping($a, $u, 'rekan');
                            $addMapping($u, $b, 'rekan');
                            $addedEdges[$idx] = [$a, $u];
                            $addedEdges[] = [$u, $b];
                            $remOut[$u]--;
                            $remIn[$u]--;
                            $swapped = true;
                            break;
                        }
                    }
                    if (!$swapped) break;
                } else {
                    break;
                }
            }
        }
    }
    return $addedEdges;
}

/**
 * Menyelaraskan total kebutuhan keluar ($remOut) dan kebutuhan masuk ($remIn).
 */
function equalizeInOutTotals(array $ids, array $remOut, array &$remIn, array $inCountByRel): void {
    $diff = array_sum($remOut) - array_sum($remIn);
    while ($diff > 0) {
        $bestId = null;
        $minTotalIn = PHP_INT_MAX;
        foreach ($ids as $id) {
            $totIn = ($inCountByRel[$id]['rekan'] ?? 0) + ($remIn[$id] ?? 0);
            if ($totIn < $minTotalIn) {
                $minTotalIn = $totIn;
                $bestId = $id;
            }
        }
        if ($bestId === null) break;
        $remIn[$bestId]++;
        $diff--;
    }
    while ($diff < 0) {
        $bestId = null;
        $maxRemIn = 0;
        foreach ($ids as $id) {
            if (($remIn[$id] ?? 0) > $maxRemIn) {
                $maxRemIn = $remIn[$id];
                $bestId = $id;
            }
        }
        if ($bestId === null) break;
        $remIn[$bestId]--;
        $diff++;
    }
}

/**
 * Memetakan rekan kerja beda seksi/sub.bidang di UPT/Bidang yang sama (5 orang).
 * Sesuai aturan resmi: Jika terdapat 3 seksi di satu UPT, maka 5 orang tersebut diambil dari
 * 2 seksi yang berbeda dan dibagi secara merata (3 orang dan 2 orang), bergantian serta
 * diputar sirkular dengan pointer simetris agar seluruh anggota di seksi lain menerima beban
 * penilaian yang sama rata (in-degree = 5, out-degree = 5).
 */
function distributeDiffSubUnitPeers(
    array $subUnits,
    callable $addMapping,
    callable $removeMapping,
    array &$mappings
): void {
    $unitIds = array_values(array_keys($subUnits));
    $kUnits = count($unitIds);
    if ($kUnits < 2) return;

    if ($kUnits === 2) {
        $uA = $unitIds[0];
        $uB = $unitIds[1];
        $mA = $subUnits[$uA];
        $mB = $subUnits[$uB];
        $nA = count($mA);
        $nB = count($mB);
        $takeA = min(5, $nB);
        for ($i = 0; $i < $nA; $i++) {
            for ($s = 0; $s < $takeA; $s++) {
                $addMapping($mA[$i], $mB[($i + $s) % $nB], 'rekan');
            }
        }
        $takeB = min(5, $nA);
        for ($j = 0; $j < $nB; $j++) {
            for ($s = 0; $s < $takeB; $s++) {
                $addMapping($mB[$j], $mA[($j + $s) % $nA], 'rekan');
            }
        }
        return;
    }

    if ($kUnits === 3) {
        // Tepat 3 seksi di UPT: Dibagi merata 3 orang dan 2 orang dari 2 seksi lainnya
        $nA = count($subUnits[$unitIds[0]]);
        $nB = count($subUnits[$unitIds[1]]);
        $nC = count($subUnits[$unitIds[2]]);

        // Jumlah edge kirim: 3 untuk indeks genap, 2 untuk indeks ganjil
        $eA_T1 = 3 * (int)ceil($nA / 2) + 2 * (int)floor($nA / 2);
        $eA_T2 = 2 * (int)ceil($nA / 2) + 3 * (int)floor($nA / 2);
        $eC_T1 = 3 * (int)ceil($nC / 2) + 2 * (int)floor($nC / 2);

        $startPtr = [
            0 => [1 => 0, 2 => 0],
            1 => [2 => $eA_T2 % $nC, 0 => $eC_T1 % $nA],
            2 => [0 => 0, 1 => $eA_T1 % $nB],
        ];

        for ($uIdx = 0; $uIdx < 3; $uIdx++) {
            $currU = $unitIds[$uIdx];
            $targetU1 = $unitIds[($uIdx + 1) % 3];
            $targetU2 = $unitIds[($uIdx + 2) % 3];

            $mCurr = $subUnits[$currU];
            $mT1 = $subUnits[$targetU1];
            $mT2 = $subUnits[$targetU2];

            $nC_curr = count($mCurr);
            $nT1 = count($mT1);
            $nT2 = count($mT2);

            $ptrT1 = $startPtr[$uIdx][($uIdx + 1) % 3] ?? 0;
            $ptrT2 = $startPtr[$uIdx][($uIdx + 2) % 3] ?? 0;

            for ($i = 0; $i < $nC_curr; $i++) {
                $mId = $mCurr[$i];
                if ($i % 2 === 0) {
                    $q1 = min(3, $nT1);
                    $q2 = min(2, $nT2);
                } else {
                    $q1 = min(2, $nT1);
                    $q2 = min(3, $nT2);
                }

                for ($s = 0; $s < $q1; $s++) {
                    $addMapping($mId, $mT1[($ptrT1 + $s) % $nT1], 'rekan');
                }
                $ptrT1 = ($ptrT1 + $q1) % $nT1;

                for ($s = 0; $s < $q2; $s++) {
                    $addMapping($mId, $mT2[($ptrT2 + $s) % $nT2], 'rekan');
                }
                $ptrT2 = ($ptrT2 + $q2) % $nT2;
            }
        }
        return;
    }

    // kUnits >= 4
    for ($uIdx = 0; $uIdx < $kUnits; $uIdx++) {
        $currU = $unitIds[$uIdx];
        $mCurr = $subUnits[$currU];
        $nC = count($mCurr);

        $otherUnits = [];
        for ($o = 1; $o < $kUnits; $o++) {
            $otherUnits[] = $unitIds[($uIdx + $o) % $kUnits];
        }
        $numOthers = count($otherUnits);
        $base = intdiv(5, $numOthers);
        $rem = 5 % $numOthers;

        $ptrs = [];
        foreach ($otherUnits as $idx => $ou) {
            $ptrs[$idx] = ($uIdx * 2 + $idx) % count($subUnits[$ou]);
        }

        for ($i = 0; $i < $nC; $i++) {
            $mId = $mCurr[$i];
            for ($o = 0; $o < $numOthers; $o++) {
                $targetU = $otherUnits[$o];
                $targetMembers = $subUnits[$targetU];
                $nT = count($targetMembers);

                $q = $base + ((($i + $o) % $numOthers < $rem) ? 1 : 0);
                $q = min($q, $nT);

                for ($s = 0; $s < $q; $s++) {
                    $addMapping($mId, $targetMembers[($ptrs[$o] + $s) % $nT], 'rekan');
                }
                $ptrs[$o] = ($ptrs[$o] + $q) % $nT;
            }
        }
    }
}

/**
 * Menentukan apakah seorang pegawai termasuk target yang dinilai dalam periode tertentu.
 * Mendukung filter 'semua' (seluruh pegawai) atau 'golongan_tertentu' (misal: Golongan III dan IV).
 */
function isPegawaiTargetDinilai(array $pegawai, string $targetPenilaian, $targetGolongan): bool {
    if ($targetPenilaian === 'semua' || empty($targetPenilaian)) {
        return true;
    }
    if ($targetPenilaian === 'golongan_tertentu') {
        if (empty($targetGolongan)) return true;
        $gols = [];
        if (is_array($targetGolongan)) {
            $gols = $targetGolongan;
        } elseif (is_string($targetGolongan)) {
            $decoded = json_decode($targetGolongan, true);
            if (is_array($decoded)) {
                $gols = $decoded;
            } else {
                $gols = array_filter(array_map('trim', explode(',', $targetGolongan)));
            }
        }
        $pGol = strtoupper(trim($pegawai['golongan_ruang'] ?? ''));
        if (empty($pGol)) return false;

        foreach ($gols as $g) {
            $gClean = strtoupper(trim($g));
            if ($gClean === 'III' || $gClean === '3') {
                if (strpos($pGol, 'III/') === 0 || $pGol === 'III') return true;
            } elseif ($gClean === 'IV' || $gClean === '4') {
                if (strpos($pGol, 'IV/') === 0 || $pGol === 'IV') return true;
            } elseif ($gClean === 'II' || $gClean === '2') {
                if (strpos($pGol, 'II/') === 0 || $pGol === 'II') return true;
            } elseif ($gClean === 'I' || $gClean === '1') {
                if (strpos($pGol, 'I/') === 0 || $pGol === 'I') return true;
            } else {
                if (strpos($pGol, $gClean) === 0) return true;
            }
        }
        return false;
    }
    return true;
}

/**
 * Generate & simpan mapping penilaian otomatis yang terdistribusi secara sama rata untuk periode tertentu.
 * Mengembalikan array ['total' => int, 'total_pemetaan' => int, 'mappings' => array].
 */
function generateBalancedMapping(PDO $pdo, $periodeId, bool $resetFirst = false, bool $dryRun = false): array {
    // Ambil konfigurasi target penilaian dari periode
    $pStmt = $pdo->prepare("SELECT id, nama_periode, target_penilaian, target_golongan FROM periode_penilaian WHERE id = ?");
    $pStmt->execute([$periodeId]);
    $periodeRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    $targetPenilaian = $periodeRow['target_penilaian'] ?? 'semua';
    $targetGolongan = $periodeRow['target_golongan'] ?? null;

    if ($resetFirst && !$dryRun) {
        // Hapus pemetaan yang belum mulai, pertahankan penilaian yang sudah selesai/berjalan
        $pdo->prepare("DELETE FROM mapping_penilai WHERE periode_id = ? AND status = 'belum_mulai'")->execute([$periodeId]);
    }

    $stmt = $pdo->query(
        "SELECT p.id, p.nama_lengkap, p.unit_kerja_id, p.atasan_langsung_id, p.golongan_ruang,
                j.id AS jabatan_id, j.nama_jabatan, j.jenis_jabatan, j.eselon, j.level_jabatan,
                uk.nama_unit, uk.parent_id AS unit_parent_id, uk.jenis_unit
         FROM pegawai p
         LEFT JOIN jabatan j ON p.jabatan_id = j.id
         LEFT JOIN unit_kerja uk ON p.unit_kerja_id = uk.id
         WHERE p.status_aktif = 'aktif'
         ORDER BY p.id ASC"
    );
    $pegawaiList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pegawaiList)) {
        return ['error' => "Tidak ada data pegawai aktif untuk digenerate."];
    }

    // Peta unit kerja untuk menentukan UPT/Bidang (Induk)
    $unitStmt = $pdo->query("SELECT id, nama_unit, parent_id, jenis_unit FROM unit_kerja");
    $unitMap = [];
    foreach ($unitStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $unitMap[$u['id']] = $u;
    }

    $getIndukId = function($uId) use ($unitMap) {
        if (empty($uId)) return 1;
        $curr = $unitMap[$uId] ?? null;
        if (!$curr) return 1;
        if (empty($curr['parent_id']) || $curr['parent_id'] == 1) {
            return (int)$curr['id'];
        }
        return (int)$curr['parent_id'];
    };

    // Identifikasi Pemimpin Struktural: Eselon 2, Eselon 3, Eselon 4
    $es2Id = null;
    $es3ByInduk = [];
    $es4ByUnit = [];
    $es4ByInduk = [];

    foreach ($pegawaiList as $p) {
        $es = trim($p['eselon'] ?? '');
        $lvl = (int)($p['level_jabatan'] ?? 0);
        $jenis = strtolower(trim($p['jenis_jabatan'] ?? ''));

        if (stripos($es, 'II.') === 0 || $es === 'II' || $lvl === 1) {
            $es2Id = (int)$p['id'];
        } elseif (stripos($es, 'III.') === 0 || $es === 'III' || ($jenis === 'struktural' && $lvl === 2)) {
            $induk = $getIndukId($p['unit_kerja_id']);
            $es3ByInduk[$induk] = (int)$p['id'];
        } elseif (stripos($es, 'IV.') === 0 || $es === 'IV' || ($jenis === 'struktural' && $lvl === 3)) {
            $uId = (int)$p['unit_kerja_id'];
            $induk = $getIndukId($uId);
            $es4ByUnit[$uId] = (int)$p['id'];
            $es4ByInduk[$induk][] = (int)$p['id'];
        }
    }

    // Klasifikasi Pegawai ke dalam 5 Aturan Resmi:
    // '1' => Eselon 2 / Level 1 (Kadis)
    // '2' => Eselon 3 / Level 2 (Kabid, Sekretaris, Ka-UPT)
    // '3' => Eselon 4 / Level 3 (Kasi, Kasubbag)
    // '4' => Pegawai Staf / Jabatan Fungsional Level 4 (Atasan langsung Eselon 3, misal Kelompok JF / Staf Pimpinan)
    // '5' => Pegawai Staf / Jabatan Pelaksana Level 4 (Atasan langsung Eselon 4 di Seksi / Sub Bagian)
    $pegawaiMap = [];
    $byGroup = ['1' => [], '2' => [], '3' => [], '4' => [], '5' => []];

    foreach ($pegawaiList as $p) {
        $es = trim($p['eselon'] ?? '');
        $lvl = (int)($p['level_jabatan'] ?? 0);
        $jenis = strtolower(trim($p['jenis_jabatan'] ?? ''));
        $uId = (int)$p['unit_kerja_id'];
        $pId = (int)$p['id'];

        if ($pId === $es2Id) {
            $grp = '1';
        } elseif (in_array($pId, array_values($es3ByInduk))) {
            $grp = '2';
        } elseif (in_array($pId, array_values($es4ByUnit))) {
            $grp = '3';
        } else {
            // Staf (Level 4):
            // Jika berada di unit kerja yang memiliki Eselon 4 (Kasi/Kasubbag), maka atasan langsungnya Eselon 4 (Aturan 5)
            if (isset($es4ByUnit[$uId])) {
                $grp = '5';
            } else {
                // Jika tidak memiliki Eselon 4 di unitnya (Kelompok JF di Bidang atau Staf Pimpinan Dinas) -> Aturan 4
                $grp = '4';
            }
        }

        $p['grp'] = $grp;
        $p['induk_id'] = $getIndukId($p['unit_kerja_id']);
        $p['is_target'] = isPegawaiTargetDinilai($p, $targetPenilaian, $targetGolongan);
        $pegawaiMap[$p['id']] = $p;
        $byGroup[$grp][] = $p['id'];
    }

    // Peta Staf (Fungsional & Pelaksana)
    $staffByUnit = [];
    $staffByInduk = [];
    $group4ByInduk = [];

    foreach (array_merge($byGroup['4'], $byGroup['5']) as $sId) {
        $uId = $pegawaiMap[$sId]['unit_kerja_id'];
        $induk = $pegawaiMap[$sId]['induk_id'];
        $staffByUnit[$uId][] = $sId;
        $staffByInduk[$induk][] = $sId;
        if ($pegawaiMap[$sId]['grp'] === '4') $group4ByInduk[$induk][] = $sId;
    }

    // Penampung mapping dan pencatat derajat
    $mappings = [];
    $outCountByRel = [];
    $inCountByRel  = [];

    foreach ($pegawaiMap as $id => $_) {
        $outCountByRel[$id] = ['diri' => 0, 'atasan' => 0, 'bawahan' => 0, 'rekan' => 0, 'total' => 0];
        $inCountByRel[$id]  = ['diri' => 0, 'atasan' => 0, 'bawahan' => 0, 'rekan' => 0, 'total' => 0];
    }

    // Muat pemetaan yang sudah ada jika tidak reset keseluruhan (misal penilaian selesai)
    $existingStmt = $pdo->prepare("SELECT pegawai_penilai_id, pegawai_dinilai_id, jenis_relasi, status FROM mapping_penilai WHERE periode_id = ?");
    $existingStmt->execute([$periodeId]);
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
        $u = (int)$ex['pegawai_penilai_id'];
        $v = (int)$ex['pegawai_dinilai_id'];
        $rel = $ex['jenis_relasi'];
        $key = "{$u}_{$v}";
        if (!isset($mappings[$key])) {
            $mappings[$key] = [
                'penilai_id' => $u,
                'dinilai_id' => $v,
                'relasi'     => $rel,
                'status'     => $ex['status'],
                'existing'   => true
            ];
            if (isset($outCountByRel[$u][$rel])) {
                $outCountByRel[$u][$rel]++;
                $outCountByRel[$u]['total']++;
            }
            if (isset($inCountByRel[$v][$rel])) {
                $inCountByRel[$v][$rel]++;
                $inCountByRel[$v]['total']++;
            }
        }
    }

    $addMapping = function ($penilaiId, $dinilaiId, $relasi) use (&$mappings, &$outCountByRel, &$inCountByRel, $pegawaiMap) {
        if (empty($penilaiId) || empty($dinilaiId)) return false;
        // VALIDASI TARGET DINILAI: Yang dinilai HARUS masuk target kriteria periode
        if (empty($pegawaiMap[$dinilaiId]['is_target'])) return false;
        $key = "{$penilaiId}_{$dinilaiId}";
        if (isset($mappings[$key])) return false;
        $mappings[$key] = [
            'penilai_id' => (int)$penilaiId,
            'dinilai_id' => (int)$dinilaiId,
            'relasi'     => $relasi,
            'existing'   => false
        ];
        if (isset($outCountByRel[$penilaiId][$relasi])) {
            $outCountByRel[$penilaiId][$relasi]++;
            $outCountByRel[$penilaiId]['total']++;
        }
        if (isset($inCountByRel[$dinilaiId][$relasi])) {
            $inCountByRel[$dinilaiId][$relasi]++;
            $inCountByRel[$dinilaiId]['total']++;
        }
        return true;
    };

    $removeMapping = function ($penilaiId, $dinilaiId) use (&$mappings, &$outCountByRel, &$inCountByRel) {
        $key = "{$penilaiId}_{$dinilaiId}";
        if (!isset($mappings[$key])) return;
        // Jangan hapus pemetaan yang sudah berstatus selesai
        if (!empty($mappings[$key]['status']) && $mappings[$key]['status'] !== 'belum_mulai') return;
        $rel = $mappings[$key]['relasi'];
        unset($mappings[$key]);
        if (isset($outCountByRel[$penilaiId][$rel]) && $outCountByRel[$penilaiId][$rel] > 0) {
            $outCountByRel[$penilaiId][$rel]--;
            $outCountByRel[$penilaiId]['total']--;
        }
        if (isset($inCountByRel[$dinilaiId][$rel]) && $inCountByRel[$dinilaiId][$rel] > 0) {
            $inCountByRel[$dinilaiId][$rel]--;
            $inCountByRel[$dinilaiId]['total']--;
        }
    };

    $es2Id = $byGroup['1'][0] ?? null;

    // -----------------------------------------------------------------
    // ATURAN 1: Eselon 2 / Level 1 (Kepala Dinas)
    // - menilai bawahan eselon 3 dan eselon 4
    // - dan menilai diri sendiri.
    // -----------------------------------------------------------------
    if ($es2Id) {
        $addMapping($es2Id, $es2Id, 'diri');
        foreach ($byGroup['2'] as $es3Id) $addMapping($es2Id, $es3Id, 'atasan');
        foreach ($byGroup['3'] as $es4Id) $addMapping($es2Id, $es4Id, 'atasan');
        foreach ($pegawaiList as $p) {
            if ($p['atasan_langsung_id'] == $es2Id && $pegawaiMap[$p['id']]['grp'] !== '2' && $pegawaiMap[$p['id']]['grp'] !== '3') {
                $addMapping($es2Id, $p['id'], 'atasan');
            }
        }
    }

    // -----------------------------------------------------------------
    // ATURAN 2: Eselon 3 / Level 2 (Kabid, Sekretaris, Ka-UPT)
    // - menilai bawahan/eselon 4 pada UPT/BIDANG yang sama
    // - menilai bawahan/jabatan fungsional pada UPT/BIDANG yang sama
    // - menilai atasan langsung/eselon 2
    // - menilai rekan kerja sesama eselon 3 (maksimal 10 orang)
    // - dan menilai diri sendiri.
    // -----------------------------------------------------------------
    $es3Ids = $byGroup['2'];
    $n3 = count($es3Ids);

    foreach ($es3Ids as $es3Id) {
        $addMapping($es3Id, $es3Id, 'diri');
        if ($es2Id) $addMapping($es3Id, $es2Id, 'bawahan');

        $myInduk = $pegawaiMap[$es3Id]['induk_id'];
        if (!empty($es4ByInduk[$myInduk])) {
            foreach ($es4ByInduk[$myInduk] as $subEs4Id) {
                $addMapping($es3Id, $subEs4Id, 'atasan');
            }
        }
        if (!empty($group4ByInduk[$myInduk])) {
            foreach ($group4ByInduk[$myInduk] as $subJfId) {
                $addMapping($es3Id, $subJfId, 'atasan');
            }
        }
    }

    // Rekan kerja sesama Eselon 3 maksimal 10 orang (sama rata dengan circulant / degree-balanced)
    $k3 = min(10, max(0, $n3 - 1));
    if ($k3 > 0) {
        $remOut3 = []; $remIn3 = [];
        foreach ($es3Ids as $es3Id) {
            $remOut3[$es3Id] = max(0, $k3 - ($outCountByRel[$es3Id]['rekan'] ?? 0));
            $remIn3[$es3Id]  = max(0, $k3 - ($inCountByRel[$es3Id]['rekan'] ?? 0));
        }
        if (array_sum($remOut3) === $n3 * $k3 && array_sum($remIn3) === $n3 * $k3) {
            foreach ($es3Ids as $idx => $es3Id) {
                for ($step = 1; $step <= $k3; $step++) {
                    $peerId = $es3Ids[($idx + $step) % $n3];
                    $addMapping($es3Id, $peerId, 'rekan');
                }
            }
        } else {
            balanceDirectedPeers($es3Ids, $remOut3, $remIn3, $mappings, $addMapping, $removeMapping);
        }
    }

    // -----------------------------------------------------------------
    // ATURAN 3: Eselon 4 / Level 3 (Kasi / Kasubbag)
    // - menilai semua bawahan jabatan pelaksana
    // - menilai atasan langsung/eselon 3
    // - menilai atasan banding/eselon 2
    // - menilai rekan kerja/sesama eselon 4 (total 10 orang) diambil dari
    //   UPT/BIDANG yang sama sisanya dari beda UPT/BIDANG
    // - dan menilai diri sendiri.
    // -----------------------------------------------------------------
    $es4Ids = $byGroup['3'];
    $n4 = count($es4Ids);

    foreach ($es4Ids as $es4Id) {
        $addMapping($es4Id, $es4Id, 'diri');
        $myInduk = $pegawaiMap[$es4Id]['induk_id'];
        $myUnit = $pegawaiMap[$es4Id]['unit_kerja_id'];

        $myEs3 = $es3ByInduk[$myInduk] ?? null;
        if ($myEs3) $addMapping($es4Id, $myEs3, 'bawahan');
        if ($es2Id) $addMapping($es4Id, $es2Id, 'bawahan');

        // Menilai SEMUA bawahan staf di unit kerjanya (Seksi / Sub Bagian)
        if (!empty($staffByUnit[$myUnit])) {
            foreach ($staffByUnit[$myUnit] as $subStafId) {
                $addMapping($es4Id, $subStafId, 'atasan');
            }
        }
    }

    // Rekan kerja sesama Eselon 4 (total 10 orang):
    // Tahap 1: Diambil dari UPT/BIDANG yang sama
    foreach ($es4ByInduk as $indukId => $sameList) {
        $mSame = count($sameList);
        if ($mSame < 2) continue;
        for ($i = 0; $i < $mSame; $i++) {
            for ($j = 0; $j < $mSame; $j++) {
                if ($i != $j) $addMapping($sameList[$i], $sameList[$j], 'rekan');
            }
        }
    }

    // Tahap 2: Sisanya dari beda UPT/BIDANG sampai total 10 rekan kerja
    $targetPeers4 = min(10, max(0, $n4 - 1));
    $remOut4 = []; $remIn4 = [];
    foreach ($es4Ids as $id) {
        $remOut4[$id] = max(0, $targetPeers4 - ($outCountByRel[$id]['rekan'] ?? 0));
        $remIn4[$id]  = max(0, $targetPeers4 - ($inCountByRel[$id]['rekan'] ?? 0));
    }
    equalizeInOutTotals($es4Ids, $remOut4, $remIn4, $inCountByRel);
    $addedEdges4 = [];
    balanceDirectedPeers($es4Ids, $remOut4, $remIn4, $mappings, $addMapping, $removeMapping, true, $pegawaiMap, false, null, $addedEdges4);

    // -----------------------------------------------------------------
    // ATURAN 4: Pegawai Jabatan Fungsional / Level 4 (Atasan langsung Eselon 3)
    // - menilai atasan langsung/eselon 3 pada UPT/BIDANG yang sama
    // - menilai atasan banding/eselon 2 (Kepala Dinas)
    // - dan menilai diri sendiri.
    // -----------------------------------------------------------------
    $group4Ids = $byGroup['4'];
    foreach ($group4Ids as $sId) {
        $addMapping($sId, $sId, 'diri');
        $myInduk = $pegawaiMap[$sId]['induk_id'];
        $myEs3 = $es3ByInduk[$myInduk] ?? null;

        if ($myEs3) {
            $addMapping($sId, $myEs3, 'bawahan');
            if ($es2Id) $addMapping($sId, $es2Id, 'bawahan');
        } elseif ($pegawaiMap[$sId]['atasan_langsung_id'] == $es2Id || $pegawaiMap[$sId]['unit_kerja_id'] == 1) {
            // Staf Langsung Pimpinan (Kadis)
            if ($es2Id) $addMapping($sId, $es2Id, 'bawahan');
        }
    }

    // -----------------------------------------------------------------
    // ATURAN 5: Pegawai Level 4 (Atasan langsung Eselon 4 di Seksi / Subbag)
    // - menilai atasan langsung/eselon 4 pada UPT/BIDANG yang sama
    // - menilai atasan banding/eselon 3
    // - TIDAK menilai Kepala Dinas (Eselon 2)
    // - dan menilai diri sendiri.
    // -----------------------------------------------------------------
    $group5Ids = $byGroup['5'];
    foreach ($group5Ids as $sId) {
        $addMapping($sId, $sId, 'diri');
        $myInduk = $pegawaiMap[$sId]['induk_id'];
        $myUnit = $pegawaiMap[$sId]['unit_kerja_id'];

        $myEs4 = $es4ByUnit[$myUnit] ?? null;
        $myEs3 = $es3ByInduk[$myInduk] ?? null;

        if ($myEs4) {
            $addMapping($sId, $myEs4, 'bawahan');
            if ($myEs3) $addMapping($sId, $myEs3, 'bawahan');
        } else {
            if (!empty($pegawaiMap[$sId]['atasan_langsung_id'])) {
                $addMapping($sId, $pegawaiMap[$sId]['atasan_langsung_id'], 'bawahan');
            }
            if ($myEs3) {
                $addMapping($sId, $myEs3, 'bawahan');
            }
        }
    }

    // -----------------------------------------------------------------
    // REKAN KERJA UNTUK STAF (Fungsional & Pelaksana)
    // -----------------------------------------------------------------
    if ($targetPenilaian === 'golongan_tertentu') {
        // REKAN KERJA STAF UNTUK PERIODE TARGET GOLONGAN TERTENTU (misal Golongan 3 & 4):
        //  - Sesuai Aturan Resmi: 5 rekan kerja sesama seksi/sub.bidang
        //  - Sesuai Aturan Resmi: 5 rekan kerja beda seksi/sub.bidang di UPT yang sama
        //    (Khusus UPT dengan 3 seksi, dibagi secara merata dari 2 seksi berbeda [3 orang dan 2 orang])
        //  - Semua pegawai (termasuk non-target dalam lingkup yang sama) turut mengevaluasi target
        //  - Distribusi penilai dijamin sama rata (setiap target staf memperoleh tepat 10 rekan kerja / 12 penilai)
        
        // 1. Rekan kerja sesama seksi/sub.bidang: 5 orang
        foreach ($staffByUnit as $uId => $allMembers) {
            $targetsInUnit = array_values(array_filter($allMembers, fn($id) => !empty($pegawaiMap[$id]['is_target'])));
            $nT = count($targetsInUnit);
            $nM = count($allMembers);
            if ($nT === 0 || $nM < 2) continue;

            $kSame = min(5, $nM - 1);
            $evalPtr = 0;
            foreach ($targetsInUnit as $tId) {
                $cands = array_values(array_filter($allMembers, fn($m) => $m != $tId));
                $nCand = count($cands);
                for ($s = 0; $s < $kSame; $s++) {
                    $evalId = $cands[($evalPtr + $s) % $nCand];
                    $addMapping($evalId, $tId, 'rekan');
                }
                $evalPtr = ($evalPtr + $kSame) % $nCand;
            }
        }

        // 2. Rekan kerja beda seksi di UPT/Induk yang sama: 5 orang
        // Mengikuti aturan resmi: Jika terdapat 3 seksi di UPT, dibagi secara merata dari 2 seksi berbeda (3 dan 2)
        foreach ($staffByInduk as $indukId => $allIndukMembers) {
            $bySubUnit = [];
            $targetsBySubUnit = [];
            foreach ($allIndukMembers as $mId) {
                $uId = $pegawaiMap[$mId]['unit_kerja_id'];
                $bySubUnit[$uId][] = $mId;
                if (!empty($pegawaiMap[$mId]['is_target'])) {
                    $targetsBySubUnit[$uId][] = $mId;
                }
            }
            $subUnitIds = array_values(array_keys($bySubUnit));
            $kUnits = count($subUnitIds);
            if ($kUnits < 2) continue;

            if ($kUnits === 2) {
                $uA = $subUnitIds[0];
                $uB = $subUnitIds[1];
                $targetsA = $targetsBySubUnit[$uA] ?? [];
                $targetsB = $targetsBySubUnit[$uB] ?? [];
                $membersA = $bySubUnit[$uA];
                $membersB = $bySubUnit[$uB];

                $nA = count($membersA);
                $nB = count($membersB);

                $ptrB = 0;
                foreach ($targetsA as $tA) {
                    $take = min(5, $nB);
                    for ($s = 0; $s < $take; $s++) {
                        $evalId = $membersB[($ptrB + $s) % $nB];
                        $addMapping($evalId, $tA, 'rekan');
                    }
                    $ptrB = ($ptrB + $take) % $nB;
                }

                $ptrA = 0;
                foreach ($targetsB as $tB) {
                    $take = min(5, $nA);
                    for ($s = 0; $s < $take; $s++) {
                        $evalId = $membersA[($ptrA + $s) % $nA];
                        $addMapping($evalId, $tB, 'rekan');
                    }
                    $ptrA = ($ptrA + $take) % $nA;
                }
            } elseif ($kUnits === 3) {
                // Tepat 3 seksi di UPT: Dibagi 3 orang dan 2 orang dari 2 seksi berbeda secara simetris
                for ($uIdx = 0; $uIdx < 3; $uIdx++) {
                    $currU = $subUnitIds[$uIdx];
                    $tU1 = $subUnitIds[($uIdx + 1) % 3];
                    $tU2 = $subUnitIds[($uIdx + 2) % 3];

                    $targetsCurr = $targetsBySubUnit[$currU] ?? [];
                    if (empty($targetsCurr)) continue;

                    $membersT1 = $bySubUnit[$tU1];
                    $membersT2 = $bySubUnit[$tU2];
                    $nT1 = count($membersT1);
                    $nT2 = count($membersT2);

                    $ptrT1 = 0;
                    $ptrT2 = 0;

                    foreach ($targetsCurr as $idx => $tId) {
                        if ($idx % 2 === 0) {
                            $q1 = min(3, $nT1);
                            $q2 = min(2, $nT2);
                        } else {
                            $q1 = min(2, $nT1);
                            $q2 = min(3, $nT2);
                        }

                        for ($s = 0; $s < $q1; $s++) {
                            $evalId = $membersT1[($ptrT1 + $s) % $nT1];
                            $addMapping($evalId, $tId, 'rekan');
                        }
                        $ptrT1 = ($ptrT1 + $q1) % $nT1;

                        for ($s = 0; $s < $q2; $s++) {
                            $evalId = $membersT2[($ptrT2 + $s) % $nT2];
                            $addMapping($evalId, $tId, 'rekan');
                        }
                        $ptrT2 = ($ptrT2 + $q2) % $nT2;
                    }
                }
            } else {
                // >= 4 seksi
                for ($uIdx = 0; $uIdx < $kUnits; $uIdx++) {
                    $currU = $subUnitIds[$uIdx];
                    $targetsCurr = $targetsBySubUnit[$currU] ?? [];
                    if (empty($targetsCurr)) continue;

                    $otherUnits = [];
                    for ($o = 1; $o < $kUnits; $o++) {
                        $otherUnits[] = $subUnitIds[($uIdx + $o) % $kUnits];
                    }
                    $numOthers = count($otherUnits);
                    $base = intdiv(5, $numOthers);
                    $rem = 5 % $numOthers;

                    $ptrs = array_fill(0, $numOthers, 0);

                    foreach ($targetsCurr as $tIdx => $tId) {
                        for ($o = 0; $o < $numOthers; $o++) {
                            $tUnit = $otherUnits[$o];
                            $tMembers = $bySubUnit[$tUnit];
                            $nT = count($tMembers);
                            $q = min($nT, $base + ((($tIdx + $o) % $numOthers < $rem) ? 1 : 0));
                            for ($s = 0; $s < $q; $s++) {
                                $evalId = $tMembers[($ptrs[$o] + $s) % $nT];
                                $addMapping($evalId, $tId, 'rekan');
                            }
                            $ptrs[$o] = ($ptrs[$o] + $q) % $nT;
                        }
                    }
                }
            }
        }

        // 3. Pasangkan Unit 1 (Dinas: 2 target) dengan Unit 19 (Sekretariat: 9 staf, 3 target)
        $unit1Staff = $staffByUnit[1] ?? [];
        $unit19Staff = $staffByUnit[19] ?? [];

        foreach ($unit1Staff as $u1) {
            if (!empty($pegawaiMap[$u1]['is_target'])) {
                $needed = max(0, 10 - ($inCountByRel[$u1]['rekan'] ?? 0));
                $take = min($needed, count($unit19Staff));
                for ($s = 0; $s < $take; $s++) {
                    $addMapping($unit19Staff[$s], $u1, 'rekan');
                }
            }
        }

        foreach ($unit19Staff as $u19) {
            if (!empty($pegawaiMap[$u19]['is_target'])) {
                foreach ($unit1Staff as $u1) {
                    $addMapping($u1, $u19, 'rekan');
                }
            }
        }

        // 4. PEMERATAAN & PENYEMPURNAAN REKAN KERJA (Jaminan Setiap Target Staf Mendapatkan Tepat 10 Rekan)
        $allStaffIds = array_merge($byGroup['4'], $byGroup['5']);
        $targetStaffIds = array_values(array_filter($allStaffIds, fn($id) => !empty($pegawaiMap[$id]['is_target'])));

        foreach ($targetStaffIds as $tId) {
            $needed = 10 - ($inCountByRel[$tId]['rekan'] ?? 0);
            if ($needed <= 0) continue;

            $myUnit = $pegawaiMap[$tId]['unit_kerja_id'];
            $myInduk = $pegawaiMap[$tId]['induk_id'];

            // Urutkan staf kandidat penilai berdasarkan out-degree terkecil agar beban menilai seimbang
            $cands = $allStaffIds;
            usort($cands, function($a, $b) use ($outCountByRel, $pegawaiMap, $myInduk) {
                $outA = $outCountByRel[$a]['rekan'] ?? 0;
                $outB = $outCountByRel[$b]['rekan'] ?? 0;
                if ($outA !== $outB) return $outA <=> $outB;
                $indukA = ($pegawaiMap[$a]['induk_id'] === $myInduk) ? 0 : 1;
                $indukB = ($pegawaiMap[$b]['induk_id'] === $myInduk) ? 0 : 1;
                return $indukA <=> $indukB;
            });

            foreach ($cands as $candId) {
                if ($candId == $tId) continue;
                if (isset($mappings["{$candId}_{$tId}"])) continue;
                if ($addMapping($candId, $tId, 'rekan')) {
                    $needed--;
                    if ($needed <= 0) break;
                }
            }
        }
    } else {
        // REKAN KERJA UNTUK STAF (Fungsional & Pelaksana) - Periode Reguler / Semua Pegawai Dinilai
        //  - 5 rekan sesama seksi/sub.bidang
        //  - 5 rekan beda seksi/sub.bidang di UPT/Bidang yang sama
        //    (Khusus UPT dengan 3 seksi, dibagi merata dari 2 seksi berbeda: 3 dan 2)
        $allStaffIds = array_merge($byGroup['4'], $byGroup['5']);
        $addedStaffEdges = [];

        // 1. Rekan kerja sesama seksi: 5 orang
        foreach ($staffByUnit as $uId => $members) {
            $m = count($members);
            if ($m < 2) continue;
            $targetSame = min(5, $m - 1);
            for ($i = 0; $i < $m; $i++) {
                for ($step = 1; $step <= $targetSame; $step++) {
                    $peerId = $members[($i + $step) % $m];
                    $addMapping($members[$i], $peerId, 'rekan');
                }
            }
        }

        // 2. Rekan kerja beda seksi/sub.bidang di UPT/Bidang yang sama: 5 orang
        // (Jika terdapat 3 seksi di satu UPT, 5 orang dibagi merata dari 2 seksi berbeda: 3 dan 2)
        foreach ($staffByInduk as $indukId => $members) {
            $bySubUnit = [];
            foreach ($members as $mId) {
                $bySubUnit[$pegawaiMap[$mId]['unit_kerja_id']][] = $mId;
            }
            if (count($bySubUnit) < 2) continue;

            distributeDiffSubUnitPeers(
                $bySubUnit,
                function($u, $v, $rel) use ($addMapping, &$addedStaffEdges) {
                    if ($addMapping($u, $v, $rel)) {
                        $addedStaffEdges[] = [$u, $v];
                        return true;
                    }
                    return false;
                },
                $removeMapping,
                $mappings
            );
        }

        // 3. Pasangkan Unit 1 (Dinas: ID 2, 3) secara simetris dengan Unit 19 (Sekretariat: 9 staf)
        $unit1Staff = $staffByUnit[1] ?? [];
        $unit19Staff = $staffByUnit[19] ?? [];
        foreach ($unit1Staff as $u1) {
            foreach ($unit19Staff as $u19) {
                $addMapping($u1, $u19, 'rekan');
                $addMapping($u19, $u1, 'rekan');
                $addedStaffEdges[] = [$u1, $u19];
                $addedStaffEdges[] = [$u19, $u1];
            }
        }

        // 4. Pemerataan global sisa kebutuhan (untuk unit tanpa seksi saudara / sisa swap)
        $remOutStaffAll = []; $remInStaffAll = [];
        foreach ($allStaffIds as $sId) {
            $remOutStaffAll[$sId] = max(0, 10 - ($outCountByRel[$sId]['rekan'] ?? 0));
            $remInStaffAll[$sId]  = max(0, 10 - ($inCountByRel[$sId]['rekan'] ?? 0));
        }

        equalizeInOutTotals($allStaffIds, $remOutStaffAll, $remInStaffAll, $inCountByRel);
        balanceDirectedPeers($allStaffIds, $remOutStaffAll, $remInStaffAll, $mappings, $addMapping, $removeMapping, true, $pegawaiMap, false, null, $addedStaffEdges);

        // 5. Transfer langsung 1-pass jika masih ada selisih in-degree minor di Kantor Pusat
        $lowInNodes = [];
        $highInNodes = [];
        foreach ($allStaffIds as $id) {
            if ($inCountByRel[$id]['rekan'] < 10) $lowInNodes[] = $id;
            if ($inCountByRel[$id]['rekan'] > 10) $highInNodes[] = $id;
        }

        foreach ($lowInNodes as $lowId) {
            $lowUnit = $pegawaiMap[$lowId]['unit_kerja_id'];
            while ($inCountByRel[$lowId]['rekan'] < 10) {
                $swapped = false;
                foreach ($highInNodes as $highIdx => $highId) {
                    if ($inCountByRel[$highId]['rekan'] <= 10) continue;
                    foreach ($mappings as $key => $m) {
                        if ($m['relasi'] === 'rekan' && $m['dinilai_id'] == $highId) {
                            $pId = $m['penilai_id'];
                            $pUnit = $pegawaiMap[$pId]['unit_kerja_id'];
                            if ($pId != $lowId && $pUnit != $lowUnit && !isset($mappings["{$pId}_{$lowId}"])) {
                                $removeMapping($pId, $highId);
                                $addMapping($pId, $lowId, 'rekan');
                                $swapped = true;
                                break 2;
                            }
                        }
                    }
                }
                if (!$swapped) break;
            }
        }
    }

    if ($dryRun) {
        return [
            'total' => count(array_filter($mappings, fn($m) => empty($m['existing']))),
            'total_pemetaan' => count($mappings),
            'target_penilaian' => $targetPenilaian,
            'target_golongan' => $targetGolongan,
            'mappings' => $mappings,
            'byGroup' => $byGroup,
            'pegawaiMap' => $pegawaiMap,
            'leftover' => [
                'es4_out' => isset($remOut4) ? array_sum($remOut4) : 0,
                'es4_in' => isset($remIn4) ? array_sum($remIn4) : 0,
                'staff_out' => isset($remOutStaffAll) ? array_sum($remOutStaffAll) : 0,
                'staff_in' => isset($remInStaffAll) ? array_sum($remInStaffAll) : 0,
            ]
        ];
    }

    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }
    try {
        $insertSql = dbIsPgsql($pdo)
            ? "INSERT INTO mapping_penilai (periode_id, pegawai_penilai_id, pegawai_dinilai_id, jenis_relasi)
               VALUES (?, ?, ?, ?)
               ON CONFLICT (periode_id, pegawai_dinilai_id, pegawai_penilai_id, jenis_relasi) DO NOTHING"
            : "INSERT IGNORE INTO mapping_penilai (periode_id, pegawai_penilai_id, pegawai_dinilai_id, jenis_relasi)
               VALUES (?, ?, ?, ?)";
        $insertStmt = $pdo->prepare($insertSql);
        $count = 0;
        foreach ($mappings as $m) {
            if (!empty($m['existing'])) continue;
            $insertStmt->execute([$periodeId, $m['penilai_id'], $m['dinilai_id'], $m['relasi']]);
            $count += $insertStmt->rowCount();
        }
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['error' => "Gagal menggenerate mapping: " . $e->getMessage()];
    }

    return [
        'total' => $count,
        'total_pemetaan' => count($mappings),
        'target_penilaian' => $targetPenilaian,
        'target_golongan' => $targetGolongan
    ];
}