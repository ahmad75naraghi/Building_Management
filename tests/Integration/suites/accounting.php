<?php
/** تست هزینه‌های دوره‌ای، کران، پرداخت/بدهی مستقیم و لجر حسابداری */
declare(strict_types=1);

use App\Services\CostService;

TestLog::suite('CostService — هزینه دوره‌ای، کران و حسابداری');

$svc = new CostService();
$manager = make_user('09139000001', 'مدیر حسابداری');

/** ساختمان با دو واحد ساکن‌دار برای تست‌های حسابداری */
function acc_building(CostService $svc, int $manager, string $phoneBase, int $charge = 0): array
{
    $b = make_building($manager, ['monthly_charge' => $charge, 'charge_mode' => 'fixed']);
    $t1 = make_user($phoneBase . '1', 'مستأجر یک');
    $t2 = make_user($phoneBase . '2', 'مستأجر دو');
    $u1 = make_unit($b, '1', ['tenant_user_id' => $t1, 'residents_count' => 2]);
    $u2 = make_unit($b, '2', ['tenant_user_id' => $t2, 'residents_count' => 3]);
    add_member($b, $t1);
    add_member($b, $t2);
    return [$b, $u1, $u2, $t1, $t2];
}

// ------------------------------------------------------------ تناوب‌ها

TestLog::run('محاسبهٔ نوبت بعد برای تناوب‌های مختلف', function () {
    TestLog::assertSame('هفتگی', '2026-03-22', CostService::addInterval('2026-03-15', 'weekly'));
    TestLog::assertSame('دوهفتگی', '2026-03-29', CostService::addInterval('2026-03-15', 'biweekly'));
    TestLog::assertSame('ماهانه', '2026-06-10', CostService::addInterval('2026-05-10', 'monthly'));
    TestLog::assertSame('دوماهانه', '2026-07-10', CostService::addInterval('2026-05-10', 'bimonthly'));
    TestLog::assertSame('سه‌ماهانه', '2026-08-10', CostService::addInterval('2026-05-10', 'quarterly'));
    TestLog::assertSame('سالانه', '2027-05-10', CostService::addInterval('2026-05-10', 'yearly'));
});

TestLog::run('برچسب فارسی تناوب‌ها', function () {
    TestLog::assertSame('ماهانه', 'ماهانه', CostService::intervalLabel('monthly'));
    TestLog::assertSame('هفتگی', 'هفتگی (هر هفته)', CostService::intervalLabel('weekly'));
    TestLog::assertSame('نامعتبر → پیش‌فرض', 'ماهانه', CostService::intervalLabel('xx'));
});

// ------------------------------------------------------------ قالب دوره‌ای

TestLog::run('ساخت قالب دوره‌ای: وضعیت فعال و نوبت اول = تاریخ شروع', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900010');
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'نظافت هفتگی', 'amount' => 200000,
        'is_recurring' => true, 'recurring_interval' => 'weekly',
        'recurring_start_date' => '2026-12-01',
    ], $manager);
    TestLog::assertSame('نوع', 'recurring', $cost->cost_type);
    TestLog::assertSame('وضعیت', 'active', $cost->status);
    TestLog::assertSame('نوبت بعد', '2026-12-01', $cost->recurring_next_date);
    TestLog::assertSame('تاریخ شروع', '2026-12-01', $cost->recurring_start_date);
});

TestLog::run('قالب دوره‌ای: تاریخ پایان قبل از شروع رد می‌شود', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900020');
    TestLog::assertThrows('پایان < شروع', fn() => $svc->createCost([
        'building_id' => $b, 'title' => 'x', 'amount' => 1000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-05-10', 'recurring_end_date' => '2026-05-01',
    ], $manager), 'پایان');
});

