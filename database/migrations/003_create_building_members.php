<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_003_create_building_members
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS building_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            building_id INT NOT NULL,
            role VARCHAR(50) DEFAULT 'resident',
            status VARCHAR(20) DEFAULT 'active',
            invited_by INT DEFAULT NULL,
            invitation_token VARCHAR(255) DEFAULT NULL,
            invitation_expires_at TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (building_id) REFERENCES buildings(id),
            UNIQUE KEY unique_user_building (user_id, building_id),
            INDEX idx_building (building_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS building_members");
    }
}

return (new Migration_003_create_building_members())->up();
