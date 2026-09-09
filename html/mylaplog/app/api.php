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

    // Ensure team_invitations table exists
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS team_invitations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            team_id BIGINT NOT NULL,
            inviter_id BIGINT NOT NULL,
            invitee_id BIGINT NOT NULL,
            role VARCHAR(20) DEFAULT "DRIVER",
            status VARCHAR(20) DEFAULT "PENDING",
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    ');

    // Ensure feedbacks table exists
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS feedbacks (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NULL,
            user_name VARCHAR(100) NOT NULL,
            user_email VARCHAR(191) NOT NULL,
            type VARCHAR(30) DEFAULT "FEATURE",
            title VARCHAR(200) NOT NULL,
            content TEXT NOT NULL,
            priority VARCHAR(20) DEFAULT "NORMAL",
            status VARCHAR(30) DEFAULT "PENDING",
            admin_response TEXT NULL,
            upvotes INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB;
    ');

    // Ensure team_messages table exists
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS team_messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            team_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            user_avatar VARCHAR(10) DEFAULT "DR",
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_team_id_created (team_id, created_at),
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    ');

    // Update existing users driver_class to VIP
    $pdo->exec("UPDATE users SET driver_class = 'VIP' WHERE driver_class IS NULL OR driver_class != 'VIP'");

    // Update existing team roles from CHIEF to ADMIN
    $pdo->exec("UPDATE team_members SET role = 'ADMIN' WHERE role = 'CHIEF'");
    $pdo->exec("UPDATE team_invitations SET role = 'ADMIN' WHERE role = 'CHIEF'");

    // Ensure kakao_id column exists in users table
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS kakao_id VARCHAR(100) NULL UNIQUE AFTER email");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN kakao_id VARCHAR(100) NULL UNIQUE AFTER email");
        } catch (Exception $e2) {}
    }

    // Ensure is_admin column exists in users table
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) DEFAULT 0 AFTER driver_class");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0 AFTER driver_class");
        } catch (Exception $e2) {}
    }

    // Ensure top_speed_kmh column exists in track_sessions table
    try {
        $pdo->exec("ALTER TABLE track_sessions ADD COLUMN IF NOT EXISTS top_speed_kmh DECIMAL(5,1) NULL DEFAULT NULL AFTER best_lap_ms");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE track_sessions ADD COLUMN top_speed_kmh DECIMAL(5,1) NULL DEFAULT NULL AFTER best_lap_ms");
        } catch (Exception $e2) {}
    }

    // Ensure guides table exists
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS guides (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(191) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(50) DEFAULT "셋업 노하우",
            excerpt TEXT NULL,
            content MEDIUMTEXT NOT NULL,
            cover_image VARCHAR(500) NULL,
            author_name VARCHAR(100) DEFAULT "MyLapLog 인텔리전스",
            read_time VARCHAR(20) DEFAULT "3분",
            status VARCHAR(20) DEFAULT "PUBLISHED",
            views INT DEFAULT 0,
            is_featured TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_guides_status (status),
            INDEX idx_guides_category (category),
            INDEX idx_guides_created (created_at)
        ) ENGINE=InnoDB;
    ');

    // Seed initial high-quality guides if empty
    $guideCount = (int)$pdo->query('SELECT COUNT(*) FROM guides')->fetchColumn();
    if ($guideCount === 0) {
        $seedGuides = [
            [
                'slug' => 'injespeedium-racing-guide',
                'title' => '[서킷 공략] 인제 스피디움 첫 주행: 코너별 기어 단수 & 브레이킹 포인트 기초 가이드',
                'category' => '서킷 공략',
                'excerpt' => '강원도 인제 스피디움(풀코스 3.908km) 첫 입문자를 위한 40m 고저차 대응법, 헤어핀 탈출 기어비, 그리고 1초를 줄이는 1번 코너 하드 브레이킹 포인트를 완벽 정리합니다.',
                'content' => "## 1. 인제 스피디움 서킷 개요\n인제 스피디움은 총 길이 **3.908km**, 19개의 테크니컬 코너와 **고저차 40m**를 자랑하는 아시아 최고의 롤러코스터 서킷입니다. 오르막과 내리막에 따라 하중 이동 폭이 매우 크므로 브레이킹 안정성과 댐퍼 리바운드 세팅이 승부를 가릅니다.\n\n### 서킷 제원 요약\n- **총 연장**: 3,908m (풀코스 기준)\n- **코너 수**: 19개 (좌 8 / 우 11)\n- **메인 스트레이트**: 약 640m (최고속 190~230km/h 도달)\n- **주요 난구간**: 1번 다운힐 하드 브레이킹, 7~8번 블라인드 복합 코너, 18~19번 고속 연석 구간\n\n---\n\n## 2. 주요 코너별 상세 공략법\n\n### ① 1번 ~ 2번 코너 (Main Straight Downhill)\n- **진입 속도**: 4~5단 전개 후 시속 190km 이상\n- **브레이킹 포인트**: 우측 150m 표지판부터 점진적 압력, 100m 지점부터 풀 브레이킹(Threshold Braking)\n- **기어 단수**: 3단 (수동/DCT 공통)\n- **핵심 팁**: 내리막 하중이 앞바퀴로 급격히 쏠리므로 스티어링을 급격히 꺾으면 바로 ABS가 개입하며 언더스티어가 납니다. 브레이크 트레일을 부드럽게 풀면서 에이펙스를 깊게 찍으세요.\n\n### ② 3번 ~ 6번 S자 다운힐 복합 구간\n- **공략 라인**: 3번 코너 탈출 직후 차체를 좌측으로 완전히 붙여 4번 우측 코너 진입 시야를 확보합니다.\n- **하중 이동**: 가속 페달을 살짝 오프(Lift-off)하여 앞바퀴 그립을 회복시키고, 연석을 부드럽게 타고 넘는 리듬감이 중요합니다.\n- **감쇠력 추천**: 프론트 범프가 너무 단단하면 연석 충격으로 차체가 튕겨나갈 수 있으므로 부드러운 셋업이 유리합니다.\n\n### ③ 7번 ~ 8번 오르막 블라인드 헤어핀\n- **기어 단수**: 2단 또는 3단 토크 밴드 활용\n- **주의점**: 언덕 꼭대기 너머 에이펙스가 보이지 않는 블라인드 코너입니다. 시선을 멀리 두고 코너 안쪽 연석 끝을 상상하며 진입해야 오버런을 방지할 수 있습니다.\n\n---\n\n## 3. 추천 공기압 & 셋업 체크리스트\n- **타이어 공기압**: 첫 주행 전 냉간(Cold) **26~28 PSI** 시작 추천 (피트인 직후 열간 34~35 PSI 목표)\n- **얼라인먼트**: 프론트 캠버 **-2.5° ~ -3.0°**, 토우(Toe) 0mm 또는 약한 토아웃\n- **주행 후 점검**: 세션 종료 즉시 4륜 열간 공기압 및 휠너트 토크(110~130Nm) 재체크 필수!",
                'cover_image' => '',
                'author_name' => 'MyLapLog 인텔리전스',
                'read_time' => '4분',
                'status' => 'PUBLISHED',
                'views' => 128,
                'is_featured' => 1
            ],
            [
                'slug' => 'tire-cold-hot-pressure-master',
                'title' => '[타이어/공기압] 트랙데이 필수! 냉간(Cold) vs 열간(Hot) 공기압 세팅의 모든 것',
                'category' => '타이어/공기압',
                'excerpt' => '트랙데이에서 가장 저렴하고 확실하게 랩타임을 줄이는 튜닝은 공기압입니다. 왜 일상 36psi로 타면 미끄러지는지, 주행 후 피트인 시 적정 열간 공기압 관리 공식을 공개합니다.',
                'content' => "## 1. 일상 주행 공기압으로 서킷을 타면 안 되는 이유\n대부분의 공도용 차량은 냉간 **34~36 PSI**를 권장합니다. 하지만 이 상태로 서킷에 들어가 3랩 이상 어택을 진행하면 타이어 내부 온도가 **80°C~90°C**까지 치솟으며 공기압이 **42~45 PSI**를 초과하게 됩니다.\n\n### 공기압 과다 시 발생하는 현상\n- 타이어 트레드 중앙부만 볼록하게 부풀어 **접지 면적(Contact Patch) 25% 이상 감소**\n- 코너링 중 사이드월 지지가 무너지며 극심한 그레인(Graining) 및 언더스티어 발생\n- 제동 거리가 10~15% 늘어나며 랩타임이 최소 1~2초 이상 저하\n\n---\n\n## 2. 하이그립 타이어별 목표 열간 공기압 (Target Hot PSI)\n\n서킷 피트인 직후 에어게이지로 측정했을 때 나와야 하는 이상적인 수치입니다:\n\n| 타이어 카테고리 | 대표 모델 (Sur4G, V730, RE71RS 등) | 목표 열간(Hot) PSI | 추천 초기 냉간(Cold) PSI |\n| :--- | :--- | :--- | :--- |\n| **세미슬릭 / 하이그립** | 금호 V730, 넥센 SUR4G | **32 ~ 34 PSI** | 25 ~ 27 PSI |\n| **초고성능 스포츠** | 미쉐린 Cup2, 브리지스톤 RE-71RS | **33 ~ 35 PSI** | 26 ~ 28 PSI |\n| **스트리트 스포츠** | 미쉐린 PS4S, 한국 S1 evo3 | **34 ~ 36 PSI** | 27 ~ 29 PSI |\n\n> 💡 **참고**: 무더운 한여름(노면온도 45°C 이상)에는 팽창 폭이 커지므로 냉간 시 1~2 PSI를 더 낮추어 출발해야 피트인 후 오버프레셔를 방지할 수 있습니다.\n\n---\n\n## 3. 서킷 현장 공기압 실전 세팅 프로세스\n\n1. **서킷 도착 직후 (냉간)**:\n   - 4바퀴 모두 27~28 PSI로 균일하게 맞춥니다.\n2. **첫 세션 (Out-lap + 3 Lap Attack + In-lap)**:\n   - 피트에 들어오자마자 시동을 끄지 않고 **즉시 에어게이지로 4륜 측정**.\n   - 이때 36~38 PSI까지 올라와 있을 것입니다. 즉시 밸브를 눌러 **목표치(예: 33 PSI)**까지 공기를 빼줍니다.\n3. **MyLapLog에 실시간 로깅**:\n   - 측정한 냉간/열간 PSI를 MyLapLog 세션 로그에 바로 기록해두면, 다음 트랙데이 방문 시 기온에 따른 최적 기준 데이터를 바로 불러올 수 있습니다.",
                'cover_image' => '',
                'author_name' => 'MyLapLog 인텔리전스',
                'read_time' => '3분',
                'status' => 'PUBLISHED',
                'views' => 245,
                'is_featured' => 0
            ],
            [
                'slug' => 'understeer-camber-damper-setup',
                'title' => '[셋업 노하우] 언더스티어가 심할 때: 프론트 캠버와 댐퍼 감쇠력 조율법',
                'category' => '셋업 노하우',
                'excerpt' => '코너 진입 시 앞머리가 바깥으로 밀려나가는 언더스티어! 타이어 바깥쪽 숄더 마모 분석부터 프론트 네거티브 캠버각과 일체형 서스펜션 감쇠력 클릭 조율 순서를 명쾌하게 정리합니다.',
                'content' => "## 1. 언더스티어의 3가지 유형 분석\n차량이 밀려나간다고 무작정 서스펜션 감쇠력만 단단하게 조이는 것은 역효과를 냅니다. 언더스티어가 코너 어느 구간에서 발생하는지 먼저 진단해야 합니다.\n\n1. **진입 언더 (Entry Under)**: 브레이킹을 끝내고 스티어링을 꺾는 순간 발생 (원인: 앞바퀴 하중 부족 또는 과도한 진입 속도)\n2. **중간 에이펙스 언더 (Mid-Corner Under)**: 횡G가 가장 높은 코너 정점에서 발생 (원인: 프론트 타이어 접지면적 부족, 캠버각 부족)\n3. **탈출 파워언더 (Exit Under)**: 가속 페달을 밟으며 코너를 빠져나갈 때 발생 (원인: 전륜 구동 가속 시 프론트 리프트, LSD 세팅 미흡)\n\n---\n\n## 2. 해결책 1: 프론트 네거티브 캠버(Camber) 확대\n순정 승용차량은 타이어 편마모를 방지하기 위해 캠버각이 `-0.5° ~ -1.0°` 수준으로 세팅되어 있습니다. 하지만 서킷 주행 시 심한 롤(Roll)로 인해 타이어 바깥쪽 숄더(Shoulder)만 노면에 닿아 미끄러집니다.\n\n- **서킷 추천 프론트 캠버**: **-2.5° ~ -3.2°**\n- **리어 캠버**: 프론트보다 약 0.5°~1.0° 덜 누운 **-1.8° ~ -2.2°** 추천\n- **확인 방법**: 주행 후 타이어 숄더의 작은 삼각형 마크(△) 바로 위까지만 마모되어 있다면 완벽한 캠버 세팅입니다.\n\n---\n\n## 3. 해결책 2: 댐퍼 감쇠력(Damper Clicks) 튜닝 공식\n일체형 서스펜션(코일오버)의 조절 다이얼을 활용하여 하중 이동 속도를 제어합니다:\n\n- **전륜(Front) 감쇠력**: 1~2클릭 부드럽게 (Soft) 풉니다. → 코너 진입 시 앞쪽으로 하중이 더 깊고 부드럽게 실려 앞바퀴 그립이 증가합니다.\n- **후륜(Rear) 감쇠력**: 1~2클릭 단단하게 (Hard) 조입니다. → 후륜 하중 이동 저항이 커져 리어가 자연스럽게 바깥으로 돌아주며(Turn-in 회두성 증가) 언더스티어가 상쇄됩니다.\n\n> ⚠️ **주의**: 한 번에 4~5클릭씩 바꾸지 마세요. MyLapLog에 현재 세팅(예: Front 8클릭 / Rear 6클릭)을 기록한 뒤, 세션마다 **1~2클릭씩만 변경하며 랩타임 추이를 확인**해야 최적점을 찾을 수 있습니다.",
                'cover_image' => '',
                'author_name' => 'MyLapLog 인텔리전스',
                'read_time' => '5분',
                'status' => 'PUBLISHED',
                'views' => 192,
                'is_featured' => 0
            ],
            [
                'slug' => 'trackday-pit-operation-roadmap',
                'title' => '[트랙데이 팁] MyLapLog 실전 로드맵: 가입부터 피트스탑 셋업 수정까지 6단계 완벽 가이드',
                'category' => '트랙데이 팁',
                'excerpt' => '처음 mylaplog.com에 가입한 드라이버가 팀을 생성하고, 머신 스펙을 등록하고, 트랙데이 당일 4륜 냉간/열간 공기압과 댐퍼 셋업을 기록·수정하여 랩타임을 단축하는 6단계 실전 로드맵을 공개합니다.',
                'content' => "## 1. 모터스포츠 데이터 관리의 시작\n\"기록되지 않은 주행은 발전하지 않습니다.\"\nMyLapLog는 단순한 랩타이머를 넘어 드라이버의 감각(Sensory)과 차량의 기계적 셋업(Hardware), 그리고 실시간 주행 데이터(Data)를 하나로 연결하는 모터스포츠 인텔리전스 플랫폼입니다.\n\n---\n\n## 2. 실전 6단계 운용 워크플로우\n\n### [1단계] 가입 & 모바일 환경 구축 (Sign-Up & Setup)\n- **계정 생성**: mylaplog.com에서 이메일로 간편 가입\n- **프로필 설정**: 닉네임, 드라이버 클래스(PRO-AM, CLUBMAN 등), 보유 라이선스 등록\n- **홈 화면에 PWA 앱 설치**: 아이폰(사파리) 공유 > 홈 화면에 추가 / 갤럭시(크롬) 메뉴 > 앱 설치\n\n### [2단계] 레이싱 팀 생성 (Create Team)\n- 좌측 메뉴 [팀 허브] > [+ 새 팀 생성] 클릭\n- 팀 이름, 홈 서킷(인제/영암 등), 팀 소개 입력 후 **6자리 고유 초대 코드** 발급\n\n### [3단계] 디지털 개러지 차량 등록 (Garage Registration)\n- [개러지] 탭 > [+ 차량 추가하기] 클릭\n- 제조사, 차종, 연식, 최고출력(hp) 및 소속 팀 지정\n- **핵심 하체 스펙 등록**: 장착 타이어 모델(Sur4G, V730 등), 전/후륜 규격, 코일오버 제조사 및 기본 감쇠력 클릭\n\n### [4단계] 팀원 가입 요청 및 초청 (Team Member Invitation)\n- 팀 초대 코드를 동료 드라이버, 미캐닉, 크루에게 공유\n- 팀원이 [팀 참가하기]에서 코드 입력 후 가입 요청 > 팀장이 승인 및 역할(DRIVER, MECHANIC) 배정\n\n### [5단계] 트랙데이 당일 세션로그 생성 (Session Log Creation)\n- 피트 도착 후 [세션로그] > [+ 세션 기록하기] 클릭\n- 출전 차량, 서킷(인제 풀코스, 영암 상설 등), 기온 및 트랙 상태 체크\n- **세션 전 Cold Check**: 4륜 냉간 공기압(FL, FR, RL, RR) 및 초기 댐퍼 감쇠력 클릭 기입\n- [스톱워치/타이머] 실행 후 스마트폰 거치대에 장착하고 실시간 랩타임 측정 주행\n\n### [6단계] 피트인 직후 세션로그 수정 & 디브리핑 (Update & Debrief)\n- **골든타임 1분**: 피트인 직후 타이어가 식기 전에 4륜 **열간 공기압(Hot PSI)** 즉시 실측\n- 주행한 세션 카드의 [수정] 클릭 후 열간 공기압 실측치 업데이트\n- **드라이버 노트(Notes)** 메모: 언더/오버스티어, 연석 탈출 트랙션 체감 기록\n- 공기압 과열 시 1~2psi 감압하거나 댐퍼 감쇠력 1클릭 미세 조율 후 다음 세션 준비!",
                'cover_image' => '',
                'author_name' => 'MyLapLog 인텔리전스',
                'read_time' => '4분',
                'status' => 'PUBLISHED',
                'views' => 156,
                'is_featured' => 0
            ]
        ];

        $ins = $pdo->prepare('
            INSERT INTO guides (slug, title, category, excerpt, content, cover_image, author_name, read_time, status, views, is_featured)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($seedGuides as $g) {
            $ins->execute([
                $g['slug'], $g['title'], $g['category'], $g['excerpt'], $g['content'],
                $g['cover_image'], $g['author_name'], $g['read_time'], $g['status'], $g['views'], $g['is_featured']
            ]);
        }
    }
} catch (PDOException $e) {
    jsonResponse(500, ['error' => 'Database connection failed: ' . $e->getMessage()]);
}

