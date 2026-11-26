<?php
/**
 * Koneksi Database menggunakan PDO (mendukung MySQL/MariaDB & PostgreSQL/Supabase)
 */
class Database {
    private $driver;
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    private $sslmode;
    public $conn;

    public function __construct() {
        $dbUrl = getenv('DATABASE_URL') ?: (getenv('SUPABASE_DB_URL') ?: '');
        if ($dbUrl !== '') {
            $parsed = parse_url($dbUrl);
            if ($parsed !== false) {
                $scheme = strtolower($parsed['scheme'] ?? '');
                $this->driver   = str_starts_with($scheme, 'postgres') ? 'pgsql' : ($scheme === 'mysql' ? 'mysql' : 'pgsql');
                $this->host     = $parsed['host'] ?? 'localhost';
                $this->port     = isset($parsed['port']) ? (string)$parsed['port'] : ($this->driver === 'pgsql' ? '5432' : '3306');
                $this->db_name  = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'postgres';
                $this->username = isset($parsed['user']) ? urldecode($parsed['user']) : 'postgres';
                $this->password = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
                $queryParams = [];
                if (!empty($parsed['query'])) {
                    parse_str($parsed['query'], $queryParams);
                }
                $this->sslmode = $queryParams['sslmode'] ?? (in_array($this->host, ['localhost', '127.0.0.1'], true) ? 'prefer' : 'require');
                return;
            }
        }

        $envHost = getenv('DB_HOST') ?: 'localhost';
        $envPort = getenv('DB_PORT') ?: '';
        $envDriver = strtolower((string)(getenv('DB_DRIVER') ?: ''));

        if ($envDriver === 'pgsql' || $envDriver === 'postgres' || $envDriver === 'postgresql') {
            $this->driver = 'pgsql';
        } elseif ($envDriver === 'mysql') {
            $this->driver = 'mysql';
        } elseif (in_array($envPort, ['5432', '6543'], true) || stripos($envHost, 'supabase') !== false) {
            $this->driver = 'pgsql';
        } else {
            $this->driver = 'mysql';
        }

        $this->host     = $envHost;
        $this->port     = $envPort !== '' ? $envPort : ($this->driver === 'pgsql' ? '5432' : '3306');
        $this->db_name  = getenv('DB_NAME') ?: ($this->driver === 'pgsql' ? 'postgres' : 'penilaian360');
        $this->username = getenv('DB_USER') ?: ($this->driver === 'pgsql' ? 'postgres' : 'root');
        $this->password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
        $this->sslmode  = getenv('DB_SSLMODE') ?: (in_array($this->host, ['localhost', '127.0.0.1'], true) ? 'prefer' : 'require');
    }

    public function getConnection() {
        $this->conn = null;
        try {
            if ($this->driver === 'pgsql') {
                $dsn = "pgsql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";sslmode=" . $this->sslmode;
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
                // Supabase Transaction Pooler (port 6543) requires emulated prepares
                if ($this->port === '6543' || getenv('DB_EMULATE_PREPARES') === '1') {
                    $options[PDO::ATTR_EMULATE_PREPARES] = true;
                }
                $this->conn = new PDO($dsn, $this->username, $this->password, $options);
                $this->conn->exec("SET TIME ZONE 'Asia/Jakarta'");
            } else {
                $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
                $this->conn = new PDO($dsn, $this->username, $this->password);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("[DB Connection Error] " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Koneksi database gagal. Periksa konfigurasi database server."]);
            exit();
        }
        return $this->conn;
    }
}

if (!function_exists('dbIsPgsql')) {
    function dbIsPgsql(PDO $pdo): bool {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}

if (!function_exists('dbLastInsertId')) {
    function dbLastInsertId(PDO $pdo, string $table): string {
        if (dbIsPgsql($pdo)) {
            try {
                $id = $pdo->lastInsertId("public.{$table}_id_seq");
                if ($id !== false && $id !== '') {
                    return (string)$id;
                }
            } catch (PDOException $e) {
                // Fallback ke lastval() bila sequence standar berbeda nama
            }
        }
        return (string)$pdo->lastInsertId();
    }
}

if (!function_exists('dbFindInSet')) {
    function dbFindInSet(PDO $pdo, string $needleExpr, string $haystackCol): string {
        if (dbIsPgsql($pdo)) {
            return "($needleExpr = ANY(string_to_array(REPLACE(COALESCE($haystackCol, ''), ' ', ''), ',')))";
        }
        return "FIND_IN_SET($needleExpr, $haystackCol) > 0";
    }
}

if (!function_exists('dbILike')) {
    function dbILike(PDO $pdo, string $colExpr): string {
        return dbIsPgsql($pdo) ? "$colExpr ILIKE ?" : "$colExpr LIKE ?";
    }
}
