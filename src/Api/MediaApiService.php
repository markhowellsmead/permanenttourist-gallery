<?php

/**
 * Media API Service
 *
 * Handles API requests for media data with filtering and JSON response.
 *
 * @package PT\Gallery\Api
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

namespace PT\Gallery\Api;

/**
 * Service class for handling media API requests
 */
final class MediaApiService
{
	/**
	 * Handle API request for media data
	 *
	 * Validates request method, reads media JSON file, applies filters,
	 * and returns JSON response with lowercased keys.
	 *
	 * @param string $requestMethod Request HTTP method (only GET allowed)
	 * @param array  $queryParams   Query parameters for filtering
	 * @param string $jsonFile      Path to media.json file
	 *
	 * @return void
	 */
	public function handle(string $requestMethod, array $queryParams, string $jsonFile): void
	{
		header('Content-Type: application/json; charset=utf-8');

		if ($requestMethod !== 'GET') {
			$this->sendJson(405, [
				'error' => 'method_not_allowed',
				'message' => 'Only GET requests are allowed.',
			], ['Allow: GET']);
			return;
		}

		if (!is_file($jsonFile) || !is_readable($jsonFile)) {
			$this->sendJson(404, [
				'error' => 'not_found',
				'message' => 'Media JSON file was not found.',
			]);
			return;
		}

		$raw = file_get_contents($jsonFile);
		if ($raw === false) {
			$this->sendJson(500, [
				'error' => 'read_error',
				'message' => 'Unable to read media JSON file.',
			]);
			return;
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			$this->sendJson(500, [
				'error' => 'invalid_json',
				'message' => 'Media JSON file could not be parsed.',
			]);
			return;
		}

		$countryFilter = trim((string) ($queryParams['country'] ?? ''));
		if ($countryFilter !== '') {
			$data = array_values(array_filter($data, function ($item) use ($countryFilter): bool {
				if (!is_array($item)) {
					return false;
				}

				$terms = [
					$item['iptc']['country_primary_location_name']['value'][0] ?? null,
					$item['iptc']['state_province']['value'][0] ?? null,
					$item['iptc']['sublocation']['value'][0] ?? null,
				];

				$normalizedFilter = $this->normalize((string) $countryFilter);
				foreach ($terms as $term) {
					if (!is_string($term)) {
						continue;
					}

					if ($this->normalize($term) === $normalizedFilter) {
						return true;
					}
				}

				return false;
			}));
		}

		$monthYearFilter = trim((string) ($queryParams['month_year'] ?? ''));
		if ($monthYearFilter !== '') {
			if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthYearFilter) !== 1) {
				$this->sendJson(400, [
					'error' => 'invalid_month_year',
					'message' => 'month_year must be in yyyy-mm format.',
				]);
				return;
			}

			/**
			 * Send JSON response with HTTP status code and optional headers
			 *
			 * @param int   $statusCode HTTP status code
			 * @param array $payload    Data to encode as JSON
			 * @param array $headers    Additional HTTP headers (default: [])
			 *
			 * @return void
			 */
			$data = array_values(array_filter($data, function ($item) use ($monthYearFilter): bool {
				/**
				 * Normalize a string value for case-insensitive comparison
				 *
				 * @param string $value String to normalize
				 *
				 * @return string Lowercased and trimmed string
				 */
				return $this->getCaptureMonthYear($item) === $monthYearFilter;
			}));
		}

		$response = $this->lowercaseKeysRecursive($data);
		echo json_encode(
			$response,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Send JSON response with HTTP status code and optional headers
	 *
	 * @param int   $statusCode HTTP status code
	 * @param array $payload    Data to encode as JSON
	 * @param array $headers    Additional HTTP headers (default: [])
	 *
	 * @return void
	 */
	private function sendJson(int $statusCode, array $payload, array $headers = []): void
	{
		http_response_code($statusCode);
		foreach ($headers as $header) {
			header($header);
		}

		echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Normalize a string value for case-insensitive comparison
	 *
	 * @param string $value String to normalize
	 *
	 * @return string Lowercased and trimmed string
	 */
	private function normalize(string $value): string
	{
		$value = trim($value);
		if (function_exists('mb_strtolower')) {
			return mb_strtolower($value);
		}

		return strtolower($value);
	}

	/**
	 * Extract non-empty string value or return null
	 *
	 * @param mixed $value Value to extract string from
	 *
	 * @return string|null Trimmed string or null if empty/invalid
	 */
	private function getStringValue($value): ?string
	{
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}

	/**
	 * Extract capture month-year from image metadata
	 *
	 * Attempts to extract YYYY-MM from EXIF DateTimeOriginal, DateTimeDigitized,
	 * DateTime, or IPTC date_created fields.
	 *
	 * @param mixed $item Image metadata item
	 *
	 * @return string|null Month-year in YYYY-MM format or null if not found
	 */
	private function getCaptureMonthYear($item): ?string
	{
		if (!is_array($item)) {
			return null;
		}

		$exifCandidates = [
			/**
			 * Recursively lowercase all associative array keys
			 *
			 * Preserves numeric array indices but lowercases string keys.
			 *
			 * @param mixed $value Value to process (array, string, etc.)
			 *
			 * @return mixed Value with lowercased keys if array
			 */
			$item['exif']['EXIF']['DateTimeOriginal'] ?? null,
			$item['exif']['EXIF']['DateTimeDigitized'] ?? null,
			$item['exif']['IFD0']['DateTime'] ?? null,
		];

		foreach ($exifCandidates as $candidate) {
			$candidateString = $this->getStringValue($candidate);
			if ($candidateString === null) {
				continue;
			}

			if (preg_match('/^(\d{4}):(\d{2}):\d{2}\s+\d{2}:\d{2}:\d{2}$/', $candidateString, $match) === 1) {
				return $match[1] . '-' . $match[2];
			}
		}

		$iptcDate = $this->getStringValue($item['iptc']['date_created']['value'][0] ?? null);
		if ($iptcDate !== null && preg_match('/^(\d{4})(\d{2})\d{2}$/', $iptcDate, $match) === 1) {
			return $match[1] . '-' . $match[2];
		}

		return null;
	}

	private function lowercaseKeysRecursive($value)
	{
		if (!is_array($value)) {
			return $value;
		}

		$isList = array_keys($value) === range(0, count($value) - 1);
		if ($isList) {
			return array_map(fn($item) => $this->lowercaseKeysRecursive($item), $value);
		}

		$lowercased = [];
		foreach ($value as $key => $item) {
			$lowercased[strtolower((string) $key)] = $this->lowercaseKeysRecursive($item);
		}

		return $lowercased;
	}
}
