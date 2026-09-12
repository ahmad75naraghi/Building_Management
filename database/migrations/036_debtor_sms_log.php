<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * مایگریشن ۰۳۶ — لاگ یادآوری پیامکی بدهکاران
 *
 * هر واحد در هر دورهٔ شمسی (YYYY-MM) حداکثر یک پیامک یادآوری بدهی دریافت
 * می‌کند؛ کلید یکتای (واحد، دوره) جلوی ارسال تکراری در اجراهای مکرر کران را می‌گیرد.
 */
class Migration_036_debtor_sms_log
{
    public function up(): void
    {
        $db = Database::getConnection();

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS debtor_sms_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    building_id INT NOT NULL,
                    unit_id INT NOT NULL,
                    user_id INT NOT NULL,
                    phone VARCHAR(20) NOT NULL,
                    amount DECIMAL(12,2) NOT NULL,
                    period CHAR(7) NOT NULL,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_debtor_sms_unit_period (unit_id, period),
                    INDEX idx_debtor_sms_period (period),
                    INDEX idx_debtor_sms_building (building_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('[036] debtor_sms_log table skipped: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
    }
}

return (new Migration_036_debtor_sms_log())->up();
