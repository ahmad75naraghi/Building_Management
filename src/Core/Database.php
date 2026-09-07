<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\AppConfig;
use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = AppConfig::getDatabaseConfig();
            self::$connection = new PDO(
                $config['dsn'],
                $config['username'],
                $config['password'],
                $config['options']
            );
        }
        return self::$connection;
    }

    /**
     * آماده‌سازی «درج بدون خطا در تکرار» به‌صورت قابل‌حمل:
     * روی MySQL «INSERT IGNORE» و روی SQLite (محیط تست) «INSERT OR IGNORE».
     */
    public static function prepareInsertIgnore(PDO $db, string $sql): \PDOStatement
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = (string) preg_replace('/INSERT\s+IGNORE/i', 'INSERT OR IGNORE', $sql);
        }
        return $db->prepare($sql);
    }

    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    public static function rollBack(): bool
    {
        return self::getConnection()->rollBack();
    }
}
