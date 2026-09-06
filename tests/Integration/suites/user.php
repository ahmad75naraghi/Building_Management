<?php
/** تست جریان ورود/ثبت‌نام با شماره موبایل */
declare(strict_types=1);

use App\Services\UserService;

TestLog::suite('UserService — ورود و ثبت‌نام با شماره');

$db = test_db();
$svc = new UserService();

// ---------------------------------------------------------- phoneStatus

TestLog::run('وضعیت شماره ثبت‌نشده', function () use ($svc) {
    $s = $svc->phoneStatus('09121000001');
    TestLog::assertSame('exists=false', false, $s['exists']);
    TestLog::assertSame('has_password=false', false, $s['has_password']);
    TestLog::assertSame('گام بعدی otp', 'otp', $s['next']);
});

TestLog::run('وضعیت کاربر کامل (نام + رمز)', function () use ($svc) {
    make_user('09121000002', 'زهرا احمدی', 'secret123');
    $s = $svc->phoneStatus('09121000002');
    TestLog::assertSame('exists=true', true, $s['exists']);
    TestLog::assertSame('has_password=true', true, $s['has_password']);
    TestLog::assertSame('has_name=true', true, $s['has_name']);
    TestLog::assertSame('گام بعدی password', 'password', $s['next']);
});

TestLog::run('کاربر بدون رمز → otp', function () use ($svc) {
    make_user('09121000003', 'مهدی کریمی', null);
    $s = $svc->phoneStatus('09121000003');
    TestLog::assertSame('has_password=false', false, $s['has_password']);
    TestLog::assertSame('گام بعدی otp', 'otp', $s['next']);
});

TestLog::run('شماره با ارقام فارسی همان کاربر را پیدا می‌کند', function () use ($svc) {
    $s = $svc->phoneStatus('۰۹۱۲۱۰۰۰۰۰۲');
    TestLog::assertSame('کاربر پیدا شد', true, $s['exists']);
});

TestLog::run('شماره نامعتبر رد می‌شود', function () use ($svc) {
    TestLog::assertThrows('phoneStatus نامعتبر', fn() => $svc->phoneStatus('0912'), 'معتبر نیست');
});

// ---------------------------------------------------------- loginOrCreateByPhone

TestLog::run('ساخت کاربر جدید پس از تأیید کد', function () use ($svc, $db) {
    $r = $svc->loginOrCreateByPhone('09121000010');
    TestLog::assertSame('is_new', true, $r['is_new']);
    TestLog::assertSame('needs_name', true, $r['needs_name']);
    TestLog::assertSame('needs_password', true, $r['needs_password']);
    TestLog::assertTrue('شناسه گرفت', $r['user']->id > 0);

    $row = $db->query("SELECT * FROM users WHERE phone = '09121000010'")->fetch();
    TestLog::assertTrue('در دیتابیس ذخیره شد', $row !== false);
    TestLog::assertSame('نام تهی است', null, $row['name']);
    TestLog::assertSame('رمز تهی است', null, $row['password_hash']);
});

TestLog::run('ورود دوباره کاربر موجود، رکورد جدید نمی‌سازد', function () use ($svc, $db) {
    $before = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $r = $svc->loginOrCreateByPhone('09121000010');
    $after = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    TestLog::assertSame('is_new=false', false, $r['is_new']);
    TestLog::assertSame('تعداد کاربران ثابت ماند', $before, $after);
});

TestLog::run('کاربر کامل نیازی به گام‌های بعدی ندارد', function () use ($svc) {
    $r = $svc->loginOrCreateByPhone('09121000002');
    TestLog::assertSame('needs_name=false', false, $r['needs_name']);
    TestLog::assertSame('needs_password=false', false, $r['needs_password']);
});

TestLog::run('شماره نامعتبر رد می‌شود', function () use ($svc) {
    TestLog::assertThrows('loginOrCreateByPhone نامعتبر', fn() => $svc->loginOrCreateByPhone('abc'), 'معتبر نیست');
});

// ---------------------------------------------------------- completeName

TestLog::run('ثبت نام کامل', function () use ($svc, $db) {
    $u = $svc->loginOrCreateByPhone('09121000020')['user'];
    $svc->completeName((int) $u->id, '  رضا موسوی  ');
    $name = $db->query("SELECT name FROM users WHERE id = {$u->id}")->fetchColumn();
    TestLog::assertSame('نام ذخیره و trim شد', 'رضا موسوی', $name);
});

TestLog::run('نام کوتاه رد می‌شود', function () use ($svc) {
    $u = $svc->loginOrCreateByPhone('09121000021')['user'];
    TestLog::assertThrows('نام دو حرفی', fn() => $svc->completeName((int) $u->id, 'عل'), 'کامل وارد کنید');
    TestLog::assertThrows('نام خالی', fn() => $svc->completeName((int) $u->id, '   '), 'کامل وارد کنید');
});

TestLog::run('نام فارسی سه‌حرفی پذیرفته می‌شود (شمارش کاراکتری نه بایتی)', function () use ($svc) {
    $u = $svc->loginOrCreateByPhone('09121000022')['user'];
    $svc->completeName((int) $u->id, 'علی');
    TestLog::ok('نام سه‌حرفی فارسی پذیرفته شد');
});

