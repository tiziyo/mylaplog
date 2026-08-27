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

// --- Secure Session Configuration ---
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => $isHttps,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

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

function getBody() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
    if (!empty($_POST)) return $_POST;
    return [];
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
        SELECT t.*, tm.role,
               (SELECT COUNT(*) FROM team_members tm2 WHERE tm2.team_id = t.id) as member_count
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
    
    // Check 10-team limit per user
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() >= 10) {
        jsonResponse(400, ['error' => '팀은 최대 10개까지만 생성하거나 가입할 수 있습니다.']);
    }

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

    // Check 10-team limit per user
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() >= 10) {
        jsonResponse(400, ['error' => '팀은 최대 10개까지만 생성하거나 가입할 수 있습니다.']);
    }

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

// PUT /teams/:id (팀 정보 수정 - 팀장 전용)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/teams/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $teamId = (int)$m[1];
    $body = getBody();

    // Check membership & role (Must be CHIEF / OWNER)
    $stmt = $pdo->prepare('SELECT tm.role, t.owner_id, t.name FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.team_id = ? AND tm.user_id = ?');
    $stmt->execute([$teamId, $userId]);
    $membership = $stmt->fetch();

    if (!$membership) {
        jsonResponse(404, ['error' => '소속된 팀을 찾을 수 없습니다.']);
    }

    $isOwner = ($membership['owner_id'] == $userId || $membership['role'] === 'CHIEF' || $membership['role'] === 'OWNER');
    if (!$isOwner) {
        jsonResponse(403, ['error' => '팀장(Chief) 권한을 가진 멤버만 팀 정보를 수정할 수 있습니다.']);
    }

    $name = trim($body['name'] ?? '');
    $homeTrack = $body['home_track'] ?? '인제 스피디움';
    $desc = $body['description'] ?? '';

    if (!$name) {
        jsonResponse(400, ['error' => '팀명을 입력하세요.']);
    }

    $stmt = $pdo->prepare('UPDATE teams SET name = ?, home_track = ?, description = ? WHERE id = ?');
    $stmt->execute([$name, $homeTrack, $desc, $teamId]);

    jsonResponse(200, [
        'message' => "'{$name}' 팀 정보가 성공적으로 수정되었습니다.",
        'team' => [
            'id' => $teamId,
            'name' => $name,
            'home_track' => $homeTrack,
            'description' => $desc
        ]
    ]);
}

