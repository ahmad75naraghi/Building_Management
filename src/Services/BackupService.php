<?php
/**
 * پشتیبان‌گیری از پایگاه‌داده — دامپ کامل SQL با خودِ PDO (بدون نیاز به mysqldump).
 *
 * فایل‌ها در پوشهٔ `backups/` ذخیره می‌شوند که با .htaccess از دسترسی وب مستقیم
 * محافظت شده است. دسترسی به ساخت/دانلود فقط برای شماره‌های تعیین‌شده در متغیر
 * محیطی `BACKUP_ADMIN_PHONES` (جداشده با کاما) باز است.
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Core\Audit;
use App\Core\Database;
use App\Models\User;
use App\Utilities\PhoneHelper;

final class BackupService
{
    /** پوشهٔ ذخیرهٔ پشتیبان‌ها */
    public function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    /** آیا کاربر اجازهٔ کار با پشتیبان‌ها را دارد؟ (شماره‌های مجاز از تنظیمات محیطی) */
    public function isAdmin(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $allowed = (string) AppConfig::env('BACKUP_ADMIN_PHONES', '');
        if (trim($allowed) === '') {
            return false;
        }
        $phone = PhoneHelper::normalize((string) ($user->phone ?? ''));
        foreach (array_filter(array_map('trim', explode(',', $allowed))) as $candidate) {
            if (PhoneHelper::normalize($candidate) === $phone) {
                return true;
            }
        }
        return false;
    }

    /** ساخت پشتیبان کامل؛ مسیر فایل ساخته‌شده را برمی‌گرداند */
    public function createBackup(int $userId = 0): string
    {
        $db = Database::getConnection();
        $driver = (string) $db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $tables = $this->listTables($db, $driver);
        $ts = date('Ymd-His');
        $plainName = 'backup-' . $ts . '.sql';

        $sql = "-- پشتیبان کامل سامانهٔ مدیریت ساختمان — {$ts}\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $sql .= $this->dumpTable($db, $driver, $table);
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $dir = $this->dir();
        $useGzip = function_exists('gzencode');
        $fileName = $useGzip ? $plainName . '.gz' : $plainName;
        $path = $dir . '/' . $fileName;
        $written = $useGzip
            ? file_put_contents($path, gzencode($sql, 6))
            : file_put_contents($path, $sql);
        if ($written === false) {
            throw new \RuntimeException('ذخیرهٔ فایل پشتیبان ممکن نشد.');
        }

        Audit::log($userId, 'backup.create', 'backup', null, null, [
            'file' => $fileName,
            'tables' => count($tables),
            'bytes' => $written,
        ]);

        return $path;
    }

    /** فهرست پشتیبان‌ها (جدیدترین اول) */
    public function listBackups(): array
    {
        $files = glob($this->dir() . '/backup-*.sql*') ?: [];
        $items = [];
        foreach ($files as $file) {
            $items[] = [
                'name' => basename($file),
                'size' => (int) @filesize($file),
                'mtime' => (int) @filemtime($file),
            ];
        }
        usort($items, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    /** حذف یک پشتیبان با اعتبارسنجی نام فایل */
    public function deleteBackup(string $name, int $userId = 0): bool
    {
        if (!$this->isSafeName($name)) {
            return false;
        }
        $path = $this->dir() . '/' . $name;
        if (!is_file($path)) {
            return false;
        }
        $deleted = @unlink($path);
        if ($deleted) {
            Audit::log($userId, 'backup.delete', 'backup', null, null, ['file' => $name]);
        }
        return $deleted;
    }

    /** نگهداری تنها تعداد مشخصی پشتیبان اخیر؛ فایل‌های قدیمی‌تر حذف می‌شوند */
    public function prune(int $keep = 7): int
    {
        $items = $this->listBackups();
        $removed = 0;
        foreach (array_slice($items, max(0, $keep)) as $item) {
            if ($this->deleteBackup((string) $item['name'])) {
                $removed++;
            }
        }
        return $removed;
    }

    /** مسیر کامل فایل با اعتبارسنجی نام (برای دانلود) */
    public function pathFor(string $name): ?string
    {
        if (!$this->isSafeName($name)) {
            return null;
        }
        $path = $this->dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    private function isSafeName(string $name): bool
    {
        return (bool) preg_match('/^backup-\d{8}-\d{6}\.sql(\.gz)?$/', $name);
    }

    /** @return list<string> */
    private function listTables(\PDO $db, string $driver): array
    {
        if ($driver === 'sqlite') {
            $rows = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $rows = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        }
        return array_values(array_filter(array_map('strval', $rows ?: [])));
    }

    private function dumpTable(\PDO $db, string $driver, string $table): string
    {
        $quoted = $driver === 'sqlite'
            ? '"' . str_replace('"', '""', $table) . '"'
            : '`' . str_replace('`', '', $table) . '`';
        $sql = "-- جدول {$table}\n";

        if ($driver === 'sqlite') {
            $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            $create = $stmt->fetchColumn();
            $sql .= ($create !== false ? (string) $create : '') . ";\n";
        } else {
            $stmt = $db->prepare('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $create = $row['Create Table'] ?? ($row ? reset($row) : '');
            if (is_string($create) && $create !== '') {
                $sql .= 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $create . ";\n";
            }
        }

        // درج داده‌ها به‌صورت دسته‌ای
        $stmt = $db->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
        $columns = null;
        $batch = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = array_map(
                    static fn($c) => '`' . str_replace('`', '', (string) $c) . '`',
                    array_keys($row)
                );
            }
            $values = array_map(static fn($v) => $v === null ? 'NULL' : $db->quote((string) $v), array_values($row));
            $batch[] = '(' . implode(',', $values) . ')';
            if (count($batch) >= 300) {
                $sql .= $this->insertBlock($quoted, $columns, $batch);
                $batch = [];
            }
        }
        if ($batch !== [] && $columns !== null) {
            $sql .= $this->insertBlock($quoted, $columns, $batch);
        }
        if ($columns === null) {
            $sql .= "-- (خالی)\n";
        }

        return $sql . "\n";
    }

    /** @param list<string> $columns @param list<string> $rows */
    private function insertBlock(string $quotedTable, array $columns, array $rows): string
    {
        return 'INSERT INTO ' . $quotedTable . ' (' . implode(',', $columns) . ") VALUES\n"
            . implode(",\n", $rows) . ";\n";
    }
}
