-- MyLapLog MariaDB Database Schema v0.8.0
-- Charset: utf8mb4 / Engine: InnoDB

CREATE DATABASE IF NOT EXISTS mylaplog DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mylaplog;

-- 1. Users (사용자 계정)
CREATE TABLE IF NOT EXISTS users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    kara_license VARCHAR(50) DEFAULT 'Circuit License',
    driver_class VARCHAR(50) DEFAULT 'PRO-AM',
    avatar VARCHAR(10) DEFAULT 'DR',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Teams (레이싱 팀/크루)
CREATE TABLE IF NOT EXISTS teams (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    invite_code VARCHAR(32) NOT NULL UNIQUE,
    owner_id BIGINT NOT NULL,
    home_track VARCHAR(100) DEFAULT '인제 스피디움',
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Team Members (팀 소속 멤버)
CREATE TABLE IF NOT EXISTS team_members (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    role VARCHAR(20) DEFAULT 'DRIVER', -- CHIEF, MANAGER, DRIVER, MECHANIC, VIEWER
    can_view_data TINYINT(1) DEFAULT 1,
    can_edit_data TINYINT(1) DEFAULT 0,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_team_user (team_id, user_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Vehicles (차량 개러지)
CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    team_id BIGINT NULL,
    make VARCHAR(50) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year INT NOT NULL,
    engine_power INT DEFAULT 280,
    tire_model VARCHAR(100) NOT NULL,
    tire_size_front VARCHAR(50) DEFAULT '245/40R18',
    tire_size_rear VARCHAR(50) DEFAULT '245/40R18',
    suspension_spec TEXT,
    visibility VARCHAR(20) DEFAULT 'TEAM', -- PRIVATE, TEAM, PUBLIC
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Tracks (서킷 메타데이터)
CREATE TABLE IF NOT EXISTS tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    layout_name VARCHAR(50) NOT NULL,
    length_meters INT NOT NULL,
    sector_count INT DEFAULT 3
) ENGINE=InnoDB;

-- 6. Track Sessions (세션 정보)
CREATE TABLE IF NOT EXISTS track_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    team_id BIGINT NULL,
    vehicle_id BIGINT NOT NULL,
    track_id INT NOT NULL,
    session_date DATE NOT NULL,
    session_time TIME DEFAULT NULL,
    session_number INT NOT NULL,
    air_temp DECIMAL(4,1) DEFAULT 25.0,
    track_temp DECIMAL(4,1) DEFAULT 40.0,
    weather_condition VARCHAR(50) DEFAULT 'DRY', -- DRY, WET, DAMP
    visibility VARCHAR(20) DEFAULT 'TEAM',
    best_lap_ms INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. Vehicle Setups (세션별 4륜 정밀 셋업)
CREATE TABLE IF NOT EXISTS vehicle_setups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT NOT NULL UNIQUE,
    cold_psi_fl DECIMAL(4,1) DEFAULT 28.0,
    cold_psi_fr DECIMAL(4,1) DEFAULT 28.0,
    cold_psi_rl DECIMAL(4,1) DEFAULT 28.0,
    cold_psi_rr DECIMAL(4,1) DEFAULT 28.0,
    hot_psi_fl DECIMAL(4,1) DEFAULT 34.0,
    hot_psi_fr DECIMAL(4,1) DEFAULT 34.0,
    hot_psi_rl DECIMAL(4,1) DEFAULT 32.0,
    hot_psi_rr DECIMAL(4,1) DEFAULT 32.0,
    damper_front_clicks INT DEFAULT 12,
    damper_rear_clicks INT DEFAULT 8,
    camber_fl DECIMAL(3,1) DEFAULT -3.2,
    camber_fr DECIMAL(3,1) DEFAULT -3.2,
    camber_rl DECIMAL(3,1) DEFAULT -2.0,
    camber_rr DECIMAL(3,1) DEFAULT -2.0,
    toe_fl DECIMAL(4,1) DEFAULT 0.0,
    toe_fr DECIMAL(4,1) DEFAULT 0.0,
    toe_rl DECIMAL(4,1) DEFAULT 1.0,
    toe_rr DECIMAL(4,1) DEFAULT 1.0,
    caster_fl DECIMAL(3,1) DEFAULT 6.5,
    caster_fr DECIMAL(3,1) DEFAULT 6.5,
    wing_angle_deg DECIMAL(3,1) DEFAULT 4.0,
    fuel_liters DECIMAL(4,1) DEFAULT 30.0,
    driver_notes TEXT,
    FOREIGN KEY (session_id) REFERENCES track_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8. Lap Times (랩타임 및 섹터)
CREATE TABLE IF NOT EXISTS lap_times (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT NOT NULL,
    lap_number INT NOT NULL,
    lap_time_ms INT NOT NULL, -- e.g. 112348 -> 01:52.348
    sector1_ms INT DEFAULT NULL,
    sector2_ms INT DEFAULT NULL,
    sector3_ms INT DEFAULT NULL,
    is_valid TINYINT(1) DEFAULT 1,
    is_best TINYINT(1) DEFAULT 0,
    FOREIGN KEY (session_id) REFERENCES track_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed Data: Tracks (전세계 40+ 공인 서킷)
INSERT INTO tracks (id, name, layout_name, length_meters, sector_count) VALUES
-- 🇰🇷 대한민국 (Korea)
(1, '인제 스피디움', 'Full Course', 3908, 3),
(2, '영암 KIC', 'F1 Grand Prix Course', 5615, 3),
(3, '용인 에버랜드 스피드웨이', 'Full Course', 4346, 3),
(4, '태백 레이싱파크', 'Speed Course', 2500, 3),
(5, '한국앤컴퍼니 테크노링', 'High Speed Circuit', 4500, 3),
(6, '포천 레이스웨이', 'Main Track', 3159, 3),

-- 🇯🇵 아시아 & 일본 (Asia / Japan)
(101, '스즈카 서킷 (Suzuka)', 'Grand Prix Circuit', 5807, 3),
(102, '후지 스피드웨이 (Fuji)', 'Grand Prix Course', 4563, 3),
(103, '츠쿠바 서킷 (Tsukuba)', 'Course 2000', 2045, 3),
(104, '모빌리티 리조트 모테기 (Motegi)', 'Road Course', 4801, 3),
(105, '오토폴리스 (Autopolis)', 'International Racing Course', 4674, 3),
(106, '오카야마 인터내셔널 서킷', 'Full Course', 3703, 3),
(107, '스포츠랜드 SUGO', 'International Road Course', 3586, 3),
(108, '세팡 인터내셔널 서킷 (Sepang)', 'Grand Prix Circuit', 5543, 3),
(109, '창 인터내셔널 서킷 (Buriram)', 'Grand Prix Circuit', 4554, 3),
(110, '상하이 인터내셔널 서킷 (Shanghai)', 'Grand Prix Circuit', 5451, 3),
(111, '마리나 베이 스트리트 서킷', 'Street Circuit', 4940, 3),

-- 🇪🇺 유럽 (Europe)
(201, '뉘르부르크링 (Nürburgring GP)', 'Grand-Prix-Strecke', 5148, 3),
(202, '스파-프랑코샹 (Spa-Francorchamps)', 'Grand Prix Circuit', 7004, 3),
(203, '몬차 국립 서킷 (Monza)', 'Autodromo Nazionale', 5793, 3),
(204, '실버스톤 서킷 (Silverstone)', 'Grand Prix Circuit', 5891, 3),
(205, '르망 사르트/부가티 (Le Mans)', 'Circuit de la Sarthe', 13626, 3),
(206, '잔드보르트 (Zandvoort)', 'Grand Prix Circuit', 4259, 3),
(207, '레드불 링 (Red Bull Ring)', 'Grand Prix Circuit', 4318, 3),
(208, '헝가로링 (Hungaroring)', 'Grand Prix Circuit', 4381, 3),
(209, '바르셀로나-카탈루냐 (Catalunya)', 'Grand Prix Circuit', 4657, 3),
(210, '이몰라 서킷 (Imola)', 'Autodromo Enzo e Dino Ferrari', 4909, 3),
(211, '무겔로 서킷 (Mugello)', 'Autodromo Internazionale', 5245, 3),
(212, '모나코 서킷 (Monaco)', 'Circuit de Monaco', 3337, 3),
(213, '포르티망 알가르베 (Portimão)', 'Algarve International Circuit', 4653, 3),

-- 🇺🇸 아메리카 / 중동 / 대양주 (Americas / Middle East / Oceania)
(301, '라구나 세카 (Laguna Seca)', 'WeatherTech Raceway', 3602, 3),
(302, 'COTA 서킷 오브 디 아메리카스', 'Grand Prix Circuit', 5513, 3),
(303, '로드 아메리카 (Road America)', 'Road Course', 6515, 3),
(304, '왓킨스 글렌 (Watkins Glen)', 'Grand Prix Course', 5430, 3),
(305, '데이토나 인터내셔널 (Daytona)', 'Road Course', 5729, 3),
(306, '세브링 인터내셔널 (Sebring)', 'Full Course', 6019, 3),
(307, '인테를라고스 (Interlagos)', 'Autódromo José Carlos Pace', 4309, 3),
(308, '질 빌뇌브 몬트리올 (Montreal)', 'Circuit Gilles Villeneuve', 4361, 3),
(309, '야스 마리나 서킷 (Yas Marina)', 'Grand Prix Circuit', 5281, 3),
(310, '바레인 인터내셔널 (Bahrain)', 'Grand Prix Circuit', 5412, 3),
(311, '마운트 파노라마 (Bathurst)', 'Mount Panorama Circuit', 6213, 3)
ON DUPLICATE KEY UPDATE 
  name=VALUES(name),
  layout_name=VALUES(layout_name),
  length_meters=VALUES(length_meters),
  sector_count=VALUES(sector_count);

-- Seed Data: Users
INSERT INTO users (id, email, password_hash, name, kara_license, driver_class, avatar) VALUES
(1, 'alex.kim@apex-racing.kr', '$2y$10$abcdefghijklmnopqrstuu', 'Alex Kim', 'KARA C', 'PRO-AM', 'AK'),
(2, 'david.lee@apex-racing.kr', '$2y$10$abcdefghijklmnopqrstuu', 'David Lee', 'KARA D', 'Clubman', 'DL'),
(3, 'jin.park@apex-racing.kr', '$2y$10$abcdefghijklmnopqrstuu', 'Jin Park', 'Chief Mechanic', 'Expert', 'JP')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Seed Data: Teams
INSERT INTO teams (id, name, invite_code, owner_id, home_track, description) VALUES
(1, 'Apex Racing Korea', 'APEX-KOR-2026', 1, '인제 스피디움', '트랙데이 & 현대 N 페스티벌 출전 크루')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Seed Data: Team Members
INSERT INTO team_members (team_id, user_id, role, can_view_data, can_edit_data) VALUES
(1, 1, 'CHIEF', 1, 1),
(1, 2, 'DRIVER', 1, 1),
(1, 3, 'MECHANIC', 1, 1)
ON DUPLICATE KEY UPDATE role=VALUES(role);

-- Seed Data: Vehicles
INSERT INTO vehicles (id, user_id, team_id, make, model, year, engine_power, tire_model, tire_size_front, tire_size_rear, suspension_spec, visibility) VALUES
(1, 1, 1, 'Hyundai', 'Avante N (TCR Specs)', 2024, 280, 'Sur4G', '245/40R18', '245/40R18', '일체형 코일오버 F 12k / R 8k', 'TEAM'),
(2, 1, 1, 'Porsche', '718 Cayman GT4', 2023, 420, 'Cup 2', '245/35R20', '295/30R20', 'PASM Clubsport 셋업', 'TEAM'),
(3, 2, 1, 'Hyundai', 'Avante N (#12)', 2024, 280, 'V730', '245/40R18', '245/40R18', '순정 전자제어 서스펜션', 'TEAM')
ON DUPLICATE KEY UPDATE model=VALUES(model);

-- Seed Data: Track Sessions
INSERT INTO track_sessions (id, user_id, team_id, vehicle_id, track_id, session_date, session_number, air_temp, track_temp, weather_condition, visibility, best_lap_ms) VALUES
(1, 1, 1, 1, 1, '2026-08-24', 3, 26.0, 42.0, 'DRY', 'TEAM', 112348),
(2, 1, 1, 1, 1, '2026-08-24', 2, 25.0, 38.0, 'DRY', 'TEAM', 112856),
(3, 1, 1, 1, 2, '2026-08-10', 1, 28.0, 45.0, 'DRY', 'TEAM', 144410)
ON DUPLICATE KEY UPDATE best_lap_ms=VALUES(best_lap_ms);

-- Seed Data: Vehicle Setups
INSERT INTO vehicle_setups (session_id, cold_psi_fl, cold_psi_fr, cold_psi_rl, cold_psi_rr, hot_psi_fl, hot_psi_fr, hot_psi_rl, hot_psi_rr, damper_front_clicks, damper_rear_clicks, camber_fl, camber_fr, camber_rl, camber_rr, driver_notes) VALUES
(1, 28.0, 28.0, 28.0, 28.0, 34.0, 34.0, 32.0, 32.0, 12, 8, -3.2, -3.2, -2.0, -2.0, '공기압 34psi 맞춘 후 2번/3번 코너 접지 한계 상승. S2 섹터 베스트 기록 달성.'),
(2, 30.0, 30.0, 29.0, 29.0, 36.0, 36.0, 33.5, 33.5, 10, 8, -3.2, -3.2, -2.0, -2.0, '공기압 다소 과열로 헤어핀 탈출 시 약간의 언더스티어 발생.'),
(3, 27.5, 27.5, 27.0, 27.0, 33.5, 33.5, 31.0, 31.0, 14, 10, -3.5, -3.5, -2.2, -2.2, '고속 코너링 안정성 우수. F1 코스 3섹터에서 만족스러운 롤 억제력.')
ON DUPLICATE KEY UPDATE driver_notes=VALUES(driver_notes);

-- Seed Data: Lap Times
INSERT INTO lap_times (session_id, lap_number, lap_time_ms, sector1_ms, sector2_ms, sector3_ms, is_valid, is_best) VALUES
(1, 1, 115200, 35500, 43200, 36500, 1, 0),
(1, 2, 113400, 35100, 42600, 35700, 1, 0),
(1, 3, 112348, 34821, 42109, 35418, 1, 1),
(1, 4, 114100, 35200, 42900, 36000, 1, 0)
ON DUPLICATE KEY UPDATE lap_time_ms=VALUES(lap_time_ms);

-- 11. Guides (인사이트 & 서킷/셋업 가이드)
CREATE TABLE IF NOT EXISTS guides (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT '셋업 노하우',
    excerpt TEXT NULL,
    content MEDIUMTEXT NOT NULL,
    cover_image VARCHAR(500) NULL,
    author_name VARCHAR(100) DEFAULT 'MyLapLog 인텔리전스',
    read_time VARCHAR(20) DEFAULT '3분',
    status VARCHAR(20) DEFAULT 'PUBLISHED',
    views INT DEFAULT 0,
    is_featured TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_guides_status (status),
    INDEX idx_guides_category (category),
    INDEX idx_guides_created (created_at)
) ENGINE=InnoDB;

INSERT INTO guides (id, slug, title, category, excerpt, content, cover_image, author_name, read_time, status, views, is_featured) VALUES
(1, 'injespeedium-racing-guide', '[서킷 공략] 인제 스피디움 첫 주행: 코너별 기어 단수 & 브레이킹 포인트 기초 가이드', '서킷 공략', '강원도 인제 스피디움(풀코스 3.908km) 첫 입문자를 위한 40m 고저차 대응법, 헤어핀 탈출 기어비, 그리고 1초를 줄이는 1번 코너 하드 브레이킹 포인트를 완벽 정리합니다.', '## 1. 인제 스피디움 서킷 개요\n인제 스피디움은 총 길이 **3.908km**, 19개의 테크니컬 코너와 **고저차 40m**를 자랑하는 아시아 최고의 롤러코스터 서킷입니다.', '', 'MyLapLog 인텔리전스', '4분', 'PUBLISHED', 128, 1),
(2, 'tire-cold-hot-pressure-master', '[타이어/공기압] 트랙데이 필수! 냉간(Cold) vs 열간(Hot) 공기압 세팅의 모든 것', '타이어/공기압', '트랙데이에서 가장 저렴하고 확실하게 랩타임을 줄이는 튜닝은 공기압입니다. 왜 일상 36psi로 타면 미끄러지는지, 주행 후 피트인 시 적정 열간 공기압 관리 공식을 공개합니다.', '## 1. 일상 주행 공기압으로 서킷을 타면 안 되는 이유\n대부분의 공도용 차량은 냉간 **34~36 PSI**를 권장합니다. 하지만 이 상태로 서킷에 들어가면 과열됩니다.', '', 'MyLapLog 인텔리전스', '3분', 'PUBLISHED', 245, 0),
(3, 'understeer-camber-damper-setup', '[셋업 노하우] 언더스티어가 심할 때: 프론트 캠버와 댐퍼 감쇠력 조율법', '셋업 노하우', '코너 진입 시 앞머리가 바깥으로 밀려나가는 언더스티어! 타이어 바깥쪽 숄더 마모 분석부터 프론트 네거티브 캠버각과 일체형 서스펜션 감쇠력 클릭 조율 순서를 명쾌하게 정리합니다.', '## 1. 언더스티어의 3가지 유형 분석\n차량이 밀려나간다고 무작정 서스펜션 감쇠력만 조이지 마세요.', '', 'MyLapLog 인텔리전스', '5분', 'PUBLISHED', 192, 0),
(4, 'mylaplog-trackday-setup-management', '[트랙데이 팁] 랩타임과 차량 셋업 관리를 한곳에서: MyLapLog가 필요한 이유', '트랙데이 팁', 'MyLaplog는 GPS 랩타임 측정과 차량 셋업(공기압, 얼라인먼트 등) 관리를 하나로 통합하여 불필요한 분석 시간을 줄여주는 올인원 트랙 주행 관리 플랫폼입니다.\n파편화되어 있던 데이터와 기억에 의존하던 주행 변수들을 체계적으로 기록함으로써, 드라이버가 더 빠르고 정확하게 랩타임을 단축할 수 있도록 돕습니다.', '서킷을 사랑하는 드라이버라면 누구나 스마트폰에 레이스크로노(RaceChrono) 같은 고성능 GPS 랩타임 측정 앱 하나쯤은 깔아두고 있을 겁니다. 주머니 속 스마트폰이나 대시보드에 레이스로직(VBOX) 단말기가 뿜어내는 소중한 랩타임 데이터는 우리가 어디서 감속하고 어디서 가속했는지 귀중한 힌트를 주죠.\n\n하지만 트랙 주행이 끝나고 피트에 돌아와 랩타임을 분석하다 보면 뭔가 거대한 퍼즐 조각이 빠진 듯한 묘한 갈증을 느끼게 됩니다. \"아, 맞다! 오늘 공기압을 몇으로 시작했더라?\", \"지난번 1분 15초 찍었을 때랑 오늘 1분 16초로 처졌을 때 캠버 각도가 달랐나? 아니면 타이어를 교체해서 그런가?\"\n\n기록은 냉정하게 남았지만, 그 기록을 만들어낸 \'그날의 차 상태(셋업)\'는 내 머릿속 기억에만 의존하거나, 너덜너덜해진 종이 셋업시트, 혹은 스마트폰 메모장의 뒤죽박죽인 텍스트 속에 갇혀 버립니다. 바로 이 지점에서 드라이버들이 진짜 겪는 고통을 해결하고 한 단계 차원 높은 데이터 관리를 위해 탄생한 서비스가 바로 \'MyLaplog\'입니다.\n\n### 1. 랩타임과 셋업은 뗄래야 뗄 수 없는 바늘과 실이다\n레이싱은 과학입니다. 하지만 많은 드라이버들이 랩타임 측정과 차량 셋업 관리를 완전히 따로 놉니다. 기록은 GPS 앱으로 측정하고, 타이어 공기압·온도, 휠 얼라인먼트(캠버/토우), 서스펜션 댐퍼 감쇠력, 심지어 그날의 트랙 노면 온도와 날씨까지 기록하려면 종이 노트를 펼치거나 엑셀 파일을 켜야 합니다.\n\n주행을 마치고 지친 체력으로 피트에 들어와 이 복잡한 변수들을 일일이 수기로 기록하는 일은 귀찮을 뿐만 아니라 누락되기 일쑤입니다. 데이터가 따로 노니 \"어떤 셋업에서 왜 빨라졌는지\" 그 인과관계를 정확히 복기하기란 사막에서 바늘 찾기입니다.\n\n### 2. MyLaplog가 제안하는 올인원(All-in-One) 솔루션\nMyLaplog는 드라이버들의 이 고단한 숙제를 단 한 방에 해결하기 위해 만들어졌습니다.\n\n- **온라인 셋업시트의 혁신**: 종이나 엑셀로 관리하던 차량의 모든 변수—타이어 종류, 공기압(콜드/핫 프레셔), 휠 얼라인먼트 수치, 메인터넌스 내역—를 스마트폰이나 웹으로 언제 어디서나 간편하게 기록하고 클라우드에 영구 보존합니다.\n- **GPS 랩타임 측정의 내제화**: 별도의 복잡한 서드파티 앱을 전전할 필요 없이, 레이싱 데이터 관리의 핵심인 랩타임 측정 기능과 차량 셋업 이력이 하나의 플랫폼 안에서 유기적으로 맞물려 돌아갑니다.\n- **압도적인 시간 절약**: 데이터 파편화를 없애주니 주행 분석에 쓰는 시간이 획기적으로 줄어듭니다. 기록과 셋업을 대조하는 지루한 행정 업무는 MyLaplog에 맡기고, 드라이버는 온전히 주행 분석과 드라이빙 스킬 향상에만 집중할 수 있습니다.\n\n### 🏁 결국, 목적은 단 하나: \"빨라지는 것\"\n우리가 고가의 타이어를 태우고, 주말 아침부터 서킷에 달려가 기름을 태우는 단 하나의 이유는 결국 \'어제보다 더 빠른 나\'를 마주하기 위해서입니다.\n\n감에만 의존하던 주행에서 벗어나, 완벽하게 정돈된 셋업 데이터와 정확한 랩타임의 상관관계를 한눈에 파악할 수 있다면 성장의 속도는 상상을 초월하게 빨라집니다. 시행착오를 줄여주고 기록 단축의 지름길을 안내하는 가장 강력한 무기, MyLaplog와 함께 다음 트랙 데이에서는 기록의 신세계와 마주해 보세요!', '', 'MyLapLog 인텔리전스', '3분', 'PUBLISHED', 45, 0),
(5, 'trackday-pit-operation-roadmap', '[트랙데이 팁] MyLapLog 실전 로드맵: 가입부터 피트스탑 셋업 수정까지 6단계 완벽 가이드', '트랙데이 팁', '처음 mylaplog.com에 가입한 드라이버가 팀을 생성하고, 머신 스펙을 등록하고, 트랙데이 당일 4륜 냉간/열간 공기압과 댐퍼 셋업을 기록·수정하여 랩타임을 단축하는 6단계 실전 로드맵을 공개합니다.', '## 1. 모터스포츠 데이터 관리의 시작\n\"기록되지 않은 주행은 발전하지 않습니다.\"\nMyLapLog는 단순한 랩타이머를 넘어 드라이버의 감각(Sensory)과 차량의 기계적 셋업(Hardware), 그리고 실시간 주행 데이터(Data)를 하나로 연결하는 모터스포츠 인텔리전스 플랫폼입니다.\n\n---\n\n## 2. 실전 6단계 운용 워크플로우\n\n### [1단계] 가입 & 모바일 환경 구축 (Sign-Up & Setup)\n- **계정 생성**: mylaplog.com에서 이메일로 간편 가입\n- **프로필 설정**: 닉네임, 드라이버 클래스(PRO-AM, CLUBMAN 등), 보유 라이선스 등록\n- **홈 화면에 PWA 앱 설치**: 아이폰(사파리) 공유 > 홈 화면에 추가 / 갤럭시(크롬) 메뉴 > 앱 설치\n\n### [2단계] 레이싱 팀 생성 (Create Team)\n- 좌측 메뉴 [팀 허브] > [+ 새 팀 생성] 클릭\n- 팀 이름, 홈 서킷(인제/영암 등), 팀 소개 입력 후 **6자리 고유 초대 코드** 발급\n\n### [3단계] 디지털 개러지 차량 등록 (Garage Registration)\n- [개러지] 탭 > [+ 차량 추가하기] 클릭\n- 제조사, 차종, 연식, 최고출력(hp) 및 소속 팀 지정\n- **핵심 하체 스펙 등록**: 장착 타이어 모델(Sur4G, V730 등), 전/후륜 규격, 코일오버 제조사 및 기본 감쇠력 클릭\n\n### [4단계] 팀원 가입 요청 및 초청 (Team Member Invitation)\n- 팀 초대 코드를 동료 드라이버, 미캐닉, 크루에게 공유\n- 팀원이 [팀 참가하기]에서 코드 입력 후 가입 요청 > 팀장이 승인 및 역할(DRIVER, MECHANIC) 배정\n\n### [5단계] 트랙데이 당일 세션로그 생성 (Session Log Creation)\n- 피트 도착 후 [세션로그] > [+ 세션 기록하기] 클릭\n- 출전 차량, 서킷(인제 풀코스, 영암 상설 등), 기온 및 트랙 상태 체크\n- **세션 전 Cold Check**: 4륜 냉간 공기압(FL, FR, RL, RR) 및 초기 댐퍼 감쇠력 클릭 기입\n- [스톱워치/타이머] 실행 후 스마트폰 거치대에 장착하고 실시간 랩타임 측정 주행\n\n### [6단계] 피트인 직후 세션로그 수정 & 디브리핑 (Update & Debrief)\n- **골든타임 1분**: 피트인 직후 타이어가 식기 전에 4륜 **열간 공기압(Hot PSI)** 즉시 실측\n- 주행한 세션 카드의 [수정] 클릭 후 열간 공기압 실측치 업데이트\n- **드라이버 노트(Notes)** 메모: 언더/오버스티어, 연석 탈출 트랙션 체감 기록\n- 공기압 과열 시 1~2psi 감압하거나 댐퍼 감쇠력 1클릭 미세 조율 후 다음 세션 준비!', '', 'MyLapLog 인텔리전스', '4분', 'PUBLISHED', 156, 0)
ON DUPLICATE KEY UPDATE title=VALUES(title);

