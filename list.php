<?php

/**
 * List View Template
 *
 * Renders the main gallery HTML page with cache-busted asset references.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Support\AssetHelper;
use PT\Gallery\Support\MetadataHelper;
use PT\Gallery\Support\RequestHelper;

$listCssUrl = AssetHelper::cacheBustedAsset('list.css', true);
$appJsUrl = AssetHelper::cacheBustedAsset('app.js', true);

// Detect if we're on a photo detail page
$path = rtrim(RequestHelper::getRequestPath($_SERVER), '/');
$photoId = null;
$photoData = null;

if (preg_match('#^/photo/([^/]+)/?$#', $path, $matches)) {
	$photoId = $matches[1];

	// Load media.json to get photo data
	$mediaJsonFile = __DIR__ . '/media/media.json';
	if (file_exists($mediaJsonFile)) {
		$content = @file_get_contents($mediaJsonFile);
		if ($content !== false) {
			$allMedia = json_decode($content, true);
			if (is_array($allMedia)) {
				// Find the photo by matching the filename without extension
				foreach ($allMedia as $item) {
					if (isset($item['url']) && is_string($item['url'])) {
						if (preg_match('/\/([^\/]+)\.(jpe?g|png|gif|webp|avif)$/i', $item['url'], $urlMatches)) {
							if ($urlMatches[1] === $photoId) {
								$photoData = $item;
								break;
							}
						}
					}
				}
			}
		}
	}
}

// Prepare meta tag data for photo detail pages
$pageTitle = 'Permanent Tourist photographic archive';
$canonicalUrl = null;
$ogTitle = null;
$ogDescription = null;
$ogImage = null;
$ogUrl = null;

if ($photoData !== null && $photoId !== null) {
	$title = MetadataHelper::extractTitle($photoData);
	$location = MetadataHelper::extractLocation($photoData);
	$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

	$pageTitle = $title . ' – Permanent Tourist photographic archive';
	$canonicalUrl = $baseUrl . '/photo/' . rawurlencode($photoId) . '/';
	$ogTitle = $title;
	$ogDescription = $title . ' · ' . $location;
	$ogImage = $baseUrl . $photoData['url'];
	$ogUrl = $canonicalUrl;
}
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
	<?php
	// Inline CSS from list.css while keeping the source file on disk.
	$cssFile = __DIR__ . '/list.css';
	if (is_readable($cssFile)) {
		echo "<style>\n" . str_replace('</style>', '<\/style>', @file_get_contents($cssFile)) . "\n</style>\n";
	} else {
		echo '<link rel="stylesheet" href="' . htmlspecialchars($listCssUrl, ENT_QUOTES, 'UTF-8') . '">';
	}
	?>
	<link rel="sitemap" type="application/xml" title="Sitemap" href="/sitemap.xml">
	<?php
	// Expose application version from README 'Version:' line
	$readmeFile = __DIR__ . '/README.md';
	if (is_readable($readmeFile)) {
		$readme = @file_get_contents($readmeFile);
		if ($readme !== false && preg_match('/^Version:\s*(.+)$/mi', $readme, $m)) {
			$version = trim($m[1]);
			echo '<meta name="app-version" content="' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '">';
		}
	}
	?>
	<?php if ($canonicalUrl !== null): ?>
		<link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
	<?php endif; ?>
	<?php if ($ogTitle !== null && $ogDescription !== null && $ogImage !== null && $ogUrl !== null): ?>
		<meta property="og:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
		<meta property="og:description" content="<?php echo htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>">
		<meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
		<meta property="og:url" content="<?php echo htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8'); ?>">
		<meta property="og:type" content="website">
		<meta property="og:site_name" content="Permanent Tourist photographic archive">
		<meta name="twitter:card" content="summary_large_image">
		<meta name="twitter:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
		<meta name="twitter:description" content="<?php echo htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8'); ?>">
		<meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
	<?php endif; ?>
</head>

<body>
	<h1>Permanent Tourist photographic archive</h1>
	<p>Photos by Mark Howells-Mead. (<a href="https://www.permanenttourist.ch/">Main website</a>)</p>
	<div id="status">Loading…</div>
	<ul id="image-list"></ul>
	<div id="detail-view" class="detail-view" hidden></div>

	<?php
	// Inline JS from app.js while keeping the source file on disk.
	$jsFile = __DIR__ . '/app.js';
	if (is_readable($jsFile)) {
		// read and output raw JS
		$js = @file_get_contents($jsFile);
		if ($js !== false) {
			echo "<script>\n" . str_replace('</script>', '<\/script>', $js) . "\n</script>\n";
		} else {
			echo '<script src="' . htmlspecialchars($appJsUrl, ENT_QUOTES, 'UTF-8') . '"></script>';
		}
	} else {
		echo '<script src="' . htmlspecialchars($appJsUrl, ENT_QUOTES, 'UTF-8') . '"></script>';
	}
	?>
</body>

</html>