// --- Extended Secure Session Configuration (세션 쿠키 수명 및 서버 GC 수명 연장) ---
$SESSION_LIFETIME = 60 * 60 * 24 * 30; // 30일 (2,592,000초 - 브라우저 재시작 후에도 로그인 유지)

// 1. 서버 측 Garbage Collection 수명 연장
ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

// 2. 데비안/리눅스 기본 24분 강제 세션 삭제(phpsessionclean) 방지를 위한 전용 세션 저장소 설정
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

// 3. 브라우저 세션 쿠키 수명 연장
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

// 4. 활성 세션 쿠키 슬라이딩 갱신 (사용자가 활동할 때마다 유효기간을 30일로 연장)
if (!empty($_SESSION['user_id']) && isset($_COOKIE[session_name()])) {
    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => time() + $SESSION_LIFETIME,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

// Admin Security Configuration
$ADMIN_MASTER_KEY = 'mylaplog2026!'; // Admin System Master Access Key

// --- Router ---
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip /api prefix or /.../api.php for routing
$uri = preg_replace('#^.*?/api(?:\.php)?#', '', $uri);
if ($uri === '' || $uri === false) {
    $uri = '/';
}

// Helper: JSON Response
function jsonResponse($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getBody() {
    $raw = file_get_contents('php://input');
    if ($raw !== false) {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    }
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

// Helper: Require Admin Auth
function requireAdminAuth() {
    global $pdo;
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
        return true;
    }
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        if ((int)$stmt->fetchColumn() === 1) {
            $_SESSION['is_admin'] = true;
            return true;
        }
    }
    jsonResponse(403, ['error' => '관리자 전용 접근 권한이 필요합니다.']);
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

// Kakao API Configuration
$KAKAO_REST_KEY = '0205ac0d0d3d23cbcf55c6359e75146c';
$KAKAO_JS_KEY   = '6c14bacdc52718fa93aaeae500a4d106';

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => $res, 'status' => $httpCode];
    }

    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 8,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
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

