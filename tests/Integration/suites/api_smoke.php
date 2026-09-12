<?php
/**
 * اسموک تست کامل API — تک‌تک مسیرهای روت‌شده فراخوانی می‌شوند:
 *   فاز ۱: همهٔ مسیرها با توکن مدیر (انتظار: بدون خطای ۵۰۰، همهٔ GETها ۲۰۰)
 *   فاز ۲: مسیرهای حذفی روی موجودیت‌های اختصاصی (انتظار: ۲۰۰/۲۰۴)
 *   فاز ۳: پیمایش بدون توکن (انتظار: ۴۰۱ برای همهٔ مسیرهای غیرعمومی)
 */
declare(strict_types=1);

use App\Utilities\JwtHelper;

TestLog::suite('API Smoke — فراخوانی تک‌تک مسیرها');

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

$db = test_db();

// جدول‌هایی که در بوت‌استرپ نیستند (ماژول‌های عملیاتی)
$smoke_ddl = [
    "CREATE TABLE IF NOT EXISTS meetings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, title TEXT, description TEXT,
        meeting_date TEXT, meeting_time TEXT, location TEXT, status TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS meeting_minutes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, meeting_id INTEGER, content TEXT, created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, user_id INTEGER,
        visitor_name TEXT, visitor_car_plate TEXT, visit_date TEXT, entry_time TEXT, exit_time TEXT,
        status TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS consumption_readings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER, unit_id INTEGER DEFAULT NULL,
        consumption_type TEXT DEFAULT 'electricity', reading_value REAL NOT NULL,
        reading_date TEXT NOT NULL, notes TEXT DEFAULT NULL, created_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS emergency_contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER NOT NULL, contact_name TEXT NOT NULL,
        contact_role TEXT DEFAULT NULL, phone TEXT NOT NULL, email TEXT DEFAULT NULL)",
    "CREATE TABLE IF NOT EXISTS emergency_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, building_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        message TEXT DEFAULT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
];
foreach ($smoke_ddl as $ddl) {
    $db->exec($ddl);
}

// ------------------------------------------------------------------
// فیکسچرها
// ------------------------------------------------------------------
$manager = make_user('09139500001', 'مدیر اسموک');
$resident = make_user('09139500002', 'ساکن اسموک');
$outsider = make_user('09139500003', 'غریبه اسموک');
$B = make_building($manager);
add_member($B, $resident, 'tenant');

$db->exec("INSERT INTO blocks (building_id, name) VALUES ({$B}, 'بلوک اسموک')");
$block = (int) $db->lastInsertId();
$db->exec("INSERT INTO floors (building_id, floor_number) VALUES ({$B}, 1)");
$floor = (int) $db->lastInsertId();
$unit = make_unit($B, '101', ['owner_user_id' => $resident]);
$unit2 = make_unit($B, '202', []);
$db->exec("INSERT INTO common_areas (building_id, name) VALUES ({$B}, 'پشت‌بام')");
$area = (int) $db->lastInsertId();

