<?php
/**
 * MyLapLog 인사이트(Guides) SEO 서버 사이드 렌더러
 * - 구글 검색엔진 크롤러(Googlebot) 및 SNS 크롤러 완벽 대응 (SSR)
 * - Clean URL 지원: /guides/{slug} 및 /guides
 * - 메타 태그, Open Graph, Twitter Card, Schema.org JSON-LD 구조화 데이터 탑재
 * - 다국어(한국어/영어 KO/EN) 원클릭 전환 지원
 * - 모바일 환경 100% 반응형 최적화 (가로 스와이프 칩, 유동 타이포그래피, 터치 타겟 최적화)
 */

// DB Connection
$DB_HOST = 'localhost';
$DB_NAME = 'mylaplog';
$DB_USER = 'admin';
$DB_PASS = 'StnXoa2w4DO8KE9V';

$pdo = null;
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    // Graceful fallback if DB fails
}

// Helper: Lightweight Markdown Parser for SEO HTML
function parseGuideMarkdown($md) {
    if (!$md) return '';

    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
    $html = '';
    $inCodeBlock = false;
    $codeLang = '';
    $codeBuffer = [];
    $inList = false;
    $listType = '';
    $inTable = false;
    $tableRows = [];

    $flushList = function() use (&$html, &$inList, &$listType) {
        if ($inList) {
            $html .= ($listType === 'ol') ? "</ol>\n" : "</ul>\n";
            $inList = false;
            $listType = '';
        }
    };

    $flushTable = function() use (&$html, &$inTable, &$tableRows) {
        if ($inTable && !empty($tableRows)) {
            $html .= "<div class=\"table-responsive\"><table class=\"guide-table\">\n";
            $isHeader = true;
            foreach ($tableRows as $idx => $row) {
                if (preg_match('/^\|?(\s*:?-+:?\s*\|?)+$/', $row)) {
                    $isHeader = false;
                    continue;
                }
                $cells = array_values(array_filter(array_map('trim', explode('|', trim($row, '|'))), function($v) {
                    return $v !== '';
                }));
                if (empty($cells)) continue;

                if ($idx === 0) {
                    $html .= "<thead><tr>\n";
                    foreach ($cells as $c) {
                        $html .= "<th>" . parseInlineStyles($c) . "</th>\n";
                    }
                    $html .= "</tr></thead>\n<tbody>\n";
                } else {
                    $html .= "<tr>\n";
                    foreach ($cells as $c) {
                        $html .= "<td>" . parseInlineStyles($c) . "</td>\n";
                    }
                    $html .= "</tr>\n";
                }
            }
            $html .= "</tbody></table></div>\n";
            $inTable = false;
            $tableRows = [];
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // 1. Code block toggle
        if (preg_match('/^```(.*)$/', $trimmed, $m)) {
            if ($inCodeBlock) {
                $html .= "<pre><code class=\"lang-" . htmlspecialchars($codeLang) . "\">" . htmlspecialchars(implode("\n", $codeBuffer)) . "</code></pre>\n";
                $inCodeBlock = false;
                $codeBuffer = [];
            } else {
                $flushList();
                $flushTable();
                $inCodeBlock = true;
                $codeLang = trim($m[1]);
            }
            continue;
        }

        if ($inCodeBlock) {
            $codeBuffer[] = $line;
            continue;
        }

        // 2. Table row
        if (strpos($trimmed, '|') === 0 || (substr_count($trimmed, '|') >= 2 && !preg_match('/^[#>]/', $trimmed))) {
            $flushList();
            $inTable = true;
            $tableRows[] = $trimmed;
            continue;
        } else {
            $flushTable();
        }

        // 3. Headings
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
            $flushList();
            $level = strlen($m[1]);
            $title = parseInlineStyles($m[2]);
            $html .= "<h{$level} class=\"guide-h{$level}\">{$title}</h{$level}>\n";
            continue;
        }

        // 4. Horizontal Rule
        if (preg_match('/^(?:---|\*\*\*|___)$/', $trimmed)) {
            $flushList();
            $html .= "<hr class=\"guide-divider\">\n";
            continue;
        }

        // 5. Blockquote / Callout
        if (preg_match('/^>\s*(.*)$/', $trimmed, $m)) {
            $flushList();
            $quoteText = parseInlineStyles($m[1]);
            $isTip = strpos($quoteText, '💡') !== false || strpos($quoteText, '[!TIP]') !== false;
            $isWarn = strpos($quoteText, '⚠️') !== false || strpos($quoteText, '[!WARNING]') !== false;
            
            $boxClass = 'guide-callout';
            if ($isTip) $boxClass .= ' callout-tip';
            else if ($isWarn) $boxClass .= ' callout-warning';

            $html .= "<blockquote class=\"{$boxClass}\">{$quoteText}</blockquote>\n";
            continue;
        }

        // 6. Lists
        if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $m)) {
            if (!$inList || $listType !== 'ul') {
                $flushList();
                $html .= "<ul class=\"guide-list\">\n";
                $inList = true;
                $listType = 'ul';
            }
            $html .= "<li>" . parseInlineStyles($m[1]) . "</li>\n";
            continue;
        }

        if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
            if (!$inList || $listType !== 'ol') {
                $flushList();
                $html .= "<ol class=\"guide-list-num\">\n";
                $inList = true;
                $listType = 'ol';
            }
            $html .= "<li>" . parseInlineStyles($m[1]) . "</li>\n";
            continue;
        }

        $flushList();

        // 7. Paragraph
        if ($trimmed !== '') {
            $html .= "<p class=\"guide-p\">" . parseInlineStyles($trimmed) . "</p>\n";
        }
    }

    $flushList();
    $flushTable();

    return $html;
}

function parseInlineStyles($text) {
    // 1. Images: ![alt](url)
    $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/u', '<div class="guide-img-container" style="margin:1.4rem 0; text-align:center;"><img src="$2" alt="$1" class="guide-article-img" style="max-width:100%; height:auto; border-radius:10px; box-shadow:0 8px 28px rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.08); display:inline-block;" loading="lazy"></div>', $text);
    // 2. Bold, Italic, Code, Links
    $text = preg_replace('/\*\*(.*?)\*\*/u', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*([^\*]+)\*/u', '<em>$1</em>', $text);
    $text = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);
    return $text;
}

// Route parameters
$slug = trim($_GET['slug'] ?? '');
$activeCategory = trim($_GET['category'] ?? '');

