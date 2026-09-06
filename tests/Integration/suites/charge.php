<?php
/** تست سه حالت محاسبه شارژ */
declare(strict_types=1);

use App\Core\Logger;
use App\Services\CostService;

TestLog::suite('CostService — حالت‌های شارژ (ثابت / نفری / دلخواه)');

$db = test_db();
$svc = new CostService();
$manager = make_user('09131000001', 'مدیر ساختمان');

// ------------------------------------------------------------ شارژ ثابت

TestLog::run('شارژ ثابت: هر واحد مبلغ یکسان', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 500000]);
    make_unit($b, '1');
    make_unit($b, '2');
    make_unit($b, '3');

    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('حالت', 'fixed', $r['mode']);
    TestLog::assertSame('تعداد واحدها', 3, count($r['units']));
    TestLog::assertSame('جمع کل', 1500000.0, $r['total']);
    TestLog::assertSame('سهم هر واحد', 500000.0, $r['units'][0]['amount']);
});

TestLog::run('شارژ ثابت بدون واحد: مبلغ پایه لحاظ می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 700000]);
    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('جمع برابر مبلغ پایه', 700000.0, $r['total']);
    TestLog::assertSame('لیست واحد خالی', 0, count($r['units']));
});

TestLog::run('شارژ ثابت صفر: چیزی محاسبه نمی‌شود', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 0]);
    make_unit($b, '1');
    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('جمع صفر', 0.0, $r['total']);
    TestLog::assertSame('واحدی درج نشد', 0, count($r['units']));
});

// ------------------------------------------------------------ شارژ نفری

TestLog::run('شارژ نفری: تعداد نفرات × نرخ', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'per_person', 'charge_per_person' => 100000]);
    make_unit($b, '1', ['residents_count' => 2]);
    make_unit($b, '2', ['residents_count' => 4]);
    make_unit($b, '3', ['residents_count' => 1]);

    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('حالت', 'per_person', $r['mode']);
    TestLog::assertSame('واحد ۲ نفره', 200000.0, $r['units'][0]['amount']);
    TestLog::assertSame('واحد ۴ نفره', 400000.0, $r['units'][1]['amount']);
    TestLog::assertSame('واحد ۱ نفره', 100000.0, $r['units'][2]['amount']);
    TestLog::assertSame('جمع کل', 700000.0, $r['total']);
});

TestLog::run('شارژ نفری: واحد خالی از شارژ معاف است', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'per_person', 'charge_per_person' => 100000]);
    make_unit($b, '1', ['residents_count' => 3]);
    make_unit($b, '2', ['residents_count' => 0]);

    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('فقط واحد ساکن‌دار', 1, count($r['units']));
    TestLog::assertSame('جمع کل', 300000.0, $r['total']);
});

TestLog::run('شارژ نفری بدون تعیین نرخ', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'per_person', 'charge_per_person' => 0]);
    make_unit($b, '1', ['residents_count' => 5]);
    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('جمع صفر', 0.0, $r['total']);
});

TestLog::run('شارژ نفری، مبلغ ثابت را نادیده می‌گیرد', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'per_person', 'charge_per_person' => 50000, 'monthly_charge' => 999999]);
    make_unit($b, '1', ['residents_count' => 2]);
    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('فقط نرخ نفری اعمال شد', 100000.0, $r['total']);
});

// ------------------------------------------------------------ شارژ دلخواه

TestLog::run('شارژ دلخواه: مبلغ اختصاصی هر واحد', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'custom']);
    make_unit($b, '1', ['custom_charge' => 300000]);
    make_unit($b, '2', ['custom_charge' => 450000]);
    make_unit($b, '3', ['custom_charge' => null]);

    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('حالت', 'custom', $r['mode']);
    TestLog::assertSame('فقط واحدهای دارای مبلغ', 2, count($r['units']));
    TestLog::assertSame('جمع کل', 750000.0, $r['total']);
});

TestLog::run('شارژ دلخواه بدون تنظیم هیچ واحدی', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'custom']);
    make_unit($b, '1');
    $r = $svc->calculateMonthlyCharges($b);
    TestLog::assertSame('جمع صفر', 0.0, $r['total']);
});

TestLog::run('شارژ دلخواه، مبلغ ثابت را نادیده می‌گیرد', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'custom', 'monthly_charge' => 888888]);
    make_unit($b, '1', ['custom_charge' => 111111]);
    TestLog::assertSame('فقط مبلغ اختصاصی', 111111.0, $svc->calculateMonthlyCharges($b)['total']);
});

// ------------------------------------------------------------ حاشیه‌ها

TestLog::run('ساختمان ناموجود', function () use ($svc) {
    $r = $svc->calculateMonthlyCharges(999999);
    TestLog::assertSame('جمع صفر', 0.0, $r['total']);
    TestLog::assertSame('حالت پیش‌فرض', 'fixed', $r['mode']);
});

TestLog::run('حالت ناشناخته به ثابت برمی‌گردد', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'bogus', 'monthly_charge' => 200000]);
    make_unit($b, '1');
    TestLog::assertSame('مثل حالت ثابت رفتار می‌کند', 200000.0, $svc->calculateMonthlyCharges($b)['total']);
});

