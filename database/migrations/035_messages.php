<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * مایگریشن ۰۳۵ — صندوق پیام درون‌اپی (چت بین اعضای ساختمان)
 *
 * پیام‌های متنی بین اعضای یک ساختمان (مدیر ↔ ساکن و برعکس).
 * هر پیام متعلق به یک ساختمان است و فقط اعضای همان ساختمان آن را می‌بینند.
 */
class Migration_035_messages
{
    public function up(): void
    {
        $db = Database::getConnection();

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    building_id INT NOT NULL,
                    sender_id INT NOT NULL,
                    recipient_id INT NOT NULL,
                    body TEXT NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    read_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_messages_recipient_read (recipient_id, is_read),
                    INDEX idx_messages_building_pair (building_id, sender_id, recipient_id),
                    INDEX idx_messages_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            error_log('[035] messages table skipped: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
    }
}

return (new Migration_035_messages())->up();
