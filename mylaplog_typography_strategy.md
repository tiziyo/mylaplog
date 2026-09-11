# MyLapLog 타이포그래피 & 폰트 시스템 가이드 (Typography Strategy)

> **MyLapLog**는 트랙데이 레이서 및 모터스포츠 드라이버를 위한 텔레메트리·셋업 로깅 플랫폼입니다.  
> 서킷 현장의 강한 햇빛과 진동 환경에서도 계측 데이터(랩타임, 최고속도, 공기압 등)를 즉각적이고 직관적으로 판독할 수 있도록 고안된 **모터스포츠 특화 타이포그래피 시스템**을 따릅니다.

---

## 1. 루트 스케일링 전략 (`rem` 기반)

전체 UI의 비율 일관성을 유지하고, 뷰포트 크기에 맞춰 유연하게 반응하도록 **루트 `html` 폰트 크기**를 기준으로 전체 컴포넌트를 스케일링합니다.

| 환경 | 기본 `html` 폰트 크기 | 1rem 기준값 | 전략 및 목적 |
| :--- | :--- | :--- | :--- |
| **데스크톱 / 태블릿 (가로)** | `17.5px` | `17.5px` | 브라우저 기본(16px) 대비 약 **+10% 확대**하여 시원한 대시보드 시인성 확보 |
| **모바일 (`≤ 768px`)** | `16.5px` | `16.5px` | 작은 모바일 화면에서 줄바꿈 왜곡 없이 최적의 레이아웃 밀도 유지 |

```css
/* 루트 및 기본 본문 정의 */
html {
  font-size: 17.5px;
}

body {
  font-family: var(--font-body);
  font-size: 1rem;
  line-height: 1.55;
}

@media (max-width: 768px) {
  html {
    font-size: 16.5px !important;
  }
}
```

---

## 2. 폰트 패밀리 3대 역할 분담

데이터의 성격과 정보 위계에 따라 3가지 전문 구글 웹폰트를 엄격히 구분하여 적용합니다.

| CSS 변수 | 폰트 패밀리 | 주 용도 | 특징 |
| :--- | :--- | :--- | :--- |
| **`--font-display`** | `'Chakra Petch', sans-serif` | 로고, 서킷 뱃지, 섹션 타이틀, 레이블 | 모터스포츠 감성의 각진 테크니컬 디자인 |
| **`--font-mono`** | `'Orbitron', monospace` | 랩타임, 최고속도, PSI 수치, 댐퍼 클릭수 | 데이터 폭 떨림이 없는 고정폭(Monospace) 계측기 폰트 |
| **`--font-body`** | `'Noto Sans KR', sans-serif` | 일반 본문, 설명 텍스트, 폼 컨트롤, 메모 | 높은 가독성과 명확한 한글/영문 렌더링 |

---

## 3. 계층별 폰트 사이즈 표준 (Typography Hierarchy)

```
[Level 1: Hero Telemetry]   ---> 2.4rem ~ 3.4rem (Orbitron 900)  - 대시보드 베스트 랩
[Level 2: Primary Data]     ---> 2.35rem         (Orbitron 900)  - 세션 카드 랩타임
[Level 3: Secondary Data]   ---> 1.38rem ~ 1.45rem (Orbitron 800) - 최고속도 km/h
[Level 4: Section Titles]   ---> 1.30rem ~ 1.55rem (Chakra Petch)  - 모달/섹션 헤딩
[Level 5: Card & Nav Title] ---> 0.92rem ~ 1.05rem (Noto Sans KR)  - 카드 제목, 탭
[Level 6: Body & Controls]  ---> 0.85rem ~ 0.92rem (Noto Sans KR)  - 본문, 인풋, 버튼
[Level 7: Specs & Meta]     ---> 0.72rem ~ 0.78rem (Noto/Orbitron) - 캠버/감쇠력/온도
[Level 8: Micro Labels]     ---> 0.65rem ~ 0.68rem (Chakra Petch)  - FL HOT, BEST LAP
```

### 상세 규격표

| 계층 (Hierarchy Level) | 대표 클래스 / 태그 | 데스크톱 (`17.5px`) | 모바일 (`≤768px`) | 굵기(Weight) & 효과 |
| :--- | :--- | :---: | :---: | :--- |
| **Hero Telemetry** | `#dashboard-highlight h2` | `clamp(2.4rem, 7vw, 3.4rem)` | `clamp(2.0rem, 6vw, 2.6rem)` | `900` + Cyan Glow |
| **Primary Data** | `.best-lap-num` | **`2.35rem`** (약 41px) | **`1.95rem`** (약 32px) | `900` + Cyan Glow |
| **Secondary Data** | `.session-top-speed` | **`1.38rem`** (약 24px) | **`1.20rem`** (약 20px) | `800` + Gauge Icon |
| **Main Heading** | `.page-title h1`, `h2` | `1.35rem ~ 1.55rem` | `1.15rem ~ 1.35rem` | `900` Italic |
| **Card Header / Nav** | `.card-title`, `.nav-item a` | `0.95rem ~ 1.05rem` | `0.88rem ~ 0.95rem` | `600 ~ 700` |
| **Base Body / Form** | `body`, `.form-input`, `.btn` | `0.85rem ~ 0.92rem` | `0.80rem ~ 0.88rem` | `500 ~ 600` |
| **Specs & Telemetry Grid**| `.session-spec-cell`, `.sess-temp` | `0.72rem ~ 0.78rem` | `0.70rem ~ 0.74rem` | `700` Mono |
| **Micro Badges & Labels** | `.best-lap-label`, `.tire-cell span`| `0.65rem ~ 0.68rem` | `0.60rem ~ 0.65rem` | `700` Uppercase |

---

## 4. 시인성 및 일관성 유지 원칙 (Core Rules)

1. **절대 최소 크기 보장 (Min 0.60rem)**
   - 야외 직사광선 환경에서 텍스트가 뭉개지는 것을 방지하기 위해 모든 라벨, 뱃지, 캡션은 **최소 0.60rem 이상**을 유지합니다.
2. **숫자 데이터 너비 고정 (Monospace Alignment)**
   - 랩타임(`01:52.340`), 속도(`218 km/h`), 타이어 압력(`32.5 PSI`) 등 실시간으로 변경되는 계측 수치는 글자 폭에 따른 흔들림을 방지하기 위해 반드시 `Orbitron` 또는 `font-family: var(--font-mono)`를 적용합니다.
3. **텍스트 글로우 & 하이라이트 대비 (Contrast & Glow)**
   - 메인 베스트 랩타임: `--accent-cyan` (`#00f0ff`) + `text-shadow: 0 0 14px rgba(0, 240, 255, 0.4)`
   - 최고속도: `--accent-red` 계열 (`#ff6b81`) + 반투명 배경 뱃지(`rgba(255, 107, 129, 0.14)`)
4. **대문자(Uppercase) 및 자간(Letter Spacing) 규격**
   - 소형 서브 라벨(`BEST LAP`, `FL HOT`, `TRACK METRICS`)은 가독성 증대를 위해 `text-transform: uppercase` 및 `letter-spacing: 0.3px ~ 0.6px`를 적용합니다.
