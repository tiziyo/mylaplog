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
        jsonResponse(401, ['error' => 'Authentication required.']);
    }
    return (int)$_SESSION['user_id'];
}

function getCurrentUserId() {
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

// --- Kakao & Google OAuth API Configuration ---
$KAKAO_REST_KEY = '0205ac0d0d3d23cbcf55c6359e75146c';
$KAKAO_JS_KEY   = '6c14bacdc52718fa93aaeae500a4d106';
$GOOGLE_CLIENT_ID = '188222968915-u7g1q746j4pfr44rh796j8mtv4kuo1o0.apps.googleusercontent.com';

// Universal HTTP Request Helper (cURL + stream_context fallback)
function makeHttpRequest($url, $method = 'GET', $data = null, $headers = []) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
            }
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => $res, 'status' => $httpCode];
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 10,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ];
    if ($method === 'POST' && $data !== null) {
        $opts['http']['content'] = is_array($data) ? http_build_query($data) : $data;
    }
    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hdr) {
            if (preg_match('#HTTP/[0-9\.]+\s+([0-9]+)#i', $hdr, $m)) {
                $status = (int)$m[1];
            }
        }
    }
    return ['body' => $res, 'status' => $status];
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
        jsonResponse(400, ['error' => 'Please enter both email and password.']);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(401, ['error' => 'Invalid email or password.']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    unset($user['password_hash']);
    jsonResponse(200, ['message' => 'Sign in successful', 'user' => $user]);
}

// POST /auth/register
if ($method === 'POST' && $uri === '/auth/register') {
    $body = getBody();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $name = trim($body['name'] ?? '');

    if (!$email || !$password || !$name) {
        jsonResponse(400, ['error' => 'Please provide name, email, and password.']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, ['error' => 'Invalid email address format.']);
    }

    if (strlen($password) < 6) {
        jsonResponse(400, ['error' => 'Password must be at least 6 characters.']);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(409, ['error' => 'Email address is already registered.']);
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

    jsonResponse(201, ['message' => 'Registration complete', 'user' => $user]);
}

// GET /auth/me
if ($method === 'GET' && $uri === '/auth/me') {
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonResponse(200, ['user' => null, 'is_guest' => true]);
    }

    $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, google_id, kakao_id, created_at FROM users WHERE id = ?');
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
    jsonResponse(200, ['message' => 'Logged out successfully.']);
}

