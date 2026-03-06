<?php

declare(strict_types=1);

namespace PT\Gallery;

final class Autoloader
{
	private function __construct()
	{
	}

	public static function register(string $baseDir, string $namespacePrefix = 'PT\\Gallery\\'): void
	{
		spl_autoload_register(static function (string $class) use ($baseDir, $namespacePrefix): void {
			if (!str_starts_with($class, $namespacePrefix)) {
				return;
			}

			$relativeClass = substr($class, strlen($namespacePrefix));
			if ($relativeClass === false || $relativeClass === '') {
				return;
			}

			$filePath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
			if (is_file($filePath)) {
				require_once $filePath;
			}
		});
	}
}
