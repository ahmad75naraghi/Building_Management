<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_014_create_invitations
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            invited_email VARCHAR(255) DEFAULT NULL,
            invited_phone VARCHAR(50) DEFAULT NULL,
            role VARCHAR(50) DEFAULT 'resident',
            token VARCHAR(255) UNIQUE NOT NULL,
            status VARCHAR(20) DEFAULT 'pending', -- pending | accepted | expired | revoked
            invited_by INT NOT NULL,
            expires_at TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            accepted_at TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (invited_by) REFERENCES users(id),
            INDEX idx_token (token),
            INDEX idx_status (status),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS invitations");
    }
}

return (new Migration_014_create_invitations())->up();