// DELETE /auth/delete-account (Authenticated instant account & telemetry data deletion)
if ($method === 'DELETE' && $uri === '/auth/delete-account') {
    $userId = requireAuth();

    try {
        $pdo->beginTransaction();

        // 1. Delete vehicle setups & lap times
        $stmt = $pdo->prepare('DELETE FROM vehicle_setups WHERE session_id IN (SELECT id FROM track_sessions WHERE user_id = ?)');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM lap_times WHERE session_id IN (SELECT id FROM track_sessions WHERE user_id = ?)');
        $stmt->execute([$userId]);

        // 2. Delete track sessions
        $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);

        // 3. Delete vehicles
        $stmt = $pdo->prepare('DELETE FROM vehicles WHERE user_id = ?');
        $stmt->execute([$userId]);

        // 4. Delete user record
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        $pdo->commit();

        // Clear session & cookies
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?: '/',
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        jsonResponse(200, ['message' => 'Account and all telemetry data have been permanently deleted.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(500, ['error' => 'Failed to delete account: ' . $e->getMessage()]);
    }
}

// POST /auth/request-delete-account (Web form account deletion request)
if ($method === 'POST' && $uri === '/auth/request-delete-account') {
    $body = getBody();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email) {
        jsonResponse(400, ['error' => 'Please enter the email address to delete.']);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(404, ['error' => 'No driver account found with this email.']);
    }

    $isSocialUser = (!empty($user['kakao_id']) || !empty($user['google_id']));
    $isLoggedInAsThisUser = (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id']);

    if ($isSocialUser) {
        if (!$isLoggedInAsThisUser) {
            jsonResponse(401, ['error' => 'Social login accounts (Google/Kakao) must be signed in to verify deletion. Please sign in first, or contact support at racesolution.nl@gmail.com.']);
        }
    } else {
        if ($password) {
            if (!password_verify($password, $user['password_hash'])) {
                jsonResponse(401, ['error' => 'Incorrect account password.']);
            }
        } else if (!$isLoggedInAsThisUser) {
            jsonResponse(400, ['error' => 'Please enter your password to confirm account deletion.']);
        }
    }

    $userId = (int)$user['id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('DELETE FROM vehicle_setups WHERE session_id IN (SELECT id FROM track_sessions WHERE user_id = ?)');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM lap_times WHERE session_id IN (SELECT id FROM track_sessions WHERE user_id = ?)');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM vehicles WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        $pdo->commit();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?: '/',
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        jsonResponse(200, ['message' => 'Account and all associated telemetry data have been permanently deleted.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(500, ['error' => 'Failed to process account deletion: ' . $e->getMessage()]);
    }
}

// POST /auth/kakao
if ($method === 'POST' && $uri === '/auth/kakao') {
    $body = getBody();
    $accessToken = trim($body['access_token'] ?? '');
    $authCode = trim($body['code'] ?? '');
    $redirectUri = trim($body['redirect_uri'] ?? '');
    $directKakaoId = trim((string)($body['kakao_id'] ?? ''));

    // 1. If authCode was passed instead of access_token, exchange code for token
    if (!$accessToken && $authCode) {
        $lastErr = '';
        foreach ([$KAKAO_REST_KEY, $KAKAO_JS_KEY] as $clientId) {
            if (!$clientId) continue;
            $tokenRes = makeHttpRequest(
                'https://kauth.kakao.com/oauth/token',
                'POST',
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri ?: 'https://app.mylaplog.com/v1/',
                    'code' => $authCode
                ],
                ['Content-Type: application/x-www-form-urlencoded;charset=utf-8']
            );
            if (!empty($tokenRes['body'])) {
                $tokenData = json_decode($tokenRes['body'], true);
                if (!empty($tokenData['access_token'])) {
                    $accessToken = $tokenData['access_token'];
                    break;
                } else if (!empty($tokenData['error_description'])) {
                    $lastErr = $tokenData['error_description'];
                }
            }
        }
        if (!$accessToken && $lastErr && !$directKakaoId) {
            jsonResponse(400, ['error' => '카카오 토큰 발급 실패: ' . $lastErr]);
        }
    }

    $kakaoId = null;
    $nickname = '';
    $email = '';

    if ($accessToken) {
        // Verify token with Kakao Open API
        $userRes = makeHttpRequest(
            'https://kapi.kakao.com/v2/user/me',
            'GET',
            null,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/x-www-form-urlencoded;charset=utf-8'
            ]
        );

        if ($userRes['status'] === 200 && !empty($userRes['body'])) {
            $kData = json_decode($userRes['body'], true);
            if (!empty($kData['id'])) {
                $kakaoId = (string)$kData['id'];
                $kakaoAccount = $kData['kakao_account'] ?? [];
                $properties = $kData['properties'] ?? [];
                $nickname = $kakaoAccount['profile']['nickname'] ?? $properties['nickname'] ?? '카카오 드라이버';
                $email = $kakaoAccount['email'] ?? '';
            }
        } else if (!empty($userRes['body']) && !$directKakaoId) {
            $kData = json_decode($userRes['body'], true);
            $errDetail = $kData['msg'] ?? ('HTTP ' . $userRes['status']);
            jsonResponse(400, ['error' => '카카오 사용자 정보 조회 실패: ' . $errDetail]);
        }
    }

    // Fallback if direct kakao_id was sent (e.g. testing / legacy)
    if (!$kakaoId && $directKakaoId) {
        $kakaoId = $directKakaoId;
        $nickname = trim($body['nickname'] ?? '카카오 드라이버');
        $email = trim($body['email'] ?? '');
    }

    if (!$kakaoId) {
        jsonResponse(400, ['error' => '카카오 인증 정보 검증에 실패했습니다. (유효하지 않은 토큰)']);
    }

    // Check if user with this kakao_id exists
    $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE kakao_id = ?');
    $stmt->execute([$kakaoId]);
    $user = $stmt->fetch();

    // If not found by kakao_id, check if existing account matches email
    if (!$user && $email) {
        $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            $pdo->prepare('UPDATE users SET kakao_id = ? WHERE id = ?')->execute([$kakaoId, $existing['id']]);
            $existing['kakao_id'] = $kakaoId;
            $user = $existing;
        }
    }

    // If still no user, create a new driver account
    if (!$user) {
        $driverName = $nickname ?: '카카오 드라이버';
        $userEmail = $email ?: ('kakao_' . substr($kakaoId, -6) . '@mylaplog.com');
        $avatar = mb_strtoupper(mb_substr($driverName, 0, 2));
        $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        // Ensure unique email
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$userEmail]);
        if ($stmt->fetch()) {
            $userEmail = 'kakao_' . $kakaoId . '@mylaplog.com';
        }

        $stmt = $pdo->prepare('INSERT INTO users (kakao_id, email, password_hash, name, avatar, driver_class) VALUES (?, ?, ?, ?, ?, "CLUBMAN")');
        $stmt->execute([$kakaoId, $userEmail, $dummyHash, $driverName, $avatar]);
        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    jsonResponse(200, ['message' => '카카오 로그인 성공', 'user' => $user]);
}

