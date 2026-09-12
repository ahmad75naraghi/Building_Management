<?php
/** همگام‌سازی مستندات OpenAPI با جدول روت‌ها + وجود دروازهٔ کیفیت */
declare(strict_types=1);

TestLog::suite('OpenAPI — مستندات و دروازهٔ کیفیت');

$root = dirname(__DIR__, 3);

TestLog::run('فایل openapi.json وجود دارد و معتبر است', function () use ($root) {
    $raw = file_get_contents($root . '/docs/openapi.json');
    TestLog::assertTrue('فایل خوانده شد', $raw !== false && $raw !== '');
    $spec = json_decode((string) $raw, true);
    TestLog::assertTrue('JSON معتبر است', is_array($spec));
    TestLog::assertTrue('نسخهٔ OpenAPI از خانوادهٔ 3', str_starts_with((string) ($spec['openapi'] ?? ''), '3.'));
    TestLog::assertTrue('مسیرها تعریف شده‌اند', !empty($spec['paths']));
    TestLog::assertTrue('طرح احراز هویت تعریف شده', isset($spec['components']['securitySchemes']['bearerAuth']));
});

TestLog::run('هر روت برنامه در مستندات وجود دارد', function () use ($root) {
    require_once $root . '/config/routes.php';
    $spec = json_decode((string) file_get_contents($root . '/docs/openapi.json'), true);
    $missing = [];
    foreach (\App\Config\Routes::$routes as $route => $handler) {
        [$method, $fullPath] = explode(' ', $route, 2);
        $path = preg_replace('#^/api#', '', $fullPath);
        $op = $spec['paths'][$path][strtolower($method)] ?? null;
        if ($op === null) {
            $missing[] = $route;
        }
    }
    TestLog::assertSame('همهٔ روت‌ها مستند شده‌اند', [], $missing);
});

TestLog::run('مورد اضافه‌ای در مستندات نیست', function () use ($root) {
    require_once $root . '/config/routes.php';
    $spec = json_decode((string) file_get_contents($root . '/docs/openapi.json'), true);
    $known = [];
    foreach (\App\Config\Routes::$routes as $route => $handler) {
        [$method, $fullPath] = explode(' ', $route, 2);
        $known[strtoupper($method) . ' ' . preg_replace('#^/api#', '', $fullPath)] = true;
    }
    $extra = [];
    foreach ($spec['paths'] ?? [] as $path => $methods) {
        foreach ($methods as $method => $op) {
            if (!isset($known[strtoupper($method) . ' ' . $path])) {
                $extra[] = strtoupper($method) . ' ' . $path;
            }
        }
    }
    TestLog::assertSame('مسیرهای مستندات با روت‌ها یکی است', [], $extra);
});

TestLog::run('دروازهٔ کیفیت: تنظیم PHPStan و اسکریپت کامپوزر', function () use ($root) {
    $neon = file_get_contents($root . '/phpstan.neon');
    TestLog::assertTrue('phpstan.neon وجود دارد', $neon !== false && $neon !== '');
    $level = (int) (preg_match('/level:\s*(\d+)/', (string) $neon, $m) ? $m[1] : 0);
    TestLog::assertTrue('سطح تحلیل حداقل ۵ است', $level >= 5, 'سطح: ' . $level);
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    TestLog::assertTrue('phpstan در وابستگی‌های توسعه', isset($composer['require-dev']['phpstan/phpstan']));
    TestLog::assertTrue('اسکریپت analyse تعریف شده', isset($composer['scripts']['analyse']));
    TestLog::assertTrue('اسکریپت مولد مستندات وجود دارد', is_file($root . '/scripts/generate_openapi.php'));
});