$baseUrl = 'https://www.mylaplog.com';
$pageTitle = 'MyLapLog 인사이트 - 서킷 공략 & 레이싱 셋업 가이드';
$pageDescription = '모터스포츠 기술 아카이브. 레이싱 드라이빙과 엔지니어링 정보를 다룹니다. 서킷 주행 라인, 레이싱 기록, 실전 트랙 세팅 등 랩타임 단축을 위한 데이터와 메커니즘을 제공합니다.';
$canonicalUrl = $baseUrl . '/guides';
$ogImage = $baseUrl . '/app/icon-512.png';
$guide = null;
$relatedGuides = [];

if ($slug !== '') {
    // Single Guide Mode
    if ($pdo) {
        $stmt = $pdo->prepare('SELECT * FROM guides WHERE (slug = ? OR id = ?) AND status = "PUBLISHED" LIMIT 1');
        $stmt->execute([$slug, is_numeric($slug) ? (int)$slug : 0]);
        $guide = $stmt->fetch();

        if ($guide) {
            $pdo->prepare('UPDATE guides SET views = views + 1 WHERE id = ?')->execute([$guide['id']]);
            $guide['views'] = (int)$guide['views'] + 1;

            $rStmt = $pdo->prepare('SELECT id, slug, title, category, read_time, views, created_at, cover_image FROM guides WHERE status = "PUBLISHED" AND id != ? ORDER BY id DESC LIMIT 3');
            $rStmt->execute([$guide['id']]);
            $relatedGuides = $rStmt->fetchAll();
        }
    }

    if ($guide) {
        $pageTitle = htmlspecialchars($guide['title']) . ' | MyLapLog 인사이트';
        $pageDescription = htmlspecialchars($guide['excerpt'] ?: mb_substr(strip_tags($guide['content']), 0, 160));
        $canonicalUrl = $baseUrl . '/guides/' . rawurlencode($guide['slug']);
        if (!empty($guide['cover_image'])) {
            $ogImage = (strpos($guide['cover_image'], 'http') === 0) ? $guide['cover_image'] : ($baseUrl . $guide['cover_image']);
        }
    } else {
        http_response_code(404);
        $pageTitle = '가이드를 찾을 수 없습니다 | MyLapLog';
    }
} else {
    // Guide Catalog List Mode
    if ($pdo) {
        $q = 'SELECT id, slug, title, category, excerpt, author_name, read_time, views, is_featured, created_at, cover_image FROM guides WHERE status = "PUBLISHED"';
        $p = [];
        if ($activeCategory !== '') {
            $q .= ' AND category = ?';
            $p[] = $activeCategory;
        }
        $q .= ' ORDER BY is_featured DESC, id DESC';
        $stmt = $pdo->prepare($q);
        $stmt->execute($p);
        $allGuides = $stmt->fetchAll();
    } else {
        $allGuides = [];
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
  
  <!-- Primary Meta Tags -->
  <title><?= $pageTitle ?></title>
  <meta name="title" content="<?= $pageTitle ?>">
  <meta name="description" content="<?= $pageDescription ?>">
  <meta name="keywords" content="서킷 공략, 인제스피디움, 영암서킷, 타이어 공기압, 열간 공기압, 캠버 셋업, 댐퍼 감쇠력, 모터스포츠, 랩타이머, 트랙데이, MyLapLog, circuit guide, lap timer, racing setup">
  <meta name="author" content="<?= $guide ? htmlspecialchars($guide['author_name']) : 'MyLapLog' ?>">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <link rel="canonical" href="<?= $canonicalUrl ?>">

  <!-- Open Graph / Facebook / Kakao -->
  <meta property="og:type" content="<?= $guide ? 'article' : 'website' ?>">
  <meta property="og:url" content="<?= $canonicalUrl ?>">
  <meta property="og:title" content="<?= $pageTitle ?>">
  <meta property="og:description" content="<?= $pageDescription ?>">
  <meta property="og:image" content="<?= $ogImage ?>">
  <meta property="og:site_name" content="MyLapLog - 모터스포츠 인텔리전스">
  <meta property="og:locale" content="ko_KR">
  <?php if ($guide): ?>
  <meta property="article:published_time" content="<?= date('c', strtotime($guide['created_at'])) ?>">
  <meta property="article:modified_time" content="<?= date('c', strtotime($guide['updated_at'] ?? $guide['created_at'])) ?>">
  <meta property="article:author" content="<?= htmlspecialchars($guide['author_name']) ?>">
  <meta property="article:section" content="<?= htmlspecialchars($guide['category']) ?>">
  <?php endif; ?>

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="<?= $canonicalUrl ?>">
  <meta name="twitter:title" content="<?= $pageTitle ?>">
  <meta name="twitter:description" content="<?= $pageDescription ?>">
  <meta name="twitter:image" content="<?= $ogImage ?>">

  <!-- Favicon & PWA Icons -->
  <link rel="icon" type="image/x-icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/app/apple-touch-icon.png">
  <link rel="manifest" href="/app/manifest.json">
  <meta name="theme-color" content="#06090e">

  <!-- Google Fonts: Inter & Outfit -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Schema.org JSON-LD Structured Data for Google Rich Snippets -->
  <?php if ($guide): ?>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "TechArticle",
    "mainEntityOfPage": {
      "@type": "WebPage",
      "@id": "<?= $canonicalUrl ?>"
    },
    "headline": <?= json_encode($guide['title'], JSON_UNESCAPED_UNICODE) ?>,
    "description": <?= json_encode($guide['excerpt'] ?: mb_substr(strip_tags($guide['content']), 0, 160), JSON_UNESCAPED_UNICODE) ?>,
    "image": [
      <?= json_encode($ogImage) ?>
    ],
    "datePublished": "<?= date('c', strtotime($guide['created_at'])) ?>",
    "dateModified": "<?= date('c', strtotime($guide['updated_at'] ?? $guide['created_at'])) ?>",
    "author": {
      "@type": "Person",
      "name": <?= json_encode($guide['author_name'] ?: 'MyLapLog 인텔리전스', JSON_UNESCAPED_UNICODE) ?>,
      "url": "https://www.mylaplog.com"
    },
    "publisher": {
      "@type": "Organization",
      "name": "MyLapLog",
      "logo": {
        "@type": "ImageObject",
        "url": "https://www.mylaplog.com/app/icon-512.png"
      }
    },
    "articleSection": <?= json_encode($guide['category'] ?: '모터스포츠 셋업', JSON_UNESCAPED_UNICODE) ?>,
    "inLanguage": "ko-KR"
  }
  </script>
  <?php else: ?>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    "name": "MyLapLog 인사이트 & 가이드",
    "url": "https://www.mylaplog.com/guides",
    "description": "모터스포츠 기술 아카이브. 레이싱 드라이빙과 엔지니어링 정보를 다룹니다. 서킷 주행 라인, 레이싱 기록, 실전 트랙 세팅 등 랩타임 단축을 위한 데이터와 메커니즘을 제공합니다.",
    "publisher": {
      "@type": "Organization",
      "name": "MyLapLog",
      "logo": {
        "@type": "ImageObject",
        "url": "https://www.mylaplog.com/app/icon-512.png"
      }
    }
  }
  </script>
  <?php endif; ?>

  <style>
    :root {
      --bg-main: #06090e;
      --bg-card: rgba(13, 19, 33, 0.85);
      --bg-card-hover: rgba(18, 26, 44, 0.95);
      --border-subtle: rgba(0, 240, 255, 0.15);
      --border-strong: rgba(0, 240, 255, 0.35);
      --accent-cyan: #00f0ff;
      --accent-red: #ff2a4b;
      --accent-orange: #ff9900;
      --accent-green: #00ff88;
      --text-main: #f1f5f9;
      --text-muted: #94a3b8;
      --font-main: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      --font-display: 'Outfit', 'Inter', sans-serif;
      --font-mono: 'JetBrains Mono', monospace;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
    }

    body {
      background-color: var(--bg-main);
      background-image: 
        radial-gradient(circle at 10% 20%, rgba(0, 240, 255, 0.05) 0%, transparent 40%),
        radial-gradient(circle at 90% 80%, rgba(255, 42, 75, 0.04) 0%, transparent 40%);
      color: var(--text-main);
      font-family: var(--font-main);
      line-height: 1.7;
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    a {
      color: var(--accent-cyan);
      text-decoration: none;
      transition: all 0.2s ease;
    }
    a:hover {
      text-decoration: underline;
      color: #70f5ff;
    }

    /* Header Nav */
    header.site-header {
      position: sticky;
      top: 0;
      z-index: 100;
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      background: rgba(6, 9, 14, 0.88);
      border-bottom: 1px solid var(--border-subtle);
    }
    .header-inner {
      max-width: 1080px;
      margin: 0 auto;
      padding: 0.75rem 1.25rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .logo-link {
      display: flex;
      align-items: center;
      gap: 0.55rem;
      text-decoration: none;
      flex-shrink: 0;
    }
    .logo-link img {
      width: 32px;
      height: 32px;
      border-radius: 8px;
    }
    .logo-text {
      font-family: var(--font-display);
      font-size: 1.2rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: #fff;
    }
    .logo-text span {
      color: var(--accent-cyan);
    }
    .header-actions {
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }

    /* Language Switcher Capsule */
    .lang-switcher {
      display: inline-flex;
      align-items: center;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(0, 240, 255, 0.2);
      border-radius: 20px;
      padding: 2px;
      position: relative;
    }
    .lang-btn {
      background: transparent;
      border: none;
      color: var(--text-muted);
      font-size: 0.75rem;
      font-weight: 700;
      padding: 0.25rem 0.6rem;
      border-radius: 16px;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: var(--font-display);
    }
    .lang-btn.active {
      background: var(--accent-cyan);
      color: #030712;
      box-shadow: 0 0 10px rgba(0, 240, 255, 0.4);
    }
    .lang-btn:not(.active):hover {
      color: #fff;
    }

    /* Google Translate Clean Integration Styles - Complete Top Banner Elimination */
    #google_translate_element,
    .goog-te-gadget,
    .goog-te-banner,
    .goog-te-banner-frame,
    .goog-te-banner-frame.skiptranslate,
    iframe.goog-te-banner-frame,
    iframe.skiptranslate,
    .goog-te-spinner-pos,
    #goog-gt-tt,
    .goog-te-balloon-frame,
    .goog-tooltip,
    .goog-tooltip:hover {
      display: none !important;
      visibility: hidden !important;
      height: 0 !important;
      width: 0 !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }

    body {
      top: 0px !important;
      position: static !important;
    }

    body > .skiptranslate {
      display: none !important;
    }

    .goog-text-highlight {
      background-color: transparent !important;
      border: none !important;
      box-shadow: none !important;
    }

    font {
      background-color: transparent !important;
      box-shadow: none !important;
    }

    .btn-app {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.42rem 0.85rem;
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 700;
      background: linear-gradient(135deg, #00f0ff 0%, #0099ff 100%);
      color: #030712;
      border: none;
      box-shadow: 0 0 14px rgba(0, 240, 255, 0.3);
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s ease;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .btn-app:hover {
      transform: translateY(-1px);
      box-shadow: 0 0 22px rgba(0, 240, 255, 0.5);
      text-decoration: none;
      color: #030712;
    }

    /* Container */
    .container {
      max-width: 880px;
      margin: 0 auto;
      padding: 2rem 1.25rem 4rem;
      flex: 1;
      width: 100%;
    }

    /* Breadcrumbs */
    .breadcrumbs {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      font-size: 0.82rem;
      color: var(--text-muted);
      margin-bottom: 1.25rem;
      flex-wrap: wrap;
    }
    .breadcrumbs a {
      color: var(--text-muted);
    }
    .breadcrumbs a:hover {
      color: var(--accent-cyan);
    }

    /* Article Reader */
    .article-header {
      margin-bottom: 2rem;
      padding-bottom: 1.5rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .article-meta-badges {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 0.85rem;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.2rem 0.6rem;
      border-radius: 9999px;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .badge-cyan {
      background: rgba(0, 240, 255, 0.12);
      color: var(--accent-cyan);
      border: 1px solid rgba(0, 240, 255, 0.3);
    }
    .badge-red {
      background: rgba(255, 42, 75, 0.12);
      color: var(--accent-red);
      border: 1px solid rgba(255, 42, 75, 0.3);
    }
    .article-title {
      font-family: var(--font-display);
      font-size: clamp(1.45rem, 3.8vw, 2.15rem);
      font-weight: 800;
      color: #ffffff;
      line-height: 1.35;
      margin-bottom: 1rem;
      letter-spacing: -0.02em;
      word-break: keep-all;
    }
    .article-author-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 0.75rem;
      font-size: 0.82rem;
      color: var(--text-muted);
    }
    .author-info {
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }
    .author-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, #00f0ff, #ff2a4b);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      color: #000;
      font-size: 0.75rem;
      flex-shrink: 0;
    }

    /* Article Cover Hero Banner */
    .article-cover-wrapper {
      margin-bottom: 2rem;
      border-radius: 14px;
      overflow: hidden;
      border: 1px solid var(--border-subtle);
      max-height: 440px;
      background: #030712;
      box-shadow: 0 10px 32px rgba(0, 0, 0, 0.6);
    }
    .article-cover-img {
      width: 100%;
      height: auto;
      max-height: 440px;
      object-fit: cover;
      display: block;
    }

    /* Article Content Typography */
    .article-body {
      font-size: 1.02rem;
      color: #e2e8f0;
      line-height: 1.85;
      word-break: keep-all;
    }
    .article-body .guide-p {
      margin-bottom: 1.35rem;
    }
    .article-body .guide-h2 {
      font-family: var(--font-display);
      font-size: clamp(1.25rem, 3vw, 1.5rem);
      font-weight: 800;
      color: #ffffff;
      margin: 2.2rem 0 0.85rem;
      padding-bottom: 0.4rem;
      border-bottom: 1px solid rgba(0, 240, 255, 0.2);
    }
    .article-body .guide-h3 {
      font-family: var(--font-display);
      font-size: clamp(1.08rem, 2.5vw, 1.25rem);
      font-weight: 700;
      color: var(--accent-cyan);
      margin: 1.6rem 0 0.65rem;
    }
    .article-body .guide-divider {
      border: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(0, 240, 255, 0.3), transparent);
      margin: 2.2rem 0;
    }
    .article-body .guide-list, .article-body .guide-list-num {
      margin: 0 0 1.4rem 1.4rem;
    }
    .article-body li {
      margin-bottom: 0.45rem;
    }
    .article-body strong {
      color: #fff;
      font-weight: 700;
    }
    .article-body code {
      font-family: var(--font-mono);
      background: rgba(0, 240, 255, 0.1);
      color: #70f5ff;
      padding: 0.15rem 0.4rem;
      border-radius: 4px;
      font-size: 0.88em;
      border: 1px solid rgba(0, 240, 255, 0.2);
      word-break: break-word;
    }
    .article-body pre {
      background: #090e17;
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      padding: 1.1rem;
      overflow-x: auto;
      margin: 1.4rem 0;
      -webkit-overflow-scrolling: touch;
    }
    .article-body pre code {
      background: none;
      border: none;
      padding: 0;
      color: #e2e8f0;
      font-size: 0.92rem;
    }

    /* Callout Boxes */
    .guide-callout {
      margin: 1.4rem 0;
      padding: 0.9rem 1.15rem;
      border-radius: 10px;
      background: rgba(13, 19, 33, 0.9);
      border-left: 4px solid var(--accent-cyan);
      font-size: 0.94rem;
      color: #cbd5e1;
    }
    .guide-callout.callout-warning {
      border-left-color: var(--accent-orange);
      background: rgba(255, 153, 0, 0.08);
    }

    /* Tables with Smooth Mobile Scroll & Sticky First Column Hint */
    .table-responsive {
      overflow-x: auto;
      margin: 1.4rem 0;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      -webkit-overflow-scrolling: touch;
      position: relative;
    }
    .guide-table {
      width: 100%;
      min-width: 520px;
      border-collapse: collapse;
      font-size: 0.88rem;
      text-align: left;
    }
    .guide-table th {
      background: rgba(15, 23, 42, 0.95);
      color: #fff;
      padding: 0.75rem 0.85rem;
      border-bottom: 1px solid rgba(0, 240, 255, 0.25);
      font-weight: 700;
      white-space: nowrap;
    }
    .guide-table td {
      padding: 0.7rem 0.85rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      color: #cbd5e1;
    }
    .guide-table tr:nth-child(even) {
      background: rgba(255, 255, 255, 0.02);
    }

    /* Article Share & Actions */
    .article-share-card {
      margin: 2.5rem 0;
      padding: 1.25rem 1.4rem;
      background: var(--bg-card);
      border: 1px solid var(--border-subtle);
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .share-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.5rem 0.9rem;
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 600;
      background: rgba(255, 255, 255, 0.08);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.12);
      cursor: pointer;
      transition: all 0.2s ease;
      touch-action: manipulation;
    }
    .share-btn:hover {
      background: rgba(0, 240, 255, 0.15);
      border-color: var(--accent-cyan);
      color: var(--accent-cyan);
      text-decoration: none;
    }

    /* PWA Banner CTA */
    .app-cta-banner {
      margin: 2.2rem 0;
      padding: 1.8rem 1.4rem;
      background: linear-gradient(135deg, rgba(0, 240, 255, 0.1) 0%, rgba(255, 42, 75, 0.08) 100%);
      border: 1px solid var(--border-strong);
      border-radius: 16px;
      text-align: center;
    }
    .app-cta-banner h3 {
      font-family: var(--font-display);
      font-size: clamp(1.15rem, 3vw, 1.4rem);
      color: #fff;
      margin-bottom: 0.45rem;
    }
    .app-cta-banner p {
      font-size: 0.88rem;
      color: var(--text-muted);
      margin-bottom: 1.15rem;
      word-break: keep-all;
    }

    /* Related Guides Grid */
    .related-section {
      margin-top: 3rem;
      padding-top: 2rem;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
    }
    .section-title {
      font-family: var(--font-display);
      font-size: 1.2rem;
      font-weight: 800;
      color: #fff;
      margin-bottom: 1.2rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .guides-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 1.15rem;
    }
    .guide-card {
      background: var(--bg-card);
      border: 1px solid var(--border-subtle);
      border-radius: 12px;
      padding: 1.15rem;
      display: flex;
      flex-direction: column;
      text-decoration: none;
      color: inherit;
      transition: all 0.2s ease;
      touch-action: manipulation;
    }
    .guide-card:hover {
      transform: translateY(-3px);
      border-color: var(--border-strong);
      background: var(--bg-card-hover);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
      text-decoration: none;
    }
    .card-thumb {
      width: 100%;
      height: 140px;
      border-radius: 8px;
      object-fit: cover;
      margin-bottom: 0.75rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: #030712;
    }
    .card-meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.65rem;
    }
    .card-title {
      font-family: var(--font-display);
      font-size: 1.02rem;
      font-weight: 700;
      color: #fff;
      line-height: 1.42;
      margin-bottom: 0.45rem;
      word-break: keep-all;
    }
    .card-excerpt {
      font-size: 0.8rem;
      color: var(--text-muted);
      line-height: 1.5;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
      margin-bottom: 0.85rem;
    }
    .card-footer {
      margin-top: auto;
      display: flex;
      justify-content: space-between;
      font-size: 0.74rem;
      color: var(--text-muted);
      padding-top: 0.7rem;
      border-top: 1px solid rgba(255, 255, 255, 0.05);
    }

    /* Catalog Page Specifics */
    .catalog-hero {
      text-align: center;
      padding: 1.5rem 0 2rem;
    }
    .catalog-hero h1 {
      font-family: var(--font-display);
      font-size: clamp(1.8rem, 4.5vw, 2.5rem);
      font-weight: 900;
      color: #fff;
      margin-bottom: 0.65rem;
      letter-spacing: -0.02em;
    }
    .catalog-hero h1 span {
      background: linear-gradient(135deg, #00f0ff 0%, #ff2a4b 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .catalog-hero p {
      font-size: 0.95rem;
      color: var(--text-muted);
      max-width: 620px;
      margin: 0 auto;
      word-break: keep-all;
    }

    /* Category Chips - Swipeable Horizontal Scroll for Mobile */
    .category-chips-wrapper {
      position: relative;
      margin-bottom: 1.75rem;
    }
    .category-chips {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      padding: 0.25rem 0.25rem 0.5rem;
      scrollbar-width: none;
    }
    .category-chips::-webkit-scrollbar {
      display: none;
    }
    .cat-chip {
      padding: 0.4rem 0.85rem;
      border-radius: 9999px;
      font-size: 0.78rem;
      font-weight: 600;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: var(--text-muted);
      text-decoration: none;
      transition: all 0.2s ease;
      white-space: nowrap;
      flex-shrink: 0;
      touch-action: manipulation;
    }
    .cat-chip:hover, .cat-chip.active {
      background: rgba(0, 240, 255, 0.15);
      border-color: var(--accent-cyan);
      color: var(--accent-cyan);
      text-decoration: none;
    }

    /* Toast Notification */
    #toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background: #0f172a;
      border: 1px solid var(--accent-cyan);
      color: #fff;
      padding: 0.7rem 1.15rem;
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(0, 240, 255, 0.3);
      display: none;
      z-index: 1000;
      font-size: 0.85rem;
      max-width: 90vw;
    }

    /* Footer */
    footer.site-footer {
      border-top: 1px solid rgba(255, 255, 255, 0.06);
      background: rgba(6, 9, 14, 0.95);
      padding: 2.2rem 1.25rem;
      font-size: 0.8rem;
      color: var(--text-muted);
      text-align: center;
    }
    .footer-links {
      display: flex;
      justify-content: center;
      gap: 1.2rem;
      margin-bottom: 0.85rem;
      flex-wrap: wrap;
    }

    /* Mobile Enhancements (<640px) */
    @media (max-width: 640px) {
      .header-inner {
        padding: 0.65rem 0.9rem;
      }
      .logo-text {
        font-size: 1.05rem;
      }
      .logo-link img {
        width: 28px;
        height: 28px;
      }
      .btn-app {
        padding: 0.35rem 0.65rem;
        font-size: 0.75rem;
      }
      .lang-btn {
        padding: 0.2rem 0.45rem;
        font-size: 0.7rem;
      }
      .container {
        padding: 1.25rem 0.9rem 3rem;
      }
      .article-header {
        margin-bottom: 1.4rem;
        padding-bottom: 1.1rem;
      }
      .article-body {
        font-size: 0.98rem;
        line-height: 1.8;
      }
      .article-share-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.85rem;
      }
      .article-share-card div:last-child {
        width: 100%;
        display: flex;
      }
      .article-share-card .share-btn {
        flex: 1;
        justify-content: center;
      }
      .guides-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      .app-cta-banner {
        padding: 1.5rem 1rem;
      }
      .app-cta-banner .btn-app {
        width: 100%;
        justify-content: center;
        padding: 0.65rem 1rem;
        font-size: 0.9rem;
      }
    }
  </style>