TestLog::run('کران: صدور نوبت‌های سررسیدشده + جلو رفتن نوبت بعد', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900030');
    $svc->createCost([
        'building_id' => $b, 'title' => 'شارژ استخر', 'amount' => 100000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-06-08',
    ], $manager);

    $r = $svc->generateDueRecurringCosts('2026-09-08');
    TestLog::assertSame('چهار نوبت صادر شد', 4, $r['generated']);

    // قالب باید به نوبت بعد (آینده) رفته باشد
    $db = test_db();
    $tpl = $db->query("SELECT * FROM costs WHERE cost_type = 'recurring' AND building_id = {$b}")->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('نوبت بعد قالب', '2026-10-08', $tpl['recurring_next_date']);

    // نمونه‌ها صادر شده و سهم هر واحد درست است (تقسیم مساوی بین ۲ واحد)
    $sum = $db->query("SELECT COUNT(*) FROM cost_payments cp JOIN costs c ON cp.cost_id = c.id WHERE c.parent_cost_id = {$tpl['id']}")->fetchColumn();
    TestLog::assertSame('ردیف پرداخت نمونه‌ها', 8, (int) $sum); // ۴ نوبت × ۲ واحد
    $share = $db->query("SELECT share_amount FROM cost_payments cp JOIN costs c ON cp.cost_id = c.id WHERE c.parent_cost_id = {$tpl['id']} LIMIT 1")->fetchColumn();
    TestLog::assertSame('سهم هر واحد', 50000.0, (float) $share);
});

TestLog::run('کران توان‌تکرار است: اجرای مجدد هزینهٔ تکراری نمی‌سازد', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900040');
    $svc->createCost([
        'building_id' => $b, 'title' => 'باغبانی', 'amount' => 50000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-08-01',
    ], $manager);
    $first = $svc->generateDueRecurringCosts('2026-09-08');
    TestLog::assertSame('نوبت اول', 2, $first['generated']);
    $second = $svc->generateDueRecurringCosts('2026-09-08');
    TestLog::assertSame('اجرای مجدد', 0, $second['generated']);
});

TestLog::run('قالب با تاریخ پایان: پس از آخرین نوبت بسته می‌شود', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900050');
    $tpl = $svc->createCost([
        'building_id' => $b, 'title' => 'پروژه موقت', 'amount' => 30000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-09-01', 'recurring_end_date' => '2026-09-20',
    ], $manager);

    $r = $svc->generateDueRecurringCosts('2026-09-08');
    TestLog::assertSame('فقط یک نوبت در بازه', 1, $r['generated']);
    TestLog::assertSame('قالب بسته شد', 1, $r['ended']);

    $db = test_db();
    $row = $db->query("SELECT status, recurring_next_date FROM costs WHERE id = {$tpl->id}")->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('وضعیت', 'ended', $row['status']);
    TestLog::assertSame('نوبت بعد خالی', null, $row['recurring_next_date']);
});

TestLog::run('صدور ناموفق: نوبت حفظ می‌شود و نمونهٔ ناقص حذف می‌گردد', function () use ($svc, $manager) {
    $b = make_building($manager);
    $emptyUnit = make_unit($b, '1'); // بدون مالک/مستأجر
    $tpl = $svc->createCost([
        'building_id' => $b, 'title' => 'بدون ساکن', 'amount' => 10000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-09-01',
        'target_audience' => 'specific_units', 'target_unit_ids' => [$emptyUnit],
    ], $manager);

    $r = $svc->generateDueRecurringCosts('2026-09-08');
    TestLog::assertSame('صدوری انجام نشد', 0, $r['generated']);

    $db = test_db();
    $row = $db->query("SELECT recurring_next_date, status FROM costs WHERE id = {$tpl->id}")->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('نوبت ثابت ماند', '2026-09-01', $row['recurring_next_date']);
    TestLog::assertSame('وضعیت فعال ماند', 'active', $row['status']);
    $orphans = $db->query("SELECT COUNT(*) FROM costs WHERE parent_cost_id = {$tpl->id}")->fetchColumn();
    TestLog::assertSame('نمونهٔ ناقص حذف شد', 0, (int) $orphans);
});

// ------------------------------------------------------------ کران شارژ ماهیانه

TestLog::run('کران شارژ ماهیانه: ساختمان فعال شارژ می‌گیرد', function () use ($svc, $manager) {
    acc_building($svc, $manager, '0913900060', 120000);
    $r1 = $svc->generateAllMonthlyCharges();
    TestLog::assertSame('صدور اول', 1, $r1['created']);
    $r2 = $svc->generateAllMonthlyCharges();
    TestLog::assertSame('اجرای مجدد صادر نمی‌کند', 0, $r2['created']);
});

