<?php

/**
 * PSR-4 Autoloader
 *
 * Registers autoloading for PT\Gallery namespace classes.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery;

/**
 * Autoloader class for PT\Gallery namespace
 */
final class Autoloader
{
	/**
	 * Private constructor prevents direct instantiation
	 */
	private function __construct()
	{
	}

	/**
	 * Register the autoloader for the PT\Gallery namespace
	 *
	 * @param string $baseDir          Base directory containing class files
	 * @param string $namespacePrefix  Namespace prefix to match (default: 'PT\Gallery\')
	 *
	 * @return void
	 */
	public static function register(string $baseDir, string $namespacePrefix = 'PT\\Gallery\\'): void
	{
		spl_autoload_register(static function (string $class) use ($baseDir, $namespacePrefix): void {
			if (!str_starts_with($class, $namespacePrefix)) {
				return;
			}

			$relativeClass = substr($class, strlen($namespacePrefix));
			if ($relativeClass === '') {
				return;
			}

			$filePath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
			if (is_file($filePath)) {
				require_once $filePath;
			}
		});
	}
}
