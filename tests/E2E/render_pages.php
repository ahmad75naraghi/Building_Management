<?php
/**
 * تست E2E سطح صفحه: هر صفحهٔ اپ با دادهٔ واقعی رندر می‌شود و باید
 * بدون خطای مهلک، با ساختار درست (داکیومنت، فرم‌ها با CSRF) خروجی بدهد.
 * درخواست‌های داخلی صفحه از مسیر واقعی کرنل عبور می‌کنند (دیسپچ درون‌فرایندی).
 */
declare(strict_types=1);

TestLog::suite('E2E — رندر همهٔ صفحات با دادهٔ واقعی');

putenv('JWT_SECRET=test-secret-key-for-e2e-render-0123456789');
if (!defined('API_INTERNAL_DISPATCH')) {
    define('API_INTERNAL_DISPATCH', true);
}
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        return [];
    }
}

// ------------------------------------------------------------------
// ساخت جدول‌هایی که در بوت‌استرپ تست نیستند (ماژول‌های عملیاتی)
// ------------------------------------------------------------------
$e2e_ddl = [
    "CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, user_id INTEGER,
        title TEXT, description TEXT, category TEXT, priority TEXT, status TEXT,
        assigned_to INTEGER, unit_id INTEGER DEFAULT NULL, is_anonymous INTEGER DEFAULT 0,
        resolved_at TEXT DEFAULT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS ticket_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, user_id INTEGER,
        body TEXT, unit_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, title TEXT, content TEXT,
        is_pinned INTEGER DEFAULT 0, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS meetings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, title TEXT, description TEXT,
        meeting_date TEXT, meeting_time TEXT, location TEXT, status TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS meeting_minutes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, meeting_id INTEGER, content TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, common_area_id INTEGER,
        user_id INTEGER, booking_date TEXT, start_time TEXT, end_time TEXT, status TEXT, notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, user_id INTEGER,
        visitor_name TEXT, visitor_car_plate TEXT, visit_date TEXT, entry_time TEXT, exit_time TEXT,
        status TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS maintenance_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, user_id INTEGER, title TEXT,
        description TEXT, status TEXT, assigned_technician_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS consumption_readings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, unit_id INTEGER,
        consumption_type TEXT, reading_value REAL, reading_date TEXT, notes TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS emergency_contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, contact_name TEXT,
        contact_role TEXT, phone TEXT, email TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS emergency_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, message TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER,
        invited_name TEXT DEFAULT NULL, invited_phone TEXT DEFAULT NULL,
        role TEXT, token TEXT, invited_by INTEGER, status TEXT, expires_at TEXT,
        invited_email TEXT DEFAULT NULL, accepted_at TEXT DEFAULT NULL, unit_id INTEGER DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT, key_hash TEXT, hits INTEGER, window_start TEXT)",
];
foreach ($e2e_ddl as $ddl) {
    test_db()->exec($ddl);
}

// ------------------------------------------------------------------
// داده‌های پایه
// ------------------------------------------------------------------
$db = test_db();
$svc = new \App\Services\CostService();

$manager = make_user('09300000001', 'مدیر E2E');
$tenant = make_user('09300000002', 'ساکن E2E');
$b = make_building($manager, ['monthly_charge' => 100000, 'charge_mode' => 'fixed']);
add_member($b, $tenant, 'tenant');

$floor = (int) $db->query("INSERT INTO floors (building_id, floor_number) VALUES ({$b}, 1) RETURNING id")->fetchColumn() ?: 1;
$block = (int) $db->query("INSERT INTO blocks (building_id, name) VALUES ({$b}, 'بلوک A') RETURNING id")->fetchColumn() ?: 1;
$area = (int) $db->query("INSERT INTO common_areas (building_id, name) VALUES ({$b}, 'سالن اجتماعات') RETURNING id")->fetchColumn() ?: 1;
$u1 = make_unit($b, '1', ['tenant_user_id' => $tenant, 'owner_user_id' => $manager, 'residents_count' => 2]);
$u2 = make_unit($b, '2', ['owner_user_id' => $manager, 'residents_count' => 1]);

// هزینه + پرداخت
$svc->generateAllMonthlyCharges();
$svc->recordDirectPayment($b, $u1, 120000.0, 'E2E', $manager);

