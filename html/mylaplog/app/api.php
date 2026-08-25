<?php
/**
 * MyLapLog REST API v0.8
 * MariaDB Backend API (Single-file Router)
 * 
 * Endpoints:
 *   POST   /api/auth/login       - 로그인
 *   POST   /api/auth/register    - 회원가입
 *   GET    /api/auth/me          - 현재 유저 정보
 *   POST   /api/auth/logout      - 로그아웃
 *
 *   GET    /api/teams            - 팀 목록 (내 팀)
 *   POST   /api/teams            - 팀 생성
 *   POST   /api/teams/join       - 초대코드로 팀 가입
 *   GET    /api/teams/:id/members - 팀원 목록
 *
 *   GET    /api/vehicles         - 내 차량 목록
 *   POST   /api/vehicles         - 차량 등록
 *   DELETE /api/vehicles/:id     - 차량 삭제
 *
 *   GET    /api/sessions         - 세션 목록
 *   POST   /api/sessions         - 세션+셋업 저장
 *   GET    /api/sessions/:id     - 세션 상세 (셋업 포함)
 *
 *   GET    /api/tracks           - 서킷 목록
 *   GET    /api/leaderboard/:trackId - 서킷별 리더보드
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Database Connection ---
$DB_HOST = 'localhost';
$DB_NAME = 'mylaplog';
$DB_USER = 'admin';
$DB_PASS = 'StnXoa2w4DO8KE9V';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    jsonResponse(500, ['error' => 'Database connection failed: ' . $e->getMessage()]);
}

// --- Session-based Auth ---
session_start();

// --- Router ---
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip /api prefix for routing
$uri = preg_replace('#^/api#', '', $uri);

// Helper: JSON Response
function jsonResponse($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper: Get JSON Body
function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Helper: Require Auth
function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(401, ['error' => '로그인이 필요합니다.']);
    }
    return $_SESSION['user_id'];
}

// =====================
// AUTH ROUTES
// =====================

// POST /auth/login
if ($method === 'POST' && $uri === '/auth/login') {
    $body = getBody();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        jsonResponse(400, ['error' => '이메일과 비밀번호를 입력하세요.']);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(401, ['error' => '등록되지 않은 이메일입니다.']);
    }

    // For seed users with placeholder hash, accept any password
    if (!password_verify($password, $user['password_hash'])) {
        // Allow demo login for seed data
        if (strpos($user['password_hash'], '$2y$10$abcdefg') !== 0) {
            jsonResponse(401, ['error' => '비밀번호가 일치하지 않습니다.']);
        }
    }

    $_SESSION['user_id'] = $user['id'];

    unset($user['password_hash']);
    jsonResponse(200, ['message' => '로그인 성공', 'user' => $user]);
}

// POST /auth/register
if ($method === 'POST' && $uri === '/auth/register') {
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $karaLicense = $body['kara_license'] ?? 'Circuit License';
    $driverClass = $body['driver_class'] ?? 'PRO-AM';

    if (!$name || !$email || strlen($password) < 6) {
        jsonResponse(400, ['error' => '이름, 이메일, 비밀번호(6자 이상)를 입력하세요.']);
    }

    // Check duplicate email
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(409, ['error' => '이미 등록된 이메일입니다.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $avatar = mb_strtoupper(mb_substr($name, 0, 2));

    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, kara_license, driver_class, avatar) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$email, $hash, $name, $karaLicense, $driverClass, $avatar]);
    $userId = $pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;

    $user = ['id' => (int)$userId, 'email' => $email, 'name' => $name, 'kara_license' => $karaLicense, 'driver_class' => $driverClass, 'avatar' => $avatar];
    jsonResponse(201, ['message' => '회원가입 완료', 'user' => $user]);
}

// GET /auth/me
if ($method === 'GET' && $uri === '/auth/me') {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(200, ['user' => null]);
    }
    $stmt = $pdo->prepare('SELECT id, email, name, kara_license, driver_class, avatar, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    jsonResponse(200, ['user' => $user ?: null]);
}

// POST /auth/logout
if ($method === 'POST' && $uri === '/auth/logout') {
    session_destroy();
    jsonResponse(200, ['message' => '로그아웃 완료']);
}

// =====================
// TEAMS ROUTES
// =====================

// GET /teams
if ($method === 'GET' && $uri === '/teams') {
    $userId = requireAuth();
    $stmt = $pdo->prepare('
        SELECT t.*, tm.role 
        FROM teams t 
        JOIN team_members tm ON tm.team_id = t.id 
        WHERE tm.user_id = ?
        ORDER BY t.created_at DESC
    ');
    $stmt->execute([$userId]);
    jsonResponse(200, ['teams' => $stmt->fetchAll()]);
}

// POST /teams
if ($method === 'POST' && $uri === '/teams') {
    $userId = requireAuth();
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $homeTrack = $body['home_track'] ?? '인제 스피디움';
    $desc = $body['description'] ?? '';

    if (!$name) {
        jsonResponse(400, ['error' => '팀명을 입력하세요.']);
    }

    // Safe alphanumeric invite code generation (prevents multi-byte truncation for Korean/UTF-8 team names)
    $cleanName = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
    $prefix = (strlen($cleanName) >= 2) ? substr($cleanName, 0, 4) : 'TEAM';
    $code = $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO teams (name, invite_code, owner_id, home_track, description) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, $code, $userId, $homeTrack, $desc]);
        $teamId = $pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO team_members (team_id, user_id, role, can_view_data, can_edit_data) VALUES (?,?,?,1,1)');
        $stmt->execute([$teamId, $userId, 'CHIEF']);

        $pdo->commit();
        jsonResponse(201, ['message' => '팀 생성 완료', 'team' => ['id' => (int)$teamId, 'name' => $name, 'invite_code' => $code, 'home_track' => $homeTrack]]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '팀 생성 실패: ' . $e->getMessage()]);
    }
}

// POST /teams/join
if ($method === 'POST' && $uri === '/teams/join') {
    $userId = requireAuth();
    $body = getBody();
    $code = strtoupper(trim($body['invite_code'] ?? ''));
    $role = $body['role'] ?? 'DRIVER';

    if (!$code) {
        jsonResponse(400, ['error' => '초대 코드를 입력하세요.']);
    }

    $stmt = $pdo->prepare('SELECT id, name FROM teams WHERE invite_code = ?');
    $stmt->execute([$code]);
    $team = $stmt->fetch();

    if (!$team) {
        jsonResponse(404, ['error' => '유효하지 않은 초대 코드입니다.']);
    }

    // Check if already a member
    $stmt = $pdo->prepare('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$team['id'], $userId]);
    if ($stmt->fetch()) {
        jsonResponse(409, ['error' => '이미 이 팀의 멤버입니다.']);
    }

    $canEdit = in_array($role, ['CHIEF', 'DRIVER', 'MECHANIC']) ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO team_members (team_id, user_id, role, can_view_data, can_edit_data) VALUES (?,?,?,1,?)');
    $stmt->execute([$team['id'], $userId, $role, $canEdit]);

    jsonResponse(200, ['message' => "{$team['name']} 팀에 가입되었습니다.", 'team' => $team]);
}

// GET /teams/:id/members
if ($method === 'GET' && preg_match('#^/teams/(\d+)/members$#', $uri, $m)) {
    requireAuth();
    $teamId = $m[1];
    $stmt = $pdo->prepare('
        SELECT u.id, u.name, u.avatar, u.driver_class, tm.role, tm.joined_at,
               (SELECT COUNT(*) FROM track_sessions ts WHERE ts.user_id = u.id AND ts.team_id = ?) as session_count,
               (SELECT CONCAT(v.make, " ", v.model) FROM vehicles v WHERE v.user_id = u.id AND v.is_active = 1 LIMIT 1) as car
        FROM team_members tm 
        JOIN users u ON u.id = tm.user_id 
        WHERE tm.team_id = ?
        ORDER BY FIELD(tm.role, "CHIEF", "MANAGER", "DRIVER", "MECHANIC", "VIEWER")
    ');
    $stmt->execute([$teamId, $teamId]);
    jsonResponse(200, ['members' => $stmt->fetchAll()]);
}

// =====================
// VEHICLES ROUTES
// =====================

// GET /vehicles
if ($method === 'GET' && $uri === '/vehicles') {
    $userId = requireAuth();
    $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE user_id = ? ORDER BY is_active DESC, created_at DESC');
    $stmt->execute([$userId]);
    jsonResponse(200, ['vehicles' => $stmt->fetchAll()]);
}

// POST /vehicles
if ($method === 'POST' && $uri === '/vehicles') {
    $userId = requireAuth();
    $body = getBody();

    $stmt = $pdo->prepare('INSERT INTO vehicles (user_id, team_id, make, model, year, engine_power, tire_model, tire_size_front, tire_size_rear, suspension_spec, visibility) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $userId,
        $body['team_id'] ?? null,
        $body['make'] ?? '',
        $body['model'] ?? '',
        $body['year'] ?? 2024,
        $body['engine_power'] ?? 250,
        $body['tire_model'] ?? '',
        $body['tire_size_front'] ?? '245/40R18',
        $body['tire_size_rear'] ?? '245/40R18',
        $body['suspension_spec'] ?? '',
        $body['visibility'] ?? 'TEAM'
    ]);

    jsonResponse(201, ['message' => '차량 등록 완료', 'vehicle_id' => (int)$pdo->lastInsertId()]);
}

// DELETE /vehicles/:id
if ($method === 'DELETE' && preg_match('#^/vehicles/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $stmt = $pdo->prepare('DELETE FROM vehicles WHERE id = ? AND user_id = ?');
    $stmt->execute([$m[1], $userId]);
    jsonResponse(200, ['message' => '차량 삭제 완료']);
}

// =====================
// TRACKS ROUTES
// =====================

// GET /tracks
if ($method === 'GET' && $uri === '/tracks') {
    $stmt = $pdo->query('SELECT * FROM tracks ORDER BY id');
    jsonResponse(200, ['tracks' => $stmt->fetchAll()]);
}

// =====================
// SESSIONS ROUTES
// =====================

// GET /sessions
if ($method === 'GET' && $uri === '/sessions') {
    $userId = requireAuth();
    $stmt = $pdo->prepare('
        SELECT ts.*, t.name as track_name, CONCAT(v.make, " ", v.model) as vehicle_name,
               vs.hot_psi_fl, vs.hot_psi_fr, vs.hot_psi_rl, vs.hot_psi_rr,
               vs.damper_front_clicks, vs.damper_rear_clicks,
               vs.camber_fl, vs.camber_fr, vs.camber_rl, vs.camber_rr,
               vs.driver_notes
        FROM track_sessions ts
        JOIN tracks t ON t.id = ts.track_id
        JOIN vehicles v ON v.id = ts.vehicle_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
        WHERE ts.user_id = ?
        ORDER BY ts.session_date DESC, ts.session_number DESC
    ');
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll();

    // Attach lap times to each session
    foreach ($sessions as &$s) {
        $lstmt = $pdo->prepare('SELECT * FROM lap_times WHERE session_id = ? ORDER BY lap_number');
        $lstmt->execute([$s['id']]);
        $s['laps'] = $lstmt->fetchAll();
    }

    jsonResponse(200, ['sessions' => $sessions]);
}

// POST /sessions
if ($method === 'POST' && $uri === '/sessions') {
    $userId = requireAuth();
    $body = getBody();

    $pdo->beginTransaction();
    try {
        // Insert track session
        $stmt = $pdo->prepare('INSERT INTO track_sessions (user_id, team_id, vehicle_id, track_id, session_date, session_number, air_temp, track_temp, weather_condition, visibility, best_lap_ms) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $userId,
            $body['team_id'] ?? null,
            $body['vehicle_id'],
            $body['track_id'],
            $body['session_date'],
            $body['session_number'] ?? 1,
            $body['air_temp'] ?? 25.0,
            $body['track_temp'] ?? 40.0,
            $body['weather_condition'] ?? 'DRY',
            $body['visibility'] ?? 'TEAM',
            $body['best_lap_ms'] ?? 0
        ]);
        $sessionId = $pdo->lastInsertId();

        // Insert vehicle setup
        $setup = $body['setup'] ?? [];
        if (!empty($setup)) {
            $stmt = $pdo->prepare('INSERT INTO vehicle_setups (session_id, cold_psi_fl, cold_psi_fr, cold_psi_rl, cold_psi_rr, hot_psi_fl, hot_psi_fr, hot_psi_rl, hot_psi_rr, damper_front_clicks, damper_rear_clicks, camber_fl, camber_fr, camber_rl, camber_rr, toe_fl, toe_fr, toe_rl, toe_rr, caster_fl, caster_fr, wing_angle_deg, fuel_liters, driver_notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $sessionId,
                $setup['cold_psi_fl'] ?? 28.0, $setup['cold_psi_fr'] ?? 28.0, $setup['cold_psi_rl'] ?? 28.0, $setup['cold_psi_rr'] ?? 28.0,
                $setup['hot_psi_fl'] ?? 34.0, $setup['hot_psi_fr'] ?? 34.0, $setup['hot_psi_rl'] ?? 32.0, $setup['hot_psi_rr'] ?? 32.0,
                $setup['damper_front_clicks'] ?? 12, $setup['damper_rear_clicks'] ?? 8,
                $setup['camber_fl'] ?? -3.2, $setup['camber_fr'] ?? -3.2, $setup['camber_rl'] ?? -2.0, $setup['camber_rr'] ?? -2.0,
                $setup['toe_fl'] ?? 0, $setup['toe_fr'] ?? 0, $setup['toe_rl'] ?? 1.0, $setup['toe_rr'] ?? 1.0,
                $setup['caster_fl'] ?? 6.5, $setup['caster_fr'] ?? 6.5,
                $setup['wing_angle_deg'] ?? 4.0,
                $setup['fuel_liters'] ?? 30.0,
                $setup['driver_notes'] ?? ''
            ]);
        }

        // Insert lap times if provided
        $laps = $body['laps'] ?? [];
        if (!empty($laps)) {
            $stmt = $pdo->prepare('INSERT INTO lap_times (session_id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_valid, is_best) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($laps as $lap) {
                $stmt->execute([
                    $sessionId,
                    $lap['lap_number'],
                    $lap['lap_time_ms'],
                    $lap['sector1_ms'] ?? null,
                    $lap['sector2_ms'] ?? null,
                    $lap['sector3_ms'] ?? null,
                    $lap['is_valid'] ?? 1,
                    $lap['is_best'] ?? 0
                ]);
            }
        }

        $pdo->commit();
        jsonResponse(201, ['message' => '세션 저장 완료', 'session_id' => (int)$sessionId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '세션 저장 실패: ' . $e->getMessage()]);
    }
}

// GET /sessions/:id
if ($method === 'GET' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    requireAuth();
    $stmt = $pdo->prepare('
        SELECT ts.*, t.name as track_name, CONCAT(v.make, " ", v.model) as vehicle_name,
               vs.*
        FROM track_sessions ts
        JOIN tracks t ON t.id = ts.track_id
        JOIN vehicles v ON v.id = ts.vehicle_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
        WHERE ts.id = ?
    ');
    $stmt->execute([$m[1]]);
    $session = $stmt->fetch();
    if (!$session) {
        jsonResponse(404, ['error' => '세션을 찾을 수 없습니다.']);
    }

    $lstmt = $pdo->prepare('SELECT * FROM lap_times WHERE session_id = ? ORDER BY lap_number');
    $lstmt->execute([$m[1]]);
    $session['laps'] = $lstmt->fetchAll();

    jsonResponse(200, ['session' => $session]);
}

// =====================
// LEADERBOARD ROUTE
// =====================

// GET /leaderboard/:trackId
if ($method === 'GET' && preg_match('#^/leaderboard/(\d+)$#', $uri, $m)) {
    $trackId = $m[1];
    $stmt = $pdo->prepare('
        SELECT u.name as driver_name, u.avatar,
               CONCAT(v.make, " ", v.model) as vehicle_name, v.tire_model,
               t2.name as team_name,
               ts.best_lap_ms, ts.session_date,
               vs.hot_psi_fl, vs.camber_fl, vs.damper_front_clicks
        FROM track_sessions ts
        JOIN users u ON u.id = ts.user_id
        JOIN vehicles v ON v.id = ts.vehicle_id
        LEFT JOIN team_members tm ON tm.user_id = u.id
        LEFT JOIN teams t2 ON t2.id = tm.team_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
        WHERE ts.track_id = ? AND ts.best_lap_ms > 0
        ORDER BY ts.best_lap_ms ASC
        LIMIT 50
    ');
    $stmt->execute([$trackId]);
    jsonResponse(200, ['leaderboard' => $stmt->fetchAll()]);
}

// Fallback: 404
jsonResponse(404, ['error' => 'API endpoint not found: ' . $method . ' ' . $uri]);
