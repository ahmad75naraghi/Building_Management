<?php
/** تست صندوق پیام درون‌اپی (چت مدیر ↔ ساکن) */
declare(strict_types=1);

use App\Services\MessageService;

TestLog::suite('MessageService — صندوق پیام درون‌اپی');

$svc = new MessageService();
$manager = make_user('09137000001', 'مدیر پیام‌ها');
$tenant = make_user('09137000002', 'ساکن پیام‌ها');
$outsider = make_user('09137000099', 'غریبه');
$b = make_building($manager);
add_member($b, $tenant);

TestLog::run('ارسال پیام بین اعضای ساختمان', function () use ($svc, $manager, $tenant, $b) {
    $m = $svc->send(['building_id' => $b, 'recipient_id' => $tenant, 'body' => 'سلام، شارژ را واریز کنید'], $manager);
    TestLog::assertTrue('شناسه ساخت شد', $m->id > 0);
    TestLog::assertSame('فرستنده', $manager, $m->sender_id);
    TestLog::assertSame('گیرنده', $tenant, $m->recipient_id);
    TestLog::assertSame('نخوانده', false, $m->is_read);
});

TestLog::run('گیرنده پیام را در فهرست گفتگوها می‌بیند', function () use ($svc, $manager, $tenant, $b) {
    $convs = $svc->conversations($b, $tenant);
    TestLog::assertSame('یک گفتگو', 1, count($convs));
    TestLog::assertSame('طرف گفتگو مدیر است', $manager, $convs[0]['other_id']);
    TestLog::assertSame('یک پیام نخوانده', 1, $convs[0]['unread']);
    TestLog::assertSame('متن آخرین پیام', 'سلام، شارژ را واریز کنید', $convs[0]['last_message']['body']);
});

TestLog::run('شمارندهٔ نخوانده‌ها', function () use ($svc, $manager, $tenant, $b) {
    TestLog::assertSame('گیرنده: ۱ نخوانده', 1, $svc->unreadCount($b, $tenant));
    TestLog::assertSame('فرستنده: ۰ نخوانده', 0, $svc->unreadCount($b, $manager));
});

TestLog::run('بازکردن گفتگو پیام‌ها را خوانده می‌کند', function () use ($svc, $manager, $tenant, $b) {
    $thread = $svc->thread($b, $tenant, $manager);
    TestLog::assertSame('یک پیام در رشته', 1, count($thread));
    TestLog::assertSame('پیام خوانده شد', true, $thread[0]['is_read']);
    TestLog::assertSame('شمارنده صفر شد', 0, $svc->unreadCount($b, $tenant));
});

TestLog::run('پاسخ ساکن و نمایش در گفتگوی مدیر', function () use ($svc, $manager, $tenant, $b) {
    $svc->send(['building_id' => $b, 'recipient_id' => $manager, 'body' => 'چشم، امروز واریز می‌کنم'], $tenant);
    $thread = $svc->thread($b, $manager, $tenant);
    TestLog::assertSame('دو پیام در رشته', 2, count($thread));
    TestLog::assertSame('ترتیب: اول پیام مدیر', $manager, $thread[0]['sender_id']);
    TestLog::assertSame('دوم پاسخ ساکن', $tenant, $thread[1]['sender_id']);
});

TestLog::run('پیام به خود رد می‌شود', function () use ($svc, $manager, $b) {
    TestLog::assertThrows('خودپیامی', fn() => $svc->send(['building_id' => $b, 'recipient_id' => $manager, 'body' => 'تست'], $manager), 'خودتان');
});

TestLog::run('متن خالی رد می‌شود', function () use ($svc, $manager, $tenant, $b) {
    TestLog::assertThrows('متن خالی', fn() => $svc->send(['building_id' => $b, 'recipient_id' => $tenant, 'body' => '   '], $manager), 'خالی');
});

TestLog::run('پیام بسیار بلند رد می‌شود', function () use ($svc, $manager, $tenant, $b) {
    TestLog::assertThrows('بلند', fn() => $svc->send(['building_id' => $b, 'recipient_id' => $tenant, 'body' => str_repeat('ا', MessageService::MAX_LENGTH + 1)], $manager), 'طولانی');
});

TestLog::run('کاربر غیرعضو نمی‌تواند پیام بفرستد', function () use ($svc, $outsider, $tenant, $b) {
    TestLog::assertThrows('فرستنده غریبه', fn() => $svc->send(['building_id' => $b, 'recipient_id' => $tenant, 'body' => 'تست'], $outsider), 'عضو');
});

TestLog::run('گیرندهٔ غیرعضو رد می‌شود', function () use ($svc, $manager, $outsider, $b) {
    TestLog::assertThrows('گیرنده غریبه', fn() => $svc->send(['building_id' => $b, 'recipient_id' => $outsider, 'body' => 'تست'], $manager), 'عضو این ساختمان نیست');
});

TestLog::run('غیرعضو گفتگوها و شمارنده را نمی‌بیند', function () use ($svc, $outsider, $manager, $b) {
    TestLog::assertThrows('گفتگوهای غریبه', fn() => $svc->conversations($b, $outsider), 'عضو');
    TestLog::assertSame('شمارنده غریبه صفر', 0, $svc->unreadCount($b, $outsider));
});
