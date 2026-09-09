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

-- Seed Data: Tracks
INSERT INTO tracks (id, name, layout_name, length_meters, sector_count) VALUES
(1, '인제 스피디움', 'Full Course', 3908, 3),
(2, '영암 KIC', 'F1 Grand Prix Course', 5615, 3),
(3, '용인 에버랜드 스피드웨이', 'Full Course', 4346, 3),
(4, '태백 레이싱파크', 'Speed Course', 2500, 3)
ON DUPLICATE KEY UPDATE name=VALUES(name);

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
(3, 'understeer-camber-damper-setup', '[셋업 노하우] 언더스티어가 심할 때: 프론트 캠버와 댐퍼 감쇠력 조율법', '셋업 노하우', '코너 진입 시 앞머리가 바깥으로 밀려나가는 언더스티어! 타이어 바깥쪽 숄더 마모 분석부터 프론트 네거티브 캠버각과 일체형 서스펜션 감쇠력 클릭 조율 순서를 명쾌하게 정리합니다.', '## 1. 언더스티어의 3가지 유형 분석\n차량이 밀려나간다고 무작정 서스펜션 감쇠력만 조이지 마세요.', '', 'MyLapLog 인텔리전스', '5분', 'PUBLISHED', 192, 0)
ON DUPLICATE KEY UPDATE title=VALUES(title);

