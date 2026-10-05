<?php
/** تست یادآوری انقضای دعوت‌نامه‌ها (اعلان به دعوت‌کننده + ضدتکرار در event_reminders) */
declare(strict_types=1);

use App\Services\InviteExpiryReminderService;

TestLog::suite('یادآوری انقضای دعوت‌نامه‌ها');

/** یک دعوت در انتظار با انقضای مشخص می‌سازد و شناسهٔ آن را برمی‌گرداند */
function make_pending_invite(int $buildingId, int $inviterId, string $expiresAt, string $name = 'مهمان تست'): int
{
    $db = test_db();
    $stmt = $db->prepare(
        "INSERT INTO invitations (building_id, invited_name, invited_phone, role, token, status, invited_by, expires_at)
         VALUES (?, ?, '09130000000', 'resident', ?, 'pending', ?, ?)"
    );
    $stmt->execute([$buildingId, $name, bin2hex(random_bytes(12)), $inviterId, $expiresAt]);
    return (int) $db->lastInsertId();
}

/** شمارش اعلان‌های انقضای دعوت برای یک کاربر */
function invite_notif_count(int $userId): int
{
    $stmt = test_db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title LIKE '%انقضا%'");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** شمارش ردیف‌های ضدتکرار یادآوری برای یک دعوت/کاربر/کانال */
function invite_reminder_rows(int $inviteId, int $userId, string $channel): int
{
    $stmt = test_db()->prepare(
        "SELECT COUNT(*) FROM event_reminders WHERE event_type = 'invite_expiry' AND event_id = ? AND user_id = ? AND channel = ?"
    );
    $stmt->execute([$inviteId, $userId, $channel]);
    return (int) $stmt->fetchColumn();
}

$reminder = new InviteExpiryReminderService();

TestLog::run('دعوت در آستانهٔ انقضا به دعوت‌کننده اعلان می‌دهد', function () use ($reminder) {
    $manager = make_user('09139000001', 'مدیر دعوت');
    $b = make_building($manager);
    $inviteId = make_pending_invite($b, $manager, date('Y-m-d H:i:s', time() + 6 * 3600), 'علی رضایی');

    $before = invite_notif_count($manager);
    $result = $reminder->run(1);

    TestLog::assertTrue('دست‌کم یک دعوت نامزد شد', $result['candidates'] >= 1);
    TestLog::assertSame('یک اعلان ساخته شد', 1, $result['notified']);
    TestLog::assertSame('اعلان برای دعوت‌کننده ثبت شد', $before + 1, invite_notif_count($manager));
    TestLog::assertSame('ردیف ضدتکرار اعلان ثبت شد', 1, invite_reminder_rows($inviteId, $manager, 'notification'));
    TestLog::assertSame('ردیف ضدتکرار پیامک ثبت شد', 1, invite_reminder_rows($inviteId, $manager, 'sms'));
});

TestLog::run('اجرای مجدد برای همان دعوت تکراری نمی‌فرستد', function () use ($reminder) {
    $manager = make_user('09139000011', 'مدیر دعوت ۲');
    $b = make_building($manager);
    $inviteId = make_pending_invite($b, $manager, date('Y-m-d H:i:s', time() + 6 * 3600));

    $before = invite_notif_count($manager);
    $reminder->run(1);
    TestLog::assertSame('بار اول اعلان رفت', $before + 1, invite_notif_count($manager));

    $between = invite_notif_count($manager);
    $second = $reminder->run(1);
    TestLog::assertSame('بار دوم اعلانی ساخته نشد', $between, invite_notif_count($manager));
    TestLog::assertTrue('بار دوم چیزی رد شد (ضدتکرار)', $second['skipped'] >= 1);
    TestLog::assertSame('ردیف ضدتکرار همچنان یکی است', 1, invite_reminder_rows($inviteId, $manager, 'notification'));
});

TestLog::run('دعوت دور از انقضا یادآوری نمی‌گیرد', function () use ($reminder) {
    $manager = make_user('09139000021', 'مدیر دعوت ۳');
    $b = make_building($manager);
    $inviteId = make_pending_invite($b, $manager, date('Y-m-d H:i:s', time() + 5 * 86400));

    $before = invite_notif_count($manager);
    $reminder->run(1);

    TestLog::assertSame('اعلانی ساخته نشد', $before, invite_notif_count($manager));
    TestLog::assertSame('ردیف یادآوری برای این دعوت ثبت نشد', 0, invite_reminder_rows($inviteId, $manager, 'notification'));
});

TestLog::run('دعوت منقضی‌شده یا پذیرفته‌شده یادآوری نمی‌گیرد', function () use ($reminder) {
    $manager = make_user('09139000031', 'مدیر دعوت ۴');
    $b = make_building($manager);

    // منقضی‌شده (گذشته)
    $expiredId = make_pending_invite($b, $manager, date('Y-m-d H:i:s', time() - 3600));
    // پذیرفته‌شده در پنجره
    $db = test_db();
    $stmt = $db->prepare(
        "INSERT INTO invitations (building_id, invited_name, role, token, status, invited_by, expires_at)
         VALUES (?, 'پذیرفته', 'resident', ?, 'accepted', ?, ?)"
    );
    $stmt->execute([$b, bin2hex(random_bytes(12)), $manager, date('Y-m-d H:i:s', time() + 3600)]);
    $acceptedId = (int) $db->lastInsertId();

    $before = invite_notif_count($manager);
    $reminder->run(1);

    TestLog::assertSame('اعلانی ساخته نشد', $before, invite_notif_count($manager));
    TestLog::assertSame('دعوت منقضی‌شده ردیف نگرفت', 0, invite_reminder_rows($expiredId, $manager, 'notification'));
    TestLog::assertSame('دعوت پذیرفته‌شده ردیف نگرفت', 0, invite_reminder_rows($acceptedId, $manager, 'notification'));
});
