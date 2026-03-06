<?php

/**
 * Asset Helper
 *
 * Utility class for generating cache-busted asset URLs.
 *
 * @package PT\Gallery\Support
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery\Support;

/**
 * Helper class for asset URL generation with cache-busting
 */
final class AssetHelper
{
	/**
	 * Private constructor prevents direct instantiation
	 */
	private function __construct()
	{
	}

	/**
	 * Generate a cache-busted URL for a static asset
	 *
	 * Appends file modification timestamp as version parameter to force
	 * browser cache invalidation when file changes.
	 *
	 * @param string $relativePath  Relative path to asset from project root
	 * @param bool   $leadingSlash  Whether to include leading slash (default: false)
	 *
	 * @return string Cache-busted asset URL with version parameter
	 */
	public static function cacheBustedAsset(string $relativePath, bool $leadingSlash = false): string
	{
		$projectRoot = dirname(__DIR__, 2);
		$absolutePath = $projectRoot . '/' . ltrim($relativePath, '/');
		$version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) time();
		$path = ($leadingSlash ? '/' : '') . ltrim($relativePath, '/');

		return $path . '?v=' . $version;
	}
}