// هزینه + پرداخت در انتظار
$db->exec("INSERT INTO costs (building_id, title, amount, status, target_audience, division_method, due_date, issued_at)
           VALUES ({$B}, 'هزینه اسموک', 100000, 'issued', 'all', 'fixed_share',
                   date('now', '+5 days'), datetime('now'))");
$cost = (int) $db->lastInsertId();
$db->exec("INSERT INTO cost_payments (cost_id, user_id, unit_id, status, share_amount, payment_date)
           VALUES ({$cost}, {$resident}, {$unit}, 'pending', 50000, date('now', '+5 days'))");
$payment = (int) $db->lastInsertId();

$db->exec("INSERT INTO tickets (building_id, user_id, title, description, status, category, priority)
           VALUES ({$B}, {$resident}, 'تیکت اسموک', 'شرح', 'open', 'general', 'normal')");
$ticket = (int) $db->lastInsertId();
$db->exec("INSERT INTO announcements (building_id, title, content, is_pinned, created_by)
           VALUES ({$B}, 'اطلاعیه اسموک', 'متن', 0, {$manager})");
$announcement = (int) $db->lastInsertId();
$db->exec("INSERT INTO votes (building_id, title, status, created_by) VALUES ({$B}, 'رأی اسموک', 'active', {$manager})");
$vote = (int) $db->lastInsertId();
$db->exec("INSERT INTO vote_options (vote_id, option_text) VALUES ({$vote}, 'موافق')");
$voteOption = (int) $db->lastInsertId();
$db->exec("INSERT INTO meetings (building_id, title, meeting_date, meeting_time, status, created_by)
           VALUES ({$B}, 'جلسه اسموک', date('now', '+2 days'), '18:00', 'scheduled', {$manager})");
$meeting = (int) $db->lastInsertId();
$db->exec("INSERT INTO bookings (building_id, common_area_id, user_id, booking_date, start_time, end_time, status)
           VALUES ({$B}, {$area}, {$resident}, date('now', '+1 day'), '10:00', '12:00', 'pending')");
$booking = (int) $db->lastInsertId();
$db->exec("INSERT INTO visitors (building_id, user_id, visitor_name, visit_date, status)
           VALUES ({$B}, {$resident}, 'مهمان اسموک', date('now', '+1 day'), 'expected')");
$visitor = (int) $db->lastInsertId();
$db->exec("INSERT INTO maintenance_requests (building_id, user_id, title, status)
           VALUES ({$B}, {$resident}, 'تعمیر اسموک', 'open')");
$maintenance = (int) $db->lastInsertId();
$db->exec("INSERT INTO documents (building_id, title, file_path, document_type, uploaded_by)
           VALUES ({$B}, 'سند اسموک', 'docs/x.pdf', 'general', {$manager})");
$document = (int) $db->lastInsertId();
$db->exec("INSERT INTO reviews (building_id, user_id, category_id, rating, review_text)
           VALUES ({$B}, {$resident}, NULL, 5, 'خوب')");
$review = (int) $db->lastInsertId();
$db->exec("INSERT INTO penalty_settings (building_id, penalty_type, penalty_value, delay_days, created_by)
           VALUES ({$B}, 'percentage', 5, 3, {$manager})");
$penalty = (int) $db->lastInsertId();
$db->exec("INSERT INTO notifications (user_id, building_id, title, message, is_read)
           VALUES ({$manager}, {$B}, 'اعلان اسموک', 'متن', 0)");
$notification = (int) $db->lastInsertId();
$db->exec("INSERT INTO consumption_readings (building_id, unit_id, consumption_type, reading_value, reading_date, created_by)
           VALUES ({$B}, {$unit}, 'electricity', 123.5, date('now'), {$manager})");
$consumption = (int) $db->lastInsertId();
$db->exec("INSERT INTO emergency_contacts (building_id, contact_name, phone) VALUES ({$B}, 'آتش‌نشانی', '125')");
$contact = (int) $db->lastInsertId();
$db->exec("INSERT INTO invitations (building_id, invited_name, invited_phone, role, token, status, invited_by, expires_at)
           VALUES ({$B}, 'دعوت اسموک', '09139990001', 'resident', 'smoke-token-01', 'pending', {$manager},
                   datetime('now', '+5 days'))");
$invitation = (int) $db->lastInsertId();
$db->exec("INSERT INTO messages (building_id, sender_id, recipient_id, body) VALUES ({$B}, {$resident}, {$manager}, 'سلام')");
$GLOBALS['manager_uid'] = $manager;

// ------------------------------------------------------------------
// نگاشت مسیر → شناسه فیکسچر
// ------------------------------------------------------------------
$idMap = [
    'building_id' => $B,
    'peer_id' => $resident,
    'meeting_id' => $meeting,
    'vote_id' => $vote,
    'ticket_id' => $ticket,
    'payment_id' => $payment,
];
// {id} بر اساس بافت مسیر
$contextIds = [
    '#/buildings/\{id\}#' => $B,
    '#/blocks/#' => $block,
    '#/floors/#' => $floor,
    '#/units/#' => $unit,
    '#/common-areas/#' => $area,
    '#/invitations/\{id\}#' => $invitation,
    '#/tickets/#' => $ticket,
    '#/notifications/#' => $notification,
    '#/costs/#' => $cost,
    '#/documents/#' => $document,
    '#/bookings/#' => $booking,
    '#/announcements/#' => $announcement,
    '#/maintenance/#' => $maintenance,
    '#/visitors/#' => $visitor,
    '#/consumption/#' => $consumption,
    '#/emergency-contacts/#' => $contact,
    '#/meetings/#' => $meeting,
    '#/reviews/#' => $review,
    '#/votes/#' => $vote,
    '#/penalty-settings/#' => $penalty,
];

/** جای‌گذاری پلیس‌هولدرها در یک مسیر */
function smoke_path(string $route, array $idMap, array $contextIds): string
{
    // دیسپچ داخلی خودش پیشوند /api را اضافه می‌کند
    $path = preg_replace('#^/api#', '', trim(strstr($route, ' '), ' '));
    foreach ($idMap as $name => $value) {
        $path = str_replace('{' . $name . '}', (string) $value, $path);
    }
    if (str_contains($path, '{id}')) {
        foreach ($contextIds as $pattern => $value) {
            if (preg_match($pattern, $path)) {
                $path = str_replace('{id}', (string) $value, $path);
                break;
            }
        }
    }
    return $path;
}

/** فراخوانی بدون کش درخواست-محیط؛ کوئری از آرگومان دیتا عبور می‌کند */
function smoke_call(string $method, string $path, array $body = []): array
{
    if ($method === 'GET') {
        $data = ['building_id' => $GLOBALS['B']];
    } else {
        $data = $body ?: false;
    }
    $res = callAPI_dispatch($method, $path, $data);
    return is_array($res) ? $res : ['http_code' => 0];
}

function smoke_login(int $userId): void
{
    $_SESSION['token'] = JwtHelper::generate(['sub' => $userId]);
}

$publicPaths = [
    'POST /api/auth/login', 'POST /api/auth/register', 'POST /api/auth/refresh',
    'POST /api/auth/logout', 'POST /api/auth/check-phone', 'POST /api/auth/send-otp',
    'POST /api/auth/verify-otp',
];

$bodies = [
    'POST /api/buildings/{building_id}/blocks' => ['name' => 'بلوک جدید اسموک'],
    'POST /api/buildings/{building_id}/floors' => ['floor_number' => 9],
    'POST /api/buildings/{building_id}/common-areas' => ['name' => 'مشاع جدید'],
    'POST /api/announcements' => ['title' => 'اطلاعیه جدید اسموک', 'content' => 'متن'],
    'POST /api/maintenance' => ['title' => 'تعمیر جدید اسموک'],
    'POST /api/tickets' => ['title' => 'تیکت جدید اسموک', 'description' => 'شرح'],
    'POST /api/tickets/{ticket_id}/comments' => ['body' => 'نظر اسموک'],
    'POST /api/votes' => ['title' => 'رأی جدید اسموک', 'options' => ['الف', 'ب']],
    'POST /api/votes/{vote_id}/options' => ['option_text' => 'گزینهٔ جدید'],
    'POST /api/votes/{vote_id}/vote' => ['option_id' => $voteOption],
    'POST /api/meetings' => ['title' => 'جلسهٔ جدید اسموک', 'meeting_date' => date('Y-m-d', time() + 3 * 86400), 'meeting_time' => '19:00'],
    'POST /api/meetings/{meeting_id}/minutes' => ['content' => 'صورت‌جلسهٔ اسموک'],
    'POST /api/bookings' => ['common_area_id' => $area, 'booking_date' => date('Y-m-d', time() + 2 * 86400), 'start_time' => '09:00', 'end_time' => '10:00'],
    'POST /api/visitors' => ['visitor_name' => 'مهمان جدید', 'visit_date' => date('Y-m-d', time() + 86400)],
    'POST /api/messages' => ['building_id' => $B, 'recipient_id' => $resident, 'body' => 'پیام اسموک'],
    'POST /api/notifications' => ['title' => 'اعلان دستی اسموک', 'message' => 'متن', 'user_id' => $manager],
    'POST /api/penalty-settings' => ['penalty_type' => 'percentage', 'penalty_value' => 4, 'delay_days' => 2],
    'POST /api/emergency-contacts' => ['contact_name' => 'اورژانس', 'phone' => '115'],
    'POST /api/consumption' => ['consumption_type' => 'water', 'reading_value' => 55, 'reading_date' => date('Y-m-d')],
    'POST /api/reviews' => ['rating' => 4, 'review_text' => 'مرسی'],
    'POST /api/review-categories' => ['name' => 'دستهٔ اسموک'],
    'POST /api/costs/monthly-charge' => ['building_id' => $B],
    'POST /api/emergency-alerts' => ['message' => 'هشدار تستی'],
    'PUT /api/auth/me' => ['name' => 'مدیر اسموک آپدیت'],
    'PUT /api/buildings/{building_id}/hierarchy/settings' => [],
];

// ------------------------------------------------------------------
// فاز ۱: همهٔ مسیرها با توکن مدیر
// ------------------------------------------------------------------
$routes = array_keys(\App\Config\Routes::$routes);

$fiveHundred = [];
$getNotOk = [];
$getWhitelist = ['/api/invitations/info']; // بدون توکن دعوت → خطای اعتبارسنجی قابل قبول

TestLog::run('هیچ مسیری با توکن معتبر خطای سرور (5xx) نمی‌دهد', function () use ($routes, $idMap, $contextIds, $bodies, $publicPaths, &$fiveHundred, &$getNotOk, $getWhitelist, $manager, $resident, $db, $B) {
    smoke_login($manager);
    foreach ($routes as $route) {
        [$method, $rawPath] = explode(' ', $route, 2);

        // مسیرهای حذفی که در ترتیب روت‌ها «قبل» از مصرف‌کننده‌های فیکسچر می‌آیند
        // باید روی موجودیت یک‌بارمصرف اجرا شوند تا فیکسچر اصلی زنده بماند
        if ($route === 'DELETE /api/buildings/{id}') {
            $path = '/buildings/' . make_building($manager);
        } elseif ($route === 'DELETE /api/costs/{id}') {
            $db->exec("INSERT INTO costs (building_id, title, amount, status, target_audience, division_method)
                       VALUES ({$B}, 'هزینه یک‌بارمصرف اسموک', 1000, 'draft', 'all', 'fixed_share')");
            $path = '/costs/' . (int) $db->lastInsertId();
        } elseif ($route === 'DELETE /api/tickets/{id}') {
            $db->exec("INSERT INTO tickets (building_id, user_id, title, status)
                       VALUES ({$B}, {$GLOBALS['manager_uid']}, 'تیکت یک‌بارمصرف اسموک', 'open')");
            $path = '/tickets/' . (int) $db->lastInsertId();
        } else {
            $path = smoke_path($route, $idMap, $contextIds);
        }
        if (str_contains($path, '{')) {
            $fiveHundred[] = $route . ' → جای‌گذاری نشد';
            continue;
        }
        // مسیرهای ورود/ثبت‌نام با بدنهٔ خالی فقط خطای اعتبارسنجی بدهند
        $caller = in_array($route, $publicPaths, true) ? $manager : $manager;
        smoke_login($caller);
        $res = smoke_call($method, $path, $bodies[$route] ?? []);
        $code = (int) ($res['http_code'] ?? 0);
        if ($code >= 500) {
            $fiveHundred[] = "{$route} → {$code}";
        }
        if ($method === 'GET' && $code !== 200 && !in_array($rawPath, $getWhitelist, true)) {
            $getNotOk[] = "{$route} → {$code}";
        }
    }
    TestLog::assertSame('خطای ۵۰۰ صفر', [], $fiveHundred);
    TestLog::assertSame('همهٔ GETها با فیکسچر ۲۰۰', [], $getNotOk);
});

TestLog::run('ساکن به داشبورد و مانده‌ها دسترسی دارد؛ غریبه نه', function () use ($B, $outsider, $resident) {
    smoke_login($resident);
    $ok = smoke_call('GET', "/buildings/{$B}/dashboard");
    TestLog::assertSame('داشبورد برای ساکن ۲۰۰', 200, (int) ($ok['http_code'] ?? 0));

    smoke_login($outsider);
    $denied = smoke_call('GET', "/buildings/{$B}/dashboard");
    TestLog::assertSame('داشبورد برای غریبه ۴۰۳', 403, (int) ($denied['http_code'] ?? 0));
    $deniedMembers = smoke_call('GET', "/buildings/{$B}/members");
    TestLog::assertSame('اعضا برای غریبه ۴۰۳', 403, (int) ($deniedMembers['http_code'] ?? 0));
});

// ------------------------------------------------------------------
// فاز ۲: مسیرهای حذفی با موجودیت اختصاصی
// ------------------------------------------------------------------
TestLog::run('همهٔ مسیرهای حذفی روی موجودیت اختصاصی موفق‌اند', function () use ($db, $B, $manager, $resident, $unit2) {
    smoke_login($manager);

    $mk = function (string $sql) use ($db): int {
        $db->exec($sql);
        return (int) $db->lastInsertId();
    };

    $targets = [];
    $targets[] = ['DELETE /api/announcements/{id}', $mk("INSERT INTO announcements (building_id, title, content, created_by) VALUES ({$B}, 'حذف', 'م', {$manager})")];
    $targets[] = ['DELETE /api/bookings/{id}', $mk("INSERT INTO bookings (building_id, common_area_id, user_id, booking_date, start_time, end_time, status) VALUES ({$B}, 1, {$resident}, date('now', '+9 days'), '08:00', '09:00', 'pending')")];
    $targets[] = ['DELETE /api/maintenance/{id}', $mk("INSERT INTO maintenance_requests (building_id, user_id, title, status) VALUES ({$B}, {$resident}, 'حذف', 'open')")];
    $targets[] = ['DELETE /api/visitors/{id}', $mk("INSERT INTO visitors (building_id, user_id, visitor_name, visit_date, status) VALUES ({$B}, {$resident}, 'حذف', date('now'), 'expected')")];
    $targets[] = ['DELETE /api/documents/{id}', $mk("INSERT INTO documents (building_id, title, file_path, uploaded_by) VALUES ({$B}, 'حذف', 'd/y.pdf', {$manager})")];
    $targets[] = ['DELETE /api/consumption/{id}', $mk("INSERT INTO consumption_readings (building_id, consumption_type, reading_value, reading_date, created_by) VALUES ({$B}, 'gas', 1, date('now'), {$manager})")];
    $targets[] = ['DELETE /api/emergency-contacts/{id}', $mk("INSERT INTO emergency_contacts (building_id, contact_name, phone) VALUES ({$B}, 'حذف', '110')")];
    $targets[] = ['DELETE /api/meetings/{id}', $mk("INSERT INTO meetings (building_id, title, meeting_date, status, created_by) VALUES ({$B}, 'حذف', date('now', '+3 days'), 'scheduled', {$manager})")];
    $targets[] = ['DELETE /api/reviews/{id}', $mk("INSERT INTO reviews (building_id, user_id, rating) VALUES ({$B}, {$resident}, 3)")];
    $voteId = $mk("INSERT INTO votes (building_id, title, status, created_by) VALUES ({$B}, 'حذف', 'active', {$manager})");
    $targets[] = ['DELETE /api/votes/{id}', $voteId];
    $targets[] = ['DELETE /api/tickets/{id}', $mk("INSERT INTO tickets (building_id, user_id, title, status) VALUES ({$B}, {$manager}, 'حذف', 'open')")];
    $targets[] = ['DELETE /api/costs/{id}', $mk("INSERT INTO costs (building_id, title, amount, status, target_audience, division_method) VALUES ({$B}, 'حذف', 1000, 'draft', 'all', 'fixed_share')")];
    $targets[] = ['DELETE /api/common-areas/{id}', $mk("INSERT INTO common_areas (building_id, name) VALUES ({$B}, 'حذف')")];
    $targets[] = ['DELETE /api/blocks/{id}', $mk("INSERT INTO blocks (building_id, name) VALUES ({$B}, 'حذف')")];
    $targets[] = ['DELETE /api/floors/{id}', $mk("INSERT INTO floors (building_id, floor_number) VALUES ({$B}, 77)")];
    $targets[] = ['DELETE /api/penalty-settings/{id}', $mk("INSERT INTO penalty_settings (building_id, penalty_type, penalty_value, created_by) VALUES ({$B}, 'fixed', 1000, {$manager})")];
    $targets[] = ['DELETE /api/invitations/{id}', $mk("INSERT INTO invitations (building_id, invited_name, invited_phone, role, token, status, invited_by, expires_at) VALUES ({$B}, 'حذف', '09139990002', 'resident', 'smoke-del-01', 'pending', {$manager}, datetime('now', '+5 days'))")];
    $targets[] = ['DELETE /api/units/{id}', $unit2];

    $failures = [];
    foreach ($targets as [$route, $id]) {
        $path = str_replace('{id}', (string) $id, preg_replace('#^/api#', '', explode(' ', $route, 2)[1]));
        $res = smoke_call('DELETE', $path);
        $code = (int) ($res['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $failures[] = "{$route} (id={$id}) → {$code}";
        }
    }

    // حذف ساختمان در انتها (روی ساختمان اختصاصی)
    $b3 = make_building($manager);
    $res = smoke_call('DELETE', "/buildings/{$b3}");
    $code = (int) ($res['http_code'] ?? 0);
    if ($code < 200 || $code >= 300) {
        $failures[] = "DELETE /api/buildings/{id} (id={$b3}) → {$code}";
    }

    TestLog::assertSame('حذف‌ها موفق', [], $failures);
});

// ------------------------------------------------------------------
// فاز ۳: پیمایش بدون توکن → ۴۰۱ برای مسیرهای محافظت‌شده
// ------------------------------------------------------------------
TestLog::run('بدون توکن، همهٔ مسیرهای غیرعمومی ۴۰۱ می‌دهند', function () use ($routes, $publicPaths) {
    $_SESSION['token'] = '';
    $failures = [];
    foreach ($routes as $route) {
        if (in_array($route, $publicPaths, true)) {
            continue;
        }
        [$method, $rawPath] = explode(' ', $route, 2);
        // شناسه‌ها واقعی نباشند مهم نیست؛ میدل‌ورها قبل از کنترلر ۴۰۱ می‌دهند
        $path = preg_replace('/\{\w+\}/', '999999', preg_replace('#^/api#', '', $rawPath));
        $res = smoke_call($method, $path);
        $code = (int) ($res['http_code'] ?? 0);
        if ($code !== 401) {
            $failures[] = "{$route} → {$code}";
        }
    }
    TestLog::assertSame('همه ۴۰۱ شدند', [], $failures);
});
