<?php
/** تست دعوت‌نامه‌ها: صدور، فهرست و لغو (با کنترل دسترسی مدیر) */
declare(strict_types=1);

use App\Services\InvitationService;

TestLog::suite('InvitationService — صدور و لغو دعوت‌نامه');

$svc = new InvitationService();
$manager = make_user('09132000080', 'مدیر دعوت‌ها');

TestLog::run('صدور دعوت‌نامه: وضعیت در انتظار و توکن یکتا', function () use ($svc, $manager) {
    $b = make_building($manager);
    $result = $svc->createInvitation([
        'building_id' => $b,
        'invited_name' => 'ساکن جدید',
        'invited_phone' => '09132000081',
        'role' => 'resident',
    ], $manager);
    TestLog::assertTrue('شناسهٔ دعوت‌نامه برگشته', ($result['invitation']->id ?? 0) > 0);
    TestLog::assertSame('وضعیت اولیه در انتظار است', 'pending', $result['invitation']->status);
    TestLog::assertTrue('توکن دعوت تولید شده', strlen((string) ($result['invitation']->token ?? '')) > 10);
});

TestLog::run('صدور بدون نام یا شمارهٔ معتبر رد می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    TestLog::assertThrows('نام الزامی است', fn () => $svc->createInvitation([
        'building_id' => $b, 'invited_phone' => '09132000082',
    ], $manager), 'نام خانوادگی');
    TestLog::assertThrows('شمارهٔ نامعتبر', fn () => $svc->createInvitation([
        'building_id' => $b, 'invited_name' => 'بدون شماره', 'invited_phone' => '123',
    ], $manager), 'شماره موبایل معتبر نیست');
});

TestLog::run('لغو دعوت‌نامه توسط مدیر: وضعیت لغوشده می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    $result = $svc->createInvitation([
        'building_id' => $b, 'invited_name' => 'لغوشونده', 'invited_phone' => '09132000083',
    ], $manager);
    $id = (int) $result['invitation']->id;
    $revoked = $svc->revokeInvitation($id, $manager);
    TestLog::assertSame('وضعیت لغوشده', 'revoked', $revoked->status);
});

TestLog::run('لغو توسط غیرمدیر مسدود است و لغو مجدد هم رد می‌شود', function () use ($svc, $manager) {
    $b = make_building($manager);
    $outsider = make_user('09132000084', 'بیرون از ساختمان');
    $result = $svc->createInvitation([
        'building_id' => $b, 'invited_name' => 'محافظت‌شده', 'invited_phone' => '09132000085',
    ], $manager);
    $id = (int) $result['invitation']->id;

    TestLog::assertThrows('غیرمدیر نمی‌تواند لغو کند', fn () => $svc->revokeInvitation($id, $outsider), 'مدیر');

    $svc->revokeInvitation($id, $manager);
    TestLog::assertThrows('لغو دوبارهٔ دعوت‌نامهٔ لغوشده', fn () => $svc->revokeInvitation($id, $manager), 'در انتظار');
});
