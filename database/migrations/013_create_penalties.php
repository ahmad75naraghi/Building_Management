<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_013_create_penalties
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS penalties (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cost_payment_id INT NOT NULL,
            building_id INT NOT NULL,
            penalty_amount DECIMAL(15,2) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reason TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            FOREIGN KEY (cost_payment_id) REFERENCES cost_payments(id) ON DELETE CASCADE,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_payment (cost_payment_id),
            INDEX idx_building (building_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS penalties");
    }
}

return (new Migration_013_create_penalties())->up();
