<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Forbidden';
	exit;
}

function cacheBustedAsset(string $relativePath, bool $leadingSlash = false): string
{
	$absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
	$version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) time();
	$path = ($leadingSlash ? '/' : '') . ltrim($relativePath, '/');

	return $path . '?v=' . $version;
}
