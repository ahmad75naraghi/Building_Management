<?php
/**
 * اجراکنندهٔ تست‌های یکپارچه.
 *
 *   php tests/Integration/run.php            اجرای همه
 *   php tests/Integration/run.php otp cost   اجرای سوئیت‌های مشخص
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

\App\Core\Logger::useMemory(true);

$only = array_slice($argv ?? [], 1);
$suites = ['logger', 'handlers', 'phone', 'jalali', 'validator', 'otp', 'user', 'charge', 'costissue', 'accounting', 'roles', 'documents', 'votes', 'notifications', 'payment_reminders', 'invite_reminders', 'bulkusers', 'audit', 'security', 'reports', 'messaging', 'invitations', 'dashboard', 'api_smoke', 'openapi', 'codebase', 'e2e'];

TestLog::start();
foreach ($suites as $suite) {
    if ($only && !in_array($suite, $only, true)) {
        continue;
    }
    test_db_reset();
    \App\Core\Logger::reset();
    $path = $suite === 'e2e'
        ? __DIR__ . '/../E2E/render_pages.php'
        : __DIR__ . '/suites/' . $suite . '.php';
    require $path;
}
exit(TestLog::summary());
