# 🏎️ MyLapLog Official Color Branding Guide
> **Version**: 1.0.0  
> **Brand**: MyLapLog (마이랩로그)  
> **Domain**: [https://mylaplog.com](https://mylaplog.com) | [https://mylaplog.com/app/](https://mylaplog.com/app/)  
> **Last Updated**: 2026-09-10  

---

## 1. 브랜드 개요 및 디자인 철학 (Brand Philosophy)

**MyLapLog**의 칼라 브랜딩은 **모터스포츠 엔지니어링의 정밀함**, **F1/WEC 텔레메트리 데이터의 역동성**, 그리고 **AMOLED 콕핏 HUD 디스플레이**의 시인성을 모티브로 설계된 **하이퍼포먼스 다크 테마(High-Performance Dark Theme)** 기반 디자인 시스템입니다.

### 🏁 핵심 키워드
1. **Precision (정밀성)**: 밀리초(0.001초) 단위 계측과 0.1 PSI 공기압까지 직관적으로 전달하는 차가운 일렉트릭 사이언.
2. **Passion & Redline (열정과 속도)**: 서킷 위의 한계 주행과 최고 랩타임 달성을 상징하는 강렬한 에이펙스 레드.
3. **Cockpit Ergonomics (콕핏 인간공학)**: 가혹한 주행 환경, 야간 트랙데이 및 스마트폰 거치 상태에서도 직관적으로 인지 가능한 고대비 OLED 다크 표면.

---

## 2. 핵심 브랜드 컬러 팔레트 (Core Brand Palette)

```
┌─────────────────┬─────────────────┬─────────────────┬─────────────────┐
│  APEX RED (주)  │ TELEMETRY CYAN  │  PODIUM GREEN   │    PIT AMBER    │
│     #FF2A4B     │     #00F0FF     │     #00FF88     │     #FF9100     │
│ [레이싱/브랜드] │ [정밀데이터/HUD]│[베스트랩/최적화]│ [피트/전략/주의]│
└─────────────────┴─────────────────┴─────────────────┴─────────────────┘
```

### 🔴 1) Apex Racing Red (Primary Brand Color)
- **HEX**: `#FF2A4B`
- **RGB**: `rgb(255, 42, 75)`
- **HSL**: `hsl(351°, 100%, 58%)`
- **Glow Color**: `rgba(255, 42, 75, 0.35)`
- **의미**: 모터스포츠의 심장, 최고속도, 한계 회전수(Redline), 메인 액션(CTA).
- **적용처**: 로고 뱃지, 메인 기록 저장/회원가입 버튼, 최고속도 하이라이트, 긴급 위험 경보.

### 🔷 2) Telemetry Cyan (Tech & HUD Color)
- **HEX**: `#00F0FF`
- **RGB**: `rgb(0, 240, 255)`
- **HSL**: `hsl(184°, 100%, 50%)`
- **Glow Color**: `rgba(0, 240, 255, 0.30)`
- **의미**: 정밀 데이터 계측, GPS 위성 동기화, 첨단 텔레메트리, 냉철한 엔지니어링.
- **적용처**: GPS 실시간 랩타이머 HUD, 차트 텔레메트리 곡선, 활성 탭 인디케이터, 기상/서킷 데이터.

### 🟢 3) Sector Podium Green (Performance & Optimal Color)
- **HEX**: `#00FF88`
- **RGB**: `rgb(0, 255, 136)`
- **HSL**: `hsl(152°, 100%, 50%)`
- **Glow Color**: `rgba(0, 255, 136, 0.30)`
- **의미**: 개인 최고 기록(Best Lap), 타이어 적정 공기압 매칭, 동작 가동 중(Active) 신호, 그린 플래그.
- **적용처**: 베스트 랩타임 뱃지, 실시간 동작 상태 LED 인디케이터, 피트 릴리즈 출발 가능 신호.

### 🟠 4) Pit Lane Amber (Caution & Strategy Color)
- **HEX**: `#FF9100`
- **RGB**: `rgb(255, 145, 0)`
- **HSL**: `hsl(34°, 100%, 50%)`
- **Glow Color**: `rgba(255, 145, 0, 0.30)`
- **의미**: 피트 스탑 전략, 피트 속도제한(Speed Limit), 일시정지, 주의 플래그.
- **적용처**: GPS 피트레인 타이머 모달, 일시정지 상태 뱃지, 피트 스탑 정차 카운트다운.

---

## 3. 다크 콕핏 표면 & 중립 계층 시스템 (Surface & Elevation)

깊이감 있는 레이어드 다크 테마를 구축하여 데이터 시각화의 입체감을 극대화합니다.

| 계층 레벨 | 명칭 | HEX / RGBA 코드 | 용도 및 설명 |
| :---: | :--- | :--- | :--- |
| **L0** | **Cockpit Base Void** | `#07090E` | 최하단 브라우저 배경 캔버스 (OLED 전력 최적화) |
| **L1** | **Carbon Subsurface** | `#0B0E17` | 사이드바, 상단 스티키 탑바, 하단 탭바 |
| **L2** | **Glass Elevated Card** | `rgba(16, 21, 33, 0.85)` | 세션 카드, 차트 컨테이너, 데이터 테이블 (Blur 12px) |
| **L3** | **Cockpit HUD Void** | `#04060A` / Radial | 스피도미터, 실시간 랩타이머 카운터 내부 표면 |
| **Border-1** | **Subtle Glass Border** | `rgba(255, 255, 255, 0.08)` | 일반 카드 및 컨테이너 외곽선 |
| **Border-2** | **Neon Active Border** | `rgba(0, 240, 255, 0.35)` | 활성화된 입력창, 선택된 서킷, 주행 중인 HUD 테두리 |

---

## 4. 타이포그래피 & 텍스트 컬러 (Typography & Contrast)

모터스포츠 전용 폰트와 고대비 텍스트 색상 체계로 가독성을 보장합니다.

```
[ 타이틀 / 헤딩 ] : Chakra Petch (Bold / Italic)  ──>  #FFFFFF (Pure White)
[ 텔레메트리 수치 ] : Orbitron (Mono Display)        ──>  #00F0FF / #00FF88
[ 본문 및 설명문 ] : Noto Sans KR (Regular/Medium)  ──>  #F0F4FC (Crisp Platinum)
[ 메타데이터/라벨] : Noto Sans KR (SemiBold)        ──>  #8391A8 (Slate Grey)
```

| 텍스트 등급 | 폰트 패밀리 | 기본 색상 | 비고 |
| :--- | :--- | :--- | :--- |
| **Hero / Title** | `Chakra Petch`, sans-serif | `#FFFFFF` | 자간 +0.5px, 폰트웨이트 700~900 |
| **Lap & Speed Data** | `Orbitron`, monospace | `#00F0FF` / `#00FF88` | 고정밀 등폭 숫자 표기 (Jitter 방지) |
| **Body / Content** | `Noto Sans KR`, sans-serif | `#F0F4FC` | 명도 대비 12:1 이상 (AAA 등급) |
| **Label / Meta Info** | `Noto Sans KR`, sans-serif | `#8391A8` | 보조 라벨, 단위 표기, 날짜/서킷 메타 |

---

## 5. 시그니처 그라디언트 & 네온 이펙트 (Gradients & Glows)

### 🏎️ 1) Apex Speed Gradient (Primary CTA)
```css
background: linear-gradient(135deg, #FF2A4B 0%, #D90429 100%);
box-shadow: 0 4px 20px rgba(255, 42, 75, 0.35);
```

### 🛰️ 2) Telemetry Pulse Gradient (HUD & Live)
```css
background: linear-gradient(135deg, rgba(0, 240, 255, 0.18) 0%, rgba(0, 255, 136, 0.12) 100%);
border: 1px solid rgba(0, 240, 255, 0.40);
box-shadow: 0 0 25px rgba(0, 240, 255, 0.20);
```

### 🛑 3) Pit Strategy Gradient (Pitlane HUD)
```css
background: linear-gradient(135deg, rgba(255, 145, 0, 0.20) 0%, rgba(255, 68, 0, 0.10) 100%);
border: 1px solid rgba(255, 145, 0, 0.45);
box-shadow: 0 0 25px rgba(255, 145, 0, 0.20);
```

---

## 6. UI 컴포넌트별 컬러 매핑 가이드 (Component Mapping)

| 컴포넌트 | 메인 배경 / 테두리 | 텍스트 / 아이콘 색상 | 비고 |
| :--- | :--- | :--- | :--- |
| **브랜드 로고 뱃지** | `linear-gradient(#FF2A4B, #D90429)` | `#FFFFFF` (Mono Bold) | 시인성 극대화 로고 심볼 |
| **상단 [랩타이머] 버튼** | `rgba(0, 240, 255, 0.12)` / Cyan 30% | `#00F0FF` | 모바일 상단 3단 메뉴 메인 |
| **상단 [피트타이머] 버튼**| `rgba(255, 145, 0, 0.12)` / Amber 35%| `#FF9100` | 모바일 상단 3단 메뉴 메인 |
| **상단 [세션 기록] 버튼** | `linear-gradient(#FF2A4B, #D90429)` | `#FFFFFF` | 가장 강조되는 1순위 액션 |
| **상태 인디케이터 [동작중]**| `rgba(0, 255, 136, 0.15)` / Green 40%| `#00FF88` + 펄스 애니메이션 | 실시간 GPS/피트 가동 중 |
| **상태 인디케이터 [일시정지]**| `rgba(255, 145, 0, 0.15)` / Amber 40%| `#FF9100` + 정지 LED | 주행 일시 중단 상태 |
| **상태 인디케이터 [대기중]**| `rgba(255, 255, 255, 0.08)` / 15% | `#8391A8` | 주행 대기 / 초기화 상태 |
| **소셜 [Google 로그인]**  | `#FFFFFF` / `#DADCE0` 테두리 | `#1F1F1F` + 4색 구글 로고 | 글로벌 표준 고대비 버튼 |
| **소셜 [Kakao 로그인]**   | `#FEE500` / `#E5CE00` 테두리 | `#191919` + 카카오 심볼 | 국내 표준 옐로우 버튼 |

---

## 7. CSS Custom Properties (디자인 토큰 시트)

웹앱(`html/mylaplog/app/index.html`)에 적용된 표준 CSS 루트 변수입니다.

```css
:root {
  /* Surface Dark Elevation */
  --bg-base: #07090e;
  --bg-sidebar: #0b0e17;
  --bg-card: rgba(16, 21, 33, 0.85);
  --bg-card-elevated: #151b2b;
  --bg-input: rgba(7, 9, 14, 0.80);

  /* Core Accent System */
  --accent-red: #ff2a4b;
  --accent-red-glow: rgba(255, 42, 75, 0.35);
  --accent-cyan: #00f0ff;
  --accent-cyan-glow: rgba(0, 240, 255, 0.30);
  --accent-green: #00ff88;
  --accent-green-glow: rgba(0, 255, 136, 0.30);
  --accent-orange: #ff9100;
  --accent-orange-glow: rgba(255, 145, 0, 0.30);

  /* Typography & Lines */
  --text-main: #f0f4fc;
  --text-muted: #8391a8;
  --border-subtle: rgba(255, 255, 255, 0.08);
  --border-focus: rgba(0, 240, 255, 0.50);

  /* Font Families */
  --font-display: 'Chakra Petch', sans-serif;
  --font-mono: 'Orbitron', monospace;
  --font-body: 'Noto Sans KR', sans-serif;
}
```

---

## 8. 접근성 및 품질 보증 (Accessibility & Contrast Compliance)

1. **WCAG 2.1 AA / AAA 준수**: 어두운 배경(`#07090E`) 위에 배치되는 모든 본문 텍스트는 최소 **4.5:1** 이상의 명도 대비를 유지하여 직사광선이 내리쬐는 야외 서킷 환경에서도 뚜렷하게 식별됩니다.
2. **OLED 번인 및 배터리 절감**: 완전한 퓨어 블랙과 딥 네이비 조합을 통해 모바일 거치 주행 시 스마트폰 발열과 배터리 소모를 최소화합니다.
3. **PWA Standalone 대응**: 화면 최상단 노치 및 하단 홈 바 영역까지 백그라운드 블러 및 안전 여백(`safe-area-inset`)을 일관된 톤앤매너로 지원합니다.
