<?php

/**
 * Request Helper
 *
 * Utility class for parsing HTTP request information.
 *
 * @package PT\Gallery\Support
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery\Support;

/**
 * Helper class for HTTP request parsing
 */
final class RequestHelper
{
	/**
	 * Private constructor prevents direct instantiation
	 */
	private function __construct() {}

	/**
	 * Extract and normalize the request path from server variables
	 *
	 * Parses REQUEST_URI, strips script directory prefix, and normalizes path.
	 *
	 * @param array<string, mixed> $server Server variables (typically $_SERVER)
	 *
	 * @return string Normalized request path starting with '/'
	 */
	public static function getRequestPath(array $server): string
	{
		$requestUri = isset($server['REQUEST_URI']) ? (string) $server['REQUEST_URI'] : '/';
		$path = parse_url($requestUri, PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			return '/';
		}

		$scriptName = isset($server['SCRIPT_NAME']) ? (string) $server['SCRIPT_NAME'] : '/';
		$scriptDir = str_replace('\\', '/', dirname($scriptName));
		if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
			$path = substr($path, strlen($scriptDir));
		}

		return '/' . ltrim($path, '/');
	}
}