// ------------------------------------------------------------ پرداخت مستقیم

TestLog::run('پرداخت مستقیم: تأیید فوری و نشستن به حساب واحد', function () use ($svc, $manager) {
    [$b, $u1, $u2, $t1] = acc_building($svc, $manager, '0913900070', 100000);
    $svc->generateAllMonthlyCharges();

    $payment = $svc->recordDirectPayment($b, $u1, 150000.0, 'واریز نقدی', $manager);
    TestLog::assertSame('وضعیت پرداخت', 'confirmed', $payment->status);
    TestLog::assertSame('مبلغ', 150000.0, $payment->amount_paid);

    $ledger = $svc->getBuildingLedger($b, $manager);
    TestLog::assertSame('مانده واحد ۱ (طلبکار)', 50000.0, $ledger['units'][$u1]['balance']);
    TestLog::assertSame('وضعیت واحد ۱', 'creditor', $ledger['units'][$u1]['state']);
    TestLog::assertSame('مانده واحد ۲ (بدهکار)', -100000.0, $ledger['units'][$u2]['balance']);
    TestLog::assertSame('جمع بدهی', 100000.0, $ledger['totals']['debt']);
    TestLog::assertSame('جمع طلب', 50000.0, $ledger['totals']['credit']);
    TestLog::assertSame('مانده خالص', -50000.0, $ledger['totals']['balance']);
});

TestLog::run('پرداخت مستقیم: فقط مدیر ساختمان مجاز است', function () use ($svc, $manager) {
    [$b, $u1] = acc_building($svc, $manager, '0913900080');
    $stranger = make_user('09139000999', 'غریبه');
    TestLog::assertThrows('غیرمدیر', fn() => $svc->recordDirectPayment($b, $u1, 1000.0, null, $stranger), 'مدیر');
});

TestLog::run('پرداخت مستقیم: واحد بدون ساکن رد می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    $empty = make_unit($b, '1');
    TestLog::assertThrows('واحد خالی', fn() => $svc->recordDirectPayment($b, $empty, 1000.0, null, $manager), 'مالک یا مستأجر');
});

// ------------------------------------------------------------ بدهی مستقیم

TestLog::run('بدهی مستقیم: بلافاصله صادر و از اعتبار واحد کم می‌شود', function () use ($svc, $manager) {
    [$b, $u1] = acc_building($svc, $manager, '0913900090', 100000);
    $svc->generateAllMonthlyCharges();
    $svc->recordDirectPayment($b, $u1, 150000.0, null, $manager);

    $cost = $svc->recordUnitCharge($b, $u1, 30000.0, 'جریمه دیرکرد', $manager);
    TestLog::assertTrue('هزینه ساخته شد', $cost->id > 0);

    $ledger = $svc->getBuildingLedger($b, $manager);
    TestLog::assertSame('مانده پس از بدهی', 20000.0, $ledger['units'][$u1]['balance']);
});

TestLog::run('بدهی مستقیم: مبلغ نامعتبر رد می‌شود', function () use ($svc, $manager) {
    [$b, $u1] = acc_building($svc, $manager, '0913900100');
    TestLog::assertThrows('مبلغ صفر', fn() => $svc->recordUnitCharge($b, $u1, 0.0, 'عنوان', $manager), 'بیشتر از صفر');
});

// ------------------------------------------------------------ لجر و جریان پرداخت عادی

