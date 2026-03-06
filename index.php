<?php

declare(strict_types=1);

function getRequestPath(): string
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir));
    }

    $path = '/' . ltrim($path, '/');
    return $path === '' ? '/' : $path;
}

$path = rtrim(getRequestPath(), '/');
if ($path === '') {
    $path = '/';
}

if ($path === '/api') {
    require __DIR__ . '/api.php';
    exit;
}

if ($path === '/build') {
    require __DIR__ . '/build.php';
    exit;
}

if ($path === '/app.js') {
    $appFile = __DIR__ . '/app.js';
    if (!is_file($appFile) || !is_readable($appFile)) {
        require __DIR__ . '/404.php';
        exit;
    }

    header('Content-Type: application/javascript; charset=utf-8');
    readfile($appFile);
    exit;
}

require __DIR__ . '/list.php';