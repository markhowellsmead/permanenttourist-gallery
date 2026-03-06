<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Api\MediaApiService;

$jsonFile = __DIR__ . '/media/media.json';

$service = new MediaApiService();
$service->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, $jsonFile);
