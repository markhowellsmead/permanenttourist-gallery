<?php

/**
 * Update Endpoint
 *
 * Runs git pull in the project root directory.
 * Requires a secret token for authentication.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Check for secret token
$providedToken = $_GET['token'] ?? null;
$validToken = getenv('UPDATE_TOKEN');
if ($validToken === false || $validToken === '') {
	$validToken = 'change_this_secret_token';
}

if ($providedToken === null || $providedToken === '') {
	http_response_code(403);
	echo json_encode([
		'error' => 'forbidden',
		'message' => 'Missing authentication token.',
	]);
	exit;
}

if ($providedToken !== $validToken) {
	http_response_code(403);
	echo json_encode([
		'error' => 'forbidden',
		'message' => 'Invalid authentication token.',
	]);
	exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	http_response_code(405);
	header('Allow: GET');
	echo json_encode([
		'error' => 'method_not_allowed',
		'message' => 'Only GET requests are allowed.',
	]);
	exit;
}

// Change to project root directory
$projectRoot = __DIR__;
if (!chdir($projectRoot)) {
	$timestamp = date('Y-m-d H:i:s');
	$logsDir = __DIR__ . '/logs';
	if (!is_dir($logsDir)) {
		@mkdir($logsDir, 0755, true);
	}

	$logFile = $logsDir . '/update-errors.log';
	$logEntry = sprintf(
		"[%s] Failed to change directory to: %s\n%s\n",
		$timestamp,
		$projectRoot,
		str_repeat('-', 80)
	);
	@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => 'directory_error',
		'message' => 'Update failed. Details have been logged.',
		'timestamp' => $timestamp,
	]);
	exit;
}

// Execute git pull
$output = [];
$exitCode = 0;

// Check if we need to use GitHub token for HTTPS authentication
$githubToken = getenv('GITHUB_TOKEN');
if ($githubToken !== false && $githubToken !== '') {
	// Get current remote URL
	exec('git remote get-url origin 2>&1', $remoteOutput, $remoteExitCode);
	
	if ($remoteExitCode === 0 && !empty($remoteOutput)) {
		$remoteUrl = trim($remoteOutput[0]);
		
		// Check if remote is using SSH
		if (preg_match('/^git@github\.com:(.+)\.git$/', $remoteUrl, $matches)) {
			$repoPath = $matches[1];
			$httpsUrl = "https://{$githubToken}@github.com/{$repoPath}.git";
			
			// Temporarily set remote to HTTPS with token
			exec("git remote set-url origin '{$httpsUrl}' 2>&1", $setUrlOutput, $setUrlExitCode);
			
			if ($setUrlExitCode === 0) {
				// Execute git pull with HTTPS
				exec('git pull origin 2>&1', $output, $exitCode);
				
				// Restore SSH remote URL
				exec("git remote set-url origin '{$remoteUrl}' 2>&1");
			} else {
				$output = array_merge(['Failed to set HTTPS remote URL'], $setUrlOutput);
				$exitCode = $setUrlExitCode;
			}
		} else {
			// Remote is already HTTPS or unknown format, use standard git pull
			exec('git pull 2>&1', $output, $exitCode);
		}
	} else {
		// Could not get remote URL, use standard git pull
		exec('git pull 2>&1', $output, $exitCode);
	}
} else {
	// No GitHub token, use standard git pull (requires SSH keys or HTTPS to be configured)
	exec('git pull 2>&1', $output, $exitCode);
}

$timestamp = date('Y-m-d H:i:s');

if ($exitCode === 0) {
	// Success - return full details
	http_response_code(200);
	echo json_encode([
		'success' => true,
		'message' => 'Update completed successfully.',
		'output' => $output,
		'timestamp' => $timestamp,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
	// Failure - log details but return generic error
	$logsDir = __DIR__ . '/logs';
	if (!is_dir($logsDir)) {
		@mkdir($logsDir, 0755, true);
	}

	$logFile = $logsDir . '/update-errors.log';
	$logEntry = sprintf(
		"[%s] Update failed (exit code: %d)\nDirectory: %s\nOutput:\n%s\n%s\n",
		$timestamp,
		$exitCode,
		$projectRoot,
		implode("\n", $output),
		str_repeat('-', 80)
	);

	@file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => 'update_failed',
		'message' => 'Update failed. Details have been logged.',
		'timestamp' => $timestamp,
	], JSON_UNESCAPED_SLASHES);
}
