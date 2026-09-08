<?php
/** تست صدور هزینه برای مخاطبان مشخص (ساکنین / مالکین / مستأجرین / واحدهای خاص) */
declare(strict_types=1);

use App\Core\Logger;
use App\Services\CostService;

TestLog::suite('CostService — صدور هزینه برای مخاطبان انتخابی');

$db = test_db();
$svc = new CostService();
$manager = make_user('09132000001', 'مدیر ساختمان');

/** پرداخت‌های یک هزینه را از دیتابیس می‌خواند */
function cost_payments_of(int $costId): array
{
    $stmt = test_db()->prepare("SELECT * FROM cost_payments WHERE cost_id = ? ORDER BY id");
    $stmt->execute([$costId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------ اعتبارسنجی مخاطب

TestLog::run('مخاطب نامعتبر رد می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    make_unit($b, '1', ['owner_user_id' => make_user('09132000099')]);
    TestLog::assertThrows('مخاطب ناشناخته', fn() => $svc->createCost([
        'building_id' => $b, 'title' => 'رنگ', 'amount' => 100000, 'target_audience' => 'ghosts',
    ], $manager), 'target_audience');
});

TestLog::run('واحدهای خاص بدون انتخاب واحد رد می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    make_unit($b, '1', ['owner_user_id' => make_user('09132000098')]);
    TestLog::assertThrows('بدون واحد', fn() => $svc->createCost([
        'building_id' => $b, 'title' => 'رنگ', 'amount' => 100000,
        'target_audience' => 'specific_units', 'target_unit_ids' => [],
    ], $manager), 'target_unit_ids');
});

// ------------------------------------------------------------ تقسیم مساوی بین مالکین

TestLog::run('صدور برای مالکین: سهم مساوی', function () use ($svc, $manager, $db) {
    $b = make_building($manager);
    $o1 = make_user('09132000010'); $o2 = make_user('09132000011');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    make_unit($b, '2', ['owner_user_id' => $o2]);

    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'رنگ راه‌پله', 'amount' => 100000,
        'target_audience' => 'owners', 'division_method' => 'fixed_share',
    ], $manager);
    $r = $svc->issueCost((int) $cost->id, $manager);

    TestLog::assertSame('دو پرداخت‌کننده', 2, $r['issued']);
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('دو ردیف پرداخت', 2, count($rows));
    TestLog::assertSame('سهم هر مالک', 50000.0, (float) $rows[0]['share_amount']);
    TestLog::assertSame('وضعیت در انتظار', 'pending', $rows[0]['status']);
    TestLog::assertTrue('مبلغ هنوز پرداخت نشده', $rows[0]['amount_paid'] === null);
    TestLog::assertTrue('زمان صدور ثبت شد', $svc->getCost((int) $cost->id)->issued_at !== null);
});

TestLog::run('صدور مجدد تکراری نمی‌سازد', function () use ($svc, $manager) {
    $b = make_building($manager);
    make_unit($b, '1', ['owner_user_id' => make_user('09132000012')]);
    make_unit($b, '2', ['owner_user_id' => make_user('09132000013')]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'تعمیر', 'amount' => 80000, 'target_audience' => 'owners'], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $second = $svc->issueCost((int) $cost->id, $manager);
    TestLog::assertSame('بار دوم چیزی صادر نشد', 0, $second['issued']);
    TestLog::assertSame('ردیف‌ها رد شدند', 2, $second['skipped']);
    TestLog::assertSame('همچنان دو ردیف', 2, count(cost_payments_of((int) $cost->id)));
});

// ------------------------------------------------------------ تقسیم وزنی بر اساس متراژ

TestLog::run('تقسیم بر اساس متراژ بین مالکین', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000014'); $o2 = make_user('09132000015');
    make_unit($b, '1', ['owner_user_id' => $o1, 'area' => 50]);
    make_unit($b, '2', ['owner_user_id' => $o2, 'area' => 150]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'ایزوگام', 'amount' => 200000,
        'target_audience' => 'owners', 'division_method' => 'area',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $byUser = [];
    foreach ($rows as $row) { $byUser[(int) $row['user_id']] = (float) $row['share_amount']; }
    TestLog::assertSame('سهم واحد ۵۰ متری', 50000.0, $byUser[$o1] ?? null);
    TestLog::assertSame('سهم واحد ۱۵۰ متری', 150000.0, $byUser[$o2] ?? null);
});

