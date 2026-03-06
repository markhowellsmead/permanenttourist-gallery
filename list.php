<?php

declare(strict_types=1);

function cacheBustedAsset(string $relativePath): string
{
	$absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
	$version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) time();

	return $relativePath . '?v=' . $version;
}

$listCssUrl = cacheBustedAsset('list.css');
$appJsUrl = cacheBustedAsset('app.js');
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Gallery</title>
	<link rel="stylesheet" href="<?php echo htmlspecialchars($listCssUrl, ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body>
	<h1>Image List</h1>
	<div id="status">Loading…</div>
	<ul id="image-list"></ul>

	<script src="<?php echo htmlspecialchars($appJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>

</html>