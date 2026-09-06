<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * ورود/ثبت‌نام یکپارچه با شماره موبایل و کد یک‌بارمصرف (OTP).
 *
 * - جدول otp_codes برای نگه‌داری کدهای ارسال‌شده
 * - password_hash کاربران nullable می‌شود (کاربری که هنوز رمز ست نکرده)
 * - name هم nullable می‌شود (کاربر تازه‌واردی که هنوز نامش را وارد نکرده)
 */
class Migration_026_otp_auth
{
    public function up(): void
    {
        $db = Database::getConnection();

        // کاربری که با OTP وارد شده ولی هنوز رمز ست نکرده است
        try {
            $db->exec("ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            error_log('[026] password_hash alter skipped: ' . $e->getMessage());
        }

        // کاربری که هنوز نام و نام خانوادگی وارد نکرده است
        try {
            $db->exec("ALTER TABLE users MODIFY name VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            error_log('[026] name alter skipped: ' . $e->getMessage());
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS otp_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                purpose VARCHAR(30) NOT NULL DEFAULT 'auth',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                consumed_at TIMESTAMP NULL DEFAULT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_otp_phone (phone),
                INDEX idx_otp_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        try {
            $db->exec("DROP TABLE IF EXISTS otp_codes");
        } catch (Throwable $e) {
            error_log('[026] rollback step skipped: ' . $e->getMessage());
        }
    }
}

return (new Migration_026_otp_auth())->up();