// ------------------------------------------------------------ مستأجرین

TestLog::run('صدور برای مستأجرین فقط مستأجران را می‌گیرد', function () use ($svc, $manager) {
    $b = make_building($manager);
    $t1 = make_user('09132000016'); $o2 = make_user('09132000017');
    make_unit($b, '1', ['tenant_user_id' => $t1]);
    make_unit($b, '2', ['owner_user_id' => $o2]); // بدون مستأجر → شامل نمی‌شود
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'نظافت', 'amount' => 90000, 'target_audience' => 'tenants'], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('فقط یک مستأجر', 1, count($rows));
    TestLog::assertSame('کاربر همان مستأجر است', $t1, (int) $rows[0]['user_id']);
    TestLog::assertSame('کل مبلغ برای تنها مستأجر', 90000.0, (float) $rows[0]['share_amount']);
});

// ------------------------------------------------------------ ساکنین

TestLog::run('ساکنین: مستأجر، و در نبود او مالکِ ساکن', function () use ($svc, $manager) {
    $b = make_building($manager);
    $t1 = make_user('09132000018');           // واحد ۱ مستأجر دارد
    $o2 = make_user('09132000019');           // واحد ۲ مالکِ ساکن
    $o3 = make_user('09132000020');           // واحد ۳ مالک غیرساکن → شامل نمی‌شود
    make_unit($b, '1', ['tenant_user_id' => $t1]);
    make_unit($b, '2', ['owner_user_id' => $o2, 'owner_resident' => 1]);
    make_unit($b, '3', ['owner_user_id' => $o3, 'owner_resident' => 0]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'برف‌روبی', 'amount' => 60000, 'target_audience' => 'residents'], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $users = array_map(fn($r) => (int) $r['user_id'], $rows);
    sort($users);
    $expected = [$t1, $o2]; sort($expected);
    TestLog::assertSame('دو ساکن', $expected, $users);
});

// ------------------------------------------------------------ واحدهای خاص

TestLog::run('صدور برای واحدهای خاص فقط همان واحدها', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000021'); $o2 = make_user('09132000022'); $t3 = make_user('09132000023');
    $u1 = make_unit($b, '1', ['owner_user_id' => $o1]);
    $u2 = make_unit($b, '2', ['owner_user_id' => $o2]);
    $u3 = make_unit($b, '3', ['tenant_user_id' => $t3]); // بدون مالک → مستأجر پرداخت می‌کند
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'درب پارکینگ', 'amount' => 120000,
        'target_audience' => 'specific_units', 'target_unit_ids' => [$u1, $u3],
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $users = array_map(fn($r) => (int) $r['user_id'], $rows);
    sort($users);
    $expected = [$o1, $t3]; sort($expected);
    TestLog::assertSame('مالک واحد۱ و مستأجر واحد۳', $expected, $users);
    TestLog::assertSame('واحد انتخاب‌نشده حذف است', 2, count($rows));
    TestLog::assertSame('سهم مساوی', 60000.0, (float) $rows[0]['share_amount']);
});

// ------------------------------------------------------------ اجازه دسترسی

TestLog::run('غیرمدیر نمی‌تواند صادر کند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $stranger = make_user('09132000024');
    make_unit($b, '1', ['owner_user_id' => $manager]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'آسانسور', 'amount' => 50000, 'target_audience' => 'owners'], $manager);
    TestLog::assertThrows('کاربر عادی', fn() => $svc->issueCost((int) $cost->id, $stranger), 'مدیر');
});

// ------------------------------------------------------------ پرداخت بدون ساخت ردیف تکراری

TestLog::run('پرداخت ساکن ردیف صادرشده را به‌روز می‌کند نه تکرار', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000025');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'رنگ', 'amount' => 40000, 'target_audience' => 'owners'], $manager);
    $svc->issueCost((int) $cost->id, $manager);

    $payment = $svc->submitPayment(['cost_id' => (int) $cost->id, 'amount_paid' => 40000], $o1, receipt_png_bytes(), 'fish.png');
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('یک ردیف باقی می‌ماند', 1, count($rows));
    TestLog::assertSame('با فیش، مستقیم در انتظار تأیید مدیر', 'pending', $payment->status);
    TestLog::assertSame('مبلغ پرداخت ثبت شد', 40000.0, (float) $rows[0]['amount_paid']);
});

