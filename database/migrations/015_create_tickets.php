<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_015_create_tickets
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            user_id INT NOT NULL,
            unit_id INT DEFAULT NULL,
            category VARCHAR(50) DEFAULT 'technical', -- technical | financial | management | complaint | suggestion
            is_anonymous TINYINT(1) DEFAULT 0,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(30) DEFAULT 'open', -- open | in_progress | resolved | closed | rejected
            priority VARCHAR(20) DEFAULT 'normal', -- low | normal | high | urgent
            assigned_to INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL,
            FOREIGN KEY (assigned_to) REFERENCES users(id),
            INDEX idx_building (building_id),
            INDEX idx_category (category),
            INDEX idx_status (status),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS tickets");
    }
}

return (new Migration_015_create_tickets())->up();
