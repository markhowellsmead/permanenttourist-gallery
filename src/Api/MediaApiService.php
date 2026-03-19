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
	 * @param string               $requestMethod Request HTTP method (only GET allowed)
	 * @param array<string, mixed> $queryParams   Query parameters for filtering
	 * @param string               $jsonFile      Path to media.json file
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

		// Support year-only filter (YYYY)
		$yearFilter = trim((string) ($queryParams['year'] ?? ''));
		if ($yearFilter !== '') {
			if (preg_match('/^\d{4}$/', $yearFilter) !== 1) {
				$this->sendJson(400, [
					'error' => 'invalid_year',
					'message' => 'year must be in yyyy format.',
				]);
				return;
			}

			$data = array_values(array_filter($data, function ($item) use ($yearFilter): bool {
				$my = $this->getCaptureMonthYear($item);
				if ($my === null) {
					return false;
				}

				return str_starts_with($my, $yearFilter . '-');
			}));
		}

		$data = $this->sortByCaptureTimestampDesc($data);

		$perPage = $this->parsePerPage($queryParams['per_page'] ?? null);
		$page = $this->parsePage($queryParams['page'] ?? null);
		$total = count($data);
		$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

		header('X-Total: ' . $total);
		header('X-Total-Pages: ' . $totalPages);
		header('X-Page: ' . $page);

		$offset = ($page - 1) * $perPage;
		$pagedData = array_slice($data, $offset, $perPage);

		$data = $this->flattenImageData($pagedData);
		echo json_encode(
			$data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Parse and validate per_page query parameter.
	 *
	 * Uses WordPress-style defaults/caps: default 20, max 100.
	 *
	 * @param mixed $value Query parameter value
	 *
	 * @return int
	 */
	private function parsePerPage($value): int
	{
		$perPage = (int) $value;
		if ($perPage <= 0) {
			$perPage = 20;
		}

		return min($perPage, 100);
	}

	/**
	 * Parse and validate page query parameter.
	 *
	 * @param mixed $value Query parameter value
	 *
	 * @return int
	 */
	private function parsePage($value): int
	{
		$page = (int) $value;
		return $page > 0 ? $page : 1;
	}

	/**
	 * Sort media records by capture timestamp descending.
	 *
	 * @param array<int, mixed> $items
	 *
	 * @return array<int, mixed>
	 */
	private function sortByCaptureTimestampDesc(array $items): array
	{
		usort($items, function ($a, $b): int {
			$aTs = $this->getCaptureTimestamp($a);
			$bTs = $this->getCaptureTimestamp($b);

			if ($aTs === null && $bTs === null) {
				return 0;
			}

			if ($aTs === null) {
				return 1;
			}

			if ($bTs === null) {
				return -1;
			}

			return $bTs <=> $aTs;
		});

		return $items;
	}

	/**
	 * Send JSON response with HTTP status code and optional headers
	 *
	 * @param int                  $statusCode HTTP status code
	 * @param array<string, mixed> $payload    Data to encode as JSON
	 * @param array<int, string>   $headers    Additional HTTP headers (default: [])
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
	 * Flatten image data into simplified structure
	 *
	 * Extracts nested IPTC/EXIF values into top-level fields for easier frontend access.
	 *
	 * @param array<int, mixed> $items Array of image items
	 *
	 * @return array<int, array<string, mixed>> Flattened image data
	 */
	private function flattenImageData(array $items): array
	{
		$flattened = [];

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$flat = ['url' => $item['url'] ?? ''];

			// Extract IPTC values
			if (isset($item['iptc']) && is_array($item['iptc'])) {
				$flat['title'] = $this->extractIptcValue($item['iptc'], 'object_name');
				$flat['country'] = $this->extractIptcValue($item['iptc'], 'country_primary_location_name');
				$flat['sublocation'] = $this->extractIptcValue($item['iptc'], 'sublocation');
				$flat['city'] = $this->extractIptcValue($item['iptc'], 'city');
				$flat['state_province'] = $this->extractIptcValue($item['iptc'], 'state_province');

				// IPTC date/time: present as combined formatted timestamp if possible
				$rawIptcDate = $this->extractIptcValue($item['iptc'], 'date_created');
				$rawIptcTime = $this->extractIptcValue($item['iptc'], 'time_created');
				$formattedIptc = $this->formatIptcDateTime($rawIptcDate, $rawIptcTime);
				$flat['date_created'] = $formattedIptc ?? $rawIptcDate;
				$flat['time_created'] = $formattedIptc ?? $rawIptcTime;

				// Keywords array (preserve as array)
				$keywords = $this->extractIptcArray($item['iptc'], 'keywords');
				if ($keywords !== null) {
					$flat['keywords'] = $keywords;
				}
			}

			// Extract EXIF values
			if (isset($item['exif']) && is_array($item['exif'])) {
				$flat['datetime_original'] = $this->formatExifDateString($item['exif']['EXIF']['DateTimeOriginal'] ?? null) ?? ($item['exif']['EXIF']['DateTimeOriginal'] ?? null);
				$flat['datetime_digitized'] = $this->formatExifDateString($item['exif']['EXIF']['DateTimeDigitized'] ?? null) ?? ($item['exif']['EXIF']['DateTimeDigitized'] ?? null);
				$flat['datetime'] = $this->formatExifDateString($item['exif']['IFD0']['DateTime'] ?? null) ?? ($item['exif']['IFD0']['DateTime'] ?? null);
				$flat['width'] = $item['exif']['COMPUTED']['Width'] ?? null;
				$flat['height'] = $item['exif']['COMPUTED']['Height'] ?? null;
			}

			$flattened[] = $flat;
		}

		return $flattened;
	}

	/**
	 * Extract first value from IPTC field structure
	 *
	 * @param array<string, mixed> $iptc IPTC data
	 * @param string               $key  Field key
	 *
	 * @return string|null First value or null if not found
	 */
	private function extractIptcValue(array $iptc, string $key): ?string
	{
		if (!isset($iptc[$key]['value'][0])) {
			return null;
		}

		$value = $iptc[$key]['value'][0];
		return is_string($value) && trim($value) !== '' ? $value : null;
	}

	/**
	 * Extract array of values from IPTC field structure
	 *
	 * @param array<string, mixed> $iptc IPTC data
	 * @param string               $key  Field key
	 *
	 * @return array<int, string>|null Array of values or null if not found
	 */
	private function extractIptcArray(array $iptc, string $key): ?array
	{
		if (!isset($iptc[$key]['value']) || !is_array($iptc[$key]['value'])) {
			return null;
		}

		$values = array_filter($iptc[$key]['value'], function ($value): bool {
			return is_string($value) && trim($value) !== '';
		});

		return !empty($values) ? array_values($values) : null;
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
	 * Format EXIF date string into `Y-m-d H:i:s`.
	 * Accepts `Y:m:d H:i:s` or `Y-m-d H:i:s` input.
	 *
	 * @param string|null $s
	 * @return string|null
	 */
	private function formatExifDateString(?string $s): ?string
	{
		if ($s === null) {
			return null;
		}

		// Try common EXIF format first
		$dt = \DateTime::createFromFormat('Y:m:d H:i:s', $s);
		if ($dt === false) {
			$dt = \DateTime::createFromFormat('Y-m-d H:i:s', $s);
		}
		if ($dt === false) {
			return null;
		}

		return $dt->format('Y-m-d H:i:s');
	}

	/**
	 * Format IPTC date (YYYYMMDD) and optional time (HHMM or HHMMSS) to `Y-m-d H:i:s`.
	 *
	 * @param string|null $dateRaw
	 * @param string|null $timeRaw
	 * @return string|null
	 */
	private function formatIptcDateTime(?string $dateRaw, ?string $timeRaw): ?string
	{
		if ($dateRaw === null || !preg_match('/^\d{8}$/', $dateRaw)) {
			return null;
		}

		$year = substr($dateRaw, 0, 4);
		$month = substr($dateRaw, 4, 2);
		$day = substr($dateRaw, 6, 2);

		$time = '00:00:00';
		if ($timeRaw !== null && preg_match('/^\d{4,6}$/', $timeRaw)) {
			$h = substr($timeRaw, 0, 2) ?: '00';
			$i = substr($timeRaw, 2, 2) ?: '00';
			$s = strlen($timeRaw) === 6 ? substr($timeRaw, 4, 2) : '00';
			$time = sprintf('%02d:%02d:%02d', (int)$h, (int)$i, (int)$s);
		}

		$dt = @\DateTime::createFromFormat('Y-m-d H:i:s', "{$year}-{$month}-{$day} {$time}");
		if ($dt === false) {
			return null;
		}

		return $dt->format('Y-m-d H:i:s');
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

	/**
	 * Extract capture timestamp from EXIF/IPTC metadata.
	 *
	 * @param mixed $item Image metadata item
	 *
	 * @return int|null UNIX timestamp or null when unavailable
	 */
	private function getCaptureTimestamp($item): ?int
	{
		if (!is_array($item)) {
			return null;
		}

		$exifCandidates = [
			$item['exif']['EXIF']['DateTimeOriginal'] ?? null,
			$item['exif']['EXIF']['DateTimeDigitized'] ?? null,
			$item['exif']['IFD0']['DateTime'] ?? null,
		];

		foreach ($exifCandidates as $candidate) {
			$candidateString = $this->getStringValue($candidate);
			if ($candidateString === null) {
				continue;
			}

			$dt = \DateTime::createFromFormat('Y:m:d H:i:s', $candidateString)
				?: \DateTime::createFromFormat('Y-m-d H:i:s', $candidateString);

			if ($dt instanceof \DateTime) {
				return $dt->getTimestamp();
			}
		}

		$iptcDate = $this->getStringValue($item['iptc']['date_created']['value'][0] ?? null);
		$iptcTime = $this->getStringValue($item['iptc']['time_created']['value'][0] ?? null);

		if ($iptcDate === null || preg_match('/^\d{8}$/', $iptcDate) !== 1) {
			return null;
		}

		$year = substr($iptcDate, 0, 4);
		$month = substr($iptcDate, 4, 2);
		$day = substr($iptcDate, 6, 2);

		$time = '00:00:00';
		if ($iptcTime !== null && preg_match('/^\d{4,6}$/', $iptcTime) === 1) {
			$h = substr($iptcTime, 0, 2) ?: '00';
			$i = substr($iptcTime, 2, 2) ?: '00';
			$s = strlen($iptcTime) === 6 ? substr($iptcTime, 4, 2) : '00';
			$time = sprintf('%02d:%02d:%02d', (int) $h, (int) $i, (int) $s);
		}

		$dt = \DateTime::createFromFormat('Y-m-d H:i:s', "{$year}-{$month}-{$day} {$time}");
		if (!$dt instanceof \DateTime) {
			return null;
		}

		return $dt->getTimestamp();
	}
}