TestLog::run('پرداخت عادی تأییدشده هم بدهی واحد را کم می‌کند', function () use ($svc, $manager) {
    [$b, $u1, $u2, $t1, $t2] = acc_building($svc, $manager, '0913900110', 100000);
    $svc->generateAllMonthlyCharges();

    // مستأجر واحد ۲ پرداخت می‌کند و مدیر تأیید می‌کند
    $db = test_db();
    $paymentId = (int) $db->query("SELECT cp.id FROM cost_payments cp JOIN costs c ON cp.cost_id=c.id WHERE c.building_id={$b} AND cp.user_id={$t2} LIMIT 1")->fetchColumn();
    $svc->submitPayment(['payment_id' => $paymentId, 'cost_id' => null, 'amount_paid' => 40000], $t2, receipt_png_bytes(), 'fish.png');
    $svc->confirmPayment($paymentId, $manager);

    $ledger = $svc->getBuildingLedger($b, $manager);
    TestLog::assertSame('بدهی واحد ۲ پس از پرداخت جزئی', -60000.0, $ledger['units'][$u2]['balance']);
    TestLog::assertSame('وضعیت بدهکار', 'debtor', $ledger['units'][$u2]['state']);
});

TestLog::run('لجر: ورودی‌ها به ترتیب تاریخ و با مانده لحظه‌ای هستند', function () use ($svc, $manager) {
    [$b, $u1] = acc_building($svc, $manager, '0913900120', 50000);
    $svc->generateAllMonthlyCharges();
    $svc->recordDirectPayment($b, $u1, 80000.0, null, $manager);

    $ledger = $svc->getBuildingLedger($b, $manager);
    $entries = $ledger['units'][$u1]['entries'];
    TestLog::assertSame('دو رویداد', 2, count($entries));
    TestLog::assertSame('اول بدهی', 'charge', $entries[0]['type']);
    TestLog::assertSame('دوم پرداخت', 'payment', $entries[1]['type']);
    TestLog::assertSame('مانده لحظه‌ای اول', -50000.0, $entries[0]['balance']);
    TestLog::assertSame('مانده لحظه‌ای دوم', 30000.0, $entries[1]['balance']);
});

TestLog::run('لجر: کاربر غیرعضو دسترسی ندارد', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900130');
    $stranger = make_user('09139000998', 'غریبه');
    TestLog::assertThrows('غیرعضو', fn() => $svc->getBuildingLedger($b, $stranger), 'عضو');
});

// ------------------------------------------------------------ سازگاری منبع واحد مانده

TestLog::run('مانده در لجر، واحد-بالانس و خلاصه مالی یک عدد است', function () use ($svc, $manager) {
    [$b, $u1, $u2, $t1, $t2] = acc_building($svc, $manager, '0913900140', 100000);
    $svc->generateAllMonthlyCharges();
    $svc->recordDirectPayment($b, $u1, 150000.0, null, $manager);

    $ledger = $svc->getBuildingLedger($b, $manager);
    $balances = $svc->getUnitBalances($b, $manager);
    $balancesByUnit = [];
    foreach ($balances as $bal) {
        $balancesByUnit[(int) $bal['unit_id']] = (float) $bal['balance'];
    }

    foreach ($ledger['units'] as $uid => $lu) {
        TestLog::assertSame("مانده واحد $uid در هر دو منبع", $balancesByUnit[$uid] ?? null, (float) $lu['balance']);
    }

    // خلاصه مالی: بدهی صادرشده و وصولی با جمع لجر یکی است
    $summary = $svc->getFinancialSummary($b);
    $sumShare = 0.0;
    $sumPaid = 0.0;
    foreach ($balances as $bal) {
        $sumShare += (float) $bal['total_share'];
        $sumPaid += (float) $bal['total_paid'];
    }
    TestLog::assertSame('جمع بدهی صادرشده', $sumShare, (float) $summary['total_costs']);
    TestLog::assertSame('جمع وصولی', $sumPaid, (float) $summary['total_collected']);
});

