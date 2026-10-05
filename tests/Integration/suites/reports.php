<?php
/** تست گزارش ماهانه، فهرست بدهکاران و یادآوری پیامکی */
declare(strict_types=1);

use App\Services\CostService;
use App\Services\DebtorReminderService;

TestLog::suite('Reports — گزارش ماهانه و یادآوری بدهکاران');

$svc = new CostService();
$manager = make_user('09139000001', 'مدیر گزارش');
$owner = make_user('09139000002', 'مالک واحد ۱');
$settled = make_user('09139000003', 'مالک واحد ۲');
$b = make_building($manager);
add_member($b, $owner);
add_member($b, $settled);

$db = test_db();
$db->exec("INSERT INTO units (building_id, unit_number, owner_user_id) VALUES ({$b}, '1', {$owner})");
$unit1 = (int) $db->lastInsertId();
$db->exec("INSERT INTO units (building_id, unit_number, owner_user_id) VALUES ({$b}, '2', {$settled})");
$unit2 = (int) $db->lastInsertId();
// برای فیلتر داده‌های این سوئیت از سایر سوئیت‌ها
$GLOBALS['u1'] = $unit1;
$GLOBALS['u2'] = $unit2;

// دو هزینه برای واحد ۱: یکی در ماه جاری، یکی با تاریخ سه ماه پیش
$svc->recordUnitCharge($b, $unit1, 500000, 'شارژ ماه جاری', $manager);
$svc->recordUnitCharge($b, $unit1, 300000, 'شارژ قدیمی', $manager);
$db->exec("UPDATE costs SET created_at = '2025-03-10 10:00:00' WHERE building_id = {$b} AND title = 'شارژ قدیمی'");
$db->exec("UPDATE cost_payments SET created_at = '2025-03-10 10:00:00' WHERE cost_id = (SELECT id FROM costs WHERE building_id = {$b} AND title = 'شارژ قدیمی')");

// پرداخت تأییدشده برای واحد ۲ (تسویه کامل)
$svc->recordUnitCharge($b, $unit2, 200000, 'شارژ واحد ۲', $manager);
$db->exec("UPDATE cost_payments SET status = 'confirmed', amount_paid = 200000, confirmed_at = '2025-03-20 09:00:00' WHERE cost_id = (SELECT id FROM costs WHERE building_id = {$b} AND title = 'شارژ واحد ۲')");

// پرداخت تأییدشدهٔ ماه جاری برای واحد ۱
$db->exec("UPDATE cost_payments SET status = 'confirmed', amount_paid = 100000, confirmed_at = datetime('now') WHERE cost_id = (SELECT id FROM costs WHERE building_id = {$b} AND title = 'شارژ ماه جاری')");

TestLog::run('گزارش ماهانه: جمع‌های کل ساختمان', function () use ($svc, $manager, $b) {
    $report = $svc->getMonthlyReport($b, $manager);
    TestLog::assertSame('جمع صادرشده', 1000000.0, $report['totals']['charge']);
    TestLog::assertSame('جمع پرداخت‌شده', 300000.0, $report['totals']['paid']);
    TestLog::assertSame('مانده فعلی ساختمان (بدهکار)', -700000.0, $report['totals']['balance_now']);
    TestLog::assertTrue('حداقل دو ماه دارد (قدیمی + جاری)', count($report['months']) >= 2);
});

TestLog::run('گزارش ماهانه: تفکیک واحد و مانده پایان ماه', function () use ($svc, $manager, $b, $unit1) {
    $report = $svc->getMonthlyReport($b, $manager, $unit1);
    TestLog::assertSame('فقط یک واحد', 1, count($report['units']));
    $u = $report['units'][0];
    TestLog::assertSame('مانده فعلی واحد ۱', -700000.0, $u['balance_now']);
    // ماه قدیمی: فقط ۳۰۰هزار صادرشده، بدون پرداخت
    [$jy25, $jm25] = \App\Utilities\JalaliHelper::toJalali(2025, 3, 10);
    $oldKey = sprintf('%04d-%02d', $jy25, $jm25);
    $oldMonth = null;
    foreach ($u['months'] as $m) {
        if ($m['key'] === $oldKey) {
            $oldMonth = $m;
        }
    }
    TestLog::assertTrue('ماه قدیمی پیدا شد', $oldMonth !== null);
    TestLog::assertSame('صادرشده ماه قدیمی', 300000.0, $oldMonth['charge']);
    TestLog::assertSame('پرداخت ماه قدیمی صفر', 0.0, $oldMonth['paid']);
    TestLog::assertSame('مانده پایان ماه قدیمی', -300000.0, $oldMonth['balance_end']);
});

