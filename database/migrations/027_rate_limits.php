<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * جدول محدودیت نرخ درخواست‌ها.
 *
 * برای جلوگیری از حمله جست‌وجوی فراگیر (brute force) روی ورود با رمز عبور.
 * هر تلاش ناموفق یک رکورد است؛ کلیدها به‌صورت SHA-256 ذخیره می‌شوند تا
 * شماره موبایل و IP خام در پایگاه‌داده نمانند.
 */
class Migration_027_rate_limits
{
    public function up(): void
    {
        $db = Database::getConnection();

        $db->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                limit_key CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_rate_key (limit_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        try {
            $db->exec("DROP TABLE IF EXISTS rate_limits");
        } catch (Throwable $e) {
            error_log('[027] rollback step skipped: ' . $e->getMessage());
        }
    }
}

return (new Migration_027_rate_limits())->up();