</head>
<body>

  <!-- Global Header -->
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="logo-link" aria-label="MyLapLog Home">
        <img src="/app/icon-192.png" alt="MyLapLog Logo" onerror="this.src='/favicon.ico'">
        <div class="logo-text">MyLap<span>Log</span></div>
      </a>
      
      <div class="header-actions">
        <!-- Language Switcher (KO / EN) -->
        <div class="lang-switcher" role="group" aria-label="Language Selector">
          <button type="button" class="lang-btn active" id="btn-lang-ko" onclick="changeLanguage('ko')">KO</button>
          <button type="button" class="lang-btn" id="btn-lang-en" onclick="changeLanguage('en')">EN</button>
        </div>

        <a href="/guides" data-i18n="nav_insights" style="font-size:0.84rem; font-weight:600; color:var(--text-main); margin:0 0.2rem;" class="nav-link-guides">인사이트</a>
        
        <a href="/app/" class="btn-app">
          <i data-lucide="gauge" style="width:14px; height:14px;"></i>
          <span data-i18n="nav_open_app">앱 바로가기</span>
        </a>
      </div>
    </div>
  </header>

  <main class="container">
    <?php if ($guide): ?>
      <!-- ============================================== -->
      <!-- SINGLE ARTICLE SSR VIEW (구글 크롤러 & 사용자 최적화) -->
      <!-- ============================================== -->
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="/" data-i18n="bc_home">홈</a>
        <i data-lucide="chevron-right" style="width:14px; height:14px;"></i>
        <a href="/guides" data-i18n="bc_insights">인사이트</a>
        <i data-lucide="chevron-right" style="width:14px; height:14px;"></i>
        <span class="i18n-cat" data-cat="<?= htmlspecialchars($guide['category']) ?>"><?= htmlspecialchars($guide['category']) ?></span>
      </nav>

      <article lang="ko">
        <header class="article-header">
          <div class="article-meta-badges">
            <span class="badge badge-cyan i18n-cat" data-cat="<?= htmlspecialchars($guide['category']) ?>"><?= htmlspecialchars($guide['category']) ?></span>
            <?php if (!empty($guide['is_featured'])): ?>
            <span class="badge badge-red" data-i18n="badge_featured">⭐ 추천 아티클</span>
            <?php endif; ?>
            <span style="font-size:0.75rem; color:var(--text-muted); display:inline-flex; align-items:center; gap:0.25rem;">
              <i data-lucide="clock" style="width:12px; height:12px;"></i> <span data-i18n-readtime="<?= htmlspecialchars($guide['read_time'] ?: '3분') ?>"><?= htmlspecialchars($guide['read_time'] ?: '3분') ?> 읽기</span>
            </span>
            <span style="font-size:0.75rem; color:var(--text-muted); display:inline-flex; align-items:center; gap:0.25rem;">
              <i data-lucide="eye" style="width:12px; height:12px;"></i> <span data-i18n-views="<?= number_format($guide['views']) ?>"><?= number_format($guide['views']) ?>회 조회</span>
            </span>
          </div>

          <h1 class="article-title" lang="ko"><?= htmlspecialchars($guide['title']) ?></h1>

          <div class="article-author-row">
            <div class="author-info">
              <div class="author-avatar"><?= mb_substr($guide['author_name'] ?: 'ML', 0, 2) ?></div>
              <div>
                <strong style="color:#fff; display:block;" data-i18n="author_name"><?= htmlspecialchars($guide['author_name'] ?: 'MyLapLog 인텔리전스') ?></strong>
                <time datetime="<?= date('Y-m-d', strtotime($guide['created_at'])) ?>" style="font-size:0.75rem;">
                  <?= date('Y년 m월 d일', strtotime($guide['created_at'])) ?>
                </time>
              </div>
            </div>
            <div style="display:flex; gap:0.5rem;">
              <button type="button" class="share-btn" onclick="copyCurrentUrl()">
                <i data-lucide="link" style="width:14px; height:14px;"></i> <span data-i18n="btn_copy_link">링크 복사</span>
              </button>
            </div>
          </div>
        </header>

        <?php if (!empty($guide['cover_image'])): ?>
        <div class="article-cover-wrapper">
          <img src="<?= htmlspecialchars($guide['cover_image']) ?>" alt="<?= htmlspecialchars($guide['title']) ?>" class="article-cover-img" loading="lazy">
        </div>
        <?php endif; ?>

        <!-- Article Content (SEO Markdown to Semantic HTML) -->
        <section class="article-body" lang="ko">
          <?= parseGuideMarkdown($guide['content']) ?>
        </section>

        <!-- Share & Actions Card -->
        <div class="article-share-card">
          <div>
            <h4 style="font-size:0.95rem; font-weight:700; color:#fff; margin-bottom:0.2rem;" data-i18n="share_title">이 인사이트가 도움 되셨나요?</h4>
            <p style="font-size:0.8rem; color:var(--text-muted);" data-i18n="share_desc">동료 드라이버와 크루원들에게 링크를 공유해보세요.</p>
          </div>
          <div style="display:flex; gap:0.5rem;">
            <button type="button" class="share-btn" onclick="copyCurrentUrl()">
              <i data-lucide="share-2" style="width:14px; height:14px;"></i> <span data-i18n="btn_share">공유하기</span>
            </button>
            <a href="/guides" class="share-btn">
              <i data-lucide="list" style="width:14px; height:14px;"></i> <span data-i18n="btn_all_guides">전체 목록</span>
            </a>
          </div>
        </div>

        <!-- App CTA Banner -->
        <div class="app-cta-banner">
          <h3 data-i18n="cta_title">나만의 서킷 셋업 & 랩타임을 MyLapLog에 기록하세요</h3>
          <p data-i18n="cta_desc">트랙데이 현장에서 4바퀴 냉간/열간 공기압 실측치와 감쇠력 클릭을 스마트하게 관리하고 랩타임을 줄여보세요.</p>
          <a href="/app/" class="btn-app" style="font-size:0.92rem; padding:0.6rem 1.3rem;">
            <i data-lucide="zap" style="width:16px; height:16px;"></i> <span data-i18n="cta_btn">MyLapLog 무료로 시작하기</span>
          </a>
        </div>

        <!-- Related Guides -->
        <?php if (!empty($relatedGuides)): ?>
        <section class="related-section">
          <h2 class="section-title">
            <i data-lucide="compass" style="width:20px; height:20px; color:var(--accent-cyan);"></i>
            <span data-i18n="related_title">함께 읽으면 좋은 추천 인사이트</span>
          </h2>
          <div class="guides-grid">
            <?php foreach ($relatedGuides as $rg): ?>
            <a href="/guides/<?= rawurlencode($rg['slug']) ?>" class="guide-card">
              <?php if (!empty($rg['cover_image'])): ?>
              <img src="<?= htmlspecialchars($rg['cover_image']) ?>" alt="<?= htmlspecialchars($rg['title']) ?>" class="card-thumb" loading="lazy">
              <?php endif; ?>
              <div class="card-meta">
                <span class="badge badge-cyan i18n-cat" data-cat="<?= htmlspecialchars($rg['category']) ?>"><?= htmlspecialchars($rg['category']) ?></span>
                <span style="font-size:0.72rem; color:var(--text-muted);"><i data-lucide="clock" style="width:11px; height:11px;"></i> <?= htmlspecialchars($rg['read_time'] ?: '3분') ?></span>
              </div>
              <h3 class="card-title" lang="ko"><?= htmlspecialchars($rg['title']) ?></h3>
              <div class="card-footer">
                <span><?= date('Y-m-d', strtotime($rg['created_at'])) ?></span>
                <span><i data-lucide="eye" style="width:11px; height:11px; vertical-align:middle;"></i> <?= number_format($rg['views']) ?></span>
              </div>
            </a>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

      </article>

    <?php else: ?>
      <!-- ============================================== -->
      <!-- GUIDES CATALOG VIEW (인사이트 전체 목록 SSR) -->
      <!-- ============================================== -->
      <section class="catalog-hero">
        <h1>MyLapLog <span data-i18n="hero_title_highlight">인사이트 &amp; 가이드</span></h1>
        <p data-i18n="hero_desc">모터스포츠 기술 아카이브. 레이싱 드라이빙과 엔지니어링 정보를 다룹니다. 서킷 주행 라인, 레이싱 기록, 실전 트랙 세팅 등 랩타임 단축을 위한 데이터와 메커니즘을 제공합니다.</p>
      </section>

      <!-- Category Filter Chips (Horizontal Swipe for Mobile) -->
      <div class="category-chips-wrapper">
        <div class="category-chips">
          <a href="/guides" class="cat-chip <?= $activeCategory === '' ? 'active' : '' ?>" data-i18n="cat_all">전체 보기</a>
          <a href="/guides?category=서킷+공략" class="cat-chip <?= $activeCategory === '서킷 공략' ? 'active' : '' ?>" data-i18n="cat_track">🏁 서킷 공략</a>
          <a href="/guides?category=타이어/공기압" class="cat-chip <?= $activeCategory === '타이어/공기압' ? 'active' : '' ?>" data-i18n="cat_tire">🛞 타이어/공기압</a>
          <a href="/guides?category=셋업+노하우" class="cat-chip <?= $activeCategory === '셋업 노하우' ? 'active' : '' ?>" data-i18n="cat_setup">⚙️ 셋업 노하우</a>
          <a href="/guides?category=트랙데이+팁" class="cat-chip <?= $activeCategory === '트랙데이 팁' ? 'active' : '' ?>" data-i18n="cat_tips">⏱️ 트랙데이 팁</a>
        </div>
      </div>

      <!-- Guides Grid -->
      <div class="guides-grid">
        <?php if (!empty($allGuides)): ?>
          <?php foreach ($allGuides as $g): ?>
          <a href="/guides/<?= rawurlencode($g['slug']) ?>" class="guide-card">
            <?php if (!empty($g['cover_image'])): ?>
            <img src="<?= htmlspecialchars($g['cover_image']) ?>" alt="<?= htmlspecialchars($g['title']) ?>" class="card-thumb" loading="lazy">
            <?php endif; ?>
            <div class="card-meta">
              <span class="badge badge-cyan i18n-cat" data-cat="<?= htmlspecialchars($g['category']) ?>"><?= htmlspecialchars($g['category']) ?></span>
              <span style="font-size:0.72rem; color:var(--text-muted); display:inline-flex; align-items:center; gap:0.25rem;">
                <i data-lucide="clock" style="width:11px; height:11px;"></i> <?= htmlspecialchars($g['read_time'] ?: '3분') ?>
              </span>
            </div>
            <h2 class="card-title" lang="ko"><?= htmlspecialchars($g['title']) ?></h2>
            <p class="card-excerpt" lang="ko"><?= htmlspecialchars($g['excerpt'] ?: '') ?></p>
            <div class="card-footer">
              <span><?= date('Y-m-d', strtotime($g['created_at'])) ?></span>
              <span><i data-lucide="eye" style="width:11px; height:11px; vertical-align:middle;"></i> <?= number_format($g['views']) ?></span>
            </div>
          </a>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="grid-column: 1 / -1; text-align:center; padding:4rem 1rem; color:var(--text-muted);">
            <p data-i18n="empty_guides">등록된 인사이트 가이드 글이 없습니다.</p>
          </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </main>

  <!-- Global Footer -->
  <footer class="site-footer">
    <div class="footer-links">
      <a href="/guides" data-i18n="footer_insights">인사이트 가이드</a>
      <a href="/app/" data-i18n="footer_app">웹앱 접속</a>
      <a href="/app/privacy.html" data-i18n="footer_privacy">개인정보처리방침</a>
      <a href="/sitemap.xml" data-i18n="footer_sitemap">XML 사이트맵</a>
    </div>
    <p>&copy; <?= date('Y') ?> MyLapLog. All rights reserved. Motorsport Intelligence Platform.</p>
  </footer>

  <div id="toast">링크가 클립보드에 복사되었습니다! 🔗</div>

  <!-- Google Translate Hidden Container -->
  <div id="google_translate_element" style="display:none;" aria-hidden="true"></div>

  <!-- Multilingual & Interactive Client Script -->
  <script>
    lucide.createIcons();

    // =============================================
    // I18N DICTIONARY (KO / EN)
    // =============================================
    const I18N = {
      ko: {
        nav_insights: '인사이트',
        nav_open_app: '앱 바로가기',
        bc_home: '홈',
        bc_insights: '인사이트',
        badge_featured: '⭐ 추천 아티클',
        author_name: 'MyLapLog 인텔리전스',
        btn_copy_link: '링크 복사',
        share_title: '이 인사이트가 도움 되셨나요?',
        share_desc: '동료 드라이버와 크루원들에게 링크를 공유해보세요.',
        btn_share: '공유하기',
        btn_all_guides: '전체 목록',
        cta_title: '나만의 서킷 셋업 & 랩타임을 MyLapLog에 기록하세요',
        cta_desc: '트랙데이 현장에서 4바퀴 냉간/열간 공기압 실측치와 감쇠력 클릭을 스마트하게 관리하고 랩타임을 줄여보세요.',
        cta_btn: 'MyLapLog 무료로 시작하기',
        related_title: '함께 읽으면 좋은 추천 인사이트',
        hero_title_highlight: '인사이트 & 가이드',
        hero_desc: '모터스포츠 기술 아카이브. 레이싱 드라이빙과 엔지니어링 정보를 다룹니다. 서킷 주행 라인, 레이싱 기록, 실전 트랙 세팅 등 랩타임 단축을 위한 데이터와 메커니즘을 제공합니다.',
        cat_all: '전체 보기',
        cat_track: '🏁 서킷 공략',
        cat_tire: '🛞 타이어/공기압',
        cat_setup: '⚙️ 셋업 노하우',
        cat_tips: '⏱️ 트랙데이 팁',
        empty_guides: '등록된 인사이트 가이드 글이 없습니다.',
        footer_insights: '인사이트 가이드',
        footer_app: '웹앱 접속',
        footer_privacy: '개인정보처리방침',
        footer_sitemap: 'XML 사이트맵',
        toast_copied: '링크가 클립보드에 복사되었습니다! 🔗'
      },
      en: {
        nav_insights: 'Insights',
        nav_open_app: 'Open App',
        bc_home: 'Home',
        bc_insights: 'Insights',
        badge_featured: '⭐ Featured Article',
        author_name: 'MyLapLog Intelligence',
        btn_copy_link: 'Copy Link',
        share_title: 'Found this insight helpful?',
        share_desc: 'Share this guide with your fellow racing drivers and pit crew.',
        btn_share: 'Share',
        btn_all_guides: 'All Insights',
        cta_title: 'Log your setups & lap times with MyLapLog',
        cta_desc: 'Manage cold/hot tire pressures, damper clicks, and session lap times directly at the track to shave seconds off.',
        cta_btn: 'Get Started with MyLapLog Free',
        related_title: 'Recommended Insights',
        hero_title_highlight: 'Insights & Guides',
        hero_desc: 'Motorsport technical archive covering racing driving and vehicle engineering. We provide real-world track setup data, racing lines, and mechanisms to help you shave seconds off your lap times.',
        cat_all: 'All',
        cat_track: '🏁 Track Attack',
        cat_tire: '🛞 Tire & Pressure',
        cat_setup: '⚙️ Setup Tips',
        cat_tips: '⏱️ Trackday Tips',
        empty_guides: 'No published insight guides found.',
        footer_insights: 'Insights Guide',
        footer_app: 'Web App',
        footer_privacy: 'Privacy Policy',
        footer_sitemap: 'XML Sitemap',
        toast_copied: 'Link copied to clipboard! 🔗'
      }
    };

    const CAT_I18N = {
      '서킷 공략': { ko: '서킷 공략', en: 'Track Attack' },
      '타이어/공기압': { ko: '타이어/공기압', en: 'Tire & Pressure' },
      '셋업 노하우': { ko: '셋업 노하우', en: 'Setup Tips' },
      '트랙데이 팁': { ko: '트랙데이 팁', en: 'Trackday Tips' },
      '레이싱 철학': { ko: '레이싱 철학', en: 'Racing Mindset' }
    };

    let currentLang = 'ko';

    function initLanguage() {
      const urlParams = new URLSearchParams(window.location.search);
      const urlLang = urlParams.get('lang');
      const savedLang = localStorage.getItem('mylaplog_lang');
      const hasGoogCookie = document.cookie.includes('googtrans=/ko/en') || document.cookie.includes('googtrans=%2Fko%2Fen');

      if (urlLang && (urlLang === 'ko' || urlLang === 'en')) {
        currentLang = urlLang;
      } else if (savedLang && (savedLang === 'ko' || savedLang === 'en')) {
        currentLang = savedLang;
      } else if (hasGoogCookie) {
        currentLang = 'en';
      } else {
        const browserLang = (navigator.language || '').toLowerCase();
        if (browserLang.startsWith('en')) {
          currentLang = 'en';
        } else {
          currentLang = 'ko';
        }
      }

      applyLanguage(currentLang);
    }

    function changeLanguage(lang) {
      currentLang = lang;
      localStorage.setItem('mylaplog_lang', lang);

      // Set/Clear Google Translate Cookie
      if (lang === 'en') {
        document.cookie = "googtrans=/ko/en; path=/; domain=" + window.location.hostname;
        document.cookie = "googtrans=/ko/en; path=/";
      } else {
        document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=" + window.location.hostname;
        document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
        document.cookie = "googtrans=/ko/ko; path=/; domain=" + window.location.hostname;
        document.cookie = "googtrans=/ko/ko; path=/";
      }

      // Update URL query param quietly
      if (window.history.replaceState) {
        const url = new URL(window.location.href);
        if (lang === 'en') {
          url.searchParams.set('lang', 'en');
        } else {
          url.searchParams.delete('lang');
        }
        window.history.replaceState(null, '', url.toString());
      }

      applyLanguage(lang);

      // Trigger Google Translate engine directly
      const selectEl = document.querySelector('.goog-te-combo');
      if (selectEl) {
        selectEl.value = lang;
        selectEl.dispatchEvent(new Event('change'));
      } else {
        window.location.reload();
      }
    }

    function applyLanguage(lang) {
      // Update Toggle Buttons
      const btnKo = document.getElementById('btn-lang-ko');
      const btnEn = document.getElementById('btn-lang-en');
      if (btnKo) btnKo.classList.toggle('active', lang === 'ko');
      if (btnEn) btnEn.classList.toggle('active', lang === 'en');

      const dict = I18N[lang] || I18N.ko;

      // Translate static i18n keys
      document.querySelectorAll('[data-i18n]').forEach((el) => {
        const key = el.getAttribute('data-i18n');
        if (dict[key]) {
          el.innerText = dict[key];
        }
      });

      // Translate category badges
      document.querySelectorAll('.i18n-cat').forEach((el) => {
        const cat = el.getAttribute('data-cat') || el.innerText.trim();
        if (CAT_I18N[cat] && CAT_I18N[cat][lang]) {
          el.innerText = CAT_I18N[cat][lang];
        }
      });

      // Translate read time & view format
      document.querySelectorAll('[data-i18n-readtime]').forEach((el) => {
        const timeVal = el.getAttribute('data-i18n-readtime');
        const num = (timeVal.match(/\d+/) || ['3'])[0];
        el.innerText = lang === 'en' ? `${num} min read` : `${num}분 읽기`;
      });

      document.querySelectorAll('[data-i18n-views]').forEach((el) => {
        const viewVal = el.getAttribute('data-i18n-views');
        el.innerText = lang === 'en' ? `${viewVal} views` : `${viewVal}회 조회`;
      });
    }

    // Google Translate Initialization
    function googleTranslateElementInit() {
      new google.translate.TranslateElement({
        pageLanguage: 'ko',
        includedLanguages: 'ko,en',
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
        autoDisplay: false
      }, 'google_translate_element');

      // Sync Google Translate with currentLang
      setTimeout(syncGoogleTranslate, 300);
      setTimeout(syncGoogleTranslate, 1000);
      setTimeout(syncGoogleTranslate, 2000);
    }

    function syncGoogleTranslate() {
      const selectEl = document.querySelector('.goog-te-combo');
      if (selectEl) {
        if (currentLang === 'en' && selectEl.value !== 'en') {
          selectEl.value = 'en';
          selectEl.dispatchEvent(new Event('change'));
        } else if (currentLang === 'ko' && selectEl.value !== 'ko' && selectEl.value !== '') {
          selectEl.value = 'ko';
          selectEl.dispatchEvent(new Event('change'));
        }
      }
      purgeGoogleBanner();
    }

    // Google Translate Top Banner Auto-remover
    function purgeGoogleBanner() {
      const elList = document.querySelectorAll('.goog-te-banner-frame, iframe.goog-te-banner-frame, .goog-te-banner, body > .skiptranslate');
      elList.forEach(el => {
        el.style.setProperty('display', 'none', 'important');
        el.style.setProperty('visibility', 'hidden', 'important');
        el.style.setProperty('height', '0', 'important');
      });
      if (document.body.style.top && document.body.style.top !== '0px') {
        document.body.style.setProperty('top', '0px', 'important');
      }
    }
    setInterval(purgeGoogleBanner, 100);

    // Copy URL
    function copyCurrentUrl() {
      const url = window.location.href;
      const dict = I18N[currentLang] || I18N.ko;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => showToast(dict.toast_copied)).catch(() => {
          fallbackCopy(url);
          showToast(dict.toast_copied);
        });
      } else {
        fallbackCopy(url);
        showToast(dict.toast_copied);
      }
    }

    function fallbackCopy(text) {
      const el = document.createElement('textarea');
      el.value = text;
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }

    function showToast(msg) {
      const t = document.getElementById('toast');
      if (!t) return;
      if (msg) t.innerText = msg;
      t.style.display = 'block';
      setTimeout(() => {
        t.style.display = 'none';
      }, 2500);
    }

    // Initialize on DOM load
    document.addEventListener('DOMContentLoaded', initLanguage);
    initLanguage();
  </script>
  <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</body>
</html>
