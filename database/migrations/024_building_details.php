<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_024_building_details
{
    public function up(): void
    {
        $db = Database::getConnection();
        // تعداد واحد / طبقه / بلوک / عکس پیش‌فرض / پارکینگ / شارژ ثابت ماهیانه
        $columns = [
            "ADD COLUMN total_units INT NULL DEFAULT NULL",
            "ADD COLUMN total_floors INT NULL DEFAULT NULL",
            "ADD COLUMN has_blocks TINYINT(1) NOT NULL DEFAULT 1",
            "ADD COLUMN default_image VARCHAR(50) NULL DEFAULT 'b1'",
            "ADD COLUMN parking_spots INT NOT NULL DEFAULT 0",
            "ADD COLUMN monthly_charge DECIMAL(15,2) NOT NULL DEFAULT 0",
            "ADD COLUMN monthly_charge_enabled TINYINT(1) NOT NULL DEFAULT 0",
        ];
        foreach ($columns as $col) {
            try {
                $db->exec("ALTER TABLE buildings {$col}");
            } catch (Throwable $e) {
                error_log('[024] skipped (may exist): ' . $col);
            }
        }

        // نام دعوت‌شونده (برای دعوت با شماره تماس)
        try {
            $db->exec("ALTER TABLE invitations ADD COLUMN invited_name VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            error_log('[024] invited_name skipped (may exist).');
        }
    }

    public function down(): void
    {
    }
}

return (new Migration_024_building_details())->up();
