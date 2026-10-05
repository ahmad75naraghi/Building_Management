<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * جدول ردیابی یادآوری‌های رویدادها (جلسه‌ها و رزروها).
 *
 * اسکریپت کران `scripts/reminders.php` برای هر رویداد نزدیک، یک ردیف
 * به‌ازای هر کاربر و هر کانال (اعلان درون‌اپ / پیامک) می‌سازد.
 * کلید یکتا مانع ارسال تکراری می‌شود؛ `sent_at` زمان ارسال را ثبت می‌کند.
 */
class Migration_028_create_event_reminders
{
    public function up(): void
    {
        $db = Database::getConnection();

        $db->exec("
            CREATE TABLE IF NOT EXISTS event_reminders (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(20) NOT NULL,
                event_id INT NOT NULL,
                user_id INT NOT NULL,
                building_id INT NOT NULL,
                remind_at DATETIME NOT NULL,
                channel VARCHAR(20) NOT NULL DEFAULT 'notification',
                sent_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_reminder (event_type, event_id, user_id, channel),
                INDEX idx_event (event_type, event_id),
                INDEX idx_user (user_id, sent_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        try {
            $db->exec("DROP TABLE IF EXISTS event_reminders");
        } catch (Throwable $e) {
            error_log('[028] rollback step skipped: ' . $e->getMessage());
        }
    }
}

return (new Migration_028_create_event_reminders())->up();
