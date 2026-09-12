<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Utilities\JalaliHelper;
use App\Utilities\PhoneHelper;

/**
 * یادآوری «مهلت پرداخت» هزینه‌ها — اجرای خودکار توسط کران (scripts/reminders.php).
 *
 * هزینه‌های صادرشده‌ای که مهلتشان در پنجرهٔ پیش رو است (پیش‌فرض ۳ روز،
 * قابل تنظیم با `REMINDER_DUE_DAYS`) پیدا می‌شوند و برای هر پرداخت‌کننده‌ای
 * که هنوز سهم خود را تأییدشده پرداخت نکرده:
 *   ۱. اعلان درون‌اپی ثبت می‌شود،
 *   ۲. در صورت فعال‌بودن سرویس پیامک و معتبربودن شماره، پیامک یادآوری می‌رود.
 *
 * هر یادآوری در جدول `event_reminders` (نوع `payment_due`) با کلید یکتای
 * هزینه + کاربر + کانال ثبت می‌شود تا اجرای مکرر کران تکراری نفرستد.
 */
final class PaymentDueReminderService
{
    /**
     * اجرای کامل چرخهٔ یادآوری مهلت پرداخت.
     *
     * @param int|null $windowDays پنجرهٔ یادآوری (روز)؛ نال = خواندن از محیط
     * @return array{window_days:int, candidates:int, notified:int, sms_sent:int, skipped:int}
     */
    public function run(?int $windowDays = null): array
    {
        $env = \App\Config\AppConfig::env(...);
        $window = $windowDays ?? max(0, (int) $env('REMINDER_DUE_DAYS', '3'));

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
            Logger::info('PaymentDueReminder', 'جدول event_reminders موجود نیست؛ یادآوری مهلت پرداخت رد شد');
            return $result;
        }

        $today = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+' . $window . ' days'));

        $stmt = $db->prepare(
            "SELECT c.id, c.building_id, c.title, c.due_date, b.name AS building_name
             FROM costs c
             JOIN buildings b ON b.id = c.building_id
             WHERE c.deleted_at IS NULL
               AND c.issued_at IS NOT NULL
               AND c.due_date IS NOT NULL AND c.due_date != ''
               AND c.due_date >= ? AND c.due_date <= ?"
        );
        $stmt->execute([$today, $until]);
        $costs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $result['candidates'] = count($costs);
        if (!$costs) {
            return $result;
        }

        $payersStmt = $db->prepare(
            "SELECT cp.user_id, SUM(cp.share_amount) AS due,
                    GROUP_CONCAT(COALESCE(un.unit_number, '?')) AS unit_numbers
             FROM cost_payments cp
             JOIN users u ON u.id = cp.user_id AND u.deleted_at IS NULL
             LEFT JOIN units un ON un.id = cp.unit_id
             WHERE cp.cost_id = ? AND cp.status != 'confirmed'
             GROUP BY cp.user_id"
        );

        $notificationService = new NotificationService();
        $smsService = new SmsService();

        foreach ($costs as $cost) {
            $costId = (int) $cost['id'];
            $buildingId = (int) $cost['building_id'];
            $buildingName = (string) ($cost['building_name'] ?? '');
            $title = (string) ($cost['title'] ?? 'هزینه');
            $dueTs = strtotime((string) $cost['due_date']);
            $dueLabel = $dueTs !== false ? JalaliHelper::formatLong($dueTs) : (string) $cost['due_date'];

            $payersStmt->execute([$costId]);
            $payers = $payersStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($payers as $payer) {
                $userId = (int) ($payer['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                $this->remindPayer(
                    $db,
                    $notificationService,
                    $smsService,
                    $result,
                    $costId,
                    $buildingId,
                    $buildingName,
                    $title,
                    $dueLabel,
                    $userId,
                    (float) ($payer['due'] ?? 0)
                );
            }
        }

        Logger::info('PaymentDueReminder', 'چرخه یادآوری مهلت پرداخت انجام شد', [
            'window_days' => $window,
            'candidates' => $result['candidates'],
            'notified' => $result['notified'],
            'sms_sent' => $result['sms_sent'],
        ]);

        return $result;
    }

