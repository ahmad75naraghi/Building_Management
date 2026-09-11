<?php
/** تست اعلان‌ها: پخش سراسری اطلاعیه/رأی‌گیری و اعلان دوطرفهٔ تیکت */
declare(strict_types=1);

use App\Services\ExtraModulesService;
use App\Services\TicketService;

TestLog::suite('اعلان‌ها — پخش سراسری و گفت‌وگوی تیکت');

/** شمارش اعلان‌های یک کاربر (با فیلتر نوع) */
function notif_count_for(int $userId, ?string $type = null): int
{
    $sql = "SELECT COUNT(*) FROM notifications WHERE user_id = {$userId}";
    if ($type !== null) {
        $sql .= " AND notification_type = '{$type}'";
    }
    return (int) test_db()->query($sql)->fetchColumn();
}

$svc = new ExtraModulesService();
$tickets = new TicketService();
$manager = make_user('09137000001', 'مدیر اعلان');
$resident = make_user('09137000002', 'ساکن اعلان');
$other = make_user('09137000003', 'ساکن دوم');

// ------------------------------------------------------------ اطلاعیه

TestLog::run('ثبت اطلاعیه برای همهٔ اعضا اعلان می‌سازد (جز ایجادکننده)', function () use ($svc, $manager, $resident, $other) {
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    add_member($b, $other, 'tenant');

    $before_r = notif_count_for($resident, 'announcement');
    $before_o = notif_count_for($other, 'announcement');
    $before_m = notif_count_for($manager, 'announcement');

    $svc->createAnnouncement([
        'building_id' => $b, 'title' => 'قطعی آب', 'content' => 'فردا از ساعت ۹ آب ساختمان قطع می‌شود.',
    ], $manager);

    TestLog::assertSame('ساکن اول اعلان گرفت', $before_r + 1, notif_count_for($resident, 'announcement'));
    TestLog::assertSame('ساکن دوم اعلان گرفت', $before_o + 1, notif_count_for($other, 'announcement'));
    TestLog::assertSame('ایجادکننده اعلان نمی‌گیرد', $before_m, notif_count_for($manager, 'announcement'));
});

// ------------------------------------------------------------ رأی‌گیری

TestLog::run('شروع رأی‌گیری برای همهٔ اعضا اعلان می‌سازد', function () use ($svc, $manager, $resident, $other) {
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    add_member($b, $other, 'tenant');

    $before = notif_count_for($resident, 'vote');
    $svc->createVote([
        'building_id' => $b, 'title' => 'نصب دوربین', 'options' => ['موافق', 'مخالف'],
    ], $manager);

    TestLog::assertSame('اعلان رأی‌گیری برای ساکن', $before + 1, notif_count_for($resident, 'vote'));
    TestLog::assertSame('اعلان رأی‌گیری برای ساکن دوم', $before + 1, notif_count_for($other, 'vote'));
});

// ------------------------------------------------------------ تیکت: پاسخ دوطرفه

TestLog::run('پاسخ مدیر به تیکت، به ساکن اعلان می‌دهد', function () use ($tickets, $manager, $resident) {
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    $ticket = $tickets->createTicket([
        'building_id' => $b, 'title' => 'خرابی آسانسور', 'description' => 'آسانسور کار نمی‌کند.',
    ], $resident);

    $before = notif_count_for($resident, 'ticket');
    $tickets->addComment((int) $ticket->id, ['comment' => 'پیگیری می‌شود.'], $manager);
    TestLog::assertSame('ساکن از پاسخ مدیر مطلع شد', $before + 1, notif_count_for($resident, 'ticket'));
});

TestLog::run('پاسخ ساکن در تیکت، به مدیر اعلان می‌دهد', function () use ($tickets, $manager, $resident) {
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    $ticket = $tickets->createTicket([
        'building_id' => $b, 'title' => 'چکه شیر', 'description' => 'شیر آشپزخانه چکه می‌کند.',
    ], $resident);

    $before = notif_count_for($manager, 'ticket');
    $tickets->addComment((int) $ticket->id, ['comment' => 'اطلاعات بیشتر اضافه کردم.'], $resident);
    TestLog::assertSame('مدیر از پاسخ ساکن مطلع شد', $before + 1, notif_count_for($manager, 'ticket'));
});

TestLog::run('یادداشت داخلی اعلان نمی‌سازد', function () use ($tickets, $manager, $resident) {
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    $ticket = $tickets->createTicket([
        'building_id' => $b, 'title' => 'نظافت', 'description' => 'راه‌پله کثیف است.',
    ], $resident);

    $before_r = notif_count_for($resident, 'ticket');
    $before_m = notif_count_for($manager, 'ticket');
    $tickets->addComment((int) $ticket->id, ['comment' => 'یادداشت داخلی مدیر', 'is_internal' => 1], $manager);
    TestLog::assertSame('ساکن اعلان نگرفت', $before_r, notif_count_for($resident, 'ticket'));
    TestLog::assertSame('مدیر هم اعلان نگرفت', $before_m, notif_count_for($manager, 'ticket'));
});

// ------------------------------------------------------------ تیکت: تغییر وضعیت

TestLog::run('تغییر وضعیت تیکت به ایجادکننده اعلان می‌دهد', function () use ($tickets, $manager, $resident) {
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    $ticket = $tickets->createTicket([
        'building_id' => $b, 'title' => 'سوختگی لامپ', 'description' => 'لامپ پارکینگ سوخته.',
    ], $resident);

    $before = notif_count_for($resident, 'ticket');
    $tickets->updateStatus((int) $ticket->id, 'resolved', null, $manager);
    TestLog::assertSame('اعلان حل‌شدن برای ساکن', $before + 1, notif_count_for($resident, 'ticket'));

    // تغییر وضعیت توسط خود ایجادکننده، به خودش اعلان نمی‌دهد
    $between = notif_count_for($resident, 'ticket');
    $tickets->updateStatus((int) $ticket->id, 'closed', null, $resident);
    TestLog::assertSame('تغییر توسط خود ساکن اعلان ندارد', $between, notif_count_for($resident, 'ticket'));
});

// ------------------------------------------------------------ پخش سراسری: اعضا و استثناها

TestLog::run('پخش سراسری فقط اعضای فعال و بدون استثناها', function () {
    $notifications = new \App\Services\NotificationService();
    $m = make_user('09137000010', 'مدیر پخش');
    $u1 = make_user('09137000011', 'عضو یک');
    $u2 = make_user('09137000012', 'عضو دو');
    $b = make_building($m);
    add_member($b, $u1, 'tenant');
    add_member($b, $u2, 'tenant');

    $sent = $notifications->broadcastToBuilding($b, 'general', 'سلام', 'پیام همگانی', [], [$u2]);
    TestLog::assertSame('دو اعلان ارسال شد (یکی استثنا)', 2, $sent);
    TestLog::assertSame('عضو یک گرفت', 1, notif_count_for($u1, 'general'));
    TestLog::assertSame('عضو استثنا نگرفت', 0, notif_count_for($u2, 'general'));
});