// ------------------------------------------------------------ تأیید پرداخت بدون مبلغ

TestLog::run('تأیید پرداختِ بدون مبلغ، سهم را لحاظ می‌کند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000026');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'شارژ ویژه', 'amount' => 70000, 'target_audience' => 'owners'], $manager);
    $svc->issueCost((int) $cost->id, $manager);

    $paymentId = (int) cost_payments_of((int) $cost->id)[0]['id'];
    $svc->confirmPayment($paymentId, $manager);
    $row = cost_payments_of((int) $cost->id)[0];
    TestLog::assertSame('تأیید شد', 'confirmed', $row['status']);
    TestLog::assertSame('مبلغ از سهم پر شد', 70000.0, (float) $row['amount_paid']);
});

// ------------------------------------------------------------ محافظ تغییر مخاطب پس از پرداخت

TestLog::run('تغییر مخاطب پس از ثبت پرداخت مسدود می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000027'); $o2 = make_user('09132000028');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    make_unit($b, '2', ['owner_user_id' => $o2]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'نما', 'amount' => 100000, 'target_audience' => 'owners'], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $svc->submitPayment(['cost_id' => (int) $cost->id, 'amount_paid' => 50000], $o1, receipt_png_bytes(), 'fish.png');

    TestLog::assertThrows('تغییر مخاطب ممنوع', fn() => $svc->updateCost((int) $cost->id, [
        'target_audience' => 'tenants',
    ], $manager), 'پرداخت');
});

// ------------------------------------------------------------ اعلان صدور

