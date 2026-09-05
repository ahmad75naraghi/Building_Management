<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_004_create_hierarchy_settings
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS building_hierarchy_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL UNIQUE,
            has_blocks TINYINT(1) DEFAULT 1,
            has_floors TINYINT(1) DEFAULT 1,
            has_units TINYINT(1) DEFAULT 1,
            has_common_areas TINYINT(1) DEFAULT 1,
            settings_json JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS building_hierarchy_settings");
    }
}

return (new Migration_004_create_hierarchy_settings())->up();
