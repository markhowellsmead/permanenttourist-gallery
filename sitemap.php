<?php

/**
 * Sitemap Endpoint
 *
 * Serves generated sitemap.xml via /sitemap/ route.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$sitemapFile = __DIR__ . '/sitemap.xml';

if (!is_file($sitemapFile)) {
    http_response_code(404);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<error>Sitemap not found. Run /build first.</error>' . PHP_EOL;
    exit;
}

$content = file_get_contents($sitemapFile);
if ($content === false) {
    http_response_code(500);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<error>Failed to read sitemap file.</error>' . PHP_EOL;
    exit;
}

http_response_code(200);
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo $content;
