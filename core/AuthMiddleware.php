<?php

require_once __DIR__ . '/../config/database.php';

class AuthMiddleware
{
  public static function handle()
  {
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    $authorization =
      $headers['Authorization']
      ?? $headers['authorization']
      ?? $_SERVER['HTTP_AUTHORIZATION']
      ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
      ?? $_SERVER['Authorization']
      ?? $_SERVER['authorization']
      ?? null;

    if (!$authorization) {
      self::unauthorized('Missing Authorization header');
    }

    if (!preg_match('/Bearer\s+(\S+)/', $authorization, $matches)) {
      self::unauthorized('Invalid Authorization format');
    }

    $sessionId = $matches[1];

    $db = Database::connect();

    $stmt = $db->prepare("
      SELECT user_id
      FROM user_sessions
      WHERE session_id = ?
        AND expires_at > NOW()
      LIMIT 1
    ");

    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
      self::unauthorized('Invalid or expired session');
    }

    return (object) [
      'id' => $session->user_id
    ];
  }

  private static function unauthorized($message)
  {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
  }
}
