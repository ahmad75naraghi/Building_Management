<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * مایگریشن ۰۳۳ — هزینه‌های دوره‌ای با تناوب دلخواه
 *
 *  - هزینه می‌تواند «قالب دوره‌ای» باشد: تناوب (هفتگی/ماهانه/…) + تاریخ شروع/پایان
 *  - نمونه‌های صادرشده با parent_cost_id به قالب وصل می‌شوند
 */
class Migration_033_recurring_costs
{
    public function up(): void
    {
        $db = Database::getConnection();

        $columns = [
            "ADD COLUMN recurring_start_date DATE NULL",
            "ADD COLUMN recurring_end_date DATE NULL",
            "ADD COLUMN recurring_next_date DATE NULL",
            "ADD COLUMN parent_cost_id INT NULL",
        ];
        foreach ($columns as $col) {
            try {
                $db->exec("ALTER TABLE costs {$col}");
            } catch (Throwable $e) {
                error_log('[033] costs skipped (may exist): ' . $col);
            }
        }

        try {
            $db->exec("CREATE INDEX idx_costs_recurring_next ON costs (recurring_next_date)");
        } catch (Throwable $e) {
            error_log('[033] index skipped (may exist).');
        }
    }

    public function down(): void
    {
    }
}

return (new Migration_033_recurring_costs())->up();
