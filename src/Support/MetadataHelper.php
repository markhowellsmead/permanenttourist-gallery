<?php

/**
 * Metadata Helper
 *
 * Utility class for extracting metadata from photo data arrays.
 *
 * @package PT\Gallery\Support
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery\Support;

/**
 * Helper class for photo metadata extraction
 */
final class MetadataHelper
{
	/**
	 * Private constructor prevents direct instantiation
	 */
	private function __construct()
	{
	}

	/**
	 * Extract title from photo data
	 *
	 * @param array<string, mixed> $photo Photo data array
	 *
	 * @return string Photo title
	 */
	public static function extractTitle(array $photo): string
	{
		if (isset($photo['iptc']['object_name']['value'][0])) {
			$title = trim($photo['iptc']['object_name']['value'][0]);
			if ($title !== '') {
				return $title;
			}
		}
		return 'Untitled';
	}

	/**
	 * Build location string from photo data
	 *
	 * @param array<string, mixed> $photo Photo data array
	 *
	 * @return string Location string
	 */
	public static function extractLocation(array $photo): string
	{
		$parts = [];

		if (isset($photo['iptc']['sublocation']['value'][0])) {
			$parts[] = $photo['iptc']['sublocation']['value'][0];
		}
		if (isset($photo['iptc']['city']['value'][0])) {
			$parts[] = $photo['iptc']['city']['value'][0];
		}
		if (isset($photo['iptc']['state_province']['value'][0])) {
			$parts[] = $photo['iptc']['state_province']['value'][0];
		}
		if (isset($photo['iptc']['country_primary_location_name']['value'][0])) {
			$country = $photo['iptc']['country_primary_location_name']['value'][0];
			if (strtolower($country) !== 'united kingdom') {
				$parts[] = $country;
			}
		}

		// Remove duplicates (case-insensitive)
		$seen = [];
		$unique = [];
		foreach ($parts as $part) {
			$key = strtolower($part);
			if (!isset($seen[$key])) {
				$seen[$key] = true;
				$unique[] = $part;
			}
		}

		return count($unique) > 0 ? implode(', ', $unique) : 'Unknown location';
	}
}