    /**
     * ارسال یادآوری برای یک پرداخت‌کننده (اعلان + پیامک) با جلوگیری از تکرار.
     *
     * @param array{notified:int, sms_sent:int, skipped:int} $result شمارنده‌ها به‌صورت مرجع به‌روز می‌شوند
     */
    private function remindPayer(
        \PDO $db,
        NotificationService $notificationService,
        SmsService $smsService,
        array &$result,
        int $costId,
        int $buildingId,
        string $buildingName,
        string $costTitle,
        string $dueLabel,
        int $userId,
        float $dueAmount
    ): void {
        $remindAt = date('Y-m-d H:i:s');

        // --- کانال اعلان درون‌اپ ---
        $ins = Database::prepareInsertIgnore(
            $db,
            'INSERT IGNORE INTO event_reminders (event_type, event_id, user_id, building_id, remind_at, channel)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute(['payment_due', $costId, $userId, $buildingId, $remindAt, 'notification']);
        if ($ins->rowCount() === 1) {
            try {
                $notificationService->createNotification([
                    'user_id' => $userId,
                    'building_id' => $buildingId,
                    'notification_type' => 'payment',
                    'title' => '⏰ مهلت پرداخت نزدیک است',
                    'message' => 'مهلت پرداخت «' . $costTitle . '» در تاریخ ' . $dueLabel
                        . ' به پایان می‌رسد. مبلغ سهم شما: '
                        . JalaliHelper::faDigits(number_format($dueAmount)) . ' تومان.',
                    'data' => ['cost_id' => $costId],
                ]);
                $result['notified']++;
                $db->prepare(
                    'UPDATE event_reminders SET sent_at = CURRENT_TIMESTAMP
                     WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ?'
                )->execute(['payment_due', $costId, $userId, 'notification']);
            } catch (\Throwable $e) {
                Logger::error('PaymentDueReminder', 'اعلان مهلت پرداخت ثبت نشد', [
                    'cost_id' => $costId,
                    'user_id' => $userId,
                ], $e);
                // حذف ردیف تازه‌ثبت‌شده برای تلاش مجدد در اجرای بعدی
                $db->prepare(
                    'DELETE FROM event_reminders
                     WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ? AND sent_at IS NULL'
                )->execute(['payment_due', $costId, $userId, 'notification']);
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
        $ins->execute(['payment_due', $costId, $userId, $buildingId, $remindAt, 'sms']);
        if ($ins->rowCount() !== 1) {
            return; // پیامک قبلاً فرستاده شده
        }

        $u = $db->prepare('SELECT name, phone FROM users WHERE id = ? AND deleted_at IS NULL');
        $u->execute([$userId]);
        $user = $u->fetch(\PDO::FETCH_ASSOC) ?: [];
        $phone = PhoneHelper::normalize((string) ($user['phone'] ?? ''));

        if ($smsService->isEnabled() && $phone !== '' && PhoneHelper::isValid($phone)) {
            $amountText = JalaliHelper::faDigits(number_format($dueAmount)) . ' تومان';
            $ok = $smsService->sendDebtorReminderSms(
                $phone,
                (string) ($user['name'] ?? ''),
                $buildingName,
                $amountText,
                $dueLabel . ' — ' . $costTitle
            );
            if ($ok) {
                $result['sms_sent']++;
            }
        }

        // چه ارسال موفق باشد چه نه، ردیف علامت‌گذاری می‌شود تا تکراری فرستاده نشود
        $db->prepare(
            'UPDATE event_reminders SET sent_at = CURRENT_TIMESTAMP
             WHERE event_type = ? AND event_id = ? AND user_id = ? AND channel = ?'
        )->execute(['payment_due', $costId, $userId, 'sms']);
    }
}
