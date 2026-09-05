<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * افزودن ستون owner_resident به جدول units
 * برای تفکیک سناریوها:
 *   - owner_resident = 1 و tenant = NULL  → مالک ساکن است
 *   - tenant != NULL                     → مستاجر ساکن است (مالک غیرساکن)
 *   - owner_resident = 0 و tenant = NULL  → واحد خالی (مالک دارد ولی ساکن نیست)
 *
 * این migration ایدمپوتنت است (دوبار اجرا هم خطا نمی‌دهد).
 */
class Migration_021_add_unit_owner_resident
{
    public function up(): void
    {
        $db = Database::getConnection();

        // بررسی وجود ستون (MySQL 8 از ADD COLUMN IF NOT EXISTS پشتیبانی نمی‌کند)
        $stmt = $db->prepare("SHOW COLUMNS FROM units LIKE 'owner_resident'");
        $stmt->execute();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $db->exec(
                "ALTER TABLE units
                 ADD COLUMN owner_resident TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT 'آیا مالک در واحد ساکن است (1) یا نه (0)'"
            );
        }
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec("ALTER TABLE units DROP COLUMN IF EXISTS owner_resident");
    }
}

return (new Migration_021_add_unit_owner_resident())->up();
