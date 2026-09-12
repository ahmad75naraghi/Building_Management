<?php
/**
 * ==================== کران روزانهٔ شارژ و هزینه‌های دوره‌ای ====================
 * هر روز یک‌بار اجرا شود:
 *   ۱. برای ساختمان‌هایی که «شارژ ماهیانه» فعال دارند، شارژ ماه جاری را می‌سازد
 *      و صادر می‌کند (اعلان برای ساکنین) — اگر ماه قبل ثبت نشده باشد، ماه قبل هم.
 *   ۲. هزینه‌های دوره‌ای (هفتگی/ماهانه/…) که نوبتشان رسیده را نمونه‌سازی و صادر می‌کند.
 *   ۳. برای واحدهای بدهکار پیامک یادآوری می‌فرستد (هر واحد حداکثر یک‌بار در هر ماه شمسی).
 *
 * اجرا:
 *   php scripts/cron_daily.php
 *
 * کرون‌تب پیشنهادی (هر روز ساعت ۷ صبح):
 *   0 7 * * * /usr/bin/php /مسیر/پروژه/scripts/cron_daily.php >> /مسیر/پروژه/storage/cron.log 2>&1
 *
 * اسکریپت توان‌تکرار است: اجرای چندباره در یک روز هزینهٔ تکراری نمی‌سازد.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';

use App\Services\CostService;
use App\Services\DebtorReminderService;

$service = new CostService();

// ۱) هزینه‌های دوره‌ای با تناوب دلخواه
$recurring = $service->generateDueRecurringCosts();

// ۲) شارژ ماهیانهٔ ساختمان‌ها
$monthly = $service->generateAllMonthlyCharges();

// ۳) یادآوری پیامکی بدهکاران (حداکثر یک پیامک برای هر واحد در هر ماه شمسی)
$reminder = (new DebtorReminderService())->run();

echo date('Y-m-d H:i:s')
    . " | هزینه‌های دوره‌ای صادرشده: {$recurring['generated']}"
    . " | قالب‌های تمام‌شده: {$recurring['ended']}"
    . " | شارژ ماهیانه صادرشده: {$monthly['created']}"
    . " | ردشده/تکراری: {$monthly['skipped']}"
    . " | یادآوری بدهکاران: ارسال {$reminder['sent']} / ردشده(تکراری) {$reminder['skipped']}"
    . ($reminder['sms_disabled'] ? ' [پیامک غیرفعال]' : '')
    . PHP_EOL;

exit(0);
