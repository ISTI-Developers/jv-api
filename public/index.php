<?php

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/env.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$base = '/jv-api/public';
$route = str_replace($base, '', $uri);

switch ($route) {
    case '/health':
        require '../api/health.php';
        break;

    case '/auth/login':
        require '../api/auth/login.php';
        break;

    case '/auth/register':
        require '../api/auth/register.php';
        break;

    case '/auth/logout':
        require '../api/auth/logout.php';
        break;

    case '/users/me':
        require '../api/users/me.php';
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
}
