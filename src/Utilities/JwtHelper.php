<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Config\AppConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtHelper
{
    /**
     * کلید امضای JWT — همیشه از تنظیمات خوانده می‌شود.
     * در محیط تولید، نبودِ JWT_SECRET باعث خطای صریح می‌شود.
     */
    private static function secret(): string
    {
        return AppConfig::jwtSecret();
    }

    public static function generate(array $payload, ?int $expiry = null): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + ($expiry ?? AppConfig::JWT_EXPIRY);
        $payload['iss'] = 'building-mgmt';
        return JWT::encode($payload, self::secret(), AppConfig::JWT_ALGO);
    }

    public static function verify(string $token): array
    {
        $decoded = JWT::decode($token, new Key(self::secret(), AppConfig::JWT_ALGO));
        return (array) $decoded;
    }
}
