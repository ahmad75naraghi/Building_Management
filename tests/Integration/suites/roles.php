<?php
/** تست سامانه نقش‌ها و تعیین سکونت */
declare(strict_types=1);

TestLog::suite('نقش‌ها — مدیر / مالک / مستأجر / ساکن');

require_once dirname(__DIR__, 3) . '/includes/api_helper.php';

/** ساخت آرایه واحد شبیه خروجی API */
$unit = static function (array $o = []): array {
    return array_merge([
        'id' => 1,
        'unit_number' => '1',
        'owner_user_id' => null,
        'tenant_user_id' => null,
        'owner_resident' => 0,
    ], $o);
};

// ------------------------------------------------------------ برچسب نقش

TestLog::assertSame('برچسب مدیر', 'مدیر ساختمان', member_role_label('manager'));
TestLog::assertSame('برچسب مالک', 'مالک', member_role_label('owner'));
TestLog::assertSame('برچسب مستأجر', 'مستأجر', member_role_label('tenant'));
TestLog::assertSame('برچسب ساکن', 'ساکن', member_role_label('resident'));
TestLog::assertSame('نقش ناشناخته', 'عضو', member_role_label('whatever'));

// ------------------------------------------------------------ مدیر ساختمان

TestLog::run('مدیر ساختمان', function () {
    $c = derive_role_context(7, 'manager', []);
    TestLog::assertSame('is_manager', true, $c['is_manager']);
    TestLog::assertSame('برچسب', 'مدیر ساختمان', $c['role_label']);
    TestLog::assertSame('مالک نیست', false, $c['is_owner']);
    TestLog::assertSame('مستأجر نیست', false, $c['is_tenant']);
    TestLog::assertSame('ساکن نیست مگر واحد داشته باشد', false, $c['is_resident']);
});

TestLog::run('مدیری که خودش مالک ساکن است', function () use ($unit) {
    $c = derive_role_context(7, 'manager', [$unit(['owner_user_id' => 7, 'owner_resident' => 1])]);
    TestLog::assertSame('هم مدیر', true, $c['is_manager']);
    TestLog::assertSame('هم مالک', true, $c['is_owner']);
    TestLog::assertSame('هم ساکن', true, $c['is_resident']);
});

// ------------------------------------------------------------ مالک

TestLog::run('مالک غیرساکن', function () use ($unit) {
    $c = derive_role_context(11, 'owner', [$unit(['owner_user_id' => 11, 'owner_resident' => 0])]);
    TestLog::assertSame('مالک است', true, $c['is_owner']);
    TestLog::assertSame('ساکن نیست', false, $c['is_resident']);
    TestLog::assertSame('مدیر نیست', false, $c['is_manager']);
    TestLog::assertSame('یک واحد', 1, count($c['units']));
});

TestLog::run('مالک ساکن', function () use ($unit) {
    $c = derive_role_context(11, 'owner', [$unit(['owner_user_id' => 11, 'owner_resident' => 1])]);
    TestLog::assertSame('مالک است', true, $c['is_owner']);
    TestLog::assertSame('ساکن است', true, $c['is_resident']);
});

TestLog::run('مالک بدون واحد ثبت‌شده از روی نقش عضویت شناخته می‌شود', function () {
    $c = derive_role_context(11, 'owner', []);
    TestLog::assertSame('مالک است', true, $c['is_owner']);
    TestLog::assertSame('ولی ساکن نیست', false, $c['is_resident']);
});

TestLog::run('مالک چندواحدی: یک واحد ساکن کافی است', function () use ($unit) {
    $c = derive_role_context(11, 'owner', [
        $unit(['id' => 1, 'owner_user_id' => 11, 'owner_resident' => 0]),
        $unit(['id' => 2, 'owner_user_id' => 11, 'owner_resident' => 1]),
        $unit(['id' => 3, 'owner_user_id' => 99]),
    ]);
    TestLog::assertSame('دو واحد خودش', 2, count($c['units']));
    TestLog::assertSame('ساکن است', true, $c['is_resident']);
});

// ------------------------------------------------------------ مستأجر

TestLog::run('مستأجر همیشه ساکن است', function () use ($unit) {
    $c = derive_role_context(21, 'tenant', [$unit(['owner_user_id' => 11, 'tenant_user_id' => 21])]);
    TestLog::assertSame('مستأجر است', true, $c['is_tenant']);
    TestLog::assertSame('ساکن است', true, $c['is_resident']);
    TestLog::assertSame('مالک نیست', false, $c['is_owner']);
});

