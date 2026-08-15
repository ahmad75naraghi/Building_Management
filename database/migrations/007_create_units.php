<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_007_create_units
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            block_id INT DEFAULT NULL,
            floor_id INT DEFAULT NULL,
            unit_number VARCHAR(50) NOT NULL,
            area FLOAT DEFAULT NULL,
            type VARCHAR(20) DEFAULT 'residential',
            owner_user_id INT DEFAULT NULL,
            tenant_user_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (block_id) REFERENCES blocks(id) ON DELETE SET NULL,
            FOREIGN KEY (floor_id) REFERENCES floors(id) ON DELETE SET NULL,
            FOREIGN KEY (owner_user_id) REFERENCES users(id),
            FOREIGN KEY (tenant_user_id) REFERENCES users(id),
            UNIQUE KEY unique_unit_per_floor (floor_id, unit_number),
            INDEX idx_building (building_id),
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS units");
    }
}

return (new Migration_007_create_units())->up();
