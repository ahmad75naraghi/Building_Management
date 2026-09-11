<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\AppConfig;
use PDO;

final class Database
{
    private static ?PDO $connection = null;

    /** یک‌بار در هر فرایند اجرا می‌شود */
    private static bool $schemaEnsured = false;

    /**
     * ستون‌های اختیاری/جدید جدول‌ها که در مایگریشن‌های متأخر (۰۲۹ تا ۰۳۳)
     * اضافه شده‌اند. اگر دیتابیس قدیمی‌تر از این مایگریشن‌ها باشد، اولین
     * اتصال آن‌ها را به‌صورت خودکار می‌سازد تا عملیات مالی با خطای
     * «ستون پیدا نشد» شکست نخورد. (تعریف‌ها برای مای‌اسکیوال/ماریادی‌بی است؛
     * در اس‌کیولایت تست‌ها همهٔ ستون‌ها از قبل موجودند.)
     *
     * @var array<string, array<string, string>>
     */
    private const OPTIONAL_COLUMNS = [
        'units' => [
            'parking_no' => 'VARCHAR(255) DEFAULT NULL',
            'storage_no' => 'VARCHAR(255) DEFAULT NULL',
        ],
        'costs' => [
            'target_unit_ids' => 'JSON DEFAULT NULL',
            'issued_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'recurring_start_date' => 'DATE NULL',
            'recurring_end_date' => 'DATE NULL',
            'recurring_next_date' => 'DATE NULL',
            'parent_cost_id' => 'INT NULL',
        ],
        'cost_payments' => [
            'amount' => 'DECIMAL(15,2) DEFAULT NULL',
            'share_amount' => 'DECIMAL(15,2) DEFAULT NULL',
            'unit_id' => 'INT DEFAULT NULL',
            'reject_reason' => 'VARCHAR(500) DEFAULT NULL',
            'payment_date' => 'DATE NULL',
        ],
        'documents' => [
            'stored_name' => 'VARCHAR(120) DEFAULT NULL',
            'mime_type' => 'VARCHAR(100) DEFAULT NULL',
            'file_size' => 'BIGINT DEFAULT NULL',
            'is_visible_to_members' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
    ];

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = AppConfig::getDatabaseConfig();
            self::$connection = new PDO(
                $config['dsn'],
                $config['username'],
                $config['password'],
                $config['options']
            );
            self::ensureOptionalSchema(self::$connection);
        }
        return self::$connection;
    }

    /**
     * خودترمیمی طرح‌واره: ستون‌های شناخته‌شدهٔ غایب را اضافه می‌کند.
     * هر خطا (جدول ناموجود، دسترسی و…) بی‌صدا نادیده گرفته می‌شود تا اتصال
     * هرگز به‌خاطر خودترمیمی شکست نخورد.
     */
    private static function ensureOptionalSchema(PDO $db): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;

        try {
            $isSqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            foreach (self::OPTIONAL_COLUMNS as $table => $columns) {
                try {
                    if ($isSqlite) {
                        $names = [];
                        foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
                            $names[] = (string) ($col['name'] ?? '');
                        }
                    } else {
                        $names = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                        $names = array_map('strval', $names);
                    }
                } catch (\Throwable $e) {
                    continue; // جدول هنوز ساخته نشده — مایگریشن مسئول آن است
                }

                foreach ($columns as $column => $definition) {
                    if (in_array($column, $names, true)) {
                        continue;
                    }
                    $def = (!$isSqlite || !str_starts_with($definition, 'JSON')) ? $definition : 'TEXT DEFAULT NULL';
                    try {
                        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$def}");
                        Logger::info('schema', 'ستون غایب به‌صورت خودکار افزوده شد', [
                            'table' => $table,
                            'column' => $column,
                        ]);
                    } catch (\Throwable $e) {
                        Logger::warning('schema', 'افزودن خودکار ستون ناموفق بود', [
                            'table' => $table,
                            'column' => $column,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // خودترمیمی هرگز نباید اتصال را خراب کند
        }
    }

    /**
     * آماده‌سازی «درج بدون خطا در تکرار» به‌صورت قابل‌حمل:
     * روی MySQL «INSERT IGNORE» و روی SQLite (محیط تست) «INSERT OR IGNORE».
     */
    public static function prepareInsertIgnore(PDO $db, string $sql): \PDOStatement
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = (string) preg_replace('/INSERT\s+IGNORE/i', 'INSERT OR IGNORE', $sql);
        }
        return $db->prepare($sql);
    }

    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    public static function rollBack(): bool
    {
        return self::getConnection()->rollBack();
    }
}
