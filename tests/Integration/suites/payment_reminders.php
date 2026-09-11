<?php
/** تست یادآوری مهلت پرداخت (اعلان + ثبت ضدتکرار در event_reminders) */
declare(strict_types=1);

use App\Services\CostService;
use App\Services\PaymentDueReminderService;

TestLog::suite('یادآوری مهلت پرداخت');

/** شمارش اعلان‌های مهلت پرداخت برای یک کاربر */
function due_notif_count(int $userId): int
{
    $stmt = test_db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title LIKE '%مهلت پرداخت%'");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/** شمارش ردیف‌های ضدتکرار یادآوری برای یک هزینه/کاربر/کانال */
function due_reminder_rows(int $costId, int $userId, string $channel): int
{
    $stmt = test_db()->prepare(
        "SELECT COUNT(*) FROM event_reminders WHERE event_type = 'payment_due' AND event_id = ? AND user_id = ? AND channel = ?"
    );
    $stmt->execute([$costId, $userId, $channel]);
    return (int) $stmt->fetchColumn();
}

$costs = new CostService();
$reminder = new PaymentDueReminderService();

function make_due_cost(CostService $costs, int $buildingId, int $managerId, string $dueDate): int
{
    $cost = $costs->createCost([
        'building_id' => $buildingId,
        'title' => 'شارژ ویژه مهلت‌دار',
        'amount' => 100000,
        'target_audience' => 'all',
        'division_method' => 'fixed_share',
        'due_date' => $dueDate,
    ], $managerId);
    $costs->issueCost((int) $cost->id, $managerId);
    return (int) $cost->id;
}

TestLog::run('هزینه با مهلت در پنجره، اعلان یادآوری می‌سازد', function () use ($costs, $reminder) {
    $manager = make_user('09138000001', 'مدیر مهلت');
    $tenant = make_user('09138000002', 'ساکن مهلت');
    $b = make_building($manager);
    add_member($b, $tenant, 'tenant');
    make_unit($b, '5', ['tenant_user_id' => $tenant]);

    $costId = make_due_cost($costs, $b, $manager, date('Y-m-d', time() + 86400));

    $before = due_notif_count($tenant);
    $result = $reminder->run(3);

    TestLog::assertSame('یک هزینه نامزد شد', 1, $result['candidates']);
    TestLog::assertSame('یک اعلان ساخته شد', 1, $result['notified']);
    TestLog::assertSame('اعلان برای ساکن ثبت شد', $before + 1, due_notif_count($tenant));
    TestLog::assertSame('ردیف ضدتکرار اعلان ثبت شد', 1, due_reminder_rows($costId, $tenant, 'notification'));
});

TestLog::run('اجرای مجدد کران اعلان تکراری نمی‌فرستد', function () use ($costs, $reminder) {
    $manager = make_user('09138000011', 'مدیر تکرار');
    $tenant = make_user('09138000012', 'ساکن تکرار');
    $b = make_building($manager);
    add_member($b, $tenant, 'tenant');
    make_unit($b, '6', ['tenant_user_id' => $tenant]);
    make_due_cost($costs, $b, $manager, date('Y-m-d', time() + 2 * 86400));

    $reminder->run(3);
    $after_first = due_notif_count($tenant);

    $result = $reminder->run(3);
    TestLog::assertSame('اعلان جدیدی ساخته نشد', $after_first, due_notif_count($tenant));
    TestLog::assertSame('مورد تکراری رد شد', 0, $result['notified']);
    TestLog::assertTrue('شمارنده ردشده پر شد', $result['skipped'] >= 1);
});

TestLog::run('پرداخت تأییدشده یادآوری نمی‌گیرد', function () use ($costs, $reminder) {
    $manager = make_user('09138000021', 'مدیر پرداخته');
    $tenant = make_user('09138000022', 'ساکن پرداخته');
    $b = make_building($manager);
    add_member($b, $tenant, 'tenant');
    make_unit($b, '7', ['tenant_user_id' => $tenant]);
    $costId = make_due_cost($costs, $b, $manager, date('Y-m-d', time() + 86400));

    // تأیید پرداخت در دیتابیس (مسیر معادل با تأیید مدیر)
    test_db()->prepare(
        "UPDATE cost_payments SET status = 'confirmed', confirmed_at = CURRENT_TIMESTAMP WHERE cost_id = ?"
    )->execute([$costId]);

    $before = due_notif_count($tenant);
    $result = $reminder->run(3);
    TestLog::assertSame('اعلانی برای پرداخت‌کنندهٔ تأییدشده نرفت', $before, due_notif_count($tenant));
    TestLog::assertSame('هیچ اعلانی ساخته نشد', 0, $result['notified']);
});

TestLog::run('هزینه با مهلت بیرون پنجره نامزد نمی‌شود', function () use ($costs, $reminder) {
    test_db_reset(); // پاک‌سازی هزینه‌های درون‌پنجرهٔ تست‌های قبلی همین سوئیت
    $manager = make_user('09138000031', 'مدیر دور');
    $tenant = make_user('09138000032', 'ساکن دور');
    $b = make_building($manager);
    add_member($b, $tenant, 'tenant');
    make_unit($b, '8', ['tenant_user_id' => $tenant]);
    make_due_cost($costs, $b, $manager, date('Y-m-d', time() + 30 * 86400));

    $result = $reminder->run(3);
    TestLog::assertSame('نامزدی یافت نشد', 0, $result['candidates']);
    TestLog::assertSame('اعلانی ساخته نشد', 0, due_notif_count($tenant));
});

TestLog::run('هزینه صادرنشده یادآوری نمی‌گیرد', function () use ($costs, $reminder) {
    test_db_reset(); // پاک‌سازی هزینه‌های درون‌پنجرهٔ تست‌های قبلی همین سوئیت
    $manager = make_user('09138000041', 'مدیر صادرنشده');
    $tenant = make_user('09138000042', 'ساکن صادرنشده');
    $b = make_building($manager);
    add_member($b, $tenant, 'tenant');
    make_unit($b, '9', ['tenant_user_id' => $tenant]);

    $costs->createCost([
        'building_id' => $b,
        'title' => 'پیش‌نویس بدون صدور',
        'amount' => 50000,
        'target_audience' => 'all',
        'division_method' => 'fixed_share',
        'due_date' => date('Y-m-d', time() + 86400),
    ], $manager);

    $result = $reminder->run(3);
    TestLog::assertSame('پیش‌نویس صادرنشده نامزد نشد', 0, $result['candidates']);
});
