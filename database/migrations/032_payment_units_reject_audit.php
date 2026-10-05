<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * حسابداری واحد + چرخه تأیید/رد پرداخت + لاگ ممیزی اقدامات.
 *
 * - cost_payments.unit_id: هر ردیف پرداخت به «واحد» منتسب می‌شود تا ماندهٔ
 *   بدهکار/طلبکار هر واحد دقیق محاسبه شود (ردیف‌های قدیمی NULL می‌مانند).
 * - cost_payments.reject_reason: دلیل رد پرداخت توسط مدیر.
 * - audit_logs: ثبت سبک و ناهمسداسازِ هر اقدام هر کاربر (یک INSERT بدون SELECT؛
 *   شکست لاگ هرگز عملیات اصلی را خراب نمی‌کند).
 *
 * این migration ایدمپوتنت است.
 */
class Migration_032_payment_units_reject_audit
{
    public function up(): void
    {
        $db = Database::getConnection();

        $columns = [
            'ALTER TABLE cost_payments ADD COLUMN unit_id INT DEFAULT NULL' => ['cost_payments', 'unit_id'],
            'ALTER TABLE cost_payments ADD COLUMN reject_reason VARCHAR(500) DEFAULT NULL' => ['cost_payments', 'reject_reason'],
        ];

        foreach ($columns as $sql => [$table, $column]) {
            $check = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->execute([$table, $column]);
            if ((int) $check->fetchColumn() === 0) {
                $db->exec($sql);
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            action VARCHAR(60) NOT NULL,
            entity_type VARCHAR(40) DEFAULT NULL,
            entity_id INT DEFAULT NULL,
            building_id INT DEFAULT NULL,
            meta TEXT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_building (building_id, created_at),
            INDEX idx_audit_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec('DROP TABLE IF EXISTS audit_logs');
        $db->exec('ALTER TABLE cost_payments
            DROP COLUMN IF EXISTS unit_id,
            DROP COLUMN IF EXISTS reject_reason');
    }
}

return (new Migration_032_payment_units_reject_audit())->up();
