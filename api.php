<?php

/**
 * API Endpoint
 *
 * Handles GET requests for media.json with optional filtering by country and month/year.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Api\MediaApiService;

// Ensure API responses are not cached by clients or intermediate proxies
// (also keeps canonical redirect responses uncached).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');


$jsonFile = __DIR__ . '/media/media.json';

$service = new MediaApiService();

// Build parameters from a readable URL such as: /api/filter/location/spiez/
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$params = $_GET;

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$path = (string) parse_url($uri, PHP_URL_PATH);

// Normalize path (no leading/trailing slash)
$path = trim($path, '/');

// Robustly locate a '/filter/' segment anywhere in the path and parse subsequent key/value pairs
$filterPrefix = '/filter/';
$fullPath = '/' . $path;
$pos = strpos($fullPath, $filterPrefix);

if ($pos !== false) {
	// Build a canonical form of the filter path (lowercased keys/values, spaces -> +)
	$afterRaw = substr($fullPath, $pos + strlen($filterPrefix));
	$afterRaw = trim($afterRaw, '/');
	if ($afterRaw !== '') {
		$pairsRaw = explode('/', $afterRaw);
		$canonicalParts = [];
		for ($i = 0; $i < count($pairsRaw); $i += 2) {
			$kRaw = $pairsRaw[$i] ?? null;
			$vRaw = $pairsRaw[$i + 1] ?? null;
			if ($kRaw === null || $vRaw === null) {
				continue;
			}

			$kDecoded = urldecode($kRaw);
			$vDecoded = urldecode($vRaw);

			$kCanon = strtolower($kDecoded);
			// use friendly 'location' key for country
			if ($kCanon === 'country') {
				$kCanon = 'location';
			}

			$vCanon = strtolower($vDecoded);
			// urlencode yields application/x-www-form-urlencoded encoding (spaces -> +)
			$vEncoded = urlencode($vCanon);

			$canonicalParts[] = $kCanon;
			$canonicalParts[] = $vEncoded;
		}

		if (!empty($canonicalParts)) {
			$prefix = substr($fullPath, 0, $pos);
			// Build canonical without forcing a trailing slash so comparisons
			// against the normalized request path don't always differ.
			$canonicalFull = rtrim($prefix, '/') . $filterPrefix . implode('/', $canonicalParts);

			// If requested path differs from canonical, redirect (preserve query string)
			if ($canonicalFull !== $fullPath) {
				$query = parse_url($uri, PHP_URL_QUERY);
				$location = $canonicalFull;
				if ($query !== null && $query !== '') {
					$location .= '?' . $query;
				}
				header('Location: ' . $location, true, 301);
				exit;
			}
		}
	}
	$after = substr($fullPath, $pos + strlen($filterPrefix));
	$after = trim($after, '/');
	if ($after !== '') {
		$pairs = explode('/', $after);
		for ($i = 0; $i < count($pairs); $i += 2) {
			$key = $pairs[$i] ?? null;
			$val = $pairs[$i + 1] ?? null;
			if ($key === null || $val === null) {
				continue;
			}

			$key = urldecode($key);
			$val = urldecode($val);

			if ($key === 'location') {
				$key = 'country';
			}

			$params[$key] = $val;
		}
	}
}

$service->handle($method, $params, $jsonFile);
