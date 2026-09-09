<?php
/**
 * MySQL / MariaDB Web Viewer API with Session Authentication & Secure Cookies
 * Endpoint: /db/api.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Extended Secure Session Configuration ---
$SESSION_LIFETIME = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

$sessionSaveDir = '/var/lib/php/mylaplog_sessions';
if (!is_dir($sessionSaveDir)) {
    @mkdir($sessionSaveDir, 0770, true);
}
if (!is_dir($sessionSaveDir) || !is_writable($sessionSaveDir)) {
    $sessionSaveDir = sys_get_temp_dir() . '/mylaplog_sessions';
    if (!is_dir($sessionSaveDir)) {
        @mkdir($sessionSaveDir, 0770, true);
    }
}
if (is_dir($sessionSaveDir) && is_writable($sessionSaveDir)) {
    session_save_path($sessionSaveDir);
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
session_start([
    'cookie_lifetime' => $SESSION_LIFETIME,
    'gc_maxlifetime' => $SESSION_LIFETIME,
    'cookie_httponly' => true,
    'cookie_secure' => $isHttps,
    'cookie_samesite' => 'Lax',
    'cookie_path' => '/',
    'use_strict_mode' => true,
]);

$DB_HOST = 'localhost';
$DB_USER = 'admin';
$DB_PASS = 'StnXoa2w4DO8KE9V';

// Master access password for Web Viewer
$VIEWER_MASTER_PASS = 'StnXoa2w4DO8KE9V';

function getPDO($dbname = null) {
    global $DB_HOST, $DB_USER, $DB_PASS;
    $dsn = "mysql:host=$DB_HOST;charset=utf8mb4";
    if ($dbname) {
        $cleanDb = preg_replace('/[^a-zA-Z0-9_]/', '', $dbname);
        $dsn .= ";dbname=$cleanDb";
    }
    return new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function jsonResponse($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getBody() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    if (!empty($_POST)) return $_POST;
    return [];
}

// Helper: Verify Authentication Guard
function requireDbAuth() {
    if (empty($_SESSION['db_authenticated']) || $_SESSION['db_authenticated'] !== true) {
        jsonResponse(401, [
            'error' => '접근 권한이 없습니다. 마스터 비밀번호로 로그인해주세요.',
            'authenticated' => false
        ]);
    }
}

$action = $_GET['action'] ?? '';

try {
    // --- 0. AUTHENTICATION ROUTES ---
    
    // Check Auth Status
    if ($action === 'auth_status') {
        $isAuth = !empty($_SESSION['db_authenticated']) && $_SESSION['db_authenticated'] === true;
        jsonResponse(200, ['authenticated' => $isAuth]);
    }

    // Login Action with Rate Limiting & Brute-force Protection
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $now = time();
        $lockUntil = $_SESSION['db_login_lock_until'] ?? 0;
        
        if ($now < $lockUntil) {
            $remaining = $lockUntil - $now;
            jsonResponse(429, [
                'error' => "비밀번호 5회 연속 오류로 인해 계정이 일시 잠겼습니다. {$remaining}초 후 다시 시도하세요.",
                'authenticated' => false
            ]);
        }

        $body = getBody();
        $password = $body['password'] ?? '';

        if (!$password) {
            jsonResponse(400, ['error' => '비밀번호를 입력하세요.']);
        }

        // Compare password safely
        if (hash_equals($VIEWER_MASTER_PASS, $password)) {
            $_SESSION['db_authenticated'] = true;
            $_SESSION['db_auth_time'] = $now;
            $_SESSION['db_fail_count'] = 0;
            unset($_SESSION['db_login_lock_until']);
            jsonResponse(200, ['message' => '인증 성공', 'authenticated' => true]);
        } else {
            $fails = ($_SESSION['db_fail_count'] ?? 0) + 1;
            $_SESSION['db_fail_count'] = $fails;
            
            if ($fails >= 5) {
                $_SESSION['db_login_lock_until'] = $now + 300; // 5 minutes lock
                jsonResponse(429, [
                    'error' => '비밀번호 5회 연속 오류로 인해 5분간 로그인이 잠겼습니다.',
                    'authenticated' => false
                ]);
            }

            $left = 5 - $fails;
            jsonResponse(401, [
                'error' => "비밀번호가 올바르지 않습니다. (남은 시도 횟수: {$left}회)",
                'authenticated' => false
            ]);
        }
    }

    // Logout Action
    if ($action === 'logout') {
        $_SESSION['db_authenticated'] = false;
        unset($_SESSION['db_authenticated']);
        session_destroy();
        jsonResponse(200, ['message' => '로그아웃 완료', 'authenticated' => false]);
    }

    // --- GUARD: ALL SUBSEQUENT ACTIONS REQUIRE AUTHENTICATION ---
    requireDbAuth();

    // 1. Server Info & Status
    if ($action === 'server_info') {
        $pdo = getPDO();
        $vStmt = $pdo->query("SELECT VERSION() as version, USER() as user, DATABASE() as current_db, @@character_set_server as charset, @@collation_server as collation");
        $serverInfo = $vStmt->fetch();

        // Uptime & status variables
        $statusStmt = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Questions', 'Slow_queries', 'Innodb_buffer_pool_reads')");
        $statusVars = [];
        while ($row = $statusStmt->fetch()) {
            $statusVars[$row['Variable_name']] = $row['Value'];
        }

        jsonResponse(200, [
            'info' => $serverInfo,
            'status' => $statusVars
        ]);
    }

    // 2. List all databases
    if ($action === 'databases') {
        $pdo = getPDO();
        $stmt = $pdo->query("
            SELECT s.SCHEMA_NAME as name,
                   s.DEFAULT_CHARACTER_SET_NAME as charset,
                   s.DEFAULT_COLLATION_NAME as collation,
                   COUNT(t.TABLE_NAME) as table_count,
                   COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) as size_bytes
            FROM information_schema.SCHEMATA s
            LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
            GROUP BY s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME, s.DEFAULT_COLLATION_NAME
            ORDER BY (s.SCHEMA_NAME = 'mylaplog') DESC, (s.SCHEMA_NAME = 'lampdb') DESC, s.SCHEMA_NAME ASC
        ");
        jsonResponse(200, ['databases' => $stmt->fetchAll()]);
    }

    // 3. List tables in a database
    if ($action === 'tables') {
        $db = $_GET['db'] ?? '';
        if (!$db) jsonResponse(400, ['error' => 'Database name is required']);

        $pdo = getPDO('information_schema');
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME as name,
                   ENGINE as engine,
                   TABLE_ROWS as row_count,
                   DATA_LENGTH as data_bytes,
                   INDEX_LENGTH as index_bytes,
                   (DATA_LENGTH + INDEX_LENGTH) as total_bytes,
                   AUTO_INCREMENT as auto_increment,
                   CREATE_TIME as created_at,
                   UPDATE_TIME as updated_at,
                   TABLE_COLLATION as collation,
                   TABLE_COMMENT as comment
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME ASC
        ");
        $stmt->execute([$db]);
        jsonResponse(200, ['tables' => $stmt->fetchAll()]);
    }

    // 4. Get Table Schema (Columns, Indexes, Foreign Keys)
    if ($action === 'schema') {
        $db = $_GET['db'] ?? '';
        $table = $_GET['table'] ?? '';
        if (!$db || !$table) jsonResponse(400, ['error' => 'Database and Table name are required']);

        $pdo = getPDO('information_schema');

        // Columns
        $colStmt = $pdo->prepare("
            SELECT COLUMN_NAME as name,
                   COLUMN_TYPE as type,
                   DATA_TYPE as data_type,
                   IS_NULLABLE as is_nullable,
                   COLUMN_KEY as key_type,
                   COLUMN_DEFAULT as default_value,
                   EXTRA as extra,
                   COLLATION_NAME as collation,
                   COLUMN_COMMENT as comment
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION ASC
        ");
        $colStmt->execute([$db, $table]);
        $columns = $colStmt->fetchAll();

        // Indexes
        $idxStmt = $pdo->prepare("
            SELECT INDEX_NAME as name,
                   NON_UNIQUE as non_unique,
                   COLUMN_NAME as column_name,
                   SEQ_IN_INDEX as seq_in_index,
                   CARDINALITY as cardinality,
                   INDEX_TYPE as type,
                   COMMENT as comment
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $idxStmt->execute([$db, $table]);
        $rawIndexes = $idxStmt->fetchAll();

        // Group index columns
        $indexes = [];
        foreach ($rawIndexes as $idx) {
            $name = $idx['name'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'name' => $name,
                    'is_unique' => ($idx['non_unique'] == 0),
                    'is_primary' => ($name === 'PRIMARY'),
                    'type' => $idx['type'],
                    'columns' => [],
                    'cardinality' => $idx['cardinality']
                ];
            }
            $indexes[$name]['columns'][] = $idx['column_name'];
        }

        // Foreign Keys
        $fkStmt = $pdo->prepare("
            SELECT CONSTRAINT_NAME as constraint_name,
                   COLUMN_NAME as column_name,
                   REFERENCED_TABLE_SCHEMA as ref_db,
                   REFERENCED_TABLE_NAME as ref_table,
                   REFERENCED_COLUMN_NAME as ref_column
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $fkStmt->execute([$db, $table]);
        $foreignKeys = $fkStmt->fetchAll();

        // SHOW CREATE TABLE
        $appPdo = getPDO($db);
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $createStmt = $appPdo->query("SHOW CREATE TABLE `$cleanTable`");
        $createRow = $createStmt->fetch();
        $createTableSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? '');

        jsonResponse(200, [
            'columns' => $columns,
            'indexes' => array_values($indexes),
            'foreign_keys' => $foreignKeys,
            'create_sql' => $createTableSql
        ]);
    }

    // 5. Browse Table Data
    if ($action === 'data') {
        $db = $_GET['db'] ?? '';
        $table = $_GET['table'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(200, max(5, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $sortCol = $_GET['sort'] ?? '';
        $sortDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $search = trim($_GET['search'] ?? '');

        if (!$db || !$table) jsonResponse(400, ['error' => 'Database and Table name are required']);

        $pdo = getPDO($db);
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

        // Get column list to validate sort column and search
        $colStmt = $pdo->query("SHOW COLUMNS FROM `$cleanTable`");
        $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        $whereSql = "";
        $params = [];
        if ($search !== '' && count($columns) > 0) {
            $searchClauses = [];
            foreach ($columns as $col) {
                $searchClauses[] = "`$col` LIKE ?";
                $params[] = "%$search%";
            }
            $whereSql = " WHERE " . implode(" OR ", $searchClauses);
        }

        // Count total rows
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$cleanTable` $whereSql");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        // Order by clause
        $orderSql = "";
        if ($sortCol && in_array($sortCol, $columns)) {
            $orderSql = " ORDER BY `$sortCol` $sortDir";
        }

        // Fetch paginated rows
        $dataStmt = $pdo->prepare("SELECT * FROM `$cleanTable` $whereSql $orderSql LIMIT $limit OFFSET $offset");
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();

        jsonResponse(200, [
            'columns' => $columns,
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total_rows' => $totalRows,
                'total_pages' => ceil($totalRows / $limit)
            ]
        ]);
    }

    // 6. Execute Custom SQL Query
    if ($action === 'query' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = getBody();
        $db = $body['db'] ?? '';
        $sql = trim($body['sql'] ?? '');

        if (!$sql) jsonResponse(400, ['error' => 'SQL query cannot be empty']);

        // Read-only safety guard (allow SELECT, SHOW, DESCRIBE, EXPLAIN)
        $firstWord = strtoupper(preg_split('/\s+/', $sql)[0] ?? '');
        $allowedCommands = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

        if (!in_array($firstWord, $allowedCommands)) {
            jsonResponse(403, [
                'error' => "보안상 안전을 위해 조회용 쿼리(SELECT, SHOW, DESCRIBE, EXPLAIN)만 실행할 수 있습니다."
            ]);
        }

        // Deep Inspection: Block file write/read exploits & DoS attack functions
        $dangerousPatterns = [
            '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i',
            '/\bLOAD_FILE\s*\(/i',
            '/\bLOAD\s+DATA\b/i',
            '/\bBENCHMARK\s*\(/i',
            '/\bSLEEP\s*\(/i'
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                jsonResponse(403, [
                    'error' => "보안 정책 위반: 파일 생성/조작(INTO OUTFILE, LOAD_FILE 등) 및 위험 함수가 포함된 쿼리는 실행할 수 없습니다."
                ]);
            }
        }

        $pdo = getPDO($db ?: null);
        $startTime = microtime(true);

        $stmt = $pdo->query($sql);
        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        $rows = $stmt->fetchAll();
        $columns = [];
        if ($stmt->columnCount() > 0) {
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'] ?? "col_$i";
            }
        }

        jsonResponse(200, [
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => count($rows),
            'execution_time_ms' => $executionTimeMs
        ]);
    }

    jsonResponse(400, ['error' => 'Invalid action parameter']);

} catch (Exception $e) {
    jsonResponse(500, [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}
