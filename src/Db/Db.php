<?php

declare(strict_types=1);

namespace App\Db;

use PDO;

class Db
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $name = $_ENV['DB_NAME'] ?? '';
            $user = $_ENV['DB_USER'] ?? '';
            $pass = $_ENV['DB_PASSWORD'] ?? '';

            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return self::$instance;
    }
}
