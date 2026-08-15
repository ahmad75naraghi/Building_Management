<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Config\AppConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class JwtHelper
{
    public static function generate(array $payload, int $expiry = null): string
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + ($expiry ?? AppConfig::JWT_EXPIRY);
        $payload['iss'] = 'building-mgmt';
        return JWT::encode($payload, AppConfig::JWT_SECRET, AppConfig::JWT_ALGO);
    }

    public static function verify(string $token): array
    {
        $decoded = JWT::decode($token, new Key(AppConfig::JWT_SECRET, AppConfig::JWT_ALGO));
        return (array) $decoded;
    }
}
