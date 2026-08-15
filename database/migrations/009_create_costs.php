<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_009_create_costs
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            amount DECIMAL(15,2) NOT NULL,
            cost_type VARCHAR(50) DEFAULT 'periodic', -- periodic | one_time
            target_audience VARCHAR(50) DEFAULT 'all', -- all | owners | tenants | residents
            division_method VARCHAR(50) DEFAULT 'fixed_share', -- fixed_share | area | people_count
            division_details JSON DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            is_recurring TINYINT(1) DEFAULT 0,
            recurring_interval VARCHAR(20) DEFAULT 'monthly', -- monthly | quarterly | yearly
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_building (building_id),
            INDEX idx_due_date (due_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS costs");
    }
}

return (new Migration_009_create_costs())->up();