TestLog::run('گزارش ماهانه: کاربر غیرعضو رد می‌شود', function () use ($svc, $b) {
    $stranger = make_user('09139000099', 'غریبه گزارش');
    TestLog::assertThrows('دسترسی', fn() => $svc->getMonthlyReport($b, $stranger), 'عضو');
});

TestLog::run('فهرست بدهکاران: واحد بدهکار با مبلغ و پرداخت‌کنندهٔ درست', function () use ($svc, $b, $unit1, $owner) {
    $debtors = $svc->getDebtorUnits(0.0);
    // فیلتر روی واحدهای این سوئیت برای جداسازی از دادهٔ سایر سوئیت‌ها
    $mine = array_values(array_filter($debtors, static fn($d) => in_array($d['unit_id'], [$GLOBALS['u1'] ?? 0, $GLOBALS['u2'] ?? 0], true)));
    TestLog::assertSame('یک بدهکار (واحد ۱)', 1, count($mine));
    TestLog::assertSame('مبلغ بدهی', 700000.0, $mine[0]['debt']);
    TestLog::assertSame('پرداخت‌کننده مالک است', $owner, $mine[0]['user_id']);
    TestLog::assertSame('شماره مالک', '09139000002', $mine[0]['phone']);
});

TestLog::run('فهرست بدهکاران: آستانه حداقل بدهی اعمال می‌شود', function () use ($svc) {
    $high = $svc->getDebtorUnits(1000000.0); // بدهی ۷۰۰هزار کمتر از آستانه است
    $mine = array_values(array_filter($high, static fn($d) => in_array($d['unit_id'], [$GLOBALS['u1'] ?? 0, $GLOBALS['u2'] ?? 0], true)));
    TestLog::assertSame('با آستانهٔ بالا هیچ', 0, count($mine));
});

TestLog::run('واحد تسویه‌شده بدهکار محسوب نمی‌شود', function () use ($svc, $unit2) {
    $debtors = $svc->getDebtorUnits(0.0);
    $found = array_filter($debtors, static fn($d) => $d['unit_id'] === $unit2);
    TestLog::assertSame('واحد ۲ در فهرست نیست', 0, count($found));
});

TestLog::run('دورهٔ شمسی جاری و مهلت', function () {
    $period = DebtorReminderService::currentPeriod();
    TestLog::assertTrue('قالب دوره', (bool) preg_match('/^\d{4}-\d{2}$/', $period));
    TestLog::assertTrue('برچسب مهلت با «پایان» شروع می‌شود', str_starts_with(DebtorReminderService::currentDeadlineLabel(), 'پایان'));
});

TestLog::run('اجرای خشک: نامزدها بدون ارسال و بدون ثبت', function () use ($svc, $b) {
    $r = (new DebtorReminderService())->run(true);
    TestLog::assertTrue('نامزد دارد', $r['candidates'] >= 1);
    TestLog::assertSame('ارسالی نداریم', 0, $r['sent']);
    $count = (int) test_db()->query('SELECT COUNT(*) FROM debtor_sms_log')->fetchColumn();
    TestLog::assertSame('لاگی ثبت نشد', 0, $count);
});

TestLog::run('پیامک غیرفعال: ارسال انجام نمی‌شود و لاگ نمی‌خورد', function () {
    $r = (new DebtorReminderService())->run();
    TestLog::assertTrue('نشان غیرفعال‌بودن', $r['sms_disabled'] === true);
    TestLog::assertSame('ارسال صفر', 0, $r['sent']);
    $count = (int) test_db()->query('SELECT COUNT(*) FROM debtor_sms_log')->fetchColumn();
    TestLog::assertSame('لاگ باز هم ثبت نشد', 0, $count);
});

TestLog::run('جلوگیری از ارسال تکراری: کلید واحد+دوره', function () use ($unit1) {
    $svcReminder = new DebtorReminderService();
    $period = DebtorReminderService::currentPeriod();
    TestLog::assertSame('بار اول نه', false, $svcReminder->alreadyReminded($unit1, $period));
    test_db()->prepare('INSERT INTO debtor_sms_log (building_id, unit_id, user_id, phone, amount, period) VALUES (1, ?, 1, ?, 1000, ?)')
        ->execute([$unit1, '09139000002', $period]);
    TestLog::assertSame('بار دوم بله', true, $svcReminder->alreadyReminded($unit1, $period));
    // کلید یکتا در سطح دیتابیس هم محافظت می‌کند
    $threw = false;
    try {
        test_db()->prepare('INSERT INTO debtor_sms_log (building_id, unit_id, user_id, phone, amount, period) VALUES (1, ?, 1, ?, 1000, ?)')
            ->execute([$unit1, '09139000002', $period]);
    } catch (Throwable $e) {
        $threw = true;
    }
    TestLog::assertTrue('درج تکراری رد شد', $threw);
});
