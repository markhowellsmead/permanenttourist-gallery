<?php

declare(strict_types=1);

namespace PT\Gallery\Support;

final class AssetHelper
{
	private function __construct()
	{
	}

	public static function cacheBustedAsset(string $relativePath, bool $leadingSlash = false): string
	{
		$projectRoot = dirname(__DIR__, 2);
		$absolutePath = $projectRoot . '/' . ltrim($relativePath, '/');
		$version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) time();
		$path = ($leadingSlash ? '/' : '') . ltrim($relativePath, '/');

		return $path . '?v=' . $version;
	}
}
