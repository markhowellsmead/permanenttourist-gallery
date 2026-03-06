<?php

/**
 * Environment Variable Loader
 *
 * Utility class for loading environment variables from .env files.
 *
 * @package PT\Gallery\Support
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery\Support;

/**
 * Helper class for loading environment variables from .env files
 */
final class EnvLoader
{
	/**
	 * Private constructor prevents direct instantiation
	 */
	private function __construct()
	{
	}

	/**
	 * Load environment variables from .env file
	 *
	 * Reads a .env file and sets environment variables using putenv().
	 * Skips empty lines and comments (lines starting with #).
	 * Supports quoted values (single and double quotes).
	 *
	 * @param string $filePath Path to .env file
	 *
	 * @return bool True if file was loaded, false if file not found or unreadable
	 */
	public static function load(string $filePath): bool
	{
		if (!file_exists($filePath) || !is_readable($filePath)) {
			return false;
		}

		$lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return false;
		}

		foreach ($lines as $line) {
			$line = trim($line);

			// Skip comments and empty lines
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			// Parse KEY=VALUE format
			$parts = explode('=', $line, 2);
			if (count($parts) !== 2) {
				continue;
			}

			$key = trim($parts[0]);
			$value = trim($parts[1]);

			// Remove quotes if present
			if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
				(str_starts_with($value, "'") && str_ends_with($value, "'"))
			) {
				$value = substr($value, 1, -1);
			}

			putenv("{$key}={$value}");
		}

		return true;
	}
}