TestLog::run('واحد یک ساختمان روی ساختمان دیگر اثر ندارد', function () use ($svc, $manager) {
    $a = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 100000]);
    $c = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 100000]);
    make_unit($a, '1'); make_unit($a, '2');
    make_unit($c, '1');
    TestLog::assertSame('ساختمان الف', 200000.0, $svc->calculateMonthlyCharges($a)['total']);
    TestLog::assertSame('ساختمان ب', 100000.0, $svc->calculateMonthlyCharges($c)['total']);
});

// ------------------------------------------------------------ ثبت خودکار شارژ

TestLog::run('ساخت شارژ ماهانه و جلوگیری از تکرار', function () use ($svc, $manager, $db) {
    $b = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 400000, 'monthly_charge_enabled' => 1]);
    make_unit($b, '1'); make_unit($b, '2');

    $cost = $svc->createMonthlyCharge($b, $manager);
    TestLog::assertSame('مبلغ کل هزینه', 800000.0, (float) $cost->amount);
    TestLog::assertSame('نشانگر ماه', 'auto:monthly:' . date('Y-m'), $cost->description);

    $again = $svc->createMonthlyCharge($b, $manager);
    TestLog::assertSame('همان رکورد برگشت', (int) $cost->id, (int) $again->id);

    $n = (int) $db->prepare("SELECT COUNT(*) FROM costs WHERE building_id = ?")->execute([$b]) ;
    $cnt = $db->query("SELECT COUNT(*) FROM costs WHERE building_id = {$b}")->fetchColumn();
    TestLog::assertSame('فقط یک هزینه ثبت شد', 1, (int) $cnt);
});

TestLog::run('روش تقسیم متناسب با حالت شارژ ثبت می‌شود', function () use ($svc, $manager, $db) {
    $b = make_building($manager, ['charge_mode' => 'per_person', 'charge_per_person' => 60000, 'monthly_charge_enabled' => 1]);
    make_unit($b, '1', ['residents_count' => 3]);
    $svc->createMonthlyCharge($b, $manager);
    $m = $db->query("SELECT division_method FROM costs WHERE building_id = {$b}")->fetchColumn();
    TestLog::assertSame('division_method', 'people_count', $m);
});

TestLog::run('شارژ غیرفعال ساخته نمی‌شود', function () use ($svc, $manager) {
    $b = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 100000, 'monthly_charge_enabled' => 0]);
    TestLog::assertThrows('شارژ غیرفعال', fn() => $svc->createMonthlyCharge($b, $manager), 'فعال نیست');
});

TestLog::run('پیام راهنمای مناسب هر حالت وقتی تنظیمات ناقص است', function () use ($svc, $manager) {
    $b1 = make_building($manager, ['charge_mode' => 'per_person', 'charge_per_person' => 0, 'monthly_charge_enabled' => 1]);
    TestLog::assertThrows('راهنمای نفری', fn() => $svc->createMonthlyCharge($b1, $manager), 'هر نفر');

    $b2 = make_building($manager, ['charge_mode' => 'custom', 'monthly_charge_enabled' => 1]);
    make_unit($b2, '1');
    TestLog::assertThrows('راهنمای دلخواه', fn() => $svc->createMonthlyCharge($b2, $manager), 'شارژ اختصاصی');

    $b3 = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 0, 'monthly_charge_enabled' => 1]);
    TestLog::assertThrows('راهنمای ثابت', fn() => $svc->createMonthlyCharge($b3, $manager), 'شارژ ثابت');
});

TestLog::run('ensureMonthlyCharge بی‌صدا شکست نمی‌خورد بلکه لاگ می‌کند', function () use ($svc, $manager, $db) {
    $b = make_building($manager, ['charge_mode' => 'fixed', 'monthly_charge' => 250000, 'monthly_charge_enabled' => 1]);
    make_unit($b, '1');
    $svc->ensureMonthlyCharge($b, $manager);
    $cnt = (int) $db->query("SELECT COUNT(*) FROM costs WHERE building_id = {$b}")->fetchColumn();
    TestLog::assertSame('شارژ ماه ساخته شد', 1, $cnt);

    $svc->ensureMonthlyCharge($b, $manager);
    $cnt2 = (int) $db->query("SELECT COUNT(*) FROM costs WHERE building_id = {$b}")->fetchColumn();
    TestLog::assertSame('بار دوم تکراری نساخت', 1, $cnt2);
});

TestLog::run('ensureMonthlyCharge برای ساختمان غیرفعال کاری نمی‌کند', function () use ($svc, $manager, $db) {
    $b = make_building($manager, ['monthly_charge' => 100000, 'monthly_charge_enabled' => 0]);
    $svc->ensureMonthlyCharge($b, $manager);
    TestLog::assertSame('هزینه‌ای ثبت نشد', 0,
        (int) $db->query("SELECT COUNT(*) FROM costs WHERE building_id = {$b}")->fetchColumn());
});

TestLog::run('جزئیات تقسیم برای شفافیت ذخیره می‌شود', function () use ($svc, $manager, $db) {
    $b = make_building($manager, ['charge_mode' => 'custom', 'monthly_charge_enabled' => 1]);
    make_unit($b, '10', ['custom_charge' => 120000]);
    make_unit($b, '11', ['custom_charge' => 180000]);
    $svc->createMonthlyCharge($b, $manager);
    $raw = $db->query("SELECT division_details FROM costs WHERE building_id = {$b}")->fetchColumn();
    $details = is_string($raw) ? json_decode($raw, true) : $raw;
    TestLog::assertTrue('جزئیات ذخیره شد', is_array($details) && count($details) === 2,
        'مقدار: ' . var_export($raw, true));
});
