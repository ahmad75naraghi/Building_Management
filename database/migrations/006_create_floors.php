<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_006_create_floors
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS floors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            block_id INT DEFAULT NULL,
            floor_number INT DEFAULT NULL,
            name VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (block_id) REFERENCES blocks(id) ON DELETE SET NULL,
            INDEX idx_building (building_id),
            INDEX idx_block (block_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS floors");
    }
}

return (new Migration_006_create_floors())->up();
