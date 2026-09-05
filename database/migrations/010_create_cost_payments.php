<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_010_create_cost_payments
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS cost_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cost_id INT NOT NULL,
            user_id INT NOT NULL,
            amount_paid DECIMAL(15,2) DEFAULT NULL,
            status VARCHAR(30) DEFAULT 'pending', -- pending | upload_receipt | confirmed | rejected
            receipt_path VARCHAR(500) DEFAULT NULL,
            receipt_is_public TINYINT(1) DEFAULT 0,
            notes TEXT DEFAULT NULL,
            confirmed_by INT DEFAULT NULL,
            confirmed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cost_id) REFERENCES costs(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (confirmed_by) REFERENCES users(id),
            INDEX idx_cost (cost_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS cost_payments");
    }
}

return (new Migration_010_create_cost_payments())->up();