TestLog::run('هزینه حذف‌شده از مانده همه صفحه‌ها خارج می‌شود', function () use ($svc, $manager) {
    [$b, $u1] = acc_building($svc, $manager, '0913900150', 0);
    // یک بدهی مستقیم می‌سازیم و سپس حذفش می‌کنیم
    $cost = $svc->recordUnitCharge($b, $u1, 70000.0, 'هزینه حذف‌شدنی', $manager);
    $before = $svc->getUnitBalances($b, $manager);
    $beforeByUnit = [];
    foreach ($before as $bal) {
        $beforeByUnit[(int) $bal['unit_id']] = (float) $bal['balance'];
    }
    TestLog::assertSame('قبل از حذف: بدهکار ۷۰هزار', -70000.0, $beforeByUnit[$u1] ?? null);

    $svc->deleteCost((int) $cost->id, $manager);

    $after = $svc->getUnitBalances($b, $manager);
    $afterByUnit = [];
    foreach ($after as $bal) {
        $afterByUnit[(int) $bal['unit_id']] = (float) $bal['balance'];
    }
    TestLog::assertSame('بعد از حذف: تسویه', 0.0, $afterByUnit[$u1] ?? 0.0);

    // لجر هم نباید تراکنشی از هزینه حذف‌شده نشان دهد
    $ledger = $svc->getBuildingLedger($b, $manager);
    TestLog::assertSame('لجر واحد خالی یا صفر', 0.0, (float) ($ledger['units'][$u1]['balance'] ?? 0.0));
});

// ------------------------------------------------------------ موتور دوره‌ای تک‌ساختمان

TestLog::run('موتور دوره‌ای تک‌ساختمان: فقط ساختمانِ صفحه پردازش می‌شود', function () use ($svc, $manager) {
    [$bA] = acc_building($svc, $manager, '0913900200');
    [$bB] = acc_building($svc, $manager, '0913900210');

    // یک قالب دوره‌ای سررسیدگذشته در هر دو ساختمان
    $tplA = $svc->createCost([
        'building_id' => $bA, 'title' => 'نظافت الف', 'amount' => 50000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-08-01',
    ], $manager);
    $tplB = $svc->createCost([
        'building_id' => $bB, 'title' => 'نظافت ب', 'amount' => 60000,
        'is_recurring' => true, 'recurring_interval' => 'monthly',
        'recurring_start_date' => '2026-08-01',
    ], $manager);

    $r = $svc->generateDueRecurringCostsForBuilding($bA, '2026-09-01');
    TestLog::assertTrue('نوبت‌های ساختمان الف صادر شد', $r['generated'] >= 1);

    $db = test_db();
    $kidsA = (int) $db->query("SELECT COUNT(*) FROM costs WHERE building_id = {$bA} AND parent_cost_id = {$tplA->id}")->fetchColumn();
    TestLog::assertTrue('هزینه فرزند برای ساختمان الف ساخته شد', $kidsA >= 1);

    // ساختمان ب نباید هیچ تغییری کرده باشد
    $kidsB = (int) $db->query("SELECT COUNT(*) FROM costs WHERE building_id = {$bB} AND parent_cost_id = {$tplB->id}")->fetchColumn();
    TestLog::assertSame('ساختمان ب دست‌نخورده ماند', 0, $kidsB);
    $nextB = (string) $db->query("SELECT recurring_next_date FROM costs WHERE id = {$tplB->id}")->fetchColumn();
    TestLog::assertSame('نوبت بعد قالب ب ثابت', '2026-08-01', $nextB);

    // قالب الف جلو رفته است
    $nextA = (string) $db->query("SELECT recurring_next_date FROM costs WHERE id = {$tplA->id}")->fetchColumn();
    TestLog::assertTrue('نوبت بعد قالب الف جلو رفت', $nextA > '2026-08-01');
});

TestLog::run('شارژ ماهیانهٔ تک‌ساختمان: صدور یک‌بار و توان‌تکرار', function () use ($svc, $manager) {
    [$b] = acc_building($svc, $manager, '0913900220', 30000);
    $r = $svc->generateMonthlyChargeForBuilding($b);
    TestLog::assertSame('یک شارژ صادر شد', 1, $r['created']);
    $r2 = $svc->generateMonthlyChargeForBuilding($b);
    TestLog::assertSame('اجرای دوم همان ماه: رد شد', 0, $r2['created']);
    $monthKey = date('Y-m');
    $cnt = (int) test_db()->query("SELECT COUNT(*) FROM costs WHERE building_id = {$b} AND description = 'auto:monthly:{$monthKey}'")->fetchColumn();
    TestLog::assertSame('فقط یک ردیف شارژ برای ماه جاری', 1, $cnt);
});
