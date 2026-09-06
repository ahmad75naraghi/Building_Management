<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

/**
 * تنظیمات برنامه.
 *
 * قاعده کلی: هیچ مقدار حساسی (رمز، کلید امضا) در کد نگه‌داری نمی‌شود.
 * همه از متغیرهای محیطی خوانده می‌شوند و در محیط تولید، نبودشان خطای صریح
 * می‌دهد تا برنامه با تنظیمات ناامن بالا نیاید.
 *
 * برای توسعه محلی، فایل .env را از روی .env.example بسازید.
 */
final class AppConfig
{
    public const APP_NAME = 'Building Management Pro';
    public const APP_VERSION = '1.0.0';

    public const JWT_ALGO = 'HS256';
    public const JWT_EXPIRY = 3600;          // ۱ ساعت
    public const JWT_REFRESH_EXPIRY = 604800; // ۷ روز

    public const REDIS_HOST = '127.0.0.1';
    public const REDIS_PORT = 6379;
    public const REDIS_DB = 0;
    public const REDIS_PASSWORD = null;

    public const STORAGE_PATH = __DIR__ . '/../../storage';
    public const MAX_FILE_SIZE = 5 * 1024 * 1024; // ۵ مگابایت
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** حداقل طول قابل قبول برای کلید امضای JWT */
    private const MIN_SECRET_LENGTH = 32;

    /** آیا فایل .env یک‌بار خوانده شده است؟ */
    private static bool $envLoaded = false;

    // ------------------------------------------------------------ محیط

    /**
     * خواندن یک متغیر محیطی (با پشتیبانی از فایل .env).
     */
    public static function env(string $key, ?string $default = null): ?string
    {
        self::loadEnvFile();

        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    /**
     * بارگذاری ساده فایل .env (بدون وابستگی بیرونی).
     * مقادیر موجود در محیط واقعی بازنویسی نمی‌شوند.
     */
    private static function loadEnvFile(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $path = dirname(__DIR__) . '/.env';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // حذف کوتیشن‌های اختیاری
            if (strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }

            // متغیر واقعی محیط اولویت دارد
            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * محیط اجرا. پیش‌فرض عمداً production است تا فراموش‌کردن تنظیم آن
     * باعث افشای جزئیات خطا نشود (پیش‌فرض امن).
     */
    public static function environment(): string
    {
        $env = strtolower((string) self::env('APP_ENV', 'production'));
        return in_array($env, ['development', 'testing', 'production'], true) ? $env : 'production';
    }

    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }

    public static function isTesting(): bool
    {
        return self::environment() === 'testing';
    }

    /** آیا اجرا در خط فرمان است؟ (تست‌ها و اسکریپت‌ها) */
    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    // ------------------------------------------------------------ اسرار

    /**
     * کلید امضای JWT.
     *
     * در محیط تولید نبودِ آن خطای صریح می‌دهد؛ در توسعه یک کلید موقتِ
     * مخصوصِ همین ماشین ساخته می‌شود تا کار متوقف نشود.
     *
     * @throws RuntimeException در محیط تولید وقتی JWT_SECRET تنظیم نشده باشد
     */
    public static function jwtSecret(): string
    {
        $secret = self::env('JWT_SECRET');

        if ($secret === null) {
            if (self::isProduction()) {
                throw new RuntimeException(
                    'متغیر محیطی JWT_SECRET تنظیم نشده است. '
                    . 'یک کلید تصادفی بسازید (مثلاً: openssl rand -base64 48) و در .env قرار دهید.'
                );
            }
            return self::developmentSecret();
        }

        if (strlen($secret) < self::MIN_SECRET_LENGTH && self::isProduction()) {
            throw new RuntimeException(
                'مقدار JWT_SECRET بیش از حد کوتاه است؛ حداقل ' . self::MIN_SECRET_LENGTH . ' کاراکتر لازم است.'
            );
        }

        return $secret;
    }

    /**
     * کلید موقت توسعه: برای هر نصب یکتاست و در storage ذخیره می‌شود.
     * هرگز در محیط تولید استفاده نمی‌شود.
     */
    private static function developmentSecret(): string
    {
        $dir = dirname(__DIR__) . '/storage';
        $file = $dir . '/.dev-jwt-secret';

        if (is_file($file)) {
            $existing = trim((string) @file_get_contents($file));
            if ($existing !== '') {
                return $existing;
            }
        }

        $secret = bin2hex(random_bytes(32));
        if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
            @file_put_contents($file, $secret, LOCK_EX);
            @chmod($file, 0600);
        }
        return $secret;
    }

    // ------------------------------------------------------------ مسیرها

    public static function getAppUrl(): string
    {
        return rtrim((string) self::env('APP_URL', 'https://file.falnic.com/b'), '/');
    }

    public static function getStoragePath(string $type, int|string $id): string
    {
        $base = self::STORAGE_PATH . '/' . $type . '/' . $id;
        if (!is_dir($base)) {
            mkdir($base, 0755, true);
        }
        return $base;
    }

    // ------------------------------------------------------------ پایگاه‌داده

    /**
     * تنظیمات اتصال به پایگاه‌داده.
     *
     * @throws RuntimeException در محیط تولید وقتی DB_PASSWORD تنظیم نشده باشد
     */
    public static function getDatabaseConfig(): array
    {
        $dsn = (string) self::env('DB_DSN', 'mysql:host=localhost;dbname=file_b;charset=utf8mb4');
        $username = (string) self::env('DB_USERNAME', 'file_b');
        $password = self::env('DB_PASSWORD');

        if ($password === null) {
            if (self::isProduction()) {
                throw new RuntimeException(
                    'متغیر محیطی DB_PASSWORD تنظیم نشده است. آن را در .env یا تنظیمات سرور قرار دهید.'
                );
            }
            $password = '';
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // این گزینه فقط برای درایور MySQL معنا دارد
        if (str_starts_with($dsn, 'mysql:') && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
        }

        return [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
            'options' => $options,
        ];
    }

    // ------------------------------------------------------------ سلامت تنظیمات

    /**
     * بررسی اینکه تنظیمات حیاتی برای محیط تولید کامل است.
     *
     * @return list<string> فهرست مشکلات؛ آرایه خالی یعنی همه‌چیز درست است
     */
    public static function validateForProduction(): array
    {
        $problems = [];

        $secret = self::env('JWT_SECRET');
        if ($secret === null) {
            $problems[] = 'JWT_SECRET تنظیم نشده است.';
        } elseif (strlen($secret) < self::MIN_SECRET_LENGTH) {
            $problems[] = 'JWT_SECRET کوتاه‌تر از ' . self::MIN_SECRET_LENGTH . ' کاراکتر است.';
        }

        if (self::env('DB_PASSWORD') === null) {
            $problems[] = 'DB_PASSWORD تنظیم نشده است.';
        }

        if (self::env('APP_URL') === null) {
            $problems[] = 'APP_URL تنظیم نشده است (برای ساخت لینک دعوت لازم است).';
        }

        if (self::env('OTP_DEBUG') === '1') {
            $problems[] = 'OTP_DEBUG فعال است؛ کد ورود در پاسخ API فاش می‌شود.';
        }

        return $problems;
    }
}
