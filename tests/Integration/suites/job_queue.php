<?php
/** تست صف کار پس‌زمینه: چرخهٔ کامل ثبت/تصاحب/تکمیل/شکست + ادغام پیامک */
declare(strict_types=1);

use App\Services\JobQueue;
use App\Services\JobRunner;

TestLog::suite('صف کار پس‌زمینه');

/** وضعیت یک کار در جدول صف */
function job_row(int $id): ?array
{
    $stmt = test_db()->prepare('SELECT * FROM jobs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

TestLog::run('چرخهٔ کامل: ثبت، تصاحب، تکمیل', function () {
    $id = JobQueue::enqueue('notification', ['title' => 'کار تستی', 'user_id' => 1]);
    TestLog::assertTrue('کار ثبت شد', $id !== null && $id > 0);
    TestLog::assertSame('وضعیت اولیه', 'pending', job_row((int) $id)['status']);

    $job = JobQueue::claim();
    TestLog::assertTrue('کار تصاحب شد', $job !== null);
    TestLog::assertSame('نوع کار', 'notification', $job['job_type']);
    TestLog::assertSame('پیلود سالم', 'کار تستی', $job['payload']['title']);
    TestLog::assertSame('وضعیت در حال اجرا', 'running', job_row((int) $id)['status']);

    JobQueue::complete((int) $id);
    TestLog::assertSame('وضعیت نهایی', 'done', job_row((int) $id)['status']);
});

TestLog::run('کار با تأخیر قبل از موعد تصاحب نمی‌شود', function () {
    $id = JobQueue::enqueue('notification', ['title' => 'بعداً'], 3600);
    $job = JobQueue::claim();
    TestLog::assertSame('چیزی برای اجرا نیست', null, $job);
    TestLog::assertSame('همچنان در انتظار', 'pending', job_row((int) $id)['status']);
});

TestLog::run('شکست با تلاش باقی‌مانده کار را به صف برمی‌گرداند', function () {
    $id = JobQueue::enqueue('notification', ['title' => 'شکننده'], 0, 3);
    JobQueue::claim();
    JobQueue::fail((int) $id, 0, 3, 'خطای تستی');
    $row = job_row((int) $id);
    TestLog::assertSame('به صف برگشت', 'pending', $row['status']);
    TestLog::assertSame('تلاش‌ها شمارش شد', 1, (int) $row['attempts']);
    TestLog::assertSame('دلیل خطا ثبت شد', 'خطای تستی', $row['last_error']);
});

TestLog::run('شکست در آخرین تلاش، کار را قطعی شکست‌خورده می‌کند', function () {
    $id = JobQueue::enqueue('notification', ['title' => 'ناامید'], 0, 1);
    JobQueue::claim();
    JobQueue::fail((int) $id, 0, 1, 'تمام شد');
    TestLog::assertSame('شکست قطعی', 'failed', job_row((int) $id)['status']);
});

TestLog::run('پردازشگر صف اعلان را واقعاً می‌سازد', function () {
    $user = make_user('09139600001', 'کاربر صف');
    JobQueue::enqueue('notification', [
        'user_id' => $user,
        'notification_type' => 'general',
        'title' => 'از صف آمد',
        'message' => 'سلام از پس‌زمینه',
    ]);

    $result = (new JobRunner())->run(10);
    TestLog::assertSame('یک کار پردازش شد', 1, $result['processed']);
    TestLog::assertSame('موفق بود', 1, $result['succeeded']);

    $stmt = test_db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'از صف آمد'");
    $stmt->execute([$user]);
    TestLog::assertSame('اعلان ساخته شد', 1, (int) $stmt->fetchColumn());
});

TestLog::run('نوع ناشناخته بدون شکستن پردازش رد می‌شود', function () {
    JobQueue::enqueue('ghost_type', ['x' => 1]);
    $result = (new JobRunner())->run(10);
    TestLog::assertSame('پردازش تلاش شد', 1, $result['processed']);
    TestLog::assertSame('شکست خورد', 1, $result['failed']);
    $stats = JobQueue::stats();
    TestLog::assertTrue('آمار صف در دسترس است', is_array($stats) && array_key_exists('failed', $stats));
});

TestLog::run('پیامک در حالت صف به‌جای ارسال مستقیم، ثبت می‌شود', function () {
    // اعتبارنامهٔ تستی تا سرویس «فعال» باشد + فعال‌سازی صف
    putenv('MELIPAYAMAK_USERNAME=queue-user');
    putenv('MELIPAYAMAK_PASSWORD=queue-pass');
    putenv('MELIPAYAMAK_BODY_ID=12345');
    putenv('QUEUE_DRIVER=database');
    JobQueue::resetAvailabilityCache();

    $before = (int) test_db()->query("SELECT COUNT(*) FROM jobs WHERE job_type = 'sms'")->fetchColumn();
    $sms = new \App\Services\SmsService();
    $accepted = $sms->sendByBaseNumber('09123456789', 'متن تستی صف');

    TestLog::assertTrue('ارسال پذیرفته شد', $accepted === true);
    $after = (int) test_db()->query("SELECT COUNT(*) FROM jobs WHERE job_type = 'sms'")->fetchColumn();
    TestLog::assertSame('یک کار پیامک در صف است', $before + 1, $after);

    $last = test_db()->query("SELECT * FROM jobs WHERE job_type = 'sms' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $payload = json_decode((string) $last['payload'], true);
    TestLog::assertSame('مقصد درست ثبت شده', '09123456789', $payload['to']);
    TestLog::assertSame('متن درست ثبت شده', 'متن تستی صف', $payload['text']);

    // بازگشت به حالت همگام برای بقیهٔ سوئیت‌ها
    putenv('QUEUE_DRIVER=sync');
    putenv('MELIPAYAMAK_USERNAME');
    putenv('MELIPAYAMAK_PASSWORD');
    putenv('MELIPAYAMAK_BODY_ID');
});
