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
$service->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $_GET, $jsonFile);
