<?php

declare(strict_types=1);

/**
 * ==================== تست ارسال پیامک ====================
 * برای عیب‌یابی «پیامک ارسال نشد» روی سرور واقعی:
 *
 *   php scripts/sms_test.php 09xxxxxxxxx
 *
 * یک پیامک آزمایشی به شمارهٔ داده‌شده می‌فرستد و نتیجهٔ هر دو مسیر
 * ارسال (SOAP و REST) را با جزئیات خطای پنل چاپ می‌کند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';

use App\Utilities\PhoneHelper;

$to = PhoneHelper::normalize($argv[1] ?? '');
if ($to === '' || !PhoneHelper::isValid($to)) {
    echo "استفاده:  php scripts/sms_test.php 09xxxxxxxxx\n";
    exit(1);
}

$service = new \App\Services\SmsService();
$report = $service->diagnose($to);

echo "----- تشخیص ارسال پیامک -----\n";
echo 'اعتبارنامه تنظیم‌شده: ' . ($report['enabled'] ? 'بله ✅' : 'خیر ❌') . "\n";
echo 'افزونهٔ soap:        ' . ($report['soap_available'] ? 'نصب است' : 'نصب نیست') . "\n";
echo 'افزونهٔ curl:        ' . ($report['curl_available'] ? 'در دسترس است' : 'در دسترس نیست') . "\n";
echo "مسیر SOAP:            " . $report['soap'] . "\n";
echo "مسیر REST:            " . $report['rest'] . "\n";
echo 'نتیجه نهایی:          ' . ($report['sent'] ? "پیامک آزمایشی ارسال شد ✅\n" : "پیامک ارسال نشد ❌ — جزئیات بالا و فایل لاگ (storage/logs) را ببینید.\n");

exit($report['sent'] ? 0 : 2);
