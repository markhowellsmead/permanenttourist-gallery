<?php

declare(strict_types=1);

namespace PT\Gallery\Support;

final class RequestHelper
{
	private function __construct()
	{
	}

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

		$path = '/' . ltrim($path, '/');
		return $path === '' ? '/' : $path;
	}
}
