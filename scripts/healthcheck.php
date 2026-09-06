<?php

/**
 * بررسی سلامت و آمادگی استقرار.
 *
 *   php scripts/healthcheck.php        بررسی کامل (شامل اتصال به پایگاه‌داده)
 *   php scripts/healthcheck.php --ci   فقط بررسی‌های ایستا (بدون پایگاه‌داده)
 *
 * کد خروجی: ۰ اگر همه‌چیز درست باشد، ۱ اگر مشکل بحرانی وجود داشته باشد.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\Database;

$ci = in_array('--ci', array_slice($argv, 1), true);

$problems = [];
$warnings = [];
$ok = [];

function line(string $sym, string $color, string $text): void
{
    echo "  \033[{$color}m{$sym}\033[0m {$text}\n";
}

echo "\n\033[1;36m▍ بررسی سلامت سامانه مدیریت ساختمان\033[0m\n\n";

// ------------------------------------------------------------ نسخه PHP
echo "\033[1mمحیط اجرا\033[0m\n";
if (PHP_VERSION_ID < 80300) {
    $problems[] = 'نسخه PHP باید حداقل ۸.۳ باشد (نسخه فعلی: ' . PHP_VERSION . ').';
    line('✘', '31', 'نسخه PHP: ' . PHP_VERSION);
} else {
    line('✔', '32', 'نسخه PHP: ' . PHP_VERSION);
}

// افزونه‌های لازم
foreach (['pdo', 'json', 'mbstring'] as $ext) {
    if (extension_loaded($ext)) {
        line('✔', '32', "افزونه {$ext}");
    } else {
        $problems[] = "افزونه PHP «{$ext}» نصب نیست.";
        line('✘', '31', "افزونه {$ext}");
    }
}
foreach (['curl' => 'تماس با API', 'soap' => 'ارسال پیامک'] as $ext => $why) {
    if (extension_loaded($ext)) {
        line('✔', '32', "افزونه {$ext}");
    } else {
        $warnings[] = "افزونه «{$ext}» نصب نیست؛ {$why} کار نخواهد کرد.";
        line('!', '33', "افزونه {$ext} — {$why} غیرفعال");
    }
}

// ------------------------------------------------------------ تنظیمات
echo "\n\033[1mتنظیمات\033[0m\n";
$env = AppConfig::environment();
line('✔', '32', "محیط: {$env}");

if (AppConfig::isProduction()) {
    foreach (AppConfig::validateForProduction() as $p) {
        $problems[] = $p;
        line('✘', '31', $p);
    }
    if (!AppConfig::validateForProduction()) {
        line('✔', '32', 'همه متغیرهای حیاتی تنظیم شده‌اند');
    }
} else {
    line('!', '33', "محیط تولید نیست؛ بررسی اسرار انجام نشد");
    $warnings[] = 'APP_ENV روی production نیست.';
}

// فایل .env نباید در گیت باشد
if (is_file(dirname(__DIR__) . '/.env')) {
    line('✔', '32', 'فایل .env موجود است');
} else {
    $warnings[] = 'فایل .env وجود ندارد؛ از .env.example کپی بگیرید.';
    line('!', '33', 'فایل .env وجود ندارد');
}

// ------------------------------------------------------------ نوشتن روی دیسک
echo "\n\033[1mدسترسی نوشتن\033[0m\n";
foreach (['storage', 'storage/logs'] as $rel) {
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        line('✔', '32', "{$rel} قابل نوشتن است");
    } else {
        $problems[] = "پوشه «{$rel}» قابل نوشتن نیست.";
        line('✘', '31', "{$rel} قابل نوشتن نیست");
    }
}

// ------------------------------------------------------------ پایگاه‌داده
if (!$ci) {
    echo "\n\033[1mپایگاه‌داده\033[0m\n";
    try {
        $db = Database::getConnection();
        line('✔', '32', 'اتصال برقرار شد');

        $required = ['users', 'buildings', 'units', 'building_members', 'costs', 'otp_codes', 'rate_limits'];
        $missing = [];
        foreach ($required as $t) {
            try {
                $db->query("SELECT 1 FROM {$t} LIMIT 1");
            } catch (Throwable $e) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            $problems[] = 'جدول‌های زیر وجود ندارند (مایگریشن اجرا نشده؟): ' . implode('، ', $missing);
            line('✘', '31', 'جدول‌های ناموجود: ' . implode('، ', $missing));
        } else {
            line('✔', '32', 'همه جدول‌های حیاتی موجودند');
        }

        // آیا مایگریشن اعمال‌نشده مانده؟
        try {
            $applied = $db->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
            $total = count(glob(dirname(__DIR__) . '/database/migrations/*.php') ?: []);
            if ((int) $applied < $total) {
                $warnings[] = "از {$total} مایگریشن، {$applied} اعمال شده است. «php scripts/migrator.php» را اجرا کنید.";
                line('!', '33', "مایگریشن‌های اعمال‌شده: {$applied} از {$total}");
            } else {
                line('✔', '32', "همه {$total} مایگریشن اعمال شده است");
            }
        } catch (Throwable $e) {
            $warnings[] = 'جدول سابقه مایگریشن‌ها وجود ندارد؛ «php scripts/migrator.php» را اجرا کنید.';
            line('!', '33', 'جدول migrations وجود ندارد');
        }
    } catch (Throwable $e) {
        $problems[] = 'اتصال به پایگاه‌داده برقرار نشد: ' . $e->getMessage();
        line('✘', '31', 'اتصال برقرار نشد: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------ خلاصه
echo "\n" . str_repeat('─', 62) . "\n";

foreach ($warnings as $w) {
    echo "  \033[33m!\033[0m {$w}\n";
}

if (!$problems) {
    echo "\n\033[1;32m✔ سامانه سالم است" . ($warnings ? ' (با ' . count($warnings) . ' هشدار)' : '') . "\033[0m\n\n";
    exit(0);
}

echo "\n\033[1;31m✘ " . count($problems) . " مشکل بحرانی پیدا شد:\033[0m\n";
foreach ($problems as $p) {
    echo "  \033[31m•\033[0m {$p}\n";
}
echo "\n";
exit(1);
