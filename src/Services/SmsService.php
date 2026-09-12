<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
/**
 * ارسال پیامک از طریق پنل ملی‌پیامک (Melipayamak) با متد SendByBaseNumber.
 *
 * مشخصات فقط از متغیرهای محیطی خوانده می‌شود و هیچ مقدار پیش‌فرضی در کد نیست:
 *   MELIPAYAMAK_USERNAME / MELIPAYAMAK_PASSWORD / MELIPAYAMAK_BODY_ID
 *   MELIPAYAMAK_REMINDER_BODY_ID — پترن جداگانه برای یادآوری رویدادها
 *   (اختیاری؛ اگر تنظیم نشود یادآوری با همان پترن پیش‌فرض و متن کامل ارسال می‌شود)
 *
 * اگر اعتبارنامه تنظیم نشده باشد، سرویس «غیرفعال» است و ارسال‌ها بی‌صدا
 * رد می‌شوند (فقط لاگ) تا جریان اصلی (ثبت‌نام/دعوت/یادآوری) متوقف نشود.
 * در صورت نبود افزونه SOAP یا خطای شبکه نیز خطا فقط لاگ می‌شود و false برمی‌گردد.
 */
final class SmsService
{
    private string $username;
    private string $password;
    private int $bodyId;
    private int $reminderBodyId;

    public function __construct()
    {
        // از طریق AppConfig خوانده می‌شود تا مقادیر فایل .env هم بارگذاری شوند
        $env = \App\Config\AppConfig::env(...);
        $this->username = trim((string) $env('MELIPAYAMAK_USERNAME', ''));
        $this->password = trim((string) $env('MELIPAYAMAK_PASSWORD', ''));
        $this->bodyId = (int) $env('MELIPAYAMAK_BODY_ID', '0');
        $this->reminderBodyId = (int) $env('MELIPAYAMAK_REMINDER_BODY_ID', '0');
    }

    /** آیا اعتبارنامهٔ پیامک پیکربندی شده است؟ */
    public function isEnabled(): bool
    {
        return $this->username !== '' && $this->password !== '' && $this->bodyId > 0;
    }

    /**
     * ارسال پیامک پترن (متن + آرگومان‌ها) به یک شماره.
     *
     * @param string   $to     شماره مقصد (فرمت 09xxxxxxxxx)
     * @param string   $text   متن اصلی پیامک
     * @param array    $args   آرگومان‌های پترن (متناظر با arg1 و arg2 و ...)
     * @param int|null $bodyId شناسه پترن (اگر نال باشد، پترن پیش‌فرض استفاده می‌شود)
     */
    public function sendByBaseNumber(string $to, string $text, array $args = [], ?int $bodyId = null): bool
    {
        if (!$this->isEnabled()) {
            Logger::warning('SmsService', 'پیامک غیرفعال است؛ متغیرهای محیطی MELIPAYAMAK_* تنظیم نشده‌اند', ['to' => $to]);
            return false;
        }
        $to = \App\Utilities\PhoneHelper::normalize($to);
        if (!\App\Utilities\PhoneHelper::isValid($to)) {
            Logger::warning('SmsService', 'شماره مقصد پیامک معتبر نیست', ['to' => $to]);
            return false;
        }
        if (!class_exists(\SoapClient::class)) {
            Logger::warning('SmsService', 'افزونه soap نصب نیست؛ ارسال پیامک انجام نشد', ['to' => $to]);
            return false;
        }

        try {
            ini_set('soap.wsdl_cache_enabled', '0');
            /** @var \SoapClient $sms */
            $sms = new \SoapClient(
                'http://api.payamak-panel.com/post/Send.asmx?wsdl',
                ['encoding' => 'UTF-8', 'connection_timeout' => 15]
            );
            $data = [
                'username' => $this->username,
                'password' => $this->password,
                'text' => $text,
                'to' => $to,
                'bodyId' => $bodyId ?? $this->bodyId,
            ];
            // اگر پترن آرگومان دارد، به‌صورت آرایه ارسال شود
            if (!empty($args)) {
                $data['text'] = array_values($args);
            }
            $result = $sms->SendByBaseNumber($data)->SendByBaseNumberResult ?? null;
            if (is_string($result) && $result !== '' && !str_starts_with($result, '0')) {
                // پاسخ موفق ملی‌پیامک معمولاً شناسه ارسال (عدد مثبت) است
                return true;
            }
            Logger::error('SmsService', 'ارسال پیامک ناموفق بود', [
                'to' => $to,
                'provider_result' => is_scalar($result) ? (string) $result : gettype($result),
            ]);
            return is_string($result) && $result !== '';
        } catch (\Throwable $e) {
            Logger::error('SmsService', 'خطا در ارتباط با سامانه پیامک', ['to' => $to], $e);
            return false;
        }
    }

