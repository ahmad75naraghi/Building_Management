<?php

/**
 * اجراکننده مایگریشن‌ها.
 *
 *   php scripts/migrator.php          اجرای همه مایگریشن‌های اعمال‌نشده
 *   php scripts/migrator.php --force  اجرای دوباره همه (حتی اعمال‌شده‌ها)
 *   php scripts/migrator.php --status فقط نمایش وضعیت، بدون اجرا
 *
 * هر فایل مایگریشن هنگام require شدن خودش را اجرا می‌کند.
 * سابقه اجرا در جدول `migrations` نگه‌داری می‌شود تا مایگریشن‌ها دوباره اجرا نشوند.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;

// مایگریشن‌ها در database/migrations قرار دارند، نه کنار همین اسکریپت
$migrationsDir = dirname(__DIR__) . '/database/migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "پوشه مایگریشن‌ها پیدا نشد: {$migrationsDir}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.php') ?: [];
sort($files);

if (!$files) {
    fwrite(STDERR, "هیچ فایل مایگریشنی در {$migrationsDir} وجود ندارد.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$statusOnly = in_array('--status', $args, true);

$db = Database::getConnection();

// جدول سابقه: نگه‌داری اینکه هر مایگریشن کِی اجرا شده است
$db->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$applied = $db->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$applied = array_flip($applied);

$record = $db->prepare("INSERT INTO migrations (migration) VALUES (?)");

$ran = 0;
$skipped = 0;
$failed = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name]) && !$force) {
        $skipped++;
        if ($statusOnly) {
            echo "  [اعمال‌شده] {$name}\n";
        }
        continue;
    }

    if ($statusOnly) {
        echo "  [در انتظار] {$name}\n";
        continue;
    }

    echo "در حال اجرا: {$name} ... ";
    try {
        require $file;

        if (!isset($applied[$name])) {
            $record->execute([$name]);
        }
        $ran++;
        echo "انجام شد.\n";
    } catch (Throwable $e) {
        $failed++;
        echo "شکست خورد.\n";
        fwrite(STDERR, "  خطا در {$name}: " . $e->getMessage() . "\n");
        Logger::error('migrator', 'اجرای مایگریشن ناموفق بود', ['migration' => $name], $e);

        // ادامه ندادن بهتر است: مایگریشن‌های بعدی معمولاً به این یکی وابسته‌اند
        fwrite(STDERR, "اجرا متوقف شد تا از خرابی بیشتر جلوگیری شود.\n");
        break;
    }
}

if ($statusOnly) {
    echo "\nمجموع: " . count($files) . " مایگریشن، {$skipped} اعمال‌شده.\n";
    exit(0);
}

echo "\nپایان: {$ran} اجرا شد، {$skipped} رد شد (قبلاً اعمال شده)، {$failed} شکست خورد.\n";
exit($failed > 0 ? 1 : 0);