// POST /auth/kakao
if ($method === 'POST' && $uri === '/auth/kakao') {
    $body = getBody();
    $accessToken = trim($body['access_token'] ?? '');
    $authCode = trim($body['code'] ?? '');
    $redirectUri = trim($body['redirect_uri'] ?? 'https://app.mylaplog.com/app/');
    
    // If authCode was passed instead of access_token, exchange code for token
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
                    'redirect_uri' => $redirectUri,
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
        if (!$accessToken && $lastErr) {
            jsonResponse(400, ['error' => '카카오 토큰 발급 실패: ' . $lastErr]);
        }
    }

    $kakaoId = null;
    $nickname = '';
    $email = '';
    $profileImage = '';

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
                $profileImage = $kakaoAccount['profile']['profile_image_url'] ?? $properties['profile_image'] ?? '';
            }
        } else if (!empty($userRes['body'])) {
            $kData = json_decode($userRes['body'], true);
            $errDetail = $kData['msg'] ?? ('HTTP ' . $userRes['status']);
            jsonResponse(400, ['error' => '카카오 사용자 정보 조회 실패: ' . $errDetail]);
        }
    }

    if (!$kakaoId) {
        jsonResponse(400, ['error' => '카카오 인증 정보 검증에 실패했습니다. (유효하지 않은 카카오 토큰)']);
    }

    // 1. Check if user with this kakao_id exists
    $stmt = $pdo->prepare('SELECT id, email, name, kara_license, driver_class, avatar, kakao_id, created_at FROM users WHERE kakao_id = ?');
    $stmt->execute([$kakaoId]);
    $user = $stmt->fetch();

    // 2. If not found by kakao_id, check if existing account matches email
    if (!$user && $email) {
        $stmt = $pdo->prepare('SELECT id, email, name, kara_license, driver_class, avatar, kakao_id, created_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            $stmt = $pdo->prepare('UPDATE users SET kakao_id = ? WHERE id = ?');
            $stmt->execute([$kakaoId, $existing['id']]);
            $existing['kakao_id'] = $kakaoId;
            $user = $existing;
        }
    }

    // 3. If still no user, create a new driver account
    if (!$user) {
        $name = $nickname ?: '카카오 드라이버';
        $avatar = mb_strtoupper(mb_substr($name, 0, 2));
        $driverEmail = $email ?: ('kakao_' . substr($kakaoId, -6) . '@mylaplog.com');
        $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        // Ensure unique email
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$driverEmail]);
        if ($stmt->fetch()) {
            $driverEmail = 'kakao_' . $kakaoId . '@mylaplog.com';
        }

        $stmt = $pdo->prepare('INSERT INTO users (kakao_id, email, password_hash, name, kara_license, driver_class, avatar) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$kakaoId, $driverEmail, $dummyHash, $name, 'Circuit License', 'VIP', $avatar]);
        $userId = $pdo->lastInsertId();

        $user = [
            'id' => (int)$userId,
            'kakao_id' => $kakaoId,
            'email' => $driverEmail,
            'name' => $name,
            'kara_license' => 'Circuit License',
            'driver_class' => 'VIP',
            'avatar' => $avatar
        ];
    }

    $_SESSION['user_id'] = $user['id'];
    jsonResponse(200, ['message' => '카카오 로그인 성공', 'user' => $user]);
}

// POST /auth/register
if ($method === 'POST' && $uri === '/auth/register') {
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $karaLicense = $body['kara_license'] ?? 'Circuit License';
    $driverClass = $body['driver_class'] ?? 'VIP';

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
    jsonResponse(200, ['message' => '로그아웃 완료']);
}