// ماژول‌های عملیاتی
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$db->exec("INSERT INTO tickets (building_id, user_id, title, description, category, priority, status) VALUES ({$b}, {$tenant}, 'تیکت تست', 'شرح', 'فنی', 'medium', 'open')");
$db->exec("INSERT INTO ticket_comments (ticket_id, user_id, comment) VALUES (1, {$manager}, 'پاسخ تست')");
$db->exec("INSERT INTO announcements (building_id, title, content, is_pinned, created_by) VALUES ({$b}, 'اطلاعیه تست', 'متن', 1, {$manager})");
$db->exec("INSERT INTO meetings (building_id, title, description, meeting_date, location, status, created_by) VALUES ({$b}, 'جلسه تست', 'شرح', '{$today}', 'لابی', 'scheduled', {$manager})");
$db->exec("INSERT INTO meeting_minutes (meeting_id, content, created_by) VALUES (1, 'صورت‌جلسه', {$manager})");
$db->exec("INSERT INTO bookings (building_id, common_area_id, user_id, booking_date, start_time, end_time, status) VALUES ({$b}, {$area}, {$tenant}, '{$today}', '10:00', '12:00', 'approved')");
$db->exec("INSERT INTO visitors (building_id, user_id, visitor_name, visit_date, status) VALUES ({$b}, {$tenant}, 'مهمان تست', '{$today}', 'expected')");
$db->exec("INSERT INTO maintenance_requests (building_id, user_id, title, description, status) VALUES ({$b}, {$tenant}, 'خرابی لامپ', 'شرح', 'open')");
$db->exec("INSERT INTO consumption_readings (building_id, unit_id, consumption_type, reading_value, reading_date, created_by) VALUES ({$b}, {$u1}, 'electricity', 120, '{$today}', {$manager})");
$db->exec("INSERT INTO emergency_contacts (building_id, contact_name, contact_role, phone) VALUES ({$b}, 'آتش‌نشانی', 'اضطراری', '125')");
$db->exec("INSERT INTO notifications (user_id, building_id, notification_type, title, message) VALUES ({$tenant}, {$b}, 'payment', 'تست', 'متن اعلان')");
$db->exec("INSERT INTO votes (building_id, title, description, start_date, end_date, status, created_by) VALUES ({$b}, 'نظرسنجی تست', 'شرح', '{$today}', '{$today}', 'active', {$manager})");
$db->exec("INSERT INTO vote_options (vote_id, option_text) VALUES (1, 'گزینه الف')");
$db->exec("INSERT INTO review_categories (name) VALUES ('نظافت')");
$db->exec("INSERT INTO reviews (building_id, category_id, user_id, rating, review_text) VALUES ({$b}, 1, {$tenant}, 4, 'خوب')");
$db->exec("INSERT INTO documents (building_id, title, file_path, stored_name, mime_type, file_size, is_visible_to_members, uploaded_by) VALUES ({$b}, 'سند تست', 'storage/documents/e2e-test-file.txt', 'e2e-test-file.txt', 'text/plain', 12, 1, {$manager})");
$db->exec("INSERT INTO invitations (building_id, invited_phone, invited_name, role, token, invited_by, status, expires_at) VALUES ({$b}, '09300000099', 'مهمان جدید', 'tenant', 'e2e-invite-token', {$manager}, 'pending', '2099-01-01 00:00:00')");
$db->exec("INSERT INTO messages (building_id, sender_id, recipient_id, body) VALUES ({$b}, {$tenant}, {$manager}, 'سلام مدیر، سوالی درباره شارژ داشتم')");
$db->exec("INSERT INTO messages (building_id, sender_id, recipient_id, body) VALUES ({$b}, {$manager}, {$tenant}, 'سلام، بفرمایید')");

// ------------------------------------------------------------------
// آماده‌سازی نشست کاربر لاگین‌شده
// ------------------------------------------------------------------
$jwt = \App\Utilities\JwtHelper::generate(['sub' => $manager, 'role' => 'manager']);
$_SESSION = [
    'token' => $jwt,
    'user_id' => $manager,
    'user_name' => 'مدیر E2E',
    'active_building_id' => $b,
];

/** رندر یک صفحه و بازگرداندن خروجی */
function e2e_render(string $page, array $get = []): array
{
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/b/' . $page;
    $_SERVER['REQUEST_URI'] = '/b/' . $page;

    ob_start();
    $fatal = null;
    try {
        // کلید صفحه ممکن است برای تمایز حالت‌ها پسوند «?...» داشته باشد
        $file = explode('?', $page)[0];
        include dirname(__DIR__, 2) . '/' . $file;
    } catch (\Throwable $e) {
        $fatal = $e->getMessage();
    }
    $html = (string) ob_get_clean();
    return [$html, $fatal];
}

