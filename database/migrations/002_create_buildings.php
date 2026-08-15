<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_002_create_buildings
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS buildings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            address TEXT NOT NULL,
            created_by INT NOT NULL,
            custom_name VARCHAR(255) DEFAULT NULL,
            custom_logo_path VARCHAR(500) DEFAULT NULL,
            theme_color VARCHAR(20) DEFAULT '#1a73e8',
            hierarchy_settings JSON DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS buildings");
    }
}

return (new Migration_002_create_buildings())->up();
