<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_017_create_notifications
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            building_id INT DEFAULT NULL,
            notification_type VARCHAR(50) DEFAULT 'general',
            title VARCHAR(255) NOT NULL,
            message TEXT DEFAULT NULL,
            data JSON DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            is_email_sent TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE SET NULL,
            INDEX idx_user (user_id),
            INDEX idx_building (building_id),
            INDEX idx_read (is_read),
            INDEX idx_type (notification_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS notifications");
    }
}

return (new Migration_017_create_notifications())->up();
