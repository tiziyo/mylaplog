<?php
/**
 * Dynamic XML Sitemap Generator for MyLapLog
 * - Google Search Console / Googlebot / Naver / Bing Sitemap Standard (sitemaps.org)
 * - Automatically fetches all published guides from MariaDB
 */

header('Content-Type: application/xml; charset=utf-8');

$DB_HOST = 'localhost';
$DB_NAME = 'mylaplog';
$DB_USER = 'admin';
$DB_PASS = 'StnXoa2w4DO8KE9V';

$baseUrl = 'https://www.mylaplog.com';
$guides = [];

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $stmt = $pdo->query('
        SELECT slug, updated_at, created_at
        FROM guides
        WHERE status = "PUBLISHED"
        ORDER BY id DESC
    ');
    $guides = $stmt->fetchAll();
} catch (Exception $e) {
    // Fallback seed list if DB error occurs
    $guides = [
        ['slug' => 'injespeedium-racing-guide', 'updated_at' => '2026-09-09 10:00:00'],
        ['slug' => 'tire-cold-hot-pressure-master', 'updated_at' => '2026-09-09 14:30:00'],
        ['slug' => 'understeer-camber-damper-setup', 'updated_at' => '2026-09-09 09:15:00'],
        ['slug' => 'trackday-pit-operation-roadmap', 'updated_at' => '2026-09-09 13:45:00']
    ];
}

$today = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <!-- Main Home Page -->
  <url>
    <loc><?= $baseUrl ?>/</loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>

  <!-- PWA Web App Dashboard -->
  <url>
    <loc><?= $baseUrl ?>/app/</loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>

  <!-- Insights & Guides Catalog -->
  <url>
    <loc><?= $baseUrl ?>/guides</loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>

  <!-- Published Insight Articles (Clean SSR URLs for Googlebot) -->
  <?php foreach ($guides as $g): 
    $lastModDate = !empty($g['updated_at']) ? date('Y-m-d', strtotime($g['updated_at'])) : (!empty($g['created_at']) ? date('Y-m-d', strtotime($g['created_at'])) : $today);
  ?>
  <url>
    <loc><?= $baseUrl ?>/guides/<?= htmlspecialchars($g['slug'], ENT_XML1, 'UTF-8') ?></loc>
    <lastmod><?= $lastModDate ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
  <?php endforeach; ?>
</urlset>
