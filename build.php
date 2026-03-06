<?php

declare(strict_types=1);

$rootDir = __DIR__;
$mediaDir = $rootDir . '/media';
$outputFile = $mediaDir . '/media.json';

if (!is_dir($mediaDir)) {
    fwrite(STDERR, "Media directory not found: {$mediaDir}\n");
    exit(1);
}

/**
 * Convert metadata values to JSON-safe UTF-8 values.
 *
 * @param mixed $value
 * @return mixed
 */
function normalizeValue($value)
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = normalizeValue($item);
        }
        return $normalized;
    }

    if (is_object($value)) {
        return normalizeValue((array) $value);
    }

    if (is_string($value)) {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        return '';
    }

    return $value;
}

/**
 * Convert a label to a safe key: lowercase, ASCII, underscore-separated.
 */
function toLegibleKey(string $label): string
{
    $normalized = $label;

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        if ($converted !== false) {
            $normalized = $converted;
        }
    }

    $normalized = strtolower($normalized);
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_');

    return $normalized !== '' ? $normalized : 'iptc_tag';
}

/**
 * Map IPTC IIM keys (e.g. 2#101) to IPTC Photo Metadata labels.
 */
function getIptcLabel(string $iptcKey): string
{
    $labels = [
        '1#000' => 'Model Version',
        '1#005' => 'Destination',
        '1#020' => 'File Format',
        '1#022' => 'File Format Version',
        '1#090' => 'Coded Character Set',
        '2#000' => 'Record Version',
        '2#003' => 'Object Type Reference',
        '2#004' => 'Object Attribute Reference',
        '2#005' => 'Object Name',
        '2#007' => 'Edit Status',
        '2#008' => 'Editorial Update',
        '2#010' => 'Urgency',
        '2#012' => 'Subject Reference',
        '2#015' => 'Category',
        '2#020' => 'Supplemental Category',
        '2#022' => 'Fixture Identifier',
        '2#025' => 'Keywords',
        '2#026' => 'Content Location Code',
        '2#027' => 'Content Location Name',
        '2#030' => 'Release Date',
        '2#035' => 'Release Time',
        '2#037' => 'Expiration Date',
        '2#038' => 'Expiration Time',
        '2#040' => 'Special Instructions',
        '2#042' => 'Action Advised',
        '2#045' => 'Reference Service',
        '2#047' => 'Reference Date',
        '2#050' => 'Reference Number',
        '2#055' => 'Date Created',
        '2#060' => 'Time Created',
        '2#062' => 'Digital Creation Date',
        '2#063' => 'Digital Creation Time',
        '2#065' => 'Originating Program',
        '2#070' => 'Program Version',
        '2#075' => 'Object Cycle',
        '2#080' => 'Byline',
        '2#085' => 'Byline Title',
        '2#090' => 'City',
        '2#092' => 'Sublocation',
        '2#095' => 'State/Province',
        '2#100' => 'Country/Primary Location Code',
        '2#101' => 'Country/Primary Location Name',
        '2#103' => 'Original Transmission Reference',
        '2#105' => 'Headline',
        '2#110' => 'Credit',
        '2#115' => 'Source',
        '2#116' => 'Copyright Notice',
        '2#118' => 'Contact',
        '2#120' => 'Caption/Abstract',
        '2#121' => 'Local Caption',
        '2#122' => 'Writer/Editor',
        '2#125' => 'Rasterized Caption',
        '2#130' => 'Image Type',
        '2#131' => 'Image Orientation',
        '2#135' => 'Language Identifier',
        '2#150' => 'Audio Type',
        '2#151' => 'Audio Sampling Rate',
        '2#152' => 'Audio Sampling Resolution',
        '2#153' => 'Audio Duration',
        '2#154' => 'Audio Outcue',
        '2#184' => 'Job Identifier',
        '2#185' => 'Master Document Identifier',
        '2#186' => 'Short Document Identifier',
        '2#187' => 'Unique Document Identifier',
        '2#188' => 'Owner Identifier',
        '2#200' => 'Object Data Preview File Format',
        '2#201' => 'Object Data Preview File Format Version',
        '2#202' => 'Object Data Preview Data',
    ];

    return $labels[$iptcKey] ?? $iptcKey;
}

/**
 * Restructure raw IPTC data into readable-key entries.
 */
function restructureIptcData(array $parsed): array
{
    $structured = [];

    foreach ($parsed as $iptcKey => $value) {
        $label = getIptcLabel((string) $iptcKey);
        $key = toLegibleKey($label);

        if (isset($structured[$key])) {
            $key = $key . '_' . str_replace('#', '_', (string) $iptcKey);
        }

        $structured[$key] = [
            'iptc_key' => (string) $iptcKey,
            'value' => normalizeValue($value),
        ];
    }

    ksort($structured);

    return $structured;
}

/**
 * Get IPTC metadata from an image file.
 */
function getIptcData(string $filePath): array
{
    $info = [];
    @getimagesize($filePath, $info);

    if (empty($info['APP13'])) {
        return [];
    }

    $parsed = @iptcparse($info['APP13']);
    if (!is_array($parsed)) {
        return [];
    }

    return restructureIptcData($parsed);
}

/**
 * Get EXIF metadata from an image file.
 */
function getExifData(string $filePath): array
{
    if (!function_exists('exif_read_data')) {
        return [];
    }

    $exif = @exif_read_data($filePath, null, true, false);
    if (!is_array($exif)) {
        return [];
    }

    return normalizeValue($exif);
}

$images = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($mediaDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }

    $extension = strtolower($item->getExtension());
    if (!in_array($extension, ['jpg', 'jpeg'], true)) {
        continue;
    }

    $absolutePath = $item->getPathname();
    $relativePath = ltrim(str_replace($mediaDir, '', $absolutePath), DIRECTORY_SEPARATOR);
    $url = '/media/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

    $images[] = [
        'url' => $url,
        'iptc' => getIptcData($absolutePath),
        'exif' => getExifData($absolutePath),
    ];
}

usort($images, static fn(array $a, array $b): int => strcmp($a['url'], $b['url']));

$json = json_encode(
    $images,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    fwrite(STDERR, "Failed to encode media JSON.\n");
    exit(1);
}

if (file_put_contents($outputFile, $json . PHP_EOL) === false) {
    fwrite(STDERR, "Failed to write output file: {$outputFile}\n");
    exit(1);
}

echo "Wrote " . count($images) . " image records to {$outputFile}\n";