TestLog::run('برای هر پرداخت‌کننده اعلان پرداخت ثبت می‌شود', function () use ($svc, $manager, $db) {
    $b = make_building($manager);
    $o1 = make_user('09132000029'); $o2 = make_user('09132000030');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    make_unit($b, '2', ['owner_user_id' => $o2]);
    $cost = $svc->createCost(['building_id' => $b, 'title' => 'دوربین', 'amount' => 100000, 'target_audience' => 'owners'], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE building_id = ? AND notification_type = 'payment'");
    $stmt->execute([$b]);
    TestLog::assertSame('دو اعلان', 2, (int) $stmt->fetchColumn());
});

// ------------------------------------------------------------ چرخه تأیید/رد پرداخت و حسابداری واحد

TestLog::run('رد پرداخت فقط با دلیل و فقط توسط مدیر', function () use ($svc, $manager, $db) {
    $b = make_building($manager);
    $o1 = make_user('09132000040');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'نگهبانی', 'amount' => 80000, 'target_audience' => 'owners',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $pid = (int) $rows[0]['id'];

    TestLog::assertThrows('بدون دلیل مجاز نیست', fn() => $svc->rejectPayment($pid, $manager, ''), 'دلیل');
    TestLog::assertThrows('غیرمدیر نمی‌تواند رد کند', fn() => $svc->rejectPayment($pid, $o1, 'تست'), 'مدیر');

    TestLog::assertTrue('رد با دلیل موفق', $svc->rejectPayment($pid, $manager, 'واریز نشده'));
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('وضعیت رد شد', 'rejected', $rows[0]['status']);
    TestLog::assertSame('دلیل ذخیره شد', 'واریز نشده', $rows[0]['reject_reason']);

    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE building_id = ? AND title LIKE '%رد شد%'");
    $stmt->execute([$b]);
    TestLog::assertTrue('اعلان رد برای پرداخت‌کننده ثبت شد', (int) $stmt->fetchColumn() >= 1);

    // پرداخت‌کننده پس از رد می‌تواند دوباره پرداخت/رسید ثبت کند
    $p = $svc->submitPayment(['payment_id' => $pid, 'amount_paid' => 80000], $o1, receipt_png_bytes(), 'fish.png');
    TestLog::assertSame('با فیش، برای تأیید مدیر آماده است', 'pending', $p->status);
});

TestLog::run('مانده واحد: بدهکار، طلبکار و دسترسی اعضا', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000041');
    $o2 = make_user('09132000042');
    $u1 = make_unit($b, '1', ['owner_user_id' => $o1]);
    $u2 = make_unit($b, '2', ['owner_user_id' => $o2]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'ایزوگام', 'amount' => 100000, 'target_audience' => 'owners',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $byUser = [];
    foreach ($rows as $r) {
        $byUser[(int) $r['user_id']] = (int) $r['id'];
    }

    // واحد ۱ بیشتر از سهمش پرداخت و تأیید می‌کند → طلبکار؛ واحد ۲ هیچ → بدهکار
    $svc->submitPayment(['payment_id' => $byUser[$o1], 'amount_paid' => 60000], $o1, receipt_png_bytes(), 'fish.png');
    $svc->confirmPayment($byUser[$o1], $manager);

    $balances = $svc->getUnitBalances($b, $manager);
    TestLog::assertSame('واحد ۱ طلبکار', 'creditor', $balances[$u1]['state']);
    TestLog::assertSame('مانده واحد ۱', 10000.0, $balances[$u1]['balance']);
    TestLog::assertSame('واحد ۲ بدهکار', 'debtor', $balances[$u2]['state']);
    TestLog::assertSame('مانده واحد ۲', -50000.0, $balances[$u2]['balance']);

    $stranger = make_user('09132000043');
    TestLog::assertThrows('غیرعضو به مانده‌ها دسترسی ندارد', fn() => $svc->getUnitBalances($b, $stranger), 'عضو این ساختمان');
});

TestLog::run('سهم‌ها واحد-محور و جمعشان دقیقاً برابر مبلغ هزینه است', function () use ($svc, $manager) {
    $b = make_building($manager);
    make_unit($b, '1', ['owner_user_id' => make_user('09132000045')]);
    make_unit($b, '2', ['owner_user_id' => make_user('09132000046')]);
    make_unit($b, '3', ['owner_user_id' => make_user('09132000047')]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'رنگ', 'amount' => 100000, 'target_audience' => 'owners',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);

    $rows = cost_payments_of((int) $cost->id);
    $sum = 0.0;
    $unitIds = [];
    foreach ($rows as $r) {
        $sum += (float) $r['share_amount'];
        $unitIds[] = $r['unit_id'];
    }
    TestLog::assertSame('سه ردیف واحد-محور', 3, count($rows));
    TestLog::assertSame('هر ردیف به واحد منتسب است', [], array_values(array_filter($unitIds, fn($v) => $v === null)));
    TestLog::assertSame('جمع سهم‌ها = مبلغ هزینه', 100000.0, round($sum, 2));
});

// ------------------------------------------------------------ شارژ ترکیبی (ثابت + نفری) در صدور ماهانه

TestLog::run('شارژ ترکیبی: سهم هر واحد = ثابت + نفری در شارژ ماهانه', function () use ($svc, $manager) {
    $b = make_building($manager, [
        'charge_mode' => 'combined', 'monthly_charge' => 200000, 'charge_per_person' => 50000,
        'monthly_charge_enabled' => 1,
    ]);
    $o1 = make_user('09132000060');
    $o2 = make_user('09132000061');
    $u1 = make_unit($b, '1', ['owner_user_id' => $o1, 'residents_count' => 2]);
    $u2 = make_unit($b, '2', ['owner_user_id' => $o2, 'residents_count' => 0]);

    $cost = $svc->createMonthlyCharge($b, $manager);
    TestLog::assertSame('مبلغ کل: (200+100) + 200', 500000.0, (float) $cost->amount);

    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('دو ردیف پرداخت', 2, count($rows));
    $byUnit = [];
    foreach ($rows as $r) {
        $byUnit[(int) $r['unit_id']] = (float) $r['share_amount'];
    }
    TestLog::assertSame('واحد ۲ نفره: ۲۰۰هزار + ۲×۵۰هزار', 300000.0, $byUnit[$u1] ?? 0.0);
    TestLog::assertSame('واحد خالی: فقط ثابت', 200000.0, $byUnit[$u2] ?? 0.0);
});

// ------------------------------------------------------------ اقدام گروهی روی پرداخت‌ها (تأیید/رد یک‌جا)

TestLog::run('تأیید گروهی: همهٔ پرداخت‌های انتخاب‌شده تأیید می‌شوند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000070'); $o2 = make_user('09132000071');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    make_unit($b, '2', ['owner_user_id' => $o2]);

    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'هزینه گروهی', 'amount' => 200000,
        'target_audience' => 'owners', 'division_method' => 'fixed_share',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('دو ردیف صادر شده', 2, count($rows));

    $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
    $result = $svc->bulkConfirmPayments($ids, $manager);
    TestLog::assertSame('هر دو تأیید شدند', 2, $result['processed']);
    TestLog::assertSame('مورد ناموفق نداریم', 0, $result['failed']);

    $after = cost_payments_of((int) $cost->id);
    foreach ($after as $row) {
        TestLog::assertSame('وضعیت نهایی تأیید است', 'confirmed', $row['status']);
        TestLog::assertTrue('مبلغ سهم به‌عنوان پرداختی ثبت شده', (float) $row['amount_paid'] === 100000.0);
    }
});

