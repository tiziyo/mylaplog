# AWS Lightsail 인스턴스 및 MySQL/MariaDB 정보

## 1. 서버 접속 정보
- **서버 IP (Host)**: `15.164.197.156`
- **SSH 포트**: `22`
- **SSH 기본 계정**: `admin`
- **SSH 프라이빗 키**: `LightsailDefaultKey-ap-northeast-2.pem`

---

## 2. 운영체제 (OS) 정보
- **운영체제**: **Debian GNU/Linux 12 (bookworm)**
- **커널 버전**: `Linux 6.1.0-51-cloud-amd64` (x86_64)
- **아키텍처**: `x86_64`

---

## 3. 데이터베이스 (MySQL / MariaDB) 정보
- **DB 종류 및 버전**: **MariaDB 10.11.18** (`10.11.18-MariaDB-0+deb12u1`)
- **서비스 상태**: `mariadb.service` (Active / Running)
- **포트**: `3306`

### 계정 및 패스워드 목록

| 구분 | 사용자(User) | 패스워드(Password) | 호스트(Host) | 기본 DB / 설명 |
| :--- | :--- | :--- | :--- | :--- |
| **메인 관리자** | **`admin`** | **`StnXoa2w4DO8KE9V`** | `localhost` | `lampdb` (기본 생성된 데이터베이스) |
| **시스템 관리자** | `root` | *(패스워드 없음)* | `localhost` | `sudo mariadb` 또는 `sudo mysql`로 직접 접속 |
| **phpMyAdmin** | `phpmyadmin` | `tVWNHH9TeMGs` | `localhost` | 내부 관리용 계정 |

---

## 4. 접속 명령어

### SSH 접속 (로컬 PC에서 실행)
```bash
ssh -i "LightsailDefaultKey-ap-northeast-2.pem" admin@15.164.197.156
```

### DB 접속 (서버 SSH 접속 후 실행)
```bash
# admin 계정으로 접속
mariadb -u admin -p'StnXoa2w4DO8KE9V'

# 또는 root 권한으로 접속
sudo mariadb
```

---

## 5. 가상 호스트 (VirtualHost) 및 SSL/HTTPS 설정

| 도메인 | 웹 루트 디렉토리 (DocumentRoot) | SSL 인증서 적용 | 자동 리다이렉트 (HTTP->HTTPS) |
| :--- | :--- | :---: | :---: |
| **`randomfi.com`** | `/var/www/html/randomfi` | 적용됨 (Let's Encrypt) | 활성화 |
| **`www.randomfi.com`** | `/var/www/html/randomfi` | 적용됨 (Let's Encrypt) | 활성화 |
| **`randomfi.io`** | `/var/www/html/randomfi` | 적용됨 (Let's Encrypt) | 활성화 |
| **`www.randomfi.io`** | `/var/www/html/randomfi` | 적용됨 (Let's Encrypt) | 활성화 |
| **`mylaplog.com`** | `/var/www/html/mylaplog` | 적용됨 (Let's Encrypt) | 활성화 |
| **`www.mylaplog.com`** | `/var/www/html/mylaplog` | 적용됨 (Let's Encrypt) | 활성화 |
| **`app.mylaplog.com`** | `/var/www/html/mylaplog` | 적용됨 (Let's Encrypt) | 활성화 |

### SSL 인증서 정보
- **발급 도구**: Certbot (Let's Encrypt)
- **인증서 경로**: `/etc/letsencrypt/live/randomfi.com/fullchain.pem`
- **개인키 경로**: `/etc/letsencrypt/live/randomfi.com/privkey.pem`
- **적용 도메인 (총 7개)**: `randomfi.com`, `www.randomfi.com`, `randomfi.io`, `www.randomfi.io`, `mylaplog.com`, `www.mylaplog.com`, `app.mylaplog.com`
- **자동 갱신(Auto Renew)**: `certbot.timer` 데몬으로 백그라운드 자동 갱신 활성화됨 (만료 전 자동 갱신)

---

## 6. 서버 내 관련 파일 위치
- **자격 증명 파일**: `/opt/aws/lamp/credentials.log` (심볼릭 링크: `/home/admin/application_credentials`)
- **웹 기본 루트**: `/var/www/html`
- **MyLapLog 홍보 랜딩 경로**: `/var/www/html/mylaplog/index.html` (`https://mylaplog.com`)
- **MyLapLog 웹 앱 포털 경로**: `/var/www/html/mylaplog/app/index.html` (`https://app.mylaplog.com` / `https://mylaplog.com/app/`)
- **로컬 앱 소스 경로**: `html/mylaplog/app/index.html`
- **로컬 랜딩 소스 경로**: `html/mylaplog/index.html`
- **가상 호스트 SSL 설정**:
  - `/etc/apache2/sites-available/randomfi.com-le-ssl.conf`
- **MySQL / MariaDB 웹 뷰어 앱 경로**: `https://mylaplog.com/db/` (`/var/www/html/mylaplog/db/index.html`)
- **로컬 DB 뷰어 소스 경로**: `html/mylaplog/db/index.html`, `html/mylaplog/db/api.php`
- **phpMyAdmin 설정**: `/etc/phpmyadmin/config-db.php`




