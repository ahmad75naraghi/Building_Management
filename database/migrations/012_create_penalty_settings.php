<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_012_create_penalty_settings
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS penalty_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            penalty_type VARCHAR(20) DEFAULT 'percentage', -- percentage | fixed_amount
            penalty_value DECIMAL(15,2) NOT NULL,
            delay_days INT DEFAULT 1,
            applies_to VARCHAR(50) DEFAULT 'unconfirmed_payments',
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_building (building_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS penalty_settings");
    }
}

return (new Migration_012_create_penalty_settings())->up();
