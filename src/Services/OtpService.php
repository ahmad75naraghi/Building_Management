<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Utilities\PhoneHelper;

/**
 * مدیریت کدهای یک‌بارمصرف (OTP) برای ورود/ثبت‌نام با شماره موبایل.
 *
 * جریان کلی:
 *   1. کاربر فقط شماره موبایل را وارد می‌کند.
 *   2. اگر رمز عبور ست کرده باشد → از او رمز خواسته می‌شود.
 *   3. در غیر این صورت (کاربر جدید یا بدون رمز) → کد OTP پیامک می‌شود.
 *   4. پس از تأیید کد، کاربر ساخته/وارد می‌شود و برای تکمیل نام و ست‌کردن رمز هدایت می‌شود.
 */
final class OtpService
{
    /** مدت اعتبار کد به ثانیه (۲ دقیقه) */
    public const TTL_SECONDS = 120;

    /** حداقل فاصله بین دو درخواست ارسال کد برای یک شماره (ثانیه) */
    public const RESEND_COOLDOWN = 60;

    /** حداکثر تعداد تلاش اشتباه برای هر کد */
    public const MAX_ATTEMPTS = 5;

    /** حداکثر تعداد کد ارسالی برای یک شماره در یک ساعت */
    public const MAX_PER_HOUR = 6;

    /**
     * تولید و ارسال کد یک‌بارمصرف.
     *
     * @return array{sent:bool, retry_after:int, debug_code:?string}
     */
    public function sendCode(string $phone, string $purpose = 'auth'): array
    {
        $phone = PhoneHelper::normalize($phone);
        if (!PhoneHelper::isValid($phone)) {
            throw new ValidationException('شماره موبایل معتبر نیست. مثال: 09123456789');
        }

        $db = Database::getConnection();
        $now = time();

        // جلوگیری از ارسال پیاپی
        $stmt = $db->prepare(
            "SELECT created_at FROM otp_codes
             WHERE phone = ? AND purpose = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$phone, $purpose]);
        $lastCreated = $stmt->fetchColumn();
        if ($lastCreated) {
            $elapsed = $now - (int) strtotime((string) $lastCreated);
            if ($elapsed >= 0 && $elapsed < self::RESEND_COOLDOWN) {
                Logger::info('OtpService', 'درخواست ارسال کد در بازه انتظار رد شد', [
                    'phone' => $phone,
                    'retry_after' => self::RESEND_COOLDOWN - $elapsed,
                ]);
                return [
                    'sent' => false,
                    'retry_after' => self::RESEND_COOLDOWN - $elapsed,
                    'debug_code' => null,
                ];
            }
        }

        // سقف ارسال در ساعت (جلوگیری از سوءاستفاده)
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > ?"
        );
        $stmt->execute([$phone, self::at($now - 3600)]);
        if ((int) $stmt->fetchColumn() >= self::MAX_PER_HOUR) {
            Logger::warning('OtpService', 'سقف ارسال کد در ساعت رد شد', ['phone' => $phone]);
            throw new ValidationException('تعداد درخواست کد بیش از حد مجاز است. لطفاً یک ساعت دیگر تلاش کنید.');
        }

        // ابطال کدهای قبلیِ استفاده‌نشده
        $stmt = $db->prepare(
            "UPDATE otp_codes SET consumed_at = ?
             WHERE phone = ? AND purpose = ? AND consumed_at IS NULL"
        );
        $stmt->execute([self::at($now), $phone, $purpose]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare(
            "INSERT INTO otp_codes (phone, code_hash, purpose, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $phone,
            password_hash($code, PASSWORD_DEFAULT),
            $purpose,
            self::at($now),
            self::at($now + self::TTL_SECONDS),
        ]);

        try {
            (new SmsService())->sendOtpSms($phone, $code);
            Logger::info('OtpService', 'کد یک‌بارمصرف ارسال شد', ['phone' => $phone, 'purpose' => $purpose]);
        } catch (\Throwable $e) {
            // نبود پنل پیامک نباید جریان ورود را قطع کند، ولی باید دیده شود
            Logger::error('OtpService', 'ارسال پیامک کد ناموفق بود', ['phone' => $phone], $e);
        }

        return [
            'sent' => true,
            'retry_after' => self::RESEND_COOLDOWN,
            // فقط در محیط توسعه برای تست بدون پیامک برگردانده می‌شود
            'debug_code' => $this->isDebugMode() ? $code : null,
        ];
    }

    /**
     * بررسی کد وارد شده. در صورت درستی، کد مصرف‌شده علامت می‌خورد.
     */
    public function verifyCode(string $phone, string $code, string $purpose = 'auth'): bool
    {
        $phone = PhoneHelper::normalize($phone);
        $code = preg_replace('/\D/', '', PhoneHelper::toEnglishDigits($code)) ?? '';
        if ($code === '') {
            throw new ValidationException('کد تأیید را وارد کنید.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT id, code_hash, attempts FROM otp_codes
             WHERE phone = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$phone, $purpose, self::at(time())]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            Logger::info('OtpService', 'کد فعالی برای تأیید یافت نشد', ['phone' => $phone]);
            throw new ValidationException('کد تأیید منقضی شده است. دوباره درخواست کنید.');
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            Logger::warning('OtpService', 'کد به دلیل تلاش بیش از حد باطل شد', ['phone' => $phone]);
            $db->prepare("UPDATE otp_codes SET consumed_at = ? WHERE id = ?")->execute([self::at(time()), $row['id']]);
            throw new ValidationException('تعداد تلاش‌های ناموفق زیاد بود. کد جدید درخواست کنید.');
        }

        if (!password_verify($code, (string) $row['code_hash'])) {
            $db->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
            $left = self::MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
            Logger::info('OtpService', 'کد تأیید اشتباه وارد شد', ['phone' => $phone, 'attempts_left' => max(0, $left)]);
            throw new ValidationException(
                $left > 0
                    ? "کد تأیید اشتباه است. {$left} تلاش دیگر باقی مانده است."
                    : 'کد تأیید اشتباه است. کد جدید درخواست کنید.'
            );
        }

        $db->prepare("UPDATE otp_codes SET consumed_at = ? WHERE id = ?")->execute([self::at(time()), $row['id']]);
        Logger::info('OtpService', 'کد تأیید با موفقیت بررسی شد', ['phone' => $phone, 'purpose' => $purpose]);
        return true;
    }

    /**
     * فقط با تنظیم صریح متغیر محیطی OTP_DEBUG=1، کد در پاسخ برگردانده می‌شود
     * تا بدون پنل پیامک هم بتوان تست کرد.
     *
     * عمداً به APP_ENV تکیه نمی‌کنیم؛ چون مقدار پیش‌فرض آن در مخزن development است
     * و در استقرار واقعی باعث افشای کد ورود می‌شد.
     */
    /**
     * قالب زمانی سازگار با MySQL و SQLite.
     * عمداً از NOW()/DATE_ADD استفاده نمی‌کنیم تا کوئری‌ها به یک موتور خاص گره نخورند
     * و قابل تست باشند.
     */
    private static function at(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function isDebugMode(): bool
    {
        return getenv('OTP_DEBUG') === '1';
    }
}
