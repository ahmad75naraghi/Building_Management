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

    $payment = $svc->submitPayment(['cost_id' => (int) $cost->id, 'amount_paid' => 40000], $o1);
    $rows = cost_payments_of((int) $cost->id);
    TestLog::assertSame('یک ردیف باقی می‌ماند', 1, count($rows));
    TestLog::assertSame('وضعیت در انتظار رسید', 'upload_receipt', $payment->status);
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
    $svc->submitPayment(['cost_id' => (int) $cost->id, 'amount_paid' => 50000], $o1);

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
