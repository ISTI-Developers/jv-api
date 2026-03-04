<?php

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/env.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$base = '/jv-api/public';
$route = str_replace($base, '', $uri);

switch ($route) {
    case '/auth/login':
        require '../api/auth/login.php';
        break;

    case '/auth/register':
        require '../api/auth/register.php';
        break;

    case '/auth/logout':
        require '../api/auth/logout.php';
        break;

    case '/auth/change-password':
        require '../api/auth/change-password.php';
        break;

    case '/auth/forgot-password':
        require '../api/auth/forgot-password.php';
        break;

    case '/auth/verify-otp':
        require '../api/auth/verify-otp.php';
        break;

    case '/auth/reset-password':
        require '../api/auth/reset-password.php';
        break;

    case '/admin/users/reset-password':
        require '../api/admin/users/reset-password.php';
        break;

    case '/admin/users/invite':
        require '../api/admin/users/invite.php';
        break;

    case '/admin/users/toggle-status':
        require '../api/admin/users/toggle-status.php';
        break;

    case '/admin/users':
        require '../api/admin/users/index.php';
        break;

    case '/users/me':
        require '../api/users/me.php';
        break;

    case '/users/update-profile':
        require '../api/users/update-profile.php';
        break;

    case '/admin/users/update-profile':
        require '../api/admin/users/update-profile.php';
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
}
