<?php

/**
 * List View Template
 *
 * Renders the main gallery HTML page with cache-busted asset references.
 *
 * @package PT\Gallery
 * @author  Mark Howells-Mead
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Support\AssetHelper;

$listCssUrl = AssetHelper::cacheBustedAsset('list.css');
$appJsUrl = AssetHelper::cacheBustedAsset('app.js');
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
	<h1>Permanent Tourist photographic archive</h1>
	<p>Photos by Mark Howells-Mead. (<a href="https://www.permanenttourist.ch/">Main website</a>)</p>
	<div id="status">Loading…</div>
	<ul id="image-list"></ul>

	<script src="<?php echo htmlspecialchars($appJsUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>

</html>
