<?php

/**
 * Sitemap XML Builder
 *
 * Generates sitemap XML from the media index data.
 *
 * @package PT\Gallery\Build
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery\Build;

/**
 * Builder class for generating sitemap.xml
 */
final class SitemapXmlBuilder
{
	/**
	 * Build sitemap index and photo sitemap from media.json
	 *
	 * @param string $mediaJsonFile Path to media JSON file
	 * @param string $sitemapFile   Path to output sitemap index XML file
	 * @param string $baseUrl       Absolute base URL for sitemap entries
	 *
	 * @return int Number of URLs written to the photo sitemap
	 * @throws \RuntimeException If media JSON cannot be read or sitemap files cannot be written
	 */
	public function buildFromMediaJson(string $mediaJsonFile, string $sitemapFile, string $baseUrl): int
	{
		$content = @file_get_contents($mediaJsonFile);
		if ($content === false) {
			throw new \RuntimeException("Failed to read media JSON file: {$mediaJsonFile}");
		}

		$data = json_decode($content, true);
		if (!is_array($data)) {
			throw new \RuntimeException("Invalid media JSON format: {$mediaJsonFile}");
		}

		$normalizedBaseUrl = rtrim($baseUrl, '/');
		$urlEntries = [
			[
				'loc' => $normalizedBaseUrl . '/',
				'lastmod' => null,
				'image' => null,
			],
		];

		foreach ($data as $item) {
			if (!is_array($item) || !isset($item['url']) || !is_string($item['url'])) {
				continue;
			}

			$photoId = $this->extractPhotoIdFromMediaUrl($item['url']);
			if ($photoId === null) {
				continue;
			}

			$urlEntries[] = [
				'loc' => $normalizedBaseUrl . '/photo/' . rawurlencode($photoId) . '/',
				'lastmod' => $this->extractLastModDate($item),
				'image' => $normalizedBaseUrl . $item['url'],
			];
		}

		$photoSitemapFile = $this->getPhotoSitemapFilePath($sitemapFile);
		$photoSitemapUrl = $normalizedBaseUrl . '/' . basename($photoSitemapFile);

		$photoSitemapXml = $this->renderUrlsetXml($urlEntries);
		if (file_put_contents($photoSitemapFile, $photoSitemapXml) === false) {
			throw new \RuntimeException("Failed to write photo sitemap file: {$photoSitemapFile}");
		}

		$indexXml = $this->renderSitemapIndexXml($photoSitemapUrl, gmdate('Y-m-d\TH:i:sP'));
		if (file_put_contents($sitemapFile, $indexXml) === false) {
			throw new \RuntimeException("Failed to write sitemap index file: {$sitemapFile}");
		}

		return count($urlEntries);
	}

	/**
	 * Derive child photo sitemap file path from sitemap index path
	 *
	 * Example: sitemap.xml -> photo-sitemap.xml
	 *
	 * @param string $sitemapFile Sitemap index file path
	 *
	 * @return string Photo sitemap file path
	 */
	private function getPhotoSitemapFilePath(string $sitemapFile): string
	{
		$dir = dirname($sitemapFile);
		return $dir . '/photo-sitemap.xml';
	}

	/**
	 * Extract photo ID from media URL
	 *
	 * Converts '/media/20170919-_DSF1840.jpg' to '20170919-_DSF1840'.
	 *
	 * @param string $url Media URL
	 *
	 * @return string|null Photo ID or null if no valid match
	 */
	private function extractPhotoIdFromMediaUrl(string $url): ?string
	{
		if (preg_match('/\/([^\/]+)\.(jpe?g|png|gif|webp|avif)$/i', $url, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Extract last modification date from media item
	 *
	 * @param array<string, mixed> $item Media item from JSON
	 *
	 * @return string|null Date in ISO 8601 format or null if not available
	 */
	private function extractLastModDate(array $item): ?string
	{
		// Try IPTC date_created and time_created fields
		if (
			isset($item['iptc']['date_created']['value'][0])
			&& is_string($item['iptc']['date_created']['value'][0])
		) {
			$dateString = $item['iptc']['date_created']['value'][0];
			$timeString = $item['iptc']['time_created']['value'][0] ?? '000000';

			// Format: YYYYMMDD + HHMMSS -> ISO 8601
			if (
				preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateString, $dateMatches) === 1
				&& is_string($timeString)
				&& preg_match('/^(\d{2})(\d{2})(\d{2})$/', $timeString, $timeMatches) === 1
			) {
				return sprintf(
					'%s-%s-%sT%s:%s:%s+00:00',
					$dateMatches[1],
					$dateMatches[2],
					$dateMatches[3],
					$timeMatches[1],
					$timeMatches[2],
					$timeMatches[3]
				);
			}
		}

		// Fallback to file modification time if available
		if (
			isset($item['exif']['FILE']['FileDateTime'])
			&& is_int($item['exif']['FILE']['FileDateTime'])
		) {
			return gmdate('Y-m-d\TH:i:sP', $item['exif']['FILE']['FileDateTime']);
		}

		return null;
	}

	/**
	 * Render URL set XML output for photo URLs
	 *
	 * @param array<int, array{loc: string, lastmod: string|null, image: string|null}> $urlEntries URL entries with loc, optional lastmod, and optional image
	 *
	 * @return string XML sitemap content
	 */
	private function renderUrlsetXml(array $urlEntries): string
	{
		$lines = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-image/1.1 http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
		];

		foreach ($urlEntries as $entry) {
			$lines[] = "\t<url>";
			$lines[] = "\t\t<loc>" . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';

			if ($entry['lastmod'] !== null) {
				$lines[] = "\t\t<lastmod>" . htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
			}

			if ($entry['image'] !== null) {
				$lines[] = "\t\t<image:image>";
				$lines[] = "\t\t\t<image:loc>" . htmlspecialchars($entry['image'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</image:loc>';
				$lines[] = "\t\t</image:image>";
			}

			$lines[] = "\t</url>";
		}

		$lines[] = '</urlset>';

		return implode(PHP_EOL, $lines) . PHP_EOL;
	}

	/**
	 * Render sitemap index XML output
	 *
	 * Matches Yoast-like sitemap_index structure with one child sitemap entry.
	 *
	 * @param string $sitemapUrl Child sitemap absolute URL
	 * @param string $lastmod    Last modification timestamp
	 *
	 * @return string XML sitemap index content
	 */
	private function renderSitemapIndexXml(string $sitemapUrl, string $lastmod): string
	{
		$escapedSitemapUrl = htmlspecialchars($sitemapUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$escapedLastmod = htmlspecialchars($lastmod, ENT_XML1 | ENT_QUOTES, 'UTF-8');

		$lines = [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
			'  <sitemap>',
			'    <loc>' . $escapedSitemapUrl . '</loc>',
			'    <lastmod>' . $escapedLastmod . '</lastmod>',
			'  </sitemap>',
			'</sitemapindex>',
		];

		return implode(PHP_EOL, $lines) . PHP_EOL;
	}
}
