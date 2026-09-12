<?php

declare(strict_types=1);

/**
 * تسک خودکار یادآوری رویدادها (جلسه‌ها و رزرو مشاعات) و مهلت پرداخت هزینه‌ها.
 *
 * رویدادهایی که زمان آن‌ها در بازه «اکنون تا پنجره یادآوری» قرار دارد پیدا می‌کند و:
 *   ۱. برای کاربران مربوطه «اعلان درون‌اپ» می‌سازد (جلسه → همه اعضای فعال ساختمان،
 *      رزرو → فقط کاربر رزروکننده)
 *   ۲. در صورت داشتن شماره موبایل معتبر، پیامک یادآوری ارسال می‌کند
 *
 * همچنین هزینه‌های صادرشده‌ای که مهلتشان نزدیک است (پیش‌فرض ۳ روز آینده،
 * `REMINDER_DUE_DAYS`) به پرداخت‌کننده‌های پرداخت‌نکرده اعلان + پیامک می‌دهد
 * و دعوت‌نامه‌های در آستانهٔ انقضا (پیش‌فرض ۱ روز آینده،
 * `INVITE_EXPIRY_REMIND_DAYS`) را به دعوت‌کننده یادآوری می‌کند.
 *
 * ارسال هر یادآوری در جدول `event_reminders` ثبت می‌شود (کلید یکتا بر اساس
 * رویداد + کاربر + کانال) تا اجرای مکرر کران باعث ارسال تکراری نشود.
 *
 * متغیرهای محیطی:
 *   REMINDER_WINDOW_HOURS       پنجره یادآوری بر حسب ساعت (پیش‌فرض ۲۴)
 *   MELIPAYAMAK_REMINDER_BODY_ID پترن جداگانه پیامک یادآوری (اختیاری —
 *                               در پنل ملی‌پیامک با آرگومان‌های
 *                               نام، عنوان رویداد، زمان، نام ساختمان ثبت شود)
 *
 * اجرا دستی:  php scripts/reminders.php
 * کرون ساعتی (پیشنهادی):
 *   0 * * * * /usr/bin/php /path/to/Building_Management/scripts/reminders.php >> /var/log/bm_reminders.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Logger;
use App\Services\InviteExpiryReminderService;
use App\Services\NotificationService;
use App\Services\PaymentDueReminderService;
use App\Services\SmsService;
use App\Utilities\JalaliHelper;
use App\Utilities\PhoneHelper;

try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    echo 'DB connection failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

$windowHours = max(1, (int) (getenv('REMINDER_WINDOW_HOURS') ?: 24));
$now = time();
$nowSql = date('Y-m-d H:i:s', $now);
$untilSql = date('Y-m-d H:i:s', $now + $windowHours * 3600);

// جدول یادآوری‌ها ممکن است هنوز مایگریت نشده باشد
try {
    $db->query('SELECT id FROM event_reminders LIMIT 1');
} catch (Throwable $e) {
    echo 'event_reminders table not migrated yet. Run: php scripts/migrator.php' . PHP_EOL;
    exit(0);
}

$notificationService = new NotificationService();
$smsService = new SmsService();

/**
 * هر نامزد: نوع، شناسه رویداد، ساختمان، زمان، عنوان، برچسب زمان (شمسی) و لیست کاربران هدف.
 * @var array $candidates
 */
$candidates = [];

// ۱) جلسه‌های پیش رو → همه اعضای فعال ساختمان
$stmt = $db->prepare(
    "SELECT m.id, m.building_id, m.title, m.location, m.meeting_date, b.name AS building_name
     FROM meetings m
     JOIN buildings b ON b.id = m.building_id
     WHERE m.status = 'scheduled' AND m.meeting_date > ? AND m.meeting_date <= ?"
);
$stmt->execute([$nowSql, $untilSql]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $ts = strtotime((string) $row['meeting_date']);
    if ($ts === false) {
        continue;
    }
    $members = $db->prepare("SELECT user_id FROM building_members WHERE building_id = ? AND status = 'active'");
    $members->execute([(int) $row['building_id']]);
    $userIds = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$userIds) {
        continue;
    }
    $candidates[] = [
        'type' => 'meeting',
        'event_id' => (int) $row['id'],
        'building_id' => (int) $row['building_id'],
        'building_name' => (string) $row['building_name'],
        'remind_ts' => $ts,
        'title' => (string) $row['title'],
        'when' => JalaliHelper::formatDateTimeLong($ts),
        'user_ids' => $userIds,
    ];
}

// ۲) رزروهای پیش رو → فقط کاربر رزروکننده
$stmt = $db->prepare(
    "SELECT bk.id, bk.building_id, bk.user_id, bk.booking_date, bk.start_time,
            b.name AS building_name, ca.name AS area_name
     FROM bookings bk
     JOIN buildings b ON b.id = bk.building_id
     LEFT JOIN common_areas ca ON ca.id = bk.common_area_id
     WHERE bk.status IN ('pending', 'confirmed')
       AND bk.booking_date >= CURDATE() AND bk.booking_date <= DATE(?)"
);
$stmt->execute([$untilSql]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $date = substr((string) $row['booking_date'], 0, 10);
    $start = trim((string) ($row['start_time'] ?? ''));
    $ts = strtotime($date . ' ' . ($start !== '' ? $start : '09:00:00'));
    if ($ts === false || $ts <= $now) {
        continue; // رزروهای تمام‌روزِ گذشته یا بدون ساعت که زمانشان رد شده
    }
    $area = (string) ($row['area_name'] ?? '');
    $candidates[] = [
        'type' => 'booking',
        'event_id' => (int) $row['id'],
        'building_id' => (int) $row['building_id'],
        'building_name' => (string) $row['building_name'],
        'remind_ts' => $ts,
        'title' => 'رزرو ' . ($area !== '' ? $area : 'مشاعات'),
        'when' => JalaliHelper::formatDateTimeLong($ts, $start !== ''),
        'user_ids' => [(int) $row['user_id']],
    ];
}

