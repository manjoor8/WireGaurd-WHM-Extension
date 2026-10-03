<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;
    private static ?string $customPath = null;

    public static function setPath(string $path): void
    {
        self::$customPath = $path;
        self::$instance = null;
    }

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dbPath = self::$customPath
            ?: getenv('DATABASE_PATH')
            ?: '/var/lib/wireguard-manager/wireguard.db';

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                // If system directory cannot be created, fallback to storage directory in project
                $fallbackDir = dirname(__DIR__) . '/storage';
                if (!is_dir($fallbackDir)) {
                    @mkdir($fallbackDir, 0750, true);
                }
                $dbPath = $fallbackDir . '/wireguard.db';
            }
        }

        try {
            $pdo = new PDO("sqlite:" . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec('PRAGMA foreign_keys = ON;');
            $pdo->exec('PRAGMA journal_mode = WAL;');
            $pdo->exec('PRAGMA busy_timeout = 5000;');

            self::initializeSchema($pdo);

            self::$instance = $pdo;
            return self::$instance;
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failure: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private static function initializeSchema(PDO $pdo): void
    {
        $schemaFile = dirname(__DIR__) . '/database/schema.sql';
        if (file_exists($schemaFile)) {
            // Check if tables already exist
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clients'");
            if (!$stmt->fetch()) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
        }
    }
}
