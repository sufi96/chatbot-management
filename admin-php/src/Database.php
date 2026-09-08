<?php

namespace App;

use PDO;
use PDOException;

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $connectionType = getenv('DB_CONNECTION') ?: 'pgsql';
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $database = getenv('DB_DATABASE') ?: 'chatbot_hub';
        $username = getenv('DB_USERNAME') ?: 'postgres';
        $password = getenv('DB_PASSWORD') ?: 'postgres';

        // Attempt PostgreSQL first
        if ($connectionType === 'pgsql') {
            try {
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
                self::$pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 2
                ]);
                return self::$pdo;
            } catch (PDOException $e) {
                // Graceful fallback to shared local SQLite
                // Path points to api-engine/chatbot_hub.db
                $sqlitePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'api-engine' . DIRECTORY_SEPARATOR . 'chatbot_hub.db';
                $dsn = "sqlite:" . $sqlitePath;
                self::$pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return self::$pdo;
            }
        }

        // Direct SQLite connection if explicitly requested
        $sqlitePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'api-engine' . DIRECTORY_SEPARATOR . 'chatbot_hub.db';
        $dsn = "sqlite:" . $sqlitePath;
        self::$pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return self::$pdo;
    }
}
