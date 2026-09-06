<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * محدودکننده نرخ درخواست‌ها بر پایه پایگاه‌داده.
 *
 * برای جلوگیری از حمله جست‌وجوی فراگیر (brute force) روی ورود با رمز عبور و
 * هر عملیات حساس دیگری استفاده می‌شود.
 *
 * منطق کار: هر تلاش ناموفق ثبت می‌شود. وقتی تعداد تلاش‌های ناموفق در بازه
 * زمانی مشخص از حد مجاز بگذرد، کلید تا پایان دوره قفل می‌شود. یک ورود موفق
 * سابقه را پاک می‌کند.
 *
 * نمونه استفاده:
 *   $limiter = new RateLimiter();
 *   if ($limiter->tooManyAttempts($key, 5, 900)) { ... خطای 429 ... }
 *   $limiter->hit($key, 900);      // پس از تلاش ناموفق
 *   $limiter->clear($key);          // پس از موفقیت
 */
final class RateLimiter
{
    /** حداکثر تلاش ناموفق ورود با رمز */
    public const LOGIN_MAX_ATTEMPTS = 5;

    /** بازه شمارش تلاش‌های ورود (ثانیه) — ۱۵ دقیقه */
    public const LOGIN_DECAY_SECONDS = 900;

    /** آیا جدول موجود است؟ برای جلوگیری از تکرار بررسی در یک درخواست */
    private static ?bool $tableReady = null;

    /**
     * آیا تعداد تلاش‌ها از حد مجاز گذشته است؟
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return $this->attempts($key, $decaySeconds) >= $maxAttempts;
    }

    /**
     * تعداد تلاش‌های ناموفق ثبت‌شده در بازه اخیر.
     */
    public function attempts(string $key, int $decaySeconds): int
    {
        if (!$this->ensureTable()) {
            return 0;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM rate_limits WHERE limit_key = ? AND created_at > ?"
            );
            $stmt->execute([$this->hashKey($key), $this->at(time() - $decaySeconds)]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            // محدودکننده نباید مانع ورود کاربران قانونی شود
            Logger::error('RateLimiter', 'شمارش تلاش‌ها ناموفق بود', ['key' => $this->maskKey($key)], $e);
            return 0;
        }
    }

    /**
     * ثبت یک تلاش ناموفق.
     */
    public function hit(string $key, int $decaySeconds): void
    {
        if (!$this->ensureTable()) {
            return;
        }

        try {
            $db = Database::getConnection();
            $db->prepare("INSERT INTO rate_limits (limit_key, created_at) VALUES (?, ?)")
               ->execute([$this->hashKey($key), $this->at(time())]);

            // پاک‌سازی گاه‌به‌گاه رکوردهای منقضی (حدود ۲٪ درخواست‌ها)
            if (random_int(1, 50) === 1) {
                $this->purge($decaySeconds);
            }
        } catch (\Throwable $e) {
            Logger::error('RateLimiter', 'ثبت تلاش ناموفق انجام نشد', ['key' => $this->maskKey($key)], $e);
        }
    }

    /**
     * پاک‌کردن سابقه یک کلید (پس از ورود موفق).
     */
    public function clear(string $key): void
    {
        if (!$this->ensureTable()) {
            return;
        }

        try {
            Database::getConnection()
                ->prepare("DELETE FROM rate_limits WHERE limit_key = ?")
                ->execute([$this->hashKey($key)]);
        } catch (\Throwable $e) {
            Logger::error('RateLimiter', 'پاک‌سازی سابقه ناموفق بود', ['key' => $this->maskKey($key)], $e);
        }
    }

    /**
     * چند ثانیه دیگر تا باز شدن قفل باقی مانده است؟
     */
    public function availableIn(string $key, int $decaySeconds): int
    {
        if (!$this->ensureTable()) {
            return 0;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT created_at FROM rate_limits
                 WHERE limit_key = ? AND created_at > ?
                 ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute([$this->hashKey($key), $this->at(time() - $decaySeconds)]);
            $oldest = $stmt->fetchColumn();
            if (!$oldest) {
                return 0;
            }
            $remaining = $decaySeconds - (time() - (int) strtotime((string) $oldest));
            return max(0, $remaining);
        } catch (\Throwable $e) {
            Logger::error('RateLimiter', 'محاسبه زمان انتظار ناموفق بود', ['key' => $this->maskKey($key)], $e);
            return 0;
        }
    }

    /**
     * حذف رکوردهای قدیمی‌تر از بازه.
     */
    public function purge(int $decaySeconds): void
    {
        try {
            Database::getConnection()
                ->prepare("DELETE FROM rate_limits WHERE created_at < ?")
                ->execute([$this->at(time() - $decaySeconds)]);
        } catch (\Throwable $e) {
            Logger::warning('RateLimiter', 'پاک‌سازی رکوردهای منقضی انجام نشد', ['reason' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------ کمکی‌ها

    /**
     * ساخت کلید محدودیت برای ورود، بر پایه شماره موبایل و IP.
     * ترکیب هر دو، هم از حمله به یک حساب و هم از حمله گسترده از یک IP جلوگیری می‌کند.
     */
    public static function loginKey(string $identifier, ?string $ip = null): string
    {
        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        return 'login:' . strtolower(trim($identifier)) . '|' . $ip;
    }

    /**
     * کلید فقط بر پایه IP (برای جلوگیری از پویش شماره‌های مختلف از یک مبدأ).
     */
    public static function ipKey(string $action, ?string $ip = null): string
    {
        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        return $action . ':ip|' . $ip;
    }

    /**
     * کلیدها هش می‌شوند تا شماره موبایل و IP به‌صورت خام در جدول نمانند.
     */
    private function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /** نسخه ماسک‌شده کلید برای لاگ (بدون افشای شماره کامل) */
    private function maskKey(string $key): string
    {
        return substr(hash('sha256', $key), 0, 12);
    }

    /** قالب زمانی سازگار با MySQL و SQLite */
    private function at(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * اطمینان از وجود جدول. اگر مایگریشن اجرا نشده باشد، جدول ساخته می‌شود
     * تا نبودِ آن باعث از کار افتادن ورود نشود.
     */
    private function ensureTable(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }

        try {
            $db = Database::getConnection();
            $driver = (string) $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS rate_limits (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        limit_key TEXT NOT NULL,
                        created_at TEXT NOT NULL
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_rate_key ON rate_limits (limit_key, created_at)");
            } else {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS rate_limits (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        limit_key CHAR(64) NOT NULL,
                        created_at DATETIME NOT NULL,
                        INDEX idx_rate_key (limit_key, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }

            self::$tableReady = true;
        } catch (\Throwable $e) {
            Logger::error('RateLimiter', 'ساخت جدول محدودیت نرخ ناموفق بود', [], $e);
            self::$tableReady = false;
        }

        return self::$tableReady;
    }

    /** فقط برای تست: بازنشانی کش وضعیت جدول */
    public static function resetTableCache(): void
    {
        self::$tableReady = null;
    }
}
