<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_016_create_ticket_comments
{
    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS ticket_comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NOT NULL,
            comment TEXT NOT NULL,
            is_internal TINYINT(1) DEFAULT 0,
            attachment_path VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            INDEX idx_ticket (ticket_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        Database::getConnection()->exec($sql);
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS ticket_comments");
    }
}

return (new Migration_016_create_ticket_comments())->up();