TestLog::run('تأیید مجدد پرداخت تأییدشده خطا می‌دهد و در شمارش ناموفق می‌نشیند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o = make_user('09132000072');
    make_unit($b, '1', ['owner_user_id' => $o]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'یک ردیفی', 'amount' => 50000,
        'target_audience' => 'owners', 'division_method' => 'fixed_share',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $ids = [(int) $rows[0]['id']];

    $svc->bulkConfirmPayments($ids, $manager);
    $second = $svc->bulkConfirmPayments($ids, $manager);
    TestLog::assertSame('تأیید مجدد ناموفق است', 0, $second['processed']);
    TestLog::assertSame('یک خطا گزارش شده', 1, $second['failed']);
    TestLog::assertTrue('پیام خطا به تأیید قبلی اشاره دارد', str_contains(implode('، ', $second['errors']), 'قبلاً تأیید'));
});

TestLog::run('رد گروهی: دلیل مشترک روی همه ثبت می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09132000073'); $o2 = make_user('09132000074');
    make_unit($b, '1', ['owner_user_id' => $o1]);
    make_unit($b, '2', ['owner_user_id' => $o2]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'رد گروهی', 'amount' => 120000,
        'target_audience' => 'owners', 'division_method' => 'fixed_share',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);
    $ids = array_map(static fn ($r) => (int) $r['id'], $rows);

    $result = $svc->bulkRejectPayments($ids, $manager, 'مبلغ واریز نشده');
    TestLog::assertSame('هر دو رد شدند', 2, $result['processed']);
    foreach (cost_payments_of((int) $cost->id) as $row) {
        TestLog::assertSame('وضعیت رد', 'rejected', $row['status']);
        TestLog::assertSame('دلیل رد ثبت شده', 'مبلغ واریز نشده', $row['reject_reason']);
    }
});

TestLog::run('غیرمدیر نمی‌تواند پرداخت را تأیید کند (حتی گروهی)', function () use ($svc) {
    $manager = make_user('09132000075', 'مدیر ساختمان الف');
    $outsider = make_user('09132000076', 'ساکن ساختمان دیگر');
    $b = make_building($manager);
    $o = make_user('09132000077');
    make_unit($b, '1', ['owner_user_id' => $o]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'محرمانه', 'amount' => 10000,
        'target_audience' => 'owners', 'division_method' => 'fixed_share',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $rows = cost_payments_of((int) $cost->id);

    TestLog::assertThrows('تأیید تک‌موردی توسط غیرمدیر', fn () => $svc->confirmPayment((int) $rows[0]['id'], $outsider), 'مدیر');
    $result = $svc->bulkConfirmPayments([(int) $rows[0]['id']], $outsider);
    TestLog::assertSame('تأیید گروهی توسط غیرمدیر ناموفق است', 0, $result['processed']);
    TestLog::assertSame('وضعیت دست‌نخورده می‌ماند', 'pending', cost_payments_of((int) $cost->id)[0]['status']);
});

// ------------------------------------------------------------ فیش واریزی اجباری + ذخیره در پوشهٔ ساختمان/واحد

/** بایت‌های یک پیکسل تصویر PNG معتبر برای تست آپلود */
function receipt_png_bytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

/** ساختمان با مالک و هزینهٔ صادرشده برای تست‌های رسید می‌سازد */
function receipt_fixture(CostService $svc, int $manager, string $phone): array
{
    $b = make_building($manager);
    $owner = make_user($phone);
    $unit = make_unit($b, '7', ['owner_user_id' => $owner]);
    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'هزینهٔ رسید', 'amount' => 80000,
        'target_audience' => 'owners', 'division_method' => 'fixed_share',
    ], $manager);
    $svc->issueCost((int) $cost->id, $manager);
    $row = cost_payments_of((int) $cost->id)[0];
    return [$b, $owner, $unit, (int) $cost->id, (int) $row['id']];
}

