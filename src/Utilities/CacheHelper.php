<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Config\AppConfig;
use Predis\Client;

final class CacheHelper
{
    private static ?Client $redis = null;

    private static function getClient(): Client
    {
        if (self::$redis === null) {
            $params = [
                'host' => AppConfig::REDIS_HOST,
                'port' => AppConfig::REDIS_PORT,
                'database' => AppConfig::REDIS_DB,
            ];
            if (AppConfig::REDIS_PASSWORD !== null) {
                $params['password'] = AppConfig::REDIS_PASSWORD;
            }
            self::$redis = new Client($params);
        }
        return self::$redis;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = self::getClient()->get($key);
            if ($value === null) {
                return $default;
            }
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        } catch (\Exception $e) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        try {
            $data = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            return (bool) self::getClient()->setex($key, $ttl, $data);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function delete(string $key): bool
    {
        try {
            return (bool) self::getClient()->del($key);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function clearByPattern(string $pattern): int
    {
        try {
            $keys = self::getClient()->keys($pattern);
            if (empty($keys)) {
                return 0;
            }
            return self::getClient()->del($keys);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public static function tagClear(string $tag): int
    {
        return self::clearByPattern("*{$tag}*");
    }
}
