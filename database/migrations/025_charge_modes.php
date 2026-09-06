<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * حالت‌های محاسبه شارژ ماهیانه:
 *   fixed        شارژ ثابت برای همه واحدها
 *   per_person   بر اساس تعداد نفرات ساکن هر واحد
 *   custom       مبلغ دلخواه به‌ازای هر واحد
 *
 * برای پشتیبانی از این حالت‌ها لازم است:
 *   • buildings: نوع شارژ و نرخ هر نفر
 *   • units: تعداد نفرات ساکن + شارژ اختصاصی واحد
 */
class Migration_025_charge_modes
{
    public function up(): void
    {
        $db = Database::getConnection();

        $buildingColumns = [
            // fixed | per_person | custom
            "ADD COLUMN charge_mode VARCHAR(20) NOT NULL DEFAULT 'fixed'",
            // نرخ شارژ به‌ازای هر نفر (وقتی charge_mode = per_person)
            "ADD COLUMN charge_per_person DECIMAL(15,2) NOT NULL DEFAULT 0",
        ];
        foreach ($buildingColumns as $col) {
            try {
                $db->exec("ALTER TABLE buildings {$col}");
            } catch (Throwable $e) {
                error_log('[025] buildings skipped (may exist): ' . $col);
            }
        }

        $unitColumns = [
            // تعداد نفرات ساکن واحد (مبنای شارژ نفری)
            "ADD COLUMN residents_count INT NOT NULL DEFAULT 0",
            // شارژ دلخواه این واحد (وقتی charge_mode = custom)
            "ADD COLUMN custom_charge DECIMAL(15,2) NULL DEFAULT NULL",
        ];
        foreach ($unitColumns as $col) {
            try {
                $db->exec("ALTER TABLE units {$col}");
            } catch (Throwable $e) {
                error_log('[025] units skipped (may exist): ' . $col);
            }
        }
    }

    public function down(): void
    {
    }
}

return (new Migration_025_charge_modes())->up();
