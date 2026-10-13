<?php
/**
 * MyLapLog v1 REST API
 * Tailored backend for core trackday intelligence:
 *   - Log Session & Session Logs
 *   - GPS Lap Timer
 *   - GPS Pit Lane Timer
 *   - Guides & Insights
 * Database: v1 (MariaDB 10.11)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Database Configuration (Dedicated v1 Database) ---
$DB_HOST = 'localhost';
$DB_NAME = 'v1';
$DB_USER = 'admin';
$DB_PASS = 'StnXoa2w4DO8KE9V';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Session Configuration (30 Days Persistent Login) ---
$SESSION_LIFETIME = 60 * 60 * 24 * 30; // 30 days
ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

$sessionSaveDir = '/var/lib/php/mylaplog_v1_sessions';
if (!is_dir($sessionSaveDir)) {
    @mkdir($sessionSaveDir, 0770, true);
}
if (!is_dir($sessionSaveDir) || !is_writable($sessionSaveDir)) {
    $sessionSaveDir = sys_get_temp_dir() . '/mylaplog_v1_sessions';
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

if (!empty($_SESSION['user_id']) && isset($_COOKIE[session_name()])) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $SESSION_LIFETIME,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// --- Utility Helpers ---
function jsonResponse($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getBody() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(401, ['error' => '로그인이 필요합니다.']);
    }
    return (int)$_SESSION['user_id'];
}

function getCurrentUserId() {
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

// --- Request Router ---
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Normalize URI: strip /v1/api, /api, /api.php etc.
$uri = preg_replace('#^.*?(?:/v1)?/api(?:\.php)?#', '', $uri);
if ($uri === '' || $uri === false) {
    $uri = '/';
}

// =========================================================================
// 1. AUTHENTICATION ENDPOINTS
// =========================================================================

// POST /auth/login
if ($method === 'POST' && $uri === '/auth/login') {
    $body = getBody();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        jsonResponse(400, ['error' => '이메일과 비밀번호를 모두 입력해 주세요.']);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(401, ['error' => '이메일 또는 비밀번호가 일치하지 않습니다.']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    unset($user['password_hash']);
    jsonResponse(200, ['message' => '로그인 성공', 'user' => $user]);
}

// POST /auth/register
if ($method === 'POST' && $uri === '/auth/register') {
    $body = getBody();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $name = trim($body['name'] ?? '');

    if (!$email || !$password || !$name) {
        jsonResponse(400, ['error' => '이름, 이메일, 비밀번호를 모두 입력해 주세요.']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, ['error' => '올바른 이메일 형식이 아닙니다.']);
    }

    if (strlen($password) < 6) {
        jsonResponse(400, ['error' => '비밀번호는 최소 6자 이상이어야 합니다.']);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(409, ['error' => '이미 등록된 이메일 주소입니다.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $avatar = mb_strtoupper(mb_substr($name, 0, 2));

    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, avatar, driver_class, kara_license) VALUES (?, ?, ?, ?, "CLUBMAN", "Circuit License")');
    $stmt->execute([$email, $hash, $name, $avatar]);
    $userId = (int)$pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    jsonResponse(201, ['message' => '회원가입 완료', 'user' => $user]);
}

// GET /auth/me
if ($method === 'GET' && $uri === '/auth/me') {
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonResponse(200, ['user' => null, 'is_guest' => true]);
    }

    $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        jsonResponse(200, ['user' => null, 'is_guest' => true]);
    }

    jsonResponse(200, ['user' => $user, 'is_guest' => false]);
}

// POST /auth/logout
if ($method === 'POST' && $uri === '/auth/logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    jsonResponse(200, ['message' => '로그아웃 되었습니다.']);
}

// POST /auth/kakao
if ($method === 'POST' && $uri === '/auth/kakao') {
    $body = getBody();
    $kakaoId = (string)($body['kakao_id'] ?? '');
    $email = trim($body['email'] ?? '');
    $nickname = trim($body['nickname'] ?? '카카오 드라이버');

    if (!$kakaoId) {
        jsonResponse(400, ['error' => '유효하지 않은 카카오 계정 정보입니다.']);
    }

    $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, created_at FROM users WHERE kakao_id = ?');
    $stmt->execute([$kakaoId]);
    $user = $stmt->fetch();

    if (!$user && $email) {
        $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, created_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            $pdo->prepare('UPDATE users SET kakao_id = ? WHERE id = ?')->execute([$kakaoId, $existing['id']]);
            $user = $existing;
        }
    }

    if (!$user) {
        $userEmail = $email ?: ('kakao_' . substr($kakaoId, -6) . '@mylaplog.com');
        $avatar = mb_strtoupper(mb_substr($nickname, 0, 2));
        $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, kakao_id, avatar, driver_class) VALUES (?, ?, ?, ?, ?, "CLUBMAN")');
        $stmt->execute([$userEmail, $dummyHash, $nickname, $kakaoId, $avatar]);
        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    jsonResponse(200, ['message' => '카카오 로그인 성공', 'user' => $user]);
}

// =========================================================================
// 2. TRACKS ENDPOINT (CIRCUITS METADATA)
// =========================================================================

// GET /tracks
if ($method === 'GET' && $uri === '/tracks') {
    $stmt = $pdo->query('SELECT id, name, layout_name, length_meters, sector_count FROM tracks ORDER BY id ASC');
    $tracks = $stmt->fetchAll();
    jsonResponse(200, ['tracks' => $tracks]);
}

// =========================================================================
// 3. VEHICLES ENDPOINTS (GARAGE QUICK ACCESS)
// =========================================================================

// GET /vehicles
if ($method === 'GET' && $uri === '/vehicles') {
    $userId = getCurrentUserId();
    if ($userId) {
        $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $vehicles = $stmt->fetchAll();
    } else {
        // Guest mode default vehicles
        $vehicles = [
            [
                'id' => 1,
                'make' => 'Hyundai',
                'model' => 'Avante N (CN7)',
                'year' => 2024,
                'engine_power' => 280,
                'tire_model' => 'Kumho Ecsta V730',
                'tire_size_front' => '245/40R18',
                'tire_size_rear' => '245/40R18',
                'suspension_spec' => 'KW Clubsport 2-way',
                'is_active' => 1
            ],
            [
                'id' => 2,
                'make' => 'Toyota',
                'model' => 'GR86 (ZN8)',
                'year' => 2023,
                'engine_power' => 228,
                'tire_model' => 'Bridgestone RE-71RS',
                'tire_size_front' => '225/45R17',
                'tire_size_rear' => '225/45R17',
                'suspension_spec' => 'Ohlins Road & Track',
                'is_active' => 1
            ],
            [
                'id' => 3,
                'make' => 'BMW',
                'model' => 'M2 (G87)',
                'year' => 2024,
                'engine_power' => 460,
                'tire_model' => 'Michelin Pilot Sport Cup 2',
                'tire_size_front' => '275/35R19',
                'tire_size_rear' => '285/30R20',
                'suspension_spec' => 'M Adaptive Suspension',
                'is_active' => 1
            ]
        ];
    }
    jsonResponse(200, ['vehicles' => $vehicles]);
}

// POST /vehicles
if ($method === 'POST' && $uri === '/vehicles') {
    $userId = requireAuth();
    $body = getBody();

    $make = trim($body['make'] ?? '');
    $model = trim($body['model'] ?? '');
    $year = (int)($body['year'] ?? date('Y'));
    $tireModel = trim($body['tire_model'] ?? 'Street Sport Tire');

    if (!$make || !$model) {
        jsonResponse(400, ['error' => '제조사와 차종명을 입력해 주세요.']);
    }

    $stmt = $pdo->prepare('
        INSERT INTO vehicles (
            user_id, make, model, year, engine_power,
            tire_model, tire_size_front, tire_size_rear,
            suspension_spec, visibility, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "PRIVATE", 1)
    ');
    $stmt->execute([
        $userId,
        $make,
        $model,
        $year,
        (int)($body['engine_power'] ?? 250),
        $tireModel,
        $body['tire_size_front'] ?? '245/40R18',
        $body['tire_size_rear'] ?? '245/40R18',
        $body['suspension_spec'] ?? 'Stock Suspension',
    ]);

    $newId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
    $stmt->execute([$newId]);
    $vehicle = $stmt->fetch();

    jsonResponse(201, ['message' => '차량이 등록되었습니다.', 'vehicle' => $vehicle]);
}

// =========================================================================
// 4. TRACK SESSIONS ENDPOINTS (LOG SESSION & SESSION LOGS)
// =========================================================================

// GET /sessions
if ($method === 'GET' && $uri === '/sessions') {
    $userId = getCurrentUserId();
    
    // Base SQL for full setup details
    $baseSql = '
        SELECT ts.*, t.name as track_name, t.layout_name as track_layout,
               CONCAT(v.make, " ", v.model) as vehicle_name,
               v.tire_model as vehicle_tire,
               u.name as driver_name, u.avatar as driver_avatar,
               vs.cold_psi_fl, vs.cold_psi_fr, vs.cold_psi_rl, vs.cold_psi_rr,
               vs.hot_psi_fl, vs.hot_psi_fr, vs.hot_psi_rl, vs.hot_psi_rr,
               vs.damper_front_clicks, vs.damper_rear_clicks,
               vs.camber_fl, vs.camber_fr, vs.camber_rl, vs.camber_rr,
               vs.toe_fl, vs.toe_fr, vs.toe_rl, vs.toe_rr,
               vs.caster_fl, vs.caster_fr,
               vs.wing_angle_deg, vs.fuel_liters,
               vs.driver_notes
        FROM track_sessions ts
        JOIN tracks t ON t.id = ts.track_id
        JOIN vehicles v ON v.id = ts.vehicle_id
        JOIN users u ON u.id = ts.user_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
    ';

    if ($userId) {
        // Logged-in user's sessions + public demo sessions
        $stmt = $pdo->prepare($baseSql . ' WHERE ts.user_id = ? OR ts.visibility = "PUBLIC" ORDER BY ts.session_date DESC, ts.id DESC');
        $stmt->execute([$userId]);
    } else {
        // Guest mode preview sessions
        $stmt = $pdo->prepare($baseSql . ' ORDER BY ts.session_date DESC, ts.id DESC LIMIT 20');
        $stmt->execute();
    }
    $sessions = $stmt->fetchAll();

    // Attach lap times list to each session
    foreach ($sessions as &$s) {
        $lstmt = $pdo->prepare('SELECT id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_best FROM lap_times WHERE session_id = ? ORDER BY lap_number ASC');
        $lstmt->execute([$s['id']]);
        $s['laps'] = $lstmt->fetchAll();
    }

    jsonResponse(200, ['sessions' => $sessions]);
}

// GET /sessions/:id
if ($method === 'GET' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $sessionId = (int)$m[1];

    $stmt = $pdo->prepare('
        SELECT ts.*, t.name as track_name, t.layout_name as track_layout,
               CONCAT(v.make, " ", v.model) as vehicle_name,
               v.tire_model as vehicle_tire,
               u.name as driver_name, u.avatar as driver_avatar,
               vs.cold_psi_fl, vs.cold_psi_fr, vs.cold_psi_rl, vs.cold_psi_rr,
               vs.hot_psi_fl, vs.hot_psi_fr, vs.hot_psi_rl, vs.hot_psi_rr,
               vs.damper_front_clicks, vs.damper_rear_clicks,
               vs.camber_fl, vs.camber_fr, vs.camber_rl, vs.camber_rr,
               vs.toe_fl, vs.toe_fr, vs.toe_rl, vs.toe_rr,
               vs.caster_fl, vs.caster_fr,
               vs.wing_angle_deg, vs.fuel_liters,
               vs.driver_notes
        FROM track_sessions ts
        JOIN tracks t ON t.id = ts.track_id
        JOIN vehicles v ON v.id = ts.vehicle_id
        JOIN users u ON u.id = ts.user_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
        WHERE ts.id = ?
    ');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        jsonResponse(404, ['error' => '세션을 찾을 수 없습니다.']);
    }

    $lstmt = $pdo->prepare('SELECT id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_best FROM lap_times WHERE session_id = ? ORDER BY lap_number ASC');
    $lstmt->execute([$sessionId]);
    $session['laps'] = $lstmt->fetchAll();

    jsonResponse(200, ['session' => $session]);
}

// POST /sessions (Log Session: Record track session with 4-wheel tire pressures & lap times)
if ($method === 'POST' && $uri === '/sessions') {
    $userId = getCurrentUserId();
    $body = getBody();

    // Default to first user in system if guest in DB (for smooth seamless guest saving)
    if (!$userId) {
        $firstUser = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetch();
        $userId = $firstUser ? (int)$firstUser['id'] : 1;
    }

    $trackId = (int)($body['track_id'] ?? 1);
    $vehicleId = !empty($body['vehicle_id']) ? (int)$body['vehicle_id'] : null;

    // Auto-create or resolve vehicle if name provided
    if (!$vehicleId && !empty($body['vehicle_name'])) {
        $vName = trim($body['vehicle_name']);
        $chk = $pdo->prepare('SELECT id FROM vehicles WHERE user_id = ? AND CONCAT(make, " ", model) = ? LIMIT 1');
        $chk->execute([$userId, $vName]);
        $found = $chk->fetch();
        if ($found) {
            $vehicleId = (int)$found['id'];
        } else {
            $ins = $pdo->prepare('INSERT INTO vehicles (user_id, make, model, year, tire_model) VALUES (?, "Track", ?, 2024, "Sport Semi-Slick")');
            $ins->execute([$userId, $vName]);
            $vehicleId = (int)$pdo->lastInsertId();
        }
    }

    if (!$vehicleId) {
        $firstV = $pdo->query('SELECT id FROM vehicles ORDER BY id ASC LIMIT 1')->fetch();
        $vehicleId = $firstV ? (int)$firstV['id'] : 1;
    }

    $bestLapMs = (int)($body['best_lap_ms'] ?? 0);
    $laps = $body['laps'] ?? [];
    if ($bestLapMs <= 0 && !empty($laps)) {
        $minMs = PHP_INT_MAX;
        foreach ($laps as $lp) {
            $ms = (int)($lp['lap_time_ms'] ?? 0);
            if ($ms > 0 && $ms < $minMs) $minMs = $ms;
        }
        if ($minMs < PHP_INT_MAX) $bestLapMs = $minMs;
    }

    $sessionDate = $body['session_date'] ?? date('Y-m-d');
    $sessionNumber = (int)($body['session_number'] ?? 1);
    $weather = $body['weather_condition'] ?? 'DRY';
    $airTemp = isset($body['air_temp']) && $body['air_temp'] !== '' ? $body['air_temp'] : 24.0;
    $trackTemp = isset($body['track_temp']) && $body['track_temp'] !== '' ? $body['track_temp'] : 38.0;
    $topSpeed = isset($body['top_speed_kmh']) && $body['top_speed_kmh'] !== '' ? $body['top_speed_kmh'] : null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO track_sessions (
                user_id, vehicle_id, track_id, session_date, session_number,
                air_temp, track_temp, weather_condition, visibility, best_lap_ms, top_speed_kmh
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "PUBLIC", ?, ?)
        ');
        $stmt->execute([
            $userId, $vehicleId, $trackId, $sessionDate, $sessionNumber,
            $airTemp, $trackTemp, $weather, $bestLapMs, $topSpeed
        ]);
        $sessionId = (int)$pdo->lastInsertId();

        // 4-Wheel Setup insertion
        $setup = $body['setup'] ?? [];
        $stmt = $pdo->prepare('
            INSERT INTO vehicle_setups (
                session_id,
                cold_psi_fl, cold_psi_fr, cold_psi_rl, cold_psi_rr,
                hot_psi_fl, hot_psi_fr, hot_psi_rl, hot_psi_rr,
                damper_front_clicks, damper_rear_clicks,
                camber_fl, camber_fr, camber_rl, camber_rr,
                toe_fl, toe_fr, toe_rl, toe_rr,
                caster_fl, caster_fr,
                wing_angle_deg, fuel_liters,
                driver_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sessionId,
            $setup['cold_psi_fl'] ?? null,
            $setup['cold_psi_fr'] ?? null,
            $setup['cold_psi_rl'] ?? null,
            $setup['cold_psi_rr'] ?? null,
            $setup['hot_psi_fl'] ?? null,
            $setup['hot_psi_fr'] ?? null,
            $setup['hot_psi_rl'] ?? null,
            $setup['hot_psi_rr'] ?? null,
            $setup['damper_front_clicks'] ?? null,
            $setup['damper_rear_clicks'] ?? null,
            $setup['camber_fl'] ?? null,
            $setup['camber_fr'] ?? null,
            $setup['camber_rl'] ?? null,
            $setup['camber_rr'] ?? null,
            $setup['toe_fl'] ?? null,
            $setup['toe_fr'] ?? null,
            $setup['toe_rl'] ?? null,
            $setup['toe_rr'] ?? null,
            $setup['caster_fl'] ?? null,
            $setup['caster_fr'] ?? null,
            $setup['wing_angle_deg'] ?? null,
            $setup['fuel_liters'] ?? null,
            $setup['driver_notes'] ?? ($body['driver_notes'] ?? '')
        ]);

        // Lap Times insertion
        if (empty($laps) && $bestLapMs > 0) {
            $laps = [['lap_number' => 1, 'lap_time_ms' => $bestLapMs, 'is_best' => 1]];
        }
        if (!empty($laps)) {
            $lstmt = $pdo->prepare('
                INSERT INTO lap_times (
                    session_id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_best
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            foreach ($laps as $lp) {
                $timeMs = (int)($lp['lap_time_ms'] ?? 0);
                if ($timeMs > 0) {
                    $lstmt->execute([
                        $sessionId,
                        (int)($lp['lap_number'] ?? 1),
                        $timeMs,
                        $lp['sector1_ms'] ?? null,
                        $lp['sector2_ms'] ?? null,
                        $lp['sector3_ms'] ?? null,
                        $timeMs === $bestLapMs ? 1 : 0
                    ]);
                }
            }
        }

        $pdo->commit();
        jsonResponse(201, ['message' => '세션이 성공적으로 기록되었습니다. 🏎️', 'session_id' => $sessionId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '세션 저장 실패: ' . $e->getMessage()]);
    }
}

// PUT /sessions/:id (Edit session, 4-wheel psi & notes)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $sessionId = (int)$m[1];
    $body = getBody();

    $pdo->beginTransaction();
    try {
        // Update track_sessions fields if present
        $updates = [];
        $params = [];
        if (isset($body['session_date'])) { $updates[] = 'session_date = ?'; $params[] = $body['session_date']; }
        if (isset($body['session_number'])) { $updates[] = 'session_number = ?'; $params[] = (int)$body['session_number']; }
        if (isset($body['weather_condition'])) { $updates[] = 'weather_condition = ?'; $params[] = $body['weather_condition']; }
        if (isset($body['air_temp'])) { $updates[] = 'air_temp = ?'; $params[] = $body['air_temp']; }
        if (isset($body['track_temp'])) { $updates[] = 'track_temp = ?'; $params[] = $body['track_temp']; }
        if (isset($body['best_lap_ms'])) { $updates[] = 'best_lap_ms = ?'; $params[] = (int)$body['best_lap_ms']; }
        if (isset($body['top_speed_kmh'])) { $updates[] = 'top_speed_kmh = ?'; $params[] = $body['top_speed_kmh']; }

        if (!empty($updates)) {
            $params[] = $sessionId;
            $stmt = $pdo->prepare('UPDATE track_sessions SET ' . implode(', ', $updates) . ' WHERE id = ?');
            $stmt->execute($params);
        }

        // Update setup fields
        $setup = $body['setup'] ?? [];
        if (!empty($setup) || isset($body['driver_notes'])) {
            $notes = $setup['driver_notes'] ?? ($body['driver_notes'] ?? null);
            $stmt = $pdo->prepare('
                UPDATE vehicle_setups SET
                    cold_psi_fl = COALESCE(?, cold_psi_fl),
                    cold_psi_fr = COALESCE(?, cold_psi_fr),
                    cold_psi_rl = COALESCE(?, cold_psi_rl),
                    cold_psi_rr = COALESCE(?, cold_psi_rr),
                    hot_psi_fl = COALESCE(?, hot_psi_fl),
                    hot_psi_fr = COALESCE(?, hot_psi_fr),
                    hot_psi_rl = COALESCE(?, hot_psi_rl),
                    hot_psi_rr = COALESCE(?, hot_psi_rr),
                    damper_front_clicks = COALESCE(?, damper_front_clicks),
                    damper_rear_clicks = COALESCE(?, damper_rear_clicks),
                    driver_notes = COALESCE(?, driver_notes)
                WHERE session_id = ?
            ');
            $stmt->execute([
                $setup['cold_psi_fl'] ?? null,
                $setup['cold_psi_fr'] ?? null,
                $setup['cold_psi_rl'] ?? null,
                $setup['cold_psi_rr'] ?? null,
                $setup['hot_psi_fl'] ?? null,
                $setup['hot_psi_fr'] ?? null,
                $setup['hot_psi_rl'] ?? null,
                $setup['hot_psi_rr'] ?? null,
                $setup['damper_front_clicks'] ?? null,
                $setup['damper_rear_clicks'] ?? null,
                $notes,
                $sessionId
            ]);
        }

        $pdo->commit();
        jsonResponse(200, ['message' => '세션 정보가 수정되었습니다.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '세션 수정 실패: ' . $e->getMessage()]);
    }
}

// DELETE /sessions/:id
if ($method === 'DELETE' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $sessionId = (int)$m[1];
    $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    jsonResponse(200, ['message' => '세션이 삭제되었습니다.']);
}

// =========================================================================
// 5. GUIDES & INSIGHTS ENDPOINTS
// =========================================================================

// GET /guides
if ($method === 'GET' && $uri === '/guides') {
    $stmt = $pdo->query('
        SELECT id, slug, title, category, excerpt, cover_image, author_name, read_time, views, is_featured, created_at
        FROM guides
        WHERE status = "PUBLISHED"
        ORDER BY is_featured DESC, id ASC
    ');
    $guides = $stmt->fetchAll();
    jsonResponse(200, ['guides' => $guides]);
}

// GET /guides/:idOrSlug
if ($method === 'GET' && preg_match('#^/guides/([^/]+)$#', $uri, $m)) {
    $param = $m[1];
    if (is_numeric($param)) {
        $stmt = $pdo->prepare('SELECT * FROM guides WHERE id = ? AND status = "PUBLISHED"');
        $stmt->execute([(int)$param]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM guides WHERE slug = ? AND status = "PUBLISHED"');
        $stmt->execute([$param]);
    }
    $guide = $stmt->fetch();

    if (!$guide) {
        jsonResponse(404, ['error' => '가이드를 찾을 수 없습니다.']);
    }

    // Increment views
    $pdo->prepare('UPDATE guides SET views = views + 1 WHERE id = ?')->execute([$guide['id']]);
    $guide['views'] = (int)$guide['views'] + 1;

    jsonResponse(200, ['guide' => $guide]);
}

// 404 Fallback
jsonResponse(404, ['error' => 'Not Found', 'endpoint' => $uri, 'method' => $method]);
