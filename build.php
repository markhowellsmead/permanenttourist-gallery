<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Build\MediaJsonBuilder;

$rootDir = __DIR__;
$mediaDir = $rootDir . '/media';
$outputFile = $mediaDir . '/media.json';

try {
	$builder = new MediaJsonBuilder();
	$imageCount = $builder->build($mediaDir, $outputFile);
	echo "Wrote {$imageCount} image records to {$outputFile}\n";
} catch (Throwable $throwable) {
	fwrite(STDERR, $throwable->getMessage() . "\n");
	exit(1);
}
