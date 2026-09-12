<?php
/** تست لاگ ممیزی اقدامات: ثبت سبک، متادیتا، و نشکستن عملیات اصلی */
declare(strict_types=1);

use App\Core\Audit;
use App\Services\CostService;

TestLog::suite('Audit — ثبت اقدام هر کاربر بدون افت پرفورمنس');

$db = test_db();
$svc = new CostService();
$manager = make_user('09135000001', 'مدیر ممیزی');

/** تعداد لاگ‌های یک اقدام */
function audit_count(string $actionLike): int
{
    $stmt = test_db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action LIKE ?");
    $stmt->execute([$actionLike . '%']);
    return (int) $stmt->fetchColumn();
}

TestLog::run('اقدام‌های مالی به‌ترتیب ثبت می‌شوند', function () use ($svc, $manager) {
    $b = make_building($manager);
    $o1 = make_user('09135000002');
    make_unit($b, '1', ['owner_user_id' => $o1]);

    $cost = $svc->createCost([
        'building_id' => $b, 'title' => 'بازسازی', 'amount' => 50000, 'target_audience' => 'owners',
    ], $manager);
    TestLog::assertSame('cost.create ثبت شد', 1, audit_count('cost.create'));

    $svc->issueCost((int) $cost->id, $manager);
    TestLog::assertSame('cost.issue ثبت شد', 1, audit_count('cost.issue'));

    $rows = cost_payments_of((int) $cost->id);
    $pid = (int) $rows[0]['id'];

    $svc->submitPayment(['payment_id' => $pid, 'amount_paid' => 50000], $o1, receipt_png_bytes(), 'fish.png');
    TestLog::assertSame('payment.submit ثبت شد', 1, audit_count('payment.submit'));

    $svc->confirmPayment($pid, $manager);
    TestLog::assertSame('payment.confirm ثبت شد', 1, audit_count('payment.confirm'));
});

TestLog::run('متادیتا و شناسه‌ها درست ذخیره می‌شوند', function () use ($db, $manager) {
    $stmt = $db->prepare("SELECT user_id, entity_type, entity_id, building_id, meta, ip FROM audit_logs WHERE action = 'cost.create' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    TestLog::assertSame('کاربر درست', $manager, (int) $row['user_id']);
    TestLog::assertSame('نوع موجودی', 'cost', $row['entity_type']);
    TestLog::assertTrue('ساختمان ثبت شده', (int) $row['building_id'] > 0);
    $meta = json_decode((string) $row['meta'], true);
    TestLog::assertSame('عنوان در متادیتا', 'بازسازی', $meta['title'] ?? '');
    TestLog::assertSame('در CLI آی‌پی نال است', null, $row['ip']);
});

TestLog::run('لاگ هرگز عملیات را نمی‌شکند', function () {
    $threw = false;
    try {
        Audit::log(0, 'test.noop', null, null, null, ['k' => 'v']);
        Audit::log(1, 'test.noop2', 'x', 5, 5);
    } catch (\Throwable) {
        $threw = true;
    }
    TestLog::assertSame('بدون استثنا', false, $threw);
});