// POST /auth/google
if ($method === 'POST' && $uri === '/auth/google') {
    $body = getBody();
    $credential = trim($body['credential'] ?? '');
    $accessToken = trim($body['access_token'] ?? '');
    $authCode = trim($body['code'] ?? '');

    $googleId = null;
    $email = '';
    $name = '';

    // 1. Google Identity Services (GSI) credential (JWT ID Token)
    if ($credential) {
        // Option A: Verify with Google TokenInfo API
        $verifyRes = makeHttpRequest(
            'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential),
            'GET'
        );

        if ($verifyRes['status'] === 200 && !empty($verifyRes['body'])) {
            $tData = json_decode($verifyRes['body'], true);
            if (!empty($tData['sub'])) {
                $googleId = (string)$tData['sub'];
                $email = $tData['email'] ?? '';
                $name = $tData['name'] ?? ($tData['given_name'] ?? '');
            }
        }

        // Option B: Fallback decode JWT payload if tokeninfo network error
        if (!$googleId) {
            $parts = explode('.', $credential);
            if (count($parts) === 3) {
                $b64 = str_replace(['-', '_'], ['+', '/'], $parts[1]);
                $pad = strlen($b64) % 4;
                if ($pad > 0) {
                    $b64 .= str_repeat('=', 4 - $pad);
                }
                $payload = json_decode(base64_decode($b64), true);
                if (!empty($payload['sub'])) {
                    $googleId = (string)$payload['sub'];
                    $email = $payload['email'] ?? '';
                    $name = $payload['name'] ?? ($payload['given_name'] ?? '');
                }
            }
        }
    }

    // 2. Google OAuth Access Token
    if (!$googleId && $accessToken) {
        $userRes = makeHttpRequest(
            'https://www.googleapis.com/oauth2/v3/userinfo',
            'GET',
            null,
            ['Authorization: Bearer ' . $accessToken]
        );
        if ($userRes['status'] === 200 && !empty($userRes['body'])) {
            $uData = json_decode($userRes['body'], true);
            if (!empty($uData['sub'])) {
                $googleId = (string)$uData['sub'];
                $email = $uData['email'] ?? '';
                $name = $uData['name'] ?? '';
            }
        }
    }

    if (!$googleId) {
        jsonResponse(400, ['error' => 'Google 인증 정보 검증에 실패했습니다. (유효하지 않은 Google 토큰)']);
    }

    // Check if user with this google_id exists
    $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE google_id = ?');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    // If not found by google_id, check if existing account matches email
    if (!$user && $email) {
        $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$googleId, $existing['id']]);
            $existing['google_id'] = $googleId;
            $user = $existing;
        }
    }

    // If still no user, create a new driver account
    if (!$user) {
        $driverName = $name ?: 'Google Driver';
        $avatar = mb_strtoupper(mb_substr($driverName, 0, 2));
        $driverEmail = $email ?: ('google_' . substr($googleId, -6) . '@mylaplog.com');
        $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        // Ensure unique email
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$driverEmail]);
        if ($stmt->fetch()) {
            $driverEmail = 'google_' . $googleId . '@mylaplog.com';
        }

        $stmt = $pdo->prepare('INSERT INTO users (google_id, email, password_hash, name, avatar, driver_class) VALUES (?, ?, ?, ?, ?, "CLUBMAN")');
        $stmt->execute([$googleId, $driverEmail, $dummyHash, $driverName, $avatar]);
        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT id, email, name, avatar, driver_class, kara_license, is_admin, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];

    jsonResponse(200, ['message' => 'Google 로그인 성공', 'user' => $user]);
}

