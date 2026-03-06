<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode([
        'error' => 'method_not_allowed',
        'message' => 'Only GET requests are allowed.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$jsonFile = __DIR__ . '/media/media.json';

if (!is_file($jsonFile) || !is_readable($jsonFile)) {
    http_response_code(404);
    echo json_encode([
        'error' => 'not_found',
        'message' => 'Media JSON file was not found.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($jsonFile);
if ($raw === false) {
    http_response_code(500);
    echo json_encode([
        'error' => 'read_error',
        'message' => 'Unable to read media JSON file.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'invalid_json',
        'message' => 'Media JSON file could not be parsed.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param mixed $value
 */
function getStringValue($value): ?string
{
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

/**
 * @param mixed $item
 */
function getCaptureMonthYear($item): ?string
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
        $candidateString = getStringValue($candidate);
        if ($candidateString === null) {
            continue;
        }

        if (preg_match('/^(\d{4}):(\d{2}):\d{2}\s+\d{2}:\d{2}:\d{2}$/', $candidateString, $match) === 1) {
            return $match[1] . '-' . $match[2];
        }
    }

    $iptcDate = getStringValue($item['iptc']['date_created']['value'][0] ?? null);
    if ($iptcDate !== null && preg_match('/^(\d{4})(\d{2})\d{2}$/', $iptcDate, $match) === 1) {
        return $match[1] . '-' . $match[2];
    }

    return null;
}

$countryFilter = trim((string) ($_GET['country'] ?? ''));
if ($countryFilter !== '') {
    $data = array_values(array_filter($data, static function ($item) use ($countryFilter): bool {
        if (!is_array($item)) {
            return false;
        }

        $normalize = static function (string $value): string {
            $value = trim($value);
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($value);
            }

            return strtolower($value);
        };

        $terms = [
            $item['iptc']['country_primary_location_name']['value'][0] ?? null,
            $item['iptc']['state_province']['value'][0] ?? null,
            $item['iptc']['sublocation']['value'][0] ?? null,
        ];

        $normalizedFilter = $normalize($countryFilter);

        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }

            if ($normalize($term) === $normalizedFilter) {
                return true;
            }
        }

        return false;
    }));
}

$monthYearFilter = trim((string) ($_GET['month_year'] ?? ''));
if ($monthYearFilter !== '') {
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthYearFilter) !== 1) {
        http_response_code(400);
        echo json_encode([
            'error' => 'invalid_month_year',
            'message' => 'month_year must be in yyyy-mm format.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = array_values(array_filter($data, static function ($item) use ($monthYearFilter): bool {
        return getCaptureMonthYear($item) === $monthYearFilter;
    }));
}

/**
 * Recursively lowercase all associative-array keys.
 *
 * @param mixed $value
 * @return mixed
 */
function lowercaseKeysRecursive($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    if ($isList) {
        return array_map('lowercaseKeysRecursive', $value);
    }

    $lowercased = [];
    foreach ($value as $key => $item) {
        $lowercased[strtolower((string) $key)] = lowercaseKeysRecursive($item);
    }

    return $lowercased;
}

$response = lowercaseKeysRecursive($data);

echo json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
