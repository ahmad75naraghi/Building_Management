<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_011_create_receipts
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS receipts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cost_payment_id INT NOT NULL UNIQUE,
            file_path VARCHAR(500) NOT NULL,
            file_size INT DEFAULT NULL,
            mime_type VARCHAR(100) DEFAULT NULL,
            original_name VARCHAR(255) DEFAULT NULL,
            is_public TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (cost_payment_id) REFERENCES cost_payments(id) ON DELETE CASCADE,
            INDEX idx_payment (cost_payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS receipts");
    }
}

return (new Migration_011_create_receipts())->up();
