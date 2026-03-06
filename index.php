<?php

/**
 * Front Controller
 *
 * Routes incoming requests to appropriate handlers based on request path.
 * Supports /api, /build, and default list view routes.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Support\RequestHelper;

$path = rtrim(RequestHelper::getRequestPath($_SERVER), '/');
if ($path === '') {
	$path = '/';
}

if ($path === '/api') {
	require __DIR__ . '/api.php';
	exit;
}

if ($path === '/build') {
	require __DIR__ . '/build.php';
	exit;
}

if ($path === '/update') {
	require __DIR__ . '/update.php';
	exit;
}

if ($path === '/') {
	require __DIR__ . '/list.php';
	exit;
}

require __DIR__ . '/404.php';
