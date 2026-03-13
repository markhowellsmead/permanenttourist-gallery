<?php

/**
 * Fetch images from an IMAP mailbox
 *
 * Connects to an IMAP server using environment variables and downloads image
 * attachments into the `media/` folder. Any email which had attachments
 * downloaded will be deleted from the mailbox.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$host = getenv('IMAP_HOST') ?: '';
$port = getenv('IMAP_PORT') ?: '';
$user = getenv('IMAP_USER') ?: '';
$pass = getenv('IMAP_PASS') ?: '';
$mailbox = getenv('IMAP_MAILBOX') ?: 'INBOX';
$encryption = strtolower((string) (getenv('IMAP_ENCRYPTION') ?: 'ssl'));

if ($host === '' || $user === '' || $pass === '') {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'missing_configuration', 'message' => 'IMAP_HOST, IMAP_USER and IMAP_PASS must be set in environment'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

// Normalize port/encryption
if ($port === '') {
	$port = $encryption === 'ssl' ? '993' : '143';
}

$flags = '/imap';
if ($encryption === 'ssl') {
	$flags .= '/ssl/novalidate-cert';
} elseif ($encryption === 'tls') {
	$flags .= '/tls/novalidate-cert';
}

$mailboxString = sprintf('{%s:%s%s}%s', $host, $port, $flags, $mailbox);

$result = [
	'success' => false,
	'downloaded' => [],
	'deleted' => [],
	'errors' => [],
];

// Check for IMAP extension
if (!function_exists('imap_open')) {
	http_response_code(500);
	$result['errors'][] = 'IMAP extension is not available on this PHP build.';
	echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

$imap = @imap_open($mailboxString, $user, $pass);
if ($imap === false) {
	http_response_code(500);
	$err = imap_last_error();
	$result['errors'][] = 'Failed to open IMAP mailbox: ' . ($err ?: 'unknown error');
	echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

// Search all messages
$messages = imap_search($imap, 'ALL');
if ($messages === false) {
	// No messages
	$result['success'] = true;
	imap_close($imap);
	echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

// Ensure media dir exists
$mediaDir = __DIR__ . '/media';
if (!is_dir($mediaDir)) {
	mkdir($mediaDir, 0755, true);
}

// Helper: recursively extract parts and attachments
function extract_parts($stream, $msgno, $part, $prefix = '')
{
	$attachments = [];

	if (!isset($part->parts) || !is_array($part->parts)) {
		// Single part
		$attachments[] = ['part' => $part, 'partNumber' => $prefix === '' ? '1' : $prefix];
		return $attachments;
	}

	foreach ($part->parts as $index => $subpart) {
		$num = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . (string) ($index + 1);
		// Dive recursively
		$attachments = array_merge($attachments, extract_parts($stream, $msgno, $subpart, $num));
	}

	return $attachments;
}

foreach ($messages as $msgno) {
	$structure = imap_fetchstructure($imap, $msgno);
	if ($structure === false) {
		$result['errors'][] = "Failed to fetch structure for message {$msgno}";
		continue;
	}

	$parts = [];
	if (isset($structure->parts) && is_array($structure->parts)) {
		foreach ($structure->parts as $idx => $p) {
			$parts = array_merge($parts, extract_parts($imap, $msgno, $p, (string) ($idx + 1)));
		}
	} else {
		$parts = extract_parts($imap, $msgno, $structure, '1');
	}

	$downloadedAny = false;
	foreach ($parts as $partInfo) {
		$part = $partInfo['part'];
		$partNumber = $partInfo['partNumber'];

		$isAttachment = false;
		$filename = null;

		if (isset($part->ifdparameters) && is_array($part->ifdparameters)) {
			foreach ($part->ifdparameters as $param) {
				if (isset($param->attribute) && strtolower($param->attribute) === 'filename') {
					$filename = $param->value;
					$isAttachment = true;
				}
			}
		}

		if (!$isAttachment && isset($part->ifparameters) && is_array($part->ifparameters)) {
			foreach ($part->ifparameters as $param) {
				if (isset($param->attribute) && in_array(strtolower($param->attribute), ['name', 'filename'], true)) {
					$filename = $param->value;
					$isAttachment = true;
				}
			}
		}

		// Fallback: some parts identify as inline with name
		if (!$isAttachment && isset($part->parameters) && is_array($part->parameters)) {
			foreach ($part->parameters as $param) {
				if (isset($param->attribute) && in_array(strtolower($param->attribute), ['name', 'filename'], true)) {
					$filename = $param->value;
					$isAttachment = true;
				}
			}
		}

		// Determine mime type
		$mime = isset($part->subtype) ? (isset($part->type) && $part->type === 0 ? 'TEXT/' . $part->subtype : '') : '';
		if (isset($part->type) && isset($part->subtype)) {
			$types = [
				0 => 'TEXT',
				1 => 'MULTIPART',
				2 => 'MESSAGE',
				3 => 'APPLICATION',
				4 => 'AUDIO',
				5 => 'IMAGE',
				6 => 'VIDEO',
				7 => 'OTHER',
			];
			$mime = ($types[$part->type] ?? 'OTHER') . '/' . $part->subtype;
		}

		// Only handle image/* attachments
		if (!$isAttachment) {
			continue;
		}

		if (!isset($mime) || stripos($mime, 'IMAGE/') !== 0) {
			continue;
		}

		// Fetch body
		$body = imap_fetchbody($imap, $msgno, $partNumber);
		if ($body === false) {
			$result['errors'][] = "Failed to fetch body for message {$msgno} part {$partNumber}";
			continue;
		}

		// Decode according to encoding
		$decoded = $body;
		if (isset($part->encoding)) {
			switch ($part->encoding) {
				case 3: // BASE64
					$decoded = base64_decode($body);
					break;
				case 4: // QUOTED-PRINTABLE
					$decoded = quoted_printable_decode($body);
					break;
				default:
					// leave as-is
			}
		}

		// If filename missing, try to invent one
		if (empty($filename)) {
			$filename = sprintf('attachment-%s-%s', $msgno, $partNumber);
		}

		// Sanitize filename
		$filename = preg_replace('/[^A-Za-z0-9_\-\.\+]/', '_', $filename);

		$target = $mediaDir . '/' . $filename;

		$written = @file_put_contents($target, $decoded);
		if ($written === false) {
			$result['errors'][] = "Failed to write file {$target}";
			continue;
		}

		// After saving, attempt to extract capture date (YYYYMMDD) from EXIF or IPTC
		$datePrefix = null;
		if (function_exists('exif_read_data')) {
			$exif = @exif_read_data($target, 'EXIF', true);
			if ($exif !== false && is_array($exif)) {
				$candidates = [
					$exif['EXIF']['DateTimeOriginal'] ?? null,
					$exif['EXIF']['DateTimeDigitized'] ?? null,
					$exif['IFD0']['DateTime'] ?? null,
				];
				foreach ($candidates as $cand) {
					if (!is_string($cand)) {
						continue;
					}

					if (preg_match('/^(\d{4}):(\d{2}):(\d{2})/', $cand, $m) === 1) {
						$datePrefix = $m[1] . $m[2] . $m[3];
						break;
					}
				}
			}
		}

		// IPTC fallback
		if ($datePrefix === null) {
			$size = @getimagesize($target, $info);
			if ($size !== false && isset($info['APP13'])) {
				$iptc = @iptcparse($info['APP13']);
				if (is_array($iptc) && isset($iptc['2#055'][0])) {
					$iptcDate = $iptc['2#055'][0]; // YYYYMMDD
					if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $iptcDate, $m2) === 1) {
						$datePrefix = $m2[1] . $m2[2] . $m2[3];
					}
				}
			}
		}

		// Sanitize filename again (ensure no slashes)
		$filename = basename($filename);

		// If file doesn't already start with YYYYMMDD prefix, prepend it
		if ($datePrefix !== null && !preg_match('/^\d{8}/', $filename)) {
			$newName = $datePrefix . '-' . $filename;
			$newTarget = $mediaDir . '/' . $newName;
			// Overwrite if exists
			if (file_exists($newTarget)) {
				@unlink($newTarget);
			}
			if (!@rename($target, $newTarget)) {
				// If rename failed, keep original and report error
				$result['errors'][] = "Failed to rename {$target} to {$newTarget}";
				$finalName = $filename;
			} else {
				$finalName = $newName;
			}
		} else {
			$finalName = $filename;
		}

		$downloadedAny = true;
		$result['downloaded'][] = $finalName;
	}

	if ($downloadedAny) {
		// Mark message for deletion
		imap_delete($imap, (string) $msgno);
		$result['deleted'][] = $msgno;
	}
}

// Permanently remove deleted messages
imap_expunge($imap);
imap_close($imap);

$result['success'] = true;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
