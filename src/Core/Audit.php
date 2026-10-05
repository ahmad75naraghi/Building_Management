<?php

declare(strict_types=1);

namespace App\Core;

use PDOStatement;

/**
 * لاگ ممیزی اقدامات کاربران (Audit Log).
 *
 * ملاحظات پرفورمنس:
 *  - هر رویداد فقط «یک INSERT» با statement آمادهٔ ثابت است؛ هیچ SELECT یا
 *    join اضافه‌ای در مسیر درخواست اجرا نمی‌شود.
 *  - اگر جدول audit_logs موجود نباشد (یا خطایی رخ دهد)، برای بقای همان
 *    درخواست غیرفعال می‌شود تا هزینهٔ تکرار خطا صفر باشد؛ عملیات اصلی
 *    هرگز به‌خاطر لاگ نمی‌شکند.
 */
final class Audit
{
    private static ?PDOStatement $stmt = null;
    private static bool $unavailable = false;

    /**
     * ثبت یک اقدام. شکست این متد هیچ‌وقت استثنا پرتاب نمی‌کند.
     *
     * @param array<string, mixed> $meta داده توصیفی کوچک (بدون اطلاعات حساس)
     */
    public static function log(
        int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $buildingId = null,
        array $meta = []
    ): void {
        if (self::$unavailable) {
            return;
        }
        try {
            $db = Database::getConnection();
            if (self::$stmt === null) {
                self::$stmt = $db->prepare(
                    'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, building_id, meta, ip)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
            }
            self::$stmt->execute([
                $userId > 0 ? $userId : null,
                $action,
                $entityType,
                $entityId,
                $buildingId,
                !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                self::clientIp(),
            ]);
        } catch (\Throwable $e) {
            // جدول ممیزی در دسترس نیست → برای این درخواست کلاً غیرفعال شود
            self::$unavailable = true;
            self::$stmt = null;
            try {
                Logger::debug('audit', 'ثبت لاگ ممیزی در دسترس نیست', [
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // حتی لاگِ خطا هم نباید چیزی را بشکند
            }
        }
    }

    private static function clientIp(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