TestLog::run('کاربر ناموجود', function () use ($svc) {
    TestLog::assertThrows('completeName کاربر ناموجود', fn() => $svc->completeName(999999, 'کاربر تست'), 'یافت نشد');
});

TestLog::run('شماره کاربر بعد از ثبت نام تغییر نمی‌کند', function () use ($svc, $db) {
    $u = $svc->loginOrCreateByPhone('09121000023')['user'];
    $svc->completeName((int) $u->id, 'نگار صادقی');
    $phone = $db->query("SELECT phone FROM users WHERE id = {$u->id}")->fetchColumn();
    TestLog::assertSame('شماره حفظ شد', '09121000023', $phone);
});

// ---------------------------------------------------------- setInitialPassword

TestLog::run('تعیین رمز اولیه', function () use ($svc, $db) {
    $u = $svc->loginOrCreateByPhone('09121000030')['user'];
    $svc->completeName((int) $u->id, 'سارا نوری');
    $svc->setInitialPassword((int) $u->id, 'abc123456', 'abc123456');
    $hash = $db->query("SELECT password_hash FROM users WHERE id = {$u->id}")->fetchColumn();
    TestLog::assertTrue('رمز هش شد', is_string($hash) && password_verify('abc123456', $hash));
    TestLog::assertTrue('رمز خام ذخیره نشد', $hash !== 'abc123456');
});

TestLog::run('رمز کوتاه رد می‌شود', function () use ($svc) {
    $u = $svc->loginOrCreateByPhone('09121000031')['user'];
    TestLog::assertThrows('کمتر از ۶ کاراکتر',
        fn() => $svc->setInitialPassword((int) $u->id, '123', '123'), '۶ کاراکتر');
});

TestLog::run('عدم تطابق تکرار رمز', function () use ($svc) {
    $u = $svc->loginOrCreateByPhone('09121000032')['user'];
    TestLog::assertThrows('تکرار نادرست',
        fn() => $svc->setInitialPassword((int) $u->id, 'abc123456', 'abc123457'), 'مطابقت ندارد');
});

TestLog::run('رمز دوباره تعیین نمی‌شود', function () use ($svc) {
    TestLog::assertThrows('کاربر دارای رمز',
        fn() => $svc->setInitialPassword((int) test_db()->query("SELECT id FROM users WHERE phone='09121000030'")->fetchColumn(),
            'newpass123', 'newpass123'), 'قبلاً رمز');
});

TestLog::run('کاربر ناموجود', function () use ($svc) {
    TestLog::assertThrows('setInitialPassword کاربر ناموجود',
        fn() => $svc->setInitialPassword(999999, 'abc123456', 'abc123456'), 'یافت نشد');
});

// ---------------------------------------------------------- authenticate

TestLog::run('ورود با رمز درست', function () use ($svc) {
    $u = $svc->authenticate('09121000002', 'secret123');
    TestLog::assertTrue('کاربر برگشت', $u !== null);
    TestLog::assertSame('شماره درست', '09121000002', $u?->phone);
});

TestLog::run('ورود با رمز اشتباه', function () use ($svc) {
    TestLog::assertSame('null برمی‌گرداند', null, $svc->authenticate('09121000002', 'wrong-pass'));
});

TestLog::run('ورود با شماره ثبت‌نشده', function () use ($svc) {
    TestLog::assertSame('null برمی‌گرداند', null, $svc->authenticate('09129998877', 'secret123'));
});

TestLog::run('ورود کاربر بدون رمز ممکن نیست', function () use ($svc) {
    TestLog::assertSame('null برمی‌گرداند', null, $svc->authenticate('09121000003', ''));
    TestLog::assertSame('با رمز دلخواه هم null', null, $svc->authenticate('09121000003', 'anything'));
});

TestLog::run('ورود با شماره فارسی', function () use ($svc) {
    TestLog::assertTrue('کاربر پیدا شد', $svc->authenticate('۰۹۱۲۱۰۰۰۰۰۲', 'secret123') !== null);
});

// ---------------------------------------------------------- جریان کامل

TestLog::run('جریان کامل ثبت‌نام تازه‌وارد', function () use ($svc) {
    $phone = '09121000099';
    TestLog::assertSame('۱) گام otp', 'otp', $svc->phoneStatus($phone)['next']);

    $r = $svc->loginOrCreateByPhone($phone);
    TestLog::assertTrue('۲) کاربر ساخته شد', $r['is_new'] && $r['needs_name']);

    $svc->completeName((int) $r['user']->id, 'حسین قاسمی');
    TestLog::assertSame('۳) نام ثبت شد، هنوز رمز ندارد', 'otp', $svc->phoneStatus($phone)['next']);

    $svc->setInitialPassword((int) $r['user']->id, 'strongpass', 'strongpass');
    $s = $svc->phoneStatus($phone);
    TestLog::assertSame('۴) از این پس رمز پرسیده می‌شود', 'password', $s['next']);
    TestLog::assertTrue('۵) ورود با رمز کار می‌کند', $svc->authenticate($phone, 'strongpass') !== null);
});
