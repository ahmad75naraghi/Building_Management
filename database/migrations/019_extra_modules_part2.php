<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_019_extra_modules_votes_visitors_documents
{
    public function up(): void
    {
        $db = Database::getConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            start_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            end_date TIMESTAMP DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'active',
            created_by INT NOT NULL,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS vote_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vote_id INT NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS vote_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vote_id INT NOT NULL,
            user_id INT NOT NULL,
            option_id INT NOT NULL,
            voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (option_id) REFERENCES vote_options(id) ON DELETE CASCADE,
            UNIQUE KEY unique_vote_user (vote_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS visitors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            user_id INT NOT NULL,
            visitor_name VARCHAR(255) NOT NULL,
            visitor_car_plate VARCHAR(50) DEFAULT NULL,
            visit_date DATE DEFAULT NULL,
            entry_time TIME DEFAULT NULL,
            exit_time TIME DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'entered',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            building_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            document_type VARCHAR(50) DEFAULT 'general',
            uploaded_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec("DROP TABLE IF EXISTS vote_results");
        $db->exec("DROP TABLE IF EXISTS vote_options");
        $db->exec("DROP TABLE IF EXISTS votes");
        $db->exec("DROP TABLE IF EXISTS visitors");
        $db->exec("DROP TABLE IF EXISTS documents");
    }
}

return (new Migration_019_extra_modules_votes_visitors_documents())->up();
