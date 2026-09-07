<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * افزودن ستون‌های «قطعه پارکینگ» و «قطعه انباری» به جدول واحدها.
 *
 * هر واحد می‌تواند قطعه پارکینگ و قطعه انباری اختصاصی خودش را داشته باشد
 * (مثلاً «12» یا «P-3» — و برای چند قطعه، جداشده با ویرگول مثل «12, 13»).
 *
 * این ستون‌ها اختیاری هستند و فقط جنبه اطلاعاتی/نمایشی دارند.
 * این migration ایدمپوتنت است (دوبار اجرا هم خطا نمی‌دهد).
 */
class Migration_029_add_unit_parking_storage
{
    public function up(): void
    {
        $db = Database::getConnection();

        foreach ([
            'parking_no' => "ALTER TABLE units ADD COLUMN parking_no VARCHAR(255) DEFAULT NULL COMMENT 'شماره قطعه پارکینگ اختصاصی واحد'",
            'storage_no' => "ALTER TABLE units ADD COLUMN storage_no VARCHAR(255) DEFAULT NULL COMMENT 'شماره قطعه انباری اختصاصی واحد'",
        ] as $column => $sql) {
            // MySQL 8 از ADD COLUMN IF NOT EXISTS پشتیبانی نمی‌کند؛ اول وجود ستون بررسی می‌شود
            $stmt = $db->prepare("SHOW COLUMNS FROM units LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $db->exec($sql);
            }
        }
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec("ALTER TABLE units DROP COLUMN IF EXISTS parking_no");
        $db->exec("ALTER TABLE units DROP COLUMN IF EXISTS storage_no");
    }
}

return (new Migration_029_add_unit_parking_storage())->up();
