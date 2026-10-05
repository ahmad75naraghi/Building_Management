<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Utilities\JalaliHelper;
use App\Utilities\PhoneHelper;

/**
 * یادآوری «انقضای دعوت‌نامه» — اجرای خودکار توسط کران (scripts/reminders.php).
 *
 * دعوت‌های در انتظار پذیرشی که در پنجرهٔ پیش رو منقضی می‌شوند (پیش‌فرض ۱ روز،
 * قابل تنظیم با `INVITE_EXPIRY_REMIND_DAYS`) پیدا می‌شوند و به دعوت‌کننده
 * (معمولاً مدیر ساختمان):
 *   ۱. اعلان درون‌اپی ثبت می‌شود،
 *   ۲. در صورت فعال‌بودن سرویس پیامک و معتبربودن شماره، پیامک یادآوری می‌رود.
 *
 * هر یادآوری در جدول `event_reminders` (نوع `invite_expiry`) با کلید یکتای
 * دعوت + کاربر + کانال ثبت می‌شود تا اجرای مکرر کران تکراری نفرستد.
 */
final class InviteExpiryReminderService
{
    /**
     * اجرای کامل چرخهٔ یادآوری انقضای دعوت‌نامه‌ها.
     *
     * @param int|null $windowDays پنجرهٔ یادآوری (روز)؛ نال = خواندن از محیط
     * @return array{window_days:int, candidates:int, notified:int, sms_sent:int, skipped:int}
     */
    public function run(?int $windowDays = null): array
    {
        $env = \App\Config\AppConfig::env(...);
        $window = $windowDays ?? max(0, (int) $env('INVITE_EXPIRY_REMIND_DAYS', '1'));

        $result = [
            'window_days' => $window,
            'candidates' => 0,
            'notified' => 0,
            'sms_sent' => 0,
            'skipped' => 0,
        ];

        $db = Database::getConnection();

        // جدول یادآوری‌ها ممکن است هنوز مایگریت نشده باشد
        try {
            $db->query('SELECT id FROM event_reminders LIMIT 1');
        } catch (\Throwable $e) {
            Logger::info('InviteExpiryReminder', 'جدول event_reminders موجود نیست؛ یادآوری انقضای دعوت رد شد');
            return $result;
        }

        $nowSql = date('Y-m-d H:i:s');
        $untilSql = date('Y-m-d H:i:s', strtotime('+' . $window . ' days'));

        $stmt = $db->prepare(
            "SELECT i.id, i.building_id, i.invited_name, i.invited_phone, i.invited_by,
                    i.expires_at, b.name AS building_name
             FROM invitations i
             JOIN buildings b ON b.id = i.building_id
             WHERE i.status = 'pending'
               AND i.expires_at IS NOT NULL AND i.expires_at != ''
               AND i.expires_at > ? AND i.expires_at <= ?"
        );
        $stmt->execute([$nowSql, $untilSql]);
        $invites = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $result['candidates'] = count($invites);
        if (!$invites) {
            return $result;
        }

        $notificationService = new NotificationService();
        $smsService = new SmsService();

        foreach ($invites as $invite) {
            $inviterId = (int) ($invite['invited_by'] ?? 0);
            if ($inviterId <= 0) {
                continue;
            }

            $invitedLabel = trim((string) ($invite['invited_name'] ?? ''));
            if ($invitedLabel === '') {
                $phone = (string) ($invite['invited_phone'] ?? '');
                $invitedLabel = $phone !== '' ? $phone : 'دعوت‌شده';
            }

            $expiryTs = strtotime((string) $invite['expires_at']);
            $expiryLabel = $expiryTs !== false
                ? JalaliHelper::formatDateTimeLong($expiryTs)
                : (string) $invite['expires_at'];

            $this->remindInviter(
                $db,
                $notificationService,
                $smsService,
                $result,
                (int) $invite['id'],
                (int) $invite['building_id'],
                (string) ($invite['building_name'] ?? ''),
                $invitedLabel,
                $expiryLabel,
                $inviterId
            );
        }

        Logger::info('InviteExpiryReminder', 'چرخه یادآوری انقضای دعوت‌نامه‌ها انجام شد', [
            'window_days' => $window,
            'candidates' => $result['candidates'],
            'notified' => $result['notified'],
            'sms_sent' => $result['sms_sent'],
        ]);

        return $result;
    }

