<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_008_create_common_areas
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS common_areas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            bookable TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            INDEX idx_building (building_id),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS common_areas");
    }
}

return (new Migration_008_create_common_areas())->up();
