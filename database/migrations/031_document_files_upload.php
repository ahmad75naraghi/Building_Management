<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * اسناد ساختمان با آپلود فایل واقعی.
 *
 * - documents.stored_name: نام تصادفی فایل روی دیسک (بدون این فیلد، سند «لینک خارجی» است)
 * - documents.mime_type / file_size: متادیتای فایل برای نمایش و دانلود امن
 * - documents.is_visible_to_members: سند برای اعضای ساختمان قابل رویت هست یا فقط مدیران؟
 * - documents.updated_at: زمان آخرین ویرایش
 *
 * امنیت: فایل‌ها با نام تصادفی ذخیره می‌شوند و فقط از مسیر
 * معتبرسازی‌شدهٔ document_download.php قابل دریافت هستند.
 *
 * این migration ایدمپوتنت است (دوبار اجرا هم خطا نمی‌دهد).
 */
class Migration_031_document_files_upload
{
    public function up(): void
    {
        $db = Database::getConnection();

        $columns = [
            'ALTER TABLE documents ADD COLUMN stored_name VARCHAR(120) DEFAULT NULL' => ['documents', 'stored_name'],
            'ALTER TABLE documents ADD COLUMN mime_type VARCHAR(100) DEFAULT NULL' => ['documents', 'mime_type'],
            'ALTER TABLE documents ADD COLUMN file_size BIGINT DEFAULT NULL' => ['documents', 'file_size'],
            'ALTER TABLE documents ADD COLUMN is_visible_to_members TINYINT(1) NOT NULL DEFAULT 1' => ['documents', 'is_visible_to_members'],
            'ALTER TABLE documents ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL' => ['documents', 'updated_at'],
        ];

        foreach ($columns as $sql => [$table, $column]) {
            // بررسی وجود ستون از information_schema — با آماده‌سازی بومی مایگریشن‌ها
            // (بدون شبیه‌سازی) سازگار است؛ «SHOW COLUMNS ... LIKE ?» در این حالت خطای 1064 می‌دهد.
            $check = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->execute([$table, $column]);
            if ((int) $check->fetchColumn() === 0) {
                $db->exec($sql);
            }
        }
    }

    public function down(): void
    {
        $db = Database::getConnection();
        $db->exec('ALTER TABLE documents
            DROP COLUMN IF EXISTS stored_name,
            DROP COLUMN IF EXISTS mime_type,
            DROP COLUMN IF EXISTS file_size,
            DROP COLUMN IF EXISTS is_visible_to_members,
            DROP COLUMN IF EXISTS updated_at');
    }
}

return (new Migration_031_document_files_upload())->up();
