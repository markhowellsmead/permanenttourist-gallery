<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
	http_response_code(405);
	header('Allow: GET');
	echo json_encode([
		'error' => 'method_not_allowed',
		'message' => 'Only GET requests are allowed.',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

$jsonFile = __DIR__ . '/media/media.json';

if (!is_file($jsonFile) || !is_readable($jsonFile)) {
	http_response_code(404);
	echo json_encode([
		'error' => 'not_found',
		'message' => 'Media JSON file was not found.',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

$raw = file_get_contents($jsonFile);
if ($raw === false) {
	http_response_code(500);
	echo json_encode([
		'error' => 'read_error',
		'message' => 'Unable to read media JSON file.',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
	http_response_code(500);
	echo json_encode([
		'error' => 'invalid_json',
		'message' => 'Media JSON file could not be parsed.',
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

/**
 * Recursively lowercase all associative-array keys.
 *
 * @param mixed $value
 * @return mixed
 */
function lowercaseKeysRecursive($value)
{
	if (!is_array($value)) {
		return $value;
	}

	$isList = array_keys($value) === range(0, count($value) - 1);
	if ($isList) {
		return array_map('lowercaseKeysRecursive', $value);
	}

	$lowercased = [];
	foreach ($value as $key => $item) {
		$lowercased[strtolower((string) $key)] = lowercaseKeysRecursive($item);
	}

	return $lowercased;
}

$response = lowercaseKeysRecursive($data);

echo json_encode(
	$response,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
