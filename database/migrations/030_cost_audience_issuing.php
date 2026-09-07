<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * صدور هزینه برای مخاطبان مشخص (جدا از شارژ ماهیانه).
 *
 * - costs.target_unit_ids: وقتی مخاطب «واحدهای خاص» باشد، شناسه واحدها اینجا ذخیره می‌شود
 * - costs.issued_at: زمان صدور هزینه برای مخاطبان (ایجاد ردیف پرداخت + اعلان)
 * - cost_payments.share_amount: سهم درخواست‌شده از هر پرداخت‌کننده هنگام صدور
 *
 * این migration ایدمپوتنت است (دوبار اجرا هم خطا نمی‌دهد).
 */
class Migration_030_cost_audience_issuing
{
    public function up(): void
    {
        $db = Database::getConnection();

        $columns = [
            'ALTER TABLE costs ADD COLUMN target_unit_ids JSON DEFAULT NULL' => ['costs', 'target_unit_ids'],
            'ALTER TABLE costs ADD COLUMN issued_at TIMESTAMP NULL DEFAULT NULL' => ['costs', 'issued_at'],
            'ALTER TABLE cost_payments ADD COLUMN share_amount DECIMAL(15,2) DEFAULT NULL' => ['cost_payments', 'share_amount'],
        ];

        foreach ($columns as $sql => [$table, $column]) {
            // MySQL 8 از ADD COLUMN IF NOT EXISTS پشتیبانی نمی‌کند؛ اول وجود ستون بررسی می‌شود
            $stmt = $db->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $db->exec($sql);
            }
        }
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec("ALTER TABLE costs DROP COLUMN IF EXISTS target_unit_ids");
        $db->exec("ALTER TABLE costs DROP COLUMN IF EXISTS issued_at");
        $db->exec("ALTER TABLE cost_payments DROP COLUMN IF EXISTS share_amount");
    }
}

return (new Migration_030_cost_audience_issuing())->up();
