<?php
/**
 * USE CASE: Merancang Sistem Penilaian 360 (Super Admin & Admin)
 * Struktur Sederhana: Kriteria Penilaian dan Instrumen Penilaian (Paket Kuesioner)
 * Endpoints:
 *  GET    /api/criteria.php?type=kategori|kriteria        -> daftar kriteria penilaian (6 aspek BKN)
 *  GET    /api/criteria.php?type=indikator&kategori_id=1  -> (kompatibilitas) list indikator
 *  GET    /api/criteria.php?paket=atasan|rekan            -> daftar instrumen pertanyaan per paket
 *  GET    /api/criteria.php                               -> daftar seluruh instrumen pertanyaan (+ join kriteria)
 *  POST   /api/criteria.php?type=kategori|kriteria|pertanyaan
 *  PUT    /api/criteria.php?type=...&id=1
 *  DELETE /api/criteria.php?type=...&id=1
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$type = $_GET['type'] ?? 'pertanyaan';
if ($type === 'kriteria') $type = 'kategori';
$validTypes = ['kategori', 'indikator', 'pertanyaan'];
if (!in_array($type, $validTypes)) $type = 'pertanyaan';

if ($method === 'GET') {
    requireLogin();
    if ($type === 'kategori') {
        $where = ''; $params = [];
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        $total = 0;
        if ($limit) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM kategori_penilaian k $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();
        }
        $limitClause = $limit ? " LIMIT $limit OFFSET " . (($page - 1) * $limit) : "";

        $hasBawahanExpr = dbFindInSet($pdo, "'bawahan'", "q.target_relasi");
        $hasRekanExpr   = "(" . dbFindInSet($pdo, "'rekan'", "q.target_relasi")
                        . " OR " . dbFindInSet($pdo, "'atasan'", "q.target_relasi")
                        . " OR " . dbFindInSet($pdo, "'diri'", "q.target_relasi") . ")";

        $stmt = $pdo->prepare("SELECT k.*, 
                                      (SELECT COUNT(*) FROM indikator_penilaian i WHERE i.kategori_id = k.id) AS jumlah_indikator,
                                      (SELECT i.id FROM indikator_penilaian i WHERE i.kategori_id = k.id ORDER BY i.id ASC LIMIT 1) AS default_indikator_id,
                                      (SELECT COUNT(*) FROM pertanyaan_penilaian q JOIN indikator_penilaian i ON q.indikator_id = i.id WHERE i.kategori_id = k.id) AS jumlah_pertanyaan,
                                      (SELECT COUNT(*) FROM pertanyaan_penilaian q JOIN indikator_penilaian i ON q.indikator_id = i.id WHERE i.kategori_id = k.id AND $hasBawahanExpr) AS jumlah_q_atasan,
                                      (SELECT COUNT(*) FROM pertanyaan_penilaian q JOIN indikator_penilaian i ON q.indikator_id = i.id WHERE i.kategori_id = k.id AND $hasRekanExpr) AS jumlah_q_rekan
                              FROM kategori_penilaian k $where ORDER BY k.urutan ASC, k.id ASC" . $limitClause);
        $stmt->execute($params);
        $meta = $limit ? ['total' => (int)$total, 'page' => $page, 'limit' => $limit, 'totalPages' => ceil($total / $limit)] : null;
        sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
    } elseif ($type === 'indikator') {
        $where = ''; $params = [];
        if (!empty($_GET['kategori_id'])) { $where = 'WHERE i.kategori_id = ?'; $params[] = $_GET['kategori_id']; }
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        $total = 0;
        if ($limit) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM indikator_penilaian i $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();
        }
        $limitClause = $limit ? " LIMIT $limit OFFSET " . (($page - 1) * $limit) : "";

        $stmt = $pdo->prepare("SELECT i.*, k.nama_kategori,
                                      (SELECT COUNT(*) FROM pertanyaan_penilaian q WHERE q.indikator_id = i.id) AS jumlah_pertanyaan
                              FROM indikator_penilaian i JOIN kategori_penilaian k ON i.kategori_id = k.id
                              $where ORDER BY k.urutan ASC, i.urutan ASC" . $limitClause);
        $stmt->execute($params);
        $meta = $limit ? ['total' => (int)$total, 'page' => $page, 'limit' => $limit, 'totalPages' => ceil($total / $limit)] : null;
        sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
    } else {
        $where = []; $params = [];
        if (!empty($_GET['paket'])) {
            $pkt = strtolower(trim($_GET['paket']));
            if ($pkt === 'atasan') {
                $where[] = dbFindInSet($pdo, "'bawahan'", "q.target_relasi");
            } elseif ($pkt === 'rekan' || $pkt === 'rekan_bawahan_diri') {
                $where[] = "(" . dbFindInSet($pdo, "'rekan'", "q.target_relasi")
                         . " OR " . dbFindInSet($pdo, "'atasan'", "q.target_relasi")
                         . " OR " . dbFindInSet($pdo, "'diri'", "q.target_relasi") . ")";
            }
        }
        if (!empty($_GET['kategori_id'])) {
            $where[] = "k.id = ?";
            $params[] = (int)$_GET['kategori_id'];
        }

        $whereClause = !empty($where) ? " WHERE " . implode(' AND ', $where) : "";

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        $total = 0;
        if ($limit) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pertanyaan_penilaian q JOIN indikator_penilaian i ON q.indikator_id = i.id JOIN kategori_penilaian k ON i.kategori_id = k.id $whereClause");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();
        }
        $limitClause = $limit ? " LIMIT $limit OFFSET " . (($page - 1) * $limit) : "";

        $stmt = $pdo->prepare("SELECT q.*, i.nama_indikator, k.nama_kategori, k.kode_kategori, k.id AS kategori_id
                              FROM pertanyaan_penilaian q
                              JOIN indikator_penilaian i ON q.indikator_id = i.id
                              JOIN kategori_penilaian k ON i.kategori_id = k.id
                              $whereClause
                              ORDER BY k.urutan ASC, i.urutan ASC, q.urutan ASC, q.id ASC" . $limitClause);
        $stmt->execute($params);
        $meta = $limit ? ['total' => (int)$total, 'page' => $page, 'limit' => $limit, 'totalPages' => ceil($total / $limit)] : null;
        sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
    }
    exit();
}

requireAdmin();

function autoUpdatePertanyaanBobot($pdo, $indikator_id = null) {
    // Hitung bobot proporsional per paket kuesioner (masing-masing paket = 100%)
    $paket1Expr = dbFindInSet($pdo, "'bawahan'", "target_relasi");
    $paket2Expr = "(" . dbFindInSet($pdo, "'rekan'", "target_relasi")
                . " OR " . dbFindInSet($pdo, "'atasan'", "target_relasi")
                . " OR " . dbFindInSet($pdo, "'diri'", "target_relasi") . ")";

    $totalPaket1 = (int)$pdo->query("SELECT COUNT(*) FROM pertanyaan_penilaian WHERE status = 'aktif' AND $paket1Expr")->fetchColumn();
    $totalPaket2 = (int)$pdo->query("SELECT COUNT(*) FROM pertanyaan_penilaian WHERE status = 'aktif' AND $paket2Expr")->fetchColumn();

    if ($totalPaket1 > 0) {
        $bobot1 = round(100 / $totalPaket1, 2);
        $pdo->prepare("UPDATE pertanyaan_penilaian SET bobot = ? WHERE status = 'aktif' AND $paket1Expr")->execute([$bobot1]);
    }
    if ($totalPaket2 > 0) {
        $bobot2 = round(100 / $totalPaket2, 2);
        $pdo->prepare("UPDATE pertanyaan_penilaian SET bobot = ? WHERE status = 'aktif' AND $paket2Expr")->execute([$bobot2]);
    }
}

if ($method === 'POST') {
    $input = getJsonInput();
    if ($type === 'kategori') {
        requireFields($input, ['kode_kategori', 'nama_kategori']);
        $bobotKat = is_numeric($input['bobot_kategori'] ?? null) ? (float)$input['bobot_kategori'] : 0;
        $urutanKat = is_numeric($input['urutan'] ?? null) ? (int)$input['urutan'] : 0;
        $stmt = $pdo->prepare("INSERT INTO kategori_penilaian (kode_kategori, nama_kategori, deskripsi, bobot_kategori, urutan) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([cleanStr($input['kode_kategori']), cleanStr($input['nama_kategori']), cleanStr($input['deskripsi'] ?? null), $bobotKat, $urutanKat]);
        $newId = dbLastInsertId($pdo, 'kategori_penilaian');

        // Otomatis sinkronkan indikator_penilaian (1:1 per Kriteria)
        $indStmt = $pdo->prepare("INSERT INTO indikator_penilaian (kategori_id, kode_indikator, nama_indikator, deskripsi, bobot_indikator, urutan, status) VALUES (?, ?, ?, ?, 100.00, ?, 'aktif')");
        $indStmt->execute([$newId, 'IND-' . cleanStr($input['kode_kategori']), cleanStr($input['nama_kategori']), cleanStr($input['deskripsi'] ?? null), $urutanKat]);

        logAudit($pdo, 'kategori_penilaian', 'create', null, ['id' => $newId]);
        sendResponse(true, "Kriteria penilaian berhasil ditambahkan.", ["id" => $newId], 201);
    } elseif ($type === 'indikator') {
        requireFields($input, ['kategori_id', 'kode_indikator', 'nama_indikator']);
        $bobotInd = is_numeric($input['bobot_indikator'] ?? null) ? (float)$input['bobot_indikator'] : 0;
        $urutanInd = is_numeric($input['urutan'] ?? null) ? (int)$input['urutan'] : 0;
        $stmt = $pdo->prepare("INSERT INTO indikator_penilaian (kategori_id, kode_indikator, nama_indikator, deskripsi, bobot_indikator, urutan) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([(int)$input['kategori_id'], cleanStr($input['kode_indikator']), cleanStr($input['nama_indikator']), cleanStr($input['deskripsi'] ?? null), $bobotInd, $urutanInd]);
        $newId = dbLastInsertId($pdo, 'indikator_penilaian');
        logAudit($pdo, 'indikator_penilaian', 'create', null, ['id' => $newId]);
        sendResponse(true, "Indikator berhasil ditambahkan.", ["id" => $newId], 201);
    } else {
        // Resolve indikator_id jika yang dikirim adalah kriteria_id / kategori_id
        $indikatorId = $input['indikator_id'] ?? null;
        if (empty($indikatorId) && (!empty($input['kriteria_id']) || !empty($input['kategori_id']))) {
            $katId = (int)($input['kriteria_id'] ?? $input['kategori_id']);
            $istmt = $pdo->prepare("SELECT id FROM indikator_penilaian WHERE kategori_id = ? ORDER BY id ASC LIMIT 1");
            $istmt->execute([$katId]);
            $indikatorId = $istmt->fetchColumn();
            if (!$indikatorId) {
                $kstmt = $pdo->prepare("SELECT * FROM kategori_penilaian WHERE id = ?");
                $kstmt->execute([$katId]);
                $kat = $kstmt->fetch();
                if ($kat) {
                    $cstmt = $pdo->prepare("INSERT INTO indikator_penilaian (kategori_id, kode_indikator, nama_indikator, deskripsi, bobot_indikator, urutan, status) VALUES (?, ?, ?, ?, 100.00, ?, 'aktif')");
                    $cstmt->execute([$katId, 'IND-' . $kat['kode_kategori'], $kat['nama_kategori'], $kat['deskripsi'], $kat['urutan']]);
                    $indikatorId = dbLastInsertId($pdo, 'indikator_penilaian');
                }
            }
        }

        if (empty($indikatorId)) {
            sendResponse(false, "Kriteria penilaian wajib dipilih.", null, 400);
        }
        if (empty($input['pertanyaan'])) {
            sendResponse(false, "Pernyataan / pertanyaan penilaian wajib diisi.", null, 400);
        }

        validateNumberRange($input['skor_min'] ?? 1, 1, 10, 'Skor minimal');
        validateNumberRange($input['skor_max'] ?? 5, 1, 10, 'Skor maksimal');

        $targetRelasi = $input['target_relasi'] ?? null;
        if (empty($targetRelasi) && !empty($input['paket'])) {
            $pkt = strtolower(trim($input['paket']));
            if ($pkt === 'atasan') {
                $targetRelasi = 'bawahan';
            } elseif ($pkt === 'rekan_bawahan_diri' || $pkt === 'rekan') {
                $targetRelasi = 'atasan,rekan,diri';
            } else {
                $targetRelasi = 'atasan,rekan,bawahan,diri';
            }
        }

        if (is_array($targetRelasi)) {
            $targetRelasi = implode(',', array_intersect($targetRelasi, ['atasan','rekan','bawahan','diri']));
        } else {
            $parts = explode(',', (string)$targetRelasi);
            $targetRelasi = implode(',', array_intersect(array_map('trim', $parts), ['atasan','rekan','bawahan','diri']));
        }
        if (empty($targetRelasi)) $targetRelasi = 'atasan,rekan,bawahan,diri';

        $stmt = $pdo->prepare("INSERT INTO pertanyaan_penilaian (indikator_id, pertanyaan, jenis_pertanyaan, target_relasi, bobot, skor_min, skor_max, urutan, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int)$indikatorId, cleanStr($input['pertanyaan']),
            in_array($input['jenis_pertanyaan'] ?? '', ['skala','esai']) ? $input['jenis_pertanyaan'] : 'skala',
            $targetRelasi,
            is_numeric($input['bobot'] ?? null) ? (float)$input['bobot'] : 1.00,
            is_numeric($input['skor_min'] ?? null) ? (int)$input['skor_min'] : 1,
            is_numeric($input['skor_max'] ?? null) ? (int)$input['skor_max'] : 5,
            is_numeric($input['urutan'] ?? null) ? (int)$input['urutan'] : 0,
            in_array($input['status'] ?? '', ['aktif','nonaktif']) ? $input['status'] : 'aktif'
        ]);
        $newId = dbLastInsertId($pdo, 'pertanyaan_penilaian');
        autoUpdatePertanyaanBobot($pdo, $indikatorId);
        logAudit($pdo, 'pertanyaan_penilaian', 'create', null, ['id' => $newId, 'pertanyaan' => $input['pertanyaan'], 'target_relasi' => $targetRelasi]);
        sendResponse(true, "Instrumen penilaian berhasil ditambahkan.", ["id" => $newId], 201);
    }
}

if ($method === 'PUT') {
    if (!isset($_GET['id'])) sendResponse(false, "ID wajib disertakan.", null, 400);
    $id = $_GET['id'];
    $input = getJsonInput();

    if ($type === 'pertanyaan') {
        if (!empty($input['paket']) && empty($input['target_relasi'])) {
            $pkt = strtolower(trim($input['paket']));
            if ($pkt === 'atasan') {
                $input['target_relasi'] = 'bawahan';
            } elseif ($pkt === 'rekan' || $pkt === 'rekan_bawahan_diri') {
                $input['target_relasi'] = 'atasan,rekan,diri';
            } else {
                $input['target_relasi'] = 'atasan,rekan,bawahan,diri';
            }
        }
        if (isset($input['target_relasi'])) {
            if (is_array($input['target_relasi'])) {
                $input['target_relasi'] = implode(',', array_intersect($input['target_relasi'], ['atasan','rekan','bawahan','diri']));
            } else {
                $parts = explode(',', (string)$input['target_relasi']);
                $input['target_relasi'] = implode(',', array_intersect(array_map('trim', $parts), ['atasan','rekan','bawahan','diri']));
            }
            if (empty($input['target_relasi'])) $input['target_relasi'] = 'atasan,rekan,bawahan,diri';
        }

        // Jika user mengubah kriteria_id
        if ((!empty($input['kriteria_id']) || !empty($input['kategori_id'])) && empty($input['indikator_id'])) {
            $katId = (int)($input['kriteria_id'] ?? $input['kategori_id']);
            $istmt = $pdo->prepare("SELECT id FROM indikator_penilaian WHERE kategori_id = ? ORDER BY id ASC LIMIT 1");
            $istmt->execute([$katId]);
            $resolvedIndId = $istmt->fetchColumn();
            if ($resolvedIndId) {
                $input['indikator_id'] = $resolvedIndId;
            }
        }
    }

    $tableMap = ['kategori' => 'kategori_penilaian', 'indikator' => 'indikator_penilaian', 'pertanyaan' => 'pertanyaan_penilaian'];
    $fieldsMap = [
        'kategori' => ['kode_kategori','nama_kategori','deskripsi','bobot_kategori','urutan','status'],
        'indikator' => ['kategori_id','kode_indikator','nama_indikator','deskripsi','bobot_indikator','urutan','status'],
        'pertanyaan' => ['indikator_id','pertanyaan','jenis_pertanyaan','target_relasi','bobot','skor_min','skor_max','urutan','status'],
    ];
    $table = $tableMap[$type];
    $numericIntFields = ['urutan', 'kategori_id', 'indikator_id', 'skor_min', 'skor_max'];
    $numericFloatFields = ['bobot_kategori', 'bobot_indikator', 'bobot'];
    $fields = []; $params = [];
    foreach ($fieldsMap[$type] as $f) {
        if (array_key_exists($f, $input)) {
            $val = $input[$f];
            if (in_array($f, $numericIntFields, true)) {
                $val = is_numeric($val) ? (int)$val : 0;
            } elseif (in_array($f, $numericFloatFields, true)) {
                $val = is_numeric($val) ? (float)$val : 0.0;
            } elseif ($val === '') {
                $val = ($f === 'deskripsi') ? null : '';
            }
            $fields[] = "$f = ?";
            $params[] = $val;
        }
    }
    if (empty($fields)) sendResponse(false, "Tidak ada perubahan.", null, 400);
    $params[] = $id;
    $pdo->prepare("UPDATE $table SET " . implode(',', $fields) . " WHERE id = ?")->execute($params);

    // Sinkronkan nama indikator jika kategori diupdate
    if ($type === 'kategori') {
        $updInd = []; $indP = [];
        if (isset($input['nama_kategori'])) { $updInd[] = "nama_indikator = ?"; $indP[] = cleanStr($input['nama_kategori']); }
        if (isset($input['kode_kategori'])) { $updInd[] = "kode_indikator = ?"; $indP[] = 'IND-' . cleanStr($input['kode_kategori']); }
        if (isset($input['deskripsi'])) { $updInd[] = "deskripsi = ?"; $indP[] = cleanStr($input['deskripsi']); }
        if (!empty($updInd)) {
            $indP[] = $id;
            $pdo->prepare("UPDATE indikator_penilaian SET " . implode(',', $updInd) . " WHERE kategori_id = ?")->execute($indP);
        }
    }

    logAudit($pdo, $table, 'update', null, array_merge(['id' => $id], $input));
    $label = ($type === 'kategori') ? 'Kriteria' : (($type === 'pertanyaan') ? 'Instrumen' : ucfirst($type));
    sendResponse(true, "$label berhasil diperbarui.");
}

if ($method === 'DELETE') {
    if (!isset($_GET['id'])) sendResponse(false, "ID wajib disertakan.", null, 400);
    $tableMap = ['kategori' => 'kategori_penilaian', 'indikator' => 'indikator_penilaian', 'pertanyaan' => 'pertanyaan_penilaian'];
    $table = $tableMap[$type];
    
    $indikator_id = null;
    if ($type === 'pertanyaan') {
        $stmt = $pdo->prepare("SELECT indikator_id FROM pertanyaan_penilaian WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $indikator_id = $stmt->fetchColumn();
    } elseif ($type === 'kategori') {
        // Hapus pertanyaan dan indikator turunan
        $istmt = $pdo->prepare("SELECT id FROM indikator_penilaian WHERE kategori_id = ?");
        $istmt->execute([$_GET['id']]);
        $indIds = $istmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($indIds)) {
            $inStr = implode(',', array_map('intval', $indIds));
            $pdo->exec("DELETE FROM pertanyaan_penilaian WHERE indikator_id IN ($inStr)");
            $pdo->exec("DELETE FROM indikator_penilaian WHERE kategori_id = " . (int)$_GET['id']);
        }
    }
    
    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$_GET['id']]);
    logAudit($pdo, $table, 'delete', ['id' => $_GET['id']], null);
    
    if ($type === 'pertanyaan' && $indikator_id) {
        autoUpdatePertanyaanBobot($pdo, $indikator_id);
    }
    
    $label = ($type === 'kategori') ? 'Kriteria' : (($type === 'pertanyaan') ? 'Instrumen' : ucfirst($type));
    sendResponse(true, "$label berhasil dihapus.");
}

sendResponse(false, "Method tidak diizinkan.", null, 405);
