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
$suites = ['logger', 'handlers', 'phone', 'jalali', 'validator', 'otp', 'user', 'charge', 'costissue', 'roles', 'documents', 'audit', 'security', 'codebase'];

TestLog::start();
foreach ($suites as $suite) {
    if ($only && !in_array($suite, $only, true)) {
        continue;
    }
    test_db_reset();
    \App\Core\Logger::reset();
    require __DIR__ . '/suites/' . $suite . '.php';
}
exit(TestLog::summary());