// DELETE /teams/:id (팀 삭제 또는 탈퇴)
if ($method === 'DELETE' && preg_match('#^/teams/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $teamId = (int)$m[1];

    // Check membership & role
    $stmt = $pdo->prepare('SELECT tm.role, t.owner_id, t.name FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.team_id = ? AND tm.user_id = ?');
    $stmt->execute([$teamId, $userId]);
    $membership = $stmt->fetch();

    if (!$membership) {
        jsonResponse(404, ['error' => '소속된 팀을 찾을 수 없습니다.']);
    }

    $isOwner = ($membership['owner_id'] == $userId || $membership['role'] === 'CHIEF');

    $pdo->beginTransaction();
    try {
        if ($isOwner) {
            // 팀장/소유자: 팀 전체 해체 및 삭제
            $stmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ?');
            $stmt->execute([$teamId]);

            $stmt = $pdo->prepare('UPDATE track_sessions SET team_id = NULL WHERE team_id = ?');
            $stmt->execute([$teamId]);

            $stmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
            $stmt->execute([$teamId]);

            $pdo->commit();
            jsonResponse(200, ['message' => "'{$membership['name']}' 팀이 성공적으로 삭제(해체)되었습니다.", 'action' => 'deleted']);
        } else {
            // 일반 멤버: 팀 탈퇴
            $stmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?');
            $stmt->execute([$teamId, $userId]);

            $pdo->commit();
            jsonResponse(200, ['message' => "'{$membership['name']}' 팀에서 탈퇴했습니다.", 'action' => 'left']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '팀 삭제/탈퇴 처리 실패: ' . $e->getMessage()]);
    }
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
    $stmt = $pdo->prepare('
        SELECT v.*, t.name as team_name, t.home_track as team_home_track
        FROM vehicles v
        LEFT JOIN teams t ON t.id = v.team_id
        WHERE v.user_id = ?
        ORDER BY v.is_active DESC, v.created_at DESC
    ');
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

// PUT /vehicles/:id (차량 정보 수정)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/vehicles/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $vehicleId = (int)$m[1];
    $body = getBody();

    // Check ownership
    $check = $pdo->prepare('SELECT id FROM vehicles WHERE id = ? AND user_id = ?');
    $check->execute([$vehicleId, $userId]);
    if (!$check->fetch()) {
        jsonResponse(404, ['error' => '수정할 차량을 찾을 수 없거나 권한이 없습니다.']);
    }

    $stmt = $pdo->prepare('
        UPDATE vehicles 
        SET make = ?, model = ?, year = ?, engine_power = ?, tire_model = ?, 
            tire_size_front = ?, tire_size_rear = ?, suspension_spec = ?, visibility = ?,
            team_id = ?
        WHERE id = ? AND user_id = ?
    ');
    $stmt->execute([
        $body['make'] ?? '',
        $body['model'] ?? '',
        $body['year'] ?? 2024,
        $body['engine_power'] ?? 250,
        $body['tire_model'] ?? '',
        $body['tire_size_front'] ?? '245/40R18',
        $body['tire_size_rear'] ?? '245/40R18',
        $body['suspension_spec'] ?? '',
        $body['visibility'] ?? 'TEAM',
        $body['team_id'] ?? null,
        $vehicleId,
        $userId
    ]);

    jsonResponse(200, ['message' => '머신 정보가 성공적으로 수정되었습니다.', 'vehicle_id' => $vehicleId]);
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

    $bestLapMs = (int)($body['best_lap_ms'] ?? 0);
    $laps = $body['laps'] ?? [];
    if ($bestLapMs <= 0 && !empty($laps)) {
        $bestLapMs = (int)($laps[0]['lap_time_ms'] ?? 0);
    }

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
            $bestLapMs
        ]);
        $sessionId = $pdo->lastInsertId();

        // Insert vehicle setup
        $setup = $body['setup'] ?? [];
        if (!empty($setup)) {
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
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ');
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

        // Insert lap times (ensure at least best lap record exists)
        if (empty($laps) && $bestLapMs > 0) {
            $laps = [
                ['lap_number' => 1, 'lap_time_ms' => $bestLapMs, 'is_valid' => 1, 'is_best' => 1]
            ];
        }

        if (!empty($laps)) {
            $stmt = $pdo->prepare('INSERT INTO lap_times (session_id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_valid, is_best) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($laps as $lap) {
                $stmt->execute([
                    $sessionId,
                    $lap['lap_number'] ?? 1,
                    $lap['lap_time_ms'],
                    $lap['sector1_ms'] ?? null,
                    $lap['sector2_ms'] ?? null,
                    $lap['sector3_ms'] ?? null,
                    $lap['is_valid'] ?? 1,
                    $lap['is_best'] ?? 1
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

// PUT /sessions/:id (세션 및 셋업 수정)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $sessionId = (int)$m[1];
    $body = getBody();

    // Check ownership
    $check = $pdo->prepare('SELECT id FROM track_sessions WHERE id = ? AND user_id = ?');
    $check->execute([$sessionId, $userId]);
    if (!$check->fetch()) {
        jsonResponse(404, ['error' => '수정할 세션을 찾을 수 없거나 권한이 없습니다.']);
    }

    $bestLapMs = (int)($body['best_lap_ms'] ?? 0);
    $laps = $body['laps'] ?? [];
    if ($bestLapMs <= 0 && !empty($laps)) {
        $bestLapMs = (int)($laps[0]['lap_time_ms'] ?? 0);
    }

    $pdo->beginTransaction();
    try {
        // Update track_sessions
        $stmt = $pdo->prepare('
            UPDATE track_sessions 
            SET vehicle_id = ?, track_id = ?, session_date = ?, session_number = ?, 
                air_temp = ?, track_temp = ?, weather_condition = ?, visibility = ?, 
                best_lap_ms = ?, team_id = ?
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([
            $body['vehicle_id'],
            $body['track_id'],
            $body['session_date'],
            $body['session_number'] ?? 1,
            $body['air_temp'] ?? 25.0,
            $body['track_temp'] ?? 40.0,
            $body['weather_condition'] ?? 'DRY',
            $body['visibility'] ?? 'TEAM',
            $bestLapMs,
            $body['team_id'] ?? null,
            $sessionId,
            $userId
        ]);

        // Update or insert vehicle_setups
        $setup = $body['setup'] ?? [];
        if (!empty($setup)) {
            $setupCheck = $pdo->prepare('SELECT id FROM vehicle_setups WHERE session_id = ?');
            $setupCheck->execute([$sessionId]);
            if ($setupCheck->fetch()) {
                $stmt = $pdo->prepare('
                    UPDATE vehicle_setups 
                    SET cold_psi_fl = ?, cold_psi_fr = ?, cold_psi_rl = ?, cold_psi_rr = ?,
                        hot_psi_fl = ?, hot_psi_fr = ?, hot_psi_rl = ?, hot_psi_rr = ?,
                        damper_front_clicks = ?, damper_rear_clicks = ?,
                        camber_fl = ?, camber_fr = ?, camber_rl = ?, camber_rr = ?,
                        toe_fl = ?, toe_fr = ?, toe_rl = ?, toe_rr = ?,
                        caster_fl = ?, caster_fr = ?,
                        wing_angle_deg = ?, fuel_liters = ?,
                        driver_notes = ?
                    WHERE session_id = ?
                ');
                $stmt->execute([
                    $setup['cold_psi_fl'] ?? 28.0, $setup['cold_psi_fr'] ?? 28.0, $setup['cold_psi_rl'] ?? 28.0, $setup['cold_psi_rr'] ?? 28.0,
                    $setup['hot_psi_fl'] ?? 34.0, $setup['hot_psi_fr'] ?? 34.0, $setup['hot_psi_rl'] ?? 32.0, $setup['hot_psi_rr'] ?? 32.0,
                    $setup['damper_front_clicks'] ?? 12, $setup['damper_rear_clicks'] ?? 8,
                    $setup['camber_fl'] ?? -3.2, $setup['camber_fr'] ?? -3.2, $setup['camber_rl'] ?? -2.0, $setup['camber_rr'] ?? -2.0,
                    $setup['toe_fl'] ?? 0, $setup['toe_fr'] ?? 0, $setup['toe_rl'] ?? 1.0, $setup['toe_rr'] ?? 1.0,
                    $setup['caster_fl'] ?? 6.5, $setup['caster_fr'] ?? 6.5,
                    $setup['wing_angle_deg'] ?? 4.0,
                    $setup['fuel_liters'] ?? 30.0,
                    $setup['driver_notes'] ?? '',
                    $sessionId
                ]);
            } else {
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
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ');
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
        }

        // Update lap times
        if (empty($laps) && $bestLapMs > 0) {
            $laps = [
                ['lap_number' => 1, 'lap_time_ms' => $bestLapMs, 'is_valid' => 1, 'is_best' => 1]
            ];
        }

        if (!empty($laps)) {
            $pdo->prepare('DELETE FROM lap_times WHERE session_id = ?')->execute([$sessionId]);
            $stmt = $pdo->prepare('INSERT INTO lap_times (session_id, lap_number, lap_time_ms, is_valid, is_best) VALUES (?,?,?,?,?)');
            foreach ($laps as $lap) {
                $stmt->execute([
                    $sessionId,
                    $lap['lap_number'] ?? 1,
                    $lap['lap_time_ms'],
                    $lap['is_valid'] ?? 1,
                    $lap['is_best'] ?? 1
                ]);
            }
        }

        $pdo->commit();
        jsonResponse(200, ['message' => '세션 및 셋업 정보가 성공적으로 수정되었습니다.', 'session_id' => $sessionId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '세션 수정 실패: ' . $e->getMessage()]);
    }
}

// DELETE /sessions/:id
if ($method === 'DELETE' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $sessionId = (int)$m[1];

    $check = $pdo->prepare('SELECT id FROM track_sessions WHERE id = ? AND user_id = ?');
    $check->execute([$sessionId, $userId]);
    if (!$check->fetch()) {
        jsonResponse(404, ['error' => '삭제할 세션을 찾을 수 없거나 권한이 없습니다.']);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM lap_times WHERE session_id = ?')->execute([$sessionId]);
        $pdo->prepare('DELETE FROM vehicle_setups WHERE session_id = ?')->execute([$sessionId]);
        $pdo->prepare('DELETE FROM track_sessions WHERE id = ? AND user_id = ?')->execute([$sessionId, $userId]);
        $pdo->commit();
        jsonResponse(200, ['message' => '세션 및 관련 셋업 데이터가 삭제되었습니다.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '세션 삭제 실패: ' . $e->getMessage()]);
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
