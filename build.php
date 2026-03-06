<?php

/**
 * Build Script
 *
 * Scans media directory for JPEG images, extracts IPTC/EXIF metadata,
 * generates media.json index, and logs newly added images to monthly log files.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Build\MediaJsonBuilder;

$rootDir = __DIR__;
$mediaDir = $rootDir . '/media';
$outputFile = $mediaDir . '/media.json';

try {
	$builder = new MediaJsonBuilder();
	$result = $builder->buildWithDetails($mediaDir, $outputFile);

	echo "Wrote {$result['total']} image records to {$outputFile}\n";

	if ($result['new'] > 0) {
		$monthKey = date('Y-m');
		$logFile = __DIR__ . "/logs/{$monthKey}.log";
		echo "Logged {$result['new']} new image(s) to {$logFile}\n";
	}
} catch (Throwable $throwable) {
	fwrite(STDERR, $throwable->getMessage() . "\n");
	exit(1);
}