if (!$candidates) {
    echo "No upcoming events in the next {$windowHours} hour(s)." . PHP_EOL;
}

$insertSql = 'INSERT IGNORE INTO event_reminders (event_type, event_id, user_id, building_id, remind_at, channel)
              VALUES (?, ?, ?, ?, ?, ?)';
$markSql = 'UPDATE event_reminders SET sent_at = NOW()
            WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ?';

$userCache = [];
$notifySent = 0;
$smsSent = 0;

foreach ($candidates as $c) {
    $remindAtSql = date('Y-m-d H:i:s', $c['remind_ts']);

    foreach ($c['user_ids'] as $uid) {
        // --- کانال اعلان درون‌اپ ---
        $ins = $db->prepare($insertSql);
        $ins->execute([$c['type'], $c['event_id'], $uid, $c['building_id'], $remindAtSql, 'notification']);
        if ($ins->rowCount() === 1) {
            try {
                $notificationService->createNotification([
                    'user_id' => $uid,
                    'building_id' => $c['building_id'],
                    'notification_type' => 'reminder',
                    'title' => 'یادآوری: ' . $c['title'],
                    'message' => $c['when'] . ' — ساختمان «' . $c['building_name'] . '»',
                    'data' => ['event_type' => $c['type'], 'event_id' => $c['event_id']],
                ]);
                $notifySent++;
                $db->prepare($markSql)->execute([$c['type'], $c['event_id'], $uid, 'notification']);
            } catch (Throwable $e) {
                Logger::error('reminders', 'ثبت اعلان یادآوری ناموفق بود', [
                    'event' => $c['type'] . '#' . $c['event_id'],
                    'user_id' => $uid,
                ], $e);
                // ردیفِ تازه‌ثبت‌شده حذف می‌شود تا در اجرای بعدی دوباره تلاش شود
                $del = $db->prepare(
                    'DELETE FROM event_reminders
                     WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ? AND sent_at IS NULL'
                );
                $del->execute([$c['type'], $c['event_id'], $uid, 'notification']);
                continue; // اگر اعلان ثبت نشد، پیامک هم ارسال نکن تا بعداً هر دو با هم تلاش شوند
            }
        }

        // --- کانال پیامک ---
        $ins = $db->prepare($insertSql);
        $ins->execute([$c['type'], $c['event_id'], $uid, $c['building_id'], $remindAtSql, 'sms']);
        if ($ins->rowCount() !== 1) {
            continue; // قبلاً برای این رویداد/کاربر پیامک فرستاده شده
        }

        if (!isset($userCache[$uid])) {
            $u = $db->prepare('SELECT name, phone FROM users WHERE id = ? AND deleted_at IS NULL');
            $u->execute([$uid]);
            $userCache[$uid] = $u->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'phone' => null];
        }
        $user = $userCache[$uid];
        if (!empty($user['phone']) && PhoneHelper::isValid((string) $user['phone'])) {
            $ok = $smsService->sendEventReminderSms(
                (string) $user['phone'],
                (string) $user['name'],
                $c['title'],
                $c['when'],
                $c['building_name']
            );
            if ($ok) {
                $smsSent++;
            }
        }
        // چه ارسال موفق باشد چه نه، ردیف علامت‌گذاری می‌شود تا تلاش تکراری صورت نگیرد؛
        // خطاهای پیامک توسط SmsService لاگ می‌شوند.
        $db->prepare($markSql)->execute([$c['type'], $c['event_id'], $uid, 'sms']);
    }
}

echo sprintf(
    "Done. %d candidate event(s), %d in-app notification(s), %d SMS sent. (window: %d hours)%s",
    count($candidates),
    $notifySent,
    $smsSent,
    $windowHours,
    PHP_EOL
);

// ------------------------------------------------------------------
// یادآوری مهلت پرداخت هزینه‌ها (پنجرهٔ روزانه؛ جدا از رویدادهای ساعتی)
// ------------------------------------------------------------------
$due = (new PaymentDueReminderService())->run();
echo sprintf(
    "Payment due: %d cost(s) in window, %d notification(s), %d SMS sent, %d skipped.%s",
    $due['candidates'],
    $due['notified'],
    $due['sms_sent'],
    $due['skipped'],
    PHP_EOL
);

// ------------------------------------------------------------------
// یادآوری انقضای دعوت‌نامه‌ها (پنجرهٔ روزانه؛ جدا از رویدادهای ساعتی)
// ------------------------------------------------------------------
$invites = (new InviteExpiryReminderService())->run();
echo sprintf(
    "Invite expiry: %d invitation(s) in window, %d notification(s), %d SMS sent, %d skipped.%s",
    $invites['candidates'],
    $invites['notified'],
    $invites['sms_sent'],
    $invites['skipped'],
    PHP_EOL
);
