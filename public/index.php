<?php

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/env.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = '/jv-api/public';
$route = str_replace($base, '', $uri);

// remove trailing slash
$route = rtrim($route, '/');

// map route → file
$path = __DIR__ . '/../api' . $route . '.php';

if (file_exists($path)) {
    require $path;
    exit;
}

// fallback: support index.php inside folder
$pathIndex = __DIR__ . '/../api' . $route . '/index.php';

if (file_exists($pathIndex)) {
    require $pathIndex;
    exit;
}

// not found
http_response_code(404);
echo json_encode(['error' => 'Route not found']);