// فهرست صفحه‌ها و پارامترهای موردنیاز هرکدام
$bid = $b;
// صفحه‌های صرفاً ریدایرکت (auth برای لاگین‌شده، دعوت بدون توکن معتبر، حذف ساختمان)
// و دانلود فایل در این تست رندر نمی‌شوند.
$pages = [
    'dashboard.php' => [],
    'index.php' => [],
    'building_add.php' => [],
    // building_view.php دیگر صفحهٔ مستقل نیست؛ صرفاً به داشبورد ریدایرکت می‌کند
    'building_edit.php' => ['id' => $bid],
    'floors.php' => ['building_id' => $bid],
    'blocks.php' => ['building_id' => $bid],
    'units.php' => ['building_id' => $bid],
    'common_areas.php' => ['building_id' => $bid],
    'costs.php' => ['building_id' => $bid],
    'accounting.php' => ['building_id' => $bid],
    'consumption.php' => ['building_id' => $bid],
    'reports.php' => ['building_id' => $bid],
    'members.php' => ['building_id' => $bid],
    'bulk_users.php' => ['building_id' => $bid],
    'tickets.php' => ['building_id' => $bid],
    'ticket_view.php' => ['id' => 1, 'building_id' => $bid],
    'announcements.php' => ['building_id' => $bid],
    'meetings.php' => ['building_id' => $bid],
    'bookings.php' => ['building_id' => $bid],
    'visitors.php' => ['building_id' => $bid],
    'maintenance.php' => ['building_id' => $bid],
    'documents.php' => ['building_id' => $bid],
    'votes.php' => ['building_id' => $bid],
    'reviews.php' => ['building_id' => $bid],
    'emergency_contacts.php' => ['building_id' => $bid],
    'calendar.php' => ['building_id' => $bid],
    'audit_logs.php' => ['building_id' => $bid],
    'messages.php' => ['building_id' => $bid],
    'messages.php?chat' => ['building_id' => $bid, 'with' => $tenant],
    'notifications.php' => [],
    'profile.php' => [],
    'profile_edit.php' => [],
    'change_password.php' => [],
];

$failed = [];
$rendered = 0;
foreach ($pages as $page => $get) {
    TestLog::run("رندر {$page}", function () use ($page, $get, &$rendered, &$failed) {
        [$html, $fatal] = e2e_render($page, $get);
        $rendered++;

        TestLog::assertTrue("بدون خطای مهلک", $fatal === null, (string) $fatal);
        TestLog::assertTrue("بدون خطای مهلک در خروجی", !preg_match('/Fatal error|Uncaught|Parse error/i', $html));
        TestLog::assertTrue("خروجی خالی نیست", strlen($html) > 200,
            'صفحه: ' . $page . ' طول: ' . strlen($html) . ($html !== '' && strlen($html) <= 200 ? ' محتوا: ' . trim($html) : ''));

        // صفحات با فرم باید توکن CSRF داشته باشند
        $formPages = ['building_add.php', 'building_edit.php', 'costs.php', 'accounting.php', 'members.php',
            'bulk_users.php', 'tickets.php', 'announcements.php', 'meetings.php', 'bookings.php',
            'visitors.php', 'maintenance.php', 'documents.php', 'votes.php', 'reviews.php',
            'emergency_contacts.php', 'change_password.php', 'profile_edit.php', 'units.php',
            'floors.php', 'blocks.php', 'common_areas.php', 'consumption.php'];
        if (in_array($page, $formPages, true) && str_contains($html, '<form')) {
            TestLog::assertTrue("فرم‌ها توکن CSRF دارند", substr_count($html, 'name="_csrf"') > 0);
        }
    });
}

// یک بار هم به‌عنوان ساکن (نه مدیر) — صفحات مشترک باید بدون خطا رندر شوند
$jwtTenant = \App\Utilities\JwtHelper::generate(['sub' => $tenant, 'role' => 'tenant']);
$_SESSION['token'] = $jwtTenant;
$_SESSION['user_id'] = $tenant;
$_SESSION['user_name'] = 'ساکن E2E';
foreach (['dashboard.php' => [], 'costs.php' => ['building_id' => $bid], 'tickets.php' => ['building_id' => $bid],
          'notifications.php' => [], 'profile.php' => []] as $page => $get) {
    TestLog::run("رندر {$page} به‌عنوان ساکن", function () use ($page, $get) {
        [$html, $fatal] = e2e_render($page, $get);
        TestLog::assertTrue('بدون خطای مهلک', $fatal === null, (string) $fatal);
        TestLog::assertTrue('خروجی خالی نیست', strlen($html) > 200);
    });
}
