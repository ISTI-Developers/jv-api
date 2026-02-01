<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {

  public static function handle() {
    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
      self::unauthorized('Missing Authorization header');
    }

    if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
      self::unauthorized('Invalid Authorization format');
    }

    $token = $matches[1];

    $config = require __DIR__ . '/../config/jwt.php';

    try {
      $decoded = JWT::decode($token, new Key($config['secret'], $config['algo']));
      return $decoded; // contains sub, email, iat, exp
    } catch (Exception $e) {
      self::unauthorized('Invalid or expired token');
    }
  }

  private static function unauthorized($message) {
    http_response_code(401);
    echo json_encode(['error' => $message]);
    exit;
  }
}