    /**
     * پیامک خوش‌آمد پس از ثبت‌نام.
     */
    public function sendWelcomeSms(string $to, string $name): bool
    {
        $text = "کاربر گرامی {$name}، ثبت‌نام شما در سامانه مدیریت ساختمان با موفقیت انجام شد.";
        return $this->sendByBaseNumber($to, $text);
    }

    /**
     * پیامک کد یک‌بارمصرف ورود/ثبت‌نام.
     */
    public function sendOtpSms(string $to, string $code): bool
    {
        $minutes = (int) ceil(\App\Services\OtpService::TTL_SECONDS / 60);
        $text = "کد ورود شما به سامانه مدیریت ساختمان: {$code}\nاعتبار: {$minutes} دقیقه. این کد را در اختیار کسی قرار ندهید.";
        return $this->sendByBaseNumber($to, $text, [$code]);
    }

    /**
     * پیامک دعوت به ساختمان همراه با لینک پذیرش.
     */
    public function sendInviteSms(string $to, string $invitedName, string $buildingName, string $roleLabel, string $inviteLink): bool
    {
        $text = "کاربر گرامی {$invitedName}، شما به ساختمان «{$buildingName}» با نقش {$roleLabel} دعوت شدید. لینک پذیرش: {$inviteLink}";
        return $this->sendByBaseNumber($to, $text);
    }

    /**
     * پیامک یادآوری شارژ/بدهی (استفاده آینده در تسک خودکار ماهیانه).
     */
    public function sendChargeReminderSms(string $to, string $name, string $buildingName, string $amount): bool
    {
        $text = "کاربر گرامی {$name}، شارژ ماهیانه ساختمان «{$buildingName}» به مبلغ {$amount} تومان صادر شد. لطفاً پرداخت فرمایید.";
        return $this->sendByBaseNumber($to, $text);
    }

    /**
     * پیامک یادآوری بدهی برای واحدهای بدهکار — ارسال خودکار توسط کران روزانه.
     *
     * اگر `MELIPAYAMAK_DEBTOR_BODY_ID` تنظیم شده باشد، پترن جداگانه با
     * آرگومان‌های [نام، مبلغ بدهی، نام ساختمان، مهلت] ارسال می‌شود؛
     * در غیر این صورت متن کامل با پترن پیش‌فرض ارسال می‌گردد.
     * متن قالب از `SMS_DEBTOR_TEMPLATE` خوانده می‌شود و جای‌دارهای
     * {نام} {مبلغ} {ساختمان} {مهلت} را پشتیبانی می‌کند.
     */
    public function sendDebtorReminderSms(string $to, string $name, string $buildingName, string $amount, string $deadline): bool
    {
        $env = \App\Config\AppConfig::env(...);
        $template = trim((string) $env('SMS_DEBTOR_TEMPLATE', ''));
        if ($template === '') {
            $template = "کاربر گرامی {نام}، مانده بدهی شما بابت شارژ ساختمان «{ساختمان}» مبلغ {مبلغ} تومان است. لطفاً تا {مهلت} نسبت به پرداخت اقدام فرمایید.";
        }
        $text = strtr($template, [
            '{نام}' => $name,
            '{مبلغ}' => $amount,
            '{ساختمان}' => $buildingName,
            '{مهلت}' => $deadline,
        ]);

        $debtorBodyId = (int) $env('MELIPAYAMAK_DEBTOR_BODY_ID', '0');
        if ($debtorBodyId > 0) {
            return $this->sendByBaseNumber($to, $text, [$name, $amount, $buildingName, $deadline], $debtorBodyId);
        }
        return $this->sendByBaseNumber($to, $text);
    }

    /**
     * پیامک یادآوری رویداد (جلسه یا رزرو مشاعات) — ارسال توسط اسکریپت کران یادآوری‌ها.
     *
     * اگر `MELIPAYAMAK_REMINDER_BODY_ID` تنظیم شده باشد، پترن جداگانه با
     * آرگومان‌های [نام، عنوان رویداد، زمان، نام ساختمان] ارسال می‌شود؛
     * در غیر این صورت متن کامل با پترن پیش‌فرض ارسال می‌گردد.
     *
     * @param string $when زمان رویداد به‌صورت متن (مثلاً «شنبه ۱۵ شهریور ۱۴۰۵، ساعت ۱۸:۰۰»)
     */
    public function sendEventReminderSms(string $to, string $name, string $eventTitle, string $when, string $buildingName): bool
    {
        $text = "کاربر گرامی {$name}، یادآوری رویداد ساختمان «{$buildingName}»: {$eventTitle} — زمان: {$when}";
        if ($this->reminderBodyId > 0) {
            return $this->sendByBaseNumber($to, $text, [$name, $eventTitle, $when, $buildingName], $this->reminderBodyId);
        }
        return $this->sendByBaseNumber($to, $text);
    }
}
