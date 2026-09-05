<?php

declare(strict_types=1);

use App\Core\Database;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * افزودن ستون unit_id به جدول invitations
 * تا هنگام دعوت «مالک» یا «مستاجر» بتوان واحد هدف را مشخص کرد.
 * هنگام پذیرش دعوت، کاربر به‌صورت خودکار به عنوان مالک/مستاجر همان واحد ثبت می‌شود.
 *
 * ایدمپوتنت است.
 */
class Migration_022_add_invitation_unit
{
    public function up(): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("SHOW COLUMNS FROM invitations LIKE 'unit_id'");
        $stmt->execute();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $db->exec("ALTER TABLE invitations ADD COLUMN unit_id INT DEFAULT NULL AFTER role");
        }

        // اطمینان از وجود کلید خارجی (بدون بررسی مجدد FK — خطای duplicate را نادیده می‌گیریم)
        try {
            $db->exec(
                "ALTER TABLE invitations
                 ADD CONSTRAINT fk_invitations_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL"
            );
        } catch (\Throwable $e) {
            // کلید خارجی از قبل وجود دارد — نادیده گرفته می‌شود
        }
    }

    public function down(): void
    {
        $db = Database::getConnection();
        try {
            $db->exec("ALTER TABLE invitations DROP FOREIGN KEY fk_invitations_unit");
        } catch (\Throwable $e) {
            // کلید وجود ندارد
        }
        $db->exec("ALTER TABLE invitations DROP COLUMN IF EXISTS unit_id");
    }
}

return (new Migration_022_add_invitation_unit())->up();
