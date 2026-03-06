<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

use PT\Gallery\Support\AssetHelper;

http_response_code(404);

$errorCssUrl = AssetHelper::cacheBustedAsset('404.css', true);
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>404 Not Found</title>
	<link rel="stylesheet" href="<?php echo htmlspecialchars($errorCssUrl, ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body>
	<main>
		<h1>404 — Page not found</h1>
		<p>The requested path does not exist. <a href="/">Go back to the gallery</a>.</p>
	</main>
</body>

</html>
