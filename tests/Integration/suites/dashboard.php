<?php
/**
 * اندپوینت تجمیعی داشبورد: همهٔ داده‌های موردنیاز صفحهٔ داشبورد
 * (اعضا، واحدها، بلوک/طبقه، مانده‌ها، پرداخت‌ها، هزینه‌ها، اطلاعیه‌ها،
 * تعمیرات و خلاصهٔ مالی) در یک درخواست واحد.
 */
declare(strict_types=1);

use App\Utilities\JwtHelper;

TestLog::suite('داشبورد تجمیعی — GET /buildings/{id}/dashboard');

if (!defined('API_INTERNAL_DISPATCH')) {
    define('API_INTERNAL_DISPATCH', true);
}
putenv('JWT_SECRET=test-secret-key-for-e2e-render-0123456789');
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        return [];
    }
}
require_once dirname(__DIR__, 3) . '/includes/api_helper.php';

/** توکن نشست برای کاربر مشخص می‌سازد */
function dashboard_login_as(int $userId): void
{
    $_SESSION['token'] = JwtHelper::generate(['sub' => $userId]);
}

TestLog::run('داشبورد تجمیعی همهٔ بخش‌ها را یک‌جا برمی‌گرداند', function () {
    $manager = make_user('09138000001', 'مدیر داشبورد');
    $resident = make_user('09138000002', 'ساکن داشبورد');
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');
    make_unit($b, '101', ['owner_user_id' => $resident]);

    dashboard_login_as($manager);
    $res = callAPI('GET', "/buildings/{$b}/dashboard");

    TestLog::assertTrue('پاسخ موفق است', ($res['success'] ?? false) === true);
    TestLog::assertSame('کد وضعیت ۲۰۰', 200, (int) ($res['http_code'] ?? 0));

    $data = $res['data'] ?? [];
    foreach ([
        'building', 'members', 'units', 'blocks', 'floors',
        'unit_balances', 'payments', 'costs', 'announcements',
        'maintenance', 'financial_summary',
    ] as $key) {
        TestLog::assertTrue("کلید {$key} وجود دارد", array_key_exists($key, $data));
    }

    TestLog::assertSame('ساختمان همان ساختمان درخواستی است', $b, (int) ($data['building']['id'] ?? 0));
    TestLog::assertTrue('دست‌کم یک واحد برمی‌گردد', count($data['units']) >= 1);

    $member_ids = array_map(static fn($m) => (int) ($m['user_id'] ?? $m['id'] ?? 0), $data['members']);
    TestLog::assertTrue('ساکن در فهرست اعضا هست', in_array($resident, $member_ids, true));
});

TestLog::run('اعضای عادی هم به داشبورد تجمیعی دسترسی دارند', function () {
    $manager = make_user('09138000011', 'مدیر داشبورد ۲');
    $resident = make_user('09138000012', 'ساکن داشبورد ۲');
    $b = make_building($manager);
    add_member($b, $resident, 'tenant');

    dashboard_login_as($resident);
    $res = callAPI('GET', "/buildings/{$b}/dashboard");

    TestLog::assertTrue('ساکن پاسخ موفق می‌گیرد', ($res['success'] ?? false) === true);
    TestLog::assertSame('کد وضعیت ۲۰۰ برای ساکن', 200, (int) ($res['http_code'] ?? 0));
});

TestLog::run('کاربر غیرعضو دسترسی ندارد', function () {
    $manager = make_user('09138000021', 'مدیر داشبورد ۳');
    $stranger = make_user('09138000022', 'غریبه');
    $b = make_building($manager);

    dashboard_login_as($stranger);
    $res = callAPI('GET', "/buildings/{$b}/dashboard");

    TestLog::assertTrue('پاسخ ناموفق است', ($res['success'] ?? true) === false);
    TestLog::assertSame('کد وضعیت ۴۰۳', 403, (int) ($res['http_code'] ?? 0));
});
