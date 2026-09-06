<?php
/**
 * بازرسی سلامت کل مخزن.
 * این سوئیت به‌جای یک تابع، الگوهای خطاخیز را در همه فایل‌ها جست‌وجو می‌کند.
 */
declare(strict_types=1);

TestLog::suite('سلامت کدبیس');

$root = dirname(__DIR__, 3);

/** فهرست فایل‌های php پروژه (بدون vendor و تست‌ها) */
$php_files = static function (string $root): array {
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (!str_ends_with($path, '.php')) { continue; }
        $rel = substr($path, strlen($root) + 1);
        if (preg_match('#^(vendor|node_modules|tests|\.git)/#', $rel)) { continue; }
        $out[$rel] = (string) file_get_contents($path);
    }
    ksort($out);
    return $out;
};

$files = $php_files($root);
TestLog::assertTrue('فایل‌های پروژه پیدا شدند', count($files) > 50, count($files) . ' فایل');

// ------------------------------------------------------- ارجاع به صفحات حذف‌شده

TestLog::run('هیچ ارجاعی به login.php/register.php به‌عنوان مقصد نمانده', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (in_array($rel, ['login.php', 'register.php'], true)) { continue; }
        if (preg_match('/Location:\s*(login|register)\.php/i', $src)) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('بدون ریدایرکت قدیمی', [], $bad);
});

// ------------------------------------------------------- سازگاری موتور دیتابیس

TestLog::run('کوئری‌ها به توابع مخصوص MySQL گره نخورده‌اند', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (!str_starts_with($rel, 'src/')) { continue; }
        if (preg_match('/\b(DATE_ADD|DATE_SUB)\s*\(/i', $src)) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('بدون DATE_ADD/DATE_SUB', [], $bad);
});

// ------------------------------------------------------- امنیت خروجی

TestLog::run('صفحات، ورودی کاربر را مستقیم چاپ نمی‌کنند', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (str_starts_with($rel, 'src/') || str_starts_with($rel, 'scripts/')) { continue; }
        // <?= $_GET[...] یا echo $_POST[...] بدون فرار
        if (preg_match('/(?:echo|<\?=)\s*\$_(GET|POST|REQUEST)\b/', $src)) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('بدون چاپ مستقیم ورودی', [], $bad);
});

TestLog::run('کوئری‌ها با الحاق رشته ساخته نمی‌شوند', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (!str_starts_with($rel, 'src/')) { continue; }
        // الحاق متغیر داخل عبارت SQL
        if (preg_match('/(?:SELECT|INSERT INTO|UPDATE|DELETE FROM)[^;\n]{0,200}?"\s*\.\s*\$(?!table|columns|placeholders|set|cols|fields|sql|where|order)/i', $src)) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('بدون الحاق مشکوک در SQL', [], $bad);
});

// ------------------------------------------------------- لاگ‌گیری

TestLog::run('همه سرویس‌ها از لاگ‌گیر مرکزی استفاده می‌کنند', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (!str_starts_with($rel, 'src/')) { continue; }
        if ($rel === 'src/Core/Logger.php') { continue; }
        if (str_contains($src, 'error_log(')) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('بدون error_log مستقیم', [], $bad);
});

TestLog::run('بلوک‌های catch خالی وجود ندارد', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', $src)) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('هر catch حداقل یک کار می‌کند', [], $bad);
});

// ------------------------------------------------------- ثبات ساختار صفحات

TestLog::run('صفحات از هدر/فوتر مشترک استفاده می‌کنند', function () use ($files, $root) {
    $missing = [];
    foreach ($files as $rel => $src) {
        if (str_contains($rel, '/')) { continue; }              // فقط صفحات ریشه
        if (in_array($rel, ['login.php', 'register.php', 'logout.php'], true)) { continue; }
        if (!str_contains($src, '<!DOCTYPE') && !str_contains($src, 'header.php')) { continue; }
        // اگر خودش DOCTYPE دارد، باید صفحه مستقل اعلام‌شده باشد
        if (str_contains($src, '<!DOCTYPE') && !str_contains($src, 'header.php')
            && !preg_match('/standalone|auth-body|dashboard/i', $src)) {
            $missing[] = $rel;
        }
    }
    TestLog::assertSame('بدون صفحه ناسازگار', [], $missing);
});

TestLog::run('هر فرم POST یک form_action دارد', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (str_contains($rel, '/')) { continue; }
        if (!preg_match('/method=["\']post["\']/i', $src)) { continue; }
        if (!str_contains($src, 'form_action')) {
            $bad[] = $rel;
        }
    }
    TestLog::assertSame('همه فرم‌ها دارای form_action', [], $bad);
});

// ------------------------------------------------------- دارایی‌ها

TestLog::run('فایل‌های ایستا موجودند', function () use ($root) {
    foreach (['assets/css/style.css', 'assets/js/main.js', 'includes/header.php', 'includes/footer.php'] as $p) {
        TestLog::assertTrue("موجود: {$p}", is_file($root . '/' . $p));
    }
});

TestLog::run('CSS متوازن است (تعداد آکولادها)', function () use ($root) {
    $css = (string) file_get_contents($root . '/assets/css/style.css');
    $stripped = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
    TestLog::assertSame('آکولاد باز و بسته برابر', substr_count($stripped, '{'), substr_count($stripped, '}'));
});

TestLog::run('متغیرهای CSS استفاده‌شده تعریف شده‌اند', function () use ($root) {
    $css = (string) file_get_contents($root . '/assets/css/style.css');
    preg_match_all('/--[\w-]+\s*:/', $css, $def);
    $defined = array_map(fn($s) => rtrim(rtrim($s, ':'), " \t"), $def[0]);
    preg_match_all('/var\(\s*(--[\w-]+)/', $css, $use);
    $missing = [];
    foreach (array_unique($use[1]) as $v) {
        if (!in_array($v, $defined, true)) { $missing[] = $v; }
    }
    TestLog::assertSame('هیچ متغیر تعریف‌نشده‌ای استفاده نشده', [], $missing);
});

TestLog::run('ارجاع مودال‌ها به تعریفشان می‌خورد', function () use ($files) {
    $bad = [];
    foreach ($files as $rel => $src) {
        if (str_contains($rel, '/')) { continue; }
        preg_match_all('/data-modal-open=["\']([\w-]+)["\']/', $src, $opens);
        preg_match_all('/modal_(?:start|open_button)\(\s*[\'"]([\w-]+)[\'"]/', $src, $defs);
        foreach (array_unique($opens[1]) as $id) {
            if (!in_array($id, $defs[1], true)) {
                $bad[] = "{$rel}: {$id}";
            }
        }
    }
    TestLog::assertSame('هر دکمه مودال، مودال متناظر دارد', [], $bad);
});

TestLog::run('مایگریشن‌ها شماره تکراری ندارند', function () use ($root) {
    $nums = [];
    foreach (glob($root . '/database/migrations/*.php') ?: [] as $f) {
        if (preg_match('/^(\d+)_/', basename($f), $m)) {
            $nums[] = $m[1];
        }
    }
    TestLog::assertTrue('مایگریشن پیدا شد', count($nums) > 0);
    TestLog::assertSame('بدون شماره تکراری', count($nums), count(array_unique($nums)));
});
