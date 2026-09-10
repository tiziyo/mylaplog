<?php
/**
 * MyLapLog Dynamic Sitemap Generator
 * - Generates W3C & Google Search Console compliant XML Sitemap with hreflang alternate URLs
 * - Auto-includes all PUBLISHED guides from DB
 * - Serves at /sitemap.xml (and /sitemap.php)
 * - Auto-syncs static sitemap.xml file on disk for 100% crawler reliability
 */

// Strict XML headers
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: all');

$DB_HOST = 'localhost';
$DB_NAME = 'mylaplog';
$DB_USER = 'admin';
$DB_PASS = 'StnXoa2w4DO8KE9V';
$BASE    = 'https://www.mylaplog.com';
$TODAY   = date('Y-m-d');

$guides = [];
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $stmt = $pdo->query("SELECT slug, updated_at, created_at FROM guides WHERE status = 'PUBLISHED' ORDER BY id DESC");
    $guides = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: empty guides list
}

function xmlEscape($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">

  <!-- === App Main Dashboard (Canonical 200 OK) === -->
  <url>
    <loc><?= xmlEscape($BASE . '/app/') ?></loc>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>

  <!-- === Guides Catalog (Korean) === -->
  <url>
    <loc><?= xmlEscape($BASE . '/guides') ?></loc>
    <xhtml:link rel="alternate" hreflang="ko" href="<?= xmlEscape($BASE . '/guides') ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= xmlEscape($BASE . '/guides?lang=en') ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= xmlEscape($BASE . '/guides') ?>"/>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>

  <!-- === Guides Catalog (English) === -->
  <url>
    <loc><?= xmlEscape($BASE . '/guides?lang=en') ?></loc>
    <xhtml:link rel="alternate" hreflang="ko" href="<?= xmlEscape($BASE . '/guides') ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= xmlEscape($BASE . '/guides?lang=en') ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= xmlEscape($BASE . '/guides') ?>"/>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
  </url>

  <!-- === Dynamic Guide Articles (DB-driven) === -->
<?php foreach ($guides as $g): ?>
<?php
    $slug    = rawurlencode($g['slug']);
    $lastmod = date('Y-m-d', strtotime($g['updated_at'] ?? $g['created_at'] ?? 'now'));
    $koUrl   = $BASE . '/guides/' . $slug;
    $enUrl   = $BASE . '/guides/' . $slug . '?lang=en';
?>
  <url>
    <loc><?= xmlEscape($koUrl) ?></loc>
    <xhtml:link rel="alternate" hreflang="ko"        href="<?= xmlEscape($koUrl) ?>"/>
    <xhtml:link rel="alternate" hreflang="en"        href="<?= xmlEscape($enUrl) ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= xmlEscape($koUrl) ?>"/>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>

  <url>
    <loc><?= xmlEscape($enUrl) ?></loc>
    <xhtml:link rel="alternate" hreflang="ko"        href="<?= xmlEscape($koUrl) ?>"/>
    <xhtml:link rel="alternate" hreflang="en"        href="<?= xmlEscape($enUrl) ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= xmlEscape($koUrl) ?>"/>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>

</urlset>
<?php
$xmlOutput = ob_get_clean();

// Auto-sync static sitemap.xml on disk for guaranteed crawler reliability
$staticPath = __DIR__ . '/sitemap.xml';
if (is_writable(__DIR__) || (file_exists($staticPath) && is_writable($staticPath))) {
    @file_put_contents($staticPath, $xmlOutput);
}

echo $xmlOutput;

