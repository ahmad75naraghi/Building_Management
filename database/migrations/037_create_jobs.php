<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_037_create_jobs
{
    public function up(): void
    {
        $db = Database::getConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(50) NOT NULL,
            payload TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 3,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_error TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_jobs_queue (status, available_at),
            INDEX idx_jobs_type (job_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        Database::getConnection()->exec("DROP TABLE IF EXISTS jobs");
    }
}

return (new Migration_037_create_jobs())->up();
