<?php
/**
 * USE CASE: Mapping Penilai (Super Admin) - siapa menilai siapa dalam suatu periode
 * Endpoints:
 *  GET    /api/mapping.php?periode_id=1        -> list mapping dalam periode
 *  POST   /api/mapping.php                     -> tambah mapping manual
 *  POST   /api/mapping.php?action=auto          -> generate mapping otomatis sesuai aturan 360 (Eselon 2, 3, 4, Staff)
 *  DELETE /api/mapping.php?id=1                -> hapus 1 mapping
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_check.php';
require_once __DIR__ . '/../helpers/mapping_generator.php';

requireAdmin();

$database = new Database();
$pdo = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    $periodeId = $_GET['periode_id'] ?? null;
    if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

    $mode = $_GET['mode'] ?? '';

    if ($mode === 'grouped') {
        // 1. Overall Summary for this periode
        $summaryStmt = $pdo->prepare(
            "SELECT 
                COUNT(*) AS total_mappings,
                COUNT(DISTINCT pegawai_dinilai_id) AS total_pegawai_dinilai,
                SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS total_selesai,
                SUM(CASE WHEN status = 'proses' THEN 1 ELSE 0 END) AS total_proses,
                SUM(CASE WHEN status = 'belum_mulai' THEN 1 ELSE 0 END) AS total_belum
             FROM mapping_penilai
             WHERE periode_id = ?"
        );
        $summaryStmt->execute([$periodeId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Filter conditions
        $where = ["m.periode_id = ?"];
        $params = [$periodeId];

        if (!empty($_GET['search'])) {
            $s = '%' . trim($_GET['search']) . '%';
            $searchCols = ['p2.nama_lengkap', 'p2.nip', 'j2.nama_jabatan', 'p2.golongan_ruang'];
            $where[] = "(" . implode(' OR ', array_map(fn($c) => dbILike($pdo, $c), $searchCols)) . ")";
            $params = array_merge($params, array_fill(0, count($searchCols), $s));
        }

        if (!empty($_GET['unit_kerja_id'])) {
            $where[] = "(p2.unit_kerja_id = ? OR p2.unit_kerja_id IN (SELECT id FROM unit_kerja WHERE parent_id = ?))";
            $params[] = (int)$_GET['unit_kerja_id'];
            $params[] = (int)$_GET['unit_kerja_id'];
        }

        if (!empty($_GET['golongan_ruang']) || !empty($_GET['golongan'])) {
            $golFilter = trim($_GET['golongan_ruang'] ?? $_GET['golongan']);
            if (in_array($golFilter, ['I', 'II', 'III', 'IV'])) {
                $where[] = "p2.golongan_ruang LIKE ?";
                $params[] = $golFilter . '/%';
            } else {
                $where[] = "p2.golongan_ruang = ?";
                $params[] = $golFilter;
            }
        }

        if (!empty($_GET['status'])) {
            $where[] = "m.status = ?";
            $params[] = $_GET['status'];
        }

        if (!empty($_GET['relasi'])) {
            $where[] = "m.jenis_relasi = ?";
            $params[] = $_GET['relasi'];
        }

        $whereClause = implode(" AND ", $where);

        // 3. Count distinct pegawai dinilai matching filters
        $countSql = "SELECT COUNT(DISTINCT m.pegawai_dinilai_id) 
                     FROM mapping_penilai m
                     JOIN pegawai p2 ON m.pegawai_dinilai_id = p2.id
                     LEFT JOIN jabatan j2 ON p2.jabatan_id = j2.id
                     WHERE $whereClause";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalPegawai = (int)$countStmt->fetchColumn();

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
        $offset = ($page - 1) * $limit;
        $totalPages = $totalPegawai > 0 ? (int)ceil($totalPegawai / $limit) : 0;

        // 4. Fetch paginated distinct IDs
        $pageSql = "SELECT m.pegawai_dinilai_id, p2.nama_lengkap
                    FROM mapping_penilai m
                    JOIN pegawai p2 ON m.pegawai_dinilai_id = p2.id
                    LEFT JOIN jabatan j2 ON p2.jabatan_id = j2.id
                    WHERE $whereClause
                    GROUP BY m.pegawai_dinilai_id, p2.nama_lengkap
                    ORDER BY p2.nama_lengkap ASC
                    LIMIT $limit OFFSET $offset";
        $pageStmt = $pdo->prepare($pageSql);
        $pageStmt->execute($params);
        $dinilaiIds = $pageStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        if (empty($dinilaiIds)) {
            sendResponse(true, "OK", [], 200, [
                'total' => $totalPegawai,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'summary' => $summary
            ]);
        }

        // 5. Fetch full evaluator details for these pegawai
        $inClause = implode(',', array_fill(0, count($dinilaiIds), '?'));
        $detailSql = "SELECT m.*, 
                             p1.nama_lengkap AS nama_penilai, p1.nip AS nip_penilai, p1.golongan_ruang AS golongan_penilai,
                             j1.nama_jabatan AS jabatan_penilai, uk1.nama_unit AS unit_penilai,
                             p2.nama_lengkap AS nama_dinilai, p2.nip AS nip_dinilai, p2.golongan_ruang AS golongan_dinilai,
                             j2.nama_jabatan AS jabatan_dinilai, uk2.nama_unit AS unit_dinilai
                      FROM mapping_penilai m
                      JOIN pegawai p1 ON m.pegawai_penilai_id = p1.id
                      LEFT JOIN jabatan j1 ON p1.jabatan_id = j1.id
                      LEFT JOIN unit_kerja uk1 ON p1.unit_kerja_id = uk1.id
                      JOIN pegawai p2 ON m.pegawai_dinilai_id = p2.id
                      LEFT JOIN jabatan j2 ON p2.jabatan_id = j2.id
                      LEFT JOIN unit_kerja uk2 ON p2.unit_kerja_id = uk2.id
                      WHERE m.periode_id = ? AND m.pegawai_dinilai_id IN ($inClause)
                      ORDER BY p2.nama_lengkap ASC, 
                               CASE m.jenis_relasi 
                                   WHEN 'diri' THEN 1 
                                   WHEN 'atasan' THEN 2 
                                   WHEN 'rekan' THEN 3 
                                   WHEN 'bawahan' THEN 4 
                                   ELSE 5 
                               END, 
                               p1.nama_lengkap ASC";
        $detailStmt = $pdo->prepare($detailSql);
        $detailStmt->execute(array_merge([$periodeId], $dinilaiIds));
        $allRows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($dinilaiIds as $did) {
            $grouped[$did] = null;
        }

        foreach ($allRows as $r) {
            $did = $r['pegawai_dinilai_id'];
            if ($grouped[$did] === null) {
                $grouped[$did] = [
                    'pegawai_dinilai_id' => (int)$did,
                    'nama_dinilai' => $r['nama_dinilai'],
                    'nip_dinilai' => $r['nip_dinilai'],
                    'golongan_dinilai' => $r['golongan_dinilai'] ?? '-',
                    'jabatan_dinilai' => $r['jabatan_dinilai'] ?? '-',
                    'unit_dinilai' => $r['unit_dinilai'] ?? '-',
                    'penilai' => [],
                    'stats' => [
                        'total' => 0,
                        'atasan' => 0,
                        'bawahan' => 0,
                        'rekan' => 0,
                        'diri' => 0,
                        'selesai' => 0,
                        'proses' => 0,
                        'belum_mulai' => 0
                    ]
                ];
            }
            $grouped[$did]['penilai'][] = [
                'id' => (int)$r['id'],
                'pegawai_penilai_id' => (int)$r['pegawai_penilai_id'],
                'nama_penilai' => $r['nama_penilai'],
                'nip_penilai' => $r['nip_penilai'],
                'golongan_penilai' => $r['golongan_penilai'] ?? '-',
                'jabatan_penilai' => $r['jabatan_penilai'] ?? '-',
                'unit_penilai' => $r['unit_penilai'] ?? '-',
                'jenis_relasi' => $r['jenis_relasi'],
                'status' => $r['status']
            ];
            $grouped[$did]['stats']['total']++;
            if (isset($grouped[$did]['stats'][$r['jenis_relasi']])) {
                $grouped[$did]['stats'][$r['jenis_relasi']]++;
            }
            if (isset($grouped[$did]['stats'][$r['status']])) {
                $grouped[$did]['stats'][$r['status']]++;
            }
        }

        sendResponse(true, "OK", array_values(array_filter($grouped)), 200, [
            'total' => $totalPegawai,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
            'summary' => $summary
        ]);
    } else {
        // Fallback: flat list
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        $total = 0;

        if ($limit) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mapping_penilai m WHERE m.periode_id = ?");
            $countStmt->execute([$periodeId]);
            $total = $countStmt->fetchColumn();
        }

        $limitClause = $limit ? " LIMIT $limit OFFSET " . (($page - 1) * $limit) : "";

        $stmt = $pdo->prepare(
            "SELECT m.*, p1.nama_lengkap AS nama_penilai, p1.golongan_ruang AS golongan_penilai, p2.nama_lengkap AS nama_dinilai, p2.golongan_ruang AS golongan_dinilai
             FROM mapping_penilai m
             JOIN pegawai p1 ON m.pegawai_penilai_id = p1.id
             JOIN pegawai p2 ON m.pegawai_dinilai_id = p2.id
             WHERE m.periode_id = ?
             ORDER BY p2.nama_lengkap ASC, m.jenis_relasi ASC" . $limitClause
        );
        $stmt->execute([$periodeId]);

        $meta = $limit ? ['total' => (int)$total, 'page' => $page, 'limit' => $limit, 'totalPages' => ceil($total / $limit)] : null;
        sendResponse(true, "OK", $stmt->fetchAll(), 200, $meta);
    }
}

if ($method === 'POST' && $action === 'auto') {
    $input = getJsonInput();
    $periodeId = $input['periode_id'] ?? null;
    if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

    $periodeCheck = $pdo->prepare("SELECT id, status FROM periode_penilaian WHERE id = ?");
    $periodeCheck->execute([$periodeId]);
    $periode = $periodeCheck->fetch();
    if (!$periode) {
        sendResponse(false, "Periode penilaian tidak ditemukan.", null, 404);
    }

    $resetFirst = !empty($input['reset_first']);
    $res = generateBalancedMapping($pdo, $periodeId, $resetFirst);
    if (!empty($res['error'])) {
        sendResponse(false, $res['error'], null, 400);
    }

    $count = $res['total'];
    $totalPemetaan = $res['total_pemetaan'];
    $targetPenilaian = $res['target_penilaian'] ?? 'semua';
    $targetGolongan = $res['target_golongan'] ?? '';
    $targetInfo = ($targetPenilaian === 'golongan_tertentu') 
        ? " [Target Khusus: Golongan " . str_replace(',', ' & ', $targetGolongan) . " Saja yang Dinilai]" 
        : "";

    logAudit($pdo, 'mapping_penilai', 'generate_otomatis', null, [
        'periode_id' => $periodeId,
        'target_penilaian' => $targetPenilaian,
        'target_golongan' => $targetGolongan,
        'total_baru' => $count,
        'total_pemetaan' => $totalPemetaan
    ]);
    sendResponse(true, "Mapping penilaian berhasil digenerate otomatis secara merata{$targetInfo} ({$count} hubungan baru ditambahkan dari {$totalPemetaan} total pemetaan).", [
        "total" => $count,
        "total_pemetaan" => $totalPemetaan,
        "target_penilaian" => $targetPenilaian,
        "target_golongan" => $targetGolongan
    ]);
}

if ($method === 'POST') {
    $input = getJsonInput();
    requireFields($input, ['periode_id', 'pegawai_penilai_id', 'pegawai_dinilai_id', 'jenis_relasi']);
    if (!in_array($input['jenis_relasi'], ['diri','atasan','rekan','bawahan'])) {
        sendResponse(false, "Jenis relasi tidak valid.", null, 400);
    }
    $stmt = $pdo->prepare("INSERT INTO mapping_penilai (periode_id, pegawai_penilai_id, pegawai_dinilai_id, jenis_relasi) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([$input['periode_id'], $input['pegawai_penilai_id'], $input['pegawai_dinilai_id'], $input['jenis_relasi']]);
    } catch (PDOException $e) {
        sendResponse(false, "Mapping sudah ada atau data tidak valid.", null, 409);
    }
    $newId = dbLastInsertId($pdo, 'mapping_penilai');
    logAudit($pdo, 'mapping_penilai', 'create', null, ['id' => $newId]);
    sendResponse(true, "Mapping berhasil ditambahkan.", ["id" => $newId], 201);
}

if ($method === 'DELETE') {
    if ($action === 'reset') {
        $periodeId = $_GET['periode_id'] ?? null;
        if (!$periodeId) sendResponse(false, "periode_id wajib disertakan.", null, 400);

        $stmt = $pdo->prepare("DELETE FROM mapping_penilai WHERE periode_id = ?");
        $stmt->execute([$periodeId]);
        $deletedCount = $stmt->rowCount();
        
        logAudit($pdo, 'mapping_penilai', 'reset_keseluruhan', ['periode_id' => $periodeId], ['deleted' => $deletedCount]);
        sendResponse(true, "Semua mapping untuk periode ini berhasil direset.", ["deleted" => $deletedCount]);
    } else {
        if (!isset($_GET['id'])) sendResponse(false, "ID mapping wajib disertakan.", null, 400);
        $pdo->prepare("DELETE FROM mapping_penilai WHERE id = ?")->execute([$_GET['id']]);
        logAudit($pdo, 'mapping_penilai', 'delete', ['id' => $_GET['id']], null);
        sendResponse(true, "Mapping berhasil dihapus.");
    }
}

sendResponse(false, "Method/aksi tidak dikenali.", null, 405);
