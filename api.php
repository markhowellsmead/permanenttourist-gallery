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
