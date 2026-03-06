<?php

declare(strict_types=1);

function getRequestPath(): string
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($requestPath) || $requestPath === '') {
        return '/';
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($requestPath, $scriptDir)) {
        $requestPath = substr($requestPath, strlen($scriptDir));
    }

    $requestPath = '/' . ltrim($requestPath, '/');

    return $requestPath === '' ? '/' : $requestPath;
}

$path = getRequestPath();

if ($path === '/api') {
    require __DIR__ . '/api.php';
    exit;
}

if ($path !== '/') {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gallery</title>
    <style>
        body {
            margin: 0;
            padding: 2rem;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8f8f8;
            color: #222;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 1rem;
        }

        #status {
            margin-bottom: 1rem;
            color: #444;
        }

        #image-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 1rem;
        }

        .image-item {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 1rem;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0.75rem;
            align-items: start;
        }

        .image-item img {
            width: 140px;
            height: 100px;
            object-fit: cover;
            border-radius: 6px;
            background: #eee;
        }

        .meta {
            display: grid;
            gap: 0.35rem;
            min-width: 0;
        }

        .meta .url {
            word-break: break-all;
            font-size: 0.9rem;
            color: #333;
        }

        .meta .date {
            font-size: 0.9rem;
            color: #666;
        }
    </style>
</head>

<body>
    <h1>Image List</h1>
    <div id="status">Loading…</div>
    <ul id="image-list"></ul>

    <script src="app.js"></script>
</body>

</html>