TestLog::run('ثبت پرداخت بدون فیش واریزی رد می‌شود', function () use ($svc, $manager) {
    [, $owner, , $costId, $payId] = receipt_fixture($svc, $manager, '09132000086');
    TestLog::assertThrows('بدون فیش خطا می‌دهد', fn () => $svc->submitPayment([
        'payment_id' => $payId, 'amount_paid' => 80000,
    ], $owner, null, null), 'فیش واریزی الزامی');
    $row = cost_payments_of($costId)[0];
    TestLog::assertSame('وضعیت دست‌نخورده می‌ماند', 'pending', $row['status']);
    TestLog::assertTrue('رسیدی ذخیره نشده', empty($row['receipt_path']));
});

TestLog::run('ثبت پرداخت با فیش: وضعیت در انتظار + فایل در پوشهٔ ساختمان/واحد', function () use ($svc, $manager) {
    [$b, $owner, $unit, $costId, $payId] = receipt_fixture($svc, $manager, '09132000087');

    $payment = $svc->submitPayment([
        'payment_id' => $payId, 'amount_paid' => 80000, 'notes' => 'کارت به کارت',
    ], $owner, receipt_png_bytes(), 'fish-varizi.png');

    TestLog::assertSame('وضعیت در انتظار تأیید مدیر', 'pending', $payment->status);
    TestLog::assertTrue('مسیر رسید ثبت شده', is_string($payment->receipt_path) && $payment->receipt_path !== '');
    TestLog::assertTrue('فایل واقعاً روی دیسک ساخته شده', is_file((string) $payment->receipt_path));
    TestLog::assertSame('محتوای فایل دقیقاً همان تصویر ارسالی است', receipt_png_bytes(), file_get_contents((string) $payment->receipt_path));

    $expectedDir = '/buildings/' . $b . '/receipts/unit-7/';
    TestLog::assertTrue('در پوشهٔ همان ساختمان و همان واحد ذخیره شده: ' . (string) $payment->receipt_path, str_contains((string) $payment->receipt_path, $expectedDir));

    // متادیتای جدول receipts
    $stmt = test_db()->prepare('SELECT * FROM receipts WHERE cost_payment_id = ?');
    $stmt->execute([$payId]);
    $meta = $stmt->fetch(PDO::FETCH_ASSOC);
    TestLog::assertTrue('ردیف متادیتای رسید ساخته شده', is_array($meta));
    TestLog::assertSame('نام اصلی فایل ذخیره شده', 'fish-varizi.png', $meta['original_name']);
    TestLog::assertSame('mime تصویر شناسایی شده', 'image/png', $meta['mime_type']);
    TestLog::assertSame('حجم فایل درست ثبت شده', strlen(receipt_png_bytes()), (int) $meta['file_size']);

    $row = test_db()->query("SELECT * FROM cost_payments WHERE id = {$payId}")->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('مسیر رسید روی ردیف پرداخت هم نشست', $payment->receipt_path, $row['receipt_path']);
    TestLog::assertSame('مبلغ پرداختی ثبت شده', 80000.0, (float) $row['amount_paid']);
});

TestLog::run('ثبت مجدد پرداخت، همان ردیف را به‌روز می‌کند و رسید جایگزین می‌شود', function () use ($svc, $manager) {
    [, $owner, , , $payId] = receipt_fixture($svc, $manager, '09132000088');
    $first = $svc->submitPayment(['payment_id' => $payId, 'amount_paid' => 50000], $owner, receipt_png_bytes(), 'a.png');
    $second = $svc->submitPayment(['payment_id' => $payId, 'amount_paid' => 60000, 'notes' => 'مبلغ اصلاح شد'], $owner, receipt_png_bytes(), 'b.png');

    TestLog::assertSame('ردیف تکراری ساخته نمی‌شود', (int) $first->id, (int) $second->id);
    TestLog::assertSame('مبلغ اصلاح شده', 60000.0, (float) $second->amount_paid);
    $count = (int) test_db()->query('SELECT COUNT(*) FROM receipts WHERE cost_payment_id = ' . $payId)->fetchColumn();
    TestLog::assertSame('متادیتای رسید هم یک ردیف می‌ماند', 1, $count);
});

