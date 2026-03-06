<?php

declare(strict_types=1);

http_response_code(404);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 Not Found</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8f8f8;
            color: #222;
            padding: 2rem;
            box-sizing: border-box;
        }

        main {
            text-align: center;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 2rem;
            max-width: 36rem;
            width: 100%;
        }

        h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.75rem;
        }

        p {
            margin: 0;
            color: #555;
        }

        a {
            color: inherit;
        }
    </style>
</head>

<body>
    <main>
        <h1>404 — Page not found</h1>
        <p>The requested path does not exist. <a href="/">Go back to the gallery</a>.</p>
    </main>
</body>

</html>