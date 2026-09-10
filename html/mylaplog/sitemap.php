<?php
/**
 * MyLapLog Dynamic Sitemap Generator
 * - Generates XML Sitemap with hreflang alternate URLs for KO/EN
 * - Auto-includes all PUBLISHED guides from DB
 * - Served at /sitemap.xml via .htaccess rewrite
 */

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

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

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">

  <!-- === Static Pages === -->
  <url>
    <loc><?= $BASE ?>/</loc>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>

  <url>
    <loc><?= $BASE ?>/app/</loc>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>

  <!-- Guides Catalog (Korean) -->
  <url>
    <loc><?= $BASE ?>/guides</loc>
    <xhtml:link rel="alternate" hreflang="ko" href="<?= $BASE ?>/guides"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= $BASE ?>/guides?lang=en"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= $BASE ?>/guides"/>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>

  <!-- Guides Catalog (English) -->
  <url>
    <loc><?= $BASE ?>/guides?lang=en</loc>
    <xhtml:link rel="alternate" hreflang="ko" href="<?= $BASE ?>/guides"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= $BASE ?>/guides?lang=en"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= $BASE ?>/guides"/>
    <lastmod><?= $TODAY ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
  </url>

  <!-- === Dynamic Guide Articles (DB-driven) === -->
<?php foreach ($guides as $g): ?>
<?php
    $slug    = rawurlencode($g['slug']);
    $lastmod = date('Y-m-d', strtotime($g['updated_at'] ?? $g['created_at']));
    $koUrl   = $BASE . '/guides/' . $slug;
    $enUrl   = $BASE . '/guides/' . $slug . '?lang=en';
?>
  <url>
    <loc><?= $koUrl ?></loc>
    <xhtml:link rel="alternate" hreflang="ko"        href="<?= $koUrl ?>"/>
    <xhtml:link rel="alternate" hreflang="en"        href="<?= $enUrl ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= $koUrl ?>"/>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>

  <url>
    <loc><?= $enUrl ?></loc>
    <xhtml:link rel="alternate" hreflang="ko"        href="<?= $koUrl ?>"/>
    <xhtml:link rel="alternate" hreflang="en"        href="<?= $enUrl ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= $koUrl ?>"/>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
  </url>
<?php endforeach; ?>

</urlset>
