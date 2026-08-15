<?php

declare(strict_types=1);

namespace App\Config;

use App\Utilities\CacheHelper;

final class AppConfig
{
    public const APP_NAME = 'Building Management Pro';
    public const APP_VERSION = '1.0.0';
    public const APP_ENV = 'development'; // development | production

    public const JWT_SECRET = 'your-very-secret-jwt-key-change-in-production';
    public const JWT_ALGO = 'HS256';
    public const JWT_EXPIRY = 3600; // 1 hour
    public const JWT_REFRESH_EXPIRY = 604800; // 7 days

    public const REDIS_HOST = '127.0.0.1';
    public const REDIS_PORT = 6379;
    public const REDIS_DB = 0;
    public const REDIS_PASSWORD = null;

    public const STORAGE_PATH = __DIR__ . '/../../storage';
    public const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public static function getStoragePath(string $type, int|string $id): string
    {
        $base = self::STORAGE_PATH . '/' . $type . '/' . $id;
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        return $base;
    }

    public static function getDatabaseConfig(): array
    {
        return [
            'dsn' => 'mysql:host=localhost;dbname=building_mgmt;charset=utf8mb4',
            'username' => 'root',
            'password' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
        ];
    }

    public static function isProduction(): bool
    {
        return self::APP_ENV === 'production';
    }
}
