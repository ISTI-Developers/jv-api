<?php

class Database
{
    private static ?PDO $conn = null;

    public static function connect(): PDO
    {
        if (self::$conn === null) {
            self::$conn = new PDO(
                sprintf(
                    "mysql:host=%s;dbname=%s;charset=utf8mb4",
                    $_ENV['DB_HOST'],
                    $_ENV['DB_NAME']
                ),
                $_ENV['DB_USER'],
                $_ENV['DB_PASS'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            self::$conn->exec("SET time_zone = '+08:00'");
        }

        return self::$conn;
    }
}