    /**
     * ارسال یادآوری برای دعوت‌کننده (اعلان + پیامک) با جلوگیری از تکرار.
     *
     * @param array{notified:int, sms_sent:int, skipped:int} $result شمارنده‌ها به‌صورت مرجع به‌روز می‌شوند
     */
    private function remindInviter(
        \PDO $db,
        NotificationService $notificationService,
        SmsService $smsService,
        array &$result,
        int $inviteId,
        int $buildingId,
        string $buildingName,
        string $invitedLabel,
        string $expiryLabel,
        int $inviterId
    ): void {
        $remindAt = date('Y-m-d H:i:s');

        // --- کانال اعلان درون‌اپ ---
        $ins = Database::prepareInsertIgnore(
            $db,
            'INSERT IGNORE INTO event_reminders (event_type, event_id, user_id, building_id, remind_at, channel)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(['invite_expiry', $inviteId, $inviterId, $buildingId, $remindAt, 'notification']);
        if ($ins->rowCount() === 1) {
            try {
                $notificationService->createNotification([
                    'user_id' => $inviterId,
                    'building_id' => $buildingId,
                    'notification_type' => 'general',
                    'title' => '⌛ دعوت‌نامه در آستانهٔ انقضا',
                    'message' => 'دعوت‌نامهٔ «' . $invitedLabel . '» در تاریخ ' . $expiryLabel
                        . ' منقضی می‌شود. در صورت نیاز آن را تمدید یا دوباره ارسال کنید. (ساختمان «'
                        . $buildingName . '»)',
                    'data' => ['invitation_id' => $inviteId],
                ]);
                $result['notified']++;
                $db->prepare(
                    'UPDATE event_reminders SET sent_at = CURRENT_TIMESTAMP
                     WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ?'
                )->execute(['invite_expiry', $inviteId, $inviterId, 'notification']);
            } catch (\Throwable $e) {
                Logger::error('InviteExpiryReminder', 'اعلان انقضای دعوت ثبت نشد', [
                    'invitation_id' => $inviteId,
                    'user_id' => $inviterId,
                ], $e);
                // حذف ردیف تازه‌ثبت‌شده برای تلاش مجدد در اجرای بعدی
                $db->prepare(
                    'DELETE FROM event_reminders
                     WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ? AND sent_at IS NULL'
                )->execute(['invite_expiry', $inviteId, $inviterId, 'notification']);
                return;
            }
        } else {
            $result['skipped']++;
        }

        // --- کانال پیامک ---
        $ins = Database::prepareInsertIgnore(
            $db,
            'INSERT IGNORE INTO event_reminders (event_type, event_id, user_id, building_id, remind_at, channel)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(['invite_expiry', $inviteId, $inviterId, $buildingId, $remindAt, 'sms']);
        if ($ins->rowCount() !== 1) {
            return; // پیامک قبلاً فرستاده شده
        }

        $u = $db->prepare('SELECT name, phone FROM users WHERE id = ? AND deleted_at IS NULL');
        $u->execute([$inviterId]);
        $user = $u->fetch(\PDO::FETCH_ASSOC) ?: [];
        $phone = PhoneHelper::normalize((string) ($user['phone'] ?? ''));

        if ($smsService->isEnabled() && $phone !== '' && PhoneHelper::isValid($phone)) {
            $ok = $smsService->sendEventReminderSms(
                $phone,
                (string) ($user['name'] ?? ''),
                'انقضای دعوت‌نامهٔ «' . $invitedLabel . '»',
                $expiryLabel,
                $buildingName
            );
            if ($ok) {
                $result['sms_sent']++;
            }
        }

        // چه ارسال موفق باشد چه نه، ردیف علامت‌گذاری می‌شود تا تکراری فرستاده نشود
        $db->prepare(
            'UPDATE event_reminders SET sent_at = CURRENT_TIMESTAMP
             WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ?'
        )->execute(['invite_expiry', $inviteId, $inviterId, 'sms']);
    }
}
