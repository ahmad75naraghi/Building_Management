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
