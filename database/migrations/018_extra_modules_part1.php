<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_018_extra_modules_bookings_announcement_maintenance
{
    public function up(): void
    {
        $db = Database::getConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            common_area_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            booking_date DATE NOT NULL,
            start_time TIME DEFAULT NULL,
            end_time TIME DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            INDEX idx_building (building_id),
            INDEX idx_date (booking_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_pinned TINYINT(1) DEFAULT 0,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS maintenance_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            status VARCHAR(30) DEFAULT 'pending',
            assigned_technician_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (assigned_technician_id) REFERENCES users(id),
            INDEX idx_building (building_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec("DROP TABLE IF EXISTS bookings");
        $db->exec("DROP TABLE IF EXISTS announcements");
        $db->exec("DROP TABLE IF EXISTS maintenance_requests");
    }
}

return (new Migration_018_extra_modules_bookings_announcement_maintenance())->up();
