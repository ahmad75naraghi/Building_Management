<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Utilities\JalaliHelper;

/**
 * یادآوری پیامکی بدهکاران — اجرای خودکار توسط کران روزانه.
 *
 * - واحدهای بدهکار (ماندهٔ منفی بیش از آستانهٔ `SMS_DEBTOR_MIN_AMOUNT`) شناسایی می‌شوند.
 * - برای هر واحد در هر دورهٔ شمسی فقط یک پیامک ارسال می‌شود (جدول `debtor_sms_log`
 *   با کلید یکتای واحد+دوره جلوی تکرار در اجراهای مکرر کران را می‌گیرد).
 * - اگر سرویس پیامک غیرفعال باشد (اعتبارنامه تنظیم نشده) هیچ ارسال/ثبتی انجام
 *   نمی‌شود و فقط شمارندهٔ `sms_disabled` گزارش می‌شود.
 * - اعلان درون‌اپی + لاگ ممیزی هم برای هر ارسال موفق ثبت می‌شود.
 */
final class DebtorReminderService
{
    /** دورهٔ شمسی جاری به‌صورت YYYY-MM (برای کلید یکتایی) */
    public static function currentPeriod(): string
    {
        [$jy, $jm] = JalaliHelper::toJalali((int) date('Y'), (int) date('n'), (int) date('j'));
        return sprintf('%04d-%02d', $jy, $jm);
    }

    /** نام مهلت پرداخت: پایان ماه شمسی جاری */
    public static function currentDeadlineLabel(): string
    {
        [$jy, $jm] = JalaliHelper::toJalali((int) date('Y'), (int) date('n'), (int) date('j'));
        return 'پایان ' . JalaliHelper::MONTH_NAMES[$jm] . ' ' . JalaliHelper::faDigits((string) $jy);
    }

    /** آیا این واحد در این دوره پیامک گرفته است؟ */
    public function alreadyReminded(int $unitId, string $period): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id FROM debtor_sms_log WHERE unit_id = ? AND period = ? LIMIT 1');
        $stmt->execute([$unitId, $period]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * اجرای کامل چرخهٔ یادآوری.
     *
     * @param bool $dryRun فقط فهرست نامزدها برگردانده شود، بدون ارسال و ثبت
     * @return array{period:string, candidates:int, sent:int, skipped:int, failed:int, sms_disabled:bool, list:array}
     */
    public function run(bool $dryRun = false): array
    {
        $env = \App\Config\AppConfig::env(...);
        $minAmount = (float) $env('SMS_DEBTOR_MIN_AMOUNT', '10000');
        $period = self::currentPeriod();
        $deadline = self::currentDeadlineLabel();

        $debtors = (new CostService())->getDebtorUnits($minAmount);
        $sms = new SmsService();

        $result = [
            'period' => $period,
            'candidates' => count($debtors),
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'sms_disabled' => !$sms->isEnabled(),
            'list' => $debtors,
        ];

        if ($dryRun || empty($debtors)) {
            return $result;
        }

        if (!$sms->isEnabled()) {
            Logger::info('DebtorReminder', 'پیامک غیرفعال است؛ یادآوری بدهکاران ارسال نشد', [
                'candidates' => count($debtors),
            ]);
            return $result;
        }

        $db = Database::getConnection();
        foreach ($debtors as $d) {
            if ($this->alreadyReminded((int) $d['unit_id'], $period)) {
                $result['skipped']++;
                continue;
            }

            $amountText = JalaliHelper::faDigits(number_format((float) $d['debt'])) . ' تومان';
            $ok = $sms->sendDebtorReminderSms(
                (string) $d['phone'],
                (string) $d['name'],
                (string) ($d['building_name'] ?? ''),
                $amountText,
                $deadline
            );

            if (!$ok) {
                $result['failed']++;
                continue;
            }

            // ثبت برای جلوگیری از ارسال تکراری در همین دوره (کلید یکتا محافظ نهایی)
            $stmt = Database::prepareInsertIgnore(
                $db,
                'INSERT IGNORE INTO debtor_sms_log (building_id, unit_id, user_id, phone, amount, period)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $d['building_id'],
                (int) $d['unit_id'],
                (int) $d['user_id'],
                (string) $d['phone'],
                (float) $d['debt'],
                $period,
            ]);

            // اعلان درون‌اپی برای پرداخت‌کنندهٔ مسئول (شکست اعلان، جریان را نمی‌شکند)
            try {
                (new NotificationService())->createNotification([
                    'user_id' => (int) $d['user_id'],
                    'building_id' => (int) $d['building_id'],
                    'notification_type' => 'payment',
                    'title' => 'یادآوری پرداخت بدهی',
                    'message' => 'مانده بدهی واحد ' . $d['unit_number'] . ' مبلغ ' . $amountText
                        . ' است. لطفاً نسبت به پرداخت اقدام فرمایید.',
                ]);
            } catch (\Throwable $e) {
                Logger::error('DebtorReminder', 'اعلان یادآوری بدهی ثبت نشد', ['unit_id' => $d['unit_id']], $e);
            }

            \App\Core\Audit::log(0, 'reminder.debtor_sms', 'unit', (int) $d['unit_id'], (int) $d['building_id'], [
                'amount' => (float) $d['debt'],
                'period' => $period,
                'phone' => (string) $d['phone'],
            ]);

            $result['sent']++;
        }

        Logger::info('DebtorReminder', 'چرخه یادآوری بدهکاران انجام شد', [
            'period' => $period,
            'sent' => $result['sent'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        return $result;
    }
}
