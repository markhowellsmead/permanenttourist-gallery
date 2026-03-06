<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Forbidden';
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';

\PT\Gallery\Autoloader::register(__DIR__ . '/src');