// =========================================================================
// 2. TRACKS ENDPOINT (CIRCUITS METADATA)
// =========================================================================

// GET /tracks
if ($method === 'GET' && $uri === '/tracks') {
    $stmt = $pdo->query('
        SELECT id, name, layout_name, length_meters, sector_count,
               country_code, country_flag, country_name,
               lat, lon, heading, pit_limit_kmh, pit_target_sec
        FROM tracks
        ORDER BY id ASC
    ');
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
        jsonResponse(400, ['error' => 'Please specify vehicle make and model.']);
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

    jsonResponse(201, ['message' => 'Vehicle registered successfully.', 'vehicle' => $vehicle]);
}

// =========================================================================
// 4. TRACK SESSIONS ENDPOINTS (LOG SESSION & SESSION LOGS)
// =========================================================================

// GET /sessions
if ($method === 'GET' && $uri === '/sessions') {
    $userId = getCurrentUserId();
    
    // Base SQL for full setup details
    $baseSql = '
        SELECT ts.*, t.name as track_name, COALESCE(NULLIF(ts.track_layout, ""), t.layout_name) as track_layout,
               t.country_code as track_country_code, t.country_flag as track_country_flag,
               t.country_name as track_country_name,
               TRIM(CONCAT(CASE WHEN v.make = "Track" THEN "" ELSE COALESCE(v.make, "") END, " ", COALESCE(v.model, ""))) as vehicle_name,
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
        LEFT JOIN tracks t ON t.id = ts.track_id
        LEFT JOIN vehicles v ON v.id = ts.vehicle_id
        LEFT JOIN users u ON u.id = ts.user_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
    ';

    if ($userId) {
        // Strictly logged-in user's own recorded sessions
        $stmt = $pdo->prepare($baseSql . ' WHERE ts.user_id = ? ORDER BY ts.session_date DESC, ts.session_time DESC, ts.id DESC');
        $stmt->execute([$userId]);
    } else {
        // Guest mode preview sessions
        $stmt = $pdo->prepare($baseSql . ' WHERE ts.visibility = "PUBLIC" ORDER BY ts.session_date DESC, ts.session_time DESC, ts.id DESC LIMIT 20');
        $stmt->execute();
    }
    $sessions = $stmt->fetchAll();

    // Attach lap times list to each session
    foreach ($sessions as &$s) {
        $lstmt = $pdo->prepare('SELECT id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_best FROM lap_times WHERE session_id = ? ORDER BY lap_number ASC');
        $lstmt->execute([$s['id']]);
        $s['laps'] = $lstmt->fetchAll();
        if (!empty($s['telemetry_json'])) {
            $s['telemetry'] = json_decode($s['telemetry_json'], true);
        } else {
            $s['telemetry'] = null;
        }
        $s['total_laps'] = !empty($s['total_laps']) ? (int)$s['total_laps'] : (!empty($s['laps']) ? count($s['laps']) : (int)($s['session_number'] ?? 1));
    }

    jsonResponse(200, ['sessions' => $sessions]);
}

// GET /sessions/:id
if ($method === 'GET' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $sessionId = (int)$m[1];

    $stmt = $pdo->prepare('
        SELECT ts.*, t.name as track_name, COALESCE(NULLIF(ts.track_layout, ""), t.layout_name) as track_layout,
               t.country_code as track_country_code, t.country_flag as track_country_flag,
               t.country_name as track_country_name,
               TRIM(CONCAT(CASE WHEN v.make = "Track" THEN "" ELSE COALESCE(v.make, "") END, " ", COALESCE(v.model, ""))) as vehicle_name,
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
        jsonResponse(404, ['error' => 'Session not found.']);
    }

    $lstmt = $pdo->prepare('SELECT id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_best FROM lap_times WHERE session_id = ? ORDER BY lap_number ASC');
    $lstmt->execute([$sessionId]);
    $session['laps'] = $lstmt->fetchAll();
    if (!empty($session['telemetry_json'])) {
        $session['telemetry'] = json_decode($session['telemetry_json'], true);
    } else {
        $session['telemetry'] = null;
    }
    $session['total_laps'] = !empty($session['total_laps']) ? (int)$session['total_laps'] : (!empty($session['laps']) ? count($session['laps']) : (int)($session['session_number'] ?? 1));

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
        $vName = preg_replace('/^Track\s+/i', '', $vName);
        $parts = preg_split('/\s+/', $vName, 2);
        $vMake = count($parts) > 1 ? $parts[0] : '';
        $vModel = count($parts) > 1 ? $parts[1] : $parts[0];

        $chk = $pdo->prepare('SELECT id FROM vehicles WHERE user_id = ? AND TRIM(CONCAT(COALESCE(make, ""), " ", COALESCE(model, ""))) = ? LIMIT 1');
        $chk->execute([$userId, $vName]);
        $found = $chk->fetch();
        if ($found) {
            $vehicleId = (int)$found['id'];
        } else {
            $ins = $pdo->prepare('INSERT INTO vehicles (user_id, make, model, year, tire_model) VALUES (?, ?, ?, 2024, "Sport Semi-Slick")');
            $ins->execute([$userId, $vMake, $vModel]);
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
    $sessionTime = !empty($body['session_time']) ? $body['session_time'] : date('H:i:s');
    $sessionNumber = (int)($body['session_number'] ?? 1);
    $weather = $body['weather_condition'] ?? 'DRY';
    $airTemp = isset($body['air_temp']) && $body['air_temp'] !== '' ? $body['air_temp'] : 24.0;
    $trackTemp = isset($body['track_temp']) && $body['track_temp'] !== '' ? $body['track_temp'] : 38.0;
    $topSpeed = isset($body['top_speed_kmh']) && $body['top_speed_kmh'] !== '' ? $body['top_speed_kmh'] : null;

    $trackLayout = !empty($body['track_layout']) ? trim($body['track_layout']) : null;

    $telemetryJson = null;
    if (!empty($body['telemetry'])) {
        $telemetryJson = is_string($body['telemetry']) ? $body['telemetry'] : json_encode($body['telemetry'], JSON_UNESCAPED_UNICODE);
    } else if (!empty($body['telemetry_json'])) {
        $telemetryJson = (string)$body['telemetry_json'];
    }
    $vboContent = !empty($body['vbo_content']) ? (string)$body['vbo_content'] : null;
    $totalLaps = !empty($body['total_laps']) ? (int)$body['total_laps'] : (!empty($laps) ? count($laps) : 1);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO track_sessions (
                user_id, vehicle_id, track_id, track_layout, session_date, session_time, session_number,
                air_temp, track_temp, weather_condition, visibility, best_lap_ms, top_speed_kmh,
                telemetry_json, vbo_content, total_laps
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PUBLIC", ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId, $vehicleId, $trackId, $trackLayout, $sessionDate, $sessionTime, $sessionNumber,
            $airTemp, $trackTemp, $weather, $bestLapMs, $topSpeed,
            $telemetryJson, $vboContent, $totalLaps
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
        jsonResponse(201, ['message' => 'Session logged successfully! 🏎️', 'session_id' => $sessionId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => 'Failed to save session: ' . $e->getMessage()]);
    }
}

// PUT /sessions/:id (Edit session, 4-wheel psi & notes)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $sessionId = (int)$m[1];
    $userId = getCurrentUserId();
    $body = getBody();

    // Check ownership if user is logged in
    $chkSess = $pdo->prepare('SELECT id, user_id FROM track_sessions WHERE id = ?');
    $chkSess->execute([$sessionId]);
    $existingSess = $chkSess->fetch();

    if ($existingSess && $userId) {
        $isAdmin = false;
        $uStmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['is_admin'])) {
            $isAdmin = true;
        }
        if (!$isAdmin && (int)$existingSess['user_id'] !== $userId) {
            jsonResponse(403, ['error' => 'You can only edit your own sessions.']);
        }
    }

    $pdo->beginTransaction();
    try {
        // Update track_sessions fields if present
        $updates = [];
        $params = [];
        if (isset($body['track_id'])) { $updates[] = 'track_id = ?'; $params[] = (int)$body['track_id']; }
        if (isset($body['track_layout'])) { $updates[] = 'track_layout = ?'; $params[] = trim($body['track_layout']); }
        if (!empty($body['vehicle_name'])) {
            $vName = trim($body['vehicle_name']);
            $vName = preg_replace('/^Track\s+/i', '', $vName);
            $parts = preg_split('/\s+/', $vName, 2);
            $vMake = count($parts) > 1 ? $parts[0] : '';
            $vModel = count($parts) > 1 ? $parts[1] : $parts[0];

            $curStmt = $pdo->prepare('SELECT vehicle_id FROM track_sessions WHERE id = ?');
            $curStmt->execute([$sessionId]);
            $curVehicleId = $curStmt->fetchColumn();

            if ($curVehicleId) {
                $updV = $pdo->prepare('UPDATE vehicles SET make = ?, model = ? WHERE id = ?');
                $updV->execute([$vMake, $vModel, $curVehicleId]);
                $vehicleId = (int)$curVehicleId;
            } else {
                $userId = getCurrentUserId() ?: 1;
                $ins = $pdo->prepare('INSERT INTO vehicles (user_id, make, model, year, tire_model) VALUES (?, ?, ?, 2024, "Sport Semi-Slick")');
                $ins->execute([$userId, $vMake, $vModel]);
                $vehicleId = (int)$pdo->lastInsertId();
            }
            $updates[] = 'vehicle_id = ?';
            $params[] = $vehicleId;
        }
        if (isset($body['session_date'])) { $updates[] = 'session_date = ?'; $params[] = $body['session_date']; }
        if (isset($body['session_time'])) { $updates[] = 'session_time = ?'; $params[] = $body['session_time']; }
        if (isset($body['session_number'])) { $updates[] = 'session_number = ?'; $params[] = (int)$body['session_number']; }
        if (isset($body['weather_condition'])) { $updates[] = 'weather_condition = ?'; $params[] = $body['weather_condition']; }
        if (isset($body['air_temp'])) { $updates[] = 'air_temp = ?'; $params[] = $body['air_temp']; }
        if (isset($body['track_temp'])) { $updates[] = 'track_temp = ?'; $params[] = $body['track_temp']; }
        if (isset($body['best_lap_ms'])) { $updates[] = 'best_lap_ms = ?'; $params[] = (int)$body['best_lap_ms']; }
        if (isset($body['top_speed_kmh'])) { $updates[] = 'top_speed_kmh = ?'; $params[] = $body['top_speed_kmh']; }
        if (isset($body['telemetry'])) {
            $updates[] = 'telemetry_json = ?';
            $params[] = is_string($body['telemetry']) ? $body['telemetry'] : json_encode($body['telemetry'], JSON_UNESCAPED_UNICODE);
        } else if (isset($body['telemetry_json'])) {
            $updates[] = 'telemetry_json = ?';
            $params[] = (string)$body['telemetry_json'];
        }
        if (isset($body['vbo_content'])) {
            $updates[] = 'vbo_content = ?';
            $params[] = (string)$body['vbo_content'];
        }
        if (isset($body['total_laps'])) {
            $updates[] = 'total_laps = ?';
            $params[] = (int)$body['total_laps'];
        }

        // Check if session exists in DB (might be sample session id)
        $chkSess = $pdo->prepare('SELECT id FROM track_sessions WHERE id = ?');
        $chkSess->execute([$sessionId]);
        if (!$chkSess->fetch()) {
            // Insert as new track_session if updating a sample/guest session
            $insSess = $pdo->prepare('
                INSERT INTO track_sessions (
                    id, user_id, vehicle_id, track_id, track_layout, session_date, session_time, session_number,
                    air_temp, track_temp, weather_condition, visibility, best_lap_ms, top_speed_kmh,
                    telemetry_json, vbo_content, total_laps
                ) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PUBLIC", ?, ?, ?, ?, ?)
            ');
            $telemetryJson = null;
            if (!empty($body['telemetry'])) {
                $telemetryJson = is_string($body['telemetry']) ? $body['telemetry'] : json_encode($body['telemetry'], JSON_UNESCAPED_UNICODE);
            } else if (!empty($body['telemetry_json'])) {
                $telemetryJson = (string)$body['telemetry_json'];
            }
            $vboContent = !empty($body['vbo_content']) ? (string)$body['vbo_content'] : null;
            $totalLaps = !empty($body['total_laps']) ? (int)$body['total_laps'] : 1;
            $insSess->execute([
                $sessionId,
                $vehicleId ?? 1,
                (int)($body['track_id'] ?? 1),
                !empty($body['track_layout']) ? trim($body['track_layout']) : null,
                $body['session_date'] ?? date('Y-m-d'),
                $body['session_time'] ?? date('H:i:s'),
                (int)($body['session_number'] ?? 1),
                $body['air_temp'] ?? 24.0,
                $body['track_temp'] ?? 38.0,
                $body['weather_condition'] ?? 'DRY',
                (int)($body['best_lap_ms'] ?? 0),
                $body['top_speed_kmh'] ?? null,
                $telemetryJson,
                $vboContent,
                $totalLaps
            ]);
        } else if (!empty($updates)) {
            $params[] = $sessionId;
            $stmt = $pdo->prepare('UPDATE track_sessions SET ' . implode(', ', $updates) . ' WHERE id = ?');
            $stmt->execute($params);
        }

        // Update or insert vehicle_setups
        $setup = $body['setup'] ?? [];
        if (!empty($setup) || isset($body['driver_notes'])) {
            $notes = $setup['driver_notes'] ?? ($body['driver_notes'] ?? null);
            $chkSetup = $pdo->prepare('SELECT id FROM vehicle_setups WHERE session_id = ?');
            $chkSetup->execute([$sessionId]);

            if ($chkSetup->fetch()) {
                $stmt = $pdo->prepare('
                    UPDATE vehicle_setups SET
                        cold_psi_fl = ?, cold_psi_fr = ?, cold_psi_rl = ?, cold_psi_rr = ?,
                        hot_psi_fl = ?, hot_psi_fr = ?, hot_psi_rl = ?, hot_psi_rr = ?,
                        damper_front_clicks = ?, damper_rear_clicks = ?,
                        driver_notes = ?
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
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO vehicle_setups (
                        session_id,
                        cold_psi_fl, cold_psi_fr, cold_psi_rl, cold_psi_rr,
                        hot_psi_fl, hot_psi_fr, hot_psi_rl, hot_psi_rr,
                        damper_front_clicks, damper_rear_clicks,
                        driver_notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                    $notes
                ]);
            }
        }

        // Update lap_times best lap if present
        if (!empty($body['best_lap_ms'])) {
            $chkLaps = $pdo->prepare('SELECT id FROM lap_times WHERE session_id = ?');
            $chkLaps->execute([$sessionId]);
            if ($chkLaps->fetch()) {
                $pdo->prepare('UPDATE lap_times SET is_best = 0 WHERE session_id = ?')->execute([$sessionId]);
                $pdo->prepare('UPDATE lap_times SET lap_time_ms = ?, is_best = 1 WHERE session_id = ? ORDER BY id ASC LIMIT 1')->execute([(int)$body['best_lap_ms'], $sessionId]);
            }
        }

        $pdo->commit();
        jsonResponse(200, ['message' => 'Session updated successfully.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => 'Failed to update session: ' . $e->getMessage()]);
    }
}

// DELETE /sessions/:id
if ($method === 'DELETE' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $sessionId = (int)$m[1];
    $userId = getCurrentUserId();

    $chk = $pdo->prepare('SELECT id, user_id FROM track_sessions WHERE id = ?');
    $chk->execute([$sessionId]);
    $sess = $chk->fetch();

    if (!$sess) {
        jsonResponse(404, ['error' => 'Session not found.']);
    }

    if ($userId) {
        $isAdmin = false;
        $uStmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['is_admin'])) {
            $isAdmin = true;
        }
        if (!$isAdmin && (int)$sess['user_id'] !== $userId) {
            jsonResponse(403, ['error' => 'You can only delete your own sessions.']);
        }
    }

    $pdo->prepare('DELETE FROM vehicle_setups WHERE session_id = ?')->execute([$sessionId]);
    $pdo->prepare('DELETE FROM lap_times WHERE session_id = ?')->execute([$sessionId]);
    $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    jsonResponse(200, ['message' => 'Session deleted successfully.']);
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
        jsonResponse(404, ['error' => 'Guide not found.']);
    }

    // Increment views
    $pdo->prepare('UPDATE guides SET views = views + 1 WHERE id = ?')->execute([$guide['id']]);
    $guide['views'] = (int)$guide['views'] + 1;

    jsonResponse(200, ['guide' => $guide]);
}

// 404 Fallback
jsonResponse(404, ['error' => 'Not Found', 'endpoint' => $uri, 'method' => $method]);