TestLog::run('مستأجر بدون واحد ثبت‌شده', function () {
    $c = derive_role_context(21, 'tenant', []);
    TestLog::assertSame('مستأجر است', true, $c['is_tenant']);
    TestLog::assertSame('ساکن است', true, $c['is_resident']);
});

TestLog::run('کاربری که هم مالک یک واحد و هم مستأجر واحد دیگر است', function () use ($unit) {
    $c = derive_role_context(30, 'owner', [
        $unit(['id' => 1, 'owner_user_id' => 30, 'owner_resident' => 0]),
        $unit(['id' => 2, 'tenant_user_id' => 30]),
    ]);
    TestLog::assertSame('مالک', true, $c['is_owner']);
    TestLog::assertSame('مستأجر', true, $c['is_tenant']);
    TestLog::assertSame('ساکن (به‌واسطه اجاره)', true, $c['is_resident']);
    TestLog::assertSame('هر دو واحد', 2, count($c['units']));
});

// ------------------------------------------------------------ عضو بی‌ارتباط

TestLog::run('عضوی که با هیچ واحدی مرتبط نیست', function () use ($unit) {
    $c = derive_role_context(40, 'resident', [
        $unit(['owner_user_id' => 11, 'tenant_user_id' => 21, 'owner_resident' => 1]),
    ]);
    TestLog::assertSame('مدیر نیست', false, $c['is_manager']);
    TestLog::assertSame('مالک نیست', false, $c['is_owner']);
    TestLog::assertSame('مستأجر نیست', false, $c['is_tenant']);
    TestLog::assertSame('ساکن نیست', false, $c['is_resident']);
    TestLog::assertSame('واحدی ندارد', 0, count($c['units']));
});

TestLog::run('کاربر مهمان (بدون شناسه) هیچ دسترسی نمی‌گیرد', function () use ($unit) {
    $c = derive_role_context(0, 'resident', [$unit(['owner_user_id' => 0, 'owner_resident' => 1])]);
    TestLog::assertSame('مالک نیست', false, $c['is_owner']);
    TestLog::assertSame('ساکن نیست', false, $c['is_resident']);
    TestLog::assertSame('واحدی ندارد', 0, count($c['units']));
});

TestLog::run('شناسه‌های رشته‌ای از API درست مقایسه می‌شوند', function () use ($unit) {
    $c = derive_role_context(15, 'owner', [$unit(['owner_user_id' => '15', 'owner_resident' => '1'])]);
    TestLog::assertSame('مالک شناخته شد', true, $c['is_owner']);
    TestLog::assertSame('ساکن شناخته شد', true, $c['is_resident']);
});

TestLog::run('نقش خالی به ساکن پیش‌فرض می‌شود', function () {
    $c = derive_role_context(50, '', []);
    TestLog::assertSame('نقش پیش‌فرض', 'resident', $c['role']);
    TestLog::assertSame('برچسب', 'ساکن', $c['role_label']);
});

// ------------------------------------------------------------ سیاست دسترسی

TestLog::run('قواعد دسترسی مبتنی بر نقش', function () {
    $manager = derive_role_context(1, 'manager', []);
    $owner = derive_role_context(2, 'owner', []);
    $tenant = derive_role_context(3, 'tenant', []);

    // فقط مدیر می‌تواند محتوا بسازد/ویرایش/حذف کند
    TestLog::assertSame('مدیر اجازه مدیریت دارد', true, $manager['is_manager']);
    TestLog::assertSame('مالک اجازه مدیریت ندارد', false, $owner['is_manager']);
    TestLog::assertSame('مستأجر اجازه مدیریت ندارد', false, $tenant['is_manager']);

    // فقط ساکنان در نظرسنجی/امکانات مشترک شرکت می‌کنند
    TestLog::assertSame('مستأجر ساکن است', true, $tenant['is_resident']);
    TestLog::assertSame('مالک غیرساکن، ساکن نیست', false, $owner['is_resident']);
});

TestLog::run('برچسب وضعیت سکونت واحد', function () {
    TestLog::assertSame('مالک ساکن', 'مالک ساکن است', occupancy_label(['occupancy_status' => 'owner_occupied']));
    TestLog::assertSame('مستأجر ساکن', 'مستأجر ساکن است', occupancy_label(['occupancy_status' => 'tenant_occupied']));
    TestLog::assertSame('خالی', 'خالی', occupancy_label(['occupancy_status' => 'vacant']));
    TestLog::assertSame('بدون مالک', 'بدون مالک', occupancy_label(['occupancy_status' => 'no_owner']));
    TestLog::assertSame('نامشخص', 'نامشخص', occupancy_label([]));
});