TestLog::run('آپلود مجدد پس از ردشدن: وضعیت به در انتظار بازمی‌گردد', function () use ($svc, $manager) {
    [$b, $owner, , $costId, $payId] = receipt_fixture($svc, $manager, '09132000089');
    $svc->submitPayment(['payment_id' => $payId, 'amount_paid' => 80000], $owner, receipt_png_bytes(), 'a.png');
    $svc->rejectPayment($payId, $manager, 'مبلغ کمتر از سهم است');

    $path = $svc->uploadReceipt($payId, receipt_png_bytes(), 'fish-dobare.png', $owner);
    TestLog::assertTrue('رسید جدید ذخیره شد', is_string($path) && is_file((string) $path));
    TestLog::assertTrue('باز هم در پوشهٔ ساختمان/واحد است', str_contains((string) $path, '/buildings/' . $b . '/receipts/unit-7/'));
    $row = test_db()->query("SELECT status FROM cost_payments WHERE id = {$payId}")->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('وضعیت برای تأیید دوباره', 'pending', $row['status']);
});

TestLog::run('غیرمالک نمی‌تواند برای پرداخت دیگران رسید بفرستد', function () use ($svc, $manager) {
    [, , , , $payId] = receipt_fixture($svc, $manager, '09132000090');
    $stranger = make_user('09132000091', 'غریبه');
    TestLog::assertThrows('دسترسی رد می‌شود', fn () => $svc->uploadReceipt($payId, receipt_png_bytes(), 'x.png', $stranger), 'access denied');
    TestLog::assertThrows('ثبت پرداخت غریبه هم رد می‌شود', fn () => $svc->submitPayment(['payment_id' => $payId], $stranger, receipt_png_bytes(), 'x.png'), 'access denied');
});

TestLog::run('پرداخت تأییدشده دیگر قابل تغییر رسید نیست', function () use ($svc, $manager) {
    [, $owner, , , $payId] = receipt_fixture($svc, $manager, '09132000092');
    $svc->submitPayment(['payment_id' => $payId, 'amount_paid' => 80000], $owner, receipt_png_bytes(), 'a.png');
    $svc->confirmPayment($payId, $manager);
    TestLog::assertThrows('آپلود پس از تأیید مسدود است', fn () => $svc->uploadReceipt($payId, receipt_png_bytes(), 'b.png', $owner), 'تأییدشده');
});

TestLog::run('فایل غیرتصویری رد می‌شود', function () use ($svc, $manager) {
    [, $owner, , , $payId] = receipt_fixture($svc, $manager, '09132000093');
    TestLog::assertThrows('محتوای متنی به‌جای تصویر پذیرفته نمی‌شود', fn () => $svc->submitPayment(
        ['payment_id' => $payId, 'amount_paid' => 80000], $owner, 'این تصویر نیست', 'script.php'
    ), 'Invalid file type');
});

TestLog::run('پرداخت مستقیم مدیر نیازی به فیش ندارد (مسیر جداگانه)', function () use ($svc, $manager) {
    $b = make_building($manager);
    $owner = make_user('09132000095');
    $u = make_unit($b, '3', ['owner_user_id' => $owner]);
    $payment = $svc->recordDirectPayment($b, $u, 45000, 'دریافت نقدی', $manager);
    TestLog::assertSame('مستقیم تأیید می‌شود', 'confirmed', $payment->status);
    TestLog::assertTrue('بدون مسیر رسید', $payment->receipt_path === null || $payment->receipt_path === '');
});

TestLog::run('تأیید پرداخت دارای فیش، مبلغ را به حساب واحد می‌نشاند', function () use ($svc, $manager) {
    [$b, $owner, $unit, $costId, $payId] = receipt_fixture($svc, $manager, '09132000094');
    $svc->submitPayment(['payment_id' => $payId, 'amount_paid' => 80000], $owner, receipt_png_bytes(), 'a.png');
    $svc->confirmPayment($payId, $manager);

    $row = test_db()->query("SELECT * FROM cost_payments WHERE id = {$payId}")->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('وضعیت نهایی تأیید', 'confirmed', $row['status']);
    TestLog::assertSame('مسیر رسید پس از تأیید حفظ می‌شود', true, str_contains((string) $row['receipt_path'], '/buildings/' . $b . '/receipts/unit-7/'));
    TestLog::assertTrue('توسط مدیر تأیید شده', (int) $row['confirmed_by'] === $manager);

    $ledger = $svc->getBuildingLedger($b, $manager);
    TestLog::assertSame('بدهی واحد پس از پرداخت کامل صفر می‌شود', 0.0, round((float) $ledger['units'][$unit]['balance'], 2));
});
