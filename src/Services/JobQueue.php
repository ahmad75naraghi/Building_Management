<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * صف کار پس‌زمینهٔ مبتنی بر دیتابیس — مناسب هاست اشتراکی (بدون نیاز به
 * زیرساخت اضافه). کارها در جدول `jobs` ثبت می‌شوند و توسط کران
 * `scripts/queue.php` پردازش می‌شوند.
 *
 * فعال‌سازی: در `.env` مقدار `QUEUE_DRIVER=database` قرار گیرد؛ در غیر
 * این صورت همه‌چیز همگام (مثل قبل) اجرا می‌شود.
 */
final class JobQueue
{
    /** وضعیت جدول صف در این فرایند (کش) */
    private static ?bool $available = null;

    /** آیا جدول صف موجود است؟ (هرگز استثنا نمی‌دهد) */
    public static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        try {
            Database::getConnection()->query('SELECT id FROM jobs LIMIT 1');
            self::$available = true;
        } catch (\Throwable $e) {
            self::$available = false;
        }
        return self::$available;
    }

    /** برای تست‌ها: کش وضعیت جدول پاک شود */
    public static function resetAvailabilityCache(): void
    {
        self::$available = null;
    }

    /** افزودن کار به صف؛ اگر جدول موجود نباشد نال برمی‌گردد */
    public static function enqueue(string $jobType, array $payload, int $delaySeconds = 0, int $maxAttempts = 3): ?int
    {
        if (!self::isAvailable()) {
            return null;
        }
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                'INSERT INTO jobs (job_type, payload, status, max_attempts, available_at)
                 VALUES (?, ?, \'pending\', ?, ' . self::sqlNowPlus($delaySeconds) . ')'
            );
            $stmt->execute([$jobType, json_encode($payload, JSON_UNESCAPED_UNICODE), $maxAttempts]);
            return (int) $db->lastInsertId();
        } catch (\Throwable $e) {
            Logger::warning('JobQueue', 'ثبت کار در صف ناموفق بود', ['job_type' => $jobType], $e);
            return null;
        }
    }

    /** مقدار لیترال زمان «اکنون + تأخیر» — محاسبه در PHP برای پرتابیلیت کامل */
    private static function sqlNowPlus(int $delaySeconds): string
    {
        return "'" . date('Y-m-d H:i:s', time() + max(0, $delaySeconds)) . "'";
    }

    /**
     * گرفتن قدیمی‌ترین کار آماده برای اجرا.
     *
     * @return array{id:int, job_type:string, payload:array, attempts:int, max_attempts:int}|null
     */
    public static function claim(): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        $db = Database::getConnection();
        $now = "'" . date('Y-m-d H:i:s') . "'";

        $stmt = $db->prepare(
            "SELECT * FROM jobs WHERE status = 'pending' AND available_at <= {$now}
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // تصاحب اتمیک: فقط اگر هنوز کسی آن را برنداشته باشد
        $upd = $db->prepare(
            "UPDATE jobs SET status = 'running', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([(int) $row['id']]);
        if ($upd->rowCount() !== 1) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'job_type' => (string) $row['job_type'],
            'payload' => is_array($payload) ? $payload : [],
            'attempts' => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
        ];
    }

    /** علامت‌گذاری کار به‌عنوان انجام‌شده */
    public static function complete(int $jobId): void
    {
        try {
            Database::getConnection()->prepare(
                "UPDATE jobs SET status = 'done', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            )->execute([$jobId]);
        } catch (\Throwable $e) {
            // بی‌صدا — وضعیت صف نباید فرایند را متوقف کند
        }
    }

    /** شکست کار: اگر تلاش باقی مانده باشد دوباره به صف برمی‌گردد، وگرنه شکست قطعی */
    public static function fail(int $jobId, int $attempts, int $maxAttempts, string $error): void
    {
        try {
            $db = Database::getConnection();
            if ($attempts + 1 < $maxAttempts) {
                $db->prepare(
                    "UPDATE jobs SET status = 'pending', attempts = attempts + 1, last_error = ?,
                            available_at = " . self::sqlNowPlus(60) . ", updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?"
                )->execute([$error, $jobId]);
                return;
            }
            $db->prepare(
                "UPDATE jobs SET status = 'failed', attempts = attempts + 1, last_error = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            )->execute([$error, $jobId]);
        } catch (\Throwable $e) {
            Logger::warning('JobQueue', 'ثبت شکست کار ناموفق بود', ['job_id' => $jobId], $e);
        }
    }

    /** شمارش وضعیت‌ها برای مانیتورینگ */
    public static function stats(): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        $rows = Database::getConnection()->query('SELECT status, COUNT(*) AS c FROM jobs GROUP BY status')
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $stats = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $stats[(string) $r['status']] = (int) $r['c'];
        }
        return $stats;
    }
}