// DELETE /auth/delete-account (인증된 세션 기반 즉시 계정 및 연관 데이터 영구 삭제)
if ($method === 'DELETE' && $uri === '/auth/delete-account') {
    $userId = requireAuth();

    try {
        $pdo->beginTransaction();

        // 1. Delete session setups & track sessions
        $stmt = $pdo->prepare('DELETE FROM session_setups WHERE session_id IN (SELECT id FROM track_sessions WHERE user_id = ?)');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);

        // 2. Delete vehicles
        $stmt = $pdo->prepare('DELETE FROM vehicles WHERE user_id = ?');
        $stmt->execute([$userId]);

        // 3. Delete team memberships, invitations, and messages
        $stmt = $pdo->prepare('DELETE FROM team_members WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM team_invitations WHERE inviter_id = ? OR invitee_id = ?');
        $stmt->execute([$userId, $userId]);

        $stmt = $pdo->prepare('DELETE FROM team_messages WHERE user_id = ?');
        $stmt->execute([$userId]);

        // 4. Delete feedbacks
        $stmt = $pdo->prepare('DELETE FROM feedbacks WHERE user_id = ?');
        $stmt->execute([$userId]);

        // 5. Delete user record
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

        jsonResponse(200, ['message' => '계정과 모든 연동 데이터가 성공적으로 영구 삭제되었습니다.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(500, ['error' => '계정 삭제 처리 중 오류가 발생했습니다: ' . $e->getMessage()]);
    }
}

// POST /auth/request-delete-account (웹 탈퇴 요청 폼 인증 처리)
if ($method === 'POST' && $uri === '/auth/request-delete-account') {
    $body = getBody();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email) {
        jsonResponse(400, ['error' => '삭제할 이메일 주소를 입력하세요.']);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(404, ['error' => '등록되지 않은 드라이버 이메일입니다.']);
    }

    $isKakaoUser = !empty($user['kakao_id']);
    if (!$isKakaoUser && $password) {
        if (!password_verify($password, $user['password_hash'])) {
            jsonResponse(401, ['error' => '비밀번호가 일치하지 않습니다.']);
        }
    } else if (!$isKakaoUser && !$password && !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id']) {
        // Active matching session
    } else if (!$isKakaoUser && !$password) {
        jsonResponse(400, ['error' => '계정 확인을 위해 비밀번호를 입력해 주세요.']);
    }

    $userId = $user['id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('DELETE FROM session_setups WHERE session_id IN (SELECT id FROM track_sessions WHERE user_id = ?)');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM vehicles WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM team_members WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM team_invitations WHERE inviter_id = ? OR invitee_id = ?');
        $stmt->execute([$userId, $userId]);

        $stmt = $pdo->prepare('DELETE FROM team_messages WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM feedbacks WHERE user_id = ?');
        $stmt->execute([$userId]);

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        $pdo->commit();

        if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$userId) {
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
        }

        jsonResponse(200, ['message' => '계정과 모든 연동 데이터가 성공적으로 영구 삭제되었습니다.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(500, ['error' => '계정 삭제 처리 중 오류가 발생했습니다: ' . $e->getMessage()]);
    }
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
        $stmt->execute([$teamId, $userId, 'ADMIN']);

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
    $role = strtoupper(trim($body['role'] ?? 'DRIVER'));
    if (!in_array($role, ['ADMIN', 'CHIEF', 'DRIVER', 'MECHANIC', 'VIEWER'])) {
        $role = 'DRIVER';
    }

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

    $canEdit = in_array($role, ['ADMIN', 'CHIEF', 'DRIVER', 'MECHANIC']) ? 1 : 0;
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

    $isOwner = ($membership['owner_id'] == $userId || in_array($membership['role'], ['ADMIN', 'CHIEF', 'OWNER']));
    if (!$isOwner) {
        jsonResponse(403, ['error' => '팀 관리자(Admin) 권한을 가진 멤버만 팀 정보를 수정할 수 있습니다.']);
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

    $isOwner = ($membership['owner_id'] == $userId || in_array($membership['role'], ['ADMIN', 'CHIEF', 'OWNER']));

    $pdo->beginTransaction();
    try {
        if ($isOwner) {
            // 1. Check if there are garage vehicles registered under this team
            $checkVeh = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE team_id = ?');
            $checkVeh->execute([$teamId]);
            $vehCount = (int)$checkVeh->fetchColumn();
            if ($vehCount > 0) {
                $pdo->rollBack();
                jsonResponse(400, ['error' => "⚠️ '{$membership['name']}' 팀에 등록된 개러지 머신이 {$vehCount}대 존재합니다.\n\n[차량 개러지] 탭에서 해당 머신의 소속 팀을 다른 팀 또는 개인 소유로 변경하거나 먼저 삭제해야 팀을 삭제할 수 있습니다."]);
            }

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
        ORDER BY FIELD(tm.role, "ADMIN", "CHIEF", "OWNER", "MANAGER", "DRIVER", "MECHANIC", "VIEWER")
    ');
    $stmt->execute([$teamId, $teamId]);
    jsonResponse(200, ['members' => $stmt->fetchAll()]);
}

// =====================
// USER SEARCH & TEAM INVITATION ROUTES
// =====================

// GET /users/search?q=...&team_id=... (초대할 유저 검색)
if ($method === 'GET' && $uri === '/users/search') {
    $userId = requireAuth();
    $q = trim($_GET['q'] ?? '');
    $teamId = (int)($_GET['team_id'] ?? 0);

    if (mb_strlen($q) < 1) {
        jsonResponse(200, ['users' => []]);
    }

    $term = "%{$q}%";
    $stmt = $pdo->prepare('
        SELECT u.id, u.name, u.email, u.avatar, u.driver_class, u.kara_license,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = ? AND tm.user_id = u.id) as is_member,
               (SELECT COUNT(*) FROM team_invitations ti WHERE ti.team_id = ? AND ti.invitee_id = u.id AND ti.status = "PENDING") as is_pending
        FROM users u
        WHERE (u.name LIKE ? OR u.email LIKE ?) AND u.id != ?
        LIMIT 20
    ');
    $stmt->execute([$teamId, $teamId, $term, $term, $userId]);
    jsonResponse(200, ['users' => $stmt->fetchAll()]);
}

// POST /teams/:id/invitations (특정 유저에게 팀 가입 요청 발송)
if ($method === 'POST' && preg_match('#^/teams/(\d+)/invitations$#', $uri, $m)) {
    $userId = requireAuth();
    $teamId = (int)$m[1];
    $body = getBody();

    $inviteeId = (int)($body['invitee_id'] ?? 0);
    $role = strtoupper(trim($body['role'] ?? 'DRIVER'));
    if (!in_array($role, ['ADMIN', 'CHIEF', 'DRIVER', 'MECHANIC', 'VIEWER'])) {
        $role = 'DRIVER';
    }

    if (!$inviteeId) {
        jsonResponse(400, ['error' => '초대할 사용자를 지정해주세요.']);
    }

    if ($inviteeId === $userId) {
        jsonResponse(400, ['error' => '자기 자신을 초대할 수 없습니다.']);
    }

    // Check inviter's team membership
    $chkTeam = $pdo->prepare('SELECT t.name, tm.role FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.team_id = ? AND tm.user_id = ?');
    $chkTeam->execute([$teamId, $userId]);
    $teamInfo = $chkTeam->fetch();
    if (!$teamInfo) {
        jsonResponse(403, ['error' => '해당 팀의 멤버만 팀원을 초대할 수 있습니다.']);
    }

    // Check if invitee exists
    $chkUser = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $chkUser->execute([$inviteeId]);
    $targetUser = $chkUser->fetch();
    if (!$targetUser) {
        jsonResponse(404, ['error' => '초대할 사용자를 찾을 수 없습니다.']);
    }

    // Check if invitee is already a member
    $chkMem = $pdo->prepare('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?');
    $chkMem->execute([$teamId, $inviteeId]);
    if ($chkMem->fetch()) {
        jsonResponse(409, ['error' => "'{$targetUser['name']}' 님은 이미 해당 팀의 멤버입니다."]);
    }

    // Check if invitee already has a pending invitation
    $chkPending = $pdo->prepare('SELECT id FROM team_invitations WHERE team_id = ? AND invitee_id = ? AND status = "PENDING"');
    $chkPending->execute([$teamId, $inviteeId]);
    if ($chkPending->fetch()) {
        jsonResponse(409, ['error' => "'{$targetUser['name']}' 님에게 이미 발송된 가입 요청이 있습니다. (수락 대기 중)"]);
    }

    $stmt = $pdo->prepare('INSERT INTO team_invitations (team_id, inviter_id, invitee_id, role, status) VALUES (?,?,?,?, "PENDING")');
    $stmt->execute([$teamId, $userId, $inviteeId, $role]);

    jsonResponse(201, [
        'message' => "'{$targetUser['name']}' 님에게 '{$teamInfo['name']}' 팀 가입 요청을 발송했습니다.",
        'invitation_id' => (int)$pdo->lastInsertId()
    ]);
}

// GET /invitations/received (내가 받은 팀 가입 초대 목록 조회)
if ($method === 'GET' && $uri === '/invitations/received') {
    $userId = requireAuth();
    $stmt = $pdo->prepare('
        SELECT ti.id, ti.team_id, ti.role, ti.status, ti.created_at,
               t.name as team_name, t.home_track, t.description as team_description,
               u.name as inviter_name, u.avatar as inviter_avatar, u.driver_class as inviter_class,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) as member_count
        FROM team_invitations ti
        JOIN teams t ON t.id = ti.team_id
        JOIN users u ON u.id = ti.inviter_id
        WHERE ti.invitee_id = ? AND ti.status = "PENDING"
        ORDER BY ti.created_at DESC
    ');
    $stmt->execute([$userId]);
    jsonResponse(200, ['invitations' => $stmt->fetchAll()]);
}

// POST /invitations/:id/accept (팀 가입 초대 수락)
if ($method === 'POST' && preg_match('#^/invitations/(\d+)/accept$#', $uri, $m)) {
    $userId = requireAuth();
    $invitationId = (int)$m[1];

    $stmt = $pdo->prepare('
        SELECT ti.*, t.name as team_name 
        FROM team_invitations ti 
        JOIN teams t ON t.id = ti.team_id 
        WHERE ti.id = ? AND ti.invitee_id = ? AND ti.status = "PENDING"
    ');
    $stmt->execute([$invitationId, $userId]);
    $inv = $stmt->fetch();

    if (!$inv) {
        jsonResponse(404, ['error' => '유효한 초대 요청을 찾을 수 없거나 이미 처리되었습니다.']);
    }

    // Check 10-team limit per user
    $chkLimit = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id = ?');
    $chkLimit->execute([$userId]);
    if ((int)$chkLimit->fetchColumn() >= 10) {
        jsonResponse(400, ['error' => '팀은 최대 10개까지만 가입할 수 있습니다. 기존 팀을 탈퇴한 후 다시 시도하세요.']);
    }

    $pdo->beginTransaction();
    try {
        $canEdit = in_array($inv['role'], ['ADMIN', 'CHIEF', 'DRIVER', 'MECHANIC']) ? 1 : 0;
        
        // Add to team_members if not already
        $stmt = $pdo->prepare('INSERT INTO team_members (team_id, user_id, role, can_view_data, can_edit_data) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE role = VALUES(role)');
        $stmt->execute([$inv['team_id'], $userId, $inv['role'], $canEdit]);

        // Mark invitation as ACCEPTED
        $stmt = $pdo->prepare('UPDATE team_invitations SET status = "ACCEPTED" WHERE id = ?');
        $stmt->execute([$invitationId]);

        $pdo->commit();
        jsonResponse(200, [
            'message' => "'{$inv['team_name']}' 팀 가입 초대를 수락했습니다! 🏁",
            'team_id' => (int)$inv['team_id']
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(500, ['error' => '초대 수락 처리 실패: ' . $e->getMessage()]);
    }
}

// POST /invitations/:id/reject (팀 가입 초대 거절)
if ($method === 'POST' && preg_match('#^/invitations/(\d+)/reject$#', $uri, $m)) {
    $userId = requireAuth();
    $invitationId = (int)$m[1];

    $stmt = $pdo->prepare('UPDATE team_invitations SET status = "REJECTED" WHERE id = ? AND invitee_id = ? AND status = "PENDING"');
    $stmt->execute([$invitationId, $userId]);

    if ($stmt->rowCount() > 0) {
        jsonResponse(200, ['message' => '팀 초대를 거절했습니다.']);
    } else {
        jsonResponse(404, ['error' => '초대 요청을 찾을 수 없거나 이미 처리되었습니다.']);
    }
}

// =====================
// TEAM CHAT / MESSAGES ROUTES
// =====================

// GET /teams/:id/messages (팀 실시간 채팅 메시지 목록 조회)
if ($method === 'GET' && preg_match('#^/teams/(\d+)/messages$#', $uri, $m)) {
    $userId = requireAuth();
    $teamId = (int)$m[1];

    // Check if user is a member of this team
    $chk = $pdo->prepare('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?');
    $chk->execute([$teamId, $userId]);
    if (!$chk->fetch()) {
        jsonResponse(403, ['error' => '해당 팀의 소속 멤버만 채팅을 열람할 수 있습니다.']);
    }

    $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;

    if ($afterId > 0) {
        $stmt = $pdo->prepare('
            SELECT tm.id, tm.team_id, tm.user_id, tm.user_name, tm.user_avatar, tm.message, tm.created_at,
                   (tm.user_id = ?) as is_mine
            FROM team_messages tm
            WHERE tm.team_id = ? AND tm.id > ?
            ORDER BY tm.created_at ASC, tm.id ASC
            LIMIT 50
        ');
        $stmt->execute([$userId, $teamId, $afterId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT * FROM (
                SELECT tm.id, tm.team_id, tm.user_id, tm.user_name, tm.user_avatar, tm.message, tm.created_at,
                       (tm.user_id = ?) as is_mine
                FROM team_messages tm
                WHERE tm.team_id = ?
                ORDER BY tm.created_at DESC, tm.id DESC
                LIMIT 50
            ) sub ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute([$userId, $teamId]);
    }

    jsonResponse(200, ['messages' => $stmt->fetchAll()]);
}

// POST /teams/:id/messages (팀 채팅 메시지 전송)
if ($method === 'POST' && preg_match('#^/teams/(\d+)/messages$#', $uri, $m)) {
    $userId = requireAuth();
    $teamId = (int)$m[1];
    $body = getBody();

    $message = trim($body['message'] ?? '');
    if ($message === '') {
        jsonResponse(400, ['error' => '메시지 내용을 입력해주세요.']);
    }

    if (mb_strlen($message, 'UTF-8') > 1000) {
        jsonResponse(400, ['error' => '메시지는 최대 1000자까지 전송할 수 있습니다.']);
    }

    // Check if user is a member of this team
    $chk = $pdo->prepare('SELECT u.name, u.avatar FROM team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.team_id = ? AND tm.user_id = ?');
    $chk->execute([$teamId, $userId]);
    $userRow = $chk->fetch();
    if (!$userRow) {
        jsonResponse(403, ['error' => '해당 팀의 소속 멤버만 메시지를 전송할 수 있습니다.']);
    }

    $userName = $userRow['name'] ?? '드라이버';
    $userAvatar = $userRow['avatar'] ?? 'DR';

    $stmt = $pdo->prepare('INSERT INTO team_messages (team_id, user_id, user_name, user_avatar, message) VALUES (?,?,?,?,?)');
    $stmt->execute([$teamId, $userId, $userName, $userAvatar, $message]);
    $newId = (int)$pdo->lastInsertId();

    $fetchStmt = $pdo->prepare('SELECT tm.id, tm.team_id, tm.user_id, tm.user_name, tm.user_avatar, tm.message, tm.created_at, 1 as is_mine FROM team_messages tm WHERE tm.id = ?');
    $fetchStmt->execute([$newId]);
    $createdMessage = $fetchStmt->fetch();

    jsonResponse(201, [
        'message' => '메시지가 전송되었습니다.',
        'chat' => $createdMessage
    ]);
}

// =====================
// VEHICLES ROUTES
// =====================

// GET /vehicles (내 차량 + 소속 팀 차량 + 팀원 공유 차량)
if ($method === 'GET' && $uri === '/vehicles') {
    $userId = requireAuth();
    $stmt = $pdo->prepare('
        SELECT DISTINCT v.*, 
               t.name as team_name, t.home_track as team_home_track,
               u.name as owner_name, u.avatar as owner_avatar, u.driver_class as owner_class,
               (v.user_id = ?) as is_mine
        FROM vehicles v
        LEFT JOIN teams t ON t.id = v.team_id
        JOIN users u ON u.id = v.user_id
        WHERE v.user_id = ?
           OR (v.team_id IS NOT NULL AND v.team_id IN (SELECT tm.team_id FROM team_members tm WHERE tm.user_id = ?))
           OR (v.visibility != "PRIVATE" AND v.user_id IN (
               SELECT tm2.user_id FROM team_members tm2 
               WHERE tm2.team_id IN (SELECT tm1.team_id FROM team_members tm1 WHERE tm1.user_id = ?)
           ))
        ORDER BY is_mine DESC, v.is_active DESC, v.created_at DESC
    ');
    $stmt->execute([$userId, $userId, $userId, $userId]);
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

// PUT /vehicles/:id (차량 정보 수정 - 소유자 또는 팀장)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/vehicles/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $vehicleId = (int)$m[1];
    $body = getBody();

    // Check ownership or team chief authority
    $check = $pdo->prepare('
        SELECT v.id, v.user_id, v.team_id,
               (v.user_id = ?) as is_owner,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = v.team_id AND tm.user_id = ? AND tm.role IN ("ADMIN", "CHIEF", "OWNER")) as is_chief
        FROM vehicles v
        WHERE v.id = ?
    ');
    $check->execute([$userId, $userId, $vehicleId]);
    $veh = $check->fetch();
    if (!$veh || (!$veh['is_owner'] && !$veh['is_chief'])) {
        jsonResponse(403, ['error' => '수정할 차량을 찾을 수 없거나 수정 권한이 없습니다. (차량 소유자 또는 팀장만 수정 가능)']);
    }

    $stmt = $pdo->prepare('
        UPDATE vehicles 
        SET make = ?, model = ?, year = ?, engine_power = ?, tire_model = ?, 
            tire_size_front = ?, tire_size_rear = ?, suspension_spec = ?, visibility = ?,
            team_id = ?
        WHERE id = ?
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
        $vehicleId
    ]);

    jsonResponse(200, ['message' => '머신 정보가 성공적으로 수정되었습니다.', 'vehicle_id' => $vehicleId]);
}

// DELETE /vehicles/:id (소유자 또는 팀장)
if ($method === 'DELETE' && preg_match('#^/vehicles/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $vehicleId = (int)$m[1];

    // Check ownership or team chief authority
    $check = $pdo->prepare('
        SELECT v.id, v.make, v.model, v.user_id, v.team_id,
               (v.user_id = ?) as is_owner,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = v.team_id AND tm.user_id = ? AND tm.role IN ("ADMIN", "CHIEF", "OWNER")) as is_chief
        FROM vehicles v
        WHERE v.id = ?
    ');
    $check->execute([$userId, $userId, $vehicleId]);
    $veh = $check->fetch();
    if (!$veh || (!$veh['is_owner'] && !$veh['is_chief'])) {
        jsonResponse(403, ['error' => '삭제할 차량을 찾을 수 없거나 권한이 없습니다. (차량 소유자 또는 팀장만 삭제 가능)']);
    }

    // Check if there are related track sessions
    $sCheck = $pdo->prepare('SELECT COUNT(*) as cnt FROM track_sessions WHERE vehicle_id = ?');
    $sCheck->execute([$vehicleId]);
    $sessionCount = (int)($sCheck->fetch()['cnt'] ?? 0);

    if ($sessionCount > 0) {
        $vehName = trim($veh['make'] . ' ' . $veh['model']);
        jsonResponse(400, [
            'error' => "해당 차량('{$vehName}')으로 등록된 트랙 세션 로그가 {$sessionCount}건 존재하여 삭제할 수 없습니다. 세션 & 셋업 로거에서 관련 세션을 먼저 삭제해주세요."
        ]);
    }

    $stmt = $pdo->prepare('DELETE FROM vehicles WHERE id = ?');
    $stmt->execute([$vehicleId]);
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
// GUIDES ROUTES (인사이트 공개 조회)
// =====================

// GET /guides (공개 가이드 목록)
if ($method === 'GET' && $uri === '/guides') {
    $category = trim($_GET['category'] ?? '');
    $q = trim($_GET['q'] ?? '');
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $sql = 'SELECT id, slug, title, category, excerpt, cover_image, author_name, read_time, views, is_featured, created_at, updated_at FROM guides WHERE status = "PUBLISHED"';
    $params = [];

    if ($category !== '' && $category !== 'ALL') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR excerpt LIKE ? OR category LIKE ?)';
        $params[] = "%$q%";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $sql .= ' ORDER BY is_featured DESC, id DESC LIMIT ? OFFSET ?';

    $stmt = $pdo->prepare($sql);
    $idx = 1;
    foreach ($params as $p) {
        $stmt->bindValue($idx++, $p, PDO::PARAM_STR);
    }
    $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $guides = $stmt->fetchAll();

    jsonResponse(200, ['guides' => $guides]);
}

// GET /guides/:idOrSlug (가이드 상세 조회 및 조회수 증가)
if ($method === 'GET' && preg_match('#^/guides/([^/]+)$#', $uri, $m)) {
    $param = urldecode($m[1]);
    if (is_numeric($param)) {
        $stmt = $pdo->prepare('SELECT * FROM guides WHERE id = ?');
        $stmt->execute([(int)$param]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM guides WHERE slug = ?');
        $stmt->execute([$param]);
    }
    $guide = $stmt->fetch();
    if (!$guide) {
        jsonResponse(404, ['error' => '해당 인사이트 가이드를 찾을 수 없습니다.']);
    }

    // 조회수 증가
    try {
        $up = $pdo->prepare('UPDATE guides SET views = views + 1 WHERE id = ?');
        $up->execute([$guide['id']]);
        $guide['views']++;
    } catch (Exception $e) {}

    jsonResponse(200, ['guide' => $guide]);
}

// =====================
// SESSIONS ROUTES
// =====================

// GET /sessions (내 세션 + 소속 팀 세션 + 팀원 공유 세션)
if ($method === 'GET' && $uri === '/sessions') {
    $userId = requireAuth();
    $stmt = $pdo->prepare('
        SELECT DISTINCT ts.*, t.name as track_name, CONCAT(v.make, " ", v.model) as vehicle_name,
               t2.name as team_name,
               u.name as driver_name, u.avatar as driver_avatar, u.driver_class as driver_class,
               (ts.user_id = ?) as is_mine,
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
        LEFT JOIN teams t2 ON t2.id = COALESCE(ts.team_id, v.team_id)
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
        WHERE ts.user_id = ?
           OR (ts.team_id IS NOT NULL AND ts.team_id IN (SELECT tm.team_id FROM team_members tm WHERE tm.user_id = ?))
           OR (v.team_id IS NOT NULL AND v.team_id IN (SELECT tm.team_id FROM team_members tm WHERE tm.user_id = ?))
           OR (ts.visibility != "PRIVATE" AND ts.user_id IN (
               SELECT tm2.user_id FROM team_members tm2 
               WHERE tm2.team_id IN (SELECT tm1.team_id FROM team_members tm1 WHERE tm1.user_id = ?)
           ))
        ORDER BY ts.session_date DESC, ts.created_at DESC, ts.id DESC
    ');
    $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
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

    // Determine target driver ID (default: current user, or fellow team member if driver_id specified)
    $driverId = $userId;
    if (!empty($body['driver_id']) && (int)$body['driver_id'] !== $userId) {
        $reqDriverId = (int)$body['driver_id'];
        $chkTeam = $pdo->prepare('
            SELECT COUNT(*) FROM team_members tm1
            JOIN team_members tm2 ON tm1.team_id = tm2.team_id
            WHERE tm1.user_id = ? AND tm2.user_id = ?
        ');
        $chkTeam->execute([$userId, $reqDriverId]);
        if ((int)$chkTeam->fetchColumn() > 0) {
            $driverId = $reqDriverId;
        }
    }

    $pdo->beginTransaction();
    try {
        // Insert track session
        $stmt = $pdo->prepare('INSERT INTO track_sessions (user_id, team_id, vehicle_id, track_id, session_date, session_time, session_number, air_temp, track_temp, weather_condition, visibility, best_lap_ms, top_speed_kmh) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $driverId,
            $body['team_id'] ?? null,
            $body['vehicle_id'],
            $body['track_id'],
            $body['session_date'],
            !empty($body['session_time']) ? $body['session_time'] : null,
            $body['session_number'] ?? 1,
            array_key_exists('air_temp', $body) && $body['air_temp'] !== null && $body['air_temp'] !== '' ? $body['air_temp'] : null,
            array_key_exists('track_temp', $body) && $body['track_temp'] !== null && $body['track_temp'] !== '' ? $body['track_temp'] : null,
            $body['weather_condition'] ?? 'DRY',
            $body['visibility'] ?? 'TEAM',
            $bestLapMs,
            array_key_exists('top_speed_kmh', $body) && $body['top_speed_kmh'] !== null && $body['top_speed_kmh'] !== '' ? $body['top_speed_kmh'] : null
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
                array_key_exists('cold_psi_fl', $setup) ? $setup['cold_psi_fl'] : null,
                array_key_exists('cold_psi_fr', $setup) ? $setup['cold_psi_fr'] : null,
                array_key_exists('cold_psi_rl', $setup) ? $setup['cold_psi_rl'] : null,
                array_key_exists('cold_psi_rr', $setup) ? $setup['cold_psi_rr'] : null,
                array_key_exists('hot_psi_fl', $setup) ? $setup['hot_psi_fl'] : null,
                array_key_exists('hot_psi_fr', $setup) ? $setup['hot_psi_fr'] : null,
                array_key_exists('hot_psi_rl', $setup) ? $setup['hot_psi_rl'] : null,
                array_key_exists('hot_psi_rr', $setup) ? $setup['hot_psi_rr'] : null,
                array_key_exists('damper_front_clicks', $setup) ? $setup['damper_front_clicks'] : null,
                array_key_exists('damper_rear_clicks', $setup) ? $setup['damper_rear_clicks'] : null,
                array_key_exists('camber_fl', $setup) ? $setup['camber_fl'] : null,
                array_key_exists('camber_fr', $setup) ? $setup['camber_fr'] : null,
                array_key_exists('camber_rl', $setup) ? $setup['camber_rl'] : null,
                array_key_exists('camber_rr', $setup) ? $setup['camber_rr'] : null,
                array_key_exists('toe_fl', $setup) ? $setup['toe_fl'] : null,
                array_key_exists('toe_fr', $setup) ? $setup['toe_fr'] : null,
                array_key_exists('toe_rl', $setup) ? $setup['toe_rl'] : null,
                array_key_exists('toe_rr', $setup) ? $setup['toe_rr'] : null,
                array_key_exists('caster_fl', $setup) ? $setup['caster_fl'] : null,
                array_key_exists('caster_fr', $setup) ? $setup['caster_fr'] : null,
                array_key_exists('wing_angle_deg', $setup) ? $setup['wing_angle_deg'] : null,
                array_key_exists('fuel_liters', $setup) ? $setup['fuel_liters'] : null,
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

// PUT /sessions/:id (세션 및 셋업 수정 - 작성자 또는 팀장)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $sessionId = (int)$m[1];
    $body = getBody();

    // Check ownership or team chief authority
    $check = $pdo->prepare('
        SELECT ts.id, ts.user_id, ts.team_id,
               (ts.user_id = ?) as is_owner,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = ts.team_id AND tm.user_id = ? AND tm.role IN ("ADMIN", "CHIEF", "OWNER")) as is_chief
        FROM track_sessions ts
        WHERE ts.id = ?
    ');
    $check->execute([$userId, $userId, $sessionId]);
    $sess = $check->fetch();
    if (!$sess || (!$sess['is_owner'] && !$sess['is_chief'])) {
        jsonResponse(403, ['error' => '수정할 세션을 찾을 수 없거나 권한이 없습니다. (작성자 또는 팀장만 수정 가능)']);
    }

    $driverId = (int)$sess['user_id'];
    if (!empty($body['driver_id'])) {
        $reqDriverId = (int)$body['driver_id'];
        if ($reqDriverId === $userId) {
            $driverId = $reqDriverId;
        } else {
            $chkTeam = $pdo->prepare('
                SELECT COUNT(*) FROM team_members tm1
                JOIN team_members tm2 ON tm1.team_id = tm2.team_id
                WHERE tm1.user_id = ? AND tm2.user_id = ?
            ');
            $chkTeam->execute([$userId, $reqDriverId]);
            if ((int)$chkTeam->fetchColumn() > 0) {
                $driverId = $reqDriverId;
            }
        }
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
            SET user_id = ?, vehicle_id = ?, track_id = ?, session_date = ?, session_time = ?, session_number = ?, 
                air_temp = ?, track_temp = ?, weather_condition = ?, visibility = ?, 
                best_lap_ms = ?, top_speed_kmh = ?, team_id = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $driverId,
            $body['vehicle_id'],
            $body['track_id'],
            $body['session_date'],
            !empty($body['session_time']) ? $body['session_time'] : null,
            $body['session_number'] ?? 1,
            array_key_exists('air_temp', $body) && $body['air_temp'] !== null && $body['air_temp'] !== '' ? $body['air_temp'] : null,
            array_key_exists('track_temp', $body) && $body['track_temp'] !== null && $body['track_temp'] !== '' ? $body['track_temp'] : null,
            $body['weather_condition'] ?? 'DRY',
            $body['visibility'] ?? 'TEAM',
            $bestLapMs,
            array_key_exists('top_speed_kmh', $body) && $body['top_speed_kmh'] !== null && $body['top_speed_kmh'] !== '' ? $body['top_speed_kmh'] : null,
            $body['team_id'] ?? null,
            $sessionId
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
                    array_key_exists('cold_psi_fl', $setup) ? $setup['cold_psi_fl'] : null,
                    array_key_exists('cold_psi_fr', $setup) ? $setup['cold_psi_fr'] : null,
                    array_key_exists('cold_psi_rl', $setup) ? $setup['cold_psi_rl'] : null,
                    array_key_exists('cold_psi_rr', $setup) ? $setup['cold_psi_rr'] : null,
                    array_key_exists('hot_psi_fl', $setup) ? $setup['hot_psi_fl'] : null,
                    array_key_exists('hot_psi_fr', $setup) ? $setup['hot_psi_fr'] : null,
                    array_key_exists('hot_psi_rl', $setup) ? $setup['hot_psi_rl'] : null,
                    array_key_exists('hot_psi_rr', $setup) ? $setup['hot_psi_rr'] : null,
                    array_key_exists('damper_front_clicks', $setup) ? $setup['damper_front_clicks'] : null,
                    array_key_exists('damper_rear_clicks', $setup) ? $setup['damper_rear_clicks'] : null,
                    array_key_exists('camber_fl', $setup) ? $setup['camber_fl'] : null,
                    array_key_exists('camber_fr', $setup) ? $setup['camber_fr'] : null,
                    array_key_exists('camber_rl', $setup) ? $setup['camber_rl'] : null,
                    array_key_exists('camber_rr', $setup) ? $setup['camber_rr'] : null,
                    array_key_exists('toe_fl', $setup) ? $setup['toe_fl'] : null,
                    array_key_exists('toe_fr', $setup) ? $setup['toe_fr'] : null,
                    array_key_exists('toe_rl', $setup) ? $setup['toe_rl'] : null,
                    array_key_exists('toe_rr', $setup) ? $setup['toe_rr'] : null,
                    array_key_exists('caster_fl', $setup) ? $setup['caster_fl'] : null,
                    array_key_exists('caster_fr', $setup) ? $setup['caster_fr'] : null,
                    array_key_exists('wing_angle_deg', $setup) ? $setup['wing_angle_deg'] : null,
                    array_key_exists('fuel_liters', $setup) ? $setup['fuel_liters'] : null,
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
                    array_key_exists('cold_psi_fl', $setup) ? $setup['cold_psi_fl'] : null,
                    array_key_exists('cold_psi_fr', $setup) ? $setup['cold_psi_fr'] : null,
                    array_key_exists('cold_psi_rl', $setup) ? $setup['cold_psi_rl'] : null,
                    array_key_exists('cold_psi_rr', $setup) ? $setup['cold_psi_rr'] : null,
                    array_key_exists('hot_psi_fl', $setup) ? $setup['hot_psi_fl'] : null,
                    array_key_exists('hot_psi_fr', $setup) ? $setup['hot_psi_fr'] : null,
                    array_key_exists('hot_psi_rl', $setup) ? $setup['hot_psi_rl'] : null,
                    array_key_exists('hot_psi_rr', $setup) ? $setup['hot_psi_rr'] : null,
                    array_key_exists('damper_front_clicks', $setup) ? $setup['damper_front_clicks'] : null,
                    array_key_exists('damper_rear_clicks', $setup) ? $setup['damper_rear_clicks'] : null,
                    array_key_exists('camber_fl', $setup) ? $setup['camber_fl'] : null,
                    array_key_exists('camber_fr', $setup) ? $setup['camber_fr'] : null,
                    array_key_exists('camber_rl', $setup) ? $setup['camber_rl'] : null,
                    array_key_exists('camber_rr', $setup) ? $setup['camber_rr'] : null,
                    array_key_exists('toe_fl', $setup) ? $setup['toe_fl'] : null,
                    array_key_exists('toe_fr', $setup) ? $setup['toe_fr'] : null,
                    array_key_exists('toe_rl', $setup) ? $setup['toe_rl'] : null,
                    array_key_exists('toe_rr', $setup) ? $setup['toe_rr'] : null,
                    array_key_exists('caster_fl', $setup) ? $setup['caster_fl'] : null,
                    array_key_exists('caster_fr', $setup) ? $setup['caster_fr'] : null,
                    array_key_exists('wing_angle_deg', $setup) ? $setup['wing_angle_deg'] : null,
                    array_key_exists('fuel_liters', $setup) ? $setup['fuel_liters'] : null,
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

// DELETE /sessions/:id (작성자 또는 팀장)
if ($method === 'DELETE' && preg_match('#^/sessions/(\d+)$#', $uri, $m)) {
    $userId = requireAuth();
    $sessionId = (int)$m[1];

    // Check ownership or team chief authority
    $check = $pdo->prepare('
        SELECT ts.id, ts.user_id, ts.team_id,
               (ts.user_id = ?) as is_owner,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = ts.team_id AND tm.user_id = ? AND tm.role IN ("ADMIN", "CHIEF", "OWNER")) as is_chief
        FROM track_sessions ts
        WHERE ts.id = ?
    ');
    $check->execute([$userId, $userId, $sessionId]);
    $sess = $check->fetch();
    if (!$sess || (!$sess['is_owner'] && !$sess['is_chief'])) {
        jsonResponse(403, ['error' => '삭제할 세션을 찾을 수 없거나 권한이 없습니다. (작성자 또는 팀장만 삭제 가능)']);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM vehicle_setups WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        $stmt = $pdo->prepare('DELETE FROM lap_times WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        $stmt = $pdo->prepare('DELETE FROM track_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);

        $pdo->commit();
        jsonResponse(200, ['message' => '세션 삭제 완료']);
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

// GET /leaderboard/:trackId (소속 팀 관련 세션 및 드라이버 랭킹)
if ($method === 'GET' && preg_match('#^/leaderboard/(\d+)$#', $uri, $m)) {
    $trackId = (int)$m[1];
    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        jsonResponse(200, ['leaderboard' => []]);
    }

    $stmt = $pdo->prepare('
        SELECT ts.id as session_id,
               u.name as driver_name, u.avatar,
               CONCAT(v.make, " ", v.model) as vehicle_name, v.tire_model,
               COALESCE(t1.name, t2.name) as team_name,
               ts.best_lap_ms, ts.session_date,
               vs.hot_psi_fl, vs.hot_psi_fr, vs.hot_psi_rl, vs.hot_psi_rr,
               vs.camber_fl, vs.damper_front_clicks
        FROM track_sessions ts
        JOIN users u ON u.id = ts.user_id
        JOIN vehicles v ON v.id = ts.vehicle_id
        LEFT JOIN teams t1 ON t1.id = ts.team_id
        LEFT JOIN teams t2 ON t2.id = v.team_id
        LEFT JOIN vehicle_setups vs ON vs.session_id = ts.id
        WHERE ts.track_id = ? AND ts.best_lap_ms > 0
          AND (
              ts.user_id = ?
              OR (ts.team_id IS NOT NULL AND ts.team_id IN (SELECT tm.team_id FROM team_members tm WHERE tm.user_id = ?))
              OR (v.team_id IS NOT NULL AND v.team_id IN (SELECT tm.team_id FROM team_members tm WHERE tm.user_id = ?))
              OR (ts.visibility != "PRIVATE" AND ts.user_id IN (
                  SELECT tm2.user_id FROM team_members tm2 
                  WHERE tm2.team_id IN (SELECT tm1.team_id FROM team_members tm1 WHERE tm1.user_id = ?)
              ))
          )
        ORDER BY ts.best_lap_ms ASC
        LIMIT 50
    ');
    $stmt->execute([$trackId, $userId, $userId, $userId, $userId]);
    jsonResponse(200, ['leaderboard' => $stmt->fetchAll()]);
}

// =====================
// FEEDBACK & FEATURE REQUEST ROUTES
// =====================

// GET /feedbacks (피드백 및 개선 요청 목록 조회)
if ($method === 'GET' && $uri === '/feedbacks') {
    $currentUserId = $_SESSION['user_id'] ?? 0;
    $stmt = $pdo->prepare('
        SELECT f.*, u.avatar as user_avatar, u.driver_class as user_class,
               (f.user_id IS NOT NULL AND f.user_id = ?) as is_mine
        FROM feedbacks f
        LEFT JOIN users u ON u.id = f.user_id
        ORDER BY f.created_at DESC
        LIMIT 60
    ');
    $stmt->execute([$currentUserId]);
    jsonResponse(200, ['feedbacks' => $stmt->fetchAll()]);
}

// POST /feedbacks (새 기능 제안 / 오류 제보 등록)
if ($method === 'POST' && $uri === '/feedbacks') {
    $body = getBody();
    $userId = $_SESSION['user_id'] ?? null;

    $title = trim($body['title'] ?? '');
    $content = trim($body['content'] ?? '');
    $type = strtoupper(trim($body['type'] ?? 'FEATURE'));
    $priority = strtoupper(trim($body['priority'] ?? 'NORMAL'));
    $userName = trim($body['user_name'] ?? '');
    $userEmail = trim($body['user_email'] ?? '');

    if (!$title || !$content) {
        jsonResponse(400, ['error' => '요청 제목과 상세 내용을 모두 입력해주세요.']);
    }

    if (!in_array($type, ['FEATURE', 'BUG', 'DATA', 'GENERAL'])) {
        $type = 'FEATURE';
    }
    if (!in_array($priority, ['LOW', 'NORMAL', 'HIGH', 'URGENT'])) {
        $priority = 'NORMAL';
    }

    // If logged in, fetch user details if not provided
    if ($userId) {
        $uStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        if ($uRow) {
            if (!$userName) $userName = $uRow['name'];
            if (!$userEmail) $userEmail = $uRow['email'];
        }
    }

    if (!$userName) $userName = '익명 드라이버';
    if (!$userEmail) $userEmail = 'anonymous@mylaplog.com';

    $stmt = $pdo->prepare('
        INSERT INTO feedbacks (user_id, user_name, user_email, type, title, content, priority, status, upvotes)
        VALUES (?, ?, ?, ?, ?, ?, ?, "PENDING", 1)
    ');
    $stmt->execute([$userId, $userName, $userEmail, $type, $title, $content, $priority]);

    jsonResponse(201, [
        'message' => '소중한 피드백이 등록되었습니다! 개발팀에서 검토 후 신속히 반영하겠습니다. 🚀',
        'feedback_id' => (int)$pdo->lastInsertId()
    ]);
}

// POST /feedbacks/:id/upvote (공감/추천 투표)
if ($method === 'POST' && preg_match('#^/feedbacks/(\d+)/upvote$#', $uri, $m)) {
    $feedbackId = (int)$m[1];
    $stmt = $pdo->prepare('UPDATE feedbacks SET upvotes = upvotes + 1 WHERE id = ?');
    $stmt->execute([$feedbackId]);

    $cntStmt = $pdo->prepare('SELECT upvotes FROM feedbacks WHERE id = ?');
    $cntStmt->execute([$feedbackId]);
    $newCount = $cntStmt->fetchColumn();

    jsonResponse(200, [
        'message' => '해당 요청에 공감 투표를 완료했습니다!',
        'upvotes' => (int)$newCount
    ]);
}

// DELETE /feedbacks/:id (피드백 삭제 - 작성자 본인 또는 어드민)
if ($method === 'DELETE' && preg_match('#^/feedbacks/(\d+)$#', $uri, $m)) {
    $feedbackId = (int)$m[1];
    $userId = $_SESSION['user_id'] ?? null;

    $stmt = $pdo->prepare('SELECT * FROM feedbacks WHERE id = ?');
    $stmt->execute([$feedbackId]);
    $fb = $stmt->fetch();

    if (!$fb) {
        jsonResponse(404, ['error' => '삭제할 제안/제보 글을 찾을 수 없습니다.']);
    }

    // Allow author OR admin
    $canDelete = false;
    if ($userId && $fb['user_id'] && $fb['user_id'] == $userId) {
        $canDelete = true;
    } else if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'ADMIN') {
        $canDelete = true;
    }

    if (!$canDelete) {
        jsonResponse(403, ['error' => '본인이 작성한 제안/제보 글만 삭제할 수 있습니다.']);
    }

    $delStmt = $pdo->prepare('DELETE FROM feedbacks WHERE id = ?');
    $delStmt->execute([$feedbackId]);

    jsonResponse(200, ['message' => '제안/제보 글이 성공적으로 삭제되었습니다.']);
}

// =====================
// ADMIN DASHBOARD & MANAGEMENT ROUTES (Protected by requireAdminAuth)
// =====================

// POST /admin/login (관리자 마스터 인증)
if ($method === 'POST' && $uri === '/admin/login') {
    $body = getBody();
    $adminKey = trim($body['key'] ?? $body['password'] ?? '');
    $email = trim($body['email'] ?? '');
    
    $authSuccess = false;
    // 1. Check Master Admin Key
    if ($adminKey && $adminKey === $ADMIN_MASTER_KEY) {
        $authSuccess = true;
    }
    // 2. Or check DB user credentials if is_admin == 1
    else if ($email && $adminKey) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_admin = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($adminKey, $user['password_hash'])) {
            $authSuccess = true;
            $_SESSION['user_id'] = $user['id'];
        }
    }

    if ($authSuccess) {
        $_SESSION['is_admin'] = true;
        jsonResponse(200, ['message' => '관리자 인증이 성공적으로 완료되었습니다.', 'is_admin' => true]);
    } else {
        jsonResponse(401, ['error' => '관리자 보안 인증 키 또는 비밀번호가 일치하지 않습니다.']);
    }
}

// GET /admin/check-auth (관리자 인증 상태 확인)
if ($method === 'GET' && $uri === '/admin/check-auth') {
    $isAdmin = false;
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
        $isAdmin = true;
    } else if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        if ((int)$stmt->fetchColumn() === 1) {
            $_SESSION['is_admin'] = true;
            $isAdmin = true;
        }
    }
    jsonResponse(200, ['is_admin' => $isAdmin]);
}

// POST /admin/logout (관리자 로그아웃)
if ($method === 'POST' && $uri === '/admin/logout') {
    unset($_SESSION['is_admin']);
    jsonResponse(200, ['message' => '관리자 로그아웃이 완료되었습니다.']);
}

// GET /admin/stats (어드민 대시보드 통계 지표)
if ($method === 'GET' && $uri === '/admin/stats') {
    requireAdminAuth();

    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $vehicleCount = (int)$pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
    $sessionCount = (int)$pdo->query('SELECT COUNT(*) FROM track_sessions')->fetchColumn();
    $teamCount = (int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();

    $fbTotal = (int)$pdo->query('SELECT COUNT(*) FROM feedbacks')->fetchColumn();
    $fbPending = (int)$pdo->query('SELECT COUNT(*) FROM feedbacks WHERE status = "PENDING"')->fetchColumn();
    $fbReviewing = (int)$pdo->query('SELECT COUNT(*) FROM feedbacks WHERE status = "REVIEWING"')->fetchColumn();
    $fbInProgress = (int)$pdo->query('SELECT COUNT(*) FROM feedbacks WHERE status = "IN_PROGRESS"')->fetchColumn();
    $fbResolved = (int)$pdo->query('SELECT COUNT(*) FROM feedbacks WHERE status = "RESOLVED"')->fetchColumn();

    $guideTotal = (int)$pdo->query('SELECT COUNT(*) FROM guides')->fetchColumn();
    $guidePublished = (int)$pdo->query('SELECT COUNT(*) FROM guides WHERE status = "PUBLISHED"')->fetchColumn();
    $guideDraft = (int)$pdo->query('SELECT COUNT(*) FROM guides WHERE status = "DRAFT"')->fetchColumn();
    $guideViews = (int)$pdo->query('SELECT COALESCE(SUM(views), 0) FROM guides')->fetchColumn();

    jsonResponse(200, [
        'stats' => [
            'users' => $userCount,
            'vehicles' => $vehicleCount,
            'sessions' => $sessionCount,
            'teams' => $teamCount,
            'feedbacks' => [
                'total' => $fbTotal,
                'pending' => $fbPending,
                'reviewing' => $fbReviewing,
                'in_progress' => $fbInProgress,
                'resolved' => $fbResolved
            ],
            'guides' => [
                'total' => $guideTotal,
                'published' => $guidePublished,
                'draft' => $guideDraft,
                'views' => $guideViews
            ]
        ]
    ]);
}

// GET /admin/users (전체 사용자 명단 및 활동 통계)
if ($method === 'GET' && $uri === '/admin/users') {
    requireAdminAuth();

    $stmt = $pdo->query('
        SELECT u.id, u.email, u.name, u.driver_class, u.kara_license, u.avatar, u.created_at,
               (SELECT COUNT(*) FROM vehicles v WHERE v.user_id = u.id) as vehicle_count,
               (SELECT COUNT(*) FROM track_sessions ts WHERE ts.user_id = u.id) as session_count,
               (SELECT GROUP_CONCAT(CONCAT(t.name, ":", tm.role) SEPARATOR ", ") 
                FROM team_members tm 
                JOIN teams t ON t.id = tm.team_id 
                WHERE tm.user_id = u.id) as teams_info
        FROM users u
        ORDER BY u.id DESC
    ');
    jsonResponse(200, ['users' => $stmt->fetchAll()]);
}

// PUT /admin/users/:id (사용자 등급 및 정보 변경)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/admin/users/(\d+)$#', $uri, $m)) {
    requireAdminAuth();

    $targetUserId = (int)$m[1];
    $body = getBody();

    $driverClass = trim($body['driver_class'] ?? 'VIP');
    $karaLicense = trim($body['kara_license'] ?? 'Circuit License');
    $name = trim($body['name'] ?? '');

    if (!$name) {
        $nameStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $nameStmt->execute([$targetUserId]);
        $name = $nameStmt->fetchColumn() ?: '드라이버';
    }

    $stmt = $pdo->prepare('UPDATE users SET driver_class = ?, kara_license = ?, name = ? WHERE id = ?');
    $stmt->execute([$driverClass, $karaLicense, $name, $targetUserId]);

    jsonResponse(200, ['message' => "사용자(ID: {$targetUserId}) 정보가 성공적으로 변경되었습니다."]);
}

// DELETE /admin/users/:id (사용자 삭제)
if ($method === 'DELETE' && preg_match('#^/admin/users/(\d+)$#', $uri, $m)) {
    requireAdminAuth();

    $targetUserId = (int)$m[1];

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);

    jsonResponse(200, ['message' => "사용자(ID: {$targetUserId}) 계정이 삭제되었습니다."]);
}

// GET /admin/feedbacks (피드백 관리 목록)
if ($method === 'GET' && $uri === '/admin/feedbacks') {
    requireAdminAuth();

    $stmt = $pdo->query('
        SELECT f.*, u.avatar as user_avatar, u.driver_class as user_class
        FROM feedbacks f
        LEFT JOIN users u ON u.id = f.user_id
        ORDER BY f.id DESC
    ');
    jsonResponse(200, ['feedbacks' => $stmt->fetchAll()]);
}

// PUT /admin/feedbacks/:id (피드백 상태 및 답변 변경)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/admin/feedbacks/(\d+)$#', $uri, $m)) {
    requireAdminAuth();

    $feedbackId = (int)$m[1];
    $body = getBody();

    $status = strtoupper(trim($body['status'] ?? 'PENDING'));
    $priority = strtoupper(trim($body['priority'] ?? 'NORMAL'));
    $adminResponse = trim($body['admin_response'] ?? '');

    if (!in_array($status, ['PENDING', 'REVIEWING', 'IN_PROGRESS', 'RESOLVED'])) {
        $status = 'PENDING';
    }
    if (!in_array($priority, ['LOW', 'NORMAL', 'HIGH', 'URGENT'])) {
        $priority = 'NORMAL';
    }

    $stmt = $pdo->prepare('UPDATE feedbacks SET status = ?, priority = ?, admin_response = ? WHERE id = ?');
    $stmt->execute([$status, $priority, $adminResponse, $feedbackId]);

    jsonResponse(200, ['message' => "피드백(ID: {$feedbackId}) 상태가 성공적으로 갱신되었습니다."]);
}

// DELETE /admin/feedbacks/:id (피드백 삭제)
if ($method === 'DELETE' && preg_match('#^/admin/feedbacks/(\d+)$#', $uri, $m)) {
    requireAdminAuth();

    $feedbackId = (int)$m[1];
    $stmt = $pdo->prepare('DELETE FROM feedbacks WHERE id = ?');
    $stmt->execute([$feedbackId]);

    jsonResponse(200, ['message' => "피드백(ID: {$feedbackId})이 삭제되었습니다."]);
}

// =====================
// ADMIN GUIDES ROUTES (인사이트 관리자 CRUD)
// =====================

// GET /admin/guides (관리자 가이드 전체 목록)
if ($method === 'GET' && $uri === '/admin/guides') {
    requireAdminAuth();

    $stmt = $pdo->query('
        SELECT id, slug, title, category, excerpt, content, cover_image, author_name, read_time, status, views, is_featured, created_at, updated_at
        FROM guides
        ORDER BY id DESC
    ');
    $guides = $stmt->fetchAll();
    jsonResponse(200, ['guides' => $guides]);
}

// POST /admin/guides (새 가이드 작성)
if ($method === 'POST' && $uri === '/admin/guides') {
    requireAdminAuth();

    $body = getBody();
    $title = trim($body['title'] ?? '');
    if ($title === '') {
        jsonResponse(400, ['error' => '가이드 제목을 입력해주세요.']);
    }
    $content = trim($body['content'] ?? '');
    if ($content === '') {
        jsonResponse(400, ['error' => '가이드 본문 내용을 입력해주세요.']);
    }

    $slug = trim($body['slug'] ?? '');
    if ($slug === '') {
        $slug = 'guide-' . time() . '-' . rand(100, 999);
    } else {
        $slug = preg_replace('/[^a-zA-Z0-9가-힣\-_]/u', '-', strtolower($slug));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
    }

    // Check slug uniqueness
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM guides WHERE slug = ?');
    $stmt->execute([$slug]);
    if ((int)$stmt->fetchColumn() > 0) {
        $slug .= '-' . time();
    }

    $category = trim($body['category'] ?? '셋업 노하우');
    $excerpt = trim($body['excerpt'] ?? '');
    $coverImage = trim($body['cover_image'] ?? '');
    $authorName = trim($body['author_name'] ?? 'MyLapLog 인텔리전스');
    $readTime = trim($body['read_time'] ?? '3분');
    $status = (strtoupper(trim($body['status'] ?? 'PUBLISHED')) === 'DRAFT') ? 'DRAFT' : 'PUBLISHED';
    $isFeatured = !empty($body['is_featured']) ? 1 : 0;

    $stmt = $pdo->prepare('
        INSERT INTO guides (slug, title, category, excerpt, content, cover_image, author_name, read_time, status, is_featured)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$slug, $title, $category, $excerpt, $content, $coverImage, $authorName, $readTime, $status, $isFeatured]);
    $newId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM guides WHERE id = ?');
    $stmt->execute([$newId]);
    $guide = $stmt->fetch();

    jsonResponse(201, ['message' => '인사이트 가이드 글이 성공적으로 등록되었습니다.', 'guide' => $guide]);
}

// PUT /admin/guides/:id (가이드 수정)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/admin/guides/(\d+)$#', $uri, $m)) {
    requireAdminAuth();

    $guideId = (int)$m[1];
    $stmt = $pdo->prepare('SELECT * FROM guides WHERE id = ?');
    $stmt->execute([$guideId]);
    $existing = $stmt->fetch();
    if (!$existing) {
        jsonResponse(404, ['error' => '수정할 가이드를 찾을 수 없습니다.']);
    }

    $body = getBody();
    $title = trim($body['title'] ?? $existing['title']);
    $content = trim($body['content'] ?? $existing['content']);
    $slug = trim($body['slug'] ?? $existing['slug']);
    $category = trim($body['category'] ?? $existing['category']);
    $excerpt = trim($body['excerpt'] ?? $existing['excerpt']);
    $coverImage = trim($body['cover_image'] ?? $existing['cover_image']);
    $authorName = trim($body['author_name'] ?? $existing['author_name']);
    $readTime = trim($body['read_time'] ?? $existing['read_time']);
    $status = isset($body['status']) ? ((strtoupper(trim($body['status'])) === 'DRAFT') ? 'DRAFT' : 'PUBLISHED') : $existing['status'];
    $isFeatured = isset($body['is_featured']) ? (!empty($body['is_featured']) ? 1 : 0) : (int)$existing['is_featured'];

    if ($slug !== $existing['slug']) {
        $slug = preg_replace('/[^a-zA-Z0-9가-힣\-_]/u', '-', strtolower($slug));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM guides WHERE slug = ? AND id != ?');
        $stmt->execute([$slug, $guideId]);
        if ((int)$stmt->fetchColumn() > 0) {
            $slug .= '-' . time();
        }
    }

    $stmt = $pdo->prepare('
        UPDATE guides
        SET slug = ?, title = ?, category = ?, excerpt = ?, content = ?, cover_image = ?,
            author_name = ?, read_time = ?, status = ?, is_featured = ?
        WHERE id = ?
    ');
    $stmt->execute([$slug, $title, $category, $excerpt, $content, $coverImage, $authorName, $readTime, $status, $isFeatured, $guideId]);

    $stmt = $pdo->prepare('SELECT * FROM guides WHERE id = ?');
    $stmt->execute([$guideId]);
    $updated = $stmt->fetch();

    jsonResponse(200, ['message' => '인사이트 가이드 글이 성공적으로 수정되었습니다.', 'guide' => $updated]);
}

// DELETE /admin/guides/:id (가이드 삭제)
if ($method === 'DELETE' && preg_match('#^/admin/guides/(\d+)$#', $uri, $m)) {
    requireAdminAuth();

    $guideId = (int)$m[1];
    $stmt = $pdo->prepare('DELETE FROM guides WHERE id = ?');
    $stmt->execute([$guideId]);

    jsonResponse(200, ['message' => "가이드 글(ID: {$guideId})이 삭제되었습니다."]);
}

// Fallback: 404
jsonResponse(404, ['error' => 'API endpoint not found: ' . $method . ' ' . $uri]);
