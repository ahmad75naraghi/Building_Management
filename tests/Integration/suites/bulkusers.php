<?php
/** تست ساخت گروهی کاربران و اتصال به واحدها */
declare(strict_types=1);

use App\Services\BulkUserService;

TestLog::suite('Bulk Users — ساخت گروهی کاربران و انتساب واحد');

$svc = new BulkUserService();

function bulk_unit_owner(int $unitId): ?int
{
    $stmt = test_db()->prepare("SELECT owner_user_id FROM units WHERE id = ?");
    $stmt->execute([$unitId]);
    $v = $stmt->fetchColumn();
    return $v === null || $v === false ? null : (int) $v;
}

function bulk_unit_tenant(int $unitId): ?int
{
    $stmt = test_db()->prepare("SELECT tenant_user_id FROM units WHERE id = ?");
    $stmt->execute([$unitId]);
    $v = $stmt->fetchColumn();
    return $v === null || $v === false ? null : (int) $v;
}

function bulk_member_role(int $buildingId, int $userId): ?string
{
    $stmt = test_db()->prepare("SELECT role FROM building_members WHERE building_id = ? AND user_id = ?");
    $stmt->execute([$buildingId, $userId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

TestLog::run('ساخت کاربر جدید + عضویت + اتصال مالک/مستاجر به واحد', function () use ($svc) {
    $manager = make_user('09137000001', 'مدیر مجتمع');
    $b = make_building($manager);
    $u1 = make_unit($b, '101');
    $u2 = make_unit($b, '202');

    $out = $svc->createBulk($b, [
        ['name' => 'علی محمدی', 'phone' => '09137000111', 'unit_number' => '101', 'role' => 'owner', 'password' => 'pass1234'],
        ['name' => 'مریم احمدی', 'phone' => '۰۹۱۳۷۰۰۰۲۲۲', 'unit_number' => '202', 'role' => 'tenant'],
        ['name' => 'بدون واحد', 'phone' => '09137000333', 'role' => 'resident'],
    ], $manager, false);

    TestLog::assertSame('سه کاربر ساخته شد', 3, $out['summary']['created']);
    TestLog::assertSame('هیچ خطایی نبود', 0, $out['summary']['failed']);

    $results = $out['results'];
    $ali = array_values(array_filter($results, fn($r) => $r['phone'] === '09137000111'))[0];
    $mary = array_values(array_filter($results, fn($r) => str_contains($r['message'] ?? '', 'ساخته شد') && $r['unit'] === '202'))[0];

    TestLog::assertSame('وضعیت ایجاد', 'created', $ali['status']);
    TestLog::assertSame('مالک واحد ۱۰۱ شد', $ali['user_id'], bulk_unit_owner($u1));
    TestLog::assertSame('مستاجر واحد ۲۰۲ شد', $mary['user_id'], bulk_unit_tenant($u2));
    TestLog::assertSame('عضویت با نقش مالک', 'owner', bulk_member_role($b, (int) $ali['user_id']));
    TestLog::assertSame('کاربر بدون واحد هم عضو شد', 'resident', bulk_member_role($b, (int) ($results[2]['user_id'] ?? 0)));

    // مالکِ بدون مستاجر → ساکن تلقی می‌شود
    $stmt = test_db()->prepare("SELECT owner_resident FROM units WHERE id = ?");
    $stmt->execute([$u1]);
    TestLog::assertSame('مالک ساکن علامت خورد', 1, (int) $stmt->fetchColumn());
});

TestLog::run('اعتبارسنجی ردیف‌ها: موبایل، رمز، واحد، تکرار در لیست', function () use ($svc) {
    $manager = make_user('09137000010', 'مدیر دو');
    $b = make_building($manager);
    make_unit($b, '1');

    $out = $svc->createBulk($b, [
        ['name' => 'موبایل بد', 'phone' => '123', 'role' => 'owner'],
        ['name' => 'رمز کوتاه', 'phone' => '09137000444', 'password' => '123', 'role' => 'resident'],
        ['name' => 'واحد اشتباه', 'phone' => '09137000555', 'unit_number' => '999', 'role' => 'owner'],
        ['name' => 'نخستین تکراری', 'phone' => '09137000666', 'role' => 'resident'],
        ['name' => 'دومین تکراری', 'phone' => '09137000666', 'role' => 'resident'],
        ['name' => '', 'phone' => '09137000777', 'role' => 'resident'],
    ], $manager, false);

    TestLog::assertSame('پنج ردیف بد خطا خوردند', 5, $out['summary']['failed']);
    TestLog::assertSame('فقط نخستین شمارهٔ تکراری ساخته شد', 1, $out['summary']['created']);
    $msgs = array_map(fn($r) => $r['message'], $out['results']);
    TestLog::assertTrue('پیام خطای موبایل', (bool) preg_grep('/معتبر/', $msgs));
    TestLog::assertTrue('پیام خطای واحد', (bool) preg_grep('/پیدا نشد/', $msgs));
    TestLog::assertTrue('پیام خطای تکرار در لیست', (bool) preg_grep('/ردیف/', $msgs));
});

TestLog::run('واحد پُر بدون جایگزینی رد و با جایگزینی قبول می‌شود', function () use ($svc) {
    $manager = make_user('09137000020', 'مدیر سه');
    $b = make_building($manager);
    $u1 = make_unit($b, '1');

    $first = $svc->createBulk($b, [
        ['name' => 'مالک اول', 'phone' => '09137000888', 'unit_number' => '1', 'role' => 'owner'],
    ], $manager, false);
    $firstOwner = (int) $first['results'][0]['user_id'];

    $blocked = $svc->createBulk($b, [
        ['name' => 'مالک دوم', 'phone' => '09137000999', 'unit_number' => '1', 'role' => 'owner'],
    ], $manager, false);
    TestLog::assertSame('بدون force رد شد', 1, $blocked['summary']['skipped']);
    TestLog::assertSame('مالک قبلی حفظ شد', $firstOwner, bulk_unit_owner($u1));

    $replaced = $svc->createBulk($b, [
        ['name' => 'مالک دوم', 'phone' => '09137000999', 'unit_number' => '1', 'role' => 'owner'],
    ], $manager, true);
    // کاربر در فراخوان قبلی ساخته شده بود؛ حالا متصل و جایگزین می‌شود
    TestLog::assertSame('با force متصل و ردیف موفق شد', 'linked', $replaced['results'][0]['status']);
    TestLog::assertSame('مالک جایگزین شد', (int) $replaced['results'][0]['user_id'], bulk_unit_owner($u1));
});

TestLog::run('شمارهٔ تکراری به کاربر موجود متصل می‌شود (بدون ساخت مجدد)', function () use ($svc) {
    $manager = make_user('09137000030', 'مدیر چهار');
    $b = make_building($manager);
    $u1 = make_unit($b, '5');
    $existing = make_user('09137001000', 'کاربر موجود');

    $before = (int) test_db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $out = $svc->createBulk($b, [
        ['name' => 'کاربر موجود', 'phone' => '09137001000', 'unit_number' => '5', 'role' => 'tenant'],
    ], $manager, false);

    TestLog::assertSame('وضعیت متصل‌شدن', 'linked', $out['results'][0]['status']);
    TestLog::assertSame('کاربر جدیدی ساخته نشد', $before, (int) test_db()->query("SELECT COUNT(*) FROM users")->fetchColumn());
    TestLog::assertSame('به واحد متصل شد', $existing, bulk_unit_tenant($u1));
    TestLog::assertSame('عضویت ایجاد شد', 'tenant', bulk_member_role($b, $existing));
});

TestLog::run('نقش فارسی پذیرفته می‌شود و سقف ردیف کنترل می‌شود', function () use ($svc) {
    $manager = make_user('09137000040', 'مدیر پنج');
    $b = make_building($manager);
    $u1 = make_unit($b, '9');

    $out = $svc->createBulk($b, [
        ['name' => 'نقش فارسی', 'phone' => '09137001100', 'unit_number' => '9', 'role' => 'مالک'],
    ], $manager, false);
    TestLog::assertSame('با نقش «مالک» فارسی ساخته شد', 'created', $out['results'][0]['status']);
    TestLog::assertSame('به واحد وصل شد', (int) $out['results'][0]['user_id'], bulk_unit_owner($u1));

    TestLog::assertThrows('بیش از سقف ردیف رد می‌شود', fn() => $svc->createBulk(
        $b,
        array_fill(0, BulkUserService::MAX_ROWS + 1, ['name' => 'x', 'phone' => '09137001200', 'role' => 'resident']),
        $manager,
        false
    ), 'ردیف');
});
