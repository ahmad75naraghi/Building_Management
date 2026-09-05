<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

class Migration_023_phone_auth
{
    public function up(): void
    {
        $db = Database::getConnection();

        // ایمیل اختیاری می‌شود (ورود با شماره موبایل است)
        try {
            $db->exec("ALTER TABLE users MODIFY email VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            error_log('[023] email alter skipped: ' . $e->getMessage());
        }

        // شماره موبایل یکتا می‌شود (نام‌کاربری هر کاربر)
        try {
            $db->exec("ALTER TABLE users MODIFY phone VARCHAR(20) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            error_log('[023] phone alter skipped: ' . $e->getMessage());
        }
        try {
            $db->exec("ALTER TABLE users ADD UNIQUE INDEX idx_phone_unique (phone)");
        } catch (Throwable $e) {
            error_log('[023] phone unique skipped (may exist): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        $db = Database::getConnection();
        try {
            $db->exec("ALTER TABLE users DROP INDEX idx_phone_unique");
        } catch (Throwable $e) {
        }
    }
}

return (new Migration_023_phone_auth())->up();
