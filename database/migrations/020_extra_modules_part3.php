<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_020_extra_modules_consumption_emergency_meetings_reviews
{
    public function up(): void
    {
        $db = Database::getConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS consumption_readings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            unit_id INT DEFAULT NULL,
            consumption_type VARCHAR(20) DEFAULT 'electricity',
            reading_value DECIMAL(15,3) NOT NULL,
            reading_date DATE NOT NULL,
            notes TEXT DEFAULT NULL,
            created_by INT NOT NULL,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS emergency_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            contact_name VARCHAR(255) NOT NULL,
            contact_role VARCHAR(100) DEFAULT NULL,
            phone VARCHAR(50) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS emergency_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            alert_type VARCHAR(50) DEFAULT 'general',
            message TEXT NOT NULL,
            sent_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (sent_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS meetings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            meeting_date DATETIME NOT NULL,
            location VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'scheduled',
            created_by INT NOT NULL,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS meeting_minutes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            minutes_content TEXT NOT NULL,
            recorded_by INT NOT NULL,
            FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            FOREIGN KEY (recorded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS review_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            user_id INT NOT NULL,
            category_id INT DEFAULT NULL,
            rating INT DEFAULT 5,
            review_text TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (category_id) REFERENCES review_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec("DROP TABLE IF EXISTS reviews");
        $db->exec("DROP TABLE IF EXISTS review_categories");
        $db->exec("DROP TABLE IF EXISTS meeting_minutes");
        $db->exec("DROP TABLE IF EXISTS meetings");
        $db->exec("DROP TABLE IF EXISTS emergency_alerts");
        $db->exec("DROP TABLE IF EXISTS emergency_contacts");
        $db->exec("DROP TABLE IF EXISTS consumption_readings");
    }
}

return (new Migration_020_extra_modules_consumption_emergency_meetings_reviews())->up();